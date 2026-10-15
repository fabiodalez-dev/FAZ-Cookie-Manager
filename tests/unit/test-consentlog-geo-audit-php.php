<?php
/**
 * The jurisdiction columns of a consent row must record what was ENFORCED.
 *
 * Migration_V2 added country_at_consent / region_at_consent /
 * ruleset_id_at_consent so every consent is traceable to the jurisdictional
 * context that applied to it (spec 001-geo-routing-next, Constitution V). The
 * frontend resolves that context through Geolocation::get_visitor_country()
 * (which honours `faz_visitor_country` and the proxy-trust settings) and
 * Geo_Runtime::resolve_for_country(), which hands the country to the routing
 * module as an authoritative override so banner selection and ruleset
 * resolution can never disagree.
 *
 * The consent log resolved the same columns through a SECOND chain — the bare
 * detector, with no override — which disagrees with the first whenever the
 * country comes from the filter, from the admin override, or from a legacy
 * proxy header: a US-CA visitor served ccpa-california was logged as
 * country 'XX' under 'fallback-gdpr-most-protective'. 'XX' itself was stored
 * as a country, although the data model says an unknown country is NULL.
 *
 * These cases drive the REAL Controller against doubles of the two chains and
 * pin that the row follows the enforcement chain, that an unknown country is
 * not written as 'XX', that Cache Compatibility Mode (under which the frontend
 * enforces no ruleset) leaves the ruleset column empty, and that the detector
 * chain is no longer consulted.
 *
 * Run: php tests/unit/test-consentlog-geo-audit-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	abstract class Rest_Controller {}

	/** Double of the enforcement chain's country/region source. */
	class Geolocation {
		public static $country = '';
		public static $region  = '';
		public static $calls   = 0;
		public static function get_visitor_country() { self::$calls++; return self::$country; }
		public static function get_visitor_region() { return self::$region; }
	}

	/** Double of the visitor-invariance predicate. */
	class Cache_Compatibility {
		public static $active = false;
		public static function is_active( $settings = null ) { return self::$active; }
	}
}

namespace FazCookie\Frontend\Includes {
	/** Double of the runtime resolver: the ruleset the frontend enforced. */
	class Geo_Runtime {
		public static $by_country = array();
		public static $calls      = array();
		public static function resolve_for_country( $country ) {
			$key           = is_string( $country ) ? strtoupper( $country ) : '';
			self::$calls[] = $key;
			return isset( self::$by_country[ $key ] ) ? self::$by_country[ $key ] : null;
		}
	}
}

