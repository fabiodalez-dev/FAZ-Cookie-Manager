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

		$path     = $dir . sanitize_file_name( $filename );
		$tmp_path = wp_tempnam( $path );
		if ( ! $tmp_path ) {
			return false;
		}
		$bytes = file_put_contents( $tmp_path, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes ) {
			@unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		if ( ! rename( $tmp_path, $path ) ) {
			@unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		return true;
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
