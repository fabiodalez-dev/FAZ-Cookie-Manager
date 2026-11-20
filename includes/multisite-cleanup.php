<?php
/**
 * Plugin Name: FAZ Cookie Manager Multisite Cleanup
 * Description: Removes FAZ Cookie Manager tables when a multisite subsite is deleted.
 * License: GPL-3.0-or-later
 *
 * @package FazCookie
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Single site: nothing to clean up. `return`, not `exit`: the plugin
// bootstrap includes this file on every request.
if ( ! is_multisite() ) {
	return;
}

if ( ! function_exists( 'faz_drop_site_tables' ) ) {
	/**
	 * Drop this plugin's tables along with a deleted subsite.
	 *
	 * Deleting a subsite dropped only the core tables: the consent log (with
	 * ip_hash and user agent), the pageviews, the embed inventory and the
	 * banner / cookie / category tables stayed in the database for good. Blog ids
	 * are not reused, so nothing read them again; it was a retention gap.
	 *
	 * wpmu_drop_tables is the list core drops. It is applied by
	 * wp_uninitialize_site() (the wp_uninitialize_site handler, WordPress 5.1+)
	 * and by wpmu_delete_blog() before 5.1, so one filter covers every version
	 * the plugin supports, and no second handler drops the same tables. Core runs
	 * DROP TABLE IF EXISTS, so a subsite that never had the plugin is unaffected.
	 *
	 * @param string[] $tables  Tables core is about to drop.
	 * @param int      $site_id Site being deleted.
	 * @return string[]
	 */
	function faz_drop_site_tables( $tables, $site_id ) {
		global $wpdb;
		if ( ! is_array( $tables ) ) {
			return $tables;
		}
		$prefix = $wpdb->get_blog_prefix( (int) $site_id );
		foreach ( array( 'faz_banners', 'faz_cookie_categories', 'faz_cookies', 'faz_consent_logs', 'faz_pageviews', 'faz_embed_placeholders' ) as $faz_table ) {
			$tables[ $faz_table ] = $prefix . $faz_table;
		}
		return $tables;
	}

	add_filter( 'wpmu_drop_tables', 'faz_drop_site_tables', 10, 2 );
}
