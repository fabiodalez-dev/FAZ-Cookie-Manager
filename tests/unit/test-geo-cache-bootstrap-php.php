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
		public static function get_visitor_country() {
			return 'US';
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

		public function get_active_banner_for_law( $law, $country = '' ) {
			return self::$strict_banner && 'gdpr' === $law && '' === $country ? (object) array( 'slug' => 'strict' ) : false;
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
		return true;
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Admin\Modules\Banners\Includes\Controller;
	use FazCookie\Admin\Modules\Geo_Routing\Includes\Ruleset_Loader;
	use FazCookie\Frontend\Frontend;
	use FazCookie\Frontend\Includes\Geo_Runtime;

	class Faz_Geo_Bootstrap_Frontend extends Frontend {
		protected function is_banner_disabled_by_settings() {
			return false;
		}
	}

	$passed = 0;
	$failed = 0;
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
	}

	echo "Cache-safe jurisdiction bootstrap — PHP\n\n";

	faz_geo_bootstrap_reset();
	$off = faz_geo_bootstrap_frontend();
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $off, 'is_geo_bootstrap_cache_active' ), false, 'default OFF preserves the country-dependent cache veto' );
	faz_geo_bootstrap_same( $off->flying_press_is_cacheable( true ), false, 'FlyingPress remains uncacheable when bootstrap is not explicitly enabled' );

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

	faz_geo_bootstrap_reset();
	add_filter( 'faz_cache_geo_bootstrap', '__return_true' );
	Controller::$strict_banner = false;
	$no_banner = faz_geo_bootstrap_frontend();
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $no_banner, 'is_geo_bootstrap_cache_active' ), false, 'no active global GDPR banner fails the gate closed' );

	faz_geo_bootstrap_reset();
	add_filter( 'faz_cache_geo_bootstrap', '__return_true' );
	Controller::$country_dependent = true;
	$country_banner = faz_geo_bootstrap_frontend();
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $country_banner, 'is_geo_bootstrap_cache_active' ), false, 'unsupported country-scoped banner output keeps the cache veto' );

	faz_geo_bootstrap_reset();
	add_filter( 'faz_cache_geo_bootstrap', '__return_true' );
	$iab = faz_geo_bootstrap_frontend( array( 'iab' => array( 'enabled' => true ) ) );
	faz_geo_bootstrap_same( faz_geo_bootstrap_private( $iab, 'is_geo_bootstrap_cache_active' ), false, 'IAB output cannot enter the bootstrap until its payload is client-resolved' );

	echo "\n" . ( 0 === $failed ? "ALL PASS ({$passed})\n" : "FAILED: {$failed}, passed: {$passed}\n" );
	exit( 0 === $failed ? 0 : 1 );
}
