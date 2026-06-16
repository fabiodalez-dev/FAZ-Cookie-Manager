<?php
/**
 * Standalone edge-case unit tests for the geo-php subsystem.
 *
 * Covers three independent pure/static units that drive geo-routing:
 *
 *   1. FazCookie\Includes\Geolocation
 *        - is_valid_mmdb() (exercised through get_database_path()):
 *          valid marker in the 128 KB tail, file too small, missing file,
 *          wrong/absent marker.
 *        - get_database_path() candidate precedence: FAZ_MAXMIND_DB_PATH first,
 *          then the configured + alternate uploads editions, then the dbip
 *          fallback, skipping invalid candidates along the way.
 *        - has_database() agreement with get_database_path().
 *   2. FazCookie\Admin\Modules\Geo_Routing\Includes\Ruleset_Resolver::resolve()
 *        - CA-QC  -> law25-quebec   (generic sub-national, stage 3.5)
 *        - CA     -> pipeda-canada  (country index, stage 5)
 *        - US-CA  -> ccpa-california (US region, stage 4)
 *        - unknown country with no mapping -> fallback.
 *   3. faz_ip_in_cidr_list() (includes/class-utils.php):
 *        IPv4 CIDR, IPv6 CIDR, bare IP, boundary-bit prefixes, family
 *        mismatch, malformed entries, /0 and /32, oversized prefix.
 *
 * FAZ_MAXMIND_DB_PATH is a process-permanent constant, so all cases that must
 * run WITHOUT it execute first; the constant is defined once near the end and
 * the final cases vary only its target file.
 *
 * Run from project root:
 *   php tests/unit/test-geo-php.php
 *   bash scripts/run-unit-tests.sh
 *
 * Exit code 0 = all pass; 1 = at least one failure. Lightweight CLI runner,
 * mirrors test-geolocation-db-path.php / test-ruleset-resolver.php.
 *
 * @package FazCookie\Tests\Unit
 */

// ---------- Bootstrap (mirrors test-geolocation-db-path.php) ----------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$faz_test_uploads = sys_get_temp_dir() . '/faz-geo-php-test-' . getmypid();
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
require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-ruleset-resolver.php';

use FazCookie\Includes\Geolocation;
use FazCookie\Admin\Modules\Geo_Routing\Includes\Ruleset_Resolver;

// ---------- Assertion harness ----------

$faz_pass = 0;
$faz_fail = 0;
function faz_assert( $cond, $label ) {
	global $faz_pass, $faz_fail;
	if ( $cond ) {
		++$faz_pass;
		echo "  [PASS] $label\n";
	} else {
		++$faz_fail;
		echo "  [FAIL] $label\n";
	}
}
function faz_eq( $actual, $expected, $label ) {
	global $faz_pass, $faz_fail;
	if ( $actual === $expected ) {
		++$faz_pass;
		echo "  [PASS] $label\n";
	} else {
		++$faz_fail;
		echo "  [FAIL] $label  (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
	}
}

// ---------- Geolocation fixtures ----------

const FAZ_GEOPHP_MARKER = "\xab\xcd\xefMaxMind.com";

$data_dir = $faz_test_uploads . '/faz-cookie-manager/';
@mkdir( $data_dir, 0777, true );

function faz_geophp_clear( $dir ) {
	foreach ( glob( $dir . '*.mmdb' ) ?: array() as $f ) {
		@unlink( $f );
	}
}
function faz_geophp_stub( $path ) {
	// Padding + marker in the tail (a minimal "valid" MMDB).
	file_put_contents( $path, str_repeat( "\x00", 32 ) . FAZ_GEOPHP_MARKER . str_repeat( "\x00", 8 ) );
}

$country = $data_dir . 'GeoLite2-Country.mmdb'; // preferred edition (no Settings loaded)
$city    = $data_dir . 'GeoLite2-City.mmdb';    // alternate edition
$dbip    = $data_dir . 'dbip-country-lite.mmdb'; // last fallback

echo "== geo-php edge-case tests ==\n\n";

