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
 *     applicableLaw / donotSell / optoutPopup / expiry / notice-control combos
 *     per jurisdiction.
 *
 * Run: php tests/unit/test-onboarding-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Store {}
}

namespace FazCookie\Admin\Modules\Languages\Includes {
	// Minimal stand-in so apply_options()'s language validation has a catalogue
	// to check against without bootstrapping the real Languages module.
	class Controller {
		private static $instance = null;
		public static function get_instance() {
			if ( ! self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function get_languages() {
			return array(
				'English' => 'en',
				'Italian' => 'it',
			);
		}
	}
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
	faz_assert_same( $gdpr['consentExpiry'], 180, 'gdpr -> canonical 180-day consent lifetime' );
	faz_assert_same( $gdpr['noticeButtons'], true, 'gdpr -> equal-weight notice controls visible' );

	$ccpa = Onboarding::map_law_to_banner_fields( 'ccpa' );
	faz_assert_same( $ccpa['applicableLaw'], 'ccpa', 'ccpa -> applicableLaw ccpa' );
	faz_assert_same( $ccpa['donotSell'], true, 'ccpa -> Do-Not-Sell on (opt-out model)' );
	faz_assert_same( $ccpa['optoutPopup'], true, 'ccpa -> opt-out popup on' );
	faz_assert_same( $ccpa['consentExpiry'], 365, 'ccpa -> canonical 365-day preference lifetime' );
	faz_assert_same( $ccpa['noticeButtons'], false, 'ccpa -> GDPR Accept/Reject notice controls hidden' );

	$both = Onboarding::map_law_to_banner_fields( 'both' );
	faz_assert_same( $both['applicableLaw'], 'gdpr', 'both -> applicableLaw gdpr (more-protective opt-in governs)' );
	faz_assert_same( $both['donotSell'], true, 'both -> Do-Not-Sell on (US opt-out entry point still shown)' );
	faz_assert_same( $both['optoutPopup'], true, 'both -> opt-out popup on' );
	faz_assert_same( $both['consentExpiry'], 180, 'both -> canonical GDPR-family 180-day lifetime' );
	faz_assert_same( $both['noticeButtons'], true, 'both -> equal-weight notice controls visible' );

	faz_assert_same( Onboarding::map_law_to_banner_fields( 'evil' ), null, 'unknown law -> null (no banner mutation)' );
	faz_assert_same( Onboarding::map_law_to_banner_fields( '' ), null, "empty law -> null (no banner mutation)" );

	echo "\n-- invariants: complete law-specific mapping --\n";

	// The map must expose exactly the fields the wizard applies. Extra keys would
	// expand its mutation surface; missing keys would leave stale values from the
	// previous law (the CCPA-on-GDPR regression this suite guards).
	foreach ( array( 'gdpr', 'ccpa', 'both' ) as $law ) {
		$fields = Onboarding::map_law_to_banner_fields( $law );
		$keys   = array_keys( $fields );
		sort( $keys );
		faz_assert_same( $keys, array( 'applicableLaw', 'consentExpiry', 'donotSell', 'noticeButtons', 'optoutPopup' ), "map('$law') exposes the complete canonical law fields only" );
	}
	// gdpr/both keep applicableLaw='gdpr', so the frontend's non-ccpa expiry clamp
	// (<=182 days) always applies to them.
	faz_assert_same( $gdpr['applicableLaw'] !== 'ccpa', true, 'gdpr stays under the 182-day non-ccpa expiry clamp' );
	faz_assert_same( $both['applicableLaw'] !== 'ccpa', true, 'both stays under the 182-day non-ccpa expiry clamp' );

	echo "\n-- wizard v2: apply_options() allowlists and gates --\n";

	// Shims the option-application path needs standalone.
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) {
			return trim( strip_tags( (string) $value ) );
		}
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}

	// Reflection: apply_options is private by design (only finish() calls it);
	// invoking it directly keeps these tests free of the Banner/DB dependency.
	$onb    = new Onboarding();
	$method = new \ReflectionMethod( Onboarding::class, 'apply_options' );
	$method->setAccessible( true );
	$run_options = function ( array $options, array $all = array() ) use ( $onb, $method ) {
		$warnings = $method->invokeArgs( $onb, array( $options, &$all ) );
		return array( $all, $warnings );
	};

