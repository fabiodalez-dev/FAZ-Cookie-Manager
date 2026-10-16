<?php
/**
 * Standalone unit tests — Site Kit's own plugin assets are not trackers
 * (GitHub issue #322).
 *
 * The catalogue entry "Site Kit by Google" carried the patterns
 * `google-site-kit` and `googlesitekit`. Every one of Site Kit's 64 frontend
 * and admin scripts lives under /plugins/google-site-kit/ and is named
 * googlesitekit-*, so the entry blocked the plugin's admin bar, its API
 * client and its consent-mode bridge for every visitor who had not accepted
 * analytics — including the logged-in administrator, whose admin-bar buttons
 * did nothing. What Site Kit TRACKS with is gtag.js (handle google_gtagjs),
 * Tag Manager and AdSense, which the catalogue matches by their own endpoints.
 *
 * Pinned against the REAL catalogue (includes/data/known-providers.json):
 *   1. Site Kit's admin-bar loader, API client and consent-mode scripts stay live.
 *   2. Site Kit's gtag.js tag is still blocked (its inline config only queues
 *      commands that gtag.js would send).
 *
 * Run: php tests/unit/test-site-kit-assets-not-blocked-php.php
 */


namespace FazCookie\Includes {

	class Known_Providers {
		public static function get_all() {
			return array();
		}
		public static function get_cookie_map() {
			return array();
		}
		public static function get_pattern_map() {
			return array();
		}
	}
}

namespace FazCookie\Frontend\Includes {

	class Placeholder_Builder {
		public static function detect_service_from_url( $url ) {
			return 'default';
		}
		public static function get_service_name( $id ) {
			return 'Embedded content';
		}
		public static function get_video_thumbnail( $url ) {
			return '';
		}
		public static function build( $service_id, $service_name, $category, $blocked_html, $thumbnail_url = '' ) {
			return $blocked_html;
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
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $str ) {
			return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			$str = (string) $str;
			$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
			return trim( wp_strip_all_tags( $str ) );
		}
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $value ) {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); // phpcs:ignore
		}
	}
	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			return false;
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $ttl = 0 ) {
			return true;
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-embed-inventory.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;

	function assert_true( $cond, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $cond ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m " . $label . "\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m " . $label . "\n";
		}
	}

	function faz_ob_frontend( array $providers, array $blocked, array $whitelist = array() ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		$preset = array(
			'blocked_categories_cache'  => $blocked,
			'provider_map_cache'        => $providers,
			'service_consent_cache'     => array(),
			'whitelist_cache'           => $whitelist,
			'settings_option_cache'     => array(),
			'pattern_service_cache'     => array(),
			'provider_match_meta_cache' => null,
			'service_match_meta_cache'  => null,
		);
		foreach ( $preset as $prop => $value ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $fe, $value );
		}
		return $fe;
	}

	function faz_ob_run( $fe, $html ) {
		$m = new ReflectionMethod( Frontend::class, 'process_output_buffer' );
		$m->setAccessible( true );
		return $m->invoke( $fe, $html );
	}

	function faz_raw_attr( $html, $name ) {
		if ( preg_match( '/(?<![\w:-])' . preg_quote( $name, '/' ) . '=("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $html, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/** The markup with every data-faz-style value blanked: what the ENGINE reads as live. */
	function faz_live_part( $html ) {
		return preg_replace( '/data-faz-style=("[^"]*"|\'[^\']*\')/i', 'data-faz-style=""', $html );
	}

	$catalogue = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/includes/data/known-providers.json' ), true );
	$providers = array();
	foreach ( (array) ( $catalogue['providers'] ?? $catalogue ) as $service ) {
		foreach ( (array) ( $service['patterns'] ?? array() ) as $pattern ) {
			if ( is_string( $pattern ) && ! isset( $providers[ $pattern ] ) ) {
				$providers[ $pattern ] = $service['category'];
			}
		}
	}
	assert_true( count( $providers ) > 500, 'premise: the real catalogue is loaded (' . count( $providers ) . ' patterns)' );
	$blocked = array( 'analytics', 'marketing', 'functional', 'performance' );
	$base    = 'https://site.test/wp-content/plugins/google-site-kit/dist/assets/js/';
	$run     = function ( $tag ) use ( $providers, $blocked ) {
		return faz_ob_run( faz_ob_frontend( $providers, $blocked ), '<html><head>' . $tag . '</head><body></body></html>' );
	};
	$parked  = function ( $out ) {
		return 1 === preg_match( '/type="text\/plain"/', $out );
	};

	echo "\n-- Site Kit's own assets stay live --\n";
	foreach ( array(
		'googlesitekit-adminbar-loader' => 'googlesitekit-adminbar-loader-0f1e2d3c4b5a6978.js',
		'googlesitekit-api'             => 'googlesitekit-api-1a2b3c4d5e6f7a8b.js',
		'googlesitekit-consent-mode'    => 'googlesitekit-consent-mode-9f8e7d6c5b4a3f2e.js',
	) as $handle => $file ) {
		$tag = '<script id="' . $handle . '-js" src="' . $base . $file . '"></script>';
		$out = $run( $tag );
		assert_true( false !== strpos( $out, $tag ), $handle . ' is untouched' );
	}

	echo "\n-- what Site Kit tracks with is still blocked --\n";
	$out = $run( '<script id="google_gtagjs-js" src="https://www.googletagmanager.com/gtag/js?id=G-TEST" async></script>' );
	assert_true( $parked( $out ), 'Site Kit\'s gtag.js tag is blocked' );
	// Its inline `gtag('config', …)` only pushes onto dataLayer; nothing is sent
	// until gtag.js loads, and gtag.js is the tag asserted above.

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "ALL PASS ({$tests_passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$tests_failed}, passed: {$tests_passed}\n";
	exit( 1 );
}
