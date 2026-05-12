<?php
/**
 * Shared IP hashing for rate-limiting handlers.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a salted HMAC hash of the visitor's IP address for rate-limiting
 * and audit-log keys. Used by DSAR_Shortcode and Do_Not_Sell_Shortcode.
 */
trait IP_Hasher {

	/**
	 * Return a salted hash of the visitor's IP address.
	 *
	 * @return string 64-char hex string.
	 */
	private function hash_ip() {
		$ip = \faz_resolve_client_ip();
		if ( empty( $ip ) ) {
			// Group missing-IP requests intentionally without hashing an empty string.
			$ip = 'no-ip';
		}
		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}
}
