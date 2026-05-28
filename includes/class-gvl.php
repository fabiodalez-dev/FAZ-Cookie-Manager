<?php
/**
 * IAB Global Vendor List (GVL) manager.
 *
 * Downloads, caches, and serves the IAB TCF v3 Global Vendor List.
 * IAB requires CMPs to download the GVL server-side and serve it
 * from their own domain — client-side MUST NOT fetch from
 * vendor-list.consensu.org directly.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gvl {

	const GVL_URL      = 'https://vendor-list.consensu.org/v3/vendor-list.json';
	const PURPOSES_URL = 'https://vendor-list.consensu.org/v3/purposes-%s.json';
	const OPTION_KEY   = 'faz_gvl_data';
	const META_KEY     = 'faz_gvl_meta';
	const PURPOSES_KEY = 'faz_gvl_purposes';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cached GVL data (avoids repeated get_option per request).
	 *
	 * @var array|false|null
	 */
	private $cached_data = null;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Download the GVL JSON from IAB, validate, store in WP option and file.
	 *
	 * @return array { success: bool, message: string, version: int, vendor_count: int }
	 */
	public function download() {
		$response = wp_remote_get(
			self::GVL_URL,
			array(
				'timeout'    => 60,
				'user-agent' => 'FAZCookieManager/1.0 (WordPress)',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success'      => false,
				'message'      => $response->get_error_message(),
				'version'      => 0,
				'vendor_count' => 0,
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array(
				'success'      => false,
				'message'      => sprintf( 'HTTP %d from IAB', $code ),
				'version'      => 0,
				'vendor_count' => 0,
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['vendorListVersion'] ) || empty( $data['vendors'] ) ) {
			return array(
				'success'      => false,
				'message'      => 'Invalid GVL JSON structure',
				'version'      => 0,
				'vendor_count' => 0,
			);
		}

		$version      = absint( $data['vendorListVersion'] );
		$vendor_count = count( $data['vendors'] );

		// Store in WP option (autoload=false — large data).
		update_option( self::OPTION_KEY, $data, false );
		$this->cached_data = $data;

		// Store metadata.
		update_option( self::META_KEY, array(
			'version'      => $version,
			'vendor_count' => $vendor_count,
			'last_updated' => current_time( 'mysql' ),
			'timestamp'    => time(),
		), false );

		// Also save raw JSON to file for frontend access.
		if ( ! $this->save_to_file( 'vendor-list.json', $body ) ) {
			return array(
				'success'      => false,
				'message'      => 'GVL saved to option but file write failed',
				'version'      => $version,
				'vendor_count' => $vendor_count,
			);
		}

		return array(
			'success'      => true,
			'message'      => sprintf( 'GVL v%d downloaded (%d vendors)', $version, $vendor_count ),
			'version'      => $version,
			'vendor_count' => $vendor_count,
		);
	}

	/**
	 * Download purpose translations for a given language.
	 *
	 * @param string $lang ISO 639-1 language code (e.g. 'it', 'de').
	 * @return array { success: bool, message: string }
	 */
	public function download_purposes( $lang ) {
		$lang = strtolower( sanitize_text_field( $lang ) );
		if ( ! preg_match( '/^[a-z]{2}$/', $lang ) ) {
			return array( 'success' => false, 'message' => 'Invalid language code' );
		}

		$url      = sprintf( self::PURPOSES_URL, $lang );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'user-agent' => 'FAZCookieManager/1.0 (WordPress)',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array( 'success' => false, 'message' => sprintf( 'HTTP %d for purposes-%s', $code, $lang ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['purposes'] ) ) {
			return array( 'success' => false, 'message' => 'Invalid purposes JSON' );
		}

		// Store per-language purposes.
		$all_purposes          = get_option( self::PURPOSES_KEY, array() );
		$all_purposes[ $lang ] = $data['purposes'];
		update_option( self::PURPOSES_KEY, $all_purposes, false );

		// Save file for reference.
		if ( ! $this->save_to_file( 'purposes-' . $lang . '.json', $body ) ) {
			return array( 'success' => false, 'message' => 'Purposes saved to option but file write failed' );
		}

		return array( 'success' => true, 'message' => sprintf( 'Purposes for "%s" downloaded', $lang ) );
	}

	/**
	 * Get cached GVL data.
	 *
	 * @return array|false
	 */
	public function get_data() {
		if ( null === $this->cached_data ) {
			$this->cached_data = get_option( self::OPTION_KEY, false );
		}
		return $this->cached_data;
	}

	/**
	 * Get GVL version from cached data.
	 *
	 * @return int
	 */
	public function get_version() {
		$meta = $this->get_meta();
		return isset( $meta['version'] ) ? absint( $meta['version'] ) : 0;
	}

	/**
	 * Get a single vendor by ID.
	 *
	 * @param int $id Vendor ID.
	 * @return array|null
	 */
	public function get_vendor( $id ) {
		$data = $this->get_data();
		if ( ! $data || ! isset( $data['vendors'][ $id ] ) ) {
			return null;
		}
		return $data['vendors'][ $id ];
	}

	/**
	 * Get all vendors, or a subset by IDs.
	 *
	 * @param array|null $ids Vendor IDs to filter, or null for all.
	 * @return array
	 */
	public function get_vendors( $ids = null ) {
		$data = $this->get_data();
		if ( ! $data || ! isset( $data['vendors'] ) ) {
			return array();
		}
		if ( null === $ids ) {
			return $data['vendors'];
		}
		$result = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( isset( $data['vendors'][ $id ] ) ) {
				$result[ $id ] = $data['vendors'][ $id ];
			}
		}
		return $result;
	}

	/**
	 * Get all 11 purposes (with translations if available).
	 *
	 * @param string $lang Language code for translations.
	 * @return array
	 */
	public function get_purposes( $lang = '' ) {
		// Try translated purposes first.
		if ( ! empty( $lang ) ) {
			$all_purposes = get_option( self::PURPOSES_KEY, array() );
			$lang         = strtolower( $lang );
			if ( isset( $all_purposes[ $lang ] ) ) {
				return $all_purposes[ $lang ];
			}
		}
		// Fall back to GVL English purposes.
		$data = $this->get_data();
		return ( $data && isset( $data['purposes'] ) ) ? $data['purposes'] : array();
	}

	/**
	 * Get special purposes.
	 *
	 * @return array
	 */
	public function get_special_purposes() {
		$data = $this->get_data();
		return ( $data && isset( $data['specialPurposes'] ) ) ? $data['specialPurposes'] : array();
	}

	/**
	 * Get features.
	 *
	 * @return array
	 */
	public function get_features() {
		$data = $this->get_data();
		return ( $data && isset( $data['features'] ) ) ? $data['features'] : array();
	}

	/**
	 * Get special features.
	 *
	 * @return array
	 */
	public function get_special_features() {
		$data = $this->get_data();
		return ( $data && isset( $data['specialFeatures'] ) ) ? $data['specialFeatures'] : array();
	}

	/**
	 * Check if GVL data has been downloaded.
	 *
	 * @return bool
	 */
	public function has_data() {
		return false !== get_option( self::OPTION_KEY, false );
	}

	/**
	 * Suggest IAB GVL vendor IDs from the cookies the scanner has
	 * already catalogued for this site. Reviewer Niharika asked for a
	 * "scan for ad vendors" shortcut so the 700+ vendor table doesn't
	 * have to be browsed by hand. This helper does the lookup:
	 *
	 *   1. Read every distinct cookie domain stored in wp_faz_cookies.
	 *   2. Match each domain against the curated domain → vendor-ID
	 *      map shipped at admin/modules/gvl/data/domain-to-vendor.json.
	 *      Matching is "domain suffix-aware" — `subdomain.linkedin.com`
	 *      matches the `linkedin.com` entry without requiring an exact
	 *      string equality (cookies frequently live on a subdomain).
	 *   3. Intersect the resulting vendor IDs with the IDs that the
	 *      downloaded GVL actually carries. Vendors that have left the
	 *      list (de-registered, withdrawn) are dropped silently so the
	 *      caller does not end up with stale IDs.
	 *
	 * The mapping JSON is intentionally conservative: every entry is a
	 * vendor whose IAB GVL ID is published in the official registry
	 * (https://vendor-list.consensu.org/v3/vendor-list.json) and whose
	 * tracking domains are industry-recognised. Community contributions
	 * (PRs adding entries with citations) are welcome — schema is
	 * `{ "mappings": { "<domain>": [<vendor_id>, …] } }`.
	 *
	 * Returns an array of vendor IDs (sorted, unique) ready to be passed
	 * to the existing /faz/v1/gvl/selected POST endpoint OR shown as
	 * "suggested" checkboxes in the admin UI. Empty array when the
	 * cookies table is empty / scanner never ran / no domain match.
	 *
	 * @return int[]
	 */
	public function suggest_vendor_ids_from_scanned_cookies() {
		global $wpdb;
		$table = $wpdb->prefix . 'faz_cookies';
		// Safety: bail if the schema isn't installed yet (fresh install before
		// activation hooks fire, or test harness that drops tables between runs).
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array();
		}
		// Restrict to scanner-discovered rows only — `discovered = 1` is
		// the column the cookie scanner sets when it actually OBSERVED a
		// cookie on the site (versus rows the admin added by hand from
		// the Cookies admin page). The auto-detect feature is meant to
		// surface vendors backing real network traffic; a manually-added
		// row is, by definition, already known to the admin and should
		// not retroactively trigger a TCF-vendor suggestion. CodeRabbit
		// PR #127 review (2026-05-27) flagged the broader query as a
		// source of potential false positives — confirmed.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$domains = (array) $wpdb->get_col( "SELECT DISTINCT domain FROM `{$table}` WHERE domain <> '' AND discovered = 1" );
		if ( empty( $domains ) ) {
			return array();
		}

		$map = self::load_domain_vendor_map();
		if ( empty( $map ) ) {
			return array();
		}

		// Cache lookup keys for the suffix scan. The map keys are
		// `linkedin.com`, `googletagmanager.com` etc.; the cookie
		// `domain` column tends to carry the same plus optional leading
		// dot (`.linkedin.com`) or subdomain (`m.linkedin.com`).
		$map_keys = array_keys( $map );
		$matched  = array();
		foreach ( $domains as $raw_domain ) {
			$d = strtolower( ltrim( (string) $raw_domain, '.' ) );
			if ( '' === $d ) {
				continue;
			}
			// Exact match first (cheapest, covers most rows).
			if ( isset( $map[ $d ] ) ) {
				foreach ( (array) $map[ $d ] as $vid ) {
					$matched[ (int) $vid ] = true;
				}
				continue;
			}
			// Suffix match: cookie domain `m.linkedin.com` against map key `linkedin.com`.
			// Guard against false positives by requiring a dot before the
			// candidate suffix — `notlinkedin.com` must NOT match
			// `linkedin.com`. The "." prefix achieves that.
			foreach ( $map_keys as $key ) {
				if ( substr( $d, -( strlen( $key ) + 1 ) ) === '.' . $key ) {
					foreach ( (array) $map[ $key ] as $vid ) {
						$matched[ (int) $vid ] = true;
					}
				}
			}
		}

		if ( empty( $matched ) ) {
			return array();
		}

		// Filter out IDs that the currently-downloaded GVL doesn't carry
		// any longer (vendor de-registered, GVL never downloaded yet).
		$gvl_data = $this->get_data();
		if ( $gvl_data && isset( $gvl_data['vendors'] ) && is_array( $gvl_data['vendors'] ) ) {
			$live_ids = array_map( 'intval', array_keys( $gvl_data['vendors'] ) );
			$matched  = array_intersect_key( $matched, array_flip( $live_ids ) );
		}

		$ids = array_keys( $matched );
		sort( $ids );
		return $ids;
	}

	/**
	 * Load the bundled domain → vendor-ID map. The file lives outside
	 * the plugin's PHP autoload path on purpose: it is plain data the
	 * community is invited to extend via PRs without touching PHP.
	 *
	 * Cached in a static var per request — the file is < 5 KB so a
	 * cold read costs microseconds, but the static cache keeps repeated
	 * calls (e.g. from the REST endpoint + the admin notice on the
	 * same load) at zero I/O.
	 *
	 * @return array<string, int[]>
	 */
	private static function load_domain_vendor_map() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$file = dirname( __DIR__ ) . '/admin/modules/gvl/data/domain-to-vendor.json';
		if ( ! is_readable( $file ) ) {
			$cached = array();
			return $cached;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin-shipped JSON data file, not user content.
		$json = (string) file_get_contents( $file );
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || empty( $decoded['mappings'] ) || ! is_array( $decoded['mappings'] ) ) {
			$cached = array();
			return $cached;
		}
		// Normalise: lowercase domain keys, drop the `_comment` / `_schema_version` meta.
		$out = array();
		foreach ( $decoded['mappings'] as $domain => $vendors ) {
			if ( ! is_string( $domain ) || '' === $domain ) {
				continue;
			}
			if ( ! is_array( $vendors ) ) {
				continue;
			}
			$ids = array();
			foreach ( $vendors as $v ) {
				$vid = (int) $v;
				if ( $vid > 0 ) {
					$ids[] = $vid;
				}
			}
			if ( ! empty( $ids ) ) {
				$out[ strtolower( $domain ) ] = $ids;
			}
		}
		$cached = $out;
		return $cached;
	}

	/**
	 * Get download metadata.
	 *
	 * @return array { version: int, vendor_count: int, last_updated: string, timestamp: int }
	 */
	public function get_meta() {
		return get_option( self::META_KEY, array() );
	}

	/**
	 * Get the local URL where frontend can fetch the GVL.
	 *
	 * @return string
	 */
	public function get_gvl_url() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['baseurl'] ) . 'faz-cookie-manager/gvl/vendor-list.json';
	}

	/**
	 * Save content to a file in the GVL data directory.
	 *
	 * @param string $filename File name.
	 * @param string $content  File content.
	 * @return bool
	 */
	private function save_to_file( $filename, $content ) {
		$dir = $this->get_gvl_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Ensure index.php exists for directory listing protection.
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$path = $dir . sanitize_file_name( $filename );
		// `wp_tempnam()` lives in wp-admin/includes/file.php which is NOT
		// auto-loaded on REST requests (only inside the admin screen).
		// The "Update GVL Now" button in the plugin settings hits a REST
		// endpoint, so without this the call fatals with
		// "undefined function FazCookie\Includes\wp_tempnam()" — PHP first
		// resolves the unqualified name in the current namespace.
		// The leading `\` forces global lookup so the correct function is
		// found once the file has been loaded. Reported in issue #85
		// (vvvamik, WP 6.9.4 + PHP 8.4.17).
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp_path = \wp_tempnam( basename( $path ), $dir );
		if ( ! $tmp_path ) {
			return false;
		}
		$bytes = file_put_contents( $tmp_path, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes ) {
			wp_delete_file( $tmp_path );
			return false;
		}
		if ( ! self::move_file( $tmp_path, $path ) ) {
			wp_delete_file( $tmp_path );
			return false;
		}
		return true;
	}

	/**
	 * Move a file using WP_Filesystem when available, falling back to rename().
	 *
	 * @param string $source      Source path.
	 * @param string $destination Destination path.
	 * @return bool
	 */
	private static function move_file( $source, $destination ) {
		global $wp_filesystem;
		if ( $wp_filesystem && is_callable( array( $wp_filesystem, 'move' ) ) ) {
			if ( $wp_filesystem->move( $source, $destination, true ) ) {
				return true;
			}
			if ( ! file_exists( $source ) ) {
				return false;
			}
		}
		return rename( $source, $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	}

	/**
	 * Get the GVL data directory path.
	 *
	 * @return string Directory path with trailing slash.
	 */
	private function get_gvl_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'faz-cookie-manager/gvl/';
	}

	/**
	 * Cron callback: update GVL if IAB is enabled.
	 */
	public static function cron_update() {
		$settings = get_option( 'faz_settings' );
		$enabled  = isset( $settings['iab']['enabled'] ) && $settings['iab']['enabled'];
		if ( ! $enabled ) {
			return;
		}

		$gvl    = self::get_instance();
		$result = $gvl->download();
		if ( ! $result['success'] ) {
			error_log( 'FAZ GVL cron: download failed - ' . $result['message'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Download purposes for current language.
		$lang = function_exists( 'faz_default_language' ) ? faz_default_language() : 'en';
		if ( 'en' !== $lang ) {
			$purposes_result = $gvl->download_purposes( $lang );
			if ( ! $purposes_result['success'] ) {
				error_log( 'FAZ GVL cron: purposes download failed - ' . $purposes_result['message'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}
