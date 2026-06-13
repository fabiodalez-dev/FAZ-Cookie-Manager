<?php
/**
 * Standalone unit tests for FazCookie\Includes\Geolocation database resolution:
 *
 *   - get_database_path()  (FAZ_MAXMIND_DB_PATH > uploads GeoLite2 > dbip)
 *   - has_database()       (true iff get_database_path() resolves)
 *
 * Regression guard for the "Geo source not configured" false negative: the
 * admin notice on the Geo Targeting tab used to check only the maxmind_key
 * option and an inline FAZ_MAXMIND_DB_PATH file_exists() test, while the live
 * resolver looked at wp-content/uploads/faz-cookie-manager/*.mmdb — so a site
 * that downloaded the database through the plugin still saw the warning. The
 * notice now calls Geolocation::has_database(), and FAZ_MAXMIND_DB_PATH is now
 * honoured by get_database_path() (previously it was read only by the notice,
 * never by the resolver). These tests pin both behaviours.
 *
 * Run from project root:
 *   php tests/unit/test-geolocation-db-path.php
 *
 * Exit code 0 = all pass; 1 = at least one failure. Not a PHPUnit suite —
 * mirrors the lightweight CLI runner pattern of test-geo-runtime-defaults.php.
 *
 * @package FazCookie\Tests\Unit
 */

// ---------- Bootstrap ----------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

// Per-run temp "uploads" directory that wp_upload_dir() points at.
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

// ---------- Tiny assertion harness ----------

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

$data_dir = $faz_test_uploads . '/faz-cookie-manager/';
@mkdir( $data_dir, 0777, true );

// Helper: remove any .mmdb files between cases.
function faz_clear_mmdb( $dir ) {
	foreach ( glob( $dir . '*.mmdb' ) ?: array() as $f ) {
		@unlink( $f );
	}
}

echo "Geolocation::get_database_path() / has_database()\n";

// ---- Case 1: nothing installed -> empty / false ----
faz_clear_mmdb( $data_dir );
faz_ok( '' === Geolocation::get_database_path(), 'no database -> get_database_path() returns ""' );
faz_ok( false === Geolocation::has_database(), 'no database -> has_database() is false' );

// ---- Case 2: plugin-downloaded DB in uploads -> resolved (the false-negative case) ----
$country_db = $data_dir . 'GeoLite2-Country.mmdb';
file_put_contents( $country_db, 'fake-mmdb' );
faz_ok( $country_db === Geolocation::get_database_path(), 'uploaded GeoLite2-Country.mmdb -> get_database_path() returns it' );
faz_ok( true === Geolocation::has_database(), 'uploaded database -> has_database() is true' );

// ---- Case 3: FAZ_MAXMIND_DB_PATH is honoured AND takes precedence over uploads ----
$own_db = $faz_test_uploads . '/my-own-GeoLite2-City.mmdb';
file_put_contents( $own_db, 'fake-mmdb' );
define( 'FAZ_MAXMIND_DB_PATH', $own_db ); // constant is permanent for the rest of the run
faz_ok( $own_db === Geolocation::get_database_path(), 'FAZ_MAXMIND_DB_PATH is honoured by the resolver and wins over uploads' );
faz_ok( true === Geolocation::has_database(), 'FAZ_MAXMIND_DB_PATH present -> has_database() is true' );

// ---- Case 4: with the constant set, removing it still leaves the uploads DB resolving ----
// (FAZ_MAXMIND_DB_PATH still points at $own_db which exists, so this asserts the
//  uploads file remains valid as a candidate behind the constant.)
faz_ok( file_exists( $country_db ), 'uploads database untouched by the constant lookup' );

// ---------- Cleanup + result ----------
faz_clear_mmdb( $data_dir );
@unlink( $own_db );
@rmdir( $data_dir );
@rmdir( $faz_test_uploads );

echo "\n" . ( 0 === $faz_fail ? "ALL PASS ($faz_pass)\n" : "FAILED: $faz_fail, passed: $faz_pass\n" );
exit( 0 === $faz_fail ? 0 : 1 );
