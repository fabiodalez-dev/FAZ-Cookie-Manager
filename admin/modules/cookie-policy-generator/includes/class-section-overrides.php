<?php
/**
 * Administrator-authored overrides for generated Cookie Policy sections.
 *
 * The bundled Markdown scaffolds are reviewed starting points, not the final
 * word: the operator is the one who knows their own processing, and — as issue
 * reports from Czech and Slovak users showed — the one who may need the policy
 * in a language the plugin does not ship a template for. Until now the only
 * way to change that text was to edit plugin files, which an update overwrites.
 *
 * An override replaces one section of the effective scaffold. It is applied
 * AFTER the gettext layer, so an explicit editorial decision beats both the
 * bundled template and any translation, and BEFORE placeholder substitution,
 * so `{{COMPANY_NAME}}` and friends keep working inside custom text and pass
 * through exactly the same escaping as shipped text. There is no second
 * sanitisation path.
 *
 * Overrides are keyed by jurisdiction AND language. A flat key would mean
 * editing the English GDPR policy silently rewrote the Italian POPIA one.
 *
 * Each entry stores the first line of the section it was written against. If a
 * later plugin version reorders or rewords the scaffold, the anchor no longer
 * matches and the override deactivates, falling back to the shipped text. For
 * a legal document, reverting to the reviewed original is the only acceptable
 * failure mode — landing the operator's wording on the wrong section is not.
 *
 * @package FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes
 * @since   1.25.0
 */

namespace FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply administrator-authored section overrides to a policy scaffold.
 */
class Section_Overrides {

	/**
	 * Hard cap on stored sections per (jurisdiction, language) pair. No shipped
	 * scaffold comes close; the cap exists so a malformed payload cannot grow
	 * the option without bound.
	 */
	const MAX_SECTIONS = 30;

	/** Maximum length of a single override body, in characters. */
	const MAX_TEXT = 10000;

	/** Maximum length of a stored anchor line. */
	const MAX_ANCHOR = 200;

	/**
	 * Replace scaffold sections with the operator's own text where one is set.
	 *
	 * Returns the ORIGINAL string, not a re-joined equivalent, when nothing
	 * applies. Re-joining would renormalise whitespace, and the effective
	 * scaffold is hashed into `data-faz-policy-version` — a cosmetic
	 * difference would bump the policy version on every install that has no
	 * overrides at all, which reads downstream as a material change.
	 *
	 * @param string $jurisdiction Jurisdiction key.
	 * @param string $lang         Policy language key.
	 * @param string $scaffold     Effective Markdown scaffold.
	 * @param array  $settings     Cookie Policy settings.
	 * @return string
	 */
	public static function apply( $jurisdiction, $lang, $scaffold, $settings ) {
		$entries = self::entries_for( $jurisdiction, $lang, $settings );
		if ( empty( $entries ) || ! is_string( $scaffold ) || '' === trim( $scaffold ) ) {
			return $scaffold;
		}

		$sections = Template_Translations::split_sections( $scaffold );
		if ( empty( $sections ) ) {
			return $scaffold;
		}

		$applied = false;
		foreach ( $entries as $index => $entry ) {
			$i = (int) $index;
			if ( $i < 0 || $i >= count( $sections ) ) {
				continue;
			}
			$text = isset( $entry['text'] ) ? trim( (string) $entry['text'] ) : '';
			if ( '' === $text ) {
				continue;
			}
			$anchor = isset( $entry['anchor'] ) ? trim( (string) $entry['anchor'] ) : '';
			if ( '' === $anchor || self::first_line( $sections[ $i ] ) !== $anchor ) {
				// The scaffold moved under this override — keep the shipped text.
				continue;
			}
			$sections[ $i ] = $text;
			$applied        = true;
		}

		if ( ! $applied ) {
			return $scaffold;
		}

		return implode( "\n\n", array_map( 'trim', $sections ) ) . "\n";
	}

	/**
	 * The stored overrides for one jurisdiction/language pair.
	 *
	 * @param string $jurisdiction Jurisdiction key.
	 * @param string $lang         Policy language key.
	 * @param array  $settings     Cookie Policy settings.
	 * @return array<int|string,array>
	 */
	public static function entries_for( $jurisdiction, $lang, $settings ) {
		if ( ! is_array( $settings ) || empty( $settings['section_overrides'] ) || ! is_array( $settings['section_overrides'] ) ) {
			return array();
		}
		$all = $settings['section_overrides'];
		$j   = (string) $jurisdiction;
		if ( ! isset( $all[ $j ] ) || ! is_array( $all[ $j ] ) ) {
			return array();
		}
		$l = (string) $lang;
		return isset( $all[ $j ][ $l ] ) && is_array( $all[ $j ][ $l ] ) ? $all[ $j ][ $l ] : array();
	}

