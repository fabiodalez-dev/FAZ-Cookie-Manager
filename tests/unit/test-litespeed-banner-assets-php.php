<?php
/**
 * Regression coverage for LiteSpeed banner CSS exclusions.
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function is_admin() {
		return ! empty( $GLOBALS['faz_ls_is_admin'] );
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	$passed = 0;
	$failed = 0;

	function faz_ls_check( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) {
			++$passed;
			echo "  [PASS] {$label}\n";
			return;
		}
		++$failed;
		echo "  [FAIL] {$label}\n";
	}

	$frontend = ( new \ReflectionClass( Frontend::class ) )->newInstanceWithoutConstructor();

	$own_link = '<link rel="stylesheet" id="faz-cookie-manager-css-css" href="/wp-content/uploads/faz-cookie-manager/assets/banner-a.css" media="all" />';
	$tagged   = $frontend->tag_own_styles_nooptimize( $own_link, 'faz-cookie-manager-css' );
	faz_ls_check( false !== strpos( $tagged, 'data-no-optimize="1"' ), 'external banner CSS carries LiteSpeed no-optimize' );
	faz_ls_check( false !== strpos( $tagged, 'data-noptimize="1"' ), 'external banner CSS carries the compatibility spelling' );
	faz_ls_check( 1 === substr_count( $frontend->tag_own_styles_nooptimize( $tagged, 'faz-cookie-manager-css' ), 'data-no-optimize' ), 'external style tagging is idempotent' );

	$foreign = '<link rel="stylesheet" id="theme-css" href="/theme.css" />';
	faz_ls_check( $foreign === $frontend->tag_own_styles_nooptimize( $foreign, 'theme' ), 'foreign styles remain untouched' );

	$excludes = $frontend->litespeed_exclude_own_scripts( array( 'theme.css' ) );
	faz_ls_check( in_array( 'theme.css', $excludes, true ), 'existing LiteSpeed CSS exclusions survive' );
	faz_ls_check( in_array( 'plugins/faz-cookie-manager/', $excludes, true ), 'bundled FAZ assets are excluded by path' );
	faz_ls_check( in_array( 'faz-cookie-manager/assets/', $excludes, true ), 'generated banner assets are excluded by path' );

	$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
	faz_ls_check( false !== strpos( $source, "add_filter( 'litespeed_optimize_css_excludes'" ), 'LiteSpeed CSS exclusion API is registered' );
	faz_ls_check( false !== strpos( $source, "'/* faz-cookie-manager/assets/ */' . \$css" ), 'inline CSS fallback carries an exclusion marker' );

	echo "LiteSpeed banner assets: {$passed} passed, {$failed} failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
