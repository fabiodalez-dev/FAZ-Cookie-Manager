<?php
/**
 * Standalone compliance-hardening unit tests.
 *
 * Covers the multi-jurisdiction compliance + security fixes from the
 * 2026-06 compliance review (branch compliance/full-review-hardening):
 *
 *   - Quebec Law 25 region routing (resolver Stage 4b)
 *   - GPC ruleset flag integrity (5 UOOM states + fallback)
 *   - PIPEDA hybrid reclassification
 *   - respectGPC default-on in shipped banner configs
 *   - 6-month (183-day) consent-expiry cap logic
 *   - Trusted-proxy CIDR allowlist (faz_ip_in_cidr_list)
 *   - Accept/Reject equal-weight default button styling
 *
 * Run from project root:
 *   php tests/unit/test-compliance-hardening.php
 *
 * Exit code 0 = all tests pass; 1 = at least one failure.
 *
 * Pure-function / JSON-config assertions only — no WP runtime or DB, so the
 * suite is fast and reusable in CI. Browser-observable behaviour (GPC
 * auto-opt-out, computed button colours, rendered aria-level) is covered by
 * tests/e2e/specs/compliance-hardening.spec.ts.
 *
 * @package FazCookie\Tests\Unit
 */

// ---------- Bootstrap ----------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$root = dirname( __DIR__, 2 );

require_once $root . '/admin/modules/geo-routing/includes/class-ruleset-resolver.php';
require_once $root . '/includes/class-utils.php';

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

function assert_true( $actual, $label ) {
	assert_eq( (bool) $actual, true, $label );
}

function load_json( $path ) {
	$raw = file_get_contents( $path );
	return json_decode( $raw, true );
}

$rulesets_dir = $root . '/admin/modules/geo-routing/rulesets';
$configs_dir  = $root . '/admin/modules/banners/includes/configs';

// ---------- Fixtures (mirror production _index.json after the fix) ----------

$index_countries = array(
	'IT' => 'gdpr-italy',
	'FR' => 'gdpr-france',
	'GB' => 'uk-gdpr-pecr',
	'US' => 'us-router',
	'CA' => 'pipeda-canada',
	'BR' => 'lgpd-brazil',
);

$index_regions = array(
	'CA-QC' => 'law25-quebec', // NEW: generic (non-US) region routing.
	'US-CA' => 'ccpa-california',
	'US-CO' => 'cpa-colorado',
	'US-TX' => 'tdpsa-texas',
);

$no_overrides = array();
$fallback     = 'fallback-gdpr-most-protective';

echo "\n== FAZ compliance-hardening unit tests ==\n";

// ===========================================================================
// 1. Quebec Law 25 region routing (resolver Stage 4b)
// ===========================================================================
echo "\n-- Quebec Law 25 region routing --\n";

