<?php
/**
 * Class Controller file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Settings\Includes;

use FazCookie\Admin\Modules\Settings\Includes\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
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
	 * Localize common plugin settings.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public function load_common_settings( $data ) {
		$settings                = new Settings();
		$data['settings']        = $settings->get();
		$data['settings']['url'] = get_site_url();
		return $data;
	}

	/**
	 * Sync data to the web app.
	 *
	 * @return array
	 */
	public function sync() {
		$settings = new Settings();
		return $settings->get();
	}

	/**
	 * This API should be called to disconnect from the web app.
	 *
	 * @return array
	 */
	public function disconnect() {
		return array( 'success' => true );
	}

	/**
	 * Prepare entire data before sending.
	 *
	 * @return array
	 */
	public function prepare_data() {
		$settings = new Settings();
		$data     = array();
		$item     = \FazCookie\Admin\Modules\Banners\Includes\Controller::get_instance()->get_active_item();
		$banner   = new \FazCookie\Admin\Modules\Banners\Includes\Banner( $item );
		/** General Settings */
		$data['settings']   = array(
			'plan'       => $settings->get_plan(),
			'domain'     => home_url(),
			'consentLog' => array(
				'status' => true,
			),
		);
		$data['categories'] = $this->prepare_cookies();
		$data['banners']    = $this->prepare_banners();
		return $data;
	}

	/**
	 * Prepare and format cookies prior to syncing.
	 *
	 * @return array
	 */
	public function prepare_cookies() {
		$data  = array();
		$items = \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::get_instance()->get_items();

		foreach ( $items as $item ) {
			$object = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $item );
			$data[] = array(
				'name'            => $object->get_name(),
				'description'     => $object->get_description(),
				'slug'            => $object->get_slug(),
				'isNecessaryLike' => 'necessary' === $object->get_slug() ? true : false,
				'active'          => $object->get_visibility(),
				'defaultConsent'  => array(
					'gdpr' => $object->get_slug() === 'necessary' ? true : $object->get_prior_consent(),
					'ccpa' => $object->get_sell_personal_data() === true && $object->get_slug() !== 'necessary' ? false : true,
				),
				'cookies'         => $this->get_cookies( $object->get_id() ),
			);
		}
		return $data;
	}

	/**
	 * Get cookies by category
	 *
	 * @param int $category Category id.
	 * @return array
	 */
	public function get_cookies( $category = 0 ) {
		$data  = array();
		$items = \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller::get_instance()->get_items_by_category( $category );
		foreach ( $items as $item ) {
			$object = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie( $item );
			$data[] = array(
				'cookie_id'   => $object->get_name(),
				'type'        => $object->get_type(),
				'domain'      => $object->get_domain(),
				'duration'    => $object->get_duration(),
				'description' => $object->get_description(),
				'provider'    => $object->get_url_pattern(),
			);

		}
		return $data;
	}

	/**
	 * Prepare and format banners prior to sync.
	 *
	 * @return array
	 */
	public function prepare_banners() {
		$items   = \FazCookie\Admin\Modules\Banners\Includes\Controller::get_instance()->get_items();
		$banners = array();
		foreach ( $items as $item ) {
			$object                                    = new \FazCookie\Admin\Modules\Banners\Includes\Banner( $item );
			$banner                                    = array(
				'id'      => $object->get_id(),
				'name'    => $object->get_name(),
				'slug'    => $object->get_slug(),
				'default' => $object->get_default(),
				'status'  => ( true === $object->get_status() ? 'active' : 'inactive' ),
			);
			$data                                      = array_merge( $banner, array_merge( $object->get_settings(), array( 'content' => $object->get_contents() ) ) );
			$data['settings']['languages']['selected'] = faz_selected_languages();
			$data['settings']['languages']['default']  = faz_default_language();

			$data['settings']['ruleSet'] = array(
				array(
					'code'    => 'ALL',
					'regions' => array(),
				),
			);

			$banners[] = $data;
		}
		return $banners;
	}

	/**
	 *  Fetch site info from either locally or from API.
	 *
	 * @param array $args Array of arguments.
	 * @return array
	 */
	public function get_info( $args = array() ) {
		$data = array();
		if ( false === faz_is_cloud_request() ) {
			$data = $this->get_site_info( $args );
		} else {
			$data = $this->get_app_info( $args );
		}
		return $data;
	}

	/**
	 *  Get the current plan details and features list from a local DB.
	 *
	 * @param array $args Array of arguments.
	 * @return array
	 */
	public function get_site_info( $args = array() ) {
		return $this->get_default();
	}

	/**
	 * Get default site info.
	 * Returns ultimate plan with all features unlocked for local use.
	 *
	 * @return array
	 */
	public function get_default() {
		$settings = new Settings();
		$scan     = \FazCookie\Admin\Modules\Scanner\Includes\Controller::get_instance()->get_info();
		return array(
			'id'                       => '',
			'url'                      => get_site_url(),
			'status'                   => 'active',
			'banner_disabled_manually' => false,
			'user'                     => array(
				'name'  => '',
				'email' => '',
			),
			'plan'           => array(
				'id'          => '',
				'slug'        => 'ultimate',
				'name'        => 'Ultimate',
				'currency'    => 'USD',
				'description' => __( 'Ultimate Plan (Local)', 'faz-cookie-manager' ),
				'scan_limit'  => '10000',
				'log_limit'   => 100000,
				'features'    => array(
					'multi_law'                     => true,
					'custom_css'                    => true,
					'custom_branding'               => true,
					'config_geo_rules'              => true,
					'max_free_websites'             => 999,
					'remove_powered_by'             => true,
					'popup_layout'                  => true,
					'schedule_scan'                 => true,
					'static_ip_scan'                => true,
					'respect_gpc'                   => true,
					'hide_banner_on_specific_pages' => true,
					'iab'                           => true,
					'gacm_support'                  => true,
					'revisit_custom_branding'       => true,
					'scan_behind_login'             => true,
					'subdomain_consent_sharing'     => true,
					'multi_user_management'         => true,
					'gcm_support'                   => true,
					'policy_generators'             => true,
					'renew_user_consent'            => true,
					'do_not_track_support'          => true,
					'automatic_cookie_blocking'     => true,
					'advanced_customisation'        => true,
				),
			),
			'banners'        => array(
				'status'          => \FazCookie\Admin\Modules\Banners\Includes\Controller::get_instance()->check_status(),
				'laws'            => 'gdpr',
				'is_iab_enabled'  => (bool) $settings->get( 'iab', 'enabled' ),
				'targetedLocation' => 'worldwide',
			),
			'consent_logs'   => array(
				'status' => $settings->get_consent_log_status(),
			),
			'scans'          => array(
				'date'   => isset( $scan['date'] ) ? $scan['date'] : '',
				'status' => isset( $scan['status'] ) ? $scan['status'] : false,
			),
			'success_scan'   => array(
				'date'   => array(
					'date' => '',
					'time' => '',
				),
				'status' => false,
			),
			'languages'      => array(
				'selected' => $settings->get_selected_languages(),
				'default'  => $settings->get_default_language(),
			),
			'tables_missing' => count( faz_missing_tables() ) > 0 ? true : false,
			'pageviews'      => array(
				'count'    => 0,
				'limit'    => 0,
				'exceeded' => false,
				'ends_at'  => '',
			),
			'website'        => array(
				'status'               => 'active',
				'is_trial'             => false,
				'isInOptoutTrial'      => false,
				'isInReverseTrial'     => false,
				'isInOptinTrial'       => false,
				'is_trial_experiment'  => false,
				'trial_ends_at'        => '',
				'ends_in'              => 0,
				'is_trial_with_card'   => false,
				'grace_period_ends_at' => '',
				'payment_status'       => true,
				'hasPaymentMethod'     => true,
				'selected_plan'        => 'ultimate',
				'canStartOptoutTrial'  => false,
			),
			'overage'        => array(
				'applicable'         => false,
				'enabled'            => false,
				'is_after_rollout'   => false,
				'overage_view_count' => 0,
			),
		);
	}

	/**
	 * Check API before initializing the plugin.
	 *
	 * @return void
	 */
	public function check_api() {
		if ( ! faz_is_cloud_request() ) {
			return;
		}
		$response = $this->get_app_info();
		if ( is_wp_error( $response ) ) {
			return;
		}
		$this->maybe_update_settings( $response );
	}

	/**
	 * Maybe update the plugin settings if required.
	 *
	 * @param array $response Response from the web app.
	 * @return void
	 */
	public function maybe_update_settings( $response ) {
		$settings             = new Settings();
		$data                 = $settings->get();
		$data['consent_logs'] = isset( $response['consent_logs'] ) ? $response['consent_logs'] : array();
		$data['languages']    = isset( $response['languages'] ) ? $response['languages'] : array();
		update_option( 'faz_settings', $data );
	}

	/**
	 * Load site info from the web app.
	 *
	 * @param array $args Array of arguments.
	 * @return array|WP_Error
	 */
	public function get_app_info( $args = array() ) {
		// Cloud requests are not supported in local mode.
		return new WP_Error(
			'faz_not_available',
			__( 'Cloud requests are not available in local mode.', 'faz-cookie-manager' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Force update app settings if any changes from the plugin side.
	 *
	 * @param array $settings Settings array.
	 * @return void
	 */
	public function maybe_update_app_settings( $settings = array() ) {
		return;
	}
	/**
	 * Add payment/subscription data.
	 *
	 * @param array $data Payment data.
	 * @return array|WP_Error
	 */
	public function add_payments( $data ) {
		return new WP_Error(
			'faz_not_available',
			__( 'Payments are not available in local mode.', 'faz-cookie-manager' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Delete the cache.
	 *
	 * @return void
	 */
	public function delete_cache() {
		wp_cache_flush();
	}
}
