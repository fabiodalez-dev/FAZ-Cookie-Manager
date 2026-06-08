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