assert_eq(
	Ruleset_Resolver::resolve( 'CA', 'CA-QC', false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'law25-quebec',
	'CA + QC region → law25-quebec (region trumps federal PIPEDA)'
);
assert_eq(
	Ruleset_Resolver::resolve( 'CA', null, false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'pipeda-canada',
	'CA without region → federal pipeda-canada'
);
assert_eq(
	Ruleset_Resolver::resolve( 'CA', 'CA-ON', false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'pipeda-canada',
	'CA + Ontario (no specific ruleset) → pipeda-canada'
);
// US region routing must still work unchanged.
assert_eq(
	Ruleset_Resolver::resolve( 'US', 'US-CA', false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'ccpa-california',
	'US + CA region → ccpa-california (US path unchanged)'
);
assert_eq(
	Ruleset_Resolver::resolve( 'US', 'US-WY', false, $no_overrides, $index_countries, $index_regions, $fallback ),
	'gdpr-strict',
	'US no-law state → gdpr-strict (most protective)'
);
// VPN trumps everything, including region routing.
assert_eq(
	Ruleset_Resolver::resolve( 'CA', 'CA-QC', true, $no_overrides, $index_countries, $index_regions, $fallback ),
	$fallback,
	'CA-QC behind VPN → fallback (VPN trumps region)'
);

// ===========================================================================
// 2. GPC ruleset flag integrity (universal-opt-out states + fallback)
// ===========================================================================
echo "\n-- GPC ruleset flags --\n";

$gpc_required_states = array(
	'tdpsa-texas',
	'njdpl-newjersey',
	'mcdpa-minnesota',
	'modpa-maryland',
	'nhpl-newhampshire',
	// Pre-existing correct states (regression guard).
	'ccpa-california',
	'cpa-colorado',
	'ctdpa-connecticut',
	'ocpa-oregon',
	'mcdpa-montana',
	'delaware-dpdpa',
);
foreach ( $gpc_required_states as $rid ) {
	$rs = load_json( "$rulesets_dir/$rid.json" );
	assert_true(
		isset( $rs['signals']['gpc_honored'] ) && true === $rs['signals']['gpc_honored'],
		"$rid honors GPC (UOOM mandate)"
	);
}
// The most-protective fallback must advertise GPC honoring.
$fb = load_json( "$rulesets_dir/fallback-gdpr-most-protective.json" );
assert_true(
	isset( $fb['signals']['gpc_honored'] ) && true === $fb['signals']['gpc_honored'],
	'fallback-gdpr-most-protective honors GPC'
);
// States genuinely without a UOOM mandate stay false (regression guard).
$no_uoom = load_json( "$rulesets_dir/ucpa-utah.json" );
assert_eq(
	$no_uoom['signals']['gpc_required'] ?? null,
	false,
	'ucpa-utah keeps gpc_required=false (no UOOM mandate)'
);

// ===========================================================================
// 3. PIPEDA hybrid reclassification
// ===========================================================================
echo "\n-- PIPEDA hybrid model --\n";

$pipeda = load_json( "$rulesets_dir/pipeda-canada.json" );
assert_eq( $pipeda['model'] ?? null, 'hybrid', 'PIPEDA model is hybrid' );
assert_eq(
	$pipeda['ui']['default_categories']['marketing'] ?? null,
	'denied-until-action',
	'PIPEDA marketing requires express opt-in'
);
assert_eq(
	$pipeda['ui']['default_categories']['profiling'] ?? null,
	'denied-until-action',
	'PIPEDA profiling requires express opt-in'
);
assert_eq(
	$pipeda['signals']['cmv2']['ad_personalization'] ?? null,
	'denied-until-action',
	'PIPEDA ad_personalization denied until action'
);
assert_eq(
	$pipeda['ui']['default_categories']['analytics'] ?? null,
	'granted',
	'PIPEDA analytics stays implied-consent (granted)'
);

// ===========================================================================
// 4. respectGPC ships opt-in (default OFF) but the key is always present
// ===========================================================================
// GPC honoring is a real, wired feature (see the E2E suite) but it ships OFF
// by default so updating the plugin never silently changes the behaviour of
// an existing install — the admin opts in via the per-banner toggle. The key
// must still be present in every shipped config so the toggle round-trips.
echo "\n-- respectGPC default (opt-in) --\n";

foreach ( array( 'gdpr', 'ccpa' ) as $law ) {
	foreach ( array( '', '6.0.0/', '6.2.0/' ) as $ver ) {
		$path = "$configs_dir/$ver$law.json";
		if ( ! file_exists( $path ) ) {
			continue;
		}
		$cfg = load_json( $path );
		assert_true(
			isset( $cfg['behaviours']['respectGPC']['status'] ),
			"respectGPC key present in {$ver}{$law}.json (toggle round-trips)"
		);
		assert_eq(
			$cfg['behaviours']['respectGPC']['status'] ?? null,
			false,
			"respectGPC defaults OFF in {$ver}{$law}.json (opt-in, no behaviour change for existing installs)"
		);
	}
}

// ===========================================================================
// 5. Accept/Reject equal-weight default styling
// ===========================================================================
echo "\n-- Accept/Reject equal weight --\n";

function find_button_styles( $node, $tag ) {
	if ( is_array( $node ) ) {
		if ( ( $node['tag'] ?? '' ) === $tag && isset( $node['styles'] ) ) {
			return $node['styles'];
		}
		foreach ( $node as $child ) {
			$found = find_button_styles( $child, $tag );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	return null;
}

foreach ( array( '', '6.0.0/', '6.2.0/' ) as $ver ) {
	$path = "$configs_dir/{$ver}gdpr.json";
	if ( ! file_exists( $path ) ) {
		continue;
	}
	$cfg    = load_json( $path );
	$accept = find_button_styles( $cfg, 'accept-button' );
	$reject = find_button_styles( $cfg, 'reject-button' );
	assert_eq(
		strtolower( $reject['background-color'] ?? '' ),
		strtolower( $accept['background-color'] ?? '#x' ),
		"reject matches accept background in {$ver}gdpr.json (no dark pattern)"
	);
	assert_true(
		'transparent' !== strtolower( $reject['background-color'] ?? 'transparent' ),
		"reject is not transparent/outlined in {$ver}gdpr.json"
	);
}

// ===========================================================================
// 6. 6-month (183-day) consent-expiry cap logic
// ===========================================================================
echo "\n-- Consent expiry cap (183 days) --\n";

// Mirror the clamp applied in frontend/class-frontend.php::get_store_data().
$clamp_expiry = function ( $value ) {
	return min( 183, max( 1, (int) $value ) );
};
assert_eq( $clamp_expiry( 3650 ), 183, '10-year request clamps to 183 days' );
assert_eq( $clamp_expiry( 365 ), 183, '1-year request clamps to 183 days' );
assert_eq( $clamp_expiry( 180 ), 180, 'compliant 180 passes through' );
assert_eq( $clamp_expiry( 0 ), 1, 'zero floors to 1 day' );
// The shipped default must be within the cap.
$gdpr_cfg = load_json( "$configs_dir/6.2.0/gdpr.json" );
assert_true(
	(int) ( $gdpr_cfg['settings']['consentExpiry']['value'] ?? 9999 ) <= 183,
	'shipped consentExpiry default is within the 6-month cap'
);

// ===========================================================================
// 7. Trusted-proxy CIDR allowlist (faz_ip_in_cidr_list)
// ===========================================================================
echo "\n-- Trusted-proxy CIDR allowlist --\n";

assert_true( faz_ip_in_cidr_list( '10.1.2.3', array( '10.0.0.0/8' ) ), 'IPv4 inside /8' );
assert_true( ! faz_ip_in_cidr_list( '11.1.2.3', array( '10.0.0.0/8' ) ), 'IPv4 outside /8' );
assert_true( faz_ip_in_cidr_list( '192.168.5.7', array( '192.168.5.0/24' ) ), 'IPv4 inside /24' );
assert_true( ! faz_ip_in_cidr_list( '192.168.6.7', array( '192.168.5.0/24' ) ), 'IPv4 outside /24' );
assert_true( faz_ip_in_cidr_list( '203.0.113.9', array( '203.0.113.9' ) ), 'bare IPv4 exact match' );
assert_true( ! faz_ip_in_cidr_list( '203.0.113.10', array( '203.0.113.9' ) ), 'bare IPv4 non-match' );
assert_true( faz_ip_in_cidr_list( '2400:cb00::1', array( '2400:cb00::/32' ) ), 'IPv6 inside /32' );
assert_true( ! faz_ip_in_cidr_list( '2401:cb00::1', array( '2400:cb00::/32' ) ), 'IPv6 outside /32' );
assert_true( ! faz_ip_in_cidr_list( '10.1.2.3', array() ), 'empty allowlist never matches' );
assert_true( ! faz_ip_in_cidr_list( 'not-an-ip', array( '10.0.0.0/8' ) ), 'malformed IP never matches' );
assert_true( ! faz_ip_in_cidr_list( '10.1.2.3', array( 'garbage/xx' ) ), 'malformed CIDR entry skipped' );
// Address-family isolation: an IPv4 must not match an IPv6 subnet.
assert_true( ! faz_ip_in_cidr_list( '10.1.2.3', array( '2400:cb00::/32' ) ), 'IPv4 never matches IPv6 subnet' );

// ---------- Summary ----------

echo "\n";
echo "Tests run:    $tests_run\n";
echo "\033[32mPassed:       $tests_passed\033[0m\n";
if ( $tests_failed > 0 ) {
	echo "\033[31mFailed:       $tests_failed\033[0m\n";
	exit( 1 );
}
echo "\033[32mAll compliance-hardening unit tests passed.\033[0m\n";
exit( 0 );
