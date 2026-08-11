<?php
/**
 * Shared fallback translations for cookie duration and description fields.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves bundled translations for cookie inventory content.
 *
 * User-entered values remain authoritative. Callers invoke this class only
 * when the requested language is empty, so the bundled catalogue acts as a
 * non-destructive fallback for both new and legacy database rows.
 */
class Cookie_Content_I18n {

	/**
	 * Request-local decoded JSON cache.
	 *
	 * @var array<string,array>
	 */
	private static $catalogues = array();

	/**
	 * Translate one cookie field.
	 *
	 * @param string $slug   Cookie slug/name.
	 * @param string $key    Field name (description or duration).
	 * @param string $source Stored fallback value, used by duration parsing.
	 * @param string $lang   Requested plugin language code.
	 * @return string
	 */
	public static function translate( $slug, $key, $source, $lang ) {
		if ( 'description' === $key ) {
			// The catalogue may only speak for text the plugin itself wrote.
			// Previously it keyed purely on the cookie name and ignored $source
			// entirely, so a description an administrator had authored — often
			// reviewed legal copy naming their own processor and retention —
			// was replaced by a generic sentence, and the next save persisted
			// that generic sentence into the database over the original.
			//
			// Same gate localize_category_name() already uses: translate when
			// the stored value is absent or is still the bundled English text,
			// stand down otherwise. Returning '' lets the caller fall back to
			// the stored value, which is the correct answer in that case.
			$stored = is_string( $source ) ? trim( $source ) : '';
			if ( '' !== $stored && ! self::is_stock_description( $slug, $stored ) ) {
				return '';
			}
			return self::description( $slug, $lang );
		}
		if ( 'duration' === $key ) {
			return self::duration( $source, $lang );
		}
		return '';
	}

