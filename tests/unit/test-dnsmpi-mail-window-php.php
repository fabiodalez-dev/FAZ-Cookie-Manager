<?php
/**
 * Unit tests for the Do-Not-Sell admin-mail ceiling window.
 *
 * Subsystem: dnsmpi-mail-window
 *
 * Do_Not_Sell_Shortcode::claim_notification() caps the admin mails one 24-hour
 * window may send. The window must close 24 hours after the FIRST mail in it:
 * a counter whose expiry moves on every mail never resets on a site with an
 * opt-out a day, and once at the ceiling the admin mail would stop for good.
 *
 * Pure-logic: no browser, no DB, no live WordPress. The class is built with
 * ReflectionClass::newInstanceWithoutConstructor() and the private method is
 * invoked by reflection against an in-memory option store.
 *
 * Run: php tests/unit/test-dnsmpi-mail-window-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	// claim_notification() waits with an unqualified usleep(), so inside the
	// class's namespace this one answers: no real sleep, and a hook that lets
	// a test act as the other request while this one waits.
	function usleep( $microseconds ) {
		if ( isset( $GLOBALS['faz_test_on_wait'] ) ) {
			( $GLOBALS['faz_test_on_wait'] )();
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['faz_test_options'] = array();
	$GLOBALS['faz_test_filters'] = array();

	// A per-request copy of the rows read, the way core's 'options' cache
	// group behaves without a persistent object cache: get_option() answers
	// from it once it has read a row, so a row another request deletes stays
	// visible here until wp_cache_delete() drops the copy.
	$GLOBALS['faz_test_cache'] = array();

	function get_option( $name, $default = false ) {
		if ( array_key_exists( $name, $GLOBALS['faz_test_cache'] ) ) {
			return $GLOBALS['faz_test_cache'][ $name ];
		}
		if ( ! array_key_exists( $name, $GLOBALS['faz_test_options'] ) ) {
			return $default;
		}
		return $GLOBALS['faz_test_cache'][ $name ] = $GLOBALS['faz_test_options'][ $name ];
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_test_options'][ $name ] = $value;
		$GLOBALS['faz_test_cache'][ $name ]   = $value;
		return true;
	}
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		// Core refuses a name get_option() can read, cached copy included.
		if ( false !== get_option( $name ) ) {
			return false;
		}
		$GLOBALS['faz_test_options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( $GLOBALS['faz_test_options'][ $name ], $GLOBALS['faz_test_cache'][ $name ] );
		return true;
	}
	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['faz_test_cache'][ $key ] );
		return true;
	}
	// Each invoke below stands for a new request: a fresh options cache.
	function faz_test_new_request() {
		$GLOBALS['faz_test_cache'] = array();
	}
	function apply_filters( $hook, $value, ...$args ) {
		return isset( $GLOBALS['faz_test_filters'][ $hook ] ) ? $GLOBALS['faz_test_filters'][ $hook ]( $value ) : $value;
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-ip-hasher.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-do-not-sell-shortcode.php';

	use FazCookie\Includes\Do_Not_Sell_Shortcode;

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

	$obj   = ( new ReflectionClass( Do_Not_Sell_Shortcode::class ) )->newInstanceWithoutConstructor();
	$claim = new ReflectionMethod( Do_Not_Sell_Shortcode::class, 'claim_notification' );
	$claim->setAccessible( true );
	$key = Do_Not_Sell_Shortcode::MAIL_WINDOW_OPTION;

	echo "\n== Do-Not-Sell admin-mail window ==\n\n";

	$GLOBALS['faz_test_filters']['faz_dnsmpi_max_notifications_per_day'] = function () {
		return 3;
	};

	check( 0 === strpos( $key, 'faz_dnsmpi_rl_' ), 'the window option carries the faz_dnsmpi_rl_ prefix the sweeps purge' );

	$results = array();
	for ( $n = 0; $n < 4; $n++ ) {
		$results[] = $claim->invoke( $obj );
	}
	check( array( true, true, true, false ) === $results, 'with a ceiling of 3, the fourth mail in the window is refused' );

	$first_start = $GLOBALS['faz_test_options'][ $key ]['start'];
	check( abs( $first_start - time() ) < 5, 'the window starts at the first mail' );

	// A window opened 23 hours ago, full: still closed, and the refusal does
	// not move its start.
	$GLOBALS['faz_test_options'][ $key ] = array( 'n' => 3, 'start' => time() - 23 * 3600 );
	check( false === $claim->invoke( $obj ), 'a full window opened 23 hours ago still refuses' );
	check( time() - 23 * 3600 === $GLOBALS['faz_test_options'][ $key ]['start'], 'a refusal leaves the window start where it was' );

	// Mails inside the window keep its start: two of three used, 20 hours in.
	$GLOBALS['faz_test_options'][ $key ] = array( 'n' => 2, 'start' => time() - 20 * 3600 );
	check( true === $claim->invoke( $obj ), 'the last mail of the window is allowed' );
	check( time() - 20 * 3600 === $GLOBALS['faz_test_options'][ $key ]['start'], 'and it does not push the window start forward' );

	// 24 hours after the first mail the window closes and a new one opens.
	$GLOBALS['faz_test_options'][ $key ] = array( 'n' => 3, 'start' => time() - 24 * 3600 - 1 );
	check( true === $claim->invoke( $obj ), 'a full window older than 24 hours is replaced by a new one' );
	check( 1 === $GLOBALS['faz_test_options'][ $key ]['n'], 'the new window counts this mail as its first' );

	// Concurrency: a request that finds the window locked by another one does
	// not read the count it is about to change.
	$lock = $key . '_lock';
	$GLOBALS['faz_test_options'][ $key ]  = array( 'n' => 0, 'start' => time() );
	$GLOBALS['faz_test_options'][ $lock ] = time();
	check( false === $claim->invoke( $obj ), 'while another request holds the window lock, no mail is claimed' );
	check( 0 === $GLOBALS['faz_test_options'][ $key ]['n'], 'and the count is untouched' );
	check( time() === $GLOBALS['faz_test_options'][ $lock ] || time() - 1 === $GLOBALS['faz_test_options'][ $lock ], 'and the other request\'s lock is left in place' );
	faz_test_new_request();
	$GLOBALS['faz_test_options'][ $lock ] = time() - 60;
	check( true === $claim->invoke( $obj ), 'a lock left by a request that died is reclaimed' );
	check( ! array_key_exists( $lock, $GLOBALS['faz_test_options'] ), 'and released after the claim' );

	// The holder releases the lock while this request waits for it. The
	// failed add_option() cached the lock row for this request, so the retry
	// must drop that copy or it never sees the release and sends no mail.
	faz_test_new_request();
	$GLOBALS['faz_test_options'][ $key ]  = array( 'n' => 0, 'start' => time() );
	$GLOBALS['faz_test_options'][ $lock ] = time();
	$GLOBALS['faz_test_on_wait']          = function () use ( $lock ) {
		unset( $GLOBALS['faz_test_options'][ $lock ] );
	};
	check( true === $claim->invoke( $obj ), 'a lock released while this request waits is taken on the retry' );
	check( 1 === $GLOBALS['faz_test_options'][ $key ]['n'], 'and the mail is counted' );
	unset( $GLOBALS['faz_test_on_wait'] );

	$GLOBALS['faz_test_filters']['faz_dnsmpi_max_notifications_per_day'] = function () {
		return 0;
	};
	check( false === $claim->invoke( $obj ), 'a ceiling of 0 sends no mail' );

	echo "\nPassed: $passed\nFailed: $failed\n";
	if ( $failed > 0 ) {
		echo "FAIL\n";
		exit( 1 );
	}
	echo "ALL PASS\n";
	exit( 0 );
}
