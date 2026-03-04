<?php
/**
 * Class Banner file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Settings\Includes;

use FazCookie\Includes\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Settings
 * @version     3.0.0
 * @package     FazCookie
 */
class Settings extends Store {
	/**
	 * Data array, with defaults.
	 *
	 * @var array
	 */
	protected $data = array();

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
	 * Constructor
	 */
	public function __construct() {
		$this->data = $this->get_defaults();
	}

	/**
	 * Get default plugin settings
	 *
	 * @return array
	 */
	public function get_defaults() {
		return array(
			'site'         => array(
				'url'       => get_site_url(),
				'installed' => time(),
			),
			'account'      => array(
				'connected' => true,
				'status'    => true,
				'plan'      => 'ultimate',
			),
			'consent_logs' => array(
				'status'    => true,
				'retention' => 12,
			),
			'languages'    => array(
				'selected' => array( 'en' ),
				'default'  => 'en',
			),
			'onboarding'   => array(
				'step' => 2,
			),
			'scanner'      => array(
				'max_pages'  => 20,
				'last_scan'  => '',
				'static_ip'  => '',
			),
			'banner_control' => array(
				'status'            => true,
				'excluded_pages'    => array(),
				'subdomain_sharing' => false,
			),
			'microsoft'    => array(
				'uet_consent_mode' => false,
				'clarity_consent'  => false,
			),
			'site_links'   => array(
				'sites' => array(),
			),
			'iab'          => array(
				'enabled'               => false,
				'publisher_cc'          => '',
				'cmp_id'                => 0,
				'purpose_one_treatment' => false,
			),
			'geolocation'  => array(
				'maxmind_license_key' => '',
			),
		);

	}
	/**
	 * Get settings
	 *
	 * @param string $group Name of the group.
	 * @param string $key Name of the key.
	 * @return array
	 */
	public function get( $group = '', $key = '' ) {
		$settings = get_option( 'faz_settings', $this->data );
		$settings = self::sanitize( $settings, $this->data );
		if ( empty( $key ) && empty( $group ) ) {
			return $settings;
		} elseif ( ! empty( $key ) && ! empty( $group ) ) {
			$settings = isset( $settings[ $group ] ) ? $settings[ $group ] : array();
			return isset( $settings[ $key ] ) ? $settings[ $key ] : array();
		} else {
			return isset( $settings[ $group ] ) ? $settings[ $group ] : array();
		}
	}

	/**
	 * Excludes a key from sanitizing multiple times.
	 *
	 * @return array
	 */
	public static function get_excludes() {
		return array(
			'selected',
			'excluded_pages',
			'sites',
		);
	}
	/**
	 * Update settings to database.
	 *
	 * @param array $data Array of settings data.
	 * @return void
	 */
	public function update( $data, $clear = true ) {
		$defaults = $this->get_defaults();
		$settings = self::sanitize( $data, $defaults );
		update_option( 'faz_settings', $settings );
		do_action( 'faz_after_update_settings', $clear );
	}

	/**
	 * Sanitize options
	 *
	 * @param array $settings Input settings array.
	 * @param array $defaults Default settings array.
	 * @return array
	 */
	public static function sanitize( $settings, $defaults ) {
		$result  = array();
		$excludes = self::get_excludes();
		foreach ( $defaults as $key => $data ) {
			$value = isset( $settings[ $key ] ) ? $settings[ $key ] : $data;
			// If the default is an array but the stored value isn't, use the default.
			if ( is_array( $data ) && ! is_array( $value ) ) {
				$value = $data;
			}
			if ( in_array( $key, $excludes, true ) ) {
				$result[ $key ] = self::sanitize_option( $key, $value );
				continue;
			}
			if ( is_array( $value ) ) {
				$result[ $key ] = self::sanitize( $value, $data );
			} else {
				if ( is_string( $key ) ) {
					$result[ $key ] = self::sanitize_option( $key, $value );
				}
			}
		}
		return $result;
	}

	/**
	 * Sanitize the option values
	 *
	 * @param string $option The name of the option.
	 * @param string $value  The unsanitised value.
	 * @return string Sanitized value.
	 */
	public static function sanitize_option( $option, $value ) {
		switch ( $option ) {
			case 'connected':
			case 'status':
			case 'subdomain_sharing':
			case 'uet_consent_mode':
			case 'clarity_consent':
			case 'enabled':
			case 'purpose_one_treatment':
				$value = faz_sanitize_bool( $value );
				break;
			case 'installed':
			case 'step':
			case 'max_pages':
				$value = absint( $value );
				break;
			case 'retention':
				$value = max( 1, min( 120, absint( $value ) ) );
				break;
			case 'cmp_id':
				$value = min( 4095, absint( $value ) );
				break;
			case 'excluded_pages':
			case 'sites':
				$value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
				break;
			case 'publisher_cc':
				$value = strtoupper( sanitize_text_field( (string) $value ) );
				$value = preg_match( '/^[A-Z]{2}$/', $value ) ? $value : '';
				break;
			default:
				$value = faz_sanitize_text( $value );
				break;
		}
		return $value;
	}

	// Getter Functions.

	/**
	 * Get current site URL.
	 *
	 * @return mixed
	 */
	public function get_url() {
		return $this->get( 'site', 'url' );
	}

	/**
	 * Always returns 'ultimate' plan for local mode.
	 *
	 * @return mixed
	 */
	public function get_plan() {
		return $this->get( 'account', 'plan' );
	}

	/**
	 * Always returns false — local mode, no cloud connection.
	 *
	 * @return boolean
	 */
	public function is_connected() {
		return false;
	}

	/**
	 * Get consent log status
	 *
	 * @return boolean
	 */
	public function get_consent_log_status() {
		return (bool) $this->get( 'consent_logs', 'status' );

	}

	/**
	 * Returns the default language code
	 *
	 * @return string
	 */
	public function get_default_language() {
		$default = $this->get( 'languages', 'default' );
		return is_string( $default ) ? sanitize_text_field( $default ) : 'en';
	}

	/**
	 * Returns the selected languages.
	 *
	 * @return array
	 */
	public function get_selected_languages() {
		return faz_sanitize_text( $this->get( 'languages', 'selected' ) );
	}

	/**
	 * First installed date of the plugin.
	 *
	 * @return mixed
	 */
	public function get_installed_date() {
		return $this->get( 'site', 'installed' );
	}
}
