<?php
/**
 * Trusted alternate domains: the hosts this same site is served on.
 *
 * wp.org topic "Cookie Banner Not Working on GTranslate Translation Proxy
 * Domains". GTranslate's paid Translation Proxy serves the Czech origin
 * dilnastore.cz as dilnastore.com / .pl / .de and forwards every request of the
 * translated domains to the origin. Three things were broken there and this
 * suite pins the server half of the fix:
 *
 *   1. the translated domains GTranslate's own settings describe are derived
 *      correctly (Integrations\Gtranslate::translated_hosts()) and merged into
 *      faz_trusted_origins() only while GTranslate is active;
 *   2. entries are normalised the one way the gates compare them, through the
 *      settings sanitiser as well (faz_normalize_trusted_origin());
 *   3. core's allowed_http_origins list is widened for this plugin's own
 *      /faz/v1/ REST routes and its public (nopriv) admin-ajax actions ONLY,
 *      faz_public_ajax_actions(), never the whole faz_ prefix — core sends
 *      `Access-Control-Allow-Credentials: true` with it;
 *   4. the `throttled` refusals: every proxied visitor reaches the origin from
 *      the proxy's addresses. No trust shortcut was added — the existing
 *      declared-proxy path must read GTranslate's `X-GT-Viewer-IP` once the
 *      ranges and the header are declared, and must keep ignoring it from an
 *      undeclared peer.
 *
 * The same-origin gate itself is covered by test-consent-logger-csrf-php.php
 * and test-dnsmpi-csrf-php.php.
 *
 * Run: php tests/unit/test-trusted-origins-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Store {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	$GLOBALS['faz_test_filters'] = array();
	$GLOBALS['faz_test_options'] = array();
	$GLOBALS['faz_test_ajax']    = false;

	function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $key ) ); }
	function sanitize_text_field( $value ) { return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', strip_tags( (string) $value ) ) ); }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
	function home_url( $path = '' ) { return ( isset( $GLOBALS['faz_test_home_url'] ) ? $GLOBALS['faz_test_home_url'] : 'https://www.dilnastore.cz' ) . $path; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
	function esc_url_raw( $url ) { return (string) $url; }
	function wp_doing_ajax() { return (bool) $GLOBALS['faz_test_ajax']; }
	function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['faz_test_options'] ) ? $GLOBALS['faz_test_options'][ $name ] : $default; }
	function apply_filters( $hook, $value, ...$args ) {
		return isset( $GLOBALS['faz_test_filters'][ $hook ] ) ? call_user_func( $GLOBALS['faz_test_filters'][ $hook ], $value, ...$args ) : $value;
	}

	$root = dirname( __DIR__, 2 );
	require_once $root . '/includes/class-utils.php';
	require_once $root . '/includes/integrations/class-gtranslate.php';
	require_once $root . '/includes/class-formatting.php';
	require_once $root . '/admin/modules/settings/includes/class-settings.php';

	use FazCookie\Includes\Integrations\Gtranslate;
	use FazCookie\Admin\Modules\Settings\Includes\Settings;

	$passed = 0;
	$failed = 0;
	function tro_same( $actual, $expected, $label ) {
		global $passed, $failed;
		if ( $actual === $expected ) {
			$passed++;
			echo "  [PASS] {$label}\n";
			return;
		}
		$failed++;
		echo "  [FAIL] {$label}\n";
		echo '         expected: ' . var_export( $expected, true ) . "\n";
		echo '         actual:   ' . var_export( $actual, true ) . "\n";
	}
	function tro_sorted( array $list ) {
		sort( $list );
		return $list;
	}

	echo "== faz_cookie_domain_for_host(): the server-side cookie scope ==\n";
	tro_same( faz_cookie_domain_for_host( '.dilnastore.cz', 'www.dilnastore.cz' ), '.dilnastore.cz', 'a host inside the configured domain keeps it' );
	tro_same( faz_cookie_domain_for_host( '.dilnastore.cz', 'dilnastore.cz:443' ), '.dilnastore.cz', 'the bare domain (port ignored) keeps it' );
	tro_same( faz_cookie_domain_for_host( '.dilnastore.cz', 'dilnastore.com' ), '', 'a proxy domain outside it gets a host-only cookie' );
	tro_same( faz_cookie_domain_for_host( '.dilnastore.cz', 'evildilnastore.cz' ), '', 'a suffix that is not a label boundary is outside' );
	tro_same( faz_cookie_domain_for_host( '', 'dilnastore.com' ), '', 'no configured domain stays host-only' );

	echo "== faz_normalize_trusted_origin() ==\n";
	tro_same( faz_normalize_trusted_origin( ' Dilnastore.COM ' ), 'dilnastore.com', 'a bare host is lowercased and trimmed' );
	tro_same( faz_normalize_trusted_origin( 'dilnastore.de/' ), 'dilnastore.de', 'a pasted trailing path is dropped from a bare host' );
	tro_same( faz_normalize_trusted_origin( 'dilnastore.pl.' ), 'dilnastore.pl', 'a trailing dot is dropped' );
	tro_same( faz_normalize_trusted_origin( 'https://Shop.Example.de/cs/kosik?x=1' ), 'https://shop.example.de', 'a full URL becomes its origin' );
	tro_same( faz_normalize_trusted_origin( 'https://example.de:443' ), 'https://example.de', 'a default https port is dropped' );
	tro_same( faz_normalize_trusted_origin( 'http://example.de:80/' ), 'http://example.de', 'a default http port is dropped' );
	tro_same( faz_normalize_trusted_origin( 'https://example.de:8443' ), 'https://example.de:8443', 'a non-default port is kept' );
	tro_same( faz_normalize_trusted_origin( 'example.de:8443' ), '', 'a bare host:port is refused (write the URL when the port matters)' );
	tro_same( faz_normalize_trusted_origin( 'javascript:alert(1)' ), '', 'a javascript: entry is refused' );
	tro_same( faz_normalize_trusted_origin( 'ftp://example.de' ), '', 'a non-http scheme is refused' );
	tro_same( faz_normalize_trusted_origin( '*.example.de' ), '', 'a wildcard is refused' );
	tro_same( faz_normalize_trusted_origin( 'localhost' ), '', 'a single-label name is refused' );
	tro_same( faz_normalize_trusted_origin( 'exa mple.de' ), '', 'whitespace inside a host is refused' );
	tro_same( faz_normalize_trusted_origin( array( 'example.de' ) ), '', 'a non-string is refused' );
	tro_same( faz_normalize_trusted_origin( '203.0.113.7' ), '203.0.113.7', 'an IPv4 host is accepted' );
	tro_same( faz_normalize_trusted_origin( '2001:db8::1' ), '[2001:db8::1]', 'a bare IPv6 host is stored in brackets, the form an Origin carries' );
	tro_same( faz_normalize_trusted_origin( '[2001:DB8::1]' ), '[2001:db8::1]', 'a bracketed IPv6 host is kept' );
	tro_same( faz_normalize_trusted_origin( '2001:db8::zz' ), '', 'a colon in anything but an IPv6 literal is refused' );

	echo "\n== Gtranslate::translated_hosts() from the GTranslate option ==\n";
	$custom = array(
		'enterprise_version'  => 1,
		'pro_version'         => '',
		'custom_domains'      => 1,
		// GTranslate stores the synchronised map through sanitize_text_field()
		// of a slashed POST value, and json_decode()s it after stripslashes().
		'custom_domains_data' => addslashes( '{"en":"dilnastore.com","pl":"DilnaStore.pl","de":"dilnastore.de"}' ),
		'default_language'    => 'cs',
		'fincl_langs'         => array( 'cs', 'en', 'pl', 'de' ),
	);
	tro_same( tro_sorted( Gtranslate::translated_hosts( $custom, 'www.dilnastore.cz' ) ), array( 'dilnastore.com', 'dilnastore.de', 'dilnastore.pl' ), 'custom domains: every mapped domain is trusted, lowercased' );
	$custom_array = $custom;
	$custom_array['custom_domains_data'] = array( 'en' => 'dilnastore.com' );
	tro_same( tro_sorted( Gtranslate::translated_hosts( $custom_array, 'www.dilnastore.cz' ) ), array( 'de.dilnastore.cz', 'dilnastore.com', 'pl.dilnastore.cz' ), 'partial custom map: unmapped languages keep their sub-domain hosts, mapped languages use only their custom host' );

	$sub = array(
		'enterprise_version' => '1',
		'pro_version'        => '',
		'custom_domains'     => '',
		'default_language'   => 'cs',
		'fincl_langs'        => array( 'cs', 'en', 'de' ),
		'incl_langs'         => array( 'en', 'zh-CN', 'bad lang', '../x' ),
	);
	tro_same(
		tro_sorted( Gtranslate::translated_hosts( $sub, 'www.dilnastore.cz' ) ),
		tro_sorted( array( 'en.dilnastore.cz', 'de.dilnastore.cz', 'zh-cn.dilnastore.cz' ) ),
		'sub_domain structure: www is stripped, source language and junk codes skipped, no additional www hosts trusted'
	);
	tro_same( tro_sorted( Gtranslate::translated_hosts( $sub, 'dilnastore.cz' ) ), array( 'de.dilnastore.cz', 'en.dilnastore.cz', 'zh-cn.dilnastore.cz' ), 'sub_domain structure on an apex WordPress Address' );
	$sub_unsynced = $custom;
	$sub_unsynced['custom_domains_data'] = '';
	tro_same( tro_sorted( Gtranslate::translated_hosts( $sub_unsynced, 'dilnastore.cz' ) ), array( 'de.dilnastore.cz', 'en.dilnastore.cz', 'pl.dilnastore.cz' ), 'custom domains on but never synchronised: the sub-domain hosts' );
	$pro = $sub;
	$pro['pro_version'] = 1;
	tro_same( Gtranslate::translated_hosts( $pro, 'dilnastore.cz' ), array(), 'pro_version (sub_directory) wins and stays on this host: nothing to trust' );
	tro_same( Gtranslate::translated_hosts( array( 'pro_version' => '', 'enterprise_version' => '', 'fincl_langs' => array( 'en' ) ), 'dilnastore.cz' ), array(), 'the free widget translates in the browser: nothing to trust' );
	tro_same( Gtranslate::translated_hosts( $sub, '192.0.2.10' ), array(), 'an IP WordPress Address has no sub-domains' );
	tro_same( Gtranslate::translated_hosts( 'not-an-array', 'dilnastore.cz' ), array(), 'a malformed option is ignored' );

	echo "\n== faz_trusted_origins(): sources and the GTranslate gate ==\n";
	$GLOBALS['faz_test_options']['GTranslate']   = $custom;
	$GLOBALS['faz_test_options']['faz_settings'] = array( 'trusted_origins' => array( 'alternate_domains' => array( 'mirror.example.org', 'dilnastore.com' ) ) );
	tro_same( Gtranslate::is_active(), false, 'premise: GTranslate is not loaded' );
	tro_same( faz_trusted_origins(), array( 'mirror.example.org', 'dilnastore.com' ), 'a stale GTranslate option is ignored while GTranslate is inactive' );
	define( 'GTRANSLATE_VERSION', '5.0.2' );
	tro_same( Gtranslate::is_active(), true, 'GTRANSLATE_VERSION marks GTranslate as loaded' );
	tro_same( faz_trusted_origins(), array( 'mirror.example.org', 'dilnastore.com', 'dilnastore.pl', 'dilnastore.de' ), 'setting first, then the auto-detected domains, de-duplicated' );
	$GLOBALS['faz_test_filters']['faz_trusted_origins'] = function ( $list ) {
		$list   = array_diff( $list, array( 'dilnastore.pl' ) );
		$list[] = 'HTTPS://cdn.example.net:8443/';
		$list[] = 'not a host';
		return $list;
	};
	tro_same( faz_trusted_origins(), array( 'mirror.example.org', 'dilnastore.com', 'dilnastore.de', 'https://cdn.example.net:8443' ), 'the filter sees every source, can remove and add, and its output is normalised' );
	unset( $GLOBALS['faz_test_filters']['faz_trusted_origins'] );

	echo "\n== allowed_http_origins is widened for the plugin's own endpoints only ==\n";
	$core = array( 'http://www.dilnastore.cz', 'https://www.dilnastore.cz' );
	$GLOBALS['faz_test_options']['faz_settings'] = array( 'trusted_origins' => array( 'alternate_domains' => array( 'https://cdn.example.net:8443' ) ) );
	$GLOBALS['faz_test_options']['GTranslate']   = $custom_array; // dilnastore.com only.
	$GLOBALS['faz_test_options']['GTranslate']['fincl_langs'] = array( 'cs', 'en' );
	// home_url() is https, so a bare host contributes its https origin only:
	// an http page on that host could be rewritten on the network path.
	$widened = array( 'http://www.dilnastore.cz', 'https://www.dilnastore.cz', 'https://cdn.example.net:8443', 'https://dilnastore.com' );
	$reset   = function () {
		unset( $GLOBALS['wp'] );
		$_GET    = array();
		$_REQUEST = array();
		$_SERVER = array();
		$GLOBALS['faz_test_ajax'] = false;
	};

	$reset();
	$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/faz/v1/consent' ) );
	tro_same( faz_allowed_http_origins( $core ), $widened, 'REST /faz/v1/consent (parsed route): trusted origins added, bare hosts as https only on an https site' );
	$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/wp/v2/users/me' ) );
	tro_same( faz_allowed_http_origins( $core ), $core, 'REST /wp/v2/users/me: core list untouched' );
	$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/faz/v10/x' ) );
	tro_same( faz_allowed_http_origins( $core ), $core, 'a lookalike namespace (/faz/v10/) is not the plugin\'s' );

	// Plain permalinks: core parses ?rest_route= into the same query var.
	$reset();
	$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/faz/v1/pageview' ) );
	tro_same( faz_allowed_http_origins( $core ), $widened, '?rest_route=/faz/v1/pageview once parsed (plain permalinks): widened' );
	// Unparsed routing hints are not trusted. Core also consults
	// allowed_http_origins on admin-post.php (no parse_request at all) and in
	// the Customizer preview (before parse_request): a forged hint there would
	// grant credentialed CORS to a privileged handler.
	$reset();
	$_GET['rest_route'] = '/faz/v1/consent';
	$_REQUEST['action'] = 'export_everything';
	tro_same( faz_allowed_http_origins( $core ), $core, 'admin-post.php?action=…&rest_route=/faz/v1/…: untouched' );
	$reset();
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin-post.php/wp-json/faz/v1/consent';
	tro_same( faz_allowed_http_origins( $core ), $core, 'admin-post.php with a REST-looking PATH_INFO: untouched' );
	$reset();
	$_SERVER['REQUEST_URI'] = '/some-page/wp-json/faz/v1/?customize_changeset_uuid=x';
	tro_same( faz_allowed_http_origins( $core ), $core, 'a /wp-json/faz/v1/ segment in the middle of a front-end path: untouched' );
	$reset();
	$_SERVER['REQUEST_URI'] = '/wp-json/faz/v1/consent?x=1';
	tro_same( faz_allowed_http_origins( $core ), $core, '/wp-json/faz/v1/consent before parse_request: not yet a REST dispatch, untouched' );
	$reset();
	$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
	tro_same( faz_allowed_http_origins( $core ), $core, '/wp-json/wp/v2/posts: untouched' );

	$reset();
	$GLOBALS['faz_test_ajax'] = true;
	$_REQUEST['action'] = 'faz_dnsmpi_optout';
	tro_same( faz_allowed_http_origins( $core ), $widened, 'admin-ajax action=faz_dnsmpi_optout: widened' );
	$_REQUEST['action'] = 'faz_origin_tokens';
	tro_same( faz_allowed_http_origins( $core ), $widened, 'admin-ajax action=faz_origin_tokens: widened' );
	$_REQUEST['action'] = 'heartbeat';
	tro_same( faz_allowed_http_origins( $core ), $core, 'admin-ajax action=heartbeat: untouched' );
	// Administrator-only actions share the prefix; on a translated sub-domain
	// the browser sends the login cookies (same-site), and core answers with
	// Access-Control-Allow-Credentials, so a proxied page could have read the
	// admin's responses. Only the nopriv actions are widened.
	$_REQUEST['action'] = 'faz_dismiss_unmatched';
	tro_same( faz_allowed_http_origins( $core ), $core, 'admin-ajax action=faz_dismiss_unmatched (administrators only): untouched despite the prefix' );
	$_GET['rest_route'] = '/faz/v1/consent';
	tro_same( faz_allowed_http_origins( $core ), $core, 'a forged REST query cannot widen CORS for a privileged AJAX action' );
	unset( $_GET['rest_route'] );
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php/wp-json/faz/v1/consent';
	tro_same( faz_allowed_http_origins( $core ), $core, 'REST-looking PATH_INFO cannot widen CORS for a privileged AJAX action' );
	unset( $_SERVER['REQUEST_URI'] );
	$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/faz/v1/consent' ) );
	tro_same( faz_allowed_http_origins( $core ), $core, 'AJAX dispatch also takes precedence over a parsed REST route' );
	unset( $GLOBALS['wp'] );
	$_REQUEST['action'] = 'FAZ_dnsmpi_optout';
	tro_same( faz_allowed_http_origins( $core ), $core, 'CORS action matching is exact, like the admin-ajax dispatcher' );
	$_REQUEST['action'] = 'faz_dsar_refresh';
	tro_same( faz_allowed_http_origins( $core ), $widened, 'admin-ajax action=faz_dsar_refresh (nopriv): widened' );
	$GLOBALS['faz_test_filters']['faz_public_ajax_actions'] = function ( $actions ) { $actions[] = 'faz_custom_public'; return $actions; };
	$_REQUEST['action'] = 'faz_custom_public';
	tro_same( faz_allowed_http_origins( $core ), $widened, 'an action added through faz_public_ajax_actions is widened' );
	unset( $GLOBALS['faz_test_filters']['faz_public_ajax_actions'] );
	tro_same( faz_allowed_http_origins( $core ), $core, 'and is not without the filter' );
	$_REQUEST['action'] = 'woocommerce_faz_lookalike';
	tro_same( faz_allowed_http_origins( $core ), $core, 'an action that merely contains faz_ is not the plugin\'s' );
	$GLOBALS['faz_test_ajax'] = false;
	$_REQUEST['action'] = 'faz_dnsmpi_optout';
	tro_same( faz_allowed_http_origins( $core ), $core, 'action=faz_* outside admin-ajax: untouched' );
	$reset();
	tro_same( faz_allowed_http_origins( $core ), $core, 'an ordinary front-end request: untouched' );
	$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/faz/v1/consent' ) );
	$GLOBALS['faz_test_filters']['faz_trusted_origins'] = function () { return array(); };
	tro_same( faz_allowed_http_origins( $core ), $core, 'nothing trusted: nothing added even on the plugin\'s routes' );
	unset( $GLOBALS['faz_test_filters']['faz_trusted_origins'] );
	tro_same( faz_allowed_http_origins( array_merge( $core, array( 'https://dilnastore.com' ) ) ), array( 'http://www.dilnastore.cz', 'https://www.dilnastore.cz', 'https://dilnastore.com', 'https://cdn.example.net:8443' ), 'an origin already allowed is not duplicated' );
	$GLOBALS['faz_test_home_url'] = 'http://www.dilnastore.cz';
	tro_same( faz_allowed_http_origins( $core ), array( 'http://www.dilnastore.cz', 'https://www.dilnastore.cz', 'https://cdn.example.net:8443', 'http://dilnastore.com', 'https://dilnastore.com' ), 'on a plain-http site a bare host contributes both schemes' );
	tro_same( faz_origin_is_trusted( 'http://dilnastore.com' ), true, 'and matches an http origin there' );
	unset( $GLOBALS['faz_test_home_url'] );
	tro_same( faz_origin_is_trusted( 'http://dilnastore.com' ), false, 'on the https site the same http origin is refused' );
	tro_same( faz_origin_is_trusted( 'https://dilnastore.com' ), true, 'while its https origin is trusted' );
	$reset();

	echo "\n== Settings sanitiser: trusted_origins.alternate_domains ==\n";
	$defaults = array( 'trusted_origins' => array( 'alternate_domains' => array() ) );
	$saved    = Settings::sanitize(
		array(
			'trusted_origins' => array(
				'alternate_domains' => array( ' DilnaStore.com ', 'https://Shop.example.pl:8443/kosik', 'javascript:alert(1)', '*.evil.test', 'localhost', 'dilnastore.com', 'http://x.example.com:80', '<b>dilnastore.de</b>', 42 ),
			),
		),
		$defaults
	);
	tro_same( $saved['trusted_origins']['alternate_domains'], array( 'dilnastore.com', 'https://shop.example.pl:8443', 'http://x.example.com', 'dilnastore.de' ), 'entries are stored canonical, junk and duplicates dropped' );
	$saved = Settings::sanitize( array( 'trusted_origins' => array( 'alternate_domains' => "dilnastore.com\r\ndilnastore.pl, dilnastore.de" ) ), $defaults );
	tro_same( $saved['trusted_origins']['alternate_domains'], array( 'dilnastore.com', 'dilnastore.pl', 'dilnastore.de' ), 'a newline/comma separated string (REST, import) is split' );
	$saved = Settings::sanitize( array(), $defaults );
	tro_same( $saved['trusted_origins']['alternate_domains'], array(), 'an install with nothing stored gets an empty list' );
	$saved = Settings::sanitize( array( 'trusted_origins' => array( 'alternate_domains' => 7 ) ), $defaults );
	tro_same( $saved['trusted_origins']['alternate_domains'], array(), 'a scalar that is not a string is dropped' );
	tro_same( in_array( 'alternate_domains', Settings::get_excludes(), true ), true, 'the leaf is handed whole to sanitize_option() instead of recursed into' );

	echo "\n== Throttled refusals: GTranslate's X-GT-Viewer-IP through the declared-proxy path ==\n";
	$GLOBALS['faz_test_options'] = array();
	$gt_peer   = '85.10.20.30';   // A proxy address GTranslate support gave the site.
	$visitor   = '8.8.8.8';
	$declare_ranges = function ( $cidrs ) { return array_merge( (array) $cidrs, array( '85.10.20.0/24' ) ); };
	$name_header    = function () { return 'HTTP_X_GT_VIEWER_IP'; };

	$_SERVER = array( 'REMOTE_ADDR' => $gt_peer, 'HTTP_X_GT_VIEWER_IP' => $visitor, 'HTTP_X_FORWARDED_FOR' => '9.9.9.9' );
	tro_same( faz_resolve_client_ip(), $gt_peer, 'nothing declared: the proxy address stands (neither forwarded header is believed)' );
	$GLOBALS['faz_test_filters']['faz_trusted_proxy_cidrs'] = $declare_ranges;
	tro_same( faz_resolve_client_ip(), '9.9.9.9', 'ranges declared, header not named: X-Forwarded-For is read, X-GT-Viewer-IP is not' );
	$GLOBALS['faz_test_filters']['faz_proxy_forwarded_header'] = $name_header;
	tro_same( faz_resolve_client_ip(), $visitor, 'ranges + faz_proxy_forwarded_header=HTTP_X_GT_VIEWER_IP: the visitor\'s own address' );
	tro_same( faz_client_ip_identifies_visitor(), true, 'and that address identifies one visitor, so each gets their own throttle window' );
	$_SERVER = array( 'REMOTE_ADDR' => $gt_peer, 'HTTP_X_GT_VIEWER_IP' => ' ' . $visitor . ' ' );
	tro_same( faz_resolve_client_ip(), $visitor, 'a single-address header with padding is read' );
	$_SERVER = array( 'REMOTE_ADDR' => $gt_peer, 'HTTP_X_GT_VIEWER_IP' => '10.0.0.5' );
	tro_same( faz_resolve_client_ip(), $gt_peer, 'a private address in the header is not a visitor: the peer stands' );
	$_SERVER = array( 'REMOTE_ADDR' => $gt_peer, 'HTTP_X_FORWARDED_FOR' => '9.9.9.9' );
	tro_same( faz_resolve_client_ip(), $gt_peer, 'a declared peer that sent no X-GT-Viewer-IP: the peer stands, no fallback to X-Forwarded-For' );
	$_SERVER = array( 'REMOTE_ADDR' => '9.9.9.9', 'HTTP_X_GT_VIEWER_IP' => '1.2.3.4' );
	tro_same( faz_resolve_client_ip(), '9.9.9.9', 'an undeclared peer forging X-GT-Viewer-IP gets nothing' );
	unset( $GLOBALS['faz_test_filters']['faz_trusted_proxy_cidrs'], $GLOBALS['faz_test_filters']['faz_proxy_forwarded_header'] );
	$_SERVER = array();

	echo "\nPassed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
