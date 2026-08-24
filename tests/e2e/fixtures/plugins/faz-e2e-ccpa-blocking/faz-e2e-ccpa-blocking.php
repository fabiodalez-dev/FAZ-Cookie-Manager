<?php
/**
 * Plugin Name: FAZ E2E — CCPA Blocking Probe
 * Description: Prints a known-provider (Google Analytics) script in the footer so
 *              an e2e test can assert the plugin's server-side script blocking is
 *              law-aware (opt-out CCPA must NOT block on first visit; GDPR must).
 * Version: 1.0.0
 *
 * @package FazCookieE2E
 */

defined( 'ABSPATH' ) || exit;

$faz_e2e_country = isset( $_SERVER['HTTP_X_FAZ_E2E_COUNTRY'] )
	? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $_SERVER['HTTP_X_FAZ_E2E_COUNTRY'] ) )
	: '';
$faz_e2e_region  = isset( $_SERVER['HTTP_X_FAZ_E2E_REGION'] )
	? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $_SERVER['HTTP_X_FAZ_E2E_REGION'] ) )
	: '';
if ( $faz_e2e_country ) {
	add_filter(
		'faz_visitor_country',
		static function () use ( $faz_e2e_country ) {
			return $faz_e2e_country;
		},
		PHP_INT_MAX
	);
	add_filter(
		'faz_visitor_region',
		static function () use ( $faz_e2e_country, $faz_e2e_region ) {
			return $faz_e2e_region ? $faz_e2e_country . '-' . $faz_e2e_region : '';
		},
		PHP_INT_MAX
	);
}

add_action(
	'wp_footer',
	function () {
		// google-analytics.com is in the plugin's built-in provider map under
		// the "analytics" category, so process_output_buffer() will rewrite this
		// tag to type="text/plain" data-faz-category="analytics" whenever the
		// active banner blocks the analytics category server-side.
		echo '<script id="faz-e2e-ga-probe" src="https://www.google-analytics.com/analytics.js"></script>' . "\n";
	},
	1
);
