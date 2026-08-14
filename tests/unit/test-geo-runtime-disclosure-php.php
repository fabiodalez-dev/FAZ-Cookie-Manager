<?php
/**
 * Keeps the intentionally disabled geo-ruleset runtime and its operator-facing
 * disclosures aligned. A reference/preview-only catalogue must never promise
 * live jurisdiction enforcement or live ipinfo lookups.
 *
 * Run: php tests/unit/test-geo-runtime-disclosure-php.php
 *
 * @package FazCookie\Tests\Unit
 */

$root    = dirname( __DIR__, 2 );
$runtime = (string) file_get_contents( $root . '/frontend/includes/class-geo-runtime.php' );
$view    = (string) file_get_contents( $root . '/admin/views/geo-routing.php' );
$readme  = (string) file_get_contents( $root . '/readme.txt' );
$passed  = 0;
$failed  = 0;

function geo_disclosure_check( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  [PASS] {$label}\n";
	} else {
		$failed++;
		echo "  [FAIL] {$label}\n";
	}
}

echo "== Geo runtime disclosures ==\n";
geo_disclosure_check( false !== strpos( $runtime, 'return false;' ), 'geo ruleset runtime remains hard-disabled' );
geo_disclosure_check( false !== strpos( $view, 'Runtime application to the live banner is disabled' ), 'admin catalogue labels its preview-only state' );
geo_disclosure_check( false !== strpos( $view, 'does not change live banner behaviour' ), 'admin preview does not imply live enforcement' );
geo_disclosure_check( false !== strpos( $view, 'does not send visitor IP addresses during banner rendering' ), 'ipinfo settings disclose that live visitor lookups are off' );
geo_disclosure_check( false !== strpos( $readme, 'not called for visitor-facing banner selection or consent enforcement' ), 'public disclosure matches the live ipinfo behaviour' );

echo "Passed: {$passed}; Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
