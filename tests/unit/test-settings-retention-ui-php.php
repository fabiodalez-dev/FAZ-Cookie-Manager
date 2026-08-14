<?php
/**
 * Settings-screen regression checks for retention controls and effective-state
 * status labels. The settings API already tests persistence/clamping; this
 * pins the missing admin controls so a supported retention setting cannot
 * silently become unreachable again.
 *
 * Run: php tests/unit/test-settings-retention-ui-php.php
 *
 * @package FazCookie\Tests\Unit
 */

$root    = dirname( __DIR__, 2 );
$settings = (string) file_get_contents( $root . '/admin/views/settings.php' );
$status   = (string) file_get_contents( $root . '/admin/views/system-status.php' );
$passed   = 0;
$failed   = 0;

function settings_ui_check( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  [PASS] {$label}\n";
	} else {
		$failed++;
		echo "  [FAIL] {$label}\n";
	}
}

echo "== Settings retention UI ==\n";
settings_ui_check( false !== strpos( $settings, 'data-path="pageviews.retention"' ), 'pageview retention is editable in Settings' );
settings_ui_check( false !== strpos( $settings, 'data-path="dsar.retention"' ), 'DSAR retention is editable in Settings' );
settings_ui_check( false !== strpos( $settings, 'data-path="pageviews.retention" value="6" min="0" max="120"' ), 'pageview UI exposes the supported zero (no auto-purge) value' );
settings_ui_check( false !== strpos( $settings, 'data-path="dsar.retention" value="24" min="1" max="120"' ), 'DSAR UI preserves the accountability floor' );
settings_ui_check( false !== strpos( $status, 'Per-Cookie Consent' ), 'system status reports the effective per-cookie feature state' );
settings_ui_check( false === strpos( $status, 'disabled in 1.18.2' ), 'system status does not present a stale version-bound status message' );

echo "Passed: {$passed}; Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
