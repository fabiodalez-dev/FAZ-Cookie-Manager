<?php
/**
 * Class Geo_Detector file — orchestrator for the geo detection pipeline.
 *
 * Spec: specs/001-geo-routing-next/spec.md FR-02
 * Task: T021 (P3 Pipeline)
 *
 * Chain as implemented by `detect()` (plan.md §1.1 stage 1):
 *   1. X-Country-Code admin override — evaluated FIRST, ahead of the cache
 *      read, and its result is deliberately never cached (the cache key is the
 *      IP hash alone, so caching the override would poison later un-overridden
 *      lookups). See the comment in `detect()` for why it moved up.
 *   2. Per-IP cache hit (`wp_cache_get`, group `faz_geo_detect`) → returned
 *      verbatim; steps 3-6 run only on a miss.
 *   3. CF-IPCountry / CF-Region-Code headers (Cloudflare), behind the
 *      `faz_trust_cf_ipcountry_header` trust gate.
 *   4. ipinfo.io VPN/proxy gate — annotates the result with `vpn` (bool|null);
 *      it does not by itself pick the country.
 *   5. GeoLite2 local DB (plus any server GeoIP module/extension) via
 *      `Geolocation` — consulted only when the CF header yielded nothing.
 *      There is no ip-api.com step: no visitor IP leaves the server for
 *      country resolution, and an earlier docblock here said otherwise.
 *   6. 'XX' sentinel when every source failed.
 *
 * Cache: `wp_cache_*` under group `faz_geo_detect`, keyed on the IP hash,
 * TTL 1h (Q6 resolution). Written on a miss only, never for step 1.
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
		$request_ip = $this->resolve_client_ip();
		$ip         = is_string( $ip_override ) && '' !== $ip_override
			? $ip_override
			: $request_ip;
		// The edge's T1 verdict describes the connection being served. An
		// explicit address that is not that connection's — a test or a cron
		// asking about another visitor — is not the relay the edge saw, so the
		// verdict is not applied to it.
		$verdict_applies = $ip === $request_ip;

		$ip_hash = $this->hash_ip( $ip );

		// 1. Admin override, BEFORE the cache read.
		//
		// This used to sit after it, and the cache is keyed on the IP hash
		// alone. Anything that resolved geo earlier in the request — the
		// plugin's own bootstrap does — stored a {country, source:cf_header}
		// entry, and every later call returned that entry without ever
		// consulting the override. An administrator's country override was
		// therefore ignored, silently, for the rest of the request: measured on
		// a clean install, the filter had no effect until wp_cache_flush().
		//
		// The override is a filter call with no I/O, so it is cheap to evaluate
		// first, and its result is deliberately NOT cached: writing it under an
		// IP-only key would poison the cache the other way round, making a
		// later un-overridden lookup answer with the override.
		$admin_override = $this->get_admin_override_country();
		if ( '' !== $admin_override ) {
			return array( 'country' => $admin_override, 'region' => '', 'vpn' => false, 'source' => 'admin_override' );
		}

		// 1b. The edge says the forwarded address is a Tor relay (`T1`). That is
		// an anonymity verdict, not a missing one: Ruleset_Resolver routes a
		// VPN/Tor visitor to the most-protective fallback before it looks at
		// any country, and this is the same gate the paid ipinfo lookup feeds.
		// It used to be folded into "no answer" with 'XX', after which the
		// GeoLite2 fallback geolocated the relay and a Tor visitor whose exit
		// sat in an opt-out jurisdiction received that regime. Nothing below
		// runs: the relay's country is not the visitor's, and its address is
		// not sent to ipinfo to learn what the edge has already said. Read
		// before the cache, like the override, because a header costs nothing
		// and a header-derived verdict is never cached under the address.
		if ( $verdict_applies && $this->cf_header_reports_anonymiser() ) {
			return array( 'country' => 'XX', 'region' => '', 'vpn' => true, 'source' => 'cf_anonymiser' );
		}

		// Cache hit?
		$cached = wp_cache_get( $ip_hash, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// 2. CF-IPCountry header, for the request's own address only: like
		// the T1 verdict above, it describes the connection being served,
		// not an explicit address a caller asks about.
		$cf_country = $verdict_applies ? $this->get_cf_country() : '';
		$cf_region  = $verdict_applies ? $this->get_cf_region() : '';

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
			// 5. GeoLite2 / server GeoIP fallbacks via existing Geolocation class.
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

		// Same rule as Geolocation::get_country(): a country that came from a
		// request header is never cached. The key is the address alone, and an
		// address is not always one visitor — a NAT, a carrier's CGNAT, a
		// shared proxy — so the first arrival's header would be served to
		// everybody behind that address for an hour, and with it the wrong
		// consent regime. Reading a header is free and already per request;
		// the cache exists for the lookups that cost something. The fallback
		// path asks Geolocation the same question, because its mod_geoip
		// variable is a header too wherever a fastcgi_param lets one through.
		$from_header = 'cf_header' === $source
			|| ( 'geolocation_fallback' === $source
				&& class_exists( '\\FazCookie\\Includes\\Geolocation' )
				&& method_exists( '\\FazCookie\\Includes\\Geolocation', 'country_header_in_play' )
				&& \FazCookie\Includes\Geolocation::country_header_in_play() );
		if ( ! $from_header ) {
			wp_cache_set( $ip_hash, $result, self::CACHE_GROUP, self::CACHE_TTL );
		}
		return $result;
	}


	/**
	 * Read CF-IPCountry header from $_SERVER.
	 *
	 * Trust gate, BOTH halves required: the operator has opted in through the
	 * legacy `faz_trust_cf_ipcountry_header` filter (default false, kept for
	 * parity with the pre-Geo_Detector `FazCookie\Includes\Geolocation`
	 * contract) AND the request arrived from one of Cloudflare's own shipped
	 * ranges. The opt-in alone is not enough and used to be: `CF-IPCountry` is
	 * written by Cloudflare's edge, so a client reaching the origin directly
	 * could send `CF-IPCountry: DE` and steer the resolver as `cf_header`. The
	 * peer check reads `faz_cloudflare_proxy_cidrs()`, which no filter can
	 * widen, for the same reason the address resolver does.
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
			// Neither is a country. 'XX' is "no country data" and the address
			// is resolved by the fallbacks; 'T1' (Tor) never reaches this
			// method — detect() answers it first, as an anonymity verdict.
			return '';
		}
		if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			return '';
		}
		return $cc;
	}

	/**
	 * Whether the trusted CF-IPCountry header carries Cloudflare's Tor sentinel.
	 *
	 * Cloudflare writes `T1` when the connection arrived through Tor; `XX` is
	 * the unrelated "no country data" value. Same trust gate as the country
	 * read: the operator's opt-in AND a Cloudflare peer, because from any other
	 * peer the header is the client's own and a client must not be able to
	 * assert an anonymity verdict. `Geolocation::cf_reports_anonymiser()` is
	 * the sibling rule for the banner-selection layer; both read the same
	 * token through the same gate so the two layers cannot disagree.
	 *
	 * @since 1.34.2
	 * @return bool
	 */
	private function cf_header_reports_anonymiser() {
		if ( empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) || ! $this->cf_header_is_trusted() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return 'T1' === strtoupper( trim( (string) $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
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
		if ( ! (bool) apply_filters( 'faz_trust_cf_ipcountry_header', false ) ) {
			return false;
		}
		return $this->peer_is_cloudflare();
	}

	/**
	 * Whether REMOTE_ADDR is one of Cloudflare's own published edges.
	 *
	 * Delegates to `faz_peer_is_cloudflare()`, which is the one place this
	 * question is answered — `Geolocation` asks it too, and two copies of a
	 * trust predicate is how the sibling path keeps missing the fix.
	 *
	 * @since 1.34.1
	 * @return bool
	 */
	private function peer_is_cloudflare() {
		// Fails CLOSED when the shared predicate is unavailable: it is the only
		// thing that proves the peer, and an unvouched header is a value the
		// visitor chose.
		if ( ! function_exists( 'faz_peer_is_cloudflare' ) ) {
			return false;
		}
		return (bool) \faz_peer_is_cloudflare();
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
	 * Resolve via existing FazCookie\Includes\Geolocation (server GeoIP + GeoLite2).
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
		// GeoLite2 / server GeoIP fallback chain — every non-CF visitor was
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
	 * The visitor's address, resolved by the one resolver that does this.
	 *
	 * This used to read `CF-Connecting-IP` for any peer inside a CIDR
	 * allowlist that two filters could widen. That was the bug: `CF-Connecting-IP`
	 * is written by Cloudflare's edge and by nothing else, so trusting it from
	 * a range an administrator added for their OWN CDN let a visitor behind that
	 * CDN send the header themselves and choose the country and VPN state this
	 * detector reports — and with them the ruleset applied. It also meant two
	 * resolvers could attribute one request to two different addresses, since
	 * `faz_resolve_client_ip()` resolved the client while this resolved the edge.
	 *
	 * Both are fixed the same way: delegate. `faz_forwarded_client_ip()` binds
	 * each header to the kind of peer that writes it — `CF-Connecting-IP` only
	 * from the SHIPPED Cloudflare ranges, which no filter can extend, and an
	 * administrator's own range through a named forwarded header instead. Its
	 * empty return means "no per-visitor address could be established", and the
	 * honest answer then is the peer itself.
	 *
	 * BREAKING, deliberately: `faz_geo_trusted_proxy_cidrs` is gone. Its only
	 * effect was to grant `CF-Connecting-IP` trust to a peer that never writes
	 * it, which is the vulnerability rather than a feature. A site that needs
	 * its own edge trusted adds the range to `faz_trusted_proxy_cidrs` and names
	 * the header it writes with `faz_proxy_forwarded_header`.
	 *
	 * @return string Cleartext IP, or '' when there is no REMOTE_ADDR at all.
	 */
	private function resolve_client_ip() {
		$remote_addr = ! empty( $_SERVER['REMOTE_ADDR'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? (string) $_SERVER['REMOTE_ADDR']
			: '';

		if ( '' === $remote_addr || ! function_exists( 'faz_forwarded_client_ip' ) ) {
			return $remote_addr;
		}

		$forwarded = (string) \faz_forwarded_client_ip( $remote_addr );

		return '' !== $forwarded ? $forwarded : $remote_addr;
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
	 * The cache key for an address: the shared salted, month-rotated digest.
	 *
	 * Delegates to `faz_ip_cache_key()` so that this layer, Ipinfo_Client and
	 * the Geolocation transients key one address identically. The formula
	 * used to live here and in Ipinfo_Client while Geolocation used a bare
	 * md5; three copies of one rule is how one of them drifts.
	 *
	 * @param string $ip Cleartext IP.
	 * @return string 64-char hex.
	 */
	private function hash_ip( $ip ) {
		return \faz_ip_cache_key( $ip );
	}
}
