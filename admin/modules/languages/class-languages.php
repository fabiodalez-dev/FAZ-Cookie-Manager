<?php
/**
 * Class Languages file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Languages;

use FazCookie\Includes\Modules;
use FazCookie\Admin\Modules\Languages\Api\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Languages
 * @version     3.0.0
 * @package     FazCookie
 */
class Languages extends Modules {

	/**
	 * Constructor.
	 */
	public function init() {
		$controller = new \FazCookie\Admin\Modules\Languages\Includes\Controller();
		$this->load_apis();
		add_filter( 'faz_admin_scripts_languages', array( $controller, 'load_config' ) );
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
