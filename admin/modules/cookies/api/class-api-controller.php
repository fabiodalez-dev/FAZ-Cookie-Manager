<?php
/**
 * Class API_Controller file.
 *
 * @package Cookies
 */

namespace FazCookie\Admin\Modules\Cookies\Api;

use WP_Error;
use FazCookie\Includes\Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cookies API
 *
 * @class       API_Controller
 * @version     3.0.0
 * @package     FazCookie
 * @extends     Rest_Controller
 */
abstract class API_Controller extends Rest_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'faz/v1';


	/**
	 * Get the data directly from DB.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	protected function get_item_objects( $args ) {
		// translators: %s: Class method name.
		return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass.", 'faz-cookie-manager' ), __METHOD__ ), array( 'status' => 405 ) );
	}
	/**
	 * Get object.
	 *
	 * @param  int $id Object ID.
	 * @return object Cookie object or Cookie_Categories object or WP_Error object.
	 */
	protected function get_item_object( $id = false ) {
		// translators: %s: Class method name.
		return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass.", 'faz-cookie-manager' ), __METHOD__ ), array( 'status' => 405 ) );
	}

	/**
	 * Get formatted data from corresponding object.
	 *
	 * @param object $object Cookie_Categories or Cookie instance.
	 * @return WP_Error
	 */
	protected function get_formatted_item_data( $object ) {
		// translators: %s: Class method name.
		return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass.", 'faz-cookie-manager' ), __METHOD__ ), array( 'status' => 405 ) );
	}

	/**
	 * Get a collection of items.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		$args         = array();
		$registered   = $this->get_collection_params();
		$objects      = array();
		$item_objects = array();
		if ( isset( $registered['lang'], $request['lang'] ) ) {
			$args['lang'] = sanitize_text_field( $request['lang'] );
		}
		if ( isset( $registered['category'], $request['category'] ) ) {
			$args['category'] = sanitize_text_field( $request['category'] );
		}
		$item_data = $this->get_item_objects( $args );
		if ( isset( $item_data ) && ! empty( $item_data ) ) {
			$item_objects = array_filter( array_map( array( $this, 'get_item_object' ), $item_data ) );
		}
		foreach ( $item_objects as $data ) {
			$data      = $this->prepare_item_for_response( $data, $request );
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
		$object = $this->get_item_object( (int) $request['id'] );
		if ( ! $object || 0 === $object->get_id() ) {
			return new WP_Error( 'fazcookie_rest_invalid_id', __( 'Invalid ID.', 'faz-cookie-manager' ), array( 'status' => 404 ) );
		}
		$data = $this->prepare_item_for_response( $object, $request );
		return rest_ensure_response( $data );
	}

	/**
	 * Create a single cookie or cookie category.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function create_item( $request ) {
		if ( ! empty( $request['id'] ) ) {
			return new WP_Error(
				'fazcookie_rest_item_exists',
				__( 'Cannot create existing post.', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
		}
		$object = $this->may_be_create( $request, true );
		$data   = $this->prepare_item_for_response( $object, $request );
		return rest_ensure_response( $data );
	}

	/**
	 * Update a single cookie or cookie category.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function update_item( $request ) {
		$object = $this->may_be_create( $request, false );
		$data   = $this->prepare_item_for_response( $object, $request );
		return rest_ensure_response( $data );
	}

	/**
	 * Delete a single cookie or cookie category
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function delete_item( $request ) {
		$id     = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		$object = $this->get_item_object();
		$object->set_id( $id );
		if ( ! $object || 0 === $object->get_id() ) {
			return new WP_Error( 'fazcookie_rest_invalid_id', __( 'Invalid ID.', 'faz-cookie-manager' ), array( 'status' => 404 ) );
		}
		$data = $this->prepare_item_for_response( $object, $request );
		$object->delete();
		return rest_ensure_response( $data );
	}
	/**
	 * Format data to provide output to API
	 *
	 * @param object $object Object of the corresponding item Cookie or Cookie_Categories.
	 * @param array  $request Request params.
	 * @return array
	 */
	public function prepare_item_for_response( $object, $request ) {
		$data    = $this->get_formatted_item_data( $object );
		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );
		return rest_ensure_response( $data );
	}

	/**
	 * Create or update item
	 *
	 * @param WP_REST_Request $request WP rest request object.
	 * @param boolean         $create Decide whether to create new or update existing.
	 * @return object
	 */
	public function may_be_create( $request, $create = false ) {
		$object = $this->prepare_item_for_database( $request, $create );
		return $object;
	}

	/**
	 * Prepare a single item for create or update.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @param boolean         $create Decide whether to create new or update existing.
	 * @return array
	 */
	public function prepare_item_for_database( $request, $create = false ) {
		$id     = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		$object = $this->get_item_object();
		$object->set_id( $id );
		if ( false === $create && ( ! $object || 0 === $object->get_id() ) ) {
			return new WP_Error( 'fazcookie_rest_invalid_id', __( 'Invalid ID.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		// For updates, load existing data so unspecified fields are preserved.
		if ( false === $create && $id > 0 ) {
			$object->get_data_from_db();
		}

		$schema     = $this->get_item_schema();
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		if ( ! empty( $properties ) ) {
			$properties_keys = array_keys(
				array_filter(
					$properties,
					function( $property ) {
						return isset( $property['readonly'] ) && true === $property['readonly'] ? false : true;
					}
				)
			);

			foreach ( $properties_keys as $key ) {
				// For updates, only override fields present in the request.
				if ( false === $create && ! isset( $request[ $key ] ) ) {
					continue;
				}
				$value = isset( $request[ $key ] ) ? $request[ $key ] : '';
				if ( true === $create && empty( $value ) ) {
					continue;
				}
				if ( is_callable( array( $object, "set_{$key}" ) ) ) {
					$object->{"set_{$key}"}( $value );
				}
			}
		}
		$object->save();
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
			'page'     => array(
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
			'lang'     => array(
				'description'       => __( 'Language of the cookie', 'faz-cookie-manager' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'category' => array(
				'description'       => __( 'Cookie category', 'faz-cookie-manager' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

} // End the class.
