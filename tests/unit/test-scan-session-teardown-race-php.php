<?php
/** Deterministic interleavings around the real session lifecycle, on both stores. */
namespace FazCookie\Admin\Modules\Scanner\Includes {
	function headers_sent() { return false; }
	function setcookie( $name, $value, $options ) {
		$GLOBALS['cookie_writes'][] = array( $name, $value, $options );
		return true;
	}
}
namespace {
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'YEAR_IN_SECONDS', 31536000 );
class WP_Error { public function __construct( ...$args ) {} }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function __( $v, ...$u ) { return $v; }
function esc_html__( $v, ...$u ) { return $v; }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function is_ssl() { return true; }
function get_current_user_id() { return 7; }
function wp_doing_ajax() { return false; }
function is_admin() { return false; }
function wp_generate_uuid4() { static $id = 0; return sprintf( '%032x', ++$id ); }
function wp_rand( $min = 0, $max = 0 ) { return mt_rand( $min, $max ); }
function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['meta'][ $k ] ?? array(); }
function delete_user_meta( $u, $k, $v = '' ) {
	$GLOBALS['meta'][ $k ] = array_values( array_filter( $GLOBALS['meta'][ $k ] ?? array(), static function ( $row ) use ( $v ) { return $row !== $v; } ) );
	return true;
}
function add_user_meta( $u, $k, $v, $unique = false ) { $GLOBALS['meta'][ $k ][] = $v; return true; }
function wp_using_ext_object_cache() { return $GLOBALS['external']; }
function wp_cache_delete( $key, $group = '' ) {
	if ( 'options' === $group ) {
		unset( $GLOBALS['local'][ preg_replace( '/^_transient_/', '', $key ) ] );
	}
}
function concurrent_request( $cb ) {
	$local = $GLOBALS['local'];
	$GLOBALS['local'] = array();
	try { $cb(); } finally { $GLOBALS['local'] = $local; }
}
function read_store( $key, $fresh ) {
	if ( ! $fresh && array_key_exists( $key, $GLOBALS['local'] ) ) { return $GLOBALS['local'][ $key ]; }
	$value = $GLOBALS['store'][ $key ] ?? false;
	$GLOBALS['local'][ $key ] = $value;
	if ( $GLOBALS['on_read'] && $GLOBALS['read_key'] === $key ) {
		$cb = $GLOBALS['on_read']; $GLOBALS['on_read'] = null;
		concurrent_request( $cb ); // Return the pre-callback snapshot, as an in-flight request would.
	}
	return $value;
}
function get_transient( $key ) { return read_store( $key, false ); }
function wp_cache_get( $key, $group = '', $force = false ) { return read_store( $key, $force ); }
function set_transient( $key, $value, $ttl = 0 ) {
	if ( $GLOBALS['on_write'] && $GLOBALS['write_key'] === $key ) {
		$cb = $GLOBALS['on_write']; $GLOBALS['on_write'] = null;
		concurrent_request( $cb ); // The concurrent teardown finishes BEFORE this stale write lands.
	}
	$GLOBALS['store'][ $key ] = $value;
	$GLOBALS['ttl'][ $key ] = $ttl;
	unset( $GLOBALS['local'][ $key ] );
	return true;
}
function delete_transient( $key ) { unset( $GLOBALS['store'][ $key ], $GLOBALS['local'][ $key ] ); return true; }
require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';
use FazCookie\Admin\Modules\Scanner\Includes\Controller;
$ok = 0; $ko = 0;
function t( $c, $l ) { global $ok, $ko; if ( $c ) { ++$ok; echo "PASS $l\n"; } else { ++$ko; echo "FAIL $l\n"; } }
$ctl = Controller::get_instance();
$scan = str_repeat( 'c', 32 );
$next_scan = str_repeat( 'd', 32 );
$seed = function () use ( $ctl, $scan ) {
	$GLOBALS['store'] = $GLOBALS['local'] = $GLOBALS['ttl'] = $GLOBALS['meta'] = $GLOBALS['cookie_writes'] = array();
	$GLOBALS['on_read'] = $GLOBALS['on_write'] = null;
	$GLOBALS['read_key'] = $GLOBALS['write_key'] = '';
	$token = $ctl->start_browser_scan_session( $scan );
	$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $token;
	return $token;
};
foreach ( array( false, true ) as $external ) {
	$GLOBALS['external'] = $external;
	$backend = $external ? 'object cache' : 'options';
	$token = $seed();
	$key = 'faz_scan_session_' . hash( 'sha256', $token );
	t( $ctl->touch_browser_scan_session( $token, $scan ), "$backend: live heartbeat renews" );
	t( count( $GLOBALS['cookie_writes'] ) === 1, "$backend: heartbeat cannot overwrite a newer browser marker" );
	t( $GLOBALS['cookie_writes'][0][2]['expires'] >= time() + Controller::BROWSER_SCAN_MAX_AGE - 1, "$backend: start marker survives a long crawl" );
	t( ! $ctl->touch_browser_scan_session( $token, $next_scan ), "$backend: wrong scan cannot renew" );

	// Capture an old read, then close through the public finish path.
	$GLOBALS['read_key'] = $key;
	$GLOBALS['on_read'] = function () use ( $ctl, $scan ) { $ctl->finish_browser_scan_session( $scan ); };
	t( ! $ctl->touch_browser_scan_session( $token, $scan ), "$backend: teardown after initial read wins" );
	t( ! Controller::is_browser_scan_request(), "$backend: closed cookie cannot authorize capture" );
	t( ! $ctl->browser_scan_session_matches( $scan ), "$backend: closed cookie cannot authorize import" );
	t( ! $ctl->describe_browser_scan_session()['active'], "$backend: closed index is inactive" );
	t( count( $GLOBALS['cookie_writes'] ) === 1, "$backend: teardown cannot clear a newer browser marker" );

	// No amount of re-reading closes THIS window: inject at the actual write.
	foreach ( array( 'finish', 'abort', 'successor' ) as $mode ) {
		$token = $seed();
		$key = 'faz_scan_session_' . hash( 'sha256', $token );
		$GLOBALS['write_key'] = $key;
		$successor = null;
		$GLOBALS['on_write'] = function () use ( $ctl, $scan, $next_scan, $mode, &$successor ) {
			if ( 'abort' === $mode ) {
				unset( $_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] );
				t( $ctl->abort_browser_scan_session( $scan ), 'abort without marker reaches the owner fallback' );
			} else {
				$ctl->finish_browser_scan_session( $scan );
			}
			if ( 'successor' === $mode ) { $successor = $ctl->start_browser_scan_session( $next_scan ); }
		};
		t( ! $ctl->touch_browser_scan_session( $token, $scan ), "$backend/$mode: close between final read and write wins" );
		$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $token;
		t( ! $ctl->browser_scan_session_matches( $scan ), "$backend/$mode: stale rewritten payload stays revoked" );
		if ( 'successor' === $mode ) {
			t( $GLOBALS['store']['faz_scan_active_7']['token'] === $successor, "$backend: old heartbeat leaves successor index untouched" );
			$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $successor;
			t( $ctl->browser_scan_session_matches( $next_scan ), "$backend: successor remains importable" );
			t( $ctl->touch_browser_scan_session( $successor, $next_scan ), "$backend: successor heartbeat works" );
		}
	}

	// A held session is reclaimable, and its old heartbeat cannot hold the new one.
	$token = $seed();
	t( $ctl->hold_browser_scan_session( $scan ), "$backend: failed import holds evidence" );
	t( $ctl->touch_browser_scan_session( $token, $scan ) && 'held' === $ctl->describe_browser_scan_session()['state'], "$backend: heartbeat preserves held state" );
	$successor = $ctl->start_browser_scan_session( $next_scan );
	t( is_string( $successor ) && $successor !== $token, "$backend: new scan reclaims held session" );
	t( ! $ctl->hold_browser_scan_session( $scan ), "$backend: late hold cannot mark successor held" );
	t( ! $ctl->touch_browser_scan_session( $token, $scan ), "$backend: reclaimed token cannot renew" );
	$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $successor;
	t( 'live' === $ctl->describe_browser_scan_session()['state'], "$backend: successor stays live" );

	$token = $seed();
	$key = 'faz_scan_session_' . hash( 'sha256', $token );
	unset( $GLOBALS['store'][ $key . '_held' ] );
	$GLOBALS['store']['faz_scan_active_7']['state'] = 'held';
	t( $ctl->start_browser_scan_session( $scan ) === $token && 'live' === $ctl->describe_browser_scan_session()['state'], "$backend: resume clears a pre-upgrade held index" );

	$token = $seed();
	$key = 'faz_scan_session_' . hash( 'sha256', $token );
	$GLOBALS['read_key'] = $key;
	$GLOBALS['on_read'] = function () use ( $key ) { unset( $GLOBALS['store'][ $key ] ); };
	t( $ctl->start_browser_scan_session( $scan ) instanceof WP_Error, "$backend: expiry during discover cannot reset an old token's birth time" );
	t( ! isset( $GLOBALS['store'][ $key ] ), "$backend: expired reused token is not rewritten" );

	$token = $seed();
	$ctl->hold_browser_scan_session( $scan );
	t( $ctl->start_browser_scan_session( $scan ) === $token && 'live' === $ctl->describe_browser_scan_session()['state'], "$backend: explicit resume consumes held state" );
	$key = 'faz_scan_session_' . hash( 'sha256', $token );
	unset( $GLOBALS['store'][ $key ] ); // Idle expiry, with an old request-local cache.
	t( ! $ctl->describe_browser_scan_session()['active'], "$backend: an expired session's index is not a lock" );
	t( is_string( $ctl->start_browser_scan_session( $next_scan ) ), "$backend: idle expiry permits immediate next scan" );

	$token = $seed();
	$key = 'faz_scan_session_' . hash( 'sha256', $token );
	$GLOBALS['store'][ $key ]['created_at'] = time() - Controller::BROWSER_SCAN_MAX_AGE - 1;
	t( ! $ctl->browser_scan_session_matches( $scan ) && ! Controller::is_browser_scan_request(), "$backend: absolute expiry protects import and capture too" );
	t( $ctl->abort_browser_scan_session( $scan ), "$backend: explicit cleanup can revoke an expired token" );
	t( is_string( $ctl->start_browser_scan_session( $next_scan ) ), "$backend: absolute expiry releases the scan index" );
}
echo "\nscan session lifecycle: $ok passed, $ko failed\n";
exit( $ko > 0 ? 1 : 0 );
}
