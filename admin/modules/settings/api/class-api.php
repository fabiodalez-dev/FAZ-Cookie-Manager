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

} // End the class.
