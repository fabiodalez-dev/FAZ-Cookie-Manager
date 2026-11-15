<?php
/**
 * Ipinfo_Client on the transient backend: lifetimes and expiry.
 *
 * Without a persistent object cache (the default WordPress install) the
 * client keeps its verdicts, the site-wide refusal marker and the hourly
 * lookup budget in transients. This runs those branches and checks the
 * lifetimes they are written with and what happens once they run out.
 *
 * Run: php tests/unit/test-ipinfo-client-transient-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Geo_Routing\Includes {
	// Ipinfo_Client calls time() unqualified, so inside its namespace this
	// one answers: the same clock as the transient shims, never the wall clock.
	function time() {
		return $GLOBALS['faz_test_now'];
	}
}

namespace {
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
define( 'FAZ_VERSION', '1.15.0-test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

// Stub WP functions before requiring class files. get_transient() exists
// here, so the client takes the transient branch, the default on a site
// without a persistent object cache. The client and these shims read one
// controlled clock, and a test moves time forward by advancing it.
$GLOBALS['faz_test_now']         = 1700000000;
$GLOBALS['_faz_test_transients'] = array();
function get_transient( $name ) {
	if ( ! isset( $GLOBALS['_faz_test_transients'][ $name ] ) ) {
		return false;
	}
	list( $value, $expires ) = $GLOBALS['_faz_test_transients'][ $name ];
	if ( $expires <= $GLOBALS['faz_test_now'] ) {
		unset( $GLOBALS['_faz_test_transients'][ $name ] );
		return false;
	}
	return $value;
}
function set_transient( $name, $value, $ttl = 0 ) {
	$GLOBALS['_faz_test_transients'][ $name ] = array( $value, $GLOBALS['faz_test_now'] + (int) $ttl, (int) $ttl );
	return true;
}
function faz_test_advance( $seconds ) {
	$GLOBALS['faz_test_now'] += $seconds;
}
function faz_test_ttl( $name ) {
	return isset( $GLOBALS['_faz_test_transients'][ $name ] ) ? $GLOBALS['_faz_test_transients'][ $name ][2] : null;
}

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
	$GLOBALS['_faz_test_http_calls'] = ( $GLOBALS['_faz_test_http_calls'] ?? 0 ) + 1;
	if ( ! empty( $GLOBALS['_faz_test_http_stub'] ) ) {
		return $GLOBALS['_faz_test_http_stub'];
	}
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

// The cache key is the shared faz_ip_cache_key() from the helpers file since
// 1.34.2 — loaded for real, so the key asserted below is the production one.
require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-secrets.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-ipinfo-client.php';

use FazCookie\Admin\Modules\Geo_Routing\Includes\Secrets;
use FazCookie\Admin\Modules\Geo_Routing\Includes\Ipinfo_Client;

$passed = 0;
$failed = 0;
function tr_check( $cond, $label ) {
	global $passed, $failed;
	if ( $cond ) {
		$passed++;
		echo "  [PASS] $label\n";
	} else {
		$failed++;
		echo "  [FAIL] $label\n";
	}
}

echo "\n== Ipinfo_Client — transient backend ==\n\n";

$GLOBALS['_faz_test_options']['faz_geo_ipinfo_optin']   = true;
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_api_key'] = Secrets::encrypt( 'mykey' );
$refused = 'faz_ipinfo_' . Ipinfo_Client::REFUSED_KEY;
$budget  = 'faz_ipinfo_' . Ipinfo_Client::BUDGET_KEY;

// Refusal: remembered site-wide for ERROR_TTL, then ipinfo is asked again.
$GLOBALS['_faz_test_http_calls'] = 0;
$GLOBALS['_faz_test_http_stub']  = array( 'response' => array( 'code' => 429 ), 'body' => '{}' );
$client = new Ipinfo_Client();
$client->lookup( '203.0.113.71' );
tr_check( Ipinfo_Client::ERROR_TTL === faz_test_ttl( $refused ), 'a 429 is stored as a transient for ERROR_TTL' );
$client->lookup( '198.51.100.72' );
tr_check( 1 === $GLOBALS['_faz_test_http_calls'], 'and another address is not looked up while it lasts' );
faz_test_advance( Ipinfo_Client::ERROR_TTL - 1 );
$late = $client->lookup( '198.51.100.75' );
tr_check( isset( $late['retry_after'] ) && 1 === $late['retry_after'], 'a refusal answered 1 second before the marker expires says retry_after 1' );
faz_test_advance( 2 );
$GLOBALS['_faz_test_http_stub'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"vpn":true}' );
$client->lookup( '198.51.100.73' );
tr_check( 2 === $GLOBALS['_faz_test_http_calls'], 'once the refusal has expired, ipinfo is asked again' );

// Budget: an hour-long fixed window, then a new one.
$GLOBALS['_faz_test_transients'] = array();
$GLOBALS['_faz_test_http_calls'] = 0;
$GLOBALS['_faz_test_http_stub']  = array( 'response' => array( 'code' => 200 ), 'body' => '{"vpn":false}' );
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 2; };
$client = new Ipinfo_Client();
foreach ( array( '203.0.113.1', '203.0.113.2', '203.0.113.3' ) as $ip ) {
	$last = $client->lookup( $ip );
}
tr_check( 2 === $GLOBALS['_faz_test_http_calls'], 'with a budget of 2, the third new address in the hour is not looked up' );
tr_check( isset( $last['retry_after'] ) && $last['retry_after'] > 3500 && $last['retry_after'] <= 3600, 'and that refusal says how long the window has left' );
tr_check( faz_test_ttl( $budget ) > 0 && faz_test_ttl( $budget ) <= 3600, 'the budget transient lives no longer than the window' );
faz_test_advance( 3601 );
$client->lookup( '203.0.113.4' );
tr_check( 3 === $GLOBALS['_faz_test_http_calls'], 'after the hour a new window opens and the lookup runs' );

echo "\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
}
