<?php
/**
 * Class Banners file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Banners;

use FazCookie\Includes\Modules;
use FazCookie\Admin\Modules\Banners\Includes\Controller;
use FazCookie\Admin\Modules\Banners\Api\Api;
use FazCookie\Admin\Modules\Banners\Includes\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Banners
 * @version     3.0.0
 * @package     FazCookie
 */
class Banners extends Modules {

	/**
	 * Banners controller class.
	 *
	 * @var object
	 */
	private $controller;

	/**
	 * Constructor.
	 */
	public function init() {
		$this->load_apis();
		$this->controller = Controller::get_instance();
		add_action( 'admin_init', array( $this->controller, 'install_tables' ) );
		add_action( 'faz_after_update_banner', array( $this->controller, 'delete_cache' ) );
		add_action( 'admin_init', array( $this->controller, 'reset_cache' ) );
		add_action( 'admin_init', array( Template::get_instance(), 'delete_cache' ) );
		add_action( 'faz_reinstall_tables', array( $this->controller, 'reinstall' ) );
	}

	/**
	 * Load API files
	 *
	 * @return void
	 */
	public function load_apis() {
		new Api();
	}
}
