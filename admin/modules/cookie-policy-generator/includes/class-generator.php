<?php
/**
 * Class Generator file — template loading + placeholder substitution.
 *
 * Spec: specs/002-cookie-policy-generator/spec.md FR-01 + FR-03
 *
 * Pure-function shape: given a (jurisdiction, lang, data) triple, returns
 * the rendered HTML. No side effects, no global state — easy to unit-test.
 *
 * Constitution XI wp.org Plugin Check: all output escaped via wp_kses_post()
 * at the shortcode boundary (see Renderer); this class produces raw HTML
 * from a markdown scaffold.
 *
 * @package FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes
 * @since   1.16.0
 */

namespace FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cookie policy template generator.
 *
 * @class    Generator
 * @since    1.16.0
 */
class Generator {

	/**
	 * Supported jurisdictions. The key matches the geo-routing ruleset id
	 * (spec 001 catalog) when possible — this allows the FAZ_PRIVACY_LAW
	 * shortcode attribute to be the same string used by the banner picker.
	 *
	 * @var string[]
	 */
	const JURISDICTIONS = array(
		'gdpr-strict',
		'ccpa-california',
		'lgpd-brazil',
	);

	/**
	 * Supported template languages, in priority order for the fallback chain.
	 * Per-jurisdiction native language is checked first by the Renderer.
	 *
	 * @var string[]
	 */
	const LANGUAGES = array( 'en', 'it', 'fr', 'de', 'es', 'pt-BR', 'bg', 'cs' );

	/**
	 * Native language per jurisdiction (statutory bias):
	 *   - gdpr-strict     → en (EU lingua franca for regulatory docs)
	 *   - ccpa-california → en + es (CA bilingual mandate per §1798.130)
	 *   - lgpd-brazil     → pt-BR
	 *
	 * @var array<string,string>
	 */
	const NATIVE_LANG = array(
		'gdpr-strict'     => 'en',
		'ccpa-california' => 'en',
		'lgpd-brazil'     => 'pt-BR',
	);

