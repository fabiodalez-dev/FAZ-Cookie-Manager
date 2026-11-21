<?php
/**
 * Ipinfo_Client's hourly lookup budget is taken atomically.
 *
 * Without a persistent object cache the budget counters are options rows
 * changed by conditional statements, and with one they are wp_cache_incr()
 * counters. A read followed by a write let requests that overlap all read the
 * same count and each write n + 1, so a flood timed to overlap got more
 * upstream lookups than the ceiling. This replays that interleaving on both
 * backends and checks that every request counts.
 *
 * Run: php tests/unit/test-ipinfo-budget-atomic-php.php
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
/**
 * A persistent object cache with the properties the budget relies on: entries
 * expire on the shared test clock, add() is create-if-absent, and incr()
 * changes the value but keeps the entry's own expiry.
 */
$GLOBALS['faz_test_ext_cache']   = false;
$GLOBALS['faz_test_incr_creates'] = false;
function wp_using_ext_object_cache() {
	return ! empty( $GLOBALS['faz_test_ext_cache'] );
}
function faz_cache_live( $k ) {
	if ( ! isset( $GLOBALS['_faz_test_cache'][ $k ] ) ) {
		return false;
	}
	$exp = $GLOBALS['_faz_test_cache_exp'][ $k ] ?? 0;
	if ( $exp && $exp <= $GLOBALS['faz_test_now'] ) {
		unset( $GLOBALS['_faz_test_cache'][ $k ], $GLOBALS['_faz_test_cache_exp'][ $k ] );
		return false;
	}
	return true;
}
function wp_cache_get( $key, $group = '' ) {
	$k = "$group:$key";
	return faz_cache_live( $k ) ? $GLOBALS['_faz_test_cache'][ $k ] : false;
}
function wp_cache_set( $key, $val, $group = '', $ttl = 0 ) {
	$GLOBALS['faz_test_cache_set_log'][] = $key;
	$k = "$group:$key";
	$GLOBALS['_faz_test_cache'][ $k ]     = $val;
	$GLOBALS['_faz_test_cache_exp'][ $k ] = $ttl ? $GLOBALS['faz_test_now'] + (int) $ttl : 0;
	return true;
}
function wp_cache_add( $key, $val, $group = '', $ttl = 0 ) {
	$k = "$group:$key";
	if ( faz_cache_live( $k ) ) {
		return false;
	}
	$GLOBALS['_faz_test_cache'][ $k ]     = $val;
	$GLOBALS['_faz_test_cache_exp'][ $k ] = $ttl ? $GLOBALS['faz_test_now'] + (int) $ttl : 0;
	if ( ! empty( $GLOBALS['faz_test_drop_after_add'] ) && false !== strpos( $key, '_n_' ) ) {
		// The entry is evicted right after it was created, before incr() runs.
		unset( $GLOBALS['_faz_test_cache'][ $k ], $GLOBALS['_faz_test_cache_exp'][ $k ] );
	}
	return true;
}
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	$k = "$group:$key";
	if ( ! faz_cache_live( $k ) ) {
		if ( ! empty( $GLOBALS['faz_test_incr_creates'] ) ) {
			// Redis Object Cache: INCRBY creates the key, with no expiry.
			return wp_cache_set( $key, $offset, $group, 0 ) ? $offset : false;
		}
		return false;
	}
	$GLOBALS['_faz_test_cache'][ $k ] = (int) $GLOBALS['_faz_test_cache'][ $k ] + $offset;
	return $GLOBALS['_faz_test_cache'][ $k ];
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

/**
 * The three statements the budget runs, on an in-memory options table with
 * MySQL's semantics: INSERT IGNORE affects one row or none, the conditional
 * UPDATE only a row that still holds the value it names. `$stale` makes the
 * next reads of a row answer what it held before, as a request that read it
 * before another one wrote does.
 */
