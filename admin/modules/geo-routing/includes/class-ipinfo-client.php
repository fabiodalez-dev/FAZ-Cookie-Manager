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
	/** How long a failed lookup is remembered before ipinfo is asked again. */
	const ERROR_TTL      = 300;
	/** Site-wide marker of an account-level refusal (401/402/403/429). */
	const REFUSED_KEY    = 'refused';
	/** Site-wide count of upstream lookups in the current hour. */
	const BUDGET_KEY     = 'budget';
	/** Default ceiling of new upstream lookups per hour (filterable). */
	const LOOKUPS_PER_HOUR = 120;
	/** Ceiling of new upstream lookups per hour for one IPv6 /64. */
	const IPV6_LOOKUPS_PER_HOUR = 20;

	/** HTTP status of the last lookup (0 for a transport failure). */
	private $last_http_status = 0;
	const REQUEST_TIMEOUT = 3;

	/**
	 * Seconds until the hourly budget opens again, set when claim_lookup()
	 * refuses.
	 *
	 * @var int
	 */
	private $budget_retry_after = 0;

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

		// The provider classifies individual addresses, including within an
		// IPv6 /64. Only the lookup budget is shared across the prefix.
		$ip_hash = $this->hash_ip( $ip );

		// Cache hit (after opt-in confirmed)? Without a persistent object
		// cache, wp_cache_* lives for one request, so every anonymous page
		// view or banner REST call paid for a fresh lookup on the operator's
		// key and any client could loop to drain the quota. A transient
		// carries the verdict across requests on those installs.
		$persistent = ! function_exists( 'get_transient' ) || ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() );
		$cached     = $this->cache_read( $persistent, $ip_hash );
		if ( false !== $cached && is_array( $cached ) && array_key_exists( 'vpn', $cached ) ) {
			return array( 'vpn' => $cached['vpn'], 'source' => 'cache' );
		}

		// ipinfo refused the account recently (see below): no call, whatever
		// the address.
		// `retry_after` says how long that answer stays true, so a caller
		// that caches it (Geo_Detector) keeps it no longer than the marker.
		$refused = $this->cache_read( $persistent, self::REFUSED_KEY );
		if ( false !== $refused ) {
			$until = is_array( $refused ) && isset( $refused['until'] ) ? (int) $refused['until'] : 0;
			return array(
				'vpn'         => null,
				'source'      => 'error',
				'retry_after' => $until > 0 ? max( 1, min( self::ERROR_TTL, $until - time() ) ) : self::ERROR_TTL,
			);
		}

		// A site-wide hourly ceiling on NEW lookups. The cache above stops a
		// client repeating itself; it cannot stop one that rotates addresses,
		// and without a persistent object cache every miss is also two
		// wp_options rows kept for a day. Past the ceiling the lookup is
		// skipped (no VPN verdict, the geo rules decide without it), so the
		// operator's quota and the options table are both bounded.
		if ( ! $this->claim_lookup( $persistent, $ip ) ) {
			return array(
				'vpn'         => null,
				'source'      => 'error',
				'retry_after' => $this->budget_retry_after,
			);
		}

		// Make the call.
		$this->last_http_status = 0;
		$result                 = $this->http_lookup( $ip, $api_key );
		if ( null === $result['vpn'] ) {
			// A transport failure is not cached: it says nothing about the
			// address and the next request may well succeed. A refusal by
			// ipinfo itself — quota exhausted, rate limited, key rejected — is
			// about the account, not the address, so it is remembered briefly
			// for the whole site: keyed per address, every new visitor would
			// still pay a full upstream call (up to the timeout) to an upstream
			// that has already said no.
			if ( in_array( $this->last_http_status, array( 401, 402, 403, 429 ), true ) ) {
				$this->cache_verdict(
					$persistent,
					self::REFUSED_KEY,
					array(
						'vpn'   => null,
						'until' => time() + self::ERROR_TTL,
					),
					self::ERROR_TTL
				);
				$result['retry_after'] = self::ERROR_TTL;
			}
			return $result;
		}

		// Cache success.
		$this->cache_verdict( $persistent, $ip_hash, array( 'vpn' => $result['vpn'] ), self::CACHE_TTL );
		return $result;
	}

	/**
	 * Take one slot of the hourly upstream-lookup budget.
	 *
	 * The window is fixed (it closes an hour after the first lookup in it).
	 * Not atomic: concurrent misses can overshoot by the number the server
	 * runs at once, which is fine for a flood ceiling.
	 *
	 * @param bool   $persistent Whether a persistent object cache is in use.
	 * @param string $ip         Address to look up.
	 * @return bool False when the hour's budget is spent.
	 */
	private function claim_lookup( $persistent, $ip ) {
		$max = function_exists( 'apply_filters' )
			? (int) apply_filters( 'faz_ipinfo_lookups_per_hour', self::LOOKUPS_PER_HOUR )
			: self::LOOKUPS_PER_HOUR;
		if ( $max <= 0 ) {
			return true; // 0 switches the ceiling off.
		}
		$now  = time();
		$site = $this->lookup_window( $persistent, self::BUDGET_KEY, $now );
		if ( $site['n'] >= $max ) {
			$this->budget_retry_after = $site['start'] + HOUR_IN_SECONDS - $now;
			return false;
		}
		$bucket = function_exists( 'faz_ip_rate_bucket' ) ? faz_ip_rate_bucket( $ip ) : $ip;
		if ( '/64' === substr( $bucket, -3 ) ) {
			$key    = self::BUDGET_KEY . '_v6_' . $this->hash_ip( $bucket );
			$prefix = $this->lookup_window( $persistent, $key, $now );
			if ( $prefix['n'] >= self::IPV6_LOOKUPS_PER_HOUR ) {
				$this->budget_retry_after = $prefix['start'] + HOUR_IN_SECONDS - $now;
				return false;
			}
			$this->count_lookup( $persistent, $key, $prefix, $now );
		}
		$this->count_lookup( $persistent, self::BUDGET_KEY, $site, $now );
		return true;
	}

	/** Read the active hourly window, or start one at the current time. */
	private function lookup_window( $persistent, $key, $now ) {
		$data  = $this->cache_read( $persistent, $key );
		$start = is_array( $data ) ? (int) ( $data['start'] ?? 0 ) : 0;
		if ( ! $start || $now - $start >= HOUR_IN_SECONDS ) {
			return array( 'n' => 0, 'start' => $now );
		}
		return array( 'n' => (int) ( $data['n'] ?? 0 ), 'start' => $start );
	}

	/** Count an upstream lookup without extending the window. */
	private function count_lookup( $persistent, $key, array $window, $now ) {
		++$window['n'];
		$this->cache_verdict( $persistent, $key, $window, $window['start'] + HOUR_IN_SECONDS - $now );
	}

	/**
	 * Read a cached entry from where cache_verdict() wrote it.
	 *
	 * @param bool   $persistent Whether a persistent object cache is in use.
	 * @param string $key        Address hash or REFUSED_KEY.
	 * @return mixed false when absent.
	 */
	private function cache_read( $persistent, $key ) {
		return $persistent ? wp_cache_get( $key, self::CACHE_GROUP ) : get_transient( 'faz_ipinfo_' . $key );
	}

	/**
	 * Store a verdict where the next request can read it.
	 *
	 * @param bool   $persistent Whether a persistent object cache is in use.
	 * @param string $key        Cache key from hash_ip(), or REFUSED_KEY.
	 * @param array  $value      array( 'vpn' => bool|null ).
	 * @param int    $ttl        Seconds.
	 */
	private function cache_verdict( $persistent, $key, $value, $ttl ) {
		if ( $persistent ) {
			wp_cache_set( $key, $value, self::CACHE_GROUP, $ttl );
		} else {
			set_transient( 'faz_ipinfo_' . $key, $value, $ttl );
		}
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
		$plain = Secrets::decrypt( $enc );
		// A key saved before 1.39.1 is a v2 XOR value, whose keystream every
		// other v2 value under the same salt shares. Rewrite it once in the
		// authenticated v3 format; the v2 copy then no longer sits in the row.
		if ( '' !== $plain && Secrets::needs_upgrade( $enc ) && function_exists( 'update_option' ) ) {
			$upgraded = Secrets::encrypt( $plain );
			if ( '' !== $upgraded && $plain === Secrets::decrypt( $upgraded ) ) {
				update_option( 'faz_geo_ipinfo_api_key', $upgraded, false );
			}
		}
		return $plain;
	}

	/**
	 * The cache key for an address: the shared salted, month-rotated digest.
	 *
	 * Delegates to `faz_ip_cache_key()`, the one place the rule lives, so this
	 * cache, Geo_Detector's and the Geolocation transients key one address
	 * identically.
	 *
	 * @param string $ip Cleartext IP.
	 * @return string 64-char hex.
	 */
	private function hash_ip( $ip ) {
		return \faz_ip_cache_key( $ip );
	}

	/**
	 * Issue the HTTP GET to ipinfo.io.
	 *
	 * @param string $ip      Cleartext IP.
	 * @param string $api_key API key.
	 * @return array{vpn: bool|null, source: string}
	 */
	private function http_lookup( $ip, $api_key ) {
		// Token MUST travel in the Authorization header, NOT as a `?token=...`
		// query parameter. With `?token=` the API key lands in every request
		// log that records URLs — WP_DEBUG_LOG, Query Monitor, reverse-proxy
		// access logs, APM tools — and a single leaked log file exposes the
		// operator's ipinfo.io credentials. ipinfo.io officially supports
		// `Authorization: Bearer <token>` (see https://ipinfo.io/developers).
		$url = sprintf( 'https://ipinfo.io/%s/privacy', rawurlencode( $ip ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 0,
				'user-agent'  => 'FAZ-Cookie-Manager/' . ( defined( 'FAZ_VERSION' ) ? FAZ_VERSION : '0.0.0' ) . ' (+https://wordpress.org/plugins/faz-cookie-manager)',
				'headers'     => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_failure( 'wp_error', $response->get_error_message() );
			return array( 'vpn' => null, 'source' => 'error' );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$this->last_http_status = (int) $code;
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
