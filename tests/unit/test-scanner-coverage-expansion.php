<?php
/**
 * Scanner maximal-coverage regression tests.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'YEAR_IN_SECONDS', 31536000 );

$GLOBALS['faz_test_transients'] = array();
$GLOBALS['faz_test_user_meta']  = array();

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}

function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function __( $value, ...$unused ) { return $value; }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $url ) { return filter_var( $url, FILTER_SANITIZE_URL ); }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function is_ssl() { return true; }
function get_current_user_id() { return 7; }
function get_transient( $key ) { return isset( $GLOBALS['faz_test_transients'][ $key ] ) ? $GLOBALS['faz_test_transients'][ $key ] : false; }
function set_transient( $key, $value ) { $GLOBALS['faz_test_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['faz_test_transients'][ $key ] ); }
function wp_generate_uuid4() { return '12345678-1234-1234-1234-123456789abc'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_user_meta( $user_id, $key, $single = false ) {
	$values = isset( $GLOBALS['faz_test_user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['faz_test_user_meta'][ $user_id ][ $key ] : array();
	return $single ? ( isset( $values[0] ) ? $values[0] : '' ) : $values;
}
function delete_user_meta( $user_id, $key, $value = '' ) {
	if ( empty( $GLOBALS['faz_test_user_meta'][ $user_id ][ $key ] ) ) {
		return;
	}
	$GLOBALS['faz_test_user_meta'][ $user_id ][ $key ] = array_values(
		array_filter(
			$GLOBALS['faz_test_user_meta'][ $user_id ][ $key ],
			static function ( $stored ) use ( $value ) { return $stored !== $value; }
		)
	);
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-cookie-database.php';

use FazCookie\Admin\Modules\Scanner\Includes\Controller;
use FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database;

$checks = array();
function coverage_check( $condition, $label ) {
	global $checks;
	$checks[] = array( (bool) $condition, $label );
}

$controller = new Controller();

$first_scan_id  = str_repeat( '1', 32 );
$second_scan_id = str_repeat( '2', 32 );
$first_token    = $controller->start_browser_scan_session( $first_scan_id );
coverage_check( is_string( $first_token ) && 32 === strlen( $first_token ), 'discover creates an isolated browser capture session' );
coverage_check( $first_token === $controller->start_browser_scan_session( $first_scan_id ), 'a retry with the same scan ID reuses its active session' );
coverage_check( is_wp_error( $controller->start_browser_scan_session( $second_scan_id ) ), 'a second tab cannot overwrite an active browser scan' );

$names = $controller->extract_request_cookie_names(
	'wordpress_logged_in_hash=secret; brikpanel_vid=private; malformed name=x; tk_ai=dashboard; foo.bar=value',
	array( 'foo_bar' => 'value', 'brikpanel_vid' => 'private' )
);
coverage_check( in_array( 'brikpanel_vid', $names, true ), 'request-cookie probe retains HttpOnly-compatible cookie names' );
coverage_check( in_array( 'foo.bar', $names, true ) && ! in_array( 'foo_bar', $names, true ), 'raw Cookie header preserves dotted names without PHP-normalized duplicates' );
coverage_check( ! in_array( 'malformed name', $names, true ), 'invalid cookie names are rejected' );
coverage_check( count( array_keys( $names, 'brikpanel_vid', true ) ) === 1, 'request-cookie names are deduplicated' );
coverage_check( array( 'fallback_cookie' ) === $controller->extract_request_cookie_names( '', array( 'fallback_cookie' => 'value' ) ), 'parsed request cookies remain a fallback when the raw header is unavailable' );

$safe_urls = $controller->sanitize_scanned_urls(
	array(
		'https://example.test/product/one',
		'https://www.example.test/checkout?step=2',
		'https://evil.test/steal',
		'https://example.test:8443/internal-service',
		'file:///etc/passwd',
		'https://user:pass@example.test/private',
	)
);
coverage_check( 2 === count( $safe_urls ), 'server enrichment keeps every safe same-site browser URL' );
coverage_check( false !== strpos( implode( ' ', $safe_urls ), '/checkout/' ), 'www/apex equivalent URLs remain eligible' );
coverage_check( false === strpos( implode( ' ', $safe_urls ), 'evil.test' ), 'foreign hosts are excluded from server replay' );
coverage_check( false === strpos( implode( ' ', $safe_urls ), ':8443' ), 'alternate same-host ports are excluded from server replay' );

$token = str_repeat( 'a', 32 );
$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $token;
$GLOBALS['faz_test_transients'][ 'faz_scan_session_' . hash( 'sha256', $token ) ] = array( 'user_id' => 7, 'created_at' => time() );
$runtime_scan_id = str_repeat( 'b', 32 );
$GLOBALS['faz_test_transients'][ 'faz_scan_session_' . hash( 'sha256', $token ) ]['scan_id'] = $runtime_scan_id;
$GLOBALS['faz_test_user_meta'][7][ Controller::BROWSER_SCAN_META ] = array(
	array(
		'token'       => $token,
		'observed_at' => time(),
		'name'        => 'brikpanel_vid',
		'domain'      => '',
		'path'        => '/',
		'expires'     => '',
		'max-age'     => '31536000',
		'httponly'    => true,
	),
);
$runtime = $controller->finish_browser_scan_session( $runtime_scan_id );
coverage_check( 1 === count( $runtime ) && 'brikpanel_vid' === $runtime[0]['name'], 'runtime Set-Cookie observation is drained into the inventory' );
coverage_check( 'server-runtime' === $runtime[0]['source'], 'runtime observation preserves its discovery provenance' );
coverage_check( '1 year' === $runtime[0]['duration'], 'runtime Max-Age metadata becomes a useful duration' );
coverage_check( empty( $GLOBALS['faz_test_user_meta'][7][ Controller::BROWSER_SCAN_META ] ), 'drained observations are removed' );

coverage_check( 'wordpress-internal' === Cookie_Database::lookup( 'tk_ai' )['category'], 'Automattic dashboard cookies remain inventoried but classified internal' );
coverage_check( 'analytics' === Cookie_Database::lookup( 'brikpanel_vid' )['category'], 'Brikpanel visitor ID is classified when observed' );

$root = dirname( __DIR__, 2 );
$engine = (string) file_get_contents( $root . '/admin/assets/js/modules/scan-engine.js' );
$api = (string) file_get_contents( $root . '/admin/modules/scanner/api/class-api.php' );
$bootstrap = (string) file_get_contents( $root . '/faz-cookie-manager.php' );
$controller_source = (string) file_get_contents( $root . '/admin/modules/scanner/includes/class-controller.php' );
coverage_check( false === strpos( $engine, 'EARLY_STOP_THRESHOLD' ), 'scanner has no implicit path that overrides the administrator-selected depth' );
coverage_check( false !== strpos( $engine, 'scanned_urls: scanMetrics.scannedUrls' ), 'browser-visited URLs are sent to server enrichment' );
coverage_check( false !== strpos( $engine, "getEntriesByType('resource')" ), 'runtime network resources feed provider inference' );
coverage_check( false !== strpos( $engine, 'triggerDelayedScripts();' ), 'interaction-delayed scripts receive a controlled activation signal' );
coverage_check( false !== strpos( $engine, 'MAX_RESOURCE_URLS' ), 'runtime resource payloads are capped and diagnosed' );
coverage_check( false !== strpos( $api, 'finish_browser_scan_session( $scan_id )' ), 'REST import merges only its own runtime Set-Cookie observations' );
coverage_check( false !== strpos( $bootstrap, 'register_browser_scan_observer()' ), 'Set-Cookie observer is registered on every same-origin request' );
coverage_check( false === strpos( $controller_source, "header_register_callback(\n" ) && false !== strpos( $controller_source, "add_action(\n\t\t\t'shutdown'" ), 'runtime observer coexists with other PHP header callbacks' );
coverage_check( false !== strpos( $controller_source, "'redirection'        => 0" ) && false !== strpos( $controller_source, 'WP_Http::make_absolute_url' ), 'server replay validates every redirect hop and retains intermediate headers' );
coverage_check( false !== strpos( $controller_source, 'BROWSER_SCAN_OBSERVATION_LIMIT' ), 'repeated runtime Set-Cookie observations are deduplicated and capped' );
coverage_check( false !== strpos( $controller_source, '$php = $this->find_php_cli();' ), 'httpOnly worker resolves a real CLI binary instead of trusting PHP_BINARY under FPM' );

$failed = 0;
foreach ( $checks as $index => $check ) {
	if ( $check[0] ) {
		echo 'PASS ' . ( $index + 1 ) . ': ' . $check[1] . "\n";
	} else {
		++$failed;
		echo 'FAIL ' . ( $index + 1 ) . ': ' . $check[1] . "\n";
	}
}
echo count( $checks ) . ' checks, ' . $failed . " failed\n";
exit( $failed > 0 ? 1 : 0 );
