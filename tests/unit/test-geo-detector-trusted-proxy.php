<?php
/**
 * Standalone unit tests for Geo_Detector trusted-proxy validation.
 *
 * Regression guard for the 2026-05-20 HIGH-severity fix: CF-Connecting-IP
 * is only honoured when REMOTE_ADDR is inside Cloudflare's published
 * proxy IP ranges. Direct-origin attacker spoofing of the header must
 * be rejected.
 *
 * Run:
 *   php tests/unit/test-geo-detector-trusted-proxy.php
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
define( 'FAZ_VERSION', '1.15.0-test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['_faz_test_options'] = array();
$GLOBALS['_faz_test_cache']   = array();
$GLOBALS['_faz_test_filters'] = array();

function get_option( $key, $default = false ) {
	return isset( $GLOBALS['_faz_test_options'][ $key ] ) ? $GLOBALS['_faz_test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) { $GLOBALS['_faz_test_options'][ $key ] = $value; return true; }
function wp_cache_get( $key, $group = '' ) {
	$k = "$group:$key";
	return isset( $GLOBALS['_faz_test_cache'][ $k ] ) ? $GLOBALS['_faz_test_cache'][ $k ] : false;
}
function wp_cache_set( $key, $val, $group = '', $ttl = 0 ) { $GLOBALS['_faz_test_cache'][ "$group:$key" ] = $val; return true; }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function apply_filters( $hook, $val ) {
	if ( isset( $GLOBALS['_faz_test_filters'][ $hook ] ) ) {
		return call_user_func( $GLOBALS['_faz_test_filters'][ $hook ], $val );
	}
	return $val;
}
function wp_remote_get( $url, $args = array() ) { return new WP_Error( 'stub', 'no HTTP' ); }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function wp_remote_retrieve_body( $r ) { return ''; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class WP_Error {
	public function __construct( $code = '', $message = '' ) {}
	public function get_error_message() { return ''; }
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-secrets.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-ipinfo-client.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-geo-detector.php';

use FazCookie\Admin\Modules\Geo_Routing\Includes\Geo_Detector;

$tests_run = $tests_passed = $tests_failed = 0;

function assert_eq( $a, $e, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $a === $e ) { $tests_passed++; echo "  \033[32m✓\033[0m $label\n"; }
	else { $tests_failed++; echo "  \033[31m✗\033[0m $label\n      expected: " . var_export( $e, true ) . "\n      actual:   " . var_export( $a, true ) . "\n"; }
}
function assert_true( $c, $l ) { assert_eq( (bool) $c, true, $l ); }
function assert_false( $c, $l ) { assert_eq( (bool) $c, false, $l ); }

echo "\n== Geo_Detector — trusted-proxy + CIDR matcher (HIGH SP1-S008 fix) ==\n\n";

$detector = new Geo_Detector();
$ref      = new ReflectionClass( $detector );

$ip_in_cidr      = $ref->getMethod( 'ip_in_cidr' );      $ip_in_cidr->setAccessible( true );
$is_trusted      = $ref->getMethod( 'is_trusted_proxy' ); $is_trusted->setAccessible( true );
$resolve_client  = $ref->getMethod( 'resolve_client_ip' );$resolve_client->setAccessible( true );

// ---------- ip_in_cidr() ----------

// Cloudflare ranges
assert_true(  $ip_in_cidr->invoke( $detector, '104.16.5.10',  '104.16.0.0/13' ),  '104.16.5.10 ∈ 104.16.0.0/13 (CF range)' );
assert_true(  $ip_in_cidr->invoke( $detector, '104.16.0.0',   '104.16.0.0/13' ),  'Network address ∈ /13' );
assert_true(  $ip_in_cidr->invoke( $detector, '104.23.255.255','104.16.0.0/13' ), 'Top of /13 ∈ range' );
assert_false( $ip_in_cidr->invoke( $detector, '104.24.0.0',   '104.16.0.0/13' ),  'One past /13 ∉ range' );

assert_true(  $ip_in_cidr->invoke( $detector, '172.65.0.1',   '172.64.0.0/13' ),  '172.65.0.1 ∈ 172.64.0.0/13' );
assert_false( $ip_in_cidr->invoke( $detector, '172.72.0.1',   '172.64.0.0/13' ),  '172.72.0.1 ∉ 172.64.0.0/13' );

// Non-CF IPs (DigitalOcean, residential)
assert_false( $ip_in_cidr->invoke( $detector, '8.8.8.8',      '104.16.0.0/13' ),  'Google DNS ∉ CF range' );
assert_false( $ip_in_cidr->invoke( $detector, '192.168.1.1',  '104.16.0.0/13' ),  'RFC1918 ∉ CF range' );

// /32 single host
assert_true(  $ip_in_cidr->invoke( $detector, '203.0.113.42', '203.0.113.42/32' ), '/32 exact match' );
assert_false( $ip_in_cidr->invoke( $detector, '203.0.113.41', '203.0.113.42/32' ), '/32 neighbour ∉ range' );

// IPv6
assert_true(  $ip_in_cidr->invoke( $detector, '2606:4700::1', '2606:4700::/32' ), 'CF v6 prefix match' );
assert_false( $ip_in_cidr->invoke( $detector, '2606:4701::1', '2606:4700::/32' ), 'Adjacent v6 prefix ∉ /32' );

// Malformed inputs
assert_false( $ip_in_cidr->invoke( $detector, 'not.an.ip',    '104.16.0.0/13' ),  'Garbage IP → false' );
assert_false( $ip_in_cidr->invoke( $detector, '8.8.8.8',      '104.16.0.0' ),     'CIDR without prefix → false' );
assert_false( $ip_in_cidr->invoke( $detector, '8.8.8.8',      '104.16.0.0/33' ),  'Prefix > 32 → false' );
assert_false( $ip_in_cidr->invoke( $detector, '8.8.8.8',      '104.16.0.0/-1' ),  'Negative prefix → false' );

// Cross-family mismatch
assert_false( $ip_in_cidr->invoke( $detector, '2606:4700::1', '104.16.0.0/13' ),  'v6 IP vs v4 CIDR → false' );
assert_false( $ip_in_cidr->invoke( $detector, '8.8.8.8',      '2606:4700::/32' ), 'v4 IP vs v6 CIDR → false' );

// ---------- is_trusted_proxy() ----------

assert_true(  $is_trusted->invoke( $detector, '104.16.5.10' ),  '104.16.5.10 (CF) is trusted proxy' );
assert_true(  $is_trusted->invoke( $detector, '162.158.1.1' ),  '162.158.1.1 (CF) is trusted proxy' );
assert_true(  $is_trusted->invoke( $detector, '2606:4700::1' ), 'CF v6 is trusted proxy' );
assert_false( $is_trusted->invoke( $detector, '8.8.8.8' ),       'Google DNS is NOT trusted proxy' );
assert_false( $is_trusted->invoke( $detector, '1.1.1.1' ),       'CF DNS 1.1.1.1 NOT in proxy ranges' );
assert_false( $is_trusted->invoke( $detector, '192.0.2.99' ),    'TEST-NET-1 is NOT trusted proxy' );

// Filter extensibility — operator adds a custom proxy
$GLOBALS['_faz_test_filters']['faz_geo_trusted_proxy_cidrs'] = function( $cidrs ) {
	return array_merge( $cidrs, array( '10.0.0.0/8' ) );
};
$d2 = new Geo_Detector();
$ref2 = new ReflectionClass( $d2 );
$is_trusted2 = $ref2->getMethod( 'is_trusted_proxy' ); $is_trusted2->setAccessible( true );
assert_true(  $is_trusted2->invoke( $d2, '10.5.5.5' ),    'Custom CIDR via faz_geo_trusted_proxy_cidrs filter accepted' );
assert_true(  $is_trusted2->invoke( $d2, '104.16.5.10' ), 'Default CF ranges still active alongside filter' );
unset( $GLOBALS['_faz_test_filters']['faz_geo_trusted_proxy_cidrs'] );

// ---------- resolve_client_ip() — the security-critical assembler ----------

// Case 1: direct origin, CF-Connecting-IP spoofed → must IGNORE header.
$_SERVER = array(
	'REMOTE_ADDR'             => '203.0.113.99',   // attacker's direct IP
	'HTTP_CF_CONNECTING_IP'   => '8.8.8.8',        // spoofed header
);
$ip = $resolve_client->invoke( $detector );
assert_eq( $ip, '203.0.113.99', 'Direct origin + spoofed CF-Connecting-IP → REMOTE_ADDR (header ignored)' );

// Case 2: legitimate Cloudflare proxy → use CF-Connecting-IP.
$_SERVER = array(
	'REMOTE_ADDR'             => '162.158.42.42', // genuine CF edge
	'HTTP_CF_CONNECTING_IP'   => '203.0.113.55',  // real visitor IP CF reports
);
$ip = $resolve_client->invoke( $detector );
assert_eq( $ip, '203.0.113.55', 'CF proxy + valid CF-Connecting-IP → trusted header used' );

// Case 3: CF proxy but malformed header → fall back to REMOTE_ADDR.
$_SERVER = array(
	'REMOTE_ADDR'             => '162.158.42.42',
	'HTTP_CF_CONNECTING_IP'   => 'garbage-not-an-ip',
);
$ip = $resolve_client->invoke( $detector );
assert_eq( $ip, '162.158.42.42', 'CF proxy + malformed header → REMOTE_ADDR fallback' );

// Case 4: no CF header at all → REMOTE_ADDR.
$_SERVER = array( 'REMOTE_ADDR' => '203.0.113.7' );
$ip = $resolve_client->invoke( $detector );
assert_eq( $ip, '203.0.113.7', 'No CF header → REMOTE_ADDR' );

// Case 5: nothing at all → empty.
$_SERVER = array();
$ip = $resolve_client->invoke( $detector );
assert_eq( $ip, '', 'No REMOTE_ADDR, no CF header → empty string' );

// Case 6: CF v6 proxy → header used.
$_SERVER = array(
	'REMOTE_ADDR'             => '2606:4700::ff',
	'HTTP_CF_CONNECTING_IP'   => '2001:db8::42',
);
$ip = $resolve_client->invoke( $detector );
assert_eq( $ip, '2001:db8::42', 'CF v6 edge + v6 CF-Connecting-IP → trusted' );

// ---------- Summary ----------

echo "\n--\n";
echo "Tests:  $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n\n";

if ( $tests_failed > 0 ) { echo "\033[31mFAIL\033[0m\n"; exit( 1 ); }
echo "\033[32mPASS\033[0m\n";
exit( 0 );
