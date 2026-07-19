<?php
/**
 * Standalone unit tests for the 1.19.0 release follow-up fixes (backend), plus
 * the per-gateway payment-SDK opt-in that replaced the hardcoded gateway
 * allow-list.
 *
 * Subsystem: followups-1190
 *
 * Covers two private Frontend helpers:
 *   - Frontend::is_always_allowed_gateway_pattern()  (forward match always;
 *     reverse substring match guarded by a >=4-char minimum needle). Its source,
 *     get_always_allowed_gateway_patterns(), is no longer a hardcoded Stripe set:
 *     a payment SDK is exempt from consent blocking ONLY when the site owner
 *     opted that gateway in (Settings → Script Blocking → payment_gateways) or on
 *     a WooCommerce checkout/cart page. A payment SDK can track, so loading it
 *     before consent is never automatic. A gateway's marketing pixel
 *     (paypal.com/tagmanager/pptm.js) is never exempted.
 *   - Frontend::compute_whitelisted_cookie_patterns() (uses the exact same
 *     context-aware gateway decision as script blocking: explicit gateway
 *     opt-in, resolved WooCommerce checkout/cart, and developer filters).
 *
 * Pure-logic: no browser, no DB, no live WordPress. Frontend is built with
 * ReflectionClass::newInstanceWithoutConstructor(); the settings option is
 * seeded via the reflection-accessible settings_option_cache so get_option is
 * never hit; WooCommerce conditionals are controllable globals.
 * Known_Providers is replaced with a controllable double.
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
	$GLOBALS['__faz_checkout'] = false;
	$GLOBALS['__faz_gateway_filter_extra'] = array();

	class WooCommerce {}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			if ( 'faz_always_allowed_gateway_patterns' === $tag ) {
				return array_merge( (array) $value, $GLOBALS['__faz_gateway_filter_extra'] );
			}
			return $value;
		}
	}
	if ( ! function_exists( 'is_checkout' ) ) {
		function is_checkout() {
			return ! empty( $GLOBALS['__faz_checkout'] );
		}
	}
	if ( ! function_exists( 'is_cart' ) ) {
		function is_cart() {
			return false;
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		// Never reached in practice (settings_option_cache is seeded), but a safe
		// fallback so a stray call can't fatal.
		function get_option( $name, $default = false ) {
			return $default;
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

	$settings_prop = new ReflectionProperty( Frontend::class, 'settings_option_cache' );
	$settings_prop->setAccessible( true );
	$cache_prop = new ReflectionProperty( Frontend::class, 'always_allowed_cache' );
	$cache_prop->setAccessible( true );

	// Seed the enabled-gateway opt-in map and reset the per-request gateway cache.
	$set_gateways = function ( array $enabled ) use ( $settings_prop, $cache_prop, $fe ) {
		$settings_prop->setValue( $fe, array( 'script_blocking' => array( 'payment_gateways' => $enabled ) ) );
		$cache_prop->setValue( $fe, null );
	};
	$set_checkout = function ( $enabled ) use ( $cache_prop, $fe ) {
		$GLOBALS['__faz_checkout'] = (bool) $enabled;
		$cache_prop->setValue( $fe, null );
	};
	$set_gateway_filter = function ( array $patterns ) use ( $cache_prop, $fe ) {
		$GLOBALS['__faz_gateway_filter_extra'] = $patterns;
		$cache_prop->setValue( $fe, null );
	};

	$is_gateway = $ref->getMethod( 'is_always_allowed_gateway_pattern' );
	$is_gateway->setAccessible( true );
	$gw = function ( $pattern ) use ( $is_gateway, $cache_prop, $fe ) {
		$cache_prop->setValue( $fe, null );
		return (bool) $is_gateway->invoke( $fe, $pattern );
	};

	$compute = $ref->getMethod( 'compute_whitelisted_cookie_patterns' );
	$compute->setAccessible( true );
	$wl = function ( $user_whitelist, $valid_categories ) use ( $compute, $fe ) {
		return $compute->invoke( $fe, $user_whitelist, $valid_categories );
	};

	echo "== is_always_allowed_gateway_pattern (per-gateway opt-in) ==\n";

	// Default: no gateway opted in, not a WooCommerce checkout → NOTHING is
	// always-allowed. A payment SDK stays blocked until consent (compliant).
	$set_checkout( false );
	$set_gateway_filter( array() );
	$set_gateways( array() );
	check( false === $gw( 'js.stripe.com' ), '01 no gateway opted in → Stripe SDK is NOT always-allowed' );
	check( false === $gw( 'paypal.com/sdk/js' ), '02 no gateway opted in → PayPal SDK is NOT always-allowed' );

	// Stripe opted in → its scripts are exempt; unrelated scripts are not.
	$set_gateways( array( 'stripe' => true ) );
	check( true === $gw( 'js.stripe.com' ), '03 Stripe opted in → js.stripe.com is always-allowed' );
	check( true === $gw( 'wc-stripe' ), '04 reverse match (>=4): wc-stripe is inside allowed wc-stripe-' );
	check( false === $gw( 'com' ), '05 reverse rejected (<4): com does NOT over-match a gateway URL' );
	check( false === $gw( 'paypal.com/sdk/js' ), '06 Stripe opt-in does NOT also allow PayPal' );

	// PayPal opted in → the checkout SDK is exempt, the MARKETING pixel is not.
	$set_gateways( array( 'paypal' => true ) );
	check(
		true === $gw( 'https://www.paypal.com/sdk/js?client-id=X&currency=EUR' ),
		'07 PayPal opted in → paypal.com/sdk/js is always-allowed'
	);
	check(
		false === $gw( 'https://www.paypal.com/tagmanager/pptm.js' ),
		'08 PayPal marketing pixel (pptm.js) is NEVER always-allowed'
	);

	// No relation to any gateway token, regardless of opt-in.
	check( false === $gw( 'google-analytics' ), '09 non-gateway (google-analytics) is never always-allowed' );

	echo "== compute_whitelisted_cookie_patterns (gateway coupling) ==\n";

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
		'patterns' => array( 'js.stripe.com' ),
		'cookies'  => array( 'sess_x' ),
	);

	$valid = array( 'marketing', 'analytics' );

	// No opt-in and no checkout: gateway cookies are not protected. A persistent
	// cookie from a previous checkout/consent can therefore be removed when the
	// visitor revokes the functional category/service.
	$set_gateways( array() ); // no gateway opted in, not a WooCommerce checkout
	$GLOBALS['__faz_providers'] = array( $stripe, $ga );
	$res = $wl( array(), $valid );
	check(
		! in_array( '__stripe_mid', $res, true ) && ! in_array( '__stripe_sid', $res, true ),
		'10 no gateway opt-in and no checkout → Stripe cookies are NOT exempt'
	);
	check(
		! in_array( '_ga', $res, true ) && ! in_array( '_gid', $res, true ),
		'11 non-gateway provider (_ga/_gid) NOT exempt'
	);

	// Opting the gateway in protects the same cookies as the allowed SDK.
	$set_gateways( array( 'stripe' => true ) );
	$GLOBALS['__faz_providers'] = array( $stripe );
	$res = $wl( array(), $valid );
	check( in_array( '__stripe_mid', $res, true ), '12 Stripe opt-in → Stripe cookies are exempt' );

	// On a resolved WooCommerce checkout the gateway is strictly necessary even
	// without the global admin opt-in. template_redirect runs after this context
	// is available, so server shredding and frontend config agree.
	$set_gateways( array() );
	$set_checkout( true );
	$res = $wl( array(), $valid );
	check( in_array( '__stripe_mid', $res, true ), '13 WooCommerce checkout → Stripe cookies are exempt for this request' );
	$set_checkout( false );

	// Developer extensions must remain symmetric: if a custom gateway pattern is
	// allowed for scripts through the public filter, its declared cookies are
	// protected by the same decision.
	$custom_gateway = array(
		'category' => 'functional',
		'patterns' => array( 'custompay.example/sdk' ),
		'cookies'  => array( 'custompay_session' ),
	);
	$set_gateway_filter( array( 'custompay.example/sdk' ) );
	$GLOBALS['__faz_providers'] = array( $custom_gateway );
	$res = $wl( array(), array( 'functional' ) );
	check( in_array( 'custompay_session', $res, true ), '14 developer-filter gateway keeps script/cookie whitelist parity' );
	$set_gateway_filter( array() );

	// The admin user-whitelist path still works (token matches a provider pattern).
	$set_gateways( array() );
	$GLOBALS['__faz_providers'] = array( $fb );
	$res = $wl( array( 'fbevents' ), $valid );
	check( in_array( '_fbp', $res, true ), '15 user whitelist token fbevents whitelists _fbp' );

	// A necessary-category provider is always skipped (even on a gateway match).
	$GLOBALS['__faz_providers'] = array( $necessary );
	$res = $wl( array(), $valid );
	check( ! in_array( 'sess_x', $res, true ) && array() === $res, '16 necessary-category provider excluded' );

	echo "\nPassed: $passed\nFailed: $failed\n";
	if ( $failed > 0 ) {
		echo "FAIL\n";
		exit( 1 );
	}
	echo "ALL PASS\n";
	exit( 0 );
}
