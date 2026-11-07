<?php
/**
 * Standalone unit tests for the qTranslate family in the i18n helpers:
 * faz_i18n_is_multilingual(), faz_current_language(),
 * faz_browser_detect_enabled(), faz_qtranslate_language_in_url() and the
 * language-code mapping.
 *
 * Subsystem: qtranslate-language
 *
 * Background (wordpress.org topic "language-qtranslate-xt"). The helpers only
 * knew Polylang, WPML, TranslatePress and Weglot. On a qTranslate-XT site the
 * server therefore rendered the banner in the FAZ default language and, since
 * the install looked "not multilingual" with two selected languages,
 * faz_browser_detect_enabled() switched on client-side browser detection: the
 * banner followed the browser instead of the language the visitor picked in
 * qTranslate.
 *
 * The family shares one global, $q_config (language, default_language,
 * enabled_languages, url_mode, locale), and one url_mode numbering
 * (1 query, 2 pre-path, 3 pre-domain, 4 per-domain). The accessor differs:
 *   - qTranslate-X / qTranslate-XT: qtranxf_getLanguage(), QTX_URL_* constants
 *   - original qTranslate and mqTranslate: qtrans_getLanguage(), QT_URL_*
 *   - qTranslate Plus: ppqtrans_getLanguage(), QT_URL_*
 *   - zTranslate: ztrans_getLanguage(), ZT_URL_*
 *
 * Why the scenarios run in SEPARATE PHP processes: detection goes by function
 * and constant presence, and PHP cannot undefine either. Each family member is
 * therefore exercised in a child process of this same file (argv[1] names the
 * scenario); the parent only collects the results.
 *
 * Run from project root:
 *   php tests/unit/test-qtranslate-language-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	$faz_scenarios = array( 'xt', 'qtrans', 'ppqtrans', 'ztrans', 'globals', 'none' );

	// ---------------------------------------------------------------------
	// Parent: run every scenario in its own process and aggregate.
	// ---------------------------------------------------------------------
	if ( ! isset( $argv[1] ) ) {
		$failed_scenarios = 0;
		$total_passed     = 0;
		$total_run        = 0;
		foreach ( $faz_scenarios as $scenario ) {
			$cmd    = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario ) . ' 2>&1';
			$output = array();
			$code   = 0;
			exec( $cmd, $output, $code );
			echo implode( "\n", $output ) . "\n";
			$tail = end( $output );
			if ( preg_match( '/RESULT (\d+)\/(\d+)/', (string) $tail, $m ) ) {
				$total_passed += (int) $m[1];
				$total_run    += (int) $m[2];
			}
			if ( 0 !== $code ) {
				++$failed_scenarios;
			}
		}
		echo "\n";
		if ( $failed_scenarios > 0 || 0 === $total_run ) {
			echo "\033[31m✗ {$failed_scenarios} scenario(s) failed\033[0m, {$total_passed} of {$total_run} assertions passed\n";
			exit( 1 );
		}
		echo "\033[32m✓ all {$total_passed} passed\033[0m ({$total_run} total)\n";
		exit( 0 );
	}

	$faz_scenario = (string) $argv[1];
	if ( ! in_array( $faz_scenario, $faz_scenarios, true ) ) {
		echo "unknown scenario {$faz_scenario}\n";
		exit( 2 );
	}

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['faz_test_options'] = array();
	$GLOBALS['faz_test_filters'] = array();

	function get_option( $name, $default = false ) { // phpcs:ignore
		return isset( $GLOBALS['faz_test_options'][ $name ] ) ? $GLOBALS['faz_test_options'][ $name ] : $default;
	}
	function apply_filters( $tag, $value ) { // phpcs:ignore
		$args = array_slice( func_get_args(), 2 );
		foreach ( $GLOBALS['faz_test_filters'][ $tag ] ?? array() as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
		}
		return $value;
	}
	function add_filter( $tag, $callback ) { // phpcs:ignore
		$GLOBALS['faz_test_filters'][ $tag ][] = $callback;
		return true;
	}
	function wp_strip_all_tags( $str ) { // phpcs:ignore
		return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
	}
	function sanitize_text_field( $str ) { // phpcs:ignore
		return trim( wp_strip_all_tags( (string) $str ) );
	}
	function faz_sanitize_text( $value ) { // phpcs:ignore
		if ( is_array( $value ) ) {
			return array_map( 'faz_sanitize_text', $value );
		}
		return sanitize_text_field( $value );
	}

	// ---------------------------------------------------------------------
	// Stand-ins for each family member, declared with the real signatures.
	// Each reads the shared global exactly like the real accessor does.
	// ---------------------------------------------------------------------
	if ( 'xt' === $faz_scenario ) {
		// qTranslate-XT 3.16.2 src/language_config.php:3 — typed `: string`,
		// so a null language throws TypeError, as it does in the real plugin.
		function qtranxf_getLanguage(): string { // phpcs:ignore
			global $q_config;
			return $q_config['language'];
		}
		// XT's optional compatibility alias (src/compatibility.php:38). Stale
		// on purpose: the newest API must win.
		function qtrans_getLanguage(): string { // phpcs:ignore
			return 'fr';
		}
		// qTranslate-XT 3.16.2 qtranslate.php:58, src/options.php:23-26.
		define( 'QTX_VERSION', '3.16.1' );
		define( 'QTX_URL_QUERY', 1 );
		define( 'QTX_URL_PATH', 2 );
		define( 'QTX_URL_DOMAIN', 3 );
		define( 'QTX_URL_DOMAINS', 4 );
	} elseif ( 'qtrans' === $faz_scenario ) {
		// qTranslate 2.5.40b qtranslate_utils.php:63, qtranslate.php:96-98.
		function qtrans_getLanguage() { // phpcs:ignore
			global $q_config;
			return $q_config['language'];
		}
		define( 'QT_URL_QUERY', 1 );
		define( 'QT_URL_PATH', 2 );
		define( 'QT_URL_DOMAIN', 3 );
	} elseif ( 'ppqtrans' === $faz_scenario ) {
		// qTranslate Plus 2.7.2 ppqtranslate_utils.php:65, ppqtranslate.php:98-100.
		function ppqtrans_getLanguage() { // phpcs:ignore
			global $q_config;
			return $q_config['language'];
		}
		define( 'QT_URL_QUERY', 1 );
		define( 'QT_URL_PATH', 2 );
		define( 'QT_URL_DOMAIN', 3 );
	} elseif ( 'ztrans' === $faz_scenario ) {
		// zTranslate 2.6.3 ztranslate_utils.php:63, ztranslate.php:104-106.
		function ztrans_getLanguage() { // phpcs:ignore
			global $q_config;
			return $q_config['language'];
		}
		define( 'ZT_URL_QUERY', 1 );
		define( 'ZT_URL_PATH', 2 );
		define( 'ZT_URL_DOMAIN', 3 );
	}
	// 'globals': no function and no constant, only $q_config.
	// 'none': nothing at all.

	require_once dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-i18n-helpers.php';

	$tests_run = $tests_passed = $tests_failed = 0;
	function assert_eq( $actual, $expected, $label ) { // phpcs:ignore
		global $tests_run, $tests_passed, $tests_failed, $faz_scenario;
		$tests_run++;
		if ( $actual === $expected ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m [{$faz_scenario}] $label\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m [{$faz_scenario}] $label\n";
			echo '      expected: ' . var_export( $expected, true ) . "\n";
			echo '      actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	/** Seed faz_settings (EN+IT unless told otherwise) and the cache-compat flag. */
	function faz_seed( $cache_compat, $selected = array( 'en', 'it' ) ) { // phpcs:ignore
		$GLOBALS['faz_test_options']['faz_settings'] = array(
			'languages'      => array( 'default' => 'en', 'selected' => $selected ),
			'banner_control' => array( 'cache_compatibility' => (bool) $cache_compat ),
		);
		faz_current_language( true );
	}

	/** Seed qTranslate's shared global the way qtranxf_init_language() / qtrans_init() leave it. */
	function faz_seed_qconfig( $language, $url_mode ) { // phpcs:ignore
		$GLOBALS['q_config'] = array(
			'language'          => $language,
			'default_language'  => 'en',
			'enabled_languages' => array( 'en', 'it', 'pb', 'ua', 'de' ),
			'url_mode'          => $url_mode,
			'locale'            => array(
				'en' => 'en_US',
				'it' => 'it_IT',
				'pb' => 'pt_BR',
				'ua' => 'uk',
				'de' => 'de_DE',
			),
		);
	}

	/** Resolve the current language with a fresh static cache. */
	function faz_lang() { // phpcs:ignore
		faz_current_language( true );
		return faz_current_language();
	}

	echo "\n\033[1mqTranslate family — scenario {$faz_scenario}\033[0m\n";
	unset( $_GET['lang'] );

	if ( 'none' === $faz_scenario ) {
		unset( $GLOBALS['q_config'] );
		faz_seed( false );
		assert_eq( faz_i18n_is_multilingual(), false, 'no qTranslate → not multilingual' );
		assert_eq( faz_browser_detect_enabled(), true, 'no qTranslate + 2 languages → browser detection stays on' );
		// Another plugin's unrelated $q_config must not look like qTranslate.
		$GLOBALS['q_config'] = array( 'foo' => 'bar' );
		assert_eq( faz_i18n_is_multilingual(), false, 'unrelated $q_config shape → not multilingual' );
		assert_eq( faz_lang(), 'en', 'no qTranslate → site default' );
	} else {
		// Path mode is the default url_mode of every member of the family.
		faz_seed_qconfig( 'it', 2 );
		faz_seed( false );
		assert_eq( faz_i18n_is_multilingual(), true, 'qTranslate active → multilingual' );
		assert_eq( faz_browser_detect_enabled(), false, 'qTranslate active → client-side browser detection off' );
		assert_eq( faz_lang(), 'it', 'pre-path mode, cache-compat OFF → visitor language resolved' );

		faz_seed( true );
		assert_eq( faz_qtranslate_language_in_url(), true, 'pre-path mode is URL-keyed' );
		assert_eq( faz_lang(), 'it', 'pre-path mode, cache-compat ON → visitor language resolved' );

		faz_seed_qconfig( 'it', 3 );
		faz_seed( true );
		assert_eq( faz_lang(), 'it', 'pre-domain mode, cache-compat ON → visitor language resolved' );

		faz_seed_qconfig( 'it', 1 );
		faz_seed( true );
		assert_eq( faz_qtranslate_language_in_url(), false, 'query mode is not URL-keyed' );
		assert_eq( faz_lang(), 'en', 'query mode, cache-compat ON → falls back to the default' );

		faz_seed( false );
		assert_eq( faz_lang(), 'it', 'query mode, cache-compat OFF → visitor language resolved' );

		// A ?lang= override on a pre-path URL is a query-string language.
		faz_seed_qconfig( 'it', 2 );
		faz_seed( true );
		$_GET['lang'] = 'it';
		assert_eq( faz_qtranslate_language_in_url(), false, 'pre-path mode with ?lang= is not URL-keyed' );
		assert_eq( faz_lang(), 'en', 'pre-path mode with ?lang=, cache-compat ON → default' );
		unset( $_GET['lang'] );

		// Unselected language → default; default URL → default.
		faz_seed_qconfig( 'de', 2 );
		faz_seed( true );
		assert_eq( faz_lang(), 'en', 'language not selected in FAZ → default' );
		faz_seed_qconfig( 'en', 2 );
		faz_seed( true );
		assert_eq( faz_lang(), 'en', 'default-language URL → default' );

		// qTranslate codes are not always ISO: 'pb' is Brazilian Portuguese,
		// 'ua' a second Ukrainian code. Both map through their locale.
		faz_seed_qconfig( 'pb', 2 );
		faz_seed( true, array( 'en', 'it', 'pt-br', 'uk' ) );
		assert_eq( faz_lang(), 'pt-br', "qTranslate 'pb' (pt_BR) → FAZ 'pt-br'" );
		faz_seed_qconfig( 'ua', 2 );
		faz_seed( true, array( 'en', 'it', 'pt-br', 'uk' ) );
		assert_eq( faz_lang(), 'uk', "qTranslate 'ua' (uk) → FAZ 'uk'" );

		faz_seed_qconfig( 'it', 2 );
		faz_seed( false );
		assert_eq( faz_i18n_default_language(), 'en', 'faz_i18n_default_language() reads the qTranslate default' );
		faz_seed( false, array( 'en', 'it', 'pt-br', 'uk' ) );
		assert_eq( faz_i18n_selected_languages(), array( 'en', 'it', 'pt-br', 'uk', 'de' ), 'faz_i18n_selected_languages() maps the qTranslate enabled languages' );

		if ( 'xt' === $faz_scenario ) {
			faz_seed_qconfig( 'it', 4 );
			faz_seed( true );
			assert_eq( faz_lang(), 'it', 'per-domain mode (XT only), cache-compat ON → visitor language resolved' );

			// qtranxf_getLanguage() wins over the stale qtrans_* alias.
			faz_seed_qconfig( 'it', 2 );
			faz_seed( false, array( 'en', 'it', 'fr' ) );
			assert_eq( faz_lang(), 'it', 'qtranxf_getLanguage() preferred over the qtrans_getLanguage() alias' );

			// Called before qTranslate set the language: XT's typed accessor
			// throws TypeError on null. Must not fatal.
			faz_seed_qconfig( null, 2 );
			faz_seed( false );
			assert_eq( faz_lang(), 'en', 'language not set yet (TypeError in XT) → default, no fatal' );
		}
	}

	echo "RESULT {$tests_passed}/{$tests_run}\n";
	exit( $tests_failed > 0 ? 1 : 0 );
}
