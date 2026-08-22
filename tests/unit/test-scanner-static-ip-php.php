<?php
/** Standalone tests for scanner CURLOPT_RESOLVE entry construction. */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url() {
		global $faz_static_ip_home_url;
		return $faz_static_ip_home_url;
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		global $faz_static_ip_http_calls;
		$faz_static_ip_http_calls[] = array( 'url' => $url, 'args' => $args );
		return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '' );
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->message = $message; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
}
if ( ! class_exists( 'Faz_Static_Ip_Settings_Stub' ) ) {
	class Faz_Static_Ip_Settings_Stub {
		public static function get_instance() { return new self(); }
		public function get() { return ''; }
	}
	class_alias( Faz_Static_Ip_Settings_Stub::class, 'FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings' );
}
if ( ! class_exists( 'Faz_Static_Ip_Rest_Controller_Stub' ) ) {
	class Faz_Static_Ip_Rest_Controller_Stub {}
	class_alias( Faz_Static_Ip_Rest_Controller_Stub::class, 'FazCookie\\Includes\\Rest_Controller' );
}
if ( ! class_exists( 'Faz_Static_Ip_Scanner_Logger_Stub' ) ) {
	class Faz_Static_Ip_Scanner_Logger_Stub {
		public static function get_instance() { return new self(); }
		public function start() {}
		public function log() {}
		public function finish() {}
	}
	class_alias( Faz_Static_Ip_Scanner_Logger_Stub::class, 'FazCookie\\Admin\\Modules\\Scanner\\Includes\\Scanner_Logger' );
}
require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/api/class-api.php';

use FazCookie\Admin\Modules\Scanner\Includes\Controller;
use FazCookie\Admin\Modules\Scanner\Api\Api;

$failed = 0;
function faz_static_ip_check( $label, $actual, $expected ) {
	global $failed;
	if ( $actual === $expected ) { echo "PASS: {$label}\n"; return; }
	$failed++;
	echo "FAIL: {$label}\n  expected " . var_export( $expected, true ) . "\n  got " . var_export( $actual, true ) . "\n";
}

faz_static_ip_check( 'public IPv4 HTTPS default port', Controller::build_static_resolve_entry( 'https://example.com/path', '93.184.216.34' ), 'example.com:443:93.184.216.34' );
faz_static_ip_check( 'explicit HTTP port', Controller::build_static_resolve_entry( 'http://EXAMPLE.com:8080/path', '8.8.8.8' ), 'example.com:8080:8.8.8.8' );
faz_static_ip_check( 'public IPv6 is bracketed', Controller::build_static_resolve_entry( 'https://example.com/', '2606:4700:4700::1111' ), 'example.com:443:[2606:4700:4700::1111]' );
faz_static_ip_check( 'private IPv4 is rejected', Controller::build_static_resolve_entry( 'https://example.com/', '192.168.1.10' ), '' );
faz_static_ip_check( 'loopback is rejected', Controller::build_static_resolve_entry( 'https://example.com/', '127.0.0.1' ), '' );
faz_static_ip_check( 'non-HTTP scheme is rejected', Controller::build_static_resolve_entry( 'ftp://example.com/', '8.8.8.8' ), '' );

$canonical_host = new ReflectionMethod( Controller::class, 'canonical_scan_host' );
$canonical_host->setAccessible( true );
$hosts_match = new ReflectionMethod( Controller::class, 'scan_hosts_match' );
$hosts_match->setAccessible( true );
faz_static_ip_check( 'www host is canonicalized', $canonical_host->invoke( null, 'WWW.Example.com' ), 'example.com' );
faz_static_ip_check( 'bracketed IPv6 loopback is canonicalized', $canonical_host->invoke( null, '[::1]' ), '::1' );
faz_static_ip_check( 'loopback aliases compare equal', $hosts_match->invoke( null, 'localhost', '127.0.0.1' ), true );
faz_static_ip_check( 'bracketed IPv6 loopback compares equal', $hosts_match->invoke( null, '[::1]', 'localhost' ), true );

$controller               = new Controller();
$faz_static_ip_http_calls = array();
$faz_static_ip_home_url   = 'http://localhost:9998';
$controller->remote_get(
	'http://[::1]:9998/scanner-fixture',
	array(
		'redirection'        => 3,
		'reject_unsafe_urls' => false,
	)
);
$loopback_args = $faz_static_ip_http_calls[0]['args'];
faz_static_ip_check(
	'validated loopback keeps the explicit URL-validation exception',
	$loopback_args['reject_unsafe_urls'],
	false
);
faz_static_ip_check(
	'validated loopback disables automatic redirects',
	$loopback_args['redirection'],
	0
);

$faz_static_ip_http_calls = array();
$faz_static_ip_home_url   = 'https://example.com';
$controller->remote_get(
	'https://cdn.example.net/scanner-fixture',
	array(
		'redirection'        => 3,
		'reject_unsafe_urls' => false,
	)
);
$external_args = $faz_static_ip_http_calls[0]['args'];
faz_static_ip_check(
	'non-loopback cannot disable WordPress unsafe-URL rejection',
	$external_args['reject_unsafe_urls'],
	true
);
faz_static_ip_check(
	'non-loopback keeps its bounded redirect policy',
	$external_args['redirection'],
	3
);

class Faz_Static_Ip_Server_Scan_Controller_Stub {
	public $http_calls = array();
	public function sanitize_scanned_urls( $urls ) { return $urls; }
	public function remote_get( $url, $args ) {
		$this->http_calls[] = array( 'url' => $url, 'args' => $args );
		return new WP_Error( 'faz_test_stop', 'stop after request capture' );
	}
}
class Faz_Static_Ip_Request_Stub {
	private $url;
	public function __construct( $url ) { $this->url = $url; }
	public function get_param( $name ) { return 'url' === $name ? $this->url : null; }
}

$faz_static_ip_home_url = 'http://localhost:9998';
$server_controller      = new Faz_Static_Ip_Server_Scan_Controller_Stub();
$api                    = new Api( $server_controller );
$api->server_scan( new Faz_Static_Ip_Request_Stub( 'http://[::1]:9998/scanner-fixture' ) );
faz_static_ip_check( 'server_scan accepts the equivalent bracketed IPv6 loopback host', count( $server_controller->http_calls ), 1 );
$server_args = $server_controller->http_calls[0]['args'];
faz_static_ip_check( 'server_scan disables loopback redirects', $server_args['redirection'], 0 );
faz_static_ip_check( 'server_scan marks validated loopback for the narrow URL-safety exception', $server_args['reject_unsafe_urls'], false );

echo $failed ? "{$failed} failed\n" : "ALL PASS\n";
exit( $failed ? 1 : 0 );
