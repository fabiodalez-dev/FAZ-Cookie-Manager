<?php
/**
 * scanner.static_ip on multisite: network authority decides where the web
 * server connects, so a subsite administrator keeps whatever is stored. WP-CLI
 * runs with shell access, which is more than that: `wp faz settings` must be
 * able to set it without --user, instead of silently keeping the old value.
 *
 * Run: php tests/unit/test-settings-static-ip-multisite-php.php
 */

namespace FazCookie\Includes {
	if ( ! class_exists( 'FazCookie\Includes\Store' ) ) {
		abstract class Store {}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['faz_caps']     = array();
	$GLOBALS['faz_settings'] = array( 'scanner' => array( 'static_ip' => '93.184.216.34' ) );
	function is_multisite() { return true; }
	function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['faz_caps'], true ); }
	function get_option( $name, $default = false ) { return 'faz_settings' === $name ? $GLOBALS['faz_settings'] : $default; }
	function apply_filters( $hook, $value ) { return $value; }

	require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/settings/includes/class-settings.php';

	use FazCookie\Admin\Modules\Settings\Includes\Settings;

	$ok = 0; $ko = 0;
	function t( $c, $l ) { global $ok, $ko; if ( $c ) { ++$ok; echo "  PASS $l\n"; } else { ++$ko; echo "  FAIL $l\n"; } }

	$new = '8.8.8.8';
	t( '93.184.216.34' === Settings::sanitize_option( 'static_ip', $new ), 'a subsite admin without network authority keeps the stored address' );

	$GLOBALS['faz_caps'] = array( 'manage_network_options' );
	t( $new === Settings::sanitize_option( 'static_ip', $new ), 'a network admin sets it' );

	$GLOBALS['faz_caps'] = array();
	define( 'WP_CLI', true );
	t( $new === Settings::sanitize_option( 'static_ip', $new ), 'WP-CLI without --user sets it too' );
	t( '' === Settings::sanitize_option( 'static_ip', '10.0.0.5' ), 'and a non-routable address is still refused' );

	echo "\nsettings static ip multisite: $ok passed, $ko failed\n";
	exit( $ko > 0 ? 1 : 0 );
}
