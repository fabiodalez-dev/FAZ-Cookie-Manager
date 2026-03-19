<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @package    FazCookie
 * @subpackage FazCookie/includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FazCookie\Includes\Loader;
use FazCookie\Includes\I18n;
use FazCookie\Admin\Admin;
use FazCookie\Frontend\Frontend;
use FazCookie\Admin\Modules\Settings\Includes\Settings;

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      3.0.0
 * @package    FazCookie
 * @subpackage FazCookie/Includes
 * @author     Fabio D'Alessandro
 */
class CLI {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    3.0.0
	 * @access   protected
	 * @var      Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    3.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    3.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Initial version of plugin database.
	 *
	 * Since 1.9.4 we've started to store cookie database version on the plugin.
	 *
	 * @var string
	 */
	public static $db_initial_version = '1.9.4';

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    3.0.0
	 */
	public function __construct() {
		if ( defined( 'FAZ_VERSION' ) ) {
			$this->version = FAZ_VERSION;
		} else {
			$this->version = '1.0.5';
		}
		$this->plugin_name = 'faz-cookie-manager';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->register_blocks();
		$this->init_license();
		$this->register_privacy_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Loader. Orchestrates the hooks of the plugin.
	 * - I18n. Defines internationalization functionality.
	 * - Admin. Defines all hooks for the admin area.
	 * - Frontend. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    3.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-utils.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-formatting.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-i18n-helpers.php';
		$this->loader = new \FazCookie\Includes\Loader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the I18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    3.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = I18n::get_instance();
		$this->loader->add_action( 'init', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    3.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Admin( $this->get_version() );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    3.0.0
	 * @access   private
	 */
	private function define_public_hooks() {
		// Skip frontend initialization on admin page requests — none of the
		// frontend hooks (wp_footer, wp_enqueue_scripts, template_redirect,
		// etc.) fire in admin context, so the object creation is wasted work.
		// We must NOT skip on REST API or AJAX requests because the
		// Consent_Logger registers REST routes through the Frontend class.
		if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! self::is_rest_url() ) {
			return;
		}
		new Frontend( $this->get_plugin_name(), $this->get_version() );
	}

	/**
	 * Check if the current request URL targets the REST API.
	 *
	 * REST_REQUEST is not defined yet during plugins_loaded, so we
	 * also check the request URI as a fallback.
	 *
	 * @return bool
	 */
	private static function is_rest_url() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$rest_prefix = rest_get_url_prefix();
		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		return false !== strpos( $request_uri, '/' . $rest_prefix . '/' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    3.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     3.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     3.0.0
	 * @return    Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     3.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Register Gutenberg blocks.
	 *
	 * Blocks are loaded on every request (admin + frontend) so that
	 * the editor can register them and the frontend can render them.
	 *
	 * @return void
	 */
	private function register_blocks() {
		new \FazCookie\Includes\Blocks\Blocks();
	}

	/**
	 * Register WordPress privacy tools hooks (Export/Erase Personal Data).
	 *
	 * Adds privacy policy suggested content, a personal data exporter,
	 * and a personal data eraser for consent logs.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	private function register_privacy_hooks() {
		// Register privacy policy suggested content.
		add_action( 'admin_init', function () {
			if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
				return;
			}
			$content = sprintf(
				'<h2>%s</h2><p>%s</p><p>%s</p>',
				esc_html__( 'Cookie Consent (FAZ Cookie Manager)', 'faz-cookie-manager' ),
				esc_html__( 'This site uses the FAZ Cookie Manager plugin to collect and manage cookie consent. When you interact with the cookie banner, we record your consent choice (accepted, rejected, or partial), a hashed version of your IP address, your browser user agent, and the page URL where consent was given. This data is stored locally on our server and retained for the period configured in the plugin settings (default: 12 months).', 'faz-cookie-manager' ),
				esc_html__( 'You can change your cookie preferences at any time using the cookie icon in the bottom corner of the page.', 'faz-cookie-manager' )
			);
			wp_add_privacy_policy_content( 'FAZ Cookie Manager', $content );
		});

		// Register personal data exporter.
		add_filter( 'wp_privacy_personal_data_exporters', function ( $exporters ) {
			$exporters['faz-cookie-manager'] = array(
				'exporter_friendly_name' => __( 'FAZ Cookie Manager - Consent Logs', 'faz-cookie-manager' ),
				'callback'               => 'faz_privacy_exporter',
			);
			return $exporters;
		});

		// Register personal data eraser.
		add_filter( 'wp_privacy_personal_data_erasers', function ( $erasers ) {
			$erasers['faz-cookie-manager'] = array(
				'eraser_friendly_name' => __( 'FAZ Cookie Manager - Consent Logs', 'faz-cookie-manager' ),
				'callback'             => 'faz_privacy_eraser',
			);
			return $erasers;
		});
	}

	/**
	 * Inititialize the license.
	 *
	 * @return void
	 */
	public function init_license() {
		if ( ! defined( 'FAZ_CLOUD_REQUEST' ) ) {
			define( 'FAZ_CLOUD_REQUEST', false );
		}
	}
}
