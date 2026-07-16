<?php
/**
 * FlyingPress cache service adapter.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Admin\Modules\Cache\Services;

use FazCookie\Admin\Modules\Cache\Services\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FlyingPress (flyingpress.com) purge integration.
 *
 * FlyingPress caches the fully rendered page HTML, so a banner / cookie /
 * settings change would keep serving the stale banner markup until its
 * cache expires or an admin purges it by hand — reported in issue #125
 * ("Cookie banner not saving", FlyingPress + Redis object cache: saving
 * only worked after deactivating FlyingPress and purging its cache).
 * Purging on the faz_after_update_* hooks brings it in line with the
 * other supported page caches (WP Rocket, LiteSpeed, W3TC, …).
 *
 * Uses the API documented at
 * https://docs.flyingpress.com/en/articles/11406092-programmatically-purge-and-preload-cache
 * — FlyingPress\Purge::purge_everything() (full purge; purge_pages() as a
 * fallback for older builds) and FlyingPress\Preload::preload_cache() to
 * re-warm the cache. Both are documented as non-blocking and safe to call
 * from hooks. Calls are guarded with is_callable() so a future FlyingPress
 * refactor degrades to a no-op instead of a fatal.
 */
class Flying_Press extends Services {

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
		return class_exists( '\FlyingPress\Purge' );
	}

	/**
	 * Clear the cache if any.
	 *
	 * @param boolean $clear Skip the purge when false (hook arg passthrough).
	 * @return boolean|void
	 */
	public function clear_cache( $clear = true ) {
		if ( false === $clear ) {
			return;
		}
		if ( is_callable( array( '\FlyingPress\Purge', 'purge_everything' ) ) ) {
			\FlyingPress\Purge::purge_everything();
		} elseif ( is_callable( array( '\FlyingPress\Purge', 'purge_pages' ) ) ) {
			\FlyingPress\Purge::purge_pages();
		} else {
			return false;
		}
		// Re-warm the purged cache; queued/non-blocking per FlyingPress docs.
		if ( is_callable( array( '\FlyingPress\Preload', 'preload_cache' ) ) ) {
			\FlyingPress\Preload::preload_cache();
		}
		return true;
	}
}
