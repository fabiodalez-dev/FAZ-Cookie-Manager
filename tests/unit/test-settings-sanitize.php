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

	function faz_sanitize_bool( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

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
