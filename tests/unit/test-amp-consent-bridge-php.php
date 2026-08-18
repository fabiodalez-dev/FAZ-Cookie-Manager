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
	/**
	 * Request double.
	 *
	 * The original version handed $params straight to get_param(), which meant
	 * it bypassed the exact WordPress layer the bridge was breaking on: the AMP
	 * runtime sends its JSON body as text/plain, WordPress never parses that
	 * into params, and every real request arrived empty. The suite was green
	 * because it validated the author's model of AMP rather than AMP.
	 *
	 * It can now carry a raw body and a content type, so a test can post the way
	 * the runtime actually does and watch hydrate_amp_body() do its work.
	 */
	class Faz_AMP_Test_Request {
		private $params;
		private $route;
		private $body;
		private $content_type;
		private $method;
		public function __construct( $params, $route = '/faz/v1/amp-consent/check', $body = '', $content_type = '', $method = 'POST' ) {
			$this->params       = $params;
			$this->route        = $route;
			$this->body         = $body;
			$this->content_type = $content_type;
			$this->method       = $method;
		}
		public function get_param( $key ) { return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null; }
		public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
		public function get_route() { return $this->route; }
		public function get_method() { return $this->method; }
		public function get_body() { return $this->body; }
		public function get_content_type() {
			return '' === $this->content_type ? null : array( 'value' => $this->content_type );
		}
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
	// WordPress's wp_parse_url() takes a $component argument, and site_scope_id()
	// passes PHP_URL_HOST. A one-argument double silently returned the whole array
	// there, so the cast to string raised "Array to string conversion" on every
	// run and the site binding was compared as the literal "Array".
	function wp_parse_url( $value, $component = -1 ) {
		return -1 === $component ? parse_url( (string) $value ) : parse_url( (string) $value, $component );
	}
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
	function esc_url_raw( $text ) { return (string) $text; }
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
	/**
	 * Double for the production expiry rule.
	 *
	 * It has to reproduce frontend/class-frontend.php::normalize_consent_expiry()
	 * exactly, because every AMP lifetime assertion is really an assertion about
	 * that method. The previous version imposed a 180-day floor on the GDPR family
	 * and omitted the CCPA ceiling, neither of which production does — so the
	 * 90-day banner fixture "passed" a >= 180-day assertion that the real code
	 * would have failed. The suite was measuring its own stand-in.
	 *
	 * The real rule: the six-month GDPR-family limit is a MAXIMUM and has no
	 * floor, since re-asking sooner is more protective; an absent value still has
	 * to yield something usable, so it becomes 182. CCPA is the one model with a
	 * floor (§1798.135's 12 months) and carries the 3650-day ceiling the banner UI
	 * advertises.
	 */
	class Frontend {
		public static function normalize_consent_expiry( $law, $configured_days ) {
			$law  = strtolower( (string) $law );
			$days = abs( (int) $configured_days );

			if ( 'ccpa' === $law ) {
				return min( 3650, max( 365, $days ) );
			}
			if ( $days < 1 ) {
				return 182;
			}
			return min( 182, $days );
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
		// Separate from $banner on purpose: "the slug in this cached AMP
		// document no longer resolves" and "this site has stopped asking for
		// consent altogether" are different situations, and the bridge has to
		// answer them differently. A single flag could not express the case
		// that matters — a banner renamed or replaced while another stays live.
		public static $any_active = null;
		public static function get_instance() { return new self(); }
		public function get_active_banner_by_slug( $slug ) { return self::$banner && self::$banner->get_slug() === $slug ? self::$banner : false; }
		public function get_active_banner() { return null === self::$any_active ? self::$banner : self::$any_active; }
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
	// Two routes, not four. The OPTIONS pair used to be registered and could
	// never run: core answers OPTIONS at rest_pre_dispatch, before route
	// callbacks, so authorize_request() never executed and serve_cors_headers()
	// then stripped the allow-origin header core had emitted. Registering them
	// only made preflight look handled while it failed outright.
	amp_same( count( $GLOBALS['faz_test_routes'] ), 2, 'only the two POST routes are registered' );
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
	// The banner fixture is configured for 90 days, and 90 is what it must get:
	// the six-month rule is a ceiling, so a publisher who re-asks sooner is being
	// more protective and nothing may lengthen that. An assertion of ">= 180 days"
	// stood here and passed only because the harness double invented a floor.
	amp_ok(
		abs( $GLOBALS['faz_test_set_cookie']['expires'] - ( time() + ( 90 * DAY_IN_SECONDS ) ) ) <= 2,
		'a 90-day GDPR configuration is written to the cookie unchanged'
	);
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

	// ── The AMP cookie must not destroy what it does not own ────────────────
	// build_cookie_value() used to build a fresh array and implode it, so an AMP
	// decision wiped every key the bridge is unaware of. Two of those carry real
	// weight: svc.* per-service grants, and gpc — the recorded Global Privacy
	// Control opt-out. A GPC opt-out silently becoming an AMP "accepted" is the
	// worst outcome this plugin can produce, so it gets an explicit test.
	$existing = 'consentid:old-id,consent:no,action:yes,necessary:yes,gpc:yes,svc.youtube:no,svc.maps:yes,rev:2';
	$merged   = AMP_Consent_Rest::build_cookie_value(
		'accepted',
		array( 'analytics' => true, 'marketing' => true ),
		$context,
		'new-id',
		time() + 3600,
		$existing
	);
	amp_ok( false !== strpos( $merged, 'gpc:yes' ), 'an AMP accept preserves a recorded GPC opt-out signal' );
	amp_ok( false !== strpos( $merged, 'svc.youtube:no' ), 'an AMP accept preserves a denied per-service grant' );
	amp_ok( false !== strpos( $merged, 'svc.maps:yes' ), 'an AMP accept preserves an allowed per-service grant' );
	amp_ok( false !== strpos( $merged, 'consent:yes' ), 'the AMP decision itself still wins over the stored one' );
	amp_ok( false !== strpos( $merged, 'consentid:new-id' ), 'the AMP consent id replaces the stored one' );

	// ── A withdrawal must withdraw everything, not just what AMP names ─────
	//
	// The accept case above pins that grants SURVIVE an acceptance. The
	// rejection is the opposite obligation, and it was unasserted: carrying
	// `yes` forward unchanged meant an AMP "Reject All" wrote consent:no with
	// every category denied while still saying svc.<id>:yes. is_cookie_allowed()
	// returns true on a matching service decision BEFORE it consults the
	// category, so the shredder spared those cookies, the outgoing guard passed
	// their Set-Cookie headers, and script.js unblocked the provider on the next
	// classic page — an explicit withdrawal that left the service running.
	$rejected = AMP_Consent_Rest::build_cookie_value(
		'rejected',
		array( 'analytics' => false, 'marketing' => false ),
		$context,
		'new-id',
		time() + 3600,
		'consentid:old-id,consent:yes,action:yes,necessary:yes,gpc:yes,svc.maps:yes,svc.youtube:no,ck.maps._gid:yes,rev:2'
	);
	amp_ok( false === strpos( $rejected, 'svc.maps:yes' ), 'an AMP reject withdraws a granted per-service consent' );
	amp_ok( false === strpos( $rejected, 'ck.maps._gid:yes' ), 'an AMP reject withdraws a granted per-cookie consent' );
	amp_ok( false !== strpos( $rejected, 'svc.youtube:no' ), 'an AMP reject preserves an existing DENIAL — only grants are refused' );
	amp_ok( false !== strpos( $rejected, 'gpc:yes' ), 'an AMP reject still preserves the GPC opt-out signal' );

	// A category hidden AFTER the visitor consented never reaches get_purposes(),
	// so the purpose loop cannot write it and its old grant would ride through a
	// total withdrawal untouched. Same rule, same guarantee.
	$hidden = AMP_Consent_Rest::build_cookie_value(
		'rejected',
		array( 'marketing' => false ),
		$context,
		'cid',
		time() + 3600,
		'consent:yes,action:yes,necessary:yes,analytics:yes,rev:2'
	);
	amp_ok( false === strpos( $hidden, 'analytics:yes' ), 'a hidden category cannot keep a stale grant through an AMP reject' );

	// A category slug is admin-editable, so one can collide with a control key.
	// It must be dropped, never allowed to overwrite the field state_from_cookie()
	// hard-gates on — otherwise every later read behaves as if no decision existed.
	$collision = AMP_Consent_Rest::build_cookie_value(
		'accepted',
		array( 'action' => false, 'consent' => false, 'analytics' => true ),
		$context,
		'cid',
		time() + 3600,
		''
	);
	amp_ok( false !== strpos( $collision, 'action:yes' ), 'a category slug named "action" cannot overwrite the control field' );
	amp_ok( false !== strpos( $collision, 'consent:yes' ), 'a category slug named "consent" cannot overwrite the decision' );
	amp_ok( false !== strpos( $collision, 'analytics:yes' ), 'a non-colliding category is still written' );

	// ── AMP and the classic page must agree on the scope fingerprint ────────
	// resolve_context() folded any non-ccpa law to "gdpr" while class-frontend
	// hashes $banner->get_law() verbatim. The banner UI offers "gdpr_ccpa" and
	// the setup wizard writes "both"/"popia", so on those the two fingerprints
	// diverged and each surface permanently rejected the other's cookie.
	foreach ( array( 'gdpr', 'ccpa', 'gdpr_ccpa', 'both', 'popia' ) as $raw_law ) {
		$classic_fp = substr( wp_hash( 'main-banner|' . $raw_law, 'auth' ), 0, 32 );
		$amp_context = array(
			'slug'              => 'main-banner',
			'law'               => $raw_law,
			'revision'          => 2,
			'expiry_days'       => 180,
			'scope_fingerprint' => $classic_fp,
			'purposes'          => AMP_Consent_Rest::get_purposes(),
		);
		$cookie = AMP_Consent_Rest::build_cookie_value( 'accepted', array( 'analytics' => true ), $amp_context, 'cid', time() + 3600, '' );
		amp_ok(
			// The pair delimiter closes the match: without it '__scope.law:gdpr'
			// also matches the gdpr_ccpa cookie, and the assertion passes for the
			// wrong banner.
			false !== strpos( $cookie . ',', '__scope.law:' . $raw_law . ',' ),
			"the AMP cookie records the raw law '{$raw_law}' exactly as the classic page does"
		);
		amp_ok(
			false !== AMP_Consent_Rest::state_from_cookie( $cookie, $amp_context ),
			"an AMP cookie written under '{$raw_law}' is readable back under the same scope"
		);
	}

	// ── Clearing cookies must reach AMP as a withdrawal ─────────────────────
	// The signed consentString exists for a browser that REFUSES the publisher
	// cookie in a third-party context. On a same-origin request the cookie is
	// first-party, so its absence means it was deleted — and clearing cookies is
	// a recognised way of withdrawing consent. Falling back to the signed state
	// there answered "accepted" for the rest of the lifetime (up to 180/365
	// days) while the canonical page had already gone back to prompting.
	// decode_state_string() could not catch it: a plain deletion changes neither
	// banner, nor law, nor revision.
	$live_signed = AMP_Consent_Rest::encode_state_string(
		array( 'state' => 'accepted', 'purposes' => array( 'analytics' => true, 'marketing' => true ), 'expires' => time() + 86400 ),
		$context
	);
	$GLOBALS['faz_test_cookie'] = '';

	$_SERVER    = array( 'HTTP_AMP_SAME_ORIGIN' => 'true' );
	$withdrawn  = $bridge->handle_check( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'accepted', 'consentString' => $live_signed ) ) );
	amp_same( $withdrawn->get_data()['consentStateValue'], 'unknown', 'a deleted first-party cookie reaches AMP as a withdrawal, not a stale accept' );
	amp_same( $withdrawn->get_data()['expireCache'], true, 'the withdrawal also expires AMP local storage' );
	amp_same( $withdrawn->get_data()['purposeConsents'], array( 'analytics' => false, 'marketing' => false ), 'every optional purpose is denied after the withdrawal' );

	// From an AMP cache the same missing cookie proves nothing — the browser may
	// simply have refused a third-party Set-Cookie — so the signed state is still
	// the only evidence available and remains authoritative.
	$_SERVER    = array( 'HTTP_ORIGIN' => 'https://publisher-example.cdn.ampproject.org' );
	$from_cache = $bridge->handle_check( new Faz_AMP_Test_Request(
		$base + array(
			'consentStateValue'    => 'accepted',
			'consentString'        => $live_signed,
			'__amp_source_origin'  => 'https://publisher.example',
		)
	) );
	amp_ok( ! is_wp_error( $from_cache ), 'a genuine AMP Cache request is authorized' );
	amp_same( $from_cache->get_data()['consentStateValue'], 'accepted', 'a cached request with no cookie still honours the signed state' );
	$_SERVER    = array( 'HTTP_AMP_SAME_ORIGIN' => 'true' );

	// Failure paths cannot mutate state.
	$before_cookie = $GLOBALS['faz_test_cookie'];
	$bad_scope = $bridge->handle_update( new Faz_AMP_Test_Request( array_merge( $base, array( 'scope' => 'tampered', 'consentStateValue' => 'accepted' ) ), '/faz/v1/amp-consent/update' ) );
	amp_same( $bad_scope->get_error_code(), 'faz_amp_invalid_scope', 'tampered banner scope is rejected' );
	amp_same( $GLOBALS['faz_test_cookie'], $before_cookie, 'invalid scope does not mutate cookie' );
	$bad_instance = $bridge->handle_update( new Faz_AMP_Test_Request( array_merge( $base, array( 'consentInstanceId' => 'other', 'consentStateValue' => 'accepted' ) ), '/faz/v1/amp-consent/update' ) );
	amp_same( $bad_instance->get_error_code(), 'faz_amp_invalid_instance', 'wrong consent instance is rejected' );
	$bad_state = $bridge->handle_update( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'unknown' ), '/faz/v1/amp-consent/update' ) );
	amp_same( $bad_state->get_error_code(), 'faz_amp_invalid_state', 'unknown update state cannot clear or grant consent' );

	// ── Lifetime bounds, at the values where the two rules actually differ ──
	// One update per configuration, reading back the absolute expiry the browser
	// cookie was given. Asserting the exact day count is what makes these able to
	// fail: a floor, a missing ceiling or a swapped law all move the number.
	$faz_expiry_for = function ( $law, $configured ) use ( $bridge, $banner, $base ) {
		$banner->law                = $law;
		$banner->expiry             = $configured;
		$GLOBALS['faz_test_cookie'] = '';
		$response = $bridge->handle_update( new Faz_AMP_Test_Request(
			$base + array( 'consentStateValue' => 'rejected', 'purposeConsent' => array() ),
			'/faz/v1/amp-consent/update'
		) );
		if ( is_wp_error( $response ) ) {
			return -1;
		}
		return (int) round( ( $GLOBALS['faz_test_set_cookie']['expires'] - time() ) / DAY_IN_SECONDS );
	};

	amp_same( $faz_expiry_for( 'ccpa', 2 ), 365, 'CCPA raises an undersized lifetime to its 12-month floor' );
	amp_same( $faz_expiry_for( 'ccpa', 9999 ), 3650, 'CCPA stops at the 3650-day ceiling the banner UI advertises' );
	amp_same( $faz_expiry_for( 'ccpa', 730 ), 730, 'CCPA leaves a lifetime between floor and ceiling alone' );
	amp_same( $faz_expiry_for( 'gdpr', 365 ), 182, 'the GDPR family caps an oversized lifetime at six months' );
	amp_same( $faz_expiry_for( 'gdpr', 30 ), 30, 'a 30-day GDPR re-prompt is stricter than the cap and is kept' );
	amp_same( $faz_expiry_for( 'gdpr', 0 ), 182, 'an absent GDPR configuration still yields a usable lifetime' );
	// "gdpr_ccpa" is a GDPR-family banner for lifetime purposes: resolve_context()
	// folds it to gdpr precisely so it cannot inherit the CCPA floor.
	amp_same( $faz_expiry_for( 'gdpr_ccpa', 90 ), 90, 'a combined GDPR/CCPA banner keeps the stricter GDPR-family rule' );
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
	//
	// Note the fixture categories: analytics and marketing exist, "functional"
	// does NOT — the state a publisher reaches by hiding or deleting that default
	// category, while amp-iframe/amp-youtube still map to it. That case has its
	// own assertions below.
	$GLOBALS['faz_test_amp_map'] = array(
		'amp-owner-widget'       => 'marketing',
		'amp-owner-filter-multi' => array( 'analytics', 'marketing' ),
		// One configured purpose and one the site does not offer.
		'amp-owner-partial'      => array( 'analytics', 'functional' ),
	);
	$markup = '<amp-analytics type="x"></amp-analytics><amp-ad width="1"></amp-ad><amp-iframe src="x"></amp-iframe><amp-youtube data-videoid="x"></amp-youtube><amp-video src="local.mp4"></amp-video><amp-pixel data-block-on-consent="_auto_reject"></amp-pixel><amp-owner-explicit data-faz-category="analytics"></amp-owner-explicit><amp-owner-purpose data-faz-purpose="marketing"></amp-owner-purpose><amp-owner-multi data-faz-purpose="analytics,marketing"></amp-owner-multi><amp-owner-widget></amp-owner-widget><amp-owner-filter-multi></amp-owner-filter-multi><amp-owner-partial></amp-owner-partial><amp-unknown-widget></amp-unknown-widget>';
	$blocked = $amp->apply_component_blocking( $markup );
	amp_ok( false !== strpos( $blocked, '<amp-analytics data-block-on-consent-purposes="analytics"' ), 'amp-analytics is blocked on analytics purpose' );
	amp_ok( false !== strpos( $blocked, '<amp-ad data-block-on-consent-purposes="marketing"' ), 'amp-ad is blocked on marketing purpose' );

	// ── A mapped purpose the site does not configure ────────────────────────
	// "functional" is a default category, but an admin may hide or delete it, and
	// get_purposes() then omits it. Gating on every configured purpose is clumsy:
	// a YouTube embed ends up waiting on marketing AND analytics, categories that
	// have nothing to do with it. An earlier revision replaced that with
	// data-block-on-consent="_till_accepted" for exactly that reason.
	//
	// It was reverted, and the direction of the trade is why. _till_accepted
	// unblocks on ACCEPTED, and Save sends accept(purposeConsentDefault=false) —
	// so ticking nothing and saving would have loaded the component on a decision
	// that granted no category. Over-blocking is a usability complaint with an
	// obvious remedy; under-blocking is a tracker running without consent.
	amp_ok(
		1 === preg_match( '/<amp-iframe data-block-on-consent-purposes="[^"]*analytics[^"]*"/', $blocked ),
		'a component mapped to an absent purpose stays gated on the purposes that exist'
	);
	amp_ok(
		false === strpos( $blocked, '<amp-youtube data-block-on-consent="_till_accepted"' ),
		'it never unblocks on a bare accept, which Save sends with zero categories granted'
	);
	amp_ok(
		1 === preg_match( '/<amp-youtube data-block-on-consent-purposes="[^"]+"/', $blocked ),
		'the YouTube embed keeps a purpose gate rather than the global decision'
	);
	amp_ok( false !== strpos( $blocked, '<amp-owner-partial data-block-on-consent-purposes="analytics">' ), 'a partly configurable mapping gates on the purposes the site does offer' );
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
	// A cached AMP document naming a banner that no longer resolves. The old
	// answer here was consentRequired:false, which in AMP unblocks EVERY gated
	// component — so renaming or replacing a banner silently turned tracker
	// gating off for everyone still served that document from the cache, until
	// it re-crawled. This assertion used to demand that behaviour.
	//
	// The distinction the bridge now makes: is any banner still active?
	// Restore the optional categories first — an earlier fixture left only
	// "necessary", and a site with no optional purposes cannot show whether the
	// replaced-banner answer denies them.
	Category_Controller::$items = array(
		array( 'slug' => 'necessary', 'name' => 'Necessary', 'visibility' => 1 ),
		array( 'slug' => 'analytics', 'name' => 'Analytics', 'visibility' => 1 ),
		array( 'slug' => 'marketing', 'name' => 'Marketing', 'visibility' => 1 ),
	);
	Banner_Controller::$banner     = false;
	Banner_Controller::$any_active = $banner;
	$replaced_check = $bridge->handle_check( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'accepted' ) ) );
	amp_same( $replaced_check->get_data()['consentRequired'], true, 'a replaced banner still requires consent — the canonical page is prompting for it' );
	amp_same( $replaced_check->get_data()['purposeConsents'], array( 'analytics' => false, 'marketing' => false ), 'a replaced banner denies every optional purpose until the visitor decides' );
	amp_same( $replaced_check->get_data()['expireCache'], true, 'a replaced banner expires the cached AMP decision so the document is refetched' );

	// Only when the publisher has genuinely stopped asking anywhere is
	// "no consent required" the honest answer.
	Banner_Controller::$any_active = false;
	$inactive_check = $bridge->handle_check( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'accepted' ) ) );
	amp_same( $inactive_check->get_data()['consentRequired'], false, 'with no banner active anywhere there is nothing left to gate' );
	amp_same( $inactive_check->get_data()['expireCache'], true, 'inactive scoped banner expires cached AMP consent' );
	Banner_Controller::$banner     = $banner;
	Banner_Controller::$any_active = null;

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
	// strpos() returns false when the needle is absent, and false < N is true
	// after coercion to 0 — comparing positions without first proving both exist
	// gives an assertion that survives the removal of the very call it checks.
	$faz_remove_pos = strpos( $serve_code, "header_remove( 'Access-Control-Allow-Origin' )" );
	$faz_guard_pos  = strpos( $serve_code, "if ( '' !== \$this->cors_origin )" );
	amp_ok(
		false !== $faz_remove_pos && false !== $faz_guard_pos && $faz_remove_pos < $faz_guard_pos,
		'generic WordPress CORS grant is removed even when an AMP route returns an error'
	);

	// The shared cookie helper remains backwards-compatible while enforcing the
	// browser requirement that SameSite=None is never emitted without Secure.
	$bootstrap_source = file_get_contents( dirname( __DIR__, 2 ) . '/faz-cookie-manager.php' );
	amp_ok( false !== strpos( $bootstrap_source, "\$same_site = 'Lax', \$force_secure = null" ), 'shared cookie helper keeps Lax/default call compatibility' );
	amp_ok( false !== strpos( $bootstrap_source, "if ( 'None' === \$same_site && ! \$secure )" ), 'shared cookie helper rejects insecure SameSite=None combinations' );
	amp_ok( false !== strpos( $bootstrap_source, "'samesite' => \$same_site" ) && false !== strpos( $bootstrap_source, "'secure'   => \$secure" ), 'normalized AMP cookie options reach the real setcookie call' );

	// ── The transport the runtime actually uses ────────────────────────────
	// amp-consent serializes its payload as JSON but labels it
	// text/plain;charset=utf-8 so the request stays preflight-free. WordPress
	// parses a body into params only for application/json or form-urlencoded, so
	// with the real runtime every body param was null: check failed its instance
	// comparison and 400'd, update was rejected during dispatch by a `required`
	// arg. The bridge never served a single real AMP request.
	//
	// No assertion could catch that while the request double fed get_param()
	// directly. These post the way the runtime does.
	$_SERVER = array( 'HTTP_AMP_SAME_ORIGIN' => 'true' );
	Banner_Controller::$banner     = $banner;
	Banner_Controller::$any_active = null;
	$GLOBALS['faz_test_cookie'] = '';

	$amp_body = wp_json_encode(
		array(
			'consentInstanceId' => $instance,
			'consentStateValue' => 'accepted',
			'purposeConsents'   => array( 'analytics' => true, 'marketing' => false ),
		)
	);
	// banner/scope travel in the query string, exactly as endpoint_urls() bakes
	// them into checkConsentHref — only the rest comes from the body.
	$url_params = array( 'banner' => 'main-banner', 'scope' => $scope );

	$plain_check = $bridge->handle_check(
		new Faz_AMP_Test_Request( $url_params, '/faz/v1/amp-consent/check', $amp_body, 'text/plain;charset=utf-8' )
	);
	amp_ok( ! is_wp_error( $plain_check ), 'a text/plain AMP check body is understood instead of 400ing' );
	amp_same( $plain_check->get_data()['consentRequired'], true, 'the hydrated check still answers restrictively with no stored state' );

	$plain_update = $bridge->handle_update(
		new Faz_AMP_Test_Request( $url_params, '/faz/v1/amp-consent/update', $amp_body, 'text/plain;charset=utf-8' )
	);
	amp_ok( ! is_wp_error( $plain_update ), 'a text/plain AMP update body reaches the callback instead of rest_missing_callback_param' );
	amp_same( $plain_update->get_data()['consentStateValue'], 'accepted', 'the decision carried in the text/plain body is the one recorded' );
	amp_ok( false !== strpos( $GLOBALS['faz_test_cookie'], 'analytics:yes' ), 'a granted purpose from the text/plain body reaches the cookie' );
	amp_ok( false !== strpos( $GLOBALS['faz_test_cookie'], 'marketing:no' ), 'a denied purpose from the text/plain body reaches the cookie' );

	// A URL param must win over a body key claiming otherwise: banner and scope
	// are what the server itself signed into the endpoint URL.
	$spoof_body = wp_json_encode(
		array(
			'consentInstanceId' => $instance,
			'consentStateValue' => 'accepted',
			'banner'            => 'attacker-banner',
			'scope'             => 'forged',
		)
	);
	$spoofed = $bridge->handle_check(
		new Faz_AMP_Test_Request( $url_params, '/faz/v1/amp-consent/check', $spoof_body, 'text/plain;charset=utf-8' )
	);
	amp_ok( ! is_wp_error( $spoofed ), 'a body key cannot override the signed banner/scope from the URL' );

	// An update with no state anywhere is refused by the callback, since the
	// route arg can no longer enforce it during dispatch.
	$stateless = $bridge->handle_update(
		new Faz_AMP_Test_Request( $url_params, '/faz/v1/amp-consent/update', wp_json_encode( array( 'consentInstanceId' => $instance ) ), 'text/plain;charset=utf-8' )
	);
	amp_ok( is_wp_error( $stateless ), 'an update carrying no consent state is still rejected' );
	amp_same( $stateless->get_error_code(), 'faz_amp_missing_state', 'the missing-state rejection is explicit rather than a dispatch-level 400' );

	// Malformed JSON must be ignored rather than throwing or half-populating
	// params: the request then simply has no stored state, which is the
	// restrictive answer.
	$garbage_request = new Faz_AMP_Test_Request( $url_params, '/faz/v1/amp-consent/check', '{not json', 'text/plain;charset=utf-8' );
	$garbage         = $bridge->handle_check( $garbage_request );
	amp_same( $garbage_request->get_param( 'consentStateValue' ), null, 'a malformed body injects no params' );
	// It fails, and failing is right: with nothing decoded there is no
	// consentInstanceId, and an unidentified check must not be answered. What
	// matters is that it is a clean, explicit rejection rather than a crash or a
	// permissive default.
	amp_ok( is_wp_error( $garbage ), 'a malformed body is rejected rather than answered' );
	amp_same( $garbage->get_error_code(), 'faz_amp_invalid_instance', 'the rejection names the missing instance instead of failing open' );

	// ── Blocking coverage ───────────────────────────────────────────────────
	// amp-embed is the documented ALIAS of amp-ad and is how Taboola/Outbrain
	// embeds are conventionally written. It was absent from the map, so
	// advertising rendered before consent on exactly the placements most likely
	// to carry it — while the feature claimed advertising was natively blocked.
	$amp_source = file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-amp-consent.php' );
	foreach ( array( 'amp-embed', 'amp-story-auto-ads' ) as $ad_component ) {
		amp_ok(
			(bool) preg_match( "/'" . preg_quote( $ad_component, '/' ) . "'\\s*=>\\s*'marketing'/", $amp_source ),
			"{$ad_component} is gated as advertising"
		);
	}
	foreach ( array( 'amp-brightcove', 'amp-dailymotion', 'amp-jwplayer', 'amp-tiktok', 'amp-reddit', 'amp-connatix-player', 'amp-3q-player', 'amp-brid-player' ) as $embed_component ) {
		amp_ok(
			false !== strpos( $amp_source, "'" . $embed_component . "'" ),
			"{$embed_component} is gated rather than left to load before consent"
		);
	}

	// ── Preflight ──────────────────────────────────────────────────────────
	// Core answers OPTIONS at rest_pre_dispatch priority 10, before any route
	// callback, so the bridge has exactly one place to establish the CORS origin
	// for a preflight. Running later meant serve_cors_headers() had nothing to
	// emit and stripped the header core had set — every real preflight failed.
	$preflight_hook = null;
	foreach ( $GLOBALS['faz_test_filters'] as $registered_filter ) {
		if ( 'rest_pre_dispatch' === $registered_filter['hook'] ) {
			$preflight_hook = $registered_filter;
		}
	}
	amp_ok( null !== $preflight_hook, 'the bridge hooks rest_pre_dispatch for preflight' );
	amp_same( $preflight_hook ? $preflight_hook['priority'] : 0, 9, 'it runs before core answers OPTIONS at priority 10' );

	// A denied preflight must leave no origin behind: emitting nothing makes the
	// browser refuse the follow-up request, which is the fail-closed direction.
	$_SERVER = array( 'HTTP_AMP_SAME_ORIGIN' => 'true' );
	$ok_preflight = new Faz_AMP_Test_Request( array(), '/faz/v1/amp-consent/check', '', '', 'OPTIONS' );
	amp_same( $bridge->authorize_preflight( null, null, $ok_preflight ), null, 'preflight authorisation is a side effect, never a response' );
	amp_same( $bridge->cors_origin_for_tests(), 'https://publisher.example', 'a same-origin preflight establishes the origin core will need' );

	// A denied origin must leave nothing behind: with no origin recorded,
	// serve_cors_headers() emits none and the browser refuses the follow-up.
	$_SERVER = array( 'HTTP_ORIGIN' => 'https://evil.example' );
	$bridge->authorize_preflight( null, null, new Faz_AMP_Test_Request( array(), '/faz/v1/amp-consent/check', '', '', 'OPTIONS' ) );
	amp_same( $bridge->cors_origin_for_tests(), '', 'a foreign preflight origin is not recorded' );

	// A POST is left to the normal path, and an unrelated route is ignored.
	$_SERVER = array( 'HTTP_AMP_SAME_ORIGIN' => 'true' );
	$bridge->authorize_preflight( null, null, new Faz_AMP_Test_Request( array(), '/faz/v1/amp-consent/check', '', '', 'POST' ) );
	amp_same( $bridge->cors_origin_for_tests(), '', 'a POST is not treated as a preflight' );
	$bridge->authorize_preflight( null, null, new Faz_AMP_Test_Request( array(), '/wp/v2/posts', '', '', 'OPTIONS' ) );
	amp_same( $bridge->cors_origin_for_tests(), '', 'an unrelated route is left alone' );

	// ── Where a logged AMP consent claims to have been given ───────────────
	// resolve_consent_url() trusts the referer only when its origin is the
	// publisher's own or the AMP cache origin already accepted for this request,
	// and otherwise records the home URL. The rejection path carries the risk: an
	// attacker-chosen referer written into the accountability record is a false
	// statement about where consent was collected, in the one table meant to be
	// evidence. Nothing asserted on the url field before this.
	$faz_logged_url = function ( $server, $params ) use ( $bridge, $base ) {
		$GLOBALS['faz_test_cookie'] = '';
		$_SERVER                    = $server;
		$before                     = count( Log_Controller::$rows );
		$response                   = $bridge->handle_update( new Faz_AMP_Test_Request(
			$base + $params + array( 'consentStateValue' => 'accepted', 'purposeConsent' => array() ),
			'/faz/v1/amp-consent/update'
		) );
		if ( is_wp_error( $response ) || count( Log_Controller::$rows ) === $before ) {
			return '';
		}
		$rows = Log_Controller::$rows;
		$row  = end( $rows );
		return $row['url'];
	};

	amp_same(
		$faz_logged_url(
			array( 'HTTP_AMP_SAME_ORIGIN' => 'true', 'HTTP_REFERER' => 'https://publisher.example/amp/article/' ),
			array()
		),
		'https://publisher.example/amp/article/',
		'a referer from the publisher itself is recorded as the consent page'
	);
	amp_same(
		$faz_logged_url(
			array(
				'HTTP_ORIGIN'  => 'https://publisher-example.cdn.ampproject.org',
				'HTTP_REFERER' => 'https://publisher-example.cdn.ampproject.org/c/s/publisher.example/amp/article/',
			),
			array( '__amp_source_origin' => 'https://publisher.example' )
		),
		'https://publisher-example.cdn.ampproject.org/c/s/publisher.example/amp/article/',
		'the AMP cache document accepted for this request is recorded as given'
	);
	amp_same(
		$faz_logged_url(
			array( 'HTTP_AMP_SAME_ORIGIN' => 'true', 'HTTP_REFERER' => 'https://evil.example/claimed-page/' ),
			array()
		),
		'https://publisher.example/',
		'a foreign referer is replaced by the home URL rather than recorded as fact'
	);
	amp_same(
		$faz_logged_url( array( 'HTTP_AMP_SAME_ORIGIN' => 'true' ), array() ),
		'https://publisher.example/',
		'and a request with no referer falls back to the home URL'
	);

	// ── A structured value where a scalar belongs ──────────────────────────
	// A body like {"consentStateValue":{"a":1}} used to be hydrated as-is and
	// then handed to sanitize_key( (string) $value ), which emits "Array to string
	// conversion". The answer stayed fail-closed, so this was never a hole — but
	// filling a publisher's error log is something any stranger could do on
	// demand. Values are now dropped at the boundary unless the key's shape allows
	// them, leaving a request that simply carries no state.
	$_SERVER            = array( 'HTTP_AMP_SAME_ORIGIN' => 'true' );
	$structured_request = new Faz_AMP_Test_Request(
		$url_params,
		'/faz/v1/amp-consent/update',
		wp_json_encode(
			array(
				'consentInstanceId' => $instance,
				'consentStateValue' => array( 'a' => 1 ),
				// The mirror image: a scalar where the purpose map belongs.
				'purposeConsents'   => 'not-a-map',
			)
		),
		'text/plain;charset=utf-8'
	);
	$faz_php_diagnostics = array();
	set_error_handler(
		function ( $errno, $errstr ) use ( &$faz_php_diagnostics ) {
			$faz_php_diagnostics[] = $errstr;
			return true;
		}
	);
	$structured = $bridge->handle_update( $structured_request );
	restore_error_handler();
	amp_same( $structured_request->get_param( 'consentStateValue' ), null, 'a structured value never becomes a scalar param' );
	amp_same( $structured_request->get_param( 'purposeConsents' ), null, 'nor a scalar a purpose map' );
	amp_ok( is_wp_error( $structured ), 'the malformed update is refused' );
	amp_same( $structured->get_error_code(), 'faz_amp_missing_state', 'and refused for the honest reason: no state was sent' );
	amp_ok( empty( $faz_php_diagnostics ), 'handling it raises no PHP diagnostics: ' . implode( ' | ', $faz_php_diagnostics ) );

	// ── Two slugs that used to share one AMP action argument ───────────────
	// setPurpose() arguments must be plain identifiers, so hyphens become
	// underscores — and "social-media" and "social_media" are both legal
	// admin-editable slugs that collapsed onto "social_media". Both boxes then
	// reported under one name and the server, matching the underscore form back to
	// the hyphenated slug, gave both categories the same answer: ticking one
	// granted the other. Granting a category the visitor did not choose is the one
	// direction this bridge may never fail in.
	$_SERVER                    = array( 'HTTP_AMP_SAME_ORIGIN' => 'true' );
	Category_Controller::$items = array(
		array( 'slug' => 'necessary', 'name' => 'Necessary', 'visibility' => 1 ),
		array( 'slug' => 'social-media', 'name' => 'Social media', 'visibility' => 1 ),
		array( 'slug' => 'social_media', 'name' => 'Social media (legacy)', 'visibility' => 1 ),
	);
	$collide_purposes = AMP_Consent_Rest::get_purposes();
	$collide_args     = AMP_Consent_Rest::purpose_action_args( $collide_purposes );
	amp_same( count( array_unique( array_values( $collide_args ) ) ), 2, 'two colliding slugs get two distinct action arguments' );
	amp_ok( false === strpos( $collide_args['social-media'], '-' ), 'the hyphenated slug still yields an identifier AMP can parse' );
	$faz_arg_shadows_slug = false;
	foreach ( $collide_args as $faz_purpose_id => $faz_arg ) {
		if ( $faz_arg !== $faz_purpose_id && isset( $collide_args[ $faz_arg ] ) ) {
			// An argument equal to ANOTHER category's slug is the same ambiguity
			// wearing a different hat: the server could not tell whose answer it is.
			$faz_arg_shadows_slug = true;
		}
	}
	amp_ok( ! $faz_arg_shadows_slug, 'no action argument doubles as a different category slug' );

	amp_same(
		AMP_Consent_Rest::normalize_purpose_consent( array( $collide_args['social_media'] => 1 ), $collide_purposes, 'accepted' ),
		array( 'social-media' => false, 'social_media' => true ),
		'granting the underscore category leaves the hyphenated one denied'
	);
	amp_same(
		AMP_Consent_Rest::normalize_purpose_consent( array( $collide_args['social-media'] => 1 ), $collide_purposes, 'accepted' ),
		array( 'social-media' => true, 'social_media' => false ),
		'and granting the hyphenated category leaves the underscore one denied'
	);
	// The plain slug stays readable, because that is the vocabulary of the signed
	// consentString and of the bridge's own purposeConsents responses.
	amp_same(
		AMP_Consent_Rest::normalize_purpose_consent( array( 'social-media' => 1 ), $collide_purposes, 'accepted' ),
		array( 'social-media' => true, 'social_media' => false ),
		'a stored map keyed by the raw slug still round-trips'
	);

	// The rendered markup is where the collision was produced, so it gets its own
	// assertion. output_amp_consent() renders once per request by design; the
	// static guard has to be released to render a second fixture.
	$faz_render_guard = new ReflectionProperty( AMP_Consent::class, 'consent_output' );
	$faz_render_guard->setAccessible( true );
	$faz_render_guard->setValue( null, false );
	ob_start();
	$amp->output_amp_consent();
	$collide_html = ob_get_clean();
	foreach ( $collide_args as $faz_purpose_id => $faz_arg ) {
		amp_ok(
			// The trailing "=event.checked)" closes the needle: without it
			// "setPurpose(social_media" also matches the social_media_2 call and the
			// assertion passes for the wrong checkbox.
			false !== strpos( $collide_html, 'setPurpose(' . $faz_arg . '=event.checked)' ),
			"the '{$faz_purpose_id}' checkbox reports under its own argument"
		);
	}
	amp_same( substr_count( $collide_html, '.setPurpose(' ), 2, 'two categories render exactly two setPurpose calls' );

	Category_Controller::$items = array(
		array( 'slug' => 'necessary', 'name' => 'Necessary', 'visibility' => 1 ),
		array( 'slug' => 'analytics', 'name' => 'Analytics', 'visibility' => 1 ),
		array( 'slug' => 'marketing', 'name' => 'Marketing', 'visibility' => 1 ),
	);

	// ── A banner that never stored a lifetime ──────────────────────────────
	// The two entry points must fall back to the SAME number. Frontend's classic
	// path uses 180 (what gdpr.json ships and the wizard writes); the bridge used
	// 182, so the AMP copy of a default banner outlived its canonical twin by two
	// days. Goes red again the moment either fallback is edited alone.
	$_SERVER                    = array( 'HTTP_AMP_SAME_ORIGIN' => 'true' );
	$GLOBALS['faz_test_cookie'] = '';
	$faz_saved_expiry           = $banner->expiry;
	$banner->expiry             = null; // isset() is false → the fallback branch runs.
	$faz_default_update         = $bridge->handle_update( new Faz_AMP_Test_Request(
		$base + array( 'consentStateValue' => 'accepted', 'purposeConsent' => array() ),
		'/faz/v1/amp-consent/update'
	) );
	amp_ok( ! is_wp_error( $faz_default_update ), 'a banner with no stored consentExpiry still updates' );
	amp_same(
		(int) round( ( $faz_default_update->get_data()['sharedData']['fazExpiresAt'] - time() ) / DAY_IN_SECONDS ),
		180,
		'an unset GDPR-family lifetime falls back to 180 days, exactly as the classic path does'
	);
	$banner->expiry = $faz_saved_expiry;

	// ── An expiry that is not known is not an expiry of zero ───────────────
	// A pre-bridge cookie carries no absolute `exp`, so state_from_cookie() reports
	// expires = 0. Published as fazExpiresAt: 0 that reads as "expired at the
	// epoch" to anything consuming sharedData; the honest encoding is no key.
	$GLOBALS['faz_test_cookie'] = 'action:yes,consent:yes,rev:2,__scope.banner:main-banner,__scope.law:gdpr,__scope.fp:'
		. substr( wp_hash( 'main-banner|gdpr', 'auth' ), 0, 32 ) . ',analytics:yes,marketing:no';
	$faz_legacy_check = $bridge->handle_check( new Faz_AMP_Test_Request( $base + array( 'consentStateValue' => 'accepted' ) ) );
	amp_same( $faz_legacy_check->get_data()['consentStateValue'], 'accepted', 'a legacy cookie without exp is still honoured' );
	amp_same(
		array_key_exists( 'fazExpiresAt', $faz_legacy_check->get_data()['sharedData'] ),
		false,
		'an unknown deadline is omitted from sharedData rather than published as epoch zero'
	);
	amp_ok(
		! isset( $faz_legacy_check->get_data()['consentString'] ),
		'and no state is signed into AMP local storage for a lifetime the server cannot prove'
	);
	$GLOBALS['faz_test_cookie'] = '';

	// ── A CORS origin is never inherited on the AMP routes ─────────────────
	// serve_cors_headers() acts on exactly these two routes, so anything that can
	// reach it without running a callback (a dispatch-time 400, a denied
	// preflight) must find the state already cleared. Before the reset moved into
	// authorize_preflight() this assertion returned the previous request's origin.
	$_SERVER = array( 'HTTP_AMP_SAME_ORIGIN' => 'true' );
	$bridge->authorize_preflight( null, null, new Faz_AMP_Test_Request( array(), '/faz/v1/amp-consent/check', '', '', 'OPTIONS' ) );
	amp_same( $bridge->cors_origin_for_tests(), 'https://publisher.example', 'the preflight establishes an origin to inherit' );
	$bridge->authorize_preflight( null, null, new Faz_AMP_Test_Request( array(), '/faz/v1/amp-consent/update', '', '', 'POST' ) );
	amp_same( $bridge->cors_origin_for_tests(), '', 'a later AMP-route request starts from no origin at all' );

	// ── A publisher policy written on a self-closed tag ────────────────────
	// AMP custom elements are never validly self-closed, but the buffer carries
	// whatever the theme emitted. The guard used to require whitespace, "=" or
	// end-of-string after the attribute, so a trailing solidus hid it and a second
	// blocking attribute was injected next to the publisher's own.
	$faz_selfclosed = $amp->apply_component_blocking( '<amp-ad width="1" data-block-on-consent/>' );
	amp_same( substr_count( $faz_selfclosed, 'data-block-on-consent' ), 1, 'a self-closed tag keeps exactly one blocking attribute' );
	amp_same( $faz_selfclosed, '<amp-ad width="1" data-block-on-consent/>', 'and the publisher policy is preserved verbatim' );

	echo "Passed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
