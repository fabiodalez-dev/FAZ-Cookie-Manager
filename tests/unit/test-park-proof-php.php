<?php
/**
 * Park_Proof signatures and the park-proofs REST route.
 *
 * Subsystem: park-proof-php
 *
 * The server signs what it parks into kses-guarded sinks (object data, style,
 * background) so a park made by another response can be vouched for: by a
 * later response that serves it again, or by POST /faz/v1/park-proofs when a
 * page fetched the markup later. The signature must bind each name to its
 * exact value, refuse what an author could write, and the route must only
 * answer, never sign.
 *
 * Run: php tests/unit/test-park-proof-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	function wp_salt( $scheme = 'auth' ) {
		return 'unit-test-salt-' . $scheme;
	}
	$GLOBALS['faz_test_routes'] = array();
	function add_action( $hook, $callback ) {
		$GLOBALS['faz_test_actions'][ $hook ][] = $callback;
	}
	function register_rest_route( $namespace, $route, $args ) {
		$GLOBALS['faz_test_routes'][] = array( $namespace, $route, $args );
		return true;
	}
	class WP_Error {
		public $code;
		public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code;
			$this->data = $data;
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
	class WP_REST_Request {
		private $params;
		public function __construct( array $params ) {
			$this->params = $params;
		}
		public function get_param( $key ) {
			return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-park-proof.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/modules/park-proofs/class-park-proofs.php';

	use FazCookie\Frontend\Includes\Park_Proof;
	use FazCookie\Frontend\Modules\Park_Proofs\Park_Proofs;

	$passed = 0;
	$failed = 0;
	function check( $cond, $label ) {
		global $passed, $failed;
		if ( $cond ) {
			$passed++;
			echo "  [PASS] $label\n";
		} else {
			$failed++;
			echo "  [FAIL] $label\n";
		}
	}

	echo "\n== Park_Proof::signature ==\n\n";

	$style = 'background-image:url(https://i.ytimg.com/vi/ID/hq.jpg)';
	$sig   = Park_Proof::signature( array( 'style' ), array( $style ) );
	check( 1 === preg_match( '/^[0-9a-f]{64}$/', $sig ), 'a parked style is signed with a 64-hex HMAC' );
	check( $sig === Park_Proof::signature( array( 'style' ), array( $style ) ), 'the signature is deterministic' );
	check( $sig !== Park_Proof::signature( array( 'style' ), array( $style . ';' ) ), 'a different value gives a different signature' );
	check( $sig !== Park_Proof::signature( array( 'background' ), array( $style ) ), 'the same value under another name gives a different signature' );
	check( Park_Proof::signature( array( 'style', 'background' ), array( 'a', 'b' ) ) !== Park_Proof::signature( array( 'background', 'style' ), array( 'b', 'a' ) ), 'the order of the names is part of what is signed' );
	check( '' === Park_Proof::signature( array( 'src' ), array( 'x' ) ), 'a name outside data/style/background is not signed' );
	check( '' === Park_Proof::signature( array( 'style', 'style' ), array( 'a', 'a' ) ), 'a repeated name is not signed' );
	check( '' === Park_Proof::signature( array( 'style' ), array( '' ) ), 'an empty value is not signed' );
	check( '' === Park_Proof::signature( array( 'style' ), array() ), 'a value count that does not match the names is not signed' );
	check( '' === Park_Proof::signature( array( 'style' ), array( str_repeat( 'a', Park_Proof::MAX_VALUE_BYTES + 1 ) ) ), 'a value over MAX_VALUE_BYTES is not signed' );

	echo "\n== Park_Proof::item_is_valid ==\n\n";

	$item = array( 'names' => array( 'style' ), 'values' => array( $style ), 'sig' => $sig );
	check( true === Park_Proof::item_is_valid( $item ), 'the values the server signed are confirmed' );
	check( false === Park_Proof::item_is_valid( array_merge( $item, array( 'values' => array( 'background:url(https://evil.test/x)' ) ) ) ), 'other values under the same signature are refused' );
	check( false === Park_Proof::item_is_valid( array_merge( $item, array( 'sig' => str_repeat( 'a', 64 ) ) ) ), 'a forged signature is refused' );
	check( false === Park_Proof::item_is_valid( array_merge( $item, array( 'sig' => strtoupper( $sig ) ) ) ), 'a signature in another spelling is refused' );
	check( false === Park_Proof::item_is_valid( array_merge( $item, array( 'names' => array( 'background' ) ) ) ), 'the signature does not carry over to another name' );
	check( false === Park_Proof::item_is_valid( array( 'names' => array( 'style' ), 'values' => array( $style ) ) ), 'an item without a signature is refused' );
	check( false === Park_Proof::item_is_valid( 'style' ), 'a malformed item is refused' );

	echo "\n== POST /faz/v1/park-proofs ==\n\n";

	$route = new Park_Proofs();
	$route->register_routes();
	$reg = $GLOBALS['faz_test_routes'][0];
	check( 'faz/v1' === $reg[0] && '/park-proofs' === $reg[1], 'the route is registered as faz/v1/park-proofs' );
	check( 'POST' === $reg[2]['methods'], 'it accepts POST only' );
	check( '__return_true' === $reg[2]['permission_callback'], 'and is public, as cached pages require' );

	$res = $route->verify( new WP_REST_Request( array( 'items' => array( $item, array_merge( $item, array( 'sig' => str_repeat( 'b', 64 ) ) ) ) ) ) );
	check( $res instanceof WP_REST_Response && array( true, false ) === $res->data['valid'], 'it answers one verdict per item' );
	check( array( 'valid' ) === array_keys( $res->data ), 'and returns nothing but the verdicts (no signature)' );

	$res = $route->verify( new WP_REST_Request( array( 'items' => 'x' ) ) );
	check( $res instanceof WP_Error && 400 === $res->data['status'], 'items that are not an array are a 400' );

	$many = array_fill( 0, Park_Proof::MAX_ITEMS + 10, $item );
	check( Park_Proof::MAX_ITEMS === count( Park_Proofs::verdicts( $many ) ), 'at most MAX_ITEMS items are judged per request' );

	echo "\nPassed: $passed\nFailed: $failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
