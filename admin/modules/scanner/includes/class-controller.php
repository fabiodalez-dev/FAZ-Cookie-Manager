<?php
/**
 * Local cookie scanner controller.
 *
 * Replaces the cloud-based scanner with a local PHP crawler
 * that fetches pages via wp_remote_get() and parses Set-Cookie headers.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Scanner\Includes;

use FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
use FazCookie\Includes\Cookie_Definitions;
use FazCookie\Admin\Modules\Scanner\Includes\Scanner_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Local cookie scanner controller.
 *
 * @class       Controller
 * @version     3.0.0
 * @package     FazCookie
 */
class Controller {

	/**
	 * Instance of the current class
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Default scan data
	 *
	 * @var array
	 */
	private static $default = array(
		'id'            => 0,
		'status'        => '',
		'type'          => 'local',
		'date'          => '',
		'total_cookies' => 0,
		'new_cookies'   => 0,
		'pages_scanned' => 0,
	);

	/**
	 * Embed/script src URLs harvested from page HTML during a server crawl.
	 *
	 * Accumulated across pages by scan_page() and turned into inferred
	 * Known_Providers cookies in run_scan(), so a provider embedded as an
	 * <iframe>/<script> (e.g. a blocked YouTube video) becomes a scanner-
	 * detected service even when its cookie is never set on a block-first
	 * site — which is what surfaces its per-service toggle (#134/#146).
	 *
	 * @var string[]
	 */
	private $scanned_embed_urls = array();

	/** Anonymous cookie jar shared across pages in one server replay worker. */
	private $server_cookie_jar = array();

	/**
	 * WP-Cron action name for async scanning.
	 */
	const CRON_HOOK = 'faz_async_cookie_scan';

	/**
	 * WP-Cron action name for async httpOnly cookie checks.
	 */
	const HTTPONLY_CRON_HOOK = 'faz_async_httponly_cookie_check';

	/** Queue of browser-visited URLs awaiting server header enrichment. */
	const HTTPONLY_URLS_OPTION = 'faz_httponly_scan_urls';

	/** Atomic option lock protecting the shared enrichment queue. */
	const HTTPONLY_LOCK_OPTION = 'faz_httponly_scan_lock';

	/**
	 * How many consecutive FULL scans a discovered cookie has gone unobserved.
	 *
	 * Keyed "name|domain". A single scan missing a cookie proves nothing: a site
	 * using delay-JS-until-interaction never fires its trackers inside a passive
	 * scan iframe, and flow-only cookies (checkout, login) are never reached at
	 * all — yet both are set for every real visitor. Deleting on one miss
	 * removes live entries from the public cookie declaration.
	 *
	 * @var string
	 */
	const MISSED_SCANS_OPTION = 'faz_cookie_missed_scans';

	/**
	 * Consecutive full scans a cookie must be missing before deletion is offered.
	 *
	 * @var int
	 */
	const MISSED_SCANS_THRESHOLD = 2;

	/** Short-lived marker used only while an administrator runs a browser scan. */
	const BROWSER_SCAN_COOKIE = 'faz_scan_session';

	/** Per-user append-only observations captured from outgoing Set-Cookie headers. */
	const BROWSER_SCAN_META = '_faz_scan_cookie_observation';

	/**
	 * Browser scan capture lifetime in seconds.
	 *
	 * This is an IDLE timeout, not a total budget. It is opened by
	 * scans/discover and slid forward by touch_browser_scan_session() on every
	 * scan-tagged page load (and by the scans/heartbeat fallback), so a crawl of
	 * any length stays importable while an abandoned tab still releases the lock.
	 * It was a fixed wall clock until the per-page settle cost roughly tripled,
	 * at which point an ordinary 500-page scan spent longer crawling than the
	 * window allowed and lost 100% of its work to a 409 at import.
	 */
	const BROWSER_SCAN_TTL = 900;

	/**
	 * Hard ceiling on how long one capture session may be kept alive.
	 *
	 * A sliding idle timeout with no ceiling turns a wedged tab into a permanent
	 * scan lock — worse than the fifteen-minute lockout it replaces. Six hours is
	 * far beyond any legitimate crawl and still bounded.
	 *
	 * @var int
	 */
	const BROWSER_SCAN_MAX_AGE = 21600; // 6 * HOUR_IN_SECONDS.

	/** Maximum unique Set-Cookie observations retained for one browser scan. */
	const BROWSER_SCAN_OBSERVATION_LIMIT = 2000;

	/** Maximum pages accepted by every browser, REST, cron and CLI scan path. */
	const MAX_SCAN_PAGES = 2000;

	/** Whether the most recently collected browser capture reached its safety cap. */
	private $browser_scan_capture_truncated = false;

	/**
	 * Last scan info.
	 *
	 * @var array|null
	 */
	protected $last_scan_info;

	/**
	 * Clamp a requested scan depth to the product safety envelope.
	 *
	 * @param mixed $value Requested number of pages.
	 * @return int Value between 1 and MAX_SCAN_PAGES.
	 */
	public static function normalize_max_pages( $value ) {
		$value = is_numeric( $value ) ? (int) $value : 0;
		return max( 1, min( self::MAX_SCAN_PAGES, $value ) );
	}

	/**
	 * Return the current instance of the class
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Whether register_cron_hook() has already attached its callbacks in this
	 * request.
	 *
	 * @var bool
	 */
	private static $cron_hooks_registered = false;

