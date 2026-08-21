<?php
/**
 * Region-map single-source regression tests (issue #238).
 *
 * The plugin used to carry the region-key -> country-code table twice:
 * Frontend::is_country_in_regions() and AMP_Consent::country_in_regions(),
 * the second one documented as a "mirror" of the first. The mirror had
 * already drifted — 'za' => array( 'ZA' ) existed only on the Frontend side.
 * The drift was invisible in practice only because both lookups fall through
 * to a direct-country-code comparison, which happens to rescue a
 * single-country region token; the next MULTI-country region added to one
 * table and not the other would have produced a real behavioural split
 * between AMP pages and regular pages.
 *
 * The table now has one home, faz_region_map() in includes/class-utils.php,
 * and both methods delegate to faz_country_in_regions(). These tests defend
 * that property in two independent ways:
 *
 *   1. BEHAVIOURAL — drive both lookups over a country x region matrix that
 *      is DERIVED from faz_region_map() itself, and require the two to agree
 *      on every cell. Because the matrix is derived, adding a region or a
 *      country to the shared table automatically extends the matrix; nobody
 *      has to remember to extend this file. Any future divergence between the
 *      two call sites shows up as a disagreeing cell.
 *   2. STRUCTURAL — assert the codebase contains exactly ONE region-map
 *      literal, and that it lives in includes/class-utils.php. This catches
 *      the copy-paste BEFORE it diverges, and also catches the case the
 *      matrix cannot see: a reintroduced local table carrying an EXTRA
 *      region key that the shared map never had.
 *
 * A third assertion pins the one asymmetry that is deliberate: the
 * `faz_is_target_region` filter belongs to the Frontend call site only. AMP
 * pages have never exposed it, so it must not migrate into the shared helper.
 *
 * Run from project root:
 *   php tests/unit/test-region-map-single-source-php.php
 *   bash scripts/run-unit-tests.sh
 *
 * Exit code 0 = all pass; 1 = at least one failure.
 *
 * @package FazCookie\Tests\Unit
 */

$faz_root = dirname( __DIR__, 2 );

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { // phpcs:ignore
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { // phpcs:ignore
		return $value;
	}
}

require_once $faz_root . '/includes/class-utils.php';
require_once $faz_root . '/frontend/class-frontend.php';
require_once $faz_root . '/frontend/class-amp-consent.php';

// ---------- Assertion harness ----------

$faz_pass = 0;
$faz_fail = 0;

/**
 * Record one assertion.
 *
 * @param bool   $cond  Condition under test.
 * @param string $label Human-readable claim.
 * @return void
 */
function faz_rm_assert( $cond, $label ) {
	global $faz_pass, $faz_fail;
	if ( $cond ) {
		++$faz_pass;
		return; // Quiet on success — the matrix runs thousands of cells.
	}
	++$faz_fail;
	echo "  [FAIL] $label\n";
}

echo "== region-map single-source tests (issue #238) ==\n\n";

// ---------- Reflection handles on the two private lookups ----------

$faz_frontend_class = new ReflectionClass( 'FazCookie\Frontend\Frontend' );
$faz_frontend       = $faz_frontend_class->newInstanceWithoutConstructor();
$faz_frontend_lookup = $faz_frontend_class->getMethod( 'is_country_in_regions' );
$faz_frontend_lookup->setAccessible( true );

$faz_amp_class  = new ReflectionClass( 'FazCookie\Frontend\AMP_Consent' );
$faz_amp_lookup = $faz_amp_class->getMethod( 'country_in_regions' );
$faz_amp_lookup->setAccessible( true );

/**
 * Frontend answer for one (country, regions) pair.
 *
 * @param string $country Country code.
 * @param array  $regions Region tokens.
 * @return bool
 */
function faz_rm_frontend( $country, $regions ) {
	global $faz_frontend, $faz_frontend_lookup;
	return (bool) $faz_frontend_lookup->invoke( $faz_frontend, $country, $regions );
}

/**
 * AMP answer for one (country, regions) pair.
 *
 * @param string $country Country code.
 * @param array  $regions Region tokens.
 * @return bool
 */
function faz_rm_amp( $country, $regions ) {
	global $faz_amp_lookup;
	return (bool) $faz_amp_lookup->invoke( null, $country, $regions );
}

// ---------- 1. Behavioural matrix, derived from the shared table ----------

$faz_map = faz_region_map();

faz_rm_assert( is_array( $faz_map ) && ! empty( $faz_map ), 'faz_region_map() returns a non-empty table' );

