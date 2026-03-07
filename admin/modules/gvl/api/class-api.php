<?php
/**
 * GVL REST API endpoints.
 *
 * @package FazCookie\Admin\Modules\Gvl\Api
 */

namespace FazCookie\Admin\Modules\Gvl\Api;

use FazCookie\Includes\Rest_Controller;
use FazCookie\Includes\Gvl;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Api extends Rest_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'gvl';

	/**
	 * Constructor — register routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// GET /faz/v1/gvl — summary (version, timestamp, vendor count, purposes).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_summary' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// GET /faz/v1/gvl/vendors — paginated vendor list.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/vendors',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_vendors' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// GET /faz/v1/gvl/vendors/(?P<id>\d+) — single vendor.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/vendors/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_vendor' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
			)
		);

		// POST /faz/v1/gvl/update — trigger GVL download.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/update',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_gvl' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);

		// GET /faz/v1/gvl/selected — selected vendor IDs.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/selected',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_selected' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// POST /faz/v1/gvl/selected — save selected vendor IDs.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/selected',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_selected' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * GET /faz/v1/gvl — GVL summary.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_summary( $request ) {
		$gvl  = Gvl::get_instance();
		$meta = $gvl->get_meta();

		$raw_purposes = $gvl->get_purposes();
		$purposes     = array();
		foreach ( $raw_purposes as $pid => $purpose ) {
			$purposes[] = array(
				'id'   => isset( $purpose['id'] ) ? absint( $purpose['id'] ) : absint( $pid ),
				'name' => isset( $purpose['name'] ) ? $purpose['name'] : '',
			);
		}

		return new WP_REST_Response( array(
			'version'      => isset( $meta['version'] ) ? $meta['version'] : 0,
			'vendor_count' => isset( $meta['vendor_count'] ) ? $meta['vendor_count'] : 0,
			'last_updated' => isset( $meta['last_updated'] ) ? $meta['last_updated'] : '',
			'has_data'     => $gvl->has_data(),
			'purposes'     => $purposes,
		), 200 );
	}

	/**
	 * GET /faz/v1/gvl/vendors — paginated vendor list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_vendors( $request ) {
		$gvl     = Gvl::get_instance();
		$vendors = $gvl->get_vendors();

		if ( empty( $vendors ) ) {
			return new WP_REST_Response( array(
				'vendors' => array(),
				'total'   => 0,
				'page'    => 1,
				'pages'   => 0,
			), 200 );
		}

		$search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?? 50 ) ) );
		$purpose  = absint( $request->get_param( 'purpose' ) ?? 0 );

		// Filter by search term.
		if ( ! empty( $search ) ) {
			$search_lower = strtolower( $search );
			$vendors = array_filter( $vendors, function ( $v ) use ( $search_lower ) {
				return false !== strpos( strtolower( $v['name'] ?? '' ), $search_lower );
			} );
		}

		// Filter by purpose.
		if ( $purpose > 0 ) {
			$vendors = array_filter( $vendors, function ( $v ) use ( $purpose ) {
				$purposes    = isset( $v['purposes'] ) ? $v['purposes'] : array();
				$leg_purposes = isset( $v['legIntPurposes'] ) ? $v['legIntPurposes'] : array();
				return in_array( $purpose, $purposes, true ) || in_array( $purpose, $leg_purposes, true );
			} );
		}

		// Sort by vendor ID.
		ksort( $vendors );

		$total = count( $vendors );
		$pages = (int) ceil( $total / $per_page );
		$offset = ( $page - 1 ) * $per_page;

		// Paginate.
		$paged = array_slice( $vendors, $offset, $per_page, true );

		// Compact vendor data for list view.
		$selected = get_option( 'faz_gvl_selected_vendors', array() );
		$result   = array();
		foreach ( $paged as $id => $vendor ) {
			$result[] = array(
				'id'             => absint( $id ),
				'name'           => $vendor['name'] ?? '',
				'purposes'       => $vendor['purposes'] ?? array(),
				'legIntPurposes' => $vendor['legIntPurposes'] ?? array(),
				'features'       => $vendor['features'] ?? array(),
				'selected'       => in_array( absint( $id ), $selected, true ),
			);
		}

		return new WP_REST_Response( array(
			'vendors' => $result,
			'total'   => $total,
			'page'    => $page,
			'pages'   => $pages,
		), 200 );
	}

	/**
	 * GET /faz/v1/gvl/vendors/{id} — single vendor details.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_vendor( $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$gvl    = Gvl::get_instance();
		$vendor = $gvl->get_vendor( $id );

		if ( null === $vendor ) {
			return new WP_REST_Response( array( 'message' => 'Vendor not found' ), 404 );
		}

		$vendor['id'] = $id;
		return new WP_REST_Response( $vendor, 200 );
	}

	/**
	 * POST /faz/v1/gvl/update — trigger GVL download.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function update_gvl( $request ) {
		$gvl    = Gvl::get_instance();
		$result = $gvl->download();

		// Also download purposes for current language.
		if ( $result['success'] ) {
			$lang = function_exists( 'faz_default_language' ) ? faz_default_language() : 'en';
			if ( 'en' !== $lang ) {
				$gvl->download_purposes( $lang );
			}

			// Auto-select all vendors if none have been selected yet.
			$existing_selected = get_option( 'faz_gvl_selected_vendors', null );
			if ( null === $existing_selected ) {
				$all_vendors = $gvl->get_vendors();
				if ( ! empty( $all_vendors ) ) {
					$all_ids = array_map( 'absint', array_keys( $all_vendors ) );
					sort( $all_ids );
					update_option( 'faz_gvl_selected_vendors', $all_ids, false );
					delete_option( 'faz_banner_template' );
				}
			}
		}

		$status = $result['success'] ? 200 : 500;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * GET /faz/v1/gvl/selected — get selected vendor IDs.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_selected( $request ) {
		$selected = get_option( 'faz_gvl_selected_vendors', array() );
		return new WP_REST_Response( array( 'vendor_ids' => $selected ), 200 );
	}

	/**
	 * POST /faz/v1/gvl/selected — save selected vendor IDs.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function save_selected( $request ) {
		$body       = $request->get_json_params();
		$vendor_ids = isset( $body['vendor_ids'] ) ? $body['vendor_ids'] : array();

		if ( ! is_array( $vendor_ids ) ) {
			return new WP_REST_Response( array( 'message' => 'vendor_ids must be an array' ), 400 );
		}

		$vendor_ids = array_map( 'absint', $vendor_ids );
		$vendor_ids = array_filter( $vendor_ids );
		$vendor_ids = array_values( array_unique( $vendor_ids ) );

		// Filter out vendor IDs not present in current GVL.
		$gvl = Gvl::get_instance();
		if ( ! $gvl->has_data() ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'GVL data not available. Please update GVL first.', 'faz-cookie-manager' ),
			), 400 );
		}
		$existing   = $gvl->get_vendors( $vendor_ids );
		$vendor_ids = array_map( 'absint', array_keys( $existing ) );
		sort( $vendor_ids );

		update_option( 'faz_gvl_selected_vendors', $vendor_ids, false );

		// Clear banner template cache so frontend picks up new vendor data.
		delete_option( 'faz_banner_template' );

		return new WP_REST_Response( array(
			'success'    => true,
			'vendor_ids' => $vendor_ids,
			'count'      => count( $vendor_ids ),
		), 200 );
	}
}