namespace FazCookie\Admin\Modules\Geo_Routing {
	/**
	 * Double of the detector chain: what the second resolution used to return
	 * on a filter-only topology (no MMDB, no Cloudflare header). Any call to it
	 * from the consent log is the divergence this suite exists to catch.
	 */
	class Geo_Routing {
		public static $calls = 0;
		private static $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function get_visitor_context( $ip_override = null, $country_override = null, $region_override = null ) {
			self::$calls++;
			return array(
				'country'    => 'XX',
				'region'     => '',
				'vpn'        => false,
				'source'     => 'unknown',
				'ruleset_id' => 'fallback-gdpr-most-protective',
				'ruleset'    => array( 'id' => 'fallback-gdpr-most-protective' ),
			);
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
	date_default_timezone_set( 'UTC' );

	// ---------- WordPress doubles (only what the controller touches) ----------
	function sanitize_text_field( $s ) {
		$s = (string) $s;
		$s = preg_replace( '/<[^>]*>/', '', $s );
		$s = preg_replace( '/[\r\n\t]+/', ' ', $s );
		$s = preg_replace( '/[\x00-\x1F\x7F]/', '', $s );
		return trim( preg_replace( '/\s{2,}/', ' ', $s ) );
	}
	function sanitize_title( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s ) ); }
	function absint( $n ) { return abs( (int) $n ); }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
	function esc_url_raw( $u ) { return trim( (string) $u ); }
	function wp_parse_url( $u ) { return parse_url( (string) $u ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
	function wp_salt( $scheme = 'auth' ) { return 'UNIT-TEST-SALT-' . $scheme; }
	function wp_generate_password( $len = 24, $special = false ) { return str_repeat( 'x', (int) $len ); }
	function current_time( $type, $gmt = 0 ) { return '2026-06-19 12:00:00'; }
	function faz_get_valid_consent_cookie() { return ''; }
	function get_option( $k, $d = false ) { return $GLOBALS['__faz_options'][ $k ] ?? $d; }
	function update_option( $k, $v ) { $GLOBALS['__faz_options'][ $k ] = $v; return true; }
	function did_action( $h ) { return 1; }
	function add_action( ...$a ) { return true; }
	function apply_filters( $hook, $value ) { return $value; }
	function faz_resolve_client_ip() { return '203.0.113.7'; }

	$GLOBALS['__faz_options'] = array( 'faz_consent_logs_db_version' => '9.9' );

	/** $wpdb double: records the one insert the cases need. */
	class FazTest_GeoAuditWPDB {
		public $prefix     = 'wp_';
		public $insert_id  = 0;
		public $last_error = '';
		public $inserts    = array();
		public function insert( $table, $data, $format = null ) {
			$this->inserts[] = array( 'data' => $data, 'format' => $format );
			$this->insert_id = count( $this->inserts );
			return 1;
		}
		public function prepare( $q, ...$a ) { return $q; }
		public function get_row( $q, $o = null ) { return null; }
		public function get_results( $q, $o = null ) { return array(); }
		public function get_var( $q ) { return 0; }
		public function query( $q ) { return 0; }
	}
	if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
	if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
	$GLOBALS['wpdb'] = new FazTest_GeoAuditWPDB();

	require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-gpc-exception-audit.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/includes/class-controller.php';

	use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;
	use FazCookie\Includes\Geolocation;
	use FazCookie\Includes\Cache_Compatibility;
	use FazCookie\Frontend\Includes\Geo_Runtime;
	use FazCookie\Admin\Modules\Geo_Routing\Geo_Routing;

	$run = 0; $pass = 0; $fail = 0;
	function geo_ok( $cond, $label ) {
		global $run, $pass, $fail; $run++;
		if ( $cond ) { $pass++; echo "  \033[32m✓\033[0m $label\n"; }
		else { $fail++; echo "  \033[31m✗\033[0m $label\n"; }
	}
	function geo_eq( $a, $b, $label ) {
		global $run, $pass, $fail; $run++;
		if ( $a === $b ) { $pass++; echo "  \033[32m✓\033[0m $label\n"; }
		else {
			$fail++; echo "  \033[31m✗\033[0m $label\n";
			echo '      expected: ' . var_export( $b, true ) . "\n";
			echo '      actual:   ' . var_export( $a, true ) . "\n";
		}
	}

	$ctrl    = ( new ReflectionClass( Controller::class ) )->newInstanceWithoutConstructor();
	$resolve = new ReflectionMethod( Controller::class, 'resolve_geo_audit_fields' );
	$resolve->setAccessible( true );
	$fields = function ( array $data = array() ) use ( $ctrl, $resolve ) {
		return $resolve->invoke( $ctrl, $data );
	};
	function geo_reset() {
		Geolocation::$country       = '';
		Geolocation::$region        = '';
		Geolocation::$calls         = 0;
		Cache_Compatibility::$active = false;
		Geo_Runtime::$by_country    = array();
		Geo_Runtime::$calls         = array();
		Geo_Routing::$calls         = 0;
	}

	echo "\n== consent-log jurisdiction columns follow the enforcement chain ==\n\n";

	// 1. The reported topology: country from faz_visitor_country (no MMDB, no
	//    Cloudflare), a US-CA visitor enforced under ccpa-california. The
	//    detector chain, with nothing to go on, says XX / fallback.
	echo "-- filter-only topology --\n";
	geo_reset();
	Geolocation::$country    = 'US';
	Geolocation::$region     = 'US-CA';
	Geo_Runtime::$by_country = array( 'US' => array( 'id' => 'ccpa-california', 'model' => 'opt-out' ) );
	$out = $fields();
	geo_eq( $out['country_at_consent'] ?? null, 'US', 'country_at_consent is the country the frontend enforced under, not the detector\'s XX' );
	geo_eq( $out['region_at_consent'] ?? null, 'US-CA', 'region_at_consent is the enforced region' );
	geo_eq( $out['ruleset_id_at_consent'] ?? null, 'ccpa-california', 'ruleset_id_at_consent is the ruleset the frontend applied, not the most-protective fallback' );
	geo_eq( Geo_Runtime::$calls, array( 'US' ), 'the ruleset is resolved through Geo_Runtime with the same country, exactly as the frontend does' );
	geo_eq( Geo_Routing::$calls, 0, 'the bare detector chain is not consulted at all' );

	// 2. Unknown country: the data model says NULL, not the 'XX' sentinel.
	echo "-- unknown country --\n";
	geo_reset();
	Geolocation::$country    = '';
	Geo_Runtime::$by_country = array( '' => array( 'id' => 'fallback-gdpr-most-protective' ) );
	$out = $fields();
	geo_ok( ! array_key_exists( 'country_at_consent', $out ), 'an unresolved country leaves country_at_consent NULL instead of writing XX' );
	geo_ok( ! array_key_exists( 'region_at_consent', $out ), 'and no region' );
	geo_eq( $out['ruleset_id_at_consent'] ?? null, 'fallback-gdpr-most-protective', 'while the ruleset actually enforced for an unknown country is still recorded' );

	// 3. Cache Compatibility Mode: the frontend enforces NO server-side ruleset
	//    (every per-visitor render path returns null), so the row must not
	//    claim one.
	echo "-- cache compatibility mode --\n";
	geo_reset();
	Geolocation::$country       = 'IT';
	Cache_Compatibility::$active = true;
	Geo_Runtime::$by_country    = array( 'IT' => array( 'id' => 'gdpr-italy' ) );
	$out = $fields();
	geo_eq( $out['country_at_consent'] ?? null, 'IT', 'the country is still recorded under cache compatibility mode' );
	geo_ok( ! array_key_exists( 'ruleset_id_at_consent', $out ), 'but no ruleset id, because none was enforced' );
	geo_eq( Geo_Runtime::$calls, array(), 'and the runtime resolver is not even asked' );

	// 4. Geo-targeting off (Geo_Runtime::resolve_for_country() returns null):
	//    nothing was enforced, nothing is claimed.
	echo "-- geo-targeting off --\n";
	geo_reset();
	Geolocation::$country = 'DE';
	$out = $fields();
	geo_eq( $out['country_at_consent'] ?? null, 'DE', 'country recorded with geo-targeting off' );
	geo_ok( ! array_key_exists( 'ruleset_id_at_consent', $out ), 'no ruleset id when the runtime resolved none' );

	// 5. Region coherence, as Geo_Routing applies it: a region of another
	//    country is discarded rather than recorded against the wrong country.
	echo "-- region coherence --\n";
	geo_reset();
	Geolocation::$country = 'US';
	Geolocation::$region  = 'CA-QC';
	$out = $fields();
	geo_eq( $out['country_at_consent'] ?? null, 'US', 'country kept' );
	geo_ok( ! array_key_exists( 'region_at_consent', $out ), 'a region whose prefix is another country is not recorded' );

	// 6. Signals still come from the request headers the REST handler derives.
	echo "-- signals --\n";
	geo_reset();
	Geolocation::$country = 'US';
	$out = $fields( array( 'signal_gpc' => 1, 'signal_dnt' => 0, 'tc_string' => 'CPyyyAAA.AAAA', 'gpp_string' => 'DBABLA~1' ) );
	geo_eq( $out['signal_gpc_received'] ?? null, 1, 'signal_gpc_received comes from the request' );
	geo_eq( $out['signal_dnt_received'] ?? null, 0, 'signal_dnt_received comes from the request' );
	geo_eq( $out['tc_string'] ?? null, 'CPyyyAAA.AAAA', 'tc_string is kept' );
	geo_eq( $out['gpp_string'] ?? null, 'DBABLA~1', 'gpp_string is kept' );

	// 7. A caller that supplies the context it enforced still wins (AMP or an
	//    integration that resolved it already).
	echo "-- caller-supplied context --\n";
	geo_reset();
	Geolocation::$country = 'US';
	$out = $fields( array( 'visitor_context' => array( 'country' => 'BR', 'region' => '', 'ruleset_id' => 'lgpd-brazil' ) ) );
	geo_eq( $out['country_at_consent'] ?? null, 'BR', 'an explicit visitor_context is used as given' );
	geo_eq( $out['ruleset_id_at_consent'] ?? null, 'lgpd-brazil', 'including its ruleset id' );
	geo_eq( Geolocation::$calls, 0, 'without resolving anything server-side' );
	$out = $fields( array( 'visitor_context' => array( 'country' => 'XX', 'ruleset_id' => 'fallback-gdpr-most-protective' ) ) );
	geo_ok( ! array_key_exists( 'country_at_consent', $out ), 'an explicit XX is not stored as a country either' );

	// 8. End to end through log_consent(): the row written carries the
	//    enforced jurisdiction.
	echo "-- through log_consent() --\n";
	geo_reset();
	Geolocation::$country    = 'US';
	Geolocation::$region     = 'US-CA';
	Geo_Runtime::$by_country = array( 'US' => array( 'id' => 'ccpa-california' ) );
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (UnitTest)';
	$GLOBALS['wpdb']->inserts = array();
	$rec = $ctrl->log_consent( array( 'consent_id' => 'cid-geo', 'status' => 'accepted', 'categories' => array( 'analytics' => 'yes' ), 'url' => 'https://example.test/' ) );
	geo_ok( is_array( $rec ), 'log_consent() writes the row' );
	$row = $GLOBALS['wpdb']->inserts[0]['data'] ?? array();
	geo_eq( $row['country_at_consent'] ?? null, 'US', 'the inserted row records the enforced country' );
	geo_eq( $row['region_at_consent'] ?? null, 'US-CA', 'and region' );
	geo_eq( $row['ruleset_id_at_consent'] ?? null, 'ccpa-california', 'and ruleset id' );
	geo_eq( count( $GLOBALS['wpdb']->inserts[0]['format'] ), count( $row ), 'the insert format stays aligned with the columns' );

	// 9. The comment that described a "JS-side hydration" nobody wired must not
	//    survive: the row's source of truth is the server-side enforcement chain.
	$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/includes/class-controller.php' );
	geo_ok( false === strpos( $src, 'JS-side hydration' ), 'the controller no longer documents an unimplemented JS-side hydration as its first source' );

	echo "\n--\nTests:  $run\nPassed: $pass\nFailed: $fail\n\n";
	if ( $fail > 0 ) { echo "\033[31mFAIL\033[0m\n"; exit( 1 ); }
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
