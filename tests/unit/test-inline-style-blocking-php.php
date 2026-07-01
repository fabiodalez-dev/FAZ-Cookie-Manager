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

	if ( 0 === $failed ) {
		echo "\033[32mALL PASS\033[0m - {$passed}/{$run}\n";
		exit( 0 );
	}
	echo "\033[31m{$failed} FAILED\033[0m - {$passed}/{$run} passed\n";
	exit( 1 );
}
