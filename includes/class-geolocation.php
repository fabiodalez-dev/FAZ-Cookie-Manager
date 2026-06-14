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
	 * Get the visitor's ISO 3166-1 alpha-2 country code.
	 *
	 * @param string|null $ip_override Optional explicit IP to look up instead of
	 *                                 the request's client IP. Used by Geo_Detector
	 *                                 to honour the `$ip_override` parameter of its
	 *                                 own detect() method (test fixtures, cron jobs,
	 *                                 batch GeoIP lookups) — without this, the geo
	 *                                 fallback chain silently re-resolved REMOTE_ADDR
	 *                                 and ignored the caller's explicit IP.
	 *                                 Pass null/empty to fall back to client IP.
	 * @return string Two-letter country code or empty string.
	 */
	public static function get_country( $ip_override = null ) {
		$ip = is_string( $ip_override ) && '' !== $ip_override ? $ip_override : self::get_client_ip();
		if ( empty( $ip ) || in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
			return '';
		}

		// Check transient cache.
		$cache_key = 'faz_geo_' . md5( $ip );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$country = self::detect_country( $ip );

		// Cache for 1 hour.
		if ( ! empty( $country ) ) {
			set_transient( $cache_key, $country, HOUR_IN_SECONDS );
		}

		return $country;
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
		// Mirror the CF branch above: 'XX' is the Cloudflare-defined
		// "anonymous proxy / Tor / unknown" sentinel. A third-party filter
		// could reintroduce it from a different detection source, and we
		// must not route it as a real country — is_valid_country_code()
		// only checks the [A-Z]{2} shape and would accept 'XX'.
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
			&& ! empty( $_SERVER['HTTP_CF_REGION_CODE'] )
			&& ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] )
		) {
			$cc = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			$rr = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_REGION_CODE'] ) ) );
			if ( self::is_valid_country_code( $cc ) && 'XX' !== $cc && 1 === preg_match( '/^[A-Z0-9]{1,3}$/', $rr ) ) {
				$region = $cc . '-' . $rr;
			}
		}

		if ( '' === $region ) {
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

		$cache_key = 'faz_georeg_' . md5( $ip );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
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
	 * @param string $ip Client IP address.
	 * @return string Two-letter country code or empty string.
	 */
	private static function detect_country( $ip ) {
		// Collect every source's vote so we can both (a) return the first
		// non-empty value (existing priority order) and (b) compare them
		// for the consensus check below. The associative shape preserves
		// source attribution for the disagreement log line.
		$votes = array();

		// 1. Cloudflare CF-IPCountry header.
		if ( apply_filters( 'faz_trust_cf_ipcountry_header', false ) && ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
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
			apply_filters( 'faz_trust_geoip_country_code', false )
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
			if ( $code ) {
				$code = strtoupper( $code );
				if ( self::is_valid_country_code( $code ) ) {
					$votes['php_geoip'] = $code;
				}
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
	 *   1. The configured GeoLite2 edition (Country or City)
	 *   2. The other GeoLite2 edition as a legacy fallback
	 *   3. wp-content/uploads/faz-cookie-manager/dbip-country-lite.mmdb
	 *
	 * Honouring the configured edition also keeps activation deterministic if
	 * an obsolete file cannot be deleted after a Country/City switch.
	 *
	 * @return string Full path to the database file, or empty string.
	 */
	public static function get_database_path() {
		$upload_dir = self::get_data_dir();
		$preferred  = self::geolite2_edition();
		$alternate  = ( 'GeoLite2-City' === $preferred ) ? 'GeoLite2-Country' : 'GeoLite2-City';
		$candidates = array(
			$upload_dir . $preferred . '.mmdb',
			$upload_dir . $alternate . '.mmdb',
			$upload_dir . 'dbip-country-lite.mmdb',
		);

		foreach ( $candidates as $path ) {
			if ( file_exists( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
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
	 * GeoLite2-Country (the default, so existing installs are unchanged). A
	 * legacy install with the setting still unset falls back to the runtime
	 * geo-routing flag (City when on). Overridable via `faz_geolite2_edition`.
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
			// Setting not yet saved (legacy). 1.18.2 HOTFIX: previously this
			// honoured the faz_geo_ruleset_runtime flag (City when on), but that
			// runtime is now hard-disabled — selecting City here would download a
			// ~60 MB DB the disabled runtime never uses. Default to Country; an
			// admin who wants City picks it explicitly (saved option above) or
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

			// Reset cached reader so it picks up the new file.
			self::$mmdb_reader = null;

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
