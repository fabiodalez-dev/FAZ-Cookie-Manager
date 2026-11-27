<?php
/**
 * Behavioural regression tests for Cookies_API::bulk_update() and a too-broad
 * script URL pattern.
 *
 * The single-item routes refuse a url_pattern that names no script ("/",
 * "js") through the schema's arg_options validate_callback. The bulk-update
 * route takes one `cookies` array, so those per-field callbacks never run for
 * it: bulk_update() called set_url_pattern() and save() directly, the request
 * succeeded, and the frontend then ignored the stored pattern.
 *
 * This suite loads the production controller unmodified against a recording
 * Cookie stand-in whose is_too_broad_url_pattern() is the production method,
 * copied from class-cookie.php at run time, and asserts:
 *
 *   - a too-broad pattern in any item is refused with faz_url_pattern_too_broad
 *     (400) and the same message as the single-item routes;
 *   - nothing is saved, not even the items listed before the refused one;
 *   - an empty pattern, a distinctive one and a short named call (fbq() are
 *     still accepted, and an item without url_pattern is untouched by the check.
 *
 * Run: php tests/unit/test-cookies-bulk-update-url-pattern-php.php
 */

namespace FazCookie\Admin\Modules\Cookies\Api {
	/** Stand-in for the REST controller ancestry: only what bulk_update() calls. */
	abstract class API_Controller {
		public function add_additional_fields_schema( $schema ) {
			return $schema;
		}
		public function prepare_item_for_response( $object, $request ) {
			return array( 'id' => $object->id );
		}
		public function prepare_response_for_collection( $response ) {
			return $response;
		}
	}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {

	/** Recording stand-in for the Cookie model; save() appends to a shared log. */
	class Cookie_Stub_Base {
		/** @var array<int,array> Rows the stub pretends the database holds. */
		public static $rows = array();
		/** @var array<int,array> What save() was called with, in order. */
		public static $saved = array();

		public $id;
		private $data = array();

		public function __construct( $id = 0 ) {
			$this->id = (int) $id;
		}
		public function get_loaded() {
			return isset( self::$rows[ $this->id ] );
		}
		public function set_url_pattern( $value ) {
			$this->data['url_pattern'] = (string) $value;
		}
		public function set_category( $value ) {
			$this->data['category'] = $value;
		}
		public function save() {
			self::$saved[] = array( 'id' => $this->id ) + $this->data;
		}
	}

	class Cookie_Controller {}
}

namespace FazCookie\Admin\Modules\Scanner\Includes {
	class Controller {}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$root = dirname( __DIR__, 2 );

	// The production predicate, unchanged: copy the method out of the model so
	// this suite judges exactly what the single-item routes judge. The eval'd
	// text is this repository's own source, read from disk, never input.
	$cookie_src = file_get_contents( $root . '/admin/modules/cookies/includes/class-cookie.php' );
	if ( ! preg_match( '/\tpublic static function is_too_broad_url_pattern\( \$pattern \) \{.*?\n\t\}\n/s', $cookie_src, $method ) ) {
		echo "  [FAIL] could not find Cookie::is_too_broad_url_pattern() in class-cookie.php\n";
		exit( 1 );
	}
	eval( 'namespace FazCookie\\Admin\\Modules\\Cookies\\Includes; class Cookie extends Cookie_Stub_Base {' . $method[0] . '}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test fixture.

	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
	}

	class Faz_Fake_Request {
		private $params;
		public function __construct( $params = array() ) {
			$this->params = $params;
		}
		public function get_param( $key ) {
			return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null;
		}
	}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	// The bulk route declares no per-field args, so WordPress answers true here.
	function rest_validate_request_arg( $value, $request, $param ) {
		return true;
	}
	function absint( $value ) {
		return abs( (int) $value );
	}
	function current_user_can( $capability ) {
		return true;
	}
	function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) {}
	function do_action( $hook ) {
		$GLOBALS['faz_actions'][] = $hook;
	}
	function rest_ensure_response( $response ) {
		return $response;
	}
	function __( $text, $domain = null ) {
		return $text;
	}
	function esc_html__( $text, $domain = null ) {
		return $text;
	}

	require_once $root . '/admin/modules/cookies/api/class-cookies-api.php';

	use FazCookie\Admin\Modules\Cookies\Api\Cookies_API;
	use FazCookie\Admin\Modules\Cookies\Includes\Cookie;

	$passed = 0;
	$failed = 0;
	function bu_ok( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) {
			$passed++;
			echo "  [PASS] {$label}\n";
			return;
		}
		$failed++;
		echo "  [FAIL] {$label}\n";
	}

	$api = new Cookies_API();
	function bu_run( $api, $items ) {
		Cookie::$rows            = array( 1 => array(), 2 => array(), 3 => array() );
		Cookie::$saved           = array();
		$GLOBALS['faz_actions']  = array();
		return $api->bulk_update( new Faz_Fake_Request( array( 'cookies' => $items ) ) );
	}

	$single = Cookies_API::validate_url_pattern_field( '/' );

	echo "== A too-broad url_pattern is refused by bulk-update before anything is saved ==\n";
	foreach ( array( '/', 'js', ' / ' ) as $wide ) {
		$r = bu_run( $api, array(
			array( 'id' => 1, 'category' => 'analytics' ),
			array( 'id' => 2, 'url_pattern' => 'widget.booking.example' ),
			array( 'id' => 3, 'url_pattern' => $wide ),
		) );
		bu_ok( is_wp_error( $r ) && 'faz_url_pattern_too_broad' === $r->code, 'bulk-update refuses "' . $wide . '" with faz_url_pattern_too_broad' );
		bu_ok( is_wp_error( $r ) && isset( $r->data['status'] ) && 400 === $r->data['status'], 'the refusal of "' . $wide . '" is a 400' );
		bu_ok( array() === Cookie::$saved, 'no item is saved, not even those listed before "' . $wide . '"' );
		bu_ok( array() === $GLOBALS['faz_actions'], 'faz_after_update_cookie does not fire for the refused batch' );
	}
	$r = bu_run( $api, array( array( 'id' => 1, 'url_pattern' => '/' ) ) );
	bu_ok( is_wp_error( $r ) && is_wp_error( $single ) && $single->message === $r->message, 'the message is the one the single-item routes return' );

	echo "== Empty, distinctive and named-call patterns are still saved ==\n";
	$r = bu_run( $api, array(
		array( 'id' => 1, 'url_pattern' => '' ),
		array( 'id' => 2, 'url_pattern' => 'widget.booking.example' ),
		array( 'id' => 3, 'url_pattern' => 'fbq(' ),
	) );
	bu_ok( ! is_wp_error( $r ) && 3 === $r['updated'], 'three valid patterns: three items updated' );
	bu_ok( 3 === count( Cookie::$saved ) && '' === Cookie::$saved[0]['url_pattern'] && 'widget.booking.example' === Cookie::$saved[1]['url_pattern'] && 'fbq(' === Cookie::$saved[2]['url_pattern'], 'each pattern is stored as sent' );

	$r = bu_run( $api, array( array( 'id' => 1, 'category' => 'marketing' ) ) );
	bu_ok( ! is_wp_error( $r ) && 1 === count( Cookie::$saved ) && ! array_key_exists( 'url_pattern', Cookie::$saved[0] ), 'an item without url_pattern is saved without one' );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( 0 === $failed ? 0 : 1 );
}
