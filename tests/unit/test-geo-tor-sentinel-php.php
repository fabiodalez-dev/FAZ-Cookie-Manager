<?php
/**
 * Standalone unit tests: Cloudflare's `T1` sentinel is an anonymity signal, not
 * a missing answer.
 *
 * Cloudflare writes `CF-IPCountry: T1` when the connection arrives through Tor
 * (`XX` is "no country data"). Both resolvers used to fold `T1` into "no
 * answer" and then geolocate the address they had — the Tor EXIT RELAY — so a
 * visitor the edge had just flagged as anonymised was routed by the relay's
 * jurisdiction: an exit in Japan or California handed them an opt-out regime
 * with analytics and marketing granted before any action. The plugin's own
 * rule (spec 001 US-05, fallback-gdpr-most-protective _meta.notes) is that
 * VPN/Tor/unknown visitors receive the most-protective fallback.
 *
 * This file drives the REAL Geolocation and the REAL Geo_Detector with the
 * real helpers file (Cloudflare peer ranges, forwarded-address resolution), a
 * PHP GeoIP extension stub that resolves EVERY address to Japan — so the exit
 * relay always has a country for the wrong answer to come from — and asserts
 * that `T1` from a trusted Cloudflare peer yields no country in one layer and
 * `vpn = true` in the other, which Ruleset_Resolver turns into the fallback.
 *
 * Run:
 *   php tests/unit/test-geo-tor-sentinel-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	$faz_tmp = sys_get_temp_dir() . '/faz-geo-tor-' . getmypid() . '/';
	@mkdir( $faz_tmp, 0777, true );

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $faz_tmp );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}
	if ( ! defined( 'FAZ_VERSION' ) ) {
		define( 'FAZ_VERSION', 'test' );
	}

	$GLOBALS['__faz_filters']    = array();
	$GLOBALS['__faz_options']    = array();
	$GLOBALS['__faz_transients'] = array();
	$GLOBALS['__faz_cache']      = array();

	function apply_filters( $tag, $value ) {
		return isset( $GLOBALS['__faz_filters'][ $tag ] ) ? $GLOBALS['__faz_filters'][ $tag ] : $value;
	}
	function has_filter( $tag ) {
		return array_key_exists( $tag, $GLOBALS['__faz_filters'] );
	}
	function faz_set_filter( $tag, $value ) {
		$GLOBALS['__faz_filters'][ $tag ] = $value;
	}
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['__faz_options'] ) ? $GLOBALS['__faz_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__faz_options'][ $name ] = $value;
		return true;
	}
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
	function wp_upload_dir() {
		return array( 'basedir' => rtrim( ABSPATH, '/' ), 'baseurl' => 'http://example.test' );
	}
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['__faz_transients'] ) ? $GLOBALS['__faz_transients'][ $key ] : false;
	}
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['__faz_transients'][ $key ] = $value;
		return true;
	}
	function delete_transient( $key ) {
		unset( $GLOBALS['__faz_transients'][ $key ] );
		return true;
	}
	function wp_cache_get( $key, $group = '' ) {
		return isset( $GLOBALS['__faz_cache'][ $group ][ $key ] ) ? $GLOBALS['__faz_cache'][ $group ][ $key ] : false;
	}
	function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
		$GLOBALS['__faz_cache'][ $group ][ $key ] = $value;
		return true;
	}
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
	}
	function sanitize_text_field( $v ) {
		return is_string( $v ) ? trim( $v ) : '';
	}
	function wp_unslash( $v ) {
		return $v;
	}
	function is_admin() {
		return false;
	}
	function wp_doing_cron() {
		return false;
	}
	function wp_doing_ajax() {
		return false;
	}
	function wp_remote_get( $url, $args = array() ) {
		return new WP_Error( 'stub', 'no HTTP' );
	}
	function wp_remote_retrieve_response_code( $r ) {
		return 0;
	}
	function wp_remote_retrieve_body( $r ) {
		return '';
	}
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	class WP_Error {
		public function __construct( $code = '', $message = '' ) {}
		public function get_error_message() {
			return '';
		}
	}
	// The relay always has a country. Japan is deliberate: APPI is an opt-out
	// regime in the catalogue, so a wrong answer here is a wrong REGIME, not
	// merely a wrong flag.
	function geoip_country_code_by_name( $ip ) {
		return 'JP';
	}

	require_once __DIR__ . '/../../includes/class-utils.php';
	require_once __DIR__ . '/../../includes/class-mmdb-reader.php';
	require_once __DIR__ . '/../../includes/class-geolocation.php';
	require_once __DIR__ . '/../../admin/modules/geo-routing/includes/class-secrets.php';
	require_once __DIR__ . '/../../admin/modules/geo-routing/includes/class-ipinfo-client.php';
	require_once __DIR__ . '/../../admin/modules/geo-routing/includes/class-geo-detector.php';
	require_once __DIR__ . '/../../admin/modules/geo-routing/includes/class-ruleset-resolver.php';
}

namespace FazCookie\Admin\Modules\Geo_Routing\Includes {
	/**
	 * An ipinfo client that records whether it was asked anything.
	 *
	 * The Tor exit relay is an address Cloudflare has already classified; the
	 * detector must not forward it to a third party to learn what it knows.
	 */
	class Faz_Recording_Ipinfo extends Ipinfo_Client {
		public $calls = array();

		public function lookup( $ip ) {
			$this->calls[] = $ip;
			return array( 'vpn' => null, 'source' => 'skip' );
		}
	}
}

