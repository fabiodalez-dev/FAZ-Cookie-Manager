<?php
/**
 * The server must reject a consent cookie recorded under another banner/law
 * scope on the SAME request the client does.
 *
 * Scenario: a US visitor closes the CCPA notice without ticking Do Not Sell
 * (cookie: action:yes, consent:no, every category yes, __scope.law:ccpa). The
 * next page is served under the GDPR banner (travel/VPN, GeoIP miss, admin
 * re-targeting). faz_get_valid_consent_cookie() used to check only `rev`, so
 * get_blocked_categories() read `marketing:yes` and shipped the trackers
 * executable while script.js, deferred, invalidated the very same cookie one
 * moment later. These tests load the shipped bootstrap predicate and the real
 * Frontend blocker; only the catalogue/HTTP boundary is stubbed.
 *
 * Run: php tests/unit/test-consent-scope-server-php.php
 */
namespace FazCookie\Includes {
	class Geolocation {
		public static function get_visitor_country() { return 'IT'; }
		public static function has_country_source() { return ! empty( $GLOBALS['scope_test_geo_routing'] ); }
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
	}
}
namespace FazCookie\Admin\Modules\Banners\Includes {
	class Controller {
		public static function get_instance() { return new self(); }
		public function has_country_dependent_banners() { return ! empty( $GLOBALS['scope_test_geo_routing'] ); }
	}
}
namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Category_Controller {
		public static function get_instance() { return new self(); }
		public function get_items() {
			return array_map( function ( $slug ) { return array( 'slug' => $slug ); }, array( 'necessary', 'functional', 'analytics', 'marketing', 'profiling' ) );
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

	$GLOBALS['scope_test_geo_routing'] = true;
	$GLOBALS['scope_test_strict']      = false;

	function plugin_basename( $file ) { return basename( $file ); }
	function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/\\' ) . '/'; }
	function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/faz-cookie-manager/'; }
	function get_option( $name, $default = false ) {
		if ( 'WPLANG' === $name ) { return 'en_US'; }
		if ( 'siteurl' === $name ) { return 'http://example.test'; }
		if ( 'faz_settings' === $name ) {
			return array(
				'general'        => array( 'consent_revision' => 1 ),
				'geolocation'    => array( 'geo_targeting' => ! empty( $GLOBALS['scope_test_geo_routing'] ) ),
				'banner_control' => array( 'per_service_consent' => true, 'per_cookie_consent' => ! empty( $GLOBALS['scope_test_per_cookie'] ) ),
			);
		}
		return $default;
	}
	function is_multisite() { return ! empty( $GLOBALS['scope_test_multisite'] ); }
	function get_current_network_id() { return 1; }
	function get_current_blog_id() { return 1; }
	function is_ssl() { return false; }
	function wp_unslash( $value ) { return $value; }
	function set_url_scheme( $url, $scheme = null ) { return $url; }
	function add_filter() { return true; }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function apply_filters( $tag, $value ) {
		if ( 'faz_strict_scope_fingerprint' === $tag ) { return ! empty( $GLOBALS['scope_test_strict'] ); }
		return $value;
	}
	function absint( $value ) { return abs( (int) $value ); }
	function wp_salt( $scheme = 'auth' ) { return 'unit-test-salt-' . $scheme; }
	function wp_hash( $data, $scheme = 'auth' ) { return hash_hmac( 'md5', (string) $data, wp_salt( $scheme ) ); }

	// Load the shipped bootstrap up to its first add_action(): the cookie
	// helpers under test are defined by then, the autoloader is not started.
	class Faz_Scope_Stop_Bootstrap extends RuntimeException {}
	function add_action() { throw new Faz_Scope_Stop_Bootstrap(); }
	try {
		require dirname( __DIR__, 2 ) . '/faz-cookie-manager.php';
	} catch ( Faz_Scope_Stop_Bootstrap $e ) {
		// Expected.
	}
	require dirname( __DIR__, 2 ) . '/frontend/includes/class-geo-runtime.php';
	require dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require dirname( __DIR__, 2 ) . '/includes/class-withdrawal-path.php';
	require dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;
	use FazCookie\Frontend\Includes\Geo_Runtime;

	$passed = 0;
	$failed = 0;
	function scope_same( $actual, $expected, $label ) {
		global $passed, $failed;
		foreach ( array( &$actual, &$expected ) as &$side ) {
			if ( ! is_array( $side ) ) { continue; }
			if ( array_keys( $side ) === range( 0, count( $side ) - 1 ) ) { sort( $side ); } else { ksort( $side ); }
		}
		unset( $side );
		if ( $actual === $expected ) { $passed++; echo "  PASS {$label}\n"; return; }
		$failed++;
		echo "  FAIL {$label}: " . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) . "\n";
	}

	function fp( $slug, $law ) { return substr( wp_hash( $slug . '|' . $law, 'auth' ), 0, 32 ); }
	function scope( $slug, $law, $strict = false ) {
		return array( 'slug' => $slug, 'law' => $law, 'fingerprint' => fp( $slug, $law ), 'strict' => $strict );
	}

	$grants  = 'consentid:abc,consent:no,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes,profiling:yes,svc.youtube:yes';
	$ccpa_us = $grants . ',__scope.banner:us-2,__scope.law:ccpa,__scope.fp:' . fp( 'us-2', 'ccpa' );
	$gdpr_eu = $grants . ',__scope.banner:eu-1,__scope.law:gdpr,__scope.fp:' . fp( 'eu-1', 'gdpr' );
	$gdpr    = scope( 'eu-1', 'gdpr' );

	echo "faz_get_valid_consent_cookie(): revision AND scope\n";
	scope_same( faz_get_valid_consent_cookie( $ccpa_us, $gdpr ), '', 'a CCPA-scoped record is not a valid cookie under the GDPR banner' );
	scope_same( faz_get_valid_consent_cookie( $gdpr_eu, $gdpr ), $gdpr_eu, 'the same scope keeps the cookie' );
	scope_same( faz_get_valid_consent_cookie( $ccpa_us, null ), $ccpa_us, 'without a scope the check stays revision-only (callers before banner selection)' );
	scope_same( faz_get_valid_consent_cookie( $grants, $gdpr ), $grants, 'a pre-1.14 cookie with no scope keys stands (upgrade case)' );
	scope_same( faz_get_valid_consent_cookie( $grants, scope( 'eu-1', 'gdpr', true ) ), '', 'strict mode rejects a cookie without a fingerprint' );
	scope_same( faz_get_valid_consent_cookie( $gdpr_eu, scope( 'eu-1', 'gdpr', true ) ), $gdpr_eu, 'strict mode keeps a fully matching cookie' );
	$tampered = $grants . ',__scope.banner:eu-1,__scope.law:gdpr,__scope.fp:' . fp( 'us-2', 'ccpa' );
	scope_same( faz_get_valid_consent_cookie( $tampered, $gdpr ), '', 'hand-edited banner/law with a foreign fingerprint is rejected' );
	scope_same( faz_get_valid_consent_cookie( $grants . ',law:ccpa', $gdpr ), '', 'the legacy unprefixed law slot is honoured on read' );
	scope_same( faz_get_valid_consent_cookie( $grants . ',banner:yes,law:no:1700000000', $gdpr ), $grants . ',banner:yes,law:no:1700000000', 'legacy slots holding a category decision are not scope values' );
	$pmp = 'action:auto,consent:no,necessary:yes,functional:no,analytics:no,marketing:no,profiling:no,rev:1,source:pmp';
	scope_same( faz_get_valid_consent_cookie( $pmp, $gdpr ), $pmp, 'the PMP necessary-only state carries no scope and is valid everywhere' );
	scope_same( faz_get_valid_consent_cookie( 'action:yes,consent:yes,marketing:yes,source:pmp', $gdpr ), 'action:yes,consent:yes,marketing:yes,source:pmp', 'a forged source marker without action:auto/consent:no gets no exception (no scope keys = upgrade case) ...' );
	scope_same( faz_get_valid_consent_cookie( 'action:yes,consent:yes,marketing:yes,source:pmp,__scope.law:ccpa', $gdpr ), '', '... and with a scope recorded it is judged on that scope' );
	// The shared comparator the AMP bridge delegates to (strict mode).
	if ( function_exists( 'faz_consent_cookie_scope_changed' ) ) {
		$amp_scope = array( 'slug' => 'eu-1', 'law' => 'gdpr', 'fingerprint' => fp( 'eu-1', 'gdpr' ), 'strict' => true );
		scope_same( faz_consent_cookie_scope_changed( faz_parse_consent_cookie( $gdpr_eu . ',rev:1' ), $amp_scope ), false, 'AMP-style strict context accepts a cookie with all three keys matching' );
		scope_same( faz_consent_cookie_scope_changed( array( '__scope.fp' => fp( 'eu-1', 'gdpr' ), '__scope.law' => 'gdpr' ), $amp_scope ), true, 'AMP-style strict context requires every scope key, as the bridge did before' );
	} else {
		scope_same( 'missing', 'defined', 'faz_consent_cookie_scope_changed() is the one scope predicate shared by every server surface' );
	}

	echo "Frontend::get_blocked_categories() / get_service_consent() under a changed scope\n";
	$class   = new ReflectionClass( Frontend::class );
	$blocked = $class->getMethod( 'get_blocked_categories' );
	$blocked->setAccessible( true );
	$service = $class->getMethod( 'get_service_consent' );
	$service->setAccessible( true );
	$memo = new ReflectionProperty( Geo_Runtime::class, 'ruleset_memo' );
	$memo->setAccessible( true );
	$memo->setValue( null, array( 'IT' => false ) ); // no runtime ruleset: the banner law decides.

	function fresh_frontend( $class, $slug, $law ) {
		$frontend = $class->newInstanceWithoutConstructor();
		foreach ( array( 'geo_bootstrap_active_cache' => false ) as $name => $value ) {
			$property = new ReflectionProperty( Frontend::class, $name );
			$property->setAccessible( true );
			$property->setValue( $frontend, $value );
		}
		$banner = new ReflectionProperty( Frontend::class, 'banner' );
		$banner->setAccessible( true );
		$banner->setValue( $frontend, new class( $slug, $law ) {
			private $slug;
			private $law;
			public function __construct( $slug, $law ) { $this->slug = $slug; $this->law = $law; }
			public function get_slug() { return $this->slug; }
			public function get_law() { return $this->law; }
		} );
		return $frontend;
	}
	$optional = array( 'functional', 'analytics', 'marketing', 'profiling' );
	$_SERVER  = array();

	$_COOKIE = array( 'fazcookie-consent' => $ccpa_us );
	$frontend = fresh_frontend( $class, 'eu-1', 'gdpr' );
	scope_same( $blocked->invoke( $frontend ), $optional, 'GDPR banner + CCPA-scoped all-yes cookie: every optional category is blocked server-side' );
	scope_same( $service->invoke( $frontend ), array(), 'and its svc.* grants are not honoured either' );

	$_COOKIE = array( 'fazcookie-consent' => $gdpr_eu );
	$frontend = fresh_frontend( $class, 'eu-1', 'gdpr' );
	scope_same( $blocked->invoke( $frontend ), array(), 'the same cookie recorded under this banner keeps its grants' );
	scope_same( $service->invoke( $frontend ), array( 'youtube' => 'yes' ), 'including the per-service grant' );

	$_COOKIE = array( 'fazcookie-consent' => $ccpa_us );
	$frontend = fresh_frontend( $class, 'us-2', 'ccpa' );
	$enforced = $class->getMethod( 'get_enforcement_blocked_categories' );
	$enforced->setAccessible( true );
	scope_same( $enforced->invoke( $frontend ), array(), 'back under the US banner the CCPA record is honoured again' );
	// The render parks the sale/sharing category under a CCPA banner whatever
	// the record says, and script.js releases it from that record.
	scope_same( $blocked->invoke( $frontend ), array( 'marketing' ), 'and the render parks sale/share for script.js to release from it' );

	$_COOKIE = array( 'fazcookie-consent' => $grants );
	$frontend = fresh_frontend( $class, 'eu-1', 'gdpr' );
	scope_same( $blocked->invoke( $frontend ), array(), 'a pre-1.14 cookie without scope keys is honoured (upgrade case)' );

	$GLOBALS['scope_test_strict'] = true;
	$frontend = fresh_frontend( $class, 'eu-1', 'gdpr' );
	scope_same( $blocked->invoke( $frontend ), $optional, 'faz_strict_scope_fingerprint makes the server re-prompt a fingerprint-less cookie, like the client' );
	$GLOBALS['scope_test_strict'] = false;

	// The gate mirrors the client: with no country-dependent output the runtime
	// never compares scopes (_geoRouting false), so neither must the server.
	$GLOBALS['scope_test_geo_routing'] = false;
	$_COOKIE = array( 'fazcookie-consent' => $ccpa_us );
	$frontend = fresh_frontend( $class, 'eu-1', 'gdpr' );
	scope_same( $blocked->invoke( $frontend ), array(), 'single-banner install (no geo routing): scope is not compared, as in script.js' );
	$GLOBALS['scope_test_geo_routing'] = true;

	// No cookie at all under the GDPR banner still blocks everything optional.
	$_COOKIE = array();
	$frontend = fresh_frontend( $class, 'eu-1', 'gdpr' );
	scope_same( $blocked->invoke( $frontend ), $optional, 'first visit under GDPR blocks every optional category (unchanged)' );

	// get_service_cookie_decisions() — the per-cookie (ck.*) decisions the
	// shredder enforces — read the cookie without the scope, so a ck.* grant
	// recorded under another banner/law kept allowing that cookie on the very
	// request where every other server layer had rejected the record.
	echo "Frontend::get_service_cookie_decisions() under a changed scope\n";
	$GLOBALS['scope_test_per_cookie'] = true;
	$decisions = $class->getMethod( 'get_service_cookie_decisions' );
	$decisions->setAccessible( true );
	$ck_grant = ',ck.youtube.VISITOR_INFO1_LIVE:yes';
	$_COOKIE  = array( 'fazcookie-consent' => $ccpa_us . $ck_grant );
	$frontend = fresh_frontend( $class, 'eu-1', 'gdpr' );
	$verdict  = $decisions->invoke( $frontend );
	scope_same( isset( $verdict['VISITOR_INFO1_LIVE'] ) && in_array( 'yes', $verdict['VISITOR_INFO1_LIVE'], true ), false, 'GDPR banner + CCPA-scoped ck.* grant: the per-cookie grant is not honoured' );
	$_COOKIE  = array( 'fazcookie-consent' => $gdpr_eu . $ck_grant );
	$frontend = fresh_frontend( $class, 'eu-1', 'gdpr' );
	$verdict  = $decisions->invoke( $frontend );
	scope_same( isset( $verdict['VISITOR_INFO1_LIVE'] ) ? $verdict['VISITOR_INFO1_LIVE'] : array(), array( 'yes' ), 'the same grant recorded under this banner is honoured' );
	$GLOBALS['scope_test_per_cookie'] = false;

	// Multisite naming and the geo scope comparator must agree: a valid
	// site-bound cookie is not spuriously discarded by the server's geo check.
	$GLOBALS['scope_test_multisite'] = true;
	$own = $grants . ',__scope.banner:eu-1,__scope.law:gdpr,__scope.fp:' . faz_scope_fingerprint( 'eu-1', 'gdpr' );
	$_COOKIE = array( faz_consent_cookie_name() => $own );
	scope_same( $blocked->invoke( fresh_frontend( $class, 'eu-1', 'gdpr' ) ), array(), 'geo-routed multisite honours a valid cookie with its network/blog fingerprint' );
	$dnsmpi = $class->getMethod( 'request_has_dnsmpi' );
	$dnsmpi->setAccessible( true );
	$_COOKIE = array( faz_consent_cookie_name( 'fazcookie-dnsmpi' ) => '1' );
	scope_same( $dnsmpi->invoke( fresh_frontend( $class, 'eu-1', 'gdpr' ) ), true, 'the server honours this site’s namespaced Do Not Sell request' );
	$_COOKIE = array( 'fazcookie-dnsmpi-n1-b2' => '1' );
	scope_same( $dnsmpi->invoke( fresh_frontend( $class, 'eu-1', 'gdpr' ) ), false, 'another site’s Do Not Sell state is not imported' );
	$GLOBALS['scope_test_multisite'] = false;
	$forged_pmp = 'source:pmp,action:auto,consent:no,necessary:yes,marketing:yes,__scope.law:ccpa';
	scope_same( faz_get_valid_consent_cookie( $forged_pmp, $gdpr ), '', 'a PMP marker with optional grants cannot bypass a law mismatch' );

	echo "consent-scope-server: {$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
