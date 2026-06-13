<?php
/**
 * Standalone unit tests for FazCookie\Includes\Geolocation database resolution.
 *
 * Covers get_database_path() / has_database() and, through them, the private
 * is_valid_mmdb() candidate check:
 *
 *   - candidate ordering: FAZ_MAXMIND_DB_PATH > configured edition
 *     (GeoLite2-Country here, since no Settings class is loaded) > the other
 *     edition > the DB-IP lite fallback;
 *   - the false-negative fix: a database the plugin downloaded into
 *     wp-content/uploads/faz-cookie-manager/ is resolved even without a saved
 *     license key or the FAZ_MAXMIND_DB_PATH constant;
 *   - the F-C fix: a candidate that exists but is corrupt / not an MMDB file
 *     (no MaxMind metadata marker in its 128 KB tail) is skipped, so the
 *     resolver falls through to the next valid database instead of handing a
 *     broken file to Mmdb_Reader (which would throw and resolve every visitor
 *     to "unknown" with no fallback);
 *   - FAZ_MAXMIND_DB_PATH precedence, and the same validity guard applied to it.
 *
 * 25 assertions. PHP constants are permanent for the process, so every
 * FAZ_MAXMIND_DB_PATH-independent case runs first (cases 1–19); the constant is
 * defined once (case 20) and the remaining cases vary only its target file.
 *
 * Run from project root:
 *   php tests/unit/test-geolocation-db-path.php
 *
 * Exit code 0 = all pass; 1 = at least one failure. Lightweight CLI runner,
 * not a PHPUnit suite — mirrors test-geo-runtime-defaults.php.
 *
 * @package FazCookie\Tests\Unit
 */

