<?php
/**
 * JavaScript-free encoding of public contact email addresses.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Obfuscation {

	/**
	 * Replace escaped addresses with a mix of decimal and hexadecimal HTML entities.
	 *
	 * Every character is encoded, including attribute-sensitive characters.
	 * This deters raw-source scrapers, not bots that decode HTML entities.
	 *
	 * The mix is derived from a hash of the address rather than drawn at
	 * random, so the same address always produces the same markup. A random
	 * draw gave every render different bytes: two renders of one policy no
	 * longer matched, and page caches, ETags and render comparisons saw a
	 * change where there was none. A scraper that decodes entities reads the
	 * address either way, so the randomness bought no protection.
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
			$pattern = hash( 'sha256', $email );
			$encoded = '';
			foreach ( str_split( $email ) as $index => $character ) {
				$code     = ord( $character );
				$encoded .= ( hexdec( $pattern[ $index % 64 ] ) & 1 ) ? '&#x' . dechex( $code ) . ';' : '&#' . $code . ';';
			}
			$replacements[ esc_html( $email ) ] = $encoded;
		}
		return strtr( $html, $replacements );
	}
}
