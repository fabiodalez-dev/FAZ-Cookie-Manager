<?php
/**
 * {{COOKIE_POLICY_URL}} keeps the request path once on a subdirectory install.
 *
 * Renderer::current_url() passed the full REQUEST_URI path to home_url(),
 * which appends it to the home path: on https://example.com/blog the policy
 * at /blog/cookie-policy/ became https://example.com/blog/blog/cookie-policy/.
 * The host still comes from home_url(), never from the Host header, and the
 * query string and fragment are still dropped.
 *
 * Run: php tests/unit/test-policy-current-url-php.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['faz_pcu_home'] = 'https://example.com/blog';
function home_url( $path = '' ) { return $GLOBALS['faz_pcu_home'] . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_unslash( $v ) { return $v; }
function apply_filters( $tag, $value ) { return $value; }
function add_filter() { return true; }
function add_action() { return true; }

require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-renderer.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;

$pass = 0;
$fail = 0;
function pcu_ok( $actual, $expected, $label ) {
	global $pass, $fail;
	if ( $actual === $expected ) { ++$pass; echo "  [PASS] $label\n"; }
	else { ++$fail; echo "  [FAIL] $label\n      expected: $expected\n      actual:   $actual\n"; }
}
function pcu_url( $request_uri, $home = null ) {
	if ( null !== $home ) {
		$GLOBALS['faz_pcu_home'] = $home;
	}
	$_SERVER['REQUEST_URI'] = $request_uri;
	$_SERVER['HTTP_HOST']   = 'evil.example';
	$m = new ReflectionMethod( Renderer::class, 'current_url' );
	$m->setAccessible( true );
	return $m->invoke( null );
}

echo "\n== Cookie policy current URL ==\n\n";

pcu_ok( pcu_url( '/blog/cookie-policy/' ), 'https://example.com/blog/cookie-policy/', '01 a subdirectory install keeps its path once' );
pcu_ok( pcu_url( '/blog/cookie-policy/?preview_id=1&preview_nonce=abc#x' ), 'https://example.com/blog/cookie-policy/', '02 query and fragment are dropped' );
pcu_ok( pcu_url( '/cookie-policy/', 'https://example.com' ), 'https://example.com/cookie-policy/', '03 a root install is unchanged' );
pcu_ok( pcu_url( '/cookie-policy/', 'http://example.com:8080' ), 'http://example.com:8080/cookie-policy/', '04 the home port is kept' );
pcu_ok( pcu_url( '//evil.test/x', 'https://example.com' ), 'https://example.com/x', '05 a protocol-relative path cannot change the host' );

echo "\nPassed: $pass\nFailed: $fail\n";
exit( $fail > 0 ? 1 : 0 );
