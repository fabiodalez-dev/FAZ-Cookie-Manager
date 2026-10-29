<?php
/**
 * Regression tests for the anonymous consent-log REST endpoint's CSRF gate.
 *
 * The cache-compatible log token is visible in rendered HTML, so it cannot be
 * treated as a same-origin credential. The production handler must also reject
 * a cross-origin browser request before it considers the token or writes a log.
 *
 * Since the gate moved to faz_is_same_origin_request() (includes/class-utils.php)
 * it also accepts the origins of faz_trusted_origins(): the domains on which a
 * translation proxy such as GTranslate serves this same site. The proxy
 * forwards the browser's same-origin POST without Sec-Fetch-Site and with
 * `Origin: https://<translated domain>`, and every one of those consents was
 * refused as `cross_origin` (wp.org topic "Cookie Banner Not Working on
 * GTranslate Translation Proxy Domains").
 *
 * Run: php tests/unit/test-consent-logger-csrf-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	$GLOBALS['faz_test_filters'] = array();
	$GLOBALS['faz_test_options'] = array();
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $key ) ); }
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) { return trim( (string) $value ); }
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
	}
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( $path = '' ) { return 'https://example.test' . $path; }
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
	}
	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) { return (string) $url; }
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['faz_test_options'] ) ? $GLOBALS['faz_test_options'][ $name ] : $default; }
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value, ...$args ) {
			return isset( $GLOBALS['faz_test_filters'][ $hook ] ) ? call_user_func( $GLOBALS['faz_test_filters'][ $hook ], $value, ...$args ) : $value;
		}
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
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

	echo "\n== Trusted alternate domains (translation proxy / mirror) ==\n";
	// Nothing trusted yet: the translated domain is refused exactly as before.
	consent_logger_check( ! $check( array( 'HTTP_ORIGIN' => 'https://example.de' ) ), 'an untrusted translated domain is refused (premise)' );

	$GLOBALS['faz_test_filters']['faz_trusted_origins'] = function ( $list ) {
		$list[] = 'Example.DE';                   // Bare host, any case.
		$list[] = 'https://shop.example.pl:8443'; // Full origin: exactly this.
		return $list;
	};
	consent_logger_check( $check( array( 'HTTP_ORIGIN' => 'https://example.de' ) ), 'a trusted host from the filter is accepted on Origin (proxy stripped Sec-Fetch-*)' );
	consent_logger_check( ! $check( array( 'HTTP_ORIGIN' => 'http://example.de' ) ), 'a bare-host entry is https-only on an https site (an http page there can be rewritten on the network path)' );
	consent_logger_check( $check( array( 'HTTP_REFERER' => 'https://example.de/kosik/' ) ), 'a trusted host is accepted on the Referer fallback' );
	consent_logger_check( $check( array( 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://example.de' ) ), 'Sec-Fetch-Site cross-site with a trusted Origin is accepted (non-rewriting mirror)' );
	consent_logger_check( $check( array( 'HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_ORIGIN' => 'https://example.de' ) ), 'Sec-Fetch-Site same-site with a trusted Origin is accepted' );
	consent_logger_check( ! $check( array( 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_REFERER' => 'https://example.de/' ) ), 'cross-site with only a trusted Referer is refused (a browser always sends Origin there)' );
	consent_logger_check( ! $check( array( 'HTTP_SEC_FETCH_SITE' => 'none', 'HTTP_ORIGIN' => 'https://example.de' ) ), 'Sec-Fetch-Site none is refused even with a trusted Origin' );
	consent_logger_check( ! $check( array( 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example' ) ), 'cross-site with an untrusted Origin is still refused' );
	consent_logger_check( ! $check( array( 'HTTP_ORIGIN' => 'https://evil.example' ) ), 'an untrusted Origin is still refused' );
	consent_logger_check( ! $check( array( 'HTTP_ORIGIN' => 'https://sub.example.de' ) ), 'trusting a host does not trust its sub-domains' );
	consent_logger_check( ! $check( array( 'HTTP_ORIGIN' => 'https://example.de.evil.test' ) ), 'a look-alike suffix of a trusted host is refused' );
	consent_logger_check( ! $check( array( 'HTTP_ORIGIN' => 'https://example.de:8443' ) ), 'a bare-host entry does not match a non-default port' );
	consent_logger_check( $check( array( 'HTTP_ORIGIN' => 'https://shop.example.pl:8443' ) ), 'a full-origin entry matches its exact scheme, host and port' );
	consent_logger_check( ! $check( array( 'HTTP_ORIGIN' => 'http://shop.example.pl:8443' ) ), 'a full-origin entry refuses a scheme mismatch' );
	consent_logger_check( ! $check( array( 'HTTP_ORIGIN' => 'https://shop.example.pl' ) ), 'a full-origin entry refuses a port mismatch' );
	consent_logger_check( ! $check( array( 'HTTP_ORIGIN' => 'null' ) ), 'an opaque (null) Origin is refused' );
	consent_logger_check( ! $check( array() ), 'no provenance header at all is still refused with trusted origins configured' );
	unset( $GLOBALS['faz_test_filters']['faz_trusted_origins'] );

	// The setting is read the same way as the filter.
	$GLOBALS['faz_test_options']['faz_settings'] = array( 'trusted_origins' => array( 'alternate_domains' => array( 'example.cz' ) ) );
	consent_logger_check( $check( array( 'HTTP_ORIGIN' => 'https://example.cz' ) ), 'a domain from Settings -> Trusted Alternate Domains is accepted' );
	$GLOBALS['faz_test_filters']['faz_trusted_origins'] = function () { return array(); };
	consent_logger_check( ! $check( array( 'HTTP_ORIGIN' => 'https://example.cz' ) ), 'the filter can remove an entry the setting added' );
	unset( $GLOBALS['faz_test_filters']['faz_trusted_origins'] );
	$GLOBALS['faz_test_options'] = array();

	echo "Passed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
