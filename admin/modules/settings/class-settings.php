<?php
/**
 * Class Settings file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Settings;

use FazCookie\Includes\Modules;
use FazCookie\Admin\Modules\Settings\Api\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Settings
 * @version     3.0.0
 * @package     FazCookie
 */
class Settings extends Modules {

	/**
	 * Constructor.
	 */
	public function init() {
		$this->load_default();
		$this->load_apis();
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
	 * Main menu template
	 *
	 * @return void
	 */
	public function menu_page_template() {
		echo '<div id="faz-app"></div>';
	}

	/**
	 * Load default settings to the database.
	 *
	 * @return void
	 */
	public function load_default() {
		if ( false === faz_first_time_install() ||  false !== get_option( 'faz_settings', false ) ) {
			return;
		}
		$settings = new \FazCookie\Admin\Modules\Settings\Includes\Settings();
		$default  = $settings->get_defaults();
		$settings->update( $default );
	}
}