class Faz_Test_Wpdb {
	public $options = 'wp_options';
	public $rows    = array();
	public $stale   = array();
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}
		return $query;
	}
	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}
	public function get_var( $query ) {
		if ( ! preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '([^']*)'$/", $query, $m ) ) {
			return null;
		}
		if ( ! empty( $this->stale[ $m[1] ] ) ) {
			return array_shift( $this->stale[ $m[1] ] );
		}
		return array_key_exists( $m[1], $this->rows ) ? $this->rows[ $m[1] ] : null;
	}
	public function query( $query ) {
		if ( preg_match( "/^INSERT IGNORE INTO wp_options \\(option_name, option_value, autoload\\) VALUES \\('([^']*)', '([^']*)', 'no'\\)$/", $query, $m ) ) {
			if ( array_key_exists( $m[1], $this->rows ) ) {
				return 0;
			}
			$this->rows[ $m[1] ] = $m[2];
			return 1;
		}
		if ( preg_match( "/^UPDATE wp_options SET option_value = '([^']*)' WHERE option_name = '([^']*)' AND option_value = '([^']*)'$/", $query, $m ) ) {
			if ( ! array_key_exists( $m[2], $this->rows ) || $this->rows[ $m[2] ] !== $m[3] ) {
				return 0;
			}
			$this->rows[ $m[2] ] = $m[1];
			return 1;
		}
		if ( preg_match( "/^DELETE FROM wp_options WHERE option_name LIKE '([^']*)' AND CAST\\(SUBSTRING_INDEX\\(option_value, ':', 1\\) AS UNSIGNED\\) < (\\d+)$/", $query, $m ) ) {
			$prefix = stripslashes( rtrim( $m[1], '%' ) );
			$prefix = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $prefix );
			$gone   = 0;
			foreach ( $this->rows as $name => $value ) {
				if ( 0 === strpos( $name, $prefix ) && (int) explode( ':', $value )[0] < (int) $m[2] ) {
					unset( $this->rows[ $name ] );
					++$gone;
				}
			}
			return $gone;
		}
		return false;
	}
}

function faz_new_wpdb() {
	$GLOBALS['wpdb'] = new Faz_Test_Wpdb();
	return $GLOBALS['wpdb'];
}

echo "\n== Ipinfo_Client budget: options-row backend ==\n\n";
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_optin']   = true;
$GLOBALS['_faz_test_options']['faz_geo_ipinfo_api_key'] = Secrets::encrypt( 'mykey' );
$GLOBALS['_faz_test_http_stub']                         = array( 'response' => array( 'code' => 200 ), 'body' => '{"vpn":false}' );
$site = 'faz_ipinfo_win_' . Ipinfo_Client::BUDGET_KEY;

// 1. Plain counting: the window is one row, "start:count".
$db                              = faz_new_wpdb();
$GLOBALS['_faz_test_http_calls'] = 0;
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 3; };
$client = new Ipinfo_Client();
foreach ( array( '203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4' ) as $ip ) {
	$last = $client->lookup( $ip );
}
tr_check( 3 === $GLOBALS['_faz_test_http_calls'], 'a ceiling of 3 allows three upstream calls and refuses the fourth' );
tr_check( ( $GLOBALS['faz_test_now'] . ':3' ) === ( $db->rows[ $site ] ?? null ), 'the site window is one row holding its start and 3 slots' );
tr_check( isset( $last['retry_after'] ) && $last['retry_after'] > 3500 && $last['retry_after'] <= 3600, 'the refusal says how long the window has left' );
tr_check( array() === get_transient_keys_with( 'budget' ), 'and no budget transient is written' );

// 2. Two requests that both read before either wrote: both are counted.
$db                              = faz_new_wpdb();
$GLOBALS['_faz_test_transients'] = array();
$GLOBALS['_faz_test_http_calls'] = 0;
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 100; };
$client = new Ipinfo_Client();
$client->lookup( '203.0.113.10' );
$start = $GLOBALS['faz_test_now'];
tr_check( ( $start . ':1' ) === $db->rows[ $site ], 'the first lookup opens the window' );
$db->stale[ $site ] = array( $start . ':1', $start . ':1' ); // Two later requests read the row as it was.
$client->lookup( '203.0.113.11' );
$client->lookup( '203.0.113.12' );
tr_check( ( $start . ':3' ) === $db->rows[ $site ], 'two requests that read the same count both count: 3, not 2 (got ' . $db->rows[ $site ] . ')' );
tr_check( 3 === $GLOBALS['_faz_test_http_calls'], 'and each made its upstream call' );

