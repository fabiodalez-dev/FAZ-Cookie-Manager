<?php
/**
 * Class Scanner file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Scanner;

use FazCookie\Includes\Modules;
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Scanner
 * @version     3.0.0
 * @package     FazCookie
 */
class Scanner extends Modules {

	/**
	 * Constructor.
	 */
	public function init() {
		$controller = new \FazCookie\Admin\Modules\Scanner\Includes\Controller();
		$this->load_apis( $controller );

		// Register async scan cron hook.
		\FazCookie\Admin\Modules\Scanner\Includes\Controller::register_cron_hook();
	}

	/**
	 * Load API classes
	 *
	 * @param object $controller Controller object.
	 * @return void
	 */
	public function load_apis( $controller ) {
		$api = new \FazCookie\Admin\Modules\Scanner\Api\Api( $controller );
	}

	/**
	 * Add admin sub menus
	 *
	 * @return void
	 */
	public function admin_menu() {

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
