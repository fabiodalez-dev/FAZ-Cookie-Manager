<?php
/**
 * Unit test — the CCPA opt-out on an address that does not identify a visitor.
 *
 * There the per-address 60-second window is skipped so one visitor cannot
 * refuse everyone else's opt-out (claim_window()). The opt-out must still be
 * applied every time, but its consent-log row is bounded site-wide: a script
 * replaying the public nonce from behind an undeclared proxy used to write one
 * row per request, without limit.
 *
 * The handlers run for real; WordPress is stubbed and the consent logger is a
 * counter.
 *
 * Run: php tests/unit/test-dnsmpi-shared-audit-php.php
 */

namespace FazCookie\Admin\Modules\Consentlogs\Includes {
	class Controller {
		public static $rows = array();
		public static function get_instance() {
			return new self();
		}
		public function log_consent( $row ) {
			self::$rows[] = $row;
		}
	}
}

namespace FazCookie\Tests\Unit {
	class Json_Response extends \Exception {
		public $success;
		public function __construct( $success ) {
			parent::__construct( $success ? 'success' : 'error' );
			$this->success = $success;
		}
	}
}

namespace {

	ob_start(); // setcookie() needs headers not to be sent yet.

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}

	$GLOBALS['faz_test_options']    = array();
	$GLOBALS['faz_test_transients'] = array();
	$GLOBALS['faz_test_filters']    = array(
		// No mail in this test: the window under test is the audit row.
		'faz_dnsmpi_max_notifications_per_day' => function () {
			return 0;
		},
	);

	function check_ajax_referer( $action, $arg = false, $die = true ) {
		return true;
	}
	function wp_send_json_success( $data = null ) {
		throw new \FazCookie\Tests\Unit\Json_Response( true );
	}
	function wp_send_json_error( $data = null ) {
		throw new \FazCookie\Tests\Unit\Json_Response( false );
	}
	function is_ssl() {
		return true;
	}
	function wp_using_ext_object_cache() {
		return false;
	}
	function get_transient( $key ) {
		$t = $GLOBALS['faz_test_transients'][ $key ] ?? null;
		return ( null === $t || $t[1] <= $GLOBALS['faz_test_now'] ) ? false : $t[0];
	}
	function set_transient( $key, $value, $ttl ) {
		$GLOBALS['faz_test_transients'][ $key ] = array( $value, $GLOBALS['faz_test_now'] + $ttl );
		return true;
	}
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_test_options'] ) ? $GLOBALS['faz_test_options'][ $name ] : $default;
	}
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $name, $GLOBALS['faz_test_options'] ) ) {
			return false;
		}
		$GLOBALS['faz_test_options'][ $name ] = $value;
		return true;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_test_options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( $GLOBALS['faz_test_options'][ $name ] );
		return true;
	}
	function apply_filters( $hook, $value, ...$args ) {
		return isset( $GLOBALS['faz_test_filters'][ $hook ] ) ? call_user_func( $GLOBALS['faz_test_filters'][ $hook ], $value, ...$args ) : $value;
	}
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt';
	}
	function __( $text, $domain = 'default' ) {
		return $text;
	}
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
	function esc_url_raw( $url ) {
		return $url;
	}
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-ip-hasher.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-do-not-sell-shortcode.php';

	use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;
	use FazCookie\Includes\Do_Not_Sell_Shortcode;
	use FazCookie\Tests\Unit\Json_Response;

	$passed = 0;
	$failed = 0;
	function check( $cond, $label ) {
		global $passed, $failed;
		if ( $cond ) {
			$passed++;
			echo "  [PASS] $label\n";
		} else {
			$failed++;
			echo "  [FAIL] $label\n";
		}
	}

	$handler = ( new ReflectionClass( Do_Not_Sell_Shortcode::class ) )->newInstanceWithoutConstructor();

	/**
	 * One opt-out POST from $addr at second $now. Returns whether it succeeded.
	 */
	function faz_optout( $handler, $addr, $now, $method = 'handle_optout', $with_cookie = false ) {
		$GLOBALS['faz_test_now'] = $now;
		$_SERVER['REMOTE_ADDR']         = $addr;
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
		$_COOKIE                        = $with_cookie ? array( Do_Not_Sell_Shortcode::COOKIE_NAME => '1' ) : array();
		try {
			$handler->$method();
		} catch ( Json_Response $r ) {
			return $r->success;
		}
		return null;
	}

	echo "== Shared address (loopback): opt-out always applied, audit row bounded ==\n";
	$ok = 0;
	for ( $i = 0; $i < 50; $i++ ) {
		$ok += faz_optout( $handler, '127.0.0.1', 1000 ) ? 1 : 0;
	}
	check( 50 === $ok, '50 opt-outs in the same second are all answered with success (got ' . $ok . ')' );
	check( 1 === count( Controller::$rows ), 'but only one consent-log row is written in that second (got ' . count( Controller::$rows ) . ')' );

	faz_optout( $handler, '127.0.0.1', 1001 );
	check( 2 === count( Controller::$rows ), 'the next second writes the next row' );

	$before = count( Controller::$rows );
	for ( $i = 0; $i < 20; $i++ ) {
		faz_optout( $handler, '127.0.0.1', 1002, 'handle_rescind', true );
	}
	check( $before + 1 === count( Controller::$rows ), 'rescinds from a shared address are bounded the same way' );

	echo "\n== Identifying address: the per-address window still applies ==\n";
	Controller::$rows = array();
	check( true === faz_optout( $handler, '203.0.113.7', 2000 ), 'a public address opts out' );
	check( 1 === count( Controller::$rows ), 'and its row is written' );
	check( false === faz_optout( $handler, '203.0.113.7', 2010 ), 'a second opt-out from it inside 60 s is refused' );
	check( true === faz_optout( $handler, '198.51.100.9', 2010 ), 'another public address in the same second is not held back by the shared slot' );
	check( 2 === count( Controller::$rows ), 'and its row is written too' );

	ob_end_flush();
	echo "\nPassed: $passed\nFailed: $failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
