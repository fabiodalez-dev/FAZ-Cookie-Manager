<?php
/**
 * The DB-version gate must live in the migration keys' numbering space.
 *
 * needs_db_update() compares the stored `faz_cookie_consent_db_version` against
 * the HIGHEST migration key. update_db_version() used to store FAZ_VERSION —
 * fine while the two shared a numbering scheme, and broken the moment the fork
 * renumbered the plugin to 1.x while the migration keys stayed on the inherited
 * 3.x scheme. version_compare( '1.27.0', '3.6.0', '<' ) is true forever, so the
 * gate never closed: check_version() fires install() on the first request after
 * any version bump, and the entire chain — Migration_V2 included — replayed
 * against live data on every single release. Verified on the test site before
 * the fix: stored 1.27.0, plugin 1.27.0, gate OPEN.
 *
 * The same bug silently disabled its own safety net: the geo-migration re-entry
 * pin in update_db_version() compares $target against '3.6.0', which a 1.x
 * value can never satisfy, so a partial geo migration was never retried either.
 *
 * These tests pin the invariant that actually matters — the value written and
 * the value read must be comparable — plus the one-time repair, including the
 * two cases it must NOT touch.
 *
 * Run: php tests/unit/test-db-version-namespace-php.php
 *
 * @package FazCookie\Tests\Unit
 */

$run    = 0;
$failed = 0;
function dbv_check( $condition, $label ) {
	global $run, $failed;
	++$run;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		return;
	}
	++$failed;
	echo "  [FAIL] {$label}\n";
}

$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-activator.php' );

echo "== The write and the read share one numbering space ==\n";

// The regression in one line: if update_db_version() defaults to FAZ_VERSION
// again, the gate reopens permanently and every upgrade replays the chain.
dbv_check(
	false === strpos( $src, '$target = is_null( $version ) ? FAZ_VERSION : $version;' ),
	'update_db_version() does not store the PLUGIN version'
);
dbv_check(
	false !== strpos( $src, '$target = is_null( $version ) ? self::highest_db_update_version() : $version;' ),
	'update_db_version() stores the highest MIGRATION key'
);
dbv_check(
	false !== strpos( $src, 'private static function highest_db_update_version()' ),
	'one helper is the single source of truth for that value'
);

echo "== The migration keys really are in a different space from the version ==\n";

// If a future release renumbers either side into the other's range this stops
// being a latent trap and the test can go — but it must be a decision, not an
// accident, so assert the situation the fix exists for.
preg_match_all( "/'(3\.\d+\.\d+)'\s*=>/", $src, $keys );
$migration_keys = isset( $keys[1] ) ? $keys[1] : array();
dbv_check( count( $migration_keys ) >= 2, 'migration keys were found in the source' );
usort( $migration_keys, 'version_compare' );
$highest = end( $migration_keys );

$plugin_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/faz-cookie-manager.php' );
preg_match( "/define\(\s*'FAZ_VERSION',\s*'([^']+)'/", $plugin_src, $vm );
$plugin_version = isset( $vm[1] ) ? $vm[1] : '';
dbv_check( '' !== $plugin_version, 'FAZ_VERSION was found' );
dbv_check(
	version_compare( $plugin_version, $highest, '<' ),
	"the plugin version ({$plugin_version}) is BELOW the highest migration key ({$highest}) — which is exactly why storing it left the gate open"
);

echo "== The one-time repair heals only what the bug wrote ==\n";

// Mirror of repair_db_version_namespace(): heal a value below the FIRST
// migration key, leave everything else alone.
$first = reset( $migration_keys );
$repair = function ( $stored ) use ( $first, $highest ) {
	if ( null === $stored || '' === $stored ) {
		return $stored; // absent -> untouched, the default drives a real migration
	}
	return version_compare( (string) $stored, $first, '<' ) ? $highest : $stored;
};

dbv_check( $highest === $repair( '1.27.0' ), 'a value written by the bug is healed to the highest key' );
dbv_check( $highest === $repair( '1.13.0' ), 'an older buggy value is healed too' );

// The two negatives are what stop the repair from becoming a bug of its own.
// Healing a genuinely-behind install would SKIP migrations it still needs —
// silent schema drift, far worse than replaying them.
dbv_check( $first === $repair( $first ), 'an install genuinely at the first key is NOT healed' );
dbv_check(
	'3.5.0' === $repair( '3.5.0' ) && version_compare( $repair( '3.5.0' ), $highest, '<' ),
	'an install genuinely behind keeps its value and still migrates'
);
dbv_check( null === $repair( null ), 'an absent option is untouched — a fresh install migrates normally' );

echo "== The repair runs before the gate is consulted ==\n";

$check_at  = strpos( $src, 'public static function check_version()' );
$repair_at = strpos( $src, 'self::repair_db_version_namespace();' );
dbv_check( false !== $check_at && false !== $repair_at, 'check_version() calls the repair' );
dbv_check(
	false !== $repair_at && $repair_at > $check_at && ( $repair_at - $check_at ) < 400,
	'the repair is the first thing check_version() does, not an afterthought'
);

echo "\n{$run} checks, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
