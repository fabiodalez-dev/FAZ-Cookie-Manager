<?php
/**
 * A render that follows one visitor's consent state must never reach a
 * shared page cache.
 *
 * get_blocked_categories() reads the consent cookie, Sec-GPC and the
 * Do-Not-Sell cookie. Only Cache Compatibility Mode and the strict-shell
 * bootstrap made it visitor-invariant, Cache Compatibility pauses itself while
 * Geo-Targeting is on (the default), and send_geo_cache_headers() vetoed the
 * cache only for COUNTRY variance — none on a site with no country source. So
 * a returning visitor who had accepted marketing, on a page-cache MISS,
 * produced a page with the trackers executable and the cache served it to
 * every later visitor, first visits included.
 *
 * This suite drives the public seams — send_geo_cache_headers() on
 * `send_headers`, then get_blocked_categories() / get_service_consent() for the
 * render, then the FlyingPress bridge — with header() captured through a
 * namespaced stub. The shipped bootstrap supplies the cookie helpers; only the
 * catalogue and the HTTP boundary are stubbed.
 *
 * Run: php tests/unit/test-visitor-state-cache-veto-php.php
 */
namespace FazCookie\Frontend {
	function header( $line, $replace = true ) {
		$GLOBALS['vs_headers'][] = $line;
	}
	function headers_sent() {
		return ! empty( $GLOBALS['vs_headers_sent'] );
	}
	function setcookie( $name, $value = '', $expires = 0 ) {
		if ( '' === $value && $expires < 0 ) {
			$GLOBALS['vs_deleted'][ $name ] = true;
		}
		return true;
	}
}
namespace FazCookie\Includes {
	class Geolocation {
		public static function get_visitor_country() { return ''; }
		public static function has_country_source() { return false; }
	}
	class Known_Providers {
		public static function get_all() {
			return array(
				'youtube' => array(
					'category' => 'marketing',
					'label'    => 'YouTube',
					'patterns' => array( 'youtube.com/embed' ),
					'cookies'  => array( 'VISITOR_INFO1_LIVE' ),
				),
			);
		}
		public static function get_cookie_map() {
			return array( 'VISITOR_INFO1_LIVE' => 'marketing' );
		}
	}
}
namespace FazCookie\Admin\Modules\Banners\Includes {
	class Controller {
		public static function get_instance() { return new self(); }
		public function has_country_dependent_banners() { return false; }
	}
}
namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Category_Controller {
		public static function get_instance() { return new self(); }
		public function get_items() {
			return array_map( function ( $slug ) { return array( 'slug' => $slug ); }, array( 'necessary', 'analytics', 'marketing' ) );
		}
	}
	class Cookie_Categories {
		private $data;
		public function __construct( $data ) { $this->data = $data; }
		public function get_slug() { return $this->data['slug']; }
		public function get_sell_personal_data() { return 'marketing' === $this->get_slug(); }
		public function get_share_personal_data() { return 'marketing' === $this->get_slug(); }
	}
}
namespace {
	define( 'WPINC', 'wp-includes' );
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['vs_headers']      = array();
	$GLOBALS['vs_headers_sent'] = false;
	$GLOBALS['vs_actions']      = array();
	$GLOBALS['vs_filters']      = array();
	// The reported configuration: Cache Compatibility saved ON, Geo-Targeting
	// ON (the default), no country source — the mode is paused and nothing
	// country-related vetoes the cache.
	$GLOBALS['vs_settings'] = array(
		'general'        => array( 'consent_revision' => 1 ),
		'geolocation'    => array( 'geo_targeting' => true ),
		'banner_control' => array( 'cache_compatibility' => true, 'per_service_consent' => true ),
	);

	function plugin_basename( $file ) { return basename( $file ); }
	function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/\\' ) . '/'; }
	function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/faz-cookie-manager/'; }
	function get_option( $name, $default = false ) {
		if ( 'WPLANG' === $name ) { return 'en_US'; }
		if ( 'siteurl' === $name ) { return 'http://example.test'; }
		if ( 'faz_settings' === $name ) { return $GLOBALS['vs_settings']; }
		return $default;
	}
	function is_ssl() { return false; }
	// The admin catalogue's cookie → category map, as the shredder reads it.
	function get_transient( $key ) {
		return 'faz_server_cookie_category_map_v2' === $key
			? array( 'probe_ga' => 'analytics', 'probe_ad' => 'marketing', 'VISITOR_INFO1_LIVE' => 'marketing' )
			: false;
	}
	function set_transient() { return true; }
	function wp_cache_get() { return false; }
	function wp_cache_set() { return true; }
	function wp_unslash( $value ) { return $value; }
	function set_url_scheme( $url, $scheme = null ) { return $url; }
	function add_filter() { return true; }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function apply_filters( $tag, $value ) {
		if ( isset( $GLOBALS['vs_filters'][ $tag ] ) ) { return $GLOBALS['vs_filters'][ $tag ]; }
		return $value;
	}
	function do_action( $tag, ...$args ) { $GLOBALS['vs_actions'][] = $tag; }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_salt( $scheme = 'auth' ) { return 'unit-test-salt-' . $scheme; }
	function wp_hash( $data, $scheme = 'auth' ) { return hash_hmac( 'md5', (string) $data, wp_salt( $scheme ) ); }
	function is_admin() { return false; }
	function wp_doing_ajax() { return false; }
	function wp_doing_cron() { return false; }
	function faz_disable_banner() { return false; }
	function faz_is_front_end_request() { return true; }
	function faz_is_bot() { return false; }

