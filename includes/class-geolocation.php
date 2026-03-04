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
	 * @return string Two-letter country code or empty string.
	 */
	public static function get_country() {
		$ip = self::get_client_ip();
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
	 * Get the client's real IP address.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// X-Forwarded-For can contain multiple IPs — use the first.
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				$ip = trim( $ip[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
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
		// 1. Cloudflare CF-IPCountry header.
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			if ( self::is_valid_country_code( $code ) && 'XX' !== $code ) {
				return $code;
			}
		}

		// 2. Apache mod_geoip.
		if ( ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) {
			$code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) );
			if ( self::is_valid_country_code( $code ) ) {
				return $code;
			}
		}

		// 3. PHP GeoIP extension.
		if ( function_exists( 'geoip_country_code_by_name' ) ) {
			$code = @geoip_country_code_by_name( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $code ) {
				$code = strtoupper( $code );
				if ( self::is_valid_country_code( $code ) ) {
					return $code;
				}
			}
		}

		// 4. MaxMind GeoLite2 MMDB database (local, no external API calls).
		$code = self::lookup_mmdb( $ip );
		if ( ! empty( $code ) ) {
			return $code;
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
