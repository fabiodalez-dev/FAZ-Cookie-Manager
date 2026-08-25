<?php
/**
 * Keeps the UI-gated geo-ruleset runtime and its operator-facing disclosures
 * aligned. Live jurisdiction enforcement and optional ipinfo lookups must be
 * described accurately wherever an administrator configures them.
 *
 * Run: php tests/unit/test-geo-runtime-disclosure-php.php
 *
 * @package FazCookie\Tests\Unit
 */

define( 'ABSPATH', __DIR__ . '/' );

/**
 * @param string $tag   Filter name.
 * @param mixed  $value Value being filtered.
 * @return mixed
 */
function apply_filters( $tag, $value ) {
	return 'faz_geo_ruleset_runtime' === $tag && null !== $GLOBALS['faz_geo_runtime_override']
		? $GLOBALS['faz_geo_runtime_override']
		: $value;
}

function get_option( $name, $default = false ) {
	return 'faz_settings' === $name ? $GLOBALS['faz_geo_runtime_settings'] : $default;
}

$GLOBALS['faz_geo_runtime_settings'] = array( 'geolocation' => array( 'geo_targeting' => true ) );
$GLOBALS['faz_geo_runtime_override'] = null;
$root    = dirname( __DIR__, 2 );
require_once $root . '/frontend/includes/class-geo-runtime.php';
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
geo_disclosure_check( true === \FazCookie\Frontend\Includes\Geo_Runtime::is_enabled(), 'Geo-Targeting enables the geo ruleset runtime' );
$GLOBALS['faz_geo_runtime_settings']['geolocation']['geo_targeting'] = false;
geo_disclosure_check( false === \FazCookie\Frontend\Includes\Geo_Runtime::is_enabled(), 'turning Geo-Targeting off disables runtime enforcement' );
$GLOBALS['faz_geo_runtime_settings']['geolocation']['geo_targeting'] = true;
$GLOBALS['faz_geo_runtime_override'] = false;
geo_disclosure_check( false === \FazCookie\Frontend\Includes\Geo_Runtime::is_enabled(), 'the emergency runtime filter can still disable enforcement' );
geo_disclosure_check( false !== strpos( $view, 'maps to the rule-set enforced by the live banner' ), 'admin catalogue labels its live enforcement role' );
geo_disclosure_check( false !== strpos( $view, 'Overrides affect both the live banner and this preview' ), 'admin overrides disclose their live effect' );
geo_disclosure_check( false !== strpos( $view, 'visitor IP addresses may be sent to ipinfo.io during banner rendering' ), 'ipinfo settings disclose optional live visitor lookups' );
geo_disclosure_check( false !== strpos( $readme, 'either a visitor-facing jurisdiction lookup or an admin preview' ), 'public disclosure matches the live ipinfo behaviour' );

echo "Passed: {$passed}; Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
