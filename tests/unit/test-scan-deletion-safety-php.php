<?php
/**
 * Deleting a cookie row removes an entry from the site's PUBLIC cookie
 * declaration, and the usual trigger is a scan that did not observe it. That is
 * not the same as the cookie being absent: a site using delay-JS-until-
 * interaction fires its trackers for every real visitor and never inside a
 * passive scan iframe, and flow-only cookies (checkout, login) are never
 * reached at all.
 *
 * These checks pin the two guards that make the judgement survivable: a tally
 * that requires several consecutive complete scans before deletion is offered,
 * and a snapshot that makes a wrong purge reversible.
 */

$passed = 0;
$failed = 0;
function ds_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  [PASS] {$label}\n";
		return;
	}
	$failed++;
	echo "  [FAIL] {$label}\n";
}

/**
 * Whitespace-insensitive containment.
 *
 * These checks pin decisions, not indentation. A needle carrying a hard-coded
 * newline and three tabs turns any reformat of the file into a red test whose
 * subject never changed — and a red test nobody believes is worse than none.
 *
 * @param string $haystack Source under inspection.
 * @param string $needle   Code fragment expected in it.
 * @return bool
 */
function ds_contains( $haystack, $needle ) {
	$squeeze = static function ( $text ) {
		return preg_replace( '/\s+/', ' ', (string) $text );
	};
	return false !== strpos( $squeeze( $haystack ), $squeeze( $needle ) );
}

$controller = file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php' );
$scan_api   = file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/scanner/api/class-api.php' );
$cookie_api = file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/cookies/api/class-cookies-api.php' );
$cookies_js   = file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/js/pages/cookies.js' );
$cookies_view = file_get_contents( dirname( __DIR__, 2 ) . '/admin/views/cookies.php' );

echo "== Consecutive-miss tally ==\n";
ds_ok( ds_contains( $controller, 'const MISSED_SCANS_THRESHOLD = 2;' ), 'a cookie must be missing from more than one scan before deletion is offered' );
ds_ok( ds_contains( $controller, 'public function record_scan_observations' ), 'the tally is updated from the scan result' );
ds_ok( ds_contains( $controller, 'if ( ! $is_complete ) { return $counts; }' ), 'an incomplete scan cannot add to the tally — its silence is not evidence' );
ds_ok( ds_contains( $controller, 'if ( empty( $cookie->name ) || empty( $cookie->discovered ) ) { continue;' ), 'a hand-added cookie is never judged by a scan' );
ds_ok( ds_contains( $controller, 'if ( isset( $observed[ self::canonical_name( $cookie->name ) ] ) ) { continue;' ), 'seeing a cookie again clears its tally' );

// The old assertion here read "the threshold is applied server-side, not left
// to the browser" and proved it by grepping for the DEFINITION of
// deletable_stale_keys(). A definition with no callers is exactly what shipped:
// the tally was computed, returned in the REST response, and read by nobody, so
// the browser was the sole arbiter of what got deleted while this suite
// reported the opposite in green. A claim about enforcement has to be a claim
// about CONSUMERS, so all three of them are named — delete any one and this
// goes red.
ds_ok( ds_contains( $controller, 'public function deletable_stale_keys' ), 'the earned-deletable list is computed server-side' );
ds_ok(
	ds_contains( $cookie_api, 'Scanner_Controller::get_instance()->deletable_stale_keys()' )
		&& ds_contains( $cookie_api, "if ( 'stale' === \$reason ) {" )
		&& ds_contains( $cookie_api, 'if ( \'\' === $key || ! isset( $earned[ $key ] ) ) { ++$refused; continue; }' ),
	'the threshold is ENFORCED server-side on a stale purge, not merely computed'
);
ds_ok(
	ds_contains( $cookie_api, "'reason' => array(" )
		&& ds_contains( $cookie_api, '$earned = null;' ),
	'the gate is scoped to reason=stale — an unscoped admin bulk delete still deletes what was selected'
);
ds_ok(
	false !== strpos( $cookies_js, 'importResult.deletable_stale_keys' )
		&& false !== strpos( $cookies_js, 'getEarnedDeletableSet(res)' ),
	'the Cookies page intersects its single-scan diff with the earned-deletable list'
);
// The two ends must key identities the same way or the intersection is empty
// forever: a stale bar that never appears, inert while looking wired.
ds_ok(
	ds_contains( $controller, 'public static function canonical_key( $name, $domain ) {' )
		&& ds_contains( $controller, "\$domain = ltrim( \$domain, '.' );" )
		&& ds_contains( $controller, "\$domain = preg_replace( '/:\\d+\$/', '', \$domain );" )
		&& false !== strpos( $cookies_js, ".replace(/^\\.+/, '').replace(/:\\d+\$/, '')" ),
	'client and server build the same canonical key — lowercase name, dot- and port-stripped domain'
);
ds_ok(
	ds_contains( $controller, 'private static function canonical_missed_scan_counts' )
		&& ds_contains( $controller, 'self::canonical_missed_scan_counts( get_option( self::MISSED_SCANS_OPTION, array() ) )' ),
	'tallies written in the pre-canonical key format are migrated, not orphaned'
);
// The rows the tally judges are written from the POST-merge set inside
// save_scan_result(), which folds in script/embed-inferred cookies the client
// never sent. Judging them against the pre-merge client array would mark every
// inference-only entry as missing on every complete scan — so the observed set
// must come from the merged list the save returned, with the client array kept
// only as a fallback (an empty observed set increments every discovered row).
ds_ok( ds_contains( $scan_api, "\$observed_names = \$result['cookie_names'];" ), 'the tally judges the names actually persisted, inference included' );
ds_ok( ds_contains( $scan_api, "if ( ! empty( \$result['cookie_names'] ) && is_array( \$result['cookie_names'] ) ) {" ), 'a missing or empty cookie_names falls back instead of emptying the observed set' );

