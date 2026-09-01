<?php
/**
 * A withdrawal must never be the row the throttle drops.
 *
 * The consent logger throttles one POST per consent_id per 300s. The consent_id
 * is deliberately KEPT across sessions (script.js keeps `consentid` so analytics
 * can correlate), so a visitor who accepts and then withdraws minutes later
 * posts the SAME id — and the withdrawal was dropped inside that window, with an
 * HTTP 200 the fire-and-forget client never inspects.
 *
 * The row that survives then affirmatively states "accepted" for a visitor who
 * has withdrawn. That is worse than a missing record: the register misstates the
 * visitor's standing consent, and Art. 7(3) withdrawal cannot be proven from it.
 *
 * A status CHANGE is the event accountability exists to record, never a replay,
 * so it bypasses the per-id throttle; a repeat of the same status still does not.
 * These cases pin that distinction against the real decision logic.
 *
 * Run: php tests/unit/test-consent-log-withdrawal-php.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['faz_throttled_keys'] = array();
$GLOBALS['faz_last_status']    = '';

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

/**
 * Stand-in for faz_throttle_request(): returns true (throttled) when the key was
 * already seen in this run, mirroring the transient-backed original.
 */
function faz_test_throttle( $key, $ttl = 300 ) {
	$now = isset( $GLOBALS['faz_fake_now'] ) ? $GLOBALS['faz_fake_now'] : 0;
	if ( isset( $GLOBALS['faz_throttled_keys'][ $key ] ) && $GLOBALS['faz_throttled_keys'][ $key ] > $now ) {
		return true;
	}
	$GLOBALS['faz_throttled_keys'][ $key ] = $now + (int) $ttl;
	return false;
}

/** Move the simulated clock forward, so a TTL can actually elapse. */
function faz_test_advance( $seconds ) {
	$GLOBALS['faz_fake_now'] = ( isset( $GLOBALS['faz_fake_now'] ) ? $GLOBALS['faz_fake_now'] : 0 ) + (int) $seconds;
}

/**
 * The decision under test, transcribed from Consent_Logger::handle_rest_consent.
 *
 * Kept as a transcription on purpose: the real method needs a WP_REST_Request,
 * the options table and a live $wpdb, none of which this standalone runner has.
 * The transcription is asserted against the shipped source below, so it cannot
 * silently drift from the code it stands for.
 *
 * @param string $consent_id     Consent id on the request.
 * @param string $status         Status being posted.
 * @param string $previous       Status of the newest stored row ('' when none).
 * @return bool True when the request is throttled (dropped).
 */
function faz_consent_is_throttled( $consent_id, $status, $previous ) {
	$is_consent_throttled = false;
	if ( '' !== $consent_id ) {
		$status = sanitize_key( $status );
		// Allowlisted BEFORE the comparison — see the shipped guard.
		if ( ! in_array( $status, array( 'accepted', 'rejected', 'partial', 'dnsmpi_optout', 'dns_rescinded', 'pmp_grant' ), true ) ) {
			$status = '';
		}
		$status_changed = '' !== $status && $status !== $previous;
		// Armed unconditionally; its verdict is ignored only for a change.
		$key           = 'faz_consent_' . substr( md5( $consent_id ), 0, 8 );
		$window_closed = faz_test_throttle( $key, 300 );
		// First change in the window is free; later ones are rate-limited.
		if ( $status_changed ) {
			$hash = substr( md5( $consent_id ), 0, 8 );
			if ( faz_test_throttle( 'faz_consent_chg1_' . $hash, 300 ) ) {
				$status_changed = ! faz_test_throttle( 'faz_consent_chgn_' . $hash, 10 );
			}
		}
		$is_consent_throttled = $status_changed ? false : $window_closed;
	}
	return $is_consent_throttled;
}

$passed = 0;
$failed = 0;
function wcheck( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		++$passed;
		echo "  \033[32mPASS\033[0m {$label}\n";
		return;
	}
	++$failed;
	echo "  \033[31mFAIL\033[0m {$label}\n";
}

$id = 'a0cwUGdtRzFHbllmRVJJMmx5clIwS3AwT0g5QXhtTFY';

// 1. The reported scenario: accept, then withdraw inside the throttle window.
$GLOBALS['faz_throttled_keys'] = array();
wcheck( ! faz_consent_is_throttled( $id, 'accepted', '' ), 'the first acceptance is logged' );
wcheck(
	! faz_consent_is_throttled( $id, 'rejected', 'accepted' ),
	'a withdrawal minutes later is NOT dropped, even on the same consent_id'
);

// 2. The throttle still does its job, from the FIRST repeat. An earlier shape of
//    this fix skipped the throttle call entirely on a change, so the bypass never
//    armed the window and the next identical replay was let through as well —
//    weakening the documented 300s guarantee by one request, which the
//    pr-2026-04-19-audit E2E case caught. Arming unconditionally fixes that.
$GLOBALS['faz_throttled_keys'] = array();
wcheck( ! faz_consent_is_throttled( $id, 'accepted', '' ), 'first write of a status goes through' );
wcheck(
	faz_consent_is_throttled( $id, 'accepted', 'accepted' ),
	'the very next replay of the same status is throttled — the bypass is for changes only'
);

