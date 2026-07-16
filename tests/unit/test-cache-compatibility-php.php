<?php
/**
 * Standalone unit tests for "Cache Compatibility Mode" (issue #158).
 *
 * Subsystem: cache-compatibility-php
 *
 * Covers the two server-side seams the feature touches:
 *   - Settings::sanitize_option(): banner_control.cache_compatibility is a plain
 *     boolean (string/int inputs coerce), so a settings PUT can't smuggle a
 *     non-bool into the option.
 *   - Frontend::is_country_dependent_output(): when the toggle is ON it
 *     SHORT-CIRCUITS to false, so send_geo_cache_headers() emits no no-cache /
 *     no-store headers and maybe_disable_country_page_cache() never defines
 *     DONOTCACHEPAGE — even when IAB TCF or geo-targeting would otherwise force
 *     the page country-dependent. The OFF path is unchanged (still country-aware).
 *
 * Pure-logic tests: no browser, DB, or live WordPress. Settings::sanitize is
 * static; the Frontend object is built with newInstanceWithoutConstructor() and
 * its private settings cache is seeded via reflection (so get_option is never
 * touched), then is_country_dependent_output() is invoked via reflection. The
 * Controller / Geo_Runtime collaborators are stubbed so the OFF path is reachable
 * without a live install. The end-to-end header behaviour is asserted by
 * tests/e2e/specs/cache-compatibility-mode.spec.ts.
 *
 * Run from project root:
 *   php tests/unit/test-cache-compatibility-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Store {}
	class Geolocation {
		public static $visitorCountry = '';
		public static function get_visitor_country() {
			return self::$visitorCountry;
		}
	}
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

namespace FazCookie\Frontend\Includes {
	// Stub so the OFF path (which calls Geo_Runtime::is_enabled()) is reachable.
	class Geo_Runtime {
		public static $enabled = false;
		public static function is_enabled() {
			return self::$enabled;
		}
	}
}

namespace FazCookie\Admin\Modules\Banners\Includes {
	// Stub so the OFF path (which calls Controller::get_instance()
	// ->has_country_dependent_banners()) is reachable without a DB.
	class Controller {
		public static $countryDependent = false;
		private static $instance        = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function has_country_dependent_banners() {
			return self::$countryDependent;
		}
	}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Category_Controller {
		public static $items = array();
		private static $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function get_items() {
			return self::$items;
		}
	}
	class Cookie_Categories {
		private $data;
		public function __construct( $data ) {
			$this->data = (array) $data;
		}
		public function get_slug() {
			return isset( $this->data['slug'] ) ? $this->data['slug'] : '';
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	if ( ! function_exists( 'faz_sanitize_bool' ) ) {
		function faz_sanitize_bool( $value ) {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}
	}
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $str ) {
			return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			return trim( wp_strip_all_tags( (string) $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}
	if ( ! function_exists( 'faz_sanitize_text' ) ) {
		function faz_sanitize_text( $value ) {
			if ( is_array( $value ) ) {
				return array_map( 'faz_sanitize_text', $value );
			}
			return sanitize_text_field( $value );
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			$args = array_slice( func_get_args(), 2 );
			if ( ! empty( $GLOBALS['faz_test_filters'][ $tag ] ) ) {
				foreach ( $GLOBALS['faz_test_filters'][ $tag ] as $callback ) {
					$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
				}
			}
			return $value;
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $tag, $callback ) {
			if ( ! isset( $GLOBALS['faz_test_filters'][ $tag ] ) ) {
				$GLOBALS['faz_test_filters'][ $tag ] = array();
			}
			$GLOBALS['faz_test_filters'][ $tag ][] = $callback;
			return true;
		}
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action() {
			return true;
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			return isset( $GLOBALS['faz_test_options'][ $name ] ) ? $GLOBALS['faz_test_options'][ $name ] : $default;
		}
	}

	if ( ! class_exists( 'FazTest_WPDB' ) ) {
		class FazTest_WPDB {
			public $prefix = 'wp_';
			public function get_col( $query ) {
				return array();
			}
		}
	}
	$GLOBALS['wpdb'] = new FazTest_WPDB();

	require_once dirname( __DIR__, 2 ) . '/admin/modules/settings/includes/class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-i18n-helpers.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-amp-consent.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/modules/banner-rest/class-banner-rest.php';

	use FazCookie\Admin\Modules\Settings\Includes\Settings;
	use FazCookie\Admin\Modules\Banners\Includes\Controller;
	use FazCookie\Frontend\AMP_Consent;
	use FazCookie\Frontend\Frontend;
	use FazCookie\Frontend\Modules\Banner_Rest\Banner_Rest;

	$tests_run = $tests_passed = $tests_failed = 0;
	function assert_eq( $actual, $expected, $label ) {
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

	/** Frontend instance with a reflection-seeded settings cache. */
	function faz_frontend_with_settings( array $settings ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		$p  = new ReflectionProperty( Frontend::class, 'settings_option_cache' );
		$p->setAccessible( true );
		$p->setValue( $fe, $settings );
		return $fe;
	}

	/** Invoke a private Frontend method. */
	function faz_call_frontend_private( $fe, $method ) {
		$m = new ReflectionMethod( Frontend::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $fe );
	}

	/** is_country_dependent_output() with a reflection-seeded settings cache. */
	function faz_is_dependent( array $settings ) {
		return (bool) faz_call_frontend_private( faz_frontend_with_settings( $settings ), 'is_country_dependent_output' );
	}

	echo "\n\033[1mCache Compatibility Mode (issue #158)\033[0m\n\n";

	// ---------------------------------------------------------------------
	// Settings::sanitize_option(): cache_compatibility is a clean boolean.
	// ---------------------------------------------------------------------
	echo "Settings::sanitize (cache_compatibility)\n";
	$defaults = array( 'banner_control' => array( 'cache_compatibility' => false ) );
	$san      = function ( $raw ) use ( $defaults ) {
		$out = Settings::sanitize( array( 'banner_control' => array( 'cache_compatibility' => $raw ) ), $defaults );
		return $out['banner_control']['cache_compatibility'];
	};
	assert_eq( $san( 'true' ), true, "string 'true' coerces to bool true" );
	assert_eq( $san( '1' ), true, "string '1' coerces to bool true" );
	assert_eq( $san( true ), true, 'bool true stays true' );
	assert_eq( $san( '0' ), false, "string '0' coerces to bool false" );
	assert_eq( $san( '' ), false, 'empty string coerces to bool false' );
	assert_eq( $san( false ), false, 'bool false stays false' );

	// ---------------------------------------------------------------------
	// Frontend::is_country_dependent_output(): ON short-circuits to false.
	// ---------------------------------------------------------------------
	echo "\nFrontend::is_country_dependent_output()\n";

	// ON → always cacheable, regardless of other triggers.
	assert_eq(
		faz_is_dependent( array( 'banner_control' => array( 'cache_compatibility' => true ) ) ),
		false,
		'cache_compatibility ON → not country-dependent (cacheable)'
	);
	assert_eq(
		faz_is_dependent( array(
			'banner_control' => array( 'cache_compatibility' => true ),
			'iab'            => array( 'enabled' => true ),
		) ),
		false,
		'cache_compatibility ON overrides IAB TCF → still cacheable'
	);
	assert_eq(
		faz_is_dependent( array(
			'banner_control' => array( 'cache_compatibility' => true ),
			'geolocation'    => array( 'geo_targeting' => true, 'default_behavior' => 'no_banner' ),
		) ),
		false,
		'cache_compatibility ON overrides geo-targeting no_banner → still cacheable'
	);
	assert_eq(
		faz_is_dependent( array( 'banner_control' => array( 'cache_compatibility' => '1' ) ) ),
		false,
		"truthy '1' value is honoured by the !empty() short-circuit"
	);

	// ON → the server-rendered HTML must be visitor-invariant. Consent cookies
	// and per-service grants are ignored by PHP so shared caches only ever see
	// the conservative blocked baseline; script.js can then unblock in-browser.
	\FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::$items = array(
		array( 'slug' => 'necessary' ),
		array( 'slug' => 'analytics' ),
		array( 'slug' => 'marketing' ),
	);
	$cache_safe_frontend = faz_frontend_with_settings(
		array(
			'banner_control' => array(
				'cache_compatibility' => true,
				'per_service_consent' => true,
			),
		)
	);
	$blocked_method = new ReflectionMethod( Frontend::class, 'get_blocked_categories' );
	$blocked_method->setAccessible( true );
	assert_eq(
		$blocked_method->invoke( $cache_safe_frontend ),
		array( 'analytics', 'marketing' ),
		'cache_compatibility ON → PHP blocks every non-necessary category as the cache-safe baseline'
	);
	$service_method = new ReflectionMethod( Frontend::class, 'get_service_consent' );
	$service_method->setAccessible( true );
	assert_eq(
		$service_method->invoke( $cache_safe_frontend ),
		array(),
		'cache_compatibility ON → PHP ignores per-service consent cookie grants'
	);
	$ruleset_method = new ReflectionMethod( Frontend::class, 'get_runtime_ruleset' );
	$ruleset_method->setAccessible( true );
	assert_eq(
		$ruleset_method->invoke( $cache_safe_frontend ),
		null,
		'cache_compatibility ON → PHP does not resolve visitor-country runtime rulesets'
	);

	$GLOBALS['faz_test_filters'] = array(
		'faz_use_country_language_fallback' => array(
			function () {
				return true;
			},
		),
	);
	$GLOBALS['faz_test_options']['faz_settings'] = array(
		'languages'      => array(
			'default'  => 'en',
			'selected' => array( 'en', 'it' ),
		),
		'banner_control' => array( 'cache_compatibility' => false ),
	);
	\FazCookie\Includes\Geolocation::$visitorCountry = 'IT';
	faz_current_language( true );
	assert_eq(
		faz_current_language(),
		'it',
		'cache_compatibility OFF → opt-in country language fallback can use visitor country'
	);
	$GLOBALS['faz_test_options']['faz_settings']['banner_control']['cache_compatibility'] = true;
	faz_current_language( true );
	assert_eq(
		faz_current_language(),
		'en',
		'cache_compatibility ON → country language fallback is suppressed for invariant HTML'
	);
	$GLOBALS['faz_test_filters'] = array();

	$GLOBALS['faz_test_options']['faz_settings'] = array( 'banner_control' => array( 'cache_compatibility' => true ) );
	Controller::$countryDependent = true;

	$amp = ( new ReflectionClass( AMP_Consent::class ) )->newInstanceWithoutConstructor();
	$amp_method = new ReflectionMethod( AMP_Consent::class, 'is_country_dependent_output' );
	$amp_method->setAccessible( true );
	assert_eq(
		$amp_method->invoke( $amp ),
		false,
		'cache_compatibility ON → AMP path does not force no-cache for country-dependent banners'
	);

	$rest = ( new ReflectionClass( Banner_Rest::class ) )->newInstanceWithoutConstructor();
	$rest_method = new ReflectionMethod( Banner_Rest::class, 'is_country_dependent_output' );
	$rest_method->setAccessible( true );
	assert_eq(
		$rest_method->invoke( $rest, Controller::get_instance() ),
		false,
		'cache_compatibility ON → REST banner endpoint uses cacheable headers for deterministic payloads'
	);

	$GLOBALS['faz_test_options']['faz_settings'] = array( 'banner_control' => array( 'cache_compatibility' => false ) );
	assert_eq(
		$amp_method->invoke( $amp ),
		true,
		'cache_compatibility OFF → AMP country-dependent cache-bust is unchanged'
	);
	assert_eq(
		$rest_method->invoke( $rest, Controller::get_instance() ),
		true,
		'cache_compatibility OFF → REST country-dependent cache-bust is unchanged'
	);
	Controller::$countryDependent = false;
	$GLOBALS['faz_test_filters']  = array(
		'faz_use_country_language_fallback' => array(
			function () {
				return true;
			},
		),
	);
	assert_eq(
		$amp_method->invoke( $amp ),
		true,
		'cache_compatibility OFF → AMP cache-busts for country language fallback'
	);
	$GLOBALS['faz_test_filters'] = array();
	$GLOBALS['faz_test_options']['faz_settings'] = array(
		'banner_control' => array( 'cache_compatibility' => false ),
		'geolocation'    => array( 'geo_targeting' => true, 'default_behavior' => 'no_banner' ),
	);
	assert_eq(
		$amp_method->invoke( $amp ),
		true,
		'cache_compatibility OFF → AMP cache-busts for global geo no_banner'
	);

	// OFF (or absent) → unchanged behaviour: country-dependent only when a
	// real trigger is active. Controller + Geo_Runtime stubs report "no".
	\FazCookie\Frontend\Includes\Geo_Runtime::$enabled                              = false;
	assert_eq(
		faz_is_dependent( array( 'banner_control' => array( 'cache_compatibility' => false ) ) ),
		false,
		'OFF + no trigger → not country-dependent'
	);
	assert_eq(
		faz_is_dependent( array(
			'banner_control' => array( 'cache_compatibility' => false ),
			'iab'            => array( 'enabled' => true ),
		) ),
		true,
		'OFF + IAB enabled → country-dependent (cache-bust active, unchanged)'
	);
	assert_eq(
		faz_is_dependent( array() ),
		false,
		'absent cache_compatibility key + no trigger → not country-dependent'
	);

	// --- F001 (#158): the MULTILINGUAL branch of faz_current_language() is
	// gated under cache-compat. TranslatePress ($TRP_LANGUAGE) and Weglot
	// resolve language from cookie/session state, which would vary the cached
	// banner store per visitor on a shared URL. Defined LAST so the multilingual
	// flag doesn't perturb the country-dependent assertions above.
	if ( ! defined( 'TRP_PLUGIN_VERSION' ) ) {
		define( 'TRP_PLUGIN_VERSION', '2.0.0' );
	}
	$GLOBALS['TRP_LANGUAGE']                      = 'it_IT';
	$GLOBALS['faz_test_options']['faz_settings']  = array(
		'languages'      => array(
			'default'  => 'en',
			'selected' => array( 'en', 'it' ),
		),
		'banner_control' => array( 'cache_compatibility' => false ),
	);
	faz_current_language( true );
	assert_eq(
		faz_current_language(),
		'it',
		'cache_compatibility OFF → TranslatePress cookie/session language is honoured (unchanged)'
	);
	$GLOBALS['faz_test_options']['faz_settings']['banner_control']['cache_compatibility'] = true;
	faz_current_language( true );
	assert_eq(
		faz_current_language(),
		'en',
		'cache_compatibility ON → TranslatePress language is gated; render stays default-stable (#158)'
	);
	$GLOBALS['faz_test_filters'] = array(
		'wpml_current_language' => array(
			function () {
				return 'it';
			},
		),
	);
	faz_current_language( true );
	assert_eq(
		faz_current_language(),
		'en',
		'cache_compatibility ON → WPML cookie-mode fallback is gated; render stays default-stable (#160)'
	);

	// ---------------------------------------------------------------------
	// FlyingPress bridge (issue #125): flying_press_is_cacheable() vetoes
	// page caching exactly where the other page caches get DONOTCACHEPAGE.
	// FlyingPress honours neither DONOTCACHEPAGE nor Cache-Control:no-store;
	// its documented flying_press_is_cacheable filter is the only channel.
	// ---------------------------------------------------------------------
	echo "\nFrontend::flying_press_is_cacheable() (issue #125)\n";

	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin() {
			return ! empty( $GLOBALS['faz_test_is_admin'] );
		}
	}
	if ( ! function_exists( 'wp_doing_ajax' ) ) {
		function wp_doing_ajax() {
			return false;
		}
	}
	if ( ! function_exists( 'wp_doing_cron' ) ) {
		function wp_doing_cron() {
			return false;
		}
	}
	if ( ! function_exists( 'faz_disable_banner' ) ) {
		function faz_disable_banner() {
			return false;
		}
	}
	if ( ! function_exists( 'faz_is_front_end_request' ) ) {
		function faz_is_front_end_request() {
			return empty( $GLOBALS['faz_test_not_frontend'] );
		}
	}

	/**
	 * Frontend double: is_banner_disabled_by_settings() reads the real
	 * Store-backed settings object (unavailable here), so surface it as a
	 * seedable flag; everything else runs the real code paths.
	 */
	class Faz_FP_Frontend extends Frontend {
		public $banner_disabled = false;
		protected function is_banner_disabled_by_settings() {
			return $this->banner_disabled;
		}
	}

	/** Faz_FP_Frontend with a reflection-seeded settings cache. */
	function faz_fp_frontend( array $settings, $banner_disabled = false ) {
		$fe = ( new ReflectionClass( Faz_FP_Frontend::class ) )->newInstanceWithoutConstructor();
		$p  = new ReflectionProperty( Frontend::class, 'settings_option_cache' );
		$p->setAccessible( true );
		$p->setValue( $fe, $settings );
		$fe->banner_disabled = $banner_disabled;
		return $fe;
	}

	$GLOBALS['faz_test_filters']     = array();
	$GLOBALS['faz_test_is_admin']    = false;
	$GLOBALS['faz_test_not_frontend'] = false;
	Controller::$countryDependent    = false;
	\FazCookie\Frontend\Includes\Geo_Runtime::$enabled = false;

	$dependent_settings = array(
		'banner_control' => array( 'cache_compatibility' => false ),
		'iab'            => array( 'enabled' => true ),
	);

	assert_eq(
		faz_fp_frontend( $dependent_settings )->flying_press_is_cacheable( true ),
		false,
		'country-dependent output → FlyingPress caching vetoed'
	);
	assert_eq(
		faz_fp_frontend( array( 'banner_control' => array( 'cache_compatibility' => false ) ) )->flying_press_is_cacheable( true ),
		true,
		'no country-dependence → FlyingPress verdict untouched'
	);
	assert_eq(
		faz_fp_frontend( array(
			'banner_control' => array( 'cache_compatibility' => true ),
			'iab'            => array( 'enabled' => true ),
		) )->flying_press_is_cacheable( true ),
		true,
		'Cache Compatibility Mode ON → page stays cacheable by FlyingPress'
	);
	assert_eq(
		faz_fp_frontend( $dependent_settings )->flying_press_is_cacheable( false ),
		false,
		'an exclusion decided elsewhere (false in) is never overturned'
	);
	assert_eq(
		faz_fp_frontend( $dependent_settings, true )->flying_press_is_cacheable( true ),
		true,
		'banner disabled by settings → no veto (page never renders the banner)'
	);
	$GLOBALS['faz_test_not_frontend'] = true;
	assert_eq(
		faz_fp_frontend( $dependent_settings )->flying_press_is_cacheable( true ),
		true,
		'non-frontend request (REST/AJAX scope guard) → no veto'
	);
	$GLOBALS['faz_test_not_frontend'] = false;
	$GLOBALS['faz_test_is_admin']     = true;
	assert_eq(
		faz_fp_frontend( $dependent_settings )->flying_press_is_cacheable( true ),
		true,
		'admin request → no veto'
	);
	$GLOBALS['faz_test_is_admin'] = false;

	// --- WPML URL-negotiation exception: resolving the WPML language IS
	// cache-safe even under Cache Compatibility Mode when WPML encodes the
	// language in the URL (directory or domain mode) — a URL-keyed cache then
	// stores one entry per language, exactly like Polylang. Only WPML's
	// parameter/cookie negotiation stays gated to the default (query strings are
	// unreliable cache keys). faz_wpml_language_in_url() gates it.
	echo "\nfaz_current_language() — WPML URL-mode under cache-compat\n";

	// WPML defines this constant; defining it here makes the WPML branch
	// reachable. (Can't be undefined afterwards — placed last on purpose.)
	if ( ! defined( 'ICL_LANGUAGE_CODE' ) ) {
		define( 'ICL_LANGUAGE_CODE', 'it' );
	}
	// Isolate the WPML branch from the TranslatePress stub defined earlier
	// (TRP_PLUGIN_VERSION is a constant and outlives this file's earlier test).
	unset( $GLOBALS['TRP_LANGUAGE'] );

	$GLOBALS['faz_test_options']['faz_settings'] = array(
		'languages'      => array( 'default' => 'en', 'selected' => array( 'en', 'it' ) ),
		'banner_control' => array( 'cache_compatibility' => true ),
	);
	$wpml_negotiation            = 1;
	$GLOBALS['faz_test_filters'] = array(
		'wpml_setting'          => array(
			function ( $value, $name = '' ) use ( &$wpml_negotiation ) {
				return 'language_negotiation_type' === $name ? $wpml_negotiation : $value;
			},
		),
		'wpml_current_language' => array(
			function () {
				return 'it';
			},
		),
	);

	// Directory mode (type 1) → URL-keyed → resolved under cache-compat.
	$wpml_negotiation = 1;
	assert_eq( faz_wpml_language_in_url(), true, 'WPML directory mode (type 1) → language is URL-keyed (cache-safe)' );
	faz_current_language( true );
	assert_eq( faz_current_language(), 'it', 'cache-compat ON + WPML directory mode → per-URL WPML language is honoured' );

	// Domain mode (type 2) → also URL-keyed → resolved under cache-compat.
	$wpml_negotiation = 2;
	assert_eq( faz_wpml_language_in_url(), true, 'WPML domain mode (type 2) → language is URL-keyed (cache-safe)' );
	faz_current_language( true );
	assert_eq( faz_current_language(), 'it', 'cache-compat ON + WPML domain mode → per-URL WPML language is honoured' );

	// Parameter mode (type 3) → query string, not a reliable cache key → gated.
	$wpml_negotiation = 3;
	assert_eq( faz_wpml_language_in_url(), false, 'WPML parameter mode (type 3) → not URL-keyed (query strings unreliable in caches)' );
	faz_current_language( true );
	assert_eq( faz_current_language(), 'en', 'cache-compat ON + WPML parameter mode → language stays gated to the site default' );

	$GLOBALS['faz_test_filters'] = array();

	echo "\n";
	if ( $tests_failed > 0 ) {
		echo "\033[31m✗ {$tests_failed} failed\033[0m, {$tests_passed} passed ({$tests_run} total)\n";
		exit( 1 );
	}
	echo "\033[32m✓ all {$tests_passed} passed\033[0m ({$tests_run} total)\n";
	exit( 0 );
}
