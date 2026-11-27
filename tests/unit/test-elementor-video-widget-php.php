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

	// The output buffer records the finished render; nothing to record here.
	class Embed_Inventory {
		public static function complete_render() {
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
	// WP Maps keeps its settings in one option; the gate answer reads its
	// site-wide map provider from there.
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $key, $default = false ) {
			return array_key_exists( $key, $GLOBALS['__faz_options'] ?? array() ) ? $GLOBALS['__faz_options'][ $key ] : $default;
		}
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) {
			return is_string( $value ) ? stripslashes( $value ) : $value;
		}
	}
	if ( ! function_exists( 'maybe_unserialize' ) ) {
		function maybe_unserialize( $data ) {
			if ( is_string( $data ) && preg_match( '/^a:\d+:\{/', $data ) ) {
				$value = @unserialize( $data, array( 'allowed_classes' => false ) );
				return false === $value ? $data : $value;
			}
			return $data;
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
			return array_key_exists( $tag, $GLOBALS['__faz_filters'] ?? array() ) ? $GLOBALS['__faz_filters'][ $tag ] : $value;
		}
	}
	// The request context page_content_is_gated() reads, one switch each.
	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin() {
			return ! empty( $GLOBALS['__faz_ctx']['admin'] );
		}
	}
	if ( ! function_exists( 'wp_doing_cron' ) ) {
		function wp_doing_cron() {
			return ! empty( $GLOBALS['__faz_ctx']['cron'] );
		}
	}
	if ( ! function_exists( 'faz_is_machine_readable_request' ) ) {
		function faz_is_machine_readable_request() {
			return ! empty( $GLOBALS['__faz_ctx']['feed'] );
		}
	}
	if ( ! function_exists( 'faz_disable_banner' ) ) {
		function faz_disable_banner() {
			return ! empty( $GLOBALS['__faz_ctx']['disable_banner'] );
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

	// Shared with Frontend, Amp_Consent, Banner_Rest and the language seams: the
	// one predicate that decides whether Cache Compatibility Mode applies. The
	// real file, not a double — a copy per suite is the thing it replaced.
	require_once dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	// Asked by the jurisdiction overlay to know whether a verified withdrawal
	// route other than the floating widget is in place.
	require_once dirname( __DIR__, 2 ) . '/includes/class-withdrawal-path.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	// The two per-page exclusions read settings this harness does not build;
	// each is answered from the same switchboard as the request context.
	class FazTest_Gated_Frontend extends Frontend {
		protected function is_banner_disabled_by_settings() {
			return ! empty( $GLOBALS['__faz_ctx']['banner_excluded'] );
		}
		protected function is_blocking_disabled_for_page() {
			return ! empty( $GLOBALS['__faz_ctx']['blocking_excluded'] );
		}
	}

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
			'google-maps'      => array( 'label' => 'Google Maps', 'category' => 'functional', 'patterns' => array( 'maps.googleapis.com' ), 'cookies' => array() ),
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
	function faz_widget( $url, $extra_class = '', $key = 'youtube_url', array $extra = array() ) {
		$settings = htmlspecialchars( json_encode( array_merge( array( 'video_type' => 'youtube', $key => $url ), $extra ) ), ENT_QUOTES, 'UTF-8' );
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

	// 9. Rules written against the EMBED URL. The widget stores the URL as
	//    typed (youtube.com/watch, vimeo.com/123) but the browser loads the
	//    embed Elementor derives (youtube.com/embed/…, player.vimeo.com/video/…),
	//    and those are the patterns the catalogue and a plain-iframe rule use.
	$embed_rule = array( 'youtube.com/embed' => 'necessary', 'player.vimeo.com' => 'marketing' );
	$out        = faz_run( $fe, faz_widget( $yt ), array( 'marketing' ), $embed_rule );
	assert_eq( faz_blocked( $out ), false, 'EV9 a rule on youtube.com/embed reaches a widget storing a watch URL' );
	$out = faz_run( $fe, faz_widget( 'https://youtu.be/NL2UmY9oKow' ), array( 'marketing' ), $embed_rule );
	assert_eq( faz_blocked( $out ), false, 'EV9 …and one storing a youtu.be short link' );
	$vimeo_rule = array( 'player.vimeo.com' => 'analytics', 'youtube.com/embed' => 'marketing' );
	$out        = faz_run( $fe, faz_widget( 'https://vimeo.com/76979871', '', 'vimeo_url' ), array( 'analytics' ), $vimeo_rule );
	assert_eq( faz_blocked( $out ) && 'analytics' === faz_category( $out ), true, 'EV9 a rule on player.vimeo.com decides a vimeo.com widget' );
	$nocookie_rule = array( 'youtube-nocookie.com/embed' => 'functional', 'youtube.com/embed' => 'marketing' );
	$out           = faz_run( $fe, faz_widget( $yt, '', 'youtube_url', array( 'yt_privacy' => 'yes' ) ), array( 'marketing' ), $nocookie_rule );
	assert_eq( faz_blocked( $out ), false, 'EV9 privacy mode is judged on youtube-nocookie.com, the host it loads' );
	$out = faz_run( $fe, faz_widget( $yt ), array( 'marketing' ), $nocookie_rule );
	assert_eq( faz_blocked( $out ), true, 'EV9 …while the same widget without privacy mode stays on youtube.com' );

	// 10. A whitelist entry for the embed host covers the widget too.
	$fe_wl2 = faz_arrange( '', false, array( 'player.vimeo.com' ) );
	$out    = faz_run( $fe_wl2, faz_widget( 'https://vimeo.com/76979871', '', 'vimeo_url' ), array( 'marketing' ), $catalogue_map );
	assert_eq( faz_blocked( $out ), false, 'EV10 whitelisting player.vimeo.com lets a vimeo.com widget load' );

	// Bricks Maps uses a div and JavaScript options, not an iframe.
	$map = '<div id="map" class="brxe-map" data-bricks-map-options="{&quot;zoom&quot;:12}"></div>';
	$fe = faz_arrange( '', false );
	$out = faz_call( $fe, 'process_bricks_map_widgets', array( $map, array( 'functional' ), array( 'maps.googleapis.com' => 'functional' ) ) );
	assert_eq( faz_blocked( $out ), true, 'Bricks: denied map has a visible placeholder' );
	assert_eq( strpos( $out, ' data-bricks-map-options=' ), false, 'Bricks: builder options are inert before consent' );
	assert_eq( strpos( $out, 'data-faz-bricks-map-options=' ) !== false, true, 'Bricks: options are preserved for restoration' );
	assert_eq( faz_call( $fe, 'process_bricks_map_widgets', array( $out, array( 'functional' ), array() ) ), $out, 'Bricks: the second buffer pass is idempotent' );
	$fe = faz_arrange( '', false );
	assert_eq( faz_call( $fe, 'process_bricks_map_widgets', array( $map, array(), array( 'maps.googleapis.com' => 'functional' ) ) ), $map, 'Bricks: granted category stays intact' );
	$fe = faz_arrange( '', false );
	assert_eq( faz_call( $fe, 'process_bricks_map_widgets', array( $map, array( 'functional' ), array( 'maps.googleapis.com' => 'necessary' ) ) ), $map, 'Bricks: explicit Necessary rule wins' );
	$fe = faz_arrange( '', false, array( 'maps.googleapis.com' ) );
	assert_eq( faz_call( $fe, 'process_bricks_map_widgets', array( $map, array( 'functional' ), array() ) ), $map, 'Bricks: Maps whitelist is respected' );
	$fe = faz_arrange( '', false );
	$skip = str_replace( 'brxe-map', 'brxe-map faz-skip', $map );
	assert_eq( faz_call( $fe, 'process_bricks_map_widgets', array( $skip, array( 'functional' ), array() ) ), $skip, 'Bricks: faz-skip is respected' );
	// An exemption written on the API script's handle id, as the page shows it.
	$fe = faz_arrange( '', false, array( 'bricks-google-maps' ) );
	assert_eq( faz_call( $fe, 'process_bricks_map_widgets', array( $map, array( 'functional' ), array() ) ), $map, 'Bricks: an exemption on the script handle id covers the widget' );
	// Per-service consent wins over the category, both ways.
	$fe  = faz_arrange( 'consentid:x,consent:yes,action:yes,functional:yes,svc.google-maps:no', true );
	$out = faz_call( $fe, 'process_bricks_map_widgets', array( $map, array(), array( 'maps.googleapis.com' => 'functional' ) ) );
	assert_eq( faz_blocked( $out ), true, 'Bricks: svc.google-maps:no keeps the map blocked although Functional is accepted' );
	$fe  = faz_arrange( 'consentid:x,consent:yes,action:yes,functional:no,svc.google-maps:yes', true );
	$out = faz_call( $fe, 'process_bricks_map_widgets', array( $map, array( 'functional' ), array( 'maps.googleapis.com' => 'functional' ) ) );
	assert_eq( $out, $map, 'Bricks: svc.google-maps:yes loads the map although Functional is denied' );
	// WP Rocket Delay JS: every FAZ inline bootstrap stays out of the delay,
	// the consent logger's data and listener included.
	$rocket = faz_call( $fe, 'rocket_exclude_own_inline', array( array( 'other' ) ) );
	assert_eq( count( array_intersect( array( '_fazConfig', '_fazStaticConfig', '_fazGcm', '_fazConsentLog', 'other' ), $rocket ) ), 5, 'Rocket: FAZ inline markers are excluded and existing entries kept' );
	$leaflet = str_replace( 'data-bricks-map-options', 'data-bricks-leaflet-options', $map );
	assert_eq( faz_call( $fe, 'process_bricks_map_widgets', array( $leaflet, array( 'functional' ), array() ) ), $leaflet, 'Bricks: Leaflet is not treated as Google Maps' );

	// WP Maps (wp-google-map-plugin) draws its map from JavaScript into an
	// empty div once the Maps API calls back wpgmpInitMap — the same shape as
	// Bricks, reported on the wp.org forum ("WP Maps support"). The markup is
	// the one WP Maps 5.0.3 prints.
	$wpgmp_map  = '<div class="wpgmp_map_container wpgmp-map-provider-google wpgmp-map-1" rel="map1" data-plugin-version="5.0.3"><div class="wpgmp_map_parent">'
		. '<div class="wpgmp_map " style="width:100%;height:450px" id="map1" data-map-id="1"></div></div></div>';
	$wpgmp_rule = array( 'maps.google.com' => 'functional' );
	$fe  = faz_arrange( '', false );
	$out = faz_call( $fe, 'process_wpgmp_map_widgets', array( $wpgmp_map, array( 'functional' ), $wpgmp_rule ) );
	assert_eq( substr_count( $out, 'data-placeholder=' ), 1, 'WP Maps: a denied map gets one placeholder' );
	assert_eq( (bool) preg_match( '#<div data-placeholder="google-maps" data-cat="functional"></div><div class="wpgmp_map#', $out ), true, 'WP Maps: the placeholder is the Google Maps one' );
	assert_eq( (bool) preg_match( '#</div>\s*<div class="wpgmp_map\s+faz-hidden"[^>]*id="map1"[^>]*data-faz-category="functional" data-faz-service="google-maps">#', $out ), true, 'WP Maps: the map div follows the placeholder, hidden and labelled for release' );
	assert_eq( 0 === strpos( $out, '<div class="wpgmp_map_container wpgmp-map-provider-google wpgmp-map-1" rel="map1"' ), true, 'WP Maps: the container (listings, filters) is left visible' );
	assert_eq( faz_call( $fe, 'process_wpgmp_map_widgets', array( $out, array( 'functional' ), $wpgmp_rule ) ), $out, 'WP Maps: the second buffer pass is idempotent' );
	$fe = faz_arrange( '', false );
	assert_eq( faz_call( $fe, 'process_wpgmp_map_widgets', array( $wpgmp_map, array(), $wpgmp_rule ) ), $wpgmp_map, 'WP Maps: granted category stays intact' );
	$fe = faz_arrange( '', false );
	assert_eq( faz_call( $fe, 'process_wpgmp_map_widgets', array( $wpgmp_map, array( 'functional' ), array( 'maps.google.com' => 'necessary' ) ) ), $wpgmp_map, 'WP Maps: explicit Necessary rule wins' );
	$fe = faz_arrange( '', false, array( 'maps.google.com' ) );
	assert_eq( faz_call( $fe, 'process_wpgmp_map_widgets', array( $wpgmp_map, array( 'functional' ), $wpgmp_rule ) ), $wpgmp_map, 'WP Maps: Maps whitelist is respected' );
	$fe = faz_arrange( '', false, array( 'wpgmp-google-api' ) );
	assert_eq( faz_call( $fe, 'process_wpgmp_map_widgets', array( $wpgmp_map, array( 'functional' ), $wpgmp_rule ) ), $wpgmp_map, 'WP Maps: an exemption on the API script handle id covers the map' );
	$fe   = faz_arrange( '', false );
	$skip = str_replace( 'class="wpgmp_map "', 'class="wpgmp_map faz-skip"', $wpgmp_map );
	assert_eq( faz_call( $fe, 'process_wpgmp_map_widgets', array( $skip, array( 'functional' ), $wpgmp_rule ) ), $skip, 'WP Maps: faz-skip is respected' );
	$osm = str_replace( 'wpgmp-map-provider-google', 'wpgmp-map-provider-leaflet', $wpgmp_map );
	assert_eq( faz_call( $fe, 'process_wpgmp_map_widgets', array( $osm, array( 'functional' ), $wpgmp_rule ) ), $osm, 'WP Maps: an OpenStreetMap/Leaflet map is not treated as Google Maps' );
	// Before 5.x the container carried no provider class, and every map was Google.
	$legacy = str_replace( ' wpgmp-map-provider-google', '', $wpgmp_map );
	$out    = faz_call( $fe, 'process_wpgmp_map_widgets', array( $legacy, array( 'functional' ), $wpgmp_rule ) );
	assert_eq( substr_count( $out, 'data-placeholder=' ), 1, 'WP Maps: a container with no provider class is a Google map' );
	// A provider belongs to the containing div, including after a nested map.
	$canvas = '<div class="wpgmp_map" id="map-standalone"></div>';
	$empty_leaflet = '<div class="wpgmp_map_container wpgmp-map-provider-leaflet"><div>Filters</div></div>';
	$out = faz_call( $fe, 'process_wpgmp_map_widgets', array( $empty_leaflet . $canvas, array( 'functional' ), $wpgmp_rule ) );
	assert_eq( substr_count( $out, 'data-placeholder=' ), 1, 'WP Maps: a closed Leaflet container does not change an adjacent legacy map' );
	$nested = '<div class="wpgmp_map_container wpgmp-map-provider-leaflet">' . $wpgmp_map
		. '<span title="</div>">Filters</span><div class="wpgmp_map" id="map-leaflet"></div></div>';
	$out = faz_call( $fe, 'process_wpgmp_map_widgets', array( $nested, array( 'functional' ), $wpgmp_rule ) );
	assert_eq( substr_count( $out, 'data-placeholder=' ), 1, 'WP Maps: a nested Google map does not change the outer Leaflet provider' );
	assert_eq( false !== strpos( $out, '<div class="wpgmp_map" id="map-leaflet">' ), true, 'WP Maps: outer Leaflet canvas remains visible' );
	// A <noscript> body is text to a browser running scripts, and the content
	// pass does not stash it: a `</div>` written there closes nothing.
	$noscript = '<div class="wpgmp_map_container wpgmp-map-provider-leaflet"><noscript><p></div></p></noscript>'
		. '<div class="wpgmp_map" id="map-leaflet"></div></div>';
	$out = faz_call( $fe, 'process_wpgmp_map_widgets', array( $noscript, array( 'functional' ), $wpgmp_rule ) );
	assert_eq( substr_count( $out, 'data-placeholder=' ), 0, 'WP Maps: a </div> inside <noscript> does not close the Leaflet container' );
	// A real close after the noscript still ends the container.
	$after = '<div class="wpgmp_map_container wpgmp-map-provider-leaflet"><noscript>x</noscript></div>' . $canvas;
	$out   = faz_call( $fe, 'process_wpgmp_map_widgets', array( $after, array( 'functional' ), $wpgmp_rule ) );
	assert_eq( substr_count( $out, 'data-placeholder=' ), 1, 'WP Maps: the container still closes at its real </div> after a <noscript>' );

	// Two maps, one of them Leaflet: only the Google one is parked.
	$second = str_replace( array( 'wpgmp-map-1', 'map1', 'data-map-id="1"' ), array( 'wpgmp-map-2', 'map2', 'data-map-id="2"' ), $osm );
	$out    = faz_call( $fe, 'process_wpgmp_map_widgets', array( $wpgmp_map . $second, array( 'functional' ), $wpgmp_rule ) );
	assert_eq( substr_count( $out, 'data-placeholder=' ), 1, 'WP Maps: each map is judged by its own container' );
	assert_eq( false !== strpos( $out, '<div class="wpgmp_map " style="width:100%;height:450px" id="map2" data-map-id="2"></div>' ), true, 'WP Maps: the Leaflet map next to it is untouched' );
	// Per-service consent wins over the category, both ways.
	$fe  = faz_arrange( 'consentid:x,consent:yes,action:yes,functional:yes,svc.google-maps:no', true );
	$out = faz_call( $fe, 'process_wpgmp_map_widgets', array( $wpgmp_map, array(), $wpgmp_rule ) );
	assert_eq( substr_count( $out, 'data-placeholder=' ), 1, 'WP Maps: svc.google-maps:no keeps the map blocked although Functional is accepted' );
	$fe = faz_arrange( 'consentid:x,consent:yes,action:yes,functional:no,svc.google-maps:yes', true );
	assert_eq( faz_call( $fe, 'process_wpgmp_map_widgets', array( $wpgmp_map, array( 'functional' ), $wpgmp_rule ) ), $wpgmp_map, 'WP Maps: svc.google-maps:yes loads the map although Functional is denied' );
	// A map div quoted inside another tag's value (a tooltip library keeping
	// HTML in an attribute) is text, not an element: rewriting it with
	// double-quoted keys would close the value early.
	$fe     = faz_arrange( '', false );
	$quoted = '<span data-tip="<div class=\'wpgmp_map\' id=\'map7\'></div>">tip</span>';
	assert_eq( faz_call( $fe, 'process_wpgmp_map_widgets', array( $quoted, array( 'functional' ), $wpgmp_rule ) ), $quoted, 'WP Maps: a map div quoted inside an attribute value is left alone' );
	// The class is read from the tag's own token, never from inside another
	// attribute's value, and the hidden class is written there too.
	$fe   = faz_arrange( '', false );
	$tip  = '<div data-tip=\'<div class="wpgmp_map"></div>\' class="description">Contatti</div>';
	assert_eq( faz_call( $fe, 'process_wpgmp_map_widgets', array( $tip, array( 'functional' ), $wpgmp_rule ) ), $tip, 'WP Maps: class="wpgmp_map" inside another attribute does not make a div a map' );
	$real = str_replace( '<div class="wpgmp_map " ', '<div data-tip=\'<b class="x">\' class="wpgmp_map " ', $wpgmp_map );
	$out  = faz_call( $fe, 'process_wpgmp_map_widgets', array( $real, array( 'functional' ), $wpgmp_rule ) );
	assert_eq( substr_count( $out, 'data-placeholder=' ), 1, 'WP Maps: a map with a class-shaped string in another attribute is still parked' );
	assert_eq( false !== strpos( $out, 'data-tip=\'<b class="x">\'' ), true, 'WP Maps: …and that attribute value is left as written' );
	assert_eq( (bool) preg_match( '#<div class="wpgmp_map\s+faz-hidden" data-tip=#', $out ), true, 'WP Maps: …the hidden class goes on the tag\'s own class token' );
	$bare = str_replace( 'class="wpgmp_map "', 'class=wpgmp_map', $wpgmp_map );
	$out  = faz_call( $fe, 'process_wpgmp_map_widgets', array( $bare, array( 'functional' ), $wpgmp_rule ) );
	assert_eq( substr_count( $out, 'data-placeholder=' ), 1, 'WP Maps: an unquoted class value is read too' );

	// The map is judged as its API script is judged by filter_script_loader_tag():
	// a rule written on the handle decides as one written on the URL, in either
	// direction. Matching only the URL let a handle rule release the API while
	// the map stayed behind the catalogue category's placeholder.
	foreach ( array(
		'handle' => 'wpgmp-google-api',
		'URL'    => 'maps.google.com',
	) as $kind => $pattern ) {
		faz_set_prop( $fe, 'provider_match_meta_cache', null );
		assert_eq( faz_call( $fe, 'process_wpgmp_map_widgets', array( $wpgmp_map, array( 'functional' ), array( $pattern => 'marketing' ) ) ), $wpgmp_map, "WP Maps: a {$kind} rule in an accepted category loads the map, as it loads the API" );
		faz_set_prop( $fe, 'provider_match_meta_cache', null );
		$out = faz_call( $fe, 'process_wpgmp_map_widgets', array( $wpgmp_map, array( 'marketing' ), array( $pattern => 'marketing' ) ) );
		assert_eq( faz_category( $out ), 'marketing', "WP Maps: a {$kind} rule in a denied category parks the map under that category" );
	}
	faz_set_prop( $fe, 'provider_match_meta_cache', null );
	$out = faz_call( $fe, 'process_wpgmp_map_widgets', array( $wpgmp_map, array( 'marketing' ), array( 'maps.google.com' => 'functional', 'wpgmp-google-api' => 'marketing' ) ) );
	assert_eq( faz_category( $out ), 'marketing', 'WP Maps: URL allowed, handle denied — the denied match decides, as for the tag' );
	// Both rules denied: the first in map order decides, whichever of handle
	// or URL it is written on — the tag filter walks the map once, so a
	// URL-first pass parked the map under a different category than its tag.
	foreach ( array(
		'marketing'  => array( 'wpgmp-google-api' => 'marketing', 'maps.google.com' => 'functional' ),
		'functional' => array( 'maps.google.com' => 'functional', 'wpgmp-google-api' => 'marketing' ),
	) as $expected => $rules ) {
		faz_set_prop( $fe, 'provider_match_meta_cache', null );
		$out = faz_call( $fe, 'process_wpgmp_map_widgets', array( $wpgmp_map, array( 'marketing', 'functional' ), $rules ) );
		assert_eq( faz_category( $out ), $expected, "WP Maps: both rules denied — the first in map order ({$expected}) decides, as for the tag" );
	}
	faz_set_prop( $fe, 'provider_match_meta_cache', null );

	// Both entry points run the detector while script bodies are stashed: a
	// real map and the same div spelled inside a JavaScript string give one
	// placeholder, and the script comes back byte for byte.
	$js_tpl = '<script>window.fazTpl = "<div class=\'wpgmp_map\' id=\'map9\' data-map-id=\'9\'></div>";</script>';
	$page   = $js_tpl . $wpgmp_map;
	$entry  = ( new ReflectionClass( 'FazTest_Gated_Frontend' ) )->newInstanceWithoutConstructor();
	faz_set_prop( $entry, 'template', array( 'html' => '<div></div>' ) );
	faz_set_prop( $entry, 'settings_option_cache', array( 'script_blocking' => array( 'whitelist_patterns' => array() ) ) );
	faz_set_prop( $entry, 'blocked_categories_cache', array( 'functional' ) );
	faz_set_prop( $entry, 'provider_map_cache', $wpgmp_rule );
	faz_set_prop( $entry, 'service_consent_cache', array() );
	$GLOBALS['__faz_ctx'] = array();
	foreach ( array(
		'content filter' => function () use ( $entry, $page ) {
			return $entry->filter_content_blocking( $page );
		},
		'output buffer'  => function () use ( $entry, $page ) {
			return faz_call( $entry, 'block_buffered_markup', array( $page ) );
		},
	) as $label => $run ) {
		$out = $run();
		assert_eq( substr_count( $out, 'data-placeholder="google-maps"' ), 1, "WP Maps ({$label}): one placeholder, for the real map only" );
		assert_eq( 0 === strpos( $out, $js_tpl ), true, "WP Maps ({$label}): the script holding the same div is left untouched" );
	}

	// WP Maps' own "Cookies Acceptance" gate is answered by FAZ only where the
	// content pass parks the map: everywhere else nothing would stop the Maps
	// API, and WP Maps' own answer has to stand.
	$gate = ( new ReflectionClass( 'FazTest_Gated_Frontend' ) )->newInstanceWithoutConstructor();
	faz_set_prop( $gate, 'template', array( 'html' => '<div></div>' ) );
	$GLOBALS['__faz_ctx'] = array();
	assert_eq( faz_call( $gate, 'wpgmp_consent_handled_by_faz', array( false ) ), true, 'WP Maps gate: answered true where FAZ gates the page' );
	foreach ( array(
		'banner_excluded'   => 'the banner is excluded on this page',
		'blocking_excluded' => 'script blocking is excluded on this page',
		'disable_banner'    => 'faz_disable_banner() is true',
		'admin'             => 'an admin screen',
		'feed'              => 'a feed or other machine-readable request',
		'cron'              => 'a cron run',
	) as $switch => $label ) {
		$GLOBALS['__faz_ctx'] = array( $switch => true );
		assert_eq( faz_call( $gate, 'wpgmp_consent_handled_by_faz', array( false ) ), false, "WP Maps gate: left alone where {$label}" );
		assert_eq( faz_call( $gate, 'wpgmp_consent_handled_by_faz', array( true ) ), true, "WP Maps gate: …WP Maps' own yes stands there too ({$switch})" );
	}
	$GLOBALS['__faz_ctx']     = array();
	$GLOBALS['__faz_filters'] = array( 'faz_is_amp_request' => true );
	assert_eq( faz_call( $gate, 'wpgmp_consent_handled_by_faz', array( false ) ), false, 'WP Maps gate: left alone on an AMP page' );
	$GLOBALS['__faz_filters'] = array();
	faz_set_prop( $gate, 'template', null );
	assert_eq( faz_call( $gate, 'wpgmp_consent_handled_by_faz', array( false ) ), false, 'WP Maps gate: left alone where FAZ shows no banner' );

	// 1.40.0 compliance review L2. WP Maps' gate sits in front of BOTH map
	// engines (wpgmp_register_map_assets() returns before its provider branch),
	// and the engine is one site-wide setting, wpgmp_settings[wpgmp_map_source].
	// FAZ parks only the Google API: in Leaflet mode the loader is a local
	// script and the tiles come from whichever host the site picked, so a
	// "handled" there released every map before consent. The answer is yes only
	// where WP Maps is in Google mode.
	faz_set_prop( $gate, 'template', array( 'html' => '<div></div>' ) );
	$GLOBALS['__faz_ctx'] = array();
	foreach ( array(
		'no WP Maps settings saved (Google is its default)' => array( null, true ),
		'the provider set to google'                         => array( array( 'wpgmp_map_source' => 'google' ), true ),
		'settings without a provider key'                    => array( array( 'wpgmp_gdpr' => true ), true ),
		'the provider set to openstreet (Leaflet)'           => array( array( 'wpgmp_map_source' => 'openstreet' ), false ),
		'any provider other than google'                     => array( array( 'wpgmp_map_source' => 'leaflet' ), false ),
		'Leaflet settings stored serialized'                 => array( serialize( array( 'wpgmp_map_source' => 'openstreet' ) ), false ),
		'Google settings stored serialized'                  => array( serialize( array( 'wpgmp_map_source' => 'google' ) ), true ),
	) as $label => $case ) {
		$GLOBALS['__faz_options'] = null === $case[0] ? array() : array( 'wpgmp_settings' => $case[0] );
		assert_eq( faz_call( $gate, 'wpgmp_consent_handled_by_faz', array( false ) ), $case[1], "WP Maps gate: with {$label}, answered " . ( $case[1] ? 'true' : 'with WP Maps\' own no' ) );
	}
	$GLOBALS['__faz_options'] = array( 'wpgmp_settings' => array( 'wpgmp_map_source' => 'openstreet' ) );
	assert_eq( faz_call( $gate, 'wpgmp_consent_handled_by_faz', array( true ) ), true, 'WP Maps gate: in Leaflet mode WP Maps\' own yes still stands' );
	// WP Maps lets a request override the provider (wpgmp_get_map_provider()
	// reads $_POST first): FAZ does not vouch for a request it cannot place.
	$GLOBALS['__faz_options'] = array( 'wpgmp_settings' => array( 'wpgmp_map_source' => 'google' ) );
	$_POST['wpgmp_map_source'] = 'openstreet';
	assert_eq( faz_call( $gate, 'wpgmp_consent_handled_by_faz', array( false ) ), false, 'WP Maps gate: a request that switches the provider to Leaflet is not vouched for' );
	$_POST['wpgmp_map_source'] = 'google';
	assert_eq( faz_call( $gate, 'wpgmp_consent_handled_by_faz', array( false ) ), true, 'WP Maps gate: a request naming google on a Google site is still handled' );
	$GLOBALS['__faz_options'] = array( 'wpgmp_settings' => array( 'wpgmp_map_source' => 'openstreet' ) );
	assert_eq( faz_call( $gate, 'wpgmp_consent_handled_by_faz', array( false ) ), true, 'WP Maps gate: a request that switches a Leaflet site to google gets the Google map FAZ parks' );
	unset( $_POST['wpgmp_map_source'] );
	$GLOBALS['__faz_options'] = array();

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
