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
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			$ip = 'no-ip';
		}
		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}
}
