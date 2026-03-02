<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @link       https://fabiodalez.it/
 * @since      1.0.0
 *
 * @package    FAZ_Cookie_Manager
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( defined( 'FAZ_REMOVE_ALL_DATA' ) && true === FAZ_REMOVE_ALL_DATA ) {
	try {
		global $wpdb;

		// Drop all plugin tables.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'faz_banners' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'faz_cookie_categories' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'faz_cookies' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'faz_consent_logs' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'faz_pageviews' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Clean up transients.
		$prefix = $wpdb->esc_like( '_transient_faz' ) . '%';
		$keys   = $wpdb->get_results( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $prefix ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! is_wp_error( $keys ) ) {
			$transients = array_map(
				function( $key ) {
					return ltrim( $key['option_name'], '_transient_' );
				},
				$keys
			);
			foreach ( $transients as $key ) {
				delete_transient( $key );
			}
		}

		// Delete all plugin options.
		$faz_options = array(
			'faz_settings',
			'faz_gcm_settings',
			'faz_scan_details',
			'faz_scan_history',
			'faz_admin_notices',
			'faz_first_time_activated_plugin',
			'faz_cookie_consent_db_version',
			'faz_cookie_consent_lite_db_version',
			'faz_banners_table_version',
			'faz_cookie_table_version',
			'faz_cookie_category_table_version',
			'faz_consent_table_version',
			'faz_consent_logs_db_version',
			'faz_pageviews_db_version',
			'faz_missing_tables',
			'faz_migration_options',
			'faz_banner_template',
		);
		foreach ( $faz_options as $option_name ) {
			delete_option( $option_name );
		}
	} catch ( Exception $e ) {
		error_log( __( 'Failed to delete FAZ Cookie Manager plugin data!', 'faz-cookie-manager' ) ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
