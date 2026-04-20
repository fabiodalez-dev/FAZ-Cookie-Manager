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

/**
 * Check whether plugin data should be removed for a given site.
 *
 * @param int|null $site_id Optional multisite blog ID.
 * @return bool
 */
function faz_should_remove_on_uninstall( $site_id = null ) {
	if ( defined( 'FAZ_REMOVE_ALL_DATA' ) && true === FAZ_REMOVE_ALL_DATA ) {
		return true;
	}

	if ( null !== $site_id && is_multisite() && function_exists( 'get_blog_option' ) ) {
		$faz_settings = get_blog_option( (int) $site_id, 'faz_settings', array() );
	} else {
		$faz_settings = get_option( 'faz_settings', array() );
	}

	return ! empty( $faz_settings['general']['remove_data_on_uninstall'] );
}

$force_remove_all = defined( 'FAZ_REMOVE_ALL_DATA' ) && true === FAZ_REMOVE_ALL_DATA;

if ( $force_remove_all || faz_should_remove_on_uninstall() || is_multisite() ) {

	/**
	 * Remove an empty directory using WP_Filesystem when available.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	function faz_uninstall_rmdir( $dir ) {
		global $wp_filesystem;
		if ( $wp_filesystem && is_callable( array( $wp_filesystem, 'rmdir' ) ) ) {
			return $wp_filesystem->rmdir( $dir );
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Clean up all plugin data for the current site.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	function faz_cleanup_site_data() {
		try {
			global $wpdb;

			// Drop all plugin tables, checking each result.
			$blog_id    = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
			$faz_tables = array( 'faz_banners', 'faz_cookie_categories', 'faz_cookies', 'faz_consent_logs', 'faz_pageviews' );
			foreach ( $faz_tables as $tbl ) {
				$result = $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $tbl ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
				if ( false === $result ) {
					error_log( 'FAZ uninstall: DROP ' . $tbl . ' failed on blog ' . $blog_id . ' — ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}

			// Clean up transients.
			$prefix = $wpdb->esc_like( '_transient_faz' ) . '%';
			$keys   = $wpdb->get_results( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $prefix ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! empty( $keys ) && is_array( $keys ) ) {
				$transients = array_map(
					function( $key ) {
						$name = $key['option_name'];
						return 0 === strpos( $name, '_transient_' ) ? substr( $name, 11 ) : $name;
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
				'faz_scan_counter',
				'faz_scan_max_pages',
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
				'faz_gvl_data',
				'faz_gvl_meta',
				'faz_gvl_purposes',
				'faz_gvl_selected_vendors',
				'faz_version',
				'faz_brand_logo_path_fixed',
				'faz_banner_gdpr_defaults_fixed',
				'faz_uncategorized_consent_fixed',
				'faz_migrated_advert_to_marketing',
				'faz_migrations_version',
				'faz_cookie_definitions',
				'faz_cookie_definitions_meta',
				'faz_file_write_access',
			);
			foreach ( $faz_options as $option_name ) {
				delete_option( $option_name );
			}

			// Also delete any language-suffixed banner template variants
			// (e.g. faz_banner_template_en, faz_banner_template_it).
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

			// Remove plugin upload directories (recursive to handle dotfiles and subdirectories).
			$upload_dir  = wp_upload_dir( null, false );
			$upload_base = trailingslashit( $upload_dir['basedir'] );
			$upload_dirs = array(
				$upload_base . 'faz-cookie-manager',
				$upload_base . 'fazcookie',
			);
			foreach ( $upload_dirs as $plugin_upload_dir ) {
				if ( ! is_dir( $plugin_upload_dir ) ) {
					continue;
				}
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $plugin_upload_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
					\RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach ( $iterator as $node ) {
					if ( $node->isDir() ) {
						faz_uninstall_rmdir( $node->getPathname() );
					} else {
						wp_delete_file( $node->getPathname() );
					}
				}
				faz_uninstall_rmdir( $plugin_upload_dir );
			}
		} catch ( \Throwable $e ) {
			error_log( 'Failed to delete FAZ Cookie Manager plugin data! ' . $e->getMessage() ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	if ( is_multisite() ) {
		$offset = 0;
		$batch  = 100;
		do {
			$site_ids = get_sites( array(
				'fields' => 'ids',
				'number' => $batch,
				'offset' => $offset,
			) );
			foreach ( $site_ids as $site_id ) {
				if ( ! faz_should_remove_on_uninstall( $site_id ) ) {
					continue;
				}
				switch_to_blog( $site_id );
				faz_cleanup_site_data();
				restore_current_blog();
			}
			$offset += $batch;
		} while ( count( $site_ids ) === $batch );
	} elseif ( faz_should_remove_on_uninstall() ) {
		faz_cleanup_site_data();
	}
}
