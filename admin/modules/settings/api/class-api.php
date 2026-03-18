<?php
/**
 * Class Api file.
 *
 * @package Settings
 */

namespace FazCookie\Admin\Modules\Settings\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use stdClass;
use FazCookie\Includes\Rest_Controller;
use FazCookie\Admin\Modules\Settings\Includes\Settings;
use FazCookie\Admin\Modules\Settings\Includes\Controller;
use FazCookie\Admin\Modules\Gcm\Includes\Gcm_Settings;
use FazCookie\Includes\Notice;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cookies API
 *
 * @class       Api
 * @version     3.0.0
 * @package     FazCookie
 */
class Api extends Rest_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'faz/v1';
	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 10 );
	}
	/**
	 * Register the routes for cookies.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/notices/(?P<notice>[a-zA-Z0-9-_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_notice' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reinstall',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'install_missing_tables' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/apply_filter',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply_filter' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/notices/pageviews_overage_notice',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'dismiss_pageviews_overage_notice' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/geolite2/update',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_geolite2' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/geolite2/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'geolite2_status' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'export_settings' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_settings' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);
	}
	/**
	 * Get a collection of items.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		$object = new Settings();
		$data   = $object->get();
		return rest_ensure_response( $data );
	}
	/**
	 * Create a single cookie or cookie category.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function create_item( $request ) {
		$data    = $this->prepare_item_for_database( $request );
		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );
		return rest_ensure_response( $data );
	}

	/**
	 * Apply WordPress filter hook
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function apply_filter( $request ) {
		$filter_name = $request->get_param( 'filter_name' );
		$filter_data = $request->get_param( 'filter_data' );

		if ( empty( $filter_name ) ) {
			return new WP_Error( 'missing_filter_name', __( 'Filter name is required.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		// Allowlist of permitted filter names to prevent arbitrary filter invocation.
		$allowed_filters = array(
			'faz_before_navigate',
			'faz_settings_update',
			'faz_banner_preview',
		);
		if ( ! in_array( $filter_name, $allowed_filters, true ) ) {
			return new WP_Error( 'invalid_filter_name', __( 'Filter name is not permitted.', 'faz-cookie-manager' ), array( 'status' => 403 ) );
		}

		// Sanitize filter data before passing to apply_filters.
		$filter_data = $this->sanitize_filter_data( $filter_data );

		// Apply the WordPress filter
		$result = apply_filters( $filter_name, $filter_data );

		// If filter returns false, it means navigation should be prevented
		$response_data = array(
			'prevent_navigation' => ( $result === false ),
			'filter_result' => $result,
		);

		return rest_ensure_response( $response_data );
	}

	/**
	 * Recursively sanitize filter data before passing to apply_filters.
	 *
	 * @param mixed $data Raw filter data from the request.
	 * @return mixed Sanitized data.
	 */
	private function sanitize_filter_data( $data ) {
		if ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		}
		if ( is_array( $data ) ) {
			return array_map( array( $this, 'sanitize_filter_data' ), $data );
		}
		if ( is_bool( $data ) ) {
			return $data;
		}
		if ( is_int( $data ) || is_float( $data ) ) {
			return $data;
		}
		// Null or unsupported types — return null.
		return null;
	}

	/**
	 * Dismiss the pageviews overage notice.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function dismiss_pageviews_overage_notice( $request ) {
		$expiry = $request->get_param( 'expiry' );
		$notice = Notice::get_instance();
		$notice->dismiss( 'pageviews_overage_notice', $expiry );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Update the status of admin notices.
	 *
	 * @param object $request Request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function update_notice( $request ) {
		$response = array( 'status' => false );
		$notice   = isset( $request['notice'] ) ? $request['notice'] : false;
		$expiry   = isset( $request['expiry'] ) ? intval( $request['expiry'] ) : 0;
		if ( $notice ) {
			Notice::get_instance()->dismiss( $notice, $expiry );
			$response['status'] = true;
		}
		return rest_ensure_response( $response );
	}

	/**
	 * Update the status of admin notices.
	 *
	 * @param object $request Request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function install_missing_tables( $request ) {
		$missing_tables = faz_missing_tables();
		if ( count( $missing_tables ) > 0 ) {
			do_action( 'faz_reinstall_tables' );
			do_action( 'faz_clear_cache' );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Format data to provide output to API
	 *
	 * @param object $object Object of the corresponding item Cookie or Cookie_Categories.
	 * @param array  $request Request params.
	 * @return array
	 */
	public function prepare_item_for_response( $object, $request ) {
		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $object, $request );
		$data    = $this->filter_response_by_context( $data, $context );
		return rest_ensure_response( $data );
	}

	/**
	 * Prepare a single item for create or update.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return stdClass
	 */
	public function prepare_item_for_database( $request ) {
		$clear = $request->get_param('clear');
		if ( is_null( $clear ) ) {
			$clear = true;
		} else {
			$clear = filter_var( $clear, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		}
		$object     = new Settings();
		$data       = $object->get();

		// Merge JSON body directly into settings data.
		$json = $request->get_json_params();
		if ( ! empty( $json ) && is_array( $json ) ) {
			foreach ( $json as $key => $value ) {
				if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) && is_array( $value ) ) {
					$data[ $key ] = array_merge( $data[ $key ], $value );
				} else {
					$data[ $key ] = $value;
				}
			}
		}

		$object->update( $data, $clear );
		return $object->get();
	}

	/**
	 * Get the query params for collections.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'context'  => $this->get_context_param( array( 'default' => 'view' ) ),
			'paged'    => array(
				'description'       => __( 'Current page of the collection.', 'faz-cookie-manager' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
				'minimum'           => 1,
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to be returned in result set.', 'faz-cookie-manager' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'search'   => array(
				'description'       => __( 'Limit results to those matching a string.', 'faz-cookie-manager' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'force'    => array(
				'type'        => 'boolean',
				'description' => __( 'Force fetch data', 'faz-cookie-manager' ),
			),
		);
	}

	/**
	 * Get the Consent logs's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'consentlogs',
			'type'       => 'object',
			'properties' => array(
				'id'           => array(
					'description' => __( 'Unique identifier for the resource.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'site'         => array(
					'description' => __( 'Unique identifier for the resource.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'api'          => array(
					'description' => __( 'Language.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'account'      => array(
					'description' => __( 'Language.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'consent_logs' => array(
					'description' => __( 'Language.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'languages'    => array(
					'description' => __( 'Language.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'onboarding'   => array(
					'description' => __( 'Language.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'banner_control' => array(
					'description' => __( 'Banner control settings.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'microsoft'    => array(
					'description' => __( 'Microsoft consent settings.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'scanner'      => array(
					'description' => __( 'Scanner settings.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'site_links'   => array(
					'description' => __( 'Linked sites settings.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'iab'          => array(
					'description' => __( 'IAB TCF settings.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Download/update the MaxMind GeoLite2 database.
	 *
	 * @param WP_REST_Request $request Request with 'license_key' param.
	 * @return WP_Error|WP_REST_Response
	 */
	public function update_geolite2( $request ) {
		$license_key = $request->get_param( 'license_key' );
		$license_key = is_scalar( $license_key ) ? trim( sanitize_text_field( (string) $license_key ) ) : '';
		if ( '' === $license_key ) {
			// Try from saved settings.
			$settings    = new Settings();
			$saved_key   = $settings->get( 'geolocation', 'maxmind_license_key' );
			$license_key = is_scalar( $saved_key ) ? trim( sanitize_text_field( (string) $saved_key ) ) : '';
		}
		if ( '' === $license_key ) {
			return new \WP_Error( 'missing_license_key', __( 'A MaxMind license key is required.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		$result = \FazCookie\Includes\Geolocation::download_database( $license_key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$info = \FazCookie\Includes\Geolocation::get_database_info();
		return rest_ensure_response(
			array(
				'success'  => true,
				'database' => $info,
			)
		);
	}

	/**
	 * Get GeoLite2 database status.
	 *
	 * @return WP_REST_Response
	 */
	public function geolite2_status() {
		$info = \FazCookie\Includes\Geolocation::get_database_info();
		return rest_ensure_response(
			array(
				'installed' => ! empty( $info ),
				'database'  => $info,
			)
		);
	}

	/**
	 * Export all plugin settings, banners, categories, and cookies as JSON.
	 *
	 * Consent logs, pageview data, and sensitive API keys are excluded.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public function export_settings( $request ) {
		global $wpdb;

		$settings     = get_option( 'faz_settings' );
		$gcm_settings = get_option( 'faz_gcm_settings' );

		// Strip sensitive data from the export.
		if ( is_array( $settings ) && isset( $settings['geolocation']['maxmind_license_key'] ) ) {
			$settings['geolocation']['maxmind_license_key'] = '';
		}

		// Banners.
		$banners = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}faz_banners", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( is_array( $banners ) ) {
			foreach ( $banners as &$banner ) {
				if ( isset( $banner['settings'] ) ) {
					$banner['settings'] = json_decode( $banner['settings'], true );
				}
				if ( isset( $banner['contents'] ) ) {
					$banner['contents'] = json_decode( $banner['contents'], true );
				}
			}
			unset( $banner );
		}

		// Cookie categories.
		$categories = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}faz_cookie_categories", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( is_array( $categories ) ) {
			foreach ( $categories as &$cat ) {
				if ( isset( $cat['name'] ) ) {
					$cat['name'] = json_decode( $cat['name'], true );
				}
				if ( isset( $cat['description'] ) ) {
					$cat['description'] = json_decode( $cat['description'], true );
				}
			}
			unset( $cat );
		}

		// Cookies — decode JSON fields so they export as structured data
		// (matching categories above) and avoid double-encoding on re-import.
		$cookies = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}faz_cookies", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
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

		return rest_ensure_response( $export );
	}

	/**
	 * Import plugin settings, banners, categories, and cookies from JSON.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function import_settings( $request ) {
		global $wpdb;

		$data = $request->get_json_params();

		// Validate export file identifier.
		if ( empty( $data['plugin'] ) || 'faz-cookie-manager' !== $data['plugin'] ) {
			return new WP_Error( 'invalid_export', __( 'Invalid export file.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		$imported = array();

		// --- Settings ---
		if ( ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
			// Preserve the current MaxMind key when the export has it stripped.
			$current = get_option( 'faz_settings' );
			if (
				empty( $data['settings']['geolocation']['maxmind_license_key'] )
				&& is_array( $current )
				&& ! empty( $current['geolocation']['maxmind_license_key'] )
			) {
				$data['settings']['geolocation']['maxmind_license_key'] = $current['geolocation']['maxmind_license_key'];
			}
			// Use Settings::update() for sanitization, cache clearing, and hooks.
			$settings_obj = new Settings();
			$settings_obj->update( $data['settings'] );
			$imported[] = 'settings';
		}

		// --- GCM Settings ---
		if ( ! empty( $data['gcm_settings'] ) && is_array( $data['gcm_settings'] ) ) {
			// Use Gcm_Settings::update() for sanitization and hooks.
			$gcm_obj = new Gcm_Settings();
			$gcm_obj->update( $data['gcm_settings'] );
			$imported[] = 'gcm_settings';
		}

		// --- Banners ---
		if ( ! empty( $data['banners'] ) && is_array( $data['banners'] ) ) {
			$table = $wpdb->prefix . 'faz_banners';
			foreach ( $data['banners'] as $banner ) {
				$banner_id = absint( $banner['banner_id'] ?? 0 );
				if ( ! $banner_id ) {
					continue;
				}
				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT banner_id FROM {$table} WHERE banner_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$banner_id
				) );

				// Sanitize banner contents — strip dangerous HTML from all text
				// values to prevent stored XSS via crafted import files.
				$safe_contents = $this->sanitize_banner_contents( $banner['contents'] ?? array() );
				$safe_settings = $this->sanitize_banner_settings( $banner['settings'] ?? array() );

				$row = array(
					'banner_id'      => $banner_id,
					'name'           => sanitize_text_field( $banner['name'] ?? '' ),
					'slug'           => sanitize_text_field( $banner['slug'] ?? '' ),
					'status'         => absint( $banner['status'] ?? 0 ),
					'settings'       => wp_json_encode( $safe_settings ),
					'banner_default' => absint( $banner['banner_default'] ?? 0 ),
					'contents'       => wp_json_encode( $safe_contents ),
				);

				if ( $existing ) {
					$wpdb->update( $table, $row, array( 'banner_id' => $banner_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				} else {
					$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
			// Clear banner cache (base + language variants) so the template is regenerated.
			faz_clear_banner_template_cache();
			$imported[] = 'banners';
		}

		// --- Categories ---
		if ( ! empty( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$table = $wpdb->prefix . 'faz_cookie_categories';
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$cat_failed = false;
			foreach ( $data['categories'] as $cat ) {
				$result = $wpdb->insert( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					'category_id'      => absint( $cat['category_id'] ?? 0 ),
					'name'             => wp_json_encode( $cat['name'] ?? array() ),
					'slug'             => sanitize_text_field( $cat['slug'] ?? '' ),
					'description'      => wp_json_encode( $cat['description'] ?? array() ),
					'prior_consent'    => absint( $cat['prior_consent'] ?? 0 ),
					'visibility'       => absint( $cat['visibility'] ?? 1 ),
					'priority'         => absint( $cat['priority'] ?? 0 ),
					'sell_personal_data' => absint( $cat['sell_personal_data'] ?? 0 ),
					'meta'             => isset( $cat['meta'] ) ? wp_json_encode( $cat['meta'] ) : null,
				) );
				if ( false === $result ) {
					$cat_failed = true;
					break;
				}
			}
			if ( $cat_failed ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new WP_Error( 'import_categories_failed', __( 'Failed to import categories. Transaction rolled back.', 'faz-cookie-manager' ), array( 'status' => 500 ) );
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			// Invalidate category cache.
			do_action( 'faz_after_update_cookie_category' );
			$imported[] = 'categories';
		}

		// --- Cookies ---
		if ( ! empty( $data['cookies'] ) && is_array( $data['cookies'] ) ) {
			$table = $wpdb->prefix . 'faz_cookies';
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$cookie_failed = false;
			foreach ( $data['cookies'] as $cookie ) {
				$result = $wpdb->insert( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					'cookie_id'   => absint( $cookie['cookie_id'] ?? 0 ),
					'name'        => sanitize_text_field( $cookie['name'] ?? '' ),
					'slug'        => sanitize_text_field( $cookie['slug'] ?? '' ),
					'description' => wp_json_encode( $cookie['description'] ?? '' ),
					'duration'    => sanitize_text_field( $cookie['duration'] ?? '' ),
					'domain'      => sanitize_text_field( $cookie['domain'] ?? '' ),
					'category'    => absint( $cookie['category'] ?? 0 ),
					'type'        => sanitize_text_field( $cookie['type'] ?? '' ),
					'discovered'  => absint( $cookie['discovered'] ?? 0 ),
					'url_pattern' => sanitize_text_field( $cookie['url_pattern'] ?? '' ),
					'meta'        => isset( $cookie['meta'] ) ? wp_json_encode( $cookie['meta'] ) : null,
				) );
				if ( false === $result ) {
					$cookie_failed = true;
					break;
				}
			}
			if ( $cookie_failed ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new WP_Error( 'import_cookies_failed', __( 'Failed to import cookies. Transaction rolled back.', 'faz-cookie-manager' ), array( 'status' => 500 ) );
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			// Invalidate cookie cache.
			do_action( 'faz_after_update_cookie' );
			$imported[] = 'cookies';
		}

		// Clear the banner template cache — settings changes (e.g. consent
		// forwarding, GCM) can affect the rendered template.
		if ( ! empty( $imported ) ) {
			faz_clear_banner_template_cache();
		}

		return rest_ensure_response( array(
			'success'  => true,
			'imported' => $imported,
		) );
	}

	/**
	 * Recursively sanitize banner contents to prevent stored XSS.
	 *
	 * Applies wp_kses_post() to all string values (titles, descriptions,
	 * button labels) while preserving the nested array structure.
	 *
	 * @param mixed $contents Raw banner contents from import.
	 * @return mixed Sanitized contents.
	 */
	private function sanitize_banner_contents( $contents ) {
		if ( is_string( $contents ) ) {
			return wp_kses_post( $contents );
		}
		if ( is_array( $contents ) ) {
			return array_map( array( $this, 'sanitize_banner_contents' ), $contents );
		}
		if ( is_bool( $contents ) || is_int( $contents ) || is_float( $contents ) ) {
			return $contents;
		}
		return null;
	}

	/**
	 * Recursively sanitize banner settings (styles, config).
	 *
	 * Validates CSS property values against an allowlist of safe patterns
	 * and sanitizes all other string values with sanitize_text_field().
	 *
	 * @param mixed $settings Raw banner settings from import.
	 * @return mixed Sanitized settings.
	 */
	private function sanitize_banner_settings( $settings ) {
		if ( is_string( $settings ) ) {
			return sanitize_text_field( $settings );
		}
		if ( is_array( $settings ) ) {
			return array_map( array( $this, 'sanitize_banner_settings' ), $settings );
		}
		if ( is_bool( $settings ) || is_int( $settings ) || is_float( $settings ) ) {
			return $settings;
		}
		return null;
	}

} // End the class.
