<?php
/**
 * Standalone unit tests — inline scripts that only assign JSON data are not
 * tracker code (GitHub issue #322).
 *
 * Query Monitor prints `var QueryMonitorData = {…};` in an inline script with
 * its own id, from `shutdown`, so the markup still passes through the output
 * buffer. The JSON lists every script and HTTP call of the page, so on a page
 * that loads any tracker the provider matcher found the tracker's URL inside
 * the data and re-typed the whole script to text/plain: QueryMonitorData was
 * never defined, the admin-bar panels stopped working, and other developer and
 * dashboard plugins that publish their state the same way broke alike.
 *
 * An inline script whose whole body is one or more `var|let|const|window.NAME
 * = <JSON>;` assignments performs no request: it defines a value. The tracker
 * that would read it is judged on its own tag. wp_localize_script() payloads
 * were already exempt for exactly this reason (-js-extra); this extends the
 * same reasoning to the same shape printed under any id, or none.
 *
 * Pinned here:
 *   1. The Query Monitor payload stays executable although it names a tracker.
 *   2. Several JSON assignments in one tag (Site Kit's data shape) stay too.
 *   3. `window.NAME = …` and `let`/`const` count, with or without semicolons.
 *   4. Controls: a script that LOADS the tracker is still blocked; data plus
 *      any statement is still judged; a JS object literal that is not JSON
 *      (a function inside) is still judged; Matomo's `_paq` queue is blocked.
 *
 * Run: php tests/unit/test-inline-json-data-scripts-php.php
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

	// Inline bodies are matched against CODE SIGNATURES only (patterns with no
	// '.' or '/'); URL fragments are applied to src attributes. That is what
	// broke Query Monitor: its JSON names the page's handles and callbacks,
	// and a handle like google_gtagjs carries the `gtag` signature.
	$providers = array(
		'gtag'  => 'analytics',
		'fbq('  => 'marketing',
		'_paq'  => 'analytics',
	);
	$blocked   = array( 'analytics', 'marketing' );
	$wrap      = function ( $script ) {
		return '<html><head></head><body><p>x</p>' . $script . '</body></html>';
	};
	$is_live   = function ( $out, $script ) {
		return false !== strpos( $out, $script );
	};
	$is_parked = function ( $out ) {
		return 1 === preg_match( '/<script[^>]*type="text\/plain"[^>]*data-faz-category=/i', $out )
			|| 1 === preg_match( '/<script[^>]*data-faz-category=[^>]*type="text\/plain"/i', $out );
	};

	echo "\n-- JSON data assignments stay executable --\n";

	$qm  = '<script id="query-monitor-inline-data">var QueryMonitorData = {"assets":{"scripts":[{"handle":"google_gtagjs","src":"https:\/\/www.googletagmanager.com\/gtag\/js?id=G-TEST"}]},"hooks":[{"callback":"fbq(init)"}]};</script>';
	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $qm ) );
	assert_true( $is_live( $out, $qm ), 'the Query Monitor payload is untouched although it names two trackers' );

	$sk  = "<script id=\"googlesitekit-base-data-js-before\">var _googlesitekitBaseData = {\"homeURL\":\"https:\/\/site.test\",\"gtagHandle\":\"google_gtagjs\"};\nvar _googlesitekitUserData = {\"user\":{\"id\":1}};</script>";
	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $sk ) );
	assert_true( $is_live( $out, $sk ), 'several JSON assignments in one tag stay untouched' );

	$win = '<script>window.ToolState = {"modules":["gtag","fbq(track)"]}</script>';
	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $win ) );
	assert_true( $is_live( $out, $win ), 'window.NAME = JSON without a semicolon stays untouched' );

	$cst = '<script type="text/javascript">const A = ["fbq(", "gtag"];let B = null;</script>';
	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $cst ) );
	assert_true( $is_live( $out, $cst ), 'const/let JSON assignments stay untouched' );

	$wp  = "<script id=\"tool-js-after\">\n/* <![CDATA[ */\nvar ToolData = {\"h\":\"google_gtagjs\"};\n/* ]]> */\n//# sourceURL=tool-js-after\n</script>";
	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $wp ) );
	assert_true( $is_live( $out, $wp ), 'the CDATA markers and the sourceURL line core adds do not hide the data shape' );

	$str = '<script>var Notes = {"text":"}; fbq(\\"track\\"); var z = {\\"a\\":1"};</script>';
	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $str ) );
	assert_true( $is_live( $out, $str ), 'code-looking text inside a JSON string is still just a string' );

	echo "\n-- everything else is still judged --\n";

	$load = '<script>(function(){var s=document.createElement("script");s.src="https://www.googletagmanager.com/gtag/js?id=G-TEST";document.head.appendChild(s);})();</script>';
	$out  = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $load ) );
	assert_true( $is_parked( $out ), 'a script that loads the tracker is still blocked' );

	$mix = '<script>var cfg = {"id":"1"}; fbq("init", cfg.id);</script>';
	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $mix ) );
	assert_true( $is_parked( $out ), 'a JSON assignment followed by any statement is still judged' );

	$fn  = '<script>var cfg = {"src":"x","onload":function(){ fbq("track","PageView"); }};</script>';
	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $fn ) );
	assert_true( $is_parked( $out ), 'an object literal that is not JSON is still judged' );

	$paq = '<script>var _paq = window._paq = window._paq || []; _paq.push(["trackPageView"]); _paq.push(["setTrackerUrl", "https://matomo.example.test/matomo.php"]);</script>';
	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $paq ) );
	assert_true( $is_parked( $out ), 'the Matomo queue is still blocked' );

	$chain = '<script>var a = {"u":"x"}, b = fbq("track","PageView");</script>';
	$out   = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $chain ) );
	assert_true( $is_parked( $out ), 'a declaration list that hides an expression is still judged' );

	$nl  = "<script>var cfg = {\"id\":\"1\"}\nfbq(\"init\", cfg.id)</script>";
	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $nl ) );
	assert_true( $is_parked( $out ), 'a statement on the line after the data is still judged' );

	$after = '<script>var cfg = {"id":"1"} fbq("init");</script>';
	$out   = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $after ) );
	assert_true( $is_parked( $out ), 'code right after the value on the same line is still judged' );

	$src = '<script src="https://www.googletagmanager.com/gtag/js?id=G-TEST">var X = {"a":1};</script>';
	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), $wrap( $src ) );
	assert_true( $is_parked( $out ), 'an external script is judged by its src, whatever its body says' );

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "ALL PASS ({$tests_passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$tests_failed}, passed: {$tests_passed}\n";
	exit( 1 );
}
