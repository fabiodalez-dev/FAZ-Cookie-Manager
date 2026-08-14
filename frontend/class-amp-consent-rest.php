<?php
/**
 * Server-side bridge for AMP consent.
 *
 * @package    FazCookie
 * @subpackage FazCookie/Frontend
 */

namespace FazCookie\Frontend;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FazCookie\Admin\Modules\Banners\Includes\Controller as Banner_Controller;
use FazCookie\Admin\Modules\Consentlogs\Includes\Controller as Consent_Log_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories;

/**
 * Remote consent state used by AMP's checkConsentHref/onUpdateHref flow.
 *
 * The public routes intentionally do not use a WordPress login nonce: AMP
 * Cache pages are cross-origin anonymous documents. Instead every request is
 * bound to an active banner by an HMAC scope and must pass AMP's CORS security
 * model (publisher source origin + exact publisher/cache request origin).
 */
class AMP_Consent_Rest {

	const REST_NAMESPACE = 'faz/v1';
	const CHECK_ROUTE    = '/amp-consent/check';
	const UPDATE_ROUTE   = '/amp-consent/update';
	const STATE_VERSION  = 1;

	/** @var string CORS origin accepted for the request currently being served. */
	private $cors_origin = '';

	/** @var string Publisher source origin accepted for the current request. */
	private $cors_source_origin = '';

