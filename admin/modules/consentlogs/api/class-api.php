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

		// GET /consent_logs/stats - detailed consent statistics (admin only).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_consent_stats' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'days' => array(
							'description'       => __( 'Number of days to look back.', 'faz-cookie-manager' ),
							'type'              => 'integer',
							'default'           => 30,
							'minimum'           => 1,
							'maximum'           => 365,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		// GET /consent_logs/ab_test - accept-rate per banner variant (admin only).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/ab_test',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ab_test_stats' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'days' => array(
							'description'       => __( 'Look-back window in days; 0 means all time.', 'faz-cookie-manager' ),
							'type'              => 'integer',
							'default'           => 30,
							'minimum'           => 0,
							'maximum'           => 3650,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
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

		// GET /consent_logs/(?P<consent_id>…) - single consent proof (admin only).
		// The class must admit every id the plugin itself writes: the base64
		// of generate_consent_id() (A-Za-z0-9+/=), the alphanumeric browser id
		// from script.js, AND the Do-Not-Sell ids 'dnsmpi-<hex>' and
		// 'dnsmpi-rsc-<hex>' from Do_Not_Sell_Shortcode::log_dnsmpi_event().
		// The original class had no '-', so WordPress answered 404
		// rest_no_route for every opt-out and rescission record although the
		// row existed. The lookup itself binds the id with prepare(%s), so the
		// class is a routing matter, not a sanitising one.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<consent_id>[A-Za-z0-9=+\/_.-]+)',
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
		$items    = is_array( $result['items'] ) ? array_map( array( $this, 'with_created_at_gmt' ), $result['items'] ) : $result['items'];
		$response = rest_ensure_response( $items );

		$response->header( 'X-WP-Total', $result['total'] );
		$response->header( 'X-WP-TotalPages', $result['pages'] );

		return $response;
	}

	/**
	 * Add `created_at_gmt` — the stored site-time instant as RFC 3339 UTC — to
	 * a row the API returns. The stored `created_at` stays as it is: the admin
	 * table and existing integrations read it, and it is what the schema now
	 * describes it to be. See Controller::created_at_gmt().
	 *
	 * @since 1.34.2
	 * @param mixed $item One consent-log row.
	 * @return mixed
	 */
	private function with_created_at_gmt( $item ) {
		if ( is_array( $item ) && array_key_exists( 'created_at', $item ) ) {
			$item['created_at_gmt'] = Controller::row_created_at_gmt( $item );
		}
		return $item;
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
	 * Get detailed consent statistics (daily breakdown, totals, per-category).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public function get_consent_stats( $request ) {
		$days  = max( 1, min( 365, absint( $request->get_param( 'days' ) ) ) );
		$stats = Controller::get_instance()->get_consent_stats( $days );
		return rest_ensure_response( $stats );
	}

	/**
	 * Accept-rate per banner variant for the A/B test Dashboard panel.
	 *
	 * Reads the configured A/B variant slugs from settings, resolves each to a
	 * display name from the banner rows, and returns the per-variant accept
	 * stats from the consent log. `enabled` reflects the settings toggle so the
	 * Dashboard can hide the panel entirely when the test is off.
	 *
	 * @since 1.25.0
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public function get_ab_test_stats( $request ) {
		$settings = new \FazCookie\Admin\Modules\Settings\Includes\Settings();
		$ab       = $settings->get( 'banner_control', 'ab_test' );
		$enabled  = is_array( $ab ) && ! empty( $ab['status'] );
		$variants = ( is_array( $ab ) && isset( $ab['variants'] ) && is_array( $ab['variants'] ) )
			? array_values( $ab['variants'] )
			: array();

		$days = absint( $request->get_param( 'days' ) );

		// slug => display name from the banner rows. Include inactive rows too,
		// so a just-deactivated variant still shows its accumulated results with
		// a readable name.
		$names             = array();
		$banner_controller = \FazCookie\Admin\Modules\Banners\Includes\Controller::get_instance();
		$items             = $banner_controller->get_items();
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( isset( $item->slug ) ) {
					$names[ (string) $item->slug ] = isset( $item->name ) && '' !== (string) $item->name
						? (string) $item->name
						: (string) $item->slug;
				}
			}
		}

		$stats = Controller::get_instance()->get_ab_test_stats( $variants, $days );
		foreach ( $stats as &$row ) {
			$slug        = isset( $row['slug'] ) ? (string) $row['slug'] : '';
			$row['name'] = isset( $names[ $slug ] ) ? $names[ $slug ] : $slug;
		}
		unset( $row );

		return rest_ensure_response(
			array(
				'enabled'  => $enabled,
				'days'     => $days,
				'variants' => $stats,
			)
		);
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

		return rest_ensure_response( $this->with_created_at_gmt( $item ) );
	}

	/**
	 * Export consent logs as CSV.
	 *
	 * Outputs raw CSV and exits to bypass WP REST JSON encoding.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return void|WP_Error
	 */
	public function export_csv( $request ) {
		$args = array(
			'search' => $request->get_param( 'search' ) ? sanitize_text_field( $request->get_param( 'search' ) ) : '',
			'status' => $request->get_param( 'status' ) ? sanitize_text_field( $request->get_param( 'status' ) ) : '',
		);

		// Every output buffer is discarded first. WordPress and other plugins
		// routinely leave one open, and streaming INTO a buffer accumulates the
		// same complete CSV in memory the buffer was meant to avoid: the code
		// would look streamed and behave exactly as before.
		if ( ! self::discard_output_buffers() ) {
			return new WP_Error(
				'faz_export_buffer_locked',
				__( 'The CSV export could not start because an output buffer is locked. Disable output compression or the conflicting plugin and try again.', 'faz-cookie-manager' ),
				array( 'status' => 500 )
			);
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="consent-logs-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// No Content-Length. It cannot be known before the last row is written,
		// and computing it would mean building the whole file first — which is
		// the thing being removed. A download without one is ordinary; the file
		// name is what the browser shows.

		$handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			status_header( 500 );
			exit;
		}

		// Written batch by batch straight to the response. The previous version
		// built the entire export in a php://temp buffer and echoed it, so peak
		// memory held the whole file — on the plugin's highest-volume table, for
		// the site with the most rows, which is the site whose administrator
		// needs the export and the one where it ran out of memory.
		$complete = Controller::get_instance()->stream_csv( $handle, $args );
		if ( false === $complete ) {
			error_log( 'FAZ: consent-log CSV export was interrupted by a database error.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- headers are already sent; logging is the server-side failure signal.
			fwrite( $handle, "\n# " . __( 'INCOMPLETE EXPORT: a database error interrupted the download.', 'faz-cookie-manager' ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Remove every removable output buffer before a streaming response.
	 *
	 * @return bool False when the current buffer is locked and streaming cannot
	 *              be guaranteed without accumulating the full response.
	 */
	private static function discard_output_buffers() {
		while ( ob_get_level() > 0 ) {
			// A non-removable buffer leaves ob_get_level() unchanged when this
			// fails. Returning immediately is the loop-termination guarantee.
			if ( ! ob_end_clean() ) {
				return false;
			}
		}
		return true;
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
					// Not `format: date-time`: that promises RFC 3339 with an
					// offset, and this value is the stored site-time wall clock,
					// `Y-m-d H:i:s`, with none. created_at_gmt is the field that
					// keeps that promise.
					'description' => __( 'Date the consent was logged, in the site timezone (Y-m-d H:i:s, no offset).', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'created_at_gmt' => array(
					'description' => __( 'The same instant in UTC, RFC 3339.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				// The Migration_V2 audit columns: the jurisdiction the visitor was
				// enforced under, the privacy signals the request carried, and
				// the TC/GPP strings consented to. NULL on rows written before
				// the migration.
				'country_at_consent' => array(
					'description' => __( 'ISO 3166-1 alpha-2 country the consent was enforced under; empty when unknown.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'region_at_consent' => array(
					'description' => __( 'ISO 3166-2 region the consent was enforced under; empty when unknown.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'ruleset_id_at_consent' => array(
					'description' => __( 'Identifier of the jurisdiction ruleset applied at consent time; empty when none was enforced.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'signal_gpc_received' => array(
					'description' => __( 'Whether the request carried a Global Privacy Control signal (1) or not (0).', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'signal_dnt_received' => array(
					'description' => __( 'Whether the request carried a Do Not Track signal (1) or not (0).', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'tc_string' => array(
					'description' => __( 'IAB TCF TC String recorded with the consent, when the TCF CMP is enabled.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'gpp_string' => array(
					'description' => __( 'IAB GPP string recorded with the consent, when a GPP API is present.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

} // End the class.
