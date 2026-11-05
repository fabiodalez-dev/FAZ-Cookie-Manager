<?php
/**
 * Randomized, JavaScript-free encoding of public contact email addresses.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Obfuscation {

	/**
	 * Replace escaped addresses with random decimal/hexadecimal HTML entities.
	 *
	 * Every character is encoded, including attribute-sensitive characters.
	 * This deters raw-source scrapers, not bots that decode HTML entities.
	 *
	 * @param string   $html   Already escaped/sanitized HTML.
	 * @param string[] $emails Plain-text email addresses.
	 * @return string
	 */
	public static function apply( $html, array $emails ) {
		$replacements = array();
		foreach ( array_unique( $emails ) as $email ) {
			if ( '' === $email ) {
				continue;
			}
			$encoded = '';
			foreach ( str_split( $email ) as $character ) {
				$code     = ord( $character );
				$encoded .= random_int( 0, 1 ) ? '&#x' . dechex( $code ) . ';' : '&#' . $code . ';';
			}
			$replacements[ esc_html( $email ) ] = $encoded;
		}
		return strtr( $html, $replacements );
	}
}
