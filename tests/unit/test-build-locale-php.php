<?php
/**
 * Standalone unit tests for Template::resolve_build_locale() + its presence in
 * the banner-template cache signature (#164 / CodeRabbit).
 *
 * Subsystem: build-locale-php
 *
 * On a single-language site whose FAZ "default language" is left at the stock
 * 'en' key, the banner chrome (Always Active / audit-table headers) must follow
 * the WordPress site locale rather than render in English. resolve_build_locale()
 * encodes that rule:
 *   - a real FAZ language key ('de', 'fr', …) → its mapped WP locale, always;
 *   - the stock 'en' key on a monolingual site → the WP site locale, but only
 *     when that locale is itself non-English (otherwise stay on the faz locale);
 *   - multilingual sites are never second-guessed (explicit per-language build).
 *
 * The resolved locale also drives the translated output, so it MUST be part of
 * get_layout_signature(): otherwise a WP locale switch leaves the cached banner
 * serving stale-language labels. These tests pin both halves.
 *
 * Run: php tests/unit/test-build-locale-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'FAZ_VERSION' ) ) {
		define( 'FAZ_VERSION', '9.9.9-test' );
	}

	// Controllable environment for the stubbed WP/plugin functions.
	$GLOBALS['__faz_wp_locale']      = 'en_US'; // get_locale()
	$GLOBALS['__faz_multilingual']   = false;   // faz_i18n_is_multilingual()
	$GLOBALS['__faz_options']        = array(); // get_option()

	// faz_wp_locale(): FAZ language key → WP locale. Mirror the real mapping for
	// the keys exercised here; unknown keys fall through to ''.
	if ( ! function_exists( 'faz_wp_locale' ) ) {
		function faz_wp_locale( $lang ) {
			$map = array( 'en' => 'en_US', 'de' => 'de_DE', 'fr' => 'fr_FR', 'it' => 'it_IT' );
			return isset( $map[ $lang ] ) ? $map[ $lang ] : '';
		}
	}
	if ( ! function_exists( 'faz_i18n_is_multilingual' ) ) {
		function faz_i18n_is_multilingual() {
			return (bool) $GLOBALS['__faz_multilingual'];
		}
	}
	if ( ! function_exists( 'get_locale' ) ) {
		function get_locale() {
			return $GLOBALS['__faz_wp_locale'];
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			return array_key_exists( $name, $GLOBALS['__faz_options'] ) ? $GLOBALS['__faz_options'][ $name ] : $default;
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $flags = 0 ) {
			return json_encode( $data, $flags );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $s ) {
			return trim( (string) $s );
		}
	}
	if ( ! function_exists( 'faz_current_language' ) ) {
		function faz_current_language() {
			return 'en';
		}
	}

	require_once dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-template.php';

	use FazCookie\Admin\Modules\Banners\Includes\Template;

	$tests_run = 0; $tests_passed = 0; $tests_failed = 0;
	function eq( $actual, $expected, $label ) {
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
	function ok( $cond, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $cond ) { $tests_passed++; echo "  \033[32m✓\033[0m " . $label . "\n"; }
		else { $tests_failed++; echo "  \033[31m✗\033[0m " . $label . "\n"; }
	}

	// Build a Template with a chosen FAZ language key, no constructor.
	function faz_template( $language ) {
		$rc = new ReflectionClass( Template::class );
		$tpl = $rc->newInstanceWithoutConstructor();
		$pl = $rc->getProperty( 'language' );
		$pl->setAccessible( true );
		$pl->setValue( $tpl, $language );
		$pp = $rc->getProperty( 'properties' );
		$pp->setAccessible( true );
		$pp->setValue( $tpl, array() );
		$pb = $rc->getProperty( 'banner' );
		$pb->setAccessible( true );
		$pb->setValue( $tpl, null );
		return $tpl;
	}
	function call_priv( $tpl, $method ) {
		$m = new ReflectionMethod( Template::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $tpl );
	}

	// =====================================================================
	// resolve_build_locale()
	// =====================================================================
	echo "resolve_build_locale()\n";

	// A real language key → its mapped WP locale, regardless of site locale.
	$GLOBALS['__faz_wp_locale'] = 'en_US';
	eq( call_priv( faz_template( 'de' ), 'resolve_build_locale' ), 'de_DE', "key 'de' → de_DE (ignores site locale)" );
	eq( call_priv( faz_template( 'fr' ), 'resolve_build_locale' ), 'fr_FR', "key 'fr' → fr_FR" );

	// Stock 'en' key, monolingual, site locale is non-English → follow the site.
	$GLOBALS['__faz_multilingual'] = false;
	$GLOBALS['__faz_wp_locale']    = 'de_DE';
	eq( call_priv( faz_template( 'en' ), 'resolve_build_locale' ), 'de_DE', "key 'en' + de_DE site → follows site locale" );
	$GLOBALS['__faz_wp_locale']    = 'it_IT';
	eq( call_priv( faz_template( 'en' ), 'resolve_build_locale' ), 'it_IT', "key 'en' + it_IT site → follows site locale" );

	// Stock 'en' key but the site locale is itself English → stay on faz locale.
	$GLOBALS['__faz_wp_locale'] = 'en_GB';
	eq( call_priv( faz_template( 'en' ), 'resolve_build_locale' ), 'en_US', "key 'en' + en_GB site → faz locale (no English self-override)" );
	$GLOBALS['__faz_wp_locale'] = '';
	eq( call_priv( faz_template( 'en' ), 'resolve_build_locale' ), 'en_US', "key 'en' + empty site locale → faz locale" );

	// Multilingual site → never second-guess, even with 'en' + non-English site.
	$GLOBALS['__faz_multilingual'] = true;
	$GLOBALS['__faz_wp_locale']    = 'de_DE';
	eq( call_priv( faz_template( 'en' ), 'resolve_build_locale' ), 'en_US', "key 'en' multilingual → faz locale (explicit per-language build)" );
	$GLOBALS['__faz_multilingual'] = false;

	// Memoization: the value is pinned on first call, so a get_locale() shift
	// later in the same request (the switch_to_locale window) can't make the
	// stored signature diverge from the locale generate() actually builds with.
	$GLOBALS['__faz_wp_locale'] = 'de_DE';
	$tpl_memo                   = faz_template( 'en' );
	eq( call_priv( $tpl_memo, 'resolve_build_locale' ), 'de_DE', 'memo: first call resolves de_DE' );
	$GLOBALS['__faz_wp_locale'] = 'fr_FR';
	eq( call_priv( $tpl_memo, 'resolve_build_locale' ), 'de_DE', 'memo: second call returns the pinned value despite a get_locale() change' );

	// =====================================================================
	// get_layout_signature() must change when the resolved locale changes.
	// =====================================================================
	echo "\nget_layout_signature() includes build_locale\n";
	$GLOBALS['__faz_wp_locale'] = 'de_DE';
	$sig_de = call_priv( faz_template( 'en' ), 'get_layout_signature' );
	$GLOBALS['__faz_wp_locale'] = 'it_IT';
	$sig_it = call_priv( faz_template( 'en' ), 'get_layout_signature' );
	$GLOBALS['__faz_wp_locale'] = 'de_DE';
	$sig_de2 = call_priv( faz_template( 'en' ), 'get_layout_signature' );
	ok( is_string( $sig_de ) && 32 === strlen( $sig_de ), 'signature is an md5 hash' );
	ok( $sig_de !== $sig_it, 'a WP locale switch (de_DE→it_IT) changes the cache signature' );
	eq( $sig_de2, $sig_de, 'same locale → identical signature (stable cache key)' );

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "\033[32mALL PASS\033[0m — {$tests_passed}/{$tests_run}\n";
		exit( 0 );
	}
	echo "\033[31m{$tests_failed} FAILED\033[0m — {$tests_passed}/{$tests_run} passed\n";
	exit( 1 );
}
