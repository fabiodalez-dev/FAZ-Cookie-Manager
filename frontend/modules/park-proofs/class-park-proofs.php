<?php
/**
 * Park proofs REST endpoint.
 *
 * POST /faz/v1/park-proofs vouches for parked attributes that arrived with a
 * park key other than the page's: markup a page fetched later (infinite
 * scroll, "load more") was parked by its own response, under its own key.
 * The runtime sends the names, the values it reads and the signature on the
 * tag; the answer says, per item, whether this site signed exactly that.
 * See FazCookie\Frontend\Includes\Park_Proof.
 *
 * @package FazCookie\Frontend\Modules\Park_Proofs
 */

namespace FazCookie\Frontend\Modules\Park_Proofs;

use FazCookie\Frontend\Includes\Park_Proof;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Park proofs REST controller.
 */
class Park_Proofs {

	/**
	 * Constructor — register the REST route.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the /faz/v1/park-proofs route.
	 *
	 * @return void
	 */
	public function register_routes() {
		// `permission_callback => __return_true` is intentional. The callers are
		// anonymous visitors on pages that are usually cached, so neither a
		// capability nor a nonce can be required. The route stores nothing,
		// reads nothing but its input and answers only whether this site's
		// secret signed the given values: it cannot be used to produce a
		// signature. Input size is bounded (Park_Proof::MAX_ITEMS and
		// Park_Proof::MAX_VALUE_BYTES).
		register_rest_route(
			'faz/v1',
			'/park-proofs',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Answer, per item, whether its signature is this site's.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify( $request ) {
		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			return new WP_Error( 'faz_park_proofs_invalid', 'items must be an array.', array( 'status' => 400 ) );
		}
		return new WP_REST_Response( array( 'valid' => self::verdicts( $items ) ), 200 );
	}

	/**
	 * One verdict per item, for at most Park_Proof::MAX_ITEMS items.
	 *
	 * @param array $items Items as sent.
	 * @return bool[]
	 */
	public static function verdicts( array $items ) {
		$valid = array();
		foreach ( array_slice( array_values( $items ), 0, Park_Proof::MAX_ITEMS ) as $item ) {
			$valid[] = Park_Proof::item_is_valid( $item );
		}
		return $valid;
	}
}
