<?php
/**
 * Super Page Cache (formerly WP Cloudflare Super Page Cache) service adapter.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Admin\Modules\Cache\Services;

use FazCookie\Admin\Modules\Cache\Services\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Super Page Cache purge integration.
 *
 * The plugin documents the `swcfpc_purge_cache` action "to purge cache
 * programmatically" (readme.txt changelog and FAQ); without an argument it
 * purges everything. Detection uses SWCFPC_PLUGIN_PATH, defined when the
 * plugin file loads, because the action itself is registered later than the
 * cache module. Super Page Cache then clears its own disk cache and, when
 * the administrator connected one, its Cloudflare zone, with credentials FAZ
 * never sees.
 */
class Super_Page_Cache extends Services {

	/**
	 * Load plugin hooks
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
		return defined( 'SWCFPC_PLUGIN_PATH' ) || (bool) has_action( 'swcfpc_purge_cache' );
	}

	/**
	 * Purge the whole Super Page Cache cache.
	 *
	 * @param boolean $clear Skip the purge when false (hook arg passthrough).
	 * @return void
	 */
	public function clear_cache( $clear = true ) {
		if ( false === $clear ) {
			return;
		}
		do_action( 'swcfpc_purge_cache' );
	}
}
