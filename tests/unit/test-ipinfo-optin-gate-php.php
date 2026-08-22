<?php
/**
 * A visitor's IP must never reach ipinfo.io without an explicit admin opt-in.
 *
 * This integration turned a corner: the geo-ruleset runtime is now live, so
 * ipinfo can be consulted during ORDINARY VISITOR BROWSING to classify an IP as
 * VPN/proxy/Tor — not only from an admin preview, which is what the readme used
 * to describe. That makes the opt-in gate the boundary between "no personal
 * data leaves the site" and "every visitor's IP is sent to a US processor", and
 * it is exactly the boundary a wp.org reviewer and a DPA will look at.
 *
 * The gate itself is three conditions, all of which must hold before a single
 * byte leaves: the integration enabled, an API key stored, and a non-empty IP.
 * What is asserted here is not that the happy path works — it is that each
 * missing condition on its own stops the call.
 *
 * The load-bearing test is the CACHE ORDERING one. lookup() deliberately checks
 * the opt-in BEFORE reading the cache, and the source carries a comment saying
 * why: with a persistent object cache (Redis, Memcached) a revoked opt-in would
 * otherwise keep serving VPN classifications for up to 24 hours, so processing
 * would continue after the administrator withdrew consent to it. Moving the
 * cache read one line up is a plausible-looking optimisation that reintroduces
 * that silently — nothing else in the suite would notice.
 *
 * The class is loaded from source rather than reimplemented, so an edit to the
 * real gate is what these assertions see.
 *
 * Run: php tests/unit/test-ipinfo-optin-gate-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	define( 'DAY_IN_SECONDS', 86400 );

	$GLOBALS['faz_options']   = array();
	$GLOBALS['faz_cache']     = array();
	$GLOBALS['faz_http_calls'] = array();
	$GLOBALS['faz_http_next']  = null;

	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['faz_options'] ) ? $GLOBALS['faz_options'][ $key ] : $default;
	}
	function update_option( $key, $value ) {
		$GLOBALS['faz_options'][ $key ] = $value;
		return true;
	}
	function wp_cache_get( $key, $group = '' ) {
		return isset( $GLOBALS['faz_cache'][ $group ][ $key ] ) ? $GLOBALS['faz_cache'][ $group ][ $key ] : false;
	}
	function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
		$GLOBALS['faz_cache'][ $group ][ $key ] = $value;
		return true;
	}
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt';
	}
	function esc_url_raw( $u ) {
		return $u;
	}
	function sanitize_text_field( $v ) {
		return is_string( $v ) ? trim( $v ) : '';
	}
	function is_wp_error( $t ) {
		return $t instanceof \WP_Error;
	}
	function __( $t, $d = null ) {
		return $t;
	}

	class WP_Error {
		private $m;
		public function __construct( $c = '', $m = '' ) {
			$this->m = $m;
		}
		public function get_error_message() {
			return $this->m;
		}
	}

	/** Every outbound request is recorded, so "no call" is an assertable fact. */
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['faz_http_calls'][] = $url;
		$next                        = $GLOBALS['faz_http_next'];
		return null === $next ? new \WP_Error( 'http', 'no canned response' ) : $next;
	}
	function wp_remote_retrieve_response_code( $r ) {
		return isset( $r['response']['code'] ) ? $r['response']['code'] : 0;
	}
	function wp_remote_retrieve_body( $r ) {
		return isset( $r['body'] ) ? $r['body'] : '';
	}
}

namespace FazCookie\Admin\Modules\Geo_Routing\Includes {
	// Ipinfo_Client calls Secrets::decrypt() unqualified, so it resolves in its
	// OWN namespace, not FazCookie\Includes. Declaring the stub anywhere else
	// fails with class-not-found at runtime. The gate only needs decrypt().
	class Secrets {
		public static function decrypt( $v ) {
			return (string) $v;
		}
		public static function encrypt( $v ) {
			return (string) $v;
		}
	}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-ipinfo-client.php';

	use FazCookie\Admin\Modules\Geo_Routing\Includes\Ipinfo_Client;

	$run    = 0;
	$failed = 0;
	function ip_check( $condition, $label ) {
		global $run, $failed;
		++$run;
		if ( $condition ) {
			echo "  [PASS] {$label}\n";
			return;
		}
		++$failed;
		echo "  [FAIL] {$label}\n";
	}

	/** Fresh state per case: options, cache and the recorded call list. */
	function ip_reset( $optin = false, $key = '' ) {
		$GLOBALS['faz_options']    = array(
			'faz_geo_ipinfo_optin'   => $optin,
			'faz_geo_ipinfo_api_key' => $key,
		);
		$GLOBALS['faz_cache']      = array();
		$GLOBALS['faz_http_calls'] = array();
		$GLOBALS['faz_http_next']  = null;
	}

