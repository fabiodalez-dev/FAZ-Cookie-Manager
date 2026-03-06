<?php

namespace FazCookie\Admin\Modules\Gcm\Includes;

use FazCookie\Includes\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Gcm_Settings extends Store {
	protected $data = array();

	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->data = $this->get_defaults();
	}

	public function get_defaults() {
		return array(
			'status' => false,
			'default_settings' => array(
				array(
					'analytics' => 'denied',
					'marketing' => 'denied',
					'functional' => 'denied',
					'necessary' => 'granted',
					'ad_user_data' => 'denied',
					'ad_personalization' => 'denied',
					'regions' => 'All',
				)
			),
			'wait_for_update' => 2000,
			'url_passthrough' => false,
			'ads_data_redaction' => false,
			'gacm_enabled' => false,
			'gacm_provider_ids' => '',
		);
	}

	public function get( $group = '', $key = '' ) {
		$settings = get_option( 'faz_gcm_settings', $this->data );
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
			'default_settings',
		);
	}
	/**
	 * Update settings to database.
	 *
	 * @param array|object $data Array of settings data.
	 * @return void
	 */
	public function update( $data ) {
		$settings = get_option( 'faz_gcm_settings', $this->data );
		if ( empty( $settings ) ) {
			$settings = $this->data;
		}
		$settings = self::sanitize( $data, $settings );
		update_option( 'faz_gcm_settings', $settings );
		do_action( 'faz_after_update_settings', $settings );
	}

	public function sanitize( $settings, $defaults ) {
		$result  = array();
		$excludes = self::get_excludes();
		foreach ( $defaults as $key => $data ) {
			$value = isset( $settings[ $key ] ) ? $settings[ $key ] : $data;
			if ( in_array( $key, $excludes, true ) ) {
				$result[ $key ] = $value;
				continue;
			}
			if ( is_array( $value ) ) {
				$result[ $key ] = self::sanitize( $value, $data );
			} elseif ( is_string( $key ) ) {
				$result[ $key ] = self::sanitize_option( $key, $value );
			}
		}
		return $result;
	}


	public static function sanitize_option( $option, $value ) {
		switch ( $option ) {
			case 'status':
			case 'url_passthrough':
			case 'ads_data_redaction':
			case 'gacm_enabled':
				$value = faz_sanitize_bool( $value );
				break;
			case 'wait_for_update':
				$value = absint( $value );
				break;
			default:
				$value = faz_sanitize_text( $value );
				break;
		}
		return $value;
	}

	/**
	 * Check whether GCM is enabled.
	 *
	 * @return boolean
	 */
	public function is_gcm_enabled() {
		return $this->get( 'status' );
	}
}