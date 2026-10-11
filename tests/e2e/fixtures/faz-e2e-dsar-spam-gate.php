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
