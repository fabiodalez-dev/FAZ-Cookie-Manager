<?php
/**
 * Class Api file.
 *
 * @package Gcm
 */

namespace FazCookie\Admin\Modules\Gcm\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FazCookie\Includes\Rest_Controller;
use FazCookie\Admin\Modules\Gcm\Includes\Gcm_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

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
	protected $rest_base = 'gcm';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 10 );
	}
	/**
	 * Register the routes for gcm.
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
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
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
	}

	/**
	 * Get GCM settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_item( $request ) {
		$object = new Gcm_Settings();
		return rest_ensure_response( $object->get() );
	}

	/**
	 * Create gcm.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function create_item( $request ) {
		$data = $this->prepare_item_for_database( $request );
		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );
		return rest_ensure_response( $data );
	}

	public function prepare_item_for_database( $request ) {
		$object     = new Gcm_Settings();
		$data       = $object->get();
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
			$boolean_keys = array( 'status', 'ads_data_redaction', 'url_passthrough', 'gacm_enabled' );
			foreach ( $properties_keys as $key ) {
				if ( ! $request->has_param( $key ) ) {
					continue;
				}
				$value = $request[ $key ];
				if ( in_array( $key, $boolean_keys, true ) ) {
					$data[ $key ] = faz_sanitize_bool( $value );
				} elseif ( 'wait_for_update' === $key ) {
					$data[ $key ] = absint( $value );
				} elseif ( 'default_settings' === $key ) {
					$data[ $key ] = is_array( $value ) ? $value : array();
				} else {
					$data[ $key ] = sanitize_text_field( $value );
				}
			}
		}
		$object->update( $data );
		return $object->get();
	}

	/**
	 * Get the Gcm's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'gcm',
			'type'       => 'object',
			'properties' => array(
				'status'           => array(
					'description' => __( 'GCM status.', 'faz-cookie-manager' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'default_settings'         => array(
					'description' => __( 'Default settings.', 'faz-cookie-manager' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
				),
				'wait_for_update'          => array(
					'description' => __( 'Wait for update.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'url_passthrough'      => array(
					'description' => __( 'Pass ad click information through URLs.', 'faz-cookie-manager' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'ads_data_redaction' => array(
					'description' => __( 'Redact ads data.', 'faz-cookie-manager' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'gacm_enabled' => array(
					'description' => __( 'Enable Google Additional Consent Mode.', 'faz-cookie-manager' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'gacm_provider_ids' => array(
					'description' => __( 'GACM provider IDs (comma-separated).', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}
}
