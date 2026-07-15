<?php
/**
 * Standalone unit tests for FazCookie\Includes\Cache invalidation under a
 * persistent external object cache (issue #125, "Cookie banner not saving"
 * with Redis Object Cache active).
 *
 * Subsystem: cache-redis-invalidation
 *
 * The failure mode being pinned:
 *   - With a persistent object-cache drop-in (Redis/Memcached), transients
 *     bypass wp_options entirely. Cache::delete_transient()'s wp_options
 *     LIKE-scan therefore finds nothing to delete, and the stale payloads
 *     survive in the external store under the unchanged transient prefix.
 *   - Cache::get() falls back from the object cache to transients, so after
 *     every invalidation the stale transient was re-promoted into the object
 *     cache: a banner save wrote the new row to the DB, but every read kept
 *     serving the pre-save payload ("saves but reverts on reload").
 *   - The fix rotates the transient prefix seed in delete_transient() (the
 *     same epoch-bump strategy invalidate_cache_group() uses for the object
 *     cache), making old entries unreachable on BOTH backends, and gives
 *     data transients a finite TTL so orphaned epochs self-expire.
 *
 * The harness simulates the two backends with in-memory stores that survive
 * across simulated "requests" (a new request = Cache::reset_prefix_cache()):
 *   - $GLOBALS['faz_obj_cache']   — the wp_cache_* backend (always persistent
 *     here, like Redis).
 *   - $GLOBALS['faz_transients']  — the transient store. In "Redis mode"
 *     (default) the wpdb options scan sees NONE of these keys; flipping
 *     $GLOBALS['faz_db_transients'] to true switches to "DB mode" where the
 *     scan sees them all (plain wp_options install).
 *
 * Run from project root:
 *   php tests/unit/test-cache-redis-invalidation-php.php
 *
 * Exit 0 = all pass; 1 = at least one failure.
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ---------- Backend simulation ----------

$GLOBALS['faz_obj_cache']      = array(); // group|key => value.
$GLOBALS['faz_transients']     = array(); // key => value.
$GLOBALS['faz_transient_ttls'] = array(); // key => ttl passed to set_transient.
$GLOBALS['faz_db_transients']  = false;   // false = Redis mode, true = DB mode.

function wp_cache_get( $key, $group = '' ) { // phpcs:ignore
	$k = $group . '|' . $key;
	return array_key_exists( $k, $GLOBALS['faz_obj_cache'] ) ? $GLOBALS['faz_obj_cache'][ $k ] : false;
}
function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) { // phpcs:ignore
	$GLOBALS['faz_obj_cache'][ $group . '|' . $key ] = $value;
	return true;
}
function get_transient( $key ) { // phpcs:ignore
	return array_key_exists( $key, $GLOBALS['faz_transients'] ) ? $GLOBALS['faz_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) { // phpcs:ignore
	$GLOBALS['faz_transients'][ $key ]     = $value;
	$GLOBALS['faz_transient_ttls'][ $key ] = $ttl;
	return true;
}
function delete_transient( $key ) { // phpcs:ignore
	unset( $GLOBALS['faz_transients'][ $key ], $GLOBALS['faz_transient_ttls'][ $key ] );
	return true;
}
function is_wp_error( $thing ) { // phpcs:ignore
	return false;
}

/**
 * wpdb stub. In DB mode the LIKE-scan sees every `_transient_{prefix}%` row
 * that the transient store holds; in Redis mode it sees nothing (transients
 * never touch wp_options with an external object cache).
 */
class FazTest_WPDB { // phpcs:ignore
	public $options   = 'wp_options';
	public $last_like = '';

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}
	public function prepare( $query, ...$args ) {
		$this->last_like = isset( $args[0] ) ? $args[0] : '';
		return $query;
	}
	public function get_results( $query, $output = OBJECT ) { // phpcs:ignore
		if ( ! $GLOBALS['faz_db_transients'] ) {
			return array(); // Redis mode: nothing in wp_options.
		}
		$prefix = stripcslashes( rtrim( $this->last_like, '%' ) ); // "_transient_faz_transient_…_".
		$rows   = array();
		foreach ( array_keys( $GLOBALS['faz_transients'] ) as $key ) {
			$option_name = '_transient_' . $key;
			if ( 0 === strpos( $option_name, $prefix ) ) {
				$rows[] = array( 'option_name' => $option_name );
			}
		}
		return $rows;
	}
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
$GLOBALS['wpdb'] = new FazTest_WPDB();

require_once dirname( __DIR__, 2 ) . '/includes/class-cache.php';

use FazCookie\Includes\Cache;

// ---------- Assertion harness ----------

