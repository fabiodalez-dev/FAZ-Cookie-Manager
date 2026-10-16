<?php
/**
 * Standalone unit tests — the output-buffer passes leave what the HTML parser
 * does not read as an element alone: comments, and the bodies of <script>,
 * <style>, <textarea>, <title> and <xmp>.
 *
 * Subsystem: buffer-markup-skip-php
 *
 * Every pass in process_output_buffer() is a regex over the whole response.
 * A tag-shaped string inside an inline script —
 * `"<video poster=\"https://i.ytimg.com/vi/x/hq.jpg\">"` — was parked like a
 * real element, and the double-quoted attribute the pass wrote ended the JS
 * string at its first quote: a SyntaxError for the WHOLE script, including
 * the JSON data scripts #322 deliberately leaves executable. Pass 5b already
 * skipped these spans; the media pass (4c), <img> (4b), SVG href (1-bis),
 * `background` (1-ter), <iframe> (2 and the srcdoc stash), <style> (4),
 * <link> (5) and the <noscript> stash did not.
 *
 *   1. A script body carrying any of those tag shapes comes out byte-identical,
 *      whether the script is left live, is a #322 data script or is blocked.
 *   2. The same tags outside a script are still parked.
 *   3. A tag inside an HTML comment or a <textarea> is text and stays intact.
 *   4. A `<script` spelled inside an attribute value does not open a skip that
 *      would hide the real elements after it.
 *
 * Run: php tests/unit/test-buffer-markup-skip-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
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
			return '<div class="faz-placeholder" data-faz-category="' . $category . '"><template class="faz-placeholder-content">' . $blocked_html . '</template></div>';
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

	function faz_ob_frontend( array $providers, array $blocked ) {
		$rc     = new ReflectionClass( Frontend::class );
		$fe     = $rc->newInstanceWithoutConstructor();
		$preset = array(
			'blocked_categories_cache'  => $blocked,
			'provider_map_cache'        => $providers,
			'service_consent_cache'     => array(),
			'whitelist_cache'           => array(),
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

	function faz_ob_run( $html ) {
		global $providers, $blocked;
		$m = new ReflectionMethod( Frontend::class, 'process_output_buffer' );
		$m->setAccessible( true );
		return $m->invoke( faz_ob_frontend( $providers, $blocked ), $html );
	}

	/** Every <script> body in the markup, in order. */
	function faz_script_bodies( $html ) {
		preg_match_all( '#<script\b[^>]*>(.*?)</script>#is', $html, $m );
		return $m[1];
	}

	$providers = array(
		'i.ytimg.com'      => 'marketing',
		'player.vimeo.com' => 'marketing',
		'cdn.tracker.test' => 'marketing',
		'fbq('             => 'marketing',
	);
	$blocked   = array( 'marketing' );

	// One tag shape per pass, each as a JS string literal would carry it.
	// Double-quoted literals with escaped quotes, single-quoted attribute
	// values inside a double-quoted literal, and bare values: all three
	// break the literal once a pass writes `attr="…"` into it.
	$in_js = array(
		'4c <video poster>'      => '<video poster=\"https://i.ytimg.com/vi/x/hq.jpg\">',
		'4c <source src>'        => '<source src=\"https://player.vimeo.com/v/1.mp4\">',
		'4c <object data>'       => "<object data='https://cdn.tracker.test/embed'></object>",
		'4b <img src>'           => "<img src='https://cdn.tracker.test/p.png' width=1>",
		'1-bis <image href>'     => '<svg><image href=\"https://cdn.tracker.test/i.png\"/></svg>',
		'1-ter <td background>'  => "<table><tr><td background='https://cdn.tracker.test/bg.png'>x</td></tr></table>",
		'2 <iframe src>'         => "<iframe src='https://cdn.tracker.test/frame'></iframe>",
		'0 <iframe srcdoc>'      => "<iframe srcdoc='<img src=https://cdn.tracker.test/p.png>'></iframe>",
		'4 <style>@import'       => '<style>@import url(https://cdn.tracker.test/f.css);</style>',
		'5 <link rel=stylesheet>' => '<link rel=stylesheet href=https://cdn.tracker.test/a.css>',
		'stash <noscript><img>'  => "<noscript><img src='https://cdn.tracker.test/n.png'></noscript>",
		'5b style attribute'     => "<div style='background:url(https://i.ytimg.com/vi/x/hq.jpg)'></div>",
	);

	echo "\n-- a tag inside a live inline script is a string, not an element --\n";

	foreach ( $in_js as $label => $tag ) {
		$body = 'var tpl = "' . $tag . '"; document.body.insertAdjacentHTML("beforeend", tpl);';
		$out  = faz_ob_run( '<html><body><script>' . $body . '</script></body></html>' );
		$got  = faz_script_bodies( $out );
		assert_true( array( $body ) === $got, $label . ': the script body comes out byte-identical' );
	}

	echo "\n-- the #322 data scripts, which are left executable on purpose --\n";

	foreach ( array(
		'4c <source src> in JSON' => 'var cfg = {"tpl":"<source src=\"https://player.vimeo.com/x.mp4\">"};',
		'4c <video poster> in JSON' => 'var cfg = {"tpl":"<video poster=\"https://i.ytimg.com/vi/x/hq.jpg\"></video>"};',
		'4b <img src> in JSON'    => 'window.Lazy = {"html":"<img src=\"https://cdn.tracker.test/p.png\">"};',
		'1-bis <image href> in JSON' => 'var Icons = {"svg":"<svg><image href=\"https://cdn.tracker.test/i.png\"/></svg>"};',
	) as $label => $body ) {
		$script = '<script id="cfg-data">' . $body . '</script>';
		$out    = faz_ob_run( '<html><body>' . $script . '</body></html>' );
		assert_true( false !== strpos( $out, $script ), $label . ': the whole tag comes out byte-identical' );
	}

	echo "\n-- a blocked script keeps its body as written --\n";

	$body = 'fbq("init","1"); var px = "<img src=\"https://cdn.tracker.test/tr?id=1\">"; document.write(px);';
	$out  = faz_ob_run( '<html><body><script>' . $body . '</script></body></html>' );
	assert_true( false !== strpos( $out, 'type="text/plain"' ), 'the tracker script is still blocked' );
	assert_true( array( $body ) === faz_script_bodies( $out ), 'and its body, restored as-is on consent, was not rewritten inside' );

	$tpl = '<script type="text/template"><img src="https://cdn.tracker.test/p.png"><video poster="https://i.ytimg.com/vi/x/hq.jpg"></video></script>';
	$out = faz_ob_run( '<html><body>' . $tpl . '</body></html>' );
	assert_true( false !== strpos( $out, $tpl ), 'a script template body is left to the script that renders it' );

	echo "\n-- the same tags outside a script are still parked --\n";

	$out = faz_ob_run( '<html><body><video poster="https://i.ytimg.com/vi/x/hq.jpg"></video><source src="https://player.vimeo.com/v/1.mp4"><img src="https://cdn.tracker.test/p.png"><svg><image href="https://cdn.tracker.test/i.png"/></svg><table><tr><td background="https://cdn.tracker.test/bg.png">x</td></tr></table><link rel="stylesheet" href="https://cdn.tracker.test/a.css"><iframe src="https://cdn.tracker.test/frame"></iframe></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-poster="https://i.ytimg.com/vi/x/hq.jpg"' ), '<video poster> is parked' );
	assert_true( false !== strpos( $out, 'data-faz-src="https://player.vimeo.com/v/1.mp4"' ), '<source src> is parked' );
	assert_true( false !== strpos( $out, 'data-faz-src="https://cdn.tracker.test/p.png"' ), '<img src> is parked' );
	assert_true( false !== strpos( $out, 'data-faz-href="https://cdn.tracker.test/i.png"' ), '<image href> is parked' );
	assert_true( false !== strpos( $out, 'data-faz-background="https://cdn.tracker.test/bg.png"' ), '<td background> is parked' );
	assert_true( false !== strpos( $out, 'data-faz-href="https://cdn.tracker.test/a.css"' ), '<link href> is parked' );
	assert_true( false !== strpos( $out, 'data-faz-src="https://cdn.tracker.test/frame"' ), '<iframe src> is parked' );

	// Next to a script that carries the same shapes, which stays as it was.
	$body = 'var t = "<img src=\"https://cdn.tracker.test/p.png\">";';
	$out  = faz_ob_run( '<html><body><script>' . $body . '</script><img src="https://cdn.tracker.test/p.png"><script>var u = 1;</script><video poster="https://i.ytimg.com/vi/x/hq.jpg"></video></body></html>' );
	assert_true( array( $body, 'var u = 1;' ) === faz_script_bodies( $out ), 'the scripts around real elements are untouched' );
	assert_true( 1 === substr_count( $out, 'data-faz-src="https://cdn.tracker.test/p.png"' ), 'the real <img> between them is parked' );
	assert_true( 1 === substr_count( $out, 'data-faz-poster=' ), 'and so is the real <video> after them' );

	echo "\n-- comments and textarea content are text --\n";

	$html = '<html><body><!-- <img src="https://cdn.tracker.test/p.png"><video poster="https://i.ytimg.com/vi/x/hq.jpg"></video> --><p>x</p></body></html>';
	assert_true( faz_ob_run( $html ) === $html, 'an HTML comment holding tags comes out byte-identical' );

	$html = '<html><body><textarea name="embed"><iframe src="https://cdn.tracker.test/frame"></iframe><img src="https://cdn.tracker.test/p.png"></textarea></body></html>';
	assert_true( faz_ob_run( $html ) === $html, 'a <textarea> value holding tags comes out byte-identical' );

	echo "\n-- a skip cannot be opened from inside an attribute value --\n";

	// `<script` written into an attribute value is not a script start. Read
	// as one, it would hide everything up to the next </script> — here the
	// tracker <img> — from every pass.
	$out = faz_ob_run( '<html><body><div data-tpl="<script>"></div><img src="https://cdn.tracker.test/p.png"><video poster="https://i.ytimg.com/vi/x/hq.jpg"></video><script>var a = 1;</script></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-src="https://cdn.tracker.test/p.png"' ), 'the <img> after it is still parked' );
	assert_true( false !== strpos( $out, 'data-faz-poster="https://i.ytimg.com/vi/x/hq.jpg"' ), 'and so is the <video>' );

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "ALL PASS ({$tests_passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$tests_failed}, passed: {$tests_passed}\n";
	exit( 1 );
}
