<?php
/**
 * Frontend consent logger.
 *
 * Registers AJAX and REST handlers to log visitor consent from the frontend.
 *
 * @package FazCookie\Frontend\Modules\ConsentLogger
 */

namespace FazCookie\Frontend\Modules\Consent_Logger;

use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Consent Logger - handles frontend consent logging via AJAX and REST.
 *
 * @class       Consent_Logger
 * @version     3.0.0
 * @package     FazCookie
 */
class Consent_Logger {

	/**
	 * Constructor - register hooks.
	 */
	public function __construct() {
		// Public REST route.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register public REST route for consent logging.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'faz/v1',
			'/consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rest_consent' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'consent_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'partial',
					),
					'categories' => array(
						'type'    => array( 'object', 'array' ),
						'default' => array(),
					),
					'url'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * Handle REST consent logging.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_rest_consent( $request ) {
		// Dual guardrail: per-IP AND per-consent_id throttle.
		// The IP check prevents a single client from flooding with different consent_ids.
		// The consent_id check prevents replaying the same consent_id from different IPs.
		$consent_id   = $request->get_param( 'consent_id' );
		$consent_key  = 'faz_consent_' . substr( md5( $consent_id ?? '' ), 0, 8 );
		if ( faz_throttle_request( 'faz_consent_ip' ) || faz_throttle_request( $consent_key ) ) {
			error_log( '[FAZ Cookie Manager] Consent log throttled for consent_id: ' . sanitize_text_field( $consent_id ?? '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return rest_ensure_response( array( 'throttled' => true ) );
		}

		$data = array(
			'consent_id' => $request->get_param( 'consent_id' ),
			'status'     => $request->get_param( 'status' ),
			'categories' => $request->get_param( 'categories' ),
			'url'        => $request->get_param( 'url' ),
		);

		$result = Controller::get_instance()->log_consent( $data );

		if ( false === $result ) {
			return new \WP_Error(
				'consent_log_failed',
				__( 'Failed to log consent.', 'faz-cookie-manager' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $result );
	}
}
