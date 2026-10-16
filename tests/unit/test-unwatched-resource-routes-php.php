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

namespace FazCookie\Frontend\Includes {

	// Double for the placeholder the iframe pass wraps a blocked frame in: the
	// decoy cases below need that pass to run end to end, and the markup it
	// hands back is all these cases look at.
	class Placeholder_Builder {
		public static function detect_service_from_url( $url ) {
			return 'default';
		}
		public static function get_service_name( $id ) {
			return 'Embedded content';
		}
		public static function get_video_thumbnail( $url ) {
			return '';
		}
		public static function build( $service_id, $service_name, $category, $blocked_html, $thumbnail_url = '' ) {
			return $blocked_html;
		}
	}
}

namespace {

	function wp_salt( $scheme = 'auth' ) { return 'srcdoc-test-' . $scheme; }
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
	assert_true( ! preg_match( '/(?<![\w-])srcdoc=/i', $out ), 'and the live attribute is gone' );
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
		assert_true( ! preg_match( '/(?<![\w-])srcdoc=/i', $out ), $label . ': not left live' );
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
	assert_true( false !== strpos( faz_ob_run( $fe, $html ), 'data-faz-untrusted-srcdoc=' ), 'an unsigned legacy park is demoted rather than activated' );

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

	// The tokenizer accepts, with a parse error, an attribute name that
	// contains a quote or starts with `=`. A walk strict enough to reject such
	// a tag would leave it to the later passes, which rewrite inside its
	// srcdoc exactly as before: tolerance here is what keeps the fix whole.
	foreach ( array(
		'a quote inside an attribute name' => '<iframe x"y srcdoc="<img src=\'https://cdn.tracker.test/p.png\'>"></iframe>',
		'a name starting with ='           => '<iframe =z srcdoc="<img src=\'https://cdn.tracker.test/p.png\'>"></iframe>',
		'a value followed by a name with no whitespace' => '<iframe a="b"c srcdoc="<img src=\'https://cdn.tracker.test/p.png\'>"></iframe>',
	) as $label => $tag ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><body>' . $tag . '</body></html>' );
		assert_true( '"<img src=\'https://cdn.tracker.test/p.png\'>"' === faz_raw_attr( $out, 'data-faz-srcdoc' ), $label . ': still parked verbatim' );
		assert_true( 1 === substr_count( $out, 'data-faz-category=' ), $label . ': with one category' );
	}

	// A parked token next to a LIVE one: the parked one is not a verdict on
	// the live one, which the engine loads. Both are stashed, the live one is
	// judged.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><iframe data-faz-srcdoc="x" srcdoc="<img src=https://cdn.tracker.test/p.png>"></iframe></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-untrusted-srcdoc="x"' ), 'the unsigned parked value stays inert' );
	assert_true( false !== strpos( $out, 'data-faz-srcdoc="<img src=https://cdn.tracker.test/p.png>"' ), 'and the live one is parked, byte-for-byte' );
	assert_true( ! preg_match( '/(?<![\w-])srcdoc=/i', $out ), 'nothing is left live' );
	assert_true( 1 === substr_count( $out, 'data-faz-category=' ), 'with one category' );

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
	// Read as the engine reads it: the value runs to the NEXT quote, which is
	// the one opening the pixel's src, so the pixel is text inside an allowed
	// srcdoc: the token comes back verbatim and nothing is parked. The name
	// glued after that closing quote gains one space, by design.
	assert_true( '"' . str_repeat( 'a', 1048576 ) . '></iframe><img src="' === faz_raw_attr( $out, 'srcdoc' ) && false === strpos( $out, 'data-faz-' ), 'and the tag is read as the engine reads it: srcdoc verbatim, nothing parked' );

	echo "\n-- a > inside a quoted value, before the resource attribute --\n";

