<?php
/**
 * Frontend::srcdoc_key() must not take the page down when the system has no
 * entropy source.
 *
 * random_bytes() throws an Exception when no CSPRNG is available. The key is
 * minted inside the front-end render (store data and every parked srcdoc), so
 * an uncaught exception there was a fatal page. The fallback must still yield
 * an attribute-safe, non-empty key that stays stable for the request.
 *
 * random_bytes() is shadowed in the Frontend namespace: class-frontend.php
 * calls it unqualified, so PHP resolves this function first.
 *
 * Run: php tests/unit/test-srcdoc-key-entropy-php.php
 */
namespace FazCookie\Frontend {
	function random_bytes( $length ) { throw new \Exception( 'Could not gather sufficient random data' ); }
}
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

	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		$GLOBALS['srcdoc_fallback_used'] = true;
		return substr( str_repeat( 'Ab3', $length ), 0, $length );
	}

	$method = new ReflectionMethod( Frontend::class, 'srcdoc_key' );
	$method->setAccessible( true );
	$failed = 0;
	try {
		$first  = $method->invoke( null );
		$second = $method->invoke( null );
	} catch ( \Throwable $e ) {
		echo "  FAIL srcdoc_key() let the entropy failure escape: " . $e->getMessage() . "\n";
		exit( 1 );
	}
	$checks = array(
		'the WordPress generator is the fallback'          => ! empty( $GLOBALS['srcdoc_fallback_used'] ),
		'the fallback key is long enough to be unguessable' => strlen( $first ) >= 32,
		'and attribute-safe (alphanumeric only)'            => 1 === preg_match( '/^[A-Za-z0-9]+$/', $first ),
		'and stable for the request'                        => $first === $second,
	);
	foreach ( $checks as $label => $ok ) {
		echo ( $ok ? '  PASS ' : '  FAIL ' ) . $label . "\n";
		$failed += $ok ? 0 : 1;
	}
	echo 'srcdoc-key-entropy: ' . ( count( $checks ) - $failed ) . " passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
