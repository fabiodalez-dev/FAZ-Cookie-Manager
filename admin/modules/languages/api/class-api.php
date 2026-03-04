<?php
/**
 * Language API class
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 * @package    FazCookie\Admin\Modules\Banners\Includes
 */

namespace FazCookie\Admin\Modules\Languages\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use stdClass;
use FazCookie\Includes\Rest_Controller;
use FazCookie\Admin\Modules\Languages\Includes\Controller;

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
	protected $rest_base = 'languages';

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
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
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
				'id'          => array(
					'description' => __( 'Unique identifier for the resource.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'language'    => array(
					'description' => __( 'Name of the language.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'native_name' => array(
					'description' => __( 'Native name of the language.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'lang'        => array(
					'description' => __( 'Language code', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

} // End the class.
