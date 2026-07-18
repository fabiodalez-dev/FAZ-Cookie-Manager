<?php
/**
 * Standalone unit tests for the 1.19.0 release follow-up fixes (backend).
 *
 * Subsystem: followups-1190
 *
 * Covers two private Frontend helpers touched by the follow-up commits:
 *   - Frontend::is_always_allowed_gateway_pattern()  (forward match always;
 *     reverse substring match guarded by a >=4-char minimum needle, so a short
 *     generic pattern can't be a substring of a gateway URL and over-exempt).
 *   - Frontend::compute_whitelisted_cookie_patterns() (now also exempts the
 *     cookies of always-allowed payment gateways — Stripe etc. — from the
 *     shredder, even with NO user whitelist; the user-whitelist path still
 *     works; necessary / out-of-scope categories stay excluded).
 *
 * Pure-logic: no browser, no DB, no live WordPress. Frontend is built with
 * ReflectionClass::newInstanceWithoutConstructor(); the few WP functions the
 * two methods touch are stubbed; Known_Providers is replaced with a
 * controllable double (braced-namespace block, defined BEFORE class-frontend.php
 * loads so the `use FazCookie\Includes\Known_Providers` alias resolves to it).
 *
 * Run: php tests/unit/test-followups-1190.php   (or bash scripts/run-unit-tests.sh)
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	class Known_Providers {
		public static function get_all() {
			return $GLOBALS['__faz_providers'];
		}
		public static function get_cookie_map() {
			return array();
		}
		public static function get_pattern_map() {
			return array();
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['__faz_providers'] = array();

	if ( ! function_exists( 'apply_filters' ) ) {
		// No filters registered: return the default value verbatim, so
		// get_always_allowed_gateway_patterns() yields its hardcoded Stripe set.
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $str ) {
			return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			$str = preg_replace( '/[\r\n\t ]+/', ' ', (string) $str );
			return trim( wp_strip_all_tags( $str ) );
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	// ---------- Tiny assertion harness ----------
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

	$fe  = ( new ReflectionClass( Frontend::class ) )->newInstanceWithoutConstructor();
	$ref = new ReflectionClass( Frontend::class );

	$is_gateway = $ref->getMethod( 'is_always_allowed_gateway_pattern' );
	$is_gateway->setAccessible( true );
	$gw = function ( $pattern ) use ( $is_gateway, $fe ) {
		// Reset the per-instance cache between calls so each is independent.
		$prop = new ReflectionProperty( \FazCookie\Frontend\Frontend::class, 'always_allowed_cache' );
		$prop->setAccessible( true );
		$prop->setValue( $fe, null );
		return (bool) $is_gateway->invoke( $fe, $pattern );
	};

	$compute = $ref->getMethod( 'compute_whitelisted_cookie_patterns' );
	$compute->setAccessible( true );
	$wl = function ( $user_whitelist, $valid_categories ) use ( $compute, $fe ) {
		return $compute->invoke( $fe, $user_whitelist, $valid_categories );
	};

	echo "== is_always_allowed_gateway_pattern ==\n";

	// 1. Forward: the default allow-list contains 'js.stripe.com'.
	check( true === $gw( 'js.stripe.com' ), '01 forward match: js.stripe.com is always-allowed' );

	// 2. Reverse (needle >= 4): 'wc-stripe' is a substring of the allowed
	//    'wc-stripe-' and is 9 chars, so the reverse arm matches.
	check( true === $gw( 'wc-stripe' ), '02 reverse match (>=4): wc-stripe is inside wc-stripe-' );

	// 3. Reverse rejected (needle < 4): 'com' is a substring of 'js.stripe.com'
	//    but only 3 chars — the min-length guard must keep it from matching.
	check( false === $gw( 'com' ), '03 reverse rejected (<4): com does NOT over-match a gateway URL' );

	// 4. No relation to any gateway token.
	check( false === $gw( 'google-analytics' ), '04 no match: google-analytics is not a gateway' );

	echo "== compute_whitelisted_cookie_patterns ==\n";

	$stripe = array(
		'category' => 'marketing',
		'patterns' => array( 'js.stripe.com', 'wc-stripe-' ),
		'cookies'  => array( '__stripe_mid', '__stripe_sid' ),
	);
	$ga = array(
		'category' => 'analytics',
		'patterns' => array( 'google-analytics.com', '_ga' ),
		'cookies'  => array( '_ga', '_gid' ),
	);
	$fb = array(
		'category' => 'marketing',
		'patterns' => array( 'connect.facebook.net/fbevents', 'fbevents' ),
		'cookies'  => array( '_fbp' ),
	);
	$necessary = array(
		'category' => 'necessary',
		'patterns' => array( 'js.stripe.com' ), // even a gateway match must be skipped for necessary.
		'cookies'  => array( 'sess_x' ),
	);
	$out_of_scope = array(
		'category' => 'social', // not in valid_categories below.
		'patterns' => array( 'js.stripe.com' ),
		'cookies'  => array( 'sc_y' ),
	);

	$valid = array( 'marketing', 'analytics' );

	// 5. Always-allowed gateway cookies are exempt even with NO user whitelist.
	$GLOBALS['__faz_providers'] = array( $stripe, $ga );
	$res = $wl( array(), $valid );
	check(
		in_array( '__stripe_mid', $res, true ) && in_array( '__stripe_sid', $res, true ),
		'05 gateway cookies whitelisted with empty user whitelist (__stripe_mid/__stripe_sid)'
	);

	// 6. A non-gateway provider's cookies are NOT whitelisted with no user whitelist.
	check(
		! in_array( '_ga', $res, true ) && ! in_array( '_gid', $res, true ),
		'06 non-gateway provider (_ga/_gid) NOT whitelisted without a user whitelist'
	);

	// 7. The admin user-whitelist path still works (token matches a provider pattern).
	$GLOBALS['__faz_providers'] = array( $fb );
	$res = $wl( array( 'fbevents' ), $valid );
	check( in_array( '_fbp', $res, true ), '07 user whitelist token fbevents whitelists _fbp' );

	// 8a. A necessary-category provider is always skipped (even on a gateway match).
	$GLOBALS['__faz_providers'] = array( $necessary );
	$res = $wl( array(), $valid );
	check( ! in_array( 'sess_x', $res, true ) && array() === $res, '08a necessary-category provider excluded' );

	// 8b. A provider whose category is not in valid_categories is skipped.
	$GLOBALS['__faz_providers'] = array( $out_of_scope );
	$res = $wl( array(), $valid );
	check( ! in_array( 'sc_y', $res, true ) && array() === $res, '08b out-of-scope-category provider excluded' );

	echo "\nPassed: $passed\nFailed: $failed\n";
	if ( $failed > 0 ) {
		echo "FAIL\n";
		exit( 1 );
	}
	echo "ALL PASS\n";
	exit( 0 );
}
