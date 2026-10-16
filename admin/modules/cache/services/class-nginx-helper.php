<?php
/**
 * Nginx Helper cache service adapter.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Admin\Modules\Cache\Services;

use FazCookie\Admin\Modules\Cache\Services\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nginx Helper (rtCamp) purge integration.
 *
 * Nginx Helper registers its purger on the `rt_nginx_helper_purge_all`
 * action, which its changelog introduces "to purge cache from other plugins"
 * (includes/class-nginx-helper.php). Firing it purges the whole FastCGI or
 * Redis page cache with the transport the administrator configured in Nginx
 * Helper; FAZ makes no request of its own.
 */
class Nginx_Helper extends Services {

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
		return (bool) has_action( 'rt_nginx_helper_purge_all' ) || class_exists( 'Nginx_Helper' );
	}

	/**
	 * Purge the whole Nginx page cache.
	 *
	 * @param boolean $clear Skip the purge when false (hook arg passthrough).
	 * @return void
	 */
	public function clear_cache( $clear = true ) {
		if ( false === $clear ) {
			return;
		}
		do_action( 'rt_nginx_helper_purge_all' );
	}
}
