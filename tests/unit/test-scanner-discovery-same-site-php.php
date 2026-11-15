<?php
/**
 * Regression test (2026-11 security audit, hardening notes): scanner page
 * discovery stays on the site, and the site host's resolved address is
 * checked where its DNS may not be the administrator's.
 *
 *  - discover_pages() admitted sub-sitemap <loc> URLs on a bare host match,
 *    with no scheme or port check; core exempts the site host from its port
 *    and private-address checks, so a sitemap index could aim the scanner at
 *    the site host on any port. Sub-sitemaps now go through
 *    sanitize_scanned_urls().
 *  - The sitemap.xml, sub-sitemap and homepage fetches kept core's automatic
 *    redirect following, which validates only the first URL. They now use
 *    'redirection' => 0 and re-validate every hop, like scan_page().
 *  - On multisite, remote_get() refuses the site host when it resolves to a
 *    private or reserved address (mapped domains whose DNS a subsite owner
 *    controls).
 *
 * Run: php tests/unit/test-scanner-discovery-same-site-php.php
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
$GLOBALS['sd_home']      = 'https://example.com';
$GLOBALS['sd_calls']     = array();
$GLOBALS['sd_responses'] = array();
$GLOBALS['sd_multisite'] = false;

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function absint( $v ) {
	return abs( (int) $v );
}
function home_url( $path = '' ) {
	return $GLOBALS['sd_home'] . $path;
}
function trailingslashit( $v ) {
	return rtrim( $v, '/' ) . '/';
}
function esc_url_raw( $v ) {
	return (string) $v;
}
function add_action() {}
function remove_action() {}
function apply_filters( $hook, $value ) {
	return $value;
}
function is_multisite() {
	return $GLOBALS['sd_multisite'];
}
function __( $t, $d = '' ) {
	return $t;
}
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}
class WP_Error {
	public $code;
	public function __construct( $code = '', $message = '' ) {
		$this->code = $code;
	}
	public function get_error_code() {
		return $this->code;
	}
}
class WP_Http {
	public static function make_absolute_url( $maybe_relative, $url ) {
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $maybe_relative ) ) {
			return $maybe_relative;
		}
		$p = parse_url( $url );
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . '/' . ltrim( $maybe_relative, '/' );
	}
}
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['sd_calls'][] = array( 'url' => $url, 'args' => $args );
	if ( isset( $GLOBALS['sd_responses'][ $url ] ) ) {
		return $GLOBALS['sd_responses'][ $url ];
	}
	return array( 'response' => array( 'code' => 404 ), 'headers' => array(), 'body' => '' );
}
function wp_remote_retrieve_response_code( $r ) {
	return $r['response']['code'] ?? 0;
}
function wp_remote_retrieve_body( $r ) {
	return $r['body'] ?? '';
}
function wp_remote_retrieve_header( $r, $h ) {
	return $r['headers'][ $h ] ?? '';
}
class Faz_Sd_Settings_Stub {
	public static function get_instance() {
		return new self();
	}
	public function get() {
		return '';
	}
}
class_alias( Faz_Sd_Settings_Stub::class, 'FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings' );
class Faz_Sd_Rest_Controller_Stub {}
class_alias( Faz_Sd_Rest_Controller_Stub::class, 'FazCookie\\Includes\\Rest_Controller' );
class Faz_Sd_Logger_Stub {
	public static function get_instance() {
		return new self();
	}
	public function start() {}
	public function log() {}
	public function finish() {}
}
class_alias( Faz_Sd_Logger_Stub::class, 'FazCookie\\Admin\\Modules\\Scanner\\Includes\\Scanner_Logger' );

require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';

use FazCookie\Admin\Modules\Scanner\Includes\Controller;

/** Controller with a scripted resolver. */
class Faz_Sd_Controller extends Controller {
	public $resolved = array();
	protected function resolve_scan_host( $host ) {
		return $this->resolved;
	}
}

$passed = 0;
$failed = 0;
function check( $cond, $label ) {
	global $passed, $failed;
	if ( $cond ) {
		++$passed;
		echo "  \033[32mPASS\033[0m {$label}\n";
	} else {
		++$failed;
		echo "  \033[31mFAIL\033[0m {$label}\n";
	}
}
function ok_xml( $body ) {
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => $body );
}
function redirect( $to ) {
	return array( 'response' => array( 'code' => 301 ), 'headers' => array( 'location' => $to ), 'body' => '' );
}
function fetched( $url ) {
	foreach ( $GLOBALS['sd_calls'] as $c ) {
		if ( $c['url'] === $url ) {
			return true;
		}
	}
	return false;
}

