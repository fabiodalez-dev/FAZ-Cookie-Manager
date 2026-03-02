<?php
/**
 * Abstract class to handle all the modules on the plugin.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Module
 */
abstract class Modules {

	/**
	 * Module slug name
	 *
	 * @var string
	 */
	protected $module_id = '';

	/**
	 * Slug of the main menu
	 *
	 * @var string
	 */
	protected static $menu_slug = 'faz-cookie-manager';

	/**
	 * Default capability of menus
	 *
	 * @var string
	 */
	protected static $capability = 'manage_options';
	/**
	 * All the module translation strings.
	 *
	 * @var array
	 */
	public $translations = array();

	/**
	 * Module constructor.
	 *
	 * @param string $module_id  Module identifier.
	 */
	public function __construct( $module_id ) {
		$this->module_id = $module_id;
		if ( true === $this->is_active() ) {
			$this->init();
		}
	}

	/**
	 * Return true if the module is activated
	 *
	 * @return Boolean
	 */
	public function is_active() {
		$module_id = $this->get_module_id();
		return apply_filters( "faz_is_module_active_$module_id", true );
	}
	/**
	 * Return the module slug name
	 *
	 * @return string
	 */
	public function get_module_id() {
		return $this->module_id;
	}

	/**
	 * Initializes the module. Always executed even if the module is deactivated.
	 *
	 * Do not use __construct in subclasses, use init() instead
	 */
	abstract public function init();
}
