<?php
/**
 * Known third-party provider database for script blocking.
 *
 * Static class with comprehensive URL/inline-code patterns mapped to
 * consent categories.  Used by the output-buffer blocker (server-side)
 * and the client-side createElement / MutationObserver interceptor.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Known_Providers
 */
class Known_Providers {

	/**
	 * Return every known third-party service.
	 *
	 * Each entry contains:
	 *   - label    (string)   Human-readable service name.
	 *   - category (string)   Default consent-category slug.
	 *   - patterns (string[]) URL fragments / inline-code signatures.
	 *   - cookies  (string[]) Optional — cookie names set by the service.
	 *
	 * @return array
	 */
	public static function get_all() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$path = __DIR__ . '/data/known-providers.json';
		if ( ! is_readable( $path ) ) {
			$cached = array();
			return $cached;
		}
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $json ) {
			$cached = array();
			return $cached;
		}
		$data = json_decode( $json, true );
		$cached = is_array( $data ) ? $data : array();
		return $cached;
	}

	/**
	 * Build a flat map of cookie-name patterns → category slugs.
	 *
	 * Used by cookie shredding to decide which cookies to delete
	 * when their category has not been consented.
	 *
	 * @return array [ '_fbp' => 'marketing', '_ga' => 'analytics', ... ]
	 */
	public static function get_cookie_map() {
		$map = array();
		foreach ( self::get_all() as $service ) {
			if ( empty( $service['cookies'] ) ) {
				continue;
			}
			foreach ( $service['cookies'] as $cookie_pattern ) {
				$map[ $cookie_pattern ] = $service['category'];
			}
		}
		return $map;
	}

	/**
	 * Get all URL/inline patterns mapped to category.
	 *
	 * @return array [ 'connect.facebook.net' => 'marketing', ... ]
	 */
	public static function get_pattern_map() {
		$map = array();
		foreach ( self::get_all() as $service ) {
			foreach ( $service['patterns'] as $pattern ) {
				if ( ! isset( $map[ $pattern ] ) ) {
					$map[ $pattern ] = $service['category'];
				}
			}
		}
		return $map;
	}
}
