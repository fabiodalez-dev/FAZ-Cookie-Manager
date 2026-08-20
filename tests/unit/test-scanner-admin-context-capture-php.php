<?php
/**
 * The browser-scan Set-Cookie observer captures far more than the scan, and
 * what it captures beyond the scan must be REPORTED, not DECLARED.
 *
 * The observer is registered on every same-origin request and is deliberately
 * not gated on a matching faz_scan_id: the sub-resource, AJAX and REST requests
 * worth observing structurally cannot carry one. The consequence is that for
 * the life of the capture window — 900s idle, slid forward on every scan-tagged
 * page, ceiling 6h, while the setup page asks the admin to keep the tab open —
 * the administrator's own wp-admin traffic is observed too, and every
 * Set-Cookie it emits used to be merged into the imported set AND seeded into
 * $attributable (which additionally un-bucketed matching names on the
 * request-cookie path that was already fixed).
 *
 * These tests run the observer, the drain and the import route, and pin:
 *   1. the admin-context test itself, over the request shapes involved;
 *   2. that observations record which side they came from;
 *   3. that a name seen from BOTH sides counts as the site's;
 *   4. that the import declares the front-end ones and reports the admin ones.
 *
 * Run: php tests/unit/test-scanner-admin-context-capture-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Scanner\Includes {
	class Scanner_Logger {
		public static function get_instance() {
			return new self();
		}
		public function start( $context ) {}
		public function log( $message, $context = null ) {}
		public function finish() {}
	}

	/**
	 * Namespaced override of the PHP builtin, resolved before the global one for
	 * unqualified calls made from this namespace — which is how the observer
	 * calls it. Lets the test drive the outgoing header set.
	 */
	function headers_list() {
		return isset( $GLOBALS['__faz_headers'] ) ? $GLOBALS['__faz_headers'] : array();
	}

	/** Same trick: the drain must not try to expire a real cookie under CLI. */
	function headers_sent() {
		return true;
	}
}

namespace FazCookie\Includes {
	class Rest_Controller {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'YEAR_IN_SECONDS', 31536000 );

