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
	 * Trust gate: only honour the header when the request actually transits a
	 * known proxy edge OR the operator has explicitly opted in via the legacy
	 * `faz_trust_cf_ipcountry_header` filter (kept for parity with the
	 * pre-Geo_Detector `FazCookie\Includes\Geolocation` contract). Without
	 * this gate a client that reaches the origin directly can spoof
	 * `CF-IPCountry: DE` and steer the resolver as `cf_header` — see
	 * `is_trusted_proxy()` for the CIDR allowlist (Cloudflare ranges plus
	 * `faz_geo_trusted_proxy_cidrs` extensions).
	 *
	 * @return string Country code (uppercase) or empty.
	 */
	private function get_cf_country() {
		if ( empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return '';
		}
		if ( ! $this->cf_header_is_trusted() ) {
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
	 * Whether the CF-IPCountry header is trustworthy on this request.
	 *
	 * Matches `Geolocation::detect_country()` / `Geolocation::get_visitor_country()`:
	 * operator opt-in via the `faz_trust_cf_ipcountry_header` filter, default
	 * `false`. The CF CIDR gate used by `resolve_client_ip()` decides which
	 * IP to USE; it does NOT, on its own, authorise consulting CF-IPCountry —
	 * that requires explicit admin opt-in to keep parity with the legacy
	 * `Geolocation` contract consumed by frontend/banner-rest/amp/i18n.
	 *
	 * @return bool
	 */
	private function cf_header_is_trusted() {
		return (bool) apply_filters( 'faz_trust_cf_ipcountry_header', false );
	}

	/**
	 * Read CF-Region header if exposed by CF Workers / custom config.
	 *
	 * Honours the same trust gate as `get_cf_country()`.
	 *
	 * @return string ISO 3166-2 or empty.
	 */
	private function get_cf_region() {
		if ( empty( $_SERVER['HTTP_CF_REGION_CODE'] ) || empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return '';
		}
		if ( ! $this->cf_header_is_trusted() ) {
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
	 * @param string $ip Resolved client IP from the caller. Passed through to
	 *                   Geolocation::get_country() so an explicit `$ip_override`
	 *                   supplied to Geo_Detector::detect() (test fixtures,
	 *                   cron jobs) actually reaches the underlying GeoIP
	 *                   lookup instead of being silently dropped — earlier
	 *                   revisions discarded `$ip` and re-resolved REMOTE_ADDR
	 *                   inside Geolocation, breaking the explicit-IP contract.
	 * @return array{country:string, region:string, source:string}
	 */
	private function resolve_via_existing_geolocation( $ip ) {
		// L2-SP1-S001 fix (1.15.0): use public static get_country(),
		// not the private static detect_country(). The previous call to
		// $geo->detect_country() raised a Throwable that was silently
		// swallowed by the outer try/catch, breaking the entire
		// ip-api / GeoLite2 fallback chain — every non-CF visitor was
		// being routed to fallback-gdpr-most-protective regardless of
		// their real country.
		// F-GEO-2 fix (1.16.0 backlog): pass the resolved $ip through
		// to Geolocation::get_country() so that an explicit ip_override
		// flowing in from Geo_Detector::detect($ip_override) reaches the
		// underlying lookup. Without the pass-through, get_country()
		// re-resolved REMOTE_ADDR via get_client_ip(), defeating the
		// contract used by tests and cron callers that need to resolve a
		// non-request IP.
		$country = '';
		$region  = '';
		if ( class_exists( '\\FazCookie\\Includes\\Geolocation' ) ) {
			try {
				$result  = \FazCookie\Includes\Geolocation::get_country( $ip );
				$country = is_string( $result ) ? strtoupper( $result ) : '';
			} catch ( \Throwable $e ) {
				$country = '';
				if ( function_exists( 'error_log' ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[FAZ Cookie Manager] Geolocation::get_country failed: ' . $e->getMessage() );
				}
			}
			// Sub-national region from the GeoLite2-City subdivision lookup
			// (empty on a Country-only DB or when no subdivision is present).
			if ( '' !== $country && method_exists( '\\FazCookie\\Includes\\Geolocation', 'get_region' ) ) {
				try {
					$reg    = \FazCookie\Includes\Geolocation::get_region( $ip );
					$region = ( is_string( $reg ) && 1 === preg_match( '/^[A-Z]{2}-[A-Z0-9]{1,3}$/', strtoupper( $reg ) ) ) ? strtoupper( $reg ) : '';
				} catch ( \Throwable $e ) {
					$region = '';
				}
			}
		}
		return array(
			'country' => $country,
			'region'  => $region,
			'source'  => '' !== $country ? 'geolocation_fallback' : '',
		);
	}

	/**
	 * Cloudflare published proxy IP ranges (https://www.cloudflare.com/ips/).
	 * Static list as of 2026 — refresh annually. Any request whose REMOTE_ADDR
	 * is NOT in this list is treated as direct (CF-Connecting-IP ignored)
	 * because that header is set by Cloudflare's edge and is forgeable when
	 * the origin is directly reachable.
	 *
	 * @var string[]
	 */
	private static $cf_ranges_v4 = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
	);

	/**
	 * @var string[]
	 */
	private static $cf_ranges_v6 = array(
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Best-effort client IP resolution.
	 *
	 * Security: CF-Connecting-IP is set by Cloudflare's edge — but only when
	 * the request actually transits Cloudflare. On any deployment where the
	 * origin is directly reachable, an attacker can spoof the header to bypass
	 * the VPN gate downstream. We validate REMOTE_ADDR against Cloudflare's
	 * published proxy IP ranges before trusting the header. Operators behind
	 * other CDNs/proxies can extend the allowlist via the
	 * `faz_geo_trusted_proxy_cidrs` filter.
	 *
	 * @return string Cleartext IP.
	 */
	private function resolve_client_ip() {
		$remote_addr = ! empty( $_SERVER['REMOTE_ADDR'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? (string) $_SERVER['REMOTE_ADDR']
			: '';

		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && '' !== $remote_addr && $this->is_trusted_proxy( $remote_addr ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$cf_ip = trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] );
			// Only accept syntactically valid IPs to avoid downstream surprises.
			if ( false !== filter_var( $cf_ip, FILTER_VALIDATE_IP ) ) {
				return $cf_ip;
			}
		}

		return $remote_addr;
	}

	/**
	 * Whether REMOTE_ADDR is in a trusted proxy CIDR range.
	 *
	 * @param string $ip REMOTE_ADDR.
	 * @return bool
	 */
	private function is_trusted_proxy( $ip ) {
		$cidrs = array_merge( self::$cf_ranges_v4, self::$cf_ranges_v6 );
		/**
		 * Extend the trusted-proxy allowlist (e.g., for other CDNs, custom edges).
		 *
		 * Accept CIDR notation (e.g., "203.0.113.0/24" or "2001:db8::/32").
		 * Each entry must be a string; non-string entries are ignored.
		 *
		 * @since 1.15.0
		 * @param string[] $cidrs Default = Cloudflare public proxy ranges.
		 */
		$cidrs = (array) apply_filters( 'faz_geo_trusted_proxy_cidrs', $cidrs );

		foreach ( $cidrs as $cidr ) {
			if ( is_string( $cidr ) && $this->ip_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether $ip falls within $cidr.
	 *
	 * @param string $ip   IPv4 or IPv6 address.
	 * @param string $cidr CIDR-notation range.
	 * @return bool
	 */
	private function ip_in_cidr( $ip, $cidr ) {
		if ( false === strpos( $cidr, '/' ) ) {
			return false;
		}
		list( $subnet, $bits ) = explode( '/', $cidr, 2 );
		$bits   = (int) $bits;
		$ip_bin = @inet_pton( $ip );
		$sn_bin = @inet_pton( $subnet );
		if ( false === $ip_bin || false === $sn_bin || strlen( $ip_bin ) !== strlen( $sn_bin ) ) {
			return false;
		}
		$max_bits = strlen( $ip_bin ) * 8;
		if ( $bits < 0 || $bits > $max_bits ) {
			return false;
		}
		// Compare $bits leading bits.
		$full_bytes = intdiv( $bits, 8 );
		$rest_bits  = $bits % 8;
		if ( $full_bytes > 0 && 0 !== substr_compare( $ip_bin, $sn_bin, 0, $full_bytes ) ) {
			return false;
		}
		if ( 0 === $rest_bits ) {
			return true;
		}
		$mask = chr( ( 0xFF << ( 8 - $rest_bits ) ) & 0xFF );
		return ( $ip_bin[ $full_bytes ] & $mask ) === ( $sn_bin[ $full_bytes ] & $mask );
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
