<?php
/**
 * Standalone unit tests — IAB TCF enabled without a Global Vendor List is said
 * out loud in the admin.
 *
 * Subsystem: tcf-gvl-missing-notice-php
 *
 * The frontend activates the CMP only when a GVL is stored
 * (`iab.enabled && cmp_id >= 2 && $gvl->has_data()` gates both the enqueue
 * and the store). The gate is right, but it was silent: the setting read
 * "enabled", the site had no __tcfapi, and nothing in the admin explained it.
 * Admin::tcf_gvl_missing_notice() now says so on the plugin's screens and on
 * the WordPress dashboard until the list is stored, with existing catalogue
 * strings so it is translated from day one.
 *
 * Run from project root:
 *   php tests/unit/test-tcf-gvl-missing-notice-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Store {}

	/** Gvl double: has_data() is whatever the case needs. */
	class Gvl {
		public static $has_data  = false;
		private static $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function has_data() {
			return self::$has_data;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	$GLOBALS['tgn_options']   = array();
	$GLOBALS['tgn_is_faz']    = true;
	$GLOBALS['tgn_screen']    = null;
	$GLOBALS['tgn_can']       = true;

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['tgn_options'] ) ? $GLOBALS['tgn_options'][ $name ] : $default;
	}
	function faz_is_admin_page() {
		return $GLOBALS['tgn_is_faz'];
	}
	function get_current_screen() {
		return $GLOBALS['tgn_screen'];
	}
	function current_user_can( $capability ) {
		return $GLOBALS['tgn_can'] && 'manage_options' === $capability;
	}
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
	function esc_url( $value ) {
		return $value;
	}
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
	function __( $text, $domain = 'default' ) {
		return $text;
	}
	function apply_filters( $tag, $value ) {
		return $value;
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-formatting.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/settings/includes/class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
	require_once dirname( __DIR__, 2 ) . '/admin/class-admin.php';

	use FazCookie\Admin\Admin;
	use FazCookie\Includes\Gvl;

	$tests_run = $tests_failed = 0;
	function tgn_ok( $cond, $label ) {
		global $tests_run, $tests_failed;
		$tests_run++;
		if ( $cond ) {
			echo "  \033[32m✓\033[0m $label\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m $label\n";
		}
	}

	$admin = ( new ReflectionClass( Admin::class ) )->newInstanceWithoutConstructor();
	function tgn_render( $admin, $tcf_on, $has_gvl ) {
		$GLOBALS['tgn_options']['faz_settings'] = array( 'iab' => array( 'enabled' => $tcf_on, 'cmp_id' => 300 ) );
		Gvl::$has_data = $has_gvl;
		ob_start();
		$admin->tcf_gvl_missing_notice();
		return (string) ob_get_clean();
	}

	echo "\n\033[1mTCF enabled without a GVL: admin notice\033[0m\n\n";

	$html = tgn_render( $admin, true, false );
	tgn_ok( false !== strpos( $html, 'id="faz-tcf-gvl-missing-notice"' ), 'TCF on, no GVL: the notice is shown on a FAZ screen' );
	tgn_ok( false !== strpos( $html, 'GVL data not available. Please update GVL first.' ), 'and says the vendor list is missing' );
	tgn_ok( false !== strpos( $html, 'admin.php?page=faz-cookie-manager-gvl' ), 'and links to the Vendor List page' );

	tgn_ok( '' === tgn_render( $admin, true, true ), 'TCF on and a GVL stored: no notice' );
	tgn_ok( '' === tgn_render( $admin, false, false ), 'TCF off: no notice' );

	$GLOBALS['tgn_is_faz'] = false;
	tgn_ok( '' === tgn_render( $admin, true, false ), 'an unrelated admin screen: no notice' );
	$GLOBALS['tgn_screen'] = (object) array( 'id' => 'dashboard' );
	tgn_ok( false !== strpos( tgn_render( $admin, true, false ), 'faz-tcf-gvl-missing-notice' ), 'the WordPress dashboard: notice shown' );
	$GLOBALS['tgn_screen'] = null;
	$GLOBALS['tgn_is_faz'] = true;

	$GLOBALS['tgn_can'] = false;
	tgn_ok( '' === tgn_render( $admin, true, false ), 'a user who cannot manage options: no notice' );
	$GLOBALS['tgn_can'] = true;

	echo "\n" . ( $tests_failed > 0 ? "\033[31m" : "\033[32m" ) . ( $tests_run - $tests_failed ) . "/{$tests_run} passed\033[0m\n";
	exit( $tests_failed > 0 ? 1 : 0 );
}