	$GLOBALS['__faz_options']     = array();
	$GLOBALS['__faz_transients']  = array();
	$GLOBALS['__faz_user_meta']   = array();
	$GLOBALS['__faz_actions']     = array();
	$GLOBALS['__faz_headers']     = array();
	$GLOBALS['__faz_is_admin']    = false;
	$GLOBALS['__faz_doing_ajax']  = false;

	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_message() {
			return $this->message;
		}
		// The import error now carries faz_session_held, which the client reads
		// to decide whether to offer a Retry at all, so the double has to expose
		// the data the way core does.
		public function get_error_data() {
			return $this->data;
		}
	}

	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}

	/** WP_REST_Request stand-in for the import route. */
	class FazTest_Import_Request {
		public $body;
		public $params;
		public $headers;
		public function __construct( $body, $params = array(), $headers = array() ) {
			$this->body    = $body;
			$this->params  = $params;
			$this->headers = $headers;
		}
		public function get_json_params() {
			return $this->body;
		}
		public function get_param( $key ) {
			return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
		}
		public function get_header( $key ) {
			return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : '';
		}
	}

	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function __( $value, ...$unused ) { return $value; }
	function wp_unslash( $value ) { return $value; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
	function esc_url_raw( $url ) { return (string) $url; }
	function trailingslashit( $value ) { return rtrim( (string) $value, '/' ) . '/'; }
	function home_url( $path = '' ) { return 'https://example.test/' . ltrim( (string) $path, '/' ); }
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
	function is_ssl() { return true; }
	function is_admin() { return (bool) $GLOBALS['__faz_is_admin']; }
	function wp_doing_ajax() { return (bool) $GLOBALS['__faz_doing_ajax']; }
	function get_current_user_id() { return 7; }
	function current_time( $type ) { return '2026-08-16 12:00:00'; }
	function apply_filters( $hook, $value, ...$rest ) { return $value; }
	function do_action( ...$unused ) { return true; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function rest_ensure_response( $value ) { return $value; }
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['__faz_actions'][ $hook ][] = $callback;
		return true;
	}
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__faz_options'] ) ? $GLOBALS['__faz_options'][ $key ] : $default;
	}
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__faz_options'][ $key ] = $value;
		return true;
	}
	function get_transient( $key ) {
		return isset( $GLOBALS['__faz_transients'][ $key ] ) ? $GLOBALS['__faz_transients'][ $key ] : false;
	}
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['__faz_transients'][ $key ] = $value;
		return true;
	}
	function delete_transient( $key ) {
		unset( $GLOBALS['__faz_transients'][ $key ] );
		return true;
	}
	function get_user_meta( $user_id, $key, $single = false ) {
		$values = isset( $GLOBALS['__faz_user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['__faz_user_meta'][ $user_id ][ $key ] : array();
		return $single ? ( isset( $values[0] ) ? $values[0] : '' ) : $values;
	}
	function add_user_meta( $user_id, $key, $value, $unique = false ) {
		$GLOBALS['__faz_user_meta'][ $user_id ][ $key ][] = $value;
		return true;
	}
	function delete_user_meta( $user_id, $key, $value = '' ) {
		if ( empty( $GLOBALS['__faz_user_meta'][ $user_id ][ $key ] ) ) {
			return true;
		}
		$GLOBALS['__faz_user_meta'][ $user_id ][ $key ] = array_values(
			array_filter(
				$GLOBALS['__faz_user_meta'][ $user_id ][ $key ],
				static function ( $stored ) use ( $value ) {
					return $stored !== $value;
				}
			)
		);
		return true;
	}

	require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/api/class-api.php';

	use FazCookie\Admin\Modules\Scanner\Includes\Controller;

	$checks   = 0;
	$failures = 0;
	function check( $condition, $label ) {
		global $checks, $failures;
		++$checks;
		if ( $condition ) {
			echo "  [PASS] {$label}\n";
			return;
		}
		++$failures;
		echo "  [FAIL] {$label}\n";
	}

	/**
	 * Put the process into one request shape.
	 *
	 * @param bool   $admin   Whether WordPress reports is_admin().
	 * @param bool   $ajax    Whether this is admin-ajax.
	 * @param string $referer Referring URL, '' for none.
	 * @param string $uri     REQUEST_URI.
	 */
	function shape( $admin, $ajax, $referer = '', $uri = '/' ) {
		$GLOBALS['__faz_is_admin']   = $admin;
		$GLOBALS['__faz_doing_ajax'] = $ajax;
		$_SERVER['REQUEST_URI']      = $uri;
		if ( '' === $referer ) {
			unset( $_SERVER['HTTP_REFERER'] );
		} else {
			$_SERVER['HTTP_REFERER'] = $referer;
		}
	}

	/*
	 * ── 1. The classifier, over the shapes that actually occur ────────────
	 *
	 * The load-bearing negatives are the admin-ajax and REST calls a SCANNED
	 * front-end page makes: is_admin() is true for the former no matter who
	 * called it, so a bare is_admin() gate would delete the very capability the
	 * observer exists for.
	 */
	shape( true, false, '', '/wp-admin/admin.php?page=faz-cookie-manager' );
	check( Controller::request_is_admin_context(), 'a wp-admin screen rendering itself is admin context' );

	shape( true, true, 'https://example.test/wp-admin/admin.php?page=faz-cookie-manager', '/wp-admin/admin-ajax.php' );
	check( Controller::request_is_admin_context(), 'admin-ajax called BY a wp-admin screen is admin context' );

	shape( true, true, 'https://example.test/shop/', '/wp-admin/admin-ajax.php' );
	check( ! Controller::request_is_admin_context(), 'admin-ajax called by a scanned front-end page is NOT admin context, despite is_admin()' );

	shape( false, false, 'https://example.test/shop/', '/wp-json/wc/store/cart' );
	check( ! Controller::request_is_admin_context(), 'a REST call from a scanned page is not admin context' );

	shape( false, false, 'https://example.test/wp-admin/post.php?post=12&action=edit', '/wp-json/wp/v2/posts/12/autosaves' );
	check( Controller::request_is_admin_context(), 'a REST call from the block editor IS admin context, which is_admin() alone cannot see' );

	shape( false, false, '', '/shop/' );
	check( ! Controller::request_is_admin_context(), 'a plain front-end page load is not admin context' );

	shape( true, false, '', '/wp-admin/load-styles.php' );
	check( Controller::request_is_admin_context(), 'a wp-admin asset endpoint is admin context' );

	// admin-post.php is the other front-end-callable wp-admin file: WP_ADMIN is
	// defined for it and its path is under wp-admin, so both the is_admin() and
	// the path branch have to stand aside for the Referer.
	shape( true, false, 'https://example.test/contact/', '/wp-admin/admin-post.php' );
	check( ! Controller::request_is_admin_context(), 'admin-post.php submitted from a front-end form is not admin context' );

	shape( true, false, 'https://example.test/wp-admin/options-general.php', '/wp-admin/admin-post.php' );
	check( Controller::request_is_admin_context(), 'admin-post.php submitted from a wp-admin screen is admin context' );

	shape( false, false, 'https://example.test/wp-admin-tips/', '/wp-admin-tips/' );
	check( ! Controller::request_is_admin_context(), 'a front-end path merely PREFIXED by wp-admin is not admin context' );

	/*
	 * ── 1b. The scanner's own iframe ──────────────────────────────────────
	 *
	 * Every page the engine crawls is dispatched through an iframe embedded in
	 * its wp-admin screen, so a legitimately scanned page ALWAYS carries a
	 * wp-admin Referer. Reading that as admin browsing stamped the whole crawl
	 * admin_context: import then routed those observations to the
	 * reported-never-imported bucket, and because that bucket is excluded from
	 * $attributable the request-cookie path dropped the same names again. A
	 * cookie a scanned page sets via its own Set-Cookie header — the exact
	 * thing the server-side capture exists to find — could not be imported.
	 *
	 * The exemption needs BOTH the faz_scanning marker and a live capture
	 * session, so neither the admin's own browsing nor a forged query
	 * parameter can borrow it. The negatives below are what hold that line.
	 */
	$scan_token       = str_repeat( 'c', 32 );
	$scan_session_key = 'faz_scan_session_' . hash( 'sha256', $scan_token );

	$arm_scan_session = static function () use ( $scan_token, $scan_session_key ) {
		$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ]      = $scan_token;
		$GLOBALS['__faz_transients'][ $scan_session_key ] = array(
			'user_id'    => 7,
			'scan_id'    => str_repeat( 'd', 32 ),
			'created_at' => time(),
		);
	};
	$disarm_scan_session = static function () use ( $scan_session_key ) {
		unset( $_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] );
		unset( $GLOBALS['__faz_transients'][ $scan_session_key ] );
	};

	$arm_scan_session();
	$_GET['faz_scanning'] = '1';
	shape( false, false, 'https://example.test/wp-admin/admin.php?page=faz-cookie-manager-cookies', '/shop/' );
	check(
		! Controller::request_is_admin_context(),
		'a scanned front-end page is NOT admin context despite the wp-admin Referer its own iframe produces'
	);

	// Same session, same wp-admin Referer, but the page was not dispatched by
	// the engine: this is the administrator browsing their own site while a
	// scan happens to be open, and it must stay classified as admin.
	unset( $_GET['faz_scanning'] );
	shape( false, false, 'https://example.test/wp-admin/admin.php?page=faz-cookie-manager-cookies', '/shop/' );
	check(
		Controller::request_is_admin_context(),
		'without the faz_scanning marker the same request is admin browsing, not a scanned page'
	);

	// The marker alone must not launder anything: with no live capture session
	// the query parameter is just an attacker-supplied string.
	$disarm_scan_session();
	$_GET['faz_scanning'] = '1';
	shape( false, false, 'https://example.test/wp-admin/admin.php?page=faz-cookie-manager-cookies', '/shop/' );
	check(
		Controller::request_is_admin_context(),
		'the faz_scanning marker without a live capture session cannot exempt a request'
	);

	// And the exemption must never reach a genuine wp-admin path.
	$arm_scan_session();
	shape( true, false, 'https://example.test/wp-admin/admin.php?page=faz-cookie-manager', '/wp-admin/admin.php' );
	check(
		Controller::request_is_admin_context(),
		'a wp-admin path stays admin context even with a live scan session and the marker set'
	);

	unset( $_GET['faz_scanning'] );
	$disarm_scan_session();

	/*
	 * ── 2. The observer records which side each Set-Cookie came from ──────
	 */
	$token = str_repeat( 'a', 32 );
	$session_key = 'faz_scan_session_' . hash( 'sha256', $token );

	function run_observer( $token, $session_key ) {
		$GLOBALS['__faz_actions'] = array();
		$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $token;
		$GLOBALS['__faz_transients'][ $session_key ] = array(
			'user_id'    => 7,
			'scan_id'    => str_repeat( 'b', 32 ),
			'created_at' => time(),
		);
		Controller::register_browser_scan_observer();
		foreach ( $GLOBALS['__faz_actions']['shutdown'] as $callback ) {
			$callback();
		}
	}

	function observations() {
		return isset( $GLOBALS['__faz_user_meta'][7][ Controller::BROWSER_SCAN_META ] )
			? $GLOBALS['__faz_user_meta'][7][ Controller::BROWSER_SCAN_META ]
			: array();
	}

	function observation_for( $name ) {
		foreach ( observations() as $row ) {
			if ( isset( $row['name'] ) && $name === $row['name'] ) {
				return $row;
			}
		}
		return null;
	}

	function observations_named( $name ) {
		return array_values(
			array_filter(
				observations(),
				static function ( $row ) use ( $name ) {
					return isset( $row['name'] ) && $name === $row['name'];
				}
			)
		);
	}

	$GLOBALS['__faz_user_meta'] = array();
	shape( false, false, 'https://example.test/shop/', '/shop/product/' );
	$GLOBALS['__faz_headers'] = array( 'Set-Cookie: shop_session=abc; Path=/; Max-Age=3600' );
	run_observer( $token, $session_key );
	$front = observation_for( 'shop_session' );
	check( null !== $front, 'a front-end Set-Cookie is still captured — the observer is not gated away' );
	check( null !== $front && empty( $front['admin_context'] ), 'a front-end observation is not marked admin context' );
	check( null !== $front && 'example.test' === $front['domain'] && '/' === $front['path'], 'captured observations store their effective domain and path identity' );

	$GLOBALS['__faz_headers'] = array( 'Set-Cookie: ephemeral=live; Path=/shop; Max-Age=3600' );
	run_observer( $token, $session_key );
	check( null !== observation_for( 'ephemeral' ), 'an active runtime directive is observed before a later clear' );
	$GLOBALS['__faz_headers'] = array( 'Set-Cookie: ephemeral=; Path=/shop; Max-Age=0' );
	run_observer( $token, $session_key );
	check( null === observation_for( 'ephemeral' ), 'a later Max-Age=0 removes the matching captured observation' );

	$GLOBALS['__faz_headers'] = array( 'Set-Cookie: scoped_cookie=live; Path=/one; Max-Age=3600' );
	run_observer( $token, $session_key );
	$GLOBALS['__faz_headers'] = array( 'Set-Cookie: scoped_cookie=; Path=/two; Max-Age=-1' );
	run_observer( $token, $session_key );
	check( 1 === count( observations_named( 'scoped_cookie' ) ), 'a clearing directive with another path does not erase the active identity' );

	$GLOBALS['__faz_headers'] = array( 'Set-Cookie: already_expired=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT' );
	run_observer( $token, $session_key );
	check( null === observation_for( 'already_expired' ), 'a deletion-only past Expires header is never persisted as an observation' );

	shape( true, false, '', '/wp-admin/plugins.php' );
	$GLOBALS['__faz_headers'] = array( 'Set-Cookie: thirdparty_admin_ui=1; Path=/wp-admin' );
	run_observer( $token, $session_key );
	$adm = observation_for( 'thirdparty_admin_ui' );
	check( null !== $adm, 'a wp-admin Set-Cookie is still captured (bucketed, not discarded)' );
	check( null !== $adm && ! empty( $adm['admin_context'] ), 'a wp-admin observation is marked admin context' );

	/*
	 * ── 3. The drain classifies, and a name seen from both sides is the site's
	 */
	$scan_id = str_repeat( 'b', 32 );
	function stage_observations( $token, $session_key, $scan_id, $rows ) {
		$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $token;
		$GLOBALS['__faz_transients'][ $session_key ] = array(
			'user_id'    => 7,
			'scan_id'    => $scan_id,
			'created_at' => time(),
		);
		$GLOBALS['__faz_user_meta'] = array();
		foreach ( $rows as $row ) {
			$GLOBALS['__faz_user_meta'][7][ Controller::BROWSER_SCAN_META ][] = array_merge(
				array(
					'token'       => $token,
					'observed_at' => time(),
					'name'        => '',
					'domain'      => '',
					'path'        => '/',
					'expires'     => '',
					'max-age'     => '',
				),
				$row
			);
		}
	}

	$controller = new Controller();

	stage_observations(
		$token,
		$session_key,
		$scan_id,
		array(
			array( 'name' => 'shop_session', 'admin_context' => false ),
			array( 'name' => 'thirdparty_admin_ui', 'admin_context' => true ),
		)
	);
	$drained = $controller->collect_browser_scan_session( $scan_id );
	$by_name = array();
	foreach ( $drained as $row ) {
		$by_name[ $row['name'] ] = $row;
	}
	check( 2 === count( $drained ), 'both sightings are drained' );
	check( isset( $by_name['shop_session'] ) && empty( $by_name['shop_session']['admin_context'] ) && 'server-runtime' === $by_name['shop_session']['source'], 'the front-end row keeps its server-runtime provenance' );
	check( isset( $by_name['thirdparty_admin_ui'] ) && ! empty( $by_name['thirdparty_admin_ui']['admin_context'] ) && 'admin-runtime' === $by_name['thirdparty_admin_ui']['source'], 'the wp-admin row is drained as admin-runtime' );

	// Order must not decide provenance: a cookie the site sets is the site's
	// even if the admin's own browsing happened to hit it first.
	foreach ( array( 'admin-first', 'front-first' ) as $order ) {
		$rows = array(
			array( 'name' => 'both_sides', 'admin_context' => true ),
			array( 'name' => 'both_sides', 'admin_context' => false ),
		);
		if ( 'front-first' === $order ) {
			$rows = array_reverse( $rows );
		}
		stage_observations( $token, $session_key, $scan_id, $rows );
		$drained = $controller->collect_browser_scan_session( $scan_id );
		check(
			1 === count( $drained ) && 'both_sides' === $drained[0]['name'] && empty( $drained[0]['admin_context'] ),
			"a name seen from both sides is the site's, not the admin's ({$order})"
		);
	}

	/*
	 * ── 4. The import declares the front-end ones and reports the rest ────
	 */
	class FazTest_Import_Controller {
		public $session_cookies = array();
		public $request_names   = array();
		public $persisted       = array();
		public $finished        = 0;
		public $scheduled       = 0;
		public $fail_next_save  = false;

		// The session must be MODELLED, not stubbed true. Returning true
		// unconditionally made the retry assertion structurally unable to fail:
		// it could not express "the session is gone", which is the entire
		// failure mode F011 describes — a save failure tore the capture down,
		// so the retry the 500 advertised answered 409. With a real flag, the
		// assertion goes red the moment teardown moves back above the save.
		public $session_open = true;

		// Held and remembered are modelled for the same reason session_open is:
		// stubbing them would make the assertions unable to fail. A held session
		// stays OPEN — that is the whole point, the evidence survives — so a
		// double that cleared it on hold would silently pass a test for the
		// opposite behaviour.
		public $held        = 0;
		public $remembered  = array();
		// Call ORDER is part of the contract, not an implementation detail, so
		// the double records it. Remembering the outcome must happen BEFORE the
		// session is closed: a request that dies between the two must leave a
		// record a resubmit can find. An assertion that only checks the record
		// EXISTS passes in either order and proves nothing.
		public $seq         = 0;
		public $seq_remember = 0;
		public $seq_finish   = 0;

		public function browser_scan_session_matches( $scan_id ) { return $this->session_open; }
		public function hold_browser_scan_session( $scan_id ) {
			if ( ! $this->session_open ) {
				return false;
			}
			++$this->held;
			return true;
		}
		public function remember_browser_scan_result( $scan_id, $result ) {
			if ( 0 === $this->seq_remember ) {
				$this->seq_remember = ++$this->seq;
			} else {
				++$this->seq;
			}
			$this->remembered[ $scan_id ] = $result;
		}
		public function recall_browser_scan_result( $scan_id ) {
			return isset( $this->remembered[ $scan_id ] ) ? $this->remembered[ $scan_id ] : null;
		}
		public function browser_scan_session_failure_reason( $scan_id ) { return $this->session_open ? 'match' : 'expired'; }
		public function collect_browser_scan_session( $scan_id ) { return $this->session_cookies; }
		public function finish_browser_scan_session( $scan_id ) {
			$this->seq_finish = ++$this->seq;
			++$this->finished;
			$this->session_cookies = array();
			$this->session_open    = false;
			return true;
		}
		public function browser_scan_capture_was_truncated() { return false; }
		public function extract_request_cookie_names( $header, $parsed ) { return $this->request_names; }
		public function schedule_httponly_check( $urls ) {
			++$this->scheduled;
			return count( $urls );
		}
		public function record_scan_observations( $names, $complete ) {}
		public function deletable_stale_keys() { return array(); }
		public function save_scan_result( $cookies, $pages, $scripts, $metrics ) {
			if ( $this->fail_next_save ) {
				$this->fail_next_save = false;
				throw new \RuntimeException( 'induced persistence failure' );
			}
			$this->persisted = $cookies;
			$names           = array();
			foreach ( $cookies as $cookie ) {
				$names[] = $cookie['name'];
			}
			return array( 'cookie_names' => $names );
		}
	}

	$fake = new FazTest_Import_Controller();
	$fake->session_cookies = array(
		array( 'name' => 'shop_session', 'domain' => 'example.test', 'duration' => '1 hour', 'source' => 'server-runtime', 'admin_context' => false ),
		array( 'name' => 'thirdparty_admin_ui', 'domain' => 'example.test', 'duration' => 'session', 'source' => 'admin-runtime', 'admin_context' => true ),
	);
	// Both names are ALSO in the admin browser's Cookie header on the import
	// request, which is where seeding $attributable from admin observations used
	// to un-bucket the admin-only one on the already-fixed request-cookie path.
	$fake->request_names = array( 'shop_session', 'thirdparty_admin_ui', 'tk_ai' );

	$api      = new \FazCookie\Admin\Modules\Scanner\Api\Api( $fake );
	$request  = new FazTest_Import_Request(
		array( 'cookies' => array(), 'jar_cookies' => array(), 'pages_scanned' => 3, 'scripts' => array(), 'metrics' => array(), 'scanned_urls' => array() ),
		array( 'scan_id' => str_repeat( 'b', 32 ) ),
		array( 'cookie' => 'shop_session=1; thirdparty_admin_ui=1; tk_ai=1' )
	);
	$result = $api->import_cookies( $request );

	$persisted_names = array();
	foreach ( $fake->persisted as $row ) {
		$persisted_names[] = $row['name'];
	}
	$jar_names = isset( $result['jar_only_cookies'] ) ? $result['jar_only_cookies'] : array();

	check( in_array( 'shop_session', $persisted_names, true ), 'a front-end runtime observation is still declared' );
	check( ! in_array( 'thirdparty_admin_ui', $persisted_names, true ), 'a wp-admin runtime observation is NOT declared' );
	check( in_array( 'thirdparty_admin_ui', $jar_names, true ), 'the wp-admin observation is reported instead of dropped in silence' );
	check( in_array( 'tk_ai', $jar_names, true ), 'an unattributable jar name is still reported' );
	check( ! in_array( 'tk_ai', $persisted_names, true ), 'an unattributable jar name is still not declared' );
	$persisted_sources = array();
	foreach ( $fake->persisted as $row ) {
		$persisted_sources[] = isset( $row['source'] ) ? $row['source'] : '';
	}
	check(
		! in_array( 'admin-runtime', $persisted_sources, true ),
		'no admin-runtime row reaches the persisted set by any route'
	);
	check( 1 === $fake->finished, 'successful persistence closes the browser capture exactly once' );
	check( 1 === $fake->scheduled, 'successful persistence schedules HttpOnly enrichment exactly once' );

	/*
	 * ── 5. A persistence failure keeps the same capture retryable ─────────
	 */
	$retry_fake = new FazTest_Import_Controller();
	$retry_fake->session_cookies = array(
		array( 'name' => 'retry_httponly', 'domain' => 'example.test', 'duration' => '1 hour', 'source' => 'server-runtime', 'admin_context' => false ),
	);
	$retry_fake->fail_next_save = true;
	$retry_api     = new \FazCookie\Admin\Modules\Scanner\Api\Api( $retry_fake );
	$retry_request = new FazTest_Import_Request(
		array( 'cookies' => array(), 'jar_cookies' => array(), 'pages_scanned' => 1, 'scripts' => array(), 'metrics' => array(), 'scanned_urls' => array( 'https://example.test/' ) ),
		array( 'scan_id' => str_repeat( 'c', 32 ) ),
		array()
	);
	$failed_import = $retry_api->import_cookies( $retry_request );
	check( is_wp_error( $failed_import ) && 'faz_scan_import_failed' === $failed_import->code, 'an induced save failure returns the explicit retryable import error' );
	check( 0 === $retry_fake->finished && 1 === count( $retry_fake->session_cookies ), 'a failed save preserves the capture session and its HttpOnly evidence' );
	check( 0 === $retry_fake->scheduled, 'a failed save schedules no background replay' );

	$retried_import = $retry_api->import_cookies( $retry_request );
	check( ! is_wp_error( $retried_import ), 'the same scan id can retry successfully after persistence recovers' );
	check( 1 === $retry_fake->finished && empty( $retry_fake->session_cookies ), 'the successful retry closes and clears the capture' );
	check( 1 === $retry_fake->scheduled, 'the successful retry schedules one background replay' );
	check( isset( $retry_fake->persisted[0]['name'] ) && 'retry_httponly' === $retry_fake->persisted[0]['name'], 'the retry persists the same server-captured HttpOnly row' );

	// --- The import is now idempotent, and a failure HOLDS the evidence -------
	//
	// Two separate promises the endpoint makes, and each is only worth making if
	// the other holds.
	//
	// A failed save keeps the capture instead of tearing it down: the
	// observations are the only record of what this site set before consent, and
	// reproducing them means re-running a crawl that can take many minutes.
	//
	// A successful save is REMEMBERED before the session is closed, so a
	// resubmit is answered with the success that really happened. Without it,
	// the one case the client's retry exists for — a request that reached the
	// server and succeeded, whose response was then lost — is reported to the
	// administrator as a failure, and they re-run the whole crawl for data that
	// is already saved.

	$hold_fake                 = new FazTest_Import_Controller();
	$hold_fake->fail_next_save = true;
	$hold_api                  = new \FazCookie\Admin\Modules\Scanner\Api\Api( $hold_fake );
	$hold_id                   = str_repeat( 'c', 32 );
	$hold_request              = new FazTest_Import_Request(
		array( 'cookies' => array(), 'jar_cookies' => array(), 'pages_scanned' => 2, 'scripts' => array(), 'metrics' => array(), 'scanned_urls' => array() ),
		array( 'scan_id' => $hold_id ),
		array()
	);
	$hold_result = $hold_api->import_cookies( $hold_request );

	check( is_wp_error( $hold_result ), 'a save failure still returns an error rather than a silent success' );
	check( 1 === $hold_fake->held, 'a save failure HOLDS the capture session' );
	check( 0 === $hold_fake->finished, 'a save failure does not tear the session down' );
	check( $hold_fake->session_open, 'the evidence is still there for the retry the error advertises' );
	// The client only offers a Retry button when this flag says the evidence
	// survived. Advertising one after a failed hold would send the
	// administrator to a 409.
	$hold_data = is_wp_error( $hold_result ) ? $hold_result->get_error_data() : array();
	check(
		isset( $hold_data['faz_session_held'] ) && true === $hold_data['faz_session_held'],
		'the error tells the client the session was held'
	);
	check( 0 === $hold_fake->scheduled, 'no background replay is scheduled for an import that saved nothing' );

	// Now the same scan succeeds on the retry, and the outcome is remembered.
	$hold_fake->fail_next_save = false;
	$retry_ok                  = $hold_api->import_cookies( $hold_request );

	check( ! is_wp_error( $retry_ok ), 'retrying the held scan succeeds' );
	check( 1 === $hold_fake->finished, 'the session is closed once the save actually lands' );
	check( isset( $hold_fake->remembered[ $hold_id ] ), 'the successful outcome is remembered against its scan id' );

	// The load-bearing ordering: remembered BEFORE finished. If the request dies
	// between the two, a resubmit finds the record and is told the truth. In the
	// other order it would find neither session nor record and report a failure
	// that never happened. Modelled by asking the double, whose
	// finish_browser_scan_session() closes the session: a record that exists
	// while the session is already closed proves nothing about order, but a
	// record written by the FIRST of the two calls does.
	$order_fake = new FazTest_Import_Controller();
	$order_api  = new \FazCookie\Admin\Modules\Scanner\Api\Api( $order_fake );
	$order_id   = str_repeat( 'd', 32 );
	$order_fake->remembered = array();
	$order_api->import_cookies(
		new FazTest_Import_Request(
			array( 'cookies' => array(), 'jar_cookies' => array(), 'pages_scanned' => 1, 'scripts' => array(), 'metrics' => array(), 'scanned_urls' => array() ),
			array( 'scan_id' => $order_id ),
			array()
		)
	);
	check( isset( $order_fake->remembered[ $order_id ] ), 'a completed import leaves a record a resubmit can find' );
	check(
		$order_fake->seq_remember > 0 && $order_fake->seq_finish > 0
			&& $order_fake->seq_remember < $order_fake->seq_finish,
		'the outcome is recorded BEFORE the session is closed, so a request dying between them still answers a resubmit truthfully'
	);

	// And the resubmit itself: the session is gone, but the answer is the stored
	// success, not "this session expired".
	$dup = $order_api->import_cookies(
		new FazTest_Import_Request(
			array( 'cookies' => array(), 'jar_cookies' => array(), 'pages_scanned' => 1, 'scripts' => array(), 'metrics' => array(), 'scanned_urls' => array() ),
			array( 'scan_id' => $order_id ),
			array()
		)
	);
	check( ! is_wp_error( $dup ), 'a resubmit of a completed import is not rejected as expired' );
	$dup_body = is_array( $dup ) ? $dup : ( is_object( $dup ) && method_exists( $dup, 'get_data' ) ? $dup->get_data() : array() );
	check( ! empty( $dup_body['duplicate'] ), 'the resubmit is marked as a duplicate rather than passed off as a fresh import' );
	check( 1 === $order_fake->finished, 'the duplicate does not close the session a second time' );
	// The expensive half: a duplicate must not re-run the background replay, or
	// one crawl schedules two.
	check( 1 === $order_fake->scheduled, 'the duplicate does not schedule the replay again' );

	if ( $failures ) {
		echo "\n{$failures} of {$checks} admin-context capture checks failed.\n";
		exit( 1 );
	}
	echo "\n{$checks} admin-context capture checks passed.\n";
}
