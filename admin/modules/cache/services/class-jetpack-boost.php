<?php
/**
 * Jetpack Boost service adapter.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Admin\Modules\Cache\Services;

use FazCookie\Admin\Modules\Cache\Services\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jetpack Boost page cache ("Cache Site Pages") purge integration.
 *
 * Boost's page-cache module documents one public action for third-party
 * plugins, `jetpack_boost_clear_page_cache_all` (app/modules/optimizations/
 * page-cache/pre-wordpress/boost-cache-actions.php), which deletes every
 * cached page. It is registered by the module's pre-WordPress bootstrap, so
 * it exists on every request where the page cache could have stored a page.
 * Boost honours DONOTCACHEPAGE (class-request.php) and the no-store header,
 * so every FAZ veto already keeps a varying render out; this adapter adds the
 * purge on a banner, cookie, category or settings save, without which a
 * cached page kept serving the previous banner until Boost's garbage
 * collection.
 */
class Jetpack_Boost extends Services {

	/**
	 * Load plugin hooks
	 *
	 * @return void
	 */
	public function run() {
		$this->load_hooks();
	}

	/**
	 * Whether Jetpack Boost is active.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return defined( 'JETPACK_BOOST_VERSION' ) || (bool) has_action( 'jetpack_boost_clear_page_cache_all' );
	}

	/**
	 * Delete every page Jetpack Boost cached.
	 *
	 * @param boolean $clear Skip the purge when false (hook arg passthrough).
	 * @return void
	 */
	public function clear_cache( $clear = true ) {
		if ( false === $clear ) {
			return;
		}
		do_action( 'jetpack_boost_clear_page_cache_all' );
	}
}
