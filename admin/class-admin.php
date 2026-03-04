<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @package    FazCookie\Admin
 */

namespace FazCookie\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FazCookie\Includes\Notice;

/**
 * The admin-specific functionality of the plugin.
 *
 * @package    FazCookie
 * @subpackage FazCookie/admin
 */
class Admin {

	/**
	 * Admin menu slug prefix.
	 */
	private const ADMIN_SLUG = 'faz-cookie-manager';

	/**
	 * The version of this plugin.
	 *
	 * @since    3.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Admin modules of the plugin
	 *
	 * @var array
	 */
	private static $modules;

	/**
	 * Currently active modules
	 *
	 * @var array
	 */
	private static $active_modules;

	/**
	 * Existing modules
	 *
	 * @var array
	 */
	public static $existing_modules;

	/**
	 * Submenu pages config.
	 *
	 * @var array
	 */
	private $pages;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    3.0.0
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $version ) {
		$this->version = $version;
		self::$modules = $this->get_default_modules();
		$this->load();
		$this->add_notices();
		$this->add_review_notice();
		$this->load_modules();
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'load_plugin' ) );
		add_action( 'activated_plugin', array( $this, 'handle_activation_redirect' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_classes' ) );
		add_action( 'admin_print_scripts', array( $this, 'hide_admin_notices' ) );
		add_filter( 'plugin_action_links_' . FAZ_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Load activator on each load.
	 *
	 * @return void
	 */
	public function load() {
		\FazCookie\Includes\Activator::init();
	}

	/**
	 * Load admin notices — disabled for local mode.
	 *
	 * @return void
	 */
	public function add_notices() {
	}

	/**
	 * Add review notice — disabled for local mode.
	 *
	 * @return void
	 */
	public function add_review_notice() {
	}

	/**
	 * Get the default modules array.
	 *
	 * @return array
	 */
	public function get_default_modules() {
		return array(
			'settings',
			'gcm',
			'gvl',
			'languages',
			'dashboard',
			'banners',
			'cookies',
			'consentlogs',
			'scanner',
			'pageviews',
			'cache',
		);
	}

	/**
	 * Get the active admin modules.
	 *
	 * @return void
	 */
	public function get_active_modules() {
	}

	/**
	 * Load all the modules.
	 *
	 * @return void
	 */
	public function load_modules() {
		foreach ( self::$modules as $module ) {
			$parts      = explode( '_', $module );
			$class      = implode( '_', $parts );
			$class_name = 'FazCookie\\Admin\\Modules\\' . ucfirst( $module ) . '\\' . ucfirst( $class );

			if ( class_exists( $class_name ) ) {
				$module_obj = new $class_name( $module );
				if ( $module_obj instanceof $class_name ) {
					if ( $module_obj->is_active() ) {
						self::$active_modules[ $module ] = true;
					}
				}
			}
		}
	}

	/**
	 * Admin page definitions.
	 *
	 * @return array
	 */
	private function get_admin_pages() {
		return array(
			'dashboard'    => array(
				'title' => __( 'Dashboard', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG,
				'view'  => 'dashboard',
			),
			'banner'       => array(
				'title' => __( 'Cookie Banner', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-banner',
				'view'  => 'banner',
			),
			'cookies'      => array(
				'title' => __( 'Cookies', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-cookies',
				'view'  => 'cookies',
			),
			'consent-logs' => array(
				'title' => __( 'Consent Logs', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-consent-logs',
				'view'  => 'consent-logs',
			),
			'gcm'          => array(
				'title' => __( 'Google Consent Mode', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-gcm',
				'view'  => 'gcm',
			),
			'languages'    => array(
				'title' => __( 'Languages', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-languages',
				'view'  => 'languages',
			),
			'gvl'          => array(
				'title' => __( 'Vendor List (IAB)', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-gvl',
				'view'  => 'gvl',
			),
			'settings'     => array(
				'title' => __( 'Settings', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-settings',
				'view'  => 'settings',
			),
		);
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    3.0.0
	 */
	public function enqueue_styles() {
		if ( false === faz_is_admin_page() ) {
			return;
		}
		wp_enqueue_style(
			'faz-admin',
			plugin_dir_url( __FILE__ ) . 'assets/css/faz-admin.css',
			array(),
			$this->version
		);
		// WordPress dashicons (for icon support in quick links, etc.).
		wp_enqueue_style( 'dashicons' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    3.0.0
	 */
	public function enqueue_scripts() {
		if ( false === faz_is_admin_page() ) {
			return;
		}

		// Core utilities — depends on wp-api-fetch for REST calls.
		wp_enqueue_script(
			'faz-admin',
			plugin_dir_url( __FILE__ ) . 'assets/js/faz-admin.js',
			array( 'wp-api-fetch' ),
			$this->version,
			true
		);

		// Localize config data for JS.
		$settings = new \FazCookie\Admin\Modules\Settings\Includes\Settings();
		wp_localize_script(
			'faz-admin',
			'fazConfig',
			array(
				'api'          => array(
					'base'  => rest_url( 'faz/v1/' ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				),
				'site'         => array(
					'url'  => get_site_url(),
					'name' => esc_attr( get_option( 'blogname' ) ),
				),
				'assetsURL'    => defined( 'FAZ_PLUGIN_URL' ) ? FAZ_PLUGIN_URL . 'frontend/images/' : '',
				'defaultLogo'  => plugins_url( 'cookie.png', FAZ_PLUGIN_FILENAME ),
				'adminURL'     => admin_url( 'admin.php' ),
				'multilingual' => faz_i18n_is_multilingual() && count( faz_selected_languages() ) > 0,
				'languages'    => array(
					'selected' => faz_selected_languages(),
					'default'  => faz_default_language(),
				),
				'version'      => $this->version,
				'modules'      => self::$active_modules,
			)
		);

		// Enqueue page-specific JS if it exists.
		if ( ! isset( $this->pages ) ) {
			$this->pages = $this->get_admin_pages();
		}
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( $this->pages as $page ) {
			if ( $page['slug'] === $current_page ) {
				$page_js = plugin_dir_path( __FILE__ ) . 'assets/js/pages/' . $page['view'] . '.js';
				if ( file_exists( $page_js ) ) {
					wp_enqueue_script(
						'faz-page-' . $page['view'],
						plugin_dir_url( __FILE__ ) . 'assets/js/pages/' . $page['view'] . '.js',
						array( 'faz-admin' ),
						filemtime( $page_js ),
						true
					);
				}
				// Enqueue WordPress media library for banner page (brand logo uploader).
				if ( 'banner' === $page['view'] ) {
					wp_enqueue_media();
					// Pass theme presets so banner.js can reset colours on theme switch.
					$theme_file = plugin_dir_path( __FILE__ ) . 'modules/banners/includes/templates/6.2.0/theme.json';
					$presets    = file_exists( $theme_file ) ? json_decode( file_get_contents( $theme_file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					wp_add_inline_script( 'faz-admin', 'fazConfig.themePresets=' . wp_json_encode( $presets ) . ';', 'after' );
				}
				break;
			}
		}
	}


	/**
	 * Prepare shortcodes for banner preview.
	 *
	 * @return array
	 */
	public function prepare_shortcodes() {
		$data   = array();
		$data[] = array(
			'key'     => 'faz_readmore',
			'content' => do_shortcode( '[faz_readmore]' ),
			'tag'     => 'readmore-button',
		);
		$data[] = array(
			'key'        => 'faz_show_desc',
			'content'    => do_shortcode( '[faz_show_desc]' ),
			'tag'        => 'show-desc-button',
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_hide_desc',
			'content'    => do_shortcode( '[faz_hide_desc]' ),
			'tag'        => 'hide-desc-button',
			'attributes' => array(),
		);
		return $data;
	}

	/**
	 * Register main menu and submenu pages.
	 *
	 * @return void
	 */
	public function admin_menu() {
		if ( ! isset( $this->pages ) ) {
			$this->pages = $this->get_admin_pages();
		}
		$capability = 'manage_options';
		$parent     = self::ADMIN_SLUG;

		// Main menu page (Dashboard).
		add_menu_page(
			__( 'FAZ Cookie', 'faz-cookie-manager' ),
			__( 'FAZ Cookie', 'faz-cookie-manager' ),
			$capability,
			$parent,
			array( $this, 'render_page' ),
			'dashicons-food',
			40
		);

		// Submenu pages.
		foreach ( $this->pages as $key => $page ) {
			add_submenu_page(
				$parent,
				$page['title'],
				$page['title'],
				$capability,
				$page['slug'],
				array( $this, 'render_page' )
			);
		}
	}

	/**
	 * Render an admin page by including its view file.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! isset( $this->pages ) ) {
			$this->pages = $this->get_admin_pages();
		}
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : self::ADMIN_SLUG; // phpcs:ignore WordPress.Security.NonceVerification

		$faz_page_title = '';
		$faz_page_slug  = 'dashboard';

		foreach ( $this->pages as $page ) {
			if ( $page['slug'] === $current_page ) {
				$faz_page_title = $page['title'];
				$faz_page_slug  = $page['view'];
				break;
			}
		}

		include plugin_dir_path( __FILE__ ) . 'views/base.php';
	}

	/**
	 * Add custom class to admin body tag.
	 *
	 * @param string $classes List of classes.
	 * @return string
	 */
	public function admin_body_classes( $classes ) {
		if ( true === faz_is_admin_page() ) {
			$classes .= ' faz-admin-page';
		}
		return $classes;
	}

	/**
	 * Returns Jed-formatted localization data.
	 *
	 * @since 4.0.0
	 *
	 * @param  string $domain Translation domain.
	 * @return array          The information of the locale.
	 */
	public function get_jed_locale_data( $domain ) {
		$locale = array(
			'' => array(
				'domain' => $domain,
				'lang'   => is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale(),
			),
		);

		$json_translations = $this->load_json_translations();

		foreach ( $json_translations as $key => $value ) {
			$locale[ $key ] = array( $value );
		}

		$json = wp_json_encode( $locale );
		if ( preg_match( '/<br[\s\/\\\\]*>/', $json ) ) {
			foreach ( $locale as $key => $value ) {
				foreach ( (array) $value as $sub_key => $sub_value ) {
					if ( is_string( $sub_value ) ) {
						$locale[ $key ][ $sub_key ] = str_replace( array( '<br>', '<br/>', '<br />' ), '', $sub_value );
					}
				}
			}
		}

		return $locale;
	}

	/**
	 * Load translations from JSON files.
	 *
	 * @since 4.0.0
	 *
	 * @return array The merged translations from all JSON files.
	 */
	private function load_json_translations() {
		$translations = array();

		$current_lang = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		$lang_code    = substr( $current_lang, 0, 2 );

		$languages_dir = WP_CONTENT_DIR . '/languages/';
		$json_paths    = array();
		$plugins_dir   = $languages_dir . 'plugins/';

		if ( is_dir( $plugins_dir ) ) {
			$files = glob( $plugins_dir . 'faz-cookie-manager-' . $current_lang . '-*.json' );
			if ( ! empty( $files ) ) {
				$json_paths = array_merge( $json_paths, $files );
			}

			$files = glob( $plugins_dir . 'faz-cookie-manager-' . $lang_code . '-*.json' );
			if ( ! empty( $files ) ) {
				$json_paths = array_merge( $json_paths, $files );
			}

			$files = glob( $plugins_dir . 'faz-cookie-manager-en-*.json' );
			if ( ! empty( $files ) ) {
				$json_paths = array_merge( $json_paths, $files );
			}
		}

		foreach ( $json_paths as $path ) {
			if ( file_exists( $path ) ) {
				$json_content = file_get_contents( $path );
				$json_data    = json_decode( $json_content, true );

				if ( $json_data && is_array( $json_data ) ) {
					if ( isset( $json_data['locale_data']['messages'] ) ) {
						$message_translations = $json_data['locale_data']['messages'];
						foreach ( $message_translations as $key => $value ) {
							if ( is_array( $value ) && isset( $value[0] ) ) {
								if ( '' !== $key ) {
									$translations[ $key ] = $value[0];
								}
							}
						}
					}
				}
			}
		}

		return $translations;
	}

	/**
	 * Hide all the unrelated notices from plugin pages.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function hide_admin_notices() {
		if ( empty( $_REQUEST['page'] ) || ! preg_match( '/' . preg_quote( self::ADMIN_SLUG, '/' ) . '/', esc_html( wp_unslash( $_REQUEST['page'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}
		global $wp_filter;

		$notices_type = array(
			'user_admin_notices',
			'admin_notices',
			'all_admin_notices',
		);

		foreach ( $notices_type as $type ) {
			if ( empty( $wp_filter[ $type ]->callbacks ) || ! is_array( $wp_filter[ $type ]->callbacks ) ) {
				continue;
			}
			foreach ( $wp_filter[ $type ]->callbacks as $priority => $hooks ) {
				foreach ( $hooks as $name => $arr ) {
					if ( is_object( $arr['function'] ) && $arr['function'] instanceof \Closure ) {
						unset( $wp_filter[ $type ]->callbacks[ $priority ][ $name ] );
						continue;
					}
					$class = ! empty( $arr['function'][0] ) && is_object( $arr['function'][0] ) ? strtolower( get_class( $arr['function'][0] ) ) : '';
					if ( ! empty( $class ) && preg_match( '/^faz/', $class ) ) {
						continue;
					}
					if ( ! empty( $name ) && ! preg_match( '/^faz/', $name ) ) {
						unset( $wp_filter[ $type ]->callbacks[ $priority ][ $name ] );
					}
				}
			}
		}
	}

	/**
	 * Handle redirect after plugin activation.
	 *
	 * @param string $plugin Plugin basename.
	 * @return void
	 */
	public function handle_activation_redirect( $plugin ) {
		if ( FAZ_PLUGIN_BASENAME !== $plugin ) {
			return;
		}
		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::ADMIN_SLUG ) );
		exit;
	}

	/**
	 * Load plugin for the first time.
	 *
	 * @return void
	 */
	public function load_plugin() {
		if ( is_admin() && 'true' === get_option( 'faz_first_time_activated_plugin' ) ) {
			do_action( 'faz_after_first_time_install' );
			delete_option( 'faz_first_time_activated_plugin' );
		}
	}

	/**
	 * Redirect the plugin to dashboard.
	 *
	 * @return void
	 */
	public function redirect() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::ADMIN_SLUG ) );
		exit;
	}

	/**
	 * Modify plugin action links on plugin listing page.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$links[] = '<a href="' . get_admin_url( null, 'admin.php?page=' . self::ADMIN_SLUG ) . '">' . esc_html__( 'Settings', 'faz-cookie-manager' ) . '</a>';
		return array_reverse( $links );
	}
}
