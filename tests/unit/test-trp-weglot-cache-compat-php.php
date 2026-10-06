<?php
/**
 * Standalone unit tests for the TranslatePress + Weglot branches of
 * faz_current_language() under Cache Compatibility Mode.
 *
 * Subsystem: trp-weglot-cache-compat
 *
 * Background. Cache Compatibility Mode (#158) renders the banner
 * visitor-invariant, so faz_current_language() consults only URL-stable
 * language sources. TranslatePress and Weglot used to be gated out wholesale
 * on the theory that they "resolve language from cookie/session state" — that
 * is not true of either plugin:
 *
 *   - TranslatePress always encodes the language in the URL (non-default
 *     languages in a subdirectory) and derives $TRP_LANGUAGE from that URL via
 *     its class-url-converter; it has no cookie/parameter negotiation mode.
 *   - Weglot resolves the current language from the request URL (subdirectory,
 *     or subdomain on paid plans) through Request_Url_Service_Weglot; there is
 *     no cookie-based resolution.
 *
 * Both are therefore URL-keyed — a URL-keyed page/CDN cache stores one entry
 * per language, exactly like Polylang — so gating them collapsed a TRP/Weglot
 * site's banner to the default language whenever Cache Compatibility Mode was
 * on. faz_trp_language_in_url() / faz_weglot_language_in_url() encode that
 * URL-safety and un-gate both branches. (WPML stays conditional — only its
 * directory/domain modes are URL-keyed — and is covered by
 * test-cache-compatibility-php.php.)
 *
 * Why this is a SEPARATE harness: TRP is detected via the TRP_PLUGIN_VERSION
 * constant, which PHP cannot undefine. Once defined, the TRP branch shadows
 * Weglot and WPML for the rest of the process (it sits earlier in the elseif
 * chain). The assertions below are therefore ordered deliberately —
 * Weglot first (no TRP yet), TRP last — and WPML lives in its own file.
 *
 * Run from project root:
 *   php tests/unit/test-trp-weglot-cache-compat-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['faz_test_options'] = array();
	$GLOBALS['faz_test_filters'] = array();

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) { // phpcs:ignore
			return isset( $GLOBALS['faz_test_options'][ $name ] ) ? $GLOBALS['faz_test_options'][ $name ] : $default;
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) { // phpcs:ignore
			$args = array_slice( func_get_args(), 2 );
			foreach ( $GLOBALS['faz_test_filters'][ $tag ] ?? array() as $callback ) {
				$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
			}
			return $value;
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $tag, $callback ) { // phpcs:ignore
			$GLOBALS['faz_test_filters'][ $tag ][] = $callback;
			return true;
		}
	}
	// faz_selected_languages() sanitises the stored list through these.
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $str ) { // phpcs:ignore
			return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) { // phpcs:ignore
			return trim( wp_strip_all_tags( (string) $str ) );
		}
	}
	if ( ! function_exists( 'faz_sanitize_text' ) ) {
		function faz_sanitize_text( $value ) { // phpcs:ignore
			if ( is_array( $value ) ) {
				return array_map( 'faz_sanitize_text', $value );
			}
			return sanitize_text_field( $value );
		}
	}

	// The one predicate every cache-compat consumer now shares. Required as the
	// REAL file rather than stubbed: a double here would be a second copy of
	// the logic whose whole point is that there is only one.
	require_once dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-i18n-helpers.php';

	$tests_run = $tests_passed = $tests_failed = 0;
	function assert_eq( $actual, $expected, $label ) { // phpcs:ignore
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $actual === $expected ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m $label\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m $label\n";
			echo '      expected: ' . var_export( $expected, true ) . "\n";
			echo '      actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	/** Seed faz_settings with an EN+IT install and the given cache-compat state. */
	function faz_seed_settings( $cache_compat ) { // phpcs:ignore
		$GLOBALS['faz_test_options']['faz_settings'] = array(
			'languages'      => array( 'default' => 'en', 'selected' => array( 'en', 'it' ) ),
			'banner_control' => array( 'cache_compatibility' => (bool) $cache_compat ),
		);
		faz_current_language( true );
	}

	echo "\n\033[1mTranslatePress + Weglot under Cache Compatibility Mode\033[0m\n\n";

	// ---------------------------------------------------------------------
	// Baseline: neither plugin present.
	// ---------------------------------------------------------------------
	echo "Detection helpers (no plugin active)\n";
	assert_eq( faz_trp_language_in_url(), false, 'faz_trp_language_in_url() false when TranslatePress is absent' );
	assert_eq( faz_weglot_language_in_url(), false, 'faz_weglot_language_in_url() false when Weglot is absent' );

	// ---------------------------------------------------------------------
	// Weglot FIRST — TRP_PLUGIN_VERSION is not defined yet, so the Weglot
	// branch is reachable. (Once TRP is defined below it shadows Weglot.)
	// ---------------------------------------------------------------------
	echo "\nWeglot (URL-resolved language)\n";

	$GLOBALS['faz_test_weglot_lang'] = 'it';
	if ( ! function_exists( 'weglot_get_current_language' ) ) {
		/** Stand-in for Weglot's URL-derived current language. */
		function weglot_get_current_language() { // phpcs:ignore
			return $GLOBALS['faz_test_weglot_lang'];
		}
	}

	assert_eq( faz_weglot_language_in_url(), true, 'faz_weglot_language_in_url() true once Weglot is active' );

	faz_seed_settings( false );
	assert_eq( faz_current_language(), 'it', 'cache-compat OFF + Weglot → URL language resolved (unchanged)' );

	faz_seed_settings( true );
	assert_eq( faz_current_language(), 'it', 'cache-compat ON + Weglot → URL language resolved (was gated to the default)' );

	// The visitor on the default-language URL still gets the default.
	$GLOBALS['faz_test_weglot_lang'] = 'en';
	faz_seed_settings( true );
	assert_eq( faz_current_language(), 'en', 'cache-compat ON + Weglot → default-language URL still serves the default' );

	// A language outside the plugin's selected set falls back to the default.
	$GLOBALS['faz_test_weglot_lang'] = 'de';
	faz_seed_settings( true );
	assert_eq( faz_current_language(), 'en', 'cache-compat ON + Weglot → unselected language falls back to the default' );
	$GLOBALS['faz_test_weglot_lang'] = 'it';

	// ---------------------------------------------------------------------
	// TranslatePress LAST — defining TRP_PLUGIN_VERSION activates the TRP
	// branch, which sits before Weglot in the chain and shadows it from here on.
	// ---------------------------------------------------------------------
	echo "\nTranslatePress (URL-subdirectory language)\n";

	if ( ! defined( 'TRP_PLUGIN_VERSION' ) ) {
		define( 'TRP_PLUGIN_VERSION', '3.2.4' );
	}
	$GLOBALS['TRP_LANGUAGE'] = 'it_IT';

	assert_eq( faz_trp_language_in_url(), true, 'faz_trp_language_in_url() true once TranslatePress is active' );

	faz_seed_settings( false );
	assert_eq( faz_current_language(), 'it', 'cache-compat OFF + TranslatePress → $TRP_LANGUAGE resolved (unchanged)' );

	faz_seed_settings( true );
	assert_eq( faz_current_language(), 'it', 'cache-compat ON + TranslatePress → $TRP_LANGUAGE resolved (was gated to the default)' );

	// TRP precedes Weglot in the chain: with both active, TRP decides.
	$GLOBALS['faz_test_weglot_lang'] = 'en';
	$GLOBALS['TRP_LANGUAGE']         = 'it_IT';
	faz_seed_settings( true );
	assert_eq( faz_current_language(), 'it', 'TranslatePress takes precedence over Weglot when both are active' );

	// TRP active but no language set for the request → default, no crash.
	$GLOBALS['TRP_LANGUAGE'] = '';
	faz_seed_settings( true );
	assert_eq( faz_current_language(), 'en', 'TranslatePress active with no $TRP_LANGUAGE → site default (no fatal)' );

	// Default-language URL under TRP.
	$GLOBALS['TRP_LANGUAGE'] = 'en_US';
	faz_seed_settings( true );
	assert_eq( faz_current_language(), 'en', 'cache-compat ON + TranslatePress → default-language URL serves the default' );

	echo "\n";
	if ( $tests_failed > 0 ) {
		echo "\033[31m✗ {$tests_failed} failed\033[0m, {$tests_passed} passed ({$tests_run} total)\n";
		exit( 1 );
	}
	echo "\033[32m✓ all {$tests_passed} passed\033[0m ({$tests_run} total)\n";
	exit( 0 );
}
