<?php
/**
 * WP-Optimize page cache service adapter.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Admin\Modules\Cache\Services;

use FazCookie\Admin\Modules\Cache\Services\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Optimize page cache purge integration.
 *
 * WP_Optimize()->get_page_cache()->purge() is what WP-Optimize's own "Purge
 * cache" control runs: it deletes the page cache files and fires
 * `wpo_cache_flush`. wpo_cache_flush(), used by the upgrade purge, also
 * flushes the whole object cache (Redis/Memcached), which a banner or cookie
 * save does not justify; it stays the fallback for builds without the
 * accessor.
 */
class Wp_Optimize extends Services {

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
		return function_exists( 'WP_Optimize' ) || function_exists( 'wpo_cache_flush' );
	}

	/**
	 * Purge the WP-Optimize page cache.
	 *
	 * @param boolean $clear Skip the purge when false (hook arg passthrough).
	 * @return boolean|void
	 */
	public function clear_cache( $clear = true ) {
		if ( false === $clear ) {
			return;
		}
		try {
			if ( function_exists( 'WP_Optimize' ) ) {
				$wpo = \WP_Optimize();
				if ( is_object( $wpo ) && method_exists( $wpo, 'get_page_cache' ) ) {
					$page_cache = $wpo->get_page_cache();
					if ( is_object( $page_cache ) && method_exists( $page_cache, 'purge' ) ) {
						return (bool) $page_cache->purge();
					}
				}
			}
			if ( function_exists( 'wpo_cache_flush' ) ) {
				\wpo_cache_flush();
				return true;
			}
		} catch ( \Throwable $error ) {
			// Fail closed: clear_cache() runs inside do_action(), where a throw
			// would abort the remaining adapters and the admin save.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'FAZ Cookie Manager: WP-Optimize purge failed — ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		return false;
	}
}
