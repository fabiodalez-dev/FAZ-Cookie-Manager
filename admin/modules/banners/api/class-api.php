<?php
/**
 * Class Api file.
 *
 * @package FazCookie\Admin\Modules\Banners\Api
 */

namespace FazCookie\Admin\Modules\Banners\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FazCookie\Includes\Rest_Controller;
use FazCookie\Admin\Modules\Banners\Includes\Controller;
use FazCookie\Admin\Modules\Banners\Includes\Banner;
use Exception;

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
	protected $rest_base = 'banners';

	/**
	 * Banner controller object.
	 *
	 * @var object
	 */
	protected $controller;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->controller = Controller::get_instance();
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
			'/' . $this->rest_base . '/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the resource.', 'faz-cookie-manager' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::DELETABLE ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/preview',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_preview' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/presets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_presets' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/configs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_configs' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
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
		$objects = array();
		$items   = $this->controller->get_items();
		foreach ( $items as $data ) {
			$object    = new Banner( (int) $data->banner_id );
			$data      = $this->prepare_item_for_response( $object, $request );
			$objects[] = $this->prepare_response_for_collection( $data );
		}
		// Wrap the data in a response object.
		return rest_ensure_response( $objects );
	}

	/**
	 * Get a single item.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_item( $request ) {
		$object = new Banner( (int) $request['id'] );
		if ( 0 === $object->get_id() ) {
			return new WP_Error( 'fazcookie_rest_invalid_id', __( 'Invalid ID.', 'faz-cookie-manager' ), array( 'status' => 404 ) );
		}
		$data = $this->prepare_item_for_response( $object, $request );
		return rest_ensure_response( $data );
	}

	/**
	 * Create a new banner.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function create_item( $request ) {
		if ( ! empty( $request['id'] ) ) {
			return new WP_Error(
				'fazcookie_rest_item_exists',
				__( 'Cannot create existing banner.', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
		}
		$object = $this->prepare_item_for_database( $request );
		$object->save();
		$data = $this->prepare_item_for_response( $object, $request );
		return rest_ensure_response( $data );
	}

	/**
	 * Update an existing banner.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function update_item( $request ) {
		if ( empty( $request['id'] ) ) {
			return new WP_Error(
				'fazcookie_rest_item_exists',
				__( 'Invalid banner id', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
		}
		$registered = $this->get_collection_params();
		$object     = $this->prepare_item_for_database( $request );
		if ( isset( $registered['language'], $request['language'] ) ) {
			$object->set_language( sanitize_text_field( $request['language'] ) );
		}
		$object->save();
		$data = $this->prepare_item_for_response( $object, $request );
		return rest_ensure_response( $data );
	}

	/**
	 * Delete an existing banner.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function delete_item( $request ) {
		if ( empty( $request['id'] ) ) {
			return new WP_Error(
				'fazcookie_rest_item_exists',
				__( 'Invalid banner id', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
		}
		$banner_id = $request['id'];
		$data      = $this->controller->remove( $banner_id );
		return rest_ensure_response( $data );
	}

	/**
	 * Performs bulk update request.
	 *
	 * @param WP_REST_Request $request WP request object.
	 * @return WP_Error|WP_REST_Response
	 */
	public function bulk( $request ) {
		$clear = $request->get_param('clear');
		if ( is_null( $clear ) ) {
			$clear = true;
		} else {
			$clear = filter_var( $clear, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		}
		try {
			if ( ! isset( $request['banners'] ) ) {
				return new WP_Error( 'fazcookie_rest_invalid_data', __( 'No data specified to create/edit banners', 'faz-cookie-manager' ), array( 'status' => 404 ) );
			}
			if ( ! defined( 'FAZ_BULK_REQUEST' ) ) {
				define( 'FAZ_BULK_REQUEST', true );
			}
			$item_objects = array();
			$objects      = array();
			$data         = $request['banners'];

			foreach ( $data as $_banner ) {
				$object = $this->prepare_item_for_database( $_banner );
				$object->save();
				$item_objects[] = $object;
			}
			foreach ( $item_objects as $item ) {
				$response  = $this->prepare_item_for_response( $item, $request );
				$objects[] = $this->prepare_response_for_collection( $response );
			}
			do_action( 'faz_after_update_banner', $clear );
			return rest_ensure_response( $objects );
		} catch ( Exception $e ) {
			return new WP_Error( $e->getCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}
	}

	/**
	 * Load banner preview.
	 *
	 * @param WP_REST_Request $request WP_REST_Request object.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_preview( $request ) {
		$data = array();
		// Always force regeneration for previews (bypass cached template)
		if ( ! defined( 'FAZ_PREVIEW_REQUEST' ) ) {
			define( 'FAZ_PREVIEW_REQUEST', true );
		}
		$object   = $this->prepare_item_for_database( $request );
		$language = isset( $request['language'] ) ? $request['language'] : faz_default_language();
		$object->set_language( $language );
		$template       = $object->get_template();
		$data['html']   = $template['html'];
		$data['styles'] = $template['styles'];
		return rest_ensure_response( $data );
	}

	/**
	 * Load presets
	 *
	 * @param WP_REST_Request $request WP_REST_Request object.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_presets( $request ) {
		$registered = $this->get_collection_params();
		$presets    = array();
		if ( isset( $registered['ver'], $request['ver'] ) ) {
			$template = new \FazCookie\Admin\Modules\Banners\Includes\Template( false );
			$presets  = $template->get_presets( $request['ver'] );
		}
		return rest_ensure_response( $presets );
	}

	/**
	 * Load default banner configs
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_configs() {
		$configs = array(
			'gdpr' => $this->controller->get_default_configs(),
			'ccpa' => $this->controller->get_default_configs( 'ccpa' ),
		);
		return rest_ensure_response( $configs );
	}

	/**
	 * Format data to provide output to API
	 *
	 * @param Banner          $object Object of the corresponding item.
	 * @param WP_REST_Request $request Request params.
	 * @return WP_Error|WP_REST_Response
	 */
	public function prepare_item_for_response( $object, $request ) {
		$data    = $this->get_formatted_item_data( $object );
		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );
		return rest_ensure_response( $data );
	}

	/**
	 * Format the support before sending.
	 *
	 * @param Banner $object Banner object.
	 * @return array<string, mixed>
	 */
	public function get_formatted_item_data( $object ) {
		return array(
			'id'         => $object->get_id(),
			'slug'       => $object->get_slug(),
			'name'       => $object->get_name(),
			'status'     => $object->get_status(),
			'default'    => $object->get_default(),
			'properties' => $object->get_settings(),
			'contents'   => $object->get_contents(),
		);
	}

	/**
	 * Prepare a single item for create or update.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return Banner
	 */
	public function prepare_item_for_database( $request ) {
		$id     = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		$object = new Banner( $id );
		$object->set_name( $request['name'] );
		$object->set_default( $request['default'] );
		$object->set_status( $request['status'] );
		$object->set_settings( $request['properties'] );
		$object->set_contents( $request['contents'] );
		return $object;
	}

	/**
	 * Get the query params for collections.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'context'  => $this->get_context_param( array( 'default' => 'view' ) ),
			'search'   => array(
				'description'       => __( 'Limit results to those matching a string.', 'faz-cookie-manager' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'ver'      => array(
				'description'       => __( 'Version', 'faz-cookie-manager' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'language' => array(
				'description'       => __( 'Language of the banner', 'faz-cookie-manager' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
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
			'title'      => 'Banners',
			'type'       => 'object',
			'properties' => array(
				'id'            => array(
					'description' => __( 'Unique identifier for the resource.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'name'          => array(
					'description' => __( 'Banner name for reference', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'slug'          => array(
					'description' => __( 'Banner unique name', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'settings'      => array(
					'description' => __( 'Banner settings.', 'faz-cookie-manager' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
				),
				'contents'      => array(
					'description' => __( 'Banner contents.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'default'       => array(
					'description' => __( 'Indicates whether the banner is default or not', 'faz-cookie-manager' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'date_created'  => array(
					'description' => __( 'The date the banner was created, as GMT.', 'faz-cookie-manager' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified' => array(
					'description' => __( 'The date the banner was last modified, as GMT.', 'faz-cookie-manager' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
				),

			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

} // End the class.
