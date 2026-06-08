<?php
/**
 * Class Api file.
 *
 * @package FazCookie\Admin\Modules\Banners\Api
 */

namespace FazCookie\Admin\Modules\Banners\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FazCookie\Includes\Rest_Controller;
use FazCookie\Admin\Modules\Banners\Includes\Controller;
use FazCookie\Admin\Modules\Banners\Includes\Banner;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cookies API
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
	protected $rest_base = 'banners';

	/**
	 * Banner controller object.
	 *
	 * @var object
	 */
	protected $controller;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->controller = Controller::get_instance();
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 10 );
	}

	/**
	 * Register the routes for cookies.
	 *
	 * @return void
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
			'/' . $this->rest_base . '/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => array(
						'description'       => __( 'Unique identifier for the resource.', 'faz-cookie-manager' ),
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
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
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::DELETABLE ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/preview',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_preview' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/presets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_presets' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/design-presets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_design_presets' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/configs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_configs' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}
	/**
	 * Get a collection of items.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		$objects = array();
		$items   = $this->controller->get_items();
		foreach ( $items as $data ) {
			$object    = new Banner( (int) $data->banner_id );
			$data      = $this->prepare_item_for_response( $object, $request );
			$objects[] = $this->prepare_response_for_collection( $data );
		}
		// Wrap the data in a response object.
		return rest_ensure_response( $objects );
	}

	/**
	 * Get a single item.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_item( $request ) {
		$id = (int) $request['id'];
		if ( $id <= 0 ) {
			return new WP_Error( 'fazcookie_rest_invalid_id', __( 'Invalid ID.', 'faz-cookie-manager' ), array( 'status' => 404 ) );
		}
		// Banner::__construct unconditionally calls set_id( $id ) BEFORE the
		// DB read, so the legacy `0 === $object->get_id()` check couldn't
		// catch a non-existent row — pre-1.14.1 this surfaced as
		// GET /banners/{phantom_id} → 200 with an empty payload. The DB
		// existence probe lives in banner_exists() so create / get / delete
		// share a single contract (see CodeRabbit review feedback on the
		// duplicated COUNT(*) probes — extracted as DRY helper + switched
		// to EXISTS() for an index-only plan).
		if ( ! $this->banner_exists( $id ) ) {
			return new WP_Error( 'fazcookie_rest_invalid_id', __( 'Banner not found.', 'faz-cookie-manager' ), array( 'status' => 404 ) );
		}
		$object = new Banner( $id );
		$data   = $this->prepare_item_for_response( $object, $request );
		return rest_ensure_response( $data );
	}

	/**
	 * Cheap existence check for a banner row.
	 *
	 * Uses EXISTS(SELECT 1 ...) instead of COUNT(*): MySQL can short-circuit
	 * on the first match and the optimiser picks an index-only plan on the
	 * banner_id primary key. Centralises the contract used by get_item() and
	 * delete_item() so any future tweak (e.g. soft-delete column) lives in
	 * one place.
	 *
	 * @since 1.14.2
	 * @param int $id Banner ID.
	 * @return bool
	 */
	private function banner_exists( $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT EXISTS(SELECT 1 FROM {$wpdb->prefix}faz_banners WHERE banner_id = %d)",
			$id
		) );
		return 1 === $exists;
	}

	/**
	 * Create a new banner.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function create_item( $request ) {
		if ( ! empty( $request['id'] ) ) {
			return new WP_Error(
				'fazcookie_rest_item_exists',
				__( 'Cannot create existing banner.', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
		}
		$object = $this->prepare_item_for_database( $request );
		$result = $object->save();
		if ( false === $result ) {
			return new WP_Error( 'fazcookie_rest_db_error', __( 'Failed to create banner.', 'faz-cookie-manager' ), array( 'status' => 500 ) );
		}
		$data = $this->prepare_item_for_response( $object, $request );
		return rest_ensure_response( $data );
	}

	/**
	 * Update an existing banner.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function update_item( $request ) {
		if ( empty( $request['id'] ) ) {
			return new WP_Error(
				'fazcookie_rest_item_exists',
				__( 'Invalid banner id', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
		}
		$registered = $this->get_collection_params();
		$object     = $this->prepare_item_for_database( $request );
		if ( isset( $registered['language'], $request['language'] ) ) {
			$object->set_language( sanitize_text_field( $request['language'] ) );
		}
		$result = $object->save();
		if ( false === $result ) {
			return new WP_Error( 'fazcookie_rest_db_error', __( 'Failed to update banner.', 'faz-cookie-manager' ), array( 'status' => 500 ) );
		}
		$data = $this->prepare_item_for_response( $object, $request );
		return rest_ensure_response( $data );
	}

	/**
	 * Delete an existing banner.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function delete_item( $request ) {
		$banner_id = (int) $request['id'];
		if ( $banner_id <= 0 ) {
			// F003 fix: the prior error code 'fazcookie_rest_item_exists'
			// was semantically inverted for an invalid-id guard. Use the
			// shared invalid-id code so REST clients can branch on a
			// consistent error code across get_item / delete_item.
			return new WP_Error(
				'fazcookie_rest_invalid_id',
				__( 'Invalid banner id', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
		}
		// F017 fix: don't probe existence separately before calling
		// $controller->delete_item(). The two-statement pattern was a
		// classic check-then-act TOCTOU — between the probe and the
		// DELETE, another admin session could remove the row, and the
		// REST response would still claim 200/deleted=1 (since the
		// controller's own delete now returns the atomic count). By
		// dropping the probe and trusting the controller's atomic
		// DELETE ... RETURNING (from F016), we get the right answer in
		// a single round-trip with zero race window: the controller
		// returns 0 when the row didn't exist, which we surface as
		// 404; >0 when it actually deleted something; false on a
		// driver/SQL error.
		$data = $this->controller->delete_item( $banner_id );
		if ( false === $data ) {
			return new WP_Error( 'fazcookie_rest_db_error', __( 'Failed to delete banner.', 'faz-cookie-manager' ), array( 'status' => 500 ) );
		}
		if ( 0 === (int) $data ) {
			return new WP_Error(
				'fazcookie_rest_invalid_id',
				__( 'Banner not found.', 'faz-cookie-manager' ),
				array( 'status' => 404 )
			);
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Performs bulk update request.
	 *
	 * @param WP_REST_Request $request WP request object.
	 * @return WP_Error|WP_REST_Response
	 */
	public function bulk( $request ) {
		$clear = $request->get_param('clear');
		if ( is_null( $clear ) ) {
			$clear = true;
		} else {
			$clear = filter_var( $clear, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		}
		$tx_started = false;
		try {
			if ( ! isset( $request['banners'] ) ) {
				return new WP_Error( 'fazcookie_rest_invalid_data', __( 'No data specified to create/edit banners', 'faz-cookie-manager' ), array( 'status' => 404 ) );
			}
			global $wpdb;
			$item_objects = array();
			$objects      = array();
			$data         = $request['banners'];
			// Reject a malformed payload before opening a transaction: iterating a
			// non-array (or a list of non-array items) would otherwise reach COMMIT
			// + do_action with zero real updates yet report success.
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'fazcookie_rest_invalid_data', __( 'Invalid banners payload: expected an array.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
			}
			if ( ! defined( 'FAZ_BULK_REQUEST' ) ) {
				define( 'FAZ_BULK_REQUEST', true );
			}

			// Wrap the multi-banner save in a transaction so a failure on banner
			// N does not leave banners 1…N-1 committed while N+1…M are dropped —
			// a partial save would publish an inconsistent banner set (e.g. two
			// banners both flagged default, or a half-applied geo-routing
			// reshuffle). Either every banner in the batch is saved or none is.
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$tx_started = true;
			foreach ( $data as $_banner ) {
				$object = $this->prepare_item_for_database( $_banner );
				$result = $object->save();
				if ( false === $result ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$tx_started = false;
					return new WP_Error( 'fazcookie_rest_db_error', __( 'Failed to save banner during bulk update.', 'faz-cookie-manager' ), array( 'status' => 500 ) );
				}
				$item_objects[] = $object;
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$tx_started = false;
			foreach ( $item_objects as $item ) {
				$response  = $this->prepare_item_for_response( $item, $request );
				$objects[] = $this->prepare_response_for_collection( $response );
			}
			do_action( 'faz_after_update_banner', $clear );
			return rest_ensure_response( $objects );
		} catch ( Exception $e ) {
			// Roll back any still-open transaction so an exception thrown after
			// START TRANSACTION does not leave the request in an open
			// transactional state (holding locks until the connection closes).
			if ( $tx_started ) {
				global $wpdb;
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			return new WP_Error( $e->getCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}
	}

	/**
	 * Load banner preview.
	 *
	 * @param WP_REST_Request $request WP_REST_Request object.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_preview( $request ) {
		$data = array();
		// Always force regeneration for previews (bypass cached template)
		if ( ! defined( 'FAZ_PREVIEW_REQUEST' ) ) {
			define( 'FAZ_PREVIEW_REQUEST', true );
		}
		$object   = $this->prepare_item_for_database( $request );
		$language = isset( $request['language'] ) ? $request['language'] : faz_default_language();
		$object->set_language( $language );
		$template       = $object->get_template();
		$data['html']   = $template['html'];
		$data['styles'] = $this->build_preview_styles( $template, $object->get_settings() );
		return rest_ensure_response( $data );
	}

	/**
	 * Build the same banner CSS the frontend would emit for this preview.
	 *
	 * @param array $template Banner template payload.
	 * @param array $settings Banner settings.
	 * @return string
	 */
	private function build_preview_styles( $template, $settings ) {
		$raw_css = isset( $template['styles'] ) ? (string) $template['styles'] : '';
		$css     = $this->boost_preview_css_specificity( $raw_css );

		$css_reset = '#faz-consent,#faz-consent *,#faz-consent *::before,#faz-consent *::after{'
			. 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;'
			. 'letter-spacing:normal;'
			. 'text-transform:none;'
			. 'font-style:normal;'
			. 'text-decoration:none;'
			. 'word-spacing:normal;'
			. 'line-height:1.5;'
			. 'box-sizing:border-box;'
			. '}';
		$css_fixes = '#faz-consent .faz-accordion-header .faz-always-active,'
			. '.faz-modal .faz-accordion-header .faz-always-active{'
			. 'margin-left:auto;margin-right:8px;white-space:nowrap;'
			. '}';

		// Custom CSS (banner.meta.customCSS) preview output removed in
		// 1.13.11 for wp.org compliance — see frontend/class-frontend.php
		// for the public-side equivalent. Existing DB values remain but
		// are inert in both contexts.
		return $css_reset . $css . $css_fixes;
	}

	/**
	 * Match frontend CSS specificity boosting for admin preview output.
	 *
	 * @param string $css Raw template CSS.
	 * @return string
	 */
	private function boost_preview_css_specificity( $css ) {
		if ( empty( $css ) ) {
			return $css;
		}

		$container_classes = array(
			'.faz-classic-top',
			'.faz-classic-bottom',
			'.faz-banner-top',
			'.faz-banner-bottom',
			'.faz-box-bottom-left',
			'.faz-box-bottom-right',
			'.faz-box-top-left',
			'.faz-box-top-right',
		);

		$sibling_prefixes = array(
			'.faz-overlay',
			'.faz-btn-revisit',
			'.faz-revisit-',
			'.faz-hide',
			'.faz-modal',
		);

		$modal_prefixes = array(
			'.faz-preference',
			'.faz-prefrence',
			'.faz-accordion',
			'.faz-audit',
			'.faz-cookie-des',
			'.faz-always-active',
			'.faz-switch',
			'.faz-chevron',
			'.faz-show-desc',
			'.faz-hide-desc',
			'.faz-btn',
			'.faz-category',
			'.faz-notice',
			'.faz-opt-out',
			'.faz-footer',
			'.faz-iab-vendors',
			'.faz-vendor-',
		);

		return preg_replace_callback(
			'/([^{}]+?)(\{)/',
			function ( $matches ) use ( $container_classes, $sibling_prefixes, $modal_prefixes ) {
				$raw = $matches[1];
				if ( false !== strpos( $raw, '@' ) ) {
					return $matches[0];
				}

				$parts = explode( ',', $raw );
				$out   = array();

				foreach ( $parts as $selector ) {
					$selector = trim( $selector );
					if ( '' === $selector ) {
						continue;
					}

					// Skip @keyframes step selectors (0%, 100%, from, to).
					if ( preg_match( '/^(?:\d+%|from|to)$/i', $selector ) ) {
						$out[] = $selector;
						continue;
					}

					if ( 0 === strpos( $selector, '.faz-consent-container' ) ) {
						$out[] = '#faz-consent' . substr( $selector, 22 );
						continue;
					}

					$matched = false;
					foreach ( $container_classes as $class_name ) {
						if ( 0 === strpos( $selector, $class_name ) ) {
							$out[]   = '#faz-consent' . $selector;
							$matched = true;
							break;
						}
					}
					if ( $matched ) {
						continue;
					}

					foreach ( $sibling_prefixes as $prefix ) {
						if ( 0 === strpos( $selector, $prefix ) ) {
							$out[]   = $selector;
							$matched = true;
							break;
						}
					}
					if ( $matched ) {
						continue;
					}

					foreach ( $modal_prefixes as $prefix ) {
						if ( 0 === strpos( $selector, $prefix ) ) {
							$out[]   = '#faz-consent ' . $selector . ',.faz-modal ' . $selector;
							$matched = true;
							break;
						}
					}
					if ( $matched ) {
						continue;
					}

					$out[] = '#faz-consent ' . $selector;
				}

				return implode( ',', $out ) . '{';
			},
			$css
		);
	}

	/**
	 * Load presets
	 *
	 * @param WP_REST_Request $request WP_REST_Request object.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_presets( $request ) {
		$registered = $this->get_collection_params();
		$presets    = array();
		if ( isset( $registered['ver'], $request['ver'] ) ) {
			$template = new \FazCookie\Admin\Modules\Banners\Includes\Template( false );
			$presets  = $template->get_presets( $request['ver'] );
		}
		return rest_ensure_response( $presets );
	}

	/**
	 * Load design presets (one-click banner styles).
	 *
	 * Reads JSON files from admin/modules/banners/includes/presets/ and returns
	 * them as an array of preset objects with an added `id` key.
	 *
	 * @return WP_REST_Response
	 */
	public function get_design_presets() {
		$presets_dir = FAZ_PLUGIN_BASEPATH . 'admin/modules/banners/includes/presets/';
		$presets     = array();

		$files = glob( $presets_dir . '*.json' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file
				$data = json_decode( $raw, true );
				if ( $data && is_array( $data ) ) {
					$data['id'] = basename( $file, '.json' );
					$presets[]  = $data;
				}
			}
		}

		return rest_ensure_response( $presets );
	}

	/**
	 * Load default banner configs.
	 *
	 * Per-user rate-limited (issue #111): admins / scripted callers can hit
	 * this endpoint at most once every `faz_configs_rate_limit_seconds`
	 * seconds. Default 5s — enough for the "+ New banner" modal to fetch
	 * the configs on open, fast enough not to interfere with rapid
	 * back-to-back creates. Override (or disable, returning 0) via the
	 * filter for staging boxes that hammer the endpoint from CI.
	 * Compatible with ClassicPress 1.x (transients + filters only).
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_configs() {
		$throttle_seconds = (int) apply_filters( 'faz_configs_rate_limit_seconds', 5 );
		if ( $throttle_seconds > 0 ) {
			$user_id = get_current_user_id();
			if ( $user_id > 0 ) {
				$rl_key  = 'faz_configs_rl_' . $user_id;
				$last_ts = (int) get_transient( $rl_key );
				$now     = time();
				if ( $last_ts > 0 && ( $now - $last_ts ) < $throttle_seconds ) {
					$retry_after = $throttle_seconds - ( $now - $last_ts );
					// Build the response directly as WP_REST_Response — going
					// through `rest_ensure_response( $wp_error )` would return
					// the WP_Error untouched (rest_ensure_response only wraps
					// arrays/objects, not WP_Errors), losing the Retry-After
					// header we need to set on the way out.
					$resp = new WP_REST_Response(
						array(
							'code'    => 'fazcookie_rest_too_many_requests',
							'message' => __( 'Too many requests. Please slow down.', 'faz-cookie-manager' ),
							'data'    => array(
								'status'      => 429,
								'retry_after' => $retry_after,
							),
						),
						429
					);
					$resp->header( 'Retry-After', (string) $retry_after );
					return $resp;
				}
				// Stamp the current second; TTL 60s is generous — even if
				// the user idles between calls, the transient self-expires.
				set_transient( $rl_key, $now, 60 );
			}
		}
		$configs = array(
			'gdpr' => $this->controller->get_default_configs(),
			'ccpa' => $this->controller->get_default_configs( 'ccpa' ),
		);
		return rest_ensure_response( $configs );
	}

	/**
	 * Format data to provide output to API
	 *
	 * @param Banner          $object Object of the corresponding item.
	 * @param WP_REST_Request $request Request params.
	 * @return WP_Error|WP_REST_Response
	 */
	public function prepare_item_for_response( $object, $request ) {
		$data    = $this->get_formatted_item_data( $object );
		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );
		return rest_ensure_response( $data );
	}

	/**
	 * Format the support before sending.
	 *
	 * @param Banner $object Banner object.
	 * @return array<string, mixed>
	 */
	public function get_formatted_item_data( $object ) {
		return array(
			'id'               => $object->get_id(),
			'slug'             => $object->get_slug(),
			'name'             => $object->get_name(),
			'status'           => $object->get_status(),
			'default'          => $object->get_default(),
			'properties'       => $object->get_settings(),
			'contents'         => $object->get_contents(),
			// Multi-banner geo-routing (1.13.18+).
			'target_countries' => $object->get_target_countries(),
			'priority'         => $object->get_priority(),
		);
	}

	/**
	 * Prepare a single item for create or update.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return Banner
	 */
	public function prepare_item_for_database( $request ) {
		$id     = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		$object = new Banner( $id );
		$object->set_name( $request['name'] );
		$object->set_default( $request['default'] );
		$object->set_status( $request['status'] );
		$object->set_settings( $request['properties'] );
		$object->set_contents( $request['contents'] );
		// Multi-banner geo-routing (1.13.18+). Both fields are optional in the
		// request — un-supplied means "leave as-is on update / default on
		// create", so legacy clients that don't send them keep working.
		if ( isset( $request['target_countries'] ) ) {
			$object->set_target_countries( $request['target_countries'] );
		}
		if ( isset( $request['priority'] ) ) {
			$object->set_priority( $request['priority'] );
		}
		return $object;
	}

	/**
	 * Get the query params for collections.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'context'  => $this->get_context_param( array( 'default' => 'view' ) ),
			'search'   => array(
				'description'       => __( 'Limit results to those matching a string.', 'faz-cookie-manager' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'ver'      => array(
				'description'       => __( 'Version', 'faz-cookie-manager' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'language' => array(
				'description'       => __( 'Language of the banner', 'faz-cookie-manager' ),
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
			'title'      => 'Banners',
			'type'       => 'object',
			'properties' => array(
				'id'            => array(
					'description' => __( 'Unique identifier for the resource.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'name'          => array(
					'description' => __( 'Banner name for reference', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'slug'          => array(
					'description' => __( 'Banner unique name', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'settings'      => array(
					'description' => __( 'Banner settings.', 'faz-cookie-manager' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
				),
				'contents'      => array(
					'description' => __( 'Banner contents.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'default'          => array(
					'description' => __( 'Indicates whether the banner is default or not', 'faz-cookie-manager' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'target_countries' => array(
					'description' => __( 'ISO-3166 alpha-2 country codes this banner targets. Empty = match every visitor.', 'faz-cookie-manager' ),
					'type'        => 'array',
					'items'       => array(
						'type'    => 'string',
						'pattern' => '^[A-Z]{2}$',
					),
					'context'     => array( 'view', 'edit' ),
				),
				'priority'         => array(
					'description' => __( 'Tie-break priority when multiple banners target the same country. Higher wins.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'context'     => array( 'view', 'edit' ),
				),
				'date_created'  => array(
					'description' => __( 'The date the banner was created, as GMT.', 'faz-cookie-manager' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified' => array(
					'description' => __( 'The date the banner was last modified, as GMT.', 'faz-cookie-manager' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
				),

			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

} // End the class.