echo "== The scan must declare its own completeness ==\n";
ds_ok( ds_contains( $scan_api, "empty( \$metrics['incremental'] ) && empty( \$metrics['earlyStopReason'] ) && empty( \$metrics['stoppedReason'] )" ), 'incremental runs, early stops and cancelled runs do not count as complete' );
ds_ok( ds_contains( $scan_api, '0 === $pages_scanned ? false' ), 'a scan that visited nothing is never complete' );
ds_ok( ds_contains( $scan_api, "\$result['deletable_stale_keys']" ), 'the import reports which entries have earned deletability' );

echo "== Reversibility ==\n";
ds_ok( ds_contains( $cookie_api, 'const RECYCLE_BIN_OPTION' ), 'deleted rows are snapshotted' );
// Ordering, not co-presence. Both fragments existing somewhere in the file
// says nothing about which runs first, and moving $cookie->delete() above the
// snapshot is exactly the edit that makes a bulk delete irreversible — so the
// check compares positions on a whitespace-squeezed copy and fails if the
// delete comes first, or if either fragment has gone away.
$cookie_api_squeezed = preg_replace( '/\s+/', ' ', $cookie_api );
$snapshot_taken_at   = strpos( $cookie_api_squeezed, '$recycled[] = $snapshot;' );
$row_deleted_at      = strpos( $cookie_api_squeezed, '$cookie->delete();' );
ds_ok(
	ds_contains( $cookie_api, '$snapshot = $cookie->get_prepared_data();' )
		&& false !== $snapshot_taken_at
		&& false !== $row_deleted_at
		&& $snapshot_taken_at < $row_deleted_at,
	'the snapshot is taken BEFORE the row is deleted'
);
ds_ok( ds_contains( $cookie_api, "method_exists( \$cookie, 'get_script_data' )" ), 'the snapshot carries the blocker scripts, which get_prepared_data() omits' );
ds_ok( ds_contains( $cookie_api, 'public function restore_deleted' ), 'a restore path exists' );
// "Reachable" used to be proved by grepping the route string out of the file
// that registers it, which shows only that someone typed it. The route was
// registered and correct; no admin JS called it and no view offered a control,
// so an administrator could reach the undo only by hand-crafting an
// authenticated REST POST — and this suite called that reachable, in green.
// Reachability is a claim about the product, so the product surface is what
// gets asserted: the route, the read path that lets the affordance survive a
// reload, the JS that calls both, and the region it renders into.
ds_ok( ds_contains( $cookie_api, "'/' . \$this->rest_base . '/restore-deleted'" ), 'the restore route is registered' );
ds_ok( ds_contains( $cookie_api, "'/' . \$this->rest_base . '/deleted-batches'" ), 'the bin can be read back, so the undo survives a page reload' );
ds_ok(
	false !== strpos( $cookies_js, "FAZ.post('cookies/restore-deleted'" )
		&& false !== strpos( $cookies_js, "FAZ.get('cookies/deleted-batches')" )
		&& false !== strpos( $cookies_js, 'function updateRestoreBar()' ),
	'the restore path is reachable from the admin UI — not only by a hand-crafted REST call'
);
ds_ok(
	false !== strpos( $cookies_view, 'id="faz-restore-bar"' )
		&& false !== strpos( $cookies_view, 'aria-live="polite"' ),
	'the undo control has a live region to render into, beside the control that caused the loss'
);
// Both destructive callers must refresh it, or the affordance appears only
// after a reload — the moment it is least likely to be looked for.
ds_ok(
	substr_count( $cookies_js, 'updateRestoreBar();' ) >= 3,
	'both bulk-delete paths refresh the undo affordance, as does page load'
);
ds_ok( ds_contains( $cookie_api, 'isset( $current_names[ strtolower( (string) $data[\'name\'] ) ] )' ), 'a restore does not duplicate a cookie that came back on its own' );
ds_ok( ds_contains( $cookie_api, 'array_slice( $bin, 0, self::RECYCLE_BIN_BATCHES )' ), 'the bin is bounded — an undo, not a growing history' );

