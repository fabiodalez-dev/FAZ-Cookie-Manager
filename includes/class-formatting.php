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

if ( ! function_exists( 'faz_status_flag' ) ) {
	/**
	 * Render a yes/no value for the System Status report.
	 *
	 * Icon AND word, deliberately. The report used a bare ✅/❌ character, and
	 * its "Copy report" button builds the text with `textContent` — so whether
	 * the answer survives depends on what the reader pastes into. Frequently it
	 * does not: a support report arrived with EVERY boolean blank, and the one
	 * value needed to diagnose the reported problem was the one the format had
	 * dropped (issue #259).
	 *
	 * The single row that did survive in that report was "Auto Scan", because it
	 * happened to append a word after its icon. That is the whole fix, applied
	 * everywhere: the icon stays for visual scanning, the word carries the
	 * meaning through any clipboard.
	 *
	 * @since 1.29.0
	 * @param bool $enabled Whether the feature is on.
	 * @return string Escaped markup, safe to echo.
	 */
	function faz_status_flag( $enabled ) {
		return $enabled
			? '&#9989; ' . esc_html__( 'Yes', 'faz-cookie-manager' )
			: '&#10060; ' . esc_html__( 'No', 'faz-cookie-manager' );
	}
}

if ( ! function_exists( 'faz_site_utc_offset' ) ) {
	/**
	 * The site's UTC offset AT A GIVEN INSTANT, not right now.
	 *
	 * get_option( 'gmt_offset' ) is the offset in force today. Using it to render
	 * a schedule in October, read from Rome in September, puts the row an hour
	 * ahead: the DST change falls between the two moments. The same error can
	 * invert the definitions comparison when the two dates are less than an hour
	 * apart.
	 *
	 * timezone_string is authoritative when set, and DateTimeZone is plain PHP —
	 * so this needs no WordPress function newer than the 5.0 floor. wp_timezone()
	 * would be the idiomatic answer and is 5.3; Plugin Check flags that name
	 * statically, guards or not. Sites configured with a raw numeric offset have
	 * no DST to model, so gmt_offset is exact for them by definition.
	 *
	 * @param int $timestamp Unix timestamp the offset is wanted for.
	 * @return int Offset in seconds.
	 */
	function faz_site_utc_offset( $timestamp ) {
		$tz_string = (string) get_option( 'timezone_string' );
		if ( '' !== $tz_string ) {
			try {
				$tz = new DateTimeZone( $tz_string );
				return (int) $tz->getOffset( new DateTime( '@' . (int) $timestamp ) );
			} catch ( Exception $e ) {
				// Unparseable setting — fall through to the numeric offset.
			}
		}
		return (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
	}
}

if ( ! function_exists( 'faz_status_schedule' ) ) {
	/**
	 * Render a scheduled-event timestamp, saying so when it is overdue.
	 *
	 * A bare timestamp states a fact nobody checks against today's date. The
	 * report that prompted this listed a next scan of 2026-08-27 while the date
	 * was 1 September — five days stale, because WP-Cron was not firing at all.
	 * That was the actual cause of the "why are my definitions old?" question
	 * the report was attached to, and it was sitting in plain sight, unlabelled.
	 *
	 * @since 1.29.0
	 * @param int|false $timestamp Result of wp_next_scheduled().
	 * @return string Escaped markup, safe to echo.
	 */
	function faz_status_schedule( $timestamp ) {
		if ( ! $timestamp ) {
			return '&mdash; ' . esc_html__( 'not scheduled', 'faz-cookie-manager' );
		}
		// date_i18n()'s second argument is a timestamp that ALREADY carries the
		// site's offset — the legacy contract. wp_next_scheduled() hands back a
		// true Unix timestamp, so passing it straight through renders UTC while
		// every other admin screen shows local time. Measured on a Europe/Rome
		// site: the row said 19:04 for a schedule that is 21:04 to the person
		// reading it. Two hours of quiet error in a report whose only job is to
		// be believed.
		//
		// wp_date() is the modern answer and is WP 5.3+; this plugin declares 5.0
		// and Plugin Check flags the name statically regardless of guards, so the
		// offset is applied by hand. get_option( 'gmt_offset' ) is safe for that:
		// WordPress recomputes it from timezone_string through the
		// pre_option_gmt_offset filter, so it follows DST — verified as 2 for
		// Europe/Rome in September and 1 in January.
		$offset = faz_site_utc_offset( (int) $timestamp );
		$when   = esc_html( date_i18n( 'Y-m-d H:i:s', (int) $timestamp + $offset ) );
		$late = time() - (int) $timestamp;

		// WP-Cron is traffic-driven: a due event fires on the next page load, so
		// between the scheduled instant and that load the event is legitimately
		// "late". On a quiet site that gap is routinely minutes. Flagging any
		// positive lag would put an alarm on a perfectly healthy install — in the
		// one document an admin pastes into a support thread, which is how a
		// diagnosis goes down the wrong path. Only a lag no plausible traffic
		// pattern explains is worth reporting.
		if ( $late <= HOUR_IN_SECONDS ) {
			return $when;
		}

		// Says what is known — the event is overdue by this much — and offers the
		// likely cause as a hypothesis. The code has not tested whether WP-Cron
		// runs; it has only read a timestamp, so it must not assert that it does not.
		return $when . ' &#9888; ' . sprintf(
			/* translators: %s: human-readable duration, e.g. "5 days". */
			esc_html__( 'OVERDUE by %s — WP-Cron may not be running', 'faz-cookie-manager' ),
			esc_html( human_time_diff( (int) $timestamp, time() ) )
		);
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
			// The trigger→dialog relationship. Without these two in the
			// allowlist, kses silently strips them from any server-rendered
			// markup that carries them — the [faz_cookie_settings] shortcode
			// already emits aria-haspopup, so it was relying on not being
			// filtered through here.
			'aria-haspopup'    => true,
			'aria-controls'    => true,
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