// Every country the table knows about, plus outsiders that must match nothing
// except their own direct code.
$faz_countries = array();
foreach ( $faz_map as $faz_codes ) {
	foreach ( $faz_codes as $faz_code ) {
		$faz_countries[ $faz_code ] = true;
	}
}
$faz_countries = array_keys( $faz_countries );
$faz_countries = array_merge( $faz_countries, array( 'IN', 'CN', 'MX', 'RU', 'TR', 'NZ', 'ZZ', '' ) );

// Every region key the table knows about, in three casings (the lookup is
// documented as case-insensitive), plus direct country codes and junk.
$faz_region_tokens = array();
foreach ( array_keys( $faz_map ) as $faz_key ) {
	$faz_region_tokens[] = $faz_key;
	$faz_region_tokens[] = strtoupper( $faz_key );
	$faz_region_tokens[] = ucfirst( $faz_key );
}
$faz_region_tokens = array_merge(
	$faz_region_tokens,
	array( 'ZA', 'za', 'GB', 'gb', 'DE', 'de', 'NZ', 'XX', 'xx', '' )
);

// Single-token region sets, plus a few multi-token sets: a divergence that
// only shows up when several regions are configured at once still gets seen.
$faz_region_sets = array();
foreach ( $faz_region_tokens as $faz_token ) {
	$faz_region_sets[] = array( $faz_token );
}
$faz_region_sets[] = array( 'eu', 'uk' );          // the shipped default.
$faz_region_sets[] = array( 'eu', 'uk', 'za' );
$faz_region_sets[] = array( 'us', 'ca', 'br' );
$faz_region_sets[] = array( 'ZA' );
$faz_region_sets[] = array( 'xx', 'za' );
$faz_region_sets[] = array();                      // nothing configured.

$faz_cells = 0;
foreach ( $faz_countries as $faz_country ) {
	foreach ( $faz_region_sets as $faz_set ) {
		++$faz_cells;
		$faz_fe = faz_rm_frontend( $faz_country, $faz_set );
		$faz_am = faz_rm_amp( $faz_country, $faz_set );
		faz_rm_assert(
			$faz_fe === $faz_am,
			sprintf(
				'Frontend and AMP agree for country "%s" against regions [%s] (frontend=%s, amp=%s)',
				$faz_country,
				implode( ',', $faz_set ),
				var_export( $faz_fe, true ),
				var_export( $faz_am, true )
			)
		);
	}
}
echo "  matrix: {$faz_cells} country x region cells compared across both lookups\n";

// The matrix above proves AGREEMENT. These prove the agreed answer is the
// right one — two lookups that both lost 'za' would agree and be wrong.
foreach ( $faz_map as $faz_key => $faz_codes ) {
	foreach ( $faz_codes as $faz_code ) {
		faz_rm_assert(
			true === faz_rm_frontend( $faz_code, array( $faz_key ) ),
			"Frontend: {$faz_code} is inside region '{$faz_key}'"
		);
		faz_rm_assert(
			true === faz_rm_amp( $faz_code, array( $faz_key ) ),
			"AMP: {$faz_code} is inside region '{$faz_key}'"
		);
	}
}

// The 'za' bucket is the specific omission issue #238 reported. Named
// explicitly so a regression reads as itself in the output, not as an
// anonymous matrix cell.
faz_rm_assert( isset( $faz_map['za'] ), "the shared table has a 'za' bucket" );
faz_rm_assert( true === faz_rm_amp( 'ZA', array( 'za' ) ), "AMP matches a ZA visitor against region 'za'" );
faz_rm_assert( true === faz_rm_frontend( 'ZA', array( 'za' ) ), "Frontend matches a ZA visitor against region 'za'" );

// The 'eu' preset excludes GB by design (UK GDPR has its own 'uk' bucket).
// Both sides must hold that line, or a UK visitor gets one banner rule on
// AMP pages and another everywhere else.
faz_rm_assert( ! in_array( 'GB', $faz_map['eu'], true ), "the 'eu' preset deliberately excludes GB" );
faz_rm_assert( false === faz_rm_frontend( 'GB', array( 'eu' ) ), "Frontend: GB is not inside 'eu'" );
faz_rm_assert( false === faz_rm_amp( 'GB', array( 'eu' ) ), "AMP: GB is not inside 'eu'" );
faz_rm_assert( 30 === count( $faz_map['eu'] ), "the 'eu' preset is the 27 EU members plus IS/LI/NO" );