// A restore path that exists is not a restore path that works. The three
// checks below pin the guarantees the restore turns on, because "a function
// called restore_deleted is present" is exactly the assertion shape that let a
// restore which restored nothing ship green.
//
// (1) The snapshot is replayed through a fixed list of setters. A blind
// `foreach ( $data as $field => $value )` hands a wp_options blob the power to
// pick which public setter it calls — and get_prepared_data() names the
// identity 'id', so the very first key it would reach is the one that turns
// every INSERT into an UPDATE of a row that no longer exists.
ds_ok(
	ds_contains( $cookie_api, "\$restorable = array( 'name', 'slug', 'description', 'duration', 'type', 'domain', 'discovered', 'url_pattern', 'category', 'transfer', 'opt_in_script', 'opt_out_script', );" )
		&& ds_contains( $cookie_api, "\$setter = 'set_' . \$field; if ( method_exists( \$cookie, \$setter ) ) {" )
		&& ! ds_contains( $cookie_api, 'foreach ( $data as $field => $value )' ),
	'a snapshot value can never choose which public setter it calls'
);
// (2) The invariant stated as an assertion rather than left to depend on which
// keys the snapshot happened to carry.
// The row is retained rather than merely skipped: a snapshot that reached this
// branch was not restored, so dropping it from the bin with the rest of the
// batch would lose it. test-recycle-bin-restore-php.php drives that behaviour.
ds_ok( ds_contains( $cookie_api, 'if ( 0 !== $cookie->get_id() ) { $retained[] = $data; continue; }' ), 'a restore is always an INSERT' );
// (3) save() returns get_id() unconditionally, so `if ( $cookie->save() )` on
// a row that failed to insert is not a success test — it is a success claim.
ds_ok(
	ds_contains( $cookie_api, '$new_id = $cookie->save(); if ( $new_id ) { $restored++; }' )
		&& ! ds_contains( $cookie_api, 'if ( $cookie->save() ) {' ),
	'save() is not trusted as a success signal'
);
// Ordering again, in F045's shape, and scoped to restore_deleted: the file
// writes RECYCLE_BIN_OPTION twice, and the earlier write (the delete path)
// would satisfy a whole-file positional check no matter what the restore does.
// Consuming the batch before knowing anything was restored is what turns a
// failed restore into data loss — the undo record is destroyed and the retry
// has nothing left to put back.
$restore_starts_at = strpos( $cookie_api_squeezed, 'public function restore_deleted' );
$restore_ends_at   = false === $restore_starts_at
	? false
	: strpos( $cookie_api_squeezed, "return rest_ensure_response( array( 'restored' => \$restored ) );", $restore_starts_at );
$restore_body      = ( false !== $restore_starts_at && false !== $restore_ends_at )
	? substr( $cookie_api_squeezed, $restore_starts_at, $restore_ends_at - $restore_starts_at )
	: '';
$bin_guarded_at    = '' === $restore_body ? false : strpos( $restore_body, 'if ( $restored > 0 ) {' );
$bin_consumed_at   = '' === $restore_body ? false : strpos( $restore_body, 'update_option( self::RECYCLE_BIN_OPTION, $bin, false );' );
ds_ok(
	'' !== $restore_body
		&& false !== $bin_guarded_at
		&& false !== $bin_consumed_at
		&& $bin_guarded_at < $bin_consumed_at,
	'the bin is consumed only AFTER something was actually restored'
);

echo "\nPassed: {$passed}; Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
