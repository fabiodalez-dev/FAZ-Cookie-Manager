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
	ok( false === strpos( process_style( $quoted ), 'data-faz-css=' ), 'comment markers inside strings are content, not a comment' );

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

	if ( 0 === $failed ) {
		echo "\033[32mALL PASS\033[0m - {$passed}/{$run}\n";
		exit( 0 );
	}
	echo "\033[31m{$failed} FAILED\033[0m - {$passed}/{$run} passed\n";
	exit( 1 );
}