// 3. At the ceiling, only one of two overlapping requests gets the last slot.
$db                              = faz_new_wpdb();
$GLOBALS['_faz_test_http_calls'] = 0;
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 2; };
$client = new Ipinfo_Client();
$client->lookup( '203.0.113.20' );
$start = $GLOBALS['faz_test_now'];
$db->stale[ $site ] = array( $start . ':1', $start . ':1', $start . ':1', $start . ':1' );
$a = $client->lookup( '203.0.113.21' );
$b = $client->lookup( '203.0.113.22' );
tr_check( 2 === $GLOBALS['_faz_test_http_calls'], 'with one slot left and two overlapping requests, one upstream call is made (got ' . $GLOBALS['_faz_test_http_calls'] . ')' );
tr_check( ( $start . ':2' ) === $db->rows[ $site ], 'the count stops at the ceiling' );
tr_check( null === $b['vpn'] && isset( $b['retry_after'] ), 'and the other is refused with a retry time' );

// 4. IPv6: overlapping misses in one /64 are all counted against the prefix.
$db                              = faz_new_wpdb();
$GLOBALS['_faz_test_http_calls'] = 0;
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 1000; };
$client = new Ipinfo_Client();
$client->lookup( '2001:db8:1:2::1' );
$prefix_row = null;
foreach ( array_keys( $db->rows ) as $name ) {
	if ( 0 === strpos( $name, 'faz_ipinfo_win_budget_v6_' ) ) {
		$prefix_row = $name;
	}
}
tr_check( null !== $prefix_row, 'a /64 gets its own window row' );
$start = $GLOBALS['faz_test_now'];
for ( $i = 2; $i <= 20; $i += 2 ) {
	// Pairs of requests: each reads the count before the other has written.
	$db->stale[ $prefix_row ] = array( $db->rows[ $prefix_row ], $db->rows[ $prefix_row ] );
	$client->lookup( '2001:db8:1:2::' . dechex( $i ) );
	$client->lookup( '2001:db8:1:2::' . dechex( $i + 1 ) );
}
tr_check( 20 === Ipinfo_Client::IPV6_LOOKUPS_PER_HOUR, 'the prefix ceiling is 20' );
tr_check( 20 === $GLOBALS['_faz_test_http_calls'], '20 pairs of overlapping misses stop at 20 upstream calls, not 40 (got ' . $GLOBALS['_faz_test_http_calls'] . ')' );
tr_check( ( $start . ':20' ) === $db->rows[ $prefix_row ], 'and the prefix row counts every one of them' );

// 5. A prefix over its ceiling does not spend the shared budget.
$site_after = $db->rows[ $site ];
$client->lookup( '2001:db8:1:2::ffff' );
tr_check( $site_after === $db->rows[ $site ], 'a refused /64 leaves the site count alone' );

// 6. A spent site budget refuses without creating a per-prefix row.
$db                              = faz_new_wpdb();
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 1; };
$client = new Ipinfo_Client();
$client->lookup( '203.0.113.30' );
for ( $i = 0; $i < 20; $i++ ) {
	$client->lookup( '2001:db8:' . dechex( $i + 100 ) . '::1' );
}
tr_check( 1 === count( $db->rows ), 'rotating /64s past a spent site budget writes no per-prefix rows (rows: ' . count( $db->rows ) . ')' );

// 7. New hour: the window starts over and expired prefix rows are swept.
$db                              = faz_new_wpdb();
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 50; };
$client = new Ipinfo_Client();
$client->lookup( '2001:db8:aaaa:1::1' );
$client->lookup( '203.0.113.40' );
tr_check( count( $db->rows ) >= 2, 'a v6 lookup leaves a prefix row and the site row' );
faz_test_advance( 3601 );
$client->lookup( '203.0.113.41' );
tr_check( ( $GLOBALS['faz_test_now'] . ':1' ) === $db->rows[ $site ], 'after the hour the site window restarts at 1' );
$left = array_filter( array_keys( $db->rows ), function ( $n ) { return 0 === strpos( $n, 'faz_ipinfo_win_budget_v6_' ); } );
tr_check( array() === array_values( $left ), 'and the expired per-prefix rows are swept' );

// 8. A query that fails is a refusal of the lookup, not an error.
$GLOBALS['wpdb'] = new class() extends Faz_Test_Wpdb {
	public function query( $query ) {
		return false;
	}
};
$GLOBALS['_faz_test_http_calls'] = 0;
$client = new Ipinfo_Client();
$res    = $client->lookup( '203.0.113.50' );
tr_check( 0 === $GLOBALS['_faz_test_http_calls'] && null === $res['vpn'], 'a database that cannot take the slot makes no upstream call and gives no verdict' );

