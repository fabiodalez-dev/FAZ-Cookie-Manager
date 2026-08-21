<?php
/** Standalone tests for scanner CURLOPT_RESOLVE entry construction. */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { return abs( (int) $value ); }
}
require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';

use FazCookie\Admin\Modules\Scanner\Includes\Controller;

$failed = 0;
function faz_static_ip_check( $label, $actual, $expected ) {
	global $failed;
	if ( $actual === $expected ) { echo "PASS: {$label}\n"; return; }
	$failed++;
	echo "FAIL: {$label}\n  expected " . var_export( $expected, true ) . "\n  got " . var_export( $actual, true ) . "\n";
}

faz_static_ip_check( 'public IPv4 HTTPS default port', Controller::build_static_resolve_entry( 'https://example.com/path', '93.184.216.34' ), 'example.com:443:93.184.216.34' );
faz_static_ip_check( 'explicit HTTP port', Controller::build_static_resolve_entry( 'http://EXAMPLE.com:8080/path', '8.8.8.8' ), 'example.com:8080:8.8.8.8' );
faz_static_ip_check( 'public IPv6 is bracketed', Controller::build_static_resolve_entry( 'https://example.com/', '2606:4700:4700::1111' ), 'example.com:443:[2606:4700:4700::1111]' );
faz_static_ip_check( 'private IPv4 is rejected', Controller::build_static_resolve_entry( 'https://example.com/', '192.168.1.10' ), '' );
faz_static_ip_check( 'loopback is rejected', Controller::build_static_resolve_entry( 'https://example.com/', '127.0.0.1' ), '' );
faz_static_ip_check( 'non-HTTP scheme is rejected', Controller::build_static_resolve_entry( 'ftp://example.com/', '8.8.8.8' ), '' );

echo $failed ? "{$failed} failed\n" : "ALL PASS\n";
exit( $failed ? 1 : 0 );
