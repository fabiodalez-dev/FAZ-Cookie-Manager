<?php
/**
 * Class Secrets file — encryption helper for sensitive admin options.
 *
 * Spec: specs/001-geo-routing-next/spec.md
 * Task: T022 (P3 Pipeline)
 *
 * Encrypts strings at rest for `wp_options`. Used for the ipinfo.io API key
 * in `faz_geo_ipinfo_api_key`.
 *
 * Formats, newest first:
 *  - v3:<hint>:<base64(nonce . secretbox)> — XSalsa20-Poly1305
 *    (sodium_crypto_secretbox) under a key derived from wp_salt('auth'),
 *    with a random 24-byte nonce per value. Every value has its own
 *    keystream and a MAC, so two ciphertexts no longer XOR to the XOR of
 *    their plaintexts and a tampered value fails to open. Written whenever
 *    sodium is available (PHP 7.2+ ext-sodium, or the sodium_compat copy
 *    WordPress bundles since 5.2).
 *  - v2:<hint>:<base64(plain XOR keystream)> — the 1.15.0 format. Still read,
 *    so keys saved by earlier versions keep working, and still written on the
 *    rare host without sodium. The ipinfo client rewrites a v2 value as v3 the
 *    first time it reads it.
 *  - v1: never decoded (see decrypt()).
 *
 * What this protects against, and what it does not: the key is derived from
 * wp_salt('auth'), i.e. AUTH_KEY . AUTH_SALT from wp-config.php. A database
 * dump alone therefore does not reveal the API key, as long as those two
 * constants are defined in wp-config.php. When they are missing, WordPress
 * generates the salt and keeps it in the database (the `auth_key` and
 * `auth_salt` site options), and a dump of that same database decrypts the
 * value. This is not a substitute for a KMS.
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
	 * Bytes of a secretbox nonce (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES).
	 * Spelled as a literal so the class loads where the constant is absent.
	 */
	const NONCE_BYTES = 24;

	/**
	 * Bytes of a secretbox MAC (SODIUM_CRYPTO_SECRETBOX_MACBYTES).
	 */
	const MAC_BYTES = 16;

	/**
	 * Encrypt a cleartext string for storage in wp_options.
	 *
	 * @param string $plain Cleartext.
	 * @return string 'v3:' (or, without sodium, 'v2:') ciphertext, or ''
	 *                when no salt is available.
	 */
	public static function encrypt( $plain ) {
		if ( ! is_string( $plain ) || '' === $plain ) {
			return '';
		}
		// Fail explicit when wp_salt() isn't available / returned empty
		// (e.g., extremely early boot, salts wiped from wp-config). The
		// previous shared fallback 'faz-fallback-salt-not-secure' made
		// the keystream globally guessable for every install in that
		// state — better to refuse to encrypt and surface the gap.
		$key_hint = self::current_key_hint();
		if ( '' === $key_hint ) {
			return '';
		}
		if ( self::sodium_available() ) {
			return self::encrypt_v3( $plain, $key_hint );
		}
		$key = self::derive_key( strlen( $plain ) );
		if ( '' === $key ) {
			return '';
		}
		// Format: v2:<8-hex-keyhint>:<base64-ciphertext>
		return 'v2:' . $key_hint . ':' . base64_encode( $plain ^ $key );
	}

	/**
	 * Whether a stored value should be re-encrypted in the current format.
	 *
	 * True for a v2 value when sodium is available: the value still opens,
	 * but it shares its keystream with every other v2 value under the same
	 * salt.
	 *
	 * @since 1.39.1
	 * @param mixed $cipher_str Stored value.
	 * @return bool
	 */
	public static function needs_upgrade( $cipher_str ) {
		return is_string( $cipher_str )
			&& 0 === strpos( $cipher_str, 'v2:' )
			&& self::sodium_available();
	}

	/**
	 * Whether the secretbox primitives are callable here.
	 *
	 * @return bool
	 */
	private static function sodium_available() {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& function_exists( 'random_bytes' );
	}

	/**
	 * Encrypt in the v3 format.
	 *
	 * @param string $plain    Cleartext.
	 * @param string $key_hint Current key hint.
	 * @return string Ciphertext, or '' on failure.
	 */
	private static function encrypt_v3( $plain, $key_hint ) {
		$key = self::derive_box_key();
		if ( '' === $key ) {
			return '';
		}
		try {
			$nonce = random_bytes( self::NONCE_BYTES );
			$box   = sodium_crypto_secretbox( $plain, $nonce, $key );
		} catch ( \Exception $e ) {
			return '';
		} catch ( \Error $e ) {
			return '';
		}
		return 'v3:' . $key_hint . ':' . base64_encode( $nonce . $box );
	}

	/**
	 * Open a v3 payload.
	 *
	 * @param string $payload Base64 of nonce . secretbox.
	 * @return string Cleartext, or '' when it does not open.
	 */
	private static function decrypt_v3( $payload ) {
		$decoded = base64_decode( $payload, true );
		if ( false === $decoded || strlen( $decoded ) <= self::NONCE_BYTES + self::MAC_BYTES ) {
			return '';
		}
		$key = self::derive_box_key();
		if ( '' === $key ) {
			return '';
		}
		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $decoded, self::NONCE_BYTES ),
				substr( $decoded, 0, self::NONCE_BYTES ),
				$key
			);
		} catch ( \Exception $e ) {
			return '';
		} catch ( \Error $e ) {
			return '';
		}
		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * The 32-byte secretbox key, separated from the v2 keystream and the hint.
	 *
	 * @return string Raw key, or '' on no-salt.
	 */
	private static function derive_box_key() {
		if ( ! function_exists( 'wp_salt' ) ) {
			return '';
		}
		$salt = (string) wp_salt( 'auth' );
		if ( '' === $salt ) {
			return '';
		}
		return hash_hmac( 'sha256', 'faz_secrets_v3|secretbox', $salt, true );
	}

	/**
	 * Decrypt a previously-encrypted string.
	 *
	 * Returns '' if input is unrecognizable OR if the key-hint indicates
	 * the salt has rotated since encryption. L1-SP1-S003 fix (1.15.0):
	 * the key-hint prefix lets the consumer detect salt rotation; an
	 * empty return triggers admin notice via Ipinfo_Client lookup path
	 * (which treats "" as "key missing" and surfaces the gap).
	 *
	 * @param string $cipher_str 'v3:', 'v2:' or 'v1:' prefixed ciphertext.
	 * @return string Decrypted plaintext or '' on failure.
	 */
	public static function decrypt( $cipher_str ) {
		if ( ! is_string( $cipher_str ) ) {
			return '';
		}
		// v3: authenticated secretbox with a per-value nonce.
		if ( 0 === strpos( $cipher_str, 'v3:' ) ) {
			$parts = explode( ':', $cipher_str, 3 );
			if ( 3 !== count( $parts ) || ! self::sodium_available() ) {
				return '';
			}
			$cur_hint = self::current_key_hint();
			if ( '' === $cur_hint || ! hash_equals( $cur_hint, (string) $parts[1] ) ) {
				return '';
			}
			return self::decrypt_v3( (string) $parts[2] );
		}
		// v2 path with salt-rotation hint check.
		if ( 0 === strpos( $cipher_str, 'v2:' ) ) {
			$parts = explode( ':', $cipher_str, 3 );
			if ( 3 !== count( $parts ) ) {
				return '';
			}
			$hint     = (string) $parts[1];
			$payload  = (string) $parts[2];
			$cur_hint = self::current_key_hint();
			if ( '' === $cur_hint || $hint !== $cur_hint ) {
				// Either wp_salt() is unavailable now, or the salt has
				// rotated since encryption — in both cases the keystream
				// is no longer derivable. Return empty so the consumer
				// (Ipinfo_Client) treats this as "key missing" rather
				// than silently producing garbage.
				return '';
			}
			$decoded = base64_decode( $payload, true );
			if ( false === $decoded || '' === $decoded ) {
				return '';
			}
			$key = self::derive_key( strlen( $decoded ) );
			if ( '' === $key ) {
				return '';
			}
			return $decoded ^ $key;
		}
		// v1 backward-compat path — DEPRECATED.
		// Pre-v2 ciphertexts have no key hint, so we cannot detect salt
		// rotation. Returning the XOR result blindly would emit arbitrary
		// bytes whenever wp_salt('auth') had rotated since encryption,
		// and those bytes would then be sent as a bearer token to
		// ipinfo.io. Refuse to decode v1 ciphertext — consumers will see
		// "key missing", the admin notice surfaces, and the operator
		// re-enters the API key (which gets re-encrypted as v2). Any
		// active install upgrading from v1 → v2 thus performs a one-time
		// re-entry; no install written by this version produces v1.
		if ( 0 === strpos( $cipher_str, 'v1:' ) ) {
			return '';
		}
		return '';
	}

	/**
	 * 8-char hint of the current key (used by v2 format for salt-rotation
	 * detection — see L1-SP1-S003 resolution).
	 *
	 * Returns empty when wp_salt() isn't available or returned an empty
	 * string — caller must treat this as "encryption unavailable" rather
	 * than substitute a fallback (the previous shared literal made every
	 * install in that state cryptographically indistinguishable).
	 *
	 * @return string 8 hex chars, or '' when no valid salt is available.
	 */
	private static function current_key_hint() {
		if ( ! function_exists( 'wp_salt' ) ) {
			return '';
		}
		$salt = (string) wp_salt( 'auth' );
		if ( '' === $salt ) {
			return '';
		}
		return substr( hash( 'sha256', 'faz_secrets_v2|' . $salt ), 0, 8 );
	}

	/**
	 * Derive a keystream of the requested length from wp_salt('auth').
	 *
	 * Returns empty string when no valid salt is available — callers MUST
	 * check for this and refuse to encrypt rather than fall back to a
	 * predictable keystream.
	 *
	 * @param int $length Bytes needed.
	 * @return string Keystream, or '' on no-salt.
	 */
	private static function derive_key( $length ) {
		$length = max( 1, (int) $length );
		if ( ! function_exists( 'wp_salt' ) ) {
			return '';
		}
		$salt = (string) wp_salt( 'auth' );
		if ( '' === $salt ) {
			return '';
		}
		$stream = '';
		while ( strlen( $stream ) < $length ) {
			$stream .= hash( 'sha256', $salt . strlen( $stream ), true );
		}
		return substr( $stream, 0, $length );
	}
}
