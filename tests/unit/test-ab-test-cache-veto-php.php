<?php
/**
 * A server-side A/B split must not be frozen by a page cache.
 *
 * maybe_apply_ab_test() draws the variant per visitor (wp_rand before any
 * action, the recorded __scope.banner after). With nothing keeping that page
 * out of the cache, the first MISS's variant became the copy every later
 * visitor received: the experiment measured one arm and the banner recorded
 * with a visitor's consent no longer matched the one served. The split now
 * runs only behind the full page-cache veto, and falls back to the normally
 * routed banner (identical for everyone) when the veto cannot be installed.
 *
 * Run: php tests/unit/test-ab-test-cache-veto-php.php
 */
namespace FazCookie\Frontend {
	function header( $line, $replace = true ) {
		$GLOBALS['ab_headers'][] = $line;
	}
	function headers_sent() {
		return ! empty( $GLOBALS['ab_headers_sent'] );
	}
}
namespace FazCookie\Includes {
	class Geolocation {
		public static $eu_countries = array( 'IT' );
		public static function has_country_source() { return false; }
	}
}
namespace FazCookie\Admin\Modules\Banners\Includes {
	class Controller {
		public static $banners = array();
		public static function get_instance() { return new self(); }
		public function filter_active_variant_slugs( $slugs ) { return array_values( array_intersect( $slugs, array_keys( self::$banners ) ) ); }
		public function get_active_banner_by_slug( $slug ) { return isset( self::$banners[ $slug ] ) ? self::$banners[ $slug ] : false; }
		public function has_country_dependent_banners() { return false; }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['ab_headers']      = array();
	$GLOBALS['ab_headers_sent'] = false;
	$GLOBALS['ab_actions']      = array();

	function apply_filters( $tag, $value ) { return $value; }
	function do_action( $tag, ...$args ) { $GLOBALS['ab_actions'][] = $tag; }
	function add_action() { return true; }
	function add_filter() { return true; }
	function get_option( $name, $default = false ) { return 'faz_settings' === $name ? array() : $default; }
	function sanitize_title( $value ) { return strtolower( trim( (string) $value ) ); }
	function wp_rand( $min, $max ) { return $max; } // Always the last variant.
	function faz_parse_consent_cookie() { return array(); }

	require dirname( __DIR__, 2 ) . '/includes/class-ab-test.php';
	require dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;
	use FazCookie\Admin\Modules\Banners\Includes\Controller;

	class Faz_AB_Banner {
		private $slug;
		public function __construct( $slug ) { $this->slug = $slug; }
		public function get_slug() { return $this->slug; }
		public function get_law() { return 'gdpr'; }
		public function get_settings() { return array(); }
		public function get_target_countries() { return array(); }
	}
	class Faz_AB_Settings {
		public function get( $group, $key ) {
			return ( 'banner_control' === $group && 'ab_test' === $key )
				? array( 'status' => true, 'variants' => array( 'a', 'b' ) )
				: null;
		}
	}
	Controller::$banners = array( 'a' => new Faz_AB_Banner( 'a' ), 'b' => new Faz_AB_Banner( 'b' ) );

	$passed = 0;
	$failed = 0;
	function ab_check( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) { $passed++; echo "  PASS {$label}\n"; return; }
		$failed++;
		echo "  FAIL {$label}\n";
	}
	function ab_frontend() {
		$fe = ( new ReflectionClass( Frontend::class ) )->newInstanceWithoutConstructor();
		foreach ( array( 'settings' => new Faz_AB_Settings(), 'geo_bootstrap_active_cache' => false, 'settings_option_cache' => array() ) as $name => $value ) {
			$p = new ReflectionProperty( Frontend::class, $name );
			$p->setAccessible( true );
			$p->setValue( $fe, $value );
		}
		return $fe;
	}
	function ab_pick( $fe ) {
		$m = new ReflectionMethod( Frontend::class, 'maybe_apply_ab_test' );
		$m->setAccessible( true );
		return $m->invoke( $fe, Controller::$banners['a'], '' );
	}
	function ab_vetoed() {
		foreach ( $GLOBALS['ab_headers'] as $line ) {
			if ( 0 === stripos( $line, 'Cache-Control:' ) && false !== strpos( $line, 'no-store' ) ) {
				return true;
			}
		}
		return false;
	}

	echo "A/B split behind the page-cache veto\n";
	$fe     = ab_frontend();
	$chosen = ab_pick( $fe );
	ab_check( 'b' === $chosen->get_slug(), 'the split still runs (variant b drawn)' );
	ab_check( ab_vetoed(), 'and the response carries the no-store veto' );
	ab_check( in_array( 'litespeed_control_set_nocache', $GLOBALS['ab_actions'], true ), 'LiteSpeed told through its action' );
	ab_check( defined( 'DONOTCACHEPAGE' ), 'DONOTCACHEPAGE defined' );

	echo "Veto impossible: no split\n";
	$GLOBALS['ab_headers']      = array();
	$GLOBALS['ab_headers_sent'] = true;
	$fe     = ab_frontend();
	$chosen = ab_pick( $fe );
	ab_check( 'a' === $chosen->get_slug(), 'the normally routed banner is served to everyone' );

	echo "ab-test-cache-veto: {$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
