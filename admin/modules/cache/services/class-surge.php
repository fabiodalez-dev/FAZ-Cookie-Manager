<?php
/**
 * Surge service adapter.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Admin\Modules\Cache\Services;

use FazCookie\Admin\Modules\Cache\Services\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Surge (wordpress.org/plugins/surge) purge integration.
 *
 * Surge has no purge function to call. It expires cache entries by flag: a
 * flag is queued with `Surge\expire()` during the request and written to
 * flags.json.php on Surge's own shutdown hook, after which every entry
 * carrying that flag (every page carries '/') is regenerated. Two ways in:
 *
 *   - `surge_flush_actions` — the list of action names whose firing during a
 *     request expires the whole site (include/invalidate.php, read on
 *     shutdown). FAZ adds its own purge action to that list and fires it from
 *     clear_cache(), which is the mechanism Surge documents for plugins.
 *   - `Surge\expire( '/' )` directly, when the function is loaded — it is on
 *     every request that went through Surge's advanced-cache drop-in.
 *
 * Both are used: the direct call covers a request on which the filter list is
 * not consulted, the action covers a build where the function moved. Neither
 * can fail loudly: Surge writes its flags file with a lock on shutdown and
 * returns silently when the cache directory cannot be created.
 *
 * Measured before this adapter (Surge 1.2.1): a banner save through the REST
 * API left the cached page serving the previous banner until Surge's own
 * expiry — Surge honours DONOTCACHEPAGE and the no-store header, so every
 * veto already worked; only the purge on save was missing.
 */
class Surge extends Services {

	/**
	 * Action FAZ fires to expire Surge's cache; added to `surge_flush_actions`.
	 */
	const FLUSH_ACTION = 'faz_surge_flush_all';

	/**
	 * Register the flush action with Surge and load the purge hooks.
	 *
	 * @return void
	 */
	public function run() {
		add_filter( 'surge_flush_actions', array( __CLASS__, 'add_flush_action' ) );
		$this->load_hooks();
	}

	/**
	 * Add the FAZ purge action to Surge's full-site flush list.
	 *
	 * @param array $actions Action names Surge expires the site on.
	 * @return array
	 */
	public static function add_flush_action( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		if ( ! in_array( self::FLUSH_ACTION, $actions, true ) ) {
			$actions[] = self::FLUSH_ACTION;
		}
		return $actions;
	}

	/**
	 * Whether Surge is active.
	 *
	 * Surge loads include/common.php (the `Surge\` functions) from its
	 * advanced-cache drop-in and include/invalidate.php on plugins_loaded, after
	 * this adapter is built, so the plugin file is checked as well.
	 *
	 * @return boolean
	 */
	public function is_active() {
		if ( function_exists( 'Surge\\expire' ) || function_exists( 'Surge\\config' ) ) {
			return true;
		}
		$active = (array) get_option( 'active_plugins', array() );
		return in_array( 'surge/surge.php', $active, true );
	}

	/**
	 * Expire every Surge cache entry.
	 *
	 * @param boolean $clear Skip the purge when false (hook arg passthrough).
	 * @return void
	 */
	public function clear_cache( $clear = true ) {
		if ( false === $clear ) {
			return;
		}
		if ( function_exists( 'Surge\\expire' ) ) {
			\Surge\expire( '/' );
		}
		do_action( self::FLUSH_ACTION );
	}
}
