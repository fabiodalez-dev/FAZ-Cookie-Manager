<?php
/**
 * Two silent data-handling failures in the retention / migration paths.
 *
 * 1. Batched retention used `DELETE … LIMIT`, a MySQL extension. SQLite rejects
 *    it, so on a SQLite-backed install every batch returned false, the guard
 *    logged once and broke, and cleanup returned 0 — which is also what "nothing
 *    to delete" returns. Consent logs and pageviews then grew without bound
 *    while the site reported successful retention. That is a data-minimisation
 *    failure, not a performance one, so it is pinned at the SQL level: the
 *    queries must be portable by construction, not by luck of the backend.
 *
 * 2. `seed_default_whitelist()` guarded only on "the list is currently empty",
 *    which cannot distinguish a fresh install from an administrator who cleared
 *    the whitelist deliberately. Every MIGRATIONS_VERSION bump re-ran it and
 *    wrote the defaults back over that decision — and the list it wrote was
 *    broader than the product's own defaults, whitelisting Google Fonts and two
 *    public CDNs that Settings::get_defaults() deliberately leaves blocked.
 *
 * Both are asserted against the shipped source, because neither can be observed
 * from a unit harness: one needs a SQLite-backed $wpdb, the other needs a real
 * migration run.
 *
 * Run: php tests/unit/test-retention-and-seed-php.php
 */

$tests_run = 0;
$failed    = 0;
function ret_eq( $actual, $expected, $label ) {
	global $tests_run, $failed;
	$tests_run++;
	if ( $actual === $expected ) {
		echo "  \033[32mPASS\033[0m {$label}\n";
		return;
	}
	$failed++;
	echo "  \033[31mFAIL\033[0m {$label}\n";
	echo "        expected: " . var_export( $expected, true ) . "\n";
	echo "        actual:   " . var_export( $actual, true ) . "\n";
}

$root = dirname( __DIR__, 2 );

echo "Retention portability and one-time seeding\n\n";

// --- 1. No DELETE … LIMIT anywhere in the plugin ----------------------------
// Written as a repo-wide scan rather than a two-file check: the failure mode is
// "somebody adds another batched purge the same way", and that is exactly the
// case a two-file check would miss.
$offenders = array();
$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $rii as $file ) {
	$path = $file->getPathname();
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	if ( false !== strpos( $path, '/node_modules/' ) || false !== strpos( $path, '/vendor/' ) || false !== strpos( $path, '/tests/' ) ) {
		continue;
	}
	$src = (string) file_get_contents( $path );
	// DELETE … LIMIT on one statement, tolerating newlines between the clauses.
	if ( preg_match( '#DELETE\s+FROM[^;"\']*?\bLIMIT\b#is', $src ) ) {
		$offenders[] = str_replace( $root . '/', '', $path );
	}
}
ret_eq( $offenders, array(), 'no DELETE … LIMIT survives anywhere (SQLite rejects it)' );

// --- and the replacement really is select-then-delete -----------------------
foreach ( array(
	'admin/modules/consentlogs/includes/class-controller.php' => 'log_id',
	'admin/modules/pageviews/includes/class-controller.php'   => 'id',
) as $rel => $pk ) {
	$src = (string) file_get_contents( $root . '/' . $rel );
	ret_eq(
		1 === preg_match( '#SELECT\s+' . preg_quote( $pk, '#' ) . '\s+FROM[^;]*?LIMIT 1000#is', $src ),
		true,
		basename( $rel ) . " — reads a bounded page of {$pk} first"
	);
	ret_eq(
		1 === preg_match( '#DELETE FROM \{\$table\} WHERE ' . preg_quote( $pk, '#' ) . ' IN \(#', $src ),
		true,
		basename( $rel ) . ' — deletes by primary key, which every backend supports'
	);
	// A failed SELECT returns an empty array, exactly like a matchless one. If
	// the two are not separated the original bug returns in a new costume.
	ret_eq(
		false !== strpos( $src, "'' !== \$wpdb->last_error" ),
		true,
		basename( $rel ) . ' — a failed read is distinguished from an empty one'
	);
	// Looping on the DELETE's own count stops early when a row vanished
	// concurrently, silently leaving expired rows behind.
	ret_eq(
		false !== strpos( $src, 'while ( count( $ids ) === 1000' ),
		true,
		basename( $rel ) . ' — pagination continues on rows READ, not rows deleted'
	);
}

// --- 2. Seeding happens once, and never re-decides for the administrator ----
$act = (string) file_get_contents( $root . '/includes/class-activator.php' );
ret_eq(
	false !== strpos( $act, "get_option( 'faz_default_whitelist_seeded' )" ),
	true,
	'seed_default_whitelist() is gated by a one-time marker, like its siblings'
);
ret_eq(
	false !== strpos( $act, "add_option( 'faz_default_whitelist_seeded'" ),
	true,
	'and records the marker, so a later MIGRATIONS_VERSION bump cannot re-run it'
);

// --- 3. It must not grant more than the shipped defaults --------------------
// The old hardcoded list is the assertion's real subject: whitelisting is
// permission, so a back-fill that is broader than the product's own default
// quietly weakens every site it touches.
foreach ( array( 'fonts.googleapis.com/', 'cdn.jsdelivr.net/', 'unpkg.com/', 'maps.googleapis.com/maps/api/' ) as $over_permissive ) {
	ret_eq(
		false === strpos( $act, "'" . $over_permissive . "'" ),
		true,
		"the seeder no longer grants {$over_permissive}"
	);
}
ret_eq(
	false !== strpos( $act, "\$shipped['script_blocking']['whitelist_patterns']" ),
	true,
	'it reads the shipped defaults instead of restating a list that can drift'
);

// And the shipped defaults themselves still exclude what that comment promises.
$settings_src = (string) file_get_contents( $root . '/admin/modules/settings/includes/class-settings.php' );
if ( preg_match( "#'whitelist_patterns'\s*=>\s*array\((.*?)\),#s", $settings_src, $m ) ) {
	ret_eq( false === strpos( $m[1], 'fonts.googleapis.com' ), true, 'the shipped default whitelist still excludes Google Fonts' );
	ret_eq( false === strpos( $m[1], 'jsdelivr' ) && false === strpos( $m[1], 'unpkg' ), true, 'and still excludes the public CDNs' );
} else {
	ret_eq( false, true, 'the shipped default whitelist could be read' );
}

echo "\n" . ( $tests_run - $failed ) . "/{$tests_run} passed\n";
exit( 0 === $failed ? 0 : 1 );
