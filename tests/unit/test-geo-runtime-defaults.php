<?php
/**
 * Standalone unit tests for the runtime geo-routing helpers on
 * FazCookie\Frontend\Includes\Geo_Runtime:
 *
 *   - category_default()  (pure: ruleset state -> bool|null)
 *   - model_to_law()      (ruleset model -> binary law gdpr|ccpa)
 *   - default_consent()   (ruleset state -> { gdpr, ccpa })
 *   - apply_cmv2_to_gcm()  (ruleset CMv2 -> GCM default_settings canonical keys)
 *
 * These back the flag-gated `faz_geo_ruleset_runtime` feature: when the resolved
 * ruleset names a category, its default_categories state wins for BOTH laws
 * (necessary always granted) so the live banner reflects the visitor's
 * jurisdiction; its model decides the enforcement law; and its CMv2 block drives
 * Google Consent Mode defaults.
 *
 * Run from project root:
 *   php tests/unit/test-geo-runtime-defaults.php
 *
 * Exit code 0 = all pass; 1 = at least one failure. Not a PHPUnit suite —
 * mirrors the lightweight CLI runner pattern of test-ruleset-resolver.php. The
 * helpers are pure public statics, so no WordPress runtime/DB/reflection is
 * needed; apply_filters() is stubbed for the (unused here) is_enabled() path.
 *
 * @package FazCookie\Tests\Unit
 */

// ---------- Bootstrap ----------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { // phpcs:ignore
		return $value;
	}
}

require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-geo-runtime.php';

use FazCookie\Frontend\Includes\Geo_Runtime;

// ---------- Minimal assert helpers ----------

$tests_run    = 0;
$tests_passed = 0;
$tests_failed = 0;

function assert_eq( $actual, $expected, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $actual === $expected ) {
		$tests_passed++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
	} else {
		$tests_failed++;
		echo "  \033[31m✗\033[0m " . $label . "\n";
		echo "      expected: " . var_export( $expected, true ) . "\n";
		echo "      actual:   " . var_export( $actual, true ) . "\n";
	}
}

$rs = function ( $cats ) {
	return array( 'ui' => array( 'default_categories' => $cats ) );
};

// ---------- category_default() state mapping ----------

echo "\n\033[1mGeo_Runtime::category_default() — state → bool|null\033[0m\n";

assert_eq( Geo_Runtime::category_default( $rs( array( 'analytics' => 'granted' ) ), 'analytics' ), true, "'granted' → true" );
assert_eq( Geo_Runtime::category_default( $rs( array( 'necessary' => 'granted-locked' ) ), 'necessary' ), true, "'granted-locked' → true" );
assert_eq( Geo_Runtime::category_default( $rs( array( 'marketing' => 'denied' ) ), 'marketing' ), false, "'denied' → false" );
assert_eq( Geo_Runtime::category_default( $rs( array( 'marketing' => 'denied-until-action' ) ), 'marketing' ), false, "'denied-until-action' → false" );
assert_eq( Geo_Runtime::category_default( $rs( array( 'analytics' => 'granted' ) ), 'marketing' ), null, 'slug absent → null' );
assert_eq( Geo_Runtime::category_default( $rs( array( 'analytics' => 'weird' ) ), 'analytics' ), null, 'unknown state → null (fail safe)' );
assert_eq( Geo_Runtime::category_default( array(), 'analytics' ), null, 'no ui.default_categories → null' );

// ---------- model_to_law() ----------

echo "\n\033[1mGeo_Runtime::model_to_law() — model → binary law\033[0m\n";

assert_eq( Geo_Runtime::model_to_law( array( 'model' => 'opt-in' ) ), 'gdpr', 'opt-in → gdpr' );
assert_eq( Geo_Runtime::model_to_law( array( 'model' => 'hybrid' ) ), 'gdpr', 'hybrid → gdpr' );
assert_eq( Geo_Runtime::model_to_law( array( 'model' => 'opt-out-with-sensitive-opt-in' ) ), 'gdpr', 'opt-out-with-sensitive-opt-in → gdpr' );
assert_eq( Geo_Runtime::model_to_law( array( 'model' => 'opt-out' ) ), 'ccpa', 'opt-out → ccpa' );
assert_eq( Geo_Runtime::model_to_law( array() ), 'gdpr', 'missing model → gdpr (most protective)' );

// ---------- real law25-quebec.json ----------

echo "\n\033[1mreal ruleset: law25-quebec.json\033[0m\n";

$quebec_path = dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/rulesets/law25-quebec.json';
$quebec      = json_decode( (string) file_get_contents( $quebec_path ), true );
assert_eq( is_array( $quebec ), true, 'law25-quebec.json loads as array' );
assert_eq( Geo_Runtime::model_to_law( $quebec ), 'gdpr', 'Quebec model (hybrid) → gdpr enforcement' );
assert_eq( Geo_Runtime::category_default( $quebec, 'necessary' ), true, 'Quebec necessary → true' );
assert_eq( Geo_Runtime::category_default( $quebec, 'functional' ), true, 'Quebec functional → true' );
assert_eq( Geo_Runtime::category_default( $quebec, 'analytics' ), false, 'Quebec analytics → false' );
assert_eq( Geo_Runtime::category_default( $quebec, 'marketing' ), false, 'Quebec marketing → false' );
assert_eq( Geo_Runtime::category_default( $quebec, 'profiling' ), false, 'Quebec profiling → false' );

// ---------- catalogue invariant: known states across all rulesets ----------

echo "\n\033[1mcatalogue invariant: default_categories states ∈ known vocabulary\033[0m\n";

