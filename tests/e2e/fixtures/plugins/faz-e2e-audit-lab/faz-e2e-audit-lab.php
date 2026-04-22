<?php
/**
 * Plugin Name: FAZ E2E Audit Lab
 * Description: Request-scoped probes for PR audit regression coverage.
 * Version: 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Faz_E2E_Audit_Lab {
	/**
	 * Number of get_option( 'faz_settings' ) reads observed on the request.
	 *
	 * @var int
	 */
	private $settings_reads = 0;

	/**
	 * Number of SELECT queries touching faz_cookies observed on the request.
	 *
	 * @var int
	 */
	private $cookie_select_queries = 0;

	/**
	 * Optional probe key used to persist request metrics for later inspection.
	 *
	 * @var string
	 */
	private $probe_key = '';

	/**
	 * Boot hooks.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'bootstrap' ), 0 );
	}

	/**
	 * Register request-scoped hooks only when a probe is requested.
	 *
	 * @return void
	 */
	public function bootstrap() {
		if ( isset( $_GET['faz_e2e_cf_country'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_SERVER['HTTP_CF_IPCOUNTRY'] = strtoupper( sanitize_text_field( wp_unslash( $_GET['faz_e2e_cf_country'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( isset( $_GET['faz_e2e_forwarded_ip'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_SERVER['HTTP_X_FORWARDED_FOR'] = sanitize_text_field( wp_unslash( $_GET['faz_e2e_forwarded_ip'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( isset( $_GET['faz_e2e_trust_proxy'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_filter( 'faz_trust_proxy_headers', '__return_true' );
		}

		if ( isset( $_GET['faz_e2e_trust_cf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_filter( 'faz_trust_cf_ipcountry_header', '__return_true' );
		}

		if ( isset( $_GET['faz_e2e_audit_headers'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->probe_key = isset( $_GET['faz_e2e_probe_key'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				? sanitize_key( wp_unslash( $_GET['faz_e2e_probe_key'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				: '';
			add_filter( 'query', array( $this, 'count_settings_reads' ) );
			add_filter( 'query', array( $this, 'count_cookie_queries' ) );
			add_action( 'shutdown', array( $this, 'store_probe_result' ), 0 );
			add_action( 'shutdown', array( $this, 'emit_probe_headers' ), 0 );
		}

		if ( isset( $_GET['faz_e2e_geo_probe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_action( 'template_redirect', array( $this, 'serve_geo_probe' ), 0 );
		}
	}

	/**
	 * Count SQL reads for the faz_settings option.
	 *
	 * @param string $query SQL query.
	 * @return string
	 */
	public function count_settings_reads( $query ) {
		if ( is_string( $query )
			&& preg_match( '/^\s*SELECT\b/i', $query )
			&& false !== stripos( $query, 'option_name' )
			&& false !== stripos( $query, 'faz_settings' )
		) {
			$this->settings_reads++;
		}

		return $query;
	}

	/**
	 * Count SELECT queries touching the faz_cookies table.
	 *
	 * @param string $query SQL query.
	 * @return string
	 */
	public function count_cookie_queries( $query ) {
		if ( is_string( $query )
			&& preg_match( '/^\s*SELECT\b/i', $query )
			&& false !== stripos( $query, 'faz_cookies' )
		) {
			$this->cookie_select_queries++;
		}

		return $query;
	}

	/**
	 * Emit request metrics as HTTP headers for Playwright assertions.
	 *
	 * @return void
	 */
	public function emit_probe_headers() {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-FAZ-E2E-Settings-Reads: ' . (string) $this->settings_reads );
		header( 'X-FAZ-E2E-Cookie-Queries: ' . (string) $this->cookie_select_queries );
	}

	/**
	 * Persist request metrics to an option so tests can read them even if
	 * response headers are sent too late by the server/runtime.
	 *
	 * @return void
	 */
	public function store_probe_result() {
		if ( '' === $this->probe_key ) {
			return;
		}

		update_option(
			'faz_e2e_audit_probe_' . $this->probe_key,
			array(
				'settings_reads'  => (int) $this->settings_reads,
				'cookie_queries'  => (int) $this->cookie_select_queries,
				'captured_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);
	}

	/**
	 * Return the detected geolocation result for the current request.
	 *
	 * @return void
	 */
	public function serve_geo_probe() {
		if ( is_admin() ) {
			return;
		}

		if ( ! class_exists( '\FazCookie\Includes\Geolocation' ) ) {
			wp_send_json(
				array(
					'country' => '',
					'is_eu'   => false,
				)
			);
		}

		$country = \FazCookie\Includes\Geolocation::get_country();
		$is_eu   = \FazCookie\Includes\Geolocation::is_eu();

		wp_send_json(
			array(
				'country' => $country,
				'is_eu'   => (bool) $is_eu,
			)
		);
	}
}

new Faz_E2E_Audit_Lab();
