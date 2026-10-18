<?php
/**
 * Geolocation helper — detects visitor country for geo-targeting.
 *
 * Detection chain (first match wins):
 *   1. Cloudflare CF-IPCountry header
 *   2. Apache mod_geoip GEOIP_COUNTRY_CODE
 *   3. PHP geoip extension
 *   4. MaxMind GeoLite2 MMDB database (self-hosted, no external API calls)
 *
 * Results cached as a transient for 1 hour.
 *
 * @package FazCookie
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Geolocation {

	/**
	 * EU/EEA country codes (includes UK post-Brexit for GDPR alignment).
	 *
	 * @var array
	 */
	public static $eu_countries = array(
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
		'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
		'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'GB', 'IS', 'LI', 'NO',
	);

	/**
	 * Cached MMDB reader instance.
	 *
	 * @var Mmdb_Reader|null
	 */
	private static $mmdb_reader = null;

	/**
	 * Per-request memo of resolved countries, keyed by IP. The banner pipeline
	 * asks for the visitor country several times per request (cache headers,
	 * banner selection, runtime ruleset); without this each call re-hit the
	 * transient/filter stack.
	 *
	 * @var array<string,string>
	 */
	private static $country_cache = array();

	/**
	 * Per-request memo of resolved regions, keyed by IP.
	 *
	 * @var array<string,string>
	 */
	private static $region_cache = array();

	/**
	 * Per-request memo of the resolved MMDB path (null = not resolved yet).
	 * get_database_path() probes up to 4 candidates with a 128 KB tail read
	 * each, and is reached 2-3 times per request — resolve once.
	 *
	 * @var string|null
	 */
	private static $db_path_cache = null;

	/**
	 * Reset all per-request memoization (resolved DB path, per-IP country /
	 * region results, MMDB reader). Production code never needs this — the
	 * memos die with the request — but tests that mutate the database files
	 * on disk between assertions do.
	 *
	 * @return void
	 */
	public static function reset_runtime_cache() {
		self::$db_path_cache = null;
		self::$country_cache = array();
		self::$region_cache  = array();
		self::$mmdb_reader   = null;
	}

	/**
	 * Get the visitor's ISO 3166-1 alpha-2 country code.
	 *
	 * @param string|null $ip_override Optional explicit IP to look up instead of
	 *                                 the request's client IP. Used by Geo_Detector
	 *                                 to honour the `$ip_override` parameter of its
	 *                                 own detect() method (test fixtures, cron jobs,
	 *                                 batch GeoIP lookups) — without this, the geo
	 *                                 fallback chain silently re-resolved REMOTE_ADDR
	 *                                 and ignored the caller's explicit IP.
	 *                                 An address other than the request's is
	 *                                 resolved without the request's country
	 *                                 headers, which describe the visitor
	 *                                 being served, not that address.
	 *                                 Pass null/empty to fall back to client IP.
	 * @return string Two-letter country code or empty string.
	 */
	public static function get_country( $ip_override = null ) {
		$request_ip = self::get_client_ip();
		$ip         = is_string( $ip_override ) && '' !== $ip_override ? $ip_override : $request_ip;
		if ( empty( $ip ) || in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
			return '';
		}
		// CF-IPCountry, its T1 verdict and mod_geoip's GEOIP_COUNTRY_CODE all
		// describe the connection being served. An explicit address that is
		// not that connection's (a test, a cron, a batch lookup) belongs to
		// somebody else, and the header would hand it this visitor's country.
		$own_address = $ip === $request_ip;

		if ( isset( self::$country_cache[ $ip ] ) ) {
			return self::$country_cache[ $ip ];
		}

		// A header is in play: resolve it per request and share nothing. See
		// country_header_in_play() — the key here is the address, and an
		// address is not always one visitor.
		$header_in_play = $own_address && self::country_header_in_play();

		// Salted and month-rotated, never a bare digest of the address: a
		// transient is a row in wp_options on most hosts, and md5 of an IPv4
		// is reversed by enumeration. See faz_ip_cache_key().
		$cache_key = 'faz_geo_' . \faz_ip_cache_key( $ip );
		if ( ! $header_in_play ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				self::$country_cache[ $ip ] = $cached;
				return $cached;
			}
		}

		$country = self::detect_country( $ip, $own_address );

		// Cache for 1 hour, unless a header contributed to the answer.
		if ( ! empty( $country ) && ! $header_in_play ) {
			set_transient( $cache_key, $country, HOUR_IN_SECONDS );
		}

		self::$country_cache[ $ip ] = $country;
		return $country;
	}

	/**
	 * Whether a request header is contributing to the country for this request.
	 *
	 * The transient cache in get_country() is keyed on the resolved ADDRESS
	 * alone, and the headers are not part of that key. An address is not always
	 * one visitor — a corporate NAT, a mobile carrier's CGNAT, a shared proxy —
	 * so a country that came from one visitor's header would be served to the
	 * next visitor on the same address for an hour, and with it the wrong
	 * consent regime. Where the origin is reachable directly it is worse than
	 * drift: the header is then chosen by whoever sent it, and the hour of
	 * cache is a poisoning window for everybody behind that address.
	 *
	 * So a header-derived country is never cached. Nothing is lost: reading a
	 * header costs nothing and is already per-request, while the cache exists
	 * for the sources that do cost something — the PHP GeoIP extension and the
	 * MaxMind database lookup. No source of detect_country() makes an outbound
	 * call; the IPinfo VPN gate lives in Geo_Detector behind a cache of its own.
	 *
	 * Public because Geo_Detector keeps a second, object-cache copy of the
	 * country under the same address-only key and must apply the same rule.
	 *
	 * @since 1.34.1
	 * @return bool
	 */
	public static function country_header_in_play() {
		if ( apply_filters( 'faz_trust_cf_ipcountry_header', false )
			&& self::cf_peer_is_trusted()
			&& ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		) {
			return true;
		}
		return apply_filters( 'faz_trust_geoip_country_code', false )
			&& ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Resolve the ISO 3166-2 region for an IP via the local GeoLite2-City DB.
	 *
	 * Public counterpart to get_country() for callers (e.g. Geo_Detector) that
	 * already hold a resolved IP and want the sub-national region without the
	 * Cloudflare-header layer that get_visitor_region() adds. Returns '' on a
	 * Country-only DB or when no subdivision is present.
	 *
	 * @param string|null $ip_override Explicit IP, or null for the client IP.
	 * @return string 'CC-RR' or empty string.
	 */
	public static function get_region( $ip_override = null ) {
		$ip = is_string( $ip_override ) && '' !== $ip_override ? $ip_override : self::get_client_ip();
		return self::detect_region( $ip );
	}

	/**
	 * Check if the visitor is in the EU/EEA.
	 *
	 * @return bool
	 */
	public static function is_eu() {
		return in_array( self::get_country(), self::$eu_countries, true );
	}

	/**
	 * Whether this request may assert a Cloudflare header.
	 *
	 * Fails CLOSED when the shared predicate is unavailable: it is the only
	 * thing that proves the peer is Cloudflare, and a header nothing vouches
	 * for is a value the visitor chose. The leading `\` is the house rule for
	 * global helpers called from inside this namespace.
	 *
	 * @since 1.34.1
	 * @return bool
	 */
	private static function cf_peer_is_trusted() {
		if ( ! function_exists( 'faz_peer_is_cloudflare' ) ) {
			return false;
		}
		return (bool) \faz_peer_is_cloudflare();
	}

	/**
	 * Cloudflare's CF-IPCountry value for a connection that arrived through Tor.
	 *
	 * Distinct from 'XX', which is "no country data". The two used to be
	 * treated alike, as no answer, and the address was then geolocated
	 * anyway — for a Tor visitor, that is the exit relay's country.
	 *
	 * @since 1.34.2
	 * @var string
	 */
	const CF_ANONYMISER_SENTINEL = 'T1';

	/**
	 * Whether the trusted Cloudflare header reports an anonymising network.
	 *
	 * Cloudflare writes `T1` to CF-IPCountry when the visitor reached the edge
	 * through Tor. That is not a missing answer: it is the edge saying that
	 * the address it forwarded belongs to a relay, not to the visitor, so no
	 * other source may geolocate it in the header's place. The MaxMind
	 * database would answer with the relay's country, and an exit in an
	 * opt-out jurisdiction would hand a visitor of unknown jurisdiction an
	 * opt-out regime with analytics and marketing granted before any action.
	 * The spec (001, US-05) names Tor among the cases that receive the
	 * most-protective fallback; Geo_Detector applies the same rule and turns
	 * it into its anonymity verdict, which Ruleset_Resolver routes first.
	 *
	 * Same trust gate as every other CF-* read: the operator's opt-in AND a
	 * Cloudflare peer. From any other peer the value is the client's own and
	 * is not read.
	 *
	 * @since 1.34.2
	 * @return bool
	 */
	public static function cf_reports_anonymiser() {
		if (
			! apply_filters( 'faz_trust_cf_ipcountry_header', false )
			|| ! self::cf_peer_is_trusted()
			|| empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		) {
			return false;
		}
		$code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return self::CF_ANONYMISER_SENTINEL === $code;
	}

	/**
	 * Resolve the visitor country with the public filter contract applied.
	 *
	 * This is the country signal used for banner selection. It intentionally
	 * re-validates after `faz_visitor_country` so test fixtures and trusted
	 * deployments can override the value without letting malformed output route
	 * into an arbitrary banner bucket.
	 *
	 * @since 1.14.0
	 * @return string Upper-case ISO 3166-1 alpha-2 country code, or empty string.
	 */
	public static function get_visitor_country() {
		$country = '';
		if (
			apply_filters( 'faz_trust_cf_ipcountry_header', false )
			&& self::cf_peer_is_trusted()
			&& isset( $_SERVER['HTTP_CF_IPCOUNTRY'] )
		) {
			$code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			if ( self::is_valid_country_code( $code ) && 'XX' !== $code ) {
				$country = $code;
			}
		}
		if ( '' === $country ) {
			$country = self::get_country();
		}
		$country = is_string( $country ) ? strtoupper( trim( $country ) ) : '';
		if ( ! self::is_valid_country_code( $country ) ) {
			$country = '';
		}

		$filtered = (string) apply_filters( 'faz_visitor_country', $country );
		$filtered = strtoupper( trim( $filtered ) );
		// Mirror the CF branch above: 'XX' is Cloudflare's "no country data"
		// sentinel (Tor is 'T1', which cf_reports_anonymiser() turns into no
		// country before any source is consulted). A third-party filter could
		// reintroduce 'XX' from a different detection source, and it must not
		// be routed as a real country — is_valid_country_code() only checks
		// the [A-Z]{2} shape and would accept it.
		if ( ! self::is_valid_country_code( $filtered ) || 'XX' === $filtered ) {
			return '';
		}
		return $filtered;
	}

	/**
	 * Resolve the visitor's ISO 3166-2 sub-national region (e.g. 'CA-QC').
	 *
	 * Sources, in priority order: the Cloudflare CF-Region-Code header (only
	 * when `faz_trust_cf_ipcountry_header` is on) and the local GeoLite2-City
	 * subdivision lookup. Returns '' when no sub-national signal is available
	 * (e.g. a Country-only DB and no Cloudflare), so callers degrade to
	 * country-level routing. Filterable via `faz_visitor_region` for fixtures.
	 *
	 * @return string ISO 3166-2 ('CC-RR') or empty string.
	 */
	public static function get_visitor_region() {
		$region = '';

		if (
			apply_filters( 'faz_trust_cf_ipcountry_header', false )
			&& self::cf_peer_is_trusted()
			&& ! empty( $_SERVER['HTTP_CF_REGION_CODE'] )
			&& ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] )
		) {
			$cc = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			$rr = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_REGION_CODE'] ) ) );
			if ( self::is_valid_country_code( $cc ) && 'XX' !== $cc && 1 === preg_match( '/^[A-Z0-9]{1,3}$/', $rr ) ) {
				$region = $cc . '-' . $rr;
			}
		}

		// The edge says the forwarded address is a Tor relay: the relay's
		// subdivision is not the visitor's, and is not looked up.
		if ( '' === $region && ! self::cf_reports_anonymiser() ) {
			$region = self::get_region();
		}

		$region = strtoupper( trim( $region ) );
		if ( 1 !== preg_match( '/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $region ) ) {
			$region = '';
		}

		/**
		 * Filter the resolved ISO 3166-2 visitor region.
		 *
		 * @since 1.17.2
		 * @param string $region ISO 3166-2 region code, or ''.
		 */
		$filtered = (string) apply_filters( 'faz_visitor_region', $region );
		$filtered = strtoupper( trim( $filtered ) );
		return ( 1 === preg_match( '/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $filtered ) ) ? $filtered : '';
	}

	/**
	 * Resolve the ISO 3166-2 region from the local GeoLite2-City DB.
	 *
	 * @param string $ip Client IP address.
	 * @return string 'CC-RR' or empty string.
	 */
	private static function detect_region( $ip ) {
		if ( empty( $ip ) || in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
			return '';
		}

		if ( isset( self::$region_cache[ $ip ] ) ) {
			return self::$region_cache[ $ip ];
		}

		// Same key rule as get_country(): this memo is written for EVERY
		// visitor address — the empty result included — so a reversible key
		// here would be a visitor log in wp_options.
		$cache_key = 'faz_georeg_' . \faz_ip_cache_key( $ip );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			self::$region_cache[ $ip ] = (string) $cached;
			return (string) $cached;
		}

		$region  = '';
		$db_path = self::get_database_path();
		if ( '' !== $db_path ) {
			try {
				if ( null === self::$mmdb_reader ) {
					self::$mmdb_reader = new Mmdb_Reader( $db_path );
				}
				$country = strtoupper( (string) self::$mmdb_reader->country( $ip ) );
				$sub     = strtoupper( (string) self::$mmdb_reader->subdivision( $ip ) );
				if ( self::is_valid_country_code( $country ) && 1 === preg_match( '/^[A-Z0-9]{1,3}$/', $sub ) ) {
					$region = $country . '-' . $sub;
				}
			} catch ( \Exception $e ) {
				error_log( 'FAZ GeoLite2 region lookup error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		// Cache even an empty result (briefly) to avoid re-reading the DB on
		// every request for IPs with no subdivision data.
		set_transient( $cache_key, $region, '' !== $region ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		self::$region_cache[ $ip ] = $region;
		return $region;
	}

	/**
	 * Get the client's real IP address.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		return faz_resolve_client_ip();
	}

	/**
	 * Validate a country code is exactly two uppercase ASCII letters.
	 *
	 * @param string $code Country code to validate.
	 * @return bool
	 */
	private static function is_valid_country_code( $code ) {
		return is_string( $code ) && 2 === strlen( $code ) && ctype_alpha( $code );
	}

	/**
	 * Run the detection chain.
	 *
	 * @param string $ip          IP address to resolve.
	 * @param bool   $own_address Whether $ip is the address of the request
	 *                            being served. The request's headers are read
	 *                            only then; another address is resolved from
	 *                            the address-based sources alone.
	 * @return string Two-letter country code or empty string.
	 */
	private static function detect_country( $ip, $own_address = true ) {
		// The edge says the forwarded address is a Tor relay. There is no
		// country to resolve, and the relay's must not be resolved instead:
		// every source below would answer with the relay's, and the visitor
		// would be routed by where the relay sits. No vote is cast.
		if ( $own_address && self::cf_reports_anonymiser() ) {
			return '';
		}

		// Collect every source's vote so we can both (a) return the first
		// non-empty value (existing priority order) and (b) compare them
		// for the consensus check below. The associative shape preserves
		// source attribution for the disagreement log line.
		$votes = array();

		// 1. Cloudflare CF-IPCountry header.
		if ( $own_address && apply_filters( 'faz_trust_cf_ipcountry_header', false ) && self::cf_peer_is_trusted() && ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			if ( self::is_valid_country_code( $code ) && 'XX' !== $code ) {
				$votes['cf']  = $code;
			}
		}

		// 2. Apache mod_geoip — gated by faz_trust_geoip_country_code, mirroring
		//    the CF-IPCountry opt-in pattern above. On installs that don't
		//    actually run mod_geoip on Apache, a misconfigured fastcgi_param
		//    can let a request header pollute $_SERVER['GEOIP_COUNTRY_CODE']
		//    and silently steer geo-routing. Default false → no behaviour
		//    change for real mod_geoip installs that opt in explicitly.
		if (
			$own_address
			&& apply_filters( 'faz_trust_geoip_country_code', false )
			&& ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] )
		) {
			$code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) );
			if ( self::is_valid_country_code( $code ) && 'XX' !== $code ) {
				$votes['geoip'] = $code;
			}
		}

		// 3. PHP GeoIP extension.
		if ( function_exists( 'geoip_country_code_by_name' ) ) {
			$code = @geoip_country_code_by_name( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			// 'XX' is the extension's "unknown" sentinel, and the
			// extension outranks MMDB in the priority order below. Accepting it
			// as a vote would let a database-less extension bury a real MMDB
			// answer: get_visitor_country() turns the winning 'XX' back into ''
			// and the visitor is routed as ungeolocated. Same exclusion the
			// CF-IPCountry and mod_geoip branches above already apply.
			$code = is_string( $code ) ? strtoupper( $code ) : '';
			if ( self::is_valid_country_code( $code ) && 'XX' !== $code ) {
				$votes['php_geoip'] = $code;
			}
		}

		// 4. MaxMind GeoLite2 MMDB database (local, no external API calls).
		$mmdb = self::lookup_mmdb( $ip );
		if ( ! empty( $mmdb ) ) {
			$votes['mmdb'] = $mmdb;
		}

		// Issue #110 — multi-source disagreement detection.
		// When ≥2 sources resolved a country and they disagree, log a
		// debug-level warning (only when WP_DEBUG / WP_DEBUG_LOG is on,
		// to avoid polluting production logs) AND optionally enforce
		// consensus via the faz_country_detection_consensus filter.
		if ( count( $votes ) >= 2 ) {
			$unique = array_unique( array_values( $votes ) );
			if ( count( $unique ) > 1 ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					$pairs = array();
					foreach ( $votes as $src => $code ) {
						$pairs[] = $src . '=' . $code;
					}
					error_log( 'FAZ geolocation source disagreement: ' . implode( ', ', $pairs ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				/**
				 * Require agreement between detection sources before
				 * returning a country. When this filter resolves to true
				 * AND ≥2 sources disagree, detect_country() returns ''
				 * (the fail-open default — banner is shown to everyone).
				 * Off by default to preserve the CF-first priority order.
				 *
				 * F109 fix (1.14.3): the third argument is gone. The
				 * F019 attempt to "anonymise" the IP by passing
				 * `wp_hash($ip, 'nonce')` produced a stable per-salt
				 * HMAC that filter consumers could still use as a
				 * persistent identifier — functionally PII-equivalent.
				 * The consensus-enforcement decision doesn't NEED the
				 * IP at all: the `$votes` map (CF / geoip / php_geoip /
				 * mmdb → country code) carries everything needed to
				 * decide whether to enforce. Plugins that genuinely
				 * need the IP for their own logic should hook
				 * `faz_visitor_country` (which already exposes it for
				 * test fixtures and trusted overrides) rather than
				 * piggyback on the consensus filter.
				 *
				 * @since 1.14.0
				 * @since 1.14.3 third `$ip` / `$ip_hash` argument removed (F109)
				 * @param bool   $require_consensus Default false.
				 * @param array  $votes             Per-source country votes (cf, geoip, php_geoip, mmdb).
				 */
				if ( apply_filters( 'faz_country_detection_consensus', false, $votes ) ) {
					return '';
				}
			}
		}

		// Priority order preserved: CF → GEOIP header → PHP ext → MMDB.
		foreach ( array( 'cf', 'geoip', 'php_geoip', 'mmdb' ) as $src ) {
			if ( ! empty( $votes[ $src ] ) ) {
				return $votes[ $src ];
			}
		}
		return '';
	}

	/**
	 * Look up an IP in the local MMDB database file.
	 *
	 * @param string $ip IP address.
	 * @return string Two-letter country code or empty string.
	 */
	private static function lookup_mmdb( $ip ) {
		$db_path = self::get_database_path();
		if ( empty( $db_path ) ) {
			return '';
		}

		try {
			if ( null === self::$mmdb_reader ) {
				self::$mmdb_reader = new Mmdb_Reader( $db_path );
			}
			$code = self::$mmdb_reader->country( $ip );
			$code = strtoupper( $code );
			if ( self::is_valid_country_code( $code ) ) {
				return $code;
			}
		} catch ( \Exception $e ) {
			error_log( 'FAZ GeoLite2 lookup error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return '';
	}

	/**
	 * Get the path to the MMDB database file, if it exists.
	 *
	 * Checks (in order):
	 *   0. The FAZ_MAXMIND_DB_PATH constant (operator-provided own .mmdb)
	 *   1. The configured GeoLite2 edition (Country or City)
	 *   2. The other GeoLite2 edition as a legacy fallback
	 *   3. wp-content/uploads/faz-cookie-manager/dbip-country-lite.mmdb
	 *
	 * Honouring the configured edition also keeps activation deterministic if
	 * an obsolete file cannot be deleted after a Country/City switch.
	 *
	 * The FAZ_MAXMIND_DB_PATH constant is checked first so that an operator who
	 * points it at their own database actually gets that file used by the
	 * resolver — previously the constant only silenced the admin "Geo source
	 * not configured" notice without ever being read here, so a site that set
	 * it still resolved every visitor to "unknown".
	 *
	 * @return string Full path to the database file, or empty string.
	 */
	public static function get_database_path() {
		if ( null !== self::$db_path_cache ) {
			return self::$db_path_cache;
		}
		$upload_dir = self::get_data_dir();
		$preferred  = self::geolite2_edition();
		$alternate  = ( 'GeoLite2-City' === $preferred ) ? 'GeoLite2-Country' : 'GeoLite2-City';
		$candidates = array();
		if ( defined( 'FAZ_MAXMIND_DB_PATH' ) && FAZ_MAXMIND_DB_PATH ) {
			$candidates[] = (string) FAZ_MAXMIND_DB_PATH;
		}
		$candidates[] = $upload_dir . $preferred . '.mmdb';
		$candidates[] = $upload_dir . $alternate . '.mmdb';
		$candidates[] = $upload_dir . 'dbip-country-lite.mmdb';

		foreach ( $candidates as $path ) {
			if ( file_exists( $path ) && is_readable( $path ) && self::is_valid_mmdb( $path ) ) {
				self::$db_path_cache = $path;
				return $path;
			}
		}

		self::$db_path_cache = '';
		return '';
	}

	/**
	 * The marker that ends every MMDB metadata section (MaxMind DB format,
	 * also used by the DB-IP lite distribution). Mirrors
	 * Mmdb_Reader::METADATA_MARKER; duplicated here so this static check has no
	 * dependency on the reader being autoloaded yet.
	 */
	const MMDB_METADATA_MARKER = "\xab\xcd\xefMaxMind.com";

	/**
	 * Cheap sanity check that a candidate file is an MMDB database.
	 *
	 * The resolver previously returned the first existing, readable candidate —
	 * so a FAZ_MAXMIND_DB_PATH (or downloaded file) that exists but is corrupt or
	 * the wrong format was preferred over a valid database, and Mmdb_Reader then
	 * threw on first lookup with no fallback (every visitor resolved to
	 * "unknown"). Skipping invalid candidates lets get_database_path() fall
	 * through to the next valid database instead.
	 *
	 * The metadata marker lives in the last 128 KB of an MMDB file per the spec,
	 * so this only reads that tail — never the whole multi-MB database.
	 *
	 * @param string $path Candidate file path.
	 * @return bool True when the file ends with a recognisable MMDB metadata marker.
	 */
	private static function is_valid_mmdb( $path ) {
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size || $size < strlen( self::MMDB_METADATA_MARKER ) ) {
			return false;
		}
		$tail_len = (int) min( $size, 131072 );
		$handle   = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return false;
		}
		if ( $tail_len < $size ) {
			@fseek( $handle, -$tail_len, SEEK_END ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$tail = @fread( $handle, $tail_len ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.PHP.NoSilencedErrors.Discouraged
		@fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged
		return is_string( $tail ) && false !== strpos( $tail, self::MMDB_METADATA_MARKER );
	}

	/**
	 * Check if a GeoIP database is available.
	 *
	 * @return bool
	 */
	public static function has_database() {
		return '' !== self::get_database_path();
	}

	/**
	 * Whether this install has any source that could resolve a visitor country.
	 *
	 * Configuration, not resolution: it answers "could a lookup happen here",
	 * never "did it succeed for this visitor". Cheap enough for the front end —
	 * the only I/O is get_database_path(), which memoises per request.
	 *
	 * This used to live in Frontend as a private method while the admin asked a
	 * different question a few files away (`! empty( maxmind_license_key ) ||
	 * has_database()`). Two answers to one question is how a site ends up being
	 * told geo-routing is configured while the resolver returns '' for every
	 * visitor, so there is one predicate now and both callers use it.
	 *
	 * The sources counted here are the ones get_visitor_country() can route
	 * on: the trust filters, mod_geoip, the PHP extension, the MaxMind
	 * database AND a callback on `faz_visitor_country`. The callback was
	 * missing until 1.34.2, so source_status() reported it as working and
	 * the admin saw routing live while this predicate — the page-cache veto's
	 * — said "no source" and let a shared cache keep one jurisdiction's render
	 * for the next visitor.
	 *
	 * @since 1.34.0
	 * @since 1.34.2 A `faz_visitor_country` callback counts as a source.
	 * @return bool
	 */
	public static function has_country_source() {
		$has_source = false;

		// The trust filters alone, NOT the headers being present on this
		// request: a cache warmer reaching the origin directly carries neither,
		// and answering "no source" for it would cache a page rendered for an
		// unknown country and then serve it to a real visitor whose country the
		// edge did identify.
		// Deliberately NOT cf_peer_is_trusted() here, even though the resolvers
		// require it. That check answers "may THIS request assert the header";
		// this function answers "is a country source configured for this site",
		// and the two differ exactly where it matters — see the paragraph
		// above. A review round asked for parity and it would be the wrong
		// parity: a warmer would be told "no source", the page would be cached
		// as country-unknown, and real visitors whose country the edge did
		// identify would be served it.
		if ( apply_filters( 'faz_trust_cf_ipcountry_header', false ) ) {
			$has_source = true;
		}
		if ( ! $has_source && apply_filters( 'faz_trust_geoip_country_code', false ) ) {
			$has_source = true;
		}
		if ( ! $has_source && self::mod_geoip_configured() ) {
			$has_source = true;
		}
		if ( ! $has_source && self::php_geoip_has_country_database() ) {
			$has_source = true;
		}
		// A callback on faz_visitor_country is a source in its own right:
		// get_visitor_country() passes every resolved value through it and
		// routes on whatever comes back, and it is the only route offered to an
		// edge that is not Cloudflare. source_status() already counted it as
		// 'country_filter', so the admin was told routing was live while this
		// predicate said "no source" and the page cache kept the first
		// visitor's render for the next one. Presence is the test, as there:
		// the visitor's request, not this one, is what the callback answers.
		if ( ! $has_source && function_exists( 'has_filter' ) && has_filter( 'faz_visitor_country' ) ) {
			$has_source = true;
		}
		// get_database_path() returns '' unless a GeoLite2 MMDB exists, is
		// readable AND passes its format check, so a non-empty path is the same
		// question as "can this install do a local lookup".
		if ( ! $has_source ) {
			try {
				$has_source = ( '' !== (string) self::get_database_path() );
			} catch ( \Throwable $e ) {
				$has_source = false;
			}
		}

		/**
		 * Override the country-source detection.
		 *
		 * A publisher whose edge injects a country by another means can force
		 * this true; one who knows their stack can never resolve a country can
		 * force it false and keep their page cache.
		 *
		 * @since 1.30.0
		 * @param bool $has_source Whether a country source was detected.
		 */
		return (bool) apply_filters( 'faz_has_country_signal_source', $has_source );
	}

	/**
	 * Whether the PECL geoip extension can actually answer a country lookup.
	 *
	 * Loading the extension does not install its database: on many hosts the
	 * module is compiled in and every lookup returns false. Counting the bare
	 * function as a source made has_country_source() true there, and the page
	 * cache was vetoed on every request of a site that resolves no country at
	 * all. geoip_db_avail() is the extension's own answer and does no lookup;
	 * the IPv6 country edition counts too, since the resolver passes either
	 * address family. Without geoip_db_avail() (a very old build) the
	 * function's presence is all there is to go on, as before.
	 *
	 * @since 1.34.2
	 * @return bool
	 */
	public static function php_geoip_has_country_database() {
		if ( ! function_exists( 'geoip_country_code_by_name' ) ) {
			return false;
		}
		if ( ! function_exists( 'geoip_db_avail' ) ) {
			return true;
		}
		foreach ( array( 'GEOIP_COUNTRY_EDITION', 'GEOIP_COUNTRY_EDITION_V6' ) as $edition ) {
			if ( defined( $edition ) && @geoip_db_avail( constant( $edition ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the extension warns when the edition is unknown.
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether Apache mod_geoip looks configured on this install.
	 *
	 * @since 1.34.0
	 * @return bool
	 */
	public static function mod_geoip_configured() {
		if ( function_exists( 'apache_get_modules' ) ) {
			$modules = apache_get_modules();
			if ( is_array( $modules ) && in_array( 'mod_geoip', $modules, true ) ) {
				return true;
			}
		}
		return ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- presence test only.
	}

	/**
	 * Describe the country source: which one, configured, and actually working.
	 *
	 * `configured` and `working` are deliberately separate. A saved MaxMind
	 * licence key means someone intended country resolution; it does not mean a
	 * database was ever downloaded, nor that the file survived. The admin used
	 * to treat the key alone as proof and told sites with no database at all
	 * that their geo source was configured.
	 *
	 * The probe performs a real lookup and is NEVER run from a front-end
	 * request: the MMDB result is cached in a non-autoloaded option against a
	 * fingerprint of the database file, so it is recomputed when the file
	 * changes and otherwise read once per admin page.
	 *
	 * `working` must mean what the resolver will actually do, so this follows
	 * get_visitor_country() / detect_country() and not the mere presence of a
	 * signal. Two consequences:
	 *
	 *  - mod_geoip is only USED when `faz_trust_geoip_country_code` is on
	 *    (default off: a misconfigured fastcgi_param can let a request header
	 *    pollute GEOIP_COUNTRY_CODE). A loaded module without that filter is
	 *    therefore reported as configured but NOT working, with its own reason
	 *    (`module_untrusted`), because the fix is a one-line filter rather than
	 *    a download — and calling it "working" told an administrator their
	 *    site was routing by country while every visitor got the fallback.
	 *  - A site that supplies the country itself is working even though none of
	 *    the built-in sources is: `faz_visitor_country` is the resolver's last
	 *    word (`country_filter`), and `faz_has_country_signal_source` forced
	 *    true is the documented way to say an edge injects the country by other
	 *    means (`signal_forced_by_filter`).
	 *  - The Cloudflare opt-in is only half of what the resolver checks: the
	 *    header is read when `faz_peer_is_cloudflare()` also vouches for the
	 *    peer, and on the common origin that restores the visitor's address
	 *    (nginx real_ip_header, Apache mod_remoteip, most managed hosts) that
	 *    is false until the operator adds `faz_remote_addr_rewritten_by_cloudflare`.
	 *    The opt-in alone would then report "working" while every visitor got
	 *    the fallback, silently. So on an interactive admin request the status
	 *    probes the predicate on the request in hand — the administrator's own
	 *    traffic crosses the same edge as the visitors', so it is a valid
	 *    sample — and reports `peer_not_cloudflare` when it fails. The probe is
	 *    skipped on cron, WP-CLI, AJAX and REST, which carry no header and
	 *    would report the context they were asked from, not the configuration;
	 *    there the answer stays the declared one, as has_country_source()
	 *    argues it must.
	 *
	 * @since 1.34.0
	 * @param bool $force Recompute even when a fresh cached result exists.
	 * @return array{source:string,configured:bool,working:bool,reason:string}
	 */
	public static function source_status( $force = false ) {
		// Site-level, like has_country_source(): what the operator declared,
		// not whether the request in hand may assert it. A status that flipped
		// to "not configured" on a WP-CLI or cache-warmer request — neither of
		// which carries the header — would be reporting the context it was
		// asked from, not the configuration.
		$declared = apply_filters( 'faz_trust_cf_ipcountry_header', false )
			? 'cloudflare'
			: ( apply_filters( 'faz_trust_geoip_country_code', false ) ? 'mod_geoip' : '' );
		if ( '' !== $declared ) {
			// A trust filter is a developer asserting the edge supplies the
			// country. The header itself cannot be probed — it arrives on
			// visitor requests, not on this one — but for Cloudflare the PEER
			// can be, and only on a request an administrator is looking at:
			// their browser reaches the origin the way a visitor's does, so a
			// peer the predicate rejects here is one it rejects for visitors.
			$status = array(
				'source'     => $declared,
				'configured' => true,
				'working'    => true,
				'reason'     => 'declared_by_filter',
			);
			if ( ! ( 'cloudflare' === $declared && self::is_interactive_admin_request() && ! self::cf_peer_is_trusted() ) ) {
				return $status;
			}
			// The declared edge is not vouching for this peer, so CF-IPCountry
			// will not be read. That is not the whole answer, and returning it
			// as one was the defect: the resolver still consults
			// faz_visitor_country, the detected sources and the signal filter,
			// and detect_country() uses every one of them for visitors. A site
			// with the Cloudflare opt-in, a working MaxMind database and an
			// IP-restoring origin — the very topology this docblock describes —
			// was reported as degraded, which inerted the region presets and
			// the custom country list in banner.php and told the administrator
			// every visitor got the fallback, while MMDB routed them all
			// correctly. So the failed probe is held and answered only if
			// nothing below works.
			$peer_failed = array(
				'source'     => $declared,
				'configured' => true,
				'working'    => false,
				'reason'     => 'peer_not_cloudflare',
			);
		}
		// get_visitor_country() passes every resolved value — including '' —
		// through this filter and routes on whatever comes back, so a callback
		// on it is a country source in its own right, whatever else is missing.
		// Presence is all that can be checked from here: the visitor's request,
		// not this admin one, is what the callback answers.
		if ( function_exists( 'has_filter' ) && has_filter( 'faz_visitor_country' ) ) {
			return array(
				'source'     => 'filter',
				'configured' => true,
				'working'    => true,
				'reason'     => 'country_filter',
			);
		}

		$status = self::detected_status( $force );
		if ( $status['working'] ) {
			return $status;
		}

		// Forced true by the integrator: "my edge injects a country by another
		// means". Asked with false as the input, so only a callback that ASSERTS
		// a source counts — one that merely passes the value through does not.
		// Forced false is not consulted: it keeps the page cache, it does not
		// stop detect_country() from resolving.
		if ( (bool) apply_filters( 'faz_has_country_signal_source', false ) ) {
			return array(
				'source'     => 'filter',
				'configured' => true,
				'working'    => true,
				'reason'     => 'signal_forced_by_filter',
			);
		}

		// Nothing the resolver consults is working, so the failed Cloudflare
		// peer probe IS the answer — and it is the more useful one, because it
		// names the filter the operator is missing instead of a download.
		if ( isset( $peer_failed ) ) {
			return $peer_failed;
		}

		return $status;
	}

	/**
	 * Whether this is a request an administrator is looking at.
	 *
	 * The peer probe in source_status() is only meaningful on such a request:
	 * cron, WP-CLI and AJAX/REST calls reach the origin from places visitors
	 * never come from, and a verdict formed there would describe the caller,
	 * not the site. The function_exists guards keep the predicate loadable in
	 * the standalone unit harness, where it reads as "not interactive".
	 *
	 * @since 1.34.1
	 * @return bool
	 */
	private static function is_interactive_admin_request() {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		return true;
	}

	/**
	 * The built-in sources detect_country() consults without a trust filter.
	 *
	 * @since 1.34.0

	 * @param bool $force Recompute the MMDB probe even when a cached result exists.
	 * @return array{source:string,configured:bool,working:bool,reason:string}
	 */
	private static function detected_status( $force ) {
		$php_geoip_status = null;
		if ( function_exists( 'geoip_country_code_by_name' ) ) {
			// Loading the extension does not install its database. Probe local
			// lookups just as we do for MMDB, and keep trying MMDB if they fail.
			$working = false;
			foreach ( array( '8.8.8.8', '1.1.1.1' ) as $probe_ip ) {
				try {
					$code = @geoip_country_code_by_name( $probe_ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- optional extension warns when its database is absent.
				} catch ( \Throwable $e ) {
					$code = false;
				}
				$code = is_string( $code ) ? strtoupper( $code ) : '';
				if ( self::is_valid_country_code( $code ) && 'XX' !== $code ) {
					$working = true;
					break;
				}
			}
			$php_geoip_status = array(
				'source'     => 'php_geoip',
				'configured' => true,
				'working'    => $working,
				'reason'     => $working ? 'probe_ok' : 'php_geoip_probe_failed',
			);
			if ( $working ) {
				return $php_geoip_status;
			}
		}

		$path = '';
		try {
			$path = (string) self::get_database_path();
		} catch ( \Throwable $e ) {
			$path = '';
		}
		$settings = get_option( 'faz_settings', array() );
		$has_key  = is_array( $settings ) && ! empty( $settings['geolocation']['maxmind_license_key'] );

		if ( '' === $path ) {
			// mod_geoip is on the server but detect_country() ignores it until
			// `faz_trust_geoip_country_code` is enabled. Configured, because the
			// signal is there; not working, because nothing reads it.
			//
			// This is checked BEFORE the failed extension on purpose. Both
			// sources are present and both are unusable, so the question is
			// which repair to put in front of the admin: enabling one filter,
			// or installing a GeoIP database (or buying a MaxMind key). The
			// cheapest fix wins. Without mod_geoip the extension failure is
			// still reported, with its own instruction.
			if ( self::mod_geoip_configured() ) {
				return array(
					'source'     => 'mod_geoip',
					'configured' => true,
					'working'    => false,
					'reason'     => 'module_untrusted',
				);
			}
			if ( null !== $php_geoip_status ) {
				return $php_geoip_status;
			}
			return array(
				'source'     => $has_key ? 'mmdb' : 'none',
				'configured' => (bool) $has_key,
				'working'    => false,
				// The distinction the admin needs: a key with no database is a
				// download that never happened or a file that was removed, not
				// an unconfigured site.
				'reason'     => $has_key ? 'key_without_database' : 'no_source',
			);
		}

		$fingerprint = self::database_fingerprint( $path );
		$cached      = get_option( 'faz_geo_source_probe', array() );
		if (
			! $force
			&& is_array( $cached )
			&& isset( $cached['fingerprint'], $cached['working'] )
			&& $cached['fingerprint'] === $fingerprint
		) {
			return array(
				'source'     => 'mmdb',
				'configured' => true,
				'working'    => (bool) $cached['working'],
				'reason'     => (bool) $cached['working'] ? 'probe_ok' : 'probe_failed',
			);
		}

		// Two fixed public addresses, and the assertion is only that SOME valid
		// country comes back — never a specific one. Allocations move between
		// countries, so asserting "8.8.8.8 is US" would turn a correct database
		// into a reported failure the day that changes.
		$working = false;
		foreach ( array( '8.8.8.8', '1.1.1.1' ) as $probe_ip ) {
			// \Throwable, not \Exception. lookup_mmdb() guards itself with a
			// catch( \Exception ), which does not catch an \Error — and a
			// truncated or wrong-format database makes the reader raise exactly
			// that. This probe exists to REPORT that a database does not work, so
			// it must not be the thing that takes the admin page down while doing
			// so. Same shape as the ftp_fput() fatal fixed in 1.33.0: a fatal is
			// not a falsy return, and a guard that only catches Exception cannot
			// see it.
			try {
				$code = self::lookup_mmdb( $probe_ip );
			} catch ( \Throwable $e ) {
				$code = '';
			}
			if ( is_string( $code ) && self::is_valid_country_code( $code ) && 'XX' !== $code ) {
				$working = true;
				break;
			}
		}
		update_option(
			'faz_geo_source_probe',
			array(
				'fingerprint' => $fingerprint,
				'working'     => $working,
				'checked_at'  => time(),
			),
			false
		);

		return array(
			'source'     => 'mmdb',
			'configured' => true,
			'working'    => $working,
			'reason'     => $working ? 'probe_ok' : 'probe_failed',
		);
	}

	/**
	 * Identity of the database file, so a changed file invalidates the probe.
	 *
	 * @since 1.34.0
	 * @param string $path Absolute path to the MMDB file.
	 * @return string
	 */
	private static function database_fingerprint( $path ) {
		if ( '' === $path || ! @file_exists( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return '';
		}
		// filesize()/filemtime() read PHP's per-request stat cache. extract_mmdb()
		// replaces the active database with rename() inside a single request, so
		// without this the fingerprint can describe the file that was there before
		// the download — and the stale probe verdict then persists until the file
		// changes AGAIN. Dropping the entry for this one path is enough.
		clearstatcache( true, $path );
		$size  = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return md5( $path . '|' . (string) $size . '|' . (string) $mtime );
	}

	/**
	 * Get the data directory for geolocation files.
	 *
	 * @return string Directory path with trailing slash.
	 */
	public static function get_data_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'faz-cookie-manager/';
	}

	/**
	 * Get info about the currently installed database.
	 *
	 * @return array { file: string, size: int, modified: string } or empty array.
	 */
	public static function get_database_info() {
		$path = self::get_database_path();
		if ( empty( $path ) ) {
			return array();
		}

		return array(
			'file'     => basename( $path ),
			'size'     => filesize( $path ),
			'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $path ) ),
		);
	}

	/**
	 * Download and install a MaxMind GeoLite2 database.
	 *
	 * The edition is the publisher's choice (Settings → GeoIP Database):
	 * 'GeoLite2-Country' (default, small, country-level only) or 'GeoLite2-City'
	 * (larger, adds region/subdivision data needed by sub-national rulesets).
	 * Requires a MaxMind license key (free registration at maxmind.com).
	 *
	 * @param string      $license_key      MaxMind license key.
	 * @param string|null $edition_override Optional explicit edition
	 *                                      ('GeoLite2-Country' | 'GeoLite2-City').
	 *                                      When omitted, the stored setting wins.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public static function download_database( $license_key, $edition_override = null ) {
		if ( empty( $license_key ) ) {
			return new \WP_Error( 'faz_geo_no_key', __( 'MaxMind license key is required.', 'faz-cookie-manager' ) );
		}

		$edition = ( is_string( $edition_override )
			&& in_array( $edition_override, array( 'GeoLite2-City', 'GeoLite2-Country' ), true ) )
			? $edition_override
			: self::geolite2_edition();
		$license_key = sanitize_text_field( $license_key );
		$url         = add_query_arg(
			array(
				'edition_id'  => $edition,
				'license_key' => $license_key,
				'suffix'      => 'tar.gz',
			),
			'https://download.maxmind.com/app/geoip_download'
		);

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php'; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
		}

		$tmp_file = \download_url( $url, 120 );
		if ( is_wp_error( $tmp_file ) ) {
			return new \WP_Error(
				'faz_geo_download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Download failed: %s', 'faz-cookie-manager' ),
					$tmp_file->get_error_message()
				)
			);
		}

		$result = self::extract_mmdb( $tmp_file, $edition );
		@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink

		return $result;
	}

	/**
	 * Decide which GeoLite2 edition to download and read.
	 *
	 * Driven by the publisher's explicit choice in Settings → GeoIP Database
	 * (`geolocation.geolite2_edition`): 'city' → GeoLite2-City, anything else →
	 * GeoLite2-Country (the data-minimising default). Sub-national enforcement
	 * such as Quebec Law 25 requires an explicit City selection (or a trusted
	 * region header); enabling runtime rulesets never silently downloads the
	 * larger database. Overridable via `faz_geolite2_edition`.
	 *
	 * @return string 'GeoLite2-City' or 'GeoLite2-Country'.
	 */
	private static function geolite2_edition() {
		$choice = '';
		if ( class_exists( '\\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings' ) ) {
			$settings = new \FazCookie\Admin\Modules\Settings\Includes\Settings();
			$raw      = $settings->get( 'geolocation', 'geolite2_edition' );
			$choice   = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		}
		if ( 'city' === $choice ) {
			$default = 'GeoLite2-City';
		} elseif ( 'country' === $choice ) {
			$default = 'GeoLite2-Country';
		} else {
			// Setting not yet saved (legacy): keep the data-minimising Country DB.
			// An admin who needs sub-national resolution picks City explicitly or
			// forces it via the faz_geolite2_edition filter below.
			$default = 'GeoLite2-Country';
		}
		/**
		 * Filter the GeoLite2 edition the downloader fetches and the lookup reads.
		 *
		 * @since 1.17.2
		 * @param string $edition 'GeoLite2-City' or 'GeoLite2-Country'.
		 */
		$edition = (string) apply_filters( 'faz_geolite2_edition', $default );
		return in_array( $edition, array( 'GeoLite2-City', 'GeoLite2-Country' ), true ) ? $edition : $default;
	}

	/**
	 * Extract a .mmdb file from a MaxMind .tar.gz archive.
	 *
	 * @param string $tar_gz_path Path to the downloaded .tar.gz file.
	 * @param string $edition     Edition downloaded ('GeoLite2-City' or
	 *                            'GeoLite2-Country'); determines the dest filename.
	 * @return true|\WP_Error
	 */
	private static function extract_mmdb( $tar_gz_path, $edition = 'GeoLite2-City' ) {
		if ( ! class_exists( 'PharData' ) ) {
			return new \WP_Error( 'faz_geo_no_phar', __( 'PharData extension is required to extract the database.', 'faz-cookie-manager' ) );
		}

		$edition  = in_array( $edition, array( 'GeoLite2-City', 'GeoLite2-Country' ), true ) ? $edition : 'GeoLite2-City';
		$data_dir = self::get_data_dir();
		wp_mkdir_p( $data_dir );
		$tar_path = '';
		$staged   = '';

		try {
			$phar     = new \PharData( $tar_gz_path );
			$tar      = $phar->decompress();
			$tar_path = $tar->getPath();

			// Find the .mmdb file inside the archive.
			$found = false;
			foreach ( new \RecursiveIteratorIterator( $tar ) as $entry ) {
				if ( '.mmdb' === substr( $entry->getFilename(), -5 ) ) {
					$dest   = $data_dir . $edition . '.mmdb';
					$staged = $data_dir . '.' . $edition . '.' . wp_generate_uuid4() . '.tmp';
					if ( ! copy( $entry->getPathname(), $staged ) ) {
						return new \WP_Error( 'faz_geo_copy_failed', __( 'Failed to copy database file.', 'faz-cookie-manager' ) );
					}

					// Validate before replacing the active database. A valid tar
					// can still contain a corrupt or wrong-edition .mmdb; without
					// this check the endpoint reported success and broke lookups.
					$reader        = new Mmdb_Reader( $staged );
					$database_type = $reader->database_type();
					$expected_type = ( 'GeoLite2-City' === $edition ) ? 'City' : 'Country';
					unset( $reader );
					if (
						'' === $database_type
						|| 1 !== preg_match( '/(?:^|-)' . preg_quote( $expected_type, '/' ) . '$/i', $database_type )
					) {
						throw new \RuntimeException(
							'Downloaded MMDB edition mismatch: expected '
							. $expected_type
							. ', received '
							. ( '' !== $database_type ? $database_type : 'unknown' )
							. '.'
						);
					}

					// The staging file is in the same directory, so rename is an
					// atomic replacement on the filesystems used by WordPress.
					// If activation fails, the previous database remains intact.
					// WP_Filesystem is intentionally NOT used here: it offers no
					// atomic move primitive, and a non-atomic copy+unlink would
					// expose a window where the active .mmdb is truncated/half
					// written. The staging file is a sibling of $dest, so a raw
					// rename() is atomic on POSIX and NTFS — the property we need.
					if ( ! @rename( $staged, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- intentional atomic same-dir swap; WP_Filesystem has no atomic move.
						return new \WP_Error( 'faz_geo_activate_failed', __( 'Failed to copy database file.', 'faz-cookie-manager' ) );
					}
					$staged = '';
					$found = true;
					// Remove the OTHER edition's DB so a Country↔City switch
					// doesn't leave a stale file that get_database_path() (which
					// now prefers the configured edition) can fall back to stale
					// data. Only the just-written edition should survive.
					$superseded = array(
						$data_dir . 'GeoLite2-City.mmdb',
						$data_dir . 'GeoLite2-Country.mmdb',
						$data_dir . 'dbip-country-lite.mmdb',
					);
					foreach ( $superseded as $old ) {
						if ( $old !== $dest && file_exists( $old ) ) {
							@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
						}
					}
					break;
				}
			}

			if ( ! $found ) {
				return new \WP_Error( 'faz_geo_no_mmdb', __( 'No .mmdb file found in the archive.', 'faz-cookie-manager' ) );
			}

			// Every memo may contain an answer from the database we just replaced,
			// including country/region results resolved earlier in this request.
			self::reset_runtime_cache();
			// The source probe is PERSISTED, not a memo, so resetting the
			// request-scoped caches does not reach it. Two databases can share a
			// size and a second-resolution mtime, which would let a stale
			// "not working" verdict survive a successful download; deleting the
			// row makes the next admin page re-probe the file installed now.
			delete_option( 'faz_geo_source_probe' );

			return true;
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'faz_geo_extract_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Extraction failed: %s', 'faz-cookie-manager' ),
					$e->getMessage()
				)
			);
		} finally {
			if ( '' !== $staged && file_exists( $staged ) ) {
				@unlink( $staged ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			if ( '' !== $tar_path && file_exists( $tar_path ) ) {
				@unlink( $tar_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}
}