echo "-- Ruleset_Resolver::resolve() --\n";

// _index.json-shaped fixtures (subset).
$rr_countries = array(
	'IT' => 'gdpr-strict',
	'US' => 'us-router',
	'CA' => 'pipeda-canada',
);
$rr_regions = array(
	'US-CA' => 'ccpa-california',
);
$rr_subnational = array(
	'CA-QC' => 'law25-quebec',
);
$rr_fallback = 'fallback-gdpr-most-protective';

// CA-QC -> law25-quebec via generic sub-national stage 3.5 (region trumps country).
faz_eq(
	Ruleset_Resolver::resolve( 'CA', 'CA-QC', false, array(), $rr_countries, $rr_regions, $rr_fallback, null, $rr_subnational ),
	'law25-quebec',
	'R01 CA-QC -> law25-quebec (sub-national region trumps PIPEDA country default)'
);

// CA without a recognised region -> federal PIPEDA from country index (stage 5).
faz_eq(
	Ruleset_Resolver::resolve( 'CA', null, false, array(), $rr_countries, $rr_regions, $rr_fallback, null, $rr_subnational ),
	'pipeda-canada',
	'R02 CA + no region -> pipeda-canada (country index)'
);

// A CA region that is NOT in the sub-national map must still fall back to the
// country default, not leak through — guards the isset() in stage 3.5.
faz_eq(
	Ruleset_Resolver::resolve( 'CA', 'CA-ON', false, array(), $rr_countries, $rr_regions, $rr_fallback, null, $rr_subnational ),
	'pipeda-canada',
	'R03 CA-ON (Ontario, no sub-national ruleset) -> pipeda-canada country default'
);

// US-CA -> ccpa-california via dedicated US region stage 4.
faz_eq(
	Ruleset_Resolver::resolve( 'US', 'US-CA', false, array(), $rr_countries, $rr_regions, $rr_fallback, null, $rr_subnational ),
	'ccpa-california',
	'R04 US-CA -> ccpa-california (US region stage)'
);

// Unknown country with no index entry and no region -> hard fallback.
faz_eq(
	Ruleset_Resolver::resolve( 'ZZ', null, false, array(), $rr_countries, $rr_regions, $rr_fallback, null, $rr_subnational ),
	$rr_fallback,
	'R05 unknown country ZZ (not in index) -> fallback'
);

// Even a US-* key placed in the generic sub-national map must NOT bypass the US
// no-law policy: stage 3.5 explicitly excludes US. US-WY has no US-region entry
// so it must resolve to gdpr-strict (Q2 policy), never to the planted value.
$rr_sub_poison = $rr_subnational;
$rr_sub_poison['US-WY'] = 'planted-should-not-win';
faz_eq(
	Ruleset_Resolver::resolve( 'US', 'US-WY', false, array(), $rr_countries, $rr_regions, $rr_fallback, null, $rr_sub_poison ),
	'gdpr-strict',
	'R06 US-WY in sub-national map is ignored (US excluded from stage 3.5) -> gdpr-strict'
);

echo "\n-- faz_ip_in_cidr_list() --\n";

// IPv4 CIDR membership and non-membership.
faz_eq( faz_ip_in_cidr_list( '10.1.2.3', array( '10.0.0.0/8' ) ), true, 'C01 IPv4 inside /8' );
faz_eq( faz_ip_in_cidr_list( '11.0.0.1', array( '10.0.0.0/8' ) ), false, 'C02 IPv4 outside /8' );

// Boundary-bit prefix: /23 spans .0/.1 in the third octet; .2.x is outside.
faz_eq( faz_ip_in_cidr_list( '192.168.1.255', array( '192.168.0.0/23' ) ), true, 'C03 IPv4 /23 boundary: .1.x inside' );
faz_eq( faz_ip_in_cidr_list( '192.168.2.0', array( '192.168.0.0/23' ) ), false, 'C04 IPv4 /23 boundary: .2.0 just outside' );

