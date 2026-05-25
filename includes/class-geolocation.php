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
	 *   1. wp-content/uploads/faz-cookie-manager/GeoLite2-Country.mmdb
	 *   2. wp-content/uploads/faz-cookie-manager/dbip-country-lite.mmdb
	 *
	 * @return string Full path to the database file, or empty string.
	 */
	public static function get_database_path() {
		$upload_dir = self::get_data_dir();
		$candidates = array(
			$upload_dir . 'GeoLite2-Country.mmdb',
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
	 * Download and install a MaxMind GeoLite2-Country database.
	 *
	 * Requires a MaxMind license key (free registration at maxmind.com).
	 *
	 * @param string $license_key MaxMind license key.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public static function download_database( $license_key ) {
		if ( empty( $license_key ) ) {
			return new \WP_Error( 'faz_geo_no_key', __( 'MaxMind license key is required.', 'faz-cookie-manager' ) );
		}

		$license_key = sanitize_text_field( $license_key );
		$url         = add_query_arg(
			array(
				'edition_id'  => 'GeoLite2-Country',
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

		$result = self::extract_mmdb( $tmp_file );
		@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink

		return $result;
	}

	/**
	 * Extract a .mmdb file from a MaxMind .tar.gz archive.
	 *
	 * @param string $tar_gz_path Path to the downloaded .tar.gz file.
	 * @return true|\WP_Error
	 */
	private static function extract_mmdb( $tar_gz_path ) {
		if ( ! class_exists( 'PharData' ) ) {
			return new \WP_Error( 'faz_geo_no_phar', __( 'PharData extension is required to extract the database.', 'faz-cookie-manager' ) );
		}

		$data_dir = self::get_data_dir();
		wp_mkdir_p( $data_dir );

		try {
			$phar    = new \PharData( $tar_gz_path );
			$tar     = $phar->decompress();
			$tar_path = $tar->getPath();

			// Find the .mmdb file inside the archive.
			$found = false;
			foreach ( new \RecursiveIteratorIterator( $tar ) as $entry ) {
				if ( '.mmdb' === substr( $entry->getFilename(), -5 ) ) {
					$dest = $data_dir . 'GeoLite2-Country.mmdb';
					if ( ! copy( $entry->getPathname(), $dest ) ) {
						return new \WP_Error( 'faz_geo_copy_failed', __( 'Failed to copy database file.', 'faz-cookie-manager' ) );
					}
					$found = true;
					break;
				}
			}

			// Clean up decompressed tar.
			@unlink( $tar_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink

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
		}
	}
}
