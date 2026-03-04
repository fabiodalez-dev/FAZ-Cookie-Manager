<?php
/**
 * Class Gcm file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Gcm;

use FazCookie\Includes\Modules;
use FazCookie\Admin\Modules\Gcm\Api\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Gcm extends Modules {
	/**
	 * Constructor.
	 */
	public function init() {
		$this->load_apis();
		$this->load_gcm_default();
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

	public function load_gcm_default() {
		if ( false === faz_first_time_install() ||  false !== get_option( 'faz_gcm_settings', false ) ) {
			return;
		}
		$settings = new \FazCookie\Admin\Modules\Gcm\Includes\Gcm_Settings();
		$default  = $settings->get_defaults();
		$settings->update( $default );
	}
}