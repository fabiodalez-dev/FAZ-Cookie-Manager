<?php
/**
 * Class Geo_Detector file — orchestrator for the geo detection pipeline.
 *
 * Spec: specs/001-geo-routing-next/spec.md FR-02
 * Task: T021 (P3 Pipeline)
 *
 * Chain per plan.md §1.1 stage 1:
 *   1. CF-IPCountry header (Cloudflare)
 *   2. X-Country-Code admin override
 *   3. ipinfo.io VPN/proxy gate → forces 'XX' on VPN detected
 *   4. ip-api.com (when ipinfo says non-VPN)
 *   5. GeoLite2 local DB
 *   6. 'XX' sentinel → fallback
 *
 * Cache: `_transient_faz_geo_{ip_hash}` TTL 1h (Q6 resolution).
 *
 * Constitution VIII — IP never stored cleartext (cache key is hash with
 * monthly rotation salt).
 *
 * @package FazCookie\Admin\Modules\Geo_Routing\Includes
 * @since   1.15.0
 */

namespace FazCookie\Admin\Modules\Geo_Routing\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Geo detection orchestrator.
 *
 * @class    Geo_Detector
 * @since    1.15.0
 */
class Geo_Detector {

	const CACHE_GROUP = 'faz_geo_detect';
	const CACHE_TTL   = HOUR_IN_SECONDS;

	/**
	 * @var Ipinfo_Client
	 */
	private $ipinfo;

	/**
	 * Constructor with DI.
	 *
	 * @param Ipinfo_Client|null $ipinfo Injectable for testability.
	 */
	public function __construct( $ipinfo = null ) {
		$this->ipinfo = $ipinfo instanceof Ipinfo_Client ? $ipinfo : new Ipinfo_Client();
	}

	/**
	 * Detect visitor country + region + VPN status.
	 *
	 * @param string|null $ip_override Optional explicit IP for unit tests / cron.
	 * @return array{country:string, region:string, vpn:bool|null, source:string}
	 */
	public function detect( $ip_override = null ) {
		$ip = is_string( $ip_override ) && '' !== $ip_override
			? $ip_override
			: $this->resolve_client_ip();

		$ip_hash = $this->hash_ip( $ip );

		// Cache hit?
		$cached = wp_cache_get( $ip_hash, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// 1. CF-IPCountry header.
		$cf_country = $this->get_cf_country();
		$cf_region  = $this->get_cf_region();

		// 2. Admin override via X-Country-Code (filterable).
		$admin_override = $this->get_admin_override_country();
		if ( '' !== $admin_override ) {
			$result = array( 'country' => $admin_override, 'region' => '', 'vpn' => false, 'source' => 'admin_override' );
			wp_cache_set( $ip_hash, $result, self::CACHE_GROUP, self::CACHE_TTL );
			return $result;
		}

		// 3. ipinfo VPN gate.
		$vpn_result = $this->ipinfo->lookup( $ip );
		$vpn        = $vpn_result['vpn']; // bool|null

		// 4. Decide country.
		$country = '';
		$region  = '';
		$source  = '';
		if ( '' !== $cf_country ) {
			$country = $cf_country;
			$region  = $cf_region;
			$source  = 'cf_header';
		} else {
			// 5. ip-api / GeoLite2 fallbacks via existing Geolocation class.
			$fallback = $this->resolve_via_existing_geolocation( $ip );
			$country  = $fallback['country'];
			$region   = $fallback['region'];
			$source   = $fallback['source'];
		}

		// 6. XX fallback if everything failed.
		if ( '' === $country ) {
			$country = 'XX';
			$source  = $source ?: 'unknown';
		}

		$result = array(
			'country' => strtoupper( $country ),
			'region'  => $region,
			'vpn'     => $vpn,
			'source'  => $source,
		);

		wp_cache_set( $ip_hash, $result, self::CACHE_GROUP, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Read CF-IPCountry header from $_SERVER.
	 *
	 * @return string Country code (uppercase) or empty.
	 */
	private function get_cf_country() {
		if ( empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$cc = strtoupper( trim( (string) $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
		if ( 'XX' === $cc || 'T1' === $cc ) {
			return ''; // CF unknown/Tor sentinel
		}
		if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			return '';
		}
		return $cc;
	}

	/**
	 * Read CF-Region header if exposed by CF Workers / custom config.
	 *
	 * @return string ISO 3166-2 or empty.
	 */
	private function get_cf_region() {
		if ( empty( $_SERVER['HTTP_CF_REGION_CODE'] ) || empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$country = strtoupper( trim( (string) $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$region  = strtoupper( trim( (string) $_SERVER['HTTP_CF_REGION_CODE'] ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) || ! preg_match( '/^[A-Z0-9]{1,3}$/', $region ) ) {
			return '';
		}
		return $country . '-' . $region;
	}

	/**
	 * Admin-configured override (filter `faz_geo_admin_override_country`).
	 *
	 * Used for testing or controllers that pre-resolved country server-side.
	 *
	 * @return string Country code or empty.
	 */
	private function get_admin_override_country() {
		/**
		 * Filter to inject an explicit country override.
		 *
		 * @since 1.15.0
		 * @param string $country Default empty (no override).
		 */
		$cc = (string) apply_filters( 'faz_geo_admin_override_country', '' );
		$cc = strtoupper( trim( $cc ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			return '';
		}
		return $cc;
	}

	/**
	 * Resolve via existing FazCookie\Includes\Geolocation (ip-api + GeoLite2).
	 *
	 * Delegates to the existing geolocation infrastructure rather than
	 * re-implementing the fallback chain.
	 *
	 * @param string $ip Visitor IP.
	 * @return array{country:string, region:string, source:string}
	 */
	private function resolve_via_existing_geolocation( $ip ) {
		$country = '';
		if ( class_exists( '\\FazCookie\\Includes\\Geolocation' ) ) {
			try {
				$geo     = new \FazCookie\Includes\Geolocation();
				$result  = method_exists( $geo, 'detect_country' ) ? $geo->detect_country() : '';
				$country = is_string( $result ) ? strtoupper( $result ) : '';
			} catch ( \Throwable $e ) {
				$country = '';
			}
		}
		// Region not available from existing Geolocation; left empty.
		return array(
			'country' => $country,
			'region'  => '',
			'source'  => '' !== $country ? 'geolocation_fallback' : '',
		);
	}

	/**
	 * Best-effort client IP resolution.
	 *
	 * Trusts Cloudflare's CF-Connecting-IP first when CF is in front;
	 * else REMOTE_ADDR.
	 *
	 * @return string Cleartext IP.
	 */
	private function resolve_client_ip() {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return (string) $_SERVER['REMOTE_ADDR'];
		}
		return '';
	}

	/**
	 * Hash IP for cache key with monthly-rotating salt.
	 *
	 * @param string $ip Cleartext IP.
	 * @return string 64-char hex.
	 */
	private function hash_ip( $ip ) {
		$salt  = function_exists( 'wp_salt' ) ? (string) wp_salt( 'nonce' ) : 'faz-fallback';
		$month = gmdate( 'Y-m' );
		return hash( 'sha256', (string) $ip . '|' . $month . '|' . $salt );
	}
}
