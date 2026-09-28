<?php
/**
 * Standalone regression tests: one matching pattern must not exempt a script
 * that another pattern classifies as blocked.
 *
 * Subsystem: provider-map-shadowing
 *
 * Reported on wordpress.org as "FAZ blocks only one of GTM4WP's two WooCommerce
 * scripts". GTM4WP enqueues `gtm4wp-ecommerce-generic` and `gtm4wp-woocommerce`
 * side by side; both handles contain `gtm4wp`, which the provider database files
 * under Google Tag Manager → analytics. The reporter saw the first blocked and
 * the second not, and attributed it to LiteSpeed's `data-deferred="1"` attribute
 * on the second tag.
 *
 * That attribute is a SYMPTOM, not the cause: LiteSpeed's _parse_js()
 * (optimize.cls.php) skips any script whose type is set and is not
 * text/javascript, so a tag FAZ has already re-typed to `text/plain` is never
 * deferred. The attribute therefore only proves the tag was still live when
 * LiteSpeed saw it.
 *
 * What these tests pin is a latent defect found while chasing that report, NOT
 * a demonstrated explanation of it. The provider map is assembled from four
 * sources in order (cookie DB url_patterns, the provider database, admin custom
 * rules, a developer filter) and both matchers stopped at the FIRST pattern that
 * matched. So when two patterns match one script and the earlier one sits in a
 * category that is not blocked, the later, specific one never got a say and the
 * tracker loaded before consent — with assembly order, which is an accident of
 * how the map is built, deciding the outcome.
 *
 * On a stock install the reported scripts are blocked (verified in a real
 * WooCommerce + GTM4WP page, with and without LiteSpeed), and no reachable
 * configuration was found that reproduces the asymmetry: an admin custom rule
 * for a new pattern is appended after the provider database, so `woocommerce`
 * landed at map position 1018 against `gtm4wp` at 56 and lost by order, and
 * `$this->providers` — the one source that precedes everything — was empty.
 * The defect is therefore fixed as hardening, on a decision where an
 * order-dependent outcome is not acceptable, and the reported site's own cause
 * is still open.
 *
 * These tests pin the three halves of the fix:
 *   A. filter_script_loader_tag() fails closed across every matching pattern.
 *   B. match_script_to_provider() does the same, because every one of its
 *      callers reads a non-blocked category as "let it through".
 *   C. get_provider_category_map() keeps patterns that can never block out of
 *      the map to begin with, as section 2 already did.
 *
 * Run: php tests/unit/test-provider-map-shadowing-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Known_Providers {
		public static function get_all() { return array(); }
		public static function get_cookie_map() { return array(); }
		public static function get_pattern_map() { return $GLOBALS['__faz_known_pattern_map']; }
	}
}

namespace FazCookie\Frontend\Includes {
	class Placeholder_Builder {
		public static function is_embed_service( $service_id ) { return false; }
		public static function build_social( $a, $b, $c ) { return ''; }
	}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Cookie_Categories {
		private $row;
		public function __construct( $row ) { $this->row = (array) $row; }
		public function get_id() { return isset( $this->row['id'] ) ? $this->row['id'] : 0; }
		public function get_slug() { return $this->row['slug']; }
	}
	class Category_Controller {
		public static function get_instance() { return new self(); }
		public function get_items() { return $GLOBALS['__faz_categories']; }
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

	$GLOBALS['__faz_known_pattern_map'] = array();
	$GLOBALS['__faz_categories']        = array(
		array( 'id' => 1, 'slug' => 'necessary' ),
		array( 'id' => 2, 'slug' => 'functional' ),
		array( 'id' => 3, 'slug' => 'analytics' ),
		array( 'id' => 4, 'slug' => 'marketing' ),
	);

	if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return false; } }
	if ( ! function_exists( 'faz_disable_banner' ) ) { function faz_disable_banner() { return false; } }
	if ( ! function_exists( 'get_the_ID' ) ) { function get_the_ID() { return 0; } }
	if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
	if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return false; } }
	if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { return true; } }
	if ( ! function_exists( 'get_option' ) ) { function get_option( $n, $d = array() ) { return $d; } }
	if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
	if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
	if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( wp_strip_all_tags( preg_replace( '/[\r\n\t ]+/', ' ', (string) $s ) ) ); } }
	if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } }
	if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); } }
	if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
	if ( ! function_exists( 'faz_get_valid_consent_cookie' ) ) { function faz_get_valid_consent_cookie() { return ''; } }
	if ( ! function_exists( 'faz_sanitize_bool_strict' ) ) { function faz_sanitize_bool_strict( $v ) { return in_array( $v, array( true, 1, '1', 'true', 'yes', 'on' ), true ); } }

	class FazTest_WPDB {
		public $prefix = 'wp_';
		public function get_col( $q ) { return array(); }
	}
	$GLOBALS['wpdb'] = new FazTest_WPDB();

	/** Banner on, nothing excluded. */
	class FazTest_Settings {
		public function get( $group, $key = null ) {
			if ( 'banner_control' === $group && 'status' === $key ) { return true; }
			return null;
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	$passed = 0;
	$failed = 0;

	function eq( $actual, $expected, $label ) {
		global $passed, $failed;
		if ( $actual === $expected ) {
			++$passed;
			echo "  \033[32m✓\033[0m " . $label . "\n";
		} else {
			++$failed;
			echo "  \033[31m✗\033[0m " . $label . "\n";
			echo "      expected: " . var_export( $expected, true ) . "\n";
			echo "      actual:   " . var_export( $actual, true ) . "\n";
		}
	}

	function setp( $fe, $prop, $value ) {
		$p = new ReflectionProperty( Frontend::class, $prop );
		$p->setAccessible( true );
		$p->setValue( $fe, $value );
	}

	function callm( $fe, $method, array $args = array() ) {
		$m = new ReflectionMethod( Frontend::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $fe, $args );
	}

	/**
	 * A frontend whose provider map and blocked-category list are fixed, so the
	 * only variable under test is how a tag is matched against them.
	 *
	 * @param array $map     Provider map, in the order the assembler produced it.
	 * @param array $blocked Categories blocked on this request.
	 * @return Frontend
	 */
	function arrange( array $map, array $blocked ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		setp( $fe, 'template', '<div id="faz-consent"></div>' );
		setp( $fe, 'settings', new FazTest_Settings() );
		setp( $fe, 'settings_option_cache', array() );
		setp( $fe, 'whitelist_cache', array() );
		setp( $fe, 'provider_map_cache', $map );
		setp( $fe, 'blocked_categories_cache', $blocked );
		setp( $fe, 'service_consent_cache', array() );
		setp( $fe, 'provider_match_meta_cache', null );
		return $fe;
	}

	$dir  = 'https://example.test/wp-content/plugins/duracelltomi-google-tag-manager/dist/js/';
	$gen  = '<script type="text/javascript" src="' . $dir . 'gtm4wp-ecommerce-generic.js?ver=1.22.3" id="gtm4wp-ecommerce-generic-js"></script>';
	// The second tag as LiteSpeed leaves it once it has NOT been blocked.
	$woo  = '<script type="text/javascript" src="' . $dir . 'gtm4wp-woocommerce.js?ver=1.22.3" id="gtm4wp-woocommerce-js" defer data-deferred="1"></script>';
	$woo_plain = '<script type="text/javascript" src="' . $dir . 'gtm4wp-woocommerce.js?ver=1.22.3" id="gtm4wp-woocommerce-js"></script>';

	function is_blocked( $tag ) {
		return false !== strpos( $tag, 'text/plain' ) && false !== strpos( $tag, 'data-faz-category' );
	}

	echo "\n  provider-map shadowing (GTM4WP, wordpress.org report)\n";
	echo "  ────────────────────────────────────────────────────\n";

	// ===== Group A — filter_script_loader_tag =====
	echo "\n  A. the tag filter fails closed across every matching pattern\n";

	// The reported shape: a `necessary` generic pattern ahead of the specific one.
	$map     = array( 'woocommerce' => 'necessary', 'gtm4wp' => 'analytics', 'gtm4wp-' => 'analytics' );
	$blocked = array( 'functional', 'analytics', 'marketing' );

	$fe = arrange( $map, $blocked );
	eq( is_blocked( $fe->filter_script_loader_tag( $woo, 'gtm4wp-woocommerce', $dir . 'gtm4wp-woocommerce.js' ) ), true,
		'A1 gtm4wp-woocommerce is blocked although a necessary `woocommerce` pattern matches first' );

	$fe = arrange( $map, $blocked );
	eq( is_blocked( $fe->filter_script_loader_tag( $gen, 'gtm4wp-ecommerce-generic', $dir . 'gtm4wp-ecommerce-generic.js' ) ), true,
		'A2 its sibling gtm4wp-ecommerce-generic stays blocked (the half that already worked)' );

	$fe = arrange( $map, $blocked );
	eq( is_blocked( $fe->filter_script_loader_tag( $woo_plain, 'gtm4wp-woocommerce', $dir . 'gtm4wp-woocommerce.js' ) ), true,
		'A3 the same handle without LiteSpeed\'s attributes behaves identically — the attribute was never the cause' );

	// A consented category shadows just as well as `necessary`, and the cookie DB
	// and the admin rule editor can both produce one.
	$map_fn = array( 'woocommerce' => 'functional', 'gtm4wp' => 'analytics' );
	$fe     = arrange( $map_fn, array( 'analytics', 'marketing' ) );
	eq( is_blocked( $fe->filter_script_loader_tag( $woo, 'gtm4wp-woocommerce', $dir . 'gtm4wp-woocommerce.js' ) ), true,
		'A4 a pattern in a category the visitor ACCEPTED does not release a tracker whose own category is refused' );

	// Order must stop mattering in both directions.
	$map_rev = array( 'gtm4wp' => 'analytics', 'woocommerce' => 'necessary' );
	$fe      = arrange( $map_rev, $blocked );
	eq( is_blocked( $fe->filter_script_loader_tag( $woo, 'gtm4wp-woocommerce', $dir . 'gtm4wp-woocommerce.js' ) ), true,
		'A5 the reverse order blocks too — assembly order no longer decides' );

	// And the fix must not start blocking what is genuinely allowed.
	$fe = arrange( array( 'woocommerce' => 'necessary' ), $blocked );
	eq( is_blocked( $fe->filter_script_loader_tag( $woo, 'gtm4wp-woocommerce', $dir . 'gtm4wp-woocommerce.js' ) ), false,
		'A6 a script whose ONLY match is a non-blocked category is still left alone' );

	$fe = arrange( $map, $blocked );
	$untouched = '<script type="text/javascript" src="https://example.test/wp-content/themes/x/app.js" id="theme-app-js"></script>';
	eq( $fe->filter_script_loader_tag( $untouched, 'theme-app', 'https://example.test/wp-content/themes/x/app.js' ), $untouched,
		'A7 a script that matches nothing is returned byte-identical' );

	// ===== Group B — match_script_to_provider (the output-buffer layer) =====
	//
	// This layer does NOT match with a plain substring: provider_pattern_matches_lc()
	// requires the pattern to sit on a separator boundary, and a hyphen is not one.
	// So `gtm4wp` inside `gtm4wp-woocommerce.js` matches nothing here — the tag
	// filter is the only layer that catches GTM4WP's handles, which is asserted
	// below so the difference between the two layers stays visible. The
	// preference rule is therefore exercised on a URL where both patterns do sit
	// on boundaries.
	echo "\n  B. the output-buffer matcher answers with the blocked category\n";

	$both = ' src="https://cdn.example/woocommerce/gtm4wp.js" ';

	$fe = arrange( $map, $blocked );
	eq( callm( $fe, 'match_script_to_provider', array( $both, '', $map ) ), 'analytics',
		'B1 a tag matching both patterns resolves to analytics, not to the necessary one that comes first' );

	$fe = arrange( $map_fn, array( 'analytics', 'marketing' ) );
	eq( callm( $fe, 'match_script_to_provider', array( $both, '', $map_fn ) ), 'analytics',
		'B2 the same holds when the shadowing category is merely one the visitor consented to' );

	$only_allowed = array( 'woocommerce' => 'necessary' );
	$fe           = arrange( $only_allowed, $blocked );
	eq( callm( $fe, 'match_script_to_provider', array( $both, '', $only_allowed ) ), 'necessary',
		'B3 with no blocked pattern in play the allowed category is still returned — callers rely on it' );

	$fe = arrange( $map, $blocked );
	eq( callm( $fe, 'match_script_to_provider', array( ' src="https://example.test/app.js" ', '', $map ) ), false,
		'B4 no match still returns false' );

	// Pins the boundary rule itself: this is why the reported tags were only ever
	// the tag filter's business, and why a fix there alone would have been enough
	// for THIS report but not for the class of defect.
	$fe = arrange( $map, $blocked );
	eq( callm( $fe, 'match_script_to_provider', array( ' src="' . $dir . 'gtm4wp-woocommerce.js" ', '', $map ) ), false,
		'B5 the real GTM4WP URL matches neither pattern in this layer (hyphen is not a boundary)' );

	// ===== Group C — the map itself =====
	//
	// An invariant, not a reproduction: the two methods that populate
	// $this->providers already skip the necessary category and take the slug from
	// a live category object, so nothing that these four assertions reject can
	// reach the property through today's code. They are here because section 2 of
	// the same function has always carried both checks, and a map that decides
	// pre-consent loading should state its own contract rather than inherit it
	// from code two files away.
	echo "\n  C. patterns that can never block stay out of the map (invariant)\n";

	function arrange_map( array $providers ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		setp( $fe, 'settings', new FazTest_Settings() );
		setp( $fe, 'settings_option_cache', array() );
		setp( $fe, 'provider_map_cache', null );
		setp( $fe, 'providers', $providers );
		return $fe;
	}

	$fe  = arrange_map( array( 'woocommerce' => array( 'necessary' ), 'gtm4wp' => array( 'analytics' ) ) );
	$out = callm( $fe, 'get_provider_category_map' );
	eq( isset( $out['woocommerce'] ), false, 'C1 a cookie-DB pattern filed as necessary is dropped, as section 2 already dropped its own' );
	eq( isset( $out['gtm4wp'] ) ? $out['gtm4wp'] : null, 'analytics', 'C2 the blockable pattern survives' );

	$fe  = arrange_map( array( 'legacy' => array( 'performance' ) ) );
	$out = callm( $fe, 'get_provider_category_map' );
	eq( isset( $out['legacy'] ), false, 'C3 a slug that no longer exists is dropped — it could only ever shadow' );

	$fe  = arrange_map( array( 'mixed' => array( 'necessary', 'marketing' ) ) );
	$out = callm( $fe, 'get_provider_category_map' );
	eq( isset( $out['mixed'] ) ? $out['mixed'] : null, 'marketing', 'C4 a pattern carrying both keeps the blockable category instead of being discarded' );

	echo "\n  ────────────────────────────────────────────────────\n";
	echo "  Passed: {$passed}; Failed: {$failed}\n\n";

	exit( $failed > 0 ? 1 : 0 );
}