	/**
	 * Resolve the template file path for a jurisdiction+lang pair.
	 *
	 * Implements the FR-03 fallback chain:
	 *   1. requested lang
	 *   2. native lang of jurisdiction (if different)
	 *   3. en (universal fallback)
	 *
	 * @param string $jurisdiction Ruleset id (gdpr-strict / ccpa-california / lgpd-brazil).
	 * @param string $lang         BCP-47 (en / it / fr / de / es / pt-BR / bg / cs).
	 * @return string|null Absolute path to .md file, or null if no template found.
	 */
	public static function resolve_template_path( $jurisdiction, $lang ) {
		if ( ! in_array( $jurisdiction, self::JURISDICTIONS, true ) ) {
			return null;
		}
		// Defense-in-depth path-traversal hardening: even though only callers
		// inside the module reach this method, an external integrator could
		// theoretically pass a `lang` like "../../../wp-config" via a filter
		// or future REST endpoint. Validate against the whitelist before
		// composing any file path.
		$lang = (string) $lang;
		if ( ! in_array( $lang, self::LANGUAGES, true ) ) {
			$lang = self::NATIVE_LANG[ $jurisdiction ] ?? 'en';
		}
		$dir = self::templates_dir() . '/' . $jurisdiction;
		$candidates = array( $lang );
		// Native fallback if different from requested.
		$native = self::NATIVE_LANG[ $jurisdiction ] ?? 'en';
		if ( $native !== $lang ) {
			$candidates[] = $native;
		}
		// Universal fallback.
		if ( 'en' !== $lang && ! in_array( 'en', $candidates, true ) ) {
			$candidates[] = 'en';
		}
		foreach ( $candidates as $candidate ) {
			$file = $dir . '/' . $candidate . '.md';
			if ( file_exists( $file ) && is_readable( $file ) ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * Where templates live on disk.
	 *
	 * @return string Absolute path (no trailing slash).
	 */
	public static function templates_dir() {
		return dirname( __DIR__ ) . '/templates';
	}

	/**
	 * Substitute {{PLACEHOLDER}} tokens in $template with values from $data.
	 *
	 * Behaviour:
	 *   - Known tokens: replaced with the string in $data (after coercion).
	 *   - Unknown tokens: replaced with empty string. They are NOT left in
	 *     the output (a leftover "{{UNKNOWN}}" would shock the visitor).
	 *   - HTML-valued tokens (COOKIE_CATEGORIES, THIRD_PARTY_SERVICES):
	 *     passed through unchanged — the renderer wraps the whole template
	 *     in wp_kses_post() at the output boundary, so injected HTML is
	 *     filtered there. The substitution itself does not escape.
	 *   - Scalar-valued tokens (COMPANY_NAME, addresses): callers MUST
	 *     pre-escape with esc_html() before passing in $data; this method
	 *     does NOT re-escape (some templates intentionally contain
	 *     entity-encoded characters that re-escaping would double-encode).
	 *
	 * @param string              $template Markdown body with {{TOKENS}}.
	 * @param array<string,mixed> $data     Token name → value map.
	 * @return string Substituted body (still markdown).
	 */
	public static function substitute( $template, array $data ) {
		if ( ! is_string( $template ) || '' === $template ) {
			return '';
		}
		// Match {{NAME}} where NAME is [A-Z][A-Z0-9_]*. Capture name in group 1.
		return (string) preg_replace_callback(
			'/\{\{([A-Z][A-Z0-9_]*)\}\}/',
			function ( $matches ) use ( $data ) {
				$key = $matches[1];
				if ( array_key_exists( $key, $data ) ) {
					$val = $data[ $key ];
					if ( is_scalar( $val ) ) {
						return (string) $val;
					}
					if ( is_array( $val ) ) {
						// Allow array values to render as comma-separated.
						return implode( ', ', array_map( 'strval', array_filter( $val, 'is_scalar' ) ) );
					}
					return '';
				}
				// Unknown token → empty (do not leak {{...}} to output).
				return '';
			},
			$template
		);
	}

	/**
	 * Convert markdown body to HTML, very lean.
	 *
	 * We deliberately do NOT pull in a full Markdown lib (no Parsedown
	 * dependency, no composer add). Cookie-policy scaffolds use only a
	 * subset: ATX headings, paragraphs, bulleted lists, links, bold.
	 * That subset is < 60 lines of regex.
	 *
	 * @param string $markdown Markdown body.
	 * @return string HTML (NOT yet sanitized — caller must wp_kses_post).
	 */
	public static function markdown_to_html( $markdown ) {
		if ( ! is_string( $markdown ) || '' === $markdown ) {
			return '';
		}
		// Normalize line endings.
		$md = str_replace( array( "\r\n", "\r" ), "\n", $markdown );

		// Convert lines to a working buffer, line-by-line.
		$lines = explode( "\n", $md );
		$out = array();
		$in_ul = false;
		$in_para = false;

		$flush_para = function () use ( &$out, &$in_para ) {
			if ( $in_para ) {
				$out[] = '</p>';
				$in_para = false;
			}
		};
		$flush_ul = function () use ( &$out, &$in_ul ) {
			if ( $in_ul ) {
				$out[] = '</ul>';
				$in_ul = false;
			}
		};

		foreach ( $lines as $line ) {
			$line = rtrim( $line );

			// Empty line: terminate paragraph / list.
			if ( '' === trim( $line ) ) {
				$flush_para();
				$flush_ul();
				continue;
			}

			// ATX heading? (# title, ## title, ### title)
			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $line, $m ) ) {
				$flush_para();
				$flush_ul();
				$level = strlen( $m[1] );
				$out[] = sprintf( '<h%d>%s</h%d>', $level, self::inline_md( trim( $m[2] ) ), $level );
				continue;
			}

			// Bulleted list item? (- text or * text)
			if ( preg_match( '/^[-*]\s+(.+)$/', $line, $m ) ) {
				$flush_para();
				if ( ! $in_ul ) {
					$out[] = '<ul>';
					$in_ul = true;
				}
				$out[] = '<li>' . self::inline_md( $m[1] ) . '</li>';
				continue;
			}

			// Raw HTML block (already-rendered embed like {{COOKIE_CATEGORIES}})?
			// If the line starts with < and the previous line was also a tag, pass through.
			if ( preg_match( '/^\s*<(table|tbody|thead|tr|td|th|dl|dt|dd|section|article|div|ul|ol|li|p|h[1-6])\b/i', $line ) ) {
				$flush_para();
				$flush_ul();
				$out[] = $line;
				continue;
			}

			// Regular text → wrap in <p>.
			if ( $in_ul ) {
				$flush_ul();
			}
			if ( ! $in_para ) {
				$out[] = '<p>';
				$in_para = true;
			}
			$out[] = self::inline_md( $line );
		}

		$flush_para();
		$flush_ul();

		return implode( "\n", $out );
	}

	/**
	 * Inline markdown: links + bold + italic. Bare-bones.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function inline_md( $text ) {
		// Links [text](url) — only http(s) urls, no javascript: shenanigans.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '" rel="noopener">' . self::esc_inline_once( $m[1] ) . '</a>';
			},
			$text
		);
		// Bold/italic/inline-code: route the captured text through
		// esc_inline_once() so HTML special chars (<, >, &) emitted by a filter
		// or by a {{PLACEHOLDER}} expansion can't bypass the bold/italic
		// wrapping and inject markup directly. Defence-in-depth alongside the
		// final wp_kses_post() pass at the Renderer boundary.
		$text = preg_replace_callback( '/\*\*([^*]+)\*\*/', function ( $m ) {
			return '<strong>' . self::esc_inline_once( $m[1] ) . '</strong>';
		}, $text );
		$text = preg_replace_callback( '/(?<!\*)\*([^*]+)\*(?!\*)/', function ( $m ) {
			return '<em>' . self::esc_inline_once( $m[1] ) . '</em>';
		}, $text );
		$text = preg_replace_callback( '/`([^`]+)`/', function ( $m ) {
			return '<code>' . self::esc_inline_once( $m[1] ) . '</code>';
		}, $text );
		return $text;
	}

	/**
	 * Escape inline markup-span text WITHOUT double-encoding.
	 *
	 * substitute()'s documented contract is that scalar {{PLACEHOLDER}} values
	 * arrive already entity-escaped by the caller. esc_html() would re-encode
	 * those (`&amp;` → `&amp;amp;`) whenever a pre-escaped value lands inside a
	 * bold/italic/code/link span, producing visibly double-encoded entities in
	 * the rendered policy. htmlspecialchars() with $double_encode = false still
	 * escapes raw `< > & " '` (so the defence-in-depth against injected markup
	 * is preserved) but leaves already-encoded entities untouched, so the
	 * output is single-encoded regardless of whether the value was pre-escaped.
	 *
	 * @param string $text Captured span text.
	 * @return string
	 */
	private static function esc_inline_once( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}

	/**
	 * Token names that are display-only and MUST be excluded from the
	 * policy version hash. The hash is meant to signal MATERIAL changes
	 * (template, cookie inventory, saved settings) so consent-mechanisms
	 * downstream can re-prompt visitors. Including calendar-volatile
	 * values like LAST_UPDATED_DATE (computed from `current_time()`)
	 * would drift the hash every day even when nothing changed, producing
	 * spurious re-prompt signals.
	 *
	 * @var string[]
	 */
	const HASH_VOLATILE_KEYS = array( 'LAST_UPDATED_DATE' );

	/**
	 * Token names whose values are pre-rendered HTML and MUST be protected
	 * from `markdown_to_html()`'s line-based parser (it only preserves a
	 * narrow allowlist of opening tags; closing tags `</dt>`, `</dd>` and
	 * inline tags `<small>`, `<strong>` would otherwise be wrapped in
	 * extra `<p>` and corrupted). The renderer's two-pass substitution
	 * (sentinel before markdown, real HTML after) gates on this list.
	 *
	 * @var string[]
	 */
	const HTML_TOKENS = array( 'COOKIE_CATEGORIES', 'THIRD_PARTY_SERVICES' );

	/**
	 * Single-line sentinel used by Renderer to protect HTML-valued tokens
	 * across the markdown_to_html() pass. Format chosen so it cannot be
	 * mistaken for a markdown heading/list/raw-HTML opening tag (it is
	 * plain ASCII with no markdown-significant prefix).
	 *
	 * @param string $token_name e.g. 'COOKIE_CATEGORIES'.
	 * @return string
	 */
	public static function html_token_sentinel( $token_name ) {
		return '__FAZ_HTML_TOKEN_' . preg_replace( '/[^A-Z0-9_]/', '', strtoupper( (string) $token_name ) ) . '__';
	}

	/**
	 * Compute the sha1 of a (template_path, data) tuple for FR-07 versioning.
	 *
	 * Volatile display-only keys (see HASH_VOLATILE_KEYS) are stripped from
	 * $data before hashing so the version reflects material change only.
	 *
	 * @param string $template_path Absolute path.
	 * @param array  $data          Substitution data.
	 * @return string 12-char hex.
	 */
	public static function policy_version_hash( $template_path, array $data ) {
		$template_sha = $template_path && file_exists( $template_path ) ? sha1_file( $template_path ) : sha1( 'no-template' );
		$stable_data  = array_diff_key( $data, array_flip( self::HASH_VOLATILE_KEYS ) );
		$data_sha     = sha1( wp_json_encode( $stable_data ) ?: '' );
		return substr( $template_sha, 0, 6 ) . '.' . substr( $data_sha, 0, 6 );
	}
}
