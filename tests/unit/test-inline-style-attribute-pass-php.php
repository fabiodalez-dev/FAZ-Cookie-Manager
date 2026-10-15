<?php
/**
 * Standalone unit tests for the server-side pass over inline `style`
 * attributes.
 *
 * Subsystem: inline-style-attribute-pass-php
 *
 * The output buffer gated CSS URLs inside <style> ELEMENTS only. A
 * `<div style="background-image:url(https://i.ytimg.com/vi/ID/hq.jpg)">`
 * server-rendered by a theme or a lazy-video plugin shipped its attribute
 * live, and the engine fetched the poster at first paint — IP, referrer and
 * the video id disclosed to a provider the catalogue files under marketing,
 * while the identical URL in an <img src> on the same page was parked. The
 * bootstrap's header documents the server as the layer that rewrites inline
 * `style` attributes; this pass is that layer.
 *
 *   1. A blocked url() in a style attribute is neutralised in place, the
 *      original declarations move to data-faz-style with a category, and
 *      the rest of the tag is untouched.
 *   2. The value is judged as the engine parses it: character references
 *      decoded, CSS escapes decoded. A form the in-place rewrite cannot
 *      reach parks the attribute WHOLE (no live style at all).
 *   3. First-party and URL-free values, `style=` inside another attribute's
 *      value, already-parked and whitelisted tags are left byte-identical.
 *   4. A tag another pass already parked keeps its single category label.
 *
 * Run: php tests/unit/test-inline-style-attribute-pass-php.php
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

	$providers = array(
		'i.ytimg.com'      => 'marketing',
		'i.vimeocdn.com'   => 'marketing',
		'cdn.tracker.test' => 'marketing',
	);
	$blocked   = array( 'marketing' );
	$poster    = 'https://i.ytimg.com/vi/ID/maxresdefault.jpg';

	echo "\n-- a blocked url() in a style attribute --\n";

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><div class="hero" style="background-image:url(' . $poster . ');min-height:300px">x</div></body></html>' );
	assert_true( '"background-image:url(' . $poster . ');min-height:300px"' === faz_raw_attr( $out, 'data-faz-style' ), 'the original declarations are parked into data-faz-style, byte-for-byte' );
	assert_true( false === strpos( faz_live_part( $out ), 'i.ytimg.com' ), 'the provider URL is gone from everything the engine reads as live' );
	assert_true( false !== strpos( $out, 'style="background-image:url(&quot;data:application/octet-stream,&quot;);min-height:300px"' ), 'the live style keeps the other declarations and an inert url()' );
	assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), 'with the provider category' );
	assert_true( false !== strpos( $out, 'class="hero"' ) && false !== strpos( $out, '>x</div>' ), 'the rest of the tag and its content are untouched' );

	// What the engine parses is the DECODED attribute value.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><span style="background:url(&quot;' . $poster . '&quot;) no-repeat"></span></body></html>' );
	assert_true( null !== faz_raw_attr( $out, 'data-faz-style' ) && false === strpos( faz_live_part( $out ), 'i.ytimg.com' ), 'a quoted url() written with character references is parked' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, "<html><body><div style='background:url($poster)'></div></body></html>" );
	assert_true( "'background:url($poster)'" === faz_raw_attr( $out, 'data-faz-style' ), 'a single-quoted attribute keeps its quoting in the parked copy' );

	// A form the in-place rewrite cannot reach parks the value WHOLE.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><div style="background:u\72l(' . $poster . ')"></div></body></html>' );
	assert_true( null !== faz_raw_attr( $out, 'data-faz-style' ), 'a url() spelled through a CSS escape is parked' );
	assert_true( ! preg_match( '/(?<![\w:-])style\s*=/i', $out ), 'with no live style at all, because the escape is beyond the in-place rewrite' );

	// Image-set, the other string channel.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><div style="background-image:image-set(&quot;' . $poster . '&quot; 1x)"></div></body></html>' );
	assert_true( null !== faz_raw_attr( $out, 'data-faz-style' ) && false === strpos( faz_live_part( $out ), 'i.ytimg.com' ), 'a provider URL in an image-set() string is parked' );

	echo "\n-- what is left alone --\n";

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><div style="background:url(/wp-content/uploads/a.png);color:red"><p style="margin:0">t</p><a href="/x" style="display:block">l</a></div></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'first-party and URL-free values come out byte-identical' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><div title="style=url(' . $poster . ')" data-style="url(' . $poster . ')">x</div></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, '`style=` inside another attribute is not a style attribute' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><div style="background:url(https://unknown.example/a.png)"></div></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'an unrecognised third party is not gated by this pass' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><div data-faz-category="marketing" data-faz-style="background:url(' . $poster . ')"></div></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'an already-parked attribute is not rewritten twice' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><div class="faz-skip" style="background:url(' . $poster . ')"></div></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'faz-skip exempts the tag' );

	$fe   = faz_ob_frontend( $providers, $blocked, array( 'i.ytimg.com' ) );
	$html = '<html><body><div style="background:url(' . $poster . ')"></div></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'a whitelisted provider is exempt' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><p>if (a <b && c>d) style=url(' . $poster . ')</p><!-- <i style="x"> --></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'text that merely looks like a tag is untouched' );

	echo "\n-- next to another parked attribute --\n";

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><img src="https://cdn.tracker.test/px.png" style="background:url(' . $poster . ')" alt=""/></body></html>' );
	assert_true( null !== faz_raw_attr( $out, 'data-faz-src' ) && null !== faz_raw_attr( $out, 'data-faz-style' ), 'both the src and the style are parked' );
	assert_true( 1 === substr_count( $out, 'data-faz-category=' ), 'under one category label' );
	assert_true( false !== strpos( $out, 'alt=""/>' ), 'and the self-closing slash stays last' );

	echo "\n-- markup inside a script body is JavaScript, not markup --\n";

	// A theme or a page builder that builds HTML in a JS string must not have
	// the string rewritten: the pass would insert double-quoted attributes into
	// a double-quoted literal and the whole inline script would stop parsing.
	$fe     = faz_ob_frontend( $providers, $blocked );
	$script = '<script>var h = "<div style=\'background:url(' . $poster . ')\'>x</div>"; document.body.insertAdjacentHTML("beforeend", h);</script>';
	$html   = '<html><body>' . $script . '<div style="background:url(' . $poster . ')">y</div></body></html>';
	$out    = faz_ob_run( $fe, $html );
	assert_true( false !== strpos( $out, $script ), 'an inline script body leaves the pass byte-identical' );
	assert_true( 1 === substr_count( $out, 'data-faz-style' ), 'while the real element after it is still parked' );
	$tpl  = '<script type="text/template"><div style="background:url(' . $poster . ')"></div></script>';
	$out2 = faz_ob_run( faz_ob_frontend( $providers, $blocked ), '<html><body>' . $tpl . '</body></html>' );
	assert_true( false !== strpos( $out2, $tpl ), 'a script template body is left to whoever renders it' );

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "ALL PASS ({$tests_passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$tests_failed}, passed: {$tests_passed}\n";
	exit( 1 );
}
