<?php
/**
 * Cachify service adapter.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Admin\Modules\Cache\Services;

use FazCookie\Admin\Modules\Cache\Services\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cachify (pluginkollektiv) purge integration.
 *
 * Cachify registers `cachify_flush_cache` on init as one of the actions that
 * flush its whole cache (Cachify::register_flush_cache_hooks(), the list is
 * filterable through `cachify_flush_cache_hooks`); the same method is public
 * and static, so it is called directly as well in case the action list was
 * filtered. Cachify honours DONOTCACHEPAGE (Cachify::_skip_cache()), so every
 * FAZ page veto already keeps a varying render out; measured on 2.4.x, only
 * the purge on a banner, cookie, category or settings save was missing, and a
 * cached page kept the previous banner until Cachify's own expiry.
 */
class Cachify extends Services {

	/**
	 * Load plugin hooks
	 *
	 * @return void
	 */
	public function run() {
		$this->load_hooks();
	}

	/**
	 * Whether Cachify is active.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return class_exists( '\\Cachify' ) || (bool) has_action( 'cachify_flush_cache' );
	}

	/**
	 * Flush the whole Cachify cache.
	 *
	 * @param boolean $clear Skip the purge when false (hook arg passthrough).
	 * @return void
	 */
	public function clear_cache( $clear = true ) {
		if ( false === $clear ) {
			return;
		}
		if ( has_action( 'cachify_flush_cache' ) ) {
			do_action( 'cachify_flush_cache' );
			return;
		}
		if ( is_callable( array( '\\Cachify', 'flush_total_cache' ) ) ) {
			\Cachify::flush_total_cache( true );
		}
	}
}