	// Every pass walked attributes with [^>]*, so a `>` inside a QUOTED value
	// ended the walk early and hid every attribute after it. For
	// server-rendered markup the engine starts the fetch before any client
	// layer can act, so this was a request before consent on every pass.
	$decoys = array(
		'script'     => array( '<script title="a>b" src="https://cdn.tracker.test/t.js"></script>', 'type="text/plain"' ),
		'svg image'  => array( '<svg><image title="a>b" href="https://cdn.tracker.test/p.png"/></svg>', 'data-faz-href="https://cdn.tracker.test/p.png"' ),
		'background' => array( '<table><tr><td title="a>b" background="https://cdn.tracker.test/c.png">x</td></tr></table>', 'data-faz-background="https://cdn.tracker.test/c.png"' ),
		'srcdoc'     => array( '<iframe title="a>b" srcdoc="<img src=&quot;https://cdn.tracker.test/p.png&quot;>"></iframe>', 'data-faz-srcdoc="<img src=&quot;https://cdn.tracker.test/p.png&quot;>"' ),
		'iframe'     => array( '<iframe title="a>b" src="https://cdn.tracker.test/f.html"></iframe>', 'data-faz-src="https://cdn.tracker.test/f.html"' ),
		'style'      => array( '<style title="a>b">@import url(https://cdn.tracker.test/f.css);</style>', 'data-faz-css=' ),
		'img'        => array( '<img title="a>b" src="https://cdn.tracker.test/p.png">', 'data-faz-src="https://cdn.tracker.test/p.png"' ),
		'link'       => array( '<link title="a>b" rel="stylesheet" href="https://cdn.tracker.test/a.css">', 'data-faz-href="https://cdn.tracker.test/a.css"' ),
		'waitfor'    => array( '<script title="a>b" data-faz-waitfor="marketing">x()</script>', 'type="text/plain"' ),
		'noscript'   => array( '<noscript><img title="a>b" src="https://cdn.tracker.test/p.png"></noscript>', 'data-faz-src="https://cdn.tracker.test/p.png"' ),
	);
	foreach ( $decoys as $label => $case ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><body>' . $case[0] . '</body></html>' );
		assert_true( false !== strpos( $out, $case[1] ), $label . ': the resource after the decoy is parked' );
		assert_true( false !== strpos( $out, 'title="a>b"' ), $label . ': and the decoy value is untouched' );
		// The waitfor pass restores through data-faz-waitfor and never wrote
		// a category; every other one must.
		if ( 'waitfor' !== $label ) {
			assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), $label . ': with its category' );
		}
	}

	// The same walk in the helper that neutralises one script tag.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$m   = new ReflectionMethod( Frontend::class, 'block_script_tag_safely' );
	$m->setAccessible( true );
	$out = $m->invoke( $fe, '<script title="a>b" id="x">track()</script>', 'marketing', 'test' );
	assert_true( false !== strpos( $out, 'type="text/plain"' ), 'block_script_tag_safely re-types a tag with a decoy' );
	assert_true( false !== strpos( $out, 'title="a>b"' ) && false !== strpos( $out, '>track()</script>' ), 'and leaves the decoy and the body intact' );

	// The walk must stay linear on every pass, not only on srcdoc: the old
	// [^>]* backtracked a megabyte on an unterminated <img> and tripped the
	// PCRE limit, which skipped that pass for the whole page.
	$fe      = faz_ob_frontend( $providers, $blocked );
	$tail    = '<html><body><img src="https://cdn.tracker.test/before.png"><img title="' . str_repeat( 'a', 1048576 );
	$log     = tempnam( sys_get_temp_dir(), 'faz-pcre' );
	$old_log = ini_set( 'error_log', $log );
	$t0      = microtime( true );
	$out     = faz_ob_run( $fe, $tail );
	$spent   = microtime( true ) - $t0;
	ini_set( 'error_log', (string) $old_log );
	$logged = (string) file_get_contents( $log );
	unlink( $log );
	assert_true( $spent < 1.0, sprintf( 'a 1 MB unterminated <img> is walked in linear time (%.3fs)', $spent ) );
	assert_true( false === strpos( $logged, 'PCRE error' ), 'without tripping the PCRE limit' );
	assert_true( false !== strpos( $out, 'data-faz-src="https://cdn.tracker.test/before.png"' ), 'so the pixel before it is still parked' );

	echo "\n-- an attribute right after a closing quote or a slash --\n";

	// The tokenizer starts a new attribute right after a closing quote or a
	// `/`; a rename that required whitespace before the name left exactly
	// those tokens live, and live is what fetches.
	$glued = array(
		'img, after a quote'        => array( '<img title="x"src="https://cdn.tracker.test/p.png">', 'data-faz-src="https://cdn.tracker.test/p.png"' ),
		'img, after a slash'        => array( '<img/src="https://cdn.tracker.test/p.png">', 'data-faz-src="https://cdn.tracker.test/p.png"' ),
		'img srcset, after a quote' => array( '<img alt="x"srcset="https://cdn.tracker.test/p.png 1x">', 'data-faz-srcset="https://cdn.tracker.test/p.png 1x"' ),
		'iframe, after a quote'     => array( '<iframe title="x"src="https://cdn.tracker.test/f.html"></iframe>', 'data-faz-src="https://cdn.tracker.test/f.html"' ),
		'iframe, after a slash'     => array( '<iframe/src="https://cdn.tracker.test/f.html"></iframe>', 'data-faz-src="https://cdn.tracker.test/f.html"' ),
		'image href, after a quote' => array( '<svg><image title="x"href="https://cdn.tracker.test/p.png"/></svg>', 'data-faz-href="https://cdn.tracker.test/p.png"' ),
		'image href, after a slash' => array( '<svg><image/href="https://cdn.tracker.test/p.png"/></svg>', 'data-faz-href="https://cdn.tracker.test/p.png"' ),
		'use xlink:href, after a quote' => array( '<svg><use title="x"xlink:href="https://cdn.tracker.test/s.svg#i"/></svg>', 'data-faz-href="https://cdn.tracker.test/s.svg#i"' ),
		'td background, after a quote'  => array( '<table><tr><td title="x"background="https://cdn.tracker.test/c.png">x</td></tr></table>', 'data-faz-background="https://cdn.tracker.test/c.png"' ),
		'body background, after a quote' => array( '<body class="x"background="https://cdn.tracker.test/b.png"></body>', 'data-faz-background="https://cdn.tracker.test/b.png"' ),
		'td background, after a slash'  => array( '<table><tr><td/background="https://cdn.tracker.test/c.png">x</td></tr></table>', 'data-faz-background="https://cdn.tracker.test/c.png"' ),
		'link href, after a quote'  => array( '<link rel="stylesheet"href="https://cdn.tracker.test/a.css">', 'data-faz-href="https://cdn.tracker.test/a.css"' ),
		'link href, after a slash'  => array( '<link/rel="stylesheet"href="https://cdn.tracker.test/a.css">', 'data-faz-href="https://cdn.tracker.test/a.css"' ),
		'link imagesrcset, after a quote' => array( '<link rel=preload as=image title="x"imagesrcset="https://cdn.tracker.test/p.png 1x">', 'data-faz-imagesrcset="https://cdn.tracker.test/p.png 1x"' ),
		'noscript img, after a quote' => array( '<noscript><img title="x"src="https://cdn.tracker.test/p.png"></noscript>', 'data-faz-src="https://cdn.tracker.test/p.png"' ),
		'noscript img, after a slash' => array( '<noscript><img/src="https://cdn.tracker.test/p.png"></noscript>', 'data-faz-src="https://cdn.tracker.test/p.png"' ),
		'script, after a quote'     => array( '<script title="x"src="https://cdn.tracker.test/t.js"></script>', 'type="text/plain"' ),
	);
	foreach ( $glued as $label => $case ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html>' . $case[0] . '</html>' );
		assert_true( false !== strpos( $out, $case[1] ), $label . ': parked' );
		// A script parks through its type and keeps its src; every other
		// resource parks by renaming the attribute.
		if ( 'script, after a quote' !== $label ) {
			assert_true( ! preg_match( '/(?<![\w:-])(?:src|srcset|href|xlink:href|background|imagesrcset)=/i', $out ), $label . ': nothing left live' );
		}
		assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), $label . ': with its category' );
	}

	// A `name=` inside another attribute's value is not that attribute.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><img alt="src=https://cdn.tracker.test/p.png" src="/local.png"></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'src= inside an alt value does not park the local image' );

	echo "\n-- the URL as the engine reads it, not as it is written --\n";

	// Chromium decodes entities, strips tab/LF/CR inside the URL and
	// percent-decodes the host before it resolves anything; a provider match
	// on the raw bytes saw none of these as the tracker.
	$shapes = array(
		'a character entity in the host' => 'https://cdn.tr&#97;cker.test/p.png',
		'a &Tab; in the host'            => 'https://cdn.tra&Tab;cker.test/p.png',
		'a literal newline in the host'  => "https://cdn.tra\ncker.test/p.png",
		'a percent-encoded dot in the host' => 'https://cdn.tracker%2Etest/p.png',
	);
	foreach ( $shapes as $label => $url ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><img src="' . $url . '"><iframe src="' . $url . '"></iframe><script src="' . $url . '"></script><link rel="stylesheet" href="' . $url . '"></html>' );
		assert_true( 2 === substr_count( $out, 'data-faz-src="' ), $label . ': img and iframe parked' );
		assert_true( false !== strpos( $out, 'type="text/plain"' ), $label . ': script re-typed' );
		assert_true( false !== strpos( $out, 'data-faz-href="' ), $label . ': link parked' );
		// Inside a srcdoc the entity is decoded TWICE: once by the attribute,
		// once by the document the attribute becomes.
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><iframe srcdoc="<img src=&quot;' . str_replace( array( '&', "\n" ), array( '&amp;', '&NewLine;' ), $url ) . '&quot;>"></iframe></html>' );
		assert_true( false !== strpos( $out, 'data-faz-srcdoc=' ), $label . ': judged through a srcdoc' );
	}

	echo "\n-- a closing tag with whitespace before its > --\n";

	// `</script >` is a closing tag to the parser. A tail that only knew
	// `</script>` scanned past it, swallowed the next element, and judged the
	// tracker script on the FIRST tag's local URL.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><script src="/local.js"></script ><script src="https://cdn.tracker.test/t.js"></script></html>' );
	assert_true( false !== strpos( $out, '<script src="/local.js"></script >' ), 'the local script closed with </script > is untouched' );
	assert_true( false !== strpos( $out, 'src="https://cdn.tracker.test/t.js" type="text/plain"' ) || preg_match( '/<script[^>]*text\/plain[^>]*cdn\.tracker\.test/', $out ), 'and the tracker script after it is re-typed' );
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><iframe src="/local.html"></iframe ><iframe src="https://cdn.tracker.test/f.html"></iframe></html>' );
	assert_true( false !== strpos( $out, '<iframe src="/local.html"></iframe >' ), 'the local iframe closed with </iframe > is untouched' );
	assert_true( false !== strpos( $out, 'data-faz-src="https://cdn.tracker.test/f.html"' ), 'and the tracker iframe after it is parked' );
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><style>.a{}</style ><style>@import url(https://cdn.tracker.test/f.css);</style></html>' );
	assert_true( false !== strpos( $out, '<style>.a{}</style >' ) && false !== strpos( $out, 'data-faz-css=' ), 'the same for </style >' );
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><noscript><p>x</p></noscript ><noscript><img src="https://cdn.tracker.test/p.png"></noscript></html>' );
	assert_true( false !== strpos( $out, 'data-faz-src="https://cdn.tracker.test/p.png"' ), 'and for </noscript >' );

	echo "\n-- the stash markers cannot be spelled by the page --\n";

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><p>__FAZ_SRCDOC_STASH_0__ and __FAZ_NOSCRIPT_STASH_0__</p><iframe srcdoc="<img src=&quot;https://cdn.tracker.test/p.png&quot;>"></iframe><noscript><img src="https://cdn.tracker.test/n.png"></noscript></html>' );
	assert_true( false !== strpos( $out, '<p>__FAZ_SRCDOC_STASH_0__ and __FAZ_NOSCRIPT_STASH_0__</p>' ), 'the literal marker text in a paragraph stays text' );
	assert_true( false !== strpos( $out, 'data-faz-srcdoc="<img src=&quot;https://cdn.tracker.test/p.png&quot;>"' ), 'while the srcdoc is parked where it belongs' );
	assert_true( false !== strpos( $out, '<noscript><img data-faz-category="marketing" data-faz-src="https://cdn.tracker.test/n.png"></noscript>' ), 'and the noscript block comes back where it belongs' );

	echo "\n-- a hundred thousand attributes --\n";

	// The tokenizer used to materialise every token of the tag at once: at
	// ~1.3 KB each, 100k attributes on one iframe was 132 MB, a fatal under a
	// 40 MB memory limit, and it was reached for every <iframe> as soon as
	// the page contained the word "srcdoc".
	$fe    = faz_ob_frontend( $providers, $blocked );
	$many  = '';
	for ( $i = 0; $i < 100000; $i++ ) {
		$many .= ' a' . $i . '="x"';
	}
	$html  = '<html><iframe' . $many . ' src="https://cdn.tracker.test/f.html" srcdoc="<img src=&quot;https://cdn.tracker.test/p.png&quot;>"></iframe></html>';
	gc_collect_cycles();
	$before = memory_get_peak_usage();
	$t0     = microtime( true );
	$out    = faz_ob_run( $fe, $html );
	$spent  = microtime( true ) - $t0;
	$grown  = memory_get_peak_usage() - $before;
	assert_true( false !== strpos( $out, 'data-faz-srcdoc=' ) && false !== strpos( $out, 'data-faz-src="https://cdn.tracker.test/f.html"' ), 'the frame is parked, src and srcdoc' );
	assert_true( $grown < 16 * 1048576, sprintf( 'peak memory grows by a few MB at most (%.1f MB)', $grown / 1048576 ) );
	assert_true( $spent < 3.0, sprintf( 'in linear time (%.2fs)', $spent ) );
	unset( $html, $out, $many );

	echo "\n-- the HTML image element, and a frame whose src is inert --\n";

	// Outside <svg>, the tree builder turns <image> into <img>, which fetches
	// src. The SVG pass looked at href only.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><image src="https://cdn.tracker.test/p.png"><image href="https://cdn.tracker.test/h.png" src="https://cdn.tracker.test/s.png"/></body></html>' );
	assert_true( 2 === substr_count( $out, 'data-faz-src="https://cdn.tracker.test/' ), '<image src> is parked as data-faz-src, with or without an href' );
	assert_true( false !== strpos( $out, 'data-faz-href="https://cdn.tracker.test/h.png"' ), 'and the href next to it still parks as data-faz-href' );
	assert_true( ! preg_match( '/(?<![\w:-])src=/', $out ), 'with no src left live' );

	// srcset on the same element, for the same reason as src: the tree builder
	// keeps every attribute when it turns <image> into <img>, and the browser
	// picks a candidate from srcset in PREFERENCE to src. The <img> pass has
	// parked both since the responsive-beacon fix; this one listed only src, so
	// a tag carrying srcset ALONE had nothing to park and came back untouched.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><image srcset="https://cdn.tracker.test/p.png 1x"></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-srcset="https://cdn.tracker.test/p.png 1x"' ), '<image srcset> alone is parked as data-faz-srcset' );
	assert_true( false !== strpos( $out, 'data-faz-srcset-category=' ), 'and carries the category its own restore reads' );
	assert_true( ! preg_match( '/(?<![\w:-])srcset=/', $out ), 'with no srcset left live' );

	// And next to a src, both leave: parking one of the two would let the other
	// fetch on its own.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><image src="https://cdn.tracker.test/s.png" srcset="https://cdn.tracker.test/p.png 2x"></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-src="https://cdn.tracker.test/s.png"' ), '<image src> next to a srcset still parks' );
	assert_true( false !== strpos( $out, 'data-faz-srcset="https://cdn.tracker.test/p.png 2x"' ), 'and the srcset parks with it' );
	assert_true( ! preg_match( '/(?<![\w:-])(?:src|srcset)=/', $out ), 'with neither left live' );

	// A first-party srcset is left exactly as it was: the park is for what the
	// origin test refuses, not for the attribute's name.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><image srcset="/wp-content/own.png 1x"></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'a same-origin <image srcset> comes out byte-identical' );

	// The "lite embed" pattern: an allowed srcdoc with a blocked src. The src
	// is inert while srcdoc is present, so there is nothing to park, and the
	// placeholder wrap used to destroy the srcdoc on its way through wp_kses.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><iframe srcdoc="<p>lite</p>" src="https://cdn.tracker.test/f.html" width="300"></iframe></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'an allowed srcdoc with a blocked src comes out byte-identical' );

	// Two live srcdoc tokens: the parser keeps the first, so the first is
	// judged — and once the first is parked the second would become THE
	// srcdoc, so every one of them goes with it.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><iframe srcdoc="<img src=&quot;https://cdn.tracker.test/p.png&quot;>" srcdoc="<img src=&quot;https://cdn.tracker.test/q.png&quot;>"></iframe></body></html>' );
	assert_true( ! preg_match( '/(?<![\w-])srcdoc=/i', $out ), 'no srcdoc is left live' );
	assert_true( false !== strpos( $out, 'data-faz-srcdoc="<img src=&quot;https://cdn.tracker.test/p.png&quot;>"' ) && false !== strpos( $out, 'data-faz-srcdoc="<img src=&quot;https://cdn.tracker.test/q.png&quot;>"' ), 'both are parked verbatim, the first one first' );
	assert_true( 1 === substr_count( $out, 'data-faz-category=' ), 'with one category' );
	$fe  = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><iframe srcdoc="<p>a</p>" srcdoc="<img src=&quot;https://unknown.example/x.png&quot;>"></iframe></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'two allowed srcdoc tokens come back byte-identical' );

	echo "\n-- A1: a src glued to the srcdoc token --\n";

	// The marker replaced the srcdoc token with nothing around it, so a src
	// glued to the closing quote became part of the marker's own token and
	// was never renamed: srcdoc parked, src live, and with srcdoc gone the
	// frame navigated to the tracker in all three engines.
	$doc = '<img src=&quot;https://cdn.tracker.test/pixel.png&quot;>';
	foreach ( array(
		'double-quoted srcdoc, glued src' => '<iframe srcdoc="' . $doc . '"src="https://cdn.tracker.test/f"></iframe>',
		'single-quoted srcdoc, glued src' => "<iframe srcdoc='" . $doc . "'src=\"https://cdn.tracker.test/f\"></iframe>",
		'slash between them'              => '<iframe srcdoc="' . $doc . '"/src="https://cdn.tracker.test/f"></iframe>',
		'glued unquoted src'              => '<iframe srcdoc="' . $doc . '"src=https://cdn.tracker.test/f></iframe>',
	) as $label => $tag ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><body>' . $tag . '</body></html>' );
		$own = str_replace( 'data-faz-srcdoc=' . faz_raw_attr( $out, 'data-faz-srcdoc' ), '', $out );
		assert_true( null !== faz_raw_attr( $out, 'data-faz-src' ), $label . ': src is parked' );
		assert_true( ! preg_match( '/(?<![\w-])src=/i', $own ), $label . ': and not left live' );
		assert_true( ! preg_match( '/(?<![\w-])srcdoc=/i', $out ), $label . ': srcdoc not left live' );
		assert_true( 1 === substr_count( $out, 'data-faz-category=' ), $label . ': one category' );
	}

	echo "\n-- A2: a script whose type lives in a decoy --\n";

	// The skips and the re-type matched `type=` anywhere in the attribute
	// string, inside another attribute's value included.
	foreach ( array(
		'alt="type=\'module\'"'           => '<script alt="type=\'module\'" src="https://cdn.tracker.test/a.js"></script>',
		'alt="type=\'text/plain\'"'       => '<script alt="type=\'text/plain\'" src="https://cdn.tracker.test/a.js"></script>',
		'alt="data-fazcookie"'            => '<script alt="data-fazcookie" src="https://cdn.tracker.test/a.js"></script>',
		'alt="type=\'application/json\'"' => '<script alt="type=\'application/json\'" src="https://cdn.tracker.test/a.js"></script>',
	) as $label => $tag ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html>' . $tag . '</html>' );
		assert_true( 1 === preg_match_all( '/(?<![\w-])type="text\/plain"/', $out ), $label . ': re-typed through a real type attribute, once' );
		assert_true( false !== strpos( $out, substr( $tag, 8, strpos( $tag, ' src=' ) - 8 ) ), $label . ': and the decoy is untouched' );
	}
	// And a real type is replaced in place, with the original kept.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><script alt="type=\'x\'" type="module" src="https://cdn.tracker.test/a.js"></script></html>' );
	assert_true( 1 === preg_match_all( '/(?<![\w-])type="text\/plain"/', $out ) && ! preg_match( '/(?<![\w-])type="module"/', $out ) && false !== strpos( $out, 'data-faz-original-type="module"' ), 'a real type="module" next to a decoy is replaced and remembered' );

	echo "\n-- A3/A4/M1: backslashes and userinfo, as WHATWG resolves them --\n";

	// The URL parser turns `\` into `/` for special schemes and reads the
	// host after any userinfo; a boundary check on the raw bytes saw neither.
	foreach ( array(
		'scheme followed by backslashes' => 'https:\\\\cdn.tracker.test/pixel.png',
		'backslash before an @ decoy'    => 'https://cdn.tracker.test\\@site.test/pixel.png',
		'userinfo before the host'       => 'https://site.test@cdn.tracker.test/pixel.png',
	) as $label => $url ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><img src="' . $url . '"><script src="' . $url . '"></script><iframe src="' . $url . '"></iframe></html>' );
		assert_true( 2 === substr_count( $out, 'data-faz-src="' ), $label . ': img and iframe parked' );
		assert_true( false !== strpos( $out, 'type="text/plain"' ), $label . ': script re-typed' );
	}
	// The whitelist reads the same host the engine does, not the one after a
	// backslash-separated decoy.
	$fe  = faz_ob_frontend( $providers, $blocked, array( 'maps.googleapis.com/' ) );
	$out = faz_ob_run( $fe, '<html><img src="https://cdn.tracker.test\\@maps.googleapis.com/maps/api/x.png"></html>' );
	assert_true( false !== strpos( $out, 'data-faz-src="' ), 'a whitelisted host after a backslash decoy does not exempt the tracker' );
	$fe   = faz_ob_frontend( $providers, $blocked, array( 'maps.googleapis.com/' ) );
	$html = '<html><img src="https://maps.googleapis.com/maps/api/x.png"></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'control: the real whitelisted host is exempt' );

	echo "\n-- M2: special-scheme URLs inside a srcdoc --\n";

	foreach ( array( 'https:/cdn.tracker.test/p', 'https:cdn.tracker.test/p' ) as $url ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><iframe srcdoc="<img src=&quot;' . $url . '&quot;>"></iframe></html>' );
		assert_true( false !== strpos( $out, 'data-faz-srcdoc=' ), $url . ' inside a srcdoc is judged as the engine resolves it' );
	}

	echo "\n-- M3: the whitelist reads the URL without its query and fragment --\n";

	$fe  = faz_ob_frontend( $providers, $blocked, array( 'jquery.min.js', 'wp-includes/' ) );
	$out = faz_ob_run( $fe, '<html><img src="https://cdn.tracker.test/pixel.png?x=jquery.min.js"><img src="https://cdn.tracker.test/pixel.png#wp-includes/"></html>' );
	assert_true( 2 === substr_count( $out, 'data-faz-src="' ), 'a whitelist token in the query or the fragment does not exempt' );
	$fe   = faz_ob_frontend( $providers, $blocked, array( 'jquery.min.js', 'wp-includes/' ) );
	$html = '<html><img src="https://cdn.tracker.test/wp-includes/js/jquery.min.js"></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'control: the token in the path still exempts' );

	echo "\n-- M4: a stashed token that is not a live srcdoc --\n";

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><iframe data-faz-srcdoc="x" src="https://cdn.tracker.test/f"></iframe></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-src="https://cdn.tracker.test/f"' ), 'with no live srcdoc the src is not inert and is parked' );
	assert_true( false !== strpos( $out, 'data-faz-untrusted-srcdoc="x"' ), 'and the unsigned token comes back inert' );

	echo "\n-- M5: a forged empty sentinel --\n";

	foreach ( array(
		'svg image' => array( '<svg><image data-faz-src="" href="https://cdn.tracker.test/p.png"/></svg>', 'data-faz-href="https://cdn.tracker.test/p.png"', 'href' ),
		'img'       => array( '<img data-faz-src="" src="https://cdn.tracker.test/p.png">', 'data-faz-src="https://cdn.tracker.test/p.png"', 'src' ),
		'iframe'    => array( '<iframe data-faz-src="" src="https://cdn.tracker.test/f"></iframe>', 'data-faz-src="https://cdn.tracker.test/f"', 'src' ),
		'link'      => array( '<link data-faz-href="" rel="stylesheet" href="https://cdn.tracker.test/a.css">', 'data-faz-href="https://cdn.tracker.test/a.css"', 'href' ),
	) as $label => $case ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html>' . $case[0] . '</html>' );
		assert_true( false !== strpos( $out, $case[1] ), $label . ': the live attribute is parked despite the sentinel' );
		assert_true( ! preg_match( '/(?<![\w:-])' . $case[2] . '=/i', $out ), $label . ': and not left live' );
		assert_true( 1 === substr_count( $out, 'data-faz-category=' ), $label . ': one category' );
	}
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><img data-faz-category="marketing" data-faz-src="" src="https://cdn.tracker.test/p.png"></html>' );
	assert_true( false !== strpos( $out, 'data-faz-src="https://cdn.tracker.test/p.png"' ) && 1 === substr_count( $out, 'data-faz-category=' ) && false !== strpos( $out, 'data-faz-category="marketing"' ), 'a sentinel with a category: parked, and exactly one category, the matcher\'s' );

	echo "\n-- a forged category on a tag whose live attribute is parked --\n";

	// A page-authored data-faz-category="necessary" next to a live tracker
	// attribute is a restore order: the runtime restores a parked resource
	// whose category is allowed before consent. When a pass parks a live
	// attribute, the matcher's category must be the only one on the tag,
	// whatever casing or spacing the forged one uses.
	$forged = array(
		'img'          => '<img data-faz-category="necessary" src="https://cdn.tracker.test/p.png">',
		'img, odd casing and spacing' => '<img DATA-FAZ-CATEGORY = " Necessary " src="https://cdn.tracker.test/p.png">',
		'iframe, empty' => '<iframe data-faz-category="" src="https://cdn.tracker.test/f"></iframe>',
		'script'       => '<script data-faz-category="necessary" src="https://cdn.tracker.test/t.js"></script>',
		'link, unknown value' => '<link data-faz-category="zzz" rel="stylesheet" href="https://cdn.tracker.test/a.css">',
		'svg image'    => '<svg><image data-faz-category="necessary" href="https://cdn.tracker.test/p.png"/></svg>',
		'svg use'      => '<svg><use data-faz-category="necessary" xlink:href="https://cdn.tracker.test/s.svg#i"/></svg>',
		'svg feImage'  => '<svg><feImage data-faz-category="necessary" href="https://cdn.tracker.test/f.png"/></svg>',
		'body background' => '<body data-faz-category="necessary" background="https://cdn.tracker.test/b.png"></body>',
		'td background'   => '<table><tr><td data-faz-category="necessary" background="https://cdn.tracker.test/c.png">x</td></tr></table>',
		'srcdoc'       => '<iframe data-faz-category="necessary" srcdoc="<img src=&quot;https://cdn.tracker.test/p.png&quot;>"></iframe>',
		'noscript img' => '<noscript><img data-faz-category="necessary" src="https://cdn.tracker.test/p.png"></noscript>',
		'style'        => '<style data-faz-category="necessary">@import url(https://cdn.tracker.test/f.css);</style>',
	);
	foreach ( $forged as $label => $tag ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html>' . $tag . '</html>' );
		assert_true( 1 === preg_match_all( '/(?<![\w-])data-faz-category\s*=/i', $out ), $label . ': exactly one category token' );
		assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), $label . ': and it is the matcher\'s' );
	}
	// A tag with nothing live to park keeps what it carries.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><img data-faz-category="necessary" data-faz-src="https://cdn.tracker.test/p.png"></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'a parked tag with nothing live keeps its own category' );

	echo "\n-- backslashes where the engine reads slashes --\n";

	foreach ( array(
		'scheme-relative backslashes with an @ decoy' => '\\\\cdn.tracker.test\\@site.test/p',
		'scheme-relative backslashes'                 => '\\\\cdn.tracker.test\\p',
	) as $label => $url ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><img src="' . $url . '"></html>' );
		assert_true( false !== strpos( $out, 'data-faz-src="' ), $label . ': parked' );
	}
	foreach ( array(
		'backslashes in an img src'     => '<img src=\'\\\\cdn.tracker.test/p\'>',
		'/\\ in an img src'              => '<img src=\'/\\cdn.tracker.test/p\'>',
		'\\/ in an img src'              => '<img src=\'\\/cdn.tracker.test/p\'>',
		'a meta refresh with backslashes' => '<meta http-equiv=refresh content=\'0;url=\\\\cdn.tracker.test/r\'>',
	) as $label => $inner ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><iframe srcdoc="' . $inner . '"></iframe></html>' );
		assert_true( false !== strpos( $out, 'data-faz-srcdoc=' ), $label . ' inside a srcdoc is judged' );
	}
	// The other direction: the host the engine reads is the first-party one.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><img src="https://site.test\\@cdn.tracker.test/p"><img src="/local.png?u=me@cdn.tracker.test"></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'a tracker host after a backslash-closed authority, or in a query, is not parked' );

	echo "\n-- a megabyte of type attributes --\n";

	$spent_by_size = array();
	foreach ( array( 1048576, 1572864 ) as $bytes ) {
		$fe    = faz_ob_frontend( $providers, $blocked );
		$many  = str_repeat( 'type="module" ', (int) ( $bytes / 14 ) );
		$html  = '<html><script ' . $many . 'src="https://cdn.tracker.test/t.js"></script></html>';
		gc_collect_cycles();
		$before = memory_get_peak_usage();
		$t0     = microtime( true );
		$out    = faz_ob_run( $fe, $html );
		$spent  = microtime( true ) - $t0;
		$grown  = memory_get_peak_usage() - $before;
		assert_true( false !== strpos( $out, 'type="text/plain"' ) && ! preg_match( '/(?<![\w-])type="module"/', $out ), sprintf( '%.1f MB of type attributes: every one re-typed', $bytes / 1048576 ) );
		assert_true( $grown < 16 * 1048576, sprintf( 'with peak memory up by a few MB at most (%.1f MB)', $grown / 1048576 ) );
		$spent_by_size[ $bytes ] = $spent;
		unset( $html, $out, $many );
	}

	// What this measures is the QUADRATIC pass this one replaced — 3.2s and
	// 7.4s, growing with the square of the input. An absolute ceiling cannot
	// say that: the one that used to be here was 1.0s, which this laptop meets
	// and a GitHub-hosted runner does not (measured 1.23s and 1.83s, every
	// run), so the Quality check would go red on hardware and not on a defect.
	// The RATIO is the part no machine changes: 1.5x the input costs about
	// 1.5x linear and about 2.25x quadratic, and 1.9 separates them with margin
	// on both sides. The generous absolute ceiling stays as a backstop for a
	// run that is pathological rather than merely slow.
	$ratio = $spent_by_size[1048576] > 0 ? $spent_by_size[1572864] / $spent_by_size[1048576] : 0;
	assert_true( $ratio > 0 && $ratio < 1.9, sprintf( '1.5x the input costs about 1.5x the time, not its square (%.2fx)', $ratio ) );
	assert_true( $spent_by_size[1572864] < 10.0, sprintf( 'and stays far under the old quadratic cost (%.2fs)', $spent_by_size[1572864] ) );

	echo "\n-- a CSS url() whose authority carries userinfo --\n";

	// `@` was dropped from the left-hand boundary set on the grounds that
	// normalize_resource_url() removes userinfo before the match. That holds for
	// the haystacks get_provider_match_context() builds, and NOT for this route:
	// css_url_block_category() trimmed the quotes and passed the raw url() token
	// straight to provider_url_pattern_matches(), so the host sat behind an `@`
	// that was no longer a boundary and matched nothing. Firefox fetches
	// subresources with embedded credentials, so the sheet went out live.
	foreach ( array(
		'userinfo before the host'      => '<style>.p{background-image:url(https://x@cdn.tracker.test/p.png)}</style>',
		'user:pass before the host'     => '<style>.p{background-image:url("https://u:p@cdn.tracker.test/p.png")}</style>',
		'userinfo on an @import'        => '<style>@import url(https://x@cdn.tracker.test/f.css);</style>',
		'a backslash-spelled authority' => '<style>.p{background-image:url(https:\\\\cdn.tracker.test/p.png)}</style>',
	) as $label => $html ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html>' . $html . '</html>' );
		assert_true( false !== strpos( $out, 'data-faz-css=' ), $label . ' is neutralised' );
	}

	// The other direction: an `@` inside a path or a query is not an authority
	// separator, and the host that gets fetched is the first-party one.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><style>.p{background-image:url(/local.png?u=me@cdn.tracker.test)}</style></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'a tracker host in a query is not over-parked' );

	echo "\n-- the_content entry point protects a srcdoc the same way --\n";

	// filter_content_blocking() runs the same script and iframe passes as
	// process_output_buffer(), over `the_content`. The srcdoc protection existed
	// only in the output buffer, so here the script pass re-typed a <script src>
	// INSIDE the attribute value and wrote data-faz-category="…" into it: the
	// parser ends the srcdoc at that first injected quote, the embedded document
	// is truncated, and the remainder becomes stray attributes on the frame. The
	// resource is destroyed rather than delayed.
	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin() {
			return false; }
	}
	if ( ! function_exists( 'wp_doing_cron' ) ) {
		function wp_doing_cron() {
			return false; }
	}
	if ( ! function_exists( 'faz_is_machine_readable_request' ) ) {
		function faz_is_machine_readable_request() {
			return false; }
	}
	if ( ! function_exists( 'faz_disable_banner' ) ) {
		function faz_disable_banner() {
			return false; }
	}
	if ( ! function_exists( 'get_the_ID' ) ) {
		function get_the_ID() {
			return 0; }
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $v ) {
			return $v; }
	}
	if ( ! class_exists( 'Faz_Content_Test_Settings' ) ) {
		class Faz_Content_Test_Settings {
			public function get( $group, $key = null ) {
				return 'status' === $key ? true : array();
			}
		}
	}

	$faz_content_frontend = function ( array $providers, array $blocked ) {
		$fe = faz_ob_frontend( $providers, $blocked );
		$rc = new ReflectionClass( Frontend::class );
		foreach ( array(
			'settings' => new Faz_Content_Test_Settings(),
			'template' => true,
		) as $prop => $val ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $fe, $val );
		}
		return $fe;
	};
	$faz_content_run = function ( $fe, $content ) {
		$m = new ReflectionMethod( Frontend::class, 'filter_content_blocking' );
		$m->setAccessible( true );
		return $m->invoke( $fe, $content );
	};

	foreach ( array(
		'a tracker <script> inside a srcdoc' => array( '"', '<script src=&quot;https://cdn.tracker.test/t.js&quot;></script><p>hi</p>' ),
		'a tracker <img> inside a srcdoc'    => array( '"', '<p>hi</p><img src=&quot;https://cdn.tracker.test/p.png&quot;>' ),
		'a single-quoted srcdoc'             => array( "'", '<img src="https://cdn.tracker.test/p.png">' ),
	) as $label => $spec ) {
		list( $quote, $value ) = $spec;
		$fe  = $faz_content_frontend( $providers, $blocked );
		$out = $faz_content_run( $fe, '<p>x</p><iframe srcdoc=' . $quote . $value . $quote . '></iframe>' );
		assert_true( false !== strpos( $out, 'data-faz-srcdoc=' ), $label . ': parked whole' );
		assert_true( false === strpos( $out, ' srcdoc=' ), $label . ': no live srcdoc left behind' );
		// The invariant that matters, and the only one that catches a pass
		// rewriting the document INSIDE the value: the parked token is the
		// original token, byte for byte, quoting included. The earlier shape
		// searched for an injected attribute behind a `data-faz-srcdoc="`
		// prefix, which a single-quoted value can never match — so for that
		// case the check could not fail at all.
		assert_true(
			false !== strpos( $out, 'data-faz-srcdoc=' . $quote . $value . $quote ),
			$label . ': the parked value is the original, byte for byte'
		);
	}

	// The control that keeps the protection from becoming a blunt instrument: a
	// srcdoc that names nothing blocked stays a live srcdoc, byte for byte.
	$fe   = $faz_content_frontend( $providers, $blocked );
	$html = '<p>x</p><iframe srcdoc="<p>hi</p><img src=&quot;/local.png&quot;>"></iframe>';
	assert_true( $faz_content_run( $fe, $html ) === $html, 'a first-party srcdoc is returned untouched' );

	// Only FAZ-signed parks are eligible for runtime activation.
	$fe = $faz_content_frontend( $providers, $blocked );
	$forged = '<iframe data-faz-category="marketing" data-faz-srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>';
	$out = $faz_content_run( $fe, $forged );
	assert_true( false === strpos( $out, ' data-faz-srcdoc=' ), 'forged parked input is demoted' );
	assert_true( false === strpos( $out, ' srcdoc=' ), 'forged parked input never becomes live' );
	$parked = $faz_content_run( $fe, '<iframe srcdoc="<img src=&quot;https://cdn.tracker.test/p.png&quot;>"></iframe>' );
	assert_true( false !== strpos( $parked, 'data-faz-srcdoc-sig=' ), 'server park carries a signature' );
	assert_true( false !== strpos( $parked, 'data-faz-srcdoc-key=' ), 'server park carries the response proof' );
	assert_true( $faz_content_run( $fe, $parked ) === $parked, 'signed park survives a second pass byte for byte' );
	$refresh = new ReflectionMethod( Frontend::class, 'refresh_srcdoc_proofs' );
	$refresh->setAccessible( true );
	$old_proof = preg_replace( '/data-faz-srcdoc-key="[^"]*"/', 'data-faz-srcdoc-key="previous-response"', $parked );
	$refreshed = $refresh->invoke( $fe, $old_proof );
	assert_true( false !== strpos( $refreshed, ' data-faz-srcdoc=' ) && false === strpos( $refreshed, 'previous-response' ), 'signed cached fragments receive the current proof even without blocking' );
	$tampered = str_replace( 'data-faz-category="marketing"', 'data-faz-category="necessary"', $parked );
	assert_true( false === strpos( $faz_content_run( $fe, $tampered ), ' data-faz-srcdoc=' ), 'category tampering invalidates the signature' );
	$tampered = str_replace( '/p.png', '/other.png', $parked );
	assert_true( false === strpos( $faz_content_run( $fe, $tampered ), ' data-faz-srcdoc=' ), 'document tampering invalidates the signature' );

	// Query Monitor uses an arbitrary id rather than WP's js-extra suffix.
	$fe = $faz_content_frontend( array( 'gtag(' => 'marketing', 'google_gtagjs-js' => 'marketing' ), $blocked );
	$data = '<script id="query-monitor-inline-data">var QueryMonitorData = {"handles":["gtag(","google_gtagjs-js"]};</script>';
	assert_true( $faz_content_run( $fe, $data ) === $data, 'data-only config remains executable under any script id' );
	$executable = str_replace( ';</script>', ';gtag("config","x");</script>', $data );
	assert_true( false !== strpos( $faz_content_run( $fe, $executable ), 'type="text/plain"' ), 'a data assignment followed by tracking code is still blocked' );
	$json_check = new ReflectionMethod( Frontend::class, 'is_json_data_assignment' );
	$json_check->setAccessible( true );
	foreach ( array( 'var x={"gtag(":1};gtag("config","x");', 'var x=fetch("https://tracker.test");', 'var x={"f":function(){gtag()}};' ) as $code ) {
		assert_true( ! $json_check->invoke( $fe, $code ), 'executable code is never exempted as JSON data' );
	}

	// ---------- result ----------

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "ALL PASS ({$tests_passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$tests_failed}, passed: {$tests_passed}\n";
	exit( 1 );
}
