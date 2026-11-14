<?php
/**
 * Geo_Detector keeps a result with an unknown VPN verdict only as long as
 * ipinfo remembers the failure behind it.
 *
 * When ipinfo cannot answer (refusal, lookup budget, transport), lookup()
 * returns source "error" and remembers that for Ipinfo_Client::ERROR_TTL.
 * detect() cached the resulting vpn=null for its full hour, so the address
 * went without a VPN verdict for most of an hour after ipinfo could answer.
 *
 * Run: php tests/unit/test-geo-detector-error-ttl-php.php
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
function wp_cache_set( $key, $val, $group = '', $ttl = 0 ) { $GLOBALS['_faz_test_cache'][ "$group:$key" ] = $val; $GLOBALS['_faz_test_ttl'][ "$group:$key" ] = $ttl; return true; }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function apply_filters( $hook, $val ) {
	if ( isset( $GLOBALS['_faz_test_filters'][ $hook ] ) ) {
		return call_user_func( $GLOBALS['_faz_test_filters'][ $hook ], $val );
	}
	return $val;
}
// faz_forwarded_client_ip() sanitises the forwarded header before parsing it.
// Mirrors WordPress: strip tags, then collapse whitespace — NOT a no-op stub,
// because a stub that passed the value through unchanged would hide a header
// whose content only survives sanitising by accident.
function sanitize_text_field( $str ) {
	$str = strip_tags( (string) $str );
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', $str ) );
}
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function wp_remote_get( $url, $args = array() ) { return new WP_Error( 'stub', 'no HTTP' ); }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function wp_remote_retrieve_body( $r ) { return ''; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class WP_Error {
	public function __construct( $code = '', $message = '' ) {}
	public function get_error_message() { return ''; }
}

// The Cloudflare ranges live in one place since 1.34.1 — faz_trusted_proxy_cidrs()
// in includes/class-utils.php, which faz_resolve_client_ip() reads as well. The
// detector reads that shared list, so the helpers file has to be loaded here too.
require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-secrets.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-ipinfo-client.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-geo-detector.php';

use FazCookie\Admin\Modules\Geo_Routing\Includes\Geo_Detector;
use FazCookie\Admin\Modules\Geo_Routing\Includes\Ipinfo_Client;

class Faz_Test_Ipinfo extends Ipinfo_Client {
	public $answer;
	public function __construct() {}
	public function lookup( $ip ) {
		return $this->answer;
	}
}

$passed = 0;
$failed = 0;
function ttl_check( $cond, $label ) {
	global $passed, $failed;
	if ( $cond ) {
		$passed++;
		echo "  [PASS] $label\n";
	} else {
		$failed++;
		echo "  [FAIL] $label\n";
	}
}
function ttl_of_last_detect() {
	$ttls = isset( $GLOBALS['_faz_test_ttl'] ) ? $GLOBALS['_faz_test_ttl'] : array();
	foreach ( $ttls as $k => $ttl ) {
		if ( 0 === strpos( $k, Geo_Detector::CACHE_GROUP . ':' ) ) {
			return $ttl;
		}
	}
	return null;
}

echo "\n== Geo_Detector — cache lifetime of an unknown VPN verdict ==\n\n";

$ipinfo   = new Faz_Test_Ipinfo();
$detector = new Geo_Detector( $ipinfo );

$ipinfo->answer               = array( 'vpn' => null, 'source' => 'error' );
$GLOBALS['_faz_test_cache']   = array();
$GLOBALS['_faz_test_ttl']     = array();
$result                       = $detector->detect( '203.0.113.7' );
ttl_check( null === $result['vpn'], 'precondition: an ipinfo failure leaves the VPN verdict unknown' );
ttl_check( Ipinfo_Client::ERROR_TTL === ttl_of_last_detect(), 'that result is cached no longer than ipinfo remembers the failure' );

$ipinfo->answer             = array( 'vpn' => false, 'source' => 'ipinfo' );
$GLOBALS['_faz_test_cache'] = array();
$GLOBALS['_faz_test_ttl']   = array();
$detector->detect( '203.0.113.8' );
ttl_check( Geo_Detector::CACHE_TTL === ttl_of_last_detect(), 'a real ipinfo verdict keeps the full cache lifetime' );

$ipinfo->answer             = array( 'vpn' => null, 'source' => 'skip' );
$GLOBALS['_faz_test_cache'] = array();
$GLOBALS['_faz_test_ttl']   = array();
$detector->detect( '203.0.113.9' );
ttl_check( Geo_Detector::CACHE_TTL === ttl_of_last_detect(), 'a lookup ipinfo was never asked to make keeps it too' );

echo "\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
