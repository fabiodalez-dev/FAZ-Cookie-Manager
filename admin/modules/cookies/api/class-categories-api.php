<?php
/**
 * Class Categories_API file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Cookies\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Exception;
use FazCookie\Admin\Modules\Cookies\Api\API_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories;
use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cookie categories API
 *
 * @class       Categories_API
 * @version     3.0.0
 * @package     FazCookie
 */
class Categories_API extends API_Controller {

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
	protected $rest_base = 'cookies/categories';

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
		return Category_Controller::get_instance()->get_items( $args );
	}

	/**
	 * Return item object
	 *
	 * @param object|null $item Cookie item.
	 * @return Cookie_Categories
	 */
	public function get_item_object( $item = null ) {
		return new Cookie_Categories( $item );
	}
	/**
	 * Get formatted item data.
	 *
	 * @since  3.0.0
	 * @param  Cookie_Categories $object Cookie instance.
	 * @return array
	 */
	protected function get_formatted_item_data( $object ) {
		return array(
			'id'                 => $object->get_id(),
			'name'               => $object->get_name(),
			'slug'               => $object->get_slug(),
			'description'        => $object->get_description(),
			'prior_consent'      => $object->get_prior_consent(),
			'visibility'         => $object->get_visibility(),
			'language'           => $object->get_language(),
			'priority'           => $object->get_priority(),
			'sell_personal_data' => $object->get_sell_personal_data(),
			'cookie_list'        => $object->get_cookies(),
			'date_created'       => $object->get_date_created(),
			'date_modified'      => $object->get_date_modified(),
		);
	}
	/**
	 * Bulk update of categories.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function bulk( $request ) {

		try {
			if ( ! isset( $request['categories'] ) ) {
				return new WP_Error( 'fazcookie_rest_invalid_data', __( 'No data specified to create/edit categories', 'faz-cookie-manager' ), array( 'status' => 400 ) );
			}
			if ( ! defined( 'FAZ_BULK_REQUEST' ) ) {
				define( 'FAZ_BULK_REQUEST', true );
			}
			$item_objects = array();
			$objects      = array();
			$data         = $request['categories'];

			$categories = array();

			foreach ( $data as $_category ) {
				$id = 0;
				// Try to get the category ID.
				if ( isset( $_category['id'] ) ) {
					$id = intval( $_category['id'] );
				}

				if ( $id ) {
					$object        = new Cookie_Categories( $id );
					$prior_consent = isset( $_category['prior_consent'] ) ? (bool) $_category['prior_consent'] : false;
					$visibility    = isset( $_category['visibility'] ) ? (bool) $_category['visibility'] : true;
					$object->set_prior_consent( $prior_consent );
					$object->set_visibility( $visibility );
					$object->save();
					$item_objects[] = $object;

				}
			}
			foreach ( $item_objects as $data ) {
				$data      = $this->prepare_item_for_response( $data, $request );
				$objects[] = $this->prepare_response_for_collection( $data );
			}
			do_action( 'faz_after_update_cookie_category' );
			return rest_ensure_response( $objects );
		} catch ( Exception $e ) {
			return new WP_Error( $e->getCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}
	}
	/**
	 * Get the Cookies's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'cookie_categories',
			'type'       => 'object',
			'properties' => array(
				'id'                 => array(
					'description' => __( 'Unique identifier for the resource.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'date_created'       => array(
					'description' => __( 'The date the category was created, as GMT.', 'faz-cookie-manager' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified'      => array(
					'description' => __( 'The date the category was last modified, as GMT.', 'faz-cookie-manager' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'name'               => array(
					'description' => __( 'Cookie category name.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'slug'               => array(
					'description' => __( 'Cookie category unique name', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'language'           => array(
					'description' => __( 'Cookie category language', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'description'        => array(
					'description' => __( 'Cookie category description.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'prior_consent'      => array(
					'description' => __( 'Whether prior consent is required.', 'faz-cookie-manager' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'priority'           => array(
					'description' => __( 'Category display priority.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'visibility'         => array(
					'description' => __( 'Whether category is visible in audit table.', 'faz-cookie-manager' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'sell_personal_data' => array(
					'description' => __( 'Whether category involves selling personal data.', 'faz-cookie-manager' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'cookie_list'        => array(
					'description' => __( 'List of cookies in this category.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
				),

			),
		);
		return $this->add_additional_fields_schema( $schema );
	}

} // End the class.
