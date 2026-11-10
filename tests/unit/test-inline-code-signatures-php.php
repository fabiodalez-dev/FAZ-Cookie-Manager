<?php
/**
 * Standalone regression tests: a dotted code signature matches the inline
 * script it names (GitHub issue #396).
 *
 * Subsystem: provider-matching
 *
 * The provider catalogue mixes URL fragments (`js.hsforms.net`) with code
 * signatures (`hbspt.forms.create(`, `mixpanel.init(`). The matcher told them
 * apart by the dot alone, so every dotted signature was treated as a URL and
 * never compared with an inline body. On a page with the legacy HubSpot embed
 * the library tag was held and the inline `hbspt.forms.create()` call ran at
 * once, failed with "hbspt is not defined", and was gone when consent came:
 * the form only appeared after a reload.
 *
 * Run: php tests/unit/test-inline-code-signatures-php.php
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


	function is_code( $pattern ) {
		if ( ! method_exists( Frontend::class, 'is_url_fragment_pattern' ) ) {
			return null;
		}
		$m = new ReflectionMethod( Frontend::class, 'is_url_fragment_pattern' );
		$m->setAccessible( true );
		return ! $m->invoke( null, $pattern );
	}

	echo "\n  inline code signatures (#396)\n";
	echo "  ─────────────────────────────\n";

	echo "\n  A. pattern classification\n";
	foreach ( array( 'hbspt.forms.create(', 'mixpanel.init(', 'ttq.load (', 'new google.maps.', 's.t()', 'fbq(', 'gtag' ) as $p ) {
		eq( is_code( $p ), true, 'A code signature: ' . $p );
	}
	foreach ( array( 'js.hsforms.net', 'js.hs-scripts.com/', 'youtu.be', 'addthis_widget.js', 'AppMeasurement.js', 'dataLayer.push({' ) as $p ) {
		eq( is_code( $p ), false, 'A URL fragment or container data: ' . $p );
	}

	echo "\n  B. the output-buffer matcher reads the inline body\n";
	$map     = array(
		'js.hsforms.net'      => 'marketing',
		'hbspt.forms.create(' => 'marketing',
		'mixpanel.init('      => 'analytics',
		'youtu.be'            => 'marketing',
		'dataLayer.push({'    => 'analytics',
	);
	$blocked = array( 'functional', 'analytics', 'marketing' );
	$fe      = arrange( $map, $blocked );

	eq( callm( $fe, 'match_script_to_provider', array( '', "\n  hbspt.forms.create({ region: \"na1\", portalId: \"1\", formId: \"x\" });\n", $map, $blocked ) ), 'marketing',
		'B1 the legacy HubSpot embed call is held with its library' );
	eq( callm( $fe, 'match_script_to_provider', array( '', 'mixpanel.init("token");', $map, $blocked ) ), 'analytics',
		'B2 another dotted signature (mixpanel.init) matches inline too' );
	eq( callm( $fe, 'match_script_to_provider', array( '', 'var links = {"video":"https://youtu.be/abc"};', $map, $blocked ) ), false,
		'B3 a URL fragment in inline data still does not match (Rank Math guard)' );
	eq( callm( $fe, 'match_script_to_provider', array( '', 'dataLayer.push({"pagePostType":"page"});', $map, $blocked ) ), false,
		'B4 a dataLayer.push({ data push is left for the container, as before' );
	eq( callm( $fe, 'match_script_to_provider', array( ' src="https://js.hsforms.net/forms/embed/v2.js"', '', $map, $blocked ) ), 'marketing',
		'B5 the library tag is still matched by its URL' );

	echo "\n  C. the shipped catalogue\n";
	$catalogue = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/includes/data/known-providers.json' ), true );
	eq( in_array( 'hbspt.forms.create(', $catalogue['hubspot']['patterns'], true ), true,
		'C1 HubSpot carries the call signature hbspt.forms.create(' );
	$template = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/cookies/includes/blocker-templates/hubspot.json' ), true );
	eq( in_array( 'hbspt.forms.create(', $template['patterns'], true ), true,
		'C2 the HubSpot blocker template carries the same signature' );

	echo "\n  " . $passed . ' passed, ' . $failed . " failed\n\n";
	exit( $failed > 0 ? 1 : 0 );
}
