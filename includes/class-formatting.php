<?php
/**
 * Formatting helper function class
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 * @package    FazCookie\Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! function_exists( 'faz_sanitize_text' ) ) {

	/**
	 * Clean variables using sanitize_text_field. Arrays are cleaned recursively.
	 * Non-scalar values are ignored.
	 *
	 * @param string|array $var Data to sanitize.
	 * @return string|array
	 */
	function faz_sanitize_text( $var ) {
		if ( is_array( $var ) ) {
			return array_map( 'faz_sanitize_text', $var );
		} else {
			return is_scalar( $var ) ? sanitize_text_field( $var ) : $var;
		}
	}
}

if ( ! function_exists( 'faz_sanitize_bool' ) ) {

	/**
	 * Converts a string (e.g. 'yes' or 'no') to a bool.
	 *
	 * The docblock has always named `'no'` as an example, and `'no'` used to come
	 * back TRUE: only `'false'` and `'0'` were recognised. That is not a cosmetic
	 * gap, because this function decides consent-adjacent booleans — a payment
	 * gateway marked `"no"` was read as exempt from blocking and loaded before
	 * consent. Every canonical negative this plugin actually writes or receives
	 * is recognised now, and the list errs toward false: for a flag whose true
	 * value REMOVES a restriction, a value nobody anticipated must not be read as
	 * permission.
	 *
	 * `'null'` and `'undefined'` are here because they arrive from JavaScript:
	 * a client that string-interpolates an absent value posts the literal word,
	 * and both are unambiguously "no value", never an affirmation.
	 *
	 * Whitespace is trimmed first, so `' false'` from a hand-edited option or a
	 * copy-pasted config no longer reads as true on a technicality.
	 *
	 * @since 3.0.0
	 * @param string|bool $string String to convert. If a bool is passed it will be returned as-is.
	 * @return bool
	 */
	function faz_sanitize_bool( $string ) {
		if ( is_string( $string ) ) {
			$string = strtolower( trim( $string ) );
			if ( in_array( $string, array( 'false', '0', 'no', 'off', 'null', 'undefined', '' ), true ) ) {
				return false;
			}
			return true;
		}
		// A non-scalar cannot express a boolean intent: an array or object here
		// means the value is malformed, and `(bool) array( 'x' )` being true
		// would turn corrupted data into an exemption.
		if ( ! is_scalar( $string ) && null !== $string ) {
			return false;
		}
		// Everything else (bool, int, float, null) maps nicely to boolean.
		return (bool) $string;
	}
}

if ( ! function_exists( 'faz_allowed_html' ) ) {
	/**
	 * Returns list of HTML tags allowed in HTML fields for use in declaration of wp_kset field validation.
	 * Deliberately allows class and ID declarations to assist with custom CSS styling.
	 * To customise further, see the excellent article at: http://ottopress.com/2010/wp-quickie-kses/
	 *
	 * @return array
	 */
	function faz_allowed_html() {
		$html = wp_kses_allowed_html( 'post' );
		// Merge our required <input> attributes INTO whatever 'input' definition
		// wp_kses_allowed_html( 'post' ) yields, rather than letting a whole-array
		// array_merge clobber it. Another active plugin can hook
		// wp_kses_allowed_html and add its own 'input' entry (e.g. a forms/comments
		// plugin allowing 'value'); with our array passed FIRST to array_merge(),
		// that entry would fully overwrite ours and drop type=true — so wp_kses()
		// strips type="checkbox" from the category toggle, which then defaults to
		// type="text" and renders as an editable field. Merging the sub-array keeps
		// both sides' attributes. #188
		$existing_input = ( isset( $html['input'] ) && is_array( $html['input'] ) ) ? $html['input'] : array();
		$html['input']  = array_merge(
			$existing_input,
			array(
				'type'  => true,
				'style' => true,
				'id'    => true,
				'class' => true,
			)
		);
		$html = array_map( '_faz_global_attributes', $html );
		return apply_filters( 'faz_allowed_html', $html );
	}
	/**
	 * Global attributes for any html tags
	 *
	 * @param string $value Default attribute.
	 * @return array
	 */
	function _faz_global_attributes( $value ) {
		$global_attributes = array(
			'aria-describedby' => true,
			'aria-details'     => true,
			'aria-label'       => true,
			'aria-labelledby'  => true,
			'aria-hidden'      => true,
			'class'            => true,
			'id'               => true,
			'style'            => true,
			'title'            => true,
			'role'             => true,
			'data-*'           => true,
			'data-faz-tag'     => true,
			'tabindex'         => true,
			'aria-level'       => true,
		);
		if ( true === $value ) {
			$value = array();
		}

		if ( is_array( $value ) ) {
			return array_merge( $value, $global_attributes );
		}

		return $value;
	}
}

if ( ! function_exists( 'faz_sanitize_content' ) ) {

	/**
	 * Sanitizes content for allowed HTML tags for post content.
	 *
	 * Post content refers to the page contents of the 'post' type and not `$_POST`
	 * data from forms.
	 *
	 * This function expects unslashed data.
	 *
	 * @since 3.0.0
	 *
	 * @param string $string Post content to filter.
	 * @return string Filtered post content with allowed HTML tags and attributes intact.
	 */
	function faz_sanitize_content( $string ) {
		if ( is_array( $string ) ) {
			return array_map( 'faz_sanitize_content', $string );
		} else {
			return is_scalar( $string ) ? wp_kses( $string, faz_allowed_html() ) : $string;
		}
	}
}
if ( ! function_exists( 'faz_sanitize_color' ) ) {

	/**
	 * Sanitize color value.
	 *
	 * @param string $value The color value.
	 * @return string
	 */
	function faz_sanitize_color( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		// CSS-wide / keyword colour values that are safe (no CSS
		// metacharacters, so they cannot break out of the custom-property
		// declaration) AND used by the bundled defaults — e.g. the revisit
		// button ships "color": "inherit" in gdpr.json / ccpa.json / theme.json.
		// Without this allow-list sanitize_hex_color() would turn them into ''
		// and wipe the default on every set_settings()/get_settings() round-trip.
		$keywords = array( 'transparent', 'inherit', 'initial', 'unset', 'currentcolor' );
		if ( in_array( strtolower( $value ), $keywords, true ) ) {
			return sanitize_text_field( $value );
		}
		if ( false === strpos( $value, 'rgba' ) ) {
			return sanitize_hex_color( $value );
		}

		// rgba value.
		$red   = '';
		$green = '';
		$blue  = '';
		$alpha = '';
		sscanf( $value, 'rgba(%d,%d,%d,%f)', $red, $green, $blue, $alpha );
		return 'rgba(' . $red . ',' . $green . ',' . $blue . ',' . $alpha . ')';
	}
}
