<?php
/**
 * Class Api file.
 *
 * REST API for local consent log operations.
 *
 * @package Api
 */

namespace FazCookie\Admin\Modules\Consentlogs\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FazCookie\Includes\Rest_Controller;
use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Consent logs REST API
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
	protected $rest_base = 'consent_logs';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 10 );
	}

	/**
	 * Register the routes for consent logs.
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /consent_logs - paginated list (admin only).
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
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// GET /consent_logs/statistics - consent statistics (admin only).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/statistics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_statistics' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// GET /consent_logs/export - CSV export (admin only).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'export_csv' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);

		// GET /consent_logs/(?P<consent_id>[a-zA-Z0-9=+/]+) - single consent proof (admin only).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<consent_id>[a-zA-Z0-9=+/]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

	}

	/**
	 * Get paginated list of consent logs.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$args = array(
			'paged'    => $request->get_param( 'paged' ) ? absint( $request->get_param( 'paged' ) ) : 1,
			'per_page' => $request->get_param( 'per_page' ) ? absint( $request->get_param( 'per_page' ) ) : 10,
			'search'   => $request->get_param( 'search' ) ? sanitize_text_field( $request->get_param( 'search' ) ) : '',
			'status'   => $request->get_param( 'status' ) ? sanitize_text_field( $request->get_param( 'status' ) ) : '',
		);

		$result   = Controller::get_instance()->get_logs( $args );
		$response = rest_ensure_response( $result['items'] );

		$response->header( 'X-WP-Total', $result['total'] );
		$response->header( 'X-WP-TotalPages', $result['pages'] );

		return $response;
	}

	/**
	 * Get consent statistics.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public function get_statistics( $request ) {
		$items = Controller::get_instance()->get_statistics();
		return rest_ensure_response( $items );
	}

	/**
	 * Get a single consent log by consent_id.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$consent_id = $request->get_param( 'consent_id' );
		$item       = Controller::get_instance()->get_log_by_consent_id( $consent_id );

		if ( null === $item ) {
			return new WP_Error(
				'consent_log_not_found',
				__( 'Consent log not found.', 'faz-cookie-manager' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $item );
	}

	/**
	 * Export consent logs as CSV.
	 *
	 * Outputs raw CSV and exits to bypass WP REST JSON encoding.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return void
	 */
	public function export_csv( $request ) {
		$args = array(
			'search' => $request->get_param( 'search' ) ? sanitize_text_field( $request->get_param( 'search' ) ) : '',
			'status' => $request->get_param( 'status' ) ? sanitize_text_field( $request->get_param( 'status' ) ) : '',
		);

		$csv = Controller::get_instance()->export_csv( $args );

		if ( ! is_string( $csv ) ) {
			status_header( 500 );
			exit;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="consent-logs-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'Content-Length: ' . strlen( $csv ) );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw CSV file download.
		exit;
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
			'status'   => array(
				'description'       => __( 'Filter by consent status.', 'faz-cookie-manager' ),
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
			'title'      => 'consentlogs',
			'type'       => 'object',
			'properties' => array(
				'log_id'     => array(
					'description' => __( 'Log ID.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'consent_id' => array(
					'description' => __( 'Unique consent ID.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'status'     => array(
					'description' => __( 'Consent status.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'categories' => array(
					'description' => __( 'Consent categories.', 'faz-cookie-manager' ),
					'type'        => array( 'object', 'array' ),
					'context'     => array( 'view', 'edit' ),
				),
				'ip_hash'    => array(
					'description' => __( 'Hashed visitor IP.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'user_agent' => array(
					'description' => __( 'Visitor user agent.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'url'        => array(
					'description' => __( 'Page URL where consent was given.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'created_at' => array(
					'description' => __( 'Date the consent was logged.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

} // End the class.
