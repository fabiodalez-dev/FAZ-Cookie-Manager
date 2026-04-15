<?php
/**
 * Plugin Name: FAZ E2E PMP Mock
 * Description: Minimal mock of Paid Memberships Pro used only for end-to-end
 *   tests of the FAZ PMP integration. Exposes the two surface APIs the FAZ
 *   integration probes — `PMPRO_VERSION` constant and the
 *   `pmpro_hasMembershipLevel()` function — and uses an option
 *   (`faz_e2e_pmp_mock_levels`) to decide which level IDs the current user
 *   owns. Never use on a production site.
 * Version: 0.1.0
 *
 * @package FazCookieE2E
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PMPRO_VERSION' ) ) {
	define( 'PMPRO_VERSION', '3.0-mock' );
}

if ( ! function_exists( 'pmpro_hasMembershipLevel' ) ) {
	/**
	 * Mock of `pmpro_hasMembershipLevel( $levels, $user_id )`.
	 *
	 * The real PMP signature accepts a single integer, an array, or null.
	 * Return true when the current user's mocked levels intersect with any
	 * of the requested IDs. The mocked levels are read from the
	 * `faz_e2e_pmp_mock_levels` option (comma-separated IDs). When the
	 * option is empty the mock returns false, mirroring a user with no
	 * memberships.
	 *
	 * @param int|array $levels  Level ID, array of IDs, or null.
	 * @param int       $user_id User ID (defaults to current user).
	 * @return bool
	 */
	function pmpro_hasMembershipLevel( $levels = null, $user_id = null ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		$requested = array();
		if ( is_array( $levels ) ) {
			$requested = array_map( 'absint', $levels );
		} elseif ( null !== $levels ) {
			$requested = array( absint( $levels ) );
		}

		$raw  = (string) get_option( 'faz_e2e_pmp_mock_levels', '' );
		$mine = array_values( array_filter( array_map( 'absint', array_map( 'trim', explode( ',', $raw ) ) ) ) );

		if ( empty( $mine ) ) {
			return false;
		}

		// At this point $mine is non-empty; mirror PMP's real behaviour of
		// "user has any level" when the caller doesn't constrain the set.
		if ( empty( $requested ) ) {
			return true;
		}

		return count( array_intersect( $mine, $requested ) ) > 0;
	}
}
