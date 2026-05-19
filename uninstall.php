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

$faz_force_remove_all = defined( 'FAZ_REMOVE_ALL_DATA' ) && true === FAZ_REMOVE_ALL_DATA;

if ( $faz_force_remove_all || faz_should_remove_on_uninstall() || is_multisite() ) {

	/**
	 * Remove an empty directory using WP_Filesystem when available.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	function faz_uninstall_rmdir( $dir ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem && defined( 'ABSPATH' ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( function_exists( 'WP_Filesystem' ) ) {
				WP_Filesystem();
			}
		}
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

			// Clean up site transients owned by the plugin. On single-site
			// installs these are stored in wp_options; the loop below covers
			// that path. Multisite is handled separately AFTER this per-blog
			// loop completes (site transients on multisite live in
			// wp_sitemeta, not in the per-blog options table).
			$site_transient_prefixes = array(
				$wpdb->esc_like( '_site_transient_faz' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_faz' ) . '%',
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$site_transient_keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$site_transient_prefixes[0],
					$site_transient_prefixes[1]
				)
			);
			foreach ( $site_transient_keys as $site_transient_key ) {
				if ( 0 === strpos( $site_transient_key, '_site_transient_timeout_' ) ) {
					$site_transient_key = substr( $site_transient_key, strlen( '_site_transient_timeout_' ) );
				} elseif ( 0 === strpos( $site_transient_key, '_site_transient_' ) ) {
					$site_transient_key = substr( $site_transient_key, strlen( '_site_transient_' ) );
				}
				delete_site_transient( $site_transient_key );
			}

			// Delete DSAR request posts — they contain personal data (name, email, request
			// type) and must be erased on uninstall to honour GDPR Article 17.
			// Enumerate every status WordPress recognises (including auto-draft and
			// inherit) because get_post_stati() returns them all and DSAR records
			// must not survive uninstall regardless of state.
			// Note: 'any' is a magic sentinel for get_posts() that only works when
			// post_status is a scalar string — inside an array it is interpreted as
			// a literal status name and matches nothing. Listing explicit statuses
			// is the correct shape.
			$dsar_posts = get_posts(
				array(
					'post_type'      => 'faz_dsar',
					'post_status'    => array( 'private', 'publish', 'pending', 'draft', 'trash', 'auto-draft', 'inherit', 'future' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			foreach ( $dsar_posts as $dsar_post_id ) {
				wp_delete_post( absint( $dsar_post_id ), true );
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

			// Clean up Do Not Sell / DSAR atomic lock options (created by add_option,
			// not set_transient, so the _transient_faz% LIKE above misses them).
			// Includes the rescind-lock variant (faz_dnsmpi_rsc_lock_*) — the opt-out
			// and rescind paths in Do_Not_Sell_Shortcode use distinct lock-key prefixes
			// (handle_optout uses faz_dnsmpi_lock_, handle_rescind uses faz_dnsmpi_rsc_lock_).
			// Without the third pattern a request that crashed between add_option (line
			// 212 of class-do-not-sell-shortcode.php) and delete_option (line 225) leaves
			// orphan locks behind on uninstall.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$faz_lock_keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( 'faz_dnsmpi_lock_' ) . '%',
					$wpdb->esc_like( 'faz_dnsmpi_rsc_lock_' ) . '%',
					$wpdb->esc_like( 'faz_dsar_lock_' ) . '%'
				)
			);
			foreach ( $faz_lock_keys as $faz_lock_key ) {
				delete_option( $faz_lock_key );
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

			// Final catch-all for plugin-prefixed options introduced by newer
			// migrations/caches. When remove_data_on_uninstall is enabled the
			// explicit contract is to leave no FAZ-owned option behind.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$remaining_faz_options = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( 'faz_' ) . '%'
				)
			);
			foreach ( $remaining_faz_options as $remaining_faz_option ) {
				delete_option( $remaining_faz_option );
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
				try {
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
				} catch ( \Throwable $e ) {
					error_log( 'FAZ uninstall: failed to remove ' . $plugin_upload_dir . ' — ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		} catch ( \Throwable $e ) {
			error_log( 'Failed to delete FAZ Cookie Manager plugin data! ' . $e->getMessage() ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	if ( is_multisite() ) {
		$faz_offset       = 0;
		$faz_batch        = 100;
		$faz_any_opted_in = false; // F102 fix: tracks whether ANY subsite opted in.
		do {
			$faz_site_ids = get_sites( array(
				'fields' => 'ids',
				'number' => $faz_batch,
				'offset' => $faz_offset,
			) );
			foreach ( $faz_site_ids as $faz_site_id ) {
				if ( ! faz_should_remove_on_uninstall( $faz_site_id ) ) {
					continue;
				}
				$faz_any_opted_in = true;
				switch_to_blog( $faz_site_id );
				faz_cleanup_site_data();
				restore_current_blog();
			}
			$faz_offset += $faz_batch;
		} while ( count( $faz_site_ids ) === $faz_batch );

		// Multisite-network site transients (CodeRabbit review, 1.14.2;
		// adamsreview F102 fix, 1.14.3): site transients on multisite are
		// stored in wp_sitemeta, NOT per-blog wp_options — the per-blog
		// loop above misses them. Sweep them here.
		//
		// F102 fix: the prior gate `faz_should_remove_on_uninstall( 0 )`
		// was semantically wrong — `get_blog_option( 0, … )` falls back
		// to `get_option()` on whatever blog is current at call time
		// (typically the primary site, blog_id=1), so it only checked
		// the primary site's opt-in. A multisite admin who set
		// remove_data_on_uninstall=true ONLY on a subsite would see
		// network transients survive — even though the per-blog loop
		// above ran the cleanup for that subsite.
		//
		// Correct semantics: if ANY site in the network opted in to
		// data removal, the network-level transients also must go (they
		// would otherwise reference cleaned-up per-blog data and turn
		// into orphans). Use the $faz_any_opted_in accumulator we
		// built during the per-blog loop above.
		//
		// F304 fix (1.14.4): also honour FAZ_REMOVE_ALL_DATA. The
		// constant is a wp-config.php override that forces full data
		// removal regardless of any per-site opt-in. If get_sites()
		// returns empty (degenerate state — pure-network setup,
		// mid-teardown ordering, all subsites just deleted), the
		// per-blog loop never sets $faz_any_opted_in even when the
		// admin explicitly asked for everything to go. Gate the
		// network sweep on either source of intent.
		//
		// Invariant: the network sweep MUST NOT run unless at least
		// one source (per-site opt-in OR FAZ_REMOVE_ALL_DATA) has
		// requested data removal. Flipping this gate would violate
		// the uninstall privacy contract (CodeRabbit#3).
		if ( $faz_force_remove_all || $faz_any_opted_in ) {
			global $wpdb;
			$faz_sitemeta_prefixes = array(
				$wpdb->esc_like( '_site_transient_faz' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_faz' ) . '%',
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$faz_network_transients = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
					$faz_sitemeta_prefixes[0],
					$faz_sitemeta_prefixes[1]
				)
			);
			foreach ( $faz_network_transients as $faz_network_transient ) {
				if ( 0 === strpos( $faz_network_transient, '_site_transient_timeout_' ) ) {
					$faz_network_transient = substr( $faz_network_transient, strlen( '_site_transient_timeout_' ) );
				} elseif ( 0 === strpos( $faz_network_transient, '_site_transient_' ) ) {
					$faz_network_transient = substr( $faz_network_transient, strlen( '_site_transient_' ) );
				}
				delete_site_transient( $faz_network_transient );
			}
		}
	} elseif ( faz_should_remove_on_uninstall() ) {
		faz_cleanup_site_data();
	}
}