// /32 acts as an exact host match.
faz_eq( faz_ip_in_cidr_list( '203.0.113.7', array( '203.0.113.7/32' ) ), true, 'C05 IPv4 /32 exact host match' );
faz_eq( faz_ip_in_cidr_list( '203.0.113.8', array( '203.0.113.7/32' ) ), false, 'C06 IPv4 /32 off-by-one no match' );

// /0 matches any IPv4 (full-byte loop never runs, bits_rem == 0 returns true).
faz_eq( faz_ip_in_cidr_list( '8.8.8.8', array( '0.0.0.0/0' ) ), true, 'C07 IPv4 /0 matches everything' );

// Bare IP (no /prefix) matches only itself.
faz_eq( faz_ip_in_cidr_list( '198.51.100.4', array( '198.51.100.4' ) ), true, 'C08 bare IPv4 exact self-match' );
faz_eq( faz_ip_in_cidr_list( '198.51.100.5', array( '198.51.100.4' ) ), false, 'C09 bare IPv4 different host no match' );

// IPv6 CIDR membership (Cloudflare-style /32) and family isolation.
faz_eq( faz_ip_in_cidr_list( '2400:cb00:1234::1', array( '2400:cb00::/32' ) ), true, 'C10 IPv6 inside /32' );
faz_eq( faz_ip_in_cidr_list( '2a00:1450::1', array( '2400:cb00::/32' ) ), false, 'C11 IPv6 outside /32' );

// IPv6 bare-IP exact match (normalised forms must compare equal via inet_pton).
faz_eq( faz_ip_in_cidr_list( '2001:db8::1', array( '2001:0db8:0000::1' ) ), true, 'C12 bare IPv6 exact match across notations' );

// Address-family mismatch: IPv4 IP vs IPv6 subnet (and vice versa) never match.
faz_eq( faz_ip_in_cidr_list( '10.0.0.1', array( '2400:cb00::/32' ) ), false, 'C13 IPv4 IP vs IPv6 subnet -> no match (family)' );
faz_eq( faz_ip_in_cidr_list( '2400:cb00::1', array( '10.0.0.0/8' ) ), false, 'C14 IPv6 IP vs IPv4 subnet -> no match (family)' );

// Malformed / empty entries are skipped; a later valid entry still matches.
faz_eq( faz_ip_in_cidr_list( '10.0.0.1', array( '', 'garbage', '999.1.1.1/8', '10.0.0.0/8' ) ), true, 'C15 malformed entries skipped, valid CIDR still matches' );

// Oversized prefix (> 32 for IPv4) is rejected, not silently clamped.
faz_eq( faz_ip_in_cidr_list( '10.0.0.1', array( '10.0.0.0/40' ) ), false, 'C16 IPv4 prefix > 32 rejected' );

// Invalid input IP -> false regardless of list.
faz_eq( faz_ip_in_cidr_list( 'not-an-ip', array( '0.0.0.0/0' ) ), false, 'C17 invalid input IP -> false' );

// Empty list -> false.
faz_eq( faz_ip_in_cidr_list( '10.0.0.1', array() ), false, 'C18 empty list -> false' );

echo "\n-- Geolocation::get_database_path() / has_database() (FAZ_MAXMIND_DB_PATH undefined) --\n";

// No database at all.
faz_geophp_clear( $data_dir );
faz_eq( Geolocation::get_database_path(), '', 'G01 no database -> path ""' );
faz_eq( Geolocation::has_database(), false, 'G02 no database -> has_database() false' );

// Valid Country edition (marker in tail) resolves.
faz_geophp_clear( $data_dir );
faz_geophp_stub( $country );
faz_eq( Geolocation::get_database_path(), $country, 'G03 valid Country.mmdb -> resolved (marker in tail)' );
faz_eq( Geolocation::has_database(), true, 'G04 valid Country.mmdb -> has_database() true' );

// Preferred (Country) wins over alternate (City) when both valid.
faz_geophp_clear( $data_dir );
faz_geophp_stub( $country );
faz_geophp_stub( $city );
faz_eq( Geolocation::get_database_path(), $country, 'G05 Country + City both valid -> preferred Country wins' );

