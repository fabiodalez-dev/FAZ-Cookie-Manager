<?php
/**
 * HTTPS asset URL regression coverage for TLS-terminating proxies.
 *
 * Loads the real bootstrap only through its first add_action() call, after the
 * URL constants and helpers are defined but before the plugin autoloader starts.
 * This keeps the test standalone while exercising the shipped functions rather
 * than a transcription of them.
 *
 * Run: php tests/unit/test-proxy-https-assets-php.php
 */

define( 'WPINC', 'wp-includes' );

$GLOBALS['faz_proxy_siteurl'] = 'http://origin.example.test';
$GLOBALS['faz_proxy_ssl']     = false;

function plugin_basename( $file ) { return basename( $file ); }
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/\\' ) . '/'; }
function plugin_dir_url( $file ) { return 'http://origin.example.test/wp-content/plugins/faz-cookie-manager/'; }
function get_option( $name, $default = false ) {
	if ( 'WPLANG' === $name ) { return 'en_US'; }
	if ( 'siteurl' === $name ) { return $GLOBALS['faz_proxy_siteurl']; }
	return $default;
}
function is_ssl() { return (bool) $GLOBALS['faz_proxy_ssl']; }
function wp_unslash( $value ) { return $value; }
function set_url_scheme( $url, $scheme = null ) {
	if ( null === $scheme ) { $scheme = is_ssl() ? 'https' : 'http'; }
	return preg_replace( '#^https?://#i', $scheme . '://', $url );
}
function add_filter() { return true; }

class Faz_Proxy_Stop_Bootstrap extends RuntimeException {}
function add_action() { throw new Faz_Proxy_Stop_Bootstrap(); }

$_SERVER['HTTP_X_FORWARDED_PROTO'] = ' HTTPS ';
try {
	require dirname( __DIR__, 2 ) . '/faz-cookie-manager.php';
} catch ( Faz_Proxy_Stop_Bootstrap $e ) {
	// Expected: constants/helpers are loaded; the rest of WordPress is not.
}

$passed = 0;
$failed = 0;
function proxy_check( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) { ++$passed; echo "  \033[32mPASS\033[0m {$label}\n"; return; }
	++$failed; echo "  \033[31mFAIL\033[0m {$label}\n";
}

proxy_check( faz_request_is_https(), 'a normalized X-Forwarded-Proto HTTPS signal is recognized' );
proxy_check(
	'https://origin.example.test/wp-content/plugins/faz-cookie-manager/' === FAZ_PLUGIN_URL,
	'the plugin base URL is forced to HTTPS behind the proxy'
);
proxy_check(
	'https://origin.example.test/wp-content/plugins/faz-cookie-manager/frontend/images/' === FAZ_APP_ASSETS_URL,
	'the image asset constant uses the same corrected scheme'
);
proxy_check(
	FAZ_PLUGIN_URL . 'frontend/' === faz_frontend_url(),
	'all frontend enqueue call sites can share the corrected base'
);

unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
proxy_check( ! faz_request_is_https(), 'plain HTTP with an HTTP siteurl remains HTTP' );

$GLOBALS['faz_proxy_siteurl'] = 'https://origin.example.test';
proxy_check( faz_request_is_https(), 'a server-controlled HTTPS siteurl is recognized' );

$GLOBALS['faz_proxy_siteurl'] = 'http://origin.example.test';
$GLOBALS['faz_proxy_ssl']     = true;
proxy_check( faz_request_is_https(), 'native is_ssl remains the first HTTPS signal' );

echo "\nproxy HTTPS assets: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
