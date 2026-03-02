<?php
/**
 * Fired during plugin activation
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @package    FazCookie
 * @subpackage FazCookie/includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FazCookie\Admin\Modules\Banners\Includes\Banner;
use FazCookie\Admin\Modules\Banners\Includes\Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
use FazCookie\Admin\Modules\Consentlogs\Includes\Controller as ConsentLogs_Controller;
use FazCookie\Admin\Modules\Pageviews\Includes\Controller as Pageviews_Controller;

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      3.0.0
 * @package    FazCookie
 * @subpackage FazCookie/includes
 * @author     Fabio D'Alessandro
 */
class Activator {

	/**
	 * Instance of the current class
	 *
	 * @var object
	 */
	private static $instance;
	/**
	 * Update DB callbacks.
	 *
	 * @var array
	 */
	private static $db_updates = array(
		'3.0.7' => array(
			'update_db_307',
		),
		'3.2.1' => array(
			'update_db_321',
		),
		'3.3.7' => array(
			'update_db_337',
		),
		'3.4.0' => array(
			'update_db_340',
		),
	);
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
	 * Activate the plugin
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'check_version' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'ensure_uncategorized_category' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_wordpress_internal_category' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_download_cookie_definitions' ) );
	}

	/**
	 * Download Open Cookie Database definitions if not yet present.
	 * Runs once on first admin visit after activation.
	 */
	public static function maybe_download_cookie_definitions() {
		$defs = Cookie_Definitions::get_instance();
		if ( ! $defs->has_definitions() ) {
			$defs->update_definitions();
		}
	}
	/**
	 * Check the plugin version and run the updater is required.
	 *
	 * This check is done on all requests and runs if the versions do not match.
	 */
	public static function check_version() {
		if ( ! defined( 'IFRAME_REQUEST' ) && version_compare( get_option( 'faz_version', '0.0.0' ), FAZ_VERSION, '<' ) ) {
			self::install();
		}
	}
	/**
	 * Install all the plugin
	 *
	 * @return void
	 */
	public static function install() {
		self::check_for_upgrade();
		if ( true === faz_first_time_install() ) {
			add_option( 'faz_first_time_activated_plugin', 'true' );
		}
		self::install_all_tables();
		self::maybe_update_db();
		update_option( 'faz_version', FAZ_VERSION );
		do_action( 'faz_after_activate', FAZ_VERSION );
		self::update_db_version();
	}

	/**
	 * Install all database tables at activation.
	 *
	 * @return void
	 */
	public static function install_all_tables() {
		// Core tables (banners, cookies, categories).
		$base_controllers = array(
			'FazCookie\Admin\Modules\Banners\Includes\Controller',
			'FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller',
			'FazCookie\Admin\Modules\Cookies\Includes\Category_Controller',
		);
		foreach ( $base_controllers as $controller_class ) {
			if ( class_exists( $controller_class ) ) {
				$controller = $controller_class::get_instance();
				$controller->install_tables();
			}
		}

		// Consent logs table (standalone controller).
		if ( class_exists( 'FazCookie\Admin\Modules\Consentlogs\Includes\Controller' ) ) {
			ConsentLogs_Controller::get_instance()->maybe_create_table();
		}

		// Pageviews table (standalone controller).
		if ( class_exists( 'FazCookie\Admin\Modules\Pageviews\Includes\Controller' ) ) {
			Pageviews_Controller::get_instance()->maybe_create_table();
		}
	}

	/**
	 * Set a temporary flag during the first time installation.
	 *
	 * @return void
	 */
	public static function check_for_upgrade() {
		if ( false === get_option( 'faz_settings', false ) ) {
			if ( false === get_site_transient( '_faz_first_time_install' ) ) {
				set_site_transient( '_faz_first_time_install', true, 30 );
			}
		}
	}

	/**
	 * Update DB version to track changes to data structure.
	 *
	 * @param string $version Current version.
	 * @return void
	 */
	public static function update_db_version( $version = null ) {
		update_option( 'faz_cookie_consent_db_version', is_null( $version ) ? FAZ_VERSION : $version );
	}

	/**
	 * Check if any database changes is required on the latest release
	 *
	 * @return boolean
	 */
	private static function needs_db_update() {
		$current_version = get_option( 'faz_cookie_consent_db_version', '3.0.7' ); // @since 3.0.7 introduced DB migrations
		$updates         = self::$db_updates;
		$update_versions = array_keys( $updates );
		usort( $update_versions, 'version_compare' );
		return ! is_null( $current_version ) && version_compare( $current_version, end( $update_versions ), '<' );
	}

	/**
	 * Update DB if required
	 *
	 * @return void
	 */
	public static function maybe_update_db() {
		if ( self::needs_db_update() ) {
			self::update();
		}
	}

	/**
	 * Run a update check during each release update.
	 *
	 * @return void
	 */
	private static function update() {
		$current_version = get_option( 'faz_cookie_consent_db_version', '3.0.7' );
		foreach ( self::$db_updates as $version => $callbacks ) {
			if ( version_compare( $current_version, $version, '<' ) ) {
				foreach ( $callbacks as $callback ) {
					self::$callback();
				}
			}
		}
	}

	/**
	 * Migrate existing banner contents to support new CCPA/GPC changes
	 *
	 * @return void
	 */
	public static function update_db_307() {
		$items = Controller::get_instance()->get_items();
		foreach ( $items as $item ) {
			$banner   = new Banner( $item->banner_id );
			$contents = $banner->get_contents();
			foreach ( $contents as $language => $content ) {
				$translation = $banner->get_translations( $language );
				$text        = isset( $translation['optoutPopup']['elements']['buttons']['elements']['confirm'] ) ? $translation['optoutPopup']['elements']['buttons']['elements']['confirm'] : 'Save My Preferences';
				$content['optoutPopup']['elements']['buttons']['elements']['confirm'] = $text;
				$contents[ $language ] = $content;
			}
			$banner->set_contents( $contents );
			$banner->save();
		}
	}

	public static function update_db_321() {
		$items = Controller::get_instance()->get_items();
		foreach ( $items as $item ) {
			$banner   = new Banner( $item->banner_id );
			$contents = $banner->get_contents();
			$settings = $banner->get_settings();
			if ( isset($contents['en']) ) {
				$translation = $banner->get_translations( 'en' );
				$text        = isset( $translation['optoutPopup']['elements']['gpcOption']['elements']['description'] ) ? $translation['optoutPopup']['elements']['gpcOption']['elements']['description'] : "<p>Your opt-out settings for this website have been respected since we detected a <b>Global Privacy Control</b> signal from your browser and, therefore, you cannot change this setting.</p>";
				$contents['en']['optoutPopup']['elements']['gpcOption']['elements']['description'] = $text;
			}
			if ( isset($settings['config']) ) {
				$settings['config']['preferenceCenter']['elements']['categories']['elements']['toggle']['status']=!$settings['config']['categoryPreview']['status'];
			}
			$banner->set_contents( $contents );
			$banner->set_settings( $settings );
			$banner->save();
		}
	}

	/**
	 * Fix MySQL schema compatibility for TEXT/LONGTEXT columns.
	 * Remove DEFAULT values from TEXT/LONGTEXT columns to prevent MySQL errors.
	 *
	 * @since 3.3.7
	 * @return void
	 */
	public static function update_db_337() {
		// Reset table version options to force schema update with corrected definitions
		delete_option( 'faz_banners_table_version' );
		delete_option( 'faz_cookie_table_version' );
		delete_option( 'faz_cookie_category_table_version' );

		// Reinstall tables with the corrected schema (without DEFAULT on TEXT/LONGTEXT columns)
		$controllers = array(
			'FazCookie\Admin\Modules\Banners\Includes\Controller',
			'FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller',
			'FazCookie\Admin\Modules\Cookies\Includes\Category_Controller',
		);

		foreach ( $controllers as $controller_class ) {
			if ( class_exists( $controller_class ) ) {
				$controller = $controller_class::get_instance();
				$controller->install_tables();
			}
		}
	}

	public static function update_db_340() {
		// Only run this migration for users who have migrated from legacy UI
		$migration_options = get_option( 'faz_migration_options', array() );
		$migration_status  = isset( $migration_options['status'] ) ? $migration_options['status'] : false;

		if ( ! $migration_status ) {
			return;
		}

		$items = Controller::get_instance()->get_items();
		foreach ( $items as $item ) {
			$banner   = new Banner( $item->banner_id );
			$settings = $banner->get_settings();
			$law      = $banner->get_law();

			// For CCPA banners, explicitly disable the accept button
			if ( 'ccpa' === $law ) {
				if ( isset( $settings['config']['notice']['elements']['buttons']['elements']['accept'] ) ) {
					$settings['config']['notice']['elements']['buttons']['elements']['accept']['status'] = false;
					$banner->set_settings( $settings );
					$banner->save();
				}
			} else {
				// For non-CCPA banners, enable the accept button if it's disabled
				if ( isset( $settings['config']['notice']['elements']['buttons']['elements']['accept']['status'] )
					&& false === $settings['config']['notice']['elements']['buttons']['elements']['accept']['status'] ) {
					$settings['config']['notice']['elements']['buttons']['elements']['accept']['status'] = true;
					$banner->set_settings( $settings );
					$banner->save();
				}
			}
		}
	}

	/**
	 * Ensure the "uncategorized" cookie category exists.
	 */
	public static function ensure_uncategorized_category() {
		self::ensure_category_by_slug( 'uncategorized', array(
			'name'        => 'Uncategorized',
			'description' => 'Cookies that have not yet been categorized.',
		) );
	}

	/**
	 * Ensure the "wordpress-internal" cookie category exists.
	 */
	public static function ensure_wordpress_internal_category() {
		self::ensure_category_by_slug( 'wordpress-internal', array(
			'name'        => 'WordPress Internal',
			'description' => 'Cookies set by WordPress core for logged-in administrators. Not shown to site visitors.',
		), false, false );
	}

	/**
	 * Create a cookie category if it does not already exist.
	 *
	 * @param string $slug          Category slug.
	 * @param array  $fallback_data Default name/description if not in Category_Controller defaults.
	 * @param bool   $prior_consent Whether prior consent is required. Default false.
	 * @param bool   $visibility    Whether visible on frontend. Default true.
	 */
	private static function ensure_category_by_slug( $slug, $fallback_data, $prior_consent = false, $visibility = true ) {
		$category_controller = Category_Controller::get_instance();
		$categories          = $category_controller->get_items();
		foreach ( $categories as $cat ) {
			if ( $slug === $cat->slug ) {
				return; // Already exists.
			}
		}
		$lang     = function_exists( 'faz_default_language' ) ? faz_default_language() : 'en';
		$defaults = Category_Controller::get_defaults();
		$data     = isset( $defaults[ $slug ] ) && is_array( $defaults[ $slug ] )
			? array_merge( $fallback_data, $defaults[ $slug ] )
			: $fallback_data;

		$object = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories();
		$object->set_name( array( $lang => $data['name'] ) );
		$object->set_description( array( $lang => $data['description'] ) );
		$object->set_slug( $slug );
		$object->set_prior_consent( $prior_consent );
		$object->set_visibility( $visibility );
		$object->save();
	}
}
