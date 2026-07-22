<?php
/**
 * Standalone unit tests for the guided setup wizard's compliance-critical logic.
 *
 * Covers:
 *   - Settings sanitize cases for the new onboarding flags (completed / dismissed
 *     bool coercion, law whitelist rejecting junk).
 *   - Backward-compat: an existing option array WITHOUT the onboarding key must
 *     still yield completed=true (no nag for upgrading installs).
 *   - Onboarding::map_law_to_banner_fields() returns the exact, compliant
 *     applicableLaw / donotSell / optoutPopup combos per jurisdiction and never
 *     mutates equal-weight buttons or consent-expiry.
 *
 * Run: php tests/unit/test-onboarding-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Store {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	if ( ! function_exists( 'faz_sanitize_bool' ) ) {
		function faz_sanitize_bool( $value ) {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}
	}

	if ( ! function_exists( 'absint' ) ) {
		function absint( $value ) {
			return abs( (int) $value );
		}
	}

	// Minimal translation shim so class-onboarding.php parses/executes standalone.
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			return $text;
		}
	}

	require_once __DIR__ . '/../../admin/modules/settings/includes/class-settings.php';
	require_once __DIR__ . '/../../admin/modules/settings/includes/class-onboarding.php';

	use FazCookie\Admin\Modules\Settings\Includes\Settings;
	use FazCookie\Admin\Modules\Settings\Includes\Onboarding;

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

	echo "\n== Onboarding wizard ==\n\n";

	// The onboarding subtree of the real defaults (mirrors Settings::get_defaults).
	$defaults = array(
		'consent_logs' => array(
			'status' => true,
		),
		'onboarding'   => array(
			'step'      => 2,
			'completed' => true,
			'dismissed' => false,
			'law'       => '',
		),
	);

	echo "-- sanitize: flag coercion & law whitelist --\n";

	$sanitized = Settings::sanitize(
		array(
			'onboarding' => array(
				'completed' => 'false', // truthy string that must NOT survive as a string
				'dismissed' => '1',
				'law'       => 'ccpa',
			),
		),
		$defaults
	);

	faz_assert_same( $sanitized['onboarding']['completed'], false, "onboarding.completed string 'false' coerces to bool false" );
	faz_assert_same( $sanitized['onboarding']['dismissed'], true, "onboarding.dismissed string '1' coerces to bool true" );
	faz_assert_same( $sanitized['onboarding']['law'], 'ccpa', 'onboarding.law accepts a whitelisted value' );

	$junk = Settings::sanitize(
		array(
			'onboarding' => array(
				'completed' => 1,
				'law'       => 'evil',
			),
		),
		$defaults
	);
	faz_assert_same( $junk['onboarding']['completed'], true, 'onboarding.completed int 1 coerces to bool true' );
	faz_assert_same( $junk['onboarding']['law'], '', "onboarding.law rejects a non-whitelisted value ('evil' -> '')" );

	foreach ( array( 'gdpr', 'ccpa', 'both', '' ) as $valid_law ) {
		$ok = Settings::sanitize( array( 'onboarding' => array( 'law' => $valid_law ) ), $defaults );
		faz_assert_same( $ok['onboarding']['law'], $valid_law, "onboarding.law accepts '" . ( '' === $valid_law ? '(empty)' : $valid_law ) . "'" );
	}

	echo "\n-- backward-compat: missing onboarding key --\n";

	// An existing install upgrading: stored option lacks the onboarding key
	// entirely. sanitize must fall back to the default (completed=true) so the
	// wizard is never surfaced.
	$upgraded = Settings::sanitize( array( 'consent_logs' => array( 'status' => true ) ), $defaults );
	faz_assert_same( $upgraded['onboarding']['completed'], true, 'missing onboarding key yields completed=true (no nag on upgrade)' );
	faz_assert_same( $upgraded['onboarding']['dismissed'], false, 'missing onboarding key yields dismissed=false' );
	faz_assert_same( $upgraded['onboarding']['law'], '', 'missing onboarding key yields law="" ' );

	// Onboarding present but partial (completed absent) must also default true.
	$partial = Settings::sanitize( array( 'onboarding' => array( 'dismissed' => true ) ), $defaults );
	faz_assert_same( $partial['onboarding']['completed'], true, 'partial onboarding (no completed key) yields completed=true' );

	echo "\n-- map_law_to_banner_fields: compliant per-law combos --\n";

	$gdpr = Onboarding::map_law_to_banner_fields( 'gdpr' );
	faz_assert_same( $gdpr['applicableLaw'], 'gdpr', 'gdpr -> applicableLaw gdpr' );
	faz_assert_same( $gdpr['donotSell'], false, 'gdpr -> Do-Not-Sell off (opt-in, no US opt-out entry point)' );
	faz_assert_same( $gdpr['optoutPopup'], false, 'gdpr -> opt-out popup off' );

	$ccpa = Onboarding::map_law_to_banner_fields( 'ccpa' );
	faz_assert_same( $ccpa['applicableLaw'], 'ccpa', 'ccpa -> applicableLaw ccpa' );
	faz_assert_same( $ccpa['donotSell'], true, 'ccpa -> Do-Not-Sell on (opt-out model)' );
	faz_assert_same( $ccpa['optoutPopup'], true, 'ccpa -> opt-out popup on' );

	$both = Onboarding::map_law_to_banner_fields( 'both' );
	faz_assert_same( $both['applicableLaw'], 'gdpr', 'both -> applicableLaw gdpr (more-protective opt-in governs)' );
	faz_assert_same( $both['donotSell'], true, 'both -> Do-Not-Sell on (US opt-out entry point still shown)' );
	faz_assert_same( $both['optoutPopup'], true, 'both -> opt-out popup on' );

	faz_assert_same( Onboarding::map_law_to_banner_fields( 'evil' ), null, 'unknown law -> null (no banner mutation)' );
	faz_assert_same( Onboarding::map_law_to_banner_fields( '' ), null, "empty law -> null (no banner mutation)" );

	echo "\n-- invariants: expiry & equal-weight buttons never mutated --\n";

	// The map must expose ONLY the three law-related fields. Any consentExpiry or
	// button-weight key here would mean the wizard could raise expiry above the
	// 182-day Garante cap or unbalance Accept/Reject — a compliance regression.
	foreach ( array( 'gdpr', 'ccpa', 'both' ) as $law ) {
		$fields = Onboarding::map_law_to_banner_fields( $law );
		$keys   = array_keys( $fields );
		sort( $keys );
		faz_assert_same( $keys, array( 'applicableLaw', 'donotSell', 'optoutPopup' ), "map('$law') exposes only law fields (no expiry/button mutation)" );
	}
	// gdpr/both keep applicableLaw='gdpr', so the frontend's non-ccpa expiry clamp
	// (<=182 days) always applies to them.
	faz_assert_same( $gdpr['applicableLaw'] !== 'ccpa', true, 'gdpr stays under the 182-day non-ccpa expiry clamp' );
	faz_assert_same( $both['applicableLaw'] !== 'ccpa', true, 'both stays under the 182-day non-ccpa expiry clamp' );

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
}
