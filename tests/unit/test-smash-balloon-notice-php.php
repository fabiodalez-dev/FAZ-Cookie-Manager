<?php
/**
 * Regression tests for the Smash Balloon admin notice.
 *
 * The frontend decision is deliberately fail-closed. The notice must use that
 * exact decision too: `sb_instagram_settings` survives plugin deactivation, so
 * an option-only check can otherwise claim that an inactive plugin is handling
 * consent and link the administrator to a page that does not exist.
 *
 * Run: php tests/unit/test-smash-balloon-notice-php.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['sbn_options']       = array();
$GLOBALS['sbn_filter_value']  = true;
$GLOBALS['sbn_user_meta']     = array();
$GLOBALS['sbn_tests_run']     = 0;
$GLOBALS['sbn_tests_failed']  = 0;

function faz_is_admin_page() {
	return true;
}

function current_user_can( $capability ) {
	return 'manage_options' === $capability;
}

function get_current_user_id() {
	return 7;
}

function get_user_meta( $user_id, $key, $single = false ) {
	unset( $user_id, $single );
	return $GLOBALS['sbn_user_meta'][ $key ] ?? '';
}

function update_user_meta( $user_id, $key, $value ) {
	unset( $user_id );
	$GLOBALS['sbn_user_meta'][ $key ] = $value;
	return true;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['sbn_options'] )
		? $GLOBALS['sbn_options'][ $name ]
		: $default;
}

function apply_filters( $hook, $value ) {
	return 'faz_respect_smash_balloon_gdpr' === $hook
		? $GLOBALS['sbn_filter_value']
		: $value;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function add_query_arg( $key, $value ) {
	return 'https://example.test/wp-admin/admin.php?' . rawurlencode( $key ) . '=' . rawurlencode( $value );
}

function wp_nonce_url( $url, $action, $name = '_wpnonce' ) {
	return $url . '&' . rawurlencode( $name ) . '=nonce-' . rawurlencode( $action );
}

function wp_verify_nonce( $nonce, $action ) {
	return 'nonce-' . $action === $nonce;
}

function sanitize_text_field( $value ) {
	return is_string( $value ) ? trim( $value ) : '';
}

function wp_unslash( $value ) {
	return $value;
}

function esc_url( $value ) {
	return $value;
}

function esc_html_e( $value, $domain = '' ) {
	unset( $domain );
	echo htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
require_once dirname( __DIR__, 2 ) . '/admin/class-admin.php';

use FazCookie\Admin\Admin;

function sbn_render_notice( $admin ) {
	ob_start();
	$admin->smash_balloon_notice();
	return (string) ob_get_clean();
}

function sbn_eq( $actual, $expected, $label ) {
	$GLOBALS['sbn_tests_run']++;
	if ( $actual === $expected ) {
		echo "  \033[32mPASS\033[0m {$label}\n";
		return;
	}
	$GLOBALS['sbn_tests_failed']++;
	echo "  \033[31mFAIL\033[0m {$label}\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        actual:   ' . var_export( $actual, true ) . "\n";
}

$admin = ( new ReflectionClass( Admin::class ) )->newInstanceWithoutConstructor();

echo "Smash Balloon admin notice\n\n";

// The option remains after the integration is deactivated. This is the exact
// regression: the old notice rendered even though the frontend correctly kept
// blocking and the linked settings screen was gone.
$GLOBALS['sbn_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'yes' ) );
sbn_eq( sbn_render_notice( $admin ), '', 'stale gdpr=yes option does not render a notice when Instagram Feed is inactive' );

// From this point the integration is active for the rest of the process.
define( 'SBIVER', '6.11.4' );

$notice = sbn_render_notice( $admin );
sbn_eq( false !== strpos( $notice, 'Instagram Feed is handling its own consent' ), true, 'active integration with gdpr=yes renders the explanation' );
sbn_eq( false !== strpos( $notice, 'admin.php?page=sbi-settings' ), true, 'active notice links to the registered settings page' );

$GLOBALS['sbn_filter_value'] = false;
sbn_eq( sbn_render_notice( $admin ), '', 'escape hatch suppresses the notice together with the frontend accommodation' );
$GLOBALS['sbn_filter_value'] = true;

$GLOBALS['sbn_options']['sb_instagram_settings']['gdpr'] = 'auto';
sbn_eq( sbn_render_notice( $admin ), '', 'automatic mode remains blocked and does not render the self-restriction notice' );

$GLOBALS['sbn_options']['sb_instagram_settings']['gdpr'] = array( 'yes' );
sbn_eq( sbn_render_notice( $admin ), '', 'malformed gdpr value fails closed without a notice or warning' );

$cookies_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/js/pages/cookies.js' );
sbn_eq( false !== strpos( $cookies_js, "parsed.origin === window.location.origin" ), true, 'template settings links are restricted to the current admin origin' );
sbn_eq( false !== strpos( $cookies_js, "document.createElement('a')" ), true, 'template settings use a native link outside the card button' );
sbn_eq( false === strpos( $cookies_js, 'window.location.href = tpl.not_applicable.url' ), true, 'unvalidated server data is never assigned directly to window.location' );

echo "\n" . ( $GLOBALS['sbn_tests_run'] - $GLOBALS['sbn_tests_failed'] ) . "/{$GLOBALS['sbn_tests_run']} passed\n";
exit( 0 === $GLOBALS['sbn_tests_failed'] ? 0 : 1 );