// Corrupt preferred + valid alternate -> alternate resolved (invalid skipped).
faz_geophp_clear( $data_dir );
file_put_contents( $country, 'this-is-not-an-mmdb' ); // wrong marker
faz_geophp_stub( $city );
faz_eq( Geolocation::get_database_path(), $city, 'G06 corrupt Country (wrong marker) skipped -> City resolved' );

// Two corrupt editions + valid dbip fallback -> dbip resolved.
faz_geophp_clear( $data_dir );
file_put_contents( $country, 'nope' );
file_put_contents( $city, 'nope' );
faz_geophp_stub( $dbip );
faz_eq( Geolocation::get_database_path(), $dbip, 'G07 two corrupt editions + valid dbip -> dbip fallback' );

// File too small (shorter than the marker) is rejected.
faz_geophp_clear( $data_dir );
file_put_contents( $country, 'abc' ); // 3 bytes < 14-byte marker
faz_eq( Geolocation::get_database_path(), '', 'G08 sub-marker-length file -> rejected (too small)' );

// Zero-byte file is rejected.
faz_geophp_clear( $data_dir );
file_put_contents( $country, '' );
faz_eq( Geolocation::get_database_path(), '', 'G09 empty (0-byte) file -> rejected' );

// Large (>128 KB) file with marker only at the HEAD (outside tail) -> rejected.
faz_geophp_clear( $data_dir );
file_put_contents( $country, FAZ_GEOPHP_MARKER . str_repeat( "\x00", 200 * 1024 ) );
faz_eq( Geolocation::get_database_path(), '', 'G10 marker only at head (outside 128 KB tail) -> rejected' );

// Large file with marker at EOF -> resolved via the tail read.
faz_geophp_clear( $data_dir );
file_put_contents( $country, str_repeat( "\x00", 200 * 1024 ) . FAZ_GEOPHP_MARKER );
faz_eq( Geolocation::get_database_path(), $country, 'G11 large file, marker at EOF -> resolved (tail read)' );

// has_database() always agrees with get_database_path() emptiness.
faz_geophp_clear( $data_dir );
faz_geophp_stub( $dbip );
faz_assert(
	Geolocation::has_database() === ( '' !== Geolocation::get_database_path() ),
	'G12 has_database() consistent with get_database_path()'
);

echo "\n-- Geolocation with FAZ_MAXMIND_DB_PATH defined (operator override) --\n";

// Define the constant once (permanent for the process). Point it at a valid
// file OUTSIDE the uploads dir; it must win over a valid uploads database.
$own = $faz_test_uploads . '/operator-db.mmdb';
faz_geophp_clear( $data_dir );
faz_geophp_stub( $country ); // valid uploads DB to fall back to
faz_geophp_stub( $own );      // valid operator DB
define( 'FAZ_MAXMIND_DB_PATH', $own );

faz_eq( Geolocation::get_database_path(), $own, 'G13 valid FAZ_MAXMIND_DB_PATH wins over uploads' );
faz_eq( Geolocation::has_database(), true, 'G14 valid constant -> has_database() true' );

// Corrupt constant file -> skipped, resolver falls through to valid uploads.
file_put_contents( $own, 'corrupt-no-marker' );
faz_eq( Geolocation::get_database_path(), $country, 'G15 corrupt constant skipped -> falls through to uploads' );

// Missing constant target AND no uploads DB -> nothing valid.
@unlink( $own );
faz_geophp_clear( $data_dir );
faz_eq( Geolocation::get_database_path(), '', 'G16 missing constant file + no uploads -> path ""' );

// ---------- Cleanup + result ----------

faz_geophp_clear( $data_dir );
@unlink( $own );
@rmdir( $data_dir );
@rmdir( $faz_test_uploads );

echo "\n" . ( 0 === $faz_fail ? "ALL PASS ($faz_pass)\n" : "FAILED: $faz_fail, passed: $faz_pass\n" );
exit( 0 === $faz_fail ? 0 : 1 );
