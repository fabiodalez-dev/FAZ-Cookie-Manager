<?php
/**
 * Abstract class to handle all the modules on the plugin.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Admin\Modules\Cache\Services;

use FazCookie\Admin\Modules\Cache\Services\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * W3 Total Cache purge integration.
 *
 * A banner / cookie / settings save changes only the rendered HTML, so this
 * adapter flushes the page cache alone through w3tc_flush_posts(), W3TC's
 * public "Purges/Flushes all posts" API (w3-total-cache-api.php).
 * w3tc_pgcache_flush(), which this adapter called before, is documented there
 * as a deprecated shortcut that simply returns w3tc_flush_posts(); it stays as
 * the fallback for builds that predate the new name.
 *
 * Activator::purge_page_caches() deliberately uses w3tc_flush_all() instead:
 * a FAZ upgrade ships new JS/CSS files, which W3TC's minify and CDN caches may
 * hold, so that one event flushes every W3TC cache. The two calls differ on
 * purpose, not by drift.
 */
class W3_Total_Cache extends Services {

	/**
	 * Load hooks of each plugin.
	 *
	 * @return void
	 */
	public function run() {
		$this->load_hooks();
	}

	/**
	 * Check if the the cache service is installed/active;
	 *
	 * @return boolean
	 */
	public function is_active() {
		return function_exists( 'w3tc_flush_posts' ) || function_exists( 'w3tc_pgcache_flush' );
	}

	/**
	 * Flush the W3TC page cache.
	 *
	 * @param boolean $clear Skip the purge when false (hook arg passthrough).
	 * @return mixed
	 */
	public function clear_cache( $clear = true ) {
		if ( false === $clear ) {
			return;
		}
		if ( function_exists( 'w3tc_flush_posts' ) ) {
			\w3tc_flush_posts();
			return true;
		}
		return \w3tc_pgcache_flush();
	}
}
