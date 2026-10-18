<?php
/**
 * The plugin's REST routes refuse storage with the same header set as every
 * other FAZ "do not store" response; the banner routes stay cacheable.
 *
 * readme.txt and Cache_Compatibility::send_no_store_headers() both said the
 * faz/v1 REST routes send Cache_Compatibility::NO_STORE_HEADERS. The filter
 * in Admin::add_rest_nocache_headers() kept its own three lines instead
 * (Cache-Control, Pragma, X-LiteSpeed-Cache-Control): no CDN-Cache-Control,
 * no Cloudflare-CDN-Cache-Control, no Surrogate-Control, so an edge rule that
 * overrides Cache-Control could still store a REST answer about cookies and
 * categories. /faz/v1/banner/* sets its own `public, max-age=300` and must
 * not be touched.
 *
 * Run: php tests/unit/test-rest-no-store-headers-php.php
 */
namespace FazCookie\Admin {
	function header( $line, $replace = true ) {
		$GLOBALS['rest_headers'][] = $line;
	}
	function headers_sent() {
		return false;
	}
}
namespace FazCookie\Includes {
	function header( $line, $replace = true ) {
		$GLOBALS['rest_headers'][] = $line;
	}
	function headers_sent() {
		return false;
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['rest_headers'] = array();
	$GLOBALS['rest_filters'] = array();
	function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['rest_filters'][ $tag ][] = $callback;
		return true;
	}
	function add_action() { return true; }
	function apply_filters( $tag, $value ) { return $value; }

	require dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require dirname( __DIR__, 2 ) . '/admin/class-admin.php';

	class Rest_Headers_Request {
		private $route;
		public function __construct( $route ) { $this->route = $route; }
		public function get_route() { return $this->route; }
	}

	$checks = 0;
	$failed = 0;
	function rest_check( $ok, $label ) {
		global $checks, $failed;
		++$checks;
		if ( $ok ) {
			echo "  PASS {$label}\n";
			return;
		}
		++$failed;
		echo "  FAIL {$label}\n";
	}

	$admin = ( new ReflectionClass( \FazCookie\Admin\Admin::class ) )->newInstanceWithoutConstructor();
	$admin->add_rest_nocache_headers();
	$filter = $GLOBALS['rest_filters']['rest_pre_serve_request'][0] ?? null;
	rest_check( is_callable( $filter ), 'PRECONDITION: the REST no-store filter is registered' );

	$expected = array();
	foreach ( \FazCookie\Includes\Cache_Compatibility::NO_STORE_HEADERS as $name => $value ) {
		$expected[] = $name . ': ' . $value;
	}
	foreach ( array( '/faz/v1/cookies', '/faz/v1/settings', '/faz/v1/consent' ) as $route ) {
		$GLOBALS['rest_headers'] = array();
		$served = $filter( false, null, new Rest_Headers_Request( $route ) );
		rest_check( false === $served, $route . ': the filter does not take over serving' );
		rest_check( $expected === $GLOBALS['rest_headers'], $route . ': sends Cache_Compatibility::NO_STORE_HEADERS, CDN-targeted directives included' );
	}
	foreach ( array( '/faz/v1/banner/en', '/wp/v2/posts' ) as $route ) {
		$GLOBALS['rest_headers'] = array();
		$filter( false, null, new Rest_Headers_Request( $route ) );
		rest_check( array() === $GLOBALS['rest_headers'], $route . ': left alone' );
	}

	echo "rest-no-store-headers: " . ( $checks - $failed ) . " passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