// 3. Every direction of change, not just accept -> reject. A partial save and a
//    re-grant are equally the visitor changing their mind.
foreach ( array(
	array( 'partial', 'accepted' ),
	array( 'accepted', 'rejected' ),
	array( 'rejected', 'partial' ),
) as $pair ) {
	$GLOBALS['faz_throttled_keys'] = array();
	faz_consent_is_throttled( $id, $pair[1], '' );
	wcheck(
		! faz_consent_is_throttled( $id, $pair[0], $pair[1] ),
		"a change from {$pair[1]} to {$pair[0]} is logged"
	);
}

// 4. An empty status must not be treated as a change — that would hand any
//    caller a free bypass of the throttle by omitting the field.
$GLOBALS['faz_throttled_keys'] = array();
faz_consent_is_throttled( $id, 'accepted', '' );
wcheck(
	faz_consent_is_throttled( $id, '', 'accepted' ),
	'a missing status is not a "change" — it goes through the throttle, so it cannot be used to bypass it'
);

// 4b. A status outside the allowlist is not a change. sanitize_key() alone made
//     ANY non-empty string one, so a caller could post junk — or alternate two
//     valid values — and mint a row per request, turning the accountability
//     bypass into an unthrottled write path.
$GLOBALS['faz_throttled_keys'] = array();
faz_consent_is_throttled( $id, 'accepted', '' );
wcheck(
	faz_consent_is_throttled( $id, 'not-a-real-status', 'accepted' ),
	'a status outside the allowlist is not treated as a change'
);

// 4c. The FIRST change is free, later ones are rate-limited. A flat cap on every
//     change was tried first and re-broke the case this guard exists for; a flat
//     bypass left a script free to alternate valid statuses and mint a row per
//     request. Splitting the two keeps both properties.
$GLOBALS['faz_throttled_keys'] = array();
wcheck( ! faz_consent_is_throttled( $id, 'accepted', '' ), 'the first write lands' );
wcheck(
	! faz_consent_is_throttled( $id, 'rejected', 'accepted' ),
	'an immediate correction (accept then reject within seconds) is STILL logged'
);
faz_consent_is_throttled( $id, 'accepted', 'rejected' );
wcheck(
	faz_consent_is_throttled( $id, 'rejected', 'accepted' ),
	'sustained alternation is throttled, so it cannot be used as a write path'
);

// 4d. The change window EXPIRES. Without a clock the suite could not tell a 10s
//     limit from a permanent block, nor from a window of any other length — it
//     only ever observed the immediate refusal.
$GLOBALS['faz_throttled_keys'] = array();
$GLOBALS['faz_fake_now']       = 0;
faz_consent_is_throttled( $id, 'accepted', '' );          // first change, free
faz_consent_is_throttled( $id, 'rejected', 'accepted' );  // second, arms the 10s window
wcheck(
	faz_consent_is_throttled( $id, 'accepted', 'rejected' ),
	'a third change inside the 10s window is still refused'
);
faz_test_advance( 11 );
wcheck(
	! faz_consent_is_throttled( $id, 'accepted', 'rejected' ),
	'and once the 10s window has passed a change is logged again — the limit is a window, not a wall'
);
// Guard the other end: the 300s per-id window must NOT have expired with it.
wcheck(
	faz_consent_is_throttled( $id, 'accepted', 'accepted' ),
	'the 300s replay window outlives the 10s change window'
);
$GLOBALS['faz_fake_now'] = 0;

// 5. The transcription above must still match the shipped source. If the real
//    guard is edited, this file has to be revisited rather than quietly
//    continuing to test a stale copy of the logic.
$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/frontend/modules/consent-logger/class-consent-logger.php' );
wcheck(
	false !== strpos( $src, '$status_changed = \'\' !== $status && $status !== $previous;' ),
	'the shipped guard still computes status_changed the way this suite transcribes it'
);
wcheck(
	false !== strpos( $src, '$is_consent_throttled = $status_changed ? false : $window_closed;' ),
	'the shipped guard still arms the window unconditionally and ignores it only on a change'
);
wcheck(
	false !== strpos( $src, 'private function last_logged_status(' ),
	'the previous status is read back from the log rather than assumed'
);
wcheck(
	false !== strpos( $src, "'accepted', 'rejected', 'partial', 'dnsmpi_optout', 'dns_rescinded', 'pmp_grant'" ),
	'the shipped guard still allowlists the status before comparing it'
);
wcheck(
	false !== strpos( $src, "'faz_consent_chg1_'" ) && false !== strpos( $src, "'faz_consent_chgn_'" ),
	'the shipped guard frees the first change and rate-limits the ones after it'
);
wcheck(
	false !== strpos( $src, "'faz_consent_chgn_' . \$hash, 10 )" ),
	'and the change window is still the 10s one this suite simulates'
);

echo "\nconsent-log withdrawal: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
