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
