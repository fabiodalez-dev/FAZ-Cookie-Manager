<?php
/**
 * Class Cookies_API file.
 *
 * @package Cookies
 */

namespace FazCookie\Admin\Modules\Cookies\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FazCookie\Admin\Modules\Cookies\Api\API_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cookies API
 *
 * @class       Cookies_API
 * @version     3.0.0
 * @package     FazCookie
 */
class Cookies_API extends API_Controller {

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
	protected $rest_base = 'cookies';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 10 );
	}
	/**
	 * Register the routes for cookies.
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
			'/' . $this->rest_base . '/bulk-update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_update' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
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
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

	}

	/**
	 * Return cookie ids
	 *
	 * @param array $args Request arguments.
	 * @return array
	 */
	public function get_item_objects( $args ) {
		return Cookie_Controller::get_instance()->get_items_by_category( $args );
	}

	/**
	 * Return item object
	 *
	 * @param object|null $item Cookie id.
	 * @return Cookie
	 */
	public function get_item_object( $item = null ) {
		return new Cookie( $item );
	}
	/**
	 * Get formatted item data.
	 *
	 * @since  3.0.0
	 * @param  Cookie $object Cookie instance.
	 * @return array
	 */
	protected function get_formatted_item_data( $object ) {
		return $object->get_prepared_data();
	}
	/**
	 * Get the Cookies's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'cookie',
			'type'       => 'object',
			'properties' => array(
				'id'            => array(
					'description' => __( 'Unique identifier for the resource.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'date_created'  => array(
					'description' => __( 'The date the cookie was created, as GMT.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified' => array(
					'description' => __( 'The date the cookie was last modified, as GMT.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'name'          => array(
					'description' => __( 'Cookie name.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'category'      => array(
					'description' => __( 'Cookie category name.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'slug'          => array(
					'description' => __( 'Cookie unique name', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'description'   => array(
					'description' => __( 'Cookie description.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'duration'      => array(
					'description' => __( 'Cookie duration', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'language'      => array(
					'description' => __( 'Cookie language.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'type'          => array(
					'description' => __( 'Cookie type.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'domain'        => array(
					'description' => __( 'Cookie domain.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'discovered'    => array(
					'description' => __( 'If cookies added from the scanner or not.', 'faz-cookie-manager' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'url_pattern'   => array(
					'description' => __( 'URL patterns for blocking purposes', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}
	/**
	 * Bulk update cookies (e.g., change category for multiple cookies at once).
	 *
	 * @param \WP_REST_Request $request Request with 'cookies' array.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_update( $request ) {
		$items = $request->get_param( 'cookies' );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return new \WP_Error( 'invalid_data', 'No cookies provided.', array( 'status' => 400 ) );
		}

		$updated = array();
		foreach ( $items as $item ) {
			$id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			if ( ! $id ) {
				continue;
			}
			$cookie = new Cookie( $id );
			if ( ! $cookie->get_id() ) {
				continue;
			}
			if ( isset( $item['category'] ) ) {
				$cookie->set_category( absint( $item['category'] ) );
			}
			if ( isset( $item['description'] ) ) {
				$cookie->set_description( $item['description'] );
			}
			if ( isset( $item['duration'] ) ) {
				$cookie->set_duration( $item['duration'] );
			}
			$cookie->save();
			$updated[] = $cookie->get_prepared_data();
		}

		do_action( 'faz_after_update_cookie' );

		return rest_ensure_response( array(
			'updated' => count( $updated ),
			'cookies' => $updated,
		) );
	}

} // End the class.