	/**
	 * Register the REST and response-header hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// WordPress core reflects arbitrary REST origins. Replace that broad
		// header for these two routes after core's rest_send_cors_headers runs.
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_cors_headers' ), 20, 4 );
	}

	/**
	 * Register AMP endpoints.
	 *
	 * @return void
	 */
	public function register_routes() {
		$common = array(
			'permission_callback' => '__return_true',
			'args'                => array(
				'banner' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_title',
				),
				'scope'  => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::CHECK_ROUTE,
			array_merge(
				$common,
				array(
					'methods'  => 'POST',
					'callback' => array( $this, 'handle_check' ),
				)
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::UPDATE_ROUTE,
			array_merge(
				$common,
				array(
					'methods'  => 'POST',
					'callback' => array( $this, 'handle_update' ),
					'args'     => array_merge(
						$common['args'],
						array(
							'ampUserId' => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'consentStateValue' => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => 'sanitize_key',
							),
							'purposeConsent' => array(
								'type' => 'object',
							),
							// Current amp-consent runtime uses the plural key in both
							// check and update payloads. Keep the singular alias above for
							// compatibility with older examples/integrations.
							'purposeConsents' => array(
								'type' => 'object',
							),
						)
					),
				)
			)
		);

		// Explicit OPTIONS routes make preflight obey the same origin checks as
		// state-changing POSTs instead of inheriting WordPress's generic CORS.
		foreach ( array( self::CHECK_ROUTE, self::UPDATE_ROUTE ) as $route ) {
			register_rest_route(
				self::REST_NAMESPACE,
				$route,
				array(
					'methods'             => 'OPTIONS',
					'callback'            => array( $this, 'handle_options' ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	/**
	 * Build banner-scoped endpoint URLs for amp-consent.
	 *
	 * @param object $banner Active banner object.
	 * @return array{check:string,update:string,instance:string}
	 */
	public static function endpoint_urls( $banner ) {
		$slug         = sanitize_title( (string) $banner->get_slug() );
		$query        = array(
			'banner' => $slug,
			'scope'  => self::scope_token( $slug ),
		);
		$update_query = $query;
		$source_origin = self::origin_from_url( home_url( '/' ) );
		if ( '' !== $source_origin ) {
			// amp-consent intentionally sends onUpdateHref with ampCors=false,
			// so the runtime does not append __amp_source_origin to cross-origin
			// cache requests. Bind it into the rendered endpoint URL instead.
			$update_query['__amp_source_origin'] = $source_origin;
		}
		return array(
			'check'    => set_url_scheme( add_query_arg( $query, rest_url( self::REST_NAMESPACE . self::CHECK_ROUTE ) ), 'https' ),
			'update'   => set_url_scheme( add_query_arg( $update_query, rest_url( self::REST_NAMESPACE . self::UPDATE_ROUTE ) ), 'https' ),
			'instance' => self::instance_id( $slug ),
		);
	}

	/**
	 * Public, cache-safe scope marker. CORS remains the CSRF control.
	 *
	 * @param string $banner_slug Banner slug.
	 * @return string
	 */
	public static function scope_token( $banner_slug ) {
		return wp_hash( 'faz_amp_consent_scope|' . sanitize_title( (string) $banner_slug ), 'auth' );
	}

	/**
	 * Stable AMP consent instance for one banner scope.
	 *
	 * @param string $banner_slug Banner slug.
	 * @return string
	 */
	public static function instance_id( $banner_slug ) {
		return 'faz-cookie-consent-' . sanitize_title( (string) $banner_slug );
	}

	/**
	 * Return visible optional categories as AMP purposes.
	 *
	 * Purpose IDs intentionally reuse sanitized category slugs. This makes
	 * `data-block-on-consent-purposes` and the standard consent cookie share a
	 * single vocabulary and avoids a lossy mapping at the REST boundary.
	 *
	 * @return array[] Array of {id,slug,name} maps.
	 */
	public static function get_purposes() {
		$purposes   = array();
		$categories = Category_Controller::get_instance()->get_items();
		foreach ( (array) $categories as $raw_category ) {
			$category = new Cookie_Categories( $raw_category );
			$slug     = sanitize_key( (string) $category->get_slug() );
			if (
				'' === $slug
				|| 'necessary' === $slug
				|| 'wordpress-internal' === $slug
				|| ! $category->get_visibility()
			) {
				continue;
			}
			$purposes[] = array(
				'id'   => $slug,
				'slug' => $slug,
				'name' => wp_strip_all_tags( (string) $category->get_name( faz_current_language() ) ),
			);
		}
		return $purposes;
	}

	/**
	 * checkConsentHref callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_check( $request ) {
		$cors = $this->authorize_request( $request );
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}
		if ( ! $this->is_banner_control_enabled() ) {
			return $this->response(
				array(
					'consentRequired'   => false,
					'consentStateValue' => 'unknown',
					'purposeConsents'   => array(),
					'expireCache'       => true,
				)
			);
		}

		$context = $this->resolve_context( $request );
		if ( is_wp_error( $context ) ) {
			if ( 'faz_amp_inactive_banner' === $context->get_error_code() ) {
				return $this->response(
					array(
						'consentRequired'   => false,
						'consentStateValue' => 'unknown',
						'purposeConsents'   => array(),
						'expireCache'       => true,
					)
				);
			}
			return $context;
		}

		$instance = sanitize_text_field( (string) $request->get_param( 'consentInstanceId' ) );
		if ( ! hash_equals( $context['instance'], $instance ) ) {
			return new \WP_Error( 'faz_amp_invalid_instance', __( 'Invalid AMP consent instance.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		$incoming_state = self::sanitize_state( $request->get_param( 'consentStateValue' ) );
		$cookie         = function_exists( 'faz_get_valid_consent_cookie' ) ? faz_get_valid_consent_cookie() : '';
		$server_state   = self::state_from_cookie( $cookie, $context );

		// A signed state returned by checkConsentHref binds AMP's local cache to
		// the server expiry/revision. It is available after the bridge has first
		// reconciled a valid publisher cookie; onUpdateHref responses are not used
		// by AMP to update consentString, so this must not be presented as a way to
		// bypass browsers that reject the initial third-party Set-Cookie entirely.
		if ( false === $server_state ) {
			$signed = sanitize_text_field( (string) $request->get_param( 'consentString' ) );
			$server_state = self::decode_state_string( $signed, $context );
		}

		if ( false === $server_state ) {
			$data = array(
				'consentRequired'   => true,
				'consentStateValue' => 'unknown',
				'purposeConsents'   => self::purpose_defaults( $context['purposes'], false ),
				'purposeConsent'    => self::purpose_defaults( $context['purposes'], false ),
				// Clear an accepted/rejected AMP localStorage decision whenever the
				// server cannot prove its current scope, revision and lifetime.
				'expireCache'       => in_array( $incoming_state, array( 'accepted', 'rejected' ), true ),
				'sharedData'        => self::shared_data( $context, 0 ),
			);
			return $this->response( $data );
		}

		$data = array(
			'consentRequired'   => true,
			'consentStateValue' => $server_state['state'],
			'purposeConsents'   => $server_state['purposes'],
			'purposeConsent'    => $server_state['purposes'],
			'expireCache'       => 'unknown' !== $incoming_state && $incoming_state !== $server_state['state'],
			'sharedData'        => self::shared_data( $context, $server_state['expires'] ),
		);
		// Classic FAZ cookies created before the AMP bridge do not contain their
		// original absolute expiry. Do not invent a new sliding TTL for them: the
		// browser cookie remains authoritative and its disappearance will make the
		// next check expire AMP's cache. Bridge-written cookies carry `exp`, so
		// their exact deadline can safely be signed into AMP local storage.
		if ( $server_state['expires'] > time() ) {
			$data['consentString'] = self::encode_state_string( $server_state, $context );
		}
		return $this->response( $data );
	}

	/**
	 * onUpdateHref callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_update( $request ) {
		$cors = $this->authorize_request( $request );
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}
		if ( ! $this->is_banner_control_enabled() ) {
			return new \WP_Error( 'faz_amp_banner_disabled', __( 'The AMP consent banner is disabled.', 'faz-cookie-manager' ), array( 'status' => 409 ) );
		}

		$context = $this->resolve_context( $request );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$instance = sanitize_text_field( (string) $request->get_param( 'consentInstanceId' ) );
		if ( ! hash_equals( $context['instance'], $instance ) ) {
			return new \WP_Error( 'faz_amp_invalid_instance', __( 'Invalid AMP consent instance.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		$state = self::sanitize_state( $request->get_param( 'consentStateValue' ) );
		if ( ! in_array( $state, array( 'accepted', 'rejected' ), true ) ) {
			return new \WP_Error( 'faz_amp_invalid_state', __( 'Invalid AMP consent state.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		if ( function_exists( 'faz_throttle_request' ) && faz_throttle_request( 'faz_amp_consent_update', 3 ) ) {
			return new \WP_Error( 'faz_amp_rate_limited', __( 'Too many consent updates.', 'faz-cookie-manager' ), array( 'status' => 429 ) );
		}

		$purpose_input = $request->get_param( 'purposeConsents' );
		if ( ! is_array( $purpose_input ) ) {
			$purpose_input = $request->get_param( 'purposeConsent' );
		}
		$purposes      = self::normalize_purpose_consent( $purpose_input, $context['purposes'], $state );
		$expires       = time() + ( $context['expiry_days'] * DAY_IN_SECONDS );
		$consent_id    = self::consent_id( $request->get_param( 'ampUserId' ) );
		$cookie_value  = self::build_cookie_value( $state, $purposes, $context, $consent_id, $expires );

		if ( function_exists( 'faz_set_browser_cookie' ) ) {
			// AMP Cache pages call the publisher endpoint cross-site. None+Secure
			// lets supporting browsers store/send the publisher cookie in that
			// credentialed request. Browsers that block third-party cookies may
			// still refuse it; in that case a later check cannot prove the cached
			// decision and expires it fail-closed. We do not claim guaranteed
			// cross-site synchronization under browser storage blocking.
			$site_origin = self::origin_from_url( home_url( '/' ) );
			$from_cache  = '' !== $this->cors_origin && $this->cors_origin !== $site_origin;
			faz_set_browser_cookie(
				'fazcookie-consent',
				$cookie_value,
				$expires,
				null,
				$from_cache ? 'None' : 'Lax',
				$from_cache ? true : null
			);
		}

		$this->maybe_log_consent( $state, $purposes, $context, $consent_id );

		$server_state = array(
			'state'    => $state,
			'purposes' => $purposes,
			'expires'  => $expires,
		);
		return $this->response(
			array(
				'updated'           => true,
				'consentStateValue' => $state,
				'consentString'     => self::encode_state_string( $server_state, $context ),
				'purposeConsents'   => $purposes,
				'purposeConsent'    => $purposes,
				'sharedData'        => self::shared_data( $context, $expires ),
			)
		);
	}

	/**
	 * CORS preflight callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_options( $request ) {
		$cors = $this->authorize_request( $request );
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}
		return $this->response( array( 'ok' => true ) );
	}

	/**
	 * Resolve and validate the banner scope carried by a cached AMP document.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function resolve_context( $request ) {
		$slug  = sanitize_title( (string) $request->get_param( 'banner' ) );
		$scope = sanitize_text_field( (string) $request->get_param( 'scope' ) );
		if ( '' === $slug || '' === $scope || ! hash_equals( self::scope_token( $slug ), $scope ) ) {
			return new \WP_Error( 'faz_amp_invalid_scope', __( 'Invalid AMP consent scope.', 'faz-cookie-manager' ), array( 'status' => 403 ) );
		}

		$banner = Banner_Controller::get_instance()->get_active_banner_by_slug( $slug );
		if ( ! $banner ) {
			return new \WP_Error( 'faz_amp_inactive_banner', __( 'The AMP consent banner is no longer active.', 'faz-cookie-manager' ), array( 'status' => 409 ) );
		}

		// The RAW law, not a gdpr/ccpa fold. class-frontend.php hashes
		// $banner->get_law() verbatim into _scopeFingerprint, and the banner UI
		// offers a third value ("gdpr_ccpa"), with the setup wizard writing
		// "both" and "popia" as well. Folding here produced a different
		// fingerprint for every one of those, so an AMP-written cookie read as
		// scope-tampered to the classic JS and a classic cookie was never
		// recognised by state_from_cookie() — the two surfaces silently stopped
		// reconciling on a mainstream GDPR+CCPA configuration.
		// Taken verbatim, not sanitize_key()'d: class-frontend.php feeds the raw
		// return value into both _activeLaw and the fingerprint hash, and the
		// classic JS writes that same raw string into __scope.law. Normalising
		// on only one side is how the two formulas drift apart again.
		$law = (string) $banner->get_law();
		if ( '' === $law ) {
			$law = 'gdpr';
		}
		// The expiry rule itself is only CCPA-vs-rest, so that one keeps the
		// fold — a "gdpr_ccpa" banner is a GDPR-family banner for lifetime
		// purposes and must not inherit the 365-day CCPA floor.
		$expiry_law        = ( 'ccpa' === $law ) ? 'ccpa' : 'gdpr';
		$settings          = $banner->get_settings();
		$configured_expiry = isset( $settings['settings']['consentExpiry']['value'] )
			? absint( $settings['settings']['consentExpiry']['value'] )
			: ( 'ccpa' === $expiry_law ? 365 : 182 );
		$expiry_days = Frontend::normalize_consent_expiry( $expiry_law, $configured_expiry );
		$revision    = function_exists( 'faz_get_consent_revision' ) ? faz_get_consent_revision() : 1;

		return array(
			'banner'            => $banner,
			'slug'              => $slug,
			'law'               => $law,
			'expiry_law'        => $expiry_law,
			'instance'          => self::instance_id( $slug ),
			'purposes'          => self::get_purposes(),
			'expiry_days'       => $expiry_days,
			'revision'          => max( 1, absint( $revision ) ),
			'scope_fingerprint' => substr( wp_hash( $slug . '|' . $law, 'auth' ), 0, 32 ),
		);
	}

	/**
	 * Whether the public banner runtime is enabled globally.
	 *
	 * @return bool
	 */
	private function is_banner_control_enabled() {
		$settings = get_option( 'faz_settings', array() );
		return is_array( $settings ) && ! empty( $settings['banner_control']['status'] );
	}

	/**
	 * Validate AMP CORS provenance before reading or mutating consent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	private function authorize_request( $request ) {
		// One bridge instance can serve more than one subrequest (for example a
		// REST batch). Never let an origin accepted for an earlier request leak
		// into the CORS response of a later denied request.
		$this->cors_origin        = '';
		$this->cors_source_origin = '';
		$source = sanitize_text_field( (string) $request->get_param( '__amp_source_origin' ) );
		$result = self::validate_cors_environment( $_SERVER, $source, home_url( '/' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->cors_origin        = $result['origin'];
		$this->cors_source_origin = $result['source'];
		return true;
	}

	/**
	 * Pure AMP CORS validator, public for regression tests.
	 *
	 * @param array  $server        Server headers.
	 * @param string $source_origin __amp_source_origin.
	 * @param string $site_url      Publisher URL.
	 * @return array|\WP_Error
	 */
	public static function validate_cors_environment( $server, $source_origin, $site_url ) {
		$site_origin   = self::origin_from_url( $site_url );
		$source_origin = self::origin_from_url( $source_origin );
		if ( '' === $site_origin ) {
			return new \WP_Error( 'faz_amp_invalid_site_origin', 'Invalid publisher origin.', array( 'status' => 500 ) );
		}

		$origin = isset( $server['HTTP_ORIGIN'] )
			? self::origin_from_url( sanitize_text_field( wp_unslash( $server['HTTP_ORIGIN'] ) ) )
			: '';
		$same_origin = isset( $server['HTTP_AMP_SAME_ORIGIN'] )
			&& 'true' === strtolower( sanitize_text_field( wp_unslash( $server['HTTP_AMP_SAME_ORIGIN'] ) ) );

		if ( '' === $origin ) {
			if ( ! $same_origin || ( '' !== $source_origin && $source_origin !== $site_origin ) ) {
				return new \WP_Error( 'faz_amp_cors_denied', 'AMP request origin denied.', array( 'status' => 403 ) );
			}
			return array( 'origin' => $site_origin, 'source' => $site_origin );
		}

		if ( $source_origin !== $site_origin ) {
			return new \WP_Error( 'faz_amp_source_origin_denied', 'AMP source origin denied.', array( 'status' => 403 ) );
		}
		if ( $origin !== $site_origin && ! self::is_allowed_amp_cache_origin( $origin, $site_origin ) ) {
			return new \WP_Error( 'faz_amp_cors_denied', 'AMP request origin denied.', array( 'status' => 403 ) );
		}

		return array( 'origin' => $origin, 'source' => $site_origin );
	}

	/**
	 * Whether an HTTPS origin belongs to a trusted AMP cache.
	 *
	 * The filter permits publishers to add another cache from the official AMP
	 * cache registry without weakening the default suffix boundary.
	 *
	 * @param string $origin      Normalized request origin.
	 * @param string $site_origin Normalized publisher origin.
	 * @return bool
	 */
	private static function is_allowed_amp_cache_origin( $origin, $site_origin ) {
		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || 'https' !== strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) ) {
			return false;
		}
		$site_parts = wp_parse_url( $site_origin );
		$host       = strtolower( isset( $parts['host'] ) ? $parts['host'] : '' );
		$site_host  = strtolower( is_array( $site_parts ) && isset( $site_parts['host'] ) ? $site_parts['host'] : '' );
		$cache_label = str_replace( '.', '-', str_replace( '-', '--', $site_host ) );
		$expected    = strlen( $cache_label ) <= 63
			? $cache_label . '.cdn.ampproject.org'
			: '';
		$allowed = '' !== $expected && hash_equals( $expected, $host );

		// Long/IDN publisher domains can use the cache's hashed hostname, and
		// other caches have different domains. Publishers may add only the exact
		// verified origins they use; the default never trusts every AMP-cache
		// subdomain, because onUpdateHref carries a static source-origin query.
		return (bool) apply_filters( 'faz_amp_consent_allowed_cache_origin', $allowed, $origin, $site_origin );
	}

	/**
	 * Normalize a URL to scheme://host[:non-default-port].
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function origin_from_url( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$host   = strtolower( $parts['host'] );
		$origin = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) ) {
			$port = (int) $parts['port'];
			if ( ! ( 80 === $port && 'http' === $scheme ) && ! ( 443 === $port && 'https' === $scheme ) ) {
				$origin .= ':' . $port;
			}
		}
		return $origin;
	}

	/**
	 * Convert AMP purpose input into a complete, fail-closed map.
	 *
	 * @param mixed  $input    Incoming purposeConsents map. AMP sends numeric
	 *                         states (1 accepted, 2 rejected); boolean aliases
	 *                         are accepted for backwards compatibility.
	 * @param array  $purposes Configured purposes.
	 * @param string $state    Global state.
	 * @return array<string,bool>
	 */
	public static function normalize_purpose_consent( $input, $purposes, $state ) {
		$input  = is_array( $input ) ? $input : array();
		$result = array();
		foreach ( (array) $purposes as $purpose ) {
			$id = isset( $purpose['id'] ) ? sanitize_key( $purpose['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}
			// Reject-all always wins. Accepted requests must explicitly carry a
			// boolean true for each purpose; missing/ambiguous values are denied.
			$result[ $id ] = 'accepted' === $state
				&& array_key_exists( $id, $input )
				&& true === self::to_bool_or_null( $input[ $id ] );
		}
		return $result;
	}

	/** @return bool|null */
	private static function to_bool_or_null( $value ) {
		if ( true === $value || false === $value ) {
			return $value;
		}
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		if ( 1 === $value || '1' === $value || 'true' === strtolower( (string) $value ) ) {
			return true;
		}
		if ( 0 === $value || '0' === $value || 'false' === strtolower( (string) $value ) ) {
			return false;
		}
		return null;
	}

	/** @return array<string,bool> */
	private static function purpose_defaults( $purposes, $value ) {
		$result = array();
		foreach ( (array) $purposes as $purpose ) {
			if ( ! empty( $purpose['id'] ) ) {
				$result[ sanitize_key( $purpose['id'] ) ] = (bool) $value;
			}
		}
		return $result;
	}

	/**
	 * Keys build_cookie_value() owns outright.
	 *
	 * A category slug is admin-editable, so one could legitimately be named
	 * "action" or "consent". Writing purposes into the same flat namespace
	 * without this guard let such a slug overwrite the control field that
	 * state_from_cookie() hard-gates on, which would make every later AMP read
	 * behave as if no decision had ever been recorded.
	 *
	 * @var string[]
	 */
	private static $reserved_cookie_keys = array(
		'consentid',
		'consent',
		'action',
		'necessary',
		'rev',
		'__scope.banner',
		'__scope.law',
		'__scope.fp',
		'source',
		'ts',
		'exp',
	);

	/**
	 * Parse the flat "key:value,key:value" consent cookie into a map.
	 *
	 * @param string $cookie Raw cookie value.
	 * @return array<string,string>
	 */
	private static function parse_cookie_pairs( $cookie ) {
		$pairs = array();
		foreach ( explode( ',', (string) $cookie ) as $chunk ) {
			if ( '' === $chunk || false === strpos( $chunk, ':' ) ) {
				continue;
			}
			list( $key, $value ) = explode( ':', $chunk, 2 );
			$key                 = trim( $key );
			if ( '' === $key ) {
				continue;
			}
			$pairs[ $key ] = trim( $value );
		}
		return $pairs;
	}

	/**
	 * Build the standard FAZ cookie from an AMP decision.
	 *
	 * The previous implementation built a fresh array and imploded it, so an AMP
	 * decision DESTROYED every key it does not know about. Two of those matter a
	 * great deal: `svc.*` holds per-service grants, and `gpc` records that the
	 * visitor sent a Global Privacy Control opt-out. Turning a recorded GPC
	 * opt-out into an AMP "accepted" cookie is the worst outcome this plugin can
	 * produce, so the existing cookie is now read and its unowned keys carried
	 * forward.
	 *
	 * @param string $state           accepted|rejected.
	 * @param array  $purposes        Purpose slug => bool.
	 * @param array  $context         Resolved banner context.
	 * @param string $consent_id      Consent identifier.
	 * @param int    $expires         Absolute expiry timestamp.
	 * @param string $existing_cookie Current cookie value; read from $_COOKIE when null.
	 * @return string
	 */
	public static function build_cookie_value( $state, $purposes, $context, $consent_id, $expires, $existing_cookie = null ) {
		if ( null === $existing_cookie ) {
			$existing_cookie = isset( $_COOKIE['fazcookie-consent'] )
				? sanitize_text_field( wp_unslash( $_COOKIE['fazcookie-consent'] ) )
				: '';
		}

		$pairs = array(
			'consentid'     => sanitize_text_field( (string) $consent_id ),
			'consent'       => 'accepted' === $state ? 'yes' : 'no',
			'action'        => 'yes',
			'necessary'     => 'yes',
			'rev'           => (string) absint( $context['revision'] ),
			'__scope.banner' => sanitize_title( $context['slug'] ),
			'__scope.law'    => (string) $context['law'],
			'__scope.fp'     => sanitize_text_field( $context['scope_fingerprint'] ),
			'source'        => 'amp',
			'ts'            => (string) time(),
			'exp'           => (string) absint( $expires ),
		);

		$purpose_keys = array();
		foreach ( (array) $purposes as $purpose => $allowed ) {
			$key = sanitize_key( $purpose );
			if ( '' === $key || in_array( $key, self::$reserved_cookie_keys, true ) ) {
				// A category whose slug collides with a control field is dropped
				// rather than allowed to corrupt the decision it would overwrite.
				continue;
			}
			$pairs[ $key ]        = $allowed ? 'yes' : 'no';
			$purpose_keys[ $key ] = true;
		}

		// Carry forward everything this bridge does not own — notably `gpc` and
		// every `svc.*` per-service grant. A key the AMP decision explicitly
		// wrote wins; anything else survives untouched.
		foreach ( self::parse_cookie_pairs( $existing_cookie ) as $key => $value ) {
			if ( isset( $pairs[ $key ] ) || isset( $purpose_keys[ $key ] ) ) {
				continue;
			}
			$pairs[ $key ] = $value;
		}

		$out = array();
		foreach ( $pairs as $key => $value ) {
			$out[] = $key . ':' . $value;
		}
		return implode( ',', $out );
	}

	/**
	 * Read a standard FAZ cookie only when scope, revision and TTL are current.
	 *
	 * @return array|false
	 */
	public static function state_from_cookie( $cookie, $context ) {
		if ( '' === (string) $cookie ) {
			return false;
		}
		$parsed = function_exists( 'faz_parse_consent_cookie' ) ? faz_parse_consent_cookie( $cookie ) : array();
		if ( 'yes' !== ( isset( $parsed['action'] ) ? $parsed['action'] : '' ) ) {
			return false;
		}
		if ( absint( isset( $parsed['rev'] ) ? $parsed['rev'] : 1 ) < absint( $context['revision'] ) ) {
			return false;
		}
		$stored_banner = isset( $parsed['__scope.banner'] ) ? $parsed['__scope.banner'] : ( isset( $parsed['banner'] ) ? $parsed['banner'] : '' );
		$stored_law    = isset( $parsed['__scope.law'] ) ? $parsed['__scope.law'] : ( isset( $parsed['law'] ) ? $parsed['law'] : '' );
		$stored_fp     = isset( $parsed['__scope.fp'] ) ? $parsed['__scope.fp'] : '';
		if (
			! hash_equals( (string) $context['slug'], (string) $stored_banner )
			|| ! hash_equals( (string) $context['law'], (string) $stored_law )
			|| ! hash_equals( (string) $context['scope_fingerprint'], (string) $stored_fp )
		) {
			return false;
		}
		$has_absolute_expiry = isset( $parsed['exp'] );
		$expires            = $has_absolute_expiry ? absint( $parsed['exp'] ) : 0;
		if ( $has_absolute_expiry && $expires <= time() ) {
			return false;
		}

		$purposes = array();
		foreach ( (array) $context['purposes'] as $purpose ) {
			$id              = sanitize_key( $purpose['id'] );
			$purposes[ $id ] = isset( $parsed[ $id ] ) && 'yes' === $parsed[ $id ];
		}
		$state = isset( $parsed['consent'] ) && 'yes' === $parsed['consent'] ? 'accepted' : 'rejected';
		return array( 'state' => $state, 'purposes' => $purposes, 'expires' => $expires );
	}

	/**
	 * Signed state carried in AMP local storage via consentString.
	 *
	 * @return string
	 */
	public static function encode_state_string( $state, $context ) {
		$payload = wp_json_encode(
			array(
				'v'        => self::STATE_VERSION,
				'banner'   => $context['slug'],
				'law'      => $context['law'],
				'rev'      => absint( $context['revision'] ),
				'exp'      => absint( $state['expires'] ),
				'state'    => self::sanitize_state( $state['state'] ),
				'purposes' => (array) $state['purposes'],
			)
		);
		$encoded = self::base64url_encode( $payload );
		return $encoded . '.' . wp_hash( 'faz_amp_consent_state|' . $encoded, 'auth' );
	}

	/** @return array|false */
	public static function decode_state_string( $signed, $context ) {
		$parts = explode( '.', (string) $signed, 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( wp_hash( 'faz_amp_consent_state|' . $parts[0], 'auth' ), $parts[1] ) ) {
			return false;
		}
		$json = self::base64url_decode( $parts[0] );
		$data = json_decode( $json, true );
		if (
			! is_array( $data )
			|| self::STATE_VERSION !== absint( isset( $data['v'] ) ? $data['v'] : 0 )
			|| ! hash_equals( (string) $context['slug'], (string) ( isset( $data['banner'] ) ? $data['banner'] : '' ) )
			|| ! hash_equals( (string) $context['law'], (string) ( isset( $data['law'] ) ? $data['law'] : '' ) )
			|| absint( isset( $data['rev'] ) ? $data['rev'] : 0 ) !== absint( $context['revision'] )
			|| absint( isset( $data['exp'] ) ? $data['exp'] : 0 ) <= time()
		) {
			return false;
		}
		$state = self::sanitize_state( isset( $data['state'] ) ? $data['state'] : '' );
		if ( ! in_array( $state, array( 'accepted', 'rejected' ), true ) ) {
			return false;
		}
		$purposes = self::normalize_purpose_consent(
			isset( $data['purposes'] ) ? $data['purposes'] : array(),
			$context['purposes'],
			$state
		);
		return array( 'state' => $state, 'purposes' => $purposes, 'expires' => absint( $data['exp'] ) );
	}

	/** @return string */
	private static function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}

	/** @return string */
	private static function base64url_decode( $value ) {
		$value = strtr( (string) $value, '-_', '+/' );
		$pad   = strlen( $value ) % 4;
		if ( $pad ) {
			$value .= str_repeat( '=', 4 - $pad );
		}
		$decoded = base64_decode( $value, true );
		return false === $decoded ? '' : $decoded;
	}

	/** @return string */
	private static function sanitize_state( $state ) {
		$state = sanitize_key( (string) $state );
		return in_array( $state, array( 'accepted', 'rejected', 'unknown' ), true ) ? $state : 'unknown';
	}

	/** @return string */
	private static function consent_id( $amp_user_id ) {
		$current = function_exists( 'faz_parse_consent_cookie' ) ? faz_parse_consent_cookie() : array();
		if ( ! empty( $current['consentid'] ) ) {
			return substr( sanitize_text_field( $current['consentid'] ), 0, 64 );
		}
		// ampUserId is an AMP-generated cross-page identifier. It is useful to
		// AMP itself but unnecessary for FAZ accountability, so never derive our
		// consent identifier from it. Generate a fresh random id on first action.
		unset( $amp_user_id );
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Record AMP decisions through the existing local accountability store.
	 *
	 * @return void
	 */
	private function maybe_log_consent( $state, $purposes, $context, $consent_id ) {
		$settings = get_option( 'faz_settings', array() );
		if ( empty( $settings['consent_logs']['status'] ) ) {
			return;
		}
		$categories = array( 'necessary' => 'yes' );
		foreach ( $purposes as $purpose => $allowed ) {
			$categories[ $purpose ] = $allowed ? 'yes' : 'no';
		}
		$allowed_count = count( array_filter( $purposes ) );
		if ( empty( $purposes ) ) {
			$status = 'accepted' === $state ? 'accepted' : 'rejected';
		} else {
			$status = 0 === $allowed_count
				? 'rejected'
				: ( $allowed_count === count( $purposes ) ? 'accepted' : 'partial' );
		}
		Consent_Log_Controller::get_instance()->log_consent(
			array(
				'consent_id'     => $consent_id,
				'status'         => $status,
				'categories'     => $categories,
				'url'            => home_url( '/' ),
				'banner_slug'    => $context['slug'],
				'policy_revision' => $context['revision'],
				'signal_gpc'     => isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) ? 1 : 0,
				'signal_dnt'     => isset( $_SERVER['HTTP_DNT'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) ? 1 : 0,
			)
		);
	}

	/** @return array */
	private static function shared_data( $context, $expires ) {
		return array(
			'fazConsentRevision' => absint( $context['revision'] ),
			'fazBanner'          => $context['slug'],
			'fazLaw'             => $context['law'],
			'fazExpiresAt'       => absint( $expires ),
		);
	}

	/**
	 * Wrap a response and attach the exact CORS headers for tests and REST.
	 *
	 * @param array $data Response body.
	 * @return \WP_REST_Response
	 */
	private function response( $data ) {
		$response = rest_ensure_response( $data );
		if ( method_exists( $response, 'header' ) ) {
			foreach ( $this->cors_headers() as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}

	/** @return array<string,string> */
	private function cors_headers() {
		return array(
			'Access-Control-Allow-Origin'            => $this->cors_origin,
			'Access-Control-Allow-Credentials'       => 'true',
			'Access-Control-Allow-Methods'           => 'POST, OPTIONS',
			'Access-Control-Allow-Headers'           => 'Content-Type, AMP-Same-Origin',
			'AMP-Access-Control-Allow-Source-Origin' => $this->cors_source_origin,
			'Access-Control-Expose-Headers'          => 'AMP-Access-Control-Allow-Source-Origin',
			'Cache-Control'                          => 'private, no-store, no-cache, max-age=0',
			'Vary'                                   => 'Origin',
		);
	}

	/**
	 * Replace generic WP REST CORS headers for these routes.
	 *
	 * @param bool              $served  Whether served.
	 * @param \WP_HTTP_Response $result Response.
	 * @param \WP_REST_Request $request Request.
	 * @param \WP_REST_Server  $server  Server.
	 * @return bool
	 */
	public function serve_cors_headers( $served, $result, $request, $server ) {
		unset( $result, $server );
		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE . '/amp-consent/' ) ) {
			return $served;
		}
		if ( ! headers_sent() ) {
			header_remove( 'Access-Control-Allow-Origin' );
			header_remove( 'Access-Control-Allow-Credentials' );
			header_remove( 'AMP-Access-Control-Allow-Source-Origin' );
			// On denied/error requests cors_origin remains empty: remove core's
			// reflected wildcard/Origin header and deliberately emit no CORS grant.
			if ( '' !== $this->cors_origin ) {
				foreach ( $this->cors_headers() as $name => $value ) {
					header( $name . ': ' . $value, true );
				}
			}
		}
		return $served;
	}
}
