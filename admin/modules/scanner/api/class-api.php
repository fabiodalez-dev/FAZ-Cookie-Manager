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
use FazCookie\Admin\Modules\Scanner\Includes\Scanner_Logger;
use FazCookie\Admin\Modules\Scanner\Includes\Controller;

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
						'max_pages'   => array(
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => Controller::MAX_SCAN_PAGES,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'fingerprint' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'scan_id'     => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_scan_id' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// Server-side fallback: scan a URL server-side when iframes fail.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/server-scan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'server_scan' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'url' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'esc_url_raw',
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
					'args'                => array(
						'scan_id' => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_scan_id' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// Fallback renewal channel for the browser capture window. A fully
		// page-cached site serves scanned pages off disk without booting PHP, so
		// the capture-path renewal in Controller::register_browser_scan_observer()
		// can silently never fire — on exactly the large, cached sites whose
		// crawls are most likely to outlast the window. Permission-gated
		// identically to scans/import: same capability, same write nonce.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/heartbeat',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'heartbeat_browser_scan' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'scan_id' => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_scan_id' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// What the server knows about the caller's own capture session. Read-only
		// and scoped to the authenticated administrator; exists so the Cookies
		// page can surface an already-open session (an abandoned tab, a crawl in
		// another tab) instead of leaving it to be discovered as a bare 409.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/session',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_browser_scan_session' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/abort',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'abort_browser_scan' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'scan_id' => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_scan_id' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
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

		// Scanner debug log endpoints.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/debug-log',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_debug_log' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_debug_log' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Reject browser-scan identifiers that cannot have come from the scanner.
	 *
	 * `createScanId()` in admin/assets/js/modules/scan-engine.js mints sixteen
	 * random bytes rendered as thirty-two lowercase hex characters, and every
	 * session check in the scanner controller matches that exact shape. Enforcing
	 * it at the route boundary turns a truncated, empty or hand-crafted id into an
	 * explicit 400 instead of letting it travel on to be read as "no capture
	 * session in progress" — to an administrator watching a scan those two look
	 * identical, and only one of them is a real state.
	 *
	 * The REST dispatcher validates before it sanitizes, so this sees the value
	 * exactly as the client sent it: an id that only becomes well-formed once
	 * sanitize_key() has stripped characters out of it is still refused.
	 *
	 * @param mixed $value Raw parameter value as supplied by the client.
	 * @return true|WP_Error
	 */
	public function validate_scan_id( $value ) {
		if ( is_string( $value ) && preg_match( '/^[a-f0-9]{32}$/', $value ) ) {
			return true;
		}

		return new WP_Error(
			'faz_invalid_browser_scan_id',
			__( 'Invalid browser scan identifier.', 'faz-cookie-manager' ),
			array( 'status' => 400 )
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
		$max_pages = Controller::normalize_max_pages( isset( $request['max_pages'] ) ? $request['max_pages'] : 20 );
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
		$defaults = array(
			'id'            => 0,
			'status'        => '',
			'type'          => 'local',
			'date'          => '',
			'total_cookies' => 0,
			'new_cookies'   => 0,
			'pages_scanned' => 0,
		);
		$data = get_option( 'faz_scan_details', $defaults );
		if ( ! is_array( $data ) ) {
			$data = $defaults;
		}
		// Sanitize output values.
		$safe = array(
			'id'            => isset( $data['id'] ) ? absint( $data['id'] ) : 0,
			'status'        => isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : '',
			'type'          => isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : 'local',
			'date'          => isset( $data['date'] ) ? sanitize_text_field( $data['date'] ) : '',
			'total_cookies' => isset( $data['total_cookies'] ) ? absint( $data['total_cookies'] ) : 0,
			// Rows actually ADDED by the last completed scan. -1 when the stored
			// state predates this field, so clients can tell "0 new" apart from
			// "unknown" and fall back to the plain found-count copy.
			'new_cookies'   => isset( $data['new_cookies'] ) ? absint( $data['new_cookies'] ) : -1,
			'pages_scanned' => isset( $data['pages_scanned'] ) ? absint( $data['pages_scanned'] ) : 0,
		);
		// Most recent completed server-side visitor check (headers only), or
		// null. Already sanitized by latest_visitor_check(). Additive: nothing
		// existing reads this field, and polling clients ignore it.
		$safe['visitor_check'] = $this->controller->latest_visitor_check();
		return rest_ensure_response( $safe );
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
		$max_pages   = Controller::normalize_max_pages( $request['max_pages'] );
		$fingerprint = $request['fingerprint'];
		$scan_id     = sanitize_key( (string) $request['scan_id'] );
		$session     = $this->controller->start_browser_scan_session( $scan_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$current_fingerprint = $this->controller->get_scan_fingerprint( $max_pages );
		$incremental         = false;

		// Priority URLs (home + posts modified in the last 7 days) are returned
		// separately so the browser scans them first. Compute the base once so we
		// do not pay the WP_Query twice per request.
		$priority_base = $this->controller->get_priority_urls( $max_pages );
		if ( ! empty( $fingerprint ) && ! empty( $current_fingerprint ) && $fingerprint === $current_fingerprint ) {
			// Nothing changed — return only priority URLs.
			$urls        = $priority_base;
			$incremental = true;
		} else {
			$urls = $this->controller->discover_pages_from_db( $max_pages );
		}

		// WooCommerce-aware priority URLs (shop, product, cart, checkout,
		// my-account) plus recently-modified posts are scanned first.
		$priority_urls = array_values(
			array_unique(
				array_merge(
					$priority_base,
					$this->controller->discover_woocommerce_urls()
				)
			)
		);

		return rest_ensure_response(
			array(
				'urls'          => array_values( $urls ),
				'priority_urls' => array_values( $priority_urls ),
				'total'         => count( array_unique( array_merge( $urls, $priority_urls ) ) ),
				'fingerprint'   => $current_fingerprint,
				'incremental'   => $incremental,
				'home_url'      => home_url( '/' ),
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

	/**
	 * Server-side scan fallback: fetch a URL via wp_remote_get,
	 * parse script tags from HTML, and infer cookies via Cookie_Database.
	 *
	 * Used when the iframe-based scanner fails (e.g. LiteSpeed optimization,
	 * X-Frame-Options, or slow page loads that exceed iframe timeouts).
	 *
	 * @param \WP_REST_Request $request Request with 'url' parameter.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function server_scan( $request ) {
		$logger = Scanner_Logger::get_instance();
		$logger->start( 'Server-side fallback scan' );
		$url    = $request->get_param( 'url' );

		try {
			if ( empty( $url ) ) {
				$logger->log( 'Server-scan: empty URL, returning empty result' );
				$response = new \WP_REST_Response( array( 'cookies' => array(), 'scripts' => array() ), 200 );
				return $response;
			}

			// SSRF protection: only allow URLs on the same domain as the site.
			$site_host     = Controller::canonical_scan_host( wp_parse_url( home_url(), PHP_URL_HOST ) );
			$url_host      = Controller::canonical_scan_host( wp_parse_url( $url, PHP_URL_HOST ) );
			$site_is_local = Controller::is_loopback_scan_host( $site_host );
			$url_is_local  = Controller::is_loopback_scan_host( $url_host );
			$hosts_match   = Controller::scan_hosts_match( $url_host, $site_host );
			if ( ! $site_host || ! $url_host || ! $hosts_match ) {
				$logger->log( 'Server-scan: URL domain mismatch (expected ' . $site_host . ', got ' . $url_host . ')' );
				return new \WP_Error(
					'faz_server_scan_domain_mismatch',
					__( 'The scan URL must match the current site domain.', 'faz-cookie-manager' ),
					array( 'status' => 400 )
				);
			}
			$is_validated_loopback = $site_is_local && $url_is_local;

			$safe_urls = $this->controller->sanitize_scanned_urls( array( $url ) );
			if ( empty( $safe_urls ) ) {
				return new \WP_Error(
					'faz_server_scan_url_mismatch',
					__( 'The scan URL must use the current site protocol, host, and port.', 'faz-cookie-manager' ),
					array( 'status' => 400 )
				);
			}
			$url = $safe_urls[0];
			$logger->log( 'Server-scan URL: ' . $url );

			// Fetch the page HTML server-side.
			// Use wp_remote_get (not wp_safe_remote_get) because the scanner
			// needs to reach the site itself, which may be on localhost/127.0.0.1.
			// SSRF is mitigated by the host validation above. Loopback requests are
			// allowed only when both the site and requested URL are loopback hosts.
			$http_response          = null;
			$redirect_cookie_headers = array();
			$current_url            = $url;
			for ( $hop = 0; $hop < 4; ++$hop ) {
				$http_response = $this->controller->remote_get(
					$current_url,
					array(
						'timeout'            => 20,
						'sslverify'          => (bool) apply_filters( 'faz_scanner_sslverify', ! $is_validated_loopback, $current_url ),
						'redirection'        => 0,
						'reject_unsafe_urls' => ! $is_validated_loopback,
						'user-agent'         => 'FAZCookieScanner/1.0 (WordPress; +' . home_url() . ')',
					)
				);
				if ( is_wp_error( $http_response ) ) {
					break;
				}
				$redirect_cookie_headers = array_merge( $redirect_cookie_headers, $this->controller->get_set_cookie_headers( $http_response ) );
				$status = wp_remote_retrieve_response_code( $http_response );
				if ( $status < 300 || $status >= 400 ) {
					break;
				}
				$location = wp_remote_retrieve_header( $http_response, 'location' );
				if ( is_array( $location ) ) {
					$location = end( $location );
				}
				$next = empty( $location ) ? '' : \WP_Http::make_absolute_url( (string) $location, $current_url );
				$safe = $this->controller->sanitize_scanned_urls( array( $next ) );
				if ( empty( $safe ) ) {
					$http_response = new \WP_Error( 'faz_server_scan_redirect_rejected', __( 'A server-scan redirect left the current site and was rejected.', 'faz-cookie-manager' ) );
					break;
				}
				$current_url = $safe[0];
			}

			if ( is_wp_error( $http_response ) || 200 !== wp_remote_retrieve_response_code( $http_response ) ) {
				$err_msg = is_wp_error( $http_response ) ? $http_response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $http_response );
				$logger->log( 'Server-scan fetch failed: ' . $err_msg );
				$response = new \WP_REST_Response( array( 'cookies' => array(), 'scripts' => array() ), 200 );
				return $response;
			}

			$html    = wp_remote_retrieve_body( $http_response );
			$scripts = array();
			$cookies = array();

			$logger->log( 'HTML size: ' . strlen( $html ) . ' bytes' );

			// Extract live and optimizer-deferred script URLs.
			foreach ( array( 'src', 'data-src', 'data-litespeed-src', 'data-rocket-src', 'data-wpr-src' ) as $attr ) {
				if ( preg_match_all( '/<script[^>]*\b' . preg_quote( $attr, '/' ) . '=["\x27]([^"\x27]+)["\x27][^>]*>/i', $html, $matches ) ) {
					$scripts = array_merge( $scripts, $matches[1] );
				}
			}
			$scripts = array_unique( $scripts );

			// Also extract live and lazy iframe URLs.
			foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $attr ) {
				if ( preg_match_all( '/<iframe[^>]*\b' . preg_quote( $attr, '/' ) . '=["\x27]([^"\x27]+)["\x27][^>]*>/i', $html, $iframe_matches ) ) {
					$scripts = array_merge( $scripts, $iframe_matches[1] );
				}
			}
			$scripts = array_unique( $scripts );

			$script_list = array_values( $scripts );
			$logger->log( 'Scripts found: ' . count( $script_list ), array_slice( $script_list, 0, 20 ) );

			// Parse Set-Cookie headers.
			$raw_cookies = $redirect_cookie_headers;

			$logger->log( 'Set-Cookie headers: ' . count( $raw_cookies ) );

			$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
			foreach ( $raw_cookies as $cookie_str ) {
				$parts = explode( '=', explode( ';', $cookie_str )[0], 2 );
				$name  = trim( $parts[0] );
				if ( $name ) {
					$cookies[] = array(
						'name'   => $name,
						'domain' => $site_domain,
					);
				}
			}

			// Infer cookies from detected scripts using Cookie_Database.
			//
			// Filter first. The extraction above matches any attribute ending in
			// `src` (the `\b` before the attribute name makes `data-thumb-src`
			// match `src`), so a lazy-loader thumbnail such as
			// img.youtube.com/vi/ID/hqdefault.jpg is harvested as a "script" —
			// and lookup_scripts() would then mint YSC / VISITOR_INFO1_LIVE from
			// an image that sets nothing. This response is not advisory: the scan
			// engine merges `cookies` straight into the imported set, so a
			// fabrication here reaches the public declaration (F019).
			$inferable = Controller::filter_inferable_script_urls( $scripts );
			$dropped   = count( $scripts ) - count( $inferable );
			if ( $dropped > 0 ) {
				$logger->log( 'Dropped ' . $dropped . ' non-code asset URL(s) before inference (images/CSS/fonts/media never set cookies)' );
			}
			$inferred = \FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database::lookup_scripts( $inferable );
			$logger->log( 'Inferred cookies from scripts: ' . count( $inferred ) );
			foreach ( $inferred as $inf ) {
				$logger->log( '  Inferred: "' . $inf['name'] . '" → ' . ( isset( $inf['category'] ) ? $inf['category'] : 'uncategorized' ) );
				$cookies[] = array(
					'name'        => $inf['name'],
					'domain'      => isset( $inf['domain'] ) ? $inf['domain'] : $site_domain,
					'duration'    => isset( $inf['duration'] ) ? $inf['duration'] : '',
					'description' => isset( $inf['description'] ) ? $inf['description'] : '',
					'category'    => isset( $inf['category'] ) ? $inf['category'] : 'uncategorized',
				);
			}

			$logger->log( 'Server-scan complete: ' . count( $cookies ) . ' cookies, ' . count( $scripts ) . ' scripts' );

			$response = new \WP_REST_Response(
				array(
					'cookies' => $cookies,
					'scripts' => array_values( $scripts ),
				),
				200
			);
			return $response;
		} finally {
			$logger->finish();
		}
	}
	public function import_cookies( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) || ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_payload', __( 'Empty or invalid request body.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		$raw_cookies   = isset( $body['cookies'] ) && is_array( $body['cookies'] ) ? $body['cookies'] : array();
		// Names the browser engine saw only as ALREADY present in the scanning
		// browser's jar before a page loaded. The scan runs in the
		// administrator's browser, so this is where wp-admin-only cookies live
		// (Automattic Tracks tk_*, anything a plugin left behind). They are not
		// attributable to the site and must not enter the public declaration as
		// discoveries; they are counted and reported so the admin can add any
		// that genuinely belong.
		$jar_cookies = isset( $body['jar_cookies'] ) && is_array( $body['jar_cookies'] ) ? $body['jar_cookies'] : array();
		$pages_scanned = isset( $body['pages_scanned'] ) ? absint( $body['pages_scanned'] ) : 0;
		$scripts       = isset( $body['scripts'] ) && is_array( $body['scripts'] ) ? $body['scripts'] : array();
		$metrics       = isset( $body['metrics'] ) && is_array( $body['metrics'] ) ? $body['metrics'] : array();
		$scanned_urls  = isset( $body['scanned_urls'] ) && is_array( $body['scanned_urls'] ) ? $body['scanned_urls'] : array();
		// Read the registered `scan_id` argument rather than the raw JSON body so
		// the route's own validate/sanitize pair governs the value. Casting an
		// unvalidated body field to string turned an array into a PHP notice, and
		// a malformed id into a 409 "session mismatch" — which reads to an
		// administrator as an expired scan rather than a bad request.
		$scan_id       = sanitize_key( (string) $request->get_param( 'scan_id' ) );

		// A resubmit of an import that already succeeded must be answered with
		// the success it produced, not with "this session expired". The session
		// is finished as part of a successful save, so the second attempt finds
		// nothing — which is indistinguishable, from here, from a genuine
		// expiry. The client cannot tell the two apart either: a request whose
		// response is lost (dropped connection, closed laptop, proxy timeout)
		// surfaces as a status-0 error, which is exactly the case its retry
		// treats as safe to repeat. Without this the administrator is told a
		// scan failed that in fact imported, and re-runs the whole crawl.
		//
		// This check comes BEFORE the session gate on purpose: by the time a
		// duplicate arrives there is no session left to match.
		$completed = $this->controller->recall_browser_scan_result( $scan_id );
		if ( null !== $completed ) {
			// `duplicate` lets the client distinguish "already saved" from a
			// fresh import. Nothing is re-run: no second save, and in
			// particular no second replay schedule, which would double the
			// background work for one crawl.
			$completed['duplicate'] = true;
			return rest_ensure_response( $completed );
		}

		if ( ! $this->controller->browser_scan_session_matches( $scan_id ) ) {
			// Expiry and a genuine cross-tab conflict are one 409 to the
			// transport but two different problems for the administrator. Naming
			// which one happened — and, for expiry, naming the idle limit that
			// was exceeded — is the difference between an actionable error and
			// the "Scan finished but failed to save results." dead end that a
			// long crawl used to hit.
			if ( 'conflict' === $this->controller->browser_scan_session_failure_reason( $scan_id ) ) {
				return new \WP_Error( 'faz_browser_scan_session_mismatch', __( 'Another browser tab is running a scan for this administrator. Finish or close it, then start a new scan.', 'faz-cookie-manager' ), array( 'status' => 409 ) );
			}
			return new \WP_Error(
				'faz_browser_scan_session_expired',
				sprintf(
					/* translators: %d: number of minutes a scan may stay idle before its capture session expires. */
					__( 'This scan session expired: the capture window closes after %d minutes without a scanned page reaching the server. Start a new scan.', 'faz-cookie-manager' ),
					(int) round( Controller::BROWSER_SCAN_TTL / MINUTE_IN_SECONDS )
				),
				array( 'status' => 409 )
			);
		}

		// Runtime responses made by scan-tagged pages can set HttpOnly cookies
		// through AJAX, REST, pixels or dynamically loaded resources. PHP captured
		// their Set-Cookie metadata through the short-lived scan marker.
		$session_cookies   = $this->controller->collect_browser_scan_session( $scan_id );
		$capture_truncated = $this->controller->browser_scan_capture_was_truncated();

		// The capture window is not scoped to one scan_id — it cannot be, because
		// the sub-resource and AJAX requests worth observing carry no scan id —
		// so it also sees the administrator's own wp-admin traffic for as long as
		// the tab the setup page asks them to keep open stays open. Those
		// sightings are split off here by the same rule the request-cookie path
		// below applies to the admin's jar: reported, never imported. Merging
		// them into $raw_cookies would declare an admin-only cookie to visitors,
		// and seeding $attributable from them would additionally un-bucket the
		// matching names on the request-cookie path.
		$site_session_cookies = array();
		foreach ( $session_cookies as $session_cookie ) {
			if ( ! is_array( $session_cookie ) || empty( $session_cookie['name'] ) ) {
				continue;
			}
			if ( ! empty( $session_cookie['admin_context'] ) ) {
				$jar_cookies[] = $session_cookie;
				continue;
			}
			$site_session_cookies[] = $session_cookie;
		}
		$raw_cookies = array_merge( $raw_cookies, $site_session_cookies );

		// Names the site was actually SEEN setting during this scan. This is the
		// attributable set, and it is what separates a server-set site cookie
		// from one the administrator merely happens to be carrying.
		$attributable = array();
		foreach ( $site_session_cookies as $session_cookie ) {
			$attributable[ (string) $session_cookie['name'] ] = true;
		}
		foreach ( $raw_cookies as $raw_cookie ) {
			if ( is_array( $raw_cookie ) && ! empty( $raw_cookie['name'] ) ) {
				$attributable[ (string) $raw_cookie['name'] ] = true;
			}
		}

		// The import request itself carries every root/path-compatible cookie,
		// including HttpOnly names that document.cookie cannot expose. Keep names
		// only; values are neither read into the inventory nor persisted.
		// This request comes from the SAME administrator browser that ran the
		// scan, so its Cookie header is that admin's jar — the wider twin of the
		// iframe channel, and it carries httpOnly names too. Walking it exists to
		// catch server-set cookies document.cookie cannot see, which is a real
		// need; but a name here is only evidence of the site setting it if the
		// scan actually observed it being set. Anything else is the admin's own
		// jar and goes to the reported-not-imported bucket.
		$request_cookie_header = method_exists( $request, 'get_header' ) ? (string) $request->get_header( 'cookie' ) : '';
		foreach ( $this->controller->extract_request_cookie_names( $request_cookie_header, $_COOKIE ) as $request_cookie_name ) {
			if ( Controller::BROWSER_SCAN_COOKIE === $request_cookie_name ) {
				continue;
			}
			$entry = array(
				'name'     => $request_cookie_name,
				'domain'   => wp_parse_url( home_url(), PHP_URL_HOST ),
				'duration' => 'session',
				'source'   => 'request-cookie',
			);
			if ( isset( $attributable[ $request_cookie_name ] ) ) {
				$raw_cookies[] = $entry;
			} else {
				$jar_cookies[] = $entry;
			}
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

		try {
			$result = $this->controller->save_scan_result( $cookies, $pages_scanned, $clean_scripts, $metrics );
		} catch ( \Throwable $e ) {
			error_log( 'FAZ: browser scan import failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- preserve diagnostics while returning a safe REST error.
			// Hold rather than discard. The observations are the only record of
			// what this site set before consent, and reproducing them means
			// re-running a crawl that can take many minutes. Held evidence does
			// not block the next scan: starting one reclaims it.
			$held = $this->controller->hold_browser_scan_session( $scan_id );
			return new \WP_Error(
				'faz_scan_import_failed',
				$held
					? __( 'The cookie import could not be completed, so nothing was saved. The scan results are kept for a few minutes — retry the import rather than re-running the scan. Review the scanner log if it fails again.', 'faz-cookie-manager' )
					: __( 'The cookie import could not be completed. No completion status was recorded; review the scanner log and try again.', 'faz-cookie-manager' ),
				array(
					'status' => 500,
					// The client only offers a retry when the evidence is
					// actually still there. Promising one after the hold failed
					// would send the administrator to a 409.
					'faz_session_held' => $held,
				)
			);
		}

		// Nothing irreversible happens until persistence succeeds. In particular,
		// the failure response above promises that the administrator may retry the
		// same scan: its observations, transients and marker must still exist, and
		// no background replay may write data for the failed import.
		//
		// Order is load-bearing: record the outcome BEFORE closing the session.
		// If the request dies between these two lines a retry finds the record
		// and is told the truth — the import succeeded. Closing first would
		// leave a retry with neither a session nor a record, and it would report
		// a failure that never happened.
		$this->controller->remember_browser_scan_result( $scan_id, $result );
		$this->controller->finish_browser_scan_session( $scan_id );

		// Persist this pass's classification before the replay is scheduled, so
		// the cron worker finds an open ledger: the imported set and the jar/
		// admin-context bucket otherwise die with this response, and the
		// visitor diff can never be computed.
		$this->controller->begin_visitor_check(
			// $scan_id, not $result['scan_id'] — the import result carries no
			// such key, so this fell back to 0 on every call. The two lines
			// above already key their writes on $scan_id.
			$scan_id,
			$cookies,
			isset( $result['cookie_names'] ) && is_array( $result['cookie_names'] ) ? $result['cookie_names'] : array(),
			$jar_cookies
		);

		// Replay every URL the browser actually visited in a background server
		// pass. This adds Set-Cookie headers and metadata that are invisible to JS.
		$enrichment_pending = $this->controller->schedule_httponly_check( $scanned_urls );

		$result['capture_truncated'] = $capture_truncated;
		$result['enrichment_pending'] = $enrichment_pending > 0;
		$result['enrichment_urls']    = $enrichment_pending;

		// Overwrite the crash-safety record above with the complete body, so a
		// resubmit gets the same response the first attempt returned rather than
		// a truthful-but-partial one. The earlier write is not redundant: it
		// covers the window where the request dies before reaching here, and in
		// that window an incomplete record still says the one thing that
		// matters — the import succeeded, do not run the crawl again.
		$this->controller->remember_browser_scan_result( $scan_id, $result );

		// A single missed scan is not evidence of absence. Only a scan that
		// covered the whole site without incident may add to the tally, and only
		// a cookie missing from several consecutive such scans becomes
		// deletable — see Controller::MISSED_SCANS_THRESHOLD.
		//
		// The rule itself lives in Controller::scan_coverage_is_complete(), one
		// method away from the tally it guards, and it now includes the two
		// facts this expression used to leave out: the DEPTH the administrator
		// chose (a 20-page run on a 500-page site finishing is not full-site
		// coverage), and $capture_truncated, which is computed ninety lines
		// above and was reported to the admin while the tally ignored it.
		$scan_was_complete = Controller::scan_coverage_is_complete( $metrics, $pages_scanned, $capture_truncated );
		// Judge the catalogue against the names that were actually PERSISTED, not
		// the pre-merge client list. save_scan_result() additionally merges
		// script/embed-inferred cookies into the rows it writes, and returns that
		// merged, deduplicated set as `cookie_names`. Using $cookies here would
		// guarantee a false miss on every complete scan for every entry that
		// exists purely by inference — the block-first per-service entries, which
		// are inference-only by design and would be the first casualties.
		//
		// The $cookies loop stays as a fallback: an empty observed set increments
		// EVERY discovered row at once, so a future refactor that stops returning
		// cookie_names — or returns it empty — must degrade to the narrower set,
		// never to nothing.
		$observed_names = array();
		if ( ! empty( $result['cookie_names'] ) && is_array( $result['cookie_names'] ) ) {
			$observed_names = $result['cookie_names'];
		} else {
			foreach ( $cookies as $observed_cookie ) {
				if ( ! empty( $observed_cookie['name'] ) ) {
					$observed_names[] = $observed_cookie['name'];
				}
			}
		}
		$this->controller->record_scan_observations( $observed_names, $scan_was_complete );
		$result['scan_was_complete'] = $scan_was_complete;
		// Emitted in Controller::canonical_key() form — lowercase name, domain
		// without leading dots or :port — because the Cookies page intersects it
		// with its own client-side stale set, which is keyed the same way. Two
		// key formats here means an intersection that is always empty and a
		// stale bar that never appears.
		$result['deletable_stale_keys'] = $this->controller->deletable_stale_keys();

		// Reported, never imported. Surfacing the count is the point: silently
		// dropping them would trade one invisible behaviour for another, and an
		// admin who recognises a name as a real site cookie can add it by hand.
		$jar_names = array();
		foreach ( $jar_cookies as $jar_cookie ) {
			if ( ! is_array( $jar_cookie ) || empty( $jar_cookie['name'] ) ) {
				continue;
			}
			$jar_name = sanitize_text_field( (string) $jar_cookie['name'] );
			if ( '' !== $jar_name && ! in_array( $jar_name, $jar_names, true ) ) {
				$jar_names[] = $jar_name;
			}
		}
		$result['jar_only_cookies'] = $jar_names;
		$result['jar_only_count']   = count( $jar_names );
		return rest_ensure_response( $result );
	}

	/**
	 * Release a browser capture after a client-side failure.
	 *
	 * Reads the registered `scan_id` argument rather than the raw JSON body so the
	 * route's own validate/sanitize pair is what governs the value: reaching past
	 * it would quietly reinstate the unvalidated path this endpoint used to take.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function abort_browser_scan( $request ) {
		$scan_id = sanitize_key( (string) $request['scan_id'] );
		return rest_ensure_response( array( 'aborted' => $this->controller->abort_browser_scan_session( $scan_id ) ) );
	}

	/**
	 * Describe the current administrator's open capture session, if any.
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function get_browser_scan_session( $request ) {
		return rest_ensure_response( $this->controller->describe_browser_scan_session() );
	}

	/**
	 * Slide the browser capture window forward without importing anything.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function heartbeat_browser_scan( $request ) {
		$scan_id = sanitize_key( (string) $request->get_param( 'scan_id' ) );
		return rest_ensure_response(
			array(
				'renewed'    => $this->controller->touch_browser_scan_session( '', $scan_id ),
				'expires_in' => Controller::BROWSER_SCAN_TTL,
			)
		);
	}

	/**
	 * Return scanner debug logs as plain text.
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function get_debug_log( $request ) {
		$logger = Scanner_Logger::get_instance();
		$text   = $logger->get_all_logs_text();

		return new \WP_REST_Response(
			array(
				'log'     => $text,
				'enabled' => $logger->is_enabled(),
			),
			200
		);
	}

	/**
	 * Clear scanner debug logs.
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function clear_debug_log( $request ) {
		$logger = Scanner_Logger::get_instance();
		$logger->clear_logs();

		return new \WP_REST_Response(
			array( 'cleared' => true ),
			200
		);
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
