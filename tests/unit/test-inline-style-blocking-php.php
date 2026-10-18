<?php
/**
 * Standalone unit tests for inline CSS url() blocking.
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $value ) {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	$run    = 0;
	$passed = 0;
	$failed = 0;

	function ok( $condition, $label ) {
		global $run, $passed, $failed;
		$run++;
		if ( $condition ) {
			$passed++;
			echo "  \033[32mPASS\033[0m {$label}\n";
		} else {
			$failed++;
			echo "  \033[31mFAIL\033[0m {$label}\n";
		}
	}

	function eq( $actual, $expected, $label ) {
		ok( $actual === $expected, $label );
		if ( $actual !== $expected ) {
			echo '       expected: ' . var_export( $expected, true ) . "\n";
			echo '       actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	function fe_for_inline_style_test() {
		$rc = new \ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		foreach ( array(
			'whitelist_cache'       => array(),
			'service_consent_cache' => array(),
			'pattern_service_cache' => array(),
		) as $prop => $value ) {
			if ( $rc->hasProperty( $prop ) ) {
				$p = $rc->getProperty( $prop );
				$p->setAccessible( true );
				$p->setValue( $fe, $value );
			}
		}
		return $fe;
	}

	function process_style( $css, $blocked = array( 'functional' ) ) {
		$fe = fe_for_inline_style_test();
		$m  = array(
			'<style id="theme-fonts">' . $css . '</style>',
			' id="theme-fonts"',
			$css,
		);
		$providers = array(
			'fonts.gstatic.com'    => 'functional',
			'fonts.googleapis.com' => 'functional',
			'facebook.com'         => 'marketing',
		);
		$method = new \ReflectionMethod( Frontend::class, 'process_style_tag' );
		$method->setAccessible( true );
		return $method->invoke( $fe, $m, $providers, $blocked );
	}

	function data_faz_css( $html ) {
		if ( ! preg_match( '/data-faz-css="([^"]+)"/', $html, $m ) ) {
			return '';
		}
		$decoded = base64_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
		return false === $decoded ? '' : $decoded;
	}

	$font_url = 'https://fonts.gstatic.com/s/roboto/v30/KFOmCnqEu92Fr1Mu4mxK.woff2';
	$css      = '@font-face{font-family:"FazLeak";src:url("' . $font_url . '") format("woff2");}.probe{background:url(/wp-content/a.png);color:#123}';
	$out      = process_style( $css );

	ok( false !== strpos( $out, 'data-faz-css=' ), 'blocked inline style stores original CSS' );
	ok( false !== strpos( $out, 'data-faz-category="functional"' ), 'blocked inline style is tagged with provider category' );
	ok( false === strpos( $out, 'src:url("' . $font_url . '")' ), 'live CSS no longer exposes the blocked font URL' );
	ok( false !== strpos( $out, 'data:application/octet-stream,' ), 'blocked font URL is replaced with inert data URL' );
	ok( false !== strpos( $out, 'url(/wp-content/a.png)' ), 'same-origin CSS URL is left intact' );
	eq( data_faz_css( $out ), $css, 'data-faz-css decodes to the exact original CSS' );

	$import_css = '@import "https://fonts.googleapis.com/css?family=Roboto"; body{font-family:Roboto}';
	$import_out = process_style( $import_css );
	ok( false !== strpos( $import_out, '@import url("data:application/octet-stream,")' ), '@import string URL is neutralized' );
	eq( data_faz_css( $import_out ), $import_css, '@import original CSS is recoverable' );

	$allowed = process_style( $css, array() );
	eq( $allowed, '<style id="theme-fonts">' . $css . '</style>', 'style is untouched when category is not blocked' );

	// ---------- the forms the engine reads that a regex over the raw text did not ----------
	//
	// The runtime and the bootstrap read four more forms than `url(...)` and a
	// whitespace-separated `@import "..."`: an @import with NO whitespace (what
	// esbuild and critical-CSS inliners emit), a url( left open, the string
	// arguments of image-set()/src(), and all of them again with CSS escapes
	// decoded. A server-rendered <style> is judged by PHP alone — nothing on
	// the client re-reads a parser-built sheet — so each of these shipped live
	// and the @import fetched Google Fonts at parse time with no consent.
	// What the in-place rewrite cannot reach parks the sheet WHOLE: empty live
	// text, the original in data-faz-css, restored on consent.
	$live_css = function ( $html ) {
		return preg_match( '#<style[^>]*>(.*)</style>#s', $html, $m ) ? $m[1] : null;
	};

	$tight = '@import"https://fonts.googleapis.com/css2?family=Inter";body{margin:0}';
	$out   = process_style( $tight );
	ok( false !== strpos( $out, 'data-faz-css=' ), 'a tight @import (no whitespace) is parked' );
	ok( false === strpos( (string) $live_css( $out ), 'fonts.googleapis.com' ), 'and the provider URL is gone from the live text' );
	ok( false !== strpos( $out, '@import url("data:application/octet-stream,")' ), 'rewritten in place, with the rest of the sheet kept' );
	eq( data_faz_css( $out ), $tight, 'the tight @import original is recoverable' );

	// A comment between @import and its string is nothing to the tokenizer,
	// so this imports Google Fonts; no pattern read it while the comment sat
	// in the way.
	$commented = '@import/*c*/"https://fonts.googleapis.com/css2?family=Inter";body{margin:0}';
	$out       = process_style( $commented );
	ok( false !== strpos( $out, 'data-faz-css=' ), 'an @import with a comment before its string is parked' );
	ok( false === strpos( (string) $live_css( $out ), 'fonts.googleapis.com' ), 'and the provider URL is gone from the live text' );
	eq( data_faz_css( $out ), $commented, 'and the original is recoverable' );
	// The comment-stripped pass only ADDS URLs: `/*` inside an unquoted url()
	// is URL text, inside an unterminated string it starts nothing, and a
	// stripper that removed from there to a later `*/` deleted the next,
	// escape-spelled provider URL from the only pass able to read it.
	foreach ( array(
		'/* inside an unquoted url() before an escaped provider url()' => '.a{background:u\72l(https://cdn.example/*x)}.b{background:u\72l(https://fonts.gstatic.com/x.woff2)}/**/',
		'a bad string before an escaped @import'                       => ".q{content:\"/*\n}@\\69mport \"https://fonts.googleapis.com/css2?family=Inter\";/**/",
	) as $label => $hidden ) {
		$out = process_style( $hidden );
		ok( false !== strpos( $out, 'data-faz-css=' ), $label . ': parked' );
		ok( false === strpos( (string) $live_css( $out ), 'fonts.g' ), $label . ': the provider URL is gone from the live text' );
	}
	$quoted = '.q:before{content:"/*"}.r:after{content:"*/ https://fonts.googleapis.com/x"}';
	ok( false === strpos( process_style( $quoted ), 'data-faz-css=' ), 'a provider URL written inside a string is content, not a fetch' );
	// The markers themselves: between two strings holding `/*` and `*/` sits
	// a URL only the comment-stripped pass can read (an @import with a comment
	// before its string, still the first rule after @charset). A stripper
	// that took the markers for a comment removed that URL along with them,
	// and the sheet stayed live.
	$quoted = '@charset "/*";@import/*c*/"https://fonts.googleapis.com/css2?family=Inter";.s:after{content:"*/"}';
	$out    = process_style( $quoted );
	ok( false !== strpos( $out, 'data-faz-css=' ), 'comment markers inside strings are content, not a comment: the URL between them is still judged' );
	ok( false === strpos( (string) $live_css( $out ), 'fonts.g' ), 'and the provider URL is gone from the live text' );

	$escaped_fn = '@font-face{font-family:x;src:u\72l(https://fonts.gstatic.com/x.woff2)}';
	$out        = process_style( $escaped_fn );
	ok( false !== strpos( $out, 'data-faz-css=' ), 'a url() spelled through a CSS escape is parked' );
	eq( $live_css( $out ), '', 'WHOLE: the live text is empty, because the in-place rewrite cannot reach the escape' );
	eq( data_faz_css( $out ), $escaped_fn, 'and the original is recoverable' );

	$escaped_host = '@font-face{src:url(https://fonts.gstatic\.com/x.woff2)}';
	$out          = process_style( $escaped_host );
	ok( false !== strpos( $out, 'data-faz-css=' ), 'an escape in the host is seen through' );
	ok( false === strpos( (string) $live_css( $out ), 'gstatic' ), 'and the URL is gone from the live text' );

	$image_set = '.p{background-image:image-set("https://www.facebook.com/tr?id=1" 1x, url(/local.png) 2x)}';
	$out       = process_style( $image_set, array( 'functional', 'marketing' ) );
	ok( false !== strpos( $out, 'data-faz-css=' ), 'an image-set() string naming a provider is parked' );
	ok( false === strpos( (string) $live_css( $out ), 'facebook.com' ), 'and gone from the live text' );
	ok( false !== strpos( (string) $live_css( $out ), 'url(/local.png)' ), 'while the first-party candidate next to it stays' );

	$open = '.p{background:url(https://fonts.gstatic.com/x.woff2';
	$out  = process_style( $open );
	ok( false !== strpos( $out, 'data-faz-css=' ), 'a url( left open is read to the end and parked' );
	eq( $live_css( $out ), '', 'whole, since there is no closing paren to rewrite' );

	// Controls: the same spellings with nothing to block are left alone.
	$clean = '.p{background:u\72l(/wp-content/a.png)}@import"/theme.css";';
	eq( process_style( $clean ), '<style id="theme-fonts">' . $clean . '</style>', 'first-party escapes and a tight first-party @import are left alone' );
	eq( process_style( $tight, array() ), '<style id="theme-fonts">' . $tight . '</style>', 'a tight @import whose category is allowed is left alone' );

	// ---------- a long string inside image-set()/src()/url() ----------
	//
	// The nested-body and string patterns matched one character per
	// iteration, so a quoted base64 data URI of a few KB exhausted the PCRE
	// stack (JIT on: ~9 KB; JIT off: ~5 KB). preg_replace_callback() returned
	// null and the function returned the ORIGINAL text with no category: the
	// @import neutralised by the pass before was thrown away and Google Fonts
	// loaded with no consent. The residual reader had the same patterns, so a
	// long url("data:…") made it drop every url() it should have judged.
	$long_payload = 'data:image/png;base64,' . str_repeat( 'iVBORw0KGgoAAAANSUhEUg', 2000 );
	foreach ( array( '1', '0' ) as $jit ) {
		ini_set( 'pcre.jit', $jit );
		$label = 'pcre.jit=' . $jit . ': ';

		$big = '@import url(https://fonts.googleapis.com/css?family=X);.a{background-image:image-set("' . $long_payload . '" 1x)}';
		$out = process_style( $big );
		ok( false !== strpos( $out, 'data-faz-css=' ), $label . 'a long image-set() string does not undo the @import neutralisation' );
		ok( false === strpos( (string) $live_css( $out ), 'fonts.googleapis.com' ), $label . 'and the provider URL is gone from the live text' );
		ok( data_faz_css( $out ) === $big, $label . 'the original is recoverable' );

		$big_src = '@font-face{font-family:F;src:url(https://fonts.gstatic.com/f.woff2)}.b{content:src(\'' . $long_payload . '\')}';
		$out     = process_style( $big_src );
		ok( false === strpos( (string) $live_css( $out ), 'fonts.gstatic.com' ), $label . 'a long src() string does not undo the url() neutralisation' );

		// The residual reader: an escaped url( only the decoded copy shows,
		// next to a long quoted data URI.
		$big_residual = '.c{background:url("' . $long_payload . '")}.d{background:u\72l("https://fonts.googleapis.com/x.png")}';
		$out          = process_style( $big_residual );
		ok( false !== strpos( $out, 'data-faz-css=' ), $label . 'an escaped provider url( next to a long quoted data URI is still judged and parked' );
		ok( '' === $live_css( $out ), $label . 'whole, since only the decoded copy names it' );

		// Control: a long first-party data URI alone is left alone.
		$big_clean = '.e{background-image:image-set("' . $long_payload . '" 1x)}';
		ok( process_style( $big_clean ) === '<style id="theme-fonts">' . $big_clean . '</style>', $label . 'a long data URI with nothing to block is left alone' );

		// An unquoted url( of 2 MB before an escaped provider url(, and a url(
		// left open after a long quoted data URI: the lazy and greedy bodies
		// these went through exhausted the backtrack limit.
		$huge = str_repeat( 'a', 2000000 );
		$out  = process_style( '.f{background:url(' . $huge . ')}.g{background:u\72l(https://fonts.googleapis.com/x.png)}' );
		ok( false !== strpos( $out, 'data-faz-css=' ) && '' === $live_css( $out ), $label . 'an escaped provider url( after a 2 MB unquoted url( is parked whole' );
		$out = process_style( '.h{background:url("' . str_repeat( $long_payload, 50 ) . '")}.i{background:url(https://fonts.googleapis.com/q' );
		ok( false !== strpos( $out, 'data-faz-css=' ) && '' === $live_css( $out ), $label . 'a provider url( left open after a 2 MB quoted data URI is parked whole' );
	}
	ini_restore( 'pcre.jit' );

	// The last resort: a PCRE limit no pattern can stay under. The text is
	// parked whole when it names a blocked provider, and left alone when it
	// names none — never returned live with the provider in it.
	$neutralize = function ( $css ) {
		$fe     = fe_for_inline_style_test();
		$method = new \ReflectionMethod( Frontend::class, 'neutralize_blocked_css_urls' );
		$method->setAccessible( true );
		$cats   = array();
		$out    = $method->invokeArgs( $fe, array( $css, array( 'fonts.googleapis.com' => 'functional' ), array( 'functional' ), &$cats ) );
		return array( $out, $cats );
	};
	ini_set( 'pcre.jit', '0' );
	ini_set( 'pcre.backtrack_limit', '1' );
	list( $limited, $limited_cats ) = $neutralize( '@import url(https://fonts.googleapis.com/css?family=X);.a{color:red}' );
	list( $limited_clean, $limited_clean_cats ) = $neutralize( '.a{background:url(/local.png)}' );
	ini_restore( 'pcre.backtrack_limit' );
	ini_restore( 'pcre.jit' );
	ok( '' === $limited && array( 'functional' ) === $limited_cats, 'every pass failing on a PCRE limit parks a sheet that names a blocked provider' );
	ok( '.a{background:url(/local.png)}' === $limited_clean && array() === $limited_clean_cats, 'and leaves a sheet that names none as it was' );


	if ( 0 === $failed ) {
		echo "\033[32mALL PASS\033[0m - {$passed}/{$run}\n";
		exit( 0 );
	}
	echo "\033[31m{$failed} FAILED\033[0m - {$passed}/{$run} passed\n";
	exit( 1 );
}
