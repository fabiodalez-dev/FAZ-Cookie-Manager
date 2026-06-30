<?php
/**
 * Standalone unit tests for Advanced Consent Mode server output (#165).
 *
 * Subsystem: gcm-advanced-php
 *
 * Advanced Consent Mode loads the gtag-direct Google stack BEFORE consent and
 * prints a synchronous denied `consent default` inline in <head>. Two pieces
 * of server logic carry the contract and are pinned here:
 *
 *   1. Frontend::print_gcm_default_inline() — emits the inline gtag bootstrap.
 *      It must be a no-op when (a) the request is AMP (CodeRabbit PR #166: AMP
 *      delegates to <amp-consent>, so the inline gtag would invalidate that
 *      branch), (b) gcm_settings is absent, or (c) Advanced mode is off. When
 *      it does emit, the baseline is all-denied / security granted, and the
 *      wait_for_update / npa / ads_data_redaction / url_passthrough knobs are
 *      reflected, all pushing to the resolved dataLayer name.
 *
 *   2. Frontend::is_gcm_managed_script() — decides which scripts Advanced mode
 *      may load early. gtag-direct only (gtag.js / GA4 inline config / Ads):
 *      the GTM container (gtm.js) and non-Google trackers stay blocked, and a
 *      script that merely mentions the string `gtag(` is NOT exempted.
 *
 * Run: php tests/unit/test-gcm-advanced-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	// ---------- Bootstrap ----------

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	$GLOBALS['__faz_is_amp']   = false;       // faz_is_amp_request filter value
	$GLOBALS['__faz_dl_name']  = 'dataLayer'; // faz_gcm_datalayer_name filter value

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			if ( 'faz_is_amp_request' === $tag ) {
				return $GLOBALS['__faz_is_amp'];
			}
			if ( 'faz_gcm_datalayer_name' === $tag ) {
				return $GLOBALS['__faz_dl_name'];
			}
			return $value;
		}
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $v ) {
			return abs( (int) $v );
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $flags = 0 ) {
			return json_encode( $data, $flags );
		}
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $s ) {
			return htmlspecialchars( (string) $s, ENT_QUOTES );
		}
	}
	if ( ! function_exists( 'faz_sanitize_bool' ) ) {
		function faz_sanitize_bool( $v ) {
			if ( is_bool( $v ) ) {
				return $v;
			}
			if ( is_string( $v ) ) {
				return in_array( strtolower( $v ), array( '1', 'true', 'yes', 'on' ), true );
			}
			return (bool) $v;
		}
	}

	// Minimal Gcm_Settings double: advanced flag + a key→value bag.
	class FazTest_GcmSettings {
		public $advanced = true;
		public $bag      = array();
		public function is_advanced_mode() {
			return (bool) $this->advanced;
		}
		public function get( $key ) {
			return array_key_exists( $key, $this->bag ) ? $this->bag[ $key ] : null;
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	// ---------- assert helpers ----------

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;

	function ok( $cond, $label ) {
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
	function eq( $actual, $expected, $label ) {
		ok( $actual === $expected, $label );
		if ( $actual !== $expected ) {
			echo '      expected: ' . var_export( $expected, true ) . "\n";
			echo '      actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	// ---------- reflection harness ----------

	function faz_fe_with_gcm( $settings ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		$p  = $rc->getProperty( 'gcm_settings' );
		$p->setAccessible( true );
		$p->setValue( $fe, $settings );
		return $fe;
	}
	function faz_capture_inline( $fe ) {
		ob_start();
		$fe->print_gcm_default_inline();
		return ob_get_clean();
	}
	function faz_managed( $fe, $attrs, $content = '' ) {
		$m = new ReflectionMethod( Frontend::class, 'is_gcm_managed_script' );
		$m->setAccessible( true );
		return $m->invoke( $fe, $attrs, $content );
	}

	// =====================================================================
	// print_gcm_default_inline() — AMP guard + emission contract
	// =====================================================================
	echo "print_gcm_default_inline() — guards\n";

	// AMP request → no-op even with Advanced ON (the PR #166 fix).
	$GLOBALS['__faz_is_amp'] = true;
	$s = new FazTest_GcmSettings();
	$s->advanced = true;
	eq( faz_capture_inline( faz_fe_with_gcm( $s ) ), '', 'AMP request emits nothing (Advanced on)' );
	$GLOBALS['__faz_is_amp'] = false;

	// gcm_settings absent → no-op.
	eq( faz_capture_inline( faz_fe_with_gcm( null ) ), '', 'no gcm_settings emits nothing' );

	// Advanced OFF → no-op.
	$s = new FazTest_GcmSettings();
	$s->advanced = false;
	eq( faz_capture_inline( faz_fe_with_gcm( $s ) ), '', 'Advanced off emits nothing' );

	echo "\nprint_gcm_default_inline() — emission\n";

	$s = new FazTest_GcmSettings();
	$s->advanced = true;
	$out = faz_capture_inline( faz_fe_with_gcm( $s ) );
	ok( '' !== $out, 'Advanced on (non-AMP) emits a script' );
	ok( false !== strpos( $out, '<script>' ) && false !== strpos( $out, '</script>' ), 'output is wrapped in <script>' );
	ok( false !== strpos( $out, "gtag('consent','default'," ), 'emits a consent default call' );
	ok( false !== strpos( $out, '"ad_storage":"denied"' ), 'ad_storage denied in the baseline' );
	ok( false !== strpos( $out, '"security_storage":"granted"' ), 'security_storage granted in the baseline' );
	ok( false === strpos( $out, 'wait_for_update' ), 'no wait_for_update when unset/zero' );
	ok( false === strpos( $out, 'npa:1' ), 'no npa fallback by default' );
	ok( false !== strpos( $out, "gtag('set','ads_data_redaction',false)" ), 'ads_data_redaction false by default' );
	ok( false !== strpos( $out, "gtag('set','url_passthrough',false)" ), 'url_passthrough false by default' );
	ok( false !== strpos( $out, 'window["dataLayer"]' ), 'pushes to the default dataLayer array' );

	// Knobs reflected.
	$s = new FazTest_GcmSettings();
	$s->bag = array(
		'wait_for_update'               => '750',
		'ads_data_redaction'            => true,
		'url_passthrough'               => '1',
		'non_personalized_ads_fallback' => true,
	);
	$out = faz_capture_inline( faz_fe_with_gcm( $s ) );
	ok( false !== strpos( $out, '"wait_for_update":750' ), 'wait_for_update reflected as int' );
	ok( false !== strpos( $out, 'npa:1' ), 'npa fallback emitted when enabled' );
	ok( false !== strpos( $out, "gtag('set','ads_data_redaction',true)" ), 'ads_data_redaction true reflected' );
	ok( false !== strpos( $out, "gtag('set','url_passthrough',true)" ), 'url_passthrough true reflected' );

	// Custom dataLayer name resolved + sanitised.
	$GLOBALS['__faz_dl_name'] = 'my-DL!name';
	$out = faz_capture_inline( faz_fe_with_gcm( new FazTest_GcmSettings() ) );
	ok( false !== strpos( $out, 'window["myDLname"]' ), 'custom dataLayer name sanitised to JS-identifier charset' );
	$GLOBALS['__faz_dl_name'] = 'dataLayer';

	// =====================================================================
	// is_gcm_managed_script() — gtag-direct only
	// =====================================================================
	echo "\nis_gcm_managed_script() — gtag-direct only\n";
	$fe = faz_fe_with_gcm( new FazTest_GcmSettings() );
	eq( faz_managed( $fe, ' src="https://www.googletagmanager.com/gtag/js?id=G-X"', '' ), true, 'gtag/js src → managed' );
	eq( faz_managed( $fe, '', "window.gtag('config','G-X');" ), true, "inline gtag('config') → managed" );
	eq( faz_managed( $fe, '', 'gtag("js", new Date());' ), true, 'inline gtag("js") → managed' );
	eq( faz_managed( $fe, ' src="https://www.googletagmanager.com/gtm.js?id=GTM-X"', '' ), false, 'GTM container (gtm.js) → NOT managed' );
	eq( faz_managed( $fe, ' src="https://connect.facebook.net/en_US/fbevents.js"', '' ), false, 'Facebook Pixel → NOT managed' );
	eq( faz_managed( $fe, '', 'var x = "this mentions gtag( in a string";' ), false, 'bare gtag( reference → NOT managed' );
	eq( faz_managed( $fe, ' src="https://googleads.g.doubleclick.net/pagead/x"', '' ), true, 'doubleclick.net → managed' );
	eq( faz_managed( $fe, ' src="https://www.googleadservices.com/pagead/conversion.js"', '' ), true, 'googleadservices.com → managed' );

	// ---------- summary ----------
	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "\033[32mALL PASS\033[0m — {$tests_passed}/{$tests_run}\n";
		exit( 0 );
	}
	echo "\033[31m{$tests_failed} FAILED\033[0m — {$tests_passed}/{$tests_run} passed\n";
	exit( 1 );
}
