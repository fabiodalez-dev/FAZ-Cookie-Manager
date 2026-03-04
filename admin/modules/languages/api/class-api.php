<?php
/**
 * Language API class
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 * @package    FazCookie\Admin\Modules\Languages\Api
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
 * Languages API
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
	 * Register a deprecated 410 Gone route for the languages endpoint.
	 *
	 * Languages are now managed through the settings endpoint.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::ALLMETHODS,
					'callback'            => array( $this, 'deprecated_route' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				),
			)
		);
	}

	/**
	 * Return 410 Gone for the deprecated languages endpoint.
	 *
	 * @since 1.1.0
	 * @return WP_Error
	 */
	public function deprecated_route() {
		return new WP_Error(
			'faz_languages_gone',
			__( 'This endpoint is deprecated. Use the settings endpoint instead.', 'faz-cookie-manager' ),
			array( 'status' => 410 )
		);
	}

} // End the class.
