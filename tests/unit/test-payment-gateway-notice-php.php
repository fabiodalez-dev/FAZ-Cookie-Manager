<?php
/**
 * Standalone unit tests for the payment-gateway admin nudge helpers (#125).
 *
 * Subsystem: payment-gateway-notice
 *
 * Admin::payment_gateway_notice() shows a dismissible nudge on FAZ admin pages
 * when a non-WooCommerce payment plugin is active but the site owner has not yet
 * authorised any payment gateway — so a Forminator / PMPro / EDD / Give payment
 * form that logs "paypal is not defined" points them at the opt-in toggle
 * instead of the plugin ever auto-loading a tracker. This pins the two private
 * decision helpers it gates on:
 *   - detect_non_wc_payment_plugins() — presence detection by constant/class.
 *   - has_enabled_payment_gateway()  — "has the admin opted a gateway in?".
 *
 * Pure logic: Admin is built with newInstanceWithoutConstructor(); get_option is
 * stubbed; the payment-plugin markers are simulated by defining their version
 * constants.
 *
 * Run: php tests/unit/test-payment-gateway-notice-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['__faz_opt'] = array();
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			return array_key_exists( $name, $GLOBALS['__faz_opt'] ) ? $GLOBALS['__faz_opt'][ $name ] : $default;
		}
	}

	// The real faz_sanitize_bool(), not a stub: this suite exercises how a stored
	// gateway flag is READ, and a local copy of the coercion would let production
	// and test drift apart on exactly the value that caused the bug.
	require_once dirname( __DIR__, 2 ) . '/includes/class-formatting.php';
	require_once dirname( __DIR__, 2 ) . '/admin/class-admin.php';

	use FazCookie\Admin\Admin;

	$passed = 0;
	$failed = 0;
	function ok( $cond, $label ) {
		global $passed, $failed;
		if ( $cond ) {
			$passed++;
			echo "  [PASS] $label\n";
		} else {
			$failed++;
			echo "  [FAIL] $label\n";
		}
	}

	$a   = ( new ReflectionClass( Admin::class ) )->newInstanceWithoutConstructor();
	$det = new ReflectionMethod( Admin::class, 'detect_non_wc_payment_plugins' );
	$det->setAccessible( true );
	$has = new ReflectionMethod( Admin::class, 'has_enabled_payment_gateway' );
	$has->setAccessible( true );

	echo "detect_non_wc_payment_plugins\n";
	ok( array() === $det->invoke( $a ), '01 no payment plugin active → empty list (no nudge)' );

	define( 'FORMINATOR_VERSION', '1.55.1' );
	$found = $det->invoke( $a );
	ok( in_array( 'Forminator', $found, true ), '02 Forminator active → detected' );

	echo "has_enabled_payment_gateway\n";
	$GLOBALS['__faz_opt'] = array();
	ok( false === $has->invoke( $a ), '03 no faz_settings option → false (nudge would show)' );

	$GLOBALS['__faz_opt']['faz_settings'] = array( 'script_blocking' => array( 'payment_gateways' => array( 'paypal' => false, 'stripe' => false ) ) );
	ok( false === $has->invoke( $a ), '04 all gateways off → false (nudge would show)' );

	$GLOBALS['__faz_opt']['faz_settings']['script_blocking']['payment_gateways']['paypal'] = true;
	ok( true === $has->invoke( $a ), '05 a gateway enabled → true (nudge suppressed)' );

	// Malformed shapes must not fatal and resolve to "not enabled".
	$GLOBALS['__faz_opt']['faz_settings'] = 'not-an-array';
	ok( false === $has->invoke( $a ), '06 non-array faz_settings → false (no fatal)' );
	$GLOBALS['__faz_opt']['faz_settings'] = array( 'script_blocking' => array( 'payment_gateways' => 'nope' ) );
	ok( false === $has->invoke( $a ), '07 non-array payment_gateways → false (no fatal)' );

	// Stored strings from before the write path coerced properly. `! empty()`
	// read every one of these as an enabled gateway — which meant exempting it
	// from consent blocking — so the read path is asserted here directly, on the
	// values a real upgraded install can be carrying.
	$stored_negatives = array( 'false', '0', 'no', 'off', 'null', 'undefined', ' false ', '' );
	foreach ( $stored_negatives as $i => $value ) {
		$GLOBALS['__faz_opt']['faz_settings'] = array(
			'script_blocking' => array( 'payment_gateways' => array( 'stripe' => $value ) ),
		);
		ok(
			false === $has->invoke( $a ),
			sprintf( '%02d stored %s reads as NOT enabled', 8 + $i, '' === $value ? "''" : "'{$value}'" )
		);
	}

	// A value nobody anticipated must not be permission either. faz_sanitize_bool()
	// enumerates its negatives, so 'maybe' comes back TRUE there — correct for a
	// general coercion, wrong for a flag that removes a restriction. These pin
	// that the gateway read uses the strict form instead.
	foreach ( array( 'maybe', 'banana', 'ye', 'enabled', '2', 'yes please' ) as $k => $value ) {
		$GLOBALS['__faz_opt']['faz_settings'] = array(
			'script_blocking' => array( 'payment_gateways' => array( 'stripe' => $value ) ),
		);
		ok( false === $has->invoke( $a ), sprintf( '%02d an unanticipated value %s is NOT an exemption', 22 + $k, "'{$value}'" ) );
	}

	// A malformed value must not become an exemption either: for a flag whose
	// true value REMOVES a restriction, "unrecognised" has to mean off.
	$GLOBALS['__faz_opt']['faz_settings'] = array(
		'script_blocking' => array( 'payment_gateways' => array( 'stripe' => array( 'x' ) ) ),
	);
	ok( false === $has->invoke( $a ), '16 an array value reads as NOT enabled' );

	// (int) 1.5 and (int) 1.9 are both 1, so a fractional value truncated its way
	// into "yes" and exempted the gateway. Nothing legitimately stores 1.9 here —
	// which is the point: an unintended value must not round toward permission.
	foreach ( array( 1.5, 1.9, 0.9, 2, -1 ) as $k => $value ) {
		$GLOBALS['__faz_opt']['faz_settings'] = array(
			'script_blocking' => array( 'payment_gateways' => array( 'stripe' => $value ) ),
		);
		ok( false === $has->invoke( $a ), sprintf( '%02d numeric %s is NOT an exemption', 28 + $k, var_export( $value, true ) ) );
	}
	foreach ( array( 1, 1.0 ) as $k => $value ) {
		$GLOBALS['__faz_opt']['faz_settings'] = array(
			'script_blocking' => array( 'payment_gateways' => array( 'stripe' => $value ) ),
		);
		ok( true === $has->invoke( $a ), sprintf( '%02d numeric %s still is', 33 + $k, var_export( $value, true ) ) );
	}

	// And the affirmative side still works, so the above is not passing by
	// simply refusing everything.
	foreach ( array( 'yes', 'true', '1', 1, true ) as $j => $value ) {
		$GLOBALS['__faz_opt']['faz_settings'] = array(
			'script_blocking' => array( 'payment_gateways' => array( 'stripe' => $value ) ),
		);
		ok( true === $has->invoke( $a ), sprintf( '%02d stored %s still reads as enabled', 17 + $j, var_export( $value, true ) ) );
	}

	echo "\nPassed: $passed\nFailed: $failed\n";
	if ( $failed > 0 ) {
		echo "FAIL\n";
		exit( 1 );
	}
	echo "PASS\n";
	exit( 0 );
}
