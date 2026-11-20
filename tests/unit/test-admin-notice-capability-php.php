<?php
/**
 * Regression test (2026-11 security audit, hardening notes): the unmatched
 * vendors and scheduled-scan notices, and the dismiss AJAX handler, check
 * manage_options themselves.
 *
 * ajax_dismiss_unmatched_vendors() checked only the nonce, unlike its sibling
 * handlers. unmatched_vendors_notice() and scheduled_scan_notice() relied on
 * faz_is_admin_page() (WordPress page access) alone, and the scan notice also
 * consumes its transient while rendering, so a user who cannot manage the
 * plugin could clear the administrator's notice by loading a screen.
 *
 * Run: php tests/unit/test-admin-notice-capability-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Store {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	$GLOBALS['anc_transients'] = array();
	$GLOBALS['anc_can']        = true;
	$GLOBALS['anc_json_error'] = null;
	$GLOBALS['anc_referer']    = 0;

	class Anc_Die extends \Exception {}

	function get_transient( $key ) {
		return $GLOBALS['anc_transients'][ $key ] ?? false;
	}
	function delete_transient( $key ) {
		unset( $GLOBALS['anc_transients'][ $key ] );
		return true;
	}
	function faz_is_admin_page() {
		return true;
	}
	function current_user_can( $capability ) {
		return $GLOBALS['anc_can'] && 'manage_options' === $capability;
	}
	function check_ajax_referer( $action, $arg ) {
		++$GLOBALS['anc_referer'];
		return 1;
	}
	function wp_send_json_error( $data = null, $status = null ) {
		$GLOBALS['anc_json_error'] = $status;
		throw new Anc_Die( 'json_error' );
	}
	function wp_die() {
		throw new Anc_Die( 'die' );
	}
	function wp_create_nonce( $action ) {
		return 'nonce-' . $action;
	}
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
	function esc_url( $v ) {
		return $v;
	}
	function esc_attr( $v ) {
		return $v;
	}
	function esc_html( $v ) {
		return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
	}
	function esc_html__( $t, $d = 'default' ) {
		return $t;
	}
	function __( $t, $d = 'default' ) {
		return $t;
	}
	function wp_kses_post( $v ) {
		return $v;
	}
	function absint( $v ) {
		return abs( (int) $v );
	}
	function apply_filters( $tag, $value ) {
		return $value;
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-formatting.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/settings/includes/class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
	require_once dirname( __DIR__, 2 ) . '/admin/class-admin.php';

	use FazCookie\Admin\Admin;

	$tests_run = $tests_failed = 0;
	function anc_ok( $cond, $label ) {
		global $tests_run, $tests_failed;
		$tests_run++;
		if ( $cond ) {
			echo "  \033[32m✓\033[0m $label\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m $label\n";
		}
	}
	function anc_render( $admin, $method ) {
		ob_start();
		$admin->{$method}();
		return (string) ob_get_clean();
	}

	$admin = ( new ReflectionClass( Admin::class ) )->newInstanceWithoutConstructor();

	echo "\n\033[1mAdmin notices and dismiss handler check manage_options\033[0m\n\n";

	// Scheduled scan notice.
	$GLOBALS['anc_can']                                  = false;
	$GLOBALS['anc_transients']['faz_scan_new_cookies']   = 3;
	anc_ok( '' === anc_render( $admin, 'scheduled_scan_notice' ), 'scan notice: hidden from a user without manage_options' );
	anc_ok( 3 === get_transient( 'faz_scan_new_cookies' ), 'scan notice: and that user\'s page load does not consume it' );
	$GLOBALS['anc_can'] = true;
	anc_ok( false !== strpos( anc_render( $admin, 'scheduled_scan_notice' ), 'Scheduled scan found 3 new cookie(s).' ), 'scan notice: shown to the administrator' );
	anc_ok( false === get_transient( 'faz_scan_new_cookies' ), 'scan notice: consumed once the administrator has seen it' );

	// Unmatched vendors notice.
	$GLOBALS['anc_transients']['faz_unmatched_vendors'] = array( array( 'service' => 'Hotjar' ) );
	$GLOBALS['anc_can']                                 = false;
	anc_ok( '' === anc_render( $admin, 'unmatched_vendors_notice' ), 'unmatched vendors notice: hidden from a user without manage_options (no dismiss nonce minted)' );
	$GLOBALS['anc_can'] = true;
	anc_ok( false !== strpos( anc_render( $admin, 'unmatched_vendors_notice' ), 'faz-unmatched-vendors-notice' ), 'unmatched vendors notice: shown to the administrator' );

	// Dismiss handler.
	$GLOBALS['anc_can']        = false;
	$GLOBALS['anc_referer']    = 0;
	$GLOBALS['anc_json_error'] = null;
	try {
		$admin->ajax_dismiss_unmatched_vendors();
	} catch ( Anc_Die $e ) {
		unset( $e );
	}
	anc_ok( 403 === $GLOBALS['anc_json_error'], 'dismiss: a user without manage_options gets a 403' );
	anc_ok( is_array( get_transient( 'faz_unmatched_vendors' ) ), 'dismiss: and the notice is not cleared' );

	$GLOBALS['anc_can']        = true;
	$GLOBALS['anc_json_error'] = null;
	try {
		$admin->ajax_dismiss_unmatched_vendors();
	} catch ( Anc_Die $e ) {
		unset( $e );
	}
	anc_ok( null === $GLOBALS['anc_json_error'] && 1 === $GLOBALS['anc_referer'], 'dismiss: the administrator passes the capability and the nonce check' );
	anc_ok( false === get_transient( 'faz_unmatched_vendors' ), 'dismiss: and the notice is cleared' );

	echo "\n" . ( $tests_failed > 0 ? "\033[31m" : "\033[32m" ) . ( $tests_run - $tests_failed ) . "/{$tests_run} passed\033[0m\n";
	exit( $tests_failed > 0 ? 1 : 0 );
}
