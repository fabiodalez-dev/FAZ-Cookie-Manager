<?php
/**
 * Class Api file.
 *
 * Local pageview & banner interaction API endpoints.
 *
 * @package Api
 */

namespace FazCookie\Admin\Modules\Pageviews\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FazCookie\Includes\Rest_Controller;
use FazCookie\Admin\Modules\Pageviews\Includes\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Pageviews API
 *
 * @class       Api
 * @version     1.0.0
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
	protected $rest_base = 'pageviews';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 10 );
	}

	/**
	 * Register the routes for pageviews.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Public: record a pageview or banner event.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'record_event' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page_url' => array(
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
						'page_title' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'event_type' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'session_id' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Admin: get pageview chart data.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/chart',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pageviews' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'days' => array(
							'type'              => 'integer',
							'default'           => 7,
							'sanitize_callback' => 'absint',
						),
						'from' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'to' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Admin: get banner interaction stats.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/banner-stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_banner_stats' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'days' => array(
							'type'              => 'integer',
							'default'           => 30,
							'sanitize_callback' => 'absint',
						),
						'from' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'to' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Admin: get daily trend data for charts.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/daily',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_daily_trend' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'days' => array(
							'type'              => 'integer',
							'default'           => 30,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Record a pageview or banner interaction event.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function record_event( $request ) {
		// Verify origin token: a time-bucketed HMAC generated server-side and
		// embedded in the page. Prevents casual spoofing from external origins.
		$token = $request->get_param( 'token' );
		if ( ! empty( $token ) ) {
			$current_bucket  = (string) floor( time() / ( 12 * HOUR_IN_SECONDS ) );
			$previous_bucket = (string) ( floor( time() / ( 12 * HOUR_IN_SECONDS ) ) - 1 );
			$valid = hash_equals( wp_hash( 'faz_pageview_' . $current_bucket ), $token )
				|| hash_equals( wp_hash( 'faz_pageview_' . $previous_bucket ), $token );
			if ( ! $valid ) {
				return new WP_Error(
					'invalid_token',
					'Invalid origin token.',
					array( 'status' => 403 )
				);
			}
		} else {
			return new WP_Error(
				'missing_token',
				'Origin token required.',
				array( 'status' => 403 )
			);
		}

		// Rate limit: 1 request per IP per event type per second.
		// Each event type (pageview, banner_view, banner_accept, etc.) gets its own
		// throttle bucket so near-simultaneous events don't suppress each other.
		$event_type = sanitize_key( $request->get_param( 'event_type' ) );
		$throttle_key = 'faz_pv_' . $event_type;
		if ( faz_throttle_request( $throttle_key ) ) {
			return rest_ensure_response( array( 'throttled' => true ) );
		}

		$data = array(
			'page_url'   => $request->get_param( 'page_url' ),
			'page_title' => $request->get_param( 'page_title' ),
			'event_type' => $request->get_param( 'event_type' ),
			'session_id' => $request->get_param( 'session_id' ),
		);

		$result = Controller::get_instance()->record_event( $data );

		if ( false === $result ) {
			return new WP_Error( 'faz_pageview_error', __( 'Failed to record event.', 'faz-cookie-manager' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get pageview chart data.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function get_pageviews( $request ) {
		$days = $request->get_param( 'days' );
		$from = $request->get_param( 'from' );
		$to   = $request->get_param( 'to' );
		$data = Controller::get_instance()->get_pageviews( $days, $from, $to );

		return rest_ensure_response(
			array(
				'total_views'         => $data['total_views'],
				'total_overage_views' => 0,
				'data'                => $data['data'],
			)
		);
	}

	/**
	 * Get banner interaction statistics.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function get_banner_stats( $request ) {
		$days  = $request->get_param( 'days' );
		$from  = $request->get_param( 'from' );
		$to    = $request->get_param( 'to' );
		$stats = Controller::get_instance()->get_banner_stats( $days, $from, $to );

		return rest_ensure_response( $stats );
	}

	/**
	 * Get daily trend data for all event types.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function get_daily_trend( $request ) {
		$days = $request->get_param( 'days' );
		$data = Controller::get_instance()->get_banner_daily_trend( $days );

		return rest_ensure_response( $data );
	}

	/**
	 * Get the query params for collections.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'context' => $this->get_context_param( array( 'default' => 'view' ) ),
			'days'    => array(
				'description'       => __( 'Number of days to look back.', 'faz-cookie-manager' ),
				'type'              => 'integer',
				'default'           => 7,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}
}
