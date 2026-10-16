<?php
/**
 * Proxy Cache Purge (Varnish HTTP Purge) cache service adapter.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Admin\Modules\Cache\Services;

use FazCookie\Admin\Modules\Cache\Services\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proxy Cache Purge purge integration.
 *
 * Proxy Cache Purge has no "purge everything" function for other plugins.
 * What it documents ("Plugins can hook into the purge actions as well",
 * readme.txt, and the Custom Filters wiki) is two filters: an action listed
 * in `varnish_http_purge_events` triggers a purge, and one also listed in
 * `varnish_http_purge_events_full` triggers a FULL purge. The plugin reads
 * both lists on `init`, so they are registered from the always-loaded admin
 * bootstrap (register_purge_events()), while this adapter, loaded with the
 * cache module, only fires the FAZ action from clear_cache() — which keeps
 * the `$clear === false` passthrough of the FAZ hooks working. The purge
 * itself (an HTTP PURGE to the proxy the administrator configured, queued
 * or sent at shutdown) is Proxy Cache Purge's own; FAZ sends nothing.
 */
class Proxy_Cache_Purge extends Services {

	/**
	 * FAZ action registered as a full-purge event.
	 */
	const PURGE_ACTION = 'faz_proxy_cache_purge_all';

	/**
	 * Add the FAZ action to both Proxy Cache Purge event lists. Must run
	 * before `init`; harmless when Proxy Cache Purge is absent.
	 *
	 * @return void
	 */
	public static function register_purge_events() {
		add_filter( 'varnish_http_purge_events', array( __CLASS__, 'add_purge_event' ) );
		add_filter( 'varnish_http_purge_events_full', array( __CLASS__, 'add_purge_event' ) );
	}

	/**
	 * Filter callback: append the FAZ action to an event list.
	 *
	 * @param mixed $events Event list.
	 * @return mixed
	 */
	public static function add_purge_event( $events ) {
		if ( ! is_array( $events ) ) {
			return $events;
		}
		if ( ! in_array( self::PURGE_ACTION, $events, true ) ) {
			$events[] = self::PURGE_ACTION;
		}
		return $events;
	}

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
		return class_exists( 'VarnishPurger' );
	}

	/**
	 * Ask Proxy Cache Purge for a full purge.
	 *
	 * @param boolean $clear Skip the purge when false (hook arg passthrough).
	 * @return void
	 */
	public function clear_cache( $clear = true ) {
		if ( false === $clear ) {
			return;
		}
		do_action( self::PURGE_ACTION );
	}
}
