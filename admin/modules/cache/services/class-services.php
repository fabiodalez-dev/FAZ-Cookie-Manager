<?php
/**
 * Abstract class to handle all the modules on the plugin.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Admin\Modules\Cache\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Module
 */
abstract class Services {

	/**
	 * Module constructor.
	 */
	public function __construct() {
		if ( $this->is_active() ) {
			$this->run();
		}
	}

	/**
	 * Load plugin hooks
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'faz_after_update_banner', array( $this, 'clear_cache' ), 10, 1 );
		add_action( 'faz_after_update_cookie', array( $this, 'clear_cache' ) );
		add_action( 'faz_after_create_cookie', array( $this, 'clear_cache' ) );
		add_action( 'faz_after_delete_cookie', array( $this, 'clear_cache' ) );
		add_action( 'faz_after_update_cookie_category', array( $this, 'clear_cache' ) );
		add_action( 'faz_after_delete_cookie_category', array( $this, 'clear_cache' ) );
		add_action( 'faz_after_update_settings', array( $this, 'clear_cache' ), 10, 1 );
		add_action( 'faz_after_activate', array( $this, 'clear_cache' ) );
		add_action( 'faz_clear_cache', array( $this, 'clear_cache' ) );
		// Page cache only: fired by writes that change the rendered HTML without
		// touching banners, cookies or settings — the IAB vendor selection and
		// list (embedded in _fazConfig), the Cookie Policy generator data
		// (rendered by its shortcode). faz_clear_cache would also drop the
		// banner template and the frontend transients for no reason.
		add_action( 'faz_purge_page_cache', array( $this, 'clear_cache' ) );
	}
	/**
	 * Check if the the cache service is installed/active;
	 *
	 * @return boolean
	 */
	abstract public function is_active();

	/**
	 * Initializes the module. Always executed even if the module is deactivated.
	 *
	 * Do not use __construct in subclasses, use init() instead
	 */
	abstract public function clear_cache();

	/**
	 * Initializes the module. Always executed even if the module is deactivated.
	 *
	 * Do not use __construct in subclasses, use init() instead
	 */
	abstract public function run();
}
