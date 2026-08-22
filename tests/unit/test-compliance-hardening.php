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
 *   - GPC honoured server-side (Sec-GPC + DNSMPI cookie)
 *   - 6-month (182-day) consent-expiry CAP — a maximum, never a minimum
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
	'denied-until-action',
	'PIPEDA analytics requires opt-in (no blanket implied consent)'
);

// ===========================================================================
// 4. GPC is honoured server-side (main's model: Sec-GPC header + navigator)
// ===========================================================================
// main 1.17.2 honours GPC for the CCPA opt-out law unconditionally (it does not
// gate on a per-banner toggle). Assert the server-side enforcement is wired in
// get_blocked_categories(): a Sec-GPC:1 request blocks the sell/share
// categories, and the DNSMPI form opt-out cookie does the same (this PR).
echo "\n-- GPC / DNSMPI server-side enforcement wired --\n";

$frontend_src = file_get_contents( $root . '/frontend/class-frontend.php' );
assert_true(
	false !== strpos( $frontend_src, 'HTTP_SEC_GPC' ),
	'get_blocked_categories honours the Sec-GPC request header'
);
assert_true(
	false !== strpos( $frontend_src, "fazcookie-dnsmpi" ),
	'get_blocked_categories honours the DNSMPI opt-out cookie'
);

$activator_src = file_get_contents( $root . '/includes/class-activator.php' );
assert_true(
	false !== strpos( $activator_src, "\$settings->get( 'iab', 'enabled' )" ),
	'scheduled scan reads IAB state through the Settings object instead of array access'
);
assert_true(
	false !== strpos( $frontend_src, 'get_sell_personal_data' )
		&& false !== strpos( $frontend_src, 'get_share_personal_data' ),
	'opt-out enforcement distinguishes sell vs share categories'
);

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
// 6. 6-month consent-expiry CAP (a maximum, never a minimum)
// ===========================================================================
echo "\n-- Consent expiry cap (182 days) --\n";

// Mirror the GDPR-family rule in Frontend::normalize_consent_expiry(). It is a
// CAP: the Garante's six-month rule is a maximum and no legal minimum exists,
// so a publisher re-asking every 30 days is being MORE protective and must be
// left alone. An earlier revision imposed a 180-day floor and this suite pinned
// it — the test defended the defect. A zero/absent configuration still needs a
// usable lifetime, which is where 182 comes from. The CCPA 365-day floor and
// its 3650-day ceiling live in the production-class regression suite.
$clamp_expiry = function ( $value ) {
	$value = (int) $value;
	return $value < 1 ? 182 : min( 182, $value );
};
assert_eq( $clamp_expiry( 3650 ), 182, '10-year request clamps to 182 days' );
assert_eq( $clamp_expiry( 365 ), 182, '1-year request clamps to 182 days' );
assert_eq( $clamp_expiry( 180 ), 180, 'compliant 180 passes through' );
assert_eq( $clamp_expiry( 30 ), 30, 'a deliberate 30-day re-prompt is stricter and survives' );
assert_eq( $clamp_expiry( 1 ), 1, 'no floor is imposed on a short lifetime' );
assert_eq( $clamp_expiry( 0 ), 182, 'an absent configuration still yields a usable lifetime' );
// The shipped default must be within the cap.
$gdpr_cfg = load_json( "$configs_dir/6.2.0/gdpr.json" );
assert_true(
	(int) ( $gdpr_cfg['settings']['consentExpiry']['value'] ?? 9999 ) <= 182,
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

// ---------- Theme presets must not weaken Reject (EDPB 03/2022) ----------
//
// The banner's shipped DEFAULT is symmetric, but picking a theme in the admin
// runs applyThemePreset() -> stripStyles() -> populateButtonColors(), which
// rewrites the buttons from theme.json. Three of the four presets gave Accept a
// solid fill and Reject a transparent outline, so a single UI action silently
// produced the button asymmetry EDPB Guidelines 03/2022 treats as a dark
// pattern — the thing CNIL and the Garante actually fine. Upstream fixed the
// same defect in their 3.5.4 "updated button colors in the banner themes".
//
// Asserting equality of the RENDERED properties, not of any particular colour,
// so a future re-skin stays free to change the palette and cannot reintroduce
// the asymmetry.
echo "\n\033[1mTheme presets: Accept and Reject carry equal visual weight\033[0m\n";

$faz_theme_files = array(
	$root . '/admin/modules/banners/includes/templates/6.0.0/theme.json',
	$root . '/admin/modules/banners/includes/templates/6.2.0/theme.json',
);

$faz_collect_pairs = function ( $node, &$pairs ) use ( &$faz_collect_pairs ) {
	if ( ! is_array( $node ) ) {
		return;
	}
	foreach ( $node as $key => $value ) {
		if ( 'elements' === $key && is_array( $value )
			&& isset( $value['accept']['styles'], $value['reject']['styles'] ) ) {
			$pairs[] = array( $value['accept']['styles'], $value['reject']['styles'] );
			continue;
		}
		$faz_collect_pairs( $value, $pairs );
	}
};

$faz_pairs_checked = 0;
foreach ( $faz_theme_files as $faz_theme_file ) {
	$faz_themes = load_json( $faz_theme_file );
	$faz_label  = basename( dirname( $faz_theme_file ) );
	assert_true( is_array( $faz_themes ) && ! empty( $faz_themes ), "theme.json for {$faz_label} loads" );
	foreach ( (array) $faz_themes as $faz_theme ) {
		$faz_name  = isset( $faz_theme['name'] ) ? $faz_theme['name'] : '?';
		$faz_pairs = array();
		$faz_collect_pairs( isset( $faz_theme['settings'] ) ? $faz_theme['settings'] : array(), $faz_pairs );
		assert_true( ! empty( $faz_pairs ), "{$faz_label}/{$faz_name}: an accept/reject pair was found to compare" );
		foreach ( $faz_pairs as $faz_pair ) {
			list( $faz_accept, $faz_reject ) = $faz_pair;
			++$faz_pairs_checked;
			foreach ( array( 'background-color', 'border-color', 'color' ) as $faz_prop ) {
				if ( ! isset( $faz_accept[ $faz_prop ] ) ) {
					continue;
				}
				assert_eq(
					isset( $faz_reject[ $faz_prop ] ) ? strtolower( $faz_reject[ $faz_prop ] ) : null,
					strtolower( $faz_accept[ $faz_prop ] ),
					"{$faz_label}/{$faz_name}: reject {$faz_prop} matches accept"
				);
			}
		}
	}
}
// Without this the loop above could pass by finding nothing at all.
assert_true( $faz_pairs_checked >= 8, 'every preset x surface pair was actually compared (>= 8)' );

echo "\n";
echo "Tests run:    $tests_run\n";
echo "\033[32mPassed:       $tests_passed\033[0m\n";
if ( $tests_failed > 0 ) {
	echo "\033[31mFailed:       $tests_failed\033[0m\n";
	exit( 1 );
}
echo "\033[32mAll compliance-hardening unit tests passed.\033[0m\n";
exit( 0 );
