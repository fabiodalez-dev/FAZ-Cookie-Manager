<?php
/**
 * An ipinfo lookup that was wanted and could not be had never yields an
 * opt-out rule set, and a spent hourly budget is recorded for the admin.
 *
 * Past the hourly lookup budget (and on a refusal or a transport failure)
 * Ipinfo_Client answers vpn=null, source "error". The orchestrator cast that
 * null to "not a VPN", so a visitor from an opt-in jurisdiction whose VPN
 * exits in an opt-out US state was served the notice-only regime, and nothing
 * told the administrator the budget had run out.
 *
 * Runs the real chain: Ipinfo_Client -> Geo_Detector -> Ruleset_Resolver ->
 * Ruleset_Loader (the shipped catalogue) through
 * Geo_Routing::get_visitor_context().
 *
 * Run: php tests/unit/test-ipinfo-unknown-vpn-fallback-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Geo_Routing\Includes {
	// Ipinfo_Client calls time() unqualified: inside its namespace this one
	// answers, so the test owns the clock.
	function time() {
		return $GLOBALS['faz_test_now'];
	}
}

namespace {
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
define( 'FAZ_VERSION', '1.40.0-test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['faz_test_now']          = 1700000000;
$GLOBALS['_faz_test_transients']  = array();
$GLOBALS['_faz_test_options']     = array();
$GLOBALS['_faz_test_option_sets'] = array();
$GLOBALS['_faz_test_cache']       = array();
$GLOBALS['_faz_test_filters']     = array();
$GLOBALS['_faz_test_http_stub']   = null;
$GLOBALS['_faz_test_http_calls']  = 0;

// get_transient() exists, so the client keeps verdicts and (with no $wpdb)
// the budget window in transients: the default shape of a site without a
// persistent object cache, minus the SQL statements the atomic test covers.
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
	$GLOBALS['_faz_test_transients'][ $name ] = array( $value, $GLOBALS['faz_test_now'] + (int) $ttl );
	return true;
}
function get_option( $key, $default = false ) {
	return isset( $GLOBALS['_faz_test_options'][ $key ] ) ? $GLOBALS['_faz_test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['_faz_test_options'][ $key ] = $value;
	$GLOBALS['_faz_test_option_sets'][]   = $key;
	return true;
}
function wp_using_ext_object_cache() { return false; }
function wp_cache_get( $key, $group = '' ) {
	$k = "$group:$key";
	return isset( $GLOBALS['_faz_test_cache'][ $k ] ) ? $GLOBALS['_faz_test_cache'][ $k ] : false;
}
function wp_cache_set( $key, $val, $group = '', $ttl = 0 ) {
	$GLOBALS['_faz_test_cache'][ "$group:$key" ] = $val;
	return true;
}
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function apply_filters( $hook, $val ) {
	if ( isset( $GLOBALS['_faz_test_filters'][ $hook ] ) ) {
		return call_user_func( $GLOBALS['_faz_test_filters'][ $hook ], $val );
	}
	return $val;
}
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function sanitize_text_field( $str ) {
	$str = strip_tags( (string) $str );
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', $str ) );
}
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function wp_remote_get( $url, $args = array() ) {
	++$GLOBALS['_faz_test_http_calls'];
	return null !== $GLOBALS['_faz_test_http_stub'] ? $GLOBALS['_faz_test_http_stub'] : new WP_Error( 'stub', 'no HTTP in unit test' );
}
function wp_remote_retrieve_response_code( $resp ) { return is_array( $resp ) ? ( $resp['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $resp ) { return is_array( $resp ) ? ( $resp['body'] ?? '' ) : ''; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

// The client logs every failed lookup; keep the runner's output readable.
ini_set( 'error_log', '/dev/null' );

$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-utils.php';
require_once $root . '/admin/modules/geo-routing/includes/class-secrets.php';
require_once $root . '/admin/modules/geo-routing/includes/class-ipinfo-client.php';
require_once $root . '/admin/modules/geo-routing/includes/class-geo-detector.php';
require_once $root . '/admin/modules/geo-routing/includes/class-ruleset-resolver.php';
require_once $root . '/admin/modules/geo-routing/includes/class-ruleset-loader.php';
require_once $root . '/admin/modules/geo-routing/class-geo-routing.php';

use FazCookie\Admin\Modules\Geo_Routing\Geo_Routing;
use FazCookie\Admin\Modules\Geo_Routing\Includes\Geo_Detector;
use FazCookie\Admin\Modules\Geo_Routing\Includes\Ipinfo_Client;
use FazCookie\Admin\Modules\Geo_Routing\Includes\Secrets;

$passed = 0;
$failed = 0;
function vpn_check( $cond, $label ) {
	global $passed, $failed;
	if ( $cond ) {
		$passed++;
		echo "  [PASS] $label\n";
	} else {
		$failed++;
		echo "  [FAIL] $label\n";
	}
}
function vpn_reset() {
	$GLOBALS['_faz_test_transients']  = array();
	$GLOBALS['_faz_test_cache']       = array();
	$GLOBALS['_faz_test_option_sets'] = array();
	$GLOBALS['_faz_test_http_calls']  = 0;
	unset( $GLOBALS['_faz_test_options'][ Ipinfo_Client::BUDGET_SPENT_OPTION ] );
}

const VPN_FALLBACK = 'fallback-gdpr-most-protective';
$not_a_vpn = array( 'response' => array( 'code' => 200 ), 'body' => '{"vpn":false,"proxy":false,"tor":false,"relay":false}' );
$routing   = Geo_Routing::get_instance();

$GLOBALS['_faz_test_options']['faz_geo_ipinfo_optin']   = true;
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_api_key'] = Secrets::encrypt( 'mykey' );

echo "\n== A spent hourly budget: the unknown VPN state is not read as \"not a VPN\" ==\n\n";

vpn_reset();
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 1; };
$GLOBALS['_faz_test_http_stub']                              = $not_a_vpn;

$ctx = $routing->get_visitor_context( '203.0.113.1', 'US', 'US-CA' );
vpn_check( is_array( $ctx ) && 'ccpa-california' === $ctx['ruleset_id'], 'control: a California visitor ipinfo cleared gets the opt-out rule set' );
vpn_check( is_array( $ctx ) && false === $ctx['vpn'] && empty( $ctx['vpn_unverified'] ), 'control: that verdict is a real one' );
vpn_check( array() === $GLOBALS['_faz_test_option_sets'], 'control: a lookup inside the budget records nothing' );

$ctx = $routing->get_visitor_context( '203.0.113.2', 'US', 'US-CA' );
vpn_check( 1 === $GLOBALS['_faz_test_http_calls'], 'precondition: the second address is past the budget of 1 and ipinfo is not called' );
vpn_check( is_array( $ctx ) && null === $ctx['vpn'] && ! empty( $ctx['vpn_unverified'] ), 'the context says the VPN state is unverified, not false' );
vpn_check( is_array( $ctx ) && VPN_FALLBACK === $ctx['ruleset_id'], 'an address past the budget that geolocates to California gets the most-protective fallback' );
vpn_check( is_array( $ctx ) && isset( $ctx['ruleset']['model'] ) && 'opt-in' === $ctx['ruleset']['model'], 'the rule set handed out is an opt-in one, not only its id' );

$ctx = $routing->get_visitor_context( '203.0.113.3', 'IT', '' );
vpn_check( is_array( $ctx ) && 'gdpr-italy' === $ctx['ruleset_id'], 'an opt-in jurisdiction keeps its own rule set: only opt-out ones are replaced' );

echo "\n== The spent budget is recorded once per window, and readable ==\n\n";

$spent = get_option( Ipinfo_Client::BUDGET_SPENT_OPTION, null );
vpn_check( is_array( $spent ) && $GLOBALS['faz_test_now'] === ( $spent['at'] ?? null ), 'the refusal is recorded with the time it happened' );
vpn_check( 1 === count( $GLOBALS['_faz_test_option_sets'] ), 'two refused lookups in one window wrote the record once' );

$GLOBALS['faz_test_now'] += 60;
$routing->get_visitor_context( '203.0.113.4', 'US', 'US-CA' );
vpn_check( 1 === count( $GLOBALS['_faz_test_option_sets'] ), 'a third refusal a minute later still does not write' );

$status = ( new Ipinfo_Client() )->budget_status();
vpn_check( 1 === $status['limit'] && 1 === $status['used'] && true === $status['spent'], 'budget_status() reports the ceiling, what was used, and that it is spent' );
vpn_check( HOUR_IN_SECONDS - 60 === $status['retry_after'], 'and when the window reopens' );
vpn_check( $GLOBALS['faz_test_now'] - 60 === $status['last_spent_at'], 'and when the budget last ran out' );
vpn_check( false === $status['account_refused'], 'a spent budget is not an account refusal' );

$GLOBALS['faz_test_now'] += HOUR_IN_SECONDS;
$status = ( new Ipinfo_Client() )->budget_status();
vpn_check( false === $status['spent'] && 0 === $status['used'], 'the next hour starts unspent' );
vpn_check( $status['last_spent_at'] > 0, 'and the record of the last spent hour outlives it' );

echo "\n== Transport failure and account refusal resolve the same way ==\n\n";

vpn_reset();
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 120; };
$GLOBALS['_faz_test_http_stub']                              = null; // WP_Error.
$ctx = $routing->get_visitor_context( '203.0.113.5', 'US', 'US-CA' );
vpn_check( is_array( $ctx ) && VPN_FALLBACK === $ctx['ruleset_id'], 'ipinfo unreachable: a California address gets the most-protective fallback' );
vpn_check( false === ( new Ipinfo_Client() )->budget_status()['spent'], 'an outage does not count as a spent budget' );

vpn_reset();
$GLOBALS['_faz_test_http_stub'] = array( 'response' => array( 'code' => 429 ), 'body' => '' );
$ctx = $routing->get_visitor_context( '203.0.113.6', 'US', 'US-CA' );
vpn_check( is_array( $ctx ) && VPN_FALLBACK === $ctx['ruleset_id'], 'ipinfo answers 429: a California address gets the most-protective fallback' );
vpn_check( true === ( new Ipinfo_Client() )->budget_status()['account_refused'], 'budget_status() reports the account refusal while its marker lasts' );

echo "\n== A lookup nobody asked for changes nothing ==\n\n";

vpn_reset();
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_optin'] = false;
$ctx = $routing->get_visitor_context( '203.0.113.7', 'US', 'US-CA' );
vpn_check( 0 === $GLOBALS['_faz_test_http_calls'], 'precondition: with the opt-in off ipinfo is never called' );
vpn_check( is_array( $ctx ) && 'ccpa-california' === $ctx['ruleset_id'] && empty( $ctx['vpn_unverified'] ), 'the opt-out rule set is served as before: no VPN detection was requested' );
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_optin'] = true;

echo "\n== Geo_Detector marks only the failed lookup ==\n\n";

class Faz_Test_Vpn_Ipinfo extends Ipinfo_Client {
	public $answer;
	public function __construct() {}
	public function lookup( $ip ) {
		return $this->answer;
	}
}
$stub     = new Faz_Test_Vpn_Ipinfo();
$detector = new Geo_Detector( $stub );
$cases    = array(
	'error'  => array( array( 'vpn' => null, 'source' => 'error', 'retry_after' => 30 ), true ),
	'skip'   => array( array( 'vpn' => null, 'source' => 'skip' ), false ),
	'ipinfo' => array( array( 'vpn' => false, 'source' => 'ipinfo' ), false ),
	'cache'  => array( array( 'vpn' => true, 'source' => 'cache' ), false ),
);
$n = 20;
foreach ( $cases as $source => $case ) {
	$GLOBALS['_faz_test_cache'] = array();
	$stub->answer               = $case[0];
	$result                     = $detector->detect( '203.0.113.' . ( ++$n ) );
	vpn_check( $case[1] === ! empty( $result['vpn_unverified'] ), "source '$source' " . ( $case[1] ? 'is' : 'is not' ) . ' flagged as unverified' );
	if ( 'error' === $source ) {
		// Served from the detector's own cache, the flag must still be there.
		$stub->answer = array( 'vpn' => false, 'source' => 'ipinfo' );
		$again        = $detector->detect( '203.0.113.' . $n );
		vpn_check( ! empty( $again['vpn_unverified'] ), 'the flag is part of the cached entry' );
	}
}

echo "\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
}
