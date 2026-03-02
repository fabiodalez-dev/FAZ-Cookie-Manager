<?php
/**
 * Class ConsentLogs file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Consentlogs;

use FazCookie\Includes\Modules;
use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;
use FazCookie\Admin\Modules\Consentlogs\Api\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


/**
 * Handles Cookies Operation
 *
 * @class       ConsentLogs
 * @version     3.0.0
 * @package     FazCookie
 */
class ConsentLogs extends Modules {

	/**
	 * ConsentLogs controller class.
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
		add_filter( 'faz_registered_admin_menus', array( $this, 'register_menus' ) );
	}

	/**
	 * Load API files
	 *
	 * @return void
	 */
	public function load_apis() {
		new Api();
	}

	/**
	 * Pass menu items to be registered.
	 *
	 * @param array $menus Sub menu array.
	 * @return array
	 */
	public function register_menus( $menus ) {
		$menus['logs'] = array(
			'name'     => __( 'Consent Log', 'faz-cookie-manager' ),
			'callback' => array( $this, 'menu_page_template' ),
			'order'    => 4,
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
