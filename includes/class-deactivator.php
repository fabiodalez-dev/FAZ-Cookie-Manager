<?php
/**
 * Fired during plugin deactivation
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @package    FazCookie
 * @subpackage FazCookie/includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      3.0.0
 * @package    FazCookie
 * @subpackage FazCookie/includes
 * @author     Fabio D'Alessandro
 */
class Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    3.0.0
	 */
	public static function deactivate() {
		// Clear banner template cache (base + language variants).
		if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
			faz_clear_banner_template_cache();
		} else {
			delete_option( 'faz_banner_template' );
			// Also delete language-suffixed variants (e.g. faz_banner_template_en).
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$lang_variants = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s",
					$wpdb->esc_like( 'faz_banner_template_' ) . '%',
					'faz_banner_template'
				)
			);
			foreach ( $lang_variants as $variant ) {
				delete_option( $variant );
			}
		}
		delete_transient( 'faz_scan_running' );

		// Unschedule all cron jobs.
		wp_clear_scheduled_hook( 'faz_download_cookie_definitions' );
		wp_clear_scheduled_hook( 'faz_daily_cleanup' );
		wp_clear_scheduled_hook( 'faz_weekly_gvl_update' );
		wp_clear_scheduled_hook( 'faz_async_cookie_scan' );
		wp_clear_scheduled_hook( 'faz_async_httponly_cookie_check' );
		wp_clear_scheduled_hook( 'faz_scheduled_scan' );
	}

}
