<?php
/**
 * Standalone unit tests for Ruleset_Resolver pure function.
 *
 * Spec: specs/001-geo-routing-next/spec.md
 * Task: T010 (P1 Foundation)
 *
 * Run from project root:
 *   php tests/unit/test-ruleset-resolver.php
 *
 * Exit code 0 = all tests pass; 1 = at least one failure.
 *
 * Not a PHPUnit suite (the plugin uses Playwright/JS for compliance,
 * not PHPUnit). This is a lightweight CLI runner that validates the
 * pure-function semantics of Ruleset_Resolver in isolation, without WP
 * runtime or DB. Pattern mirrors compliance-tests.mjs in the parent
 * project directory.
 *
 * @package FazCookie\Tests\Unit
 */

// ---------- Bootstrap ----------

// Stub WP constants needed by the resolver's source file.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-ruleset-resolver.php';

use FazCookie\Admin\Modules\Geo_Routing\Includes\Ruleset_Resolver;

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

// ---------- Fixtures ----------

// Mock _index.json contents (subset).
$index_countries = array(
	'IT' => 'gdpr-strict',
	'FR' => 'gdpr-strict',
	'DE' => 'gdpr-strict',
	'GB' => 'gdpr-strict',
	'US' => 'us-router',
	'BR' => 'gdpr-strict',
	'CN' => 'gdpr-strict',
	'JP' => 'gdpr-strict',
	'IS' => 'gdpr-strict',
	'LI' => 'gdpr-strict',
	'NO' => 'gdpr-strict',
	'CA' => 'pipeda-canada',
);

$index_regions = array(
	'US-CA' => 'ccpa-california',
	'US-CO' => 'ccpa-california',
	'US-NY' => 'ccpa-california',
);

// Generic non-US sub-national region map (Ruleset_Loader::load_regions()).
$index_subnational = array(
	'CA-QC' => 'law25-quebec',
);

$no_overrides = array();
$fallback     = 'fallback-gdpr-most-protective';

// ---------- Tests ----------

echo "\n== Ruleset_Resolver — pure-function tests (T010) ==\n\n";

