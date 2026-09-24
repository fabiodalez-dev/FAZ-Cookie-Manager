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
	 * How long one pageview token value lasts before a new one is minted.
	 *
	 * Same shape as the consent-log token: a keyed hash of a time bucket,
	 * printed into cacheable HTML, proving only that the page came from this
	 * installation.
	 */
	const TOKEN_BUCKET = 43200; // 12 * HOUR_IN_SECONDS.

	/**
	 * How far back a pageview token is still accepted, in seconds, by default.
	 *
	 * Issue #296. This endpoint carried the same defect issue #292 reported
	 * against the consent log, and carried it for the same reason: only the current bucket
	 * and the previous one were accepted — 12 to 24 hours — while LiteSpeed
	 * Cache ships a 604800-second public TTL and WP Rocket and W3TC defaults
	 * are measured in days. From about a day after a page was cached every
	 * pageview, banner_view and banner_accept event posted from it was refused
	 * with a 403, so the Dashboard's counters under-reported in silence and,
	 * unlike the consent path, nothing counted or logged the loss.
	 *
	 * Analytics is not accountability, so the consequence was smaller — but it
	 * was the same bug, and leaving a second copy of the arithmetic to be fixed
	 * separately is what let the two drift apart in the first place.
	 */
	const TOKEN_MAX_AGE = 604800; // 7 * DAY_IN_SECONDS.

	/**
	 * Upper bound for the filtered window, in seconds.
	 */
	const TOKEN_MAX_AGE_LIMIT = 2592000; // 30 * DAY_IN_SECONDS.

	/**
	 * The current pageview origin token.
	 *
	 * Minted here, in the class that accepts it, so the frontend cannot hold a
	 * second copy of the bucket arithmetic free to drift from this one.
	 *
	 * @param int|null $at Unix timestamp, or null for now.
	 * @return string
	 */
	public static function current_token( $at = null ) {
		$at = null === $at ? time() : (int) $at;
		return wp_hash( 'faz_pageview_' . (string) (int) floor( $at / self::TOKEN_BUCKET ) );
	}

	/**
	 * How far back a pageview token is accepted, in seconds.
	 *
	 * Filter: faz_pageview_token_max_age. Clamped to one bucket at the bottom
	 * and to TOKEN_MAX_AGE_LIMIT at the top, exactly as the consent token is.
	 *
	 * @return int
	 */
	public static function token_max_age() {
		/**
		 * Filters how long a pageview origin token stays acceptable.
		 *
		 * @param int $max_age Seconds. Default 7 days.
		 */
		$max_age = (int) apply_filters( 'faz_pageview_token_max_age', self::TOKEN_MAX_AGE );
		if ( $max_age < self::TOKEN_BUCKET ) {
			return self::TOKEN_BUCKET;
		}
		if ( $max_age > self::TOKEN_MAX_AGE_LIMIT ) {
			return self::TOKEN_MAX_AGE_LIMIT;
		}
		return $max_age;
	}

	/**
	 * Whether a pageview token was minted by this site inside the window.
	 *
	 * @param string   $token Token from the request.
	 * @param int|null $at    Unix timestamp, or null for now.
	 * @return bool
	 */
	public static function token_is_valid( $token, $at = null ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}
		$at      = null === $at ? time() : (int) $at;
		$bucket  = (int) floor( $at / self::TOKEN_BUCKET );
		// One bucket more than the window divides into: a token minted at the
		// very start of the oldest acceptable bucket is already up to a bucket
		// old, so a bare floor() would refuse pages the window means to accept.
		$buckets = (int) ceil( self::token_max_age() / self::TOKEN_BUCKET );
		for ( $i = 0; $i <= $buckets; $i++ ) {
			if ( hash_equals( wp_hash( 'faz_pageview_' . (string) ( $bucket - $i ) ), $token ) ) {
				return true;
			}
		}
		return false;
	}

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
		// Gate the public POST endpoint behind the admin's
		// `pageview_tracking` toggle. When tracking is off, the route is
		// not registered at all — defense-in-depth against token harvest
		// and per-IP throttle bypass attempts on installs that don't use
		// the dashboard analytics. The admin chart endpoints below are
		// always registered (they need to render even with empty data).
		// Strict `true ===`, matching the beacon emitter in
		// Frontend::enqueue_scripts(): the two reads must agree, or a value
		// written outside the sanitiser (import, WP-CLI) as '1' registers this
		// public endpoint while the frontend emits no beacon — an anonymous
		// POST route live on an install whose admin sees tracking as off.
		$settings           = get_option( 'faz_settings', array() );
		$pageview_tracking  = isset( $settings['pageview_tracking'] ) && true === $settings['pageview_tracking'];

		if ( $pageview_tracking ) {
		// Public: record a pageview or banner event.
		// `permission_callback => __return_true` is intentional. This endpoint
		// is consumed by anonymous frontend visitors (the only audience for
		// pageview / banner-interaction tracking), so a logged-in capability
		// check would defeat its purpose. Abuse mitigation lives at the
		// callback layer instead:
		//   - Required HMAC `token` (signed server-side, time-bucketed to a
		//     12-hour window) — see `record_event()` validation.
		//   - Per-IP rate limiting via transient counter.
		//   - Strict `sanitize_callback` on every input field.
		// Same shape as wp/v2/comments POST when "anyone can comment" is on.
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
						// No per-visitor identifier is accepted: pre-consent
						// metrics are aggregate-only (see class-frontend.php).
					),
				),
			)
		);
		} // end if ( $pageview_tracking ).

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
			if ( ! self::token_is_valid( $token ) ) {
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

		// Validate event_type against known values to prevent cache-key flooding.
		$event_type = sanitize_key( $request->get_param( 'event_type' ) );
		$allowed_event_types = array( 'pageview', 'banner_view', 'banner_accept', 'banner_reject', 'banner_settings' );
		if ( ! in_array( $event_type, $allowed_event_types, true ) ) {
			return new WP_Error(
				'invalid_event_type',
				__( 'Unknown event type.', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
		}

		// Rate limit: 1 request per IP per event type per second.
		// Each event type (pageview, banner_view, banner_accept, etc.) gets its own
		// throttle bucket so near-simultaneous events don't suppress each other.
		$throttle_key = 'faz_pv_' . $event_type;
		if ( faz_throttle_request( $throttle_key ) ) {
			return rest_ensure_response( array( 'throttled' => true ) );
		}

		$data = array(
			'page_url'   => $request->get_param( 'page_url' ),
			'page_title' => $request->get_param( 'page_title' ),
			'event_type' => $event_type,
			// session_id intentionally omitted — pre-consent metrics carry no
			// per-visitor identifier (the DB column defaults to '').
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