$c = new Faz_Sd_Controller();

echo "\nsub-sitemaps go through sanitize_scanned_urls()\n";
$GLOBALS['sd_calls']     = array();
$GLOBALS['sd_responses'] = array(
	'https://example.com/sitemap.xml'      => ok_xml( '<sitemapindex><sitemap><loc>https://example.com:6379/x.xml</loc></sitemap><sitemap><loc>http://example.com/plain.xml</loc></sitemap><sitemap><loc>https://user:pw@example.com/u.xml</loc></sitemap><sitemap><loc>https://example.com/post-sitemap.xml</loc></sitemap></sitemapindex>' ),
	'https://example.com/post-sitemap.xml' => ok_xml( '<urlset><url><loc>https://example.com/a/</loc></url></urlset>' ),
);
$pages = $c->discover_pages( 'https://example.com', 10 );
check( ! fetched( 'https://example.com:6379/x.xml' ), 'a sub-sitemap on another port of the site host is not fetched' );
check( ! fetched( 'http://example.com/plain.xml' ), 'a sub-sitemap on another scheme is not fetched' );
check( ! fetched( 'https://user:pw@example.com/u.xml' ), 'a sub-sitemap with userinfo is not fetched' );
check( fetched( 'https://example.com/post-sitemap.xml' ), 'a same-site sub-sitemap is still fetched' );
check( in_array( 'https://example.com/a/', $pages, true ), 'and its pages are discovered' );

echo "\ndiscovery fetches follow redirects only within the site\n";
$all_manual = true;
foreach ( $GLOBALS['sd_calls'] as $call ) {
	$all_manual = $all_manual && isset( $call['args']['redirection'] ) && 0 === $call['args']['redirection'];
}
check( $all_manual, 'every discovery fetch is made with redirection => 0' );

$GLOBALS['sd_calls']     = array();
$GLOBALS['sd_responses'] = array(
	'https://example.com/sitemap.xml'     => redirect( '/wp-sitemap.xml' ),
	'https://example.com/wp-sitemap.xml'  => ok_xml( '<urlset><url><loc>https://example.com/b/</loc></url></urlset>' ),
);
$pages = $c->discover_pages( 'https://example.com', 10 );
check( in_array( 'https://example.com/b/', $pages, true ), 'a same-site redirect (sitemap.xml to wp-sitemap.xml) is followed' );

$GLOBALS['sd_calls']     = array();
$GLOBALS['sd_responses'] = array(
	'https://example.com/sitemap.xml' => redirect( 'https://attacker.example.net/sitemap.xml' ),
	'https://example.com/'            => redirect( 'https://attacker.example.net/' ),
);
$c->discover_pages( 'https://example.com', 10 );
check( ! fetched( 'https://attacker.example.net/sitemap.xml' ), 'an off-site sitemap redirect is not followed' );
check( ! fetched( 'https://attacker.example.net/' ), 'an off-site homepage redirect is not followed' );

echo "\nresolved address of the site host\n";
$GLOBALS['sd_multisite'] = true;
$c->resolved             = array( '10.0.0.5' );
$r                       = $c->remote_get( 'https://example.com/', array() );
check( is_wp_error( $r ) && 'faz_scanner_internal_address' === $r->get_error_code(), 'multisite: a site host resolving to a private address is refused' );
$c->resolved = array( '93.184.216.34' );
check( ! is_wp_error( $c->remote_get( 'https://example.com/', array() ) ), 'multisite: a public address is fetched' );
$c->resolved = array();
check( ! is_wp_error( $c->remote_get( 'https://example.com/', array() ) ), 'multisite: a host that does not resolve is left to the request itself' );
$GLOBALS['sd_multisite'] = false;
$c->resolved             = array( '10.0.0.5' );
check( ! is_wp_error( $c->remote_get( 'https://example.com/', array() ) ), 'single site: split-horizon DNS to a private address keeps working' );
$GLOBALS['sd_multisite'] = true;
$GLOBALS['sd_home']      = 'http://localhost:9998';
$c->resolved             = array( '127.0.0.1' );
check( ! is_wp_error( $c->remote_get( 'http://localhost:9998/', array() ) ), 'a validated loopback dev site is not affected' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
