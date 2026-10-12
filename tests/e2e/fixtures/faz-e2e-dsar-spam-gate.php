<?php
/**
 * Plugin Name: FAZ E2E — DSAR spam gate
 * Description: Test-only mu-plugin standing in for a third-party form-protection
 *   plugin (ALTCHA / OpenPorte, Turnstile, Akismet) hooking the DSAR form
 *   through `faz_dsar_pre_submit`. It refuses any submission whose message
 *   contains the word "viagra", which is the whole point under test: a protection
 *   plugin that cannot see this form cannot protect it, so the filter has to
 *   exist and its verdict has to reach the visitor. Copied into
 *   wp-content/mu-plugins/ by dsar-admin-and-spam-gate.spec.ts and removed
 *   afterwards. NEVER ship this.
 *
 * @package FazCookie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The anti-bot layers run BEFORE `faz_dsar_pre_submit`, and the first of them
// refuses anything submitted within `faz_dsar_min_dwell_seconds` (three by
// default) of the form being rendered. Playwright fills four fields and clicks
// in well under that, so without this the spec would be asserting the dwell
// refusal — "Please wait a moment…" — and never reach the filter it exists to
// test, passing or failing on how fast the machine happens to be. The dwell
// itself is covered exhaustively in tests/unit/test-dsar-antibot-php.php; here
// it is only in the way.
add_filter( 'faz_dsar_min_dwell_seconds', '__return_zero' );

add_filter(
	'faz_dsar_pre_submit',
	function ( $verdict, $context ) {
		$message = isset( $context['message'] ) ? (string) $context['message'] : '';
		if ( false !== stripos( $message, 'viagra' ) ) {
			return new WP_Error( 'faz_e2e_gate', 'Blocked by the E2E spam gate.' );
		}
		return $verdict;
	},
	10,
	2
);
