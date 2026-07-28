<?php
/**
 * Unit tests for the CCPA opt-out / rescind CSRF hardening.
 *
 * Subsystem: dnsmpi-csrf
 *
 * The unauthenticated wp_ajax_nopriv_faz_dnsmpi_optout / _rescind handlers are
 * guarded by a nonce that is identical for every anonymous visitor and rendered
 * into the public shortcode page, so it is spam hardening, not a CSRF control.
 * Do_Not_Sell_Shortcode::is_same_origin_request() is the actual CSRF gate: it
 * must accept a same-origin submission and reject a cross-site replay.
 *
 * Pure-logic: no browser, no DB, no live WordPress. The class is built with
 * ReflectionClass::newInstanceWithoutConstructor() (skips add_action wiring) and
 * the private method is invoked by reflection under controlled $_SERVER state.
 *
 * Run: php tests/unit/test-dnsmpi-csrf-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
		}
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) {
			return is_string( $value ) ? stripslashes( $value ) : $value;
		}
	}
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( $path = '' ) {
			return 'https://example.test' . $path;
		}
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			return parse_url( (string) $url, $component );
		}
	}
	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) {
			return $url;
		}
	}

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

	$obj    = ( new ReflectionClass( Do_Not_Sell_Shortcode::class ) )->newInstanceWithoutConstructor();
	$method = new ReflectionMethod( Do_Not_Sell_Shortcode::class, 'is_same_origin_request' );
	$method->setAccessible( true );

	// Helper: set $_SERVER to a known state and evaluate the gate.
	$same_origin = function ( array $server ) use ( $obj, $method ) {
		$_SERVER = array_merge(
			array_diff_key( $_SERVER, array( 'HTTP_SEC_FETCH_SITE' => 1, 'HTTP_ORIGIN' => 1, 'HTTP_REFERER' => 1 ) ),
			$server
		);
		return (bool) $method->invoke( $obj );
	};

	echo "== is_same_origin_request (CCPA opt-out/rescind CSRF gate, #F2) ==\n";

	// --- Fetch Metadata path (modern browsers): STRICT same-origin only ---
	check( true === $same_origin( array( 'HTTP_SEC_FETCH_SITE' => 'same-origin' ) ), '01 Sec-Fetch-Site: same-origin -> allowed' );
	check( false === $same_origin( array( 'HTTP_SEC_FETCH_SITE' => 'same-site' ) ), '02 Sec-Fetch-Site: same-site -> REJECTED (sibling subdomain could replay the public nonce)' );
	check( false === $same_origin( array( 'HTTP_SEC_FETCH_SITE' => 'none' ) ), '03 Sec-Fetch-Site: none -> REJECTED (a POST can never be a user-initiated navigation)' );
	check( false === $same_origin( array( 'HTTP_SEC_FETCH_SITE' => 'cross-site' ) ), '04 Sec-Fetch-Site: cross-site -> REJECTED (the CSRF case)' );

	// --- Origin fallback (no Sec-Fetch-Site): scheme + host + port must all match ---
	check( true === $same_origin( array( 'HTTP_ORIGIN' => 'https://example.test' ) ), '05 no Sec-Fetch-Site, Origin == site origin -> allowed' );
	check( false === $same_origin( array( 'HTTP_ORIGIN' => 'https://evil.example.com' ) ), '06 no Sec-Fetch-Site, attacker Origin -> REJECTED' );
	check( false === $same_origin( array( 'HTTP_ORIGIN' => 'https://example.test.evil.com' ) ), '07 look-alike host (example.test.evil.com) -> REJECTED' );
	check( false === $same_origin( array( 'HTTP_ORIGIN' => 'https://sub.example.test' ) ), '07b sibling subdomain Origin -> REJECTED (host-only match would let this through)' );
	check( false === $same_origin( array( 'HTTP_ORIGIN' => 'http://example.test' ) ), '07c scheme mismatch (http vs https site) -> REJECTED' );
	check( false === $same_origin( array( 'HTTP_ORIGIN' => 'https://example.test:8443' ) ), '07d port mismatch -> REJECTED' );
	check( true === $same_origin( array( 'HTTP_ORIGIN' => 'https://example.test:443' ) ), '07e explicit default port equals implicit -> allowed' );

	// --- Referer fallback (no Sec-Fetch-Site, no Origin) ---
	check( true === $same_origin( array( 'HTTP_REFERER' => 'https://example.test/privacy/' ) ), '08 no Origin, Referer origin == site origin -> allowed' );
	check( false === $same_origin( array( 'HTTP_REFERER' => 'https://evil.example.com/attack' ) ), '09 no Origin, attacker Referer -> REJECTED' );
	check( false === $same_origin( array( 'HTTP_REFERER' => 'https://sub.example.test/privacy/' ) ), '09b sibling-subdomain Referer -> REJECTED' );

	// --- No cross-origin signal at all -> reject (cannot prove same-origin) ---
	check( false === $same_origin( array() ), '10 no Sec-Fetch-Site / Origin / Referer -> REJECTED' );

	// --- Sec-Fetch-Site wins over a spoofed same-origin Referer on a cross-site POST ---
	check(
		false === $same_origin( array( 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_REFERER' => 'https://example.test/' ) ),
		'11 cross-site Fetch-Site is honoured even with a site-looking Referer'
	);

	echo "\nPassed: $passed\nFailed: $failed\n";
	if ( $failed > 0 ) {
		echo "FAIL\n";
		exit( 1 );
	}
	echo "ALL PASS\n";
	exit( 0 );
}
