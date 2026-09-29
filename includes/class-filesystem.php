<?php
/**
 * WordPress file system API.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 * @package    FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class Filesystem.
 */
class Filesystem {

	/**
	 * Store instance of class Filesystem
	 *
	 * @since 3.0.0.
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get instance of class Filesystem
	 *
	 * @since 3.0.0
	 * @return self
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get WP_Filesystem instance.
	 *
	 * @since 3.0.0
	 * @return \WP_Filesystem_Base
	 */
	public function get_filesystem() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';// phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound

			$context = apply_filters( 'request_filesystem_credentials_context', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

			add_filter( 'request_filesystem_credentials', array( $this, 'request_filesystem_credentials' ) );

			$creds = request_filesystem_credentials( site_url(), '', false, $context, null );

			$connected = \WP_Filesystem( $creds, $context );
			remove_filter( 'request_filesystem_credentials', array( $this, 'request_filesystem_credentials' ) );

			// WP_Filesystem() assigns $wp_filesystem BEFORE it connects, and
			// leaves the object in place when the connection fails. Ignoring
			// the return value therefore hands back a live-looking object whose
			// transport is dead: on a host where the PHP user does not own the
			// files and the FTP extension is loaded, get_filesystem_method()
			// picks 'ftpext', our credentials are `true` rather than a host and
			// password, WP_Filesystem() returns false with `empty_hostname`,
			// and the first write reaches ftp_fput( null ) — a TypeError, which
			// is a fatal, not a falsy return, so no caller's fallback can catch
			// it. That turned every frontend request needing a new asset into a
			// 500 (#300).
			if ( ! $connected ) {
				return null;
			}
		}

		// The global may also have been left unusable by an earlier attempt in
		// this request, or by another plugin, in which case the branch above is
		// skipped entirely. Validate the object itself rather than trusting how
		// it got here.
		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return null;
		}
		if ( ! empty( $wp_filesystem->errors ) && ! empty( $wp_filesystem->errors->errors ) ) {
			return null;
		}
		// Only the direct transport can be trusted without performing I/O to
		// prove it. A remote transport that is genuinely connected would work,
		// but it cannot be told apart from one that merely looks connected, and
		// the cost of being wrong is a fatal on every page view. Callers that
		// write plugin assets already fall back to inlining them, so refusing
		// here degrades output rather than breaking the site.
		if ( 'direct' !== $wp_filesystem->method ) {
			return null;
		}

		// Note: we no longer define `FS_CHMOD_DIR` / `FS_CHMOD_FILE` from
		// here. Removed in 1.13.11 for wp.org compliance ("plugins must
		// not change global behaviour"). WordPress core falls back to
		// 0755 / 0644 internally when those constants are unset, which is
		// the same value we used to set, so the runtime behaviour is
		// identical for any environment that doesn't already define them.
		// Hosts that need different permissions can still set the
		// constants in `wp-config.php` and we'll honour them.

		return $wp_filesystem;
	}

	/**
	 * Sets credentials to true.
	 *
	 * @since 3.0.0
	 */
	public function request_filesystem_credentials() {
		return true;
	}

	/**
	 * Checks to see if the site has SSL enabled or not.
	 *
	 * @since 3.0.0
	 * @return bool
	 */
	public function is_ssl() {
		if ( is_ssl() ) {
			return true;
		} elseif ( 0 === stripos( get_option( 'siteurl' ), 'https://' ) ) {
			return true;
		} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Update Filesystem status.
	 *
	 * @since 3.0.0
	 * @param boolean $status status for filesystem access.
	 * @return void
	 */
	public function update_filesystem_access_status( $status ) {
		update_option( 'faz_file_write_access', $status );
	}

	/**
	 * Check if filesystem has write access.
	 *
	 * @since 3.0.0
	 * @return boolean True if filesystem has access, false if does not have access.
	 */
	public function can_access_filesystem() {
		return (bool) get_option( 'faz_file_write_access', true );
	}

	/**
	 * Reset filesystem access status.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function reset_filesystem_access_status() {
		delete_option( 'faz_file_write_access' );
	}

	/**
	 * Returns an array of paths for the upload directory
	 * of the current site.
	 *
	 * @since 3.0.0
	 * @param String $assets_dir directory name to be created in the WordPress uploads directory.
	 * @return array
	 */
	public function get_uploads_dir( $assets_dir ) {
		$wp_info = wp_upload_dir( null, false );

		// SSL workaround.
		if ( $this->is_ssl() ) {
			$wp_info['baseurl'] = str_ireplace( 'http://', 'https://', $wp_info['baseurl'] );
		}

		// Build the paths.
		$dir_info = array(
			'path' => $wp_info['basedir'] . '/' . $assets_dir . '/',
			'url'  => $wp_info['baseurl'] . '/' . $assets_dir . '/',
		);

		return apply_filters( 'cli__get_assets_uploads_dir', $dir_info );
	}

	/**
	 * Delete file from the filesystem.
	 *
	 * @since 3.0.0
	 * @param String  $file Path to the file or directory.
	 * @param boolean $recursive If set to true, changes file group recursively.
	 * @param string|false $type Type of resource. 'f' for file, 'd' for directory.
	 * @return void
	 */
	public function delete( $file, $recursive = false, $type = false ) {
		$file_system = $this->get_filesystem();
		if ( ! $file_system instanceof \WP_Filesystem_Base ) {
			return;
		}
		$file_system->delete( $file, $recursive, $type );
	}

	/**
	 * Adds contents to the file.
	 *
	 * @param  string $file_path  Gets the assets path info.
	 * @param  string $style_data   Gets the CSS data.
	 * @since  3.0.0
	 * @return bool $put_content returns false if file write is not successful.
	 */
	public function put_contents( $file_path, $style_data ) {
		$file_system = $this->get_filesystem();
		if ( ! $file_system instanceof \WP_Filesystem_Base ) {
			return false;
		}
		return $file_system->put_contents( $file_path, $style_data );
	}

	/**
	 * Get contents of the file.
	 *
	 * @param  string $file_path  Gets the assets path info.
	 * @since  3.0.0
	 * @return bool $get_contents Gets te file contents.
	 */
	public function get_contents( $file_path ) {
		$file_system = $this->get_filesystem();
		if ( $file_system instanceof \WP_Filesystem_Base && empty( $file_system->errors->errors ) ) {
			$file_path = str_replace( ABSPATH, $this->abspath(), $file_path );
			if ( $this->get_filesystem()->exists( $file_path ) && $this->get_filesystem()->is_readable( $file_path ) ) {
				return $this->get_filesystem()->get_contents( $file_path );
			}
		} else {
			if ( file_exists( $file_path ) && is_file( $file_path ) ) {
				return @file_get_contents( $file_path );
			}
		}
		return false;
	}

	/**
	 * Return absolute file path
	 *
	 * @since 3.0.0
	 * @return string
	 */
	public function abspath() {
		$file_system = $this->get_filesystem();
		// Behaviour deliberately unchanged beyond the null guard. The test
		// below reads as "if any error was recorded", but `errors` is a WP_Error
		// object that is always present, so it is always true and this method
		// has only ever returned ABSPATH. Correcting it would start returning
		// $file_system->abspath(), whose value feeds a str_replace() over file
		// paths in get_contents() — a semantic change that does not belong in a
		// fix for a fatal. Reported separately.
		if ( ! $file_system instanceof \WP_Filesystem_Base ) {
			return ABSPATH;
		}
		if ( $file_system->errors ) {
			return ABSPATH;
		}
		return $file_system->abspath();
	}

}
