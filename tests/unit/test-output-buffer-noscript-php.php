<?php
/**
 * Standalone unit tests for the output buffer's <noscript> handling and the
 * own-asset guards in process_script_tag() / process_link_tag().
 *
 * Subsystem: output-buffer-noscript-php
 *
 * Pins the merged single-pass <noscript> pipeline (perf refactor of the
 * former two-pass pixel-process + stash sequence) AND the URL-pattern fix:
 * historically match_script_to_provider() was called with empty attrs for
 * <noscript> content, so URL-fragment patterns (www.facebook.com/tr …)
 * never gated the pixel beacons the pass exists for — only code-signature
 * patterns did. The fix matches each embedded <img>/<iframe>'s own tag
 * attributes, the same way the script/iframe filters treat regular tags.
 *   1. URL-pattern pixels inside <noscript> ARE now consent-gated
 *      (src → data-faz-src + data-faz-category), wrapper preserved.
 *   2. Code-signature matches keep gating as before.
 *   3. <noscript>-wrapped iframes are protected from process_iframe_tag —
 *      no phantom consent placeholders — but blocked-provider iframes are
 *      gated IN PLACE inside the wrapper.
 *   4. Non-tracking and whitelisted <noscript> content passes through
 *      byte-identical.
 *   5. Blocked provider <script src> outside <noscript> is still neutralised
 *      to type="text/plain" (the merge must not affect script gating).
 *   6. The plugin's own asset tags (id="<handle>-js" / "<handle>-css") are
 *      never gated by provider patterns — the content-hashed static config
 *      and banner CSS served from uploads must be immune by construction.
 *
 * Run: php tests/unit/test-output-buffer-noscript-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	// Double for the `use FazCookie\Includes\Known_Providers` alias inside
	// class-frontend.php — no autoload, no JSON read.
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

	// ---------- Bootstrap ----------

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
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $value ) {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); // phpcs:ignore
		}
	}
	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			return false;
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $ttl = 0 ) {
			return true;
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	// ---------- assert helpers ----------

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;

	function assert_true( $cond, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $cond ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m " . $label . "\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m " . $label . "\n";
		}
	}

	// ---------- reflection harness ----------

	function faz_ob_frontend( array $providers, array $blocked, array $whitelist = array() ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		$preset = array(
			'blocked_categories_cache'   => $blocked,
			'provider_map_cache'         => $providers,
			'service_consent_cache'      => array(), // per-service consent off
			'whitelist_cache'            => $whitelist,
			'settings_option_cache'      => array(),
			'pattern_service_cache'      => array(),
			'provider_match_meta_cache'  => null,
			'provider_match_meta_source' => null,
			'service_match_meta_cache'   => null,
		);
		foreach ( $preset as $prop => $value ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $fe, $value );
		}
		return $fe;
	}

	function faz_ob_run( $fe, $html ) {
		$m = new ReflectionMethod( Frontend::class, 'process_output_buffer' );
		$m->setAccessible( true );
		return $m->invoke( $fe, $html );
	}

	$providers = array(
		'connect.facebook.net' => 'marketing',
		'www.facebook.com/tr'  => 'marketing',
		'youtube.com/embed'    => 'marketing',
		'fbq('                 => 'marketing',
	);
	$blocked   = array( 'marketing' );

	echo "\n-- merged <noscript> pass: gating + stash/restore --\n";

	// 1. URL-fragment patterns now gate noscript pixel beacons (the fix):
	//    the embedded <img>'s own attributes are matched, wrapper preserved.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body>'
		. '<noscript><img height="1" width="1" src="https://www.facebook.com/tr?id=123&ev=PageView"/></noscript>'
		. '</body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( false !== strpos( $out, 'data-faz-src="https://www.facebook.com/tr?id=123&ev=PageView"' ), 'URL-pattern noscript pixel src rewritten to data-faz-src' );
	assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), 'URL-pattern noscript pixel carries data-faz-category' );
	assert_true( false !== strpos( $out, '<noscript>' ), 'noscript wrapper preserved' );
	assert_true( false === strpos( $out, '__FAZ_NOSCRIPT_STASH_' ), 'stash markers restored' );

	// 2. A code-signature match keeps gating as before.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body>'
		. '<noscript><img src="https://tracker.example/t.gif" alt="fbq(pixel)"/></noscript>'
		. '</body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( false !== strpos( $out, 'data-faz-src="https://tracker.example/t.gif"' ), 'signature-matched pixel src rewritten to data-faz-src' );
	assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), 'signature-matched pixel carries data-faz-category' );
	assert_true( false === strpos( $out, '__FAZ_NOSCRIPT_STASH_' ), 'stash markers restored after gating' );

	// 3. <noscript>-wrapped blocked-provider iframe: no consent placeholder
	//    (stash protection intact) but gated IN PLACE inside the wrapper.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body>'
		. '<noscript><iframe src="https://youtube.com/embed/abc"></iframe></noscript>'
		. '</body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( false !== strpos( $out, '<noscript><iframe data-faz-category="marketing" data-faz-src="https://youtube.com/embed/abc">' ), 'blocked noscript iframe gated in place, no placeholder' );
	assert_true( false === strpos( $out, 'data-ph' ), 'no phantom placeholder injected' );

	// 3b. Non-tracking <noscript> iframe passes through byte-identical.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body>'
		. '<noscript><iframe src="https://example.com/plain-fallback"></iframe></noscript>'
		. '</body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'non-tracking noscript iframe passes through byte-identical' );

	// 3c. Whitelisted resource is not gated by the new URL pass.
	$fe   = faz_ob_frontend( $providers, $blocked, array( 'www.facebook.com/tr' ) );
	$html = '<html><body>'
		. '<noscript><img src="https://www.facebook.com/tr?id=5"/></noscript>'
		. '</body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'whitelisted noscript pixel passes through' );

	// 4. Multiple <noscript> blocks: processed and restored in order.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<p>a</p>'
		. '<noscript><img src="https://www.facebook.com/tr?id=1"/></noscript>'
		. '<p>b</p>'
		. '<noscript><span>plain text fallback</span></noscript>'
		. '<p>c</p>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( false !== strpos( $out, 'data-faz-src="https://www.facebook.com/tr?id=1"' ), 'first noscript gated' );
	assert_true( false !== strpos( $out, '<noscript><span>plain text fallback</span></noscript>' ), 'second noscript untouched' );
	assert_true( 1 === preg_match( '/a.+facebook.+b.+plain text fallback.+c/s', $out ), 'document order preserved' );

	echo "\n-- script gating unaffected by the merge --\n";

	// 5. Blocked provider script outside <noscript> still neutralised.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( false !== strpos( $out, 'type="text/plain"' ), 'blocked script src neutralised to text/plain' );
	assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), 'blocked script tagged with category' );

	// 6. Inline code-signature match still gates the script.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<script>fbq("init","123");fbq("track","PageView");</script>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( false !== strpos( $out, 'type="text/plain"' ), 'inline signature match neutralised' );

	// 7. Unrelated scripts pass untouched.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<script src="https://example.com/app.js"></script><script>var x=1;</script>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'unmatched scripts pass through byte-identical' );

	echo "\n-- own-asset guards --\n";

	// 8. Own static-config script is immune even when a provider pattern
	//    would substring-match its URL.
	$evil_providers = array( 'faz-cookie-manager/assets' => 'marketing' );
	$fe   = faz_ob_frontend( $evil_providers, $blocked );
	$html = '<script src="https://example.com/wp-content/uploads/faz-cookie-manager/assets/config-abc.js" id="faz-cookie-manager-static-config-js"></script>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'own static-config script never gated' );

	// 9. Same URL WITHOUT an own id is gated (guard is id-scoped, not URL-scoped).
	$fe   = faz_ob_frontend( $evil_providers, $blocked );
	$html = '<script src="https://example.com/wp-content/uploads/faz-cookie-manager/assets/config-abc.js"></script>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( false !== strpos( $out, 'type="text/plain"' ), 'foreign tag with matching URL still gated' );

	// 10. Own banner stylesheet is immune in process_link_tag.
	$fe   = faz_ob_frontend( $evil_providers, $blocked );
	$html = '<link rel="stylesheet" id="faz-cookie-manager-css-css" href="https://example.com/wp-content/uploads/faz-cookie-manager/assets/banner-abc.css"/>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'own banner stylesheet never gated' );

	// ---------- result ----------

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "ALL PASS ({$tests_passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$tests_failed}, passed: {$tests_passed}\n";
	exit( 1 );
}
