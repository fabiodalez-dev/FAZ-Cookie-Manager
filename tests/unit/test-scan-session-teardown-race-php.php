<?php
/**
 * #245 — a teardown must be final.
 *
 * touch_browser_scan_session() reads the session transient, validates it, and
 * only then writes. A request already in flight when the crawl ended used to
 * write the pre-teardown array back afterwards, resurrecting a session no tab
 * was driving; the next scan then met faz_browser_scan_in_progress and the
 * administrator waited out the idle TTL for a crawl that had finished.
 *
 * Deleting the transient cannot express "this was closed" — absence and "never
 * existed" are the same observation, so a late writer has nothing to detect.
 * The teardown now leaves a short-lived tombstone, and the touch path re-reads
 * immediately before writing and abandons the write when it finds one.
 *
 * Run: php tests/unit/test-scan-session-teardown-race-php.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'YEAR_IN_SECONDS', 31536000 );

$GLOBALS['faz_t']      = array();   // transient store
$GLOBALS['faz_meta']   = array();
$GLOBALS['faz_cookie'] = array();
// Fires on the Nth read of the session key, so a teardown can be placed exactly
// between touch()'s initial read and its re-read — the window the fix closes.
// Firing on the FIRST read (the obvious thing, and what this harness did at
// first) proves nothing: touch() then sees the closed session in its opening
// validation and bails long before reaching the code under test, so the
// assertions pass whether the fix is present or not.
$GLOBALS['faz_on_read']       = null;
$GLOBALS['faz_on_read_key']   = '';
$GLOBALS['faz_on_read_after'] = 1;
$GLOBALS['faz_read_count']    = 0;

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
function wp_generate_uuid4() { return sprintf( '%08x-0000-4000-8000-%012x', mt_rand(), mt_rand() ); }
function wp_rand( $min = 0, $max = 0 ) { return mt_rand( $min, $max ); }
function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['faz_meta'][ $k ] ?? array(); }
function delete_user_meta( $u, $k, $v = '' ) { unset( $GLOBALS['faz_meta'][ $k ] ); return true; }
function add_user_meta( $u, $k, $v, $unique = false ) { $GLOBALS['faz_meta'][ $k ][] = $v; return true; }

function get_transient( $key ) {
	if ( $GLOBALS['faz_on_read'] && $key === $GLOBALS['faz_on_read_key'] ) {
		++$GLOBALS['faz_read_count'];
		if ( $GLOBALS['faz_read_count'] > $GLOBALS['faz_on_read_after'] ) {
			$cb = $GLOBALS['faz_on_read'];
			$GLOBALS['faz_on_read'] = null;   // one shot
			$cb( $key );
			return $GLOBALS['faz_t'][ $key ] ?? false;   // post-callback state
		}
	}
	return $GLOBALS['faz_t'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['faz_t'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['faz_t'][ $key ] ); return true; }

require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';

use FazCookie\Admin\Modules\Scanner\Includes\Controller;

$ok = 0; $ko = 0;
function t( $c, $l ) { global $ok, $ko; if ( $c ) { ++$ok; echo "  PASS $l\n"; } else { ++$ko; echo "  FAIL $l\n"; } }

$ctl   = Controller::get_instance();
$token = str_repeat( 'ab', 16 );
$scan  = str_repeat( 'cd', 16 );

$ref       = new ReflectionClass( $ctl );
$key_m     = $ref->getMethod( 'browser_scan_transient_key' );     $key_m->setAccessible( true );
$akey_m    = $ref->getMethod( 'browser_scan_active_transient_key' ); $akey_m->setAccessible( true );
$tomb_m    = $ref->getMethod( 'tombstone_browser_scan_session' ); $tomb_m->setAccessible( true );
$skey      = $key_m->invoke( null, $token );
$akey      = $akey_m->invoke( null, 7 );

$seed = function () use ( $skey, $akey, $token, $scan ) {
	$GLOBALS['faz_t'] = array(
		$skey => array( 'user_id' => 7, 'scan_id' => $scan, 'created_at' => time(), 'touched_at' => time() ),
		$akey => array( 'token' => $token, 'scan_id' => $scan, 'created_at' => time() ),
	);
};

// 1. The baseline the fix must not break: a live session still slides.
$seed();
$GLOBALS['faz_on_read'] = null;
t( true === $ctl->touch_browser_scan_session( $token, $scan ), 'a live session is still renewed by a heartbeat' );
t( ! empty( $GLOBALS['faz_t'][ $skey ]['user_id'] ), 'and the session survives the renewal' );

// 2. The race itself. The teardown lands after touch() has read and validated,
//    which is precisely the window that used to resurrect the session.
$seed();
// The REAL teardown, not the tombstone helper. Calling the helper directly
// tested that touch() reacts to a tombstone, which is true but does not prove
// the teardown produces one — a first version of this test did exactly that and
// stayed green when the teardown was reverted to a plain delete.
// finish_browser_scan_session() identifies the session from the marker cookie,
// exactly as the real teardown request does.
$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $token;
$GLOBALS['faz_on_read_key']   = $skey;
$GLOBALS['faz_on_read_after'] = 1;   // let the initial read through, fire on the re-read
$GLOBALS['faz_read_count']    = 0;
$GLOBALS['faz_on_read'] = function () use ( $ctl, $scan ) {
	$ctl->finish_browser_scan_session( $scan );   // teardown, mid-flight
};
$resurrected = $ctl->touch_browser_scan_session( $token, $scan );
t( false === $resurrected, 'a heartbeat that raced a teardown reports failure' );
unset( $_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] );
$after = $GLOBALS['faz_t'][ $skey ] ?? false;
t( empty( $after['user_id'] ), 'and does NOT write the pre-teardown session back' );

// 3. The regression this fix could have introduced: the tombstone is an array,
//    so a reader that only checked is_array() would mistake it for a live
//    session and refuse the next scan — the very symptom #245 describes.
$GLOBALS['faz_t'] = array();
$tomb_m->invoke( null, $token, 7 );
// is_browser_scan_request() is the guard that decides whether a request belongs
// to a live capture session; it requires user_id, which a tombstone has not.
$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $token;
t( false === Controller::is_browser_scan_request(), 'a tombstone is not mistaken for a live capture session' );
unset( $_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] );
$tombstone = $GLOBALS['faz_t'][ $akey ] ?? array();
t( empty( $tombstone['token'] ) && empty( $tombstone['scan_id'] ),
	'and carries none of the fields the active-session guards require' );

echo "\nscan session teardown race: $ok passed, $ko failed\n";
exit( $ko > 0 ? 1 : 0 );
