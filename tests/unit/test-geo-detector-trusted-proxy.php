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
$peer_is_cf      = $ref->getMethod( 'peer_is_cloudflare' ); $peer_is_cf->setAccessible( true );
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

// ---------- peer_is_cloudflare() ----------
// The SHIPPED list, which no filter can widen: the two headers gated on this
// are written by Cloudflare and by nothing else.

$cf_peer = function ( $ip ) use ( $peer_is_cf, $detector ) {
	$_SERVER = array( 'REMOTE_ADDR' => $ip );
	return $peer_is_cf->invoke( $detector );
};

assert_true(  $cf_peer( '104.16.5.10' ),  '104.16.5.10 (CF) is a Cloudflare peer' );
assert_true(  $cf_peer( '162.158.1.1' ),  '162.158.1.1 (CF) is a Cloudflare peer' );
assert_true(  $cf_peer( '2606:4700::1' ), 'CF v6 is a Cloudflare peer' );
assert_false( $cf_peer( '8.8.8.8' ),      'Google DNS is NOT a Cloudflare peer' );
assert_false( $cf_peer( '1.1.1.1' ),      'CF DNS 1.1.1.1 is not a proxy range' );
assert_false( $cf_peer( '192.0.2.99' ),   'TEST-NET-1 is NOT a Cloudflare peer' );
assert_false( $cf_peer( '' ),             'no REMOTE_ADDR is not a Cloudflare peer' );

// The finding this closes: an administrator who adds their OWN CDN range must
// not thereby make CF-Connecting-IP authoritative, because that CDN does not
// write it — the visitor behind it would, and would then choose the country
// and VPN state the detector reports.
$GLOBALS['_faz_test_filters']['faz_trusted_proxy_cidrs'] = function ( $cidrs ) {
	return array_merge( (array) $cidrs, array( '198.51.100.0/24' ) );
};
assert_false( $cf_peer( '198.51.100.7' ), 'a range added through faz_trusted_proxy_cidrs is NOT a Cloudflare peer' );
$_SERVER = array(
	'REMOTE_ADDR'           => '198.51.100.7',  // the operator's own edge
	'HTTP_CF_CONNECTING_IP' => '8.8.8.8',       // forged by the visitor behind it
);
assert_eq(
	$resolve_client->invoke( $detector ),
	'198.51.100.7',
	'and a forged CF-Connecting-IP from that peer is ignored'
);
// Through the header that peer DOES write, the same visitor resolves normally.
$_SERVER = array(
	'REMOTE_ADDR'          => '198.51.100.7',
	'HTTP_X_FORWARDED_FOR' => '203.0.113.90',
);
assert_eq(
	$resolve_client->invoke( $detector ),
	'203.0.113.90',
	'while the named forwarded header from that peer still resolves the visitor'
);
unset( $GLOBALS['_faz_test_filters']['faz_trusted_proxy_cidrs'] );

// A Cloudflare origin that restores the visitor's address does not look like
// one: nginx real_ip, Apache mod_remoteip and most managed hosts leave
// REMOTE_ADDR as the visitor, or as 127.0.0.1 behind a loopback proxy. No
// range test can match, so it takes the operator's assertion — and nothing
// else, because mod_remoteip REMOVES the header once it has consumed it.
$_SERVER = array( 'REMOTE_ADDR' => '203.0.113.90' );
assert_false( $peer_is_cf->invoke( $detector ), 'a restored visitor address is not a Cloudflare peer on its own' );

$GLOBALS['_faz_test_filters']['faz_remote_addr_rewritten_by_cloudflare'] = function () {
	return true;
};
assert_true( $peer_is_cf->invoke( $detector ), 'with the operator assertion it is, with no CF-Connecting-IP present at all' );

// The Apache case the earlier revision made unreachable: the header is gone,
// consumed by mod_remoteip, and the site must still resolve its country.
$_SERVER = array( 'REMOTE_ADDR' => '127.0.0.1' );
assert_true( $peer_is_cf->invoke( $detector ), 'and behind a loopback proxy, where the header never arrives' );
unset( $GLOBALS['_faz_test_filters']['faz_remote_addr_rewritten_by_cloudflare'] );

// Without the assertion, a loopback peer is just a loopback peer.
assert_false( $peer_is_cf->invoke( $detector ), 'and without it, a loopback peer asserts nothing' );

