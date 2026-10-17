<?php
/**
 * Standalone unit tests: the client-side provider list must keep an explicit
 * admin / developer blocking rule under the gateway exemption, exactly as
 * the server map does.
 *
 * Subsystem: client-provider-explicit-rules
 *
 * Two layers enforce blocking: the server map (get_provider_category_map(),
 * output buffer + script_loader_tag) and the client list shipped to script.js
 * as `_providersToBlock` (get_store_data(), for dynamically injected
 * resources). Both remove the always-allowed payment-gateway patterns, and the
 * server map has carved explicit rules out of that removal since c7f1c58f /
 * f8575f01: "admin intent wins", because gateway_pattern_matches() is a
 * bidirectional substring test and a generic rule such as `payment` or
 * `klarna` must not be silently exempted for being a substring of
 * `stripe-payment` or `x.klarnacdn.net`.
 *
 * The client list never got that carve-out. With Klarna opted in, an admin
 * rule `klarna` -> marketing (the only gate for Klarna On-Site Messaging,
 * which is not in the provider database) was kept on the server — a
 * server-rendered js.klarna.com tag was re-typed — and dropped on the client,
 * so the same script injected dynamically after load ran before consent on
 * every page while the Script Blocking screen showed the rule as active.
 *
 * The exemption now lives in apply_client_provider_exemptions(), which skips
 * explicit rules, and — because the server releases the gateway's OWN urls
 * through its whitelist rather than through the map — hands back the gateway
 * tokens for the client whitelist whenever an explicit rule overlapped one, so
 * both layers resolve a gateway-hosted url the same way (allowed) and a
 * differently-hosted script under the explicit rule the same way (blocked).
 *
 * Run: php tests/unit/test-client-provider-explicit-rules-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	// Double for the `use FazCookie\Includes\Known_Providers` alias inside
	// class-frontend.php — no autoload, no JSON read.
	class Known_Providers {
		public static function get_all() {
			return array();
		}
		public static function get_cookie_map() {
			return array();
		}
		public static function get_pattern_map() {
			return array();
		}
	}
}

namespace {

	// ---------- Bootstrap ----------

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	$GLOBALS['__faz_on_checkout'] = false;

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $str ) {
			return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			return trim( wp_strip_all_tags( preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) ) );
		}
	}
	// WooCommerce presence + checkout conditionals, toggled per case so the
	// checkout/cart branch of the exemption is reachable.
	class WooCommerce {}
	function is_checkout() {
		return (bool) $GLOBALS['__faz_on_checkout'];
	}
	function is_cart() {
		return false;
	}
	function has_block( $name ) {
		return false;
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	// ---------- assert helpers ----------

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;

	function ok( $cond, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $cond ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m " . $label . "\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m " . $label . "\n";
		}
	}
	function eq( $actual, $expected, $label ) {
		ok( $actual === $expected, $label );
		if ( $actual !== $expected ) {
			echo '      expected: ' . var_export( $expected, true ) . "\n";
			echo '      actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	// ---------- reflection harness ----------

	$klarna_tokens = array( 'x.klarnacdn.net', 'klarna-payments', 'klarna-checkout' );

	/** A Frontend whose always-allowed gateway set is the given token list. */
	function faz_client_frontend( array $always_allowed ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		foreach ( array(
			'always_allowed_cache'  => $always_allowed,
			'settings_option_cache' => array(),
		) as $prop => $value ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $fe, $value );
		}
		return $fe;
	}

	$has_helper = method_exists( Frontend::class, 'apply_client_provider_exemptions' );

	/**
	 * @return array{providers: array, whitelist: array}|null null when the
	 *         carve-out does not exist yet (the state this test was written
	 *         against: the exemption loop inlined in get_store_data() with no
	 *         notion of an explicit rule).
	 */
	function faz_client_exempt( $fe, array $providers, array $explicit ) {
		if ( ! method_exists( Frontend::class, 'apply_client_provider_exemptions' ) ) {
			return null;
		}
		$m = new ReflectionMethod( Frontend::class, 'apply_client_provider_exemptions' );
		$m->setAccessible( true );
		return $m->invoke( $fe, $providers, $explicit );
	}

	echo "client provider list — explicit rules survive the gateway exemption\n";
	ok( $has_helper, 'get_store_data() routes the client list through apply_client_provider_exemptions() (an explicit-rule-aware exemption)' );

	// =====================================================================
	// A. Klarna opted in, admin rule `klarna` -> marketing (the reported case)
	// =====================================================================
	$providers = array(
		'klarna'               => array( 'marketing' ),
		'x.klarnacdn.net'      => array( 'marketing' ),
		'connect.facebook.net' => array( 'marketing' ),
	);
	$res = faz_client_exempt( faz_client_frontend( $klarna_tokens ), $providers, array( 'klarna' => true ) );
	eq( null === $res ? null : array_keys( $res['providers'] ), array( 'klarna', 'connect.facebook.net' ), 'the explicit `klarna` rule is kept on the client list; the built-in gateway pattern is still removed' );
	ok( null !== $res && in_array( 'x.klarnacdn.net', $res['whitelist'], true ) && in_array( 'klarna-payments', $res['whitelist'], true ), "the gateway's own tokens travel to the client whitelist, mirroring the server whitelist" );

	// =====================================================================
	// B. Same list with no explicit rule: unchanged built-in behaviour
	// =====================================================================
	$res = faz_client_exempt( faz_client_frontend( $klarna_tokens ), $providers, array() );
	eq( null === $res ? null : array_keys( $res['providers'] ), array( 'connect.facebook.net' ), 'without an explicit rule every gateway-overlapping pattern is removed as before' );
	eq( null === $res ? null : $res['whitelist'], array(), 'and no whitelist tokens are added, so the payload of an untouched install is byte-identical' );

	// =====================================================================
	// C. An explicit rule with no gateway overlap: kept, no whitelist noise
	// =====================================================================
	$res = faz_client_exempt( faz_client_frontend( $klarna_tokens ), array( 'fbq(' => array( 'marketing' ), 'klarna-payments' => array( 'marketing' ) ), array( 'fbq(' => true ) );
	eq( null === $res ? null : array_keys( $res['providers'] ), array( 'fbq(' ), 'a non-overlapping explicit rule is kept and the built-in gateway pattern dropped' );
	eq( null === $res ? null : $res['whitelist'], array(), 'no overlap, no extra whitelist tokens' );

	// =====================================================================
	// D. A generic explicit rule that is a substring of a gateway token
	// =====================================================================
	$res = faz_client_exempt( faz_client_frontend( array( 'js.stripe.com', 'stripe-payment' ) ), array( 'payment' => array( 'marketing' ), 'js.stripe.com' => array( 'marketing' ) ), array( 'payment' => true ) );
	eq( null === $res ? null : array_keys( $res['providers'] ), array( 'payment' ), '`payment` (substring of `stripe-payment`) is kept because the admin wrote it' );

	// =====================================================================
	// E. WooCommerce checkout: the gateway-whitelist pass has the same carve-out
	// =====================================================================
	$GLOBALS['__faz_on_checkout'] = true;
	$res = faz_client_exempt(
		faz_client_frontend( array() ),
		array(
			'ppcp-gateway-tracker' => array( 'marketing' ),
			'wc-ppcp-gateway'      => array( 'marketing' ),
		),
		array( 'ppcp-gateway-tracker' => true )
	);
	eq( null === $res ? null : array_keys( $res['providers'] ), array( 'ppcp-gateway-tracker' ), 'on checkout the explicit rule survives while the built-in gateway pattern is removed' );
	ok( null !== $res && in_array( 'ppcp-gateway', $res['whitelist'], true ), 'and the checkout gateway whitelist is handed to the client' );
	$GLOBALS['__faz_on_checkout'] = false;

	// =====================================================================
	// F. Wiring: get_store_data() computes the explicit set like the server map
	// =====================================================================
	echo "\nget_store_data() wiring\n";
	$src   = file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
	$start = strpos( $src, 'public function get_store_data()' );
	$end   = strpos( $src, 'return $store;', $start );
	$body  = false === $start || false === $end ? '' : substr( $src, $start, $end - $start );
	ok( false !== strpos( $body, 'apply_client_provider_exemptions(' ), 'get_store_data() calls the shared exemption' );
	ok( false !== strpos( $body, "apply_filters( 'faz_blocking_rules_client'" ) && 1 === preg_match( '/\$pre_filter_client\s*=\s*\$this->providers;\s*\n\s*\$this->providers\s*=\s*apply_filters\( \'faz_blocking_rules_client\'/', $body ), 'the list is snapshotted before faz_blocking_rules_client so filter-added rules count as explicit' );
	ok( false !== strpos( $body, "\$store['_userWhitelist']" ) && 1 === preg_match( '/_userWhitelist\'\]\s*=\s*array_values\(\s*array_unique\(\s*array_merge\(\s*\$user_whitelist,\s*\$client_gateway_whitelist/', $body ), 'the returned gateway tokens are merged into the client whitelist' );

	// ---------- summary ----------
	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "\033[32mALL PASS\033[0m — {$tests_passed}/{$tests_run}\n";
		exit( 0 );
	}
	echo "\033[31m{$tests_failed} FAILED\033[0m — {$tests_passed}/{$tests_run} passed\n";
	exit( 1 );
}
