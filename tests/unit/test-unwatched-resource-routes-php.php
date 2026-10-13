<?php
/**
 * Standalone unit tests for the resource routes the server-side pass used to
 * miss: the SVG namespace, the legacy `background` attribute, and srcdoc.
 *
 * Subsystem: unwatched-resource-routes-php
 *
 * <image> is an <img> by another name and <use href="https://x/sprite.svg#icon">
 * fetches an external document, both through `href` or the legacy `xlink:href`.
 * No pass in class-frontend.php had ever looked at those attributes, and neither
 * had the client: the bootstrap's element map is keyed on nodeName, which is
 * uppercase only for HTML, so the whole namespace was invisible there too. An
 * SVG tracking pixel in server markup therefore went out before consent.
 *
 *   1. A blocked provider in `xlink:href` is parked into data-faz-href with a
 *      category, and the tag's shape (self-closing or not) survives.
 *   2. The same through the SVG2 spelling `href`.
 *   3. <use> is gated the same way — an external sprite reference is a fetch.
 *   4. First-party and unrecognised URLs are left byte-identical: over-parking
 *      an icon sprite breaks the page for nothing.
 *   5. A tag already carrying data-faz-href is left alone, so a second pass
 *      (or the client having parked it first) cannot double-rewrite it.
 *   6. A whitelisted tag is exempt, like every other pass here.
 *   8. An <iframe srcdoc> is judged whole — its markup becomes a document
 *      whose fetches no gate on either side can reach — and parked into
 *      data-faz-srcdoc when it names a blocked provider.
 *   7. The legacy `background` attribute, which fetches an image on <body> and
 *      on every table part, is parked the same way — on whichever element
 *      carried it, since the attribute is what fetches and not the tag.
 *
 * Run: php tests/unit/test-unwatched-resource-routes-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	// Double for the `use FazCookie\Includes\Known_Providers` alias inside
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

	// ---------- Bootstrap ----------

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $str ) {
			return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			$str = (string) $str;
			$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
			return trim( wp_strip_all_tags( $str ) );
		}
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $value ) {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); // phpcs:ignore
		}
	}
	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			return false;
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $ttl = 0 ) {
			return true;
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-embed-inventory.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	// ---------- assert helpers ----------

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;

	function assert_true( $cond, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $cond ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m " . $label . "\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m " . $label . "\n";
		}
	}

	// ---------- reflection harness ----------

	/**
	 * @param array $service_consent  service id => 'yes'|'no'. Empty = per-service
	 *                                consent off, which is the default everywhere
	 *                                below except the per-service cases.
	 * @param array $pattern_services URL pattern => list of service ids.
	 */
	function faz_ob_frontend( array $providers, array $blocked, array $whitelist = array(), array $service_consent = array(), array $pattern_services = array() ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		$preset = array(
			'blocked_categories_cache'   => $blocked,
			'provider_map_cache'         => $providers,
			'service_consent_cache'      => $service_consent,
			'whitelist_cache'            => $whitelist,
			'settings_option_cache'      => array(),
			'pattern_service_cache'      => $pattern_services,
			'provider_match_meta_cache' => null,
			'service_match_meta_cache'  => null,
		);
		foreach ( $preset as $prop => $value ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $fe, $value );
		}
		return $fe;
	}

	function faz_ob_run( $fe, $html ) {
		$m = new ReflectionMethod( Frontend::class, 'process_output_buffer' );
		$m->setAccessible( true );
		return $m->invoke( $fe, $html );
	}

	$providers = array(
		'connect.facebook.net' => 'marketing',
		'cdn.tracker.test'     => 'marketing',
	);
	$blocked   = array( 'marketing' );

	echo "\n-- SVG <image> / <use>: href and xlink:href --\n";

	// 1. The legacy spelling.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$out  = faz_ob_run( $fe, '<html><body><svg><image xlink:href="https://cdn.tracker.test/pixel.png" width="1" height="1"/></svg></body></html>' );
	assert_true( false === strpos( $out, 'xlink:href="https://cdn.tracker.test' ), 'xlink:href is not left live' );
	assert_true( false !== strpos( $out, 'data-faz-href="https://cdn.tracker.test/pixel.png"' ), 'it is parked into data-faz-href' );
	assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), 'with the category the restore pass needs' );
	assert_true( false !== strpos( $out, '/>' ), 'and the self-closing shape survives' );

	// 2. The SVG2 spelling.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><svg><image href="https://connect.facebook.net/p.png"/></svg></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-href="https://connect.facebook.net/p.png"' ), 'href is parked too' );
	assert_true( false === strpos( $out, ' href="https://connect.facebook.net' ), 'and is not left live' );

	// 3. <use> fetches an external document.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><svg><use xlink:href="https://cdn.tracker.test/sprite.svg#icon"/></svg></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-href="https://cdn.tracker.test/sprite.svg#icon"' ), '<use> is gated as well' );

	// 4. The other error direction: nothing to gate, nothing touched.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><svg><use href="/assets/sprite.svg#icon"/><image href="/logo.png"/></svg></body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'first-party SVG references pass through byte-identical' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><svg><image xlink:href="https://unknown.example/x.png"/></svg></body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'an unrecognised third party is not gated by this pass' );

	// 5. Idempotent: already parked stays as it is.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><svg><image data-faz-category="marketing" data-faz-href="https://cdn.tracker.test/pixel.png"/></svg></body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'a tag already parked is not rewritten a second time' );

	// 6. The whitelist exemption every pass here honours.
	$fe   = faz_ob_frontend( $providers, $blocked, array( 'cdn.tracker.test' ) );
	$html = '<html><body><svg><image xlink:href="https://cdn.tracker.test/pixel.png"/></svg></body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'a whitelisted URL is exempt' );

	echo "\n-- SVG: both spellings on one element --\n";

	// Sprites and exports commonly carry BOTH `href` and `xlink:href`. The old
	// pass parked the first and left the second live, which fetches. Both go,
	// and exactly one data-faz-href remains: the SVG2 value when it is there,
	// because that is the one an engine honours when both are present.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><svg><image href="https://cdn.tracker.test/a.png" xlink:href="https://cdn.tracker.test/legacy.png" width="1"/></svg></body></html>' );
	assert_true( ! preg_match( '/(?<![\w:-])href=/', $out ), 'href is not left live' );
	assert_true( false === stripos( $out, 'xlink:href=' ), 'xlink:href is not left live either' );
	assert_true( 1 === substr_count( $out, 'data-faz-href=' ), 'exactly one parked attribute' );
	assert_true( false !== strpos( $out, 'data-faz-href="https://cdn.tracker.test/a.png"' ), 'carrying the SVG2 value' );
	assert_true( 1 === substr_count( $out, 'data-faz-category=' ), 'and one category' );
	assert_true( false !== strpos( $out, 'width="1"/>' ), 'with the other attributes and the shape intact' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><svg><use xlink:href="https://cdn.tracker.test/s.svg#i" href="https://cdn.tracker.test/s.svg#i"></use></svg></body></html>' );
	assert_true( ! preg_match( '/(?<![\w:-])href=/', $out ) && false === stripos( $out, 'xlink:href=' ), 'legacy spelling first: neither is left live' );
	assert_true( 1 === substr_count( $out, 'data-faz-href=' ), 'and still one parked attribute' );

	echo "\n-- SVG: feImage, and names that merely start with image or use --\n";

	// <feImage> fetches through href exactly as <image> does.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><svg><filter><feImage href="https://cdn.tracker.test/f.png"/><feImage xlink:href="https://cdn.tracker.test/g.png"/></filter></svg></body></html>' );
	assert_true( 2 === substr_count( $out, 'data-faz-href=' ), 'feImage is parked through either spelling' );
	assert_true( ! preg_match( '/(?<![\w:-])href=/', $out ) && false === stripos( $out, 'xlink:href=' ), 'and neither is left live' );
	assert_true( 2 === substr_count( $out, 'data-faz-category="marketing"' ), 'each with its category' );

	// A custom element whose name starts with the tag is not the tag: the old
	// \b let <use-case> and <image-gallery> through and mutilated them.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><use-case href="https://cdn.tracker.test/x"></use-case><image-gallery href="https://cdn.tracker.test/y"></image-gallery></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'custom elements named use-* and image-* pass through byte-identical' );

	echo "\n-- the legacy background attribute --\n";

	// Any element that honours it, not just the body.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body background="https://cdn.tracker.test/bg.png"><table background="https://cdn.tracker.test/t.png"><tr><td background="https://cdn.tracker.test/c.png">x</td></tr></table></body></html>' );
	assert_true( 3 === substr_count( $out, 'data-faz-background=' ), 'body, table and cell are all parked' );
	assert_true( false === strpos( $out, ' background="https://cdn.tracker.test' ), 'and none is left live' );
	assert_true( 3 === substr_count( $out, 'data-faz-category="marketing"' ), 'each one carries the category' );

	// Whitespace around `=` is legal and the attribute pattern accepted it,
	// but the cheaper pre-check in front of it did not.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body background = "https://cdn.tracker.test/bg.png"></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-background=' ), 'background = "…" with spaces is parked' );
	assert_true( ! preg_match( '/(?<![\w-])background\s*=/i', $out ), 'and not left live' );

	// First-party backgrounds are ordinary page decoration.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body background="/images/paper.png"><table background="/images/grid.png"></table></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'first-party backgrounds pass through byte-identical' );

	// Already parked stays parked.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body data-faz-category="marketing" data-faz-background="https://cdn.tracker.test/bg.png"></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'a parked background is not rewritten twice' );

	echo "\n-- link: a duplicated href --\n";

	// Duplicate attributes are kept first-wins by the parser. Renaming only the
	// first href left the second one live, and live is what fetches.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><head><link rel="stylesheet" href="https://cdn.tracker.test/a.css" href="https://cdn.tracker.test/b.css"></head></html>' );
	assert_true( ! preg_match( '/(?<![\w-])href=/', $out ), 'no href is left live on a <link> that carries two' );
	assert_true( false !== strpos( $out, 'data-faz-href="https://cdn.tracker.test/a.css"' ), 'and the first, which the parser keeps, is the parked value' );

	echo "\n-- iframe srcdoc: a document in an attribute --\n";

	// The raw attribute token as it sits in the output, quotes included, so a
	// value the pipeline rewrote INSIDE cannot pass as parked.
	function faz_raw_attr( $html, $name ) {
		if ( preg_match( '/(?<![\w-])' . preg_quote( $name, '/' ) . '=("[^"]*"|\'[^\']*\'|[^\s>]+)/', $html, $m ) ) {
			return $m[1];
		}
		return null;
	}
	function faz_decode_attr( $raw ) {
		$inner = ( null !== $raw && strlen( $raw ) >= 2 && ( '"' === $raw[0] || "'" === $raw[0] ) ) ? substr( $raw, 1, -1 ) : (string) $raw;
		return html_entity_decode( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><iframe srcdoc="<img src=&quot;https://cdn.tracker.test/pixel.png&quot;>"></iframe></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-srcdoc=' ), 'a srcdoc naming a blocked provider is parked' );
	assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), 'with its category' );
	assert_true( false === strpos( $out, ' srcdoc="' ), 'and the live attribute is gone' );
	assert_true( '"<img src=&quot;https://cdn.tracker.test/pixel.png&quot;>"' === faz_raw_attr( $out, 'data-faz-srcdoc' ), 'the parked value is the original byte-for-byte: no pass rewrote inside it' );
	assert_true( '<img src="https://cdn.tracker.test/pixel.png">' === faz_decode_attr( faz_raw_attr( $out, 'data-faz-srcdoc' ) ), 'and decodes to the document the engine would have parsed' );
	assert_true( 1 === substr_count( $out, 'data-faz-category=' ), 'exactly one category on the tag' );
	assert_true( false !== strpos( $out, 'class="faz-hidden"' ), 'a parked srcdoc frame is hidden like a parked src frame' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><iframe srcdoc="<p>plain local markup</p>"></iframe></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'a srcdoc with no third-party URL is untouched' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><iframe srcdoc="<img src=&quot;https://unknown.example/x.png&quot;>"></iframe></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'an unrecognised third party inside srcdoc is not gated by this pass' );

	echo "\n-- iframe srcdoc: every pass that used to rewrite inside the value --\n";

	// Each inner element belongs to a pass that runs over the whole buffer:
	// <script src> (step 1), <style>@import (4), <img src> (4b), <link> (5).
	// Every one of them wrote a double-quoted attribute INTO a double-quoted
	// value and so ended the value early; the engine then read the tracker's
	// URL as the frame's src. The parked value must be the original, exactly.
	$inner_docs = array(
		'inner <script src>'        => '<script src=&quot;https://cdn.tracker.test/t.js&quot;></script>',
		'inner <link rel=stylesheet>' => '<link rel=&quot;stylesheet&quot; href=&quot;https://cdn.tracker.test/a.css&quot;>',
		'inner <style>@import'      => '<style>@import url(https://cdn.tracker.test/f.css);</style>',
		'inner <img src>, entities for > and "' => '&lt;p&gt;a &gt; b&lt;/p&gt;<img src=&quot;https://cdn.tracker.test/p.png&quot;>',
	);
	foreach ( $inner_docs as $label => $doc ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><body><iframe srcdoc="' . $doc . '"></iframe></body></html>' );
		assert_true( '"' . $doc . '"' === faz_raw_attr( $out, 'data-faz-srcdoc' ), $label . ': parked verbatim' );
		assert_true( false === strpos( $out, ' srcdoc="' ), $label . ': not left live' );
		assert_true( 1 === substr_count( $out, 'data-faz-category=' ), $label . ': one category' );
	}

	// Single-quoted srcdoc: the inner markup carries raw double quotes, so the
	// <img> pass used to deliver a data-faz-src INSIDE a document that has no
	// runtime to restore it.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, "<html><body><iframe srcdoc='<img src=\"https://cdn.tracker.test/pixel.png\">'></iframe></body></html>" );
	assert_true( "'<img src=\"https://cdn.tracker.test/pixel.png\">'" === faz_raw_attr( $out, 'data-faz-srcdoc' ), 'single-quoted srcdoc: parked verbatim, quoting preserved' );
	assert_true( false === strpos( $out, "data-faz-src=\"https://cdn.tracker.test" ), 'and nothing inside it was parked separately' );

	// An ALLOWED srcdoc is just as much off-limits to the inner passes.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = "<html><body><iframe srcdoc='<script src=\"/local.js\"></script><img src=\"/p.png\">'></iframe></body></html>";
	assert_true( faz_ob_run( $fe, $html ) === $html, 'an allowed srcdoc with inner script and img passes through byte-identical' );

	// Already parked: protected from the inner passes too, and not re-judged.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><iframe class="faz-hidden" data-faz-category="marketing" data-faz-srcdoc="<img src=&quot;https://cdn.tracker.test/pixel.png&quot;>"></iframe></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'a parked srcdoc is not rewritten a second time, inside or out' );

	echo "\n-- iframe srcdoc: src goes with it --\n";

	// The engine ignores src while srcdoc is present. Parking srcdoc alone
	// therefore ACTIVATED a src that had been inert, and the iframe pass never
	// parked it because its "already parked" check matched data-faz-src as a
	// substring of data-faz-srcdoc.
	$doc = '<img src=&quot;https://cdn.tracker.test/pixel.png&quot;>';
	foreach ( array(
		'srcdoc then src'          => '<iframe srcdoc="' . $doc . '" src="https://cdn.tracker.test/frame.html"></iframe>',
		'src then srcdoc'          => '<iframe src="https://cdn.tracker.test/frame.html" srcdoc="' . $doc . '"></iframe>',
		'first-party src, blocked srcdoc' => '<iframe src="/local.html" srcdoc="' . $doc . '" width="300"></iframe>',
	) as $label => $tag ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><body>' . $tag . '</body></html>' );
		assert_true( null !== faz_raw_attr( $out, 'data-faz-src' ), $label . ': src is parked' );
		// The tag's own attributes, with the parked document taken out: the
		// document legitimately contains a `src=` of its own.
		$own = str_replace( 'data-faz-srcdoc=' . faz_raw_attr( $out, 'data-faz-srcdoc' ), '', $out );
		assert_true( ! preg_match( '/(?<![\w-])src=/', $own ), $label . ': and not left live' );
		assert_true( '"' . $doc . '"' === faz_raw_attr( $out, 'data-faz-srcdoc' ), $label . ': srcdoc is parked verbatim' );
		assert_true( 1 === substr_count( $out, 'data-faz-category=' ), $label . ': exactly one category' );
		assert_true( false !== strpos( $out, 'faz-hidden' ), $label . ': hidden' );
	}

	echo "\n-- iframe srcdoc: the attribute walk --\n";

	// An apostrophe in an unquoted value is part of that value, not the start
	// of a run: the old walk swallowed the next iframe, which came out parked
	// without a category.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$out  = faz_ob_run( $fe, '<html><body><iframe title=it\'s src="/local"></iframe><iframe srcdoc="' . $doc . '"></iframe></body></html>' );
	assert_true( false !== strpos( $out, '<iframe title=it\'s src="/local"></iframe>' ), 'the iframe with the apostrophe is untouched' );
	assert_true( '"' . $doc . '"' === faz_raw_attr( $out, 'data-faz-srcdoc' ), 'the next iframe is parked' );
	assert_true( 1 === substr_count( $out, 'data-faz-category=' ), 'with its category' );

	// A megabyte of attribute value with no closing quote must neither
	// backtrack nor trip the PCRE limit: the old walk did the latter, and a
	// PCRE failure makes that pass give up for the WHOLE page. The tag is
	// closed so the later iframe pass, whose own `(.*?)</iframe>` scan is a
	// separate and older limit, is not what gets measured here.
	$fe      = faz_ob_frontend( $providers, $blocked );
	$tail    = '<html><body><iframe srcdoc="' . str_repeat( 'a', 1048576 ) . '></iframe><img src="https://cdn.tracker.test/after.png"></body></html>';
	$log     = tempnam( sys_get_temp_dir(), 'faz-pcre' );
	$old_log = ini_set( 'error_log', $log );
	$t0      = microtime( true );
	$out     = faz_ob_run( $fe, $tail );
	$spent   = microtime( true ) - $t0;
	ini_set( 'error_log', (string) $old_log );
	$logged = (string) file_get_contents( $log );
	unlink( $log );
	assert_true( $spent < 1.0, sprintf( 'a 1 MB unterminated srcdoc is walked in linear time (%.3fs)', $spent ) );
	assert_true( false === strpos( $logged, 'PCRE error' ), 'and does not trip the PCRE limit, which would skip blocking for the whole page' );
	assert_true( false !== strpos( $out, 'data-faz-src="https://cdn.tracker.test/after.png"' ), 'so the pixel after it is still parked' );

	// ---------- result ----------

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "ALL PASS ({$tests_passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$tests_failed}, passed: {$tests_passed}\n";
	exit( 1 );
}