namespace {

	use FazCookie\Includes\Geolocation;
	use FazCookie\Admin\Modules\Geo_Routing\Includes\Geo_Detector;
	use FazCookie\Admin\Modules\Geo_Routing\Includes\Faz_Recording_Ipinfo;
	use FazCookie\Admin\Modules\Geo_Routing\Includes\Ruleset_Resolver;

	$run = $pass = $fail = 0;
	function faz_is( $actual, $expected, $label ) {
		global $run, $pass, $fail;
		$run++;
		if ( $actual === $expected ) {
			$pass++;
			echo "  \033[32m✓\033[0m $label\n";
			return;
		}
		$fail++;
		echo "  \033[31m✗\033[0m $label\n";
		echo "      expected: " . var_export( $expected, true ) . "\n";
		echo "      actual:   " . var_export( $actual, true ) . "\n";
	}

	const FAZ_CF_EDGE  = '162.158.42.42';  // one of Cloudflare's shipped ranges
	const FAZ_NOT_EDGE = '203.0.113.99';   // TEST-NET-3: nobody's edge
	const FAZ_FALLBACK = 'fallback-gdpr-most-protective';

	/**
	 * A request from a Cloudflare edge with the given CF-IPCountry.
	 *
	 * @param string $country Header value.
	 * @param string $peer    REMOTE_ADDR.
	 */
	function faz_request( $country, $peer = FAZ_CF_EDGE ) {
		$GLOBALS['__faz_filters']    = array();
		$GLOBALS['__faz_transients'] = array();
		$GLOBALS['__faz_cache']      = array();
		$_SERVER                     = array(
			'REMOTE_ADDR'       => $peer,
			'HTTP_CF_IPCOUNTRY' => $country,
		);
		faz_set_filter( 'faz_trust_cf_ipcountry_header', true );
		Geolocation::reset_runtime_cache();
	}

	/**
	 * What the catalogue would do with a detector result: the real resolver,
	 * a country index in which the relay's country is an opt-out regime.
	 */
	function faz_resolve( array $geo ) {
		return Ruleset_Resolver::resolve(
			$geo['country'],
			$geo['region'],
			$geo['vpn'],
			array(),
			array( 'JP' => 'appi-japan', 'DE' => 'gdpr-strict' ),
			array(),
			FAZ_FALLBACK
		);
	}

	echo "\n\033[1mGeolocation — the banner-selection layer\033[0m\n";

	faz_request( 'T1' );
	faz_is( Geolocation::get_visitor_country(), '', 'T1 from a Cloudflare peer: no country — the relay is NOT geolocated in its place' );
	faz_is( Geolocation::get_country(), '', 'get_country() agrees: no source may answer for an address the edge called a relay' );
	faz_is( $GLOBALS['__faz_transients'], array(), 'and nothing is memoised for the relay address' );
	faz_is( Geolocation::get_visitor_region(), '', 'nor is a region resolved for it' );

