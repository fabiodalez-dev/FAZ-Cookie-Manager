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
	 * is recognised now.
	 *
	 * What this function does NOT do, stated plainly because an earlier version
	 * of this docblock claimed otherwise: it does not err toward false for
	 * arbitrary input. The negatives are ENUMERATED, so an unrecognised string
	 * ('maybe', 'banana', a value truncated by a bad migration) still returns
	 * true. Narrowing that globally is not safe — this is the plugin's general
	 * boolean coercion, and it has callers where TRUE is the restrictive side,
	 * so flipping unknown input to false there would quietly switch protections
	 * off rather than on.
	 *
	 * For the asymmetric case — a flag whose true value REMOVES a restriction,
	 * such as exempting a payment gateway from consent blocking — use
	 * faz_sanitize_bool_strict() below, which demands an explicit affirmative.
	 * Which of the two applies is a question about which direction is dangerous,
	 * and only the callsite knows that.
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

if ( ! function_exists( 'faz_sanitize_bool_strict' ) ) {

	/**
	 * Coerce to bool for flags whose TRUE value removes a restriction.
	 *
	 * faz_sanitize_bool() enumerates the negatives, so anything it does not
	 * recognise comes back true. That is the right default for a general
	 * coercion and the wrong one for permission: a payment-gateway exemption, an
	 * always-allow, a "skip blocking here" flag. On those, a corrupted or
	 * unexpected value must not be the same as the site owner having said yes.
	 *
	 * So this inverts the burden — only an explicit affirmative counts, and
	 * everything else, including a string nobody anticipated, is false. There is
	 * no third outcome and no logging: the caller wants a decision, and the safe
	 * decision when the stored value is unintelligible is "not exempt".
	 *
	 * @since 1.26.0
	 * @param mixed $value Stored value of a permission flag.
	 * @return bool
	 */
	function faz_sanitize_bool_strict( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			// Compared without a cast: (int) 1.5 and (int) 1.9 are both 1, so a
			// fractional value truncated its way into "yes" and exempted a gateway
			// from pre-consent blocking. Nothing legitimately stores 1.9 here, and
			// that is the point — an unintended value must not round toward
			// permission.
			return 1 === $value || 1.0 === $value;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		return in_array( strtolower( trim( $value ) ), array( '1', 'yes', 'true', 'on' ), true );
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

if ( ! function_exists( 'faz_asset_suffix' ) ) {
	/**
	 * Return `.min` when a production JavaScript build exists and debug is off.
	 *
	 * @param string $relative_path Plugin-relative asset path without extension.
	 * @return string
	 */
	function faz_asset_suffix( $relative_path ) {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return '';
		}
		$base = defined( 'FAZ_PLUGIN_BASEPATH' ) ? FAZ_PLUGIN_BASEPATH : dirname( __DIR__ ) . '/';
		$path = $base . ltrim( (string) $relative_path, '/' ) . '.min.js';
		return file_exists( $path ) ? '.min' : '';
	}
}
