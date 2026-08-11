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

	// The REAL helper, not a filter_var() stand-in. apply_options() hands it raw
	// wizard values to decide which payment SDKs are exempt from pre-consent
	// blocking, and the two differ exactly where that matters: filter_var()
	// reports FALSE for any string it does not recognise, faz_sanitize_bool()
	// reports TRUE. A stub would have made the gateway assertions below pass
	// while proving nothing about what production does.
	require_once __DIR__ . '/../../includes/class-formatting.php';

	// Minimal WP_Error so the REST-boundary validators can be exercised without
	// bootstrapping WordPress.
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public $code;
			public $message;
			public $data;
			public function __construct( $code = '', $message = '', $data = '' ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}
			public function get_error_code() {
				return $this->code;
			}
		}
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ) {
			return $thing instanceof WP_Error;
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

	$popia = Onboarding::map_law_to_banner_fields( 'popia' );
	faz_assert_same( $popia['applicableLaw'], 'gdpr', 'popia consent preset -> stored as the gdpr-shaped model (runtime has no popia law id)' );
	faz_assert_same( $popia['donotSell'], false, 'popia consent preset -> no US Do-Not-Sell surface' );
	faz_assert_same( $popia['optoutPopup'], false, 'popia -> opt-out popup off' );
	faz_assert_same( $popia['consentExpiry'], 180, 'popia -> conservative 180-day consent lifetime' );
	faz_assert_same( $popia['noticeButtons'], true, 'popia -> equal-weight notice controls visible' );

	faz_assert_same( Onboarding::map_law_to_banner_fields( 'evil' ), null, 'unknown law -> null (no banner mutation)' );
	faz_assert_same( Onboarding::map_law_to_banner_fields( '' ), null, "empty law -> null (no banner mutation)" );

	echo "\n-- invariants: complete law-specific mapping --\n";

	// The map must expose exactly the fields the wizard applies. Extra keys would
	// expand its mutation surface; missing keys would leave stale values from the
	// previous law (the CCPA-on-GDPR regression this suite guards).
	foreach ( array( 'gdpr', 'ccpa', 'both', 'popia' ) as $law ) {
		$fields = Onboarding::map_law_to_banner_fields( $law );
		$keys   = array_keys( $fields );
		sort( $keys );
		faz_assert_same( $keys, array( 'applicableLaw', 'consentExpiry', 'donotSell', 'noticeButtons', 'optoutPopup' ), "map('$law') exposes the complete canonical law fields only" );
	}
	// gdpr/both/popia keep applicableLaw='gdpr', so the frontend's non-ccpa expiry
	// clamp (<=182 days) always applies to them.
	faz_assert_same( $gdpr['applicableLaw'] !== 'ccpa', true, 'gdpr stays under the 182-day non-ccpa expiry clamp' );
	faz_assert_same( $both['applicableLaw'] !== 'ccpa', true, 'both stays under the 182-day non-ccpa expiry clamp' );
	faz_assert_same( $popia['applicableLaw'] !== 'ccpa', true, 'popia stays under the 182-day non-ccpa expiry clamp' );

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
	list( $all, ) = $run_options( array( 'banner_control' => array( 'cache_compatibility' => 'false' ) ) );
	faz_assert_same( $all['banner_control']['cache_compatibility'], false, "banner_control: string 'false' cannot turn a switch on" );

	list( $all, ) = $run_options( array( 'microsoft' => array( 'uet_consent_mode' => 'false', 'clarity_consent' => '0' ) ) );
	faz_assert_same( array( $all['microsoft']['uet_consent_mode'], $all['microsoft']['clarity_consent'] ), array( false, false ), 'microsoft: false-like strings remain disabled' );

	// IAB TCF: enabling without a registered CMP ID (>=2) is refused with a warning.
	list( $all, $warnings ) = $run_options( array( 'iab' => array( 'enabled' => true, 'cmp_id' => 0 ) ) );
	faz_assert_same( $all['iab']['enabled'], false, 'iab: enable without CMP ID is refused (frontend would ignore it)' );
	faz_assert_same( count( $warnings ) === 1, true, 'iab: the refusal carries an advisory warning' );
	list( $all, $warnings ) = $run_options( array( 'iab' => array( 'enabled' => true, 'cmp_id' => 300, 'publisher_cc' => 'it' ) ) );
	faz_assert_same( $all['iab']['enabled'], true, 'iab: enable with a valid CMP ID sticks' );
	faz_assert_same( $all['iab']['cmp_id'], 300, 'iab: CMP ID persisted as int' );
	faz_assert_same( $warnings, array(), 'iab: no warning for a valid TCF configuration' );
	list( $all, ) = $run_options( array( 'iab' => array( 'enabled' => 'false', 'cmp_id' => 300 ) ) );
	faz_assert_same( $all['iab']['enabled'], false, "iab: string 'false' cannot enable TCF" );

	// Geo: junk regions filtered by the whitelist; zero regions with targeting on
	// falls back to the safe eu+uk set; behavior enum enforced.
	list( $all, ) = $run_options( array( 'geolocation' => array( 'geo_targeting' => true, 'target_regions' => array( 'eu', 'mars', 'uk' ), 'default_behavior' => 'no_banner' ) ) );
	faz_assert_same( $all['geolocation']['target_regions'], array( 'eu', 'uk' ), 'geo: unknown regions are filtered out' );
	faz_assert_same( $all['geolocation']['default_behavior'], 'no_banner', 'geo: valid behavior is applied' );
	list( $all, ) = $run_options( array( 'geolocation' => array( 'geo_targeting' => true, 'target_regions' => array( 'mars' ), 'default_behavior' => 'explode' ) ) );
	faz_assert_same( $all['geolocation']['target_regions'], array( 'eu', 'uk' ), 'geo: all-junk regions fall back to the safe eu+uk set' );
	faz_assert_same( $all['geolocation']['default_behavior'], 'show_banner', 'geo: unknown behavior falls back to show_banner' );
	list( $all, ) = $run_options( array( 'geolocation' => array( 'geo_targeting' => 'false' ) ) );
	faz_assert_same( $all['geolocation']['geo_targeting'], false, "geo: string 'false' cannot enable targeting" );

	// Payment gateways: valid keys opt in, junk ignored, existing map preserved.
	list( $all, ) = $run_options(
		array( 'payment_gateways' => array( 'stripe', 'evil_gateway' ) ),
		array( 'script_blocking' => array( 'payment_gateways' => array( 'paypal' => true, 'stripe' => false ) ) )
	);
	faz_assert_same( $all['script_blocking']['payment_gateways']['stripe'], true, 'payments: detected gateway is opted in' );
	faz_assert_same( $all['script_blocking']['payment_gateways']['paypal'], true, 'payments: pre-existing opt-ins are preserved' );
	faz_assert_same( array_key_exists( 'evil_gateway', $all['script_blocking']['payment_gateways'] ), false, 'payments: unknown gateway keys are ignored' );
	list( $all, ) = $run_options( array( 'payment_gateways' => array( 'stripe' => 'false' ) ) );
	faz_assert_same( $all['script_blocking']['payment_gateways']['stripe'], false, "payments: string 'false' disables the gateway" );

	echo "\n-- payment_gateways REST boundary --\n";

	// apply_options() calls faz_sanitize_bool() on the raw value, which is the
	// right helper but a permissive one: an unrecognised string comes back TRUE.
	// Pinned here so the reason the boundary has to be strict stays visible.
	faz_assert_same( faz_sanitize_bool( 'banana' ), true, 'faz_sanitize_bool reports an unanticipated string as TRUE (why the schema must reject it)' );
	list( $all, ) = $run_options( array( 'payment_gateways' => array( 'stripe' => 'banana' ) ) );
	faz_assert_same( $all['script_blocking']['payment_gateways']['stripe'], true, 'unchecked, junk would EXEMPT a gateway from pre-consent blocking' );

	$gw_valid = function ( $value ) {
		return Onboarding::validate_payment_gateways( $value );
	};
	// Map form: known keys with boolean-ish values pass; everything else 400s
	// rather than being silently dropped, so a broken client learns of it.
	faz_assert_same( $gw_valid( array( 'stripe' => true, 'paypal' => false ) ), true, 'REST: map of known gateways with real booleans validates' );
	faz_assert_same( $gw_valid( array( 'stripe' => 'true', 'paypal' => 'off' ) ), true, 'REST: canonical boolean strings validate' );
	faz_assert_same( $gw_valid( array( 'stripe' => 1, 'paypal' => 0 ) ), true, 'REST: 0/1 integers validate' );
	faz_assert_same( is_wp_error( $gw_valid( array( 'stripe' => 'banana' ) ) ), true, 'REST: junk gateway value is rejected (the exemption path above is closed)' );
	faz_assert_same( is_wp_error( $gw_valid( array( 'evil_gateway' => true ) ) ), true, 'REST: unknown gateway key is rejected' );
	faz_assert_same( is_wp_error( $gw_valid( array( 'stripe' => array( 'nested' => true ) ) ) ), true, 'REST: nested array value is rejected' );
	faz_assert_same( is_wp_error( $gw_valid( array( 'stripe' => 2 ) ) ), true, 'REST: an integer that is not 0/1 is rejected' );
	faz_assert_same( is_wp_error( $gw_valid( 'stripe' ) ), true, 'REST: a scalar instead of a map/list is rejected' );
	// Legacy list form stays accepted — the wizard shipped it and installs still send it.
	faz_assert_same( $gw_valid( array( 'stripe', 'paypal' ) ), true, 'REST: legacy opt-in list of known gateways validates' );
	faz_assert_same( is_wp_error( $gw_valid( array( 'evil_gateway' ) ) ), true, 'REST: legacy list naming an unknown gateway is rejected' );
	faz_assert_same( $gw_valid( array() ), true, 'REST: an empty payload is legitimate (no gateway opted in)' );

	// Sanitizer normalises values but must PRESERVE the shape: expanding a
	// legacy list into a full map would switch off every gateway the wizard
	// never mentioned, which is the opposite of the list form's contract.
	faz_assert_same( Onboarding::sanitize_payment_gateways( array( 'stripe' => 'yes', 'paypal' => '0' ) ), array( 'stripe' => true, 'paypal' => false ), 'REST: map values normalise to real booleans' );
	faz_assert_same( Onboarding::sanitize_payment_gateways( array( 'stripe', 'paypal' ) ), array( 'stripe', 'paypal' ), 'REST: legacy list is preserved as a list, not expanded into a map' );
	faz_assert_same( Onboarding::sanitize_payment_gateways( array( 'evil_gateway' => true, 'stripe' => true ) ), array( 'stripe' => true ), 'REST: sanitizer drops unknown keys as a second line of defence' );
	faz_assert_same( Onboarding::sanitize_payment_gateways( 'nonsense' ), array(), 'REST: a non-array sanitises to an empty payload' );

	// A validator nothing routes to is decoration: pin that the wizard's
	// onboarding route actually installs both callbacks on the argument.
	$api_source = file_get_contents( __DIR__ . '/../../admin/modules/settings/api/class-api.php' );
	faz_assert_same( (bool) preg_match( "/'validate_callback'\s*=>\s*array\(\s*Onboarding::class,\s*'validate_payment_gateways'\s*\)/", $api_source ), true, 'REST: the onboarding route wires the payment-gateway validator' );
	faz_assert_same( (bool) preg_match( "/'sanitize_callback'\s*=>\s*array\(\s*Onboarding::class,\s*'sanitize_payment_gateways'\s*\)/", $api_source ), true, 'REST: the onboarding route wires the payment-gateway sanitizer' );
	faz_assert_same( is_callable( array( Onboarding::class, 'validate_payment_gateways' ) ), true, 'REST: the wired validator is callable as declared' );
	faz_assert_same( is_callable( array( Onboarding::class, 'sanitize_payment_gateways' ) ), true, 'REST: the wired sanitizer is callable as declared' );

	// Site-locale mapping is independent of the logged-in administrator locale.
	faz_assert_same( Onboarding::language_from_locale( 'it_IT' ), 'it', 'site locale: it_IT maps to Italian' );
	faz_assert_same( Onboarding::language_from_locale( 'pt_BR' ), 'pt-br', 'site locale: pt_BR keeps the Brazilian catalogue variant' );
	faz_assert_same( Onboarding::language_from_locale( 'zh_TW' ), 'zh-hant', 'site locale: Traditional Chinese maps to zh-hant' );

	// Constants stay in sync with the surfaces they mirror.
	faz_assert_same( Onboarding::REGIONS, array( 'eu', 'uk', 'us', 'ca', 'br', 'au', 'jp', 'ch', 'za' ), 'REGIONS matches the Settings → Geolocation region list (za added with POPIA)' );
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