	function ip_ok_response( $is_vpn ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode_compat( array( 'vpn' => $is_vpn, 'proxy' => false, 'tor' => false, 'hosting' => false ) ),
		);
	}
	function wp_json_encode_compat( $a ) {
		return json_encode( $a );
	}

	echo "== Nothing leaves the site until all three conditions hold ==\n";

	// The load-bearing negative. If this ever goes green-by-accident, every
	// visitor IP is being shipped to a third-party processor with no opt-in.
	ip_reset( false, 'a-real-key' );
	$client = new Ipinfo_Client();
	$r      = $client->lookup( '203.0.113.9' );
	ip_check( 'skip' === $r['source'] && null === $r['vpn'], 'opt-in OFF returns skip' );
	ip_check( array() === $GLOBALS['faz_http_calls'], 'opt-in OFF makes NO outbound request' );

	ip_reset( true, '' );
	$client = new Ipinfo_Client();
	$r      = $client->lookup( '203.0.113.9' );
	ip_check( 'skip' === $r['source'], 'opt-in ON but no API key returns skip' );
	ip_check( array() === $GLOBALS['faz_http_calls'], 'no API key makes NO outbound request' );

	ip_reset( true, 'a-real-key' );
	$client = new Ipinfo_Client();
	$r      = $client->lookup( '' );
	ip_check( array() === $GLOBALS['faz_http_calls'], 'an empty IP makes NO outbound request' );

	echo "== With the opt-in complete, the call is allowed ==\n";

	ip_reset( true, 'a-real-key' );
	$GLOBALS['faz_http_next'] = ip_ok_response( true );
	$client                   = new Ipinfo_Client();
	$r                        = $client->lookup( '203.0.113.9' );
	ip_check( 1 === count( $GLOBALS['faz_http_calls'] ), 'a complete opt-in performs exactly one request' );
	ip_check( true === $r['vpn'], 'a VPN verdict is returned' );
	ip_check(
		false === strpos( $GLOBALS['faz_http_calls'][0], 'a-real-key' ),
		'the API key is not smuggled into the URL (it belongs in the header)'
	);

	echo "== Revoking the opt-in stops processing IMMEDIATELY, not in 24h ==\n";

	// lookup() checks the opt-in BEFORE reading the cache, on purpose. With a
	// persistent object cache the other order keeps answering from previously
	// cached classifications long after the administrator revoked consent —
	// processing that no longer has a basis. This is the assertion that pins
	// the ordering; nothing else in the suite would catch it being swapped.
	ip_reset( true, 'a-real-key' );
	$GLOBALS['faz_http_next'] = ip_ok_response( true );
	$client                   = new Ipinfo_Client();
	$client->lookup( '203.0.113.9' );                       // warm the cache
	ip_check( 1 === count( $GLOBALS['faz_http_calls'] ), 'the first lookup populated the cache' );
	$r = $client->lookup( '203.0.113.9' );
	ip_check( 'cache' === $r['source'], 'a second lookup is served from cache while opt-in holds' );

	$GLOBALS['faz_options']['faz_geo_ipinfo_optin'] = false; // admin revokes
	$r = $client->lookup( '203.0.113.9' );
	ip_check( 'skip' === $r['source'], 'after revocation the WARM CACHE is not consulted' );
	ip_check( null === $r['vpn'], 'after revocation no classification is returned at all' );

	echo "== A failed lookup is not cached, so it cannot freeze a wrong verdict ==\n";

	ip_reset( true, 'a-real-key' );
	$GLOBALS['faz_http_next'] = new \WP_Error( 'http', 'timeout' );
	$client                   = new Ipinfo_Client();
	$r                        = $client->lookup( '203.0.113.9' );
	ip_check( null === $r['vpn'] && 'error' === $r['source'], 'a transport failure reports error, not a verdict' );
	$GLOBALS['faz_http_next'] = ip_ok_response( false );
	$r                        = $client->lookup( '203.0.113.9' );
	ip_check( 2 === count( $GLOBALS['faz_http_calls'] ), 'the failure was not cached — the next request retries' );
	ip_check( false === $r['vpn'], 'the retry returns the real verdict' );

	echo "== The visitor's IP is not stored in the clear ==\n";

	ip_reset( true, 'a-real-key' );
	$GLOBALS['faz_http_next'] = ip_ok_response( true );
	$client                   = new Ipinfo_Client();
	$client->lookup( '203.0.113.9' );
	$keys = array();
	foreach ( $GLOBALS['faz_cache'] as $group => $entries ) {
		$keys = array_merge( $keys, array_keys( $entries ) );
	}
	ip_check( ! empty( $keys ), 'the success was cached' );
	$raw_ip_used = false;
	foreach ( $keys as $k ) {
		if ( false !== strpos( (string) $k, '203.0.113.9' ) ) {
			$raw_ip_used = true;
		}
	}
	ip_check( ! $raw_ip_used, 'the cache key is a hash, never the raw IP' );

	echo "== The gate reads the documented option names ==\n";

	// The readme's External Services entry promises the call happens only after
	// the administrator "configured an ipinfo API key, confirmed the transfer
	// terms, and enabled the integration". If these option names drift, the
	// admin UI and the gate stop agreeing and the disclosure becomes false.
	$src = (string) file_get_contents(
		dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-ipinfo-client.php'
	);
	ip_check( false !== strpos( $src, "'faz_geo_ipinfo_optin'" ), 'the opt-in flag is faz_geo_ipinfo_optin' );
	ip_check( false !== strpos( $src, "'faz_geo_ipinfo_api_key'" ), 'the key option is faz_geo_ipinfo_api_key' );

	// And the ordering, asserted on the source too: a behavioural test can be
	// satisfied by a lucky refactor, but the comment above the gate states the
	// contract, and losing it should be loud.
	$optin_at = strpos( $src, 'is_optin_active()' );
	$cache_at = strpos( $src, 'wp_cache_get(' );
	ip_check(
		false !== $optin_at && false !== $cache_at && $optin_at < $cache_at,
		'the opt-in gate still precedes the cache read in the source'
	);

	echo "\n{$run} checks, {$failed} failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