// 1. EU countries → gdpr-strict
assert_eq(
	Ruleset_Resolver::resolve( 'IT', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'IT visitor without VPN → gdpr-strict'
);
assert_eq(
	Ruleset_Resolver::resolve( 'FR', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'FR visitor without VPN → gdpr-strict'
);

// 2. EEA non-EU (Q1 resolution 2026-05-20): alias gdpr-strict
assert_eq(
	Ruleset_Resolver::resolve( 'IS', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'IS (Iceland EEA) → alias gdpr-strict [Q1]'
);
assert_eq(
	Ruleset_Resolver::resolve( 'LI', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'LI (Liechtenstein EEA) → alias gdpr-strict [Q1]'
);
assert_eq(
	Ruleset_Resolver::resolve( 'NO', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'NO (Norway EEA) → alias gdpr-strict [Q1]'
);

// 3. UK → gdpr-strict (covered by gdpr-strict ruleset until uk-gdpr-pecr lands in P4)
assert_eq(
	Ruleset_Resolver::resolve( 'GB', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'GB visitor → gdpr-strict (uk-gdpr-pecr lands P4)'
);

// 4. US + California → ccpa-california
assert_eq(
	Ruleset_Resolver::resolve( 'US', 'US-CA', false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'ccpa-california',
	'US + CA region → ccpa-california'
);

// 5. US + no-law state (Q2 resolution 2026-05-19): gdpr-strict
assert_eq(
	Ruleset_Resolver::resolve( 'US', 'US-WY', false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'US + Wyoming (no privacy law) → gdpr-strict [Q2]'
);

// 6. US + no region → gdpr-strict (most-protective when state unknown)
assert_eq(
	Ruleset_Resolver::resolve( 'US', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'US + no region → gdpr-strict (most-protective unknown state)'
);

// 7. VPN/proxy detected (Q7 resolution): forced fallback regardless of country
assert_eq(
	Ruleset_Resolver::resolve( 'US', 'US-CA', true, $no_overrides, $index_countries, $index_regions, $fallback ),
	$fallback,
	'VPN detected on US+CA → forced fallback (most-protective) [Q7]'
);
assert_eq(
	Ruleset_Resolver::resolve( 'IT', null, true, $no_overrides, $index_countries, $index_regions, $fallback ),
	$fallback,
	'VPN detected on IT → forced fallback (most-protective) [Q7]'
);

// 7b. Permissive VPN gate cast — non-bool truthy values must still trigger gate
assert_eq(
	Ruleset_Resolver::resolve( 'US', 'US-CA', 1, $no_overrides, $index_countries, $index_regions, $fallback ),
	$fallback,
	'VPN=1 (int truthy) → fallback (cast guards CLI/REST consumers)'
);
assert_eq(
	Ruleset_Resolver::resolve( 'IT', null, '1', $no_overrides, $index_countries, $index_regions, $fallback ),
	$fallback,
	"VPN='1' (string truthy) → fallback"
);
assert_eq(
	Ruleset_Resolver::resolve( 'IT', null, 0, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'VPN=0 (int falsy) → normal resolution'
);
assert_eq(
	Ruleset_Resolver::resolve( 'IT', null, null, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'VPN=null → normal resolution (null cast to false)'
);

// 7c. resolve_us_no_law decoupling — Q2 ruleset must not depend on _index.json
// sentinel. Even if the index changes the US mapping to anything else (or
// removes it entirely), an unknown-state US visitor still gets gdpr-strict.
$index_alt_us = $index_countries;
$index_alt_us['US'] = 'us-fallback'; // hypothetical future catalog
assert_eq(
	Ruleset_Resolver::resolve( 'US', 'US-WY', false, $no_overrides, $index_alt_us, $index_regions, $fallback ),
	'gdpr-strict',
	'US no-law state still → gdpr-strict when US sentinel changes (decoupling)'
);
$index_no_us = $index_countries;
unset( $index_no_us['US'] );
assert_eq(
	Ruleset_Resolver::resolve( 'US', null, false, $no_overrides, $index_no_us, $index_regions, $fallback ),
	'gdpr-strict',
	'US with no entry in index → still gdpr-strict (policy constant)'
);

// 8. XX (Cloudflare unknown) → fallback
assert_eq(
	Ruleset_Resolver::resolve( 'XX', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	$fallback,
	'XX country (CF unknown/anonymous) → fallback'
);

// 9. Empty / null country → fallback
assert_eq(
	Ruleset_Resolver::resolve( '', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	$fallback,
	'Empty country → fallback'
);
assert_eq(
	Ruleset_Resolver::resolve( null, null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	$fallback,
	'Null country → fallback'
);

// 10. Country not in map → fallback (LATAM ZA AU NZ etc — until P5 catalog lands)
assert_eq(
	Ruleset_Resolver::resolve( 'BR', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'BR (in map post-P1 limited catalog) → gdpr-strict'
);
assert_eq(
	Ruleset_Resolver::resolve( 'ZZ', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	$fallback,
	'ZZ (invalid country code) → fallback'
);

// 11. Admin override — explicit ruleset_id
$override_explicit = array(
	'IT' => array(
		'ruleset_id' => 'custom-gdpr-italy',
		'delta'      => array(),
	),
);
assert_eq(
	Ruleset_Resolver::resolve( 'IT', null, false, $override_explicit, $index_countries, $index_regions, $fallback ),
	'custom-gdpr-italy',
	'Admin override with explicit ruleset_id → use override [Q3]'
);

// 12. Admin override — delta only, no ruleset_id → fall through to auto-detect
$override_delta_only = array(
	'IT' => array(
		'ruleset_id' => null,
		'delta'      => array( 'ui.equal_weight_buttons' => false ),
	),
);
assert_eq(
	Ruleset_Resolver::resolve( 'IT', null, false, $override_delta_only, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'Admin override with delta only (no ruleset_id) → auto-detected gdpr-strict (delta applied by consumer)'
);

// 13. Lowercase country normalization
assert_eq(
	Ruleset_Resolver::resolve( 'it', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	"Lowercase 'it' → normalized to IT → gdpr-strict"
);

// 14. Lowercase region normalization
assert_eq(
	Ruleset_Resolver::resolve( 'US', 'us-ca', false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'ccpa-california',
	"Lowercase 'us-ca' region → normalized to US-CA → ccpa-california"
);

// 15. Invalid country format
assert_eq(
	Ruleset_Resolver::resolve( 'ITALY', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	$fallback,
	"Invalid country 'ITALY' (3 chars) → fallback"
);

// 16. Invalid region format
assert_eq(
	Ruleset_Resolver::resolve( 'US', 'US-CALIFORNIA', false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	"Invalid region 'US-CALIFORNIA' (>3 chars) → ignored, US no-region → gdpr-strict"
);

// ---------- Round-2 regression: 8th-arg whitelist + ruleset_id sentinel ----------

// 17. Override with whitelist passed AND override id is valid → use override.
$override_valid = array(
	'IT' => array( 'ruleset_id' => 'gdpr-italy', 'delta' => array() ),
);
$whitelist = array( 'gdpr-strict', 'ccpa-california', 'gdpr-italy', 'fallback-gdpr-most-protective' );
assert_eq(
	Ruleset_Resolver::resolve( 'IT', null, false, $override_valid, $index_countries, $index_regions, $fallback, $whitelist ),
	'gdpr-italy',
	'Override valid against whitelist → applied [L2-SP1-S006]'
);

// 18. Override with whitelist AND override id is INVALID → fall through.
$override_invalid = array(
	'IT' => array( 'ruleset_id' => 'gdpr-mars-2099', 'delta' => array() ),
);
assert_eq(
	Ruleset_Resolver::resolve( 'IT', null, false, $override_invalid, $index_countries, $index_regions, $fallback, $whitelist ),
	'gdpr-strict',
	'Override invalid against whitelist → fall through to auto-detect [L2-SP1-S006]'
);

// 19. Override with whitelist = null → no validation, override always applied (back-compat).
assert_eq(
	Ruleset_Resolver::resolve( 'IT', null, false, $override_invalid, $index_countries, $index_regions, $fallback, null ),
	'gdpr-mars-2099',
	'Whitelist=null disables validation (legacy callers) — override applied verbatim'
);

// 20. Override ruleset_id explicitly null in shape → falls through, delta still applicable.
$override_null_id = array(
	'IT' => array( 'ruleset_id' => null, 'delta' => array( 'banner.color' => '#000' ) ),
);
assert_eq(
	Ruleset_Resolver::resolve( 'IT', null, false, $override_null_id, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'Override with null ruleset_id + delta → fall through to auto-detect (consumer applies delta downstream)'
);

// ---------- Round-3: generic non-US sub-national region stage (9th arg) ----------
//
// Proves the path PRODUCTION takes (Geo_Routing passes load_regions() as the 9th
// arg): a CA visitor detected in Quebec (region CA-QC) must resolve to
// law25-quebec, NOT the CA country default (pipeda-canada). Without the region
// signal the visitor correctly falls back to the country map. This is the gap a
// direct-JSON-load test could never catch.

// 21. CA + CA-QC region → law25-quebec (region stage wins over country map).
assert_eq(
	Ruleset_Resolver::resolve( 'CA', 'CA-QC', false, $no_overrides, $index_countries, $index_regions, $fallback, null, $index_subnational ),
	'law25-quebec',
	'CA + CA-QC → law25-quebec (generic sub-national stage)'
);

// 22. CA with no region → country default pipeda-canada (no sub-national signal).
assert_eq(
	Ruleset_Resolver::resolve( 'CA', null, false, $no_overrides, $index_countries, $index_regions, $fallback, null, $index_subnational ),
	'pipeda-canada',
	'CA + no region → pipeda-canada (falls back to country map)'
);

// 23. CA + an unmapped region → country default (region stage only fires on a hit).
assert_eq(
	Ruleset_Resolver::resolve( 'CA', 'CA-ON', false, $no_overrides, $index_countries, $index_regions, $fallback, null, $index_subnational ),
	'pipeda-canada',
	'CA + CA-ON (not in sub-national map) → pipeda-canada'
);

// 24. Lowercase sub-national region normalises.
assert_eq(
	Ruleset_Resolver::resolve( 'ca', 'ca-qc', false, $no_overrides, $index_countries, $index_regions, $fallback, null, $index_subnational ),
	'law25-quebec',
	"Lowercase 'ca-qc' → normalized → law25-quebec"
);

// 25. US region resolution is unaffected by the new sub-national map (US keeps its
// dedicated stage, which carries the no-law-state policy).
assert_eq(
	Ruleset_Resolver::resolve( 'US', 'US-CA', false, $no_overrides, $index_countries, $index_regions, $fallback, null, $index_subnational ),
	'ccpa-california',
	'US + US-CA still → ccpa-california with sub-national map present (US stage intact)'
);

// 26. VPN still trumps the sub-national region stage.
assert_eq(
	Ruleset_Resolver::resolve( 'CA', 'CA-QC', true, $no_overrides, $index_countries, $index_regions, $fallback, null, $index_subnational ),
	$fallback,
	'CA + CA-QC + VPN → fallback (VPN gate trumps region stage)'
);

// ---------- Summary ----------

echo "\n--\n";
echo "Tests:  $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n\n";

if ( $tests_failed > 0 ) {
	echo "\033[31mFAIL\033[0m\n";
	exit( 1 );
}
echo "\033[32mPASS\033[0m\n";
exit( 0 );
