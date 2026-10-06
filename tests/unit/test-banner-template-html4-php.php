<?php
/**
 * Standalone unit tests — the banner template survives an HTML4 rewrite.
 *
 * The template ships inside <script id="fazBannerTemplate" type="text/template">.
 * Page optimisers that rewrite the whole document through PHP's
 * DOMDocument::loadHTML() (WPSpeed's image optimiser, on by default) use
 * libxml's HTML4 parser, which ends a script at any `</` + letter and drops
 * the stray end tags: the banner arrived with no closing tags at all and
 * rendered 0 px tall. Frontend::escape_template_end_tags() writes `</` as `<\/`.
 *
 * Pinned here:
 *   1. The escaped template keeps every closing tag through a DOMDocument
 *      round trip.
 *   2. The unescaped one does not (the reason the escape exists — if libxml
 *      ever stops doing this, this check tells us the escape is optional).
 *   3. Undoing the escape the way script.js does gives back the original.
 *   4. No other character is touched.
 *   5. 1-3 also hold for the real box, banner and classic templates shipped
 *      in templates/6.2.0/template.json, in a UTF-8 page (with and without
 *      non-ASCII banner text), closing tag for closing tag.
 *
 * Run: php tests/unit/test-banner-template-html4-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	// Double for the `use FazCookie\Includes\Known_Providers` alias in
	// class-frontend.php — no autoload, no JSON read.
	class Known_Providers {
		public static function get_all() {
			return array();
		}
		public static function get_cookie_map() {
			return array();
		}
		public static function get_pattern_map() {
			return array();
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	$passed = 0;
	$failed = 0;
	function check( $cond, $label ) {
		global $passed, $failed;
		if ( $cond ) {
			$passed++;
			echo "  \033[32m✓\033[0m {$label}\n";
		} else {
			$failed++;
			echo "  \033[31m✗\033[0m {$label}\n";
		}
	}

	/**
	 * Round-trip a page through libxml's HTML parser, as the optimisers do.
	 *
	 * @param string $template Template markup.
	 * @param bool   $utf8     Declare the page UTF-8, as WordPress themes do;
	 *                         without it libxml reads the bytes as Latin-1.
	 * @param bool   $root     Serialise the root element instead of the whole
	 *                         document. Whole-document saveHTML() writes every
	 *                         non-ASCII character as an entity, even inside a
	 *                         script; serialising the root keeps UTF-8 bytes.
	 *                         Optimisers use either.
	 */
	function html4_round_trip( $template, $utf8 = false, $root = false ) {
		$page = '<!DOCTYPE html><html><head>' . ( $utf8 ? '<meta charset="utf-8">' : '' ) . '<title>t</title></head><body>'
			. '<script id="fazBannerTemplate" type="text/template">' . $template . '</script>'
			. '<p>after</p></body></html>';
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( $page );
		libxml_clear_errors();
		$out = $root ? $doc->saveHTML( $doc->documentElement ) : $doc->saveHTML();
		return preg_match( '#<script id="fazBannerTemplate" type="text/template">(.*?)</script>#s', $out, $m ) ? $m[1] : '';
	}

	/** What _fazReadBannerTemplate() in script.js does. */
	function js_unescape( $html ) {
		return str_replace( '<\/', '</', $html );
	}

	$template = '<div class="faz-consent-container"><div class="faz-consent-bar"><p class="faz-title">We value your privacy</p>'
		. '<div class="faz-notice-btn-wrapper"><button class="faz-btn faz-btn-reject">Reject All</button>'
		. '<button class="faz-btn faz-btn-accept">Accept All</button></div></div></div>'
		. '<ul><li><span>Cafe - 6 months</span></li></ul>';
	$closing  = substr_count( $template, '</' );

	echo "\nDOMDocument round trip\n";
	$escaped = Frontend::escape_template_end_tags( $template );
	$after   = html4_round_trip( $escaped );
	check( $closing > 0 && substr_count( $after, '<\/' ) === $closing, "escaped: all {$closing} closing tags survive" );
	check( js_unescape( $after ) === $template, 'escaped: unescaping in the browser gives back the original markup' );

	$raw_after = html4_round_trip( $template );
	check( substr_count( $raw_after, '</' ) < $closing, 'unescaped: libxml drops closing tags (why the escape exists)' );

	echo "\nReal templates (templates/6.2.0/template.json)\n";
	$json_path = dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/templates/6.2.0/template.json';
	$templates = json_decode( (string) file_get_contents( $json_path ), true ); // phpcs:ignore
	check( is_array( $templates ), 'template.json decodes' );
	$by_type = array();
	foreach ( (array) $templates as $entry ) {
		if ( isset( $entry['type'], $entry['html'] ) ) {
			$by_type[ $entry['type'] ] = $entry['html'];
		}
	}
	// Banner text as a translated site would have it: non-ASCII, so a page
	// read in the wrong charset would show up as a mismatch.
	$utf8_text = array(
		'[faz_notice_title]'       => 'La tua privacy è importante – «Café»',
		'[faz_notice_description]' => 'Usiamo i cookie per migliorare l’esperienza. Ελληνικά, 日本語.',
	);
	foreach ( array( 'box', 'banner', 'classic' ) as $type ) {
		$real = isset( $by_type[ $type ] ) ? $by_type[ $type ] : '';
		check( '' !== $real, "{$type}: template found" );
		foreach ( array( 'as shipped' => $real, 'with UTF-8 text' => strtr( $real, $utf8_text ) ) as $variant => $markup ) {
			$real_closing = substr_count( $markup, '</' );
			$escaped_real = Frontend::escape_template_end_tags( $markup );
			foreach ( array( 'document' => false, 'root element' => true ) as $how => $root ) {
				$real_after = html4_round_trip( $escaped_real, true, $root );
				$label      = "{$type} ({$variant}, saveHTML of the {$how})";
				check( $real_closing > 20 && substr_count( $real_after, '<\/' ) === $real_closing, "{$label}: all {$real_closing} closing tags survive, escaped" );
				if ( $root || $markup === $real ) {
					check( js_unescape( $real_after ) === $markup, "{$label}: unescaping reproduces the input byte for byte" );
				} else {
					// Non-ASCII came back as entities: the browser's DOMParser,
					// which _fazRenderBanner() feeds the template to, decodes them.
					check( html_entity_decode( js_unescape( $real_after ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) === html_entity_decode( $markup, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), "{$label}: unescaping reproduces the input once entities are decoded" );
				}
			}
			check( substr_count( html4_round_trip( $markup, true ), '</' ) < $real_closing, "{$type} ({$variant}): unescaped, libxml drops closing tags" );
		}
	}

	echo "\nThe escape itself\n";
	check( false === strpos( $escaped, '</' ), 'no bare `</` left in the template' );
	check( str_replace( '<\/', '</', $escaped ) === $template, 'only `</` is rewritten' );
	check( '' === Frontend::escape_template_end_tags( '' ), 'empty template stays empty' );
	check( 'a < b' === Frontend::escape_template_end_tags( 'a < b' ), 'a lone `<` is untouched' );

	echo "\n";
	if ( 0 === $failed ) {
		echo "ALL PASS ({$passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$failed}, passed: {$passed}\n";
	exit( 1 );
}
