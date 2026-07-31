<?php
/**
 * Gettext overrides for the generated Cookie Policy scaffolds.
 *
 * The legal-policy sources are Markdown files, which WordPress' normal POT
 * extractor cannot discover. The generated cookie-policy-gettext-catalog.php
 * file mirrors each English scaffold section as a literal _x() call. This
 * class combines those gettext results with the bundled localized Markdown:
 * untranslated or structurally invalid entries fall back section-by-section
 * to the reviewed template shipped with the plugin.
 *
 * @package FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes
 * @since   1.23.1
 */

namespace FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply safe gettext overrides to Cookie Policy template sections.
 */
class Template_Translations {

	/** Generated catalogue file shipped with the plugin. */
	const CATALOG_FILE = __DIR__ . '/cookie-policy-gettext-catalog.php';

	/**
	 * Replace bundled localized sections with matching gettext translations.
	 *
	 * Overrides are considered only when the policy language matches the locale
	 * currently used by WordPress' gettext layer. Each accepted section must
	 * retain the exact placeholder multiset from its English source; otherwise
	 * the reviewed bundled section is kept. Consequently a broken PO entry
	 * cannot remove controller contacts, the cookie inventory, retention
	 * information, or statutory-resource tokens.
	 *
	 * @param string $jurisdiction     Jurisdiction template key.
	 * @param string $lang             Effective policy language.
	 * @param string $bundled_scaffold Localized Markdown loaded from disk.
	 * @return string Effective localized Markdown.
	 */
	public static function apply( $jurisdiction, $lang, $bundled_scaffold ) {
		if ( ! is_string( $bundled_scaffold ) || '' === $bundled_scaffold ) {
			return (string) $bundled_scaffold;
		}
		if ( ! self::locale_matches_policy_lang( $lang ) || ! function_exists( '_x' ) ) {
			return $bundled_scaffold;
		}
		if ( ! is_readable( self::CATALOG_FILE ) ) {
			return $bundled_scaffold;
		}

		$source_path = Generator::resolve_template_path( $jurisdiction, 'en' );
		if ( ! is_string( $source_path ) || ! is_readable( $source_path ) ) {
			return $bundled_scaffold;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin-shipped canonical Markdown.
		$source_scaffold = (string) file_get_contents( $source_path );
		$source_sections = self::split_sections( $source_scaffold );
		$local_sections  = self::split_sections( $bundled_scaffold );
		$catalogue       = self::catalogue_for_current_locale();
		$translated      = isset( $catalogue[ $jurisdiction ] ) && is_array( $catalogue[ $jurisdiction ] )
			? $catalogue[ $jurisdiction ]
			: array();

		$count = count( $source_sections );
		if ( 0 === $count || count( $translated ) !== $count ) {
			return $bundled_scaffold;
		}

		// Some older bundled locales predate sections later added to the English
		// CCPA/LGPD scaffolds. They cannot be combined positionally, but a fully
		// translated gettext policy is still safe to use. Partial catalogues keep
		// the intact bundled document until every source section is translated.
		if ( count( $local_sections ) !== $count ) {
			$complete = array();
			for ( $index = 0; $index < $count; $index++ ) {
				$candidate = is_string( $translated[ $index ] ) ? $translated[ $index ] : '';
				if (
					'' === trim( $candidate )
					|| self::same_section( $candidate, $source_sections[ $index ] )
					|| ! self::same_placeholders( $source_sections[ $index ], $candidate )
				) {
					return $bundled_scaffold;
				}
				$complete[] = trim( $candidate );
			}
			return implode( "\n\n", $complete ) . "\n";
		}

		$effective = $local_sections;
		$changed   = false;
		for ( $index = 0; $index < $count; $index++ ) {
			$candidate = is_string( $translated[ $index ] ) ? $translated[ $index ] : '';
			$source    = $source_sections[ $index ];

			// gettext returns the msgid when no translation is available. Keep
			// the already-localized Markdown section in that case.
			if ( '' === trim( $candidate ) || self::same_section( $candidate, $source ) ) {
				continue;
			}
			if ( ! self::same_placeholders( $source, $candidate ) ) {
				continue;
			}

			$effective[ $index ] = trim( $candidate );
			$changed             = true;
		}

		if ( ! $changed ) {
			return $bundled_scaffold;
		}
		return implode( "\n\n", array_map( 'trim', $effective ) ) . "\n";
	}

	/**
	 * Load the generated gettext catalogue for the active locale.
	 *
	 * A locale-keyed cache remains correct when WPML/Polylang or core
	 * switch_to_locale() changes language during the same request.
	 *
	 * @return array<string,array<int,string>>
	 */
	private static function catalogue_for_current_locale() {
		static $catalogues = array();
		$locale            = self::current_locale();
		if ( isset( $catalogues[ $locale ] ) ) {
			return $catalogues[ $locale ];
		}

		$loaded = require self::CATALOG_FILE;

		$catalogues[ $locale ] = is_array( $loaded ) ? $loaded : array();
		return $catalogues[ $locale ];
	}

	/**
	 * Split one scaffold into its introduction plus level-two sections.
	 *
	 * @param string $scaffold Markdown scaffold.
	 * @return string[]
	 */
	public static function split_sections( $scaffold ) {
		if ( ! is_string( $scaffold ) || '' === trim( $scaffold ) ) {
			return array();
		}
		$sections = preg_split( '/(?=^##[ \t]+)/m', str_replace( array( "\r\n", "\r" ), "\n", $scaffold ) );
		if ( ! is_array( $sections ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map( 'trim', $sections ),
				static function ( $section ) {
					return '' !== $section;
				}
			)
		);
	}

	/**
	 * Compare sections while ignoring only surrounding whitespace.
	 *
	 * @param string $left  First section.
	 * @param string $right Second section.
	 * @return bool
	 */
	private static function same_section( $left, $right ) {
		return trim( str_replace( array( "\r\n", "\r" ), "\n", $left ) )
			=== trim( str_replace( array( "\r\n", "\r" ), "\n", $right ) );
	}

	/**
	 * Require a translated section to preserve every placeholder occurrence.
	 *
	 * @param string $source     Canonical English section.
	 * @param string $translated Candidate gettext translation.
	 * @return bool
	 */
	private static function same_placeholders( $source, $translated ) {
		return self::placeholder_tokens( $source ) === self::placeholder_tokens( $translated );
	}

	/**
	 * Extract a stable sorted placeholder multiset.
	 *
	 * @param string $section Markdown section.
	 * @return string[]
	 */
	private static function placeholder_tokens( $section ) {
		$matches = array();
		preg_match_all( '/\{\{[A-Z][A-Z0-9_]*\}\}/', (string) $section, $matches );
		$tokens = isset( $matches[0] ) && is_array( $matches[0] ) ? $matches[0] : array();
		sort( $tokens, SORT_STRING );
		return $tokens;
	}

	/**
	 * Whether the current gettext locale represents the requested policy lang.
	 *
	 * @param string $lang Effective policy language.
	 * @return bool
	 */
	private static function locale_matches_policy_lang( $lang ) {
		return self::locale_to_policy_lang( self::current_locale() ) === self::normalize_policy_lang( $lang );
	}

	/** Return the locale currently used by WordPress translations. */
	private static function current_locale() {
		if ( function_exists( 'determine_locale' ) ) {
			return (string) determine_locale();
		}
		if ( function_exists( 'get_locale' ) ) {
			return (string) get_locale();
		}
		return 'en_US';
	}

	/**
	 * Convert a WordPress locale to one of the policy language keys.
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	private static function locale_to_policy_lang( $locale ) {
		$locale = str_replace( '-', '_', (string) $locale );
		if ( 'pt_BR' === $locale ) {
			return 'pt-BR';
		}
		$parts = explode( '_', $locale, 2 );
		return strtolower( $parts[0] ?? 'en' );
	}

	/**
	 * Normalize a policy language key.
	 *
	 * @param string $lang Policy language.
	 * @return string
	 */
	private static function normalize_policy_lang( $lang ) {
		$lang = str_replace( '_', '-', (string) $lang );
		if ( false !== strpos( $lang, '-' ) ) {
			$parts = explode( '-', $lang, 2 );
			return strtolower( $parts[0] ) . '-' . strtoupper( $parts[1] );
		}
		return strtolower( $lang );
	}
}
