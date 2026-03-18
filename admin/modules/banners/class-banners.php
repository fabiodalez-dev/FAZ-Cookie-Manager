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
		$this->controller = Controller::get_instance();
		$this->load_apis();
		add_action( 'faz_after_update_banner', array( $this->controller, 'delete_cache' ) );
		add_action( 'faz_reinstall_tables', array( $this->controller, 'reinstall' ) );
		// Only run cache reset / template invalidation on FAZ admin pages.
		if ( self::is_faz_admin_request() ) {
			$this->controller->reset_cache();
			Template::get_instance()->delete_cache();
		}
	}

	/**
	 * Quick check whether the current request is a FAZ admin page.
	 *
	 * Uses the raw $_GET['page'] parameter (available before get_current_screen())
	 * to avoid expensive function calls on non-FAZ pages.
	 *
	 * @return bool
	 */
	private static function is_faz_admin_request() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return false;
		}
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return false !== strpos( $page, 'faz-cookie-manager' );
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