// 9. A persistent object cache: the counter belongs to its window. The start
// and the counter are separate entries; here the counter was evicted and
// re-added later, so it outlives the window it counted. It must not be read
// into the next one.
echo "\n== Ipinfo_Client budget: persistent object cache ==\n\n";
$GLOBALS['faz_test_ext_cache']   = true;
$GLOBALS['_faz_test_cache']      = array();
$GLOBALS['_faz_test_cache_exp']  = array();
$GLOBALS['_faz_test_http_calls'] = 0;
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 4; };
$client = new Ipinfo_Client();
$t0     = $GLOBALS['faz_test_now'];
$client->lookup( '203.0.113.60' );
$client->lookup( '203.0.113.61' );
tr_check( 2 === $GLOBALS['_faz_test_http_calls'], 'two lookups are counted in the first window' );
// Evict the counter and let another lookup re-add it 1000 s into the window:
// its expiry is now later than the start entry\'s.
foreach ( array_keys( $GLOBALS['_faz_test_cache'] ) as $k ) {
	if ( false !== strpos( $k, 'budget_n_' ) ) {
		unset( $GLOBALS['_faz_test_cache'][ $k ], $GLOBALS['_faz_test_cache_exp'][ $k ] );
	}
}
faz_test_advance( 1000 );
$client->lookup( '203.0.113.62' );
$counters = array_values( array_filter( array_keys( $GLOBALS['_faz_test_cache'] ), function ( $k ) { return false !== strpos( $k, 'budget_n_' ); } ) );
tr_check( 1 === count( $counters ) && false !== strpos( $counters[0], 'budget_n_' . $t0 ), 'the counter is named after the window it counts' );
// The start entry expires at t0 + 3600; the counter at t0 + 4600.
faz_test_advance( 2700 ); // t0 + 3700: a new window.
$GLOBALS['_faz_test_http_calls'] = 0;
for ( $i = 70; $i < 76; $i++ ) {
	$client->lookup( '203.0.113.' . $i );
}
tr_check( 4 === $GLOBALS['_faz_test_http_calls'], 'the new window allows its own 4 lookups, not 4 minus what the old counter held (got ' . $GLOBALS['_faz_test_http_calls'] . ')' );
// Overlap on this backend: incr is the atomic step, so n lookups count n.
$GLOBALS['_faz_test_cache']      = array();
$GLOBALS['_faz_test_cache_exp']  = array();
$GLOBALS['_faz_test_http_calls'] = 0;
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 1000; };
$client = new Ipinfo_Client();
for ( $i = 1; $i <= 40; $i++ ) {
	$client->lookup( '2001:db8:9:9::' . dechex( $i ) );
}
tr_check( Ipinfo_Client::IPV6_LOOKUPS_PER_HOUR === $GLOBALS['_faz_test_http_calls'], 'one /64 stops at its ceiling of 20 on the object-cache backend (got ' . $GLOBALS['_faz_test_http_calls'] . ')' );

// 10. The counter is dropped while its window is under way: the slots already
// taken are not handed out a second time.
$GLOBALS['_faz_test_cache']      = array();
$GLOBALS['_faz_test_cache_exp']  = array();
$GLOBALS['_faz_test_http_calls'] = 0;
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 1000; };
$client = new Ipinfo_Client();
for ( $i = 1; $i <= 10; $i++ ) {
	$client->lookup( '2001:db8:7:7::' . dechex( $i ) );
}
foreach ( array_keys( $GLOBALS['_faz_test_cache'] ) as $k ) {
	if ( false !== strpos( $k, 'budget_v6_' ) && false !== strpos( $k, '_n_' ) ) {
		unset( $GLOBALS['_faz_test_cache'][ $k ], $GLOBALS['_faz_test_cache_exp'][ $k ] );
	}
}
$GLOBALS['faz_test_cache_set_log'] = array();
faz_test_advance( 30 );
for ( $i = 11; $i <= 50; $i++ ) {
	$client->lookup( '2001:db8:7:7::' . dechex( $i ) );
}
tr_check( $GLOBALS['_faz_test_http_calls'] <= Ipinfo_Client::IPV6_LOOKUPS_PER_HOUR, 'a /64 whose counter was dropped mid-window still stays within 20 lookups (got ' . $GLOBALS['_faz_test_http_calls'] . ')' );
$set_counters = array_filter( $GLOBALS['faz_test_cache_set_log'], function ( $k ) { return false !== strpos( $k, '_n_' ); } );
tr_check( array() === array_values( $set_counters ), 'a lost counter is created already spent, in one add: it is never written to afterwards, so no request can take a slot in between' );

