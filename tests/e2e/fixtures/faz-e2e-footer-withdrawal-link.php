<?php
/**
 * Plugin Name: FAZ E2E — Persistent footer withdrawal link
 * Description: Test-only mu-plugin. Prints the [faz_cookie_settings type="link"]
 *   shortcode on wp_footer for every front-end page, standing in for an
 *   administrator who placed a consent-preferences link in their site template.
 *   This is what Withdrawal_Path::verify() must find when it fetches the site's
 *   own pages. Copied into wp-content/mu-plugins/ by
 *   withdrawal-path-verification.spec.ts and removed afterwards — the spec also
 *   removes it mid-run, on purpose, to assert the lock comes back. NEVER ship this.
 *
 * @package FazCookie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_footer',
	function () {
		echo do_shortcode( '[faz_cookie_settings type="link" text="Cookie preferences"]' );
	},
	5
);
