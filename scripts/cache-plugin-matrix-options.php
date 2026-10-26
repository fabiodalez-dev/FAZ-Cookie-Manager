<?php
/**
 * Snapshot/restore the option families changed by cache-plugin-matrix.sh.
 * Run through `wp --skip-plugins --skip-themes eval-file`, never over HTTP.
 *
 * @package FazCookie\Tests
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

global $wpdb;

// Include activation-created options so a previously absent setting becomes
// absent again. Preserve the raw serialized value and the autoload flag, and
// avoid plugin option hooks while restoring their original configuration.
$prefixes = array(
	'WpFastestCache', 'cache_enabler', 'wphb_', 'breeze_', 'wpo_',
	'comet_cache', 'cachify', 'surge_', 'jetpack_boost_', 'swcfpc_',
	'litespeed.', 'autoptimize_', 'siteground_optimizer_', 'sg_cachepress',
	'w3tc_', 'wp_cache_', 'wp_super_cache_', 'flying_press_', 'FLYING_PRESS_',
);
$pattern = '^(' . implode( '|', array_map( function ( $prefix ) {
	return preg_quote( $prefix, '/' );
}, $prefixes ) ) . ')';
$where = $wpdb->prepare(
	'option_name IN (%s, %s) OR option_name REGEXP %s',
	'active_plugins', 'faz_settings', $pattern
);
$mode = $args[0] ?? '';
$file = $args[1] ?? '';
if ( ! in_array( $mode, array( 'snapshot', 'restore' ), true ) || '' === $file ) {
	WP_CLI::error( 'Usage: eval-file cache-plugin-matrix-options.php snapshot|restore <file>' );
}

if ( 'snapshot' === $mode ) {
	$rows = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE {$where}", ARRAY_A );
	if ( $wpdb->last_error || ! is_array( $rows ) || false === file_put_contents( $file, serialize( $rows ) ) ) {
		WP_CLI::error( 'Could not snapshot the cache plugin options.' );
	}
	return;
}

$contents = file_get_contents( $file );
$rows = false === $contents ? false : unserialize( $contents, array( 'allowed_classes' => false ) );
if ( ! is_array( $rows ) ) {
	WP_CLI::error( 'Invalid cache plugin option snapshot; nothing was restored.' );
}
foreach ( $rows as $row ) {
	if ( ! is_array( $row ) || ! isset( $row['option_name'], $row['option_value'], $row['autoload'] )
		|| ! is_string( $row['option_name'] ) || ! is_string( $row['option_value'] ) || ! is_string( $row['autoload'] ) ) {
		WP_CLI::error( 'Invalid cache plugin option row; nothing was restored.' );
	}
}

$wpdb->query( 'START TRANSACTION' );
if ( false === $wpdb->query( "DELETE FROM {$wpdb->options} WHERE {$where}" ) ) {
	$wpdb->query( 'ROLLBACK' );
	WP_CLI::error( 'Could not restore the cache plugin options; snapshot retained.' );
}
foreach ( $rows as $row ) {
	if ( false === $wpdb->insert( $wpdb->options, $row ) ) {
		$wpdb->query( 'ROLLBACK' );
		WP_CLI::error( 'Could not restore a cache plugin option; snapshot retained.' );
	}
}
if ( false === $wpdb->query( 'COMMIT' ) ) {
	WP_CLI::error( 'Could not commit the restored cache plugin options; snapshot retained.' );
}
wp_cache_flush();