// 11. A backend whose incr creates a missing key (Redis INCRBY) still gives
// every counter an expiry.
$GLOBALS['faz_test_incr_creates'] = true;
$GLOBALS['_faz_test_cache']       = array();
$GLOBALS['_faz_test_cache_exp']   = array();
$client = new Ipinfo_Client();
$client->lookup( '203.0.113.90' );
$client->lookup( '2001:db8:8:8::1' );
$nonexpiring = array();
foreach ( $GLOBALS['_faz_test_cache'] as $k => $v ) {
	if ( false !== strpos( $k, '_n_' ) && empty( $GLOBALS['_faz_test_cache_exp'][ $k ] ) ) {
		$nonexpiring[] = $k;
	}
}
tr_check( array() === $nonexpiring, 'every window counter carries an expiry on an incr that creates keys (without: ' . implode( ', ', $nonexpiring ) . ')' );
$GLOBALS['faz_test_incr_creates'] = false;

// 12. The counter is evicted between add() and incr() on a backend whose incr
// creates the key: that request is refused and the counter is put back at the
// ceiling with an expiry, so the rest of the window is not counted on from 1.
$GLOBALS['faz_test_incr_creates'] = true;
$GLOBALS['_faz_test_cache']       = array();
$GLOBALS['_faz_test_cache_exp']   = array();
$GLOBALS['_faz_test_http_calls']  = 0;
$GLOBALS['_faz_test_filters']['faz_ipinfo_lookups_per_hour'] = function () { return 1000; };
$client = new Ipinfo_Client();
$client->lookup( '2001:db8:9:9::1' );
faz_test_advance( 30 );
foreach ( array_keys( $GLOBALS['_faz_test_cache'] ) as $k ) {
	if ( false !== strpos( $k, 'budget_v6_' ) && false !== strpos( $k, '_n_' ) ) {
		unset( $GLOBALS['_faz_test_cache'][ $k ], $GLOBALS['_faz_test_cache_exp'][ $k ] );
	}
}
$GLOBALS['faz_test_drop_after_add'] = true;
$GLOBALS['_faz_test_http_calls']    = 0;
$client->lookup( '2001:db8:9:9::2' );
$GLOBALS['faz_test_drop_after_add'] = false;
$after_race = $GLOBALS['_faz_test_http_calls'];
for ( $i = 3; $i <= 40; $i++ ) {
	$client->lookup( '2001:db8:9:9::' . dechex( $i ) );
}
tr_check( 0 === $after_race, 'a counter evicted between add and incr: that request takes no slot (got ' . $after_race . ' lookups)' );
tr_check( 0 === $GLOBALS['_faz_test_http_calls'], 'a counter evicted between add and incr does not reopen the window: no lookup afterwards (got ' . $GLOBALS['_faz_test_http_calls'] . ')' );
$restored = array();
foreach ( $GLOBALS['_faz_test_cache'] as $k => $v ) {
	if ( false !== strpos( $k, 'budget_v6_' ) && false !== strpos( $k, '_n_' ) ) {
		$restored[ $k ] = array( $v, $GLOBALS['_faz_test_cache_exp'][ $k ] ?? 0 );
	}
}
tr_check( 1 === count( $restored ) && reset( $restored )[0] >= Ipinfo_Client::IPV6_LOOKUPS_PER_HOUR && ! empty( reset( $restored )[1] ), 'the evicted counter is restored at the ceiling and with an expiry (' . json_encode( $restored ) . ')' );
$GLOBALS['faz_test_incr_creates'] = false;
$GLOBALS['faz_test_ext_cache']    = false;

function get_transient_keys_with( $needle ) {
	return array_values( array_filter( array_keys( $GLOBALS['_faz_test_transients'] ), function ( $k ) use ( $needle ) {
		return false !== strpos( $k, $needle );
	} ) );
}

echo "\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
}
