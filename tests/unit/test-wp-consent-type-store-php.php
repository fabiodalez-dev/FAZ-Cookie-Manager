<?php
/**
 * Standalone unit tests: the WP Consent API regime the server declares must
 * also be the one the client bridge declares.
 *
 * Subsystem: wp-consent-type-store
 *
 * filter_wp_consent_type() answers `wp_get_consent_type` with the regime
 * actually being ENFORCED for this visitor, not the banner's label: `optin`
 * whenever the runtime geo ruleset resolved a non-CCPA law (even though the
 * fail-closed branch keeps a CCPA banner to drive blocking) and under Cache
 * Compatibility Mode; `optout` only for a CCPA banner with neither. That
 * server verdict used to stop at the PHP side. wca.js set
 * `window.wp_consent_type` from the banner label, and the WP Consent API
 * client prefers that global over the PHP-localised fallback, so for a
 * visitor the plugin had classified as opt-in every JS consumer was told
 * `optout` and `wp_has_consent()` answered true before any act.
 *
 * Pinned here:
 *   1. the verdict itself, in the three states that matter;
 *   2. get_store_data() ships that verdict to the client as `_wpConsentType`,
 *      computed by the same method, so the two layers cannot drift again
 *      (wca.js's side is pinned in tests/unit/js/wca-consent-type-server-verdict.test.mjs).
 *
 * Run: php tests/unit/test-wp-consent-type-store-php.php
 *  or: bash scripts/run-unit-tests.sh
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
	class Geolocation {
		public static function get_visitor_country() {
			return 'DE';
		}
	}
	class Cache_Compatibility {
		public static function is_active( $settings = null ) {
			return (bool) $GLOBALS['__faz_ccm'];
		}
	}
}

namespace FazCookie\Frontend\Includes {

	// The runtime ruleset the geo resolver hands back for this visitor, or null.
	class Geo_Runtime {
		public static function resolve_for_country( $country ) {
			return $GLOBALS['__faz_ruleset'];
		}
		public static function model_to_law( $ruleset ) {
			return isset( $ruleset['law'] ) ? $ruleset['law'] : 'gdpr';
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

	$GLOBALS['__faz_ccm']     = false;
	$GLOBALS['__faz_ruleset'] = null;

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	// Banner double: only the law is read on this path.
	class FazTest_Banner {
		public $law = 'gdpr';
		public function get_law() {
			return $this->law;
		}
	}

	// ---------- assert helpers ----------

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;

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

	// ---------- reflection harness ----------

	function faz_type_frontend( $law ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		$banner      = new FazTest_Banner();
		$banner->law = $law;
		foreach ( array(
			'banner'                    => $banner,
			'settings_option_cache'     => array(),
			'geo_bootstrap_active_cache' => false,
		) as $prop => $value ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $fe, $value );
		}
		return $fe;
	}

	// =====================================================================
	// 1. The server verdict
	// =====================================================================
	echo "filter_wp_consent_type() — the enforced regime, not the label\n";

	eq( faz_type_frontend( 'gdpr' )->filter_wp_consent_type( '' ), 'optin', 'a GDPR banner declares optin' );
	eq( faz_type_frontend( 'ccpa' )->filter_wp_consent_type( '' ), 'optout', 'a CCPA banner with no runtime ruleset declares optout' );

	// The fail-closed branch: the runtime ruleset resolved an opt-in law and the
	// publisher only has a CCPA banner, which is kept to drive blocking.
	$GLOBALS['__faz_ruleset'] = array( 'law' => 'gdpr' );
	eq( faz_type_frontend( 'ccpa' )->filter_wp_consent_type( '' ), 'optin', 'a CCPA banner under an opt-in runtime ruleset declares optin (fail closed)' );
	$GLOBALS['__faz_ruleset'] = array( 'law' => 'ccpa' );
	eq( faz_type_frontend( 'ccpa' )->filter_wp_consent_type( '' ), 'optout', 'a CCPA banner under a CCPA runtime ruleset declares optout' );
	$GLOBALS['__faz_ruleset'] = null;

	// Cache Compatibility Mode: nothing may be assumed consented.
	$GLOBALS['__faz_ccm'] = true;
	eq( faz_type_frontend( 'ccpa' )->filter_wp_consent_type( '' ), 'optin', 'Cache Compatibility Mode declares optin regardless of the banner' );
	$GLOBALS['__faz_ccm'] = false;

	// Another plugin's stricter declaration is never downgraded.
	eq( faz_type_frontend( 'ccpa' )->filter_wp_consent_type( 'optin' ), 'optin', 'a stricter declaration from another integration is kept' );

	// =====================================================================
	// 2. The verdict reaches the client store
	// =====================================================================
	echo "\nget_store_data() ships the verdict\n";
	$src   = file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
	$start = strpos( $src, 'public function get_store_data()' );
	$end   = strpos( $src, 'return $store;', $start );
	$body  = false === $start || false === $end ? '' : substr( $src, $start, $end - $start );
	eq(
		1 === preg_match( '/\'_wpConsentType\'\s*=>\s*\$this->filter_wp_consent_type\( \'\' \)/', $body ),
		true,
		'the store carries _wpConsentType computed by filter_wp_consent_type(), the same method that answers wp_get_consent_type'
	);
	eq(
		1 === preg_match( '/\'_activeLaw\'\s*=>\s*\$banner->get_law\(\)/', $body ),
		true,
		'the banner label is still shipped separately as _activeLaw (UI and cookie scope keep using it)'
	);

	// ---------- summary ----------
	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "\033[32mALL PASS\033[0m — {$tests_passed}/{$tests_run}\n";
		exit( 0 );
	}
	echo "\033[31m{$tests_failed} FAILED\033[0m — {$tests_passed}/{$tests_run} passed\n";
	exit( 1 );
}
