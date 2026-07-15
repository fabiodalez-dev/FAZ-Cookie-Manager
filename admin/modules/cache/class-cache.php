<?php
/**
 * Class Cookies file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Cache;

use FazCookie\Includes\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Cookies
 * @version     3.0.0
 * @package     FazCookie
 */
class Cache extends Modules {

	/**
	 * Guard against double registration of the cache services in exotic
	 * boot orders (e.g. a plugin that re-instantiates the FAZ Admin
	 * module loader, or test fixtures that call init() twice). Without
	 * this flag, load_services() could fire twice and each adapter would
	 * add_action() its clear_cache twice → cache plugins purge twice per
	 * banner CRUD.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Constructor.
	 *
	 * Cache-service registration runs on `plugins_loaded` so third-party
	 * cache plugins (LiteSpeed, WP Rocket, W3TC, …) are already loaded
	 * and their classes are detectable. BUT the FAZ module loader can
	 * fire AFTER plugins_loaded in REST/admin contexts:
	 * `Admin::maybe_load_modules` defers module instantiation to
	 * `rest_api_init`, by which point plugins_loaded has already passed
	 * and any deferred listener is dead weight. Pre-fix this surfaced on
	 * prod (fabiodalez.it 2026-05-18, 1.14.1) as "creating/deleting a
	 * banner doesn't purge LSCache" — Litespeed_Cache::clear_cache was
	 * never registered as a faz_after_update_banner listener because
	 * load_services() never fired.
	 *
	 * Fix: detect the post-plugins_loaded race and run load_services
	 * immediately, otherwise queue it as before. The `$loaded` flag
	 * ensures the registration is exactly-once regardless of boot path.
	 */
	public function init() {
		if ( did_action( 'plugins_loaded' ) ) {
			$this->load_services_once();
			return;
		}
		add_action( 'plugins_loaded', array( $this, 'load_services_once' ) );
	}

	/**
	 * Idempotent wrapper around load_services().
	 *
	 * Public because WordPress's add_action() needs a public method to
	 * dispatch to. External callers should NOT invoke this directly —
	 * use load_services() instead if you genuinely need to re-register.
	 *
	 * @since 1.14.2
	 * @return void
	 */
	public function load_services_once() {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;
		$this->load_services();
	}

	/**
	 * Load services classes.
	 *
	 * @return void
	 */
	public function load_services() {
		$modules = $this->get_services();
		foreach ( $modules as $module ) {
			$parts = explode( '_', $module );
			$temp  = array();
			foreach ( $parts as $part ) {
				$temp[] = ucfirst( $part );
			}
			$class      = implode( '_', $temp );
			$class_name = 'FazCookie\\Admin\\Modules\\Cache\\Services\\' . ucfirst( $class );

			if ( class_exists( $class_name ) ) {
				new $class_name( $module );
			}
		}
	}

	/**
	 * Get supported list of services.
	 *
	 * @return array
	 */
	public function get_services() {
		return array(
			'wp_rocket',
			'autoptimize',
			'hummingbird',
			'w3_total_cache',
			'wp_fastest_cache',
			'wp_super_cache',
			'breeze',
			'siteground_optimize',
			'cache_enabler',
			'litespeed_cache',
			'flying_press',
		);
	}
}
