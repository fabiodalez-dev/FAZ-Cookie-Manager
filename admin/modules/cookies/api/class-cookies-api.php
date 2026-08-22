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
use FazCookie\Admin\Modules\Scanner\Includes\Controller as Scanner_Controller;

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
	 * Snapshots of bulk-deleted cookies, most recent first.
	 *
	 * A bulk delete removes entries from the site's public cookie declaration,
	 * and the usual trigger is a scan that did not observe them — which is not
	 * the same as their being absent. This makes that judgement reversible.
	 *
	 * @var string
	 */
	const RECYCLE_BIN_OPTION = 'faz_cookies_recycle_bin';

	/**
	 * How many delete batches stay restorable.
	 *
	 * An undo for a mistake noticed soon after, not an archive.
	 *
	 * @var int
	 */
	const RECYCLE_BIN_BATCHES = 3;

	/**
	 * Soft ceiling, in bytes, for the serialized recycle bin.
	 *
	 * A purge of 1000 rows serializes to roughly 1.8 MB across three batches,
	 * so this is a guard against a pathological catalogue, not a hot path. It
	 * matters because the option is a single row: past the server's
	 * max_allowed_packet the write fails outright, and a bin that cannot be
	 * written is a purge that must not happen (see bulk_delete()).
	 *
	 * @var int
	 */
	const RECYCLE_BIN_MAX_BYTES = 2097152;

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
				'args'                => array(
					// Optional, and deliberately opt-in. Only the Cookies page's
					// stale-purge sends reason=stale, and only that value turns on
					// the consecutive-miss threshold below. The default unscoped
					// path stays exactly as it was: an administrator selecting
					// rows by hand must keep being able to delete any of them,
					// including hand-added and never-scanned entries.
					'reason' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/restore-deleted',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_deleted' ),
				// Restoring writes cookie rows, so it is gated on the create
				// capability rather than the delete one that produced the batch.
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
			)
		);

		// What is still undoable. A read path is what lets the Cookies page show
		// the undo affordance on load rather than only in the seconds after a
		// delete — the bin persists several batches across reloads, and an
		// administrator notices a wrong purge after navigating away.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/deleted-batches',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_deleted_batches' ),
				// A read uses the read permission, matching the other READABLE
				// routes here; create_item_* would also demand the write nonce.
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
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

		// Manual service registration (#161): list the built-in provider
		// catalogue, and register a chosen service's cookies into wp_faz_cookies
		// so they are declared domain-wide without relying on the scanner.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/catalogue-services',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_catalogue_services' ),
				// Read endpoint → use the read permission (manage_options),
				// matching the other READABLE routes here. create_item_* also
				// demands the plugin write-nonce, which a read needn't. (#162 review)
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/register-service',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'register_service' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => array(
					'service_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
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
	 * Active non-necessary category slugs that exist on this install.
	 *
	 * @return array
	 */
	private function active_category_slugs() {
		$slugs = array();
		$cats  = \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::get_instance()->get_items();
		foreach ( (array) $cats as $cat ) {
			$slug = is_object( $cat ) ? ( $cat->slug ?? '' ) : ( $cat['slug'] ?? '' );
			if ( '' !== $slug && 'necessary' !== $slug && 'wordpress-internal' !== $slug ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	/**
	 * Existing cookie names already in wp_faz_cookies (lower-cased map).
	 *
	 * @return array
	 */
	private function existing_cookie_names() {
		$names = array();
		$rows  = Cookie_Controller::get_instance()->get_item_from_db();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$n = is_object( $row ) ? ( $row->name ?? '' ) : ( $row['name'] ?? '' );
				if ( '' !== $n ) {
					$names[ strtolower( $n ) ] = true;
				}
			}
		}
		return $names;
	}

	/**
	 * Best-effort cookie domain derived from a provider's first host-like pattern.
	 *
	 * @param array $patterns Provider URL/inline patterns.
	 * @return string
	 */
	private function provider_domain_from_patterns( $patterns ) {
		foreach ( (array) $patterns as $pattern ) {
			$pattern = is_string( $pattern ) ? trim( $pattern ) : '';
			if ( '' === $pattern ) {
				continue;
			}
			// Take the token before the first slash, drop scheme if present.
			$host = preg_replace( '#^https?://#i', '', $pattern );
			$host = explode( '/', $host )[0];
			if ( false !== strpos( $host, '.' ) && false === strpos( $host, ' ' ) ) {
				return sanitize_text_field( $host );
			}
		}
		return '';
	}

	/**
	 * Concrete (non-wildcard) cookie names declared by a provider.
	 *
	 * Some catalogue entries list wildcard patterns (e.g. "_hjSession_*").
	 * Those can't be created as a literal wp_faz_cookies row and never match a
	 * real scanned cookie name, so they are excluded from both the registration
	 * payload and the "registered" calculation — mirroring the scanner's own
	 * inference path, which already drops them. (#161)
	 *
	 * @param array $svc Provider entry from Known_Providers.
	 * @return array
	 */
	private function provider_concrete_cookies( $svc ) {
		$cookies = isset( $svc['cookies'] ) && is_array( $svc['cookies'] ) ? $svc['cookies'] : array();
		$out     = array();
		foreach ( $cookies as $name ) {
			$name = is_string( $name ) ? trim( $name ) : '';
			if ( '' !== $name && false === strpos( $name, '*' ) ) {
				$out[] = $name;
			}
		}
		return array_values( $out );
	}

	/**
	 * GET catalogue-services — the built-in provider catalogue for the manual
	 * registration UI (#161). Lists non-necessary providers whose category is
	 * active on this install, flagging which are already fully registered.
	 *
	 * @return WP_REST_Response
	 */
	public function get_catalogue_services() {
		$active   = $this->active_category_slugs();
		$existing = $this->existing_cookie_names();
		$out      = array();
		foreach ( \FazCookie\Includes\Known_Providers::get_all() as $id => $svc ) {
			$category = isset( $svc['category'] ) ? $svc['category'] : '';
			if ( '' === $category || ! in_array( $category, $active, true ) ) {
				continue;
			}
			$cookies = $this->provider_concrete_cookies( $svc );
			// Skip providers that declare no concrete cookie names — nothing to
			// register as a transparency row (they are still blocked by URL
			// pattern, and wildcard-only entries can't be persisted).
			if ( empty( $cookies ) ) {
				continue;
			}
			$missing = 0;
			foreach ( $cookies as $name ) {
				if ( empty( $existing[ strtolower( (string) $name ) ] ) ) {
					$missing++;
				}
			}
			$out[] = array(
				'id'           => sanitize_key( $id ),
				'label'        => isset( $svc['label'] ) ? sanitize_text_field( $svc['label'] ) : sanitize_key( $id ),
				'category'     => sanitize_key( $category ),
				'cookie_count' => count( $cookies ),
				'registered'   => ( 0 === $missing ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);
		return new WP_REST_Response( array( 'services' => $out ), 200 );
	}

	/**
	 * POST register-service — register a catalogue service's cookies into
	 * wp_faz_cookies (discovered=1) so they are declared domain-wide on every
	 * page without relying on the scanner, and feed the Cookie Policy generator.
	 * Reuses the scanner's save_cookies() enrichment (Cookie_Database → Known
	 * Providers → Open Cookie Database). (#161)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_service( $request ) {
		$service_id = sanitize_key( (string) $request->get_param( 'service_id' ) );
		if ( '' === $service_id ) {
			return new WP_Error( 'faz_invalid_service', __( 'A service id is required.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}
		$providers = \FazCookie\Includes\Known_Providers::get_all();
		if ( ! isset( $providers[ $service_id ] ) ) {
			return new WP_Error( 'faz_unknown_service', __( 'Unknown service.', 'faz-cookie-manager' ), array( 'status' => 404 ) );
		}
		$svc      = $providers[ $service_id ];
		$category = isset( $svc['category'] ) ? sanitize_key( $svc['category'] ) : '';
		if ( '' === $category || 'necessary' === $category || ! in_array( $category, $this->active_category_slugs(), true ) ) {
			return new WP_Error( 'faz_invalid_category', __( 'This service maps to a category that is not active.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}
		$cookie_names = $this->provider_concrete_cookies( $svc );
		if ( empty( $cookie_names ) ) {
			return new WP_Error( 'faz_no_cookies', __( 'This service declares no registrable cookies.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}
		$domain  = $this->provider_domain_from_patterns( isset( $svc['patterns'] ) ? $svc['patterns'] : array() );
		$before  = $this->existing_cookie_names();
		$payload = array();
		foreach ( $cookie_names as $name ) {
			$name = sanitize_text_field( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$payload[] = array(
				'name'     => $name,
				'category' => $category,
				'domain'   => $domain,
			);
		}

		// Reuse the scanner's enrichment + persistence (sets discovered=1, skips
		// existing names, flushes caches). Guarded so a missing scanner module
		// never fatals the endpoint.
		$scanner = '\\FazCookie\\Admin\\Modules\\Scanner\\Includes\\Controller';
		if ( ! class_exists( $scanner ) ) {
			return new WP_Error( 'faz_scanner_unavailable', __( 'The registration engine is unavailable.', 'faz-cookie-manager' ), array( 'status' => 500 ) );
		}
		try {
			\call_user_func( array( $scanner, 'get_instance' ) )->save_cookies( $payload );
		} catch ( \Throwable $e ) {
			// save_cookies() writes row by row, so an exception on one entry
			// leaves the earlier ones persisted. The runtime map is rebuilt from
			// that table, and it is what decides which scripts are held before
			// consent: leaving it stale means cookies that ARE registered are
			// not yet blocked. Failing the request does not undo the writes, so
			// the map has to be invalidated on the way out too — the error path
			// needs this more than the success path, not less.
			delete_transient( 'faz_cookie_scripts_map' );
			error_log( 'FAZ: service registration failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- preserve diagnostics while returning a safe REST error.
			return new WP_Error(
				'faz_service_registration_failed',
				__( 'The service cookies could not be registered. Review the scanner log and try again.', 'faz-cookie-manager' ),
				array( 'status' => 500 )
			);
		}

		// Invalidate the runtime cookie-scripts map so the new rows surface on
		// the next render, and fire the standard write action (cache purge etc.).
		delete_transient( 'faz_cookie_scripts_map' );
		do_action( 'faz_after_update_cookie' );

		$after = $this->existing_cookie_names();
		$added = max( 0, count( $after ) - count( $before ) );
		return new WP_REST_Response(
			array(
				'service'   => array(
					'id'    => $service_id,
					'label' => isset( $svc['label'] ) ? sanitize_text_field( $svc['label'] ) : $service_id,
				),
				'requested' => count( $payload ),
				'added'     => $added,
				'category'  => $category,
			),
			200
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
				'transfer'       => array(
					'description'       => __( 'Third-country (Schrems II) personal-data transfer disclosure for this cookie.', 'faz-cookie-manager' ),
					'type'              => 'object',
					// Visible in both contexts: this is not sensitive raw JS, it is
					// a transparency disclosure that the frontend store and the
					// public Cookie Policy both consume from the 'view' context.
					'context'           => array( 'view', 'edit' ),
					// No unfiltered_html gate — not a script field. The safeguard
					// text is still markup-filtered via wp_kses in the sanitiser.
					'sanitize_callback' => array( __CLASS__, 'sanitize_transfer_field' ),
					'properties'        => array(
						'enabled'   => array( 'type' => 'boolean' ),
						'countries' => array( 'type' => 'object' ),
						'safeguard' => array( 'type' => 'object' ),
					),
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
		$refused = 0;

		// Scoped threshold enforcement. reason=stale means "delete these because
		// a scan did not see them", and that claim is only admissible once an
		// entry has been missing from Controller::MISSED_SCANS_THRESHOLD
		// consecutive COMPLETE scans. The browser computes the candidate list, so
		// without this check the safeguard is advisory: a stale request, or a
		// direct REST call, could purge on a single missed scan.
		//
		// Deliberately NOT a blanket gate. Applying it to every caller would take
		// away an administrator's ability to delete a hand-added or never-scanned
		// row — a visible regression across the whole Cookies page, and a worse
		// outcome than the gap being closed.
		$reason = sanitize_key( (string) $request->get_param( 'reason' ) );
		$earned = null;
		if ( 'stale' === $reason ) {
			$earned = array_flip( Scanner_Controller::get_instance()->deletable_stale_keys() );
		}

		// Two phases, and the order is the whole point.
		//
		// PHASE 1 collects the snapshots. Nothing is deleted here. A bulk delete
		// removes entries from the site's PUBLIC cookie declaration, and the
		// usual trigger is a scan that did not observe them — which is not the
		// same as their being absent. The snapshot makes that judgement
		// reversible; without it a wrong purge is unrecoverable and the
		// declaration is quietly incomplete.
		$recycled = array();
		$doomed   = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}
			$cookie = new Cookie( $id );
			if ( null !== $earned && $cookie->get_loaded() ) {
				// Same canonical form the tally is keyed on and the client
				// intersects against — one builder, no second key format.
				$key = Scanner_Controller::canonical_key( $cookie->get_name(), $cookie->get_domain() );
				if ( '' === $key || ! isset( $earned[ $key ] ) ) {
					++$refused;
					continue;
				}
			}
			if ( $cookie->get_loaded() ) {
				// get_prepared_data() omits opt_in_script/opt_out_script, and
				// restore_deleted() can only put back what the snapshot holds —
				// so restoring would have silently dropped the blocker scripts.
				$snapshot = $cookie->get_prepared_data();
				if ( method_exists( $cookie, 'get_script_data' ) ) {
					$snapshot = array_merge( $snapshot, (array) $cookie->get_script_data() );
				}
				$recycled[] = $snapshot;
				$doomed[]   = $cookie;
			}
		}

		// PHASE 2 persists the snapshot BEFORE a single row is removed.
		//
		// Deleting first and saving afterwards looks equivalent and is not. When
		// the write fails — oversize packet, an OOM part-way through a large
		// purge, any transient database error $wpdb swallows with WP_DEBUG off —
		// the rows are already gone while wp_options still holds the PREVIOUS
		// batch. The page then re-reads the bin, finds that older batch, and
		// offers an Undo button that restores a DIFFERENT set of cookies while
		// the administrator believes the purge was reversed. An affirmative
		// false undo is worse than no undo at all, so a bin that cannot be
		// written aborts the purge instead.
		if ( ! empty( $recycled ) ) {
			$bin = get_option( self::RECYCLE_BIN_OPTION, array() );
			$bin = is_array( $bin ) ? $bin : array();
			array_unshift(
				$bin,
				array(
					'deleted_at' => time(),
					'cookies'    => $recycled,
				)
			);
			// Keep a handful of batches, not a growing history: this is an undo
			// for a mistake noticed soon after, not an archive.
			$bin = array_slice( $bin, 0, self::RECYCLE_BIN_BATCHES );
			$bin = self::trim_recycle_bin_to_size( $bin );

			if ( ! self::store_recycle_bin( $bin ) ) {
				// No hook here. Phase 3 never ran, so provably zero rows were
				// removed — firing the same action the success path fires made
				// every listener (page-cache purge, banner-template
				// regeneration, category cache, IAB vendor recount) invalidate
				// for a deletion that did not happen, at the exact moment the
				// admin is told nothing was deleted.
				return new \WP_Error(
					'faz_recycle_bin_write_failed',
					__( 'The undo snapshot could not be saved, so nothing was deleted. The cookies were left in place.', 'faz-cookie-manager' ),
					array(
						'status'          => 500,
						'deleted'         => 0,
						'restorable'      => 0,
						'refused'         => $refused,
						'snapshot_failed' => true,
					)
				);
			}
		}

		// PHASE 3. The snapshot is on disk and verified; now the rows may go.
		foreach ( $doomed as $cookie ) {
			$cookie->delete();
			$deleted++;
		}

		do_action( 'faz_after_delete_cookie' );
		return rest_ensure_response(
			array(
				'deleted'    => $deleted,
				// Backed by a verified write, not by an in-memory array: this
				// line is only reached once store_recycle_bin() confirmed the
				// option really holds these snapshots.
				'restorable' => count( $recycled ),
				// Rows the stale purge asked for that have not yet earned
				// deletability. Reported so the page can say so rather than
				// quietly deleting fewer entries than the admin was shown.
				'refused'    => $refused,
			)
		);
	}

	/**
	 * Write the recycle bin and confirm the write actually landed.
	 *
	 * update_option() answers false for two unrelated situations: the write
	 * failed, and the stored value was already identical. Treating both as
	 * failure would abort purges that are perfectly safe; treating both as
	 * success is the bug this exists to prevent. So a false is resolved by
	 * reading the option back and comparing — on a genuine failure the cache
	 * and the row still hold the previous value, and the comparison says so.
	 *
	 * @param array $bin Recycle bin to store.
	 * @return bool True when the option demonstrably holds $bin.
	 */
	private static function store_recycle_bin( $bin ) {
		if ( update_option( self::RECYCLE_BIN_OPTION, $bin, false ) ) {
			return true;
		}
		$written = get_option( self::RECYCLE_BIN_OPTION, null );
		return is_array( $written ) && $written === $bin;
	}

	/**
	 * Drop the oldest batches until the bin fits in one option row.
	 *
	 * The newest batch is never dropped: it is the undo for the purge being
	 * performed right now, which is the one an administrator is most likely to
	 * want back. If that batch alone is over the ceiling it is still attempted —
	 * a write that then fails aborts the purge, which is the correct outcome.
	 *
	 * @param array $bin Recycle bin, newest first.
	 * @return array Possibly shortened bin.
	 */
	private static function trim_recycle_bin_to_size( $bin ) {
		while ( count( $bin ) > 1 && strlen( (string) maybe_serialize( $bin ) ) > self::RECYCLE_BIN_MAX_BYTES ) {
			array_pop( $bin );
		}
		return $bin;
	}

	/**
	 * Describe what is still restorable from the recycle bin.
	 *
	 * Metadata only — never the snapshotted rows, which carry raw opt-in/opt-out
	 * blocker scripts.
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_deleted_batches( $request ) {
		$bin     = get_option( self::RECYCLE_BIN_OPTION, array() );
		$bin     = is_array( $bin ) ? $bin : array();
		$now     = time();
		$batches = array();
		foreach ( array_values( $bin ) as $index => $batch ) {
			if ( ! is_array( $batch ) ) {
				continue;
			}
			$deleted_at = isset( $batch['deleted_at'] ) ? absint( $batch['deleted_at'] ) : 0;
			$batches[]  = array(
				'index'            => (int) $index,
				'count'            => isset( $batch['cookies'] ) ? count( (array) $batch['cookies'] ) : 0,
				'deleted_at'       => $deleted_at,
				// The timestamp was recorded from the first release of the bin
				// and read by nothing, so the bar called an eight-month-old
				// purge "recently deleted" and offered to resurrect cookies
				// whose categories and policy text had long moved on.
				//
				// Rendered rather than age-pruned on purpose: pruning would
				// destroy the only recovery path on a schedule nobody sees,
				// which is the same class of silent loss the bin was added to
				// prevent. Showing the age lets the administrator judge, and
				// costs nothing when the answer is "two minutes".
				//
				// Formatted server-side because human_time_diff() is
				// translated and locale-aware; the raw timestamp stays in the
				// payload for any caller that wants to do its own arithmetic.
				'deleted_at_human' => $deleted_at > 0 ? human_time_diff( $deleted_at, $now ) : '',
			);
		}

		return rest_ensure_response(
			array(
				'batches'     => $batches,
				'batch_count' => count( $batches ),
			)
		);
	}

	/**
	 * Restore the most recent bulk-deleted batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore_deleted( $request ) {
		$bin = get_option( self::RECYCLE_BIN_OPTION, array() );
		$bin = is_array( $bin ) ? $bin : array();
		if ( empty( $bin ) ) {
			return new \WP_Error( 'faz_nothing_to_restore', __( 'There is no recently deleted batch to restore.', 'faz-cookie-manager' ), array( 'status' => 404 ) );
		}

		$batch     = array_shift( $bin );
		$snapshots = isset( $batch['cookies'] ) ? array_values( (array) $batch['cookies'] ) : array();

		// A restore is not a save: nobody typed this script, it is being put
		// back exactly as it was. But set_opt_in_script()/set_opt_out_script()
		// route through set_meta(), whose sanitizer strips raw JavaScript for a
		// caller below `unfiltered_html` — so for such a user the row comes
		// back with its name, category and duration intact and its BLOCKING
		// BEHAVIOUR silently gone. The declaration then looks complete while the
		// service it was supposed to gate loads unconditionally, which is worse
		// than not restoring at all because nothing on the page says so.
		//
		// Refuse instead, before the setter loop and before the bin is touched,
		// so the batch stays intact for an administrator who can restore it
		// whole. Batches carrying no script data are unaffected, as is every
		// caller who does hold the capability.
		if ( self::batch_carries_scripts( $snapshots ) && ! current_user_can( 'unfiltered_html' ) ) {
			return new \WP_Error(
				'faz_restore_requires_unfiltered_html',
				__( 'This batch contains opt-in/opt-out blocker scripts, which your account is not allowed to save. Restoring it would put the cookies back without their blocking behaviour, so the batch has been left in the recycle bin for an administrator to restore.', 'faz-cookie-manager' ),
				array( 'status' => 403 )
			);
		}

		$restored     = 0;
		$skipped      = 0;
		$retained     = array();
		// Keyed on the SAME identity the rest of the catalogue uses — the stale
		// set, the delete gate at line 749 and the browser's getStaleKey() all
		// key on name+domain via canonical_key(). Keying the duplicate check on
		// the bare name meant a snapshot for `_ga` on one domain was skipped
		// because an unrelated `_ga` existed on another; the skipped row is not
		// retained either, so when any sibling in the batch did restore, the
		// shortened bin was written and that row was gone from BOTH the live
		// table and the recycle bin. Silent, unrecoverable, in the one feature
		// whose whole purpose is making a wrong purge reversible.
		$current_keys = array();
		foreach ( (array) Cookie_Controller::get_instance()->get_item_from_db() as $current ) {
			if ( ! empty( $current->name ) ) {
				$faz_key = Scanner_Controller::canonical_key(
					(string) $current->name,
					isset( $current->domain ) ? (string) $current->domain : ''
				);
				if ( '' !== $faz_key ) {
					$current_keys[ $faz_key ] = true;
				}
			}
		}
		// The snapshot is replayed through an explicit allowlist, not a blind
		// set_{key} dispatch. get_prepared_data() names the identity 'id', so
		// the snapshot carries the id of a row that no longer exists; handed to
		// set_id() it would send save() down the UPDATE branch, which matches
		// nothing — and, once a settings import has re-inserted rows with
		// explicit cookie_id values, could match a DIFFERENT live cookie and
		// overwrite it. The allowlist closes the whole class rather than that
		// one key: no value out of a wp_options blob gets to choose which
		// public setter it calls. The scripts stay on their setters on purpose,
		// so the unfiltered_html gate inside them still strips raw JS for a
		// restorer below that capability.
		$restorable = array(
			'name',
			'slug',
			'description',
			'duration',
			'type',
			'domain',
			'discovered',
			'url_pattern',
			'category',
			'transfer',
			'opt_in_script',
			'opt_out_script',
		);
		foreach ( $snapshots as $data ) {
			if ( ! is_array( $data ) || empty( $data['name'] ) ) {
				continue;
			}
			// A restore must not resurrect a duplicate if the cookie has since
			// been re-discovered or re-added by hand. There is no by-name
			// lookup on the controller, so the current set is read once above
			// and consulted here — on name+domain, so a same-named cookie on a
			// DIFFERENT domain is a different cookie and still gets restored.
			$faz_snapshot_key = Scanner_Controller::canonical_key(
				(string) $data['name'],
				isset( $data['domain'] ) ? (string) $data['domain'] : ''
			);
			if ( '' !== $faz_snapshot_key && isset( $current_keys[ $faz_snapshot_key ] ) ) {
				++$skipped;
				continue;
			}
			$cookie = new Cookie();
			foreach ( $restorable as $field ) {
				if ( ! array_key_exists( $field, $data ) ) {
					continue;
				}
				$setter = 'set_' . $field;
				if ( method_exists( $cookie, $setter ) ) {
					$cookie->$setter( $data[ $field ] );
				}
			}
			// A restore is always an INSERT: the row it came from is gone. Make
			// that an asserted invariant rather than an accident of which keys
			// the snapshot happened to hold.
			if ( 0 !== $cookie->get_id() ) {
				$retained[] = $data;
				continue;
			}
			// save() returns get_id() unconditionally and can never signal
			// failure. On an object that entered at 0, a non-zero id coming
			// back is the one honest piece of evidence that the row was
			// written: create_item() returns before set_id() when the insert
			// fails, so the id stays 0 on a rejected row.
			$new_id = $cookie->save();
			if ( $new_id ) {
				$restored++;
			} else {
				$retained[] = $data;
			}
		}

		// Consuming the batch is only legitimate once the rows are actually
		// back. A restore that wrote nothing must leave the bin untouched:
		// otherwise the failure also destroys the only undo record and the
		// retry has nothing left to restore, which is what turns a bug into
		// data loss.
		//
		// A PARTIAL restore is the same failure at a smaller scale, and it used
		// to be invisible: three rows offered, one insert rejected, `$restored`
		// is 2 and the whole batch — including the row that never came back —
		// was dropped from the bin. The rows that did not save are therefore put
		// back as a batch of their own, so the retry still has exactly what is
		// still missing. Rows skipped as duplicates are NOT retained: the cookie
		// is already live, so there is nothing left to restore.
		//
		// A batch whose rows were ALL already live is settled too, and the
		// `$restored > 0` guard alone left it at the head of the bin forever:
		// the Undo bar kept advertising it, every click answered restored:0 —
		// with a SUCCESS-toned "0 cookie(s) restored." — and re-rendered the
		// identical bar. The only exit was a later purge pushing it off the end.
		// Consuming it when every row was skipped and nothing is retained ends
		// that loop while keeping the write-nothing-leave-the-bin rule intact
		// for the case it exists for: a restore that FAILED.
		if ( $restored > 0 || ( $skipped > 0 && empty( $retained ) ) ) {
			if ( ! empty( $retained ) ) {
				$batch['cookies'] = array_values( $retained );
				array_unshift( $bin, $batch );
			}
			update_option( self::RECYCLE_BIN_OPTION, $bin, false );
		}
		do_action( 'faz_after_create_cookie' );
		// `skipped` travels so the client can say "already present" instead of
		// reporting a success-toned zero the admin cannot act on.
		return rest_ensure_response(
			array(
				'restored' => $restored,
				'skipped'  => $skipped,
			)
		);
	}

	/**
	 * Whether a recycle-bin batch carries opt-in/opt-out blocker scripts.
	 *
	 * Only non-empty values count: a snapshot of a cookie that never had a
	 * blocker holds two empty strings, and refusing that restore would block
	 * the common case for no benefit.
	 *
	 * @param array $snapshots Snapshot rows from one batch.
	 * @return bool
	 */
	private static function batch_carries_scripts( $snapshots ) {
		foreach ( (array) $snapshots as $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			foreach ( array( 'opt_in_script', 'opt_out_script' ) as $field ) {
				if ( isset( $data[ $field ] ) && '' !== trim( (string) $data[ $field ] ) ) {
					return true;
				}
			}
		}
		return false;
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

		// When Instagram Feed limits itself, this plugin stands down for that feed
		// — container and script alike. The template stays listed and stays
		// addable, because the setting it belongs to is Instagram Feed's own and
		// can be changed there at any moment; what changes is that adding it here
		// no longer affects that feed. Saying so beside the control is the whole
		// point: an admin who finds a rule that does nothing, with nothing
		// explaining why, reasonably concludes the plugin is broken.
		$sb_stood_down = class_exists( '\FazCookie\Frontend\Frontend' )
			&& method_exists( '\FazCookie\Frontend\Frontend', 'smash_balloon_self_restricts' )
			&& \FazCookie\Frontend\Frontend::smash_balloon_self_restricts();

		foreach ( glob( $templates_dir . '*.json' ) as $file ) {
			$data = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $data && isset( $data['id'] ) ) {
				if ( $sb_stood_down && 'smash-balloon-instagram' === $data['id'] ) {
					$data['not_applicable'] = array(
						'label'      => __( 'Not applied', 'faz-cookie-manager' ),
						// Deliberately not "overridden". Nothing is overriding
						// anything: with its GDPR setting on Yes, Instagram Feed
						// serves local copies and contacts nobody, so the
						// third-party surface this rule exists to gate is gone.
						// There is nothing left for the setting to apply TO.
						'note'       => __( 'Instagram Feed is set to serve local copies and contact nobody, so there is nothing left for this rule to block. Change its GDPR setting to Automatic or No to put this rule back in effect.', 'faz-cookie-manager' ),
						'url'        => \FazCookie\Frontend\Frontend::smash_balloon_settings_url(),
						'link_label' => __( 'Open Instagram Feed settings', 'faz-cookie-manager' ),
					);
				}
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
	 * Structurally sanitise the third-country transfer schema property.
	 *
	 * Defence-in-depth for the REST boundary: coerces the incoming object into
	 * the { enabled:bool, countries:{lang:text}, safeguard:{lang:html} } shape
	 * before it reaches Cookie::set_transfer(). Both boundaries call the shared
	 * Cookie::sanitize_transfer_value() helper, so defence in depth is retained
	 * without duplicating coercion rules.
	 *
	 * Unlike sanitize_script_field this NEVER returns a WP_Error: the transfer
	 * disclosure carries no unfiltered_html-gated content (the safeguard text is
	 * markup-filtered, not executed), so an editor with manage_options may save
	 * it. A non-object simply collapses to the disabled default.
	 *
	 * @param mixed           $value   Raw request value.
	 * @param WP_REST_Request $request Request object (unused; REST signature).
	 * @param string          $param   Parameter name (unused; REST signature).
	 * @return array
	 */
	public static function sanitize_transfer_field( $value, $request = null, $param = '' ) {
		return Cookie::sanitize_transfer_value( $value );
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
