<?php
/**
 * A downloaded definitions copy used to shadow the bundled snapshot forever.
 *
 * get_runtime_data() returned the stored option whenever it was non-empty and
 * nothing ever revisited it — no version check, no comparison, no clearing on
 * upgrade. So pressing "Update definitions" once converted a site that refreshed
 * with every plugin update into one pinned to that instant permanently.
 *
 * Run: php tests/unit/test-definitions-bundle-supersedes-php.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'FAZ_PLUGIN_BASEPATH', dirname( __DIR__, 2 ) . '/' );

$GLOBALS['OPTS'] = array();
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $k ] : $d;
}
function update_option( $k, $v, $a = null ) {
	$GLOBALS['OPTS'][ $k ] = $v;
	return true;
}
function current_time( $t ) {
	return gmdate( 'Y-m-d H:i:s' );
}
function sanitize_text_field( $s ) {
	return $s;
}
function is_wp_error( $x ) {
	return false;
}

require_once dirname( __DIR__, 2 ) . '/includes/class-cookie-definitions.php';

use FazCookie\Includes\Cookie_Definitions;

$ok = 0;
$ko = 0;
function t( $c, $l ) {
	global $ok, $ko;
	if ( $c ) {
		++$ok;
		echo "  PASS $l\n";
	} else {
		++$ko;
		echo "  FAIL $l\n";
	}
}

$bundle_date = Cookie_Definitions::BUNDLED_DATA_DATE;

// Short-circuit get_bundled_meta()'s 2.5 MB decode by pre-seeding its cache
// with the fingerprint it will compute. The bundle's CONTENT is irrelevant to
// what is under test; only which dataset gets chosen is.
$file = FAZ_PLUGIN_BASEPATH . 'includes/data/open-cookie-database.json';
$seed_bundled_cache = function () use ( $file, $bundle_date ) {
	$GLOBALS['OPTS']['faz_cookie_definitions_bundled_meta'] = array(
		'fingerprint' => ( (int) filemtime( $file ) ) . ':' . ( (int) filesize( $file ) ) . ':' . $bundle_date,
		'meta'        => array(
			'count'      => 6754,
			'updated_at' => $bundle_date,
			'source'     => 'bundled',
		),
	);
};

$scenario = function ( $downloaded_at ) use ( $seed_bundled_cache ) {
	$GLOBALS['OPTS'] = array();
	$seed_bundled_cache();
	// A non-empty stored dataset, shaped like the real one.
	$GLOBALS['OPTS']['faz_cookie_definitions'] = array( 'example.com' => array( array( 'cookie' => '_x', 'category' => 'Analytics' ) ) );
	$meta = array( 'count' => 1, 'source' => Cookie_Definitions::SOURCE_URL );
	if ( null !== $downloaded_at ) {
		$meta['updated_at'] = $downloaded_at;
	}
	$GLOBALS['OPTS']['faz_cookie_definitions_meta'] = $meta;
	$defs = new Cookie_Definitions();
	return $defs->get_meta();
};

echo "\nbundled snapshot captured: $bundle_date\n\n";

// 1. The reported case: a download older than the shipped bundle. Before this
//    change the stored copy won and kept winning, however many newer bundles
//    arrived. The user whose list had been stuck since July is this row.
$old = $scenario( '2026-07-01 10:00:00' );
t( 'bundled' === $old['source'], 'a download OLDER than the bundle no longer wins' );
t( $bundle_date === $old['updated_at'], 'and the reported date is the bundle\'s, not the stale one' );

// 2. The other direction must still hold, or the fix would throw away genuinely
//    fresher data — the failure mode of "just always prefer the bundle".
$new = $scenario( gmdate( 'Y-m-d H:i:s', strtotime( $bundle_date ) + 86400 * 30 ) );
t( Cookie_Definitions::SOURCE_URL === $new['source'], 'a download NEWER than the bundle still wins' );

// 3. Legacy copies predate the versions that stamp a date, so they are old.
$undated = $scenario( null );
t( 'bundled' === $undated['source'], 'an undated legacy copy is treated as older' );

// 4. A garbage date must not be read as "year zero beats everything" nor crash.
$broken = $scenario( 'not a date' );
t( 'bundled' === $broken['source'], 'an unparseable date falls back to the bundle' );

// 5. No stored copy at all — the pre-existing path, which must be untouched.
$GLOBALS['OPTS'] = array();
$seed_bundled_cache();
$fresh = ( new Cookie_Definitions() )->get_meta();
t( 'bundled' === $fresh['source'], 'a fresh install still reads the bundle' );

// 6. TRIPWIRE. BUNDLED_DATA_DATE is hand-maintained, and a constant that can
//    silently drift from the file it describes is a future bug, not a fix. If
//    the snapshot is regenerated without bumping the date, this goes red.
$expected_sha = '0e3693aa951c0154933bb97d2636b86a';
$actual_sha   = md5_file( $file );
t(
	$expected_sha === $actual_sha,
	'the bundled JSON matches the hash this date was recorded for'
	. ( $expected_sha === $actual_sha ? '' : "\n       the snapshot changed: set BUNDLED_DATA_DATE to its new capture date,\n       then update \$expected_sha here to $actual_sha" )
);
t( false !== strtotime( $bundle_date ), 'BUNDLED_DATA_DATE parses as a date' );

echo "\ndefinitions bundle supersedes: $ok passed, $ko failed\n";
exit( $ko > 0 ? 1 : 0 );
