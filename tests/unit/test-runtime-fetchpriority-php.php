<?php
/**
 * Frontend::prioritize_runtime_script_tag(): the consent runtime, and only the
 * runtime, is fetched with fetchpriority="high" (wp.org topic
 * render-blocking-requests-2: the banner is the LCP element and painted only
 * after a low-priority deferred download).
 *
 * Run: php tests/unit/test-runtime-fetchpriority-php.php
 */
define( 'ABSPATH', __DIR__ . '/' );
function is_admin() { return ! empty( $GLOBALS['rfp_admin'] ); }
function apply_filters( $tag, $value ) {
	return 'faz_runtime_fetchpriority' === $tag && isset( $GLOBALS['rfp_filter'] ) ? $GLOBALS['rfp_filter'] : $value;
}
require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

$frontend = ( new ReflectionClass( \FazCookie\Frontend\Frontend::class ) )->newInstanceWithoutConstructor();
$name     = new ReflectionProperty( \FazCookie\Frontend\Frontend::class, 'plugin_name' );
$name->setAccessible( true );
$name->setValue( $frontend, 'faz-cookie-manager' );

$run  = 0;
$fail = 0;
function rfp_check( $ok, $label ) {
	global $run, $fail;
	++$run;
	echo ( $ok ? "  \033[32m✓\033[0m " : "  \033[31m✗\033[0m " ) . $label . "\n";
	if ( ! $ok ) {
		++$fail;
	}
}

// What WordPress prints for the handle: the localized data, the inline
// bootstrap, then the external runtime.
$composite = "<script id=\"faz-cookie-manager-js-extra\">var _fazConfig = {};</script>\n"
	. "<script id=\"faz-cookie-manager-js-before\">window._fazBootstrap = {};</script>\n"
	. "<script defer id=\"faz-cookie-manager-js\" src=\"https://example.test/wp-content/plugins/faz-cookie-manager/frontend/js/script.min.js?ver=1\" data-wp-strategy=\"defer\"></script>\n";

$out = $frontend->prioritize_runtime_script_tag( $composite, 'faz-cookie-manager' );
rfp_check( 1 === substr_count( $out, 'fetchpriority="high"' ), 'exactly one fetchpriority attribute is added' );
rfp_check( 1 === preg_match( '#<script fetchpriority="high" defer id="faz-cookie-manager-js" src=#', $out ), 'it goes on the external runtime tag' );
rfp_check( false !== strpos( $out, '<script id="faz-cookie-manager-js-before">' ), 'the inline bootstrap is untouched' );
rfp_check( false !== strpos( $out, '<script id="faz-cookie-manager-js-extra">' ), 'the localized config is untouched' );
rfp_check( $out === $frontend->prioritize_runtime_script_tag( $out, 'faz-cookie-manager' ), 'running twice does not add it twice' );

$config = '<script defer id="faz-cookie-manager-static-config-js" src="https://example.test/config.js"></script>';
rfp_check( $config === $frontend->prioritize_runtime_script_tag( $config, 'faz-cookie-manager-static-config' ), 'the config file keeps its priority' );

$other = '<script defer id="jquery-core-js" src="https://example.test/jquery.js"></script>';
rfp_check( $other === $frontend->prioritize_runtime_script_tag( $other, 'jquery-core' ), 'other handles are untouched' );

$own = str_replace( '<script defer id="faz-cookie-manager-js"', '<script fetchpriority="low" defer id="faz-cookie-manager-js"', $composite );
rfp_check( $own === $frontend->prioritize_runtime_script_tag( $own, 'faz-cookie-manager' ), 'a priority someone else set is respected' );

$inline = "<script id=\"faz-cookie-manager-js\">/* alternative-asset inline bundle */</script>\n";
rfp_check( $inline === $frontend->prioritize_runtime_script_tag( $inline, 'faz-cookie-manager' ), 'the inline alternative-asset bundle is untouched' );

$GLOBALS['rfp_filter'] = false;
rfp_check( $composite === $frontend->prioritize_runtime_script_tag( $composite, 'faz-cookie-manager' ), 'faz_runtime_fetchpriority false turns it off' );
unset( $GLOBALS['rfp_filter'] );

$GLOBALS['rfp_admin'] = true;
rfp_check( $composite === $frontend->prioritize_runtime_script_tag( $composite, 'faz-cookie-manager' ), 'admin screens are untouched' );

echo "\nruntime-fetchpriority: {$run} checks, {$fail} failed\n";
exit( $fail ? 1 : 0 );
