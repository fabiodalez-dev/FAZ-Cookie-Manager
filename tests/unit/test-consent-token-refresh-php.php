<?php
/**
 * A page cached past the origin-token window must be able to recover.
 *
 * The consent-log and pageview tokens are printed into cacheable HTML and
 * accepted for a bounded window (7 days by default, 30 at most). An edge cache
 * that keeps a page longer hands every visitor a token the endpoint refuses,
 * and because both beacons are fire-and-forget the record is lost. The beacons
 * now fetch a current token from a no-store admin-ajax action and resend once
 * (the client half is pinned by tests/unit/js/origin-token-refresh.test.mjs).
 *
 * This runner drives the REAL Consent_Logger against WordPress doubles and
 * pins the server half: the refresh action is registered for anonymous
 * visitors, hands out exactly the tokens the page would print, only for the
 * beacons that are enabled, and the first post of a beacon that announced it
 * can refresh is refused without being counted as a lost record — while the
 * resend, or a beacon that cannot refresh, is still counted.
 *
 * Run: php tests/unit/test-consent-token-refresh-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Consentlogs\Includes {
	class Controller {
		private static $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function get_log_by_consent_id( $consent_id ) { return null; }
		public function log_consent( $data ) { return array( 'log_id' => 1 ); }
	}
}

namespace FazCookie\Includes {
	// Cache_Compatibility::send_no_store_headers() runs in this namespace, so
	// these record what the refresh endpoint actually sends.
	function headers_sent() { return false; }
	function header( $line, $replace = true ) { $GLOBALS['faz_sent_headers'][] = $line; }
}

namespace FazCookie\Admin\Modules\Pageviews\Api {
	/** The pageview token minting, as the real class does it (wp_hash of the bucket). */
	class Api {
		const TOKEN_BUCKET = 43200;
		public static function current_token( $at = null ) {
			$at = null === $at ? time() : (int) $at;
			return wp_hash( 'faz_pageview_' . (string) (int) floor( $at / self::TOKEN_BUCKET ) );
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	foreach ( array( 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800, 'MINUTE_IN_SECONDS' => 60 ) as $faz_const => $faz_value ) {
		if ( ! defined( $faz_const ) ) {
			define( $faz_const, $faz_value );
		}
	}
	ini_set( 'error_log', sys_get_temp_dir() . '/faz-consent-token-refresh-test.log' );

	$GLOBALS['faz_options']    = array();
	$GLOBALS['faz_transients'] = array();
	$GLOBALS['faz_actions']    = array();
	$GLOBALS['faz_json']       = null;

	// WordPress doubles, one line each: the body is its own description.
	function wp_hash( $data, $scheme = 'auth' ) { return hash_hmac( 'md5', (string) $data, 'unit-salt' ); }
	function apply_filters( $hook, $value ) { return $value; }
	function add_action( $hook, $callback ) { $GLOBALS['faz_actions'][ $hook ] = $callback; return true; }
	function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['faz_options'] ) ? $GLOBALS['faz_options'][ $name ] : $default; }
	function update_option( $name, $value, $autoload = null ) { $GLOBALS['faz_options'][ $name ] = $value; return true; }
	function get_transient( $name ) { return array_key_exists( $name, $GLOBALS['faz_transients'] ) ? $GLOBALS['faz_transients'][ $name ] : false; }
	function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['faz_transients'][ $name ] = $value; return true; }
	function faz_throttle_request( $prefix = 'faz_throttle', $ttl = 1 ) { return false; }
	function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $key ) ); }
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
	function wp_unslash( $value ) { return $value; }
	function home_url( $path = '' ) { return 'https://example.test' . $path; }
	function wp_parse_url( $url ) { return parse_url( (string) $url ); }
	function esc_url_raw( $url ) { return (string) $url; }
	function __( $text, $domain = 'default' ) { return (string) $text; } // phpcs:ignore
	function rest_ensure_response( $response ) { return $response; }
	function wp_send_json_success( $data = null ) { $GLOBALS['faz_json'] = array( 'success' => true, 'data' => $data ); }

	class WP_Error {
		public $code;
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; }
		public function get_error_code() { return $this->code; }
	}
	class WP_REST_Request {
		private $params;
		public function __construct( array $params ) { $this->params = $params; }
		public function get_param( $key ) { return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null; }
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/modules/consent-logger/class-consent-logger.php';

	use FazCookie\Frontend\Modules\Consent_Logger\Consent_Logger;

	$passed = 0;
	$failed = 0;
	function ref_check( $actual, $label ) {
		global $passed, $failed;
		if ( $actual ) { $passed++; echo "  [PASS] {$label}\n"; }
		else { $failed++; echo "  [FAIL] {$label}\n"; }
	}
	function ref_stale_count() {
		$tally = Consent_Logger::rejection_tally();
		return (int) ( $tally['causes'][ Consent_Logger::CAUSE_STALE_TOKEN ] ?? 0 );
	}

	$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
	$action = defined( Consent_Logger::class . '::TOKEN_REFRESH_ACTION' ) ? Consent_Logger::TOKEN_REFRESH_ACTION : 'faz_origin_tokens';

	echo "\n== The refresh action ==\n";
	$logger = new Consent_Logger();
	ref_check( isset( $GLOBALS['faz_actions'][ 'wp_ajax_nopriv_' . $action ] ), 'the refresh action is registered for anonymous visitors' );
	ref_check( isset( $GLOBALS['faz_actions'][ 'wp_ajax_' . $action ] ), 'and for logged-in ones' );

	$GLOBALS['faz_options']['faz_settings'] = array( 'consent_logs' => array( 'status' => true ), 'pageview_tracking' => true );
	$GLOBALS['faz_json'] = null;
	if ( method_exists( $logger, 'handle_token_refresh' ) ) {
		$logger->handle_token_refresh();
	}
	$json = $GLOBALS['faz_json'];
	ref_check( is_array( $json ) && true === $json['success'], 'it answers with a success payload' );
	ref_check( isset( $json['data']['consent'] ) && Consent_Logger::current_token() === $json['data']['consent'], 'carrying the consent token a page rendered now would print' );
	ref_check( isset( $json['data']['pageview'] ) && \FazCookie\Admin\Modules\Pageviews\Api\Api::current_token() === $json['data']['pageview'], 'and the pageview token' );
	ref_check( isset( $json['data']['consent'] ) && Consent_Logger::token_is_valid( $json['data']['consent'] ), 'which the consent endpoint accepts' );

	$GLOBALS['faz_options']['faz_settings'] = array( 'consent_logs' => array( 'status' => true ), 'pageview_tracking' => '1' );
	$GLOBALS['faz_json'] = null;
	if ( method_exists( $logger, 'handle_token_refresh' ) ) {
		$logger->handle_token_refresh();
	}
	ref_check( is_array( $GLOBALS['faz_json'] ) && ! isset( $GLOBALS['faz_json']['data']['pageview'] ), 'a beacon that is off gets no token (same strict read as the frontend: "1" is not true)' );
	$GLOBALS['faz_options']['faz_settings'] = array();
	$GLOBALS['faz_json'] = null;
	if ( method_exists( $logger, 'handle_token_refresh' ) ) {
		$logger->handle_token_refresh();
	}
	ref_check( is_array( $GLOBALS['faz_json'] ) && array() === $GLOBALS['faz_json']['data'], 'with both off the payload is empty' );

	// The full no-store set every FAZ veto sends, not a local Cache-Control
	// line: an edge that reads only CDN-Cache-Control or Surrogate-Control
	// would otherwise store a token the next visitor is handed.
	$GLOBALS['faz_sent_headers'] = array();
	$logger->handle_token_refresh();
	$faz_expected = array();
	foreach ( \FazCookie\Includes\Cache_Compatibility::NO_STORE_HEADERS as $faz_name => $faz_value ) {
		$faz_expected[] = $faz_name . ': ' . $faz_value;
	}
	ref_check( $faz_expected === $GLOBALS['faz_sent_headers'], 'the refresh response carries Cache_Compatibility::NO_STORE_HEADERS' );

	echo "\n== Counting a stale-token refusal ==\n";
	$stale_token = 'a-token-from-a-page-cached-too-long';
	$post = function ( array $extra ) use ( $logger, $stale_token ) {
		$result = $logger->handle_rest_consent( new WP_REST_Request( array_merge( array(
			'token' => $stale_token, 'consent_id' => 'abc', 'status' => 'rejected', 'categories' => array(),
		), $extra ) ) );
		return $result instanceof WP_Error ? $result->get_error_code() : 'logged';
	};

	$GLOBALS['faz_options'] = array();
	ref_check( 'invalid_token' === $post( array( 'can_refresh' => 1 ) ), 'a stale token is still refused when the beacon can refresh' );
	ref_check( 0 === ref_stale_count(), 'but that refusal is not counted as a lost record: the beacon will resend' );
	ref_check( 'invalid_token' === $post( array() ), 'the resend (no flag) refused again' );
	ref_check( 1 === ref_stale_count(), 'is counted' );
	$GLOBALS['faz_options'] = array();
	$post( array( 'can_refresh' => '0' ) );
	ref_check( 1 === ref_stale_count(), 'a beacon that cannot refresh (a page cached before this change) is counted as before' );
	$GLOBALS['faz_options'] = array();
	$fresh = $logger->handle_rest_consent( new WP_REST_Request( array( 'token' => Consent_Logger::current_token(), 'consent_id' => 'abc', 'status' => 'rejected', 'categories' => array(), 'can_refresh' => 1 ) ) );
	ref_check( ! ( $fresh instanceof WP_Error ), 'premise: a fresh token is accepted, flag or no flag' );
	ref_check( 0 === ref_stale_count(), 'and counts nothing' );

	echo "\nconsent token refresh: {$passed} passed, {$failed} failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
