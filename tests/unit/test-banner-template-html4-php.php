<?php
/**
 * Standalone unit tests — the banner template survives an HTML4 rewrite.
 *
 * The template ships as <template id="fazBannerTemplate">. It used to be a
 * <script type="text/template"> with every `</` written as `<\/`, because
 * page optimisers that rewrite the whole document through PHP's
 * DOMDocument::loadHTML() (WPSpeed's image optimiser, on by default) use
 * libxml's HTML4 parser, which ends a script at any `</` + letter and drops
 * the stray end tags. That escape is what a translation proxy (GTranslate)
 * then mangled: it parses the script body as HTML, so `<\/div>` became
 * translated text and the consent bar was nested inside the revisit button.
 * A <template> has real closing tags, and libxml keeps an unknown element's
 * children, so no escape is needed.
 *
 * Pinned here:
 *   1. In a <template>, every closing tag of the real box, banner, classic,
 *      sidebar and popup templates survives a DOMDocument round trip, in a
 *      UTF-8 page, with and without non-ASCII text, through both
 *      saveHTML() forms the optimisers use.
 *   2. Nothing leaks out: the element after the template is still after it,
 *      outside it.
 *   3. The control: the same markup in the old unescaped script form loses
 *      its closing tags on libxml < 2.14 (reported, not failed, on 2.14+,
 *      whose new HTML parser keeps them).
 *   4. banner_html() prints the <template> form and no `<\/` escape.
 *
 * That the round-tripped markup builds the same DOM in a browser as the
 * original is pinned in tests/unit/js/banner-late-load-template.test.mjs.
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

	$observed = 0;
	/**
	 * The control: does THIS libxml drop the closing tags of a script body?
	 *
	 * libxml up to 2.13 (what most hosts run: Ubuntu 24.04 ships 2.9.14) ends
	 * a script at any `</` + letter and drops the stray end tags — the reason
	 * the template is not a script. libxml 2.14 replaced its HTML parser and
	 * keeps them, so there the control is reported, not failed.
	 *
	 * @param bool   $dropped Whether the script round trip lost closing tags.
	 * @param string $label   What was round-tripped.
	 */
	function observe_script_form( $dropped, $label ) {
		global $observed;
		if ( $dropped ) {
			check( true, $label );
			return;
		}
		++$observed;
		echo "  \033[33m~\033[0m {$label} — not on libxml " . LIBXML_DOTTED_VERSION . " (keeps them)\n";
	}

	/**
	 * Round-trip a page through libxml's HTML parser, as the optimisers do.
	 *
	 * @param string $open     Opening tag of the carrier element.
	 * @param string $close    Its closing tag.
	 * @param string $template Template markup.
	 * @param bool   $root     Serialise the root element instead of the whole
	 *                         document; optimisers use either.
	 * @return array{0:string,1:bool} Carrier body after the round trip, and
	 *                                whether the following element stayed
	 *                                outside and after it.
	 */
	function html4_round_trip( $open, $close, $template, $root = false ) {
		$page = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>t</title></head><body>'
			. $open . $template . $close
			. '<p id="after">after</p></body></html>';
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( $page );
		libxml_clear_errors();
		$out  = $root ? $doc->saveHTML( $doc->documentElement ) : $doc->saveHTML();
		$body = preg_match( '#' . preg_quote( $open, '#' ) . '(.*?)' . preg_quote( $close, '#' ) . '#s', $out, $m ) ? $m[1] : '';
		$at   = strpos( $out, '<p id="after">' );
		$end  = strpos( $out, $close, (int) strpos( $out, $open ) );
		return array( $body, false !== $at && false !== $end && $at > $end );
	}

	/** Closing tags by name. */
	function closing_tags( $html ) {
		preg_match_all( '#</([a-z][a-z0-9]*)\s*>#i', $html, $m );
		$c = array_count_values( array_map( 'strtolower', $m[1] ) );
		ksort( $c );
		return $c;
	}

	/** Every closing tag of $before is still in $after (libxml may add some). */
	function keeps_closing_tags( $before, $after ) {
		$a = closing_tags( $before );
		$b = closing_tags( $after );
		foreach ( $a as $tag => $n ) {
			if ( ! isset( $b[ $tag ] ) || $b[ $tag ] < $n ) {
				return false;
			}
		}
		return array_sum( $a ) > 0;
	}

	echo "\nReal templates (templates/6.2.0/template.json) in a <template>\n";
	$json_path = dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/templates/6.2.0/template.json';
	$templates = json_decode( (string) file_get_contents( $json_path ), true ); // phpcs:ignore
	check( is_array( $templates ) && count( $templates ) >= 6, 'template.json decodes' );
	// Banner text as a translated site would have it.
	$utf8_text = array(
		'[faz_notice_title]'       => 'La tua privacy è importante – «Café»',
		'[faz_notice_description]' => 'Usiamo i cookie per migliorare l’esperienza. Ελληνικά, 日本語, Příliš žluťoučký kůň.',
	);
	foreach ( (array) $templates as $entry ) {
		if ( ! isset( $entry['type'], $entry['html'] ) ) {
			continue;
		}
		$type = $entry['type'];
		foreach ( array( 'as shipped' => $entry['html'], 'with UTF-8 text' => strtr( $entry['html'], $utf8_text ) ) as $variant => $markup ) {
			foreach ( array( 'document' => false, 'root element' => true ) as $how => $root ) {
				list( $after, $outside ) = html4_round_trip( '<template id="fazBannerTemplate">', '</template>', $markup, $root );
				$label = "{$type} ({$variant}, saveHTML of the {$how})";
				check( keeps_closing_tags( $markup, $after ), "{$label}: every closing tag survives" );
				check( $outside, "{$label}: the next element stays outside the template" );
			}
			list( $script_after, ) = html4_round_trip( '<script id="fazBannerTemplate" type="text/template">', '</script>', $markup );
			observe_script_form( ! keeps_closing_tags( $markup, $script_after ), "{$type} ({$variant}): control — the old script form loses closing tags" );
		}
	}

	echo "\nWhat banner_html() prints\n";
	$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
	$fn  = preg_match( '/public function banner_html\(\) \{(.*?)\n\t\}\n/s', $src, $m ) ? $m[1] : '';
	check( '' !== $fn, 'banner_html() found' );
	check( false !== strpos( $fn, "echo '<template id=\"fazBannerTemplate\">';" ) && false !== strpos( $fn, "echo '</template>';" ), 'it prints a <template id="fazBannerTemplate">' );
	check( false === strpos( $fn, "echo '<script" ), 'it no longer prints the script form' );
	check( false === strpos( $src, 'escape_template_end_tags(' ), 'the `<\/` escape is gone' );

	echo "\n";
	if ( 0 === $failed ) {
		echo "ALL PASS ({$passed})" . ( $observed ? ", {$observed} libxml control(s) not applicable on " . LIBXML_DOTTED_VERSION : '' ) . "\n";
		exit( 0 );
	}
	echo "FAILED: {$failed}, passed: {$passed}\n";
	exit( 1 );
}
