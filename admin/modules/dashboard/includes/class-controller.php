<?php
/**
 * Dashboard controller class.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @package    FazCookie\Admin\Modules\Dashboard\Includes
 */

namespace FazCookie\Admin\Modules\Dashboard\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Dashboard controller class.
 *
 * @class       Controller
 * @version     3.0.0
 * @package     FazCookie
 */
class Controller {

	/**
	 * Instance of the current class
	 *
	 * @var object
	 */
	private static $instance;
	/**
	 * Cookie items
	 *
	 * @var array
	 */
	public $languages;

	/**
	 * Return the current instance of the class
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Load data from local database.
	 *
	 * @return array
	 */
	public function get_items() {
		$cookie_controller   = \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller::get_instance();
		$category_controller = \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::get_instance();

		$cookies    = $cookie_controller->get_items();
		$categories = $category_controller->get_items();

		return array(
			'cookies'    => is_array( $cookies ) ? count( $cookies ) : 0,
			'scripts'    => 0,
			'categories' => is_array( $categories ) ? count( $categories ) : 0,
			'pages'      => 0,
		);
	}

}