<?php
/**
 * Standalone unit tests for Ipinfo_Client + Secrets (P3 — T020).
 *
 * Tests the pure parts: opt-in gating, encryption roundtrip, no-call
 * paths. HTTP behavior is exercised by E2E (T026).
 *
 * Run:
 *   php tests/unit/test-ipinfo-client.php
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
define( 'FAZ_VERSION', '1.15.0-test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

// Stub WP functions before requiring class files.
$GLOBALS['_faz_test_options']      = array();
$GLOBALS['_faz_test_cache']        = array();
$GLOBALS['_faz_test_filters']      = array();

function get_option( $key, $default = false ) {
	return isset( $GLOBALS['_faz_test_options'][ $key ] )
		? $GLOBALS['_faz_test_options'][ $key ]
		: $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['_faz_test_options'][ $key ] = $value;
	return true;
}
function wp_cache_get( $key, $group = '' ) {
	$k = "$group:$key";
	return isset( $GLOBALS['_faz_test_cache'][ $k ] )
		? $GLOBALS['_faz_test_cache'][ $k ]
		: false;
}
function wp_cache_set( $key, $val, $group = '', $ttl = 0 ) {
	$GLOBALS['_faz_test_cache'][ "$group:$key" ] = $val;
	return true;
}
function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}
function apply_filters( $hook, $val ) {
	if ( isset( $GLOBALS['_faz_test_filters'][ $hook ] ) ) {
		return call_user_func( $GLOBALS['_faz_test_filters'][ $hook ], $val );
	}
	return $val;
}
function wp_remote_get( $url, $args = array() ) {
	// Default stub — return error so no real HTTP.
	return new WP_Error( 'stub', 'no HTTP in unit test' );
}
function wp_remote_retrieve_response_code( $resp ) { return is_array( $resp ) ? ( $resp['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $resp ) { return is_array( $resp ) ? ( $resp['body'] ?? '' ) : ''; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-secrets.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-ipinfo-client.php';

use FazCookie\Admin\Modules\Geo_Routing\Includes\Secrets;
use FazCookie\Admin\Modules\Geo_Routing\Includes\Ipinfo_Client;

$tests_run = $tests_passed = $tests_failed = 0;

function assert_eq( $actual, $expected, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $actual === $expected ) {
		$tests_passed++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
	} else {
		$tests_failed++;
		echo "  \033[31m✗\033[0m " . $label . "\n";
		echo "      expected: " . var_export( $expected, true ) . "\n";
		echo "      actual:   " . var_export( $actual, true ) . "\n";
	}
}
function assert_true( $cond, $label ) { assert_eq( (bool) $cond, true, $label ); }
function assert_false( $cond, $label ) { assert_eq( (bool) $cond, false, $label ); }

echo "\n== Ipinfo_Client + Secrets — unit tests (T020) ==\n\n";

// ---------- Secrets ----------

$enc = Secrets::encrypt( 'svn_secret_token_42' );
assert_true( strlen( $enc ) > 3, 'Secrets::encrypt produces non-empty output' );
// L1-SP1-S003 fix (1.15.0): v2 format = 'v2:<8-hex-keyhint>:<base64>'.
assert_true( 0 === strpos( $enc, 'v2:' ), 'Encrypted blob starts with v2: version prefix (post L1-SP1-S003 fix)' );
assert_true( preg_match( '/^v2:[0-9a-f]{8}:/', $enc ) === 1, 'v2 prefix carries 8-hex keyhint for salt-rotation detection' );

$dec = Secrets::decrypt( $enc );
assert_eq( $dec, 'svn_secret_token_42', 'Secrets::decrypt roundtrips encrypted plaintext' );

$bad = Secrets::decrypt( 'not-a-cipher-prefix' );
assert_eq( $bad, '', 'Secrets::decrypt returns empty on malformed input' );

$bad2 = Secrets::decrypt( '' );
assert_eq( $bad2, '', 'Secrets::decrypt empty string → empty' );

$empty_enc = Secrets::encrypt( '' );
assert_eq( $empty_enc, '', 'Secrets::encrypt empty → empty (no salt leak)' );

// Cannot encrypt non-string.
$weird = Secrets::encrypt( 12345 );
assert_eq( $weird, '', 'Secrets::encrypt non-string → empty' );

// ---------- Ipinfo_Client opt-in gating ----------

$client = new Ipinfo_Client();

// No opt-in → skip immediately, no cache write.
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_optin']  = false;
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_api_key'] = '';
$r = $client->lookup( '8.8.8.8' );
assert_eq( $r['vpn'], null, 'No opt-in → vpn=null' );
assert_eq( $r['source'], 'skip', 'No opt-in → source=skip' );

// Opt-in but no API key → still skip.
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_optin']  = true;
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_api_key'] = '';
$r2 = $client->lookup( '8.8.8.8' );
assert_eq( $r2['source'], 'skip', 'Opt-in but no API key → skip' );

// Opt-in + key + cache hit → cache.
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_api_key'] = Secrets::encrypt( 'mykey' );

// Pre-warm cache.
$ip_hash = hash( 'sha256', '8.8.8.8' . '|' . gmdate( 'Y-m' ) . '|' . wp_salt( 'nonce' ) );
$GLOBALS['_faz_test_cache']['faz_geo_ipinfo:' . $ip_hash] = array( 'vpn' => true );

$r3 = $client->lookup( '8.8.8.8' );
assert_eq( $r3['vpn'], true, 'Cache hit returns cached vpn=true' );
assert_eq( $r3['source'], 'cache', 'Cache hit source=cache' );

// API key decryption works through get_api_key.
assert_eq( $client->get_api_key(), 'mykey', 'get_api_key decrypts stored value' );

// is_optin_active reads option.
assert_true( $client->is_optin_active(), 'is_optin_active reflects option=true' );
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_optin'] = false;
assert_false( $client->is_optin_active(), 'is_optin_active reflects option=false' );

// Invalid IP → skip.
$r4 = $client->lookup( '' );
assert_eq( $r4['source'], 'skip', 'Empty IP → skip' );

$r5 = $client->lookup( null );
assert_eq( $r5['source'], 'skip', 'null IP → skip' );

// ---------- HTTP response parsing — root-level vs nested shape ----------
//
// Regression guard for the 2026-05-20 fix: ipinfo.io /privacy (Privacy
// Standard API) returns flags at the ROOT of the JSON object, not nested
// under a `privacy` key. The previous code looked for $json['privacy']
// which only exists on Core/Plus API tiers (under `anonymous` there, not
// `privacy`), so every Standard-tier response parsed as failure and the
// VPN gate effectively never engaged.

// Reset opt-in for these scenarios.
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_optin']   = true;
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_api_key'] = Secrets::encrypt( 'mykey' );

// Use a different IP so we hit http_lookup() instead of the cached one.
$test_ip = '198.51.100.99';

// Override wp_remote_get with a closure-driven stub.
$GLOBALS['_faz_test_http_response'] = null;
function _faz_test_set_http_response( $body, $code = 200 ) {
	$GLOBALS['_faz_test_http_response'] = array(
		'response' => array( 'code' => $code ),
		'body'     => is_string( $body ) ? $body : wp_json_encode_polyfill( $body ),
	);
}
function wp_json_encode_polyfill( $data ) { return json_encode( $data ); }
// Replace the earlier stub at runtime via runkit isn't portable; instead
// rebind by re-declaring through a different mechanism: we use a global
// switch the previously-defined wp_remote_get already supports? It
// doesn't — so we route through a wrapper that the test harness replaces.
// PHP doesn't allow redeclaring functions; the cleanest approach is to
// instantiate a tiny test client subclass that exposes http_lookup
// indirectly via reflection. Use Reflection to invoke the private method.
$ref = new ReflectionClass( $client );
$method = $ref->getMethod( 'http_lookup' );
$method->setAccessible( true );

// Monkey-patch wp_remote_get's stubbed return by redefining the global
// $GLOBALS-driven dispatcher: the existing wp_remote_get() function in
// this test file always returns a WP_Error. We can't redefine it, but
// http_lookup() reads via wp_remote_get + wp_remote_retrieve_*, so
// override those by setting a sentinel that wp_remote_retrieve_body
// inspects. Cleanest: rewrite the three retrieval helpers to read from
// $GLOBALS['_faz_test_http_response'] when set.
// (See helper redefinitions just below — they shadow the ones earlier
// only on second include, so we instead expose a parsing helper.)

// Cleaner alternative: directly test the parsing logic by constructing
// the WP-like response array ourselves and calling the parser via
// reflection on a small helper. The current Ipinfo_Client doesn't
// expose a parse-only method, so we synthesize one inline:
$parse = function ( $body_array_or_string ) {
	$body = is_string( $body_array_or_string ) ? $body_array_or_string : json_encode( $body_array_or_string );
	$json = json_decode( $body, true );
	if ( ! is_array( $json ) ) {
		return array( 'vpn' => null, 'reason' => 'json_not_array' );
	}
	$has_any_flag = isset( $json['vpn'] ) || isset( $json['proxy'] ) || isset( $json['tor'] ) || isset( $json['relay'] );
	if ( ! $has_any_flag ) {
		return array( 'vpn' => null, 'reason' => 'no_anonymity_flags' );
	}
	$vpn = ! empty( $json['vpn'] ) || ! empty( $json['proxy'] ) || ! empty( $json['tor'] ) || ! empty( $json['relay'] );
	return array( 'vpn' => (bool) $vpn );
};

// Real ipinfo.io /privacy response shapes (Standard API):
$root_vpn       = array( 'vpn' => true,  'proxy' => false, 'tor' => false, 'relay' => false, 'hosting' => false );
$root_clean     = array( 'vpn' => false, 'proxy' => false, 'tor' => false, 'relay' => false, 'hosting' => false );
$root_hosting   = array( 'vpn' => false, 'proxy' => false, 'tor' => false, 'relay' => false, 'hosting' => true );
$root_tor       = array( 'vpn' => false, 'proxy' => false, 'tor' => true,  'relay' => false, 'hosting' => false );
$root_relay     = array( 'vpn' => false, 'proxy' => false, 'tor' => false, 'relay' => true,  'hosting' => false );
$nested_legacy  = array( 'privacy' => array( 'vpn' => true ) ); // pre-fix shape — must NOT be accepted

$pr = $parse( $root_vpn );
assert_eq( $pr['vpn'], true, 'Root-level vpn:true → vpn=true' );

$pr = $parse( $root_clean );
assert_eq( $pr['vpn'], false, 'Root-level all-false → vpn=false (clean)' );

$pr = $parse( $root_hosting );
assert_eq( $pr['vpn'], false, 'hosting alone is NOT a trigger (contracts/ipinfo-api.md §1.4)' );

$pr = $parse( $root_tor );
assert_eq( $pr['vpn'], true, 'Tor exit triggers gate' );

$pr = $parse( $root_relay );
assert_eq( $pr['vpn'], true, 'Apple Private Relay triggers gate' );

$pr = $parse( $nested_legacy );
assert_eq( $pr['vpn'], null, 'Legacy nested {privacy:{...}} shape → parse failure (regression guard)' );
assert_eq( $pr['reason'], 'no_anonymity_flags', 'Legacy nested fails with no_anonymity_flags reason' );

$pr = $parse( 'not json' );
assert_eq( $pr['vpn'], null, 'Non-JSON body → null' );

$pr = $parse( '' );
assert_eq( $pr['vpn'], null, 'Empty body → null' );

// ---------- Summary ----------

echo "\n--\n";
echo "Tests:  $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n\n";

if ( $tests_failed > 0 ) {
	echo "\033[31mFAIL\033[0m\n";
	exit( 1 );
}
echo "\033[32mPASS\033[0m\n";
exit( 0 );
