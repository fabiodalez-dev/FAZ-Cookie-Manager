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
		add_action( 'admin_init', array( Category_Controller::get_instance(), 'install_tables' ) );
		add_action( 'faz_after_update_cookie', array( Category_Controller::get_instance(), 'delete_cache' ) );
		add_action( 'faz_after_update_cookie_category', array( Cookie_Controller::get_instance(), 'delete_cache' ) );
		add_action( 'faz_after_update_cookie_category', array( Category_Controller::get_instance(), 'delete_cache' ) );
		add_action( 'admin_init', array( Cookie_Controller::get_instance(), 'reset_cache' ) );
		add_action( 'admin_init', array( Category_Controller::get_instance(), 'reset_cache' ) );
		add_action( 'admin_init', array( Cookie_Controller::get_instance(), 'install_tables' ) );
		add_filter( 'faz_registered_admin_menus', array( $this, 'register_menus' ) );
		add_action( 'faz_reinstall_tables', array( Category_Controller::get_instance(), 'reinstall' ) );
		add_action( 'faz_reinstall_tables', array( Cookie_Controller::get_instance(), 'reinstall' ) );
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

	/**
	 * Pass menu items to be registered.
	 *
	 * @param array $menus Sub menu array.
	 * @return array
	 */
	public function register_menus( $menus ) {
		$menus['cookies'] = array(
			'name'     => __( 'Cookie Manager', 'faz-cookie-manager' ),
			'callback' => array( $this, 'menu_page_template' ),
			'order'    => 3,
		);
		return $menus;
	}

	/**
	 * Main menu template
	 *
	 * @return void
	 */
	public function menu_page_template() {
		echo '<div id="faz-app"></div>';
	}
}