// The retired geo-only filter grants nothing any more.
$GLOBALS['_faz_test_filters']['faz_geo_trusted_proxy_cidrs'] = function ( $cidrs ) {
	return array_merge( (array) $cidrs, array( '10.0.0.0/8' ) );
};
$_SERVER = array(
	'REMOTE_ADDR'           => '10.5.5.5',
	'HTTP_CF_CONNECTING_IP' => '8.8.8.8',
);
assert_eq(
	$resolve_client->invoke( $detector ),
	'10.5.5.5',
	'faz_geo_trusted_proxy_cidrs no longer grants CF-Connecting-IP trust'
);
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

// ---------- detect() — a header-derived country is not cached ----------
// The object cache is keyed on the address hash alone, and an address is not
// always one visitor: cached, the first arrival's CF-IPCountry would be served
// to everybody behind the same NAT or proxy for an hour. Geolocation already
// refuses to cache such a country; the detector keeps its own copy and must
// refuse the same way.

$cached_entries = function () {
	return count(
		array_filter(
			array_keys( $GLOBALS['_faz_test_cache'] ),
			function ( $k ) {
				return 0 === strpos( $k, Geo_Detector::CACHE_GROUP . ':' );
			}
		)
	);
};

$GLOBALS['_faz_test_cache']                                    = array();
$GLOBALS['_faz_test_filters']['faz_trust_cf_ipcountry_header'] = function () {
	return true;
};
$_SERVER = array(
	'REMOTE_ADDR'       => '162.158.42.42', // genuine CF edge
	'HTTP_CF_IPCOUNTRY' => 'DE',
);
$result  = $detector->detect();
assert_eq( $result['country'], 'DE', 'trusted CF-IPCountry from a Cloudflare peer resolves the country' );
assert_eq( $result['source'], 'cf_header', 'and the source says it came from the header' );
assert_eq( $cached_entries(), 0, 'a header-derived country is NOT written to the object cache' );
assert_eq( $detector->detect()['country'], 'DE', 'a second call re-reads the header instead' );

// The same address without the header is cached: the assertion above is about
// the SOURCE, not about caching having been switched off.
$GLOBALS['_faz_test_cache'] = array();
$_SERVER                    = array( 'REMOTE_ADDR' => '162.158.42.42' );
$result                     = $detector->detect();
assert_eq( $result['source'], 'unknown', 'with no header and no database the source is unknown' );
assert_eq( $cached_entries(), 1, 'and that result IS cached under the address' );
unset( $GLOBALS['_faz_test_filters']['faz_trust_cf_ipcountry_header'] );

// ---------- Additional CIDR boundary cases (round-2 regression guards) ----------


// /0 special case (CIDR matches the entire address space).
assert_true(
	$ip_in_cidr->invoke( $detector, '8.8.8.8',    '0.0.0.0/0' ),
	'/0 matches any v4 address (special-case prefix)'
);
assert_true(
	$ip_in_cidr->invoke( $detector, '2001:db8::1', '::/0' ),
	'/0 matches any v6 address'
);

// /8 prefix boundary — first byte boundary.
assert_true(
	$ip_in_cidr->invoke( $detector, '104.0.0.0',   '104.0.0.0/8' ),
	'/8 matches first byte boundary low'
);
assert_true(
	$ip_in_cidr->invoke( $detector, '104.255.255.255', '104.0.0.0/8' ),
	'/8 matches first byte boundary high'
);
assert_false(
	$ip_in_cidr->invoke( $detector, '105.0.0.0',   '104.0.0.0/8' ),
	'/8 rejects one-past first byte boundary'
);

// /31 (point-to-point) — 2-address subnet edge case.
assert_true(
	$ip_in_cidr->invoke( $detector, '192.0.2.0',  '192.0.2.0/31' ),
	'/31 first address ∈ range'
);
assert_true(
	$ip_in_cidr->invoke( $detector, '192.0.2.1',  '192.0.2.0/31' ),
	'/31 second address ∈ range'
);
assert_false(
	$ip_in_cidr->invoke( $detector, '192.0.2.2',  '192.0.2.0/31' ),
	'/31 third address ∉ range (only 2 addrs)'
);

// IPv4-mapped IPv6 (::ffff:1.2.3.4) — must NOT match v4 CIDR (different address families).
assert_false(
	$ip_in_cidr->invoke( $detector, '::ffff:104.16.5.10', '104.16.0.0/13' ),
	'IPv4-mapped IPv6 (::ffff:104.16.5.10) ∉ pure v4 CIDR (family mismatch is correct)'
);

// ---------- Summary ----------

echo "\n--\n";
echo "Tests:  $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n\n";

if ( $tests_failed > 0 ) { echo "\033[31mFAIL\033[0m\n"; exit( 1 ); }
echo "\033[32mPASS\033[0m\n";
exit( 0 );
