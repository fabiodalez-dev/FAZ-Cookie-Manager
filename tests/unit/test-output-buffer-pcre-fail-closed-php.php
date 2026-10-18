<?php
/**
 * Standalone unit tests — a markup pass that PCRE cannot complete is re-run
 * over a linear walk, so the page never ships with its trackers live.
 *
 * Subsystem: output-buffer-pcre-fail-closed-php
 *
 * preg_replace_callback() returns null for the whole document when it hits a
 * PCRE limit anywhere in it, and process_output_buffer() used to log that and
 * serve the buffer as it was: a megabyte of unterminated inline script after
 * a Meta Pixel tag exhausted pcre.backtrack_limit in the lazy body of the
 * script pattern, and the pixel shipped executable before consent.
 * filter_content_blocking() tried to strip the scripts instead, with a second
 * lazy regex that failed on the same input and returned the content as it was.
 *
 *   1. The script pass fails and the tracker before the failure is parked,
 *      in the shape the runtime restores (text/plain + data-faz-category).
 *   2. The linear walk judges exactly as the regex pass: every element of a
 *      document full of exemptions (own FAZ asset, JSON-LD, whitelist, a
 *      necessary-category provider, a `<script` inside an attribute value,
 *      a tag inside a script body) comes out byte-identical to what the
 *      working pass produces for the same document.
 *   3. The <iframe> and <style> passes fail closed the same way.
 *   4. the_content: the tracker is parked, not shipped.
 *   5. `faz_output_buffer_fail_closed` false restores the old skip.
 *   6. The failure is logged once, not once per request.
 *
 * Run: php tests/unit/test-output-buffer-pcre-fail-closed-php.php
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

	$GLOBALS['faz_test_filters']    = array();
	$GLOBALS['faz_test_transients'] = array();

	function wp_strip_all_tags( $str ) {
		return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
	}
	function sanitize_text_field( $str ) {
		$str = (string) $str;
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( wp_strip_all_tags( $str ) );
	}
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
	function apply_filters( $tag, $value ) {
		return array_key_exists( $tag, $GLOBALS['faz_test_filters'] ) ? $GLOBALS['faz_test_filters'][ $tag ] : $value;
	}
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); // phpcs:ignore
	}
	function get_transient( $key ) {
		return isset( $GLOBALS['faz_test_transients'][ $key ] ) ? $GLOBALS['faz_test_transients'][ $key ] : false;
	}
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['faz_test_transients'][ $key ] = $value;
		return true;
	}
	function is_admin() {
		return false;
	}
	function wp_doing_cron() {
		return false;
	}
	function faz_is_machine_readable_request() {
		return false;
	}
	function faz_disable_banner() {
		return false;
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

	/** Settings double: banner on, nothing excluded. */
	class Faz_Test_Settings {
		public function get( $group, $key ) {
			return 'status' === $key ? true : null;
		}
	}

	function faz_ob_frontend( array $providers, array $blocked, array $whitelist = array() ) {
		$rc     = new ReflectionClass( Frontend::class );
		$fe     = $rc->newInstanceWithoutConstructor();
		$preset = array(
			'blocked_categories_cache'  => $blocked,
			'provider_map_cache'        => $providers,
			'service_consent_cache'     => array(),
			'whitelist_cache'           => $whitelist,
			'settings_option_cache'     => array(),
			'pattern_service_cache'     => array(),
			'provider_match_meta_cache' => null,
			'service_match_meta_cache'  => null,
			'template'                  => array( 'html' => '<div></div>' ),
			'settings'                  => new Faz_Test_Settings(),
		);
		foreach ( $preset as $prop => $value ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $fe, $value );
		}
		return $fe;
	}

	function faz_ob_run( $html, array $whitelist = array() ) {
		global $providers, $blocked;
		$m = new ReflectionMethod( Frontend::class, 'process_output_buffer' );
		$m->setAccessible( true );
		return $m->invoke( faz_ob_frontend( $providers, $blocked, $whitelist ), $html );
	}

	/** A <script> opened and never closed, a few MB long: the lazy body exhausts the backtrack limit. */
	function faz_pathological_tail( $tag = 'script' ) {
		return '<' . $tag . '>var big = "' . str_repeat( 'x', 3000000 );
	}

	function faz_is_live_tracker( $out ) {
		return 1 === preg_match( '#<script(?![^>]*text/plain)[^>]*src="https://connect\.facebook\.net/en_US/fbevents\.js"#', substr( $out, 0, 20000 ) );
	}

	$providers = array(
		'connect.facebook.net'       => 'marketing',
		'www.facebook.com/tr'        => 'marketing',
		'youtube.com/embed'          => 'marketing',
		'fonts.googleapis.com'       => 'marketing',
		'fbq('                       => 'marketing',
		'faz-cookie-manager/assets'  => 'marketing',
		'cdn.necessary.test'         => 'necessary',
	);
	$blocked   = array( 'marketing' );
	$tracker   = '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>';

	// Writes go to a file so the once-an-hour rule can be counted.
	$faz_log = tempnam( sys_get_temp_dir(), 'faz-pcre-log' );
	ini_set( 'error_log', $faz_log ); // phpcs:ignore

	echo "\n-- 1. the script pass fails: the tracker before the failure is parked --\n";

	$html = '<html><head>' . $tracker . '</head><body><p>hi</p>' . faz_pathological_tail();
	$out  = faz_ob_run( $html );
	assert_true( ! faz_is_live_tracker( $out ), 'the Meta Pixel script is not served executable' );
	assert_true( false !== strpos( $out, '<script data-faz-category="marketing" type="text/plain" src="https://connect.facebook.net/en_US/fbevents.js"></script>' ), 'it is parked as the working pass parks it: text/plain + data-faz-category' );
	assert_true( strlen( $out ) === strlen( $html ) + strlen( ' data-faz-category="marketing" type="text/plain"' ), 'nothing else in the document changed, the unterminated tail included' );

	echo "\n-- 2. the linear walk judges as the regex pass does --\n";

	$doc = '<html><head>'
		. '<script src="https://example.com/wp-content/uploads/faz-cookie-manager/assets/config-abc.js" id="faz-cookie-manager-static-config-js"></script>'
		. '<script type="application/ld+json">{"x":"fbq(1)"}</script>'
		. '<script src="https://cdn.necessary.test/lib.js"></script>'
		. '<script src="https://example.com/app.js"></script>'
		. '<script>var tpl = "<script src=\"https://connect.facebook.net/x.js\"><\/script>";</script>'
		. '<script>fbq("init","1");</script>'
		. '<script id="wl" src="https://www.facebook.com/tr/allowed.js"></script>'
		. '<script type="module" src="https://connect.facebook.net/mod.js"></script>'
		. '<script SRC=\'https://connect.facebook.net/b.js\' async/>'
		. "<script\nsrc=https://connect.facebook.net/c.js></script >"
		. '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=X">'
		. '</head><body>'
		. '<div title="<script src=https://connect.facebook.net/in-attr.js></script>">a</div>'
		. '</p title="<script>">'
		. '<!-- <script src="https://connect.facebook.net/in-comment.js"></script> -->'
		. '<textarea><script src="https://connect.facebook.net/in-textarea.js"></script></textarea>'
		. '<svg><script href="https://connect.facebook.net/in-svg.js"></script></svg>'
		. '<img src="https://www.facebook.com/tr?id=1" alt=x />'
		. '<iframe src="https://youtube.com/embed/abc"></iframe>'
		. '<noscript><img src="https://www.facebook.com/tr?id=2"></noscript>'
		. '<script data-faz-waitfor="marketing">window.after = 1;</script>'
		. '</body>';
	$whitelist = array( 'www.facebook.com/tr/allowed.js' );
	$normal    = faz_ob_run( $doc, $whitelist );
	assert_true( $normal !== $doc, 'sanity: the working pass changes the document' );
	$failed = faz_ob_run( $doc . faz_pathological_tail(), $whitelist );
	assert_true( substr( $failed, 0, strlen( $normal ) ) === $normal, 'the document judged by the fallback is byte-identical to the working pass' );
	assert_true( substr( $failed, strlen( $normal ) ) === faz_pathological_tail(), 'and the unterminated tail is left as it was' );
	assert_true( false !== strpos( $failed, 'id="faz-cookie-manager-static-config-js"></script>' ) && false === strpos( $failed, 'text/plain" src="https://example.com/wp-content/uploads/faz-cookie-manager' ), 'own FAZ asset stays live' );
	assert_true( false !== strpos( $failed, '<script type="application/ld+json">{"x":"fbq(1)"}</script>' ), 'JSON-LD stays as it is' );
	assert_true( false !== strpos( $failed, '<script src="https://cdn.necessary.test/lib.js"></script>' ), 'necessary-category provider stays live' );
	assert_true( false !== strpos( $failed, '<script id="wl" src="https://www.facebook.com/tr/allowed.js"></script>' ), 'whitelisted script stays live' );

	echo "\n-- 3. other lazy passes fail closed too --\n";

	$iframe_doc = '<body><iframe src="https://youtube.com/embed/abc"></iframe><p>x</p>';
	$normal     = faz_ob_run( $iframe_doc );
	$failed     = faz_ob_run( $iframe_doc . faz_pathological_tail( 'iframe' ) );
	assert_true( false !== strpos( $normal, 'data-faz-src="https://youtube.com/embed/abc"' ), 'sanity: the working iframe pass parks the player' );
	assert_true( substr( $failed, 0, strlen( $normal ) ) === $normal, 'iframe pass: judged by the fallback exactly as by the regex' );
	assert_true( false === strpos( substr( $failed, 0, 2000 ), ' src="https://youtube.com/embed/abc"' ), 'iframe pass: the player is not served with a live src' );

	$style_doc = '<head><style>@import url("https://fonts.googleapis.com/css?family=Y");</style></head>';
	$normal    = faz_ob_run( $style_doc );
	$failed    = faz_ob_run( $style_doc . faz_pathological_tail( 'style' ) );
	assert_true( $normal !== $style_doc, 'sanity: the working style pass neutralises the @import' );
	assert_true( substr( $failed, 0, strlen( $normal ) ) === $normal, 'style pass: judged by the fallback exactly as by the regex' );

	echo "\n-- 4. the_content --\n";

	$content = '<p>Post</p>' . $tracker . faz_pathological_tail();
	$out     = faz_ob_frontend( $providers, $blocked )->filter_content_blocking( $content );
	assert_true( ! faz_is_live_tracker( $out ), 'the tracker in post content is not served executable' );
	assert_true( false !== strpos( $out, 'type="text/plain" src="https://connect.facebook.net/en_US/fbevents.js"' ), 'it is parked, not stripped: the runtime can restore it after consent' );

	// The content filter runs the same iframe pattern: an <iframe> quoted in a
	// tooltip's value is text, the real one after it is still a frame.
	$quoted  = '<p data-html=\'<iframe src="https://www.youtube.com/embed/Q"></iframe>\'>x</p>';
	$out     = faz_ob_frontend( $providers, $blocked )->filter_content_blocking( $quoted . '<iframe src="https://www.youtube.com/embed/ID"></iframe>' );
	assert_true( 0 === strpos( $out, $quoted ), 'the_content: an <iframe> quoted in another tag\'s value is left as it was' );
	assert_true( 1 === substr_count( $out, 'data-faz-src="https://www.youtube.com/embed/ID"' ) && 1 === substr_count( $out, 'faz-placeholder"' ), 'the_content: the real <iframe> after it is parked behind one placeholder' );

	echo "\n-- 5. faz_output_buffer_fail_closed = false: the old skip --\n";

	$GLOBALS['faz_test_filters']['faz_output_buffer_fail_closed'] = false;
	$html = '<html><head>' . $tracker . '</head><body>' . faz_pathological_tail();
	$out  = faz_ob_run( $html );
	assert_true( $out === $html, 'the document is served as it was rendered' );
	unset( $GLOBALS['faz_test_filters']['faz_output_buffer_fail_closed'] );

	echo "\n-- 6. the failure is logged once an hour, not per request --\n";

	$GLOBALS['faz_test_transients'] = array();
	file_put_contents( $faz_log, '' );
	faz_ob_run( '<head>' . $tracker . '</head>' . faz_pathological_tail() );
	faz_ob_run( '<head>' . $tracker . '</head>' . faz_pathological_tail() );
	$lines = array_filter( explode( "\n", (string) file_get_contents( $faz_log ) ) );
	assert_true( 1 === count( $lines ), 'two failing requests, one log line (got ' . count( $lines ) . ')' );
	assert_true( 1 === count( $lines ) && false !== strpos( reset( $lines ), 'script (PCRE error 2)' ), 'the line names the pass and the PCRE error' );
	@unlink( $faz_log ); // phpcs:ignore

	echo "\n--\n";
	echo "Tests:  {$tests_run}\n";
	echo "Passed: {$tests_passed}\n";
	echo "Failed: {$tests_failed}\n\n";
	if ( $tests_failed > 0 ) {
		echo "\033[31mFAIL\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mALL PASS\033[0m\n";
}
