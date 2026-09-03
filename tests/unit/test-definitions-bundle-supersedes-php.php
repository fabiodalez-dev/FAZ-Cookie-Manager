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
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
define( 'FAZ_PLUGIN_BASEPATH', dirname( __DIR__, 2 ) . '/' );

$GLOBALS['OPTS'] = array();
function get_option( $k, $d = false ) {
	if ( 'timezone_string' === $k ) { return $GLOBALS['FAZ_TZ_NAME'] ?? ''; }
	if ( 'gmt_offset' === $k ) { return $GLOBALS['FAZ_TZ_OFFSET'] ?? 0; }
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

$scenario = function ( $downloaded_at, $downloaded_gmt = null ) use ( $seed_bundled_cache ) {
	$GLOBALS['OPTS'] = array();
	$seed_bundled_cache();
	// A non-empty stored dataset, shaped like the real one.
	$GLOBALS['OPTS']['faz_cookie_definitions'] = array( 'example.com' => array( array( 'cookie' => '_x', 'category' => 'Analytics' ) ) );
	$meta = array( 'count' => 1, 'source' => Cookie_Definitions::SOURCE_URL );
	if ( null !== $downloaded_at ) {
		$meta['updated_at'] = $downloaded_at;
	}
	if ( null !== $downloaded_gmt ) {
		$meta['updated_at_gmt'] = $downloaded_gmt;
	}
	$GLOBALS['OPTS']['faz_cookie_definitions_meta'] = $meta;
	$defs = new Cookie_Definitions();
	// BOTH answers, not just the reported one. get_meta() and get_runtime_data()
	// each consult bundle_supersedes_stored() separately, so asserting only the
	// metadata leaves every case below green even if the lookup went on serving
	// the stale dataset — the report would say "bundled" while the categories
	// came from the copy it claims not to be using. That divergence is the
	// original defect's shape, one layer down.
	$ref = new ReflectionMethod( $defs, 'get_runtime_data' );
	$ref->setAccessible( true );
	$runtime = $ref->invoke( $defs );
	return array(
		'meta'    => $defs->get_meta(),
		'runtime' => is_array( $runtime ) && isset( $runtime['example.com'] ) ? 'stored' : 'bundled',
	);
};

echo "\nbundled snapshot captured: $bundle_date\n\n";

// 1. The reported case: a download older than the shipped bundle. Before this
//    change the stored copy won and kept winning, however many newer bundles
//    arrived. The user whose list had been stuck since July is this row.
$old = $scenario( '2026-07-01 10:00:00' );
t( 'bundled' === $old['meta']['source'], 'a download OLDER than the bundle no longer wins' );
t( 'bundled' === $old['runtime'], 'and the LOOKUP uses the bundle too, not only the report' );
t( $bundle_date === $old['meta']['updated_at'], 'and the reported date is the bundle\'s, not the stale one' );

// 2. The other direction must still hold, or the fix would throw away genuinely
//    fresher data — the failure mode of "just always prefer the bundle".
$new = $scenario( gmdate( 'Y-m-d H:i:s', strtotime( $bundle_date ) + 86400 * 30 ) );
t( Cookie_Definitions::SOURCE_URL === $new['meta']['source'], 'a download NEWER than the bundle still wins' );
t( 'stored' === $new['runtime'], 'and the lookup keeps using the newer stored dataset' );

// 3. Legacy copies predate the versions that stamp a date, so they are old.
$undated = $scenario( null );
t( 'bundled' === $undated['meta']['source'], 'an undated legacy copy is treated as older' );

// 4. A garbage date must not be read as "year zero beats everything" nor crash.
$broken = $scenario( 'not a date' );
t( 'bundled' === $broken['meta']['source'], 'an unparseable date falls back to the bundle' );

// 5. No stored copy at all — the pre-existing path, which must be untouched.
$GLOBALS['OPTS'] = array();
$seed_bundled_cache();
$fresh = ( new Cookie_Definitions() )->get_meta();
t( 'bundled' === $fresh['source'], 'a fresh install still reads the bundle' );


// --- Il fuso non deve decidere il vincitore -------------------------------
// update_definitions() timbra in ora LOCALE del sito, BUNDLED_DATA_DATE e' UTC.
// I due casi qui sotto sono scelti perche' l'offset RIBALTA il verdetto: il
// delta reale (1 ora) e' piu' piccolo dell'offset e di segno opposto, quindi
// senza la normalizzazione il confronto sbaglia. Una prima versione di questi
// test usava le direzioni opposte e passava anche col difetto presente — se ne
// e' accorta solo la prova di mutazione.
$bundle_ts = strtotime( $bundle_date . ' UTC' );

// Sito a UTC+13, download fatto un'ora PRIMA del bundle: la sua stringa locale
// sembra 12 ore nel futuro, quindi senza correzione vincerebbe a torto.
$GLOBALS['FAZ_TZ_OFFSET'] = 13;
$r = $scenario( gmdate( 'Y-m-d H:i:s', $bundle_ts - 3600 + 13 * 3600 ) );
t( 'bundled' === $r['meta']['source'], 'UTC+13: a download older than the bundle does not win by timezone' );

// Sito a UTC-11, download fatto un'ora DOPO: sembra 10 ore piu' vecchio, quindi
// senza correzione perderebbe a torto.
$GLOBALS['FAZ_TZ_OFFSET'] = -11;
$r = $scenario( gmdate( 'Y-m-d H:i:s', $bundle_ts + 3600 - 11 * 3600 ) );
t( Cookie_Definitions::SOURCE_URL === $r['meta']['source'], 'UTC-11: a download newer than the bundle is not discarded by timezone' );
$GLOBALS['FAZ_TZ_OFFSET'] = 0;

// --- updated_at_gmt: nessun offset da indovinare ------------------------
// Il caso che il ramo legacy non puo' risolvere: scritto in ora legale, letto
// in ora solare. Con il solo stamp locale l'offset applicato in lettura non e'
// quello in vigore alla scrittura, e a distanza ravvicinata puo' ribaltare il
// verdetto. Con updated_at_gmt il confronto e' esatto per costruzione.
$GLOBALS['FAZ_TZ_NAME'] = 'Europe/Rome';
$bundle_ts = strtotime( $bundle_date . ' UTC' );
// Download un'ora DOPO il bundle, timbrato in UTC.
$r = $scenario( 'irrilevante', gmdate( 'Y-m-d H:i:s', $bundle_ts + 3600 ) );
t( Cookie_Definitions::SOURCE_URL === $r['meta']['source'], 'a UTC-stamped newer download wins regardless of site timezone' );
t( 'stored' === $r['runtime'], 'and the lookup uses it' );
// Download un'ora PRIMA, sempre in UTC.
$r = $scenario( 'irrilevante', gmdate( 'Y-m-d H:i:s', $bundle_ts - 3600 ) );
t( 'bundled' === $r['meta']['source'], 'a UTC-stamped older download still loses' );
$GLOBALS['FAZ_TZ_NAME'] = '';

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