	// The fixture contract survives: faz_visitor_country keeps the last word.
	faz_request( 'T1' );
	faz_set_filter( 'faz_visitor_country', 'IT' );
	faz_is( Geolocation::get_visitor_country(), 'IT', 'faz_visitor_country still has the last word over the sentinel (fixtures, trusted integrations)' );

	// Controls: the rule is about T1 and about the peer, nothing else moved.
	faz_request( 'DE' );
	faz_is( Geolocation::get_visitor_country(), 'DE', 'control: a real code from the same peer still resolves' );

	faz_request( 'XX' );
	faz_is( Geolocation::get_visitor_country(), 'JP', 'control: XX ("no country data") is still re-resolved from the address, as before' );

	faz_request( 'T1', FAZ_NOT_EDGE );
	faz_is( Geolocation::get_visitor_country(), 'JP', 'T1 from a peer that is not Cloudflare is a value the client chose: ignored, the address is resolved' );

	faz_request( 'T1' );
	$GLOBALS['__faz_filters'] = array(); // opt-in withdrawn
	Geolocation::reset_runtime_cache();
	faz_is( Geolocation::get_visitor_country(), 'JP', 'without the faz_trust_cf_ipcountry_header opt-in the header is not read at all, T1 included' );

	echo "\n\033[1mGeo_Detector — the ruleset layer\033[0m\n";

	faz_request( 'T1' );
	$ipinfo   = new Faz_Recording_Ipinfo();
	$detector = new Geo_Detector( $ipinfo );
	$tor      = $detector->detect();
	faz_is( $tor['vpn'], true, 'T1 from a Cloudflare peer is an anonymity signal: vpn = true, the same gate the paid ipinfo lookup feeds' );
	faz_is( $tor['country'], 'XX', 'and the country is the unknown sentinel, not the relay\'s' );
	faz_is( $tor['region'], '', 'with no region' );
	faz_is( $tor['source'], 'cf_anonymiser', 'attributed to the edge, so the admin preview can say why' );
	faz_is( $ipinfo->calls, array(), 'the relay address is not sent to ipinfo: the edge has already answered' );
	faz_is( $GLOBALS['__faz_cache'], array(), 'a header-derived verdict is not cached under the address' );
	faz_is( faz_resolve( $tor ), FAZ_FALLBACK, 'end to end: the resolver routes the Tor visitor to the most-protective fallback' );

	// The admin's explicit override is a fixture and still wins, as it does
	// over every other signal.
	faz_request( 'T1' );
	faz_set_filter( 'faz_geo_admin_override_country', 'IT' );
	faz_is( $detector->detect()['country'], 'IT', 'control: faz_geo_admin_override_country precedes the sentinel, like every other signal' );

	faz_request( 'DE' );
	$de = $detector->detect();
	faz_is( $de['country'], 'DE', 'control: a real code resolves through the header' );
	faz_is( $de['vpn'], null, 'and carries no anonymity verdict of its own' );
	faz_is( faz_resolve( $de ), 'gdpr-strict', 'and reaches its own ruleset' );

	faz_request( 'XX' );
	$xx = $detector->detect();
	faz_is( $xx['country'], 'JP', 'control: XX is still re-resolved from the address, as before' );
	faz_is( $xx['vpn'], null, 'without an anonymity verdict' );

	faz_request( 'T1', FAZ_NOT_EDGE );
	$spoofed = $detector->detect();
	faz_is( $spoofed['vpn'], null, 'T1 from a peer that is not Cloudflare carries no verdict: the client cannot force the fallback on itself and everybody behind its NAT' );
	faz_is( $spoofed['country'], 'JP', 'and the address is resolved as before' );

	@rmdir( rtrim( ABSPATH, '/' ) );

	echo "\n--\n";
	echo "Tests:  $run\n";
	echo "Passed: $pass\n";
	echo "Failed: $fail\n\n";
	if ( $fail > 0 ) {
		echo "\033[31mFAIL\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
