<?php
/**
 * Unit test — Elementor Video widgets answer to the same rules as every other
 * blocked embed.
 *
 * The widget renders an empty wrapper server-side and builds the real iframe
 * client-side from data-settings, so the generic <iframe> blocker never sees
 * it and process_elementor_video_widgets() decides on its own. It took the
 * category straight from the bundled provider catalogue, where YouTube is
 * marketing. Every other surface resolves it through the merged provider map —
 * the admin's Script Blocking rules and the faz_blocking_rules filter included
 * — and honours the whitelist and the faz-skip class. So a site that made
 * YouTube necessary, or whitelisted it, still got a placeholder on exactly this
 * widget. Reported on the wordpress.org forum ("YouTube as necessary").
 *
 * Reuses the bootstrap of test-per-service-embeds-php.php verbatim (WordPress
 * stubs, reflection harness, faz_arrange()); only the doubles below differ.
 *
 * Run: php tests/unit/test-elementor-video-widget-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	class Known_Providers {
		public static function get_all() {
			return $GLOBALS['__faz_providers'];
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
		public static function is_embed_service( $service_id ) {
			return in_array( $service_id, array( 'youtube', 'vimeo' ), true );
		}
		public static function detect_service_from_url( $url ) {
			if ( false !== stripos( $url, 'youtube.com' ) || false !== stripos( $url, 'youtu.be' ) ) {
				return 'youtube';
			}
			if ( false !== stripos( $url, 'vimeo.com' ) ) {
				return 'vimeo';
			}
			return 'default';
		}
		public static function get_service_name( $service_id ) {
			return ucfirst( $service_id );
		}
		public static function build_social( $service_id, $service_name, $category ) {
			return '<div data-placeholder="' . $service_id . '" data-cat="' . $category . '"></div>';
		}
	}
}

namespace {

	// ---------- Bootstrap ----------

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	$GLOBALS['__faz_transients']     = array();
	$GLOBALS['__faz_set_transients'] = array();
	$GLOBALS['__faz_db_rows']        = array();
	$GLOBALS['__faz_consent_cookie'] = '';
	$GLOBALS['__faz_providers']      = array();

	if ( ! function_exists( 'wp_parse_url' ) ) {
	// Reached only once the social-container path consults the whitelist, which
	// it did not before this change — the harness had no reason to stub it.
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			return array_key_exists( $key, $GLOBALS['__faz_transients'] )
				? $GLOBALS['__faz_transients'][ $key ]
				: false;
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $ttl = 0 ) {
			$GLOBALS['__faz_transients'][ $key ] = $value;
			return true;
		}
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
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
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
	if ( ! function_exists( 'faz_get_valid_consent_cookie' ) ) {
		function faz_get_valid_consent_cookie() {
			return $GLOBALS['__faz_consent_cookie'];
		}
	}

	if ( ! class_exists( 'FazTest_WPDB' ) ) {
		class FazTest_WPDB {
			public $prefix = 'wp_';
			public function get_col( $query ) {
				return $GLOBALS['__faz_db_rows'];
			}
		}
	}
	$GLOBALS['wpdb'] = new FazTest_WPDB();

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	// ---------- assert helpers ----------

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;

	function assert_eq( $actual, $expected, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $actual === $expected ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m " . $label . "\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m " . $label . "\n";
			echo "      expected: " . var_export( $expected, true ) . "\n";
			echo "      actual:   " . var_export( $actual, true ) . "\n";
		}
	}

	// ---------- reflection harness ----------

	function faz_new_frontend() {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		foreach ( array(
			'per_service_cache',
			'enforceable_cache',
			'service_consent_cache',
			'pattern_service_cache',
			'settings_option_cache',
		) as $prop ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $fe, null );
		}
		return $fe;
	}

	function faz_call( $fe, $method, array $args = array() ) {
		$m = new ReflectionMethod( Frontend::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $fe, $args );
	}

	function faz_set_prop( $fe, $prop, $value ) {
		$p = new ReflectionProperty( Frontend::class, $prop );
		$p->setAccessible( true );
		$p->setValue( $fe, $value );
	}

	/**
	 * A small provider catalogue: youtube (marketing, video iframe), vimeo
	 * (marketing), google-analytics (analytics), a-necessary (necessary), and
	 * old-thing (a provider in a category that is NOT active on this site).
	 */
	function faz_providers() {
		return array(
			'youtube'          => array( 'label' => 'YouTube', 'category' => 'marketing', 'patterns' => array( 'youtube.com/embed', 'youtube-nocookie.com/embed' ), 'cookies' => array( 'YSC', 'VISITOR_INFO1_LIVE' ) ),
			'vimeo'            => array( 'label' => 'Vimeo', 'category' => 'marketing', 'patterns' => array( 'player.vimeo.com' ), 'cookies' => array( 'vuid' ) ),
			'google-analytics' => array( 'label' => 'Google Analytics', 'category' => 'analytics', 'patterns' => array( 'google-analytics.com/analytics.js' ), 'cookies' => array( '_ga' ) ),
			'a-necessary'      => array( 'label' => 'Necessary thing', 'category' => 'necessary', 'patterns' => array( 'needed.example.com' ), 'cookies' => array( 'need' ) ),
			'old-thing'        => array( 'label' => 'Old', 'category' => 'social', 'patterns' => array( 'old.example.com' ), 'cookies' => array( 'oldc' ) ),
		);
	}

	/** Active (non-necessary) categories on this fictional site. */
	function faz_active_cats() {
		return array( 'analytics', 'marketing', 'functional' );
	}

	/**
	 * Arrange a frontend with a fixed consent cookie + per_service flag, and the
	 * enforceable set computed from the provider catalogue (so get_service_consent
	 * resolves against the BROAD set, mirroring runtime).
	 */
	function faz_arrange( $cookie, $option_on = true, $whitelist = array() ) {
		$GLOBALS['__faz_consent_cookie'] = $cookie;
		$GLOBALS['__faz_providers']      = faz_providers();
		$fe = faz_new_frontend();
		faz_set_prop( $fe, 'settings_option_cache', array(
			'banner_control' => array( 'per_service_consent' => $option_on ),
			'script_blocking' => array( 'whitelist_patterns' => $whitelist ),
		) );
		// Detected (visible) list stays narrow & empty — the whole point of the
		// block-first scenario is that no provider cookie was observed.
		faz_set_prop( $fe, 'per_service_cache', array() );
		// Enforceable set = every known provider in an active category.
		faz_set_prop( $fe, 'enforceable_cache', faz_call( $fe, 'get_enforceable_services', array( faz_active_cats() ) ) );
		return $fe;
	}


	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) {
			return (string) $url;
		}
	}

	echo "\n  Elementor Video widget: same rules as every other embed\n";

	/**
	 * An Elementor Video widget wrapper as the page renders it: data-settings is
	 * entity-encoded JSON carrying the source URL.
	 */
	function faz_widget( $url, $extra_class = '', $key = 'youtube_url' ) {
		$settings = htmlspecialchars( json_encode( array( 'video_type' => 'youtube', $key => $url ) ), ENT_QUOTES, 'UTF-8' );
		return '<div class="elementor-element elementor-widget elementor-widget-video' . ( '' !== $extra_class ? ' ' . $extra_class : '' ) . '" data-settings="' . $settings . '"><div class="elementor-video"></div></div>';
	}
	function faz_blocked( $html ) {
		return false !== strpos( $html, 'data-placeholder=' );
	}
	function faz_category( $html ) {
		return preg_match( '/data-faz-category="([^"]*)"/', $html, $m ) ? $m[1] : '';
	}

	/**
	 * Run the widget pass with a given provider map. The match metadata is
	 * cached on the instance for the rest of the request (one map per request
	 * in production), so it is cleared here to let each case bring its own.
	 */
	function faz_run( $fe, $html, array $blocked, array $map ) {
		faz_set_prop( $fe, 'provider_match_meta_cache', null );
		return faz_call( $fe, 'process_elementor_video_widgets', array( $html, $blocked, $map ) );
	}

	$yt = 'https://www.youtube.com/watch?v=NL2UmY9oKow';

	// The merged provider map as get_provider_category_map() builds it:
	// pattern => category. The catalogue puts YouTube in marketing.
	$catalogue_map = array( 'youtube.com' => 'marketing', 'player.vimeo.com' => 'marketing' );

	// 1. Baseline: nothing configured, marketing not accepted → placeholder.
	$fe  = faz_arrange( '', false );
	$out = faz_run( $fe, faz_widget( $yt ), array( 'marketing' ), $catalogue_map );
	assert_eq( faz_blocked( $out ), true, 'EV1 YouTube in marketing, marketing not accepted → placeholder' );
	assert_eq( faz_category( $out ), 'marketing', 'EV1 the hidden widget carries its category' );

	// 2. THE REPORT. The admin made YouTube necessary with a Script Blocking
	//    rule; the merged map says so. The widget must load, as a plain iframe
	//    to the same URL would.
	$rule_map = array( 'youtube.com' => 'necessary', 'player.vimeo.com' => 'marketing' );
	$out      = faz_run( $fe, faz_widget( $yt ), array( 'marketing' ), $rule_map );
	assert_eq( faz_blocked( $out ), false, 'EV2 a rule making YouTube necessary lets the widget load' );

	// 3. A rule that MOVES YouTube to another category is followed both ways.
	$analytics_map = array( 'youtube.com' => 'analytics' );
	$out           = faz_run( $fe, faz_widget( $yt ), array( 'marketing' ), $analytics_map );
	assert_eq( faz_blocked( $out ), false, 'EV3 moved to analytics: marketing alone denied does not block it' );
	$out = faz_run( $fe, faz_widget( $yt ), array( 'analytics' ), $analytics_map );
	assert_eq( faz_blocked( $out ) && 'analytics' === faz_category( $out ), true, 'EV3 moved to analytics: blocked under analytics, labelled analytics' );

	// 4. No entry in the map for this URL → the catalogue decides, as before.
	$out = faz_run( $fe, faz_widget( $yt ), array( 'marketing' ), array( 'player.vimeo.com' => 'marketing' ) );
	assert_eq( faz_blocked( $out ) && 'marketing' === faz_category( $out ), true, 'EV4 no map entry: the catalogue category still applies' );

	// 5. Whitelist. An admin who whitelisted youtube.com gets the video on a
	//    plain iframe; the widget must not be the one place it stays blocked.
	$fe_wl = faz_arrange( '', false, array( 'youtube.com' ) );
	$out   = faz_run( $fe_wl, faz_widget( $yt ), array( 'marketing' ), $catalogue_map );
	assert_eq( faz_blocked( $out ), false, 'EV5 a whitelisted video domain is not blocked on the widget' );
	$out = faz_run( $fe_wl, faz_widget( 'https://vimeo.com/76979871', '', 'vimeo_url' ), array( 'marketing' ), $catalogue_map );
	assert_eq( faz_blocked( $out ), true, 'EV5 …while a service that is not whitelisted still is' );

	// 6. faz-skip: the built-in escape hatch, set through Elementor's
	//    Advanced → CSS Classes, which lands on this same wrapper.
	$out = faz_run( $fe, faz_widget( $yt, 'faz-skip' ), array( 'marketing' ), $catalogue_map );
	assert_eq( faz_blocked( $out ), false, 'EV6 class="faz-skip" on the widget bypasses blocking' );
	$out = faz_run( $fe, faz_widget( $yt, 'my-faz-skipper' ), array( 'marketing' ), $catalogue_map );
	assert_eq( faz_blocked( $out ), true, 'EV6 …a class that merely contains the word does not' );

	// 7. A per-service choice still wins over the category (existing contract).
	$fe_svc = faz_arrange( 'consentid:x,consent:yes,action:yes,marketing:no,svc.youtube:yes', true );
	$out    = faz_run( $fe_svc, faz_widget( $yt ), array( 'marketing' ), $catalogue_map );
	assert_eq( faz_blocked( $out ), false, 'EV7 svc.youtube:yes loads the widget although marketing is denied' );

	// 8. Nothing to gate: a self-hosted video, and a widget already processed.
	$out = faz_run( $fe, faz_widget( 'https://example.test/uploads/clip.mp4', '', 'external_url' ), array( 'marketing' ), $catalogue_map );
	assert_eq( faz_blocked( $out ), false, 'EV8 a self-hosted video is left alone' );
	$done = str_replace( 'data-settings=', 'data-faz-category="marketing" data-settings=', faz_widget( $yt ) );
	$out  = faz_run( $fe, $done, array( 'marketing' ), $catalogue_map );
	assert_eq( substr_count( $out, 'data-placeholder=' ), 0, 'EV8 a widget already processed is not processed twice' );

	echo "\n";
	echo "  Passed: {$tests_passed}\n";
	echo "  Failed: {$tests_failed}\n\n";
	if ( $tests_failed > 0 ) {
		echo "\033[31mFAIL\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
