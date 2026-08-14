<?php
/**
 * Production-path tests for the AMP consent REST/rendering bridge.
 *
 * Run: php tests/unit/test-amp-consent-bridge-php.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );

	$GLOBALS['faz_test_actions'] = array();
	$GLOBALS['faz_test_filters'] = array();
	$GLOBALS['faz_test_routes']  = array();
	$GLOBALS['faz_test_cookie']  = '';
	$GLOBALS['faz_test_set_cookie'] = array();
	$GLOBALS['faz_test_options'] = array(
		'faz_settings' => array(
			'banner_control' => array( 'status' => true ),
			'general'        => array( 'consent_revision' => 2 ),
			'consent_logs'   => array( 'status' => true ),
		),
		'faz_gcm_settings' => array( 'status' => false ),
	);

	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_data() { return $this->data; }
		public function get_error_message() { return $this->message; }
	}
	class WP_REST_Response {
		private $data;
		private $headers = array();
		public function __construct( $data ) { $this->data = $data; }
		public function get_data() { return $this->data; }
		public function header( $name, $value ) { $this->headers[ $name ] = $value; }
		public function get_headers() { return $this->headers; }
	}
	class Faz_AMP_Test_Request {
		private $params;
		private $route;
		public function __construct( $params, $route = '/faz/v1/amp-consent/check' ) { $this->params = $params; $this->route = $route; }
		public function get_param( $key ) { return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null; }
		public function get_route() { return $this->route; }
	}
	class Faz_AMP_Test_Banner {
		public $slug = 'main-banner';
		public $law = 'gdpr';
		public $expiry = 90;
		public function get_slug() { return $this->slug; }
		public function get_law() { return $this->law; }
		public function get_settings() {
			return array(
				'settings' => array( 'applicableLaw' => $this->law, 'consentExpiry' => array( 'value' => $this->expiry ), 'ruleSet' => array() ),
				'config'   => array(),
			);
		}
		public function get_contents() {
			return array(
				'en' => array(
					'notice' => array( 'elements' => array(
						'title' => 'Privacy choices',
						'description' => '<p>Choose optional cookies.</p>',
						'privacyLink' => '/privacy/',
						'buttons' => array( 'elements' => array( 'accept' => 'Accept all', 'reject' => 'Reject all', 'readMore' => 'Cookie policy' ) ),
					) ),
					'preferenceCenter' => array( 'elements' => array( 'buttons' => array( 'elements' => array( 'save' => 'Save choices' ) ) ) ),
				),
			);
		}
	}

	function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['faz_test_actions'][] = compact( 'hook', 'callback', 'priority', 'args' ); }
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['faz_test_filters'][] = compact( 'hook', 'callback', 'priority', 'args' ); }
	function apply_filters( $hook, $value ) {
		if ( 'faz_amp_component_purpose_map' === $hook && ! empty( $GLOBALS['faz_test_amp_map'] ) ) {
			return array_merge( (array) $value, $GLOBALS['faz_test_amp_map'] );
		}
		return $value;
	}
	function do_action() {}
	function register_rest_route( $namespace, $route, $args ) { $GLOBALS['faz_test_routes'][] = compact( 'namespace', 'route', 'args' ); return true; }
	function rest_ensure_response( $data ) { return new WP_REST_Response( $data ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function sanitize_text_field( $value ) { return trim( preg_replace( '/[\x00-\x1F<>]/', '', (string) $value ) ); }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ); }
	function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9_-]+/i', '-', strtolower( (string) $value ) ), '-' ); }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
	function wp_parse_url( $value ) { return parse_url( (string) $value ); }
	function wp_strip_all_tags( $value ) { return trim( strip_tags( (string) $value ) ); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_salt( $scheme = 'auth' ) { return 'amp-test-salt-' . $scheme; }
	function wp_hash( $value, $scheme = 'auth' ) { return hash_hmac( 'sha256', (string) $value, wp_salt( $scheme ) ); }
	function wp_generate_password( $length = 12, $special = true, $extra = false ) { return substr( str_repeat( 'RANDOM-CONSENT-ID-', 4 ), 0, $length ); }
	function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['faz_test_options'] ) ? $GLOBALS['faz_test_options'][ $key ] : $default; }
	function home_url( $path = '' ) { return 'https://publisher.example' . $path; }
	function get_site_url() { return 'https://publisher.example'; }
	function rest_url( $path = '' ) { return 'https://publisher.example/wp-json/' . ltrim( $path, '/' ); }
	function set_url_scheme( $url, $scheme = null ) { return preg_replace( '#^https?://#', ( $scheme ? $scheme : 'https' ) . '://', $url ); }
	function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
	function __( $text ) { return $text; }
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_url( $text ) { return (string) $text; }
	function esc_attr_e( $text ) { echo esc_attr( $text ); }
	function esc_html_e( $text ) { echo esc_html( $text ); }
	function faz_current_language() { return 'en'; }
	function faz_throttle_request() { return false; }
	function faz_get_consent_revision() { return max( 1, absint( $GLOBALS['faz_test_options']['faz_settings']['general']['consent_revision'] ) ); }
	function faz_parse_consent_cookie( $cookie = '' ) {
		$cookie = '' !== $cookie ? $cookie : $GLOBALS['faz_test_cookie'];
		$out = array();
		foreach ( explode( ',', rawurldecode( (string) $cookie ) ) as $pair ) {
			$parts = explode( ':', $pair, 2 );
			if ( 2 === count( $parts ) ) { $out[ $parts[0] ] = $parts[1]; }
		}
		return $out;
	}
	function faz_get_valid_consent_cookie() {
		$parsed = faz_parse_consent_cookie( $GLOBALS['faz_test_cookie'] );
		return absint( isset( $parsed['rev'] ) ? $parsed['rev'] : 0 ) < faz_get_consent_revision() ? '' : $GLOBALS['faz_test_cookie'];
	}
	function faz_set_browser_cookie( $name, $value, $expires, $domain = null, $same_site = 'Lax', $secure = null ) {
		$GLOBALS['faz_test_cookie'] = $value;
		$GLOBALS['faz_test_set_cookie'] = compact( 'name', 'value', 'expires', 'domain', 'same_site', 'secure' );
	}
	function amp_is_request() { return true; }
	function is_ssl() { return true; }
	function wp_dequeue_script() {}
	function wp_deregister_script() {}
}

namespace FazCookie\Frontend {
	class Frontend {
		public static function normalize_consent_expiry( $law, $days ) {
			return 'ccpa' === $law ? max( 365, abs( (int) $days ) ) : max( 180, min( 182, abs( (int) $days ) ) );
		}
	}
}

namespace FazCookie\Includes {
	class Geolocation {
		public static $eu_countries = array();
		public static function get_visitor_country() { return ''; }
	}
}

namespace FazCookie\Admin\Modules\Banners\Includes {
	class Controller {
		public static $banner;
		public static function get_instance() { return new self(); }
		public function get_active_banner_by_slug( $slug ) { return self::$banner && self::$banner->get_slug() === $slug ? self::$banner : false; }
		public function get_active_banner_for_country() { return self::$banner; }
		public function has_country_dependent_banners() { return false; }
	}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Category_Controller {
		public static $items = array();
		public static function get_instance() { return new self(); }
		public function get_items() { return self::$items; }
	}
	class Cookie_Categories {
		private $item;
		public function __construct( $item ) { $this->item = (object) $item; }
		public function get_slug() { return $this->item->slug; }
		public function get_visibility() { return ! empty( $this->item->visibility ); }
		public function get_name() { return $this->item->name; }
	}
}

namespace FazCookie\Admin\Modules\Consentlogs\Includes {
	class Controller {
		public static $rows = array();
		public static function get_instance() { return new self(); }
		public function log_consent( $data ) { self::$rows[] = $data; return $data; }
	}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/frontend/class-amp-consent-rest.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-amp-consent.php';

	use FazCookie\Frontend\AMP_Consent;
	use FazCookie\Frontend\AMP_Consent_Rest;
	use FazCookie\Admin\Modules\Banners\Includes\Controller as Banner_Controller;
	use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
	use FazCookie\Admin\Modules\Consentlogs\Includes\Controller as Log_Controller;

	$passed = 0;
	$failed = 0;
	function amp_ok( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) { $passed++; echo "  [PASS] $label\n"; }
		else { $failed++; echo "  [FAIL] $label\n"; }
	}
	function amp_same( $actual, $expected, $label ) {
		amp_ok( $actual === $expected, $label . ( $actual === $expected ? '' : ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) ) );
	}

	echo "== AMP consent bridge ==\n";
	$banner = new Faz_AMP_Test_Banner();
	Banner_Controller::$banner = $banner;
	Category_Controller::$items = array(
		array( 'slug' => 'necessary', 'name' => 'Necessary', 'visibility' => 1 ),
		array( 'slug' => 'analytics', 'name' => 'Analytics', 'visibility' => 1 ),
		array( 'slug' => 'marketing', 'name' => 'Marketing', 'visibility' => 1 ),
		array( 'slug' => 'hidden', 'name' => 'Hidden', 'visibility' => 0 ),
	);

	// Route contract.
	$bridge = new AMP_Consent_Rest();
	$bridge->register_routes();
	amp_same( count( $GLOBALS['faz_test_routes'] ), 4, 'check/update POST plus two explicit OPTIONS routes are registered' );
	amp_same( $GLOBALS['faz_test_routes'][0]['route'], '/amp-consent/check', 'check route path is stable' );
	amp_same( $GLOBALS['faz_test_routes'][1]['route'], '/amp-consent/update', 'update route path is stable' );
	$rendered_endpoints = AMP_Consent_Rest::endpoint_urls( $banner );
	amp_ok( false !== strpos( $rendered_endpoints['update'], '__amp_source_origin=https%3A%2F%2Fpublisher.example' ), 'onUpdateHref carries publisher source because AMP runtime disables automatic AMP CORS query injection' );

	// AMP CORS security.
	$same = AMP_Consent_Rest::validate_cors_environment( array( 'HTTP_AMP_SAME_ORIGIN' => 'true' ), '', 'https://publisher.example/' );
	amp_same( $same['origin'], 'https://publisher.example', 'AMP-Same-Origin request is accepted without Origin' );
	$cache = AMP_Consent_Rest::validate_cors_environment(
		array( 'HTTP_ORIGIN' => 'https://publisher-example.cdn.ampproject.org' ),
		'https://publisher.example',
		'https://publisher.example/'
	);
	amp_same( $cache['origin'], 'https://publisher-example.cdn.ampproject.org', 'HTTPS AMP Cache origin with exact source origin is accepted' );
	amp_ok( is_wp_error( AMP_Consent_Rest::validate_cors_environment( array(), '', 'https://publisher.example/' ) ), 'missing AMP provenance fails closed' );
	amp_ok( is_wp_error( AMP_Consent_Rest::validate_cors_environment( array( 'HTTP_ORIGIN' => 'https://evil.example' ), 'https://publisher.example', 'https://publisher.example/' ) ), 'foreign Origin is denied' );
	amp_ok( is_wp_error( AMP_Consent_Rest::validate_cors_environment( array( 'HTTP_ORIGIN' => 'https://publisher-example.cdn.ampproject.org.evil.test' ), 'https://publisher.example', 'https://publisher.example/' ) ), 'AMP Cache lookalike suffix is denied' );
	amp_ok( is_wp_error( AMP_Consent_Rest::validate_cors_environment( array( 'HTTP_ORIGIN' => 'https://other-publisher-example.cdn.ampproject.org' ), 'https://publisher.example', 'https://publisher.example/' ) ), 'another publisher AMP Cache origin is denied even with a copied source parameter' );
	$hyphen_cache = AMP_Consent_Rest::validate_cors_environment( array( 'HTTP_ORIGIN' => 'https://news--site-example.cdn.ampproject.org' ), 'https://news-site.example', 'https://news-site.example/' );
	amp_same( $hyphen_cache['origin'], 'https://news--site-example.cdn.ampproject.org', 'publisher hyphens use the Google AMP Cache double-hyphen encoding' );
	amp_ok( is_wp_error( AMP_Consent_Rest::validate_cors_environment( array( 'HTTP_ORIGIN' => 'http://publisher-example.cdn.ampproject.org' ), 'https://publisher.example', 'https://publisher.example/' ) ), 'non-HTTPS cache origin is denied' );
	amp_ok( is_wp_error( AMP_Consent_Rest::validate_cors_environment( array( 'HTTP_ORIGIN' => 'https://publisher-example.cdn.ampproject.org' ), 'https://other.example', 'https://publisher.example/' ) ), 'mismatched __amp_source_origin is denied' );

	$scope    = AMP_Consent_Rest::scope_token( 'main-banner' );
	$instance = AMP_Consent_Rest::instance_id( 'main-banner' );
	$base     = array( 'banner' => 'main-banner', 'scope' => $scope, 'consentInstanceId' => $instance );
	$_SERVER  = array( 'HTTP_AMP_SAME_ORIGIN' => 'true' );

	// Missing server state invalidates an accepted AMP cache decision.
	$GLOBALS['faz_test_cookie'] = '';
	$check = $bridge->handle_check( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'accepted' ) ) );
	amp_same( $check->get_data()['consentStateValue'], 'unknown', 'missing server state returns unknown' );
	amp_same( $check->get_data()['expireCache'], true, 'missing server state expires an accepted AMP cache entry' );
	amp_same( $check->get_data()['purposeConsents'], array( 'analytics' => false, 'marketing' => false ), 'unknown state denies all optional purposes using AMP runtime plural key' );
	amp_same( $check->get_headers()['Access-Control-Allow-Origin'], 'https://publisher.example', 'response carries exact non-wildcard CORS origin' );
	amp_same( $check->get_headers()['Cache-Control'], 'private, no-store, no-cache, max-age=0', 'personalized check response is never cached' );

	// Update path: incomplete purpose payload denies missing keys.
	$update = $bridge->handle_update( new Faz_AMP_Test_Request(
		$base + array(
			'consentStateValue' => 'accepted',
			'ampUserId' => 'stable-amp-user-id-must-not-be-reused',
			// AMP runtime persists/sends purpose state as 1=accepted, 2=rejected.
			'purposeConsents' => array( 'analytics' => 1 ),
		),
		'/faz/v1/amp-consent/update'
	) );
	amp_same( $update->get_data()['purposeConsents'], array( 'analytics' => true, 'marketing' => false ), 'accepted update reads AMP runtime purposeConsents and fills missing purpose as denied' );
	amp_same(
		AMP_Consent_Rest::normalize_purpose_consent( array( 'analytics' => 2, 'marketing' => 1 ), AMP_Consent_Rest::get_purposes(), 'accepted' ),
		array( 'analytics' => false, 'marketing' => true ),
		'AMP numeric purpose states map 1 to granted and 2 to denied'
	);
	$cookie = faz_parse_consent_cookie( $GLOBALS['faz_test_cookie'] );
	amp_same( $cookie['analytics'], 'yes', 'accepted purpose is synchronized to standard cookie' );
	amp_same( $cookie['marketing'], 'no', 'missing purpose fails closed in standard cookie' );
	amp_same( $cookie['rev'], '2', 'cookie carries current policy revision' );
	amp_same( $cookie['__scope.banner'], 'main-banner', 'cookie carries banner scope' );
	amp_same( $cookie['__scope.fp'], substr( wp_hash( 'main-banner|gdpr', 'auth' ), 0, 32 ), 'cookie carries unforgeable scope fingerprint' );
	amp_ok( false === strpos( $cookie['consentid'], 'stable-amp-user-id' ), 'consent id is not derived from AMP user id' );
	amp_same( $GLOBALS['faz_test_set_cookie']['same_site'], 'Lax', 'same-origin AMP update keeps the standard SameSite=Lax policy' );
	amp_same( $GLOBALS['faz_test_set_cookie']['secure'], null, 'same-origin AMP update does not force a cookie security override' );
	amp_ok( $GLOBALS['faz_test_set_cookie']['expires'] >= time() + ( 180 * DAY_IN_SECONDS ) - 2, 'GDPR expiry is normalized to at least 180 days' );
	amp_same( Log_Controller::$rows[0]['status'], 'partial', 'mixed AMP purposes use existing consent logger as partial' );

	// A cached AMP document needs None+Secure for browsers that permit
	// credentialed third-party cookies. The publisher source must still match.
	$_SERVER = array( 'HTTP_ORIGIN' => 'https://publisher-example.cdn.ampproject.org' );
	$cache_update = $bridge->handle_update( new Faz_AMP_Test_Request(
		$base + array(
			'__amp_source_origin' => 'https://publisher.example',
			'consentStateValue'   => 'accepted',
			'purposeConsents'     => array( 'analytics' => 1, 'marketing' => 2 ),
		),
		'/faz/v1/amp-consent/update'
	) );
	amp_ok( ! is_wp_error( $cache_update ), 'HTTPS AMP Cache update is accepted with exact publisher source' );
	amp_same( $GLOBALS['faz_test_set_cookie']['same_site'], 'None', 'AMP Cache update requests SameSite=None' );
	amp_same( $GLOBALS['faz_test_set_cookie']['secure'], true, 'AMP Cache update forces Secure' );
	$_SERVER = array( 'HTTP_AMP_SAME_ORIGIN' => 'true' );

	// Server cookie now reconciles back into AMP.
	$check2 = $bridge->handle_check( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'unknown' ) ) );
	amp_same( $check2->get_data()['consentStateValue'], 'accepted', 'check reconciles standard cookie into AMP state' );
	amp_same( $check2->get_data()['purposeConsents']['marketing'], false, 'check preserves granular denial in AMP runtime response key' );
	amp_ok( is_string( $check2->get_data()['consentString'] ) && false !== strpos( $check2->get_data()['consentString'], '.' ), 'check returns signed consentString with server lifetime' );
	$bridge_cookie = $GLOBALS['faz_test_cookie'];
	$GLOBALS['faz_test_cookie'] = preg_replace( '/,(?:ts|exp):[0-9]+/', '', $bridge_cookie );
	$legacy_check = $bridge->handle_check( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'accepted' ) ) );
	amp_same( $legacy_check->get_data()['consentStateValue'], 'accepted', 'classic cookie without embedded expiry still reconciles while the browser sends it' );
	amp_ok( ! isset( $legacy_check->get_data()['consentString'] ), 'classic cookie does not receive an invented sliding AMP expiry' );
	$GLOBALS['faz_test_cookie'] = $bridge_cookie;

	// Signed state enforces tamper, expiry and revision.
	$context = array(
		'slug' => 'main-banner', 'law' => 'gdpr', 'revision' => 2, 'expiry_days' => 180,
		'scope_fingerprint' => substr( wp_hash( 'main-banner|gdpr', 'auth' ), 0, 32 ),
		'purposes' => AMP_Consent_Rest::get_purposes(),
	);
	$signed_state = $check2->get_data()['consentString'];
	amp_same( AMP_Consent_Rest::decode_state_string( $signed_state, $context )['state'], 'accepted', 'valid signed AMP state round-trips' );
	amp_same( AMP_Consent_Rest::decode_state_string( $signed_state . 'tamper', $context ), false, 'tampered signed state is rejected' );
	$context_new_revision = $context; $context_new_revision['revision'] = 3;
	amp_same( AMP_Consent_Rest::decode_state_string( $signed_state, $context_new_revision ), false, 'revision bump invalidates signed AMP state' );
	$expired = AMP_Consent_Rest::encode_state_string( array( 'state' => 'accepted', 'purposes' => array( 'analytics' => true, 'marketing' => true ), 'expires' => time() - 1 ), $context );
	amp_same( AMP_Consent_Rest::decode_state_string( $expired, $context ), false, 'expired signed AMP state is rejected' );
	amp_same( AMP_Consent_Rest::state_from_cookie( $GLOBALS['faz_test_cookie'], $context_new_revision ), false, 'revision bump invalidates synchronized cookie state' );

	// Failure paths cannot mutate state.
	$before_cookie = $GLOBALS['faz_test_cookie'];
	$bad_scope = $bridge->handle_update( new Faz_AMP_Test_Request( array_merge( $base, array( 'scope' => 'tampered', 'consentStateValue' => 'accepted' ) ), '/faz/v1/amp-consent/update' ) );
	amp_same( $bad_scope->get_error_code(), 'faz_amp_invalid_scope', 'tampered banner scope is rejected' );
	amp_same( $GLOBALS['faz_test_cookie'], $before_cookie, 'invalid scope does not mutate cookie' );
	$bad_instance = $bridge->handle_update( new Faz_AMP_Test_Request( array_merge( $base, array( 'consentInstanceId' => 'other', 'consentStateValue' => 'accepted' ) ), '/faz/v1/amp-consent/update' ) );
	amp_same( $bad_instance->get_error_code(), 'faz_amp_invalid_instance', 'wrong consent instance is rejected' );
	$bad_state = $bridge->handle_update( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'unknown' ), '/faz/v1/amp-consent/update' ) );
	amp_same( $bad_state->get_error_code(), 'faz_amp_invalid_state', 'unknown update state cannot clear or grant consent' );

	// CCPA runtime boundary is at least 365 days.
	$banner->law = 'ccpa'; $banner->expiry = 2;
	$GLOBALS['faz_test_cookie'] = '';
	$ccpa = $bridge->handle_update( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'rejected', 'purposeConsent' => array() ), '/faz/v1/amp-consent/update' ) );
	amp_ok( ! is_wp_error( $ccpa ) && $GLOBALS['faz_test_set_cookie']['expires'] >= time() + ( 365 * DAY_IN_SECONDS ) - 2, 'CCPA preference cookie is normalized to at least 365 days' );
	$banner->law = 'gdpr'; $banner->expiry = 90;

	// Rendering config and controls.
	$GLOBALS['faz_test_cookie'] = '';
	$amp = ( new ReflectionClass( AMP_Consent::class ) )->newInstanceWithoutConstructor();
	ob_start();
	$amp->output_amp_consent();
	$html = ob_get_clean();
	amp_ok( preg_match( '#<script type="application/json">(.*?)</script>#s', $html, $json_match ) === 1, 'AMP renderer emits JSON configuration' );
	$config = json_decode( $json_match[1], true );
	amp_same( $config['consentRequired'], 'remote', 'render uses remote consent requirement' );
	amp_ok( isset( $config['checkConsentHref'], $config['onUpdateHref'] ), 'render wires checkConsentHref and onUpdateHref' );
	amp_same( $config['purposeConsentRequired'], array( 'analytics', 'marketing' ), 'render requires every optional purpose' );
	amp_same( $config['policy']['default']['timeout']['fallbackAction'], 'reject', 'endpoint timeout fails closed' );
	amp_ok( ! isset( $config['consents'] ), 'render uses current direct amp-consent config, not legacy wrapper' );
	amp_ok( false !== strpos( $html, 'change:faz-amp-consent.setPurpose(analytics=event.checked)' ), 'category checkbox stores explicit true/false using event.checked' );
	amp_ok( false !== strpos( $html, 'accept(purposeConsentDefault=false)' ), 'Save fills every untouched purpose as denied' );
	amp_ok( false !== strpos( $html, 'accept(purposeConsentDefault=true)' ) && false !== strpos( $html, 'reject(purposeConsentDefault=false)' ), 'Accept-all and reject-all persist complete purpose maps' );
	amp_ok( false !== strpos( $html, 'tap:faz-amp-consent.prompt' ), 'post-prompt revisit control remains available' );

	// Markup blocking is narrow, granular and preserves publisher policies.
	$GLOBALS['faz_test_amp_map'] = array( 'amp-owner-widget' => 'marketing', 'amp-owner-filter-multi' => array( 'analytics', 'marketing' ) );
	$markup = '<amp-analytics type="x"></amp-analytics><amp-ad width="1"></amp-ad><amp-iframe src="x"></amp-iframe><amp-video src="local.mp4"></amp-video><amp-pixel data-block-on-consent="_auto_reject"></amp-pixel><amp-owner-explicit data-faz-category="analytics"></amp-owner-explicit><amp-owner-purpose data-faz-purpose="marketing"></amp-owner-purpose><amp-owner-multi data-faz-purpose="analytics,marketing"></amp-owner-multi><amp-owner-widget></amp-owner-widget><amp-owner-filter-multi></amp-owner-filter-multi><amp-unknown-widget></amp-unknown-widget>';
	$blocked = $amp->apply_component_blocking( $markup );
	amp_ok( false !== strpos( $blocked, '<amp-analytics data-block-on-consent-purposes="analytics"' ), 'amp-analytics is blocked on analytics purpose' );
	amp_ok( false !== strpos( $blocked, '<amp-ad data-block-on-consent-purposes="marketing"' ), 'amp-ad is blocked on marketing purpose' );
	amp_ok( false !== strpos( $blocked, '<amp-iframe data-block-on-consent-purposes="analytics,marketing"' ), 'unmapped custom category fails conservatively across all purposes' );
	amp_ok( false !== strpos( $blocked, '<amp-video src="local.mp4">' ), 'unclassified first-party AMP video is not claimed or rewritten' );
	amp_ok( false !== strpos( $blocked, '<amp-pixel data-block-on-consent="_auto_reject">' ), 'publisher-provided consent policy is preserved exactly' );
	amp_ok( false !== strpos( $blocked, '<amp-owner-explicit data-block-on-consent-purposes="analytics" data-faz-category="analytics">' ), 'custom component data-faz-category maps to native AMP purpose blocking' );
	amp_ok( false !== strpos( $blocked, '<amp-owner-purpose data-block-on-consent-purposes="marketing" data-faz-purpose="marketing">' ), 'custom component data-faz-purpose maps to native AMP purpose blocking' );
	amp_ok( false !== strpos( $blocked, '<amp-owner-multi data-block-on-consent-purposes="analytics,marketing" data-faz-purpose="analytics,marketing">' ), 'custom component marker supports multiple required purposes' );
	amp_ok( false !== strpos( $blocked, '<amp-owner-widget data-block-on-consent-purposes="marketing">' ), 'custom component mapping filter is applied' );
	amp_ok( false !== strpos( $blocked, '<amp-owner-filter-multi data-block-on-consent-purposes="analytics,marketing">' ), 'custom component mapping filter supports multiple required purposes' );
	amp_ok( false !== strpos( $blocked, '<amp-unknown-widget></amp-unknown-widget>' ), 'unknown AMP component without declaration remains unchanged' );
	unset( $GLOBALS['faz_test_amp_map'] );

	// Accepted decision with no optional purposes must log accepted, not rejected.
	Category_Controller::$items = array( array( 'slug' => 'necessary', 'name' => 'Necessary', 'visibility' => 1 ) );
	$GLOBALS['faz_test_cookie'] = '';
	$before_logs = count( Log_Controller::$rows );
	$no_optional = $bridge->handle_update( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'accepted', 'purposeConsent' => array() ), '/faz/v1/amp-consent/update' ) );
	amp_ok( ! is_wp_error( $no_optional ), 'accepted state without optional categories is valid' );
	amp_same( Log_Controller::$rows[ $before_logs ]['status'], 'accepted', 'zero-purpose accepted state logs as accepted' );

	// Cached pages stop prompting and cannot mutate consent after the global
	// banner runtime is disabled. Deactivating the scoped banner behaves alike.
	$GLOBALS['faz_test_options']['faz_settings']['banner_control']['status'] = false;
	$disabled_check = $bridge->handle_check( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'accepted' ) ) );
	amp_same( $disabled_check->get_data()['consentRequired'], false, 'disabled banner control tells cached AMP pages consent is no longer required' );
	amp_same( $disabled_check->get_data()['expireCache'], true, 'disabled banner control expires cached AMP consent' );
	$before_disabled_cookie = $GLOBALS['faz_test_cookie'];
	$disabled_update = $bridge->handle_update( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'accepted' ), '/faz/v1/amp-consent/update' ) );
	amp_same( $disabled_update->get_error_code(), 'faz_amp_banner_disabled', 'disabled banner control rejects stale AMP updates' );
	amp_same( $GLOBALS['faz_test_cookie'], $before_disabled_cookie, 'disabled banner update cannot mutate consent' );
	$GLOBALS['faz_test_options']['faz_settings']['banner_control']['status'] = true;
	Banner_Controller::$banner = false;
	$inactive_check = $bridge->handle_check( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'accepted' ) ) );
	amp_same( $inactive_check->get_data()['consentRequired'], false, 'inactive scoped banner stops prompting cached AMP pages' );
	amp_same( $inactive_check->get_data()['expireCache'], true, 'inactive scoped banner expires cached AMP consent' );
	Banner_Controller::$banner = $banner;

	// A denied subrequest must clear CORS state left by a previous success.
	$_SERVER = array( 'HTTP_ORIGIN' => 'https://evil.example' );
	$denied_after_success = $bridge->handle_check( new Faz_AMP_Test_Request( $base + array( '__amp_source_origin' => 'https://publisher.example' ) ) );
	amp_same( $denied_after_success->get_error_code(), 'faz_amp_cors_denied', 'denied request after a success still fails CORS' );
	$cors_property = new ReflectionProperty( AMP_Consent_Rest::class, 'cors_origin' );
	$cors_property->setAccessible( true );
	amp_same( $cors_property->getValue( $bridge ), '', 'denied request cannot reuse a previous accepted CORS origin' );
	$_SERVER = array( 'HTTP_AMP_SAME_ORIGIN' => 'true' );

	// Static guard for the error-path header boundary: the route branch removes
	// WordPress's generic reflected CORS headers before checking cors_origin.
	$rest_source = file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-amp-consent-rest.php' );
	$serve_start = strpos( $rest_source, 'public function serve_cors_headers' );
	$serve_code  = substr( $rest_source, $serve_start, 2200 );
	amp_ok(
		strpos( $serve_code, "header_remove( 'Access-Control-Allow-Origin' )" ) < strpos( $serve_code, "if ( '' !== \$this->cors_origin )" ),
		'generic WordPress CORS grant is removed even when an AMP route returns an error'
	);

	// The shared cookie helper remains backwards-compatible while enforcing the
	// browser requirement that SameSite=None is never emitted without Secure.
	$bootstrap_source = file_get_contents( dirname( __DIR__, 2 ) . '/faz-cookie-manager.php' );
	amp_ok( false !== strpos( $bootstrap_source, "\$same_site = 'Lax', \$force_secure = null" ), 'shared cookie helper keeps Lax/default call compatibility' );
	amp_ok( false !== strpos( $bootstrap_source, "if ( 'None' === \$same_site && ! \$secure )" ), 'shared cookie helper rejects insecure SameSite=None combinations' );
	amp_ok( false !== strpos( $bootstrap_source, "'samesite' => \$same_site" ) && false !== strpos( $bootstrap_source, "'secure'   => \$secure" ), 'normalized AMP cookie options reach the real setcookie call' );

	echo "Passed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
