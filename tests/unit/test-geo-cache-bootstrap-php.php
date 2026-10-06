<?php
/**
 * Cache-safe jurisdiction bootstrap — PHP readiness and strict-shell tests.
 *
 * This standalone harness proves that enabling the optimisation does not trust
 * request geolocation for the cacheable document. The explicit catalogue
 * fallback must load, a global active GDPR banner must exist, and unsupported
 * country-dependent output must keep the existing page-cache veto.
 */

namespace FazCookie\Includes {
	class Store {}
	class Geolocation {
		/**
		 * Stands in for a usable local GeoLite2 database.
		 *
		 * @var bool
		 */
		public static $has_database = false;

		public static function get_visitor_country() {
			return 'US';
		}

		/**
		 * Mirror of the real predicate, which moved out of Frontend in 1.34.0.
		 *
		 * This IS a duplicate, and a duplicate cannot fail when the original
		 * changes. It is deliberate: this suite drives Frontend's cache-veto
		 * branches, and standing up the real Geolocation here would drag in the
		 * MMDB reader, the transient layer and the uploads directory to test
		 * something that is not what these cases are about. The real predicate
		 * is exercised directly by test-geolocation-source-status-php.php, which
		 * loads includes/class-geolocation.php and drives it with files on disk.
		 * If you change has_country_source(), that file is the one that fails —
		 * and this double should be brought back into line by hand.
		 *
		 * The shape matters as much as the answer: these cases turn mod_geoip,
		 * the PHP extension and the trust filters on and off one at a time, and
		 * a double that returned a fixed value would quietly pass them all.
		 */
		public static function has_country_source() {
			$has_source = false;
			if ( \apply_filters( 'faz_trust_cf_ipcountry_header', false ) ) {
				$has_source = true;
			}
			if ( ! $has_source && \apply_filters( 'faz_trust_geoip_country_code', false ) ) {
				$has_source = true;
			}
			if ( ! $has_source && self::mod_geoip_configured() ) {
				$has_source = true;
			}
			if ( ! $has_source && \function_exists( 'geoip_country_code_by_name' ) ) {
				$has_source = true;
			}
			if ( ! $has_source ) {
				$has_source = (bool) self::$has_database;
			}
			return (bool) \apply_filters( 'faz_has_country_signal_source', $has_source );
		}

		public static function mod_geoip_configured() {
			if ( \function_exists( 'apache_get_modules' ) ) {
				$modules = \apache_get_modules();
				if ( \is_array( $modules ) && \in_array( 'mod_geoip', $modules, true ) ) {
					return true;
				}
			}
			return ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] );
		}
	}
	class Known_Providers {}
}

namespace FazCookie\Frontend\Includes {
	class Geo_Runtime {
		public static $enabled       = true;
		public static $resolve_calls = 0;

		public static function is_enabled() {
			return self::$enabled;
		}

		public static function model_to_law( $ruleset ) {
			return isset( $ruleset['model'] ) && 0 === strpos( (string) $ruleset['model'], 'opt-out' ) ? 'ccpa' : 'gdpr';
		}

		public static function resolve_for_country( $country ) {
			unset( $country );
			++self::$resolve_calls;
			return array( 'id' => 'request-california', 'model' => 'opt-out-with-sensitive-opt-in' );
		}
	}
}

namespace FazCookie\Admin\Modules\Geo_Routing\Includes {
	class Ruleset_Loader {
		public static $ruleset = array(
			'id'    => 'fallback-gdpr-most-protective',
			'model' => 'opt-in',
		);
		public static $loaded_ids = array();

		public static function get_instance() {
			return new self();
		}

		public function load_ruleset( $id ) {
			self::$loaded_ids[] = $id;
			return self::$ruleset;
		}
	}
}

namespace FazCookie\Admin\Modules\Banners\Includes {
	class Controller {
		public static $strict_banner     = true;
		public static $country_dependent = false;

		public static function get_instance() {
			return new self();
		}

		public function has_active_banner_for_law( $law, $country = '' ) {
			return self::$strict_banner && 'gdpr' === $law && '' === $country;
		}

