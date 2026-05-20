<?php
/**
 * Class Ipinfo_Client file — VPN/proxy detection via ipinfo.io.
 *
 * Spec: specs/001-geo-routing-next/spec.md FR-02 + contracts/ipinfo-api.md
 * Task: T019 (P3 Pipeline)
 *
 * Contract (see contracts/ipinfo-api.md):
 *   - Opt-in gated (`faz_geo_ipinfo_optin` + `faz_geo_ipinfo_api_key` required)
 *   - 24h cache per IP-hash (NFR-03 — hash with monthly salt, never cleartext IP)
 *   - 3s timeout + graceful degrade to `{vpn: null}` on any error
 *   - Returns `{vpn: bool|null, source: 'cache'|'ipinfo'|'skip'|'error'}`
 *
 * Constitution VIII Data Minimization — cache key is IP hash, not IP.
 * Constitution IX Cross-Border — Admin must attest DPF/SCC before opt-in.
 *
 * @package FazCookie\Admin\Modules\Geo_Routing\Includes
 * @since   1.15.0
 */

namespace FazCookie\Admin\Modules\Geo_Routing\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ipinfo.io VPN/proxy detection client.
 *
 * @class    Ipinfo_Client
 * @since    1.15.0
 */
class Ipinfo_Client {

	const CACHE_GROUP    = 'faz_geo_ipinfo';
	const CACHE_TTL      = DAY_IN_SECONDS;
	const REQUEST_TIMEOUT = 3;

	/**
	 * Lookup VPN/proxy status for an IP address.
	 *
	 * @param string $ip Visitor IP (cleartext — needed for ipinfo lookup;
	 *                   hashed before cache write; never logged).
	 * @return array{vpn: bool|null, source: string}
	 */
	public function lookup( $ip ) {
		if ( ! is_string( $ip ) || '' === $ip ) {
			return array( 'vpn' => null, 'source' => 'skip' );
		}

		// L2-SP1-S005 / L1-SP1-S001 fix (1.15.0): opt-in gate MUST run
		// BEFORE the cache read. Otherwise, after an admin revokes
		// opt-in, persistent caches (Redis/Memcached) continue serving
		// previously-cached VPN classifications for up to 24h, violating
		// Constitution VIII/IX (cross-border data processing must stop
		// immediately on opt-in revocation). On a default WP install
		// without persistent cache the gap is per-request only, but the
		// contract violation in the code is the same regardless.
		if ( ! $this->is_optin_active() ) {
			return array( 'vpn' => null, 'source' => 'skip' );
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return array( 'vpn' => null, 'source' => 'skip' );
		}

		// Hash IP for cache key — Constitution VIII / NFR-03.
		$ip_hash = $this->hash_ip( $ip );

		// Cache hit (after opt-in confirmed)?
		$cached = wp_cache_get( $ip_hash, self::CACHE_GROUP );
		if ( false !== $cached && is_array( $cached ) ) {
			return array( 'vpn' => $cached['vpn'], 'source' => 'cache' );
		}

		// Make the call.
		$result = $this->http_lookup( $ip, $api_key );
		if ( null === $result['vpn'] ) {
			// Error — do NOT cache (preserve retry next request).
			return $result;
		}

		// Cache success.
		wp_cache_set( $ip_hash, array( 'vpn' => $result['vpn'] ), self::CACHE_GROUP, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Whether ipinfo opt-in is active (admin has explicitly enabled).
	 *
	 * @return bool
	 */
	public function is_optin_active() {
		return (bool) get_option( 'faz_geo_ipinfo_optin', false );
	}

	/**
	 * Get the decrypted API key.
	 *
	 * @return string Empty if not configured.
	 */
	public function get_api_key() {
		$enc = get_option( 'faz_geo_ipinfo_api_key', '' );
		if ( '' === $enc ) {
			return '';
		}
		return Secrets::decrypt( $enc );
	}

	/**
	 * Hash an IP address with monthly-rotating salt.
	 *
	 * @param string $ip Cleartext IP.
	 * @return string 64-char hex.
	 */
	private function hash_ip( $ip ) {
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'nonce' ) : 'faz-fallback';
		$month = gmdate( 'Y-m' );
		return hash( 'sha256', $ip . '|' . $month . '|' . $salt );
	}

	/**
	 * Issue the HTTP GET to ipinfo.io.
	 *
	 * @param string $ip      Cleartext IP.
	 * @param string $api_key API key.
	 * @return array{vpn: bool|null, source: string}
	 */
	private function http_lookup( $ip, $api_key ) {
		$url = sprintf( 'https://ipinfo.io/%s/privacy?token=%s', rawurlencode( $ip ), rawurlencode( $api_key ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 0,
				'user-agent'  => 'FAZ-Cookie-Manager/' . ( defined( 'FAZ_VERSION' ) ? FAZ_VERSION : '0.0.0' ) . ' (+https://wordpress.org/plugins/faz-cookie-manager)',
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_failure( 'wp_error', $response->get_error_message() );
			return array( 'vpn' => null, 'source' => 'error' );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			$this->log_failure( "http_$code", '' );
			return array( 'vpn' => null, 'source' => 'error' );
		}

		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		// ipinfo.io /privacy (Privacy Standard API) returns flags at the ROOT
		// of the JSON object, NOT nested under a `privacy` key. The previous
		// implementation looked for $json['privacy'] which only exists in
		// the Core/Plus API tiers (under an `anonymous` object there, not
		// `privacy`), so every Standard-tier lookup parsed as failure and
		// the VPN gate effectively never engaged. Reference:
		// https://ipinfo.io/developers/privacy-standard-api — fields:
		//   { vpn, proxy, tor, relay, hosting, service }
		// where service is a string and the four anonymity flags are bool.
		// Per contracts/ipinfo-api.md §1.4 — vpn/proxy/tor/relay trigger the
		// gate; hosting alone is NOT a trigger (legitimate data centers).
		if ( ! is_array( $json ) ) {
			$this->log_failure( 'parse', 'json_not_array' );
			return array( 'vpn' => null, 'source' => 'error' );
		}
		$has_any_flag = isset( $json['vpn'] ) || isset( $json['proxy'] ) || isset( $json['tor'] ) || isset( $json['relay'] );
		if ( ! $has_any_flag ) {
			$this->log_failure( 'parse', 'no_anonymity_flags' );
			return array( 'vpn' => null, 'source' => 'error' );
		}

		$vpn = ! empty( $json['vpn'] ) || ! empty( $json['proxy'] ) || ! empty( $json['tor'] ) || ! empty( $json['relay'] );

		return array( 'vpn' => (bool) $vpn, 'source' => 'ipinfo' );
	}

	/**
	 * Log a failure to error_log (admin-visible via debug.log if enabled).
	 *
	 * @param string $reason Short failure tag.
	 * @param string $detail Optional detail.
	 * @return void
	 */
	private function log_failure( $reason, $detail = '' ) {
		if ( function_exists( 'error_log' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[FAZ Cookie Manager] ipinfo lookup failed (%s): %s', $reason, $detail ) );
		}
	}
}
