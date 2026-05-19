<?php
/**
 * Class Cookies_API file.
 *
 * @package Cookies
 */

namespace FazCookie\Admin\Modules\Cookies\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use FazCookie\Admin\Modules\Cookies\Api\API_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cookies API
 *
 * @class       Cookies_API
 * @version     3.0.0
 * @package     FazCookie
 */
class Cookies_API extends API_Controller {

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
	protected $rest_base = 'cookies';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 10 );
	}
	/**
	 * Register the routes for cookies.
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
			'/' . $this->rest_base . '/bulk-update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_update' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk-delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_delete' ),
				'permission_callback' => array( $this, 'delete_item_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/blocker-templates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_blocker_templates' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
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
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

	}

	/**
	 * Return cookie ids
	 *
	 * @param array $args Request arguments.
	 * @return array
	 */
	public function get_item_objects( $args ) {
		return Cookie_Controller::get_instance()->get_items_by_category( $args );
	}

	/**
	 * Return item object
	 *
	 * @param object|null $item Cookie item.
	 * @return Cookie
	 */
	public function get_item_object( $item = null ) {
		return new Cookie( $item );
	}
	/**
	 * Get formatted item data.
	 *
	 * Merges the admin-only script fields back in so REST callers (which run
	 * through the 'edit' context check in prepare_item_for_response) still
	 * receive opt_in_script / opt_out_script. Other consumers of
	 * Cookie::get_prepared_data() — such as the category controller — do not
	 * see those fields, preventing accidental exposure of raw JS.
	 *
	 * @since  3.0.0
	 * @param  Cookie $object Cookie instance.
	 * @return array
	 */
	protected function get_formatted_item_data( $object ) {
		$data = $object->get_prepared_data();
		$data = array_merge( $data, $object->get_script_data() );
		return $data;
	}
	/**
	 * Get the Cookies's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'cookie',
			'type'       => 'object',
			'properties' => array(
				'id'            => array(
					'description' => __( 'Unique identifier for the resource.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_created'  => array(
					'description' => __( 'The date the cookie was created, as GMT.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified' => array(
					'description' => __( 'The date the cookie was last modified, as GMT.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'name'          => array(
					'description' => __( 'Cookie name.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'category'      => array(
					'description' => __( 'Cookie category name.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'slug'          => array(
					'description' => __( 'Cookie unique name', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'description'   => array(
					'description' => __( 'Cookie description.', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'duration'      => array(
					'description' => __( 'Cookie duration', 'faz-cookie-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'language'      => array(
					'description' => __( 'Cookie language.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'type'          => array(
					'description' => __( 'Cookie type.', 'faz-cookie-manager' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'domain'        => array(
					'description' => __( 'Cookie domain.', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'discovered'    => array(
					'description' => __( 'If cookies added from the scanner or not.', 'faz-cookie-manager' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'url_pattern'    => array(
					'description' => __( 'URL patterns for blocking purposes', 'faz-cookie-manager' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'opt_in_script'  => array(
					'description'       => __( 'JavaScript executed when this cookie\'s category is accepted.', 'faz-cookie-manager' ),
					'type'              => 'string',
					// Keep out of the public 'view' context so the raw JS is not exposed
					// to unauthenticated callers; only admins with 'edit' context see it.
					'context'           => array( 'edit' ),
					// Only users with unfiltered_html (administrators on single-site,
					// super-admins on multisite) may save arbitrary JS. Everyone else gets
					// an empty string, which preserves the existing value.
					'sanitize_callback' => array( __CLASS__, 'sanitize_script_field' ),
					'maxLength'         => 10000,
				),
				'opt_out_script' => array(
					'description'       => __( 'JavaScript executed when this cookie\'s category is rejected or revoked.', 'faz-cookie-manager' ),
					'type'              => 'string',
					'context'           => array( 'edit' ),
					'sanitize_callback' => array( __CLASS__, 'sanitize_script_field' ),
					'maxLength'         => 10000,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}
	/**
	 * Bulk update cookies (e.g., change category for multiple cookies at once).
	 *
	 * Iterates the item schema dynamically so every editable property is
	 * honoured — mirroring API_Controller::prepare_item_for_database — rather
	 * than hardcoding a subset of fields. Script fields (opt_in_script,
	 * opt_out_script) flow through sanitize_script_field so the unfiltered_html
	 * capability gate is enforced symmetrically with single-item updates.
	 *
	 * @param \WP_REST_Request $request Request with 'cookies' array.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_update( $request ) {
		$items = $request->get_param( 'cookies' );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return new \WP_Error( 'invalid_data', __( 'No cookies provided.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		$schema     = $this->get_item_schema();
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		// Editable property keys = every schema property that is not readonly.
		$editable_keys = array();
		foreach ( $properties as $key => $property ) {
			if ( isset( $property['readonly'] ) && true === $property['readonly'] ) {
				continue;
			}
			$editable_keys[] = $key;
		}

		$updated = array();
		foreach ( $items as $item ) {
			$id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			if ( ! $id ) {
				continue;
			}
			$cookie = new Cookie( $id );
			if ( ! $cookie->get_loaded() ) {
				continue;
			}

			foreach ( $editable_keys as $key ) {
				// Partial-update semantics: only override fields explicitly present.
				if ( ! array_key_exists( $key, $item ) ) {
					continue;
				}
				$value = $item[ $key ];

				// Capability-aware sanitisation for raw-JS fields. Reuse the
				// same gate the single-item schema uses so callers without
				// unfiltered_html cannot silently smuggle scripts through
				// the bulk endpoint.
				if ( 'opt_in_script' === $key || 'opt_out_script' === $key ) {
					$sanitised = self::sanitize_script_field( $value, $request, $key );
					if ( is_wp_error( $sanitised ) ) {
						return $sanitised;
					}
					$value = $sanitised;
				}

				$setter = "set_{$key}";
				if ( is_callable( array( $cookie, $setter ) ) ) {
					$cookie->{$setter}( $value );
				}
			}

			$cookie->save();
			$response  = $this->prepare_item_for_response( $cookie, $request );
			$updated[] = $this->prepare_response_for_collection( $response );
		}

		do_action( 'faz_after_update_cookie' );

		return rest_ensure_response( array(
			'updated' => count( $updated ),
			'cookies' => $updated,
		) );
	}

	/**
	 * Bulk delete cookies by ID.
	 *
	 * @param \WP_REST_Request $request Request with 'ids' array.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_delete( $request ) {
		$ids = $request->get_param( 'ids' );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return new \WP_Error( 'invalid_data', __( 'No cookie IDs provided.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}
		$deleted = 0;
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}
			$cookie = new Cookie( $id );
			if ( $cookie->get_loaded() ) {
				$cookie->delete();
				$deleted++;
			}
		}
		do_action( 'faz_after_delete_cookie' );
		return rest_ensure_response( array( 'deleted' => $deleted ) );
	}

	/**
	 * Return all available blocker templates.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public function get_blocker_templates( $request ) {
		$templates_dir = FAZ_PLUGIN_BASEPATH . 'admin/modules/cookies/includes/blocker-templates/';
		$templates     = array();

		foreach ( glob( $templates_dir . '*.json' ) as $file ) {
			$data = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $data && isset( $data['id'] ) ) {
				$templates[] = $data;
			}
		}

		return rest_ensure_response( $templates );
	}

	/**
	 * Sanitize an admin-defined script field (opt_in_script / opt_out_script).
	 *
	 * Raw JavaScript may only be saved by users with the `unfiltered_html`
	 * capability — equivalent to Administrators on single-site and Super Admins
	 * on multisite. Any other role gets a 403 WP_Error so the request fails
	 * explicitly instead of silently dropping the modification.
	 *
	 * This mirrors WordPress core's handling of unfiltered content in the REST
	 * API (see WP_REST_Posts_Controller::sanitize_post_statuses).
	 *
	 * @param mixed $value Raw input value.
	 * @param WP_REST_Request $request Request object required by the REST API signature.
	 * @param string $param Parameter name required by the REST API signature.
	 * @return string|WP_Error
	 */
	public static function sanitize_script_field( $value, $request, $param ) {
		// Allow saves with empty script fields regardless of capability. The
		// admin UI always submits these fields (even empty strings) on every
		// cookie edit, so a strict capability check would otherwise block
		// multisite site-admins who have `manage_options` but not
		// `unfiltered_html` from editing any cookie. Empty strings cannot
		// inject JavaScript, so there is no XSS risk in this early return.
		if ( '' === (string) $value ) {
			return '';
		}
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to modify script fields.', 'faz-cookie-manager' ),
				array( 'status' => 403 )
			);
		}
		return (string) $value;
	}

	/**
	 * Capability-aware sanitiser for an entire cookie/category meta array.
	 *
	 * Strips script keys (opt_in_script, opt_out_script) when the current user
	 * lacks `unfiltered_html`. This is the single source of truth for every
	 * write path into wp_faz_cookies.meta / wp_faz_cookie_categories.meta —
	 * REST per-field updates, bulk update, settings import, WP-CLI import, and
	 * internal Cookie::set_meta() defence-in-depth all route through it.
	 *
	 * Unlike sanitize_script_field which returns a WP_Error on cap failure
	 * (suitable for an inline schema sanitize_callback), this helper silently
	 * unsets script keys so bulk write paths (import) do not abort the entire
	 * payload over a single privileged field; the caller may emit a warning
	 * when keys are stripped (see WP-CLI import).
	 *
	 * @param mixed $meta Raw meta data; expected to be an associative array or
	 *                    JSON-encoded string. Non-array values pass through.
	 * @return array|mixed Sanitised meta (array) when input was array/JSON, or
	 *                     the original value when input was not coercible.
	 */
	public static function sanitize_meta_for_current_user( $meta ) {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $meta;
		}

		$decoded = $meta;
		$was_json_string = false;
		if ( is_string( $meta ) ) {
			$tentative = json_decode( $meta, true );
			if ( is_array( $tentative ) ) {
				$decoded = $tentative;
				$was_json_string = true;
			}
		}

		if ( ! is_array( $decoded ) ) {
			return $meta;
		}

		$stripped = false;
		foreach ( array( 'opt_in_script', 'opt_out_script' ) as $script_key ) {
			if ( array_key_exists( $script_key, $decoded ) && '' !== (string) $decoded[ $script_key ] ) {
				unset( $decoded[ $script_key ] );
				$stripped = true;
			}
		}

		// Allow callers to detect stripping (e.g. WP-CLI import warnings).
		if ( $stripped ) {
			/**
			 * Fires when script meta keys are stripped due to missing
			 * unfiltered_html capability. Hooked by WP-CLI commands to surface
			 * a warning. Side-effect-free for normal users.
			 */
			do_action( 'faz_meta_script_keys_stripped' );
		}

		return $was_json_string ? wp_json_encode( $decoded ) : $decoded;
	}

} // End the class.