// ---------- Bootstrap ----------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$faz_test_uploads = sys_get_temp_dir() . '/faz-geo-test-' . getmypid();
@mkdir( $faz_test_uploads, 0777, true );

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() { // phpcs:ignore
		global $faz_test_uploads;
		return array( 'basedir' => $faz_test_uploads );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) { // phpcs:ignore
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { // phpcs:ignore
		return $value;
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/class-geolocation.php';

use FazCookie\Includes\Geolocation;

// ---------- Assertion harness ----------

$faz_pass = 0;
$faz_fail = 0;
function faz_ok( $cond, $label ) {
	global $faz_pass, $faz_fail;
	if ( $cond ) {
		++$faz_pass;
		echo "  [PASS] $label\n";
	} else {
		++$faz_fail;
		echo "  [FAIL] $label\n";
	}
}

// ---------- Fixtures ----------

$data_dir = $faz_test_uploads . '/faz-cookie-manager/';
@mkdir( $data_dir, 0777, true );

const FAZ_TEST_MARKER = "\xab\xcd\xefMaxMind.com";

/** Remove every .mmdb file in the uploads data dir between cases. */
function faz_clear_mmdb( $dir ) {
	foreach ( glob( $dir . '*.mmdb' ) ?: array() as $f ) {
		@unlink( $f );
	}
}

/** Minimal valid MMDB stub: padding + the MaxMind metadata marker near EOF. */
function faz_write_mmdb_stub( $path ) {
	file_put_contents( $path, str_repeat( "\x00", 32 ) . FAZ_TEST_MARKER . str_repeat( "\x00", 8 ) );
}

/** A non-MMDB file (no marker) — must be rejected as corrupt. */
function faz_write_corrupt( $path ) {
	file_put_contents( $path, 'this-is-not-an-mmdb-database-file' );
}

/**
 * A large (~200 KB) file. $where='end' puts the marker at EOF (found by the
 * 128 KB tail read); $where='head' puts it at the very start only (NOT in the
 * tail, so it must be rejected — documents the tail-read scope).
 */
function faz_write_large( $path, $where = 'end' ) {
	$pad = str_repeat( "\x00", 200 * 1024 );
	file_put_contents( $path, 'head' === $where ? FAZ_TEST_MARKER . $pad : $pad . FAZ_TEST_MARKER );
}

echo "Geolocation::get_database_path() / has_database()  (25 assertions)\n";

$country = $data_dir . 'GeoLite2-Country.mmdb';
$city    = $data_dir . 'GeoLite2-City.mmdb';
$dbip    = $data_dir . 'dbip-country-lite.mmdb';

// === Part A — FAZ_MAXMIND_DB_PATH undefined (uploads-only resolution) =========

// 1–2: nothing installed.
faz_clear_mmdb( $data_dir );
faz_ok( '' === Geolocation::get_database_path(), '01 no database -> path is ""' );
faz_ok( false === Geolocation::has_database(), '02 no database -> has_database() false' );

// 3–4: a valid downloaded GeoLite2-Country.mmdb (the false-negative case).
faz_clear_mmdb( $data_dir );
faz_write_mmdb_stub( $country );
faz_ok( $country === Geolocation::get_database_path(), '03 valid Country.mmdb -> resolved' );
faz_ok( true === Geolocation::has_database(), '04 valid Country.mmdb -> has_database() true' );

// 5: only the City edition present -> alternate fallback.
faz_clear_mmdb( $data_dir );
faz_write_mmdb_stub( $city );
faz_ok( $city === Geolocation::get_database_path(), '05 only City.mmdb -> alternate edition resolved' );

// 6: both editions -> the configured (Country) edition wins.
faz_clear_mmdb( $data_dir );
faz_write_mmdb_stub( $country );
faz_write_mmdb_stub( $city );
faz_ok( $country === Geolocation::get_database_path(), '06 Country + City -> preferred edition wins' );

// 7: corrupt Country + valid City -> corrupt one skipped, City resolved.
faz_clear_mmdb( $data_dir );
faz_write_corrupt( $country );
faz_write_mmdb_stub( $city );
faz_ok( $city === Geolocation::get_database_path(), '07 corrupt Country + valid City -> City resolved' );

// 8: corrupt Country + corrupt City + valid dbip -> dbip resolved.
faz_clear_mmdb( $data_dir );
faz_write_corrupt( $country );
faz_write_corrupt( $city );
faz_write_mmdb_stub( $dbip );
faz_ok( $dbip === Geolocation::get_database_path(), '08 two corrupt editions + valid dbip -> dbip resolved' );

// 9: only the DB-IP lite fallback present.
faz_clear_mmdb( $data_dir );
faz_write_mmdb_stub( $dbip );
faz_ok( $dbip === Geolocation::get_database_path(), '09 only dbip-country-lite.mmdb -> resolved' );

// 10–11: a single corrupt candidate -> nothing valid.
faz_clear_mmdb( $data_dir );
faz_write_corrupt( $country );
faz_ok( '' === Geolocation::get_database_path(), '10 lone corrupt Country -> path "" (skipped)' );
faz_ok( false === Geolocation::has_database(), '11 lone corrupt Country -> has_database() false' );

// 12: an empty (0-byte) file is rejected.
faz_clear_mmdb( $data_dir );
file_put_contents( $country, '' );
faz_ok( '' === Geolocation::get_database_path(), '12 empty Country.mmdb -> rejected' );

// 13: a file shorter than the marker is rejected.
faz_clear_mmdb( $data_dir );
file_put_contents( $country, 'abc' );
faz_ok( '' === Geolocation::get_database_path(), '13 sub-marker-length file -> rejected' );

// 14: a large file with the marker at EOF is found via the 128 KB tail read.
faz_clear_mmdb( $data_dir );
faz_write_large( $country, 'end' );
faz_ok( $country === Geolocation::get_database_path(), '14 large file, marker at EOF -> resolved (tail read)' );

// 15: a large file with the marker only at the head (outside the last 128 KB)
//     is rejected — documents the tail-read scope (a real MMDB ends with it).
faz_clear_mmdb( $data_dir );
faz_write_large( $country, 'head' );
faz_ok( '' === Geolocation::get_database_path(), '15 large file, marker only at head -> rejected (tail scope)' );

// 16: marker followed by trailing metadata bytes (still inside the tail) -> valid.
faz_clear_mmdb( $data_dir );
file_put_contents( $country, str_repeat( "\x00", 100 ) . FAZ_TEST_MARKER . str_repeat( "\x01", 1024 ) );
faz_ok( $country === Geolocation::get_database_path(), '16 marker + trailing data in tail -> resolved' );

// 17: an unrelated .mmdb name is not a candidate.
faz_clear_mmdb( $data_dir );
faz_write_mmdb_stub( $data_dir . 'random-other.mmdb' );
faz_ok( '' === Geolocation::get_database_path(), '17 unrelated .mmdb filename -> not a candidate' );

// 18: a valid Country alongside an unrelated file -> Country resolved.
faz_clear_mmdb( $data_dir );
faz_write_mmdb_stub( $country );
faz_write_mmdb_stub( $data_dir . 'random-other.mmdb' );
faz_ok( $country === Geolocation::get_database_path(), '18 valid Country + unrelated file -> Country resolved' );

// 19: has_database() agrees with get_database_path() on a resolved state.
faz_ok(
	Geolocation::has_database() === ( '' !== Geolocation::get_database_path() ),
	'19 has_database() consistent with get_database_path()'
);

// === Part B — FAZ_MAXMIND_DB_PATH defined (operator override) ================

$own = $faz_test_uploads . '/operator-GeoLite2-City.mmdb'; // outside $data_dir
faz_clear_mmdb( $data_dir );
faz_write_mmdb_stub( $country ); // a valid uploads DB to fall back to
faz_write_mmdb_stub( $own );
define( 'FAZ_MAXMIND_DB_PATH', $own ); // permanent for the rest of the run

// 20–21: valid constant wins over a valid uploads database.
faz_ok( $own === Geolocation::get_database_path(), '20 valid FAZ_MAXMIND_DB_PATH wins over uploads' );
faz_ok( true === Geolocation::has_database(), '21 valid constant -> has_database() true' );

// 22: corrupt constant is skipped; resolver falls through to valid uploads (F-C).
faz_write_corrupt( $own );
faz_ok( $country === Geolocation::get_database_path(), '22 corrupt constant -> falls through to uploads' );
faz_ok( true === Geolocation::has_database(), '23 corrupt constant + valid uploads -> has_database() true' );

// 24: empty constant file is skipped; uploads used.
file_put_contents( $own, '' );
faz_ok( $country === Geolocation::get_database_path(), '24 empty constant file -> falls through to uploads' );

// 25: missing constant file AND no uploads DB -> nothing valid.
@unlink( $own );
faz_clear_mmdb( $data_dir );
faz_ok( '' === Geolocation::get_database_path(), '25 missing constant + no uploads -> path ""' );

// ---------- Cleanup + result ----------
faz_clear_mmdb( $data_dir );
@unlink( $own );
@rmdir( $data_dir );
@rmdir( $faz_test_uploads );

echo "\n" . ( 0 === $faz_fail ? "ALL PASS ($faz_pass)\n" : "FAILED: $faz_fail, passed: $faz_pass\n" );
exit( 0 === $faz_fail ? 0 : 1 );
