<?php
/**
 * Cookie lookup endpoint — uses Open Cookie Database definitions.
 *
 * Replaces the old cookie.is scraper with local lookups against the
 * Open Cookie Database (Apache-2.0 licensed). No external HTTP requests
 * are made during lookup — definitions are pre-downloaded.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Cookies\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_Error;
use FazCookie\Includes\Cookie_Definitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cookie_Scraper {

	protected $namespace = 'faz/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		// Lookup cookies against local definitions (replaces scraping).
		register_rest_route(
			$this->namespace,
			'/cookies/scrape',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'lookup_cookies' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'names' => array(
						'required'    => true,
						'type'        => 'array',
						'description' => 'Array of cookie names to look up.',
						'items'       => array( 'type' => 'string' ),
					),
				),
			)
		);

		// Update definitions from GitHub.
		register_rest_route(
			$this->namespace,
			'/cookies/definitions/update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_definitions' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		// Get definitions metadata.
		register_rest_route(
			$this->namespace,
			'/cookies/definitions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_definitions_meta' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	public function permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to use this endpoint.', 'faz-cookie-manager' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Look up cookie names against local Open Cookie Database definitions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function lookup_cookies( WP_REST_Request $request ) {
		$names = $request->get_param( 'names' );
		$defs  = Cookie_Definitions::get_instance();

		// Auto-download definitions if not yet available.
		if ( ! $defs->has_definitions() ) {
			$defs->update_definitions();
		}

		$results = $defs->lookup_batch( $names );
		return rest_ensure_response( $results );
	}

	/**
	 * Download/update cookie definitions from GitHub.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function update_definitions( WP_REST_Request $request ) {
		$defs   = Cookie_Definitions::get_instance();
		$result = $defs->update_definitions();

		if ( ! $result['success'] ) {
			return new WP_Error(
				'definitions_update_failed',
				__( 'Failed to update cookie definitions. Check server logs for details.', 'faz-cookie-manager' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get metadata about stored cookie definitions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_definitions_meta( WP_REST_Request $request ) {
		$defs = Cookie_Definitions::get_instance();
		$meta = $defs->get_meta();
		$meta['has_definitions'] = $defs->has_definitions();
		return rest_ensure_response( $meta );
	}
}
