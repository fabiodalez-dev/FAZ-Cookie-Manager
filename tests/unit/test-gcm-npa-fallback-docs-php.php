<?php
/**
 * Standalone unit test: every description of the non-personalized-ads
 * fallback must say what the runtime does — ad_storage stays denied.
 *
 * Subsystem: gcm-npa-fallback-docs
 *
 * The fallback was introduced in 1.11.0 as "ad_storage = granted, ad_user_data
 * / ad_personalization = denied" and reversed in the 1.17.2 compliance pass:
 * granting ad_storage writes ad cookies without consent, which is unlawful in
 * the EEA/UK/CH, so gcm.js (setNpaIfDenied / buildConsentState) and the inline
 * Advanced-mode default keep ad_storage denied and only emit the legacy `npa`
 * signal (1 while ad_storage is denied, 0 once granted). The admin help text
 * (admin/views/gcm.php) says so. Three comments still described the removed
 * behaviour and read as a specification to anyone touching the fallback:
 *
 *   - the Gcm_Settings defaults comment above `non_personalized_ads_fallback`;
 *   - Geo_Runtime::apply_cmv2_to_gcm(), which justified reading the category
 *     mirrors with "the fallback keeps `marketing` and canonical `ad_storage`
 *     out of sync" — they have been in sync since buildConsentState() returns
 *     ad_storage: marketing;
 *   - the user-reports-regression.spec.ts header, whose test body (R2)
 *     asserts the opposite of what the header says.
 *
 * The runtime is pinned elsewhere (R2 in that spec, test-gcm-advanced-php.php,
 * test-gcm-npa.mjs). This test pins the documentation so the stale wording
 * cannot come back and send a maintainer the wrong way.
 *
 * Run: php tests/unit/test-gcm-npa-fallback-docs-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

$root = dirname( __DIR__, 2 );

$tests_run    = 0;
$tests_passed = 0;
$tests_failed = 0;

function ok( $cond, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $cond ) {
		$tests_passed++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
	} else {
		$tests_failed++;
		echo "  \033[31m✗\033[0m " . $label . "\n";
	}
}

/** The comment block right above a given line of source (contiguous `//` or docblock lines). */
function faz_comment_above( $src, $needle ) {
	$pos = strpos( $src, $needle );
	if ( false === $pos ) {
		return '';
	}
	$before = substr( $src, max( 0, $pos - 1500 ), min( 1500, $pos ) );
	$lines  = explode( "\n", $before );
	array_pop( $lines ); // the needle's own (partial) line
	$block = array();
	for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
		$t = trim( $lines[ $i ] );
		if ( '' === $t || ( 0 !== strpos( $t, '//' ) && 0 !== strpos( $t, '*' ) && 0 !== strpos( $t, '/*' ) ) ) {
			break;
		}
		array_unshift( $block, $t );
	}
	return implode( "\n", $block );
}

echo "non-personalized-ads fallback — documentation matches the runtime\n";

// 1. Gcm_Settings defaults.
$settings_src = file_get_contents( $root . '/admin/modules/gcm/includes/class-gcm-settings.php' );
$comment      = faz_comment_above( $settings_src, "'non_personalized_ads_fallback' =>" );
ok( '' !== $comment, 'Gcm_Settings documents the non_personalized_ads_fallback default' );
ok( 1 !== preg_match( '/ad_storage\s*=\s*granted/i', $comment ), 'Gcm_Settings no longer describes the fallback as ad_storage = granted' );
ok( 1 === preg_match( '/ad_storage[^\n]*(stays?|remains?|kept)[^\n]*denied/i', $comment ), 'Gcm_Settings says ad_storage stays denied under the fallback' );
ok( false !== stripos( $comment, 'npa' ), 'Gcm_Settings names the npa signal the fallback actually emits' );

// 2. Geo_Runtime::apply_cmv2_to_gcm() docblock.
$geo_src = file_get_contents( $root . '/frontend/includes/class-geo-runtime.php' );
ok( false === stripos( $geo_src, 'out of sync' ), 'Geo_Runtime no longer claims the fallback keeps marketing and ad_storage out of sync' );
// Read from the docblock itself: any line of the file naming ad_storage and
// denied, or saying "in sync", satisfied the check against the whole source.
$geo_doc = faz_comment_above( $geo_src, 'public static function apply_cmv2_to_gcm' );
ok( '' !== $geo_doc, 'Geo_Runtime::apply_cmv2_to_gcm() has a docblock' );
ok( 1 === preg_match( '/ad_storage[^\n]*denied/i', $geo_doc ) && 1 === preg_match( '/in sync/i', $geo_doc ), 'Geo_Runtime::apply_cmv2_to_gcm() describes the mirrors as the keys gcm.js reads, with ad_storage following marketing' );

// 3. E2E spec header.
$spec_src = file_get_contents( $root . '/tests/e2e/specs/user-reports-regression.spec.ts' );
$header   = substr( $spec_src, 0, strpos( $spec_src, 'import {' ) );
ok( 1 !== preg_match( '/ad_storage\s*=\s*granted/i', $header ), 'the user-reports spec header no longer says ad_storage=granted' );
ok( 1 === preg_match( '/ad_storage[^\n]*denied/i', $header ) && false !== stripos( $header, 'npa' ), 'the spec header describes the shipped behaviour its R2 test asserts (ad_storage denied, npa=1)' );

// 4. The runtime these comments describe is still the denied one (guards the
//    guard: if gcm.js ever flips, this file must be rewritten, not the code).
$gcm_js = file_get_contents( $root . '/frontend/js/gcm.js' );
ok( false !== strpos( $gcm_js, 'ad_storage: marketing' ), 'gcm.js still derives ad_storage from the marketing decision' );
ok( false !== strpos( $gcm_js, 'npa: adStorage === "denied" ? 1 : 0' ), 'gcm.js still emits npa from the ad_storage state' );

echo "\n";
if ( 0 === $tests_failed ) {
	echo "\033[32mALL PASS\033[0m — {$tests_passed}/{$tests_run}\n";
	exit( 0 );
}
echo "\033[31m{$tests_failed} FAILED\033[0m — {$tests_passed}/{$tests_run} passed\n";
exit( 1 );
