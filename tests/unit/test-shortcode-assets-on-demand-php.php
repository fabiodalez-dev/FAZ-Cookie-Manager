<?php
/**
 * Standalone unit tests — shortcode assets load where the shortcode is (#308).
 *
 * The DSAR form JS, the Do-Not-Sell form JS and the Cookie Policy CSS were
 * enqueued on every frontend page of every site, shortcode or not; the CSS is
 * render-blocking. They now come from their shortcode callbacks, the policy
 * CSS also goes in <head> on a singular page whose content holds the
 * shortcode, and `faz_load_shortcode_assets_everywhere` restores the old
 * behaviour for builders that inject the markup client-side.
 *
 * Run: php tests/unit/test-shortcode-assets-on-demand-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes {
	class Renderer {
		public static function render( $atts ) {
			return '<div class="faz-cookie-policy"></div>';
		}
	}
}

namespace FazCookie\Admin\Modules\Cookie_Policy_Generator\Api {
	class Cookie_Policy_Api {
		public static function get_instance() {
			return new self();
		}
		public function init() {}
	}
}

namespace {

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'FAZ_PLUGIN_URL', 'https://example.test/wp-content/plugins/faz-cookie-manager/' );
	define( 'FAZ_PLUGIN_BASEPATH', dirname( __DIR__, 2 ) . '/' );
	define( 'FAZ_VERSION', '9.9.9' );

	class WP_Post {
		public $post_content = '';
		public function __construct( $content ) {
			$this->post_content = $content;
		}
	}

	$GLOBALS['__faz_enqueued_scripts'] = array();
	$GLOBALS['__faz_enqueued_styles']  = array();
	$GLOBALS['__faz_registered']       = array();
	$GLOBALS['__faz_everywhere']       = array();
	$GLOBALS['__faz_singular']         = false;
	$GLOBALS['__faz_queried']          = null;
	$GLOBALS['__faz_is_admin']         = false;

	function add_shortcode( $tag, $cb ) {}
	function add_action( $hook, $cb, $priority = 10, $args = 1 ) {}
	function is_admin() {
		return $GLOBALS['__faz_is_admin'];
	}
	function __( $text, $domain = '' ) {
		return $text;
	}
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . $path;
	}
	function apply_filters( $tag, $value, ...$args ) {
		if ( 'faz_load_shortcode_assets_everywhere' === $tag ) {
			return in_array( $args[0], $GLOBALS['__faz_everywhere'], true );
		}
		return $value;
	}
	function wp_script_is( $handle, $list = 'enqueued' ) {
		return isset( $GLOBALS['__faz_registered'][ $handle ] );
	}
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['__faz_registered'][ $handle ] = $src;
	}
	function wp_localize_script( $handle, $name, $data ) {}
	function wp_enqueue_script( $handle ) {
		$GLOBALS['__faz_enqueued_scripts'][ $handle ] = true;
	}
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
		$GLOBALS['__faz_enqueued_styles'][ $handle ] = $src;
	}
	function is_singular() {
		return $GLOBALS['__faz_singular'];
	}
	function get_queried_object() {
		return $GLOBALS['__faz_queried'];
	}
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		return array_merge( $pairs, (array) $atts );
	}
	function has_shortcode( $content, $tag ) {
		return false !== strpos( $content, '[' . $tag );
	}
	// Markup helpers the form render() bodies use; only the enqueue matters here.
	function wp_register_style( $handle, $src = '', $deps = array(), $ver = false ) {}
	function wp_add_inline_style( $handle, $css ) {}
	function wp_create_nonce( $action = -1 ) {
		return 'nonce';
	}
	function wp_rand( $min = 0, $max = 0 ) {
		return 4;
	}
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_html( $text ) {
		return esc_attr( $text );
	}
	function esc_url( $url ) {
		return (string) $url;
	}
	function esc_attr_e( $text, $domain = '' ) {
		echo esc_attr( $text );
	}
	function esc_html_e( $text, $domain = '' ) {
		echo esc_html( $text );
	}

	require_once FAZ_PLUGIN_BASEPATH . 'includes/class-formatting.php';
	require_once FAZ_PLUGIN_BASEPATH . 'includes/class-ip-hasher.php';
	require_once FAZ_PLUGIN_BASEPATH . 'includes/class-dsar-shortcode.php';
	require_once FAZ_PLUGIN_BASEPATH . 'includes/class-do-not-sell-shortcode.php';
	require_once FAZ_PLUGIN_BASEPATH . 'admin/modules/cookie-policy-generator/class-cookie-policy-generator.php';

	use FazCookie\Includes\DSAR_Shortcode;
	use FazCookie\Includes\Do_Not_Sell_Shortcode;
	use FazCookie\Admin\Modules\Cookie_Policy_Generator\Cookie_Policy_Generator;

	$passed = 0;
	$failed = 0;
	function check( $cond, $label ) {
		global $passed, $failed;
		if ( $cond ) {
			$passed++;
			echo "  \033[32m✓\033[0m {$label}\n";
		} else {
			$failed++;
			echo "  \033[31m✗\033[0m {$label}\n";
		}
	}
	function reset_assets() {
		$GLOBALS['__faz_enqueued_scripts'] = array();
		$GLOBALS['__faz_enqueued_styles']  = array();
		$GLOBALS['__faz_registered']       = array();
		$GLOBALS['__faz_everywhere']       = array();
		$GLOBALS['__faz_singular']         = false;
		$GLOBALS['__faz_queried']          = null;
		$GLOBALS['__faz_is_admin']         = false;
	}
	function new_policy_generator() {
		$rc = new ReflectionClass( Cookie_Policy_Generator::class );
		return $rc->newInstanceWithoutConstructor();
	}

	$dsar = new DSAR_Shortcode();
	$dns  = new Do_Not_Sell_Shortcode();
	$pol  = new_policy_generator();

	echo "\nA page without any FAZ shortcode loads none of them\n";
	reset_assets();
	$dsar->maybe_enqueue_assets();
	$dns->maybe_enqueue_assets();
	$pol->maybe_enqueue_frontend_assets();
	check( ! isset( $GLOBALS['__faz_enqueued_scripts']['faz-dsar-form'] ), 'no DSAR form JS' );
	check( ! isset( $GLOBALS['__faz_enqueued_scripts']['faz-dnsmpi-form'] ), 'no Do-Not-Sell form JS' );
	check( ! isset( $GLOBALS['__faz_enqueued_styles']['faz-cookie-policy'] ), 'no policy CSS' );

	echo "\nRendering a shortcode loads its own asset\n";
	reset_assets();
	$dsar->render( array() );
	check( isset( $GLOBALS['__faz_enqueued_scripts']['faz-dsar-form'] ), '[faz_dsar_form] enqueues the DSAR JS' );
	check( false !== strpos( (string) $GLOBALS['__faz_registered']['faz-dsar-form'], 'frontend/js/faz-dsar' ), 'from the plugin file' );
	reset_assets();
	$dns->render( array() );
	check( isset( $GLOBALS['__faz_enqueued_scripts']['faz-dnsmpi-form'] ), '[faz_do_not_sell] enqueues the Do-Not-Sell JS' );
	reset_assets();
	$pol->render_shortcode( array() );
	check(
		isset( $GLOBALS['__faz_enqueued_styles']['faz-cookie-policy'] )
			&& false !== strpos( $GLOBALS['__faz_enqueued_styles']['faz-cookie-policy'], 'frontend/css/faz-cookie-policy.css' ),
		'[faz_cookie_policy_complete] enqueues the policy CSS'
	);

	echo "\nThe policy page gets its CSS in <head>\n";
	reset_assets();
	$GLOBALS['__faz_singular'] = true;
	$GLOBALS['__faz_queried']  = new WP_Post( "<p>Intro</p>\n[faz_cookie_policy_complete lang=\"it\" jurisdiction=\"gdpr-strict\"]" );
	$pol->maybe_enqueue_frontend_assets();
	check( isset( $GLOBALS['__faz_enqueued_styles']['faz-cookie-policy'] ), 'singular page with the shortcode: CSS enqueued before render' );
	reset_assets();
	$GLOBALS['__faz_singular'] = true;
	$GLOBALS['__faz_queried']  = new WP_Post( '<p>About us</p>' );
	$pol->maybe_enqueue_frontend_assets();
	check( ! isset( $GLOBALS['__faz_enqueued_styles']['faz-cookie-policy'] ), 'singular page without it: nothing' );
	reset_assets();
	$GLOBALS['__faz_singular'] = false;
	$GLOBALS['__faz_queried']  = new WP_Post( '[faz_cookie_policy_complete]' );
	$pol->maybe_enqueue_frontend_assets();
	check( ! isset( $GLOBALS['__faz_enqueued_styles']['faz-cookie-policy'] ), 'an archive is not read as the policy page' );

	echo "\nThe opt-in filter restores loading everywhere, per asset\n";
	reset_assets();
	$GLOBALS['__faz_everywhere'] = array( 'dsar' );
	$dsar->maybe_enqueue_assets();
	$dns->maybe_enqueue_assets();
	$pol->maybe_enqueue_frontend_assets();
	check( isset( $GLOBALS['__faz_enqueued_scripts']['faz-dsar-form'] ), "'dsar' loads the DSAR JS" );
	check( ! isset( $GLOBALS['__faz_enqueued_scripts']['faz-dnsmpi-form'] ), "'dsar' leaves the Do-Not-Sell JS alone" );
	check( ! isset( $GLOBALS['__faz_enqueued_styles']['faz-cookie-policy'] ), "'dsar' leaves the policy CSS alone" );
	reset_assets();
	$GLOBALS['__faz_everywhere'] = array( 'dsar', 'dnsmpi', 'cookie_policy' );
	$dsar->maybe_enqueue_assets();
	$dns->maybe_enqueue_assets();
	$pol->maybe_enqueue_frontend_assets();
	check(
		isset( $GLOBALS['__faz_enqueued_scripts']['faz-dsar-form'], $GLOBALS['__faz_enqueued_scripts']['faz-dnsmpi-form'], $GLOBALS['__faz_enqueued_styles']['faz-cookie-policy'] ),
		'all three when all three are asked for'
	);

	echo "\nNever in wp-admin\n";
	reset_assets();
	$GLOBALS['__faz_everywhere'] = array( 'dsar', 'dnsmpi', 'cookie_policy' );
	$GLOBALS['__faz_is_admin']   = true;
	$dsar->maybe_enqueue_assets();
	$dns->maybe_enqueue_assets();
	$pol->maybe_enqueue_frontend_assets();
	check( empty( $GLOBALS['__faz_enqueued_scripts'] ) && empty( $GLOBALS['__faz_enqueued_styles'] ), 'admin screens load nothing' );

	echo "\n";
	if ( 0 === $failed ) {
		echo "ALL PASS ({$passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$failed}, passed: {$passed}\n";
	exit( 1 );
}
