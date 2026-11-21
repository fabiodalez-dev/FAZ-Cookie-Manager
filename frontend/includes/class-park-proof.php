<?php
/**
 * Server signature for the values the output buffer parks into kses-guarded
 * sinks (object data, style, background).
 *
 * The runtime restores data-faz-data, data-faz-style and data-faz-background
 * only with a park proof: the response-local key plus the parked names. That
 * key is random per response, so markup parked by ANOTHER response — content
 * a page fetches later (infinite scroll, "load more", AJAX pagination), or a
 * fragment a cache stored and a later response embeds — carries a key the
 * page does not have, and was left parked after consent until a reload.
 *
 * The signature binds each parked name to the exact value the server parked,
 * with a site secret. An author can write data-faz-* attributes into a post
 * (wp_kses_post keeps data-*), but cannot produce a signature for a value of
 * their choice, so a signed value is one the server itself parked from a
 * live attribute. A later response re-keys signed tags it serves
 * (Frontend::refresh_park_proofs()), and the runtime asks the park-proofs
 * REST route to vouch for a signed tag that arrived with another key.
 *
 * @package FazCookie\Frontend\Includes
 */

namespace FazCookie\Frontend\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Park proof signatures.
 */
class Park_Proof {

	/**
	 * The parked names a proof may cover: data-faz-<name> holds the value.
	 *
	 * @var string[]
	 */
	const NAMES = array( 'data', 'style', 'background' );

	/**
	 * Longest value the REST route signs back, in bytes. A parked style or
	 * URL is far shorter; the bound keeps one request cheap.
	 */
	const MAX_VALUE_BYTES = 32768;

	/**
	 * Most items one REST request may ask about.
	 */
	const MAX_ITEMS = 50;

	/**
	 * Signature over parked names and the values they hold, in order.
	 *
	 * The values are the ones the browser reads back with getAttribute(),
	 * i.e. character references already decoded.
	 *
	 * @param string[] $names  Parked names, each one of self::NAMES.
	 * @param string[] $values Value of data-faz-<name> for each name.
	 * @return string Hex HMAC, or '' when the input cannot be signed.
	 */
	public static function signature( array $names, array $values ) {
		if ( ! self::names_are_valid( $names ) || count( $names ) !== count( $values ) ) {
			return '';
		}
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > self::MAX_VALUE_BYTES ) {
				return '';
			}
		}
		$payload = json_encode( array( array_values( $names ), array_values( $values ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $payload ) {
			return '';
		}
		return hash_hmac( 'sha256', 'faz-park|' . $payload, wp_salt( 'auth' ) );
	}

	/**
	 * Whether a list of names is non-empty, unique and inside self::NAMES.
	 *
	 * @param mixed $names Candidate list.
	 * @return bool
	 */
	public static function names_are_valid( $names ) {
		if ( ! is_array( $names ) || array() === $names || count( $names ) > count( self::NAMES ) ) {
			return false;
		}
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || ! in_array( $name, self::NAMES, true ) ) {
				return false;
			}
		}
		return count( array_unique( $names ) ) === count( $names );
	}

	/**
	 * Whether one REST item carries a signature this site made.
	 *
	 * @param mixed $item array( 'names' => string[], 'values' => string[], 'sig' => string ).
	 * @return bool
	 */
	public static function item_is_valid( $item ) {
		if ( ! is_array( $item ) || ! isset( $item['names'], $item['values'], $item['sig'] ) ) {
			return false;
		}
		if ( ! is_array( $item['names'] ) || ! is_array( $item['values'] ) || ! is_string( $item['sig'] ) || ! preg_match( '/^[0-9a-f]{64}$/', $item['sig'] ) ) {
			return false;
		}
		$expected = self::signature( array_values( $item['names'] ), array_values( $item['values'] ) );
		return '' !== $expected && hash_equals( $expected, $item['sig'] );
	}
}
