<?php
/**
 * F019 regression: every path that lets a URL MINT a cookie declaration must
 * filter passive assets first.
 *
 * The provider pattern lists are matched unanchored against whole URLs, and
 * they contain image-CDN hosts (ytimg.com, i.vimeocdn.com) plus bare tokens.
 * A YouTube THUMBNAIL therefore matches the YouTube provider and fabricates
 * YSC / VISITOR_INFO1_LIVE / LOGIN_INFO — declarations for cookies the page
 * never set, written to the catalogue and shown to visitors.
 *
 * The first fix covered only the browser-scan import. These tests pin the two
 * entry points it missed:
 *
 *   1. run_scan() → infer_cookies_from_scripts( $this->scanned_embed_urls )
 *   2. the scans/server-scan REST route → Cookie_Database::lookup_scripts()
 *
 * Both are exercised by RUNNING them, with the real Known_Providers /
 * Cookie_Database data and the real extraction regexes, not by matching source
 * text. Each thumbnail case is paired with a genuine-script case so a filter
 * that simply dropped everything would fail here.
 *
 * Run: php tests/unit/test-scanner-inference-boundary-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Scanner\Includes {
	class Scanner_Logger {
		public static function get_instance() {
			return new self();
		}
		public function start( $context ) {}
		public function log( $message, $context = null ) {}
		public function finish() {}
	}
}

namespace FazCookie\Includes {
	/** Stand-in for the abstract REST base so the scanner Api can be built. */
	class Rest_Controller {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'YEAR_IN_SECONDS', 31536000 );

	$GLOBALS['__faz_options'] = array();

	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_message() {
			return $this->message;
		}
	}

	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}

	class WP_Http {
		public static function make_absolute_url( $maybe_relative, $base ) {
			return $maybe_relative;
		}
	}

	/** Minimal WP_REST_Request stand-in: server_scan only reads `url`. */
	class FazTest_Request {
		private $params;
		public function __construct( $params ) {
			$this->params = $params;
		}
		public function get_param( $key ) {
			return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
		}
	}

	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function __( $value, ...$unused ) { return $value; }
	function wp_unslash( $value ) { return $value; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
	function esc_url_raw( $url ) { return (string) $url; }
	function trailingslashit( $value ) { return rtrim( (string) $value, '/' ) . '/'; }
	function home_url( $path = '' ) { return 'https://example.test/' . ltrim( (string) $path, '/' ); }
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
	function is_ssl() { return true; }
	function get_current_user_id() { return 7; }
	function current_time( $type ) { return '2026-08-16 12:00:00'; }
	function apply_filters( $hook, $value, ...$rest ) { return $value; }
	function add_action( ...$unused ) { return true; }
	function do_action( ...$unused ) { return true; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function html_entity_decode_shim( $value ) { return $value; }
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__faz_options'] ) ? $GLOBALS['__faz_options'][ $key ] : $default;
	}
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__faz_options'][ $key ] = $value;
		return true;
	}
	function get_transient( $key ) { return false; }
	function set_transient( $key, $value, $ttl = 0 ) { return true; }
	function delete_transient( $key ) { return true; }
	function get_user_meta( $user_id, $key, $single = false ) { return $single ? '' : array(); }
	function delete_user_meta( $user_id, $key, $value = '' ) { return true; }
	function add_user_meta( $user_id, $key, $value, $unique = false ) { return true; }

	// wp_remote_* are driven by this fixture so the REST route runs for real.
	$GLOBALS['__faz_http_body'] = '';
	function wp_remote_get( $url, $args = array() ) {
		return array( 'body' => $GLOBALS['__faz_http_body'], 'response' => array( 'code' => 200 ), 'headers' => array() );
	}
	function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? $response['body'] : ''; }
	function wp_remote_retrieve_response_code( $response ) { return isset( $response['response']['code'] ) ? $response['response']['code'] : 0; }
	function wp_remote_retrieve_header( $response, $header ) { return ''; }
	function wp_remote_retrieve_headers( $response ) { return isset( $response['headers'] ) ? $response['headers'] : array(); }
	function wp_remote_retrieve_cookies( $response ) { return array(); }

	require_once dirname( __DIR__, 2 ) . '/includes/class-known-providers.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-cookie-database.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/api/class-api.php';

	use FazCookie\Admin\Modules\Scanner\Includes\Controller;

	$checks   = 0;
	$failures = 0;
	function check( $condition, $label ) {
		global $checks, $failures;
		++$checks;
		if ( $condition ) {
			echo "  [PASS] {$label}\n";
			return;
		}
		++$failures;
		echo "  [FAIL] {$label}\n";
	}

	/** Names of the cookies YouTube would fabricate from a thumbnail. */
	$youtube_names = array( 'YSC', 'VISITOR_INFO1_LIVE', 'LOGIN_INFO' );

	function names_of( $rows ) {
		$names = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['name'] ) ) {
				$names[] = (string) $row['name'];
			}
		}
		return $names;
	}

	function has_any( $names, $wanted ) {
		return (bool) array_intersect( $names, $wanted );
	}

	/*
	 * ── 0. The premise ────────────────────────────────────────────────────
	 * If a thumbnail URL stopped matching the provider lists these tests would
	 * be vacuous. Assert the hazard exists before asserting it is contained.
	 */
	$providers = \FazCookie\Includes\Known_Providers::get_all();
	check(
		isset( $providers['youtube'] ) && in_array( 'ytimg.com', $providers['youtube']['patterns'], true ),
		'the YouTube provider still matches the image CDN, so an unfiltered thumbnail really would fabricate'
	);
	check(
		count( names_of( \FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database::lookup_scripts( array( 'https://img.youtube.com/vi/ID/hqdefault.jpg' ) ) ) ) > 0,
		'Cookie_Database still mints cookies from a raw thumbnail URL when nothing filters it'
	);

	/*
	 * ── 1. The filter itself ──────────────────────────────────────────────
	 */
	check(
		array() === Controller::filter_inferable_script_urls( array( 'https://i.ytimg.com/vi/ID/hqdefault.jpg' ) ),
		'a thumbnail is not an inferable script'
	);
	check(
		array( 'https://www.youtube.com/iframe_api' ) === Controller::filter_inferable_script_urls( array( 'https://www.youtube.com/iframe_api' ) ),
		'a genuine script URL survives the filter'
	);
	check(
		array( 'https://pixel.example.com' ) === Controller::filter_inferable_script_urls( array( 'https://pixel.example.com' ) ),
		'an extension-less beacon endpoint survives the filter'
	);

	/*
	 * ── 2. run_scan() → embed inference (was unfiltered) ──────────────────
	 *
	 * scan_page() is replaced by one that feeds the REAL extractor the page
	 * HTML, exactly as the real one does with the fetched body, so the
	 * `\b(?:src|data-src|…)` word-boundary harvest of `data-thumb-src` is
	 * executed rather than assumed.
	 */
	class FazTest_Embed_Controller extends Controller {
		public $page_html     = '';
		public $saved_cookies = array();
		public $harvested     = array();

		public function discover_pages( $site_url, $max ) {
			return array( $site_url );
		}

		public function scan_page( $url ) {
			$extract = new \ReflectionMethod( Controller::class, 'extract_embed_urls' );
			$extract->setAccessible( true );
			$urls = $extract->invoke( $this, $this->page_html );

			$prop = new \ReflectionProperty( Controller::class, 'scanned_embed_urls' );
			$prop->setAccessible( true );
			$prop->setValue( $this, array_merge( (array) $prop->getValue( $this ), $urls ) );

			$this->harvested = $urls;
			return array();
		}

		public function save_cookies( $cookies ) {
			$this->saved_cookies = $cookies;
			return count( $cookies );
		}
	}

	$thumb_only = new FazTest_Embed_Controller();
	$thumb_only->page_html = '<div class="lazy-video"><iframe data-thumb-src="https://i.ytimg.com/vi/ID/hqdefault.jpg"></iframe></div>';
	$thumb_only->run_scan( 1 );
	check(
		in_array( 'https://i.ytimg.com/vi/ID/hqdefault.jpg', $thumb_only->harvested, true ),
		'the embed extractor really does harvest a data-thumb-src thumbnail (the \b before `src` matches any *-src attribute)'
	);
	check(
		! has_any( names_of( $thumb_only->saved_cookies ), $youtube_names ),
		'run_scan: a harvested thumbnail declares no YouTube cookie'
	);
	check(
		array() === $thumb_only->saved_cookies,
		'run_scan: a page whose only embed is a thumbnail declares nothing at all'
	);

	$real_embed = new FazTest_Embed_Controller();
	$real_embed->page_html = '<iframe src="https://www.youtube.com/embed/ID" title="video"></iframe>';
	$real_embed->run_scan( 1 );
	$real_names = names_of( $real_embed->saved_cookies );
	check(
		in_array( 'YSC', $real_names, true ) && in_array( 'VISITOR_INFO1_LIVE', $real_names, true ),
		'run_scan: a genuine YouTube embed still declares its cookies (the filter is not a blanket drop)'
	);

	$mixed = new FazTest_Embed_Controller();
	$mixed->page_html = '<iframe data-thumb-src="https://i.ytimg.com/vi/ID/hqdefault.jpg"></iframe><iframe data-src="https://player.vimeo.com/video/1"></iframe>';
	$mixed->run_scan( 1 );
	$mixed_names = names_of( $mixed->saved_cookies );
	check(
		2 === count( $mixed->harvested ),
		'run_scan: both the thumbnail and the real embed were harvested before filtering'
	);
	check(
		in_array( 'vuid', $mixed_names, true ) && ! has_any( $mixed_names, $youtube_names ),
		'run_scan: the real embed on a page is kept while the thumbnail beside it is dropped'
	);

	/*
	 * ── 3. scans/server-scan REST route (was unfiltered) ──────────────────
	 *
	 * Not advisory: scan-engine.js pushes serverResult.cookies straight into
	 * collectedCookies and imports them as observed cookies.
	 */
	$api = new \FazCookie\Admin\Modules\Scanner\Api\Api( new Controller() );

	$GLOBALS['__faz_http_body'] = '<html><body><iframe data-thumb-src="https://img.youtube.com/vi/ID/hqdefault.jpg"></iframe></body></html>';
	$response = $api->server_scan( new FazTest_Request( array( 'url' => 'https://example.test/' ) ) );
	check(
		$response instanceof WP_REST_Response,
		'server-scan returns a response for a thumbnail-only page'
	);
	$thumb_scripts = isset( $response->data['scripts'] ) ? $response->data['scripts'] : array();
	check(
		in_array( 'https://img.youtube.com/vi/ID/hqdefault.jpg', $thumb_scripts, true ),
		'server-scan really does harvest the thumbnail as a "script"'
	);
	check(
		! has_any( names_of( isset( $response->data['cookies'] ) ? $response->data['cookies'] : array() ), $youtube_names ),
		'server-scan: a thumbnail mints no cookie for the client to import'
	);
	check(
		array() === ( isset( $response->data['cookies'] ) ? $response->data['cookies'] : null ),
		'server-scan: a thumbnail-only page reports no cookies at all'
	);

	$GLOBALS['__faz_http_body'] = '<html><body><script src="https://www.google-analytics.com/analytics.js"></script></body></html>';
	$response = $api->server_scan( new FazTest_Request( array( 'url' => 'https://example.test/' ) ) );
	$ga_names = names_of( isset( $response->data['cookies'] ) ? $response->data['cookies'] : array() );
	check(
		in_array( '_ga', $ga_names, true ),
		'server-scan: a genuine analytics script still reports its cookies'
	);

	$GLOBALS['__faz_http_body'] = '<html><body><iframe data-thumb-src="https://img.youtube.com/vi/ID/hqdefault.jpg"></iframe><script src="https://www.google-analytics.com/analytics.js"></script></body></html>';
	$response = $api->server_scan( new FazTest_Request( array( 'url' => 'https://example.test/' ) ) );
	$mixed_api_names = names_of( isset( $response->data['cookies'] ) ? $response->data['cookies'] : array() );
	check(
		in_array( '_ga', $mixed_api_names, true ) && ! has_any( $mixed_api_names, $youtube_names ),
		'server-scan: the real script is kept while the thumbnail beside it is dropped'
	);

	if ( $failures ) {
		echo "\n{$failures} of {$checks} inference-boundary checks failed.\n";
		exit( 1 );
	}
	echo "\n{$checks} inference-boundary checks passed.\n";
}
