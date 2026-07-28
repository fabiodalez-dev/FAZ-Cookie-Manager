<?php
/**
 * Standalone unit tests for the banner-variant A/B testing feature.
 *
 * Covers the two pure pieces that carry the correctness of the feature:
 *   1. Split determinism / validity — Ab_Test::pick_variant() and
 *      Ab_Test::filter_slugs().
 *   2. Stats aggregation — Ab_Test::compute_stats() (the shape the consent-log
 *      GROUP BY query is reshaped into for the Dashboard panel).
 * Plus the settings sanitiser keeping the ab_test defaults and cleaning the
 * variant slug list.
 *
 * Self-contained CLI runner: stubs the few WP functions it needs, exits 0 on
 * success / 1 on failure (mirrors the other tests/unit/test-*.php runners).
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	// Minimal stub: Settings extends Store, which we don't exercise here.
	class Store {}

	// Minimal stub for the geo helper referenced by Ab_Test::is_banner_geo_eligible
	// (only its static $eu_countries data is used). Kept tiny — the real class is
	// not loaded in this standalone runner.
	class Geolocation {
		public static $eu_countries = array( 'IT', 'FR', 'DE', 'ES', 'NL' );
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	// Minimal WP stubs used by the settings sanitiser under test.
	if ( ! function_exists( 'faz_sanitize_bool' ) ) {
		function faz_sanitize_bool( $value ) {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}
	}
	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( $value ) {
			$value = strtolower( (string) $value );
			$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
			return trim( (string) $value, '-' );
		}
	}

	require_once __DIR__ . '/../../includes/class-ab-test.php';
	require_once __DIR__ . '/../../admin/modules/settings/includes/class-settings.php';

	use FazCookie\Includes\Ab_Test;
	use FazCookie\Admin\Modules\Settings\Includes\Settings;

	/**
	 * Tiny banner double exposing only the three public getters Ab_Test reads:
	 * get_law(), get_settings(), get_target_countries(). Lets the geo-eligibility
	 * and experiment-model helpers be exercised without WordPress or the real
	 * Banner class.
	 */
	class Faz_Fake_Banner {
		private $law;
		private $settings;
		private $targets;
		public function __construct( $law, $settings = array(), $targets = array() ) {
			$this->law      = $law;
			$this->settings = $settings;
			$this->targets  = $targets;
		}
		public function get_law() {
			return $this->law;
		}
		public function get_settings() {
			return $this->settings;
		}
		public function get_target_countries() {
			return $this->targets;
		}
	}

	$tests_run = $tests_passed = $tests_failed = 0;
	function faz_assert_same( $actual, $expected, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $actual === $expected ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m $label\n";
			return;
		}
		$tests_failed++;
		echo "  \033[31m✗\033[0m $label\n";
		echo '      expected: ' . var_export( $expected, true ) . "\n";
		echo '      actual:   ' . var_export( $actual, true ) . "\n";
	}

	echo "\n== A/B test: filter_slugs ==\n\n";

	faz_assert_same(
		Ab_Test::filter_slugs( array( 'gdpr-1', 'ccpa-2', 'ghost-9' ), array( 'gdpr-1', 'ccpa-2', 'lgpd-3' ) ),
		array( 'gdpr-1', 'ccpa-2' ),
		'keeps only candidates that map to an active banner slug'
	);
	faz_assert_same(
		Ab_Test::filter_slugs( array( 'gdpr-1', 'gdpr-1', ' ccpa-2 ', 'ccpa-2' ), array( 'gdpr-1', 'ccpa-2' ) ),
		array( 'gdpr-1', 'ccpa-2' ),
		'de-duplicates, trims, and preserves candidate order'
	);
	faz_assert_same(
		Ab_Test::filter_slugs( array( 'ccpa-2', 'gdpr-1' ), array( 'gdpr-1', 'ccpa-2' ) ),
		array( 'ccpa-2', 'gdpr-1' ),
		'order follows the candidate (admin) list, not the active list'
	);
	faz_assert_same(
		Ab_Test::filter_slugs( array( '', null, 'x' ), array( 'gdpr-1' ) ),
		array(),
		'blanks and non-active slugs drop out entirely'
	);

	echo "\n== A/B test: pick_variant (split determinism) ==\n\n";

	$valid = array( 'gdpr-1', 'ccpa-2', 'box-3' );

	faz_assert_same(
		Ab_Test::pick_variant( $valid, 'ccpa-2', 0 ),
		'ccpa-2',
		'sticky: a valid banner slug from the consent scope is kept regardless of the random index'
	);
	// Determinism: the same stored consent scope always yields the same variant.
	$first  = Ab_Test::pick_variant( $valid, 'box-3', 2 );
	$second = Ab_Test::pick_variant( $valid, 'box-3', 99 );
	faz_assert_same( $first === $second && 'box-3' === $first, true, 'same stored consent scope always resolves to the same variant' );

	faz_assert_same(
		Ab_Test::pick_variant( $valid, '', 0 ),
		'gdpr-1',
		'no stored scope: random index 0 selects the first valid variant'
	);
	faz_assert_same(
		Ab_Test::pick_variant( $valid, '', 1 ),
		'ccpa-2',
		'no stored scope: random index 1 selects the second valid variant'
	);
	faz_assert_same(
		Ab_Test::pick_variant( $valid, 'deleted-7', 2 ),
		'box-3',
		'a stored variant that no longer exists triggers a fresh pick (index 2)'
	);
	faz_assert_same(
		Ab_Test::pick_variant( $valid, '', 4 ),
		'ccpa-2',
		'out-of-range random index is normalised modulo the variant count (4 % 3 = 1)'
	);
	faz_assert_same(
		Ab_Test::pick_variant( $valid, '', -1 ),
		'box-3',
		'negative random index normalises into range (-1 -> 2)'
	);
	faz_assert_same(
		Ab_Test::pick_variant( array( 'only-1' ), '', 0 ),
		'',
		'fewer than two variants: returns empty so the caller falls back'
	);
	// Every possible index maps to a member of the valid set (never out of range).
	$all_valid = true;
	for ( $i = 0; $i < 30; $i++ ) {
		if ( ! in_array( Ab_Test::pick_variant( $valid, '', $i ), $valid, true ) ) {
			$all_valid = false;
			break;
		}
	}
	faz_assert_same( $all_valid, true, 'every assignment stays within the valid variant set' );

	echo "\n== A/B test: compute_stats ==\n\n";

	$rows = array(
		array( 'banner_slug' => 'gdpr-1', 'total' => '4', 'accepted' => '3', 'rejected' => '1', 'partial' => '0' ),
		array( 'banner_slug' => 'ccpa-2', 'total' => '2', 'accepted' => '1', 'rejected' => '0', 'partial' => '1' ),
		// CCPA / Do-Not-Sell variant: COUNT(*) (total) also folds in the two
		// opt-out statuses AND a hypothetical stray status (total 12 > the 10
		// named decisions) — the rate must be computed on `decisions`, not total.
		array( 'banner_slug' => 'ccpa-4', 'total' => '12', 'accepted' => '4', 'rejected' => '2', 'partial' => '0', 'optout' => '3', 'rescinded' => '1' ),
		array( 'banner_slug' => 'unrelated-9', 'total' => '5', 'accepted' => '5', 'rejected' => '0', 'partial' => '0' ),
	);
	$stats = Ab_Test::compute_stats( $rows, array( 'gdpr-1', 'ccpa-2', 'ccpa-4', 'empty-3' ) );

	faz_assert_same( count( $stats ), 4, 'one entry per requested variant slug (order preserved)' );
	faz_assert_same( $stats[0]['slug'], 'gdpr-1', 'first entry is the first requested slug' );
	faz_assert_same( $stats[0]['total'], 4, 'DB string counts are cast to int (total)' );
	faz_assert_same( $stats[0]['accepted'], 3, 'accepted cast to int' );
	faz_assert_same( $stats[0]['optout'], 0, 'optout defaults to 0 when the GROUP BY row omits it' );
	faz_assert_same( $stats[0]['rescinded'], 0, 'rescinded defaults to 0 when omitted' );
	faz_assert_same( $stats[0]['decisions'], 4, 'decisions = accepted+rejected+partial+optout+rescinded (3+1+0+0+0)' );
	faz_assert_same( $stats[0]['accept_rate'], 75.0, 'accept_rate = accepted/decisions*100 (3/4 -> 75.0)' );
	faz_assert_same( $stats[1]['slug'], 'ccpa-2', 'second entry follows requested order' );
	faz_assert_same( $stats[1]['partial'], 1, 'partial cast to int' );
	faz_assert_same( $stats[1]['decisions'], 2, 'ccpa-2 decisions (1+0+1+0+0)' );
	faz_assert_same( $stats[1]['accept_rate'], 50.0, 'accept_rate for ccpa-2 (1/2 -> 50.0)' );

	// The CCPA fix: accept_rate uses the explicit `decisions` denominator, so
	// it is NOT diluted by opt-out/rescind (and NOT skewed by total's COUNT(*)).
	faz_assert_same( $stats[2]['slug'], 'ccpa-4', 'third entry is the CCPA variant' );
	faz_assert_same( $stats[2]['total'], 12, 'total (COUNT(*)) is preserved verbatim for backward compat' );
	faz_assert_same( $stats[2]['optout'], 3, 'dnsmpi_optout count is exposed in the breakdown' );
	faz_assert_same( $stats[2]['rescinded'], 1, 'dns_rescinded count is exposed in the breakdown' );
	faz_assert_same( $stats[2]['decisions'], 10, 'decisions sums the 5 named statuses (4+2+0+3+1), ignoring stray total' );
	faz_assert_same( $stats[2]['accept_rate'], 40.0, 'accept_rate = accepted/decisions (4/10 -> 40.0), not accepted/total (4/12 -> 33.3)' );

	faz_assert_same( $stats[3]['slug'], 'empty-3', 'a variant with no consents is zero-filled, not dropped' );
	faz_assert_same( $stats[3]['total'], 0, 'zero-filled variant has total 0' );
	faz_assert_same( $stats[3]['decisions'], 0, 'zero-filled variant has decisions 0' );
	faz_assert_same( $stats[3]['accept_rate'], 0, 'zero-filled variant has accept_rate 0 (no divide-by-zero)' );

	// Rows for slugs not requested must never leak into the output.
	$slugs_out = array_map( function ( $r ) { return $r['slug']; }, $stats );
	faz_assert_same( in_array( 'unrelated-9', $slugs_out, true ), false, 'rows for non-requested slugs are excluded' );

	echo "\n== A/B test: experiment_model ==\n\n";

	// donotSell true => "<law>|dns"; anything else => "<law>|plain". "Both" is
	// stored as gdpr + donotSell, so it must NOT collapse into pure gdpr.
	$dns_settings = array(
		'config' => array(
			'notice' => array(
				'elements' => array(
					'buttons' => array(
						'elements' => array(
							'donotSell' => array( 'status' => true ),
						),
					),
				),
			),
		),
	);
	faz_assert_same(
		Ab_Test::experiment_model( new Faz_Fake_Banner( 'gdpr', array() ) ),
		'gdpr|plain',
		'pure GDPR banner (no donotSell) => gdpr|plain'
	);
	faz_assert_same(
		Ab_Test::experiment_model( new Faz_Fake_Banner( 'gdpr', $dns_settings ) ),
		'gdpr|dns',
		'"both" banner (gdpr + donotSell) => gdpr|dns, distinct from pure GDPR'
	);
	faz_assert_same(
		Ab_Test::experiment_model( new Faz_Fake_Banner( 'ccpa', $dns_settings ) ),
		'ccpa|dns',
		'CCPA banner with donotSell => ccpa|dns'
	);

	echo "\n== A/B test: is_banner_geo_eligible ==\n\n";

	// No targets, no ruleSet => eligible everywhere (incl. unknown country).
	faz_assert_same(
		Ab_Test::is_banner_geo_eligible( new Faz_Fake_Banner( 'gdpr', array() ), 'IT' ),
		true,
		'no target_countries and no ruleSet => eligible for any visitor'
	);
	faz_assert_same(
		Ab_Test::is_banner_geo_eligible( new Faz_Fake_Banner( 'gdpr', array() ), '' ),
		true,
		'no target/ruleSet with unresolved country => still eligible (no signal never hides the surface)'
	);

	// target_countries gate.
	faz_assert_same(
		Ab_Test::is_banner_geo_eligible( new Faz_Fake_Banner( 'gdpr', array(), array( 'IT', 'FR' ) ), 'DE' ),
		false,
		'country-restricted banner drops a visitor outside target_countries'
	);
	faz_assert_same(
		Ab_Test::is_banner_geo_eligible( new Faz_Fake_Banner( 'gdpr', array(), array( 'IT', 'FR' ) ), '' ),
		false,
		'country-restricted banner drops an unresolved-country visitor'
	);
	// THE ASYMMETRY FIX: targets stored lower-case must still match an
	// upper-case visitor country (both sides normalised now).
	faz_assert_same(
		Ab_Test::is_banner_geo_eligible( new Faz_Fake_Banner( 'gdpr', array(), array( 'it', 'fr' ) ), 'IT' ),
		true,
		'case-insensitive target match: lower-case targets accept upper-case visitor country'
	);
	faz_assert_same(
		Ab_Test::is_banner_geo_eligible( new Faz_Fake_Banner( 'gdpr', array(), array( 'IT' ) ), 'it' ),
		true,
		'case-insensitive target match: lower-case visitor country accepted against upper-case target'
	);

	// ruleSet gate.
	$rule_eu = array( 'settings' => array( 'ruleSet' => array( array( 'code' => 'EU' ) ) ) );
	faz_assert_same(
		Ab_Test::is_banner_geo_eligible( new Faz_Fake_Banner( 'gdpr', $rule_eu ), 'IT' ),
		true,
		'ruleSet EU matches an EU visitor (IT in $eu_countries)'
	);
	faz_assert_same(
		Ab_Test::is_banner_geo_eligible( new Faz_Fake_Banner( 'gdpr', $rule_eu ), 'US' ),
		false,
		'ruleSet EU does not match a US visitor'
	);
	faz_assert_same(
		Ab_Test::is_banner_geo_eligible( new Faz_Fake_Banner( 'gdpr', $rule_eu ), '' ),
		true,
		'ruleSet present but country unresolved => eligible (no signal never hides the surface)'
	);
	$rule_all = array( 'settings' => array( 'ruleSet' => array( array( 'code' => 'ALL' ) ) ) );
	faz_assert_same(
		Ab_Test::is_banner_geo_eligible( new Faz_Fake_Banner( 'gdpr', $rule_all ), 'US' ),
		true,
		'ruleSet ALL matches every resolved country'
	);
	$rule_other = array( 'settings' => array( 'ruleSet' => array( array( 'code' => 'OTHER', 'regions' => array( 'br', 'jp' ) ) ) ) );
	faz_assert_same(
		Ab_Test::is_banner_geo_eligible( new Faz_Fake_Banner( 'gdpr', $rule_other ), 'BR' ),
		true,
		'ruleSet OTHER matches a listed region case-insensitively (br => BR)'
	);
	faz_assert_same(
		Ab_Test::is_banner_geo_eligible( new Faz_Fake_Banner( 'gdpr', $rule_other ), 'US' ),
		false,
		'ruleSet OTHER does not match an unlisted region'
	);

	echo "\n== A/B test: settings sanitiser ==\n\n";

	$defaults = array(
		'banner_control' => array(
			'ab_test' => array(
				'status'   => false,
				'variants' => array(),
			),
		),
	);

	// Default shape survives an empty payload (backward-compat: installs whose
	// stored banner_control predates the feature get the ab_test default).
	$def = Settings::sanitize( array(), $defaults );
	faz_assert_same( $def['banner_control']['ab_test']['status'], false, 'ab_test.status defaults to false (opt-in, OFF)' );
	faz_assert_same( $def['banner_control']['ab_test']['variants'], array(), 'ab_test.variants defaults to empty array' );

	// Status coerces, variants are slug-normalised, de-duplicated and blank-free.
	$san = Settings::sanitize(
		array(
			'banner_control' => array(
				'ab_test' => array(
					'status'   => '1',
					'variants' => array( 'GDPR 1', 'gdpr-1', '  ccpa-2 ', '', 'Box 3' ),
				),
			),
		),
		$defaults
	);
	faz_assert_same( $san['banner_control']['ab_test']['status'], true, "ab_test.status string '1' coerces to bool true" );
	faz_assert_same(
		$san['banner_control']['ab_test']['variants'],
		array( 'gdpr-1', 'ccpa-2', 'box-3' ),
		'variants normalised via sanitize_title, de-duplicated, blanks dropped'
	);

	// A non-array variants value resets to an empty array (injection-safe).
	$bad = Settings::sanitize(
		array( 'banner_control' => array( 'ab_test' => array( 'variants' => 'not-an-array' ) ) ),
		$defaults
	);
	faz_assert_same( $bad['banner_control']['ab_test']['variants'], array(), 'non-array variants resets to empty array' );

	echo "\n────────────────────────────────────────────\n";
	if ( $tests_failed > 0 ) {
		echo "\033[31m{$tests_failed} FAILED\033[0m, {$tests_passed}/{$tests_run} passed\n";
		exit( 1 );
	}
	echo "\033[32mALL PASS\033[0m — {$tests_passed}/{$tests_run} passed\n";
	exit( 0 );
}
