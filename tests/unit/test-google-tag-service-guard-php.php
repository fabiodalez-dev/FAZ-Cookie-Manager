<?php
/**
 * Standalone unit tests: the built-in Google tag honours a per-service "no"
 * where the server cannot read it.
 *
 * Subsystem: google-tag-service-guard-php
 *
 * Under Cache Compatibility Mode and the jurisdiction shell the HTML is the
 * same for every visitor, so Frontend::get_service_consent() returns an empty
 * map on purpose and the choice is enforced by the runtime. The built-in tag
 * is printed at wp_head:1, before the runtime exists: with Advanced Consent
 * Mode (and the #385 container opt-in) it went out live for a visitor whose
 * cookie says `svc.google-tag-manager:no` or `svc.google-analytics:no`, and a
 * script the parser has run cannot be taken back.
 *
 * Pinned here:
 *   1. Google_Tag::render() with `guard_cookie`: every tag that would be live
 *      is a client-side guard (no static loader left in the markup), parked
 *      tags are unchanged, an unsafe cookie name disables the guard.
 *   2. Frontend::print_google_tag() asks for the guard exactly when per-service
 *      consent is on AND svc.* is client-only (cache compatibility or the
 *      jurisdiction shell), and never otherwise.
 *   3. The output buffer leaves the guard alone (own-handle id), in the very
 *      state where it would otherwise re-type it.
 *
 * What the guard does in a browser is tests/unit/js/google-tag-service-guard.test.mjs.
 *
 * Run: php tests/unit/test-google-tag-service-guard-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	class Known_Providers {
		public static function get_all() {
			return array(
				'google-tag-manager' => array( 'category' => 'analytics' ),
				'google-analytics'   => array( 'category' => 'analytics' ),
				'google-ads'         => array( 'category' => 'marketing' ),
			);
		}
		public static function get_cookie_map() {
			return array();
		}
		public static function get_pattern_map() {
			return array();
		}
	}

	// The one predicate Frontend::is_cache_compatibility_enabled() calls.
	class Cache_Compatibility {
		public static function is_active( $settings = null ) {
			return is_array( $settings ) && ! empty( $settings['banner_control']['cache_compatibility'] );
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	function wp_strip_all_tags( $str ) {
		return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
	}
	function sanitize_text_field( $str ) {
		return trim( wp_strip_all_tags( preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) ) );
	}
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
	function esc_url( $value ) {
		return str_replace( '&', '&amp;', (string) $value );
	}
	function apply_filters( $tag, $value ) {
		return $value;
	}
	function wp_parse_url( $url, $component = -1 ) {
		$url = (string) $url;
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'placeholder:' . $url;
		}
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); // phpcs:ignore
	}
	function get_transient( $key ) {
		return false;
	}
	function set_transient( $key, $value, $ttl = 0 ) {
		return true;
	}
	function absint( $v ) {
		return abs( (int) $v );
	}
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
	function faz_sanitize_bool( $v ) {
		return is_string( $v ) ? in_array( strtolower( $v ), array( '1', 'true', 'yes', 'on' ), true ) : (bool) $v;
	}
	function faz_is_machine_readable_request() {
		return false;
	}
	function faz_disable_banner() {
		return false;
	}
	function faz_consent_cookie_name( $name = 'fazcookie-consent' ) {
		return $name . '-n1-b7';
	}

	class FazTest_GuardGcm {
		public $ids = array( 'G-ABCD1234', 'AW-123456789', 'GTM-ABC123' );
		public $gtm = true;
		public function is_advanced_mode() {
			return true;
		}
		public function is_advanced_gtm_mode() {
			return (bool) $this->gtm;
		}
		public function get_google_tag_ids() {
			return $this->ids;
		}
		public function get( $key ) {
			return null;
		}
	}
	class FazTest_GuardSettings {
		public function get( $group = '', $key = '' ) {
			return 'status' === $key ? true : array();
		}
	}

	$root = dirname( __DIR__, 2 );
	require_once $root . '/frontend/includes/class-embed-inventory.php';
	require_once $root . '/frontend/class-frontend.php';
	require_once $root . '/includes/class-google-tag.php';

	use FazCookie\Frontend\Frontend;
	use FazCookie\Includes\Google_Tag;

	$run  = 0;
	$fail = 0;
	function g_ok( $cond, $label ) {
		global $run, $fail;
		++$run;
		if ( $cond ) {
			echo "  PASS {$label}\n";
			return;
		}
		++$fail;
		echo "  FAIL {$label}\n";
	}
	function g_tags( $html ) {
		preg_match_all( '/<script\b([^>]*)>/', $html, $m );
		return $m[1];
	}

	// =====================================================================
	// 1. Google_Tag::render()
	// =====================================================================
	echo "Google_Tag::render() — guard_cookie\n";
	$ids  = array( 'G-ABCD1234', 'AW-123456789', 'GTM-ABC123', 'GTM-DEF456' );
	$live = array( 'gtag_unblocked' => true, 'gtm_unblocked' => true );
	$html = Google_Tag::render( $ids, $live + array( 'guard_cookie' => 'fazcookie-consent-n1-b7', 'guard_id' => 'faz-cookie-manager-google-tag' ) );
	$tags = g_tags( $html );
	g_ok( 4 === count( $tags ), 'one guard per gtag group and per container (' . count( $tags ) . ' scripts)' );
	g_ok( 0 === preg_match( '/<script[^>]*\ssrc=/', $html ), 'no static loader is left for the parser to run' );
	g_ok( 4 === substr_count( $html, 'data-faz-google-tag-guard="' ), 'every script is marked as a guard' );
	g_ok( 0 === preg_match( '/<script[^>]*\sdata-faz-google-tag="/', $html ), 'and none claims to be the tag itself' );
	g_ok( 4 === substr_count( $html, '"fazcookie-consent-n1-b7"' ), 'each guard reads the site\'s consent cookie' );
	foreach ( array( 'google-analytics', 'google-ads', 'google-tag-manager' ) as $svc ) {
		g_ok( false !== strpos( $html, 'data-faz-google-tag-guard="' . $svc . '"' ) && false !== strpos( $html, ',"' . $svc . '",' ), 'guard for ' . $svc . ' checks its own service' );
	}
	g_ok( false !== strpos( $html, '":no,"' ), 'the guard looks for an explicit svc.<id>:no' );
	g_ok( false !== strpos( $html, '"https://www.googletagmanager.com/gtag/js?id=G-ABCD1234"' ) && false !== strpos( $html, '"https://www.googletagmanager.com/gtag/js?id=AW-123456789"' ), 'the loader URLs are data for the guard' );
	preg_match_all( '/<script id="([^"]+)"/', $html, $idm );
	g_ok( 4 === count( array_unique( $idm[1] ) ), 'guard ids are unique' );
	foreach ( $idm[1] as $gid ) {
		g_ok( 1 === preg_match( '/^faz-cookie-manager-google-tag-[a-z0-9-]+-js-before$/', $gid ), 'own-handle id: ' . $gid );
	}
	g_ok( 4 === substr_count( $html, '</script>' ), 'no value closes a script early' );

	$named = Google_Tag::render( $ids, $live + array( 'guard_cookie' => 'c', 'data_layer' => 'my$dl' ) );
	g_ok( false !== strpos( $named, 'gtag/js?id=G-ABCD1234&l=my%24dl"' ), 'a custom dataLayer name reaches the guarded loader unescaped (the shape own_tag_provider() expects)' );
	g_ok( 0 === preg_match( '/<script id=/', $named ), 'no guard_id: no id' );

	$parked = Google_Tag::render( $ids, array( 'guard_cookie' => 'fazcookie-consent' ) );
	g_ok( $parked === Google_Tag::render( $ids ), 'nothing live: the guard changes nothing, the tags are parked as before' );
	$mixed = Google_Tag::render( $ids, array( 'gtag_unblocked' => true, 'guard_cookie' => 'fazcookie-consent' ) );
	g_ok( 2 === substr_count( $mixed, 'data-faz-google-tag-guard="' ) && 2 === substr_count( $mixed, '<script type="text/plain" data-faz-category="analytics" data-faz-service="google-tag-manager"' ), 'gtag exemption only: gtag groups guarded, containers parked server-side' );
	g_ok( Google_Tag::render( $ids, $live ) === Google_Tag::render( $ids, $live + array( 'guard_cookie' => 'x";alert(1);//' ) ), 'an unsafe cookie name disables the guard instead of reaching the script' );
	g_ok( Google_Tag::render( $ids, $live ) === Google_Tag::render( $ids, $live + array( 'guard_cookie' => '' ) ), 'no cookie name: the markup is the one it always was' );
	$hinted = Google_Tag::render( $ids, $live + array( 'guard_cookie' => 'c', 'hints' => ' data-no-optimize="1"' ) );
	g_ok( 4 === substr_count( $hinted, 'data-no-optimize="1"' ), 'optimiser hints are on every guard' );

	// =====================================================================
	// 2. Frontend::print_google_tag()
	// =====================================================================
	function g_frontend( array $banner_control, $geo_shell = false, ?array $service_consent = null ) {
		$rc  = new ReflectionClass( Frontend::class );
		$fe  = $rc->newInstanceWithoutConstructor();
		$set = array(
			'gcm_settings'               => new FazTest_GuardGcm(),
			'settings'                   => new FazTest_GuardSettings(),
			'template'                   => array( 'html' => '<div></div>' ),
			'plugin_name'                => 'faz-cookie-manager',
			'gcm_default_emitted'        => true,
			'settings_option_cache'      => array( 'banner_control' => $banner_control ),
			'geo_bootstrap_active_cache' => $geo_shell,
			'blocked_categories_cache'   => array( 'analytics', 'marketing' ),
			'provider_map_cache'         => array(
				'googletagmanager.com/gtm.js'  => 'analytics',
				'googletagmanager.com/gtag/js' => 'analytics',
				'GTM-ABC123'                   => 'analytics',
				'gtag('                        => 'analytics',
			),
			'pattern_service_cache'      => array(
				'googletagmanager.com/gtm.js'  => array( 'google-tag-manager' ),
				'googletagmanager.com/gtag/js' => array( 'google-analytics' ),
			),
			'whitelist_cache'            => array(),
			'provider_match_meta_cache'  => null,
			'service_match_meta_cache'   => null,
		);
		if ( null !== $service_consent ) {
			$set['service_consent_cache'] = $service_consent;
		}
		foreach ( $set as $prop => $value ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $fe, $value );
		}
		return $fe;
	}
	function g_print( $fe ) {
		ob_start();
		$fe->print_google_tag();
		return ob_get_clean();
	}
	function g_guards( $html ) {
		return substr_count( $html, 'data-faz-google-tag-guard="' );
	}

	echo "\nprint_google_tag() — Cache Compatibility Mode + per-service consent\n";
	$out = g_print( g_frontend( array( 'cache_compatibility' => true, 'per_service_consent' => true ) ) );
	g_ok( 3 === g_guards( $out ), 'GA4, Ads and the container are all guarded' );
	g_ok( 0 === preg_match( '/<script[^>]*\ssrc=/', $out ), 'no gtag.js loader is printed live' );
	g_ok( 0 === preg_match( '/<script data-faz-google-tag="google-tag-manager"/', $out ), 'the container loader is not printed live' );
	g_ok( 3 === substr_count( $out, '"fazcookie-consent-n1-b7"' ), 'the guard reads the site-bound cookie name' );
	g_ok( 3 === substr_count( $out, '<script id="faz-cookie-manager-google-tag-' ), 'with an own-handle id' );

	echo "\nprint_google_tag() — jurisdiction shell + per-service consent\n";
	$out = g_print( g_frontend( array( 'per_service_consent' => true ), true ) );
	g_ok( 3 === g_guards( $out ) && 0 === preg_match( '/<script[^>]*\ssrc=/', $out ), 'guarded exactly like Cache Compatibility Mode' );

	echo "\nprint_google_tag() — every other state keeps its markup\n";
	$out = g_print( g_frontend( array( 'cache_compatibility' => true ) ) );
	g_ok( 0 === g_guards( $out ) && 2 === preg_match_all( '/<script async src=/', $out ), 'cache compatibility without per-service consent: live tags, no guard (there is no svc.* to honour)' );
	$out = g_print( g_frontend( array( 'per_service_consent' => true ), false, array() ) );
	g_ok( 0 === g_guards( $out ) && 2 === preg_match_all( '/<script async src=/', $out ), 'per-service consent on a server that reads the cookie: live tags, no guard' );
	$out = g_print( g_frontend( array( 'per_service_consent' => true ), false, array( 'google-tag-manager' => 'no' ) ) );
	g_ok( 0 === g_guards( $out ) && 1 === preg_match( '/<script type="text\/plain" data-faz-category="analytics" data-faz-service="google-tag-manager"/', $out ), '…where a denial of the container still parks it server-side' );
	$fe = g_frontend( array( 'cache_compatibility' => true, 'per_service_consent' => true ) );
	$rp = new ReflectionProperty( Frontend::class, 'gcm_default_emitted' );
	$rp->setAccessible( true );
	$rp->setValue( $fe, false );
	$out = g_print( $fe );
	g_ok( 0 === g_guards( $out ) && 5 === substr_count( $out, 'type="text/plain"' ), 'no consent default in this response: everything parked, nothing to guard' );

	// =====================================================================
	// 3. The output buffer
	// =====================================================================
	echo "\nthe output buffer leaves the guard alone\n";
	$fe    = g_frontend( array( 'cache_compatibility' => true, 'per_service_consent' => true ) );
	$guard = g_print( $fe );
	$m     = new ReflectionMethod( Frontend::class, 'process_output_buffer' );
	$m->setAccessible( true );
	$page  = $m->invoke( $fe, '<html><head>' . $guard . '</head><body></body></html>' );
	g_ok( 3 === g_guards( $guard ) && false !== strpos( $page, $guard ), 'byte for byte, with analytics and marketing blocked and a `gtag(` / container-ID rule in the map' );

	echo "\n";
	if ( 0 === $fail ) {
		echo "ALL PASS — {$run}/{$run}\n";
		exit( 0 );
	}
	echo "{$fail} FAILED — " . ( $run - $fail ) . "/{$run} passed\n";
	exit( 1 );
}
