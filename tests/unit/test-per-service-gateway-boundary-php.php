<?php
/**
 * Standalone unit tests for the per-service GATEWAY exemption and provider URL
 * BOUNDARY matching (server side).
 *
 * Subsystem: per-service-gateway-boundary-php
 *
 * These two pure helpers guard two launch-critical correctness properties that
 * are NOT covered elsewhere:
 *
 *   - Frontend::is_always_allowed_gateway_pattern()
 *       A payment-gateway provider (Stripe) must stay exempt from blocking /
 *       shredding so checkout keeps working, WITHOUT a short or unrelated
 *       custom pattern accidentally exempting itself (false-positive guard).
 *
 *   - Frontend::provider_url_pattern_matches() / has_provider_boundary()
 *       A provider pattern must match only at a real token boundary, so
 *       "youtube.com" never matches inside "notyoutube.com" and
 *       "facebook.net" never matches inside "fakefacebook.net". This PHP set is
 *       the mirror of the JS _fazHasProviderBoundary (see the jsdom parity test
 *       tests/unit/js/per-service-boundary-ckkey.test.mjs).
 *
 * Pure-logic tests: no browser, DB, or live WordPress. The Frontend object is
 * built with ReflectionClass::newInstanceWithoutConstructor() and the private
 * methods are invoked via reflection (same pattern as test-percookie-php.php).
 *
 * Run from project root:
 *   php tests/unit/test-per-service-gateway-boundary-php.php
 *
 * Exit code 0 = all tests pass; 1 = at least one failure.
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	class Known_Providers {
		public static function get_all() {
			return array();
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
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $str ) {
			return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			$str = (string) $str;
			$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
			return trim( wp_strip_all_tags( $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}

	if ( ! class_exists( 'FazTest_WPDB' ) ) {
		class FazTest_WPDB {
			public $prefix = 'wp_';
			public function get_col( $query ) {
				return array();
			}
		}
	}
	$GLOBALS['wpdb'] = new FazTest_WPDB();

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;

	function assert_eq( $actual, $expected, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $actual === $expected ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m " . $label . "\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m " . $label . "\n";
			echo '      expected: ' . var_export( $expected, true ) . "\n";
			echo '      actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	function faz_new_frontend() {
		$rc = new ReflectionClass( Frontend::class );
		return $rc->newInstanceWithoutConstructor();
	}

	function faz_call( $fe, $method, array $args = array() ) {
		$m = new ReflectionMethod( Frontend::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $fe, $args );
	}

	echo "\n\033[1mPer-service gateway exemption + provider URL boundary\033[0m\n\n";
	$fe = faz_new_frontend();

	// ---------------------------------------------------------------------
	// is_always_allowed_gateway_pattern(): Stripe stays exempt, unrelated /
	// short patterns must NOT silently exempt themselves.
	// ---------------------------------------------------------------------
	echo "is_always_allowed_gateway_pattern()\n";
	$gw = function ( $pattern ) use ( $fe ) {
		return faz_call( $fe, 'is_always_allowed_gateway_pattern', array( $pattern ) );
	};

	// Stripe's own provider patterns must be exempt (checkout must keep working).
	assert_eq( $gw( 'js.stripe.com' ), true, 'exact gateway token js.stripe.com is exempt' );
	assert_eq( $gw( 'wc-stripe-blocks' ), true, 'forward match: pattern containing wc-stripe- is exempt' );
	// Reverse match: a provider pattern that is itself a substring of a gateway
	// token (>= 4 chars) is exempt — "stripe.com" lives inside "js.stripe.com".
	assert_eq( $gw( 'stripe.com' ), true, 'reverse match: stripe.com (inside js.stripe.com) is exempt' );

	// False-positive guards — the launch risk: an unrelated/custom provider must
	// NOT inherit Stripe's exemption and skip blocking/shredding.
	assert_eq( $gw( 'notstripe.example' ), false, 'unrelated provider notstripe.example is NOT exempt' );
	assert_eq( $gw( 'analytics.example.com' ), false, 'unrelated analytics provider is NOT exempt' );
	// 3-char needle is below the reverse-match minimum (4) even though "upe" is a
	// substring of "stripe-upe": a generic 3-char pattern must never exempt.
	assert_eq( $gw( 'upe' ), false, 'short 3-char pattern (upe) does NOT exempt despite being inside stripe-upe' );
	assert_eq( $gw( '' ), false, 'empty pattern is never exempt' );

	// ---------------------------------------------------------------------
	// provider_url_pattern_matches(): match only at real token boundaries.
	// Mirror of JS _fazHasProviderBoundary (jsdom parity test).
	// ---------------------------------------------------------------------
	echo "\nprovider_url_pattern_matches()\n";
	$m = function ( $target, $pattern ) use ( $fe ) {
		return faz_call( $fe, 'provider_url_pattern_matches', array( $target, $pattern ) );
	};

	// Legitimate matches.
	assert_eq( $m( 'https://www.youtube.com/embed/abc', 'youtube.com' ), true, 'youtube.com matches in a real youtube embed URL' );
	assert_eq( $m( 'https://accounts.youtube.com/login', 'youtube.com' ), true, 'youtube.com matches a sub-domain host' );
	assert_eq( $m( 'connect.facebook.net/en_US/fbevents.js', 'facebook.net' ), true, 'facebook.net matches in a pixel URL' );
	assert_eq( $m( 'connect.facebook.net', 'facebook.net' ), true, 'match at end-of-string (after-guard skipped) is allowed' );

	// False positives that MUST be rejected (the whole point of boundary checks).
	assert_eq( $m( 'https://notyoutube.com/evil', 'youtube.com' ), false, 'youtube.com is NOT matched inside notyoutube.com' );
	assert_eq( $m( 'https://fakefacebook.net/evil', 'facebook.net' ), false, 'facebook.net is NOT matched inside fakefacebook.net' );
	// youtube-nocookie.com does not literally contain "youtube.com" → no match;
	// and the dedicated youtube-nocookie.com pattern matches it correctly.
	assert_eq( $m( 'https://www.youtube-nocookie.com/embed/abc', 'youtube.com' ), false, 'youtube.com does NOT match a youtube-nocookie.com URL' );
	assert_eq( $m( 'https://www.youtube-nocookie.com/embed/abc', 'youtube-nocookie.com' ), true, 'youtube-nocookie.com matches its own URL' );

	echo "\n";
	if ( $tests_failed > 0 ) {
		echo "\033[31m✗ {$tests_failed} failed\033[0m, {$tests_passed} passed ({$tests_run} total)\n";
		exit( 1 );
	}
	echo "\033[32m✓ all {$tests_passed} passed\033[0m ({$tests_run} total)\n";
	exit( 0 );
}
