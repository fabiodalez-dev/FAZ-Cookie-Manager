<?php
/**
 * Every FAZ page-cache veto must speak to the caches that read headers only.
 *
 * The veto used to be three response headers (Cache-Control no-store, Pragma,
 * X-LiteSpeed-Cache-Control) plus DONOTCACHEPAGE. A CDN "Cache Everything"
 * rule, a Fastly/Akamai edge reading CDN-Cache-Control, or a Varnish layer
 * reading Surrogate-Control never sees the constant, and an edge rule that
 * overrides Cache-Control does not override the targeted headers — so the
 * response a veto was meant to keep private could still be stored.
 *
 * This suite drives the real veto sites (Frontend crawler veto, Frontend
 * country veto constants, AMP crawler veto) with header() captured through a
 * namespaced stub and asserts the full set: `private`, CDN-Cache-Control,
 * Cloudflare-CDN-Cache-Control, Surrogate-Control, and LSCACHE_NO_CACHE.
 *
 * Run: php tests/unit/test-cache-veto-headers-php.php
 */
namespace FazCookie\Frontend {
	function header( $line, $replace = true ) {
		$GLOBALS['veto_headers'][] = $line;
	}
	function headers_sent() {
		return ! empty( $GLOBALS['veto_headers_sent'] );
	}
}
namespace FazCookie\Includes {
	class Geolocation {
		public static function has_country_source() { return false; }
		public static function get_visitor_country() { return ''; }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['veto_headers']      = array();
	$GLOBALS['veto_headers_sent'] = false;
	$GLOBALS['veto_actions']      = array();
	$GLOBALS['veto_settings']     = array();

	function do_action( $tag, ...$args ) { $GLOBALS['veto_actions'][] = $tag; }
	function add_action() { return true; }
	function add_filter() { return true; }
	function apply_filters( $tag, $value ) { return $value; }
	function get_option( $name, $default = false ) {
		return 'faz_settings' === $name ? $GLOBALS['veto_settings'] : $default;
	}
	function faz_is_bot() { return true; }

	require dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
	require dirname( __DIR__, 2 ) . '/frontend/class-amp-consent.php';

	use FazCookie\Frontend\Frontend;
	use FazCookie\Frontend\AMP_Consent;

	$passed = 0;
	$failed = 0;
	function veto_check( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) { $passed++; echo "  PASS {$label}\n"; return; }
		$failed++;
		echo "  FAIL {$label}\n";
	}
	function veto_header( $name ) {
		$found = array();
		foreach ( $GLOBALS['veto_headers'] as $line ) {
			if ( 0 === stripos( $line, $name . ':' ) ) {
				$found[] = trim( substr( $line, strlen( $name ) + 1 ) );
			}
		}
		return $found;
	}
	function veto_assert_full_set( $label ) {
		$cc = veto_header( 'Cache-Control' );
		veto_check( 1 === count( $cc ) && false !== strpos( $cc[0], 'no-store' ) && false !== strpos( $cc[0], 'private' ), "{$label}: Cache-Control is private + no-store" );
		veto_check( array( 'no-store' ) === veto_header( 'CDN-Cache-Control' ), "{$label}: CDN-Cache-Control: no-store" );
		veto_check( array( 'no-store' ) === veto_header( 'Cloudflare-CDN-Cache-Control' ), "{$label}: Cloudflare-CDN-Cache-Control: no-store" );
		veto_check( array( 'no-store' ) === veto_header( 'Surrogate-Control' ), "{$label}: Surrogate-Control: no-store" );
		veto_check( array( 'no-cache' ) === veto_header( 'X-LiteSpeed-Cache-Control' ), "{$label}: X-LiteSpeed-Cache-Control kept" );
		veto_check( in_array( 'litespeed_control_set_nocache', $GLOBALS['veto_actions'], true ), "{$label}: LiteSpeed told through its action" );
	}

	echo "Frontend crawler veto\n";
	$frontend = ( new ReflectionClass( Frontend::class ) )->newInstanceWithoutConstructor();
	$bot_veto = new ReflectionMethod( Frontend::class, 'veto_page_cache_for_bot_variance' );
	$bot_veto->setAccessible( true );
	veto_check( true === $bot_veto->invoke( $frontend ), 'veto installed while headers are pending' );
	veto_assert_full_set( 'crawler veto' );
	veto_check( in_array( 'User-Agent', veto_header( 'Vary' ), true ), 'crawler veto: Vary: User-Agent kept' );
	veto_check( defined( 'LSCACHE_NO_CACHE' ) && LSCACHE_NO_CACHE, 'LSCACHE_NO_CACHE defined for LiteSpeed Cache' );
	veto_check( defined( 'DONOTCACHEPAGE' ) && defined( 'DONOTCACHEOBJECT' ) && defined( 'DONOTCACHEDB' ), 'DONOTCACHE* constants defined' );

	$GLOBALS['veto_headers']      = array();
	$GLOBALS['veto_actions']      = array();
	$GLOBALS['veto_headers_sent'] = true;
	$frontend = ( new ReflectionClass( Frontend::class ) )->newInstanceWithoutConstructor();
	veto_check( false === $bot_veto->invoke( $frontend ), 'headers already sent: the veto reports itself absent (the caller renders the shareable variant)' );
	veto_check( array() === $GLOBALS['veto_headers'], 'and no header is attempted' );
	veto_check( in_array( 'litespeed_control_set_nocache', $GLOBALS['veto_actions'], true ), 'LiteSpeed is still told: its action needs no header' );
	$GLOBALS['veto_headers_sent'] = false;

	echo "AMP crawler veto\n";
	$GLOBALS['veto_headers'] = array();
	$GLOBALS['veto_actions'] = array();
	$amp      = ( new ReflectionClass( AMP_Consent::class ) )->newInstanceWithoutConstructor();
	$amp_veto = new ReflectionMethod( AMP_Consent::class, 'bot_suppression_applies' );
	$amp_veto->setAccessible( true );
	veto_check( true === $amp_veto->invoke( $amp ), 'AMP veto installed' );
	veto_assert_full_set( 'AMP crawler veto' );

	echo "Shared header set\n";
	$set = \FazCookie\Includes\Cache_Compatibility::NO_STORE_HEADERS;
	foreach ( $set as $name => $value ) {
		veto_check( false === strpos( $value, 'public' ) && ( false !== strpos( $value, 'no-store' ) || false !== strpos( $value, 'no-cache' ) ), "{$name} only ever forbids storage" );
	}

	echo "cache-veto-headers: {$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