$known_states = array( 'granted', 'granted-locked', 'denied', 'denied-until-action' );
$rulesets_dir = dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/rulesets';
$files        = glob( $rulesets_dir . '/*.json' );
$bad          = array();
foreach ( $files as $file ) {
	$data = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $data ) || ! isset( $data['ui']['default_categories'] ) || ! is_array( $data['ui']['default_categories'] ) ) {
		continue;
	}
	foreach ( $data['ui']['default_categories'] as $slug => $state ) {
		if ( ! in_array( $state, $known_states, true ) ) {
			$bad[] = basename( $file ) . ":{$slug}={$state}";
		}
	}
}
assert_eq( $bad, array(), 'all ' . count( $files ) . ' rulesets use only known states' );

// ---------- default_consent() — catalogue baseline (no ruleset) ----------

echo "\n\033[1mGeo_Runtime::default_consent() — catalogue baseline (ruleset = null)\033[0m\n";

assert_eq( Geo_Runtime::default_consent( null, 'necessary', true, false, false ), array( 'gdpr' => true, 'ccpa' => true ), 'necessary → gdpr=prior, ccpa=true' );
assert_eq( Geo_Runtime::default_consent( null, 'marketing', false, true, true ), array( 'gdpr' => false, 'ccpa' => false ), 'marketing sold/shared → gdpr=false, ccpa=false' );
assert_eq( Geo_Runtime::default_consent( null, 'functional', true, false, false ), array( 'gdpr' => true, 'ccpa' => true ), 'functional not sold/shared → ccpa=true' );
assert_eq( Geo_Runtime::default_consent( null, 'analytics', false, false, true ), array( 'gdpr' => false, 'ccpa' => false ), 'analytics shared-only → ccpa=false' );

// ---------- default_consent() — runtime ruleset wins both laws ----------

echo "\n\033[1mGeo_Runtime::default_consent() — runtime ruleset overrides BOTH laws\033[0m\n";

assert_eq( Geo_Runtime::default_consent( $quebec, 'analytics', true, false, false ), array( 'gdpr' => false, 'ccpa' => false ), 'Quebec analytics → gdpr=false AND ccpa=false (ruleset wins, not sold/shared)' );
assert_eq( Geo_Runtime::default_consent( $quebec, 'functional', false, true, true ), array( 'gdpr' => true, 'ccpa' => true ), 'Quebec functional → gdpr=true AND ccpa=true (ruleset wins, even though sold/shared)' );
assert_eq( Geo_Runtime::default_consent( $quebec, 'necessary', false, false, false ), array( 'gdpr' => true, 'ccpa' => true ), 'Quebec necessary → always granted' );
assert_eq( Geo_Runtime::default_consent( $quebec, 'marketing', true, false, false ), array( 'gdpr' => false, 'ccpa' => false ), 'Quebec marketing → gdpr=false AND ccpa=false' );
assert_eq( Geo_Runtime::default_consent( $quebec, 'social', true, false, false ), array( 'gdpr' => true, 'ccpa' => true ), 'ruleset-unnamed slug → base logic' );

// ---------- apply_cmv2_to_gcm() — canonical keys reflect CMv2 ----------

echo "\n\033[1mGeo_Runtime::apply_cmv2_to_gcm() — CMv2 → GCM canonical keys\033[0m\n";

// A GCM settings array with one default_settings row carrying both canonical
// and category-mirror keys (mirrors the live shape).
$gcm = array(
	'default_settings' => array(
		array(
			'ad_storage'              => 'granted',
			'analytics_storage'       => 'granted',
			'ad_user_data'            => 'granted',
			'ad_personalization'      => 'granted',
			'functionality_storage'   => 'granted',
			'personalization_storage' => 'granted',
			'security_storage'        => 'granted',
			'marketing'               => 'granted',
			'analytics'               => 'granted',
			'functional'              => 'granted',
			'necessary'               => 'granted',
			'regions'                 => 'All',
		),
	),
);

$out      = Geo_Runtime::apply_cmv2_to_gcm( $quebec, $gcm );
$row      = $out['default_settings'][0];
$cmv2     = $quebec['signals']['cmv2'];
// Quebec CMv2: ad/analytics/ad_user_data/ad_personalization = denied-until-action;
// personalization/functionality/security = granted.
assert_eq( $row['ad_storage'], 'denied', 'Quebec → ad_storage denied' );
assert_eq( $row['analytics_storage'], 'denied', 'Quebec → analytics_storage denied' );
assert_eq( $row['ad_user_data'], 'denied', 'Quebec → ad_user_data denied' );
assert_eq( $row['ad_personalization'], 'denied', 'Quebec → ad_personalization denied' );
assert_eq( $row['personalization_storage'], 'granted', 'Quebec → personalization_storage granted (independent of functionality)' );
assert_eq( $row['functionality_storage'], 'granted', 'Quebec → functionality_storage granted' );
assert_eq( $row['security_storage'], 'granted', 'Quebec → security_storage granted' );
// Mirror keys NOT named by CMv2 are left untouched (gcm.js falls back to them).
assert_eq( $row['regions'], 'All', 'non-signal keys untouched' );

// Null ruleset → unchanged.
assert_eq( Geo_Runtime::apply_cmv2_to_gcm( null, $gcm ), $gcm, 'null ruleset → GCM unchanged' );

// ---------- Summary ----------

echo "\n";
echo "\033[1mRan {$tests_run} assertions: ";
echo "\033[32m{$tests_passed} passed\033[0m";
if ( $tests_failed > 0 ) {
	echo ", \033[31m{$tests_failed} failed\033[0m";
}
echo "\033[0m\n";

exit( $tests_failed > 0 ? 1 : 0 );