	class Faz_VS_Stop_Bootstrap extends RuntimeException {}
	function add_action() { throw new Faz_VS_Stop_Bootstrap(); }
	try {
		require dirname( __DIR__, 2 ) . '/faz-cookie-manager.php';
	} catch ( Faz_VS_Stop_Bootstrap $e ) {
		// Expected: the cookie helpers are defined by then.
	}
	require dirname( __DIR__, 2 ) . '/frontend/includes/class-geo-runtime.php';
	require dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require dirname( __DIR__, 2 ) . '/includes/class-withdrawal-path.php';
	require dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	class Faz_VS_Frontend extends Frontend {
		protected function is_banner_disabled_by_settings() { return false; }
		protected function is_blocking_disabled_for_page() { return false; }
	}

	$passed = 0;
	$failed = 0;
	function vs_check( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) { $passed++; echo "  PASS {$label}\n"; return; }
		$failed++;
		echo "  FAIL {$label}\n";
	}
	function vs_sorted( $list ) { sort( $list ); return $list; }
	function vs_vetoed() {
		foreach ( $GLOBALS['vs_headers'] as $line ) {
			if ( 0 === stripos( $line, 'Cache-Control:' ) && false !== strpos( $line, 'no-store' ) ) {
				return true;
			}
		}
		return false;
	}

	/** A fresh request: new Frontend, served banner, cookies, header state. */
	function vs_request( $law, array $cookies, array $server = array(), $headers_sent = false ) {
		$GLOBALS['vs_headers']      = array();
		$GLOBALS['vs_actions']      = array();
		$GLOBALS['vs_headers_sent'] = $headers_sent;
		$_COOKIE = $cookies;
		$_SERVER = $server;
		$GLOBALS['vs_deleted']      = array();
		$frontend = ( new ReflectionClass( Faz_VS_Frontend::class ) )->newInstanceWithoutConstructor();
		// A banner was loaded on init: the visitor has a consent context.
		$template = new ReflectionProperty( Frontend::class, 'template' );
		$template->setAccessible( true );
		$template->setValue( $frontend, array( 'html' => '<div></div>' ) );
		$memo = new ReflectionProperty( Frontend::class, 'geo_bootstrap_active_cache' );
		$memo->setAccessible( true );
		$memo->setValue( $frontend, false );
		$banner = new ReflectionProperty( Frontend::class, 'banner' );
		$banner->setAccessible( true );
		$banner->setValue( $frontend, new class( $law ) {
			private $law;
			public function __construct( $law ) { $this->law = $law; }
			public function get_slug() { return 'b-' . $this->law; }
			public function get_law() { return $this->law; }
		} );
		// send_headers runs before any output, exactly as in WP::main().
		$frontend->send_geo_cache_headers();
		return $frontend;
	}
	function vs_blocked( $frontend ) {
		$m = new ReflectionMethod( Frontend::class, 'get_blocked_categories' );
		$m->setAccessible( true );
		return vs_sorted( $m->invoke( $frontend ) );
	}
	function vs_verdict( $frontend ) {
		$p = new ReflectionProperty( Frontend::class, 'visitor_state_veto' );
		$p->setAccessible( true );
		return $p->getValue( $frontend );
	}
	function vs_services( $frontend ) {
		$m = new ReflectionMethod( Frontend::class, 'get_service_consent' );
		$m->setAccessible( true );
		return $m->invoke( $frontend );
	}

	$memo = new ReflectionProperty( \FazCookie\Frontend\Includes\Geo_Runtime::class, 'ruleset_memo' );
	$memo->setAccessible( true );
	$memo->setValue( null, array( '' => false ) ); // No ruleset: the banner law decides.

	$accept_all = 'consentid:abc,consent:yes,action:yes,necessary:yes,analytics:yes,marketing:yes,svc.youtube:yes,rev:1';
	$reject_all = 'consentid:abc,consent:no,action:yes,necessary:yes,analytics:no,marketing:no,rev:1';
	$ccpa_optout = 'consentid:abc,consent:no,action:yes,necessary:yes,analytics:yes,marketing:no,rev:1';
	$optional   = array( 'analytics', 'marketing' );

	echo "Renders identical to a first visit stay cacheable\n";
	$fe = vs_request( 'gdpr', array() );
	vs_check( ! vs_vetoed() && array() === $GLOBALS['vs_actions'], 'first visit: no veto' );
	vs_check( $optional === vs_blocked( $fe ), 'first visit under GDPR blocks every optional category' );
	vs_check( true === $fe->flying_press_is_cacheable( true ), 'first visit: FlyingPress may cache it' );

	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $reject_all ) );
	vs_check( ! vs_vetoed(), 'GDPR reject-all cookie renders the baseline: no veto' );
	vs_check( $optional === vs_blocked( $fe ), 'and blocks what a first visit blocks' );

	$fe = vs_request( 'gdpr', array(), array( 'HTTP_SEC_GPC' => '1' ) );
	vs_check( ! vs_vetoed(), 'Sec-GPC under an opt-in law changes nothing (sell/share already blocked): no veto' );
	vs_check( ! defined( 'DONOTCACHEPAGE' ), 'no page-cache constant was defined by any of the above' );

	echo "A consented visitor's MISS is never stored\n";
	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $accept_all ) );
	vs_check( vs_vetoed(), 'accept-all cookie: no-store sent on send_headers, before output' );
	vs_check( in_array( 'litespeed_control_set_nocache', $GLOBALS['vs_actions'], true ), 'LiteSpeed Cache told through its action' );
	vs_check( defined( 'DONOTCACHEPAGE' ) && defined( 'LSCACHE_NO_CACHE' ), 'DONOTCACHEPAGE and LSCACHE_NO_CACHE defined' );
	vs_check( array() === vs_blocked( $fe ), 'with the veto in force the visitor gets their consented render' );
	vs_check( array( 'youtube' => 'yes' ) === vs_services( $fe ), 'including the per-service grant' );
	vs_check( false === $fe->flying_press_is_cacheable( true ), 'FlyingPress (which reads neither header nor constant) is refused too' );

	echo "When the veto cannot be installed, the render is the shareable one\n";
	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $accept_all ), array(), true );
	vs_check( ! vs_vetoed(), 'headers already sent: nothing could be sent' );
	vs_check( $optional === vs_blocked( $fe ), 'so the trackers stay blocked server-side (script.js releases them from the cookie)' );
	vs_check( array() === vs_services( $fe ), 'and the svc.* YES is left to the client as well' );
	vs_check( true === $fe->flying_press_is_cacheable( true ), 'that copy is safe to share, so FlyingPress may keep it' );

	$GLOBALS['vs_filters']['faz_veto_cache_for_visitor_state'] = false;
	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $accept_all ) );
	vs_check( ! vs_vetoed(), 'faz_veto_cache_for_visitor_state=false: no veto' );
	vs_check( $optional === vs_blocked( $fe ), 'and the shareable render instead' );
	unset( $GLOBALS['vs_filters']['faz_veto_cache_for_visitor_state'] );

	echo "Opt-out signals under CCPA\n";
	// The first-visit render parks the sale/sharing category (script.js
	// releases it when no signal is present), so a cached copy replayed to a
	// GPC or Do Not Sell visitor never runs it. That render is also what a
	// signalled visitor gets, so their page no longer needs the veto.
	$fe = vs_request( 'ccpa', array() );
	vs_check( ! vs_vetoed() && array( 'marketing' ) === vs_blocked( $fe ), 'CCPA first visit: sale/share parked for the runtime to release, nothing vetoed' );
	$enforced = new ReflectionMethod( Frontend::class, 'get_enforcement_blocked_categories' );
	$enforced->setAccessible( true );
	vs_check( array() === $enforced->invoke( $fe ), 'and the visitor\'s own state still blocks nothing (CCPA: no opt-out, no block)' );

	$fe = vs_request( 'ccpa', array(), array( 'HTTP_SEC_GPC' => '1' ) );
	vs_check( ! vs_vetoed() && 'baseline' === vs_verdict( $fe ), 'Sec-GPC under CCPA renders the baseline: cacheable' );
	vs_check( array( 'marketing' ) === vs_blocked( $fe ), 'and the GPC visitor still gets sell/share blocked' );

	$fe = vs_request( 'ccpa', array( 'fazcookie-dnsmpi' => '1' ) );
	vs_check( ! vs_vetoed() && array( 'marketing' ) === vs_blocked( $fe ), 'Do Not Sell cookie under CCPA: the baseline, sell/share blocked' );

	$fe = vs_request( 'ccpa', array( 'fazcookie-consent' => $ccpa_optout ) );
	vs_check( ! vs_vetoed() && array( 'marketing' ) === vs_blocked( $fe ), 'a recorded CCPA opt-out: the baseline, sell/share blocked' );

	// A CCPA visitor who granted sale/sharing renders the same parked page and
	// script.js releases it from their cookie; the shredder keeps their cookies.
	// (No svc.* entry: per-service decisions are a variance of their own.)
	$ccpa_grant = 'consentid:abc,consent:yes,action:yes,necessary:yes,analytics:yes,marketing:yes,rev:1';
	$fe = vs_request( 'ccpa', array( 'fazcookie-consent' => $ccpa_grant, 'probe_ad' => '1', 'probe_ga' => '1' ) );
	vs_check( ! vs_vetoed() && array( 'marketing' ) === vs_blocked( $fe ), 'a CCPA grant renders the parked baseline: cacheable' );
	$fe->shred_non_consented_cookies();
	vs_check( array() === $GLOBALS['vs_deleted'], 'and the shredder deletes none of that visitor\'s cookies (the parking is a render-time over-block)' );

	$fe = vs_request( 'ccpa', array( 'probe_ad' => '1' ) );
	$fe->shred_non_consented_cookies();
	vs_check( ! isset( $GLOBALS['vs_deleted']['probe_ad'] ), 'CCPA first visit: the shredder leaves a sale/share cookie alone (no opt-out yet)' );

	// faz_ccpa_park_sell_share_by_default=false: the previous render, and the
	// veto keeping the signalled visitor's own page out of the cache.
	$GLOBALS['vs_filters']['faz_ccpa_park_sell_share_by_default'] = false;
	$fe = vs_request( 'ccpa', array() );
	vs_check( ! vs_vetoed() && array() === vs_blocked( $fe ), 'filter off: CCPA first visit blocks nothing, as before' );
	$fe = vs_request( 'ccpa', array(), array( 'HTTP_SEC_GPC' => '1' ) );
	vs_check( vs_vetoed() && array( 'marketing' ) === vs_blocked( $fe ), 'filter off: Sec-GPC under CCPA is vetoed, as before' );
	$fe = vs_request( 'ccpa', array( 'fazcookie-dnsmpi' => '1' ) );
	vs_check( vs_vetoed(), 'filter off: Do Not Sell cookie under CCPA is vetoed, as before' );
	unset( $GLOBALS['vs_filters']['faz_ccpa_park_sell_share_by_default'] );

	$fe = vs_request( 'ccpa', array(), array( 'HTTP_SEC_GPC' => '1' ), true );
	vs_check( array( 'marketing' ) === vs_blocked( $fe ), 'veto impossible: the GPC opt-out is still enforced (a denial is never dropped)' );

	$fe = vs_request( 'ccpa', array( 'fazcookie-consent' => $ccpa_optout ), array(), true );
	vs_check( array( 'marketing' ) === vs_blocked( $fe ), 'veto impossible: a CCPA opt-out recorded in the cookie is still enforced' );

	echo "Cache Compatibility active: already invariant\n";
	$GLOBALS['vs_settings']['geolocation']['geo_targeting'] = false;
	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $accept_all ) );
	vs_check( ! vs_vetoed(), 'no veto: the render ignores visitor state' );
	vs_check( $optional === vs_blocked( $fe ), 'every optional category blocked, as before' );

	echo "The shareable render never deletes what the visitor consented to\n";
	// The shredder runs on template_redirect, after send_headers decided the
	// verdict. It must read the visitor's REAL state: the shareable union is a
	// render-time over-block that script.js undoes on the client, but a cookie
	// deleted on the server is gone, so applying the union there destroyed a
	// consented visitor's `_ga` on every page of a site whose veto was off.
	$GLOBALS['vs_settings']['geolocation']['geo_targeting'] = true;
	$GLOBALS['vs_filters']['faz_veto_cache_for_visitor_state'] = false;
	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $accept_all, 'probe_ga' => '1', 'probe_ad' => '1' ) );
	vs_check( $optional === vs_blocked( $fe ), 'fallback: the render is still the shareable one' );
	$fe->shred_non_consented_cookies();
	vs_check( array() === $GLOBALS['vs_deleted'] && isset( $_COOKIE['probe_ga'] ), 'fallback: the shredder deletes none of an accept-all visitor\'s cookies' );
	// The outgoing guard is the opposite case: it decides what this possibly
	// cached RESPONSE carries. A cache that keeps headers would replay a
	// server-side `Set-Cookie: probe_ga=…` to every later visitor, so on the
	// shareable render it is stripped and script.js sets it after consent.
	$out = $fe->filter_outgoing_cookie_header_lines( array( 'Set-Cookie: probe_ga=GA1.1.2; path=/', 'Set-Cookie: wordpress_test_cookie=WP; path=/' ) );
	vs_check( array( 'Set-Cookie: wordpress_test_cookie=WP; path=/' ) === $out['set_cookie_headers'], 'fallback: the Set-Cookie guard still strips a server-side Set-Cookie of an accepted category from the cacheable response' );

	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $reject_all, 'probe_ga' => '1' ) );
	$fe->shred_non_consented_cookies();
	vs_check( isset( $GLOBALS['vs_deleted']['probe_ga'] ), 'a rejected category\'s cookie is still deleted' );

	$partial = 'consentid:abc,consent:yes,action:yes,necessary:yes,analytics:yes,marketing:no,svc.youtube:yes,rev:1';
	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $partial, 'probe_ga' => '1', 'probe_ad' => '1', 'VISITOR_INFO1_LIVE' => '1' ) );
	vs_check( 'fallback' === vs_verdict( $fe ), 'partial consent with the veto off: fallback' );
	$fe->shred_non_consented_cookies();
	vs_check( isset( $GLOBALS['vs_deleted']['probe_ad'] ), 'fallback: the denied category\'s cookie is deleted' );
	vs_check( ! isset( $GLOBALS['vs_deleted']['probe_ga'] ), 'fallback: the granted category\'s cookie survives' );
	vs_check( ! isset( $GLOBALS['vs_deleted']['VISITOR_INFO1_LIVE'] ), 'fallback: a per-service YES inside a denied category survives' );
	$out = $fe->filter_outgoing_cookie_header_lines( array( 'Set-Cookie: VISITOR_INFO1_LIVE=x; path=/', 'Set-Cookie: probe_ga=y; path=/' ) );
	vs_check( array() === $out['set_cookie_headers'], 'fallback: the guard strips both the per-service and the category grant from the response' );

	// A per-cookie ck.* grant is read straight from the cookie, not through
	// get_service_consent(): it must not reopen the Set-Cookie either, while the
	// shredder still keeps the cookie in the visitor's browser.
	$GLOBALS['vs_settings']['banner_control']['per_cookie_consent'] = true;
	$per_cookie = 'consentid:abc,consent:yes,action:yes,necessary:yes,analytics:yes,marketing:no,ck.youtube.VISITOR_INFO1_LIVE:yes,rev:1';
	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $per_cookie, 'VISITOR_INFO1_LIVE' => '1' ) );
	$fe->shred_non_consented_cookies();
	vs_check( ! isset( $GLOBALS['vs_deleted']['VISITOR_INFO1_LIVE'] ), 'fallback: a per-cookie YES keeps the cookie in the browser' );
	$out = $fe->filter_outgoing_cookie_header_lines( array( 'Set-Cookie: VISITOR_INFO1_LIVE=x; path=/' ) );
	vs_check( array() === $out['set_cookie_headers'], 'fallback: and the guard still strips its Set-Cookie from the response' );
	unset( $GLOBALS['vs_settings']['banner_control']['per_cookie_consent'] );

	$GLOBALS['vs_filters']['faz_veto_cache_for_visitor_state'] = true;
	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $accept_all ) );
	$out = $fe->filter_outgoing_cookie_header_lines( array( 'Set-Cookie: probe_ga=GA1.1.2; path=/' ) );
	vs_check( 'vetoed' === vs_verdict( $fe ) && 1 === count( $out['set_cookie_headers'] ), 'vetoed (uncacheable) response: the accepted category\'s Set-Cookie passes' );
	unset( $GLOBALS['vs_filters']['faz_veto_cache_for_visitor_state'] );

	$fe = vs_request( 'ccpa', array( 'fazcookie-consent' => $ccpa_optout, 'probe_ad' => '1', 'probe_ga' => '1' ), array(), true );
	$fe->shred_non_consented_cookies();
	vs_check( isset( $GLOBALS['vs_deleted']['probe_ad'] ) && ! isset( $GLOBALS['vs_deleted']['probe_ga'] ), 'veto impossible under CCPA: the opt-out is still enforced on the cookies, nothing else' );

	echo "visitor-state-cache-veto: {$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
