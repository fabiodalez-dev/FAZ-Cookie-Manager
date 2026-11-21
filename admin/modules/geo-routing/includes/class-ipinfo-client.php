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
	/** Seconds into a window after which a missing object-cache counter means it was dropped (see reserve_slot()). */
	const WINDOW_GRACE = 2;

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
	 * The window is fixed (it closes an hour after the first lookup in it) and
	 * a slot is taken as one atomic step, never as a read followed by a write:
	 * misses that overlap would all read the same count and each write back
	 * n + 1, so a flood timed to overlap got up to as many lookups per counted
	 * slot as the server runs requests at once, for the whole window. See
	 * reserve_slot() for how each backend does it.
	 *
	 * The /64 is taken before the site: a prefix over its own ceiling must not
	 * spend the shared budget, which is what a flood rotating through /64s
	 * would drain. A site budget that is already spent is read first and
	 * refuses without writing, so a flood past it creates no per-prefix rows.
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
		$mode = $this->budget_backend( $persistent );
		$site = $this->peek_window( $mode, self::BUDGET_KEY, $now );
		if ( $site['n'] >= $max ) {
			$this->budget_retry_after = max( 1, $site['start'] + HOUR_IN_SECONDS - $now );
			return false;
		}
		$bucket = function_exists( 'faz_ip_rate_bucket' ) ? faz_ip_rate_bucket( $ip ) : $ip;
		if ( '/64' === substr( $bucket, -3 ) ) {
			$left = $this->reserve_slot( $mode, self::BUDGET_KEY . '_v6_' . $this->hash_ip( $bucket ), self::IPV6_LOOKUPS_PER_HOUR, $now );
			if ( $left > 0 ) {
				$this->budget_retry_after = $left;
				return false;
			}
		}
		$left = $this->reserve_slot( $mode, self::BUDGET_KEY, $max, $now );
		if ( $left > 0 ) {
			$this->budget_retry_after = $left;
			return false;
		}
		return true;
	}

	/**
	 * Where the budget counters live.
	 *
	 * 'object' with a persistent object cache (wp_cache_add / wp_cache_incr are
	 * atomic there), 'sql' otherwise (one options row per window, changed by
	 * conditional statements), 'transient' only where there is no database
	 * handle at all, which is no WordPress request.
	 *
	 * @param bool $persistent Whether a persistent object cache is in use.
	 * @return string
	 */
	private function budget_backend( $persistent ) {
		if ( $persistent ) {
			return 'object';
		}
		return isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? 'sql' : 'transient';
	}

	/** Cache key of the counter of the window that began at `$start` ('object' backend). */
	private function object_counter_key( $key, $start ) {
		return $key . '_n_' . (int) $start;
	}

	/** Option holding one budget window on the 'sql' backend: "start:count". */
	private function window_option( $key ) {
		return 'faz_ipinfo_win_' . $key;
	}

	/**
	 * The active hourly window, or an empty one starting now. Read only.
	 *
	 * @param string $mode Backend from budget_backend().
	 * @param string $key  BUDGET_KEY or a per-prefix key.
	 * @param int    $now  Current time.
	 * @return array{n:int,start:int}
	 */
	private function peek_window( $mode, $key, $now ) {
		if ( 'sql' === $mode ) {
			$row = $this->read_window_row( $key );
			if ( null === $row || $now - $row['start'] >= HOUR_IN_SECONDS ) {
				return array( 'n' => 0, 'start' => $now );
			}
			return $row;
		}
		if ( 'object' === $mode ) {
			$start = (int) wp_cache_get( $key . '_s', self::CACHE_GROUP );
			$count = $start > 0 ? wp_cache_get( $this->object_counter_key( $key, $start ), self::CACHE_GROUP ) : false;
			return ( $start > 0 && false !== $count ) ? array( 'n' => (int) $count, 'start' => $start ) : array( 'n' => 0, 'start' => $now );
		}
		$data  = $this->cache_read( false, $key );
		$start = is_array( $data ) ? (int) ( $data['start'] ?? 0 ) : 0;
		if ( ! $start || $now - $start >= HOUR_IN_SECONDS ) {
			return array( 'n' => 0, 'start' => $now );
		}
		return array( 'n' => (int) ( $data['n'] ?? 0 ), 'start' => $start );
	}

	/**
	 * Take one slot of a window if it has one left, as a single atomic step.
	 *
	 * - 'object': wp_cache_add() opens the window (the first request to add it
	 *   wins) and wp_cache_incr() counts, which the cache backend does in one
	 *   operation; a count past the ceiling is a refusal.
	 * - 'sql': the window is one options row, "start:count". It is created with
	 *   INSERT IGNORE, replaced when its hour is over and incremented with an
	 *   UPDATE that only matches the value this request read, so two requests
	 *   cannot both turn n into n + 1. A request that loses retries on the new
	 *   value; one that keeps losing is refused, which skips a lookup and never
	 *   a decision. A transient could not do this: it is read and written as
	 *   two operations.
	 * - 'transient': read and write, not atomic, for a context with no database.
	 *
	 * @param string $mode Backend from budget_backend().
	 * @param string $key  BUDGET_KEY or a per-prefix key.
	 * @param int    $max  Slots per window.
	 * @param int    $now  Current time.
	 * @return int 0 when a slot was taken, otherwise the seconds the window has left.
	 */
	private function reserve_slot( $mode, $key, $max, $now ) {
		if ( 'object' === $mode ) {
			wp_cache_add( $key . '_s', $now, self::CACHE_GROUP, HOUR_IN_SECONDS );
			$start = (int) wp_cache_get( $key . '_s', self::CACHE_GROUP );
			$start = $start > 0 ? $start : $now;
			// The counter is named after the window it counts. It and the start
			// are separate cache entries that may not expire together (one can
			// be evicted and re-added later), and a counter that outlived its
			// window must not be read into the next one.
			$counter = $this->object_counter_key( $key, $start );
			$left    = max( 1, $start + HOUR_IN_SECONDS - $now );
			// Create the counter with its expiry before counting, then increment
			// it. A backend whose incr() creates a missing key (Redis INCRBY)
			// would otherwise leave it with no expiry at all; add() is
			// create-if-absent and incr() keeps the entry's expiry.
			//
			// What the counter starts at is decided in that one add(), so there is
			// no moment where it exists at zero and a concurrent request can take
			// a slot before it is marked spent. A counter missing WINDOW_GRACE
			// seconds or more into its window was dropped by the cache, and with
			// it the slots already taken: counting on from zero would hand the
			// ceiling out a second time, so it starts at the ceiling and the rest
			// of the window is refused (a lookup is skipped, never a decision).
			// Inside the first seconds a missing counter cannot be told from two
			// requests racing to add it, and a drop there is not detected: the
			// evidence of it would have to live in the same cache.
			$base = ( $now - $start >= self::WINDOW_GRACE ) ? $max : 0;
			$added = wp_cache_add( $counter, $base, self::CACHE_GROUP, $left );
			$n     = wp_cache_incr( $counter, 1, self::CACHE_GROUP );
			if ( false === $n || $n > $max ) {
				return max( 1, $start + HOUR_IN_SECONDS - $now );
			}
			if ( $added && $base === $max ) {
				// The counter was added at the ceiling, so incr() can only have
				// answered above it. An answer at or below it means the entry was
				// dropped between the two calls and incr() recreated it at 1 with
				// no expiry: refuse, and put the ceiling and the expiry back, or
				// the rest of the window would count on from 1. A request that
				// increments the recreated entry before this set() still takes a
				// slot: the object-cache API has no compare-and-set to close that
				// gap, and it needs an eviction inside the add()/incr() pair plus
				// a concurrent request inside the same few microseconds, so it is
				// a documented limit (a lookup is skipped, never a decision).
				wp_cache_set( $counter, $max, self::CACHE_GROUP, $left );
				return max( 1, $start + HOUR_IN_SECONDS - $now );
			}
			return 0;
		}
		if ( 'sql' === $mode ) {
			return $this->reserve_slot_sql( $key, $max, $now );
		}
		$window = $this->peek_window( $mode, $key, $now );
		if ( $window['n'] >= $max ) {
			return max( 1, $window['start'] + HOUR_IN_SECONDS - $now );
		}
		++$window['n'];
		$this->cache_verdict( false, $key, $window, $window['start'] + HOUR_IN_SECONDS - $now );
		return 0;
	}

	/** The 'sql' half of reserve_slot(). */
	private function reserve_slot_sql( $key, $max, $now ) {
		global $wpdb;
		$name = $this->window_option( $key );
		for ( $try = 0; $try < 4; $try++ ) {
			$row = $this->read_window_row( $key );
			if ( null === $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a window must be opened in one statement, which the options API cannot express; $wpdb->options is core's own table name.
				if ( 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $now . ':1' ) ) ) {
					return 0;
				}
				continue;
			}
			$old = $row['start'] . ':' . $row['n'];
			if ( $now - $row['start'] >= HOUR_IN_SECONDS ) {
				$new = $now . ':1';
			} elseif ( $row['n'] >= $max ) {
				return max( 1, $row['start'] + HOUR_IN_SECONDS - $now );
			} else {
				$new = $row['start'] . ':' . ( $row['n'] + 1 );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- compare-and-set on the value this request read; $wpdb->options is core's own table name.
			if ( 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, $name, $old ) ) ) {
				if ( self::BUDGET_KEY === $key && $now - $row['start'] >= HOUR_IN_SECONDS ) {
					$this->sweep_expired_windows( $now );
				}
				return 0;
			}
		}
		return 1;
	}

	/**
	 * The stored window of the 'sql' backend, read past the options cache so a
	 * change another request just made is seen.
	 *
	 * @return array{n:int,start:int}|null
	 */
	private function read_window_row( $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the value must come from the database, not the options cache; $wpdb->options is core's own table name.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->window_option( $key ) ) );
		if ( ! is_string( $value ) || false === strpos( $value, ':' ) ) {
			return null;
		}
		list( $start, $n ) = array_map( 'intval', explode( ':', $value, 2 ) );
		return $start > 0 ? array( 'n' => $n, 'start' => $start ) : null;
	}

	/**
	 * Drop the per-prefix windows whose hour is over. Runs when the site
	 * window rolls over, so a flood that rotated through /64s leaves no rows
	 * behind for longer than the next hour (a transient has an expiry sweep, a
	 * plain option does not).
	 */
	private function sweep_expired_windows( $now ) {
		global $wpdb;
		$like = $wpdb->esc_like( $this->window_option( self::BUDGET_KEY . '_v6_' ) ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- housekeeping of this class's own rows; $wpdb->options is core's own table name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d", $like, $now - HOUR_IN_SECONDS ) );
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
		if ( '' !== $plain && Secrets::needs_upgrade( $enc ) ) {
			$upgraded = Secrets::encrypt( $plain );
			if ( '' !== $upgraded && $plain === Secrets::decrypt( $upgraded ) ) {
				return $this->replace_legacy_key( $enc, $upgraded, $plain );
			}
		}
		return $plain;
	}

	/**
	 * Re-encrypt only if the saved key is still the one this request read.
	 *
	 * @param string $previous Legacy ciphertext.
	 * @param string $upgraded Replacement ciphertext.
	 * @param string $plain    Decrypted legacy value.
	 * @return string The key this request should use.
	 */
	private function replace_legacy_key( $previous, $upgraded, $plain ) {
		global $wpdb;
		$name = 'faz_geo_ipinfo_api_key';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- update_option() cannot compare against the legacy value in the same write.
		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $upgraded, 'autoload' => 'no' ),
			array( 'option_name' => $name, 'option_value' => $previous ),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);
		if ( false === $updated ) {
			return $plain;
		}
		wp_cache_delete( $name, 'options' );
		$autoloaded = wp_cache_get( 'alloptions', 'options' );
		if ( is_array( $autoloaded ) && isset( $autoloaded[ $name ] ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
		// Another request rotated or removed the key. Read its value before
		// attempting the provider lookup.
		return 1 === $updated ? $plain : Secrets::decrypt( get_option( $name, '' ) );
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
