<?php
/**
 * Regression tests for the anonymous consent-log REST endpoint's CSRF gate.
 *
 * The cache-compatible log token is visible in rendered HTML, so it cannot be
 * treated as a same-origin credential. The production handler must also reject
 * a cross-origin browser request before it considers the token or writes a log.
 *
 * Run: php tests/unit/test-consent-logger-csrf-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $key ) ); }
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
	}
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( $path = '' ) { return 'https://example.test' . $path; }
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url ) { return parse_url( (string) $url ); }
	}
	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) { return (string) $url; }
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/modules/consent-logger/class-consent-logger.php';

	use FazCookie\Frontend\Modules\Consent_Logger\Consent_Logger;

	$passed = 0;
	$failed = 0;
	function consent_logger_check( $actual, $label ) {
		global $passed, $failed;
		if ( $actual ) {
			$passed++;
			echo "  [PASS] {$label}\n";
		} else {
			$failed++;
			echo "  [FAIL] {$label}\n";
		}
	}

	$logger = ( new \ReflectionClass( Consent_Logger::class ) )->newInstanceWithoutConstructor();
	$method = new \ReflectionMethod( Consent_Logger::class, 'is_same_origin_request' );
	$method->setAccessible( true );
	$check = function ( array $headers ) use ( $logger, $method ) {
		$_SERVER = $headers;
		return (bool) $method->invoke( $logger );
	};

	echo "== Consent logger same-origin gate ==\n";
	consent_logger_check( $check( array( 'HTTP_SEC_FETCH_SITE' => 'same-origin' ) ), 'Fetch Metadata same-origin is accepted' );
	consent_logger_check( ! $check( array( 'HTTP_SEC_FETCH_SITE' => 'cross-site' ) ), 'Fetch Metadata cross-site is rejected' );
	consent_logger_check( ! $check( array( 'HTTP_SEC_FETCH_SITE' => 'same-site' ) ), 'sibling subdomain request is rejected' );
	consent_logger_check( $check( array( 'HTTP_ORIGIN' => 'https://example.test' ) ), 'matching Origin is accepted' );
	consent_logger_check( ! $check( array( 'HTTP_ORIGIN' => 'https://evil.example.test' ) ), 'foreign Origin is rejected' );
	consent_logger_check( ! $check( array( 'HTTP_ORIGIN' => 'http://example.test' ) ), 'different scheme is rejected' );
	consent_logger_check( $check( array( 'HTTP_REFERER' => 'https://example.test/privacy/' ) ), 'matching Referer fallback is accepted' );
	consent_logger_check( ! $check( array() ), 'missing provenance headers are rejected' );

	echo "Passed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
