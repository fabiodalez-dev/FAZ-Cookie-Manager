<?php
/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @package    FazCookie
 * @subpackage FazCookie/includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      3.0.0
 * @package    FazCookie
 * @subpackage FazCookie/includes
 * @author     Fabio D'Alessandro
 */
class I18n {

	/**
	 * Instance of the current class
	 *
	 * @var object
	 */
	private static $instance;

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
	 * No-op kept for backward compatibility with the loader registration in
	 * class-cli.php. WordPress 4.6+ auto-loads the plugin text domain from
	 * the plugin slug ("faz-cookie-manager") on the init hook, so an explicit
	 * load_plugin_textdomain() call is no longer required and is in fact
	 * discouraged on wp.org-hosted plugins (PluginCheck.CodeAnalysis.
	 * DiscouragedFunctions.load_plugin_textdomainFound).
	 *
	 * @since 3.0.0
	 */
	public function load_plugin_textdomain() {
		// Intentional no-op. WordPress 4.6+ handles textdomain loading.
	}
}
