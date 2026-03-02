<?php
/**
 * Geolocation helper — detects visitor country for geo-targeting.
 *
 * Detection chain (first match wins):
 *   1. Cloudflare CF-IPCountry header
 *   2. Apache mod_geoip GEOIP_COUNTRY_CODE
 *   3. PHP geoip extension
 *   4. Free ip-api.com fallback (45 req/min)
 *
 * Results cached as a transient for 1 hour.
 *
 * @package FazCookie
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Geolocation {

	/**
	 * EU/EEA country codes (includes UK post-Brexit for GDPR alignment).
	 *
	 * @var array
	 */
	public static $eu_countries = array(
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
		'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
		'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'GB', 'IS', 'LI', 'NO',
	);

	/**
	 * Get the visitor's ISO 3166-1 alpha-2 country code.
	 *
	 * @return string Two-letter country code or empty string.
	 */
	public static function get_country() {
		$ip = self::get_client_ip();
		if ( empty( $ip ) || in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
			return '';
		}

		// Check transient cache.
		$cache_key = 'faz_geo_' . md5( $ip );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$country = self::detect_country( $ip );

		// Cache for 1 hour.
		if ( ! empty( $country ) ) {
			set_transient( $cache_key, $country, HOUR_IN_SECONDS );
		}

		return $country;
	}

	/**
	 * Check if the visitor is in the EU/EEA.
	 *
	 * @return bool
	 */
	public static function is_eu() {
		return in_array( self::get_country(), self::$eu_countries, true );
	}

	/**
	 * Get the client's real IP address.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// X-Forwarded-For can contain multiple IPs — use the first.
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				$ip = trim( $ip[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Run the detection chain.
	 *
	 * @param string $ip Client IP address.
	 * @return string Two-letter country code or empty string.
	 */
	private static function detect_country( $ip ) {
		// 1. Cloudflare CF-IPCountry header.
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			if ( strlen( $code ) === 2 && $code !== 'XX' ) {
				return $code;
			}
		}

		// 2. Apache mod_geoip.
		if ( ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) {
			return strtoupper( sanitize_text_field( wp_unslash( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) );
		}

		// 3. PHP GeoIP extension.
		if ( function_exists( 'geoip_country_code_by_name' ) ) {
			$code = @geoip_country_code_by_name( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $code ) {
				return strtoupper( $code );
			}
		}

		// 4. Free API fallback: ip-api.com (no key needed, 45 req/min).
		// Note: ip-api.com free tier only supports HTTP. HTTPS requires a paid plan.
		$response = wp_remote_get(
			'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=countryCode',
			array(
				'timeout' => 5,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['countryCode'] ) && strlen( $body['countryCode'] ) === 2 ) {
			return strtoupper( $body['countryCode'] );
		}

		return '';
	}
}
