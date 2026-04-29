<?php
/**
 * WP-CLI commands for FAZ Cookie Manager.
 *
 * Provides `wp faz scan`, `wp faz export`, `wp faz import`, and `wp faz status`.
 *
 * @package FazCookie
 * @since   1.7.0
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

use WP_CLI;
use WP_CLI\Utils;

/**
 * Manage the FAZ Cookie Manager plugin.
 *
 * @class   WP_CLI_Commands
 * @package FazCookie
 */
class WP_CLI_Commands {

	/**
	 * Run a cookie scan.
	 *
	 * ## OPTIONS
	 *
	 * [--max-pages=<number>]
	 * : Maximum pages to scan. Default from settings or 100.
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp faz scan
	 *     wp faz scan --max-pages=50
	 *     wp faz scan --format=json
	 *
	 * @subcommand scan
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function scan( $args, $assoc_args ) {
		$settings  = get_option( 'faz_settings' );
		$max_pages = isset( $assoc_args['max-pages'] )
			? absint( $assoc_args['max-pages'] )
			: ( isset( $settings['scanner']['max_pages'] ) ? absint( $settings['scanner']['max_pages'] ) : 100 );

		WP_CLI::log( "Starting cookie scan (max {$max_pages} pages)..." );

		$controller = \FazCookie\Admin\Modules\Scanner\Includes\Controller::get_instance();
		$result     = $controller->run_scan( $max_pages );

		if ( ! $result ) {
			WP_CLI::error( 'Scan failed. Check error logs for details.' );
			return;
		}

		WP_CLI::success( sprintf(
			'Scan complete. Pages scanned: %d, Cookies found: %d',
			$result['pages_scanned'] ?? 0,
			$result['total_cookies'] ?? 0
		) );
	}

	/**
	 * Export plugin settings to a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Output file path. Default: faz-settings-{date}.json in current directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp faz export
	 *     wp faz export /tmp/faz-backup.json
	 *
	 * @subcommand export
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function export_settings( $args, $assoc_args ) {
		global $wpdb;

		$settings     = get_option( 'faz_settings' );
		$gcm_settings = get_option( 'faz_gcm_settings' );

		// Strip sensitive data.
		if ( is_array( $settings ) && isset( $settings['geolocation']['maxmind_license_key'] ) ) {
			$settings['geolocation']['maxmind_license_key'] = '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WP-CLI export runs once on demand; $wpdb->prefix + literal table name, no user input. Caching irrelevant.
		$banners = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}faz_banners", ARRAY_A );
		if ( is_array( $banners ) ) {
			foreach ( $banners as &$b ) {
				if ( isset( $b['settings'] ) ) {
					$b['settings'] = json_decode( $b['settings'], true );
				}
				if ( isset( $b['contents'] ) ) {
					$b['contents'] = json_decode( $b['contents'], true );
				}
			}
			unset( $b );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WP-CLI export runs once on demand; $wpdb->prefix + literal table name, no user input.
		$categories = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}faz_cookie_categories", ARRAY_A );
		if ( is_array( $categories ) ) {
			foreach ( $categories as &$c ) {
				if ( isset( $c['name'] ) ) {
					$c['name'] = json_decode( $c['name'], true );
				}
				if ( isset( $c['description'] ) ) {
					$c['description'] = json_decode( $c['description'], true );
				}
			}
			unset( $c );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WP-CLI export runs once on demand; $wpdb->prefix + literal table name, no user input.
		$cookies = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}faz_cookies", ARRAY_A );
		if ( is_array( $cookies ) ) {
			foreach ( $cookies as &$ck ) {
				if ( isset( $ck['description'] ) ) {
					$decoded = json_decode( $ck['description'], true );
					$ck['description'] = ( null !== $decoded ) ? $decoded : $ck['description'];
				}
				if ( isset( $ck['meta'] ) ) {
					$decoded = json_decode( $ck['meta'], true );
					$ck['meta'] = ( null !== $decoded ) ? $decoded : $ck['meta'];
				}
			}
			unset( $ck );
		}

		$export = array(
			'plugin'       => 'faz-cookie-manager',
			'version'      => FAZ_VERSION,
			'exported_at'  => current_time( 'c' ),
			'site_url'     => home_url(),
			'settings'     => $settings,
			'gcm_settings' => $gcm_settings,
			'banners'      => $banners ? $banners : array(),
			'categories'   => $categories ? $categories : array(),
			'cookies'      => $cookies ? $cookies : array(),
		);

		$json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		// Resolve the destination path. By default, write to
		// `wp_upload_dir()/faz-cookie-manager/exports/faz-settings-YYYY-MM-DD.json`,
		// creating the directory if needed. If the user passed an explicit
		// path on the command line, it must be either:
		//   - a bare filename (treated as relative to the default exports
		//     directory above), or
		//   - an absolute path that resolves inside `wp_upload_dir()`.
		// Any other path is rejected — wp.org compliance ("plugins must
		// not write outside allowed locations").
		$upload         = wp_upload_dir( null, false );
		$exports_base   = trailingslashit( $upload['basedir'] ) . 'faz-cookie-manager/exports';
		if ( ! is_dir( $exports_base ) ) {
			wp_mkdir_p( $exports_base );
		}
		$default_name   = 'faz-settings-' . gmdate( 'Y-m-d' ) . '.json';
		$requested_path = isset( $args[0] ) ? (string) $args[0] : '';
		$file           = '';
		if ( '' === $requested_path ) {
			$file = trailingslashit( $exports_base ) . $default_name;
		} elseif ( false === strpos( $requested_path, '/' ) && false === strpos( $requested_path, DIRECTORY_SEPARATOR ) ) {
			// Bare filename — append to the default exports directory.
			$file = trailingslashit( $exports_base ) . sanitize_file_name( $requested_path );
		} else {
			// Caller passed a (possibly relative) path. Resolve it and
			// confirm the result lives inside `wp_upload_dir()`.
			$resolved      = wp_normalize_path( $requested_path );
			$abs_target    = '/' === substr( $resolved, 0, 1 ) || preg_match( '#^[A-Za-z]:[/\\\\]#', $resolved )
				? $resolved
				: trailingslashit( $exports_base ) . $resolved;
			$abs_target    = wp_normalize_path( $abs_target );
			$uploads_root  = wp_normalize_path( trailingslashit( $upload['basedir'] ) );
			if ( 0 !== strpos( $abs_target, $uploads_root ) ) {
				WP_CLI::error( 'Refusing to write outside wp_upload_dir() (' . $uploads_root . '). Pass a bare filename to use the default exports/ directory, or an absolute path inside uploads.' );
				return;
			}
			// Ensure parent directory exists.
			$parent_dir = dirname( $abs_target );
			if ( ! is_dir( $parent_dir ) ) {
				wp_mkdir_p( $parent_dir );
			}
			$file = $abs_target;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI context, target path validated above to live inside wp_upload_dir().
		if ( false === file_put_contents( $file, $json ) ) {
			WP_CLI::error( "Failed to write to {$file}" );
			return;
		}

		WP_CLI::success( "Settings exported to {$file} (" . size_format( strlen( $json ) ) . ')' );
	}

	/**
	 * Import plugin settings from a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the JSON export file.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp faz import faz-settings-2026-03-17.json
	 *     wp faz import backup.json --yes
	 *
	 * @subcommand import
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function import_settings( $args, $assoc_args ) {
		$file = $args[0];

		if ( ! file_exists( $file ) ) {
			WP_CLI::error( "File not found: {$file}" );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI context, local file.
		$json = file_get_contents( $file );
		$data = json_decode( $json, true );

		if ( ! $data || empty( $data['plugin'] ) || 'faz-cookie-manager' !== $data['plugin'] ) {
			WP_CLI::error( 'Invalid FAZ Cookie Manager export file.' );
			return;
		}

		WP_CLI::log( "Export from: {$data['site_url']} (v{$data['version']}, {$data['exported_at']})" );
		WP_CLI::log( sprintf(
			'Contains: %d banner(s), %d category/ies, %d cookie(s)',
			count( $data['banners'] ?? array() ),
			count( $data['categories'] ?? array() ),
			count( $data['cookies'] ?? array() )
		) );

		WP_CLI::confirm( 'This will overwrite your current settings. Continue?', $assoc_args );

		// Ensure we have an admin user context for the internal REST request.
		// WP-CLI runs with full privileges but rest_do_request() checks
		// permission callbacks that require an authenticated user with
		// manage_options capability.
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
			if ( ! empty( $admins ) ) {
				wp_set_current_user( $admins[0]->ID );
			} else {
				WP_CLI::error( 'No administrator user found. Cannot authenticate the internal REST request.' );
				return;
			}
		}

		// Reuse the REST import logic via an internal request.
		$request = new \WP_REST_Request( 'POST', '/faz/v1/settings/import' );
		$request->set_body( $json );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			WP_CLI::error( 'Import failed: ' . $response->as_error()->get_error_message() );
			return;
		}

		$result = $response->get_data();
		WP_CLI::success( 'Imported: ' . implode( ', ', $result['imported'] ?? array() ) );
	}

	/**
	 * Show plugin status and configuration.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp faz status
	 *     wp faz status --format=json
	 *
	 * @subcommand status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		global $wpdb;
		$format = $assoc_args['format'] ?? 'table';

		$settings     = get_option( 'faz_settings' );
		$gcm_settings = get_option( 'faz_gcm_settings' );

		// Table names are trusted ($wpdb->prefix is set by WordPress core).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WP-CLI status command; $wpdb->prefix + literal plugin table names, no user input. Caching irrelevant for an on-demand status read.
		$banner_count   = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}faz_banners" );
		$cookie_count   = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookies" );
		$category_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookie_categories" );
		$consent_count  = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}faz_consent_logs" );
		// phpcs:enable

		$rows = array(
			array( 'Key' => 'Plugin Version',     'Value' => FAZ_VERSION ),
			array( 'Key' => 'WordPress Version',  'Value' => get_bloginfo( 'version' ) ),
			array( 'Key' => 'PHP Version',         'Value' => PHP_VERSION ),
			array( 'Key' => 'Banner Enabled',      'Value' => ! empty( $settings['banner_control']['status'] ) ? 'Yes' : 'No' ),
			array( 'Key' => 'Consent Logging',     'Value' => ! empty( $settings['consent_logs']['status'] ) ? 'Yes' : 'No' ),
			array( 'Key' => 'GCM Enabled',         'Value' => ! empty( $gcm_settings['status'] ) ? 'Yes' : 'No' ),
			array( 'Key' => 'IAB TCF Enabled',     'Value' => ! empty( $settings['iab']['enabled'] ) ? 'Yes' : 'No' ),
			array( 'Key' => 'Pageview Tracking',   'Value' => ! empty( $settings['pageview_tracking'] ) ? 'Yes' : 'No' ),
			array( 'Key' => 'Auto Scan',           'Value' => ! empty( $settings['scanner']['auto_scan'] ) ? ( $settings['scanner']['scan_frequency'] ?? 'On' ) : 'Off' ),
			array( 'Key' => 'Geo-Targeting',       'Value' => ! empty( $settings['geolocation']['geo_targeting'] ) ? 'Yes' : 'No' ),
			array( 'Key' => 'Per-Service Consent', 'Value' => ! empty( $settings['banner_control']['per_service_consent'] ) ? 'Yes' : 'No' ),
			array( 'Key' => 'Bot Detection',       'Value' => ( ! isset( $settings['banner_control']['hide_from_bots'] ) || ! empty( $settings['banner_control']['hide_from_bots'] ) ) ? 'Yes' : 'No' ),
			array( 'Key' => 'Banners',             'Value' => $banner_count ),
			array( 'Key' => 'Cookie Categories',   'Value' => $category_count ),
			array( 'Key' => 'Cookies',             'Value' => $cookie_count ),
			array( 'Key' => 'Consent Logs (total)', 'Value' => $consent_count ),
		);

		Utils\format_items( $format, $rows, array( 'Key', 'Value' ) );
	}
}
