<?php
/**
 * The server and script.js must agree on when a TCF vendor list makes a
 * decided consent stale.
 *
 * script.js (_fazVendorRecordStale) re-prompts a visitor whose fazVendorConsent
 * record does not disclose every vendor now selected — or who has no record at
 * all — and deletes the consent cookie. The server used to read the same
 * cookie as valid, so on that page view it had already rendered the accepted
 * categories' trackers executable; the re-prompt came after they had run.
 *
 * Covers the PHP parser (faz_decode_vendor_bits(), faz_parse_vendor_consent_record())
 * on the formats script.js writes, faz_is_vendor_record_stale() rule by rule,
 * and the Frontend read path (get_scoped_valid_consent_cookie() →
 * get_blocked_categories()). Parser parity with the JS functions on the same
 * vectors is in tests/unit/js/vendor-record-parity.test.mjs.
 *
 * Run: php tests/unit/test-vendor-record-stale-php.php
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
	/** Gvl double: the vendors the stored list carries, and whether one is stored. */
	class Gvl {
		public static $has_data = true;
		public static $vendors  = array( 1 => array(), 2 => array(), 3 => array(), 7 => array( 'deletedDate' => '2026-01-01' ) );
		public static $loads    = 0;
		public static function get_instance() { return new self(); }
		public function has_data() { return self::$has_data; }
		public function get_vendors( $ids ) {
			++self::$loads;
			$out = array();
			foreach ( $ids as $id ) {
				if ( isset( self::$vendors[ $id ] ) && empty( self::$vendors[ $id ]['deletedDate'] ) ) {
					$out[ $id ] = self::$vendors[ $id ];
				}
			}
			return $out;
		}
	}
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
		'iab'            => array( 'enabled' => true, 'cmp_id' => 300 ),
	);
	$GLOBALS['vs_selected'] = array( 1, 2 );

	function plugin_basename( $file ) { return basename( $file ); }
	function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/\\' ) . '/'; }
	function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/faz-cookie-manager/'; }
	function get_option( $name, $default = false ) {
		if ( 'WPLANG' === $name ) { return 'en_US'; }
		if ( 'siteurl' === $name ) { return 'http://example.test'; }
		if ( 'faz_settings' === $name ) { return $GLOBALS['vs_settings']; }
		if ( 'faz_gvl_selected_vendors' === $name ) { return $GLOBALS['vs_selected']; }
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

	class Faz_VR_Stop_Bootstrap extends RuntimeException {}
	function add_action() { throw new Faz_VR_Stop_Bootstrap(); }
	try {
		require dirname( __DIR__, 2 ) . '/faz-cookie-manager.php';
	} catch ( Faz_VR_Stop_Bootstrap $e ) {
		// Expected: the cookie helpers are defined by then.
	}
	require dirname( __DIR__, 2 ) . '/frontend/includes/class-geo-runtime.php';
	require dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require dirname( __DIR__, 2 ) . '/includes/class-withdrawal-path.php';
	require dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	class Faz_VR_Frontend extends Frontend {
		protected function is_banner_disabled_by_settings() { return false; }
		protected function is_blocking_disabled_for_page() { return false; }
	}

	$passed = 0;
	$failed = 0;
	function vr_check( $condition, $label ) {
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
	function vs_request( $law, array $cookies, array $server = array(), $headers_sent = false, $template_loaded = true ) {
		$GLOBALS['vs_headers']      = array();
		$GLOBALS['vs_actions']      = array();
		$GLOBALS['vs_headers_sent'] = $headers_sent;
		$_COOKIE = $cookies;
		$_SERVER = $server;
		$GLOBALS['vs_deleted']      = array();
		$frontend = ( new ReflectionClass( Faz_VR_Frontend::class ) )->newInstanceWithoutConstructor();
		// A banner was loaded on init: the visitor has a consent context.
		$template = new ReflectionProperty( Frontend::class, 'template' );
		$template->setAccessible( true );
		$template->setValue( $frontend, $template_loaded ? array( 'html' => '<div></div>' ) : null );
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


	echo "Parser: the PHP reader matches the fixture script.js is checked against\n";
	$vectors = json_decode( file_get_contents( __DIR__ . '/fixtures/vendor-record-vectors.json' ), true );
	foreach ( $vectors['records'] as $v ) {
		$got = faz_parse_vendor_consent_record( $v['raw'] );
		if ( null !== $got ) {
			$got = array(
				'disclosed' => vs_sorted( array_keys( array_filter( $got['disclosed'] ) ) ),
				'consent'   => vs_sorted( array_keys( array_filter( $got['consent'] ) ) ),
			);
		}
		vr_check( $v['expected'] === $got, 'record ' . json_encode( $v['raw'] ) );
	}
	foreach ( $vectors['bitfields'] as $v ) {
		vr_check( $v['ids'] === faz_decode_vendor_bits( $v['text'] ), 'bitfield ' . json_encode( $v['text'] ) );
	}

	echo "faz_is_vendor_record_stale(): the rules of _fazVendorRecordStale()\n";
	$decided  = 'consentid:abc,consent:yes,action:yes,necessary:yes,analytics:yes,marketing:yes,rev:1';
	$full     = 'd:4,c:*';  // vendors 1, 2, 3 disclosed.
	$only_one = 'd:g,c:*';  // vendor 1 disclosed.
	vr_check( false === faz_is_vendor_record_stale( $decided, 'gdpr', $full ), 'every selected vendor disclosed: valid' );
	vr_check( true === faz_is_vendor_record_stale( $decided, 'gdpr', '' ), 'no vendor record at all (TCF switched on later, or an AMP cookie): stale' );
	vr_check( true === faz_is_vendor_record_stale( $decided, 'gdpr', $only_one ), 'a selected vendor missing from the disclosed set: stale' );
	vr_check( false === faz_is_vendor_record_stale( $decided, 'gdpr', '1:yes,2:no' ), 'legacy per-pair record that disclosed both: valid' );
	vr_check( true === faz_is_vendor_record_stale( $decided, 'gdpr', '1:yes' ), 'legacy per-pair record missing one: stale' );
	vr_check( false === faz_is_vendor_record_stale( $decided, 'ccpa', '' ), 'opt-out law (ccpa): never re-asked' );
	vr_check( false === faz_is_vendor_record_stale( str_replace( 'action:yes', 'action:no', $decided ), 'gdpr', '' ), 'record without action:yes: not stale' );
	vr_check( false === faz_is_vendor_record_stale( $decided . ',undecided:1', 'gdpr', '' ), 'signal-created undecided record: not stale' );
	vr_check( false === faz_is_vendor_record_stale( '', 'gdpr', '' ), 'no consent cookie: nothing to invalidate' );

	$GLOBALS['vs_selected'] = array( 1, 7 );
	vr_check( false === faz_is_vendor_record_stale( $decided, 'gdpr', $only_one ), 'the only undisclosed vendor is deleted from the GVL: valid (the preference center does not list it)' );
	$GLOBALS['vs_selected'] = array( 1, 99 );
	vr_check( false === faz_is_vendor_record_stale( $decided, 'gdpr', $only_one ), 'the only undisclosed vendor is not in the GVL any more: valid' );
	$GLOBALS['vs_selected'] = array( 1 );
	vr_check( false === faz_is_vendor_record_stale( $decided, 'gdpr', $full ), 'a vendor REMOVED from the selection asks nothing new: valid' );
	\FazCookie\Includes\Gvl::$loads = 0;
	faz_is_vendor_record_stale( $decided, 'gdpr', $full );
	vr_check( 0 === \FazCookie\Includes\Gvl::$loads, 'a record that discloses every selected vendor never loads the GVL' );
	$GLOBALS['vs_selected'] = array();
	vr_check( false === faz_is_vendor_record_stale( $decided, 'gdpr', '' ), 'no vendor selected: TCF discloses nobody, not stale' );
	$GLOBALS['vs_selected'] = array( 1, 2 );

	$GLOBALS['vs_settings']['iab']['enabled'] = false;
	vr_check( false === faz_is_vendor_record_stale( $decided, 'gdpr', '' ), 'TCF off: not stale' );
	$GLOBALS['vs_settings']['iab']['enabled'] = true;
	$GLOBALS['vs_settings']['iab']['cmp_id']  = 1;
	vr_check( false === faz_is_vendor_record_stale( $decided, 'gdpr', '' ), 'no registered CMP ID (the CMP is not enqueued): not stale' );
	$GLOBALS['vs_settings']['iab']['cmp_id'] = 300;
	\FazCookie\Includes\Gvl::$has_data = false;
	vr_check( false === faz_is_vendor_record_stale( $decided, 'gdpr', '' ), 'no GVL stored (the CMP is not enqueued): not stale' );
	\FazCookie\Includes\Gvl::$has_data = true;

	echo "Frontend: a stale record is read as absent on the page view it is re-asked\n";
	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $decided, 'fazVendorConsent' => $full ) );
	vr_check( array() === vs_blocked( $fe ), 'every vendor disclosed: the accepted categories render executable' );
	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $decided ) );
	vr_check( array( 'analytics', 'marketing' ) === vs_blocked( $fe ), 'no vendor record: the render is a first visit\'s, nothing executable before the re-prompt' );
	vr_check( ! vs_vetoed(), 'and, being the baseline, it stays cacheable' );
	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $decided, 'fazVendorConsent' => $only_one ) );
	vr_check( array( 'analytics', 'marketing' ) === vs_blocked( $fe ), 'a vendor added since the choice: same' );
	vr_check( isset( $_COOKIE['fazcookie-consent'] ), 'the cookie is not deleted server-side (script.js deletes it where it re-prompts)' );
	$fe = vs_request( 'gdpr', array( 'fazcookie-consent' => $decided ), array(), false, false );
	vr_check( array() === vs_blocked( $fe ), 'no banner loaded (no script.js to re-prompt, e.g. AMP): the cookie is honoured as before' );
	$ccpa = 'consentid:abc,consent:no,action:yes,necessary:yes,analytics:yes,marketing:no,rev:1';
	$fe = vs_request( 'ccpa', array( 'fazcookie-consent' => $ccpa ) );
	vr_check( array( 'marketing' ) === vs_blocked( $fe ), 'CCPA: the recorded opt-out stands, not reset to a first visit' );

	echo "vendor-record-stale: {$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
