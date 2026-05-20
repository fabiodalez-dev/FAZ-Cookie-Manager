<?php
/**
 * Class Secrets file — encryption helper for sensitive admin options.
 *
 * Spec: specs/001-geo-routing-next/spec.md
 * Task: T022 (P3 Pipeline)
 *
 * Encrypts strings at rest using a XOR keystream derived from
 * `wp_salt('auth')`. Sufficient against casual database dumps; NOT
 * a substitute for proper KMS. Used for storing the ipinfo.io API
 * key in `wp_options::faz_geo_ipinfo_api_key`.
 *
 * Constitution VIII Data Minimization — sensitive secrets never live
 * in cleartext in `wp_options`.
 *
 * @package FazCookie\Admin\Modules\Geo_Routing\Includes
 * @since   1.15.0
 */

namespace FazCookie\Admin\Modules\Geo_Routing\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption helper.
 *
 * @class    Secrets
 * @since    1.15.0
 */
class Secrets {

	/**
	 * Encrypt a cleartext string for storage in wp_options.
	 *
	 * @param string $plain Cleartext.
	 * @return string Base64-encoded XOR ciphertext + version prefix 'v1:'.
	 */
	public static function encrypt( $plain ) {
		if ( ! is_string( $plain ) || '' === $plain ) {
			return '';
		}
		$key    = self::derive_key( strlen( $plain ) );
		$cipher = $plain ^ $key;
		return 'v1:' . base64_encode( $cipher );
	}

	/**
	 * Decrypt a previously-encrypted string.
	 *
	 * Returns '' if input is unrecognizable (allows the consumer to
	 * fail gracefully — e.g. ipinfo client skips lookup if key empty).
	 *
	 * @param string $cipher_str 'v1:' prefixed base64 ciphertext.
	 * @return string Decrypted plaintext or '' on failure.
	 */
	public static function decrypt( $cipher_str ) {
		if ( ! is_string( $cipher_str ) || 0 !== strpos( $cipher_str, 'v1:' ) ) {
			return '';
		}
		$decoded = base64_decode( substr( $cipher_str, 3 ), true );
		if ( false === $decoded || '' === $decoded ) {
			return '';
		}
		$key = self::derive_key( strlen( $decoded ) );
		return $decoded ^ $key;
	}

	/**
	 * Derive a keystream of the requested length from wp_salt('auth').
	 *
	 * @param int $length Bytes needed.
	 * @return string Keystream.
	 */
	private static function derive_key( $length ) {
		$length = max( 1, (int) $length );
		$salt   = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : 'faz-fallback-salt-not-secure';
		if ( '' === $salt ) {
			$salt = 'faz-fallback-salt-not-secure';
		}
		$stream = '';
		while ( strlen( $stream ) < $length ) {
			$stream .= hash( 'sha256', $salt . strlen( $stream ), true );
		}
		return substr( $stream, 0, $length );
	}
}