	// Language: valid catalogue code becomes default + joins selected; junk is ignored.
	list( $all, ) = $run_options( array( 'language' => 'it' ), array( 'languages' => array( 'selected' => array( 'en' ), 'default' => 'en' ) ) );
	faz_assert_same( $all['languages']['default'], 'it', 'language: valid code becomes the default' );
	faz_assert_same( $all['languages']['selected'], array( 'en', 'it' ), 'language: default is appended to selected' );
	list( $all, ) = $run_options( array( 'language' => 'xx' ), array( 'languages' => array( 'selected' => array( 'en' ), 'default' => 'en' ) ) );
	faz_assert_same( $all['languages']['default'], 'en', 'language: unknown code is rejected' );

	// Banner control: strict allowlist — status/ab_test can never be written here.
	list( $all, ) = $run_options( array( 'banner_control' => array( 'per_service_consent' => 1, 'status' => false, 'ab_test' => array( 'status' => true ) ) ) );
	faz_assert_same( $all['banner_control']['per_service_consent'], true, 'banner_control: allowlisted switch is applied (bool-coerced)' );
	faz_assert_same( array_key_exists( 'status', $all['banner_control'] ), false, 'banner_control: status is NOT writable from the wizard' );
	faz_assert_same( array_key_exists( 'ab_test', $all['banner_control'] ), false, 'banner_control: ab_test is NOT writable from the wizard' );

	// IAB TCF: enabling without a registered CMP ID (>=2) is refused with a warning.
	list( $all, $warnings ) = $run_options( array( 'iab' => array( 'enabled' => true, 'cmp_id' => 0 ) ) );
	faz_assert_same( $all['iab']['enabled'], false, 'iab: enable without CMP ID is refused (frontend would ignore it)' );
	faz_assert_same( count( $warnings ) === 1, true, 'iab: the refusal carries an advisory warning' );
	list( $all, $warnings ) = $run_options( array( 'iab' => array( 'enabled' => true, 'cmp_id' => 300, 'publisher_cc' => 'it' ) ) );
	faz_assert_same( $all['iab']['enabled'], true, 'iab: enable with a valid CMP ID sticks' );
	faz_assert_same( $all['iab']['cmp_id'], 300, 'iab: CMP ID persisted as int' );
	faz_assert_same( $warnings, array(), 'iab: no warning for a valid TCF configuration' );

	// Geo: junk regions filtered by the whitelist; zero regions with targeting on
	// falls back to the safe eu+uk set; behavior enum enforced.
	list( $all, ) = $run_options( array( 'geolocation' => array( 'geo_targeting' => true, 'target_regions' => array( 'eu', 'mars', 'uk' ), 'default_behavior' => 'no_banner' ) ) );
	faz_assert_same( $all['geolocation']['target_regions'], array( 'eu', 'uk' ), 'geo: unknown regions are filtered out' );
	faz_assert_same( $all['geolocation']['default_behavior'], 'no_banner', 'geo: valid behavior is applied' );
	list( $all, ) = $run_options( array( 'geolocation' => array( 'geo_targeting' => true, 'target_regions' => array( 'mars' ), 'default_behavior' => 'explode' ) ) );
	faz_assert_same( $all['geolocation']['target_regions'], array( 'eu', 'uk' ), 'geo: all-junk regions fall back to the safe eu+uk set' );
	faz_assert_same( $all['geolocation']['default_behavior'], 'show_banner', 'geo: unknown behavior falls back to show_banner' );

	// Payment gateways: valid keys opt in, junk ignored, existing map preserved.
	list( $all, ) = $run_options(
		array( 'payment_gateways' => array( 'stripe', 'evil_gateway' ) ),
		array( 'script_blocking' => array( 'payment_gateways' => array( 'paypal' => true, 'stripe' => false ) ) )
	);
	faz_assert_same( $all['script_blocking']['payment_gateways']['stripe'], true, 'payments: detected gateway is opted in' );
	faz_assert_same( $all['script_blocking']['payment_gateways']['paypal'], true, 'payments: pre-existing opt-ins are preserved' );
	faz_assert_same( array_key_exists( 'evil_gateway', $all['script_blocking']['payment_gateways'] ), false, 'payments: unknown gateway keys are ignored' );

	// Constants stay in sync with the surfaces they mirror.
	faz_assert_same( Onboarding::REGIONS, array( 'eu', 'uk', 'us', 'ca', 'br', 'au', 'jp', 'ch' ), 'REGIONS matches the Settings → Geolocation region list' );
	faz_assert_same( Onboarding::payment_gateway_keys(), array( 'paypal', 'stripe', 'square', 'braintree', 'klarna', 'mollie', 'amazon_pay' ), 'payment_gateway_keys falls back to the full catalogue standalone' );
	faz_assert_same( in_array( 'status', Onboarding::BANNER_CONTROL_KEYS, true ), false, 'BANNER_CONTROL_KEYS never includes the master status switch' );

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
