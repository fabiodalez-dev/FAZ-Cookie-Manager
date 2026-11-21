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

namespace FazCookie\Includes {
	// The handlers' clock is the test's, as the transient stubs' is.
	function time() {
		return isset( $GLOBALS['faz_test_now'] ) ? (int) $GLOBALS['faz_test_now'] : \time();
	}
	// Every cookie the handlers set or clear, in order: array( name, value ).
	function setcookie( $name, $value = '', $options = array() ) {
		$GLOBALS['faz_test_cookies'][] = array( $name, $value );
		return true;
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
	$GLOBALS['faz_test_cookies']      = array();
	$GLOBALS['faz_test_stale_reads'] = false; // Every request read the transients before any wrote them.

	/**
	 * The two statements the shared audit slot runs, on an in-memory options
	 * table, with MySQL's semantics: INSERT IGNORE affects one row or none,
	 * the conditional UPDATE only a row that still matches.
	 */
	class Faz_Test_Wpdb {
		public $options = 'wp_options';
		public $rows    = array();
		public function prepare( $query, ...$args ) {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 );
			}
			return $query;
		}
		public function query( $query ) {
			if ( preg_match( "/^INSERT IGNORE INTO wp_options \\(option_name, option_value, autoload\\) VALUES \\('([^']*)', '([^']*)', 'no'\\)$/", $query, $m ) ) {
				if ( array_key_exists( $m[1], $this->rows ) ) {
					return 0;
				}
				$this->rows[ $m[1] ] = $m[2];
				return 1;
			}
			if ( preg_match( "/^UPDATE wp_options SET option_value = '([^']*)' WHERE option_name = '([^']*)' AND CAST\\(option_value AS UNSIGNED\\) < (\\d+)$/", $query, $m ) ) {
				if ( ! array_key_exists( $m[2], $this->rows ) || (int) $this->rows[ $m[2] ] >= (int) $m[3] ) {
					return 0;
				}
				$this->rows[ $m[2] ] = $m[1];
				return 1;
			}
			return false;
		}
	}
	$GLOBALS['wpdb'] = new Faz_Test_Wpdb();

	function get_transient( $key ) {
		if ( $GLOBALS['faz_test_stale_reads'] ) {
			return false;
		}
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

	echo "\n== The opt-out cookie is applied whether or not the audit row is written ==\n";
	$GLOBALS['faz_test_cookies'] = array();
	for ( $i = 0; $i < 10; $i++ ) {
		faz_optout( $handler, '127.0.0.1', 1003 );
	}
	$set = array_filter( $GLOBALS['faz_test_cookies'], function ( $c ) {
		return Do_Not_Sell_Shortcode::COOKIE_NAME === $c[0] && '1' === $c[1];
	} );
	check( 10 === count( $set ), 'each of 10 opt-outs in one second sets the opt-out cookie, the 9 without an audit slot included (got ' . count( $set ) . ')' );
	$GLOBALS['faz_test_cookies'] = array();
	for ( $i = 0; $i < 10; $i++ ) {
		faz_optout( $handler, '127.0.0.1', 1004, 'handle_rescind', true );
	}
	$cleared = array_filter( $GLOBALS['faz_test_cookies'], function ( $c ) {
		return Do_Not_Sell_Shortcode::COOKIE_NAME === $c[0] && '' === $c[1];
	} );
	check( 10 === count( $cleared ), 'each of 10 rescinds in one second clears it, the 9 without an audit slot included (got ' . count( $cleared ) . ')' );

	echo "\n== Concurrent requests: one slot, not one per request that read it first ==\n";
	Controller::$rows                = array();
	$GLOBALS['faz_test_stale_reads'] = true; // Each request reads before any writes.
	for ( $i = 0; $i < 5; $i++ ) {
		faz_optout( $handler, '127.0.0.1', 1005 );
	}
	$GLOBALS['faz_test_stale_reads'] = false;
	check( 1 === count( Controller::$rows ), 'five opt-outs that all read before any wrote still write one row in that second (got ' . count( Controller::$rows ) . ')' );
	$GLOBALS['wpdb']->rows = array();
	$broken = new class() extends Faz_Test_Wpdb {
		public function query( $query ) {
			return false;
		}
	};
	$GLOBALS['wpdb'] = $broken;
	Controller::$rows = array();
	check( true === faz_optout( $handler, '127.0.0.1', 1006 ), 'a slot query that fails still answers the opt-out with success' );
	check( 0 === count( Controller::$rows ), 'and writes no audit row' );
	$GLOBALS['wpdb'] = new Faz_Test_Wpdb();

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
