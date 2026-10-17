<?php
/**
 * No `Vary: Accept-Language` for HTML that does not vary by it.
 *
 * The server renders the banner in the site default (or URL) language and the
 * browser language is applied client-side, so every Accept-Language spelling
 * gets the same document. Announcing the header split each cached URL into one
 * entry per spelling on every cache that honours Vary. The header is now only
 * sent when `faz_send_vary_header` asks for it.
 *
 * Run: php tests/unit/test-vary-accept-language-php.php
 */
namespace FazCookie\Frontend {
	function header( $line, $replace = true ) {
		$GLOBALS['vary_headers'][] = $line;
	}
	function headers_sent() {
		return false;
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['vary_headers'] = array();
	$GLOBALS['vary_filters'] = array();

	function apply_filters( $tag, $value ) {
		return array_key_exists( $tag, $GLOBALS['vary_filters'] ) ? $GLOBALS['vary_filters'][ $tag ] : $value;
	}
	function is_admin() { return false; }
	function wp_doing_ajax() { return false; }
	function wp_doing_cron() { return false; }
	function faz_disable_banner() { return false; }
	// Browser-language detection active: no multilingual plugin, 2+ languages.
	function faz_browser_detect_enabled() { return true; }

	require dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	$passed = 0;
	$failed = 0;
	function vary_check( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) { $passed++; echo "  PASS {$label}\n"; return; }
		$failed++;
		echo "  FAIL {$label}\n";
	}
	function vary_sent() {
		foreach ( $GLOBALS['vary_headers'] as $line ) {
			if ( 0 === stripos( $line, 'Vary:' ) && false !== stripos( $line, 'Accept-Language' ) ) {
				return true;
			}
		}
		return false;
	}

	$frontend = ( new ReflectionClass( \FazCookie\Frontend\Frontend::class ) )->newInstanceWithoutConstructor();

	$frontend->send_vary_header();
	vary_check( ! vary_sent(), 'browser-language detection on: no Vary: Accept-Language by default' );

	$GLOBALS['vary_filters']['faz_send_vary_header'] = true;
	$GLOBALS['vary_headers'] = array();
	$frontend->send_vary_header();
	vary_check( vary_sent(), 'faz_send_vary_header=true still announces it' );

	echo "vary-accept-language: {$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
