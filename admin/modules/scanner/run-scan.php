<?php
/**
 * Standalone scanner bootstrap — run via PHP-CLI when WP-CLI is unavailable.
 *
 * Usage: php run-scan.php /path/to/wordpress 20
 *
 * @package FAZ_Cookie_Manager
 */

// Satisfy Plugin Check's direct-access guard (ABSPATH is defined below after locating WP).
if ( ! defined( 'ABSPATH' ) && php_sapi_name() !== 'cli' ) {
	exit;
}
// CLI-only guard — unconditional.
if ( php_sapi_name() !== 'cli' ) {
	exit( 'CLI only.' );
}

$abspath = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) . '/' : '';
$mode    = isset( $argv[2] ) ? $argv[2] : '20';

$resolved = realpath( $abspath );
if ( false === $resolved || ! file_exists( $resolved . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress installation not found at: {$abspath}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}
$abspath = rtrim( $resolved, '/' ) . '/';

// Bootstrap WordPress.
define( 'ABSPATH', $abspath );
define( 'SHORTINIT', false );
require_once $abspath . 'wp-load.php';

$controller = \FazCookie\Admin\Modules\Scanner\Includes\Controller::get_instance();

if ( 'httponly' === $mode ) {
	// Quick httpOnly cookie check on homepage only.
	$controller->run_httponly_check();
	echo "httpOnly check complete.\n";
} else {
	// Full scan.
	$max_pages = absint( $mode );
	$result    = $controller->run_scan( $max_pages );
	echo 'Scan complete: ' . wp_json_encode( $result ) . "\n";
}
