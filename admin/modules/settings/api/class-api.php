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
	 * Helper method to register simple POST routes.
	 *
	 * @param string $endpoint The endpoint path.
	 * @param string $callback The callback method name.
	 * @return void
	 */
	private function register_post_route( $endpoint, $callback ) {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/' . $endpoint,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
	}

	/**
	 * Helper method for simple controller method calls with JSON data and error handling.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @param string $method_name The controller method name to call.
	 * @param bool $use_json_params Whether to use JSON params (default: true).
	 * @return WP_Error|WP_REST_Response
	 */
	private function call_controller_method( $request, $method_name, $use_json_params = true ) {
		$data = $use_json_params ? $request->get_json_params() : array();
		$response = Controller::get_instance()->{$method_name}( $data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return rest_ensure_response( $response );
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
			'/' . $this->rest_base . '/laws',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_laws' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/info',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_info' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/disconnect',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'disconnect' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sync',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the resource.', 'faz-cookie-manager' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_items' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/cache/purge',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'clear_cache' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
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
		$this->register_post_route( 'payments', 'add_payments' );
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
	 * Fetch default laws from database
	 *
	 * @param array $request WP_REST_Request $request Full details about the request.
	 * @return array
	 */
	public function get_laws( $request = array() ) {
		$object = array(
			array(
				'slug'        => 'gdpr',
				'title'       => __( 'GDPR (General Data Protection Regulation)', 'faz-cookie-manager' ),
				'description' => __( 'Continue with the GDPR template if most of your targeted audience are from the EU or UK. It creates a customizable banner that allows your visitors to accept/reject cookies or adjust their consent preferences.', 'faz-cookie-manager' ),
				'tooltip'     => __(
					'Choose GDPR if most of your targeted audience are from the EU or UK.
					It creates a customizable banner that allows your visitors to accept/reject cookies or adjust their consent preferences.',
					'faz-cookie-manager'
				),
			),
			array(
				'slug'        => 'ccpa',
				'title'       => __( 'CCPA (California Consumer Privacy Act)', 'faz-cookie-manager' ),
				'description' => __( 'Choose CCPA if most of your targeted audience are from California or US. This will create a customizable banner with a "Do Not Sell My Personal Information" link that allows your visitors to refuse the use of cookies.', 'faz-cookie-manager' ),
				'tooltip'     => __(
					'Choose CCPA if most of your targeted audience are from California or US.
					It creates a customizable banner with a "Do Not Sell My Personal Information" link that allows your visitors to refuse the use of cookies.',
					'faz-cookie-manager'
				),
			),
			array(
				'slug'        => 'info',
				'title'       => __( 'INFO (Information Display Banner)', 'faz-cookie-manager' ),
				'description' => __( 'Choose INFO if you do not want to block any cookies on your website. This will create a dismissible banner that provides some general information to your site visitors.', 'faz-cookie-manager' ),
				'tooltip'     => __(
					'Choose Info if you do not want to block any cookies on your website.
						It creates a dismissible banner that provides some general info to your site visitors.',
					'faz-cookie-manager'
				),
			),
		);
		$data   = $this->prepare_item_for_response( $object, $request );
		return rest_ensure_response( $data );
	}

	/**
	 * Get site info including the features allowed for the current plan.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_info( $request ) {
		$args       = array();
		$registered = $this->get_collection_params();
		if ( isset( $registered['force'], $request['force'] ) ) {
			$args['force'] = (bool) $request['force'];
		}
		$response = Controller::get_instance()->get_info( $args );
		if ( empty( $response ) ) {
			$data = array();
		} else {
			$data = $this->prepare_item_for_response( $response, $request );
		}
		$objects = $this->prepare_response_for_collection( $data );
		return rest_ensure_response( $objects );
	}

	/**
	 * Send data directly to the web app.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function send_items( $request ) {
		$response = Controller::get_instance()->sync();
		if ( empty( $response ) ) {
			$data = array();
		} else {
			$data = $this->prepare_item_for_response( $response, $request );
		}
		$objects = $this->prepare_response_for_collection( $data );
		return rest_ensure_response( $objects );
	}

	/**
	 * Clear cache of all the modules
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function clear_cache() {
		$banner_controller   = new \FazCookie\Admin\Modules\Banners\Includes\Controller();
		$category_controller = new \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller();
		$cookie_controller   = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller();
		$banner_controller->delete_cache();
		$category_controller->delete_cache();
		$cookie_controller->delete_cache();
		wp_cache_flush();
		$data = array( 'status' => true );
		return rest_ensure_response( $data );
	}

	/**
	 * Initiate disconnect request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function disconnect() {
		$response = Controller::get_instance()->disconnect();
		return rest_ensure_response( $response );
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
	 * Add payments/subscription.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function add_payments( $request ) {
		return $this->call_controller_method( $request, 'add_payments' );
	}

	/**
	 * Download/update the MaxMind GeoLite2 database.
	 *
	 * @param WP_REST_Request $request Request with 'license_key' param.
	 * @return WP_Error|WP_REST_Response
	 */
	public function update_geolite2( $request ) {
		$license_key = $request->get_param( 'license_key' );
		if ( empty( $license_key ) ) {
			// Try from saved settings.
			$settings    = new Settings();
			$license_key = $settings->get( 'geolocation', 'maxmind_license_key' );
		}
		if ( empty( $license_key ) ) {
			return new \WP_Error( 'missing_license_key', __( 'A MaxMind license key is required.', 'cookie-law-info' ), array( 'status' => 400 ) );
		}

		$result = \FazCookie\Includes\Geolocation::download_database( sanitize_text_field( $license_key ) );
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
