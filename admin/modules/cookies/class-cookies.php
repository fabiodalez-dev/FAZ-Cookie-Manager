<?php
/**
 * Class Cookies file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Cookies;

use FazCookie\Includes\Modules;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
use FazCookie\Admin\Modules\Cookies\Api\Categories_API;
use FazCookie\Admin\Modules\Cookies\Api\Cookies_API;
use FazCookie\Admin\Modules\Cookies\Api\Cookie_Scraper;

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
class Cookies extends Modules {

	/**
	 * Constructor.
	 */
	public function init() {
		$this->load_apis();
		add_action( 'faz_after_update_cookie', array( Category_Controller::get_instance(), 'delete_cache' ) );
		add_action( 'faz_after_update_cookie_category', array( Cookie_Controller::get_instance(), 'delete_cache' ) );
		add_action( 'faz_after_update_cookie_category', array( Category_Controller::get_instance(), 'delete_cache' ) );
		add_action( 'faz_reinstall_tables', array( Category_Controller::get_instance(), 'reinstall' ) );
		add_action( 'faz_reinstall_tables', array( Cookie_Controller::get_instance(), 'reinstall' ) );
		// Only run cache reset on FAZ admin pages — not on every admin_init.
		if ( self::is_faz_admin_request() ) {
			Cookie_Controller::get_instance()->reset_cache();
			Category_Controller::get_instance()->reset_cache();
		}
	}

	/**
	 * Quick check whether the current request is a FAZ admin page.
	 *
	 * @return bool
	 */
	private static function is_faz_admin_request() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return false;
		}
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return false !== strpos( $page, 'faz-cookie-manager' );
	}

	/**
	 * Load API files
	 *
	 * @return void
	 */
	public function load_apis() {
		$cookie_cat_api = new Categories_API();
		$cookie_api     = new Cookies_API();
		$cookie_scraper = new Cookie_Scraper();
	}
}
