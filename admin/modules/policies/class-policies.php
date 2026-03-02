<?php
/**
 * Class Policies file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Policies;

use FazCookie\Includes\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Policies
 * @version     3.0.0
 * @package     FazCookie
 */
class Policies extends Modules {

	/**
	 * Constructor.
	 */
	public function init() {
		add_filter( 'faz_registered_admin_menus', array( $this, 'register_menus' ) );
	}

	/**
	 * Pass menu items to be registered.
	 *
	 * @param array $menus Sub menu array.
	 * @return array
	 */
	public function register_menus( $menus ) {
		$menus['policies'] = array(
			'name'     => __( 'Policy Generators', 'faz-cookie-manager' ),
			'callback' => array( $this, 'menu_page_template' ),
			'order'    => 5,
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