		public function has_country_dependent_banners() {
			return self::$country_dependent;
		}
	}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Category_Controller {}
	class Cookie_Categories {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'FAZ_VERSION' ) ) {
		define( 'FAZ_VERSION', 'test' );
	}

	$GLOBALS['faz_geo_bootstrap_filters'] = array();
	$GLOBALS['faz_geo_bootstrap_multilingual'] = true;
	function apply_filters( $tag, $value ) {
		$args = array_slice( func_get_args(), 2 );
		foreach ( isset( $GLOBALS['faz_geo_bootstrap_filters'][ $tag ] ) ? $GLOBALS['faz_geo_bootstrap_filters'][ $tag ] : array() as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
		}
		return $value;
	}
	function add_filter( $tag, $callback ) {
		$GLOBALS['faz_geo_bootstrap_filters'][ $tag ][] = $callback;
		return true;
	}
	function add_action() {
		return true;
	}
	function get_option( $name, $default = false ) {
		return isset( $GLOBALS['faz_geo_bootstrap_options'][ $name ] ) ? $GLOBALS['faz_geo_bootstrap_options'][ $name ] : $default;
	}
	function is_admin() {
		return false;
	}
	function wp_doing_ajax() {
		return false;
	}
	function wp_doing_cron() {
		return false;
	}
	function faz_disable_banner() {
		return false;
	}
	function faz_is_front_end_request() {
		return true;
	}
	function faz_i18n_is_multilingual() {
		return $GLOBALS['faz_geo_bootstrap_multilingual'];
	}

	// The one predicate every cache-compat consumer now shares. Required as the
	// REAL file rather than stubbed: a double here would be a second copy of
	// the logic whose whole point is that there is only one.
	require_once dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Admin\Modules\Banners\Includes\Controller;
	use FazCookie\Admin\Modules\Geo_Routing\Includes\Ruleset_Loader;
	use FazCookie\Frontend\Frontend;
	use FazCookie\Frontend\Includes\Geo_Runtime;
	use FazCookie\Includes\Geolocation;

	class Faz_Geo_Bootstrap_Frontend extends Frontend {
		protected function is_banner_disabled_by_settings() {
			return false;
		}
	}

	$passed = 0;
	$failed = 0;
	// CLI PHP has no apache_get_modules(); the predicate under test asks whether
	// the MODULE is configured rather than whether this request carries its
	// header, so the suite has to be able to stand one up. Driven by a global so
	// a case can present the module as loaded, absent, or (unset) unavailable.
	function apache_get_modules() {
		return isset( $GLOBALS['faz_fake_apache_modules'] )
			? (array) $GLOBALS['faz_fake_apache_modules']
			: array();
	}

	function faz_geo_bootstrap_same( $actual, $expected, $label ) {
		global $passed, $failed;
		if ( $actual === $expected ) {
			++$passed;
			echo "  \033[32mPASS\033[0m {$label}\n";
			return;
		}
		++$failed;
		echo "  \033[31mFAIL\033[0m {$label}\n";
		echo '       expected: ' . var_export( $expected, true ) . "\n";
		echo '       actual:   ' . var_export( $actual, true ) . "\n";
	}

	function faz_geo_bootstrap_frontend( array $settings = array() ) {
		$frontend = ( new \ReflectionClass( Faz_Geo_Bootstrap_Frontend::class ) )->newInstanceWithoutConstructor();
		$property = new \ReflectionProperty( Frontend::class, 'settings_option_cache' );
		$property->setAccessible( true );
		$property->setValue( $frontend, $settings );
		return $frontend;
	}

	function faz_geo_bootstrap_private( $frontend, $method ) {
		$reflection = new \ReflectionMethod( Frontend::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( $frontend );
	}

	function faz_geo_bootstrap_reset() {
		$GLOBALS['faz_geo_bootstrap_filters'] = array();
		Controller::$strict_banner            = true;
		Controller::$country_dependent        = false;
		Ruleset_Loader::$ruleset              = array( 'id' => 'fallback-gdpr-most-protective', 'model' => 'opt-in' );
		Ruleset_Loader::$loaded_ids           = array();
		Geo_Runtime::$enabled                 = true;
		Geo_Runtime::$resolve_calls           = 0;
		$GLOBALS['faz_geo_bootstrap_multilingual'] = true;
	}

	echo "Cache-safe jurisdiction bootstrap — PHP\n\n";

	faz_geo_bootstrap_reset();
	$off = faz_geo_bootstrap_frontend();
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $off, 'is_geo_bootstrap_cache_active' ), false, 'default OFF preserves the country-dependent cache veto' );
	faz_geo_bootstrap_same( Frontend::get_geo_bootstrap_status( array() )['reason'], 'disabled', 'admin readiness explains that the saved optimisation is off' );
	// A country source must exist for the page to be country-dependent at all.
	// Since 1.28.0 the veto is gated on one: an install with no way to resolve a
	// country renders the same fallback ruleset for every visitor, and was losing
	// its page cache for a variation that cannot occur. Declaring the source here
	// is what makes this case assert the thing it means.
	$_SERVER['GEOIP_COUNTRY_CODE'] = 'IT';
	faz_geo_bootstrap_same( $off->flying_press_is_cacheable( true ), false, 'FlyingPress remains uncacheable when bootstrap is not explicitly enabled' );
	unset( $_SERVER['GEOIP_COUNTRY_CODE'] );

	// ...and the new branch: same configuration, no country source at all.
	// Pinned through the filter rather than left to the environment: without it
	// has_country_signal_source() would still find geoip_country_code_by_name()
	// wherever the PHP GeoIP extension happens to be installed, and this case
	// would fail for the machine it runs on rather than for the behaviour it
	// tests.
	add_filter( 'faz_has_country_signal_source', static function () { return false; } );
	faz_geo_bootstrap_same( $off->flying_press_is_cacheable( true ), true, 'with no country source the page stays cacheable - the veto would buy nothing' );
	unset( $GLOBALS['faz_geo_bootstrap_filters']['faz_has_country_signal_source'] );

	// Cloudflare counts as a CONFIGURED source the moment the publisher trusts the
	// header — not only on requests that happen to carry it. Requiring the header
	// made the answer depend on who was asking: a cache warmer, or anything that
	// reaches the origin without passing through Cloudflare, carries no
	// CF-IPCountry and would have been told "no source". Its un-vetoed response
	// then sits in the cache waiting for a real visitor whose country the header
	// DID identify, and hands them the fallback ruleset and banner.
	//
	// Note there is deliberately NO CF-IPCountry in $_SERVER here: that absence is
	// the whole point of the case.
	add_filter( 'faz_trust_cf_ipcountry_header', static function () { return true; } );
	faz_geo_bootstrap_same( $off->flying_press_is_cacheable( true ), false, 'a trusted CF header counts as a source even on a request that lacks it (cache-warmer leak)' );
	unset( $GLOBALS['faz_geo_bootstrap_filters']['faz_trust_cf_ipcountry_header'] );

	// The SAME leak through mod_geoip, which the CF fix left behind — and whose
	// comment asserted CF "was the one place", three lines above a branch doing
	// exactly the same thing. Nothing pinned this predicate directly: every case
	// above reaches it through the filter or through a header it sets itself, so
	// the sibling could not fail a test.
	//
	// GEOIP_COUNTRY_CODE is derived from REMOTE_ADDR, so a warmer on loopback
	// carries none. The case therefore asserts the veto WITHOUT the header,
	// which is the whole scenario — and it has to reach the real branch to mean
	// anything. Going through `faz_has_country_signal_source` would have passed
	// before the fix as well; standing up apache_get_modules(), which CLI PHP
	// does not have, is what makes this case able to fail.
	$GLOBALS['faz_fake_apache_modules'] = array( 'mod_rewrite', 'mod_geoip' );
	faz_geo_bootstrap_same(
		$off->flying_press_is_cacheable( true ),
		false,
		'mod_geoip being LOADED vetoes the cache on a request that carries no country header (cache-warmer leak)'
	);

	// The other direction, so the case cannot pass by always vetoing: the module
	// absent and no header means no source, and the page stays cacheable.
	$GLOBALS['faz_fake_apache_modules'] = array( 'mod_rewrite' );
	faz_geo_bootstrap_same(
		$off->flying_press_is_cacheable( true ),
		true,
		'without mod_geoip and without a header there is no source, so the cache is kept'
	);
	unset( $GLOBALS['faz_fake_apache_modules'] );

	// And the direction that must NOT change: a request that merely happens to
	// carry the header still counts, so hosts running mod_geoip under PHP-FPM
	// keep the veto they had before.
	$_SERVER['GEOIP_COUNTRY_CODE'] = 'IT';
	faz_geo_bootstrap_same(
		$off->flying_press_is_cacheable( true ),
		false,
		'the mod_geoip header still counts as a source where the module cannot be enumerated'
	);
	unset( $_SERVER['GEOIP_COUNTRY_CODE'] );

	faz_geo_bootstrap_reset();
	$saved_opt_in_settings = array(
		'geolocation' => array(
			'geo_targeting'       => true,
			'cache_geo_bootstrap' => true,
			'default_behavior'    => 'show_banner',
		),
		'iab' => array( 'enabled' => false ),
	);
	$saved_opt_in = faz_geo_bootstrap_frontend( $saved_opt_in_settings );
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $saved_opt_in, 'is_geo_bootstrap_cache_active' ), true, 'the persisted UI setting activates a ready strict-shell bootstrap without PHP' );
	faz_geo_bootstrap_same( Frontend::get_geo_bootstrap_status( $saved_opt_in_settings )['reason'], 'ready', 'admin readiness and the frontend share the same ready gate' );

	faz_geo_bootstrap_reset();
	add_filter( 'faz_cache_vary_by_law', '__return_true' );
	if ( ! function_exists( '__return_true' ) ) {
		function __return_true() {
			return true;
		}
	}
	$legacy_opt_in = faz_geo_bootstrap_frontend();
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $legacy_opt_in, 'is_geo_bootstrap_cache_active' ), true, 'the existing vary-by-law opt-in migrates to the safe one-shell bootstrap' );

	faz_geo_bootstrap_reset();
	add_filter( 'faz_cache_geo_bootstrap', '__return_true' );
	$active = faz_geo_bootstrap_frontend();
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $active, 'is_geo_bootstrap_cache_active' ), true, 'complete readiness gate activates the strict-shell bootstrap' );
	faz_geo_bootstrap_same( Ruleset_Loader::$loaded_ids[0], 'fallback-gdpr-most-protective', 'readiness loads the strict fallback by explicit catalogue id' );
	$strict = faz_geo_bootstrap_private( $active, 'get_runtime_ruleset' );
	faz_geo_bootstrap_same( $strict['id'], 'fallback-gdpr-most-protective', 'cacheable PHP render uses the explicit GDPR fallback' );
	faz_geo_bootstrap_same( Geo_Runtime::$resolve_calls, 0, 'strict shell never re-detects the request country' );
	faz_geo_bootstrap_same( $active->flying_press_is_cacheable( true ), true, 'FlyingPress may cache the visitor-invariant strict shell' );
	faz_geo_bootstrap_private( $active, 'maybe_disable_country_page_cache' );
	faz_geo_bootstrap_same( defined( 'DONOTCACHEPAGE' ), false, 'strict shell does not define the generic page-cache veto' );

	faz_geo_bootstrap_reset();
	add_filter( 'faz_cache_geo_bootstrap', '__return_true' );
	Ruleset_Loader::$ruleset = array( 'id' => 'unsafe', 'model' => 'opt-out-with-sensitive-opt-in' );
	$unsafe_fallback = faz_geo_bootstrap_frontend();
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $unsafe_fallback, 'is_geo_bootstrap_cache_active' ), false, 'a non-GDPR fallback fails the gate closed' );
	faz_geo_bootstrap_same( Frontend::get_geo_bootstrap_status( array() )['reason'], 'missing_strict_ruleset', 'admin readiness reports the strict-ruleset failure' );

	faz_geo_bootstrap_reset();
	add_filter( 'faz_cache_geo_bootstrap', '__return_true' );
	Controller::$strict_banner = false;
	$no_banner = faz_geo_bootstrap_frontend();
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $no_banner, 'is_geo_bootstrap_cache_active' ), false, 'no active global GDPR banner fails the gate closed' );
	faz_geo_bootstrap_same( Frontend::get_geo_bootstrap_status( array() )['reason'], 'missing_gdpr_banner', 'admin readiness reports the missing strict banner' );

	faz_geo_bootstrap_reset();
	add_filter( 'faz_cache_geo_bootstrap', '__return_true' );
	Controller::$country_dependent = true;
	$country_banner = faz_geo_bootstrap_frontend();
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $country_banner, 'is_geo_bootstrap_cache_active' ), false, 'unsupported country-scoped banner output keeps the cache veto' );
	faz_geo_bootstrap_same( Frontend::get_geo_bootstrap_status( array() )['reason'], 'country_banners', 'admin readiness reports country-targeted banner rows' );

	faz_geo_bootstrap_reset();
	add_filter( 'faz_cache_geo_bootstrap', '__return_true' );
	$iab = faz_geo_bootstrap_frontend( array( 'iab' => array( 'enabled' => true ) ) );
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $iab, 'is_geo_bootstrap_cache_active' ), false, 'IAB output cannot enter the bootstrap until its payload is client-resolved' );
	faz_geo_bootstrap_same( Frontend::get_geo_bootstrap_status( array( 'iab' => array( 'enabled' => true ) ) )['reason'], 'iab', 'admin readiness reports IAB as the fallback reason' );

	faz_geo_bootstrap_reset();
	add_filter( 'faz_cache_geo_bootstrap', '__return_true' );
	$no_banner_geo = faz_geo_bootstrap_frontend(
		array( 'geolocation' => array( 'geo_targeting' => true, 'default_behavior' => 'no_banner' ) )
	);
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $no_banner_geo, 'is_geo_bootstrap_cache_active' ), false, 'global no-banner geo targeting keeps the cache veto' );
	faz_geo_bootstrap_same(
		Frontend::get_geo_bootstrap_status( array( 'geolocation' => array( 'geo_targeting' => true, 'default_behavior' => 'no_banner' ) ) )['reason'],
		'no_banner',
		'admin readiness reports country-dependent banner visibility'
	);

	faz_geo_bootstrap_reset();
	add_filter( 'faz_cache_geo_bootstrap', '__return_true' );
	add_filter( 'faz_country_dependent_banner_output', '__return_true' );
	$custom_dependent = faz_geo_bootstrap_frontend();
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $custom_dependent, 'is_geo_bootstrap_cache_active' ), false, 'a custom country-dependent integration keeps the cache veto' );
	faz_geo_bootstrap_same( Frontend::get_geo_bootstrap_status( array() )['reason'], 'custom_output', 'admin readiness reports custom country-dependent output' );

	faz_geo_bootstrap_reset();
	$GLOBALS['faz_geo_bootstrap_multilingual'] = false;
	add_filter( 'faz_cache_geo_bootstrap', '__return_true' );
	add_filter( 'faz_use_country_language_fallback', '__return_true' );
	$country_language = faz_geo_bootstrap_frontend();
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $country_language, 'is_geo_bootstrap_cache_active' ), false, 'country-derived language fallback keeps the cache veto' );
	faz_geo_bootstrap_same( Frontend::get_geo_bootstrap_status( array() )['reason'], 'country_language', 'admin readiness reports country-language fallback' );

	faz_geo_bootstrap_reset();
	Geo_Runtime::$enabled = false;
	add_filter( 'faz_cache_geo_bootstrap', '__return_true' );
	$cache_compat = faz_geo_bootstrap_frontend(
		array( 'banner_control' => array( 'cache_compatibility' => true ) )
	);
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $cache_compat, 'is_cache_compatibility_enabled' ), true, 'Cache Compatibility Mode activates only after geo runtime is disabled' );
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $cache_compat, 'is_geo_bootstrap_cache_active' ), false, 'Cache Compatibility Mode excludes the strict-shell bootstrap' );
	faz_geo_bootstrap_same( Frontend::get_geo_bootstrap_status( array() )['reason'], 'enforcement_disabled', 'admin readiness reports that enforcement is disabled' );

	// With routing and a country source, the bootstrap still permits shared
	// caching. Visitor-only WPML / translation state must not enter that shell.
	faz_geo_bootstrap_reset();
	require_once dirname( __DIR__, 2 ) . '/includes/class-i18n-helpers.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-translation-compat.php';
	function faz_sanitize_text( $value ) { return is_array( $value ) ? array_map( 'faz_sanitize_text', $value ) : trim( (string) $value ); }
	define( 'ICL_LANGUAGE_CODE', 'en' );
	$language_settings = array(
		'banner_control' => array( 'cache_compatibility' => true ),
		'geolocation'    => array( 'geo_targeting' => true, 'cache_geo_bootstrap' => true ),
		'languages'      => array( 'default' => 'en', 'selected' => array( 'en', 'it', 'fr' ) ),
	);
	$GLOBALS['faz_geo_bootstrap_options']['faz_settings'] = $language_settings;
	Geolocation::$has_database = true;
	add_filter( 'wpml_setting', static function ( $value, $name = '' ) { return 'language_negotiation_type' === $name ? 3 : $value; } );
	add_filter( 'wpml_current_language', static function () { return $GLOBALS['faz_test_visitor_language']; } );
	$translation = ( new \ReflectionClass( '\\FazCookie\\Frontend\\Translation_Compat' ) )->newInstanceWithoutConstructor();
	$language_shell = faz_geo_bootstrap_frontend( $language_settings );
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $language_shell, 'is_geo_bootstrap_cache_active' ), true, 'routing shell bootstrap is active with cache compatibility paused' );
	faz_geo_bootstrap_same( $language_shell->flying_press_is_cacheable( true ), true, 'routing shell remains cacheable with a country source' );
	faz_geo_bootstrap_same( \FazCookie\Includes\Cache_Compatibility::is_shared_cache_render(), true, 'language gate agrees with the cacheable bootstrap shell' );
	foreach ( array( 'it', 'fr' ) as $visitor_language ) {
		$GLOBALS['faz_test_visitor_language'] = $visitor_language;
		$GLOBALS['TRP_LANGUAGE'] = $visitor_language . '_XX';
		faz_current_language( true );
		faz_geo_bootstrap_same( faz_current_language(), 'en', 'bootstrap shell ignores visitor-only language ' . $visitor_language );
		faz_geo_bootstrap_same( $translation->get_translatepress_language( 'en' ), 'en', 'TranslatePress filter preserves bootstrap shell language' );
		faz_geo_bootstrap_same( $translation->get_weglot_language( 'en' ), 'en', 'Weglot filter preserves bootstrap shell language' );
	}
	// URL-based WPML language remains safe even on the shared bootstrap shell.
	//
	// This case runs BEFORE the Weglot mock is defined, and the order is the
	// assertion. faz_current_language() consults Weglot before WPML, and a PHP
	// function definition is permanent: once weglot_get_current_language()
	// exists, every later call resolves through it. With this case after the
	// mock, 'fr' came from Weglot and the WPML branch never executed — the
	// assertion passed without ever reaching the code it names. The predicate
	// is asserted too, so the negotiation type is pinned rather than inferred
	// from the resolved language.
	unset( $GLOBALS['faz_geo_bootstrap_filters']['wpml_setting'] );
	add_filter( 'wpml_setting', static function ( $value, $name = '' ) { return 'language_negotiation_type' === $name ? 1 : $value; } );
	faz_current_language( true );
	faz_geo_bootstrap_same( function_exists( 'weglot_get_current_language' ), false, 'premise: no Weglot source exists yet, so WPML is the one that answers' );
	faz_geo_bootstrap_same( faz_wpml_language_in_url(), true, 'negotiation type 1 puts the WPML language in the URL' );
	faz_geo_bootstrap_same( faz_current_language(), 'fr', 'URL-based WPML language remains available in the shared shell' );
	if ( ! function_exists( 'weglot_get_current_language' ) ) {
		function weglot_get_current_language() { return $GLOBALS['faz_test_visitor_language']; }
	}
	faz_current_language( true );
	faz_geo_bootstrap_same( $translation->get_weglot_language( 'en' ), 'en', 'Weglot visitor cookie cannot change the bootstrap shell language' );
	// Unsupported dimensions and AMP keep the cache veto, so language can vary.
	foreach ( array( 'iab', 'amp' ) as $excluded ) {
		$excluded_settings = $language_settings;
		if ( 'iab' === $excluded ) { $excluded_settings['iab']['enabled'] = true; }
		else { add_filter( 'faz_is_amp_request', '__return_true' ); }
		$GLOBALS['faz_geo_bootstrap_options']['faz_settings'] = $excluded_settings;
		faz_geo_bootstrap_same( \FazCookie\Includes\Cache_Compatibility::is_shared_cache_render(), false, $excluded . ' cannot share the bootstrap shell' );
		$GLOBALS['faz_test_visitor_language'] = 'it';
		faz_current_language( true );
		faz_geo_bootstrap_same( faz_current_language(), 'it', $excluded . ' retains visitor language on uncached pages' );
	}

	echo "\n" . ( 0 === $failed ? "ALL PASS ({$passed})\n" : "FAILED: {$failed}, passed: {$passed}\n" );
	exit( 0 === $failed ? 0 : 1 );
}
