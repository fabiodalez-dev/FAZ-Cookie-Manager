<?php
/**
 * Standalone unit tests for settings normalization.
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
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $value ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
		}
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $value ) {
			return abs( (int) $value );
		}
	}

	// The real coercions, not stubs. This suite exercises the settings write
	// path, and a local filter_var() copy is not what ships: it accepts 'on' and
	// rejects 'yes', so the harness would be asserting against its own idea of
	// the rule while production used another.
	require_once __DIR__ . '/../../includes/class-formatting.php';

	require_once __DIR__ . '/../../admin/modules/settings/includes/class-settings.php';

	use FazCookie\Admin\Modules\Settings\Includes\Settings;

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

	echo "\n== Settings sanitize guards ==\n\n";

	$defaults = array(
		'banner_control' => array(
			'per_service_consent' => false,
			'per_cookie_consent'  => false,
			'adblock_resilience'  => false,
			'cache_compatibility' => false,
		),
		'geolocation' => array(
			'geo_targeting' => false,
			'target_regions' => array( 'eu', 'uk' ),
		),
		'scanner' => array(
			'max_pages' => 20,
		),
		'iab' => array(
			'enabled' => false,
		),
		'script_blocking' => array(
			'aggressive_css_url_blocking' => false,
			'payment_gateways'            => array(
				'paypal'     => false,
				'stripe'     => false,
				'square'     => false,
				'braintree'  => false,
				'klarna'     => false,
				'mollie'     => false,
				'amazon_pay' => false,
			),
		),
	);

	$sanitized = Settings::sanitize(
		array(
			'banner_control' => array(
				'per_service_consent' => 'true',
				'per_cookie_consent'  => 'true',
				'adblock_resilience'  => '1',
			),
			'script_blocking' => array(
				'aggressive_css_url_blocking' => 'true',
			),
		),
		$defaults
	);

	faz_assert_same(
		$sanitized['banner_control']['per_service_consent'],
		true,
		'per_service_consent remains opt-in via settings'
	);
	faz_assert_same(
		$sanitized['banner_control']['per_cookie_consent'],
		true,
		'per_cookie_consent is a settable boolean (no longer hard-disabled)'
	);
	faz_assert_same(
		$sanitized['banner_control']['adblock_resilience'],
		true,
		"adblock_resilience string '1' coerces to bool true (opt-in via settings)"
	);

	// adblock_resilience: empty string coerces to false, and it defaults to
	// false when absent from the incoming payload (backward-compat for installs
	// whose stored banner_control predates the key).
	$adblock_off = Settings::sanitize(
		array(
			'banner_control' => array(
				'adblock_resilience' => '',
			),
		),
		$defaults
	);
	faz_assert_same(
		$adblock_off['banner_control']['adblock_resilience'],
		false,
		"adblock_resilience empty string coerces to bool false"
	);
	$adblock_absent = Settings::sanitize( array(), $defaults );
	faz_assert_same(
		$adblock_absent['banner_control']['adblock_resilience'],
		false,
		'adblock_resilience defaults to false when absent (opt-in, default OFF)'
	);
	faz_assert_same(
		$sanitized['script_blocking']['aggressive_css_url_blocking'],
		true,
		'aggressive_css_url_blocking is opt-in via settings'
	);

	// Payment-gateway opt-in: values coerce to strict bools, unknown gateway
	// keys are dropped (no injection into the whitelist decision), and every
	// catalogue key is always present.
	$gw_sanitized = Settings::sanitize(
		array(
			'script_blocking' => array(
				'payment_gateways' => array(
					'paypal'     => '1',
					'stripe'     => 0,
					'amazon_pay' => 'yes',
					'evilkey'    => true,
				),
			),
		),
		$defaults
	);
	faz_assert_same( $gw_sanitized['script_blocking']['payment_gateways']['paypal'], true, "payment gateway 'paypal' string '1' coerces to bool true" );
	faz_assert_same( $gw_sanitized['script_blocking']['payment_gateways']['stripe'], false, "payment gateway 'stripe' int 0 coerces to bool false" );
	faz_assert_same( $gw_sanitized['script_blocking']['payment_gateways']['amazon_pay'], true, "payment gateway 'amazon_pay' string 'yes' coerces to bool true" );
	faz_assert_same( Settings::sanitize_option( 'payment_gateways', array( 'paypal' => 'false' ) )['paypal'], false, "payment gateway string 'false' coerces to bool false" );
	faz_assert_same( $gw_sanitized['script_blocking']['payment_gateways']['braintree'], false, 'unset payment gateway defaults to false' );
	faz_assert_same( array_key_exists( 'evilkey', $gw_sanitized['script_blocking']['payment_gateways'] ), false, 'unknown payment-gateway key is dropped (injection-safe)' );

	$sanitized_defaults = Settings::sanitize( array(), $defaults );
	faz_assert_same(
		$sanitized_defaults['script_blocking']['payment_gateways']['paypal'],
		false,
		'payment gateways default off (compliant: no SDK before consent)'
	);
	faz_assert_same(
		$sanitized_defaults['script_blocking']['aggressive_css_url_blocking'],
		false,
		'aggressive_css_url_blocking defaults off'
	);

	$cookie_dependency = Settings::sanitize(
		array(
			'banner_control' => array(
				'per_service_consent' => false,
				'per_cookie_consent'  => true,
			),
		),
		$defaults
	);
	faz_assert_same( $cookie_dependency['banner_control']['per_service_consent'], false, 'per-service consent stays off when the admin switches it off' );
	faz_assert_same( $cookie_dependency['banner_control']['per_cookie_consent'], false, 'per-cookie consent is dropped with its required per-service layer' );

	// The case the settings screen actually produced before the toggle was
	// gated: per-cookie ticked ALONE, with per_service_consent absent from the
	// payload rather than explicitly false. It has to fall back to the default
	// (off) and take per-cookie down with it — otherwise the admin is told
	// "Settings saved successfully." for a value the server never stored. The
	// UI half of this is admin/views/settings.php's data-show-if wrapper,
	// covered by tests/unit/js/settings-per-cookie-gate.test.mjs.
	$cookie_alone = Settings::sanitize(
		array(
			'banner_control' => array(
				'per_cookie_consent' => true,
			),
		),
		$defaults
	);
	faz_assert_same( $cookie_alone['banner_control']['per_service_consent'], false, 'per-service consent stays at its default when the payload omits it' );
	faz_assert_same( $cookie_alone['banner_control']['per_cookie_consent'], false, 'per-cookie consent ticked alone cannot enable itself' );

	// The retained direction: with the parent on, the child is stored as asked.
	// Guards against "fixing" the invariant into an unconditional clear, which
	// would make per-cookie consent impossible to switch on at all.
	$cookie_retained = Settings::sanitize(
		array(
			'banner_control' => array(
				'per_service_consent' => true,
				'per_cookie_consent'  => true,
			),
		),
		$defaults
	);
	faz_assert_same( $cookie_retained['banner_control']['per_cookie_consent'], true, 'per-cookie consent is retained when per-service consent is on' );

	$geo_cache = Settings::sanitize(
		array(
			'banner_control' => array( 'cache_compatibility' => true ),
			'geolocation'    => array( 'geo_targeting' => true ),
		),
		$defaults
	);
	faz_assert_same( $geo_cache['banner_control']['cache_compatibility'], true, 'cache mode survives a save while geo-targeting is on (frontend neutralises geo instead)' );

	$iab_cache = Settings::sanitize(
		array(
			'banner_control' => array( 'cache_compatibility' => true ),
			'iab'            => array( 'enabled' => true ),
		),
		$defaults
	);
	faz_assert_same( $iab_cache['banner_control']['cache_compatibility'], true, 'cache mode survives a save while IAB TCF is on (frontend forces the conservative gdpr_applies instead)' );

	$bounded = Settings::sanitize(
		array(
			'geolocation' => array( 'target_regions' => array( 'EU', 'mars', 'za', 'ZA', '<script>' ) ),
			'scanner'     => array( 'max_pages' => 999999 ),
		),
		$defaults
	);
	faz_assert_same( $bounded['geolocation']['target_regions'], array( 'eu', 'za' ), 'target regions are normalized, deduplicated and constrained to the runtime catalogue' );
	faz_assert_same( $bounded['scanner']['max_pages'], 2000, 'scanner settings clamp a crafted oversized crawl depth' );

	$minimum_scan = Settings::sanitize(
		array( 'scanner' => array( 'max_pages' => -50 ) ),
		$defaults
	);
	faz_assert_same( $minimum_scan['scanner']['max_pages'], 1, 'scanner settings clamp negative crawl depth to the minimum instead of turning it positive' );

	// The WRITE path must agree with the read path. It used the general coercion,
	// whose negatives are enumerated, so 'garbage' persisted as an ENABLED
	// gateway — a stored value that meant one thing on save and another on
	// render is worse than either answer alone.
	$gw_defaults = array( 'script_blocking' => array( 'payment_gateways' => array( 'stripe' => false, 'paypal' => false ) ) );
	foreach ( array( 'garbage', 'maybe', '', 'no', 'off', 1.5, array( 'x' ) ) as $bad ) {
		$out = Settings::sanitize( array( 'script_blocking' => array( 'payment_gateways' => array( 'stripe' => $bad ) ) ), $gw_defaults );
		faz_assert_same( $out['script_blocking']['payment_gateways']['stripe'], false, 'write path refuses ' . var_export( $bad, true ) );
	}
	foreach ( array( true, 1, '1', 'yes', 'true', 'on' ) as $good ) {
		$out = Settings::sanitize( array( 'script_blocking' => array( 'payment_gateways' => array( 'stripe' => $good ) ) ), $gw_defaults );
		faz_assert_same( $out['script_blocking']['payment_gateways']['stripe'], true, 'write path accepts ' . var_export( $good, true ) );
	}


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
