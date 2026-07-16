<?php
/**
 * Plugin Name: FAZ E2E FlyingPress Probe
 * Description: Test-only. Exposes FlyingPress's per-request runtime delay-exclude
 *              config (the array the FAZ reflection bridge injects into) and the
 *              persisted FLYING_PRESS_CONFIG value as response headers, so the E2E
 *              suite can observe the in-memory injection that a separate wp-cli
 *              process cannot see. Never shipped — lives under tests/e2e/fixtures.
 *
 * @package FazCookie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'send_headers',
	static function () {
		if ( is_admin() ) {
			return;
		}
		if ( ! class_exists( '\FlyingPress\Config' ) ) {
			return;
		}
		// Runtime (in-memory) delay excludes — what the FAZ bridge injects.
		$runtime = isset( \FlyingPress\Config::$config['js_delay_excludes'] )
			? \FlyingPress\Config::$config['js_delay_excludes']
			: null;
		header( 'X-Faz-Fp-Runtime-Excludes: ' . wp_json_encode( $runtime ) );

		// Persisted option — must stay untouched by the runtime bridge.
		$stored = get_option( 'FLYING_PRESS_CONFIG' );
		$stored = ( is_array( $stored ) && isset( $stored['js_delay_excludes'] ) )
			? $stored['js_delay_excludes']
			: null;
		header( 'X-Faz-Fp-Stored-Excludes: ' . wp_json_encode( $stored ) );
	},
	99999
);
