<?php
/**
 * Reusable compliance regression matrix for administrator settings.
 *
 * Keep this suite self-contained so it can run in CI without WordPress. Every
 * assertion protects a setting that can affect consent validity, data
 * minimisation, prior blocking or safe cross-site propagation.
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

	// The real coercion, not a copy of it. A local stub duplicating the accepted
	// negatives means this matrix asserts against its own idea of the rule:
	// simplify the shipped function to a bare cast and production regresses to
	// the very bug this suite exists for while it still prints all-green.
	require_once __DIR__ . '/../../includes/class-formatting.php';
	function faz_sanitize_text( $value ) {
		return is_array( $value ) ? array_map( 'faz_sanitize_text', $value ) : ( is_scalar( $value ) ? sanitize_text_field( $value ) : $value );
	}
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
	function sanitize_title( $value ) {
		$value = strtolower( sanitize_text_field( $value ) );
		return trim( preg_replace( '/[^a-z0-9]+/', '-', $value ), '-' );
	}
	function absint( $value ) {
		return abs( (int) $value );
	}
	function esc_url_raw( $value ) {
		return filter_var( $value, FILTER_VALIDATE_URL ) ? $value : '';
	}
	function wp_parse_url( $value, $component = -1 ) {
		return parse_url( $value, $component );
	}
	function get_option( $key, $default = false ) {
		return $default;
	}

	require_once __DIR__ . '/../../admin/modules/settings/includes/class-settings.php';

	use FazCookie\Admin\Modules\Settings\Includes\Settings;

	$run = 0;
	$failed = 0;
	function compliance_same( $actual, $expected, $label ) {
		global $run, $failed;
		$run++;
		if ( $actual === $expected ) {
			echo "  \033[32m✓\033[0m {$label}\n";
			return;
		}
		$failed++;
		echo "  \033[31m✗\033[0m {$label}\n";
		echo '      expected: ' . var_export( $expected, true ) . "\n";
		echo '      actual:   ' . var_export( $actual, true ) . "\n";
	}

	echo "\n== Settings compliance matrix (56 reusable checks) ==\n\n";

	// 1-10: consent-affecting flags must become strict booleans.
	$boolean_cases = array(
		array( 'status', 'false', false, 'banner/log status rejects string false' ),
		array( 'status', '1', true, 'banner/log status accepts string one' ),
		array( 'per_service_consent', '0', false, 'per-service consent rejects string zero' ),
		array( 'per_service_consent', 'yes', true, 'per-service consent accepts explicit yes' ),
		array( 'per_cookie_consent', '', false, 'per-cookie consent rejects empty value' ),
		array( 'per_cookie_consent', true, true, 'per-cookie consent preserves boolean true' ),
		array( 'geo_targeting', 'false', false, 'geo-targeting rejects string false' ),
		array( 'cache_compatibility', '0', false, 'cache mode rejects string zero' ),
		array( 'enabled', 0, false, 'generic integration enabled flag rejects integer zero' ),
		array( 'pageview_tracking', 1, true, 'pageview tracking accepts integer one' ),
	);
	foreach ( $boolean_cases as $case ) {
		compliance_same( Settings::sanitize_option( $case[0], $case[1] ), $case[2], $case[3] );
	}

	// 11-22: bounded retention, age, scan-budget and identifier values.
	compliance_same( Settings::sanitize_option( 'retention', 0 ), 1, 'retention cannot be below one month' );
	compliance_same( Settings::sanitize_option( 'retention', 999 ), 120, 'retention cannot exceed 120 months' );
	compliance_same( Settings::sanitize_option( 'retention', 12 ), 12, 'normal retention is preserved' );
	compliance_same( Settings::sanitize_option( 'min_age', 5 ), 13, 'age threshold cannot be below 13' );
	compliance_same( Settings::sanitize_option( 'min_age', 99 ), 18, 'age threshold cannot exceed 18' );
	compliance_same( Settings::sanitize_option( 'cmp_id', -4 ), 4, 'CMP identifier is normalized to a non-negative integer' );
	compliance_same( Settings::sanitize_option( 'cmp_id', 9999 ), 4095, 'CMP identifier respects the TCF upper bound' );
	compliance_same( Settings::sanitize_option( 'consent_revision', 0 ), 1, 'consent revision cannot be zero' );
	// The scheduled-scan cron and WP-CLI honour the STORED page budget without a
	// runtime cap of their own, so the write path must enforce the same [1, 2000]
	// window as the interactive scan endpoint. A stored 0 (absint of any
	// non-numeric string) made every auto-scan a silent no-op while the admin
	// believed scanning was on.
	compliance_same( Settings::sanitize_option( 'max_pages', 0 ), 1, 'zero scan budget cannot silently disable the scheduled scan' );
	compliance_same( Settings::sanitize_option( 'max_pages', 'garbage' ), 1, 'non-numeric scan budget falls to the floor, not to zero' );
	compliance_same( Settings::sanitize_option( 'max_pages', 999999 ), 2000, 'stored scan budget respects the interactive scan cap' );
	compliance_same( Settings::sanitize_option( 'max_pages', 100 ), 100, 'normal scan budget is preserved' );

	// 23-31: enums and identifiers are allowlisted.
	compliance_same( Settings::sanitize_option( 'scan_frequency', 'daily' ), 'daily', 'daily scan frequency is accepted' );
	compliance_same( Settings::sanitize_option( 'scan_frequency', 'hourly' ), 'weekly', 'unknown scan frequency falls back safely' );
	compliance_same( Settings::sanitize_option( 'geolite2_edition', 'city' ), 'city', 'GeoLite city edition is accepted' );
	compliance_same( Settings::sanitize_option( 'geolite2_edition', '../secret' ), 'country', 'unknown GeoLite edition is rejected' );
	compliance_same( Settings::sanitize_option( 'default_behavior', 'show_banner' ), 'show_banner', 'show-banner geo fallback is accepted' );
	compliance_same( Settings::sanitize_option( 'default_behavior', 'track_anyway' ), 'show_banner', 'unsafe geo fallback becomes show-banner' );
	compliance_same( Settings::sanitize_option( 'publisher_cc', 'it' ), 'IT', 'publisher country code is normalized' );
	compliance_same( Settings::sanitize_option( 'publisher_cc', 'ITA' ), '', 'invalid publisher country code is rejected' );
	compliance_same( Settings::sanitize_option( 'law', 'malicious-law' ), '', 'unknown onboarding law is rejected' );

	// 32-38: consent forwarding accepts only HTTP(S) destinations.
	$domains = Settings::sanitize_option(
		'target_domains',
		array( 'https://shop.example.com', 'http://legacy.example.com/path', 'javascript:alert(1)', 'data:text/html,x', '', 'not-a-url' )
	);
	compliance_same( count( $domains ), 2, 'only two valid forwarding destinations survive' );
	compliance_same( $domains[0], 'https://shop.example.com', 'HTTPS forwarding destination survives' );
	compliance_same( $domains[1], 'http://legacy.example.com/path', 'HTTP forwarding destination survives' );
	compliance_same( in_array( 'javascript:alert(1)', $domains, true ), false, 'JavaScript forwarding URL is rejected' );
	compliance_same( in_array( 'data:text/html,x', $domains, true ), false, 'data URL forwarding destination is rejected' );
	compliance_same( Settings::sanitize_option( 'target_domains', 'https://example.com' ), array(), 'scalar forwarding configuration is rejected' );
	compliance_same( Settings::sanitize_option( 'target_domains', array( '' ) ), array(), 'blank forwarding destinations are removed' );

	// 39-45: gateway exemptions remain explicit and closed to unknown keys.
	$gateways = Settings::sanitize_option(
		'payment_gateways',
		array( 'paypal' => 'false', 'stripe' => '1', 'square' => 0, 'evil' => true )
	);
	compliance_same( $gateways['paypal'], false, 'string false does not exempt PayPal before consent' );
	compliance_same( $gateways['stripe'], true, 'explicit Stripe opt-in is preserved' );
	compliance_same( $gateways['square'], false, 'integer zero does not exempt Square' );
	compliance_same( $gateways['braintree'], false, 'missing gateway defaults to blocked' );
	compliance_same( array_key_exists( 'evil', $gateways ), false, 'unknown gateway cannot enter the exemption map' );
	// Compared against a written-down list, not against the same method that
	// produced the map. Reflecting into payment_gateway_keys() and asserting the
	// output equals its own return is true by construction: it stays green if the
	// catalogue is emptied, reordered, or has a gateway silently dropped — the
	// three changes worth catching. The list is short and changes rarely; when a
	// gateway is genuinely added, updating one line here is the intended cost.
	// This harness does not load Frontend, so payment_gateway_keys() resolves to
	// its own fallback list — which is exactly the list a site gets whenever the
	// frontend class is unavailable, and therefore worth pinning in its own
	// right rather than treating as a test artefact.
	compliance_same(
		array_keys( $gateways ),
		array( 'paypal', 'stripe', 'square', 'braintree', 'klarna', 'mollie', 'amazon_pay' ),
		'gateway map contains exactly the canonical catalogue (Frontend-absent fallback)'
	);
	compliance_same( Settings::sanitize_option( 'payment_gateways', 'paypal' )['paypal'], false, 'scalar gateway input enables nothing' );

	// 46-50: custom blocking rules are structurally constrained.
	$rules = Settings::sanitize_option(
		'custom_rules',
		array(
			array( 'pattern' => 'tracker.example', 'category' => 'analytics' ),
			array( 'pattern' => 'tracker.example', 'category' => 'analytics' ),
			array( 'pattern' => 'ads.example', 'category' => 'unknown' ),
			array( 'pattern' => '', 'category' => 'marketing' ),
			'bad-row',
		)
	);
	compliance_same( count( $rules ), 1, 'invalid and duplicate blocking rules are removed' );
	compliance_same( $rules[0]['category'], 'analytics', 'valid analytics category survives' );
	compliance_same( $rules[0]['pattern'], 'tracker.example', 'valid blocking pattern survives' );
	compliance_same( Settings::sanitize_option( 'custom_rules', 'bad' ), array(), 'scalar blocking rules input is rejected' );
	compliance_same( Settings::sanitize_option( 'custom_rules', array( array( 'pattern' => 'x', 'category' => 'necessary' ) ) )[0]['category'], 'necessary', 'strictly necessary rule category is supported' );

	// 51-56: cross-setting invariants are enforced for every write path.
	$defaults = array(
		'banner_control' => array(
			'per_service_consent' => false,
			'per_cookie_consent'  => false,
			'cache_compatibility' => false,
		),
		'geolocation' => array( 'geo_targeting' => false ),
		'iab'         => array( 'enabled' => false ),
	);
	// The dependency runs parent → child only. Enforcing it upwards (per-cookie
	// implying per-service) would make per-service impossible to switch off while
	// per-cookie is on, which is what the settings screen actually submits: it
	// posts the whole banner_control subtree with one flag changed.
	$svc_off = Settings::sanitize(
		array( 'banner_control' => array( 'per_service_consent' => false, 'per_cookie_consent' => true ) ),
		$defaults
	);
	compliance_same( $svc_off['banner_control']['per_service_consent'], false, 'per-service consent can be switched off even with per-cookie still set' );
	compliance_same( $svc_off['banner_control']['per_cookie_consent'], false, 'per-cookie consent is dropped when its required per-service layer is off' );
	$both_on = Settings::sanitize(
		array( 'banner_control' => array( 'per_service_consent' => true, 'per_cookie_consent' => true ) ),
		$defaults
	);
	compliance_same( $both_on['banner_control']['per_cookie_consent'], true, 'per-cookie consent survives when per-service is on' );

	// Cache Compatibility Mode combined with geo-targeting or IAB TCF must be
	// PRESERVED, not silently switched off. The frontend supports both: under
	// cache mode banner-rest and amp-consent skip the country/ruleset lookup, and
	// class-frontend forces the conservative TCF gdpr_applies=true, so the output
	// stays visitor-invariant instead of failing. Overriding the flag here would
	// revert the administrator's choice on every save — including saves that never
	// touched it — and strip the setting from installs already running the
	// combination. The admin is warned in settings.js instead.
	$geo_cache = Settings::sanitize( array( 'banner_control' => array( 'cache_compatibility' => true ), 'geolocation' => array( 'geo_targeting' => true ) ), $defaults );
	compliance_same( $geo_cache['banner_control']['cache_compatibility'], true, 'cache mode survives a save while geo-targeting is on' );
	$iab_cache = Settings::sanitize( array( 'banner_control' => array( 'cache_compatibility' => true ), 'iab' => array( 'enabled' => true ) ), $defaults );
	compliance_same( $iab_cache['banner_control']['cache_compatibility'], true, 'cache mode survives a save while IAB TCF is on' );
	$plain_cache = Settings::sanitize( array( 'banner_control' => array( 'cache_compatibility' => true ) ), $defaults );
	compliance_same( $plain_cache['banner_control']['cache_compatibility'], true, 'cache mode is preserved on its own' );

	if ( 56 !== $run ) {
		$failed++;
		echo "  \033[31m✗\033[0m suite contract expected exactly 56 checks, ran {$run}\n";
	}

	echo "\n--\nChecks: {$run}\nFailed: {$failed}\n\n";
	if ( $failed > 0 ) {
		echo "\033[31mFAIL\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mPASS — 56/56 compliance checks passed\033[0m\n";
	exit( 0 );
}
