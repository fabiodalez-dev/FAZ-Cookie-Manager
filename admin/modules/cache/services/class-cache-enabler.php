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
 * Cache Enabler purge integration.
 *
 * Cache Enabler 1.6.0 renamed its public "clear the complete cache" action to
 * `cache_enabler_clear_complete_cache`; `ce_clear_cache`, which this adapter
 * used before, is still registered but marked "Deprecated in 1.6.0" in
 * inc/cache_enabler.class.php, and both run the same
 * Cache_Enabler::clear_complete_cache(). The adapter now fires the current
 * action and keeps the old one only for builds older than 1.6.0, which is
 * also what Activator::purge_page_caches() does on an upgrade.
 */
class Cache_Enabler extends Services {

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
		return (bool) ( has_action( 'cache_enabler_clear_complete_cache' ) || has_action( 'ce_clear_cache' ) );
	}

	/**
	 * Clear the complete Cache Enabler cache.
	 *
	 * @param boolean $clear Skip the purge when false (hook arg passthrough).
	 * @return void
	 */
	public function clear_cache( $clear = true ) {
		if ( false === $clear ) {
			return;
		}
		if ( has_action( 'cache_enabler_clear_complete_cache' ) ) {
			do_action( 'cache_enabler_clear_complete_cache' );
			return;
		}
		do_action( 'ce_clear_cache' );
	}
}
