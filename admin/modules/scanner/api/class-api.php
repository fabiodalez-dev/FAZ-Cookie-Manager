<?php
/**
 * Scanner REST API for local cookie scanning.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Scanner\Api;

use WP_REST_Server;
use WP_Error;
use stdClass;
use FazCookie\Includes\Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Scanner API
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
	protected $rest_base = 'scans';

	/**
	 * Base controller
	 *
	 * @var object
	 */
	protected $controller;

	/**
	 * Constructor
	 *
	 * @param object $controller Controller class object.
	 */
	public function __construct( $controller ) {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 10 );
		$this->controller = $controller;
	}

	/**
	 * Register the routes for scanning.
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
			'/' . $this->rest_base . '/info',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_scan_info' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// Browser-based scanner: discover URLs for client-side scanning.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/discover',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'discover_urls' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'max_pages' => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Browser-based scanner: import cookies discovered by client JS.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_cookies' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
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
			)
		);
	}

	/**
	 * Get scan history from local storage.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$per_page = isset( $request['per_page'] ) ? absint( $request['per_page'] ) : 10;
		$page     = isset( $request['page'] ) ? absint( $request['page'] ) : 1;
		$history  = get_option( 'faz_scan_history', array() );

		// Reverse to show most recent first.
		$history = array_reverse( $history );
		$total   = count( $history );
		$offset  = ( $page - 1 ) * $per_page;
		$items   = array_slice( $history, $offset, $per_page );

		$data = array();
		foreach ( $items as $index => $item ) {
			$entry                  = new stdClass();
			$entry->id              = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$entry->scan_status     = isset( $item['status'] ) ? sanitize_text_field( $item['status'] ) : '';
			$entry->pages_scanned   = isset( $item['pages_scanned'] ) ? absint( $item['pages_scanned'] ) : 0;
			$entry->total_cookies   = isset( $item['total_cookies'] ) ? absint( $item['total_cookies'] ) : 0;
			$entry->total_scripts   = 0;
			$entry->created_at      = isset( $item['date'] ) ? sanitize_text_field( $item['date'] ) : '';
			$entry->total_categories = 0;
			$data[]                 = $entry;
		}

		$result = array(
			'data'       => $data,
			'pagination' => (object) array(
				'per_page' => $per_page,
				'total'    => $total,
			),
		);

		return rest_ensure_response( $result );
	}

	/**
	 * Get a single scan detail by ID.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return object|WP_Error
	 */
	public function get_item( $request ) {
		$scan_id = (int) $request['id'];
		$history = get_option( 'faz_scan_history', array() );

		foreach ( $history as $item ) {
			if ( isset( $item['id'] ) && absint( $item['id'] ) === $scan_id ) {
				$data                = new stdClass();
				$data->id            = absint( $item['id'] );
				$data->scan_status   = isset( $item['status'] ) ? sanitize_text_field( $item['status'] ) : '';
				$data->total_pages   = isset( $item['pages_scanned'] ) ? absint( $item['pages_scanned'] ) : 0;
				$data->total_cookies = isset( $item['total_cookies'] ) ? absint( $item['total_cookies'] ) : 0;
				$data->total_scripts = 0;
				$data->created_at    = isset( $item['date'] ) ? sanitize_text_field( $item['date'] ) : '';
				$data->total_categories = 0;
				return $data;
			}
		}

		return new WP_Error( 'fazcookie_rest_invalid_id', __( 'Invalid ID.', 'faz-cookie-manager' ), array( 'status' => 404 ) );
	}

	/**
	 * Initiate a new local scan.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		// Check if a scan is already running.
		$current  = $this->controller->get_info();
		$is_stale = false;

		if ( 'scanning' === $current['status'] ) {
			// Auto-reset stale scans older than 5 minutes.
			$scan_date = get_option( 'faz_scan_details', array() );
			$raw_date  = isset( $scan_date['date'] ) ? $scan_date['date'] : '';
			if ( ! empty( $raw_date ) ) {
				$started = strtotime( $raw_date );
				if ( $started && ( time() - $started ) > 300 ) {
					$is_stale = true;
				}
			} else {
				// No date recorded — treat as stale.
				$is_stale = true;
			}

			if ( ! $is_stale ) {
				return new WP_Error(
					'faz_rest_scan_in_progress',
					__( 'A scan is already in progress, please wait for it to complete.', 'faz-cookie-manager' ),
					array( 'status' => 409 )
				);
			}
		}

		// Mark scan as in progress.
		$this->controller->update_info(
			array(
				'status' => 'scanning',
				'date'   => current_time( 'mysql' ),
			)
		);

		// Schedule async scan (avoids loopback deadlock with single-threaded PHP dev server).
		$max_pages = isset( $request['max_pages'] ) ? absint( $request['max_pages'] ) : 20;
		$this->controller->schedule_scan( $max_pages );

		return rest_ensure_response( $this->controller->get_info() );
	}

	/**
	 * Get current scan status (for polling).
	 *
	 * @return \WP_REST_Response
	 */
	public function get_scan_info() {
		// Force re-read from DB (don't use cached value).
		$data = get_option( 'faz_scan_details', array(
			'id'            => 0,
			'status'        => '',
			'type'          => 'local',
			'date'          => '',
			'total_cookies' => 0,
			'pages_scanned' => 0,
		) );
		return rest_ensure_response( $data );
	}

	/**
	 * Discover site URLs for client-side scanning.
	 *
	 * Returns a list of URLs that the browser-based scanner should load
	 * in hidden iframes. Uses existing discover_pages() logic.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function discover_urls( $request ) {
		$max_pages = isset( $request['max_pages'] ) ? absint( $request['max_pages'] ) : 20;
		$urls      = $this->controller->discover_pages_from_db( $max_pages );

		return rest_ensure_response(
			array(
				'urls'  => array_values( $urls ),
				'total' => count( $urls ),
			)
		);
	}

	/**
	 * Import cookies discovered by the client-side browser scanner.
	 *
	 * Receives cookie data and script URLs from the JS iframe scanner,
	 * saves cookies to the database, and updates scan history.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_cookies( $request ) {
		$body = $request->get_json_params();

		$raw_cookies   = isset( $body['cookies'] ) && is_array( $body['cookies'] ) ? $body['cookies'] : array();
		$pages_scanned = isset( $body['pages_scanned'] ) ? absint( $body['pages_scanned'] ) : 0;
		$scripts       = isset( $body['scripts'] ) && is_array( $body['scripts'] ) ? $body['scripts'] : array();

		if ( empty( $raw_cookies ) && empty( $scripts ) ) {
			return new \WP_Error(
				'faz_rest_no_data',
				__( 'No cookies or scripts provided.', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
		}

		// Sanitize cookie data.
		$cookies = array();
		foreach ( $raw_cookies as $c ) {
			if ( empty( $c['name'] ) ) {
				continue;
			}
			$cookies[] = array(
				'name'        => sanitize_text_field( $c['name'] ),
				'domain'      => isset( $c['domain'] ) ? sanitize_text_field( $c['domain'] ) : '',
				'duration'    => isset( $c['duration'] ) ? sanitize_text_field( $c['duration'] ) : 'session',
				'description' => isset( $c['description'] ) ? sanitize_text_field( $c['description'] ) : '',
				'category'    => isset( $c['category'] ) ? sanitize_text_field( $c['category'] ) : 'uncategorized',
				'source'      => isset( $c['source'] ) ? sanitize_text_field( $c['source'] ) : 'browser',
			);
		}

		// Sanitize script URLs.
		$clean_scripts = array();
		foreach ( $scripts as $s ) {
			$clean_scripts[] = esc_url_raw( $s );
		}

		// Schedule a background server-side scan of the homepage to catch
		// httpOnly cookies that JavaScript cannot read from document.cookie.
		$this->controller->schedule_httponly_check();

		$result = $this->controller->save_scan_result( $cookies, $pages_scanned, $clean_scripts );

		return rest_ensure_response( $result );
	}

	/**
	 * Get formatted item data.
	 *
	 * @param object $object Item data.
	 * @return void
	 */
	protected function get_formatted_item_data( $object ) {
		// Not used for scanner.
	}

	/**
	 * Get the schema for scan items.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'scan',
			'type'       => 'object',
			'properties' => array(
				'id'            => array(
					'description' => __( 'Unique identifier for the resource.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'status'        => array(
					'description' => __( 'Scan status.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'type'          => array(
					'description' => __( 'Scan type.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'date'          => array(
					'description' => __( 'Scan date.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'total_cookies' => array(
					'description' => __( 'Total cookies found.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'pages_scanned' => array(
					'description' => __( 'Total pages scanned.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'max_pages'     => array(
					'description' => __( 'Maximum pages to scan.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'edit' ),
					'default'     => 20,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}
} // End the class.
