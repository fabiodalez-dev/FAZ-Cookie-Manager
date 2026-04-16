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
				self::get_default_settings_entry(),
			),
			'wait_for_update' => 500,
			'url_passthrough' => false,
			'ads_data_redaction' => false,
			'gacm_enabled' => false,
			'gacm_provider_ids' => '',
			// When true and marketing consent is denied, serve non-personalized ads:
			// ad_storage = granted, but ad_user_data / ad_personalization = denied.
			// See https://support.google.com/adsense/answer/13554116
			'non_personalized_ads_fallback' => false,
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
		return array();
	}

	/**
	 * Sanitize a default_settings array: whitelist keys, validate values.
	 *
	 * @param array $settings Raw default_settings entries.
	 * @return array Sanitized entries.
	 */
	public static function sanitize_default_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return array();
		}

		$allowed_consent_keys = array(
			'ad_storage', 'analytics_storage', 'ad_user_data',
			'ad_personalization', 'functionality_storage',
			'personalization_storage', 'security_storage',
			// Legacy category-based keys used by the admin UI.
			'analytics', 'marketing', 'functional', 'necessary',
		);
		$allowed_values = array( 'granted', 'denied' );
		$default_entry  = self::get_default_settings_entry();
		$aliases        = array(
			'analytics'  => 'analytics_storage',
			'marketing'  => 'ad_storage',
			'functional' => 'functionality_storage',
			'necessary'  => 'security_storage',
		);

		$sanitized = array();
		foreach ( $settings as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$clean = array();
			foreach ( $entry as $k => $v ) {
				$k = sanitize_text_field( $k );
				if ( 'regions' === $k ) {
					$v = sanitize_text_field( $v );
					if ( 'All' !== $v ) {
						$codes = array_filter( array_map( 'trim', explode( ',', strtoupper( $v ) ) ), function ( $c ) {
							return preg_match( '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $c );
						} );
						$v = ! empty( $codes ) ? implode( ',', $codes ) : 'All';
					}
					$clean['regions'] = $v;
				} elseif ( in_array( $k, $allowed_consent_keys, true ) ) {
					$clean[ $k ] = in_array( $v, $allowed_values, true ) ? $v : 'denied';
				}
				// Unknown keys are silently dropped.
			}
			if ( ! empty( $clean ) ) {
				foreach ( $aliases as $legacy_key => $storage_key ) {
					if ( isset( $clean[ $legacy_key ] ) && ! isset( $clean[ $storage_key ] ) ) {
						$clean[ $storage_key ] = $clean[ $legacy_key ];
					} elseif ( isset( $clean[ $storage_key ] ) && ! isset( $clean[ $legacy_key ] ) ) {
						$clean[ $legacy_key ] = $clean[ $storage_key ];
					}
				}
				if ( isset( $clean['functionality_storage'] ) && ! isset( $clean['personalization_storage'] ) ) {
					$clean['personalization_storage'] = $clean['functionality_storage'];
				} elseif ( isset( $clean['personalization_storage'] ) && ! isset( $clean['functionality_storage'] ) ) {
					$clean['functionality_storage'] = $clean['personalization_storage'];
				}
				if ( isset( $clean['personalization_storage'] ) && ! isset( $clean['functional'] ) ) {
					$clean['functional'] = $clean['personalization_storage'];
				}
				$sanitized[] = wp_parse_args( $clean, $default_entry );
			}
		}
		return $sanitized;
	}

	/**
	 * Return the canonical consent defaults for a single region entry.
	 *
	 * Includes both the legacy FAZ category keys used by the current admin/UI
	 * flow and the GCM v2 storage keys so older installs and API-driven
	 * updates cannot drop newly introduced consent signals.
	 *
	 * @return array
	 */
	public static function get_default_settings_entry() {
		return array(
			'ad_storage'              => 'denied',
			'analytics_storage'       => 'denied',
			'ad_user_data'            => 'denied',
			'ad_personalization'      => 'denied',
			'functionality_storage'   => 'denied',
			'personalization_storage' => 'denied',
			'security_storage'        => 'granted',
			'analytics'               => 'denied',
			'marketing'               => 'denied',
			'functional'              => 'denied',
			'necessary'               => 'granted',
			'regions'                 => 'All',
		);
	}
	/**
	 * Update settings to database.
	 *
	 * @param array|object $data Array of settings data.
	 * @return void
	 */
	public function update( $data ) {
		$stored = get_option( 'faz_gcm_settings', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		// Merge stored values onto the canonical defaults so any keys added
		// to get_defaults() in newer plugin versions (e.g.
		// non_personalized_ads_fallback in 1.11.0) are visible to the
		// sanitize iteration even on installs that never persisted them.
		// Without this, sanitize() iterates the *stored* array as its keyset
		// and silently drops new keys included in the POST payload.
		$base = wp_parse_args( $stored, $this->data );
		// Apply incoming changes on top, then sanitize against canonical defaults.
		$merged   = wp_parse_args( (array) $data, $base );
		$settings = self::sanitize( $merged, $this->data );
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
			if ( 'default_settings' === $key ) {
				$result[ $key ] = self::sanitize_default_settings( $value );
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
			case 'non_personalized_ads_fallback':
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
