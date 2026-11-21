<?php
/**
 * Regression test (2026-11 security audit, hardening note): the multilingual
 * getters unslash BEFORE they filter.
 *
 * Store::get_description(), Cookie::get_description() and
 * Cookie_Categories::get_name()/get_description() ran
 * stripslashes( wp_kses_post( $content ) ): whatever stripslashes() rebuilds
 * from the filtered text reaches the page unfiltered. No exploitable form was
 * found against real kses, but the order is wrong in principle. The kses
 * stand-in below removes <script> elements, so a backslash inside the tag
 * name shows which order runs: filtered last, nothing comes out.
 *
 * Run: php tests/unit/test-multilingual-getter-unslash-order-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Cache {
		public static function get( $key, $group ) {
			return array();
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	function absint( $v ) { return abs( (int) $v ); }
	function sanitize_text_field( $v ) { return (string) $v; }
	function sanitize_textarea_field( $v ) { return (string) $v; }
	function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
	function sanitize_title( $v ) { return (string) $v; }
	function sanitize_file_name( $v ) { return basename( $v ); }
	function wp_strip_all_tags( $v ) { return strip_tags( $v ); }
	/** kses stand-in: drops script elements, keeps everything else. */
	function wp_kses_post( $v ) { return preg_replace( '#<script\b[^>]*>.*?</script\s*>#is', '', (string) $v ); }
	function wp_filter_post_kses( $v ) { return (string) $v; }
	function wp_kses( $v, $a = array() ) { return (string) $v; }
	function faz_allowed_html() { return array(); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function faz_default_language() { return 'en'; }
	function faz_current_language( $reset = false ) { return 'en'; }
	function faz_selected_languages( $lang = '' ) { return array( 'en' ); }
	function wp_upload_dir() { return array( 'basedir' => '/unslash-test-uploads' ); }
	function wp_cache_get( $key, $group = '' ) { return false; }
	function wp_cache_set( ...$a ) { return true; }
	function wp_cache_delete( ...$a ) { return true; }
	function faz_read_json_file( $path ) { return array(); }
	function apply_filters( $hook, $value, ...$args ) { return $value; }
	function add_action( ...$a ) {}
	function do_action( ...$a ) {}
	function current_user_can( $c ) { return true; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function esc_url_raw( $v ) { return (string) $v; }
	function __( $t, $d = '' ) { return $t; }
	// The one-time unslash migration has not run (no faz_cookie_text_unslashed_done):
	// the getters still strip the legacy slashes, which is the order under test.
	function get_option( $name, $default = false ) { return $default; }

	$root = dirname( __DIR__, 2 );
	require_once $root . '/includes/class-base-controller.php';
	require_once $root . '/includes/class-store.php';
	require_once $root . '/admin/modules/cookies/includes/class-category-controller.php';
	require_once $root . '/admin/modules/cookies/includes/class-cookie-categories.php';
	require_once $root . '/admin/modules/cookies/includes/class-cookie.php';

	use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories;
	use FazCookie\Admin\Modules\Cookies\Includes\Cookie;

	$passed = 0;
	$failed = 0;
	function check( $cond, $label ) {
		global $passed, $failed;
		if ( $cond ) {
			++$passed;
			echo "  \033[32mPASS\033[0m {$label}\n";
		} else {
			++$failed;
			echo "  \033[31mFAIL\033[0m {$label}\n";
		}
	}

	// "<scr\ipt>" is not a script tag to the filter; stripslashes() turns it into one.
	$slashed = 'Analytics <scr\\ipt>alert(1)</scr\\ipt> cookies';

	echo "\nmultilingual getters: unslash, then filter\n";

	$cat = new Cookie_Categories();
	$cat->set_slug( 'analytics' );
	$cat->set_name( array( 'en' => $slashed ) );
	$cat->set_description( array( 'en' => $slashed ) );
	$name = $cat->get_name( 'en' );
	$desc = $cat->get_description( 'en' );
	check( false === stripos( (string) $name, '<script' ), 'Cookie_Categories::get_name(): no script element comes out' );
	check( false === stripos( (string) $desc, '<script' ), 'Cookie_Categories::get_description(): no script element comes out' );

	$cookie = new Cookie();
	$cookie->set_name( 'custom' );
	$cookie->set_description( array( 'en' => $slashed ) );
	$cdesc = $cookie->get_description( 'en' );
	check( false === stripos( (string) $cdesc, '<script' ), 'Cookie::get_description(): no script element comes out' );

	// Store::get_description() is the base implementation; reach it directly.
	$store_get = new ReflectionMethod( \FazCookie\Includes\Store::class, 'get_description' );
	$sdesc     = $store_get->invoke( $cat, 'en' );
	check( false === stripos( (string) $sdesc, '<script' ), 'Store::get_description(): no script element comes out' );

	$plain = new Cookie_Categories();
	$plain->set_slug( 'necessary' );
	$plain->set_name( array( 'en' => 'It\\\'s necessary' ) );
	check( "It's necessary" === $plain->get_name( 'en' ), 'ordinary slashed text is still unslashed' );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