// The direct-country-code fallback: an unknown token is an ISO code, not an
// error. This is what made the 'za' omission survive unnoticed, so it is
// worth pinning rather than leaving implicit.
faz_rm_assert( true === faz_rm_amp( 'NZ', array( 'NZ' ) ), 'AMP: an unknown token matches as a direct country code' );
faz_rm_assert( true === faz_rm_frontend( 'NZ', array( 'nz' ) ), 'Frontend: the direct-code fallback is case-insensitive' );
faz_rm_assert( false === faz_rm_amp( 'NZ', array( 'xx' ) ), 'AMP: a non-matching token yields false' );
faz_rm_assert( false === faz_rm_frontend( 'NZ', array() ), 'Frontend: an empty region list yields false' );

// ---------- 2. Structural: exactly one region-map literal ----------

/**
 * Collect the plugin's PHP sources, excluding the test suite and vendored code.
 *
 * @param string $root Plugin root.
 * @return string[] Absolute file paths.
 */
function faz_rm_php_sources( $root ) {
	$files    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file ) {
		$path = $file->getPathname();
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		if ( preg_match( '#/(tests|node_modules|vendor|\.git|build|dist)/#', $path ) ) {
			continue;
		}
		$files[] = $path;
	}
	sort( $files );
	return $files;
}

// A region-map literal is a region KEY mapped straight to a list of ISO
// country codes: "'uk' => array( 'GB'" or "'eu' => array(\n 'AT'". A plain
// list of codes (Geolocation::$eu_countries, which is a separate concern and
// intentionally still includes GB) is not keyed this way and does not match.
$faz_literal_pattern = "/'(eu|uk|us|ca|br|au|jp|ch|za)'\s*=>\s*array\s*\(\s*'[A-Z]{2}'/";

$faz_literal_files = array();
foreach ( faz_rm_php_sources( $faz_root ) as $faz_file ) {
	$faz_src = (string) file_get_contents( $faz_file );
	$faz_hits = preg_match_all( $faz_literal_pattern, $faz_src );
	if ( $faz_hits > 0 ) {
		$faz_literal_files[ str_replace( $faz_root . '/', '', $faz_file ) ] = $faz_hits;
	}
}

faz_rm_assert(
	array( 'includes/class-utils.php' ) === array_keys( $faz_literal_files ),
	'the region table is written out in exactly one file (found: ' . ( $faz_literal_files ? implode( ', ', array_keys( $faz_literal_files ) ) : 'none' ) . ')'
);
faz_rm_assert(
	isset( $faz_literal_files['includes/class-utils.php'] )
		&& count( $faz_map ) === $faz_literal_files['includes/class-utils.php'],
	'includes/class-utils.php holds one entry per region key and no second table'
);

// Both call sites must actually delegate — a wrapper that stopped calling the
// shared helper would keep the structural check green while going its own way.
$faz_frontend_src = (string) file_get_contents( $faz_root . '/frontend/class-frontend.php' );
$faz_amp_src      = (string) file_get_contents( $faz_root . '/frontend/class-amp-consent.php' );

faz_rm_assert(
	false !== strpos( $faz_frontend_src, 'faz_country_in_regions(' ),
	'Frontend::is_country_in_regions() delegates to the shared helper'
);
faz_rm_assert(
	false !== strpos( $faz_amp_src, 'faz_country_in_regions(' ),
	'AMP_Consent::country_in_regions() delegates to the shared helper'
);
// The old docblock summary line, verbatim. Matched as the exact claim rather
// than as a loose keyword, so prose that DISCUSSES the retired mirror (as the
// current docblock does) is not mistaken for the claim itself.
faz_rm_assert(
	false === strpos( $faz_amp_src, 'Compact region-set lookup (mirror of' ),
	'the AMP docblock no longer summarises itself as a mirror'
);

// ---------- 3. The one deliberate asymmetry ----------

// `faz_is_target_region` is a Frontend-only extension point. Moving it into
// the shared helper would quietly hand third-party code a say over AMP pages
// that it never had, so the filter must stay out of class-utils.php.
$faz_utils_src = (string) file_get_contents( $faz_root . '/includes/class-utils.php' );
faz_rm_assert(
	false !== strpos( $faz_frontend_src, "apply_filters( 'faz_is_target_region'" ),
	'the faz_is_target_region filter still runs on the Frontend path'
);
faz_rm_assert(
	false === strpos( $faz_utils_src, "apply_filters( 'faz_is_target_region'" ),
	'the shared helper does not run the Frontend-only faz_is_target_region filter'
);
faz_rm_assert(
	false === strpos( $faz_amp_src, "apply_filters( 'faz_is_target_region'" ),
	'AMP pages still do not run faz_is_target_region'
);

// ---------- Summary ----------

$faz_total = $faz_pass + $faz_fail;
if ( $faz_fail > 0 ) {
	echo "\n{$faz_fail} of {$faz_total} region-map checks failed.\n";
	exit( 1 );
}
echo "\nALL PASS ({$faz_total} region-map checks passed)\n";