	/**
	 * Register the WP-Cron hook for async scanning.
	 *
	 * The closures keep registration cheap on the frontend: the controller is
	 * only autoloaded and instantiated when a scan cron event actually fires,
	 * not on every request that merely registers the callbacks. That laziness
	 * is why they stay closures rather than becoming array callbacks — an
	 * `array( self::get_instance(), … )` callback would have to build the
	 * controller on every request just to name the handler.
	 *
	 * The price of a closure is that it cannot be de-duplicated: WordPress keys
	 * its callback table by _wp_filter_build_unique_id(), which spl_object_hash
	 * -es a Closure, and two syntactically identical closures are two distinct
	 * objects with two distinct hashes. Both therefore stay attached and the
	 * scan runs TWICE. This is not hypothetical: register_cron_hook() is called
	 * once from the plugin bootstrap (so wp-cron.php, which never loads the
	 * admin modules, still has a handler) and once from the scanner module's
	 * init(), and both fire in the same admin/REST request. Hence the guard —
	 * it belongs here rather than at the callsites, because the reason the two
	 * callsites exist is precisely that neither can know about the other.
	 */
	public static function register_cron_hook() {
		if ( self::$cron_hooks_registered ) {
			return;
		}
		self::$cron_hooks_registered = true;

		add_action(
			self::CRON_HOOK,
			static function () {
				self::get_instance()->run_scan_async();
			}
		);
		add_action(
			self::HTTPONLY_CRON_HOOK,
			static function () {
				$controller = self::get_instance();
				try {
					$controller->run_httponly_check();
				} catch ( \Throwable $e ) {
					$controller->record_scan_failure( 'httponly', $e->getMessage(), 1 );
					error_log( 'FAZ: httpOnly scan failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- cron has no response channel.
				}
			}
		);
	}

	/**
	 * Observe Set-Cookie headers emitted by scan-tagged same-origin requests.
	 *
	 * The marker is HttpOnly and created only by the authenticated discover
	 * endpoint. Resource, REST and admin-ajax requests made by scanned pages
	 * carry it automatically, which lets PHP record cookie metadata that browser
	 * JavaScript is forbidden to read. Values are never persisted.
	 *
	 * Deliberately NOT gated on a matching `faz_scan_id`: the requests worth
	 * capturing are the sub-resource, AJAX and REST calls a scanned page makes,
	 * and those structurally cannot carry the scan id. The cost is that the
	 * administrator's OWN wp-admin browsing, in the same browser, for the life
	 * of the session, is observed too. That is handled by classification rather
	 * than exclusion: each observation records whether it came from an admin
	 * context (see request_is_admin_context()), and the import reports those
	 * instead of declaring them.
	 *
	 * @return void
	 */
	public static function register_browser_scan_observer() {
		if ( empty( $_COOKIE[ self::BROWSER_SCAN_COOKIE ] ) ) {
			return;
		}

		$token = sanitize_key( wp_unslash( (string) $_COOKIE[ self::BROWSER_SCAN_COOKIE ] ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return;
		}

		// Slide the capture window forward on every scan-tagged page load. The
		// session is opened once by scans/discover; without this it expires on a
		// fixed wall clock the client cannot see or extend, and a long crawl is
		// discarded wholesale by the 409 at scans/import.
		//
		// Deferred to `init` because this runs while plugins are still loading,
		// before pluggable.php defines wp_get_current_user() — and because the
		// cookie has to be re-issued before headers go out, which rules out the
		// shutdown hook used for the observation capture below.
		//
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only marker read; renewal is refused unless the httpOnly session cookie resolves to a live session owned by the current user whose scan_id matches.
		$scan_id = isset( $_GET['faz_scan_id'] ) ? sanitize_key( wp_unslash( (string) $_GET['faz_scan_id'] ) ) : '';
		if ( '' !== $scan_id ) {
			add_action(
				'init',
				static function () use ( $token, $scan_id ) {
					self::get_instance()->touch_browser_scan_session( $token, $scan_id );
				},
				1
			);
		}

		// Use WordPress' composable shutdown hook instead of PHP's single global
		// header_register_callback(), which would replace (or be replaced by) a
		// callback installed by another plugin. headers_list() remains available
		// during shutdown and contains the final outgoing header set.
		add_action(
			'shutdown',
			static function () use ( $token ) {
				$session = get_transient( self::browser_scan_transient_key( $token ) );
				$user_id = get_current_user_id();
				if ( ! is_array( $session ) || empty( $session['user_id'] ) || $user_id !== absint( $session['user_id'] ) ) {
					return;
				}

				$controller    = self::get_instance();
				$admin_context = self::request_is_admin_context();
				$existing      = (array) get_user_meta( $user_id, self::BROWSER_SCAN_META, false );
				$seen          = array();
				$stored_by_key = array();
				$stored        = 0;
				$truncated     = false;
				foreach ( $existing as $row ) {
					if ( ! is_array( $row ) || ! hash_equals( $token, isset( $row['token'] ) ? (string) $row['token'] : '' ) ) {
						continue;
					}
					if ( ! empty( $row['truncated'] ) ) {
						$truncated = true;
						continue;
					}
					// The admin flag is part of the key on purpose: the same cookie
					// seen once from wp-admin and once from a scanned front-end
					// page must not be collapsed into whichever was observed
					// first, or a genuine site cookie stays bucketed as the
					// administrator's own.
					$request_url = $controller->observation_url( isset( $row['request_path'] ) ? (string) $row['request_path'] : '/' );
					$key         = $controller->set_cookie_identity( $row, $request_url ) . '|' . ( empty( $row['admin_context'] ) ? '0' : '1' );
					if ( $controller->set_cookie_is_deletion( $row, $controller->observation_reference_time( $row ) ) ) {
						foreach ( isset( $stored_by_key[ $key ] ) ? $stored_by_key[ $key ] : array() as $stored_row ) {
							delete_user_meta( $user_id, self::BROWSER_SCAN_META, $stored_row );
							$stored = max( 0, $stored - 1 );
						}
						// Older plugin versions persisted the clearing directive itself.
						// Remove it while rebuilding the ordered active set.
						delete_user_meta( $user_id, self::BROWSER_SCAN_META, $row );
						unset( $seen[ $key ], $stored_by_key[ $key ] );
						continue;
					}
					$seen[ $key ] = true;
					$stored_by_key[ $key ][] = $row;
					++$stored;
				}
				$request_path = isset( $_SERVER['REQUEST_URI'] )
					? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
					: '/';
				$request_url  = $controller->observation_url( $request_path );
				foreach ( headers_list() as $header_line ) {
					if ( 0 !== stripos( $header_line, 'Set-Cookie:' ) ) {
						continue;
					}
					$parsed = $controller->parse_set_cookie( trim( substr( $header_line, strlen( 'Set-Cookie:' ) ) ) );
					if ( empty( $parsed['name'] ) || self::BROWSER_SCAN_COOKIE === $parsed['name'] ) {
						continue;
					}
					$key = $controller->set_cookie_identity( $parsed, $request_url ) . '|' . ( $admin_context ? '1' : '0' );
					if ( $controller->set_cookie_is_deletion( $parsed ) ) {
						// Set-Cookie is an ordered mutation stream. A deletion removes a
						// prior sighting of the same effective name/domain/path instead
						// of becoming a fictitious session cookie. Remove every legacy
						// duplicate too, then allow a later header in this response to
						// set the identity again.
						foreach ( isset( $stored_by_key[ $key ] ) ? $stored_by_key[ $key ] : array() as $stored_row ) {
							delete_user_meta( $user_id, self::BROWSER_SCAN_META, $stored_row );
							$stored = max( 0, $stored - 1 );
						}
						unset( $seen[ $key ], $stored_by_key[ $key ] );
						continue;
					}
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					if ( $stored >= self::BROWSER_SCAN_OBSERVATION_LIMIT ) {
						if ( ! $truncated ) {
							add_user_meta( $user_id, self::BROWSER_SCAN_META, array( 'token' => $token, 'observed_at' => time(), 'truncated' => true ), false );
							$truncated = true;
						}
						break;
					}

					$scope       = $controller->set_cookie_scope( $parsed, $request_url );
					$observation = array(
						'token'         => $token,
						'observed_at'   => time(),
						'admin_context' => $admin_context,
						'request_path' => sanitize_text_field( $request_path ),
						'name'         => sanitize_text_field( $parsed['name'] ),
						'domain'       => sanitize_text_field( $scope['domain'] ),
						'path'         => sanitize_text_field( $scope['path'] ),
						'expires'      => sanitize_text_field( $parsed['expires'] ),
						'max-age'      => sanitize_text_field( $parsed['max-age'] ),
						'secure'       => ! empty( $parsed['secure'] ),
						'httponly'     => ! empty( $parsed['httponly'] ),
						'samesite'     => sanitize_text_field( $parsed['samesite'] ),
					);
					add_user_meta( $user_id, self::BROWSER_SCAN_META, $observation, false );
					$seen[ $key ] = true;
					$stored_by_key[ $key ][] = $observation;
					++$stored;
				}
			},
			PHP_INT_MAX
		);
	}

	/**
	 * Whether this request is the administrator's own wp-admin traffic rather
	 * than something a scanned front-end page caused.
	 *
	 * The capture session is deliberately not scoped to a single scan_id (see
	 * register_browser_scan_observer()), so everything the administrator's
	 * browser does same-origin while the window is open is observed. This is the
	 * test that decides whether an observation may be DECLARED as a site cookie
	 * or only REPORTED — the same reported-not-imported bucket the request-cookie
	 * path already uses for the admin's jar.
	 *
	 * Three request shapes have to be told apart, and no single signal does it:
	 *
	 * - A wp-admin screen rendering itself: is_admin() is true. Admin.
	 * - admin-ajax.php and admin-post.php: is_admin() is true NO MATTER who
	 *   called them, and their path is under wp-admin either way, yet scanned
	 *   front-end pages legitimately call them (that is a large part of why the
	 *   observer exists). is_admin() alone would throw those away, so these two
	 *   are excluded from both the is_admin() and the path branch and decided by
	 *   their Referer alone.
	 * - REST: is_admin() is false even when the block editor is the caller, so
	 *   the Referer is again what separates an editor autosave from a scanned
	 *   page's fetch().
	 *
	 * The Referer is sent by the browser on same-origin subresource, AJAX and
	 * fetch requests, which is exactly the traffic in question; when it is
	 * absent the request is treated as non-admin, keeping the failure in the
	 * direction of declaring rather than silently discarding.
	 *
	 * @return bool
	 */
	public static function request_is_admin_context() {
		$admin_path = function_exists( 'admin_url' ) ? (string) wp_parse_url( admin_url( '/' ), PHP_URL_PATH ) : '';
		$admin_path = rtrim( $admin_path, '/' );

		$doing_ajax = wp_doing_ajax();

		$request_path = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
			: '';

		// admin-ajax.php and admin-post.php are the two wp-admin endpoints
		// WordPress intends FRONT-END pages to call, and both define WP_ADMIN —
		// so is_admin() and the request path each say "admin" about a request a
		// scanned page made. For those two the Referer is the only honest
		// signal, and both other branches stand aside for it.
		$front_end_callable = in_array(
			'' === $request_path ? '' : basename( $request_path ),
			array( 'admin-ajax.php', 'admin-post.php' ),
			true
		);

		if ( ! $front_end_callable && function_exists( 'is_admin' ) && is_admin() && ! $doing_ajax ) {
			return true;
		}

		if ( '' === $admin_path ) {
			return false;
		}

		$under_admin = static function ( $path ) use ( $admin_path ) {
			if ( ! is_string( $path ) || '' === $path ) {
				return false;
			}
			return $path === $admin_path || 0 === strpos( $path, $admin_path . '/' );
		};

		// The scanner dispatches every page it crawls through an iframe embedded
		// in its own wp-admin screen, so the Referer of a legitimately scanned
		// front-end page is ALWAYS a wp-admin URL. Reading that as "the admin
		// was browsing" is what the referer branch below would do, and it
		// misclassified the entire crawl: each observation was stamped
		// admin_context, import routed it to the reported-never-imported bucket,
		// and — because that bucket is also excluded from $attributable — the
		// request-cookie path dropped the same names a second time. The net
		// effect was that a cookie a scanned page sets through its own
		// Set-Cookie header, which is precisely what the server-side capture
		// exists to find, could never be imported by a browser scan.
		//
		// Two conditions, because either alone is too broad. The faz_scanning
		// marker is what the engine appends to the URLs it dispatches, so it
		// separates a page the scanner loaded from one the administrator merely
		// visited while a session happened to be open — that browsing must stay
		// classified as admin. And is_browser_scan_request() resolves the
		// capture token against the live transient AND the current user, so the
		// query parameter alone cannot be used to launder an observation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- classification only; no state is changed on the strength of this read.
		$scan_marked = isset( $_GET['faz_scanning'] ) && '1' === $_GET['faz_scanning'];
		if ( $scan_marked && ! $under_admin( $request_path ) && self::is_browser_scan_request() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- classification only; no state is changed on the strength of this read.
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		if ( '' !== $referer && $under_admin( wp_parse_url( $referer, PHP_URL_PATH ) ) ) {
			return true;
		}

		// A wp-admin file reached without WP_ADMIN being defined for it (an
		// admin asset endpoint, a direct hit on an admin script).
		return ! $front_end_callable && $under_admin( $request_path );
	}

	/**
	 * Whether this request belongs to a live authenticated browser scan.
	 *
	 * Used by the optional outgoing-cookie guard so scanner AJAX/REST requests
	 * can observe the original Set-Cookie header. A marker value alone never
	 * bypasses blocking: it must resolve to a live transient owned by the current
	 * authenticated user.
	 *
	 * @return bool
	 */
	public static function is_browser_scan_request() {
		if ( empty( $_COOKIE[ self::BROWSER_SCAN_COOKIE ] ) ) {
			return false;
		}
		$token = sanitize_key( wp_unslash( (string) $_COOKIE[ self::BROWSER_SCAN_COOKIE ] ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return false;
		}
		$session = get_transient( self::browser_scan_transient_key( $token ) );
		return is_array( $session )
			&& ! empty( $session['user_id'] )
			&& get_current_user_id() === absint( $session['user_id'] );
	}

	/**
	 * Start a short-lived browser scan capture session and set its marker.
	 *
	 * @param string $scan_id Client-generated identifier used to isolate retries/tabs.
	 * @return string|\WP_Error Opaque session token, or a conflict response.
	 */
	public function start_browser_scan_session( $scan_id = '' ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return '';
		}
		$scan_id = sanitize_key( (string) $scan_id );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $scan_id ) ) {
			return new \WP_Error( 'faz_invalid_browser_scan_id', __( 'Invalid browser scan identifier.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		$active_key = self::browser_scan_active_transient_key( $user_id );
		$active     = get_transient( $active_key );
		if ( is_array( $active ) && ! empty( $active['token'] ) && ! empty( $active['scan_id'] ) ) {
			if ( ! hash_equals( (string) $active['scan_id'], $scan_id ) ) {
				// A HELD session is evidence kept for a retry after an import
				// failed; nothing is driving it. Refusing a new scan because one
				// exists would trade the old bug (evidence destroyed on failure)
				// for a worse one: an administrator locked out of scanning until
				// the idle window lapses, with no way to clear it from the UI.
				// Starting a fresh scan is an unambiguous statement that the held
				// evidence is no longer wanted, so reclaim it and continue.
				//
				// A LIVE session still gets the 409 — a second tab really is a
				// conflict, and taking its capture away mid-crawl would silently
				// corrupt a scan someone is watching.
				if ( 'held' !== ( isset( $active['state'] ) ? $active['state'] : '' ) ) {
					return new \WP_Error( 'faz_browser_scan_in_progress', __( 'Another browser scan is already in progress for this administrator.', 'faz-cookie-manager' ), array( 'status' => 409 ) );
				}
				$this->discard_held_browser_scan_session( $active );
				$active = false;
			}
		}
		if ( is_array( $active ) && ! empty( $active['token'] ) && ! empty( $active['scan_id'] ) ) {
			$token = sanitize_key( (string) $active['token'] );
		} else {
			$token = str_replace( '-', '', wp_generate_uuid4() );
			set_transient(
				$active_key,
				array( 'token' => $token, 'scan_id' => $scan_id, 'created_at' => time() ),
				self::BROWSER_SCAN_TTL
			);
		}

		// created_at is the ABSOLUTE-age ceiling: touch_browser_scan_session()
		// slides the idle window but refuses once the session is older than
		// BROWSER_SCAN_MAX_AGE, which is what stops a wedged tab holding the
		// capture lock indefinitely. Writing time() unconditionally here reset
		// that ceiling on the REUSE branch too, so any caller re-entering with
		// the same scan_id renewed the guarantee — and could revive a session
		// that had already idled out. Carry the original forward; only a
		// genuinely new session starts its clock now.
		$existing_session = get_transient( self::browser_scan_transient_key( $token ) );
		$created_at       = ( is_array( $existing_session ) && ! empty( $existing_session['created_at'] ) )
			? absint( $existing_session['created_at'] )
			: time();

		set_transient(
			self::browser_scan_transient_key( $token ),
			array( 'user_id' => $user_id, 'scan_id' => $scan_id, 'created_at' => $created_at ),
			self::BROWSER_SCAN_TTL
		);

		// Remove abandoned observations from expired scans without touching a
		// still-live parallel session owned by the same administrator.
		foreach ( (array) get_user_meta( $user_id, self::BROWSER_SCAN_META, false ) as $old ) {
			if ( ! is_array( $old ) || empty( $old['observed_at'] ) || (int) $old['observed_at'] < time() - self::BROWSER_SCAN_TTL ) {
				delete_user_meta( $user_id, self::BROWSER_SCAN_META, $old );
			}
		}

		$this->issue_browser_scan_cookie( $token );

		return $token;
	}

	/**
	 * (Re-)issue the httpOnly scan marker with a fresh window.
	 *
	 * Kept in one place so the attributes — httpOnly, Secure on TLS,
	 * SameSite=Strict, path=/ — stay byte-for-byte identical everywhere the
	 * cookie is written. A renewal that quietly widened the scope or dropped
	 * SameSite would be a silent security regression.
	 *
	 * @param string $token Session token to write.
	 * @return void
	 */
	private function issue_browser_scan_cookie( $token ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::BROWSER_SCAN_COOKIE,
			$token,
			array(
				'expires'  => time() + self::BROWSER_SCAN_TTL,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}

	/**
	 * Slide an existing capture session forward by another idle window.
	 *
	 * Called from the capture path on every scan-tagged page load, and from the
	 * scans/heartbeat route — which exists because a fully page-cached site
	 * serves scanned pages straight off disk without booting PHP, so the capture
	 * path can silently never fire on exactly the large sites whose crawls
	 * outlast the window.
	 *
	 * Renewal is refused unless the presented scan_id matches the one the
	 * session was opened with, so two tabs still collide instead of one
	 * indefinitely renewing the other's lock. `created_at` is carried forward
	 * untouched and compared against BROWSER_SCAN_MAX_AGE, which is what stops
	 * a wedged tab from holding the lock forever.
	 *
	 * @param string $token   Session token; empty reads the httpOnly marker.
	 * @param string $scan_id Client scan identifier that must own the session.
	 * @return bool Whether the window was extended.
	 */
	public function touch_browser_scan_session( $token = '', $scan_id = '' ) {
		if ( '' === $token ) {
			if ( empty( $_COOKIE[ self::BROWSER_SCAN_COOKIE ] ) ) {
				return false;
			}
			$token = sanitize_key( wp_unslash( (string) $_COOKIE[ self::BROWSER_SCAN_COOKIE ] ) );
		} else {
			$token = sanitize_key( (string) $token );
		}
		$scan_id = sanitize_key( (string) $scan_id );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $scan_id ) ) {
			return false;
		}

		$session_key = self::browser_scan_transient_key( $token );
		$session     = get_transient( $session_key );
		$user_id     = get_current_user_id();
		if ( ! is_array( $session )
			|| empty( $session['user_id'] )
			|| $user_id !== absint( $session['user_id'] )
			|| ! isset( $session['scan_id'] )
			|| ! hash_equals( (string) $session['scan_id'], $scan_id ) ) {
			return false;
		}

		// Absolute-age ceiling. Sliding indefinitely would replace a bounded
		// fifteen-minute lockout with an unbounded one.
		$created_at = isset( $session['created_at'] ) ? absint( $session['created_at'] ) : 0;
		if ( $created_at > 0 && ( time() - $created_at ) > self::BROWSER_SCAN_MAX_AGE ) {
			return false;
		}

		set_transient( $session_key, $session, self::BROWSER_SCAN_TTL );

		$active_key = self::browser_scan_active_transient_key( $user_id );
		$active     = get_transient( $active_key );
		if ( ! is_array( $active ) ) {
			$active = array( 'token' => $token, 'scan_id' => $scan_id, 'created_at' => $created_at );
		}
		set_transient( $active_key, $active, self::BROWSER_SCAN_TTL );

		$this->issue_browser_scan_cookie( $token );

		return true;
	}

	/**
	 * Why a presented scan_id did not match the live session.
	 *
	 * "Expired" and "another tab is scanning" are the same 409 to the client but
	 * completely different problems for the administrator: one says the crawl
	 * outlived its window, the other says to close the other tab. Reporting them
	 * as one message is what let a fifteen-minute limit hide behind a
	 * conflict-shaped error.
	 *
	 * @param string $scan_id Client scan identifier.
	 * @return string 'match', 'conflict' or 'expired'.
	 */
	public function browser_scan_session_failure_reason( $scan_id ) {
		if ( $this->browser_scan_session_matches( $scan_id ) ) {
			return 'match';
		}
		$scan_id = sanitize_key( (string) $scan_id );
		$active  = get_transient( self::browser_scan_active_transient_key( get_current_user_id() ) );
		if ( is_array( $active ) && ! empty( $active['scan_id'] ) && ! hash_equals( (string) $active['scan_id'], $scan_id ) ) {
			return 'conflict';
		}
		return 'expired';
	}

	/**
	 * Read cookie metadata captured from scan-tagged runtime responses.
	 *
	 * Each row carries `admin_context`: true when the only sighting of that name
	 * was on the administrator's own wp-admin traffic, which the capture window
	 * necessarily also observes. The caller must report those rather than import
	 * them — see Api::import_cookies().
	 *
	 * This method is deliberately non-destructive. Api::import_cookies() may still
	 * fail while persisting the inventory, and the same scan id must remain
	 * retryable with the same server-captured evidence in that case. The caller
	 * closes the session explicitly with finish_browser_scan_session() only after
	 * the save commits.
	 *
	 * @param string $scan_id Client scan identifier.
	 * @return array[] Cookie inventory rows, without cookie values.
	 */
	public function collect_browser_scan_session( $scan_id = '' ) {
		$this->browser_scan_capture_truncated = false;
		if ( ! $this->browser_scan_session_matches( $scan_id ) ) {
			return array();
		}
		$token   = sanitize_key( wp_unslash( (string) $_COOKIE[ self::BROWSER_SCAN_COOKIE ] ) );
		$user_id = get_current_user_id();

		$cookies            = array();
		$active_observations = array();
		foreach ( (array) get_user_meta( $user_id, self::BROWSER_SCAN_META, false ) as $observation ) {
			if ( ! is_array( $observation ) || ! hash_equals( $token, isset( $observation['token'] ) ? (string) $observation['token'] : '' ) ) {
				continue;
			}
			if ( ! empty( $observation['truncated'] ) ) {
				$this->browser_scan_capture_truncated = true;
				continue;
			}
			$request_url = $this->observation_url( isset( $observation['request_path'] ) ? (string) $observation['request_path'] : '/' );
			$identity    = $this->set_cookie_identity( $observation, $request_url ) . '|' . ( empty( $observation['admin_context'] ) ? '0' : '1' );
			if ( $this->set_cookie_is_deletion( $observation, $this->observation_reference_time( $observation ) ) ) {
				unset( $active_observations[ $identity ] );
				continue;
			}
			$active_observations[ $identity ] = $observation;
		}

		foreach ( $active_observations as $observation ) {
			$name = isset( $observation['name'] ) ? sanitize_text_field( $observation['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$admin_context = ! empty( $observation['admin_context'] );
			if ( isset( $cookies[ $name ] ) ) {
				// One row per name, but a name seen from BOTH sides belongs to
				// the site: a front-end sighting always wins over an admin one,
				// whichever arrived first.
				if ( $admin_context || empty( $cookies[ $name ]['admin_context'] ) ) {
					continue;
				}
			}
			$duration = 'session';
			// Same Max-Age validator as set_cookie_is_deletion(): unparseable
			// text is ignored so a valid Expires can still supply the lifetime.
			$raw_max_age = isset( $observation['max-age'] ) ? trim( (string) $observation['max-age'] ) : '';
			if ( '' !== $raw_max_age && preg_match( '/^-?[0-9]+$/', $raw_max_age ) ) {
				$max_age = (int) $raw_max_age;
				if ( $max_age > 0 ) {
					$duration = $this->seconds_to_human( $max_age );
				}
			} elseif ( ! empty( $observation['expires'] ) ) {
				// Expires is absolute, so it means what it meant when the header
				// was seen — the same reasoning that gave set_cookie_is_deletion()
				// its reference-time argument. Judging it against the current
				// clock here would undo half of that: a cookie whose expiry fell
				// inside the crawl window now correctly survives the deletion
				// check, only to be reported as 'session' instead of its real
				// lifetime.
				$expires   = strtotime( $observation['expires'] );
				$seen_at   = $this->observation_reference_time( $observation );
				if ( false !== $expires && $expires > $seen_at ) {
					$duration = $this->seconds_to_human( $expires - $seen_at );
				}
			}
			$cookies[ $name ] = array(
				'name'          => $name,
				'domain'        => ! empty( $observation['domain'] ) ? sanitize_text_field( $observation['domain'] ) : wp_parse_url( home_url(), PHP_URL_HOST ),
				'duration'      => $duration,
				'description'   => '',
				'category'      => 'uncategorized',
				'source'        => $admin_context ? 'admin-runtime' : 'server-runtime',
				'admin_context' => $admin_context,
			);
		}
		return array_values( $cookies );
	}

	/**
	 * Permanently close a browser capture after a successful import or abort.
	 *
	 * Every destructive session operation lives here so the import failure path
	 * cannot consume observations, transients or the marker by accident.
	 *
	 * @param string $scan_id Client scan identifier.
	 * @return bool Whether a matching session was closed.
	 */
	public function finish_browser_scan_session( $scan_id = '' ) {
		if ( ! $this->browser_scan_session_matches( $scan_id ) ) {
			return false;
		}
		$token   = sanitize_key( wp_unslash( (string) $_COOKIE[ self::BROWSER_SCAN_COOKIE ] ) );
		$user_id = get_current_user_id();
		foreach ( (array) get_user_meta( $user_id, self::BROWSER_SCAN_META, false ) as $observation ) {
			if ( is_array( $observation ) && hash_equals( $token, isset( $observation['token'] ) ? (string) $observation['token'] : '' ) ) {
				delete_user_meta( $user_id, self::BROWSER_SCAN_META, $observation );
			}
		}
		delete_transient( self::browser_scan_transient_key( $token ) );
		delete_transient( self::browser_scan_active_transient_key( $user_id ) );
		if ( ! headers_sent() ) {
			setcookie(
				self::BROWSER_SCAN_COOKIE,
				'',
				array(
					'expires'  => time() - YEAR_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Strict',
				)
			);
		}

		return true;
	}

	/**
	 * Verify that an import belongs to the active marker/session pair.
	 *
	 * @param string $scan_id Client scan identifier.
	 * @return bool
	 */
	public function browser_scan_session_matches( $scan_id ) {
		if ( empty( $_COOKIE[ self::BROWSER_SCAN_COOKIE ] ) ) {
			return false;
		}
		$token   = sanitize_key( wp_unslash( (string) $_COOKIE[ self::BROWSER_SCAN_COOKIE ] ) );
		$scan_id = sanitize_key( (string) $scan_id );
		$session = get_transient( self::browser_scan_transient_key( $token ) );
		return preg_match( '/^[a-f0-9]{32}$/', $token )
			&& preg_match( '/^[a-f0-9]{32}$/', $scan_id )
			&& is_array( $session )
			&& isset( $session['user_id'] )
			&& get_current_user_id() === absint( $session['user_id'] )
			&& isset( $session['scan_id'] )
			&& hash_equals( (string) $session['scan_id'], $scan_id );
	}

	/**
	 * Release a failed/cancelled browser scan without importing observations.
	 *
	 * @param string $scan_id Client scan identifier.
	 * @return bool
	 */
	public function abort_browser_scan_session( $scan_id ) {
		return $this->finish_browser_scan_session( $scan_id );
	}

	/** @return bool */
	public function browser_scan_capture_was_truncated() {
		return $this->browser_scan_capture_truncated;
	}

	/**
	 * Extract cookie names from an HTTP Cookie header and parsed cookie map.
	 *
	 * @param string $header Raw Cookie request header.
	 * @param array  $parsed Parsed request cookies (normally $_COOKIE).
	 * @return string[]
	 */
	public function extract_request_cookie_names( $header, $parsed = array() ) {
		$names = array();
		foreach ( explode( ';', (string) $header ) as $pair ) {
			$equals = strpos( $pair, '=' );
			$name   = trim( false === $equals ? $pair : substr( $pair, 0, $equals ) );
			if ( '' !== $name ) {
				$names[ $name ] = true;
			}
		}
		// PHP normalizes dots and spaces in $_COOKIE keys. Prefer the raw header
		// whenever available so `foo.bar` never becomes a second `foo_bar` row.
		if ( empty( $names ) ) {
			foreach ( array_keys( (array) $parsed ) as $name ) {
				$names[ (string) $name ] = true;
			}
		}

		$result = array();
		foreach ( array_keys( $names ) as $name ) {
			if ( strlen( $name ) <= 200 && ! preg_match( '/[=;,\s\x00-\x1F\x7F]/', $name ) ) {
				$result[] = sanitize_text_field( $name );
			}
		}
		return array_values( array_unique( $result ) );
	}

	/** @return string */
	private static function browser_scan_transient_key( $token ) {
		return 'faz_scan_session_' . hash( 'sha256', (string) $token );
	}

	/** @return string */
	private static function browser_scan_active_transient_key( $user_id ) {
		return 'faz_scan_active_' . absint( $user_id );
	}

	/**
	 * Where a completed import's response is remembered, keyed by scan id.
	 *
	 * @param int    $user_id Owner.
	 * @param string $scan_id Scan identifier.
	 * @return string
	 */
	private static function browser_scan_result_transient_key( $user_id, $scan_id ) {
		return 'faz_scan_done_' . absint( $user_id ) . '_' . hash( 'sha256', (string) $scan_id );
	}

	/**
	 * Keep a failed import's evidence so the same scan can be retried.
	 *
	 * Persistence failing is the one case where throwing the capture away costs
	 * the administrator the whole crawl: the observations are the only record of
	 * cookies set before consent, and a browser scan can take many minutes to
	 * reproduce. The session, its observations and the marker cookie all stay.
	 *
	 * The session is marked `held` rather than merely left alone, because those
	 * are different states to everyone who looks at it. A live session is being
	 * driven by a tab that is still crawling; a held one is being kept for a
	 * retry that may never come. start_browser_scan_session() reclaims the
	 * second and refuses the first — without that distinction, holding the
	 * evidence would lock this administrator out of scanning for the whole idle
	 * window, which is precisely what the client's abort-on-failure existed to
	 * prevent.
	 *
	 * @param string $scan_id Scan identifier whose session should be held.
	 * @return bool True when a session was held.
	 */
	public function hold_browser_scan_session( $scan_id ) {
		if ( ! $this->browser_scan_session_matches( $scan_id ) ) {
			return false;
		}

		$user_id    = get_current_user_id();
		$active_key = self::browser_scan_active_transient_key( $user_id );
		$active     = get_transient( $active_key );
		if ( ! is_array( $active ) ) {
			return false;
		}

		$active['state']   = 'held';
		$active['held_at'] = time();
		set_transient( $active_key, $active, self::BROWSER_SCAN_TTL );

		// Slide the session transient too. The client stops its heartbeat once
		// the run has failed, so without this the retry window is whatever was
		// left of the last touch rather than a full one.
		$token = sanitize_key( wp_unslash( (string) $_COOKIE[ self::BROWSER_SCAN_COOKIE ] ) );
		$session = get_transient( self::browser_scan_transient_key( $token ) );
		if ( is_array( $session ) ) {
			set_transient( self::browser_scan_transient_key( $token ), $session, self::BROWSER_SCAN_TTL );
		}

		return true;
	}

	/**
	 * Drop a held session's evidence so a new scan can take its place.
	 *
	 * Only ever called for a session already established as `held`. It cannot
	 * reuse finish_browser_scan_session(), which reads the marker cookie of the
	 * CURRENT request — the tab starting the new scan may carry a different
	 * token, or none — so the token is taken from the active record instead.
	 *
	 * @param array $active The active-session record being discarded.
	 * @return void
	 */
	private function discard_held_browser_scan_session( $active ) {
		$user_id = get_current_user_id();
		$token   = sanitize_key( isset( $active['token'] ) ? (string) $active['token'] : '' );
		if ( '' !== $token ) {
			foreach ( (array) get_user_meta( $user_id, self::BROWSER_SCAN_META, false ) as $observation ) {
				if ( is_array( $observation ) && hash_equals( $token, isset( $observation['token'] ) ? (string) $observation['token'] : '' ) ) {
					delete_user_meta( $user_id, self::BROWSER_SCAN_META, $observation );
				}
			}
			delete_transient( self::browser_scan_transient_key( $token ) );
		}
		delete_transient( self::browser_scan_active_transient_key( $user_id ) );
	}

	/**
	 * Remember a completed import's response so a resubmit can be answered.
	 *
	 * An import is not idempotent on its own: the session is finished as part of
	 * a successful save, so a second submission of the same payload finds no
	 * session and is rejected as expired. That is the wrong answer when the
	 * first attempt actually succeeded and only its RESPONSE was lost — a
	 * dropped connection, a closed laptop, a proxy timeout. The client cannot
	 * tell that case apart from a genuine failure, so it retries, and the
	 * administrator is told a scan failed that in fact imported.
	 *
	 * Storing the response makes the resubmit answerable. Callers must write
	 * this BEFORE finishing the session: if the request dies between the two, a
	 * retry finds the record and reports the success that really happened. In
	 * the other order it would find nothing and report a failure that did not.
	 *
	 * @param string $scan_id Scan identifier.
	 * @param array  $result  The response body that was returned.
	 * @return void
	 */
	public function remember_browser_scan_result( $scan_id, $result ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! is_array( $result ) ) {
			return;
		}
		set_transient(
			self::browser_scan_result_transient_key( $user_id, sanitize_key( (string) $scan_id ) ),
			$result,
			self::BROWSER_SCAN_TTL
		);
	}

	/**
	 * The response a previous import for this scan id already returned, if any.
	 *
	 * @param string $scan_id Scan identifier.
	 * @return array|null Stored response, or null when this scan has not completed.
	 */
	public function recall_browser_scan_result( $scan_id ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}
		$stored = get_transient(
			self::browser_scan_result_transient_key( $user_id, sanitize_key( (string) $scan_id ) )
		);

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Schedule an async scan.
	 *
	 * Web SAPIs (PHP-FPM/Apache/CGI) hand the scan to WP-Cron plus a
	 * non-blocking loopback nudge — a child spawned with exec('… &') would be
	 * reaped when the FastCGI request ends. The detached PHP-CLI/WP-CLI exec
	 * spawn is the fast path only under the CLI SAPI (long-lived parent), and
	 * a best-effort fallback when DISABLE_WP_CRON leaves cron unable to
	 * self-trigger.
	 *
	 * @param int $max_pages Maximum pages to scan.
	 * @return array Current scan info.
	 */
	public function schedule_scan( $max_pages = 20 ) {
		$max_pages = self::normalize_max_pages( $max_pages );

		// A background process spawned with exec( '… &' ) only survives when the
		// parent is a long-lived CLI process (WP-CLI, real cron). Under a web
		// SAPI (PHP-FPM/Apache/CGI) the detached child is tied to the FastCGI
		// request worker and gets reaped when the request ends, so the scan dies
		// silently mid-crawl. Only take the exec fast-path when we ARE the CLI
		// SAPI; every web request goes through WP-Cron, which runs the scan in a
		// separate loopback worker that outlives the triggering request.
		if ( 'cli' === PHP_SAPI ) {
			$cmd = $this->build_exec_scan_command( $max_pages );
			if ( null !== $cmd ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Required for background scan.
				exec( $cmd ); // nosemgrep: php.lang.security.exec-use
				return $this->get_info();
			}
		}

		// Web request path: hand the scan to WP-Cron.
		update_option( 'faz_scan_max_pages', $max_pages, false );

		// WP-Cron disabled — it will never self-trigger. Try a detached CLI
		// spawn (best effort; may still be reaped under FPM), else run inline as
		// the last resort so the scan is not lost.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$cmd = $this->build_exec_scan_command( $max_pages );
			if ( null !== $cmd ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Required for background scan.
				exec( $cmd ); // nosemgrep: php.lang.security.exec-use
				return $this->get_info();
			}
			$this->run_scan( $max_pages );
			return $this->get_info();
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( time() + 1, self::CRON_HOOK );

		// Nudge WP-Cron with a non-blocking loopback so the scan starts now
		// instead of waiting for the next polling request to tick cron.
		$this->spawn_scan_cron();

		return $this->get_info();
	}

	/**
	 * Build the shell command that runs a scan in a detached background process,
	 * or null when no usable CLI spawn exists on this host.
	 *
	 * Not usable when exec() is disabled, no WP-CLI is in PATH, the run-scan.php
	 * bootstrap is absent (it is stripped from the wp.org ZIP), or no CLI PHP
	 * interpreter can be resolved. Callers fall back to WP-Cron in those cases.
	 *
	 * @param int $max_pages Maximum number of pages to crawl (already absint'd by caller).
	 * @return string|null Shell command ending in ' &', or null.
	 */
	private function build_exec_scan_command( $max_pages ) {
		if ( ! $this->can_spawn_background_process() ) {
			return null;
		}

		$abspath = ABSPATH;

		// Prefer WP-CLI (most reliable when present).
		$wp_cli = $this->find_wp_cli();
		if ( $wp_cli ) {
			// Build safe eval string — max_pages is already absint'd.
			$eval_code = 'FazCookie\\Admin\\Modules\\Scanner\\Includes\\Controller::get_instance()->run_scan(' . $max_pages . ');';
			$cmd_parts = array(
				escapeshellarg( $wp_cli ),
				'eval',
				escapeshellarg( $eval_code ),
				'--path=' . escapeshellarg( $abspath ),
			);
			return implode( ' ', $cmd_parts ) . ' > /dev/null 2>&1 &';
		}

		// Fallback: spawn PHP-CLI with the bootstrap script.
		$runner = ( defined( 'FAZ_PLUGIN_BASEPATH' ) ? FAZ_PLUGIN_BASEPATH : plugin_dir_path( __DIR__ ) . '../../../' ) . 'admin/modules/scanner/run-scan.php';
		$runner = realpath( $runner );
		$php    = $this->find_php_cli();
		if ( false !== $runner && 0 === strpos( $runner, realpath( FAZ_PLUGIN_BASEPATH ) ) && '' !== $php ) {
			return sprintf(
				'%s %s %s %d > /dev/null 2>&1 &',
				escapeshellarg( $php ),
				escapeshellarg( $runner ),
				escapeshellarg( $abspath ),
				$max_pages
			);
		}

		return null;
	}

	/**
	 * Fire a non-blocking loopback request to wp-cron.php so a scheduled scan
	 * event runs immediately in a fresh worker, rather than waiting for the next
	 * front-end/admin request to tick WP-Cron. Best-effort: failure is harmless
	 * because the polling requests will trigger the due event anyway.
	 *
	 * @return void
	 */
	private function spawn_scan_cron() {
		$cron_url = site_url( '/wp-cron.php?doing_wp_cron=' . rawurlencode( sprintf( '%.22F', microtime( true ) ) ) );
		wp_remote_post(
			$cron_url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/**
	 * Schedule a background server-side check of browser-visited URLs to detect
	 * Set-Cookie headers that JavaScript cannot read via document.cookie.
	 *
	 * Uses WP-Cron plus a non-blocking loopback nudge. Starting a detached shell
	 * process from PHP-FPM is not reliably detached on every host: inherited file
	 * descriptors can keep the REST import request open until the worker exits.
	 * Cron gives the queue a durable execution boundary and never makes the UI
	 * wait for the replay crawl.
	 *
	 * @param string[] $urls URLs actually visited by the browser scanner.
	 * @return int Number of URLs pending enrichment.
	 */
	public function schedule_httponly_check( $urls = array() ) {
		$safe_urls = $this->sanitize_scanned_urls( $urls );
		if ( empty( $safe_urls ) ) {
			$safe_urls = array( home_url( '/' ) );
		}
		$queued = $this->sanitize_scanned_urls( get_option( self::HTTPONLY_URLS_OPTION, array() ) );
		$queued = array_slice( array_values( array_unique( array_merge( $queued, $safe_urls ) ) ), 0, 2000 );
		update_option( self::HTTPONLY_URLS_OPTION, $queued, false );
		wp_clear_scheduled_hook( self::HTTPONLY_CRON_HOOK );
		wp_schedule_single_event( time() + 1, self::HTTPONLY_CRON_HOOK );
		$cron_spawn = wp_remote_post(
			site_url( '/wp-cron.php?doing_wp_cron=' . rawurlencode( sprintf( '%.22F', microtime( true ) ) ) ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $cron_spawn ) ) {
			// The event stays scheduled for the next normal/external cron tick.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[FAZ Scanner] Unable to nudge wp-cron for httpOnly check: ' . $cron_spawn->get_error_message() );
		}
		return count( $queued );
	}

	/**
	 * Run a server-side check for Set-Cookie headers on browser-visited URLs.
	 *
	 * Called as a background process so broad/deep scans do not hold the REST
	 * import open while each page is fetched again.
	 *
	 * @return void
	 */
	public function run_httponly_check() {
		$lock_time = absint( get_option( self::HTTPONLY_LOCK_OPTION, 0 ) );
		if ( $lock_time > 0 && $lock_time < time() - 600 ) {
			delete_option( self::HTTPONLY_LOCK_OPTION );
		}
		if ( ! add_option( self::HTTPONLY_LOCK_OPTION, time(), '', false ) ) {
			return;
		}

		$logger       = Scanner_Logger::get_instance();
		$logger->start( 'httpOnly cookie check' );
		$this->server_cookie_jar = array();
		$remaining = array();

		try {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.NoSilencedErrors -- background enrichment may revisit many admin-selected pages.
			@set_time_limit( 300 );
			$queued = $this->sanitize_scanned_urls( get_option( self::HTTPONLY_URLS_OPTION, array() ) );
			if ( empty( $queued ) ) {
				$queued = array( home_url( '/' ) );
			}
			$urls      = array_slice( $queued, 0, 20 );
			// Do not dequeue a batch before it is processed. A fatal/timeout must
			// leave the current and all following URLs available to recovery cron.
			if ( ! wp_next_scheduled( self::HTTPONLY_CRON_HOOK ) ) {
				wp_schedule_single_event( time() + 60, self::HTTPONLY_CRON_HOOK );
			}

			$logger->log( 'Checking ' . count( $urls ) . ' browser-visited URLs for Set-Cookie headers' );
			$cookies = array();
			foreach ( $urls as $url ) {
				$page_cookies = $this->scan_page( $url );
				$logger->log( 'Header enrichment: ' . $url . ' → ' . count( $page_cookies ) . ' cookies' );
				foreach ( $page_cookies as $cookie ) {
					if ( ! empty( $cookie['name'] ) && ! isset( $cookies[ $cookie['name'] ] ) ) {
						$cookies[ $cookie['name'] ] = $cookie;
					}
				}
				if ( ! empty( $page_cookies ) ) {
					// Checkpoint results before advancing the durable queue. If this
					// worker dies on a later URL, earlier findings remain persisted.
					$this->save_cookies( $page_cookies );
					// Saving the row was never the whole job. A cookie only this
					// replay ever sees — a first-visit session cookie issued to
					// this worker's private jar and to nobody else —
					// was being written to the catalogue on every run while its
					// consecutive-miss tally climbed toward deletion in parallel.
					// Checkpointed alongside the save for the same reason: a
					// worker that dies on a later URL must keep what it proved.
					$replayed_names = array();
					foreach ( $page_cookies as $page_cookie ) {
						if ( is_array( $page_cookie ) && ! empty( $page_cookie['name'] ) ) {
							$replayed_names[] = $page_cookie['name'];
						}
					}
					$this->clear_scan_observations( $replayed_names );
				}
				$latest = $this->sanitize_scanned_urls( get_option( self::HTTPONLY_URLS_OPTION, array() ) );
				$latest = array_values( array_diff( $latest, array( $url ) ) );
				if ( empty( $latest ) ) {
					delete_option( self::HTTPONLY_URLS_OPTION );
				} else {
					update_option( self::HTTPONLY_URLS_OPTION, $latest, false );
				}
			}

			$logger->log( 'Found ' . count( $cookies ) . ' unique cookies from Set-Cookie headers' );
			$remaining = $this->sanitize_scanned_urls( get_option( self::HTTPONLY_URLS_OPTION, array() ) );
		} finally {
			delete_option( self::HTTPONLY_LOCK_OPTION );
			$logger->finish();
		}

		if ( ! empty( $remaining ) ) {
			$this->schedule_httponly_check( $remaining );
		} else {
			wp_clear_scheduled_hook( self::HTTPONLY_CRON_HOOK );
		}
	}

	/**
	 * Validate browser-reported URLs before any server-side replay.
	 *
	 * @param array $urls Candidate URLs.
	 * @return string[] Same-site HTTP(S) URLs, capped to the scanner limit.
	 */
	public function sanitize_scanned_urls( $urls ) {
		$site_url    = wp_parse_url( home_url() );
		$site_host   = is_array( $site_url ) && ! empty( $site_url['host'] ) ? self::canonical_scan_host( $site_url['host'] ) : '';
		$site_scheme = is_array( $site_url ) && ! empty( $site_url['scheme'] ) ? strtolower( $site_url['scheme'] ) : 'https';
		$site_port   = is_array( $site_url ) && isset( $site_url['port'] ) ? absint( $site_url['port'] ) : ( 'https' === $site_scheme ? 443 : 80 );
		$result       = array();
		foreach ( (array) $urls as $url ) {
			$parsed = wp_parse_url( (string) $url );
			if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
				continue;
			}
			$scheme      = strtolower( $parsed['scheme'] );
			$host        = self::canonical_scan_host( $parsed['host'] );
			$port        = isset( $parsed['port'] ) ? absint( $parsed['port'] ) : ( 'https' === $scheme ? 443 : 80 );
			$hosts_match = self::scan_hosts_match( $host, $site_host );
			if ( $scheme !== $site_scheme || $port !== $site_port || ! $hosts_match || isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
				continue;
			}
			$normalized = esc_url_raw( $this->normalize_url( (string) $url ) );
			if ( '' !== $normalized ) {
				$result[ $normalized ] = true;
			}
			if ( count( $result ) >= 2000 ) {
				break;
			}
		}
		return array_keys( $result );
	}

	/**
	 * Find WP-CLI binary path.
	 *
	 * @return string|false Path to wp binary, or false if not found.
	 */
	private function find_wp_cli() {
		if ( ! $this->can_spawn_background_process() ) {
			return false;
		}

		$output = array();
		$code   = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( 'which wp 2>/dev/null', $output, $code ); // nosemgrep: php.lang.security.exec-use
		if ( 0 === $code && ! empty( $output[0] ) ) {
			return trim( $output[0] );
		}
		// Common paths.
		$paths = array( '/usr/local/bin/wp', '/opt/homebrew/bin/wp' );
		foreach ( $paths as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}
		return false;
	}

	/**
	 * Resolve a PHP *CLI* interpreter for spawning the background scan.
	 *
	 * PHP_BINARY points at the current SAPI's executable, so under PHP-FPM/CGI
	 * it is the php-fpm binary — running `php-fpm run-scan.php` does nothing.
	 * Only trust PHP_BINARY when we are actually the CLI SAPI; otherwise derive
	 * the CLI interpreter from PHP_BINDIR (compile-time, shared with FPM) and
	 * common locations, then finally the shell PATH.
	 *
	 * @return string Absolute path to a PHP CLI binary, or '' when none is
	 *                resolvable — callers (build_exec_scan_command) treat ''
	 *                as "no usable CLI spawn" and fall back to WP-Cron.
	 */
	private function find_php_cli() {
		if ( 'cli' === PHP_SAPI && defined( 'PHP_BINARY' ) && '' !== PHP_BINARY ) {
			return PHP_BINARY;
		}

		$candidates = array();
		if ( defined( 'PHP_BINDIR' ) && '' !== PHP_BINDIR ) {
			$candidates[] = rtrim( PHP_BINDIR, '/\\' ) . '/php';
		}
		$candidates[] = '/usr/local/bin/php';
		$candidates[] = '/opt/homebrew/bin/php';
		$candidates[] = '/usr/bin/php';
		foreach ( $candidates as $candidate ) {
			if ( @is_executable( $candidate ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir may block the stat; treat as "not found".
				return $candidate;
			}
		}

		// Last resort: resolve from the shell PATH.
		$output = array();
		$code   = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( 'command -v php 2>/dev/null', $output, $code ); // nosemgrep: php.lang.security.exec-use
		if ( 0 === $code && ! empty( $output[0] ) ) {
			return trim( $output[0] );
		}

		// Nothing resolvable. Return '' — NOT a blind 'php' literal, which
		// would exec() an interpreter we never proved exists and make
		// build_exec_scan_command()'s "no CLI → WP-Cron fallback" contract
		// unreachable dead code.
		return '';
	}

	/**
	 * Check if shell process spawning is available on this host.
	 *
	 * @return bool
	 */
	private function can_spawn_background_process() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$disabled = (string) ini_get( 'disable_functions' );
		if ( '' === trim( $disabled ) ) {
			return true;
		}

		$list = array_map( 'trim', explode( ',', $disabled ) );
		return ! in_array( 'exec', $list, true );
	}

	/**
	 * WP-Cron callback — runs the actual scan. The PRIMARY path for every
	 * web-SAPI-triggered scan (schedule_scan schedules this event and nudges
	 * wp-cron.php); the CLI exec spawn is the exception, not the rule.
	 */
	public function run_scan_async() {
		$max_pages = self::normalize_max_pages( get_option( 'faz_scan_max_pages', 20 ) );
		try {
			$this->run_scan( $max_pages );
		} catch ( \Throwable $e ) {
			$this->record_scan_failure( 'local', $e->getMessage() );
			error_log( 'FAZ: scheduled scan failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- cron has no response channel.
		}
	}

	/**
	 * Run a full local cookie scan.
	 *
	 * @param int $max_pages Maximum number of pages to crawl.
	 * @return array Scan results summary.
	 */
	public function run_scan( $max_pages = 20 ) {
		$max_pages = self::normalize_max_pages( $max_pages );
		// Scanning makes many HTTP requests; prevent PHP timeout.
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.NoSilencedErrors -- scanner crawls 20-1000 URLs over wp_remote_get; PHP default max_execution_time (30s) consistently truncates the run on medium-sized sites. 5-minute window is the standard pattern for long-running plugin batch jobs (importers, scanners). Suppressed @ — read-only access on hardened hosts where set_time_limit is disabled returns false silently rather than emitting a warning.
		@set_time_limit( 300 );

		$logger = Scanner_Logger::get_instance();
		$logger->start( 'Server-side scan (run_scan)' );

		try {
			$this->scanned_embed_urls = array();
			$this->server_cookie_jar  = array();
			$logger->log( 'Max pages: ' . $max_pages );

			$site_url = home_url( '/' );
			$pages    = $this->discover_pages( $site_url, $max_pages );
			$logger->log( 'Discovered ' . count( $pages ) . ' pages to scan' );
			$cookies  = array();

			foreach ( $pages as $url ) {
				$page_cookies = $this->scan_page( $url );
				$logger->log( 'Scanned: ' . $url . ' → ' . count( $page_cookies ) . ' cookies' );
				foreach ( $page_cookies as $cookie_data ) {
					$name = $cookie_data['name'];
					// Deduplicate by cookie name, keeping first occurrence.
					if ( ! isset( $cookies[ $name ] ) ) {
						$cookies[ $name ] = $cookie_data;
					}
				}
			}

			// Infer Known_Providers cookies from embedded scripts/iframes seen
			// across the crawl, so a provider present only as an embed (e.g. a
			// blocked YouTube video) becomes a detected per-service even when its
			// cookie is never set on a block-first site (#134/#146).
			//
			// extract_embed_urls() harvests every `*src`-shaped attribute on a
			// <script>/<iframe>, which includes lazy-loader thumbnails
			// (data-thumb-src="…/hqdefault.jpg"). Those are passive assets that
			// set nothing, but they still contain provider tokens — so they go
			// through the same import-boundary filter the browser-scan path uses
			// before they are allowed near the pattern matchers (F019).
			if ( ! empty( $this->scanned_embed_urls ) ) {
				$embed_urls     = self::filter_inferable_script_urls( array_values( array_unique( $this->scanned_embed_urls ) ) );
				$embed_inferred = $this->infer_cookies_from_scripts( $embed_urls );
				$embed_added    = 0;
				foreach ( $embed_inferred as $inf ) {
					if ( empty( $inf['name'] ) || isset( $cookies[ $inf['name'] ] ) ) {
						continue;
					}
					$cookies[ $inf['name'] ] = $inf;
					++$embed_added;
				}
				$logger->log( 'Embed inference: +' . $embed_added . ' cookies from ' . count( $embed_urls ) . ' inferable embed URLs (' . count( $this->scanned_embed_urls ) . ' seen)' );
			}

			$total_cookies = count( $cookies );
			$logger->log( 'Total unique cookies discovered: ' . $total_cookies );
			// new_cookies = rows actually ADDED to the catalogue this run.
			// total_cookies counts every unique cookie this scan observed, so on
			// a re-scan the two diverge — the wizard reports both to stay honest
			// ("57 detected, none new" instead of a bare "57 found").
			$new_cookies = $this->save_cookies( $cookies );

			$scan_id = absint( get_option( 'faz_scan_counter', 0 ) ) + 1;
			update_option( 'faz_scan_counter', $scan_id, false );

			$this->update_info(
				array(
					'id'            => $scan_id,
					'status'        => 'completed',
					'type'          => 'local',
					'date'          => current_time( 'mysql' ),
					'total_cookies' => $total_cookies,
					'new_cookies'   => $new_cookies,
					'pages_scanned' => count( $pages ),
				)
			);

			// Store scan history entry.
			$history   = get_option( 'faz_scan_history', array() );
			$history[] = array(
				'id'            => $scan_id,
				'status'        => 'completed',
				'type'          => 'local',
				'date'          => current_time( 'mysql' ),
				'total_cookies' => $total_cookies,
				'pages_scanned' => count( $pages ),
			);
			// Keep only last 50 entries.
			if ( count( $history ) > 50 ) {
				$history = array_slice( $history, -50 );
			}
			update_option( 'faz_scan_history', $history, false );

			$logger->log( 'Server-side scan result: scan_id=' . $scan_id . ', total_cookies=' . $total_cookies . ', pages=' . count( $pages ) );

			return $this->get_info();
		} finally {
			$logger->finish();
		}
	}

	/**
	 * Normalize a URL for deduplication: remove fragment, preserve query string,
	 * and enforce trailing slash consistency.
	 *
	 * No additional URL encoding/decoding is performed. The query string is
	 * preserved as parsed and re-appended when present.
	 *
	 * @param string $url URL to normalize for deduplication.
	 * @return string URL with normalized trailing slash, preserved query string, and no fragment.
	 */
	public function normalize_url( $url ) {
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return trailingslashit( $url );
		}
		$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'http';
		$host   = $parsed['host'];
		$port   = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
		$path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
		$query  = isset( $parsed['query'] ) && '' !== $parsed['query'] ? '?' . $parsed['query'] : '';

		$last_segment = basename( $path );
		$is_file_path = '/' !== substr( $path, -1 )
			&& false !== strpos( $last_segment, '.' )
			&& 0 !== strpos( $last_segment, '.' );
		$normalized_path = $is_file_path ? $path : trailingslashit( $path );

		return $scheme . '://' . $host . $port . $normalized_path . $query;
	}

	/**
	 * Collect normalized, deduplicated permalink URLs from an array of post IDs.
	 *
	 * @param int[]  $post_ids Post IDs to resolve permalinks for.
	 * @param array  &$pages   Pages array to append to (passed by reference).
	 * @param array  &$seen    Seen-URL hash map (passed by reference).
	 * @param int    $max      Maximum total pages to collect.
	 * @return void
	 */
	private function collect_post_urls( $post_ids, &$pages, &$seen, $max ) {
		foreach ( $post_ids as $post_id ) {
			$url = get_permalink( $post_id );
			if ( ! $url ) {
				continue;
			}
			$normalized = $this->normalize_url( $url );
			if ( ! isset( $seen[ $normalized ] ) ) {
				$seen[ $normalized ] = true;
				$pages[]             = $normalized;
				if ( count( $pages ) >= $max ) {
					break;
				}
			}
		}
	}

	/**
	 * Discover pages using WordPress database queries (no HTTP requests).
	 *
	 * Used by the browser-based scanner's discover endpoint to avoid
	 * loopback deadlocks on single-threaded dev servers. Queries
	 * published posts, pages, and custom post types directly.
	 *
	 * @param int $max Maximum number of pages.
	 * @return array List of URLs.
	 */
	public function discover_pages_from_db( $max ) {
		$max = absint( $max );
		if ( $max < 1 ) {
			return array();
		}

		$home    = $this->normalize_url( home_url( '/' ) );
		$pages   = array( $home );
		$seen    = array( $home => true );

		// Get published posts and pages.
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$posts      = get_posts(
			array(
				'post_type'              => array_values( $post_types ),
				'post_status'            => 'publish',
				'posts_per_page'         => $max,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$this->collect_post_urls( $posts, $pages, $seen, $max );

		// Add category/tag archive pages if we still have room.
		if ( count( $pages ) < $max ) {
			$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
			$terms      = get_terms(
				array(
					'taxonomy'   => array_values( $taxonomies ),
					'hide_empty' => true,
					'number'     => $max - count( $pages ),
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$url = get_term_link( $term );
					if ( is_wp_error( $url ) ) {
						continue;
					}
					$normalized = $this->normalize_url( $url );
					if ( ! isset( $seen[ $normalized ] ) ) {
						$seen[ $normalized ] = true;
						$pages[]             = $normalized;
						if ( count( $pages ) >= $max ) {
							break;
						}
					}
				}
			}
		}

		return array_slice( $pages, 0, $max );
	}

	/**
	 * Discover pages to scan from sitemap.xml and homepage links.
	 *
	 * @param string $site_url The site URL.
	 * @param int    $max      Maximum number of pages.
	 * @return array List of URLs.
	 */
	public function discover_pages( $site_url, $max ) {
		$pages = array( $site_url );

		// Try sitemap.xml.
		$sitemap_url = trailingslashit( $site_url ) . 'sitemap.xml';
		$response    = $this->remote_get(
			$sitemap_url,
			array(
				'timeout'   => 15,
				'sslverify' => (bool) apply_filters( 'faz_scanner_sslverify', true, $sitemap_url ),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = wp_remote_retrieve_body( $response );
			if ( ! empty( $body ) ) {
				// Suppress XML errors and parse.
				$previous = libxml_use_internal_errors( true );
				$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
				libxml_use_internal_errors( $previous );

				if ( false !== $xml ) {
					// Handle sitemap index (contains other sitemaps).
					if ( isset( $xml->sitemap ) ) {
						foreach ( $xml->sitemap as $sitemap ) {
							if ( isset( $sitemap->loc ) ) {
								$sub_url = (string) $sitemap->loc;
								// Validate sitemap URL belongs to the same host.
								if ( wp_parse_url( $sub_url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
									continue;
								}
								$sub_response = $this->remote_get(
									$sub_url,
									array(
										'timeout'     => 15,
										'sslverify'   => (bool) apply_filters( 'faz_scanner_sslverify', true, $sub_url ),
										'redirection' => 0,
									)
								);
								if ( ! is_wp_error( $sub_response ) && 200 === wp_remote_retrieve_response_code( $sub_response ) ) {
									$sub_body = wp_remote_retrieve_body( $sub_response );
									$previous = libxml_use_internal_errors( true );
									$sub_xml  = simplexml_load_string( $sub_body, 'SimpleXMLElement', LIBXML_NONET );
									libxml_use_internal_errors( $previous );
									if ( false !== $sub_xml && isset( $sub_xml->url ) ) {
										foreach ( $sub_xml->url as $url_entry ) {
											if ( isset( $url_entry->loc ) ) {
												$pages[] = (string) $url_entry->loc;
												if ( count( $pages ) >= $max ) {
													break 2;
												}
											}
										}
									}
								}
							}
							if ( count( $pages ) >= $max ) {
								break;
							}
						}
					}
					// Handle regular URL sitemap.
					if ( isset( $xml->url ) ) {
						foreach ( $xml->url as $url_entry ) {
							if ( isset( $url_entry->loc ) ) {
								$pages[] = (string) $url_entry->loc;
								if ( count( $pages ) >= $max ) {
									break;
								}
							}
						}
					}
				}
			}
		}

		// If sitemap didn't yield enough pages, parse homepage links.
		if ( count( $pages ) < $max ) {
			$homepage_response = $this->remote_get(
				$site_url,
				array(
					'timeout'   => 15,
					'sslverify' => (bool) apply_filters( 'faz_scanner_sslverify', true, $site_url ),
				)
			);
			if ( ! is_wp_error( $homepage_response ) && 200 === wp_remote_retrieve_response_code( $homepage_response ) ) {
				$html = wp_remote_retrieve_body( $homepage_response );
				$host = wp_parse_url( $site_url, PHP_URL_HOST );
				if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
					foreach ( $matches[1] as $href ) {
						$parsed = wp_parse_url( $href );
						// Only follow internal links.
						if ( isset( $parsed['host'] ) && $parsed['host'] !== $host ) {
							continue;
						}
						// Build absolute URL for relative links.
						if ( ! isset( $parsed['host'] ) ) {
							$href = trailingslashit( $site_url ) . ltrim( $href, '/' );
						}
						// Skip anchors, mailto, tel, javascript.
						if ( preg_match( '/^(#|mailto:|tel:|javascript:)/i', $href ) ) {
							continue;
						}
						// Skip non-page resources.
						if ( preg_match( '/\.(jpg|jpeg|png|gif|svg|css|js|pdf|zip|xml)(\?|$)/i', $href ) ) {
							continue;
						}
						if ( ! in_array( $href, $pages, true ) ) {
							$pages[] = $href;
							if ( count( $pages ) >= $max ) {
								break;
							}
						}
					}
				}
			}
		}

		return array_unique( array_slice( $pages, 0, $max ) );
	}

	/**
	 * Scan a single page for cookies via Set-Cookie headers.
	 *
	 * @param string $url URL to scan.
	 * @return array Array of discovered cookie data.
	 */
	public function scan_page( $url ) {
		$cookies      = array();
		$raw_cookies  = array();
		$safe_initial = $this->sanitize_scanned_urls( array( $url ) );
		if ( empty( $safe_initial ) ) {
			return $cookies;
		}

		$current   = $safe_initial[0];
		$site_host = self::canonical_scan_host( wp_parse_url( home_url(), PHP_URL_HOST ) );

		// Follow redirects manually. Automatic redirects validate only the first
		// URL and also hide Set-Cookie headers emitted by intermediate hops.
		for ( $hop = 0; $hop < 4; ++$hop ) {
			$parsed       = wp_parse_url( $current );
			$current_host = is_array( $parsed ) && isset( $parsed['host'] ) ? self::canonical_scan_host( $parsed['host'] ) : '';
			$is_loopback  = self::is_loopback_scan_host( $site_host )
				&& self::is_loopback_scan_host( $current_host )
				&& self::scan_hosts_match( $current_host, $site_host );
			$response = $this->remote_get(
				$current,
				array(
					'timeout'            => 15,
					'sslverify'          => (bool) apply_filters( 'faz_scanner_sslverify', ! $is_loopback, $current ),
					'redirection'        => 0,
					'reject_unsafe_urls' => ! $is_loopback,
					'cookies'            => array_values( $this->server_cookie_jar ),
				)
			);
			if ( is_wp_error( $response ) ) {
				break;
			}

			$response_set_cookie_headers = $this->get_set_cookie_headers( $response );
			foreach ( $response_set_cookie_headers as $set_cookie_header ) {
				$raw_cookies[] = array(
					'header'      => $set_cookie_header,
					'request_url' => $current,
				);
			}
			foreach ( (array) wp_remote_retrieve_cookies( $response ) as $response_cookie ) {
				if ( ! is_object( $response_cookie ) || empty( $response_cookie->name ) ) {
					continue;
				}
				$jar_key = $this->set_cookie_identity(
					array(
						'name'   => (string) $response_cookie->name,
						'domain' => isset( $response_cookie->domain ) ? (string) $response_cookie->domain : '',
						'path'   => isset( $response_cookie->path ) ? (string) $response_cookie->path : '',
					),
					$current
				);
				$this->server_cookie_jar[ $jar_key ] = $response_cookie;
			}
			// Requests may expose an already-expired cookie object. Apply the raw
			// mutation stream last so a clearing directive cannot be resurrected
			// and sent to a redirect target as an active jar entry.
			foreach ( $response_set_cookie_headers as $set_cookie_header ) {
				$parsed_set_cookie = $this->parse_set_cookie( $set_cookie_header );
				if ( ! empty( $parsed_set_cookie['name'] ) && $this->set_cookie_is_deletion( $parsed_set_cookie ) ) {
					unset( $this->server_cookie_jar[ $this->set_cookie_identity( $parsed_set_cookie, $current ) ] );
				}
			}
			foreach ( $this->extract_embed_urls( wp_remote_retrieve_body( $response ) ) as $embed_url ) {
				$this->scanned_embed_urls[] = $embed_url;
			}

			$status = wp_remote_retrieve_response_code( $response );
			if ( $status < 300 || $status >= 400 ) {
				break;
			}
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( is_array( $location ) ) {
				$location = end( $location );
			}
			if ( empty( $location ) ) {
				break;
			}
			$next = \WP_Http::make_absolute_url( (string) $location, $current );
			$safe = $this->sanitize_scanned_urls( array( $next ) );
			if ( empty( $safe ) ) {
				break;
			}
			$current = $safe[0];
		}

		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( $raw_cookies as $cookie_directive ) {
			$cookie_string = is_array( $cookie_directive ) && isset( $cookie_directive['header'] ) ? $cookie_directive['header'] : $cookie_directive;
			$request_url   = is_array( $cookie_directive ) && isset( $cookie_directive['request_url'] ) ? $cookie_directive['request_url'] : $url;
			$parsed        = $this->parse_set_cookie( $cookie_string );
			if ( empty( $parsed['name'] ) ) {
				continue;
			}

			$name     = $parsed['name'];
			$scope    = $this->set_cookie_scope( $parsed, $request_url );
			$domain   = $scope['domain'] ? $scope['domain'] : $site_domain;
			$identity = $this->set_cookie_identity( $parsed, $request_url );

			// Set-Cookie is ordered. A clearing directive removes only the
			// matching name/domain/path observation and must be handled before a
			// known-cookie lookup can turn it back into a catalogue declaration.
			if ( $this->set_cookie_is_deletion( $parsed ) ) {
				unset( $cookies[ $identity ] );
				continue;
			}

			// Look up in known cookies database.
			$known = Cookie_Database::lookup( $name );

			if ( $known ) {
				$cookies[ $identity ] = array(
					'name'        => $name,
					'domain'      => $domain,
					'duration'    => $known['duration'],
					'description' => $known['description'],
					'category'    => $known['category'],
				);
				continue;
			}

			// Fallback: Open Cookie Database (1400+ cookie definitions).
			$ocd = Cookie_Definitions::get_instance()->lookup( $name );
			if ( $ocd ) {
				$cookies[ $identity ] = array(
					'name'        => $name,
					'domain'      => $domain,
					'duration'    => ! empty( $ocd['duration'] ) ? $ocd['duration'] : 'session',
					'description' => ! empty( $ocd['description'] ) ? $ocd['description'] : '',
					'category'    => ! empty( $ocd['category'] ) ? $ocd['category'] : 'uncategorized',
				);
				continue;
			}

			// Unknown active cookie — extract its positive lifetime from headers.
			// Same Max-Age validator as set_cookie_is_deletion(): a remote server
			// can send unparseable text, which must defer to Expires rather than
			// cast to 0 and declare a multi-year cookie session-scoped.
			$duration    = 'session';
			$raw_max_age = isset( $parsed['max-age'] ) ? trim( (string) $parsed['max-age'] ) : '';
			if ( '' !== $raw_max_age && preg_match( '/^-?[0-9]+$/', $raw_max_age ) ) {
				$max_age = (int) $raw_max_age;
				if ( $max_age > 0 ) {
					$duration = $this->seconds_to_human( $max_age );
				}
			} elseif ( ! empty( $parsed['expires'] ) ) {
				$expires_time = strtotime( $parsed['expires'] );
				if ( false !== $expires_time && $expires_time > time() ) {
					$duration = $this->seconds_to_human( $expires_time - time() );
				}
			}

			$cookies[ $identity ] = array(
				'name'        => $name,
				'domain'      => $domain,
				'duration'    => $duration,
				'description' => '',
				'category'    => 'uncategorized',
			);
		}

		return array_values( $cookies );
	}

	/**
	 * Return every Set-Cookie header from a WordPress HTTP response.
	 *
	 * @param array|\WP_Error $response HTTP response.
	 * @return string[]
	 */
	public function get_set_cookie_headers( $response ) {
		$headers = wp_remote_retrieve_headers( $response );
		if ( $headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary || ( class_exists( '\Requests_Utility_CaseInsensitiveDictionary' ) && $headers instanceof \Requests_Utility_CaseInsensitiveDictionary ) ) {
			$all = $headers->getAll();
			return isset( $all['set-cookie'] ) ? (array) $all['set-cookie'] : array();
		}
		if ( is_array( $headers ) && isset( $headers['set-cookie'] ) ) {
			return (array) $headers['set-cookie'];
		}
		return array();
	}

	/**
	 * Absolute URL for a path taken from REQUEST_URI.
	 *
	 * REQUEST_URI already carries the subdirectory a site may be installed in,
	 * so home_url() would prefix it a second time ('/blog/shop' becoming
	 * '/blog/blog/shop') and hand set_cookie_scope() a wrong RFC 6265
	 * default-path. Build the URL from the site root instead.
	 *
	 * @param string $request_path Path component of the observed request.
	 * @return string
	 */
	public function observation_url( $request_path ) {
		$path = (string) $request_path;
		// Collapse a leading '//' too, not just a missing slash: a path of
		// '//evil.com/x' would otherwise survive the guard and yield
		// 'https://site//evil.com/x', from which set_cookie_scope() derives a
		// default-path of '//evil.com'. The host is taken from home_url() and
		// the URL is never fetched, so this is a malformed capture key rather
		// than a request-forgery hole — but a malformed key still splits one
		// cookie into two rows.
		if ( '' === $path || '/' !== substr( $path, 0, 1 ) || 0 === strpos( $path, '//' ) ) {
			$path = '/' . ltrim( $path, '/' );
		}

		$home   = (array) wp_parse_url( home_url( '/' ) );
		$scheme = ! empty( $home['scheme'] ) ? $home['scheme'] : 'http';
		$host   = ! empty( $home['host'] ) ? $home['host'] : '';
		$port   = ! empty( $home['port'] ) ? ':' . $home['port'] : '';

		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * The moment a persisted observation was seen, for judging absolute times.
	 *
	 * `Set-Cookie` attributes that carry an absolute instant — `Expires` — mean
	 * what they meant when the header arrived, not what they mean now. Rows sit
	 * in user meta for the length of a crawl, so anything comparing them to the
	 * clock has to say WHICH clock.
	 *
	 * A row missing `observed_at`, or carrying a non-positive one, falls back to
	 * the current time. `isset()` alone is not enough: a corrupt `0` would read
	 * as the epoch, every `Expires` would look like the future, and a genuine
	 * clearing directive would survive as a phantom active cookie — the exact
	 * inverse of the bug the reference time was introduced to fix.
	 *
	 * @param array $observation Persisted observation row.
	 * @return int Unix timestamp to judge absolute cookie attributes against.
	 */
	public function observation_reference_time( $observation ) {
		$observed_at = isset( $observation['observed_at'] ) ? (int) $observation['observed_at'] : 0;

		return $observed_at > 0 ? $observed_at : time();
	}

	/**
	 * Resolve the effective cookie scope used by the browser.
	 *
	 * Domain defaults to the response host. Path follows RFC 6265's
	 * default-path algorithm rather than assuming '/', because a clearing header
	 * only removes a prior cookie when all three identity coordinates match.
	 *
	 * @param array  $parsed      Parsed Set-Cookie attributes.
	 * @param string $request_url URL that emitted the header.
	 * @return array{domain:string,path:string}
	 */
	public function set_cookie_scope( $parsed, $request_url = '' ) {
		$domain = isset( $parsed['domain'] ) ? strtolower( ltrim( trim( (string) $parsed['domain'] ), '.' ) ) : '';
		if ( '' === $domain ) {
			$domain = strtolower( (string) wp_parse_url( $request_url ? $request_url : home_url(), PHP_URL_HOST ) );
		}

		$path = isset( $parsed['path'] ) ? trim( (string) $parsed['path'] ) : '';
		if ( '' === $path || '/' !== substr( $path, 0, 1 ) ) {
			$request_path = (string) wp_parse_url( $request_url ? $request_url : home_url(), PHP_URL_PATH );
			if ( '' === $request_path || '/' !== substr( $request_path, 0, 1 ) ) {
				$path = '/';
			} else {
				$last_slash = strrpos( $request_path, '/' );
				$path       = false === $last_slash || 0 === $last_slash ? '/' : substr( $request_path, 0, $last_slash );
			}
		}

		return array(
			'domain' => $domain,
			'path'   => $path,
		);
	}

	/**
	 * Canonical identity for ordered Set-Cookie mutation handling.
	 *
	 * @param array  $parsed      Parsed Set-Cookie attributes.
	 * @param string $request_url URL that emitted the header.
	 * @return string
	 */
	public function set_cookie_identity( $parsed, $request_url = '' ) {
		$scope = $this->set_cookie_scope( $parsed, $request_url );
		return strtolower( trim( isset( $parsed['name'] ) ? (string) $parsed['name'] : '' ) ) . '|' . $scope['domain'] . '|' . $scope['path'];
	}

	/**
	 * Whether a Set-Cookie directive expires its identity immediately.
	 *
	 * A syntactically valid Max-Age takes precedence over Expires. Invalid
	 * Max-Age text is ignored, allowing a valid Expires attribute to decide.
	 *
	 * Expires is absolute, so it has to be judged against the moment the header
	 * was seen. A persisted observation carries its own observed_at and must
	 * pass it: re-reading a row minutes later against the current clock would
	 * reclassify a short-lived cookie as a clearing directive and drop it.
	 *
	 * @param array    $parsed         Parsed Set-Cookie attributes.
	 * @param int|null $reference_time Moment the header was observed. Defaults to now.
	 * @return bool
	 */
	public function set_cookie_is_deletion( $parsed, $reference_time = null ) {
		$max_age = isset( $parsed['max-age'] ) ? trim( (string) $parsed['max-age'] ) : '';
		if ( '' !== $max_age && preg_match( '/^-?[0-9]+$/', $max_age ) ) {
			return (int) $max_age <= 0;
		}
		$expires = isset( $parsed['expires'] ) ? trim( (string) $parsed['expires'] ) : '';
		if ( '' === $expires ) {
			return false;
		}
		$observed_at  = null === $reference_time ? time() : (int) $reference_time;
		$expires_time = strtotime( $expires );
		return false !== $expires_time && $expires_time <= $observed_at;
	}

	/**
	 * Fetch a scanner URL, optionally pinning the site's hostname to a public IP.
	 *
	 * CURLOPT_RESOLVE preserves the original URL, Host header, TLS SNI and
	 * certificate validation. Rewriting an HTTPS URL to the numeric address (the
	 * old implementation) breaks certificate-name validation and was applied to
	 * scan_page() only, so sitemap discovery silently used different DNS.
	 * When a static IP is configured we fail closed if WordPress does not use its
	 * cURL transport; silently falling back to DNS would make the setting lie.
	 *
	 * @param string $url  Same-site URL.
	 * @param array  $args WordPress HTTP arguments.
	 * @return array|\WP_Error
	 */
	public function remote_get( $url, $args = array() ) {
		$url_host              = self::canonical_scan_host( wp_parse_url( $url, PHP_URL_HOST ) );
		$site_host             = self::canonical_scan_host( wp_parse_url( home_url(), PHP_URL_HOST ) );
		$is_validated_loopback = self::is_loopback_scan_host( $url_host )
			&& self::is_loopback_scan_host( $site_host )
			&& self::scan_hosts_match( $url_host, $site_host );

		// Reject unsafe targets by default. The only exception is the current
		// site's validated loopback alias. Never trust a caller-supplied false for
		// another host, and never follow redirects while core URL validation is
		// disabled: a loopback response could otherwise pivot to an internal URL.
		$reject_unsafe_urls = array_key_exists( 'reject_unsafe_urls', $args )
			? (bool) $args['reject_unsafe_urls']
			: ! $is_validated_loopback;
		if ( ! $reject_unsafe_urls && ! $is_validated_loopback ) {
			$reject_unsafe_urls = true;
		}
		$args['reject_unsafe_urls'] = $reject_unsafe_urls;
		if ( ! $reject_unsafe_urls ) {
			$args['redirection'] = 0;
		}

		$settings  = \FazCookie\Admin\Modules\Settings\Includes\Settings::get_instance();
		$static_ip = $settings->get( 'scanner', 'static_ip' );
		$entry     = self::build_static_resolve_entry( $url, $static_ip );
		if ( '' === $entry ) {
			return wp_remote_get( $url, $args );
		}

		if ( '' === $url_host || ! self::scan_hosts_match( $url_host, $site_host ) ) {
			return new \WP_Error( 'faz_scanner_static_ip_host', __( 'Static IP pinning is limited to this site hostname.', 'faz-cookie-manager' ) );
		}

		$applied  = false;
		$resolver = static function ( $handle, $parsed_args, $request_url ) use ( $entry, $url_host, &$applied ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			$request_host = self::canonical_scan_host( wp_parse_url( $request_url, PHP_URL_HOST ) );
			if ( ! self::scan_hosts_match( $request_host, $url_host )
				|| ! defined( 'CURLOPT_RESOLVE' ) || ! function_exists( 'curl_setopt' ) ) {
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress exposes this transport-specific hook precisely for cURL options.
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $entry ) );
			$applied = true;
		};
		add_action( 'http_api_curl', $resolver, 10, 3 );
		$response = wp_remote_get( $url, $args );
		remove_action( 'http_api_curl', $resolver, 10 );

		if ( ! $applied ) {
			return new \WP_Error( 'faz_scanner_static_ip_transport', __( 'Static IP pinning requires the WordPress cURL HTTP transport.', 'faz-cookie-manager' ) );
		}
		return $response;
	}

	/**
	 * Canonical host shape shared by static-IP validation and its cURL hook.
	 *
	 * @param mixed $host Parsed URL host.
	 * @return string
	 */
	public static function canonical_scan_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		if ( strlen( $host ) >= 2 && '[' === $host[0] && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}
		return (string) preg_replace( '/^www\./i', '', $host );
	}

	/**
	 * Whether a canonical scanner host is a loopback alias.
	 *
	 * @param string $host Hostname or IP literal.
	 * @return bool
	 */
	public static function is_loopback_scan_host( $host ) {
		return in_array( self::canonical_scan_host( $host ), array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/**
	 * Compare canonical site hosts, including the validated loopback aliases.
	 *
	 * @param string $left  First host.
	 * @param string $right Second host.
	 * @return bool
	 */
	public static function scan_hosts_match( $left, $right ) {
		$left  = self::canonical_scan_host( $left );
		$right = self::canonical_scan_host( $right );
		return $left === $right
			|| ( self::is_loopback_scan_host( $left ) && self::is_loopback_scan_host( $right ) );
	}

	/**
	 * Build a CURLOPT_RESOLVE entry for a public IP, or an empty string.
	 *
	 * @param string $url URL whose hostname and port should be pinned.
	 * @param string $ip  Configured IP address.
	 * @return string
	 */
	public static function build_static_resolve_entry( $url, $ip ) {
		$ip = trim( (string) $ip );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return '';
		}
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return '';
		}
		$scheme = isset( $parsed['scheme'] ) ? strtolower( (string) $parsed['scheme'] ) : 'https';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$port = isset( $parsed['port'] ) ? absint( $parsed['port'] ) : ( 'https' === $scheme ? 443 : 80 );
		$address = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
		return strtolower( (string) $parsed['host'] ) . ':' . $port . ':' . $address;
	}

	/**
	 * Parse a Set-Cookie header string.
	 *
	 * @param string $cookie_string The raw Set-Cookie header value.
	 * @return array Parsed cookie attributes.
	 */
	public function parse_set_cookie( $cookie_string ) {
		$result = array(
			'name'     => '',
			'value'    => '',
			'domain'   => '',
			'path'     => '',
			'expires'  => '',
			'max-age'  => '',
			'secure'   => false,
			'httponly' => false,
			'samesite' => '',
		);

		$parts = explode( ';', $cookie_string );

		// First part is name=value.
		$name_value = trim( $parts[0] );
		$eq_pos     = strpos( $name_value, '=' );
		if ( false === $eq_pos ) {
			return $result;
		}

		$result['name']  = trim( substr( $name_value, 0, $eq_pos ) );
		$result['value'] = trim( substr( $name_value, $eq_pos + 1 ) );

		// Parse remaining attributes.
		for ( $i = 1; $i < count( $parts ); $i++ ) {
			$part = trim( $parts[ $i ] );
			if ( empty( $part ) ) {
				continue;
			}
			$eq_pos = strpos( $part, '=' );
			if ( false !== $eq_pos ) {
				$attr_name  = strtolower( trim( substr( $part, 0, $eq_pos ) ) );
				$attr_value = trim( substr( $part, $eq_pos + 1 ) );
				if ( isset( $result[ $attr_name ] ) ) {
					$result[ $attr_name ] = $attr_value;
				}
			} else {
				$attr_name = strtolower( $part );
				if ( 'secure' === $attr_name ) {
					$result['secure'] = true;
				} elseif ( 'httponly' === $attr_name ) {
					$result['httponly'] = true;
				}
			}
		}

		return $result;
	}

	/**
	 * Convert seconds to a human-readable duration string.
	 *
	 * @param int $seconds Number of seconds.
	 * @return string Human-readable duration.
	 */
	public function seconds_to_human( $seconds ) {
		if ( $seconds <= 0 ) {
			return 'session';
		}

		$years  = floor( $seconds / ( 365 * DAY_IN_SECONDS ) );
		$months = floor( $seconds / ( 30.44 * DAY_IN_SECONDS ) );
		$days   = floor( $seconds / DAY_IN_SECONDS );
		$hours  = floor( $seconds / HOUR_IN_SECONDS );
		$mins   = floor( $seconds / MINUTE_IN_SECONDS );

		if ( $years >= 1 ) {
			return 1 === (int) $years ? '1 year' : $years . ' years';
		}
		if ( $months >= 1 ) {
			return 1 === (int) $months ? '1 month' : $months . ' months';
		}
		if ( $days >= 1 ) {
			return 1 === (int) $days ? '1 day' : $days . ' days';
		}
		if ( $hours >= 1 ) {
			return 1 === (int) $hours ? '1 hour' : $hours . ' hours';
		}
		if ( $mins >= 1 ) {
			return 1 === (int) $mins ? '1 minute' : $mins . ' minutes';
		}

		return $seconds . ' seconds';
	}

	/**
	 * Save scan results from the browser-based scanner.
	 *
	 * Receives cookies discovered by the client-side iframe scanner,
	 * merges inferred cookies from script analysis, saves everything,
	 * and updates scan history.
	 *
	 * @param array $cookies       Array of cookie data arrays.
	 * @param int   $pages_scanned Number of pages scanned.
	 * @param array $scripts       Array of detected script URLs (for inference).
	 * @param array $metrics       Optional scan metrics from the client.
	 * @return array Scan result summary.
	 */
	/**
	 * Update the consecutive-miss tally after a COMPLETE scan.
	 *
	 * Only a full, healthy scan may increment: an incremental run or one that
	 * stopped early has not looked everywhere, so its silence is not evidence.
	 *
	 * @param string[] $observed_names Cookie names this scan actually saw.
	 * @param bool     $is_complete    Whether the scan covered the whole site.
	 * @return array<string,int> Updated tally, keyed "name|domain".
	 */
	public function record_scan_observations( $observed_names, $is_complete ) {
		$counts = self::canonical_missed_scan_counts( get_option( self::MISSED_SCANS_OPTION, array() ) );

		if ( ! $is_complete ) {
			return $counts;
		}

		// Observed names arrive without a domain (save_scan_result() returns the
		// merged name list, and script-inferred entries never had one), so the
		// reset is — and already was — a NAME comparison against a bare-name
		// index, and it did match. What it was not is case-insensitive: a `_GA`
		// catalogue row was never cleared by an observed `_ga`, so its tally
		// accrued a miss on every complete scan until the row crossed
		// MISSED_SCANS_THRESHOLD and was offered for deletion on a site where
		// the cookie was in fact still being set. Folding both sides through
		// canonical_name() — the same canonicaliser the tally keys use — is
		// what closes that.
		$observed = array();
		foreach ( (array) $observed_names as $observed_name ) {
			$observed_name = self::canonical_name( sanitize_text_field( (string) $observed_name ) );
			if ( '' !== $observed_name ) {
				$observed[ $observed_name ] = true;
			}
		}

		$existing = Cookie_Controller::get_instance()->get_item_from_db();
		$updated  = array();
		foreach ( (array) $existing as $cookie ) {
			if ( empty( $cookie->name ) || empty( $cookie->discovered ) ) {
				continue; // Hand-added cookies are never judged by a scan.
			}
			$key = self::canonical_key( $cookie->name, isset( $cookie->domain ) ? $cookie->domain : '' );
			if ( '' === $key ) {
				continue;
			}
			if ( isset( $observed[ self::canonical_name( $cookie->name ) ] ) ) {
				continue; // Seen again: the tally resets by omission.
			}
			$previous        = isset( $counts[ $key ] ) ? absint( $counts[ $key ] ) : 0;
			$updated[ $key ] = $previous + 1;
		}

		update_option( self::MISSED_SCANS_OPTION, $updated, false );
		return $updated;
	}

	/**
	 * Decide whether one scan's silence about a cookie is evidence of absence.
	 *
	 * The single place that answers "did this run actually look everywhere?".
	 * It lives here, next to the tally it guards, because the tally is the only
	 * thing the answer feeds and a second copy of this rule elsewhere is how the
	 * two ends drift apart.
	 *
	 * Depth is the term that was missing. `incremental`, `earlyStopReason` and
	 * `stoppedReason` describe a run that did not finish what it set out to do;
	 * none of them describes a run that set out to do 20 pages of a 500-page
	 * site and finished. That run is not evidence of anything, and the client's
	 * own gate has always agreed — scanCoverageIsComplete() in
	 * admin/assets/js/pages/cookies.js requires `maxPages === 0`. Requiring the
	 * same fact here is what stops the two gates disagreeing about the word
	 * "complete".
	 *
	 * Every unknown fails CLOSED. A client that does not send the depth at all
	 * (an older cached admin bundle) yields false, so the tally simply stops
	 * advancing: no deletion is ever offered from evidence nobody described.
	 * The opposite default would keep the pre-existing bug and call it
	 * backwards compatibility.
	 *
	 * @param array $metrics           Client-reported scan metrics.
	 * @param int   $pages_scanned     Pages the browser actually visited.
	 * @param bool  $capture_truncated Whether the server's Set-Cookie capture
	 *                                 hit its per-session cap and dropped
	 *                                 observations.
	 * @return bool
	 */
	public static function scan_coverage_is_complete( $metrics, $pages_scanned, $capture_truncated = false ) {
		$metrics = is_array( $metrics ) ? $metrics : array();

		if ( absint( $pages_scanned ) < 1 ) {
			return false;
		}
		// Observations were dropped on the floor by the capture cap. The import
		// already reports this to the administrator; letting it also silence the
		// tally is the difference between reporting a degraded run and acting on
		// one. It was computed and then ignored.
		if ( ! empty( $capture_truncated ) ) {
			return false;
		}
		if ( ! empty( $metrics['incremental'] ) ) {
			return false;
		}
		if ( ! empty( $metrics['earlyStopReason'] ) ) {
			return false;
		}
		// Set only by an explicit administrator cancel.
		if ( ! empty( $metrics['stoppedReason'] ) ) {
			return false;
		}
		// The administrator's own choice, and its derived flag. Both must agree
		// and both must be present: a payload claiming a full scan while naming
		// a page cap is describing two different runs, and the safe reading of a
		// contradiction is the narrower one.
		if ( ! array_key_exists( 'maxPages', $metrics ) || 0 !== (int) $metrics['maxPages'] ) {
			return false;
		}
		if ( empty( $metrics['isFullScan'] ) ) {
			return false;
		}
		// Degraded capture is a way to be incomplete, and until this term
		// existed only the CLIENT gate knew it. scanCoverageIsComplete() in
		// pages/cookies.js has always required diagnostics.totalIssues === 0;
		// the payload carried no such field, so a crawl with timed-out iframes,
		// a cross-origin refusal, a failed server-scan or a hit resource cap
		// read here as full-site evidence. That verdict is not cosmetic: it
		// feeds record_scan_observations(), which advances the consecutive-miss
		// tally for every catalogue row the degraded run failed to observe, and
		// MISSED_SCANS_THRESHOLD is 2 — two such runs are enough to offer a
		// live cookie for deletion.
		//
		// Fail CLOSED on absence, exactly as the maxPages term above does: an
		// older client that does not send the field must not be read as
		// "no issues", or the gap re-opens for every un-upgraded browser tab.
		if ( ! array_key_exists( 'diagnosticsIssues', $metrics )
			|| ! is_numeric( $metrics['diagnosticsIssues'] )
			|| 0 !== (int) $metrics['diagnosticsIssues'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Clear the miss tally for cookies re-confirmed outside a browser scan.
	 *
	 * CLEAR-ONLY, and that restriction is the whole design. The background
	 * header-replay pass (run_httponly_check()) starts each worker with an empty
	 * jar and then shares that jar across its 20-URL batch. A first-visit session
	 * cookie is therefore seen on the first URL that issues it, but may never be
	 * seen by the administrator's browser, which already holds it. Its name can
	 * remain absent from record_scan_observations()'s browser-import set while
	 * its tally climbs forever and this worker keeps watching the site set it.
	 *
	 * Reusing record_scan_observations() here would be worse than the bug: it
	 * increments every catalogue row absent from the set it is handed, and this
	 * worker's set is 20 URLs of an arbitrarily large site. One false positive
	 * would become a site-wide one. So this method can only ever REMOVE keys —
	 * it never adds one, never increments one, and leaves every entry the batch
	 * did not touch exactly as it found it.
	 *
	 * @param string[] $observed_names Cookie names re-observed being set.
	 * @return array<string,int> The tally after clearing.
	 */
	public function clear_scan_observations( $observed_names ) {
		$counts = self::canonical_missed_scan_counts( get_option( self::MISSED_SCANS_OPTION, array() ) );
		if ( empty( $counts ) ) {
			return $counts;
		}

		$observed = array();
		foreach ( (array) $observed_names as $observed_name ) {
			$observed_name = self::canonical_name( sanitize_text_field( (string) $observed_name ) );
			if ( '' !== $observed_name ) {
				$observed[ $observed_name ] = true;
			}
		}
		if ( empty( $observed ) ) {
			return $counts;
		}

		// Name-only comparison, matching record_scan_observations(): the tally is
		// keyed "name|domain" but a replay observes the name, and a Set-Cookie
		// domain routinely differs in its leading dot from the catalogue row.
		$updated = $counts;
		foreach ( $counts as $key => $count ) {
			$parts = explode( '|', (string) $key, 2 );
			if ( isset( $observed[ self::canonical_name( $parts[0] ) ] ) ) {
				unset( $updated[ $key ] );
			}
		}

		if ( count( $updated ) !== count( $counts ) ) {
			update_option( self::MISSED_SCANS_OPTION, $updated, false );
		}
		return $updated;
	}

	/**
	 * Entries that have been missing long enough for deletion to be offered.
	 *
	 * @return string[] Canonical keys — see canonical_key().
	 */
	public function deletable_stale_keys() {
		$counts = self::canonical_missed_scan_counts( get_option( self::MISSED_SCANS_OPTION, array() ) );
		$keys   = array();
		foreach ( $counts as $key => $count ) {
			if ( absint( $count ) >= self::MISSED_SCANS_THRESHOLD ) {
				$keys[] = (string) $key;
			}
		}
		return $keys;
	}

	/**
	 * The one canonical form of a cookie identity, shared by client and server.
	 *
	 * This MUST stay byte-identical to getStaleKey()/normalizeDomain() in
	 * admin/assets/js/pages/cookies.js: lowercase trimmed name, then lowercase
	 * domain with leading dots and any `:port` suffix removed. The tally used to
	 * be keyed on the raw name and the raw domain while the browser keyed on the
	 * normalized pair, and because cookie domains routinely carry a leading dot
	 * the two sets could never intersect. Wiring them together without this
	 * would have produced a stale bar that shows zero forever — inert, but
	 * looking wired, which is worse than an obviously dead field.
	 *
	 * Public rather than private because the cookies bulk-delete endpoint has to
	 * derive the same key to enforce the threshold on a scoped stale purge; one
	 * shared builder is the entire point.
	 *
	 * @param string $name   Cookie name.
	 * @param string $domain Cookie domain.
	 * @return string Canonical "name|domain" key, or '' for a nameless row.
	 */
	public static function canonical_key( $name, $domain ) {
		$name = self::canonical_name( $name );
		if ( '' === $name ) {
			return '';
		}
		$domain = strtolower( trim( (string) $domain ) );
		$domain = ltrim( $domain, '.' );
		$domain = preg_replace( '/:\d+$/', '', $domain );
		return $name . '|' . $domain;
	}

	/**
	 * Canonical form of a cookie name alone.
	 *
	 * @param string $name Cookie name.
	 * @return string
	 */
	public static function canonical_name( $name ) {
		return strtolower( trim( (string) $name ) );
	}

	/**
	 * Re-key a stored tally into canonical form.
	 *
	 * Applied on every read so tallies written before the canonicalization are
	 * carried across instead of orphaned. Dropping them would silently reset
	 * every site's counters and delay every deletion offer by two extra complete
	 * scans — the migration is the difference between a fix and a regression.
	 * Where two legacy keys collapse onto one canonical key the higher count
	 * wins: the entry has demonstrably been missed that many times.
	 *
	 * @param mixed $counts Stored option value.
	 * @return array<string,int>
	 */
	private static function canonical_missed_scan_counts( $counts ) {
		$canonical = array();
		foreach ( (array) $counts as $key => $count ) {
			$parts = explode( '|', (string) $key, 2 );
			$item  = self::canonical_key( $parts[0], isset( $parts[1] ) ? $parts[1] : '' );
			if ( '' === $item ) {
				continue;
			}
			$count = absint( $count );
			if ( ! isset( $canonical[ $item ] ) || $count > $canonical[ $item ] ) {
				$canonical[ $item ] = $count;
			}
		}
		return $canonical;
	}

	public function save_scan_result( $cookies, $pages_scanned, $scripts = array(), $metrics = array() ) {
		$logger = Scanner_Logger::get_instance();
		$logger->start( 'Browser scan import' );

		try {
			$logger->log( 'Received ' . count( $cookies ) . ' cookies, ' . count( $scripts ) . ' scripts from client' );
			$logger->log( 'Pages scanned: ' . $pages_scanned );

			// Deduplicate cookies by name (single pass, also used for merge check).
			$unique = array();
			$seen   = array();
			foreach ( $cookies as $c ) {
				if ( ! is_array( $c ) || empty( $c['name'] ) ) {
					continue;
				}
				$name = sanitize_text_field( $c['name'] );
				if ( isset( $seen[ $name ] ) ) {
					continue;
				}
				$seen[ $name ] = true;
				$c['name']     = $name;
				$unique[]      = $c;
			}
			$logger->log( 'Deduplicating: ' . count( $unique ) . ' unique cookies from client data' );

			// Merge inferred cookies from script patterns. The client is not
			// authoritative about what counts as a "script": filter at this trust
			// boundary so a stale cached admin bundle — or any future writer of
			// the array — cannot feed a passive asset to the pattern matchers.
			$inferable = self::filter_inferable_script_urls( $scripts );
			$dropped   = count( $scripts ) - count( $inferable );
			if ( $dropped > 0 ) {
				$logger->log( 'Dropped ' . $dropped . ' non-code asset URL(s) before inference (images/CSS/fonts/media never set cookies)' );
			}
			if ( ! empty( $inferable ) ) {
				$logger->log( 'Script inference from ' . count( $inferable ) . ' scripts (Cookie_Database)...' );
				$inferred = Cookie_Database::lookup_scripts( $inferable );
				$logger->log( 'Cookie_Database::lookup_scripts returned ' . count( $inferred ) . ' inferred cookies' );
				foreach ( $inferred as $inf ) {
					if ( ! is_array( $inf ) || empty( $inf['name'] ) ) {
						continue;
					}
					$name = sanitize_text_field( $inf['name'] );
					if ( isset( $seen[ $name ] ) ) {
						$logger->log( '  Script-inferred cookie "' . $name . '" already seen, skipping' );
						continue;
					}
					$inf_cat = isset( $inf['category'] ) ? $inf['category'] : 'unknown';
					$logger->log( '  Script-inferred: "' . $name . '" → category=' . $inf_cat );
					$inf['name']  = $name;
					$seen[ $name ] = true;
					$unique[]      = $inf;
				}

				// Also infer cookies from Known Providers based on detected scripts.
				$logger->log( 'Script inference from Known Providers...' );
				$kp_inferred = $this->infer_cookies_from_scripts( $inferable );
				$logger->log( 'Known Providers returned ' . count( $kp_inferred ) . ' inferred cookies' );
				foreach ( $kp_inferred as $inf ) {
					$name = sanitize_text_field( $inf['name'] );
					if ( isset( $seen[ $name ] ) ) {
						$logger->log( '  KP-inferred cookie "' . $name . '" already seen, skipping' );
						continue;
					}
					$kp_cat = isset( $inf['category'] ) ? $inf['category'] : 'unknown';
					$logger->log( '  KP-inferred: "' . $name . '" → category=' . $kp_cat );
					$seen[ $name ] = true;
					$unique[]      = $inf;
				}
			}

			$total_cookies = count( $unique );
			$logger->log( 'Total unique cookies to save: ' . $total_cookies );
			$this->save_cookies( $unique );
			$cookie_names = array();
			foreach ( $unique as $item ) {
				if ( isset( $item['name'] ) && '' !== $item['name'] ) {
					$cookie_names[] = sanitize_text_field( $item['name'] );
				}
			}

			$scan_id = absint( get_option( 'faz_scan_counter', 0 ) ) + 1;
			update_option( 'faz_scan_counter', $scan_id, false );

			$this->update_info(
				array(
					'id'            => $scan_id,
					'status'        => 'completed',
					'type'          => 'browser',
					'date'          => current_time( 'mysql' ),
					'total_cookies' => $total_cookies,
					'pages_scanned' => $pages_scanned,
				)
			);

			$clean_metrics = $this->sanitize_scan_metrics( $metrics );

			// Store scan history entry.
			$history       = get_option( 'faz_scan_history', array() );
			$history_entry = array(
				'id'            => $scan_id,
				'status'        => 'completed',
				'type'          => 'browser',
				'date'          => current_time( 'mysql' ),
				'total_cookies' => $total_cookies,
				'pages_scanned' => $pages_scanned,
			);
			if ( ! empty( $clean_metrics ) ) {
				$history_entry['metrics'] = $clean_metrics;
			}
			$history[] = $history_entry;
			if ( count( $history ) > 50 ) {
				$history = array_slice( $history, -50 );
			}
			update_option( 'faz_scan_history', $history, false );

			$logger->log( 'Scan result: scan_id=' . $scan_id . ', total_cookies=' . $total_cookies . ', pages_scanned=' . $pages_scanned );

			return array(
				'scan_id'       => $scan_id,
				'total_cookies' => $total_cookies,
				'pages_scanned' => $pages_scanned,
				'cookie_names'  => array_values( array_unique( $cookie_names ) ),
			);
		} catch ( \Throwable $e ) {
			$this->record_scan_failure( 'browser', $e->getMessage(), $pages_scanned );
			throw $e;
		} finally {
			$logger->finish();
		}
	}

	/**
	 * Persist a failed scan result at an execution boundary.
	 *
	 * @param string $type          Scan type (local, browser, or httponly).
	 * @param string $message       Diagnostic failure message.
	 * @param int    $pages_scanned Number of pages completed before failure.
	 * @return array Failure record.
	 */
	public function record_scan_failure( $type, $message, $pages_scanned = 0 ) {
		$scan_id = absint( get_option( 'faz_scan_counter', 0 ) ) + 1;
		update_option( 'faz_scan_counter', $scan_id, false );

		$failure = array(
			'id'            => $scan_id,
			'status'        => 'failed',
			'type'          => sanitize_key( $type ),
			'date'          => current_time( 'mysql' ),
			'total_cookies' => 0,
			'pages_scanned' => absint( $pages_scanned ),
			'error'         => substr( sanitize_text_field( $message ), 0, 500 ),
		);
		$this->update_info( $failure );

		$history   = get_option( 'faz_scan_history', array() );
		$history   = is_array( $history ) ? $history : array();
		$history[] = $failure;
		if ( count( $history ) > 50 ) {
			$history = array_slice( $history, -50 );
		}
		update_option( 'faz_scan_history', $history, false );
		return $failure;
	}

	/**
	 * Save discovered cookies to the database using the Cookie model.
	 *
	 * @param array $cookies Array of discovered cookie data arrays.
	 * @return int Number of NEW cookie rows created (existing names are skipped,
	 *             never overwritten — manual recategorisations always survive).
	 */
	public function save_cookies( $cookies ) {
		$created = 0;
		$logger = Scanner_Logger::get_instance();

		$category_controller = Category_Controller::get_instance();
		$categories          = $category_controller->get_items();
		$category_map        = array();
		foreach ( $categories as $cat ) {
			$category_map[ $cat->slug ] = $cat->category_id;
		}

		$logger->log( 'Category map', $category_map );

		// Get existing cookies to avoid duplicates (hash map for O(1) lookup).
		$existing_cookies = Cookie_Controller::get_instance()->get_item_from_db();
		$existing_names   = array();
		if ( ! empty( $existing_cookies ) && is_array( $existing_cookies ) ) {
			foreach ( $existing_cookies as $ec ) {
				$existing_names[ $ec->name ] = true;
			}
		}

		$existing_list = array_keys( $existing_names );
		$logger->log( 'Existing cookies in DB: ' . count( $existing_list ), $existing_list );

		$default_lang = function_exists( 'faz_default_language' ) ? faz_default_language() : 'en';

		// Default fallback category for unknown cookies — prefer uncategorized.
		$default_cat_id = isset( $category_map['uncategorized'] )
			? $category_map['uncategorized']
			: ( isset( $category_map['necessary'] ) ? $category_map['necessary'] : 1 );

		// Bulk mode: one cache invalidation + one faz_after_create_cookie at
		// the end of the loop instead of per inserted cookie (each per-item
		// flush cost a wp_options LIKE scan and a full-table vendor re-check).
		\FazCookie\Includes\Base_Controller::suspend_cache_invalidation();
		try {
			foreach ( $cookies as $cookie_data ) {
				if ( ! is_array( $cookie_data ) || empty( $cookie_data['name'] ) ) {
					continue;
				}
				$cookie_data = wp_parse_args(
					$cookie_data,
					array(
						'description' => '',
						'duration'    => 'session',
						'domain'      => '',
						'category'    => '',
					)
				);
				$name        = sanitize_text_field( $cookie_data['name'] );

				$logger->log( 'Processing: "' . $name . '"' );

				if ( isset( $existing_names[ $name ] ) ) {
					$logger->log( '  SKIPPED: already exists in DB' );
					continue; // Don't overwrite existing cookies.
				}

				// Try known cookies database first (handles WP admin cookies, etc.).
				$known = Cookie_Database::lookup( $name );
				if ( $known ) {
					$cat_slug = $known['category'];
					$logger->log( '  Cookie_Database lookup: FOUND → category=' . $known['category'] . ', description="' . substr( $known['description'], 0, 60 ) . '..."' );
					if ( ! empty( $known['description'] ) && empty( $cookie_data['description'] ) ) {
						$cookie_data['description'] = $known['description'];
					}
					if ( ! empty( $known['duration'] ) && ( empty( $cookie_data['duration'] ) || 'session' === $cookie_data['duration'] ) ) {
						$cookie_data['duration'] = $known['duration'];
					}
				} else {
					$logger->log( '  Cookie_Database lookup: not found' );
					// Fallback 2: Known Providers cookie map.
					$provider_cat = $this->match_cookie_to_provider( $name );
					if ( $provider_cat ) {
						$cat_slug = $provider_cat;
						$logger->log( '  Known_Providers match: FOUND → category=' . $provider_cat );
						// Known Providers only gives category — try OCD for description/duration.
						if ( empty( $cookie_data['description'] ) || empty( $cookie_data['duration'] ) || 'session' === $cookie_data['duration'] ) {
							$ocd_extra = Cookie_Definitions::get_instance()->lookup( $name );
							if ( $ocd_extra ) {
								$logger->log( '  OCD lookup (for description/duration): FOUND' );
								if ( ! empty( $ocd_extra['description'] ) && empty( $cookie_data['description'] ) ) {
									$cookie_data['description'] = $ocd_extra['description'];
								}
								if ( ! empty( $ocd_extra['duration'] ) && ( empty( $cookie_data['duration'] ) || 'session' === $cookie_data['duration'] ) ) {
									$cookie_data['duration'] = $ocd_extra['duration'];
								}
							} else {
								$logger->log( '  OCD lookup (for description/duration): not found' );
							}
						}
					} else {
						$logger->log( '  Known_Providers match: not found' );
						// Fallback 3: Open Cookie Database (1400+ definitions).
						$ocd = Cookie_Definitions::get_instance()->lookup( $name );
						if ( $ocd ) {
							$cat_slug = ! empty( $ocd['category'] ) ? $ocd['category'] : 'uncategorized';
							$logger->log( '  OCD lookup: FOUND → category=' . $cat_slug . ', description="' . substr( isset( $ocd['description'] ) ? $ocd['description'] : '', 0, 60 ) . '..."' );
							if ( ! empty( $ocd['description'] ) && empty( $cookie_data['description'] ) ) {
								$cookie_data['description'] = $ocd['description'];
							}
							if ( ! empty( $ocd['duration'] ) && ( empty( $cookie_data['duration'] ) || 'session' === $cookie_data['duration'] ) ) {
								$cookie_data['duration'] = $ocd['duration'];
							}
						} else {
							$cat_slug = isset( $cookie_data['category'] ) ? $cookie_data['category'] : 'uncategorized';
							$logger->log( '  OCD lookup: not found' );
							$logger->log( '  Using client-provided category: ' . $cat_slug );
						}
					}
				}
				$category_id = isset( $category_map[ $cat_slug ] ) ? $category_map[ $cat_slug ] : $default_cat_id;

				$logger->log( '  Final category: ' . $cat_slug . ' (id=' . $category_id . ')' );
				$logger->log( '  Description: "' . substr( $cookie_data['description'], 0, 80 ) . '"' );
				$logger->log( '  Duration: ' . $cookie_data['duration'] );

				$cookie = new Cookie();
				$cookie->set_name( $name );
				$cookie->set_slug( sanitize_title( $name ) );
				$cookie->set_description( array( $default_lang => sanitize_text_field( $cookie_data['description'] ) ) );
				$cookie->set_duration( array( $default_lang => sanitize_text_field( $cookie_data['duration'] ) ) );
				$cookie->set_domain( sanitize_text_field( $cookie_data['domain'] ) );
				$cookie->set_category( $category_id );
				$cookie->set_type( 1 );
				$cookie->set_discovered( true );

				$result = Cookie_Controller::get_instance()->create_item( $cookie );
				if ( false === $result ) {
					// Do not report or count a row the database rejected. Throwing also
					// drives the finally block, which restores invalidation and flushes
					// any rows that were inserted earlier in this batch.
					throw new \RuntimeException( 'FAZ: failed to persist scanned cookie "' . $name . '".' );
				}
				$logger->log( '  CREATED: "' . $name . '"' );
				$existing_names[ $name ] = true;
				++$created;
			}
		} finally {
			\FazCookie\Includes\Base_Controller::resume_cache_invalidation();

			// The flush belongs in the finally too, not after it. Suspending
			// invalidation trades one purge per inserted row for a single purge
			// at the end; if create_item() throws mid-loop, the rows already
			// written stay in the database while the caches keep serving the
			// previous contents. Before bulk mode existed each insert invalidated
			// immediately, so a partial failure still left the caches coherent —
			// this restores that guarantee.
			Cookie_Controller::get_instance()->delete_cache();
			Category_Controller::get_instance()->delete_cache();
			if ( $created > 0 ) {
				do_action( 'faz_after_create_cookie' );
			}
		}

		return $created;
	}

	/**
	 * Extract embeddable provider URLs (<script src> / <iframe src>) from HTML.
	 *
	 * Feeds infer_cookies_from_scripts() so a provider present only as an embed
	 * (e.g. a blocked YouTube <iframe>) is detected even when it never sets a
	 * cookie on a block-first site — which is what surfaces its per-service
	 * toggle (#134/#146). Returns raw URLs (deduplication happens at the caller).
	 *
	 * @param string $html Raw page HTML.
	 * @return string[] Embed src URLs.
	 */
	private function extract_embed_urls( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return array();
		}
		$urls = array();
		if ( preg_match_all( '/<(?:script|iframe)\b[^>]*\b(?:src|data-src|data-litespeed-src|data-rocket-src|data-wpr-src|data-lazy-src)\s*=\s*(["\'])(.*?)\1/i', $html, $matches ) ) {
			foreach ( $matches[2] as $url ) {
				$url = trim( html_entity_decode( $url, ENT_QUOTES ) );
				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}
		}
		return $urls;
	}

	/**
	 * Drop passive asset URLs before provider-pattern inference.
	 *
	 * Both matchers (Cookie_Database::lookup_scripts and
	 * infer_cookies_from_scripts) do an unanchored substring match of provider
	 * patterns against the whole URL, and whatever they return is written to the
	 * catalogue — which is the FIRST authority for a cookie's category, drives
	 * server-side cookie deletion, and is shown to visitors as a cookie this site
	 * sets. Provider patterns include image CDNs (ytimg.com, i.vimeocdn.com,
	 * snap.licdn.com) and short tokens that occur in ordinary paths, so an image
	 * or stylesheet URL reaching the matchers fabricates declarations: an
	 * i.ytimg.com thumbnail yields YSC/VISITOR_INFO1_LIVE/LOGIN_INFO even on a
	 * page that only renders a privacy facade.
	 *
	 * The guard lives here, on the URLs, rather than inside the matchers,
	 * because the patterns cannot be anchored without losing real detections.
	 * Roughly a quarter of the shipped patterns are bare tokens, script
	 * filenames and inline-code signatures ("yotuwp", "gtag(", "matomo.js")
	 * that exist precisely to catch SELF-HOSTED trackers, where the provider's
	 * host never appears in the URL at all; host-anchoring the lists deletes
	 * those. Under-declaration is the dangerous direction, so the shape of the
	 * URL is filtered instead of the shape of the pattern, at every point where
	 * a match is allowed to MINT a cookie declaration.
	 *
	 * (The frontend blocker does not consume these matchers unanchored: it runs
	 * its own boundary-checked matcher — Frontend::has_provider_boundary(),
	 * mirrored in frontend/js/script.js — so nothing here constrains it.)
	 *
	 * Static because both scanner entry points need it: the browser-scan import
	 * on this class, and the server-scan REST route in the scanner Api.
	 *
	 * @param array $scripts Candidate script URLs.
	 * @return array Only the URLs that can execute or beacon.
	 */
	public static function filter_inferable_script_urls( $scripts ) {
		if ( ! is_array( $scripts ) || empty( $scripts ) ) {
			return array();
		}

		$filtered = array();
		foreach ( $scripts as $url ) {
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				continue;
			}
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( ! is_string( $path ) || '' === $path ) {
				// No path component (e.g. "https://pixel.example.com") — an
				// extension-less endpoint, which is exactly the tracking-pixel
				// shape that must stay importable.
				$filtered[] = $url;
				continue;
			}
			// Mirrors NON_CODE_ASSET_PATH in admin/assets/js/modules/scan-engine.js.
			if ( preg_match( '/\.(?:png|jpe?g|gif|webp|avif|bmp|ico|cur|svgz?|tiff?|heic|heif|css|less|sass|scss|woff2?|ttf|otf|eot|mp4|m4v|mov|avi|mkv|webm|ogv|mp3|m4a|wav|flac|aac|oga|ogg|opus|vtt|srt|pdf|zip|gz|tgz|bz2|xz|rar|7z|map)$/i', $path ) ) {
				continue;
			}
			$filtered[] = $url;
		}

		return $filtered;
	}

	/**
	 * Infer cookies from detected scripts using Known Providers.
	 *
	 * When a script URL matches a Known Provider, return that provider's
	 * cookie names so they can be pre-registered in the database.
	 *
	 * @param array $scripts Array of script URL strings.
	 * @return array Array of cookie data arrays.
	 */
	private function infer_cookies_from_scripts( $scripts ) {
		$all      = \FazCookie\Includes\Known_Providers::get_all();
		$inferred = array();
		$seen     = array();

		foreach ( $scripts as $script_url ) {
			if ( ! is_string( $script_url ) ) {
				continue;
			}
			foreach ( $all as $service ) {
				if ( empty( $service['cookies'] ) ) {
					continue;
				}
				$matched = false;
				foreach ( $service['patterns'] as $pattern ) {
					if ( false !== stripos( $script_url, $pattern ) ) {
						$matched = true;
						break;
					}
				}
				if ( ! $matched ) {
					continue;
				}
				foreach ( $service['cookies'] as $cookie_name ) {
					// Skip wildcard-only patterns for inference.
					if ( false !== strpos( $cookie_name, '*' ) ) {
						continue;
					}
					if ( isset( $seen[ $cookie_name ] ) ) {
						continue;
					}
					$seen[ $cookie_name ] = true;
					$inferred[] = array(
						'name'        => $cookie_name,
						'category'    => $service['category'],
						'description' => sprintf( 'Set by %s', $service['label'] ),
						'domain'      => '',
						'duration'    => '',
					);
				}
			}
		}
		return $inferred;
	}

	/**
	 * Match a cookie name against Known Providers' cookie map.
	 *
	 * Supports exact match and wildcard patterns (e.g. '_ga_*').
	 *
	 * @param string $name Cookie name.
	 * @return string|false Category slug or false.
	 */
	private function match_cookie_to_provider( $name ) {
		$cookie_map = \FazCookie\Includes\Known_Providers::get_cookie_map();
		foreach ( $cookie_map as $pattern => $category ) {
			if ( $pattern === $name ) {
				return $category;
			}
			// Wildcard: '_ga_*' matches '_ga_ABC123'.
			if ( false !== strpos( $pattern, '*' ) ) {
				$regex = '/^' . str_replace( '\\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
				if ( preg_match( $regex, $name ) ) {
					return $category;
				}
			}
		}
		return false;
	}

	/**
	 * Sanitize client-side scan metrics for safe storage.
	 *
	 * @param array $metrics Raw metrics from the client.
	 * @return array Sanitized metrics, or empty array if input is empty.
	 */
	private function sanitize_scan_metrics( $metrics ) {
		if ( empty( $metrics ) || ! is_array( $metrics ) ) {
			return array();
		}

		$int_keys = array( 'discoverMs', 'scanMs', 'importMs', 'urlsDiscovered', 'cookiesFound', 'scriptsFound', 'pagesScanned' );
		$clean    = array();
		foreach ( $int_keys as $key ) {
			$clean[ $key ] = isset( $metrics[ $key ] ) ? absint( $metrics[ $key ] ) : 0;
		}
		$clean['earlyStopReason'] = isset( $metrics['earlyStopReason'] ) ? sanitize_text_field( $metrics['earlyStopReason'] ) : '';
		$clean['incremental']     = ! empty( $metrics['incremental'] );

		return $clean;
	}

	/**
	 * Get the last scan info.
	 *
	 * @return array
	 */
	public function get_info() {
		if ( ! $this->last_scan_info ) {
			$data = get_option( 'faz_scan_details', self::$default );
			$data = wp_parse_args( $data, self::$default );

			$formatted = '';
			if ( ! empty( $data['date'] ) ) {
				$timestamp = strtotime( sanitize_text_field( $data['date'] ) );
				$formatted = $timestamp ? gmdate( 'd F Y H:i:s', $timestamp ) : '';
			}

			$this->last_scan_info = array(
				'id'            => absint( $data['id'] ),
				'status'        => sanitize_text_field( $data['status'] ),
				'type'          => sanitize_text_field( $data['type'] ),
				'date'          => sanitize_text_field( $formatted ),
				'total_cookies' => absint( $data['total_cookies'] ),
				'pages_scanned' => absint( $data['pages_scanned'] ),
			);
		}
		return $this->last_scan_info;
	}

	/**
	 * Update the last scan info in the options table.
	 *
	 * @param array $data Scan data.
	 * @return void
	 */
	public function update_info( $data = array() ) {
		$scan_data = get_option( 'faz_scan_details', self::$default );
		$scan_data = wp_parse_args( $scan_data, self::$default );
		$data      = wp_parse_args( $data, $scan_data );

		$sanitized = array(
			'id'            => absint( $data['id'] ),
			'status'        => sanitize_text_field( $data['status'] ),
			'type'          => sanitize_text_field( $data['type'] ),
			'date'          => sanitize_text_field( $data['date'] ),
			'total_cookies' => absint( $data['total_cookies'] ),
			// Rows actually added by the last scan — run_scan() passes it and
			// scans/info surfaces it; without this whitelist entry the value
			// was silently dropped before update_option.
			'new_cookies'   => absint( $data['new_cookies'] ),
			'pages_scanned' => absint( $data['pages_scanned'] ),
		);

		update_option( 'faz_scan_details', $sanitized, false );
		$this->last_scan_info = null; // Reset cached info.
	}

	/**
	 * Generate a fingerprint of the site's content state.
	 *
	 * Used for incremental scanning — if the fingerprint hasn't changed,
	 * only priority URLs (home + recently modified) need re-scanning.
	 *
	 * @param int $max The max_pages parameter for context.
	 * @return string MD5 fingerprint.
	 */
	public function get_scan_fingerprint( $max ) {
		global $wpdb;

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		if ( empty( $post_types ) ) {
			return ''; // Unknown state — forces full scan.
		}

		$post_types_values = array_values( $post_types );
		$placeholders      = implode( ',', array_fill( 0, count( $post_types_values ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- {$wpdb->posts} is the WP-core posts table; {$placeholders} is a server-built string of "%s,%s,..." matching count($post_types_values), all bound by prepare(). Scanner fingerprint must reflect live post state, so caching defeats its purpose.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) as cnt, MAX(post_modified_gmt) as latest FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$post_types_values
			)
		);

		if ( null === $row || ! empty( $wpdb->last_error ) ) {
			return ''; // DB error — forces full scan.
		}

		// Include taxonomy state so archive page changes also invalidate the fingerprint.
		// Uses term slugs (not just counts) to detect renames/slug changes.
		$tax_part = '';
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		if ( ! empty( $taxonomies ) ) {
			$tax_entries = array();
			foreach ( $taxonomies as $tax ) {
				$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true, 'fields' => 'slugs' ) );
				if ( is_wp_error( $terms ) ) {
					$terms = array();
				}
				sort( $terms );
				$tax_entries[] = $tax . ':' . count( $terms ) . ':' . implode( '|', $terms );
			}
			sort( $tax_entries );
			$tax_part = implode( ',', $tax_entries );
		}

		return md5( $row->cnt . '|' . $row->latest . '|' . $max . '|' . $tax_part );
	}

	/**
	 * Get priority URLs for incremental scanning.
	 *
	 * Returns homepage + posts modified in the last 7 days.
	 *
	 * @param int $max Maximum URLs to return.
	 * @return array List of URLs.
	 */
	public function get_priority_urls( $max ) {
		$max = absint( $max );
		if ( $max < 1 ) {
			return array();
		}

		$home  = $this->normalize_url( home_url( '/' ) );
		$pages = array( $home );
		$seen  = array( $home => true );

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$recent     = get_posts(
			array(
				'post_type'              => array_values( $post_types ),
				'post_status'            => 'publish',
				'posts_per_page'         => max( 0, $max - 1 ),
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'date_query'             => array(
					array(
						'column' => 'post_modified_gmt',
						'after'  => '7 days ago',
					),
				),
			)
		);

		$this->collect_post_urls( $recent, $pages, $seen, $max );

		return array_slice( $pages, 0, $max );
	}

	/**
	 * Discover WooCommerce-specific URLs that are likely to load
	 * tracking pixels, payment SDKs, and analytics cookies.
	 *
	 * These URLs are returned as "priority" so the scanner does not
	 * skip them via early stop — they often set unique cookies that
	 * generic pages never trigger.
	 *
	 * @return array List of normalized WooCommerce URLs.
	 */
	public function discover_woocommerce_urls() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}

		$wc_urls = array();
		$seen    = array();

		// Shop page — loads analytics, pixel tracking.
		$shop_id = wc_get_page_id( 'shop' );
		if ( $shop_id > 0 ) {
			$url = get_permalink( $shop_id );
			if ( $url ) {
				$normalized = $this->normalize_url( $url );
				if ( ! isset( $seen[ $normalized ] ) ) {
					$seen[ $normalized ] = true;
					$wc_urls[]           = $normalized;
				}
			}
		}

		// First published product — loads retargeting pixels (FB, TikTok).
		$products = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'numberposts'            => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( ! empty( $products ) ) {
			$url = get_permalink( $products[0] );
			if ( $url ) {
				$normalized = $this->normalize_url( $url );
				if ( ! isset( $seen[ $normalized ] ) ) {
					$seen[ $normalized ] = true;
					$wc_urls[]           = $normalized;
				}
			}
		}

		// Cart page — loads payment SDKs (even when empty).
		$cart_id = wc_get_page_id( 'cart' );
		if ( $cart_id > 0 ) {
			$url = get_permalink( $cart_id );
			if ( $url ) {
				$normalized = $this->normalize_url( $url );
				if ( ! isset( $seen[ $normalized ] ) ) {
					$seen[ $normalized ] = true;
					$wc_urls[]           = $normalized;
				}
			}
		}

		// Checkout page — loads full payment gateways (PayPal, Stripe).
		$checkout_id = wc_get_page_id( 'checkout' );
		if ( $checkout_id > 0 ) {
			$url = get_permalink( $checkout_id );
			if ( $url ) {
				$normalized = $this->normalize_url( $url );
				if ( ! isset( $seen[ $normalized ] ) ) {
					$seen[ $normalized ] = true;
					$wc_urls[]           = $normalized;
				}
			}
		}

		// My Account page — loads reCAPTCHA, login tracking.
		$account_id = wc_get_page_id( 'myaccount' );
		if ( $account_id > 0 ) {
			$url = get_permalink( $account_id );
			if ( $url ) {
				$normalized = $this->normalize_url( $url );
				if ( ! isset( $seen[ $normalized ] ) ) {
					$seen[ $normalized ] = true;
					$wc_urls[]           = $normalized;
				}
			}
		}

		return array_values( $wc_urls );
	}

	/**
	 * Load scanner configs into WordPress localization function.
	 *
	 * @return array
	 */
	public function load_scanner_config() {
		return $this->get_info();
	}
}