$faz_pass = 0;
$faz_fail = 0;
function faz_ok( $cond, $label ) { // phpcs:ignore
	global $faz_pass, $faz_fail;
	if ( $cond ) {
		++$faz_pass;
		echo "  [PASS] $label\n";
	} else {
		++$faz_fail;
		echo "  [FAIL] $label\n";
	}
}

/** Simulate the end of the current PHP request (per-request memoization dies). */
function faz_new_request() { // phpcs:ignore
	Cache::reset_prefix_cache();
}

echo "Cache invalidation with a persistent object cache (issue #125)  \n\n";

// ---------------------------------------------------------------------
// Redis mode: transients live in the external store, NOT wp_options.
// ---------------------------------------------------------------------
$GLOBALS['faz_db_transients'] = false;

$v1 = array( array( 'id' => 1, 'title' => 'old banner' ) );
$v2 = array( array( 'id' => 1, 'title' => 'NEW banner' ) );

Cache::set( 'all', 'banner', $v1 );
faz_ok( Cache::get( 'all', 'banner' ) === $v1, '01 seeded payload readable through Cache::get()' );

$seed_before = get_transient( 'faz_banner_transient_prefix' );

// The object-cache epoch seed and the payload both land in Redis; the
// transient copies must carry a finite TTL so rotated-away epochs expire.
$data_ttls = array();
foreach ( $GLOBALS['faz_transient_ttls'] as $key => $ttl ) {
	if ( 0 === strpos( $key, 'faz_transient_' ) ) {
		$data_ttls[] = $ttl;
	}
}
faz_ok(
	array( Cache::TRANSIENT_TTL ) === array_unique( $data_ttls ),
	'02 data transients are written with TRANSIENT_TTL (orphaned epochs self-expire)'
);
faz_ok(
	0 === $GLOBALS['faz_transient_ttls']['faz_banner_transient_prefix'],
	'03 the prefix seed itself has no expiry (stays autoloaded / maximally stable)'
);

// Fallback promotion still works: object cache evicted, transient survives.
$GLOBALS['faz_obj_cache'] = array();
faz_new_request();
faz_ok( Cache::get( 'all', 'banner' ) === $v1, '04 object-cache eviction → payload re-promoted from the transient copy' );

// THE regression: save + invalidate, then read from a fresh request.
// Pre-fix: the wp_options scan found nothing (Redis mode), the prefix seed
// stayed put, and get() resurrected $v1 from the external store.
Cache::delete( 'banner' );
faz_new_request();
faz_ok( false === Cache::get( 'all', 'banner' ), '05 REGRESSION #125: after Cache::delete() a fresh request must NOT see the stale payload' );

$seed_after = get_transient( 'faz_banner_transient_prefix' );
faz_ok(
	false !== $seed_after && $seed_after !== $seed_before,
	'06 delete_transient() rotates the transient prefix seed (epoch bump reaches Redis)'
);

// The new payload is stored and served under the fresh epoch.
Cache::set( 'all', 'banner', $v2 );
faz_new_request();
faz_ok( Cache::get( 'all', 'banner' ) === $v2, '07 post-save payload is served after invalidation' );

// Same-request coherence: delete + set + get without a request boundary.
Cache::delete( 'banner' );
Cache::set( 'all', 'banner', $v2 );
faz_ok( Cache::get( 'all', 'banner' ) === $v2, '08 delete/set/get inside one request stays coherent (memoization reset)' );

// ---------------------------------------------------------------------
// DB mode: plain install, transients ARE wp_options rows. The LIKE-scan
// cleanup must still physically remove the old epoch's rows.
// ---------------------------------------------------------------------
$GLOBALS['faz_db_transients']  = true;
$GLOBALS['faz_obj_cache']      = array();
$GLOBALS['faz_transients']     = array();
$GLOBALS['faz_transient_ttls'] = array();
faz_new_request();

Cache::set( 'all', 'banner', $v1 );
$old_keys = array();
foreach ( array_keys( $GLOBALS['faz_transients'] ) as $key ) {
	if ( 0 === strpos( $key, 'faz_transient_' ) ) {
		$old_keys[] = $key;
	}
}
faz_ok( 1 === count( $old_keys ), '09 DB mode: payload stored as one faz_transient_* row' );

Cache::delete( 'banner' );
faz_ok(
	! array_key_exists( $old_keys[0], $GLOBALS['faz_transients'] ),
	'10 DB mode: the LIKE-scan cleanup still deletes the old epoch row (no wp_options litter)'
);
faz_new_request();
faz_ok( false === Cache::get( 'all', 'banner' ), '11 DB mode: fresh request after delete does not see the stale payload' );

// ---------- Result ----------
echo "\n" . ( 0 === $faz_fail ? "ALL PASS ($faz_pass)\n" : "FAILED: $faz_fail, passed: $faz_pass\n" );
exit( 0 === $faz_fail ? 0 : 1 );
