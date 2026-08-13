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
 *
 * ---------------------------------------------------------------------------
 * Adding a language
 * ---------------------------------------------------------------------------
 * Two data files under admin/modules/cookies/includes/contents/ feed this
 * class, both keyed by the two-letter code normalize_language() produces
 * (`pt-br` is the one exception, kept whole because Brazilian wording differs):
 *
 * 1. duration-units.json — one object per language:
 *
 *      "de": {
 *        "session": "Sitzung",          // whole-value synonyms, matched alone
 *        "persistent": "dauerhaft",
 *        "year": { "one": "Jahr", "other": "Jahre" },
 *        …second, minute, hour, day, week, month
 *      }
 *
 *    The keys inside a unit are CLDR plural categories. Supply exactly the ones
 *    the language uses — `one`/`other` for most, plus `few`/`many` for Czech and
 *    Polish, `few` for Croatian — and teach plural_form() the rule if the
 *    language needs a selection this class does not model yet. A missing
 *    category falls back to `other`, which silently prints the wrong grammar
 *    rather than failing, so add the rule and the words together.
 *
 * 2. cookies/<lang>.json — the description catalogue, currently `en` and `it`:
 *
 *      { "_ga": { "description": "…" }, "_ga_": { "description": "…" } }
 *
 *    A key is either an exact cookie name, or a prefix covering a family of
 *    runtime-generated names. A prefix is spelled with its own trailing `_`/`-`
 *    (`_ga_`, `wp-settings-`) or, when the family has no separator, with an
 *    explicit `*` (`_hj*`) — see catalogue_prefix(). Exact matches win over
 *    prefixes, and among prefixes the first match in file order wins, so keep
 *    the more specific key above the more general one (`wordpress_logged_in_`
 *    before `wordpress_`).
 *
 * Translating a file means copying en.json, keeping every key byte-for-byte and
 * translating only the description values; keys are cookie names, not prose. A
 * language with no file simply gets no fallback — nothing breaks, the stored
 * value is shown as-is.
 *
 * Sites that need to override any of this without touching bundled files have
 * the `faz_cookie_content_i18n_description` and `faz_cookie_content_i18n_duration`
 * filters, documented at their call sites below.
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
	 * @param string $domain Cookie domain for domain-constrained catalogue entries.
	 * @return string
	 */
	public static function translate( $slug, $key, $source, $lang, $domain = '' ) {
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
			if ( '' !== $stored && ! self::is_stock_description( $slug, $stored, $domain ) ) {
				return '';
			}
			return self::description( $slug, $lang, $domain );
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
	 * @param string $domain Cookie domain for domain-constrained catalogue entries.
	 * @return bool
	 */
	public static function is_stock_description( $slug, $stored, $domain = '' ) {
		$stock = self::description( $slug, 'en', $domain );
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
	 * @param string $domain Cookie domain for domain-constrained catalogue entries.
	 * @return string
	 */
	public static function description( $slug, $lang, $domain = '' ) {
		$lang = self::normalize_language( $lang );
		$slug = function_exists( 'sanitize_title' ) ? sanitize_title( $slug ) : strtolower( trim( (string) $slug ) );

		/**
		 * Filter the bundled description resolved for one cookie.
		 *
		 * The escape hatch for sites the catalogue cannot serve: return a string
		 * to substitute wording, or '' to switch the fallback off for this cookie
		 * so the caller keeps whatever the row already stores.
		 *
		 * Note it also decides what counts as "stock": is_stock_description()
		 * compares a stored value against description( $slug, 'en' ), so a site
		 * that rewrites the English text moves that line with it — deliberately,
		 * since a filtered catalogue is that site's idea of the plugin's own copy.
		 *
		 * @param string $description Resolved catalogue text, '' when unknown.
		 * @param string $slug        Sanitised cookie slug being described.
		 * @param string $lang        Normalised catalogue language code.
		 */
		return self::filter(
			'faz_cookie_content_i18n_description',
			self::lookup_description( $slug, $lang, $domain ),
			array( $slug, $lang, $domain )
		);
	}

	/**
	 * Catalogue lookup behind description(), before filtering.
	 *
	 * @param string $slug Sanitised cookie slug.
	 * @param string $lang Normalised language code.
	 * @param string $domain Cookie domain for domain-constrained catalogue entries.
	 * @return string
	 */
	private static function lookup_description( $slug, $lang, $domain = '' ) {
		$contents = self::load_catalogue( 'cookies/' . $lang . '.json' );
		if ( empty( $contents ) ) {
			return '';
		}

		if ( isset( $contents[ $slug ]['description'] ) && is_string( $contents[ $slug ]['description'] )
			&& self::domain_allows( $slug, $domain ) ) {
			return $contents[ $slug ]['description'];
		}

		// Prefix definitions such as `_ga_` and `comment_author_` also cover
		// the concrete cookie names generated at runtime.
		//
		// LONGEST match wins, not the first one found. Several prefixes are
		// prefixes of each other — `wordpress_` and `wordpress_logged_in_`,
		// `comment_author_` and `comment_author_email_` — and returning the first
		// hit made the answer depend on the order of keys in a JSON file. It
		// happens to be right today because the specific keys sit above the
		// general ones; reorder the file, or add a key, and a WordPress session
		// cookie silently acquires the wrong description in a document a visitor
		// consents to. Correct by accident is worth converting into correct by
		// construction, especially when the failure is silent and legal.
		$best        = '';
		$best_length = -1;
		foreach ( $contents as $catalogue_slug => $entry ) {
			$prefix = self::catalogue_prefix( (string) $catalogue_slug );
			if ( '' === $prefix || 0 !== strpos( $slug, $prefix ) ) {
				continue;
			}
			if ( strlen( $prefix ) <= $best_length ) {
				continue;
			}
			if ( isset( $entry['description'] ) && is_string( $entry['description'] )
				&& self::domain_allows( (string) $catalogue_slug, $domain ) ) {
				$best        = $entry['description'];
				$best_length = strlen( $prefix );
			}
		}
		return $best;
	}

	/**
	 * Whether a catalogue entry may speak about a cookie found on this domain.
	 *
	 * Matching on the cookie NAME alone is wrong for the short, generic ones. A
	 * cookie called `fr` gets "Facebook advertising cookie" whatever set it — and
	 * `fr` is a perfectly ordinary name for a site's own language preference, so
	 * the declaration ends up asserting a transfer to Meta that never happened.
	 * That is not a cosmetic error: the cookie declaration is the document a
	 * visitor is asked to consent to, and a regulator to read.
	 *
	 * Only entries that are genuinely set BY a third party carry a `domains`
	 * constraint — `fr`, `ide`, `dsid`, `muid`, `lidc`, `ysc`, `vuid`, `ct0`.
	 * Names like `_ga` or `_fbp` are written first-party by a script on the
	 * site's own host, so constraining them by domain would reject every real
	 * one. Entries without the key are unaffected.
	 *
	 * An unknown domain is refused rather than assumed. For a legal statement,
	 * "we could not attribute this" must not resolve to "it belongs to Facebook";
	 * a site that wants the wording anyway has the
	 * faz_cookie_content_i18n_description filter.
	 *
	 * Constraints are read from the ENGLISH catalogue in every language: they are
	 * metadata about who sets the cookie, not translated prose, and duplicating
	 * them per locale would only create somewhere for them to drift.
	 *
	 * @param string $catalogue_slug Catalogue key being considered.
	 * @param string $domain         Domain the cookie was observed on, '' if unknown.
	 * @return bool
	 */
	private static function domain_allows( $catalogue_slug, $domain ) {
		$meta = self::load_catalogue( 'cookies/en.json' );
		if ( ! isset( $meta[ $catalogue_slug ]['domains'] ) || ! is_array( $meta[ $catalogue_slug ]['domains'] ) ) {
			return true;
		}
		$domain = strtolower( ltrim( trim( (string) $domain ), '.' ) );
		if ( '' === $domain ) {
			return false;
		}
		foreach ( $meta[ $catalogue_slug ]['domains'] as $allowed ) {
			$allowed = strtolower( ltrim( trim( (string) $allowed ), '.' ) );
			if ( '' === $allowed ) {
				continue;
			}
			// Suffix match on a label boundary, so `notfacebook.com` cannot pass
			// as `facebook.com` while `connect.facebook.com` still does.
			if ( $domain === $allowed || substr( $domain, -( strlen( $allowed ) + 1 ) ) === '.' . $allowed ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The prefix a catalogue key stands for, or '' when it is an exact name.
	 *
	 * Two spellings, and the split is deliberate. A key ending in `_` or `-` is
	 * its own prefix — `_ga_` matches `_ga_G-ABC123` — which covers every family
	 * whose members keep the separator. It cannot express Hotjar, whose cookies
	 * run `_hjFirstSeen`/`_hjSessionUser_1` with no separator after `_hj`, so
	 * that family carries the explicit trailing `*` already used for the same job
	 * in includes/data/known-providers.json.
	 *
	 * The `*` form is additive on purpose: no existing key contains one, and the
	 * `_`/`-` rule is untouched, so no key that matched before matches
	 * differently now. Making every key a prefix instead would have been the
	 * destructive option — `_ga` would then swallow `_gac_UA-…`, and `fr` would
	 * swallow anything beginning "fr".
	 *
	 * @param string $catalogue_slug Key as written in the JSON catalogue.
	 * @return string
	 */
	private static function catalogue_prefix( $catalogue_slug ) {
		if ( '' === $catalogue_slug ) {
			return '';
		}
		$last = substr( $catalogue_slug, -1 );
		if ( '*' === $last ) {
			return substr( $catalogue_slug, 0, -1 );
		}
		return in_array( $last, array( '_', '-' ), true ) ? $catalogue_slug : '';
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
		/**
		 * Filter the bundled retention period resolved for one cookie.
		 *
		 * Same contract as the description filter: a string substitutes the
		 * rendered period, '' stands the fallback down so the stored value is
		 * shown as typed. Useful for the free-form periods this parser
		 * deliberately declines ("up to 13 months", "until consent is withdrawn").
		 *
		 * @param string $duration Translated period, '' when not translatable.
		 * @param string $source   Stored duration exactly as it came in.
		 * @param string $lang     Requested language, before normalisation.
		 */
		return self::filter(
			'faz_cookie_content_i18n_duration',
			self::lookup_duration( $source, $lang ),
			array( (string) $source, (string) $lang )
		);
	}

	/**
	 * Catalogue lookup behind duration(), before filtering.
	 *
	 * @param string $source Stored duration.
	 * @param string $lang   Requested language.
	 * @return string
	 */
	private static function lookup_duration( $source, $lang ) {
		// The function_exists() guard is NOT about old WordPress versions —
		// wp_strip_all_tags() has shipped since 2.9, far below this plugin's floor.
		// It is what lets tests/unit/ load this class with no WordPress at all, which
		// is the only environment where the fallback actually runs. Removing it as
		// dead code takes six unit suites down with a fatal.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- see above: the fallback exists for the WordPress-less unit harness.
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
		if ( isset( $specials[ $source ], $units[ $lang ][ $specials[ $source ] ] )
			&& is_string( $units[ $lang ][ $specials[ $source ] ] ) ) {
			return self::match_leading_case( $raw, $units[ $lang ][ $specials[ $source ] ] );
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
	 * @return string one|few|many|other
	 */
	private static function plural_form( $amount, $lang ) {
		// French and Portuguese are the odd ones out among the Romance locales:
		// CLDR gives them `one` for an INTEGER PART of 0 or 1, not for the value
		// 1 alone. So "0 jour" and "1,5 an" are singular where Italian, Spanish
		// and German would pluralise. Handled before the generic 1.0 test because
		// that test cannot express "0 is singular too".
		if ( in_array( $lang, array( 'fr', 'pt', 'pt-br' ), true ) ) {
			$integer_part = (int) $amount;
			return ( 0 === $integer_part || 1 === $integer_part ) ? 'one' : 'other';
		}
		if ( 1.0 === $amount ) {
			return 'one';
		}
		if ( floor( $amount ) !== $amount ) {
			// Czech puts every fractional amount in its own class (CLDR `many`,
			// the genitive singular): "1,5 roku", never "1,5 let". Elsewhere the
			// fractional case falls through to `other`, which is also where CLDR
			// puts Polish decimals — see the pl branch below, where `other` is
			// therefore NOT the general integer plural.
			return 'cs' === $lang ? 'many' : 'other';
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
			if ( 'pl' === $lang ) {
				// Every remaining Polish INTEGER is `many` (5-9, 0, the teens, and
				// 21/31/… which are not `one`). `other` is reserved for decimals,
				// so falling through to it would now render "21 roku" instead of
				// "21 lat" — the distinction the catalogue carries.
				return 'many';
			}
		}
		return 'other';
	}

	/**
	 * Run one of this class's filters, if there is a WordPress to run it in.
	 *
	 * The guard mirrors the sanitize_title()/wp_strip_all_tags() ones above: this
	 * resolver is loaded directly by the standalone unit runners and by CLI
	 * utilities, where the plugin API is not bootstrapped. A non-string return is
	 * read as '' — the documented "leave the stored value alone" answer — so a
	 * filter that returns null or false disables the fallback instead of
	 * propagating a type error into the cookie table.
	 *
	 * @param string $hook  Filter name.
	 * @param string $value Resolved value.
	 * @param array  $args  Extra context passed to subscribers.
	 * @return string
	 */
	private static function filter( $hook, $value, array $args ) {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $value;
		}
		$filtered = apply_filters( $hook, $value, ...$args );
		return is_string( $filtered ) ? $filtered : '';
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