	/**
	 * Sections of the effective scaffold, with any override already applied,
	 * for the admin editor to render.
	 *
	 * @param string $jurisdiction Jurisdiction key.
	 * @param string $lang         Policy language key.
	 * @param string $scaffold     Effective Markdown scaffold (post-gettext).
	 * @param array  $settings     Cookie Policy settings.
	 * @return array<int,array{index:int,anchor:string,shipped:string,override:string,active:bool}>
	 */
	public static function describe( $jurisdiction, $lang, $scaffold, $settings ) {
		$sections = Template_Translations::split_sections( $scaffold );
		$entries  = self::entries_for( $jurisdiction, $lang, $settings );
		$out      = array();
		foreach ( $sections as $i => $section ) {
			$stored        = isset( $entries[ $i ] ) ? $entries[ $i ] : ( isset( $entries[ (string) $i ] ) ? $entries[ (string) $i ] : array() );
			$override      = is_array( $stored ) && isset( $stored['text'] ) ? (string) $stored['text'] : '';
			$anchor        = self::first_line( $section );
			$stored_anchor = is_array( $stored ) && isset( $stored['anchor'] ) ? trim( (string) $stored['anchor'] ) : '';
			$out[]         = array(
				'index'    => (int) $i,
				'anchor'   => $anchor,
				'shipped'  => (string) $section,
				'override' => $override,
				'active'   => '' !== trim( $override ) && '' !== $stored_anchor && $stored_anchor === $anchor,
			);
		}
		return $out;
	}

	/**
	 * Overridden sections whose placeholder set differs from the shipped one.
	 *
	 * Advisory only. An override is the operator's deliberate document, so a
	 * dropped `{{COMPANY_NAME}}` is a choice, not an error — but it is worth
	 * saying out loud, because the omission is invisible once rendered.
	 *
	 * @param string $jurisdiction Jurisdiction key.
	 * @param string $lang         Policy language key.
	 * @param string $scaffold     Effective Markdown scaffold.
	 * @param array  $settings     Cookie Policy settings.
	 * @return string[]
	 */
	public static function placeholder_warnings( $jurisdiction, $lang, $scaffold, $settings ) {
		$warnings = array();
		foreach ( self::describe( $jurisdiction, $lang, $scaffold, $settings ) as $row ) {
			if ( '' === $row['override'] || empty( $row['active'] ) ) {
				continue;
			}
			$shipped_tokens  = self::tokens( $row['shipped'] );
			$override_tokens = self::tokens( $row['override'] );
			$missing         = array_diff( $shipped_tokens, $override_tokens );
			if ( empty( $missing ) ) {
				continue;
			}
			$warnings[] = sprintf(
				/* translators: 1: section heading, 2: comma-separated placeholder list. */
				__( 'Your text for "%1$s" no longer uses %2$s, so that information will not appear in the policy.', 'faz-cookie-manager' ),
				$row['anchor'],
				implode( ', ', $missing )
			);
		}
		return $warnings;
	}

	/**
	 * Placeholder tokens present in a chunk of Markdown, sorted and unique.
	 *
	 * @param string $text Markdown.
	 * @return string[]
	 */
	private static function tokens( $text ) {
		$matches = array();
		preg_match_all( '/\{\{[A-Z][A-Z0-9_]*\}\}/', (string) $text, $matches );
		$tokens = isset( $matches[0] ) && is_array( $matches[0] ) ? array_unique( $matches[0] ) : array();
		sort( $tokens, SORT_STRING );
		return $tokens;
	}

	/**
	 * First non-empty line of a section, used as the drift anchor.
	 *
	 * @param string $section Markdown section.
	 * @return string
	 */
	private static function first_line( $section ) {
		$line = strtok( trim( (string) $section ), "\n" );
		return false === $line ? '' : trim( $line );
	}

	/**
	 * Normalise a submitted override map to the stored shape.
	 *
	 * Unknown jurisdictions and languages are dropped rather than coerced: a
	 * typo must not create a bucket that silently never renders. Empty bodies
	 * are dropped too, so clearing a box removes the override instead of
	 * storing an empty string that would blank the section.
	 *
	 * @param mixed    $raw           Submitted value.
	 * @param string[] $jurisdictions Allowed jurisdiction keys.
	 * @param string[] $languages     Allowed language keys.
	 * @param callable $clip          Multiline-safe length clipper.
	 * @return array
	 */
	public static function sanitize( $raw, array $jurisdictions, array $languages, $clip ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $jurisdiction => $langs ) {
			if ( ! is_string( $jurisdiction ) || ! in_array( $jurisdiction, $jurisdictions, true ) || ! is_array( $langs ) ) {
				continue;
			}
			foreach ( $langs as $lang => $sections ) {
				if ( ! is_string( $lang ) || ! in_array( $lang, $languages, true ) || ! is_array( $sections ) ) {
					continue;
				}
				$kept = array();
				foreach ( $sections as $index => $entry ) {
					if ( count( $kept ) >= self::MAX_SECTIONS ) {
						break;
					}
					if ( ! is_array( $entry ) || ! preg_match( '/^\d{1,2}$/', (string) $index ) ) {
						continue;
					}
					$text = isset( $entry['text'] ) ? call_user_func( $clip, (string) $entry['text'], self::MAX_TEXT ) : '';
					if ( '' === trim( (string) $text ) ) {
						continue;
					}
					$anchor = isset( $entry['anchor'] ) ? call_user_func( $clip, (string) $entry['anchor'], self::MAX_ANCHOR ) : '';
					if ( '' === trim( (string) $anchor ) ) {
						// An anchor is the safety contract: without it an old
						// override could silently land on a different legal
						// section after a scaffold update.
						continue;
					}
					$kept[ (string) (int) $index ] = array(
						'anchor' => trim( (string) $anchor ),
						'text'   => (string) $text,
					);
				}
				if ( ! empty( $kept ) ) {
					$out[ $jurisdiction ][ $lang ] = $kept;
				}
			}
		}
		return $out;
	}
}
