<?php
/**
 * Standalone unit tests: a visitor address never reaches wp_options as a key
 * anyone can reverse.
 *
 * Geolocation memoised the country and the region of an address in transients
 * keyed on md5( $ip ). Without a persistent object cache those rows live in
 * wp_options as `_transient_faz_geo_<md5>`, and md5 over the IPv4 space is
 * enumerated in seconds: a backup or a staging copy resolved back to (address,
 * country, time). The sibling layers — Geo_Detector and Ipinfo_Client — already
 * keyed their caches on sha256( ip | Y-m | wp_salt ), so one rule was written
 * two ways. This file loads the REAL helper file and the REAL Geolocation and
 * asserts on what is actually written, through stubs that keep every write.
 *
 * Run:
 *   php tests/unit/test-geo-ip-cache-key-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	$faz_tmp = sys_get_temp_dir() . '/faz-geo-key-' . getmypid() . '/';
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
	$GLOBALS['__faz_salt']       = 'salt-one';

	function apply_filters( $tag, $value ) {
		return isset( $GLOBALS['__faz_filters'][ $tag ] ) ? $GLOBALS['__faz_filters'][ $tag ] : $value;
	}
	function has_filter( $tag ) {
		return array_key_exists( $tag, $GLOBALS['__faz_filters'] );
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
	// The stubs KEEP every write: a stub that discarded them would let any
	// assertion about what reaches the database pass on nothing.
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
		return $GLOBALS['__faz_salt'] . '-' . $scheme;
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
	// A country source that costs nothing to stand up: the PHP GeoIP extension,
	// answering the same country for every address. It is what makes
	// get_country() reach set_transient() at all.
	function geoip_country_code_by_name( $ip ) {
		return 'IT';
	}

	// The helper file is loaded for REAL: the shared key function lives there,
	// and a stub of it would be a second copy of the thing under test.
	require_once __DIR__ . '/../../includes/class-utils.php';
	require_once __DIR__ . '/../../includes/class-mmdb-reader.php';
	require_once __DIR__ . '/../../includes/class-geolocation.php';
	require_once __DIR__ . '/../../admin/modules/geo-routing/includes/class-secrets.php';
	require_once __DIR__ . '/../../admin/modules/geo-routing/includes/class-ipinfo-client.php';
	require_once __DIR__ . '/../../admin/modules/geo-routing/includes/class-geo-detector.php';

	use FazCookie\Includes\Geolocation;
	use FazCookie\Admin\Modules\Geo_Routing\Includes\Geo_Detector;
	use FazCookie\Admin\Modules\Geo_Routing\Includes\Ipinfo_Client;

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

	/**
	 * The key the module's own rule prescribes (Constitution VIII / NFR-03):
	 * sha256 over address | month | nonce salt. Computed here, independently of
	 * any helper, so the assertions below hold the code to the rule rather than
	 * to whatever the code happens to do.
	 */
	function faz_expected_key( $ip ) {
		return hash( 'sha256', $ip . '|' . gmdate( 'Y-m' ) . '|' . wp_salt( 'nonce' ) );
	}

	function faz_keys_with_prefix( $prefix ) {
		return array_values(
			array_filter(
				array_keys( $GLOBALS['__faz_transients'] ),
				function ( $k ) use ( $prefix ) {
					return 0 === strpos( $k, $prefix );
				}
			)
		);
	}

	$ip = '203.0.113.7';

	echo "\n\033[1mThe shared helper\033[0m\n";

	faz_is( function_exists( 'faz_ip_cache_key' ), true, 'one helper, faz_ip_cache_key(), is what every layer keys an address with' );
	$helper = function ( $address ) {
		return function_exists( 'faz_ip_cache_key' ) ? faz_ip_cache_key( $address ) : '';
	};
	faz_is( $helper( $ip ), faz_expected_key( $ip ), 'the helper is sha256( ip | Y-m | wp_salt(nonce) ) — the rule Geo_Detector already followed' );
	faz_is( strlen( $helper( $ip ) ), 64, 'and is 64 hex characters, like the keys already in the object cache' );
	$GLOBALS['__faz_salt'] = 'salt-two';
	$with_other_salt = $helper( $ip );
	$GLOBALS['__faz_salt'] = 'salt-one';
	faz_is( $with_other_salt !== $helper( $ip ), true, 'a different site salt gives a different key: the address cannot be enumerated without it' );

	echo "\n\033[1mGeolocation::get_country() — the country memo\033[0m\n";

	$GLOBALS['__faz_transients'] = array();
	Geolocation::reset_runtime_cache();
	faz_is( Geolocation::get_country( $ip ), 'IT', 'premise: the extension resolves the address, so the memo is written' );
	$country_keys = faz_keys_with_prefix( 'faz_geo_' );
	faz_is( count( $country_keys ), 1, 'premise: exactly one country transient was written' );
	faz_is( in_array( 'faz_geo_' . md5( $ip ), $country_keys, true ), false, 'the country transient is NOT keyed on md5( ip ): that key is reversed in seconds' );
	faz_is( $country_keys, array( 'faz_geo_' . faz_expected_key( $ip ) ), 'the country transient is keyed on the salted, month-rotated digest' );

	// The memo must still be a memo: the second call answers from the transient.
	Geolocation::reset_runtime_cache();
	$GLOBALS['__faz_transients'][ 'faz_geo_' . faz_expected_key( $ip ) ] = 'FR';
	faz_is( Geolocation::get_country( $ip ), 'FR', 'the salted key is also the one READ back, so the memo keeps working across requests' );

	echo "\n\033[1mGeolocation::get_region() — the region memo, written even when empty\033[0m\n";

	$GLOBALS['__faz_transients'] = array();
	Geolocation::reset_runtime_cache();
	faz_is( Geolocation::get_region( $ip ), '', 'premise: no City database, so the region is empty' );
	$region_keys = faz_keys_with_prefix( 'faz_georeg_' );
	faz_is( count( $region_keys ), 1, 'premise: the empty result is memoised too (10 minutes), so every visitor address is written' );
	faz_is( in_array( 'faz_georeg_' . md5( $ip ), $region_keys, true ), false, 'the region transient is NOT keyed on md5( ip )' );
	faz_is( $region_keys, array( 'faz_georeg_' . faz_expected_key( $ip ) ), 'the region transient is keyed on the salted, month-rotated digest' );

	echo "\n\033[1mThe sibling layers use the SAME helper\033[0m\n";

	// Geo_Detector keeps an object-cache copy of the lookup; its key must be the
	// helper's, so that one address is one digest in every layer.
	$GLOBALS['__faz_cache'] = array();
	$GLOBALS['__faz_transients'] = array();
	Geolocation::reset_runtime_cache();
	$_SERVER = array( 'REMOTE_ADDR' => $ip );
	$detector = new Geo_Detector();
	$result   = $detector->detect();
	faz_is( $result['country'], 'IT', 'premise: the detector resolved the address through Geolocation' );
	$detector_keys = isset( $GLOBALS['__faz_cache'][ Geo_Detector::CACHE_GROUP ] ) ? array_keys( $GLOBALS['__faz_cache'][ Geo_Detector::CACHE_GROUP ] ) : array();
	faz_is( $detector_keys, array( faz_expected_key( $ip ) ), 'Geo_Detector caches under the same digest' );

	$ipinfo_hash = new \ReflectionMethod( Ipinfo_Client::class, 'hash_ip' );
	$ipinfo_hash->setAccessible( true );
	faz_is( $ipinfo_hash->invoke( new Ipinfo_Client(), $ip ), faz_expected_key( $ip ), 'Ipinfo_Client keys its cache with the same digest' );

	$detector_hash = new \ReflectionMethod( Geo_Detector::class, 'hash_ip' );
	$detector_hash->setAccessible( true );
	faz_is( $detector_hash->invoke( $detector, $ip ), faz_expected_key( $ip ), 'Geo_Detector::hash_ip() is the helper, not a second formula' );

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