	/**
	 * Whether a stored description is still the plugin's own English text.
	 *
	 * "Stock" means the bundled English catalogue entry for this cookie — the
	 * text the scanner or a blocker template would have written. Anything else,
	 * including a lightly edited version of it, counts as the site owner's and is
	 * left alone: an edit is a decision, and half-respecting it would be worse
	 * than not translating at all.
	 *
	 * Comparison is whitespace- and case-insensitive so a trailing space or a
	 * capitalisation tweak from a WYSIWYG round-trip does not read as authorship,
	 * but nothing looser than that — the failure this guards against is treating
	 * somebody's own words as replaceable.
	 *
	 * @param string $slug   Cookie slug/name.
	 * @param string $stored Trimmed stored value in the default language.
	 * @return bool
	 */
	public static function is_stock_description( $slug, $stored ) {
		$stock = self::description( $slug, 'en' );
		if ( '' === $stock ) {
			return false;
		}
		$normalise = static function ( $text ) {
			return strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $text ) ) );
		};
		return $normalise( $stock ) === $normalise( $stored );
	}

	/**
	 * Resolve a curated cookie description by slug.
	 *
	 * @param string $slug Cookie slug/name.
	 * @param string $lang Requested language.
	 * @return string
	 */
	public static function description( $slug, $lang ) {
		$lang     = self::normalize_language( $lang );
		$contents = self::load_catalogue( 'cookies/' . $lang . '.json' );
		if ( empty( $contents ) ) {
			return '';
		}

		$slug = function_exists( 'sanitize_title' ) ? sanitize_title( $slug ) : strtolower( trim( (string) $slug ) );
		if ( isset( $contents[ $slug ]['description'] ) && is_string( $contents[ $slug ]['description'] ) ) {
			return $contents[ $slug ]['description'];
		}

		// Prefix definitions such as `_ga_` and `comment_author_` also cover
		// the concrete cookie names generated at runtime.
		foreach ( $contents as $catalogue_slug => $entry ) {
			$last = substr( (string) $catalogue_slug, -1 );
			if ( ! in_array( $last, array( '_', '-' ), true ) || 0 !== strpos( $slug, (string) $catalogue_slug ) ) {
				continue;
			}
			if ( isset( $entry['description'] ) && is_string( $entry['description'] ) ) {
				return $entry['description'];
			}
		}
		return '';
	}

	/**
	 * Translate a simple English retention period (for example "2 years").
	 *
	 * Free-form or ambiguous values intentionally return an empty string. The
	 * caller then preserves the stored value through its existing fallback.
	 *
	 * @param string $source Stored duration.
	 * @param string $lang   Requested language.
	 * @return string
	 */
	public static function duration( $source, $lang ) {
		$source = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $source ) : strip_tags( (string) $source );
		$raw    = trim( $source );
		$source = strtolower( $raw );
		$lang   = self::normalize_language( $lang );
		$units  = self::load_catalogue( 'duration-units.json' );
		if ( ! isset( $units[ $lang ] ) || ! is_array( $units[ $lang ] ) ) {
			return '';
		}

		$specials = array(
			'session'    => 'session',
			'sessions'   => 'session',
			'seesion'    => 'session',
			'session cookie' => 'session',
			'persistent' => 'persistent',
			'permanent'  => 'persistent',
			'forever'    => 'persistent',
		);
		if ( isset( $specials[ $source ], $units[ $lang ][ $specials[ $source ] ] ) ) {
			return self::match_leading_case( $raw, (string) $units[ $lang ][ $specials[ $source ] ] );
		}

		if ( ! preg_match( '/^(\d+(?:[.,]\d+)?)\s*(seconds?|secs?|sec|minutes?|mins?|min|hours?|hrs?|hr|days?|weeks?|months?|years?)$/', $source, $matches ) ) {
			return '';
		}
		$aliases = array(
			'sec' => 'second', 'secs' => 'second', 'second' => 'second', 'seconds' => 'second',
			'min' => 'minute', 'mins' => 'minute', 'minute' => 'minute', 'minutes' => 'minute',
			'hr' => 'hour', 'hrs' => 'hour', 'hour' => 'hour', 'hours' => 'hour',
			'day' => 'day', 'days' => 'day', 'week' => 'week', 'weeks' => 'week',
			'month' => 'month', 'months' => 'month', 'year' => 'year', 'years' => 'year',
		);
		$unit = isset( $aliases[ $matches[2] ] ) ? $aliases[ $matches[2] ] : '';
		if ( '' === $unit || ! isset( $units[ $lang ][ $unit ] ) || ! is_array( $units[ $lang ][ $unit ] ) ) {
			return '';
		}

		$amount = str_replace( ',', '.', $matches[1] );
		$form   = self::plural_form( (float) $amount, $lang );
		$label  = isset( $units[ $lang ][ $unit ][ $form ] ) ? $units[ $lang ][ $unit ][ $form ] : '';
		if ( '' === $label && isset( $units[ $lang ][ $unit ]['other'] ) ) {
			$label = $units[ $lang ][ $unit ]['other'];
		}
		return '' !== $label ? $matches[1] . ' ' . $label : '';
	}

	/**
	 * Carry the source's leading capitalisation onto the translation.
	 *
	 * Inventories store these as "Session" or "Persistent" far more often than
	 * lowercase, while the catalogue is lowercase so it can also sit mid-phrase.
	 * Without this the duration column reads "Session" in English and "sessione"
	 * in Italian — the mixed-language look issue #214 is about, reintroduced by
	 * the fix for it. Numeric durations start with a digit and are untouched.
	 *
	 * @param string $source     Original stored value, trimmed.
	 * @param string $translated Catalogue value.
	 * @return string
	 */
	private static function match_leading_case( $source, $translated ) {
		if ( '' === $source || '' === $translated ) {
			return $translated;
		}
		$first = substr( $source, 0, 1 );
		if ( strtoupper( $first ) !== $first || strtolower( $first ) === $first ) {
			return $translated; // Not an upper-case letter (digit, symbol, or already lower).
		}
		if ( function_exists( 'mb_strtoupper' ) && function_exists( 'mb_substr' ) ) {
			return mb_strtoupper( mb_substr( $translated, 0, 1 ), 'UTF-8' ) . mb_substr( $translated, 1 );
		}
		return ucfirst( $translated );
	}

	/**
	 * Select the grammatical number used by the bundled unit catalogue.
	 *
	 * @param float  $amount Numeric duration amount.
	 * @param string $lang   Normalized language code.
	 * @return string one|few|other
	 */
	private static function plural_form( $amount, $lang ) {
		if ( 1.0 === $amount ) {
			return 'one';
		}
		if ( floor( $amount ) !== $amount ) {
			return 'other';
		}
		$integer = (int) $amount;
		if ( 'cs' === $lang && $integer >= 2 && $integer <= 4 ) {
			return 'few';
		}
		// Polish and Croatian share the "few" rule — 2-4 in the last digit, minus
		// the 12-14 teens — but NOT the "one" rule, and the difference is real:
		//
		//   Croatian  21 → one   "21 sat"   (hr.hour one=sat, other=sati)
		//   Polish    21 → other "21 lat"   (pl.year one=rok, other=lat)
		//
		// CLDR gives Polish `one` for exactly 1 and nothing else, while Croatian
		// gives it to every number ending in 1 except 11. Extending the "one"
		// branch to Polish as well would render "21 rok".
		if ( in_array( $lang, array( 'pl', 'hr' ), true ) ) {
			$last_digit = $integer % 10;
			$last_two   = $integer % 100;
			if ( 'hr' === $lang && 1 === $last_digit && 11 !== $last_two ) {
				return 'one';
			}
			if ( in_array( $last_digit, array( 2, 3, 4 ), true )
				&& ! in_array( $last_two, array( 12, 13, 14 ), true ) ) {
				return 'few';
			}
		}
		return 'other';
	}

	/**
	 * Decode one bundled JSON catalogue.
	 *
	 * @param string $relative Relative path below cookie contents.
	 * @return array
	 */
	private static function load_catalogue( $relative ) {
		if ( isset( self::$catalogues[ $relative ] ) ) {
			return self::$catalogues[ $relative ];
		}
		$path = dirname( __DIR__ ) . '/admin/modules/cookies/includes/contents/' . $relative;
		if ( ! is_readable( $path ) ) {
			self::$catalogues[ $relative ] = array();
			return array();
		}
		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled read-only JSON, not a URL.
		self::$catalogues[ $relative ] = is_array( $decoded ) ? $decoded : array();
		return self::$catalogues[ $relative ];
	}

	/**
	 * Normalize WordPress/plugin locale variants to catalogue keys.
	 *
	 * @param string $lang Language or locale.
	 * @return string
	 */
	private static function normalize_language( $lang ) {
		$lang = strtolower( str_replace( '_', '-', (string) $lang ) );
		if ( 0 === strpos( $lang, 'pt-br' ) ) {
			return 'pt-br';
		}
		return substr( $lang, 0, 2 );
	}
}
