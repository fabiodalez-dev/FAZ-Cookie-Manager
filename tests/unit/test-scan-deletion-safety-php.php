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

echo "== Consecutive-miss tally ==\n";
ds_ok( ds_contains( $controller, 'const MISSED_SCANS_THRESHOLD = 2;' ), 'a cookie must be missing from more than one scan before deletion is offered' );
ds_ok( ds_contains( $controller, 'public function record_scan_observations' ), 'the tally is updated from the scan result' );
ds_ok( ds_contains( $controller, 'if ( ! $is_complete ) { return $counts; }' ), 'an incomplete scan cannot add to the tally — its silence is not evidence' );
ds_ok( ds_contains( $controller, 'if ( empty( $cookie->name ) || empty( $cookie->discovered ) ) { continue;' ), 'a hand-added cookie is never judged by a scan' );
ds_ok( ds_contains( $controller, 'if ( isset( $observed[ $cookie->name ] ) ) { continue;' ), 'seeing a cookie again clears its tally' );
ds_ok( ds_contains( $controller, 'public function deletable_stale_keys' ), 'the threshold is applied server-side, not left to the browser' );

echo "== The scan must declare its own completeness ==\n";
ds_ok( ds_contains( $scan_api, "empty( \$metrics['incremental'] ) && empty( \$metrics['earlyStopReason'] )" ), 'incremental runs and early stops do not count as complete' );
ds_ok( ds_contains( $scan_api, '0 === $pages_scanned ? false' ), 'a scan that visited nothing is never complete' );
ds_ok( ds_contains( $scan_api, "\$result['deletable_stale_keys']" ), 'the import reports which entries have earned deletability' );

echo "== Reversibility ==\n";
ds_ok( ds_contains( $cookie_api, 'const RECYCLE_BIN_OPTION' ), 'deleted rows are snapshotted' );
ds_ok( ds_contains( $cookie_api, '$snapshot = $cookie->get_prepared_data();' ) && ds_contains( $cookie_api, '$recycled[] = $snapshot;' ), 'the snapshot is taken BEFORE the row is deleted' );
ds_ok( ds_contains( $cookie_api, "method_exists( \$cookie, 'get_script_data' )" ), 'the snapshot carries the blocker scripts, which get_prepared_data() omits' );
ds_ok( ds_contains( $cookie_api, 'public function restore_deleted' ), 'a restore path exists' );
ds_ok( ds_contains( $cookie_api, "'/' . \$this->rest_base . '/restore-deleted'" ), 'the restore path is reachable — a registered route, not dead code' );
ds_ok( ds_contains( $cookie_api, 'isset( $current_names[ strtolower( (string) $data[\'name\'] ) ] )' ), 'a restore does not duplicate a cookie that came back on its own' );
ds_ok( ds_contains( $cookie_api, 'array_slice( $bin, 0, self::RECYCLE_BIN_BATCHES )' ), 'the bin is bounded — an undo, not a growing history' );

echo "\nPassed: {$passed}; Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
