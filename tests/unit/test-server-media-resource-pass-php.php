<?php
/**
 * Standalone unit tests for the server-side pass over the media, plug-in,
 * text-track and image-button elements, plus two iframe/img rules the same
 * pass family shares with the runtime.
 *
 * Subsystem: server-media-resource-pass-php
 *
 * <video src|poster>, <audio src>, <source src|srcset>, <embed src>,
 * <object data>, <track src> and <input type=image src> all fetch from the
 * parser exactly as <img src> does, and no pass in class-frontend.php had
 * ever looked at them. The bootstrap defers parser-built markup to the
 * server as the authority, and the runtime's restore branches for
 * data-faz-src, data-faz-srcset, data-faz-poster and data-faz-data were
 * already in place — the server was the one layer not writing them, so a
 * legacy <object data="https://www.youtube.com/embed/ID"> loaded the player
 * document with its cookies before consent, for the whole page view.
 *
 *   1. Each fetching attribute is judged ON ITS OWN and parked under the
 *      key its restore branch reads: src → data-faz-src, srcset →
 *      data-faz-srcset (+ data-faz-srcset-category), poster → data-faz-poster,
 *      data → data-faz-data. A <video> with a blocked poster and a
 *      first-party src keeps its src.
 *   2. First-party, unrecognised, already-parked and whitelisted tags come
 *      out byte-identical; <input> is only an image button when type=image;
 *      custom elements whose name starts like a media tag are untouched.
 *   3. The "lite embed" frame — an ALLOWED srcdoc next to a BLOCKED src —
 *      has its src parked, with no placeholder and NOT hidden: the frame
 *      shows the allowed facade, and hiding it left a hole in the page. The
 *      runtime applies the same rule to the same frame. The engine ignores
 *      src while srcdoc is present, but the facade's own Play button removes
 *      srcdoc and activates a src nothing had gated.
 *   4. An <img> whose src is a data: URI is not parked: the browser decodes
 *      it in place and an SVG shown through <img> can neither fetch nor
 *      run script, so a provider URL inside the payload is text, not a
 *      beacon. A data: iframe IS still parked — its payload is a document
 *      whose fetches no gate can reach once parsed.
 *
 * Run: php tests/unit/test-server-media-resource-pass-php.php
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

	// Double for the placeholder the iframe pass wraps a blocked frame in. It
	// marks its output so a case can tell a wrapped frame from a bare one.
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
	if ( ! function_exists( 'wp_salt' ) ) {
		function wp_salt( $scheme = 'auth' ) {
			return 'test-salt-' . $scheme;
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

	function faz_ob_frontend( array $providers, array $blocked, array $whitelist = array(), array $service_consent = array(), array $pattern_services = array() ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		$preset = array(
			'blocked_categories_cache'  => $blocked,
			'provider_map_cache'        => $providers,
			'service_consent_cache'     => $service_consent,
			'whitelist_cache'           => $whitelist,
			'settings_option_cache'     => array(),
			'pattern_service_cache'     => $pattern_services,
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

	/** The raw value of the first attribute of this name, quotes included, or null. */
	function faz_raw_attr( $html, $name ) {
		if ( preg_match( '/(?<![\w:-])' . preg_quote( $name, '/' ) . '=("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $html, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/** Whether a LIVE attribute of this name (not a data-faz-* one) is left in the markup. */
	function faz_has_live_attr( $html, $name ) {
		return 1 === preg_match( '/(?<![\w:-])' . preg_quote( $name, '/' ) . '\s*=/i', $html );
	}

	$providers = array(
		'www.youtube.com/embed' => 'marketing',
		'i.ytimg.com'           => 'marketing',
		'player.vimeo.com'      => 'marketing',
		'cdn.tracker.test'      => 'marketing',
		'tile.openstreetmap.org' => 'functional',
	);
	$blocked   = array( 'marketing' );

	echo "\n-- <object data> and <embed src>: the legacy player embeds --\n";

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><object data="https://www.youtube.com/embed/ID" width="560" type="text/html"><p>fallback</p></object></body></html>' );
	assert_true( '"https://www.youtube.com/embed/ID"' === faz_raw_attr( $out, 'data-faz-data' ), '<object data> is parked into data-faz-data' );
	assert_true( ! faz_has_live_attr( $out, 'data' ), 'with no live data attribute left' );
	assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), 'under the provider category' );
	assert_true( false !== strpos( $out, 'width="560" type="text/html"><p>fallback</p></object>' ), 'and the rest of the element, its fallback content included, is untouched' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><embed src="https://www.youtube.com/embed/ID" type="video/mp4"></body></html>' );
	assert_true( '"https://www.youtube.com/embed/ID"' === faz_raw_attr( $out, 'data-faz-src' ), '<embed src> is parked into data-faz-src' );
	assert_true( ! faz_has_live_attr( $out, 'src' ), 'with no live src' );

	echo "\n-- <video>: src and poster, each on its own --\n";

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><video poster="https://i.ytimg.com/vi/ID/hqdefault.jpg" src="https://player.vimeo.com/external/1.mp4" controls></video></body></html>' );
	assert_true( '"https://i.ytimg.com/vi/ID/hqdefault.jpg"' === faz_raw_attr( $out, 'data-faz-poster' ), 'a blocked poster is parked into data-faz-poster' );
	assert_true( '"https://player.vimeo.com/external/1.mp4"' === faz_raw_attr( $out, 'data-faz-src' ), 'and a blocked src into data-faz-src' );
	assert_true( ! faz_has_live_attr( $out, 'poster' ) && ! faz_has_live_attr( $out, 'src' ), 'neither is left live' );
	assert_true( false !== strpos( $out, ' controls></video>' ), 'the boolean attribute and the closing tag survive' );
	assert_true( 1 === substr_count( $out, 'data-faz-category=' ), 'one category label' );

	// The two attributes can name different hosts: the verdict is per attribute.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><video poster="https://i.ytimg.com/vi/ID/hqdefault.jpg" src="/wp-content/uploads/clip.mp4"></video></body></html>' );
	assert_true( null !== faz_raw_attr( $out, 'data-faz-poster' ), 'a blocked poster next to a first-party src is parked' );
	assert_true( '"/wp-content/uploads/clip.mp4"' === faz_raw_attr( $out, 'src' ), 'while the first-party src stays live' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><video poster="/wp-content/uploads/p.jpg" src="https://player.vimeo.com/external/1.mp4"></video></body></html>' );
	assert_true( '"/wp-content/uploads/p.jpg"' === faz_raw_attr( $out, 'poster' ), 'a first-party poster stays live' );
	assert_true( null !== faz_raw_attr( $out, 'data-faz-src' ), 'next to a parked src' );

	// A poster in a category that is NOT blocked is left alone.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><video poster="https://tile.openstreetmap.org/1/1/1.png" src="/v.mp4"></video></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'a poster whose category is allowed comes out byte-identical' );

	echo "\n-- <audio>, <source>, <track>, <input type=image> --\n";

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><audio src="https://player.vimeo.com/external/1.mp3" controls></audio></body></html>' );
	assert_true( '"https://player.vimeo.com/external/1.mp3"' === faz_raw_attr( $out, 'data-faz-src' ) && ! faz_has_live_attr( $out, 'src' ), '<audio src> is parked' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><video controls><source src="https://player.vimeo.com/external/1.mp4" type="video/mp4"><source src="/local.webm" type="video/webm"></video></body></html>' );
	assert_true( '"https://player.vimeo.com/external/1.mp4"' === faz_raw_attr( $out, 'data-faz-src' ), 'a <source src> naming a blocked provider is parked' );
	assert_true( false !== strpos( $out, '<source src="/local.webm" type="video/webm">' ), 'its first-party sibling is untouched' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><picture><source srcset="https://cdn.tracker.test/px.png 1x, https://cdn.tracker.test/px@2x.png 2x" type="image/webp"><img src="/local.png" alt=""></picture></body></html>' );
	assert_true( '"https://cdn.tracker.test/px.png 1x, https://cdn.tracker.test/px@2x.png 2x"' === faz_raw_attr( $out, 'data-faz-srcset' ), 'a <source srcset> is parked into data-faz-srcset' );
	assert_true( false !== strpos( $out, 'data-faz-srcset-category="marketing"' ), 'with the category its own restore reads' );
	assert_true( ! faz_has_live_attr( $out, 'srcset' ), 'and no srcset left live' );
	assert_true( false !== strpos( $out, '<img src="/local.png" alt="">' ), 'the <img> fallback is untouched' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><video><track src="https://cdn.tracker.test/sub.vtt" kind="subtitles" srclang="en"></video></body></html>' );
	assert_true( '"https://cdn.tracker.test/sub.vtt"' === faz_raw_attr( $out, 'data-faz-src' ) && ! faz_has_live_attr( $out, 'src' ), '<track src> is parked' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><form><input type="image" src="https://cdn.tracker.test/btn.png" alt="Go"><input type="text" name="q" value="https://cdn.tracker.test/x"></form></body></html>' );
	assert_true( '"https://cdn.tracker.test/btn.png"' === faz_raw_attr( $out, 'data-faz-src' ), 'an image button is parked' );
	assert_true( false !== strpos( $out, '<input type="text" name="q" value="https://cdn.tracker.test/x">' ), 'a text input naming the same host in its value is untouched' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><input type="submit" src="https://cdn.tracker.test/btn.png"></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'src on an input that is not an image button fetches nothing and is left alone' );

	echo "\n-- what is left alone --\n";

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><video src="/v.mp4" poster="/p.jpg"></video><audio src="/a.mp3"></audio><object data="/doc.pdf"></object><embed src="/e.swf"><track src="/s.vtt"><input type="image" src="/b.png"></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'first-party resources on every tag come out byte-identical' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><video src="https://unknown.example/v.mp4" poster="https://unknown.example/p.jpg"></video></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'an unrecognised third party is not gated by this pass' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><video data-faz-category="marketing" data-faz-src="https://player.vimeo.com/x.mp4" data-faz-poster="https://i.ytimg.com/p.jpg"></video><object data-faz-category="marketing" data-faz-data="https://www.youtube.com/embed/ID"></object></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'an already-parked tag is not rewritten twice' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><embed class="faz-skip" src="https://www.youtube.com/embed/ID"></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'faz-skip exempts the tag, like every other pass' );

	$fe  = faz_ob_frontend( $providers, $blocked, array( 'player.vimeo.com' ) );
	$out = faz_ob_run( $fe, '<html><body><video poster="https://i.ytimg.com/vi/ID/hq.jpg" src="https://player.vimeo.com/external/1.mp4"></video></body></html>' );
	assert_true( '"https://player.vimeo.com/external/1.mp4"' === faz_raw_attr( $out, 'src' ), 'a whitelisted src stays live' );
	assert_true( null !== faz_raw_attr( $out, 'data-faz-poster' ), 'while the poster next to it, which is not whitelisted, is still parked' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><video><source src="https://player.vimeo.com/x.mp4"/></video></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-src="https://player.vimeo.com/x.mp4"/>' ), 'a self-closing <source> keeps its slash where it was' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><video-player src="https://player.vimeo.com/x.mp4"></video-player><source-list src="https://cdn.tracker.test/l.json"></source-list><input-image src="https://cdn.tracker.test/x.png"></input-image></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'custom elements whose name starts like a media tag pass through byte-identical' );

	echo "\n-- the lite-embed frame: an allowed srcdoc with a blocked src --\n";

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><iframe srcdoc="<button class=play>Play</button>" src="https://cdn.tracker.test/embed/ID" width="300"></iframe></body></html>' );
	assert_true( '"https://cdn.tracker.test/embed/ID"' === faz_raw_attr( $out, 'data-faz-src' ), 'the blocked src is parked into data-faz-src' );
	assert_true( ! faz_has_live_attr( $out, 'src' ), 'and not left live for the facade\'s Play button to activate' );
	assert_true( '"<button class=play>Play</button>"' === faz_raw_attr( $out, 'srcdoc' ), 'the allowed srcdoc stays live, byte-for-byte' );
	assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), 'under the src\'s category' );
	assert_true( false === strpos( $out, 'faz-hidden' ), 'and left visible: it shows the allowed facade, not the parked src, so hiding it only left a hole' );
	assert_true( false === strpos( $out, 'faz-placeholder' ), 'with no placeholder wrap: the srcdoc marker would not survive wp_kses on the way into the template' );
	assert_true( false !== strpos( $out, 'width="300"></iframe>' ), 'the remaining attributes and the closing tag survive' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><iframe srcdoc="<p>lite</p>" src="/embedded/local.html"></iframe></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'an allowed srcdoc with a first-party src comes out byte-identical' );

	echo "\n-- a data: src on <img> is text, on <iframe> a document --\n";

	$svg = 'data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cimage%20href=%22https://cdn.tracker.test/px.png%22/%3E%3C/svg%3E';
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><img src="' . $svg . '" alt=""></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'an <img> whose data: payload names a provider is not parked: SVG-as-image fetches nothing' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><img src="' . $svg . '" srcset="https://cdn.tracker.test/px.png 1x"></body></html>' );
	assert_true( null !== faz_raw_attr( $out, 'data-faz-srcset' ), 'but a srcset standing next to it is still judged and parked' );

	$doc = 'data:text/html,%3Cscript%20src=%22https://cdn.tracker.test/t.js%22%3E%3C/script%3E';
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><iframe src="' . $doc . '"></iframe></body></html>' );
	assert_true( '"' . $doc . '"' === faz_raw_attr( $out, 'data-faz-src' ), 'a data: iframe whose document loads a tracker IS parked: nothing gates the sub-document once it is parsed' );

	echo "\n-- tag-shaped text inside another tag's attribute value is not an element --\n";

	// Tooltip and popover libraries keep HTML in data-* and title. The media
	// pattern started a match at `<video` wherever it stood, so a value like
	// the one below was rewritten in place: the parked attributes it injected
	// carry double quotes, which closed the outer value early and broke the
	// markup. The linear fallback already left such values alone; the pass now
	// walks whole opening tags, as the style-attribute pass does.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><span class="tip" data-tip=\'<video poster="https://i.ytimg.com/vi/ID/hq.jpg">x\'>hover</span><span title="<embed src=\'https://www.youtube.com/embed/ID\'>">t</span></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'media tags quoted inside data-tip and title come out byte-identical' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><span data-tip=\'<video poster="https://i.ytimg.com/a.jpg">\'>h</span><video poster="https://i.ytimg.com/vi/ID/hqdefault.jpg"></video></body></html>' );
	assert_true( false !== strpos( $out, 'data-tip=\'<video poster="https://i.ytimg.com/a.jpg">\'' ), 'the quoted one is left as it was' );
	assert_true( '"https://i.ytimg.com/vi/ID/hqdefault.jpg"' === faz_raw_attr( $out, 'data-faz-poster' ), 'while the real <video> after it is still parked' );

	echo "\n-- <img> and <iframe> quoted inside another tag's attribute value --\n";

	// The <img>, <iframe> and srcdoc patterns were anchored on their tag
	// names, so a match started wherever `<img` or `<iframe` stood, inside a
	// tooltip's data-html or a title included. The double-quoted keys they
	// wrote there closed the outer value early and broke the markup, and the
	// placeholder the iframe pass wraps a frame in landed inside the value.
	$quoted = array(
		'img in a double-quoted data-html'   => '<div data-html="<img src=\'https://cdn.tracker.test/p.png\'>">x</div>',
		'img in a single-quoted data-tip'    => '<span data-tip=\'<img src="https://cdn.tracker.test/p.png" width="1">\'>h</span>',
		'iframe in a title'                  => '<span title=\'<iframe src="https://www.youtube.com/embed/ID"></iframe>\'>t</span>',
		'self-closed iframe in a data-*'     => '<a data-embed=\'<iframe src="https://www.youtube.com/embed/ID"/>\' href="/v">v</a>',
		'iframe srcdoc in a data-*'          => '<i data-x=\'<iframe srcdoc="<img src=https://cdn.tracker.test/p.png>"></iframe>\'></i>',
		'img in an end tag\'s attribute'     => '<p>a</p title="<img src=\'https://cdn.tracker.test/p.png\'>">',
	);
	foreach ( $quoted as $what => $snippet ) {
		$fe   = faz_ob_frontend( $providers, $blocked );
		$html = '<html><body>' . $snippet . '</body></html>';
		assert_true( faz_ob_run( $fe, $html ) === $html, $what . ': byte-identical' );
	}

	// The real tags after a quoted one are judged as before.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><div data-html="<img src=\'https://cdn.tracker.test/q.png\'>">x</div><img src="https://cdn.tracker.test/p.png" width="1"></body></html>' );
	assert_true( false !== strpos( $out, '<div data-html="<img src=\'https://cdn.tracker.test/q.png\'>">x</div>' ), 'the quoted <img> is left as it was' );
	assert_true( '"https://cdn.tracker.test/p.png"' === faz_raw_attr( $out, 'data-faz-src' ) && 1 === substr_count( $out, 'data-faz-src=' ), 'while the real <img> after it is parked, and only that one' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><span title=\'<iframe src="https://www.youtube.com/embed/Q"></iframe>\'>t</span><iframe src="https://www.youtube.com/embed/ID" width="560"></iframe></body></html>' );
	assert_true( false !== strpos( $out, '<span title=\'<iframe src="https://www.youtube.com/embed/Q"></iframe>\'>t</span>' ), 'the quoted <iframe> is left as it was' );
	assert_true( '"https://www.youtube.com/embed/ID"' === faz_raw_attr( $out, 'data-faz-src' ) && 1 === substr_count( $out, 'faz-placeholder"' ), 'while the real <iframe> after it is parked behind one placeholder' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><i data-x=\'<iframe srcdoc="<img src=https://cdn.tracker.test/q.png>"></iframe>\'></i><iframe srcdoc="<img src=https://cdn.tracker.test/p.png>"></iframe></body></html>' );
	assert_true( false !== strpos( $out, '<i data-x=\'<iframe srcdoc="<img src=https://cdn.tracker.test/q.png>"></iframe>\'></i>' ), 'a quoted srcdoc is left as it was' );
	assert_true( 1 === substr_count( $out, 'data-faz-srcdoc=' ) && false !== strpos( $out, 'data-faz-srcdoc="<img src=https://cdn.tracker.test/p.png>"' ), 'while the real srcdoc after it is parked' );

	echo "\n-- media and style attributes inside end tags and bogus comments --\n";

	// The media and style-attribute passes walk every OPENING tag, which kept
	// a quoted value inside one whole, but nothing consumed the other tokens
	// the tokenizer reads in one piece: an end tag with an attribute, `<?…>`,
	// `<!x…>` and `</ …>`. The regex then moved on one character at a time,
	// started a match at the `<video` or `<b style` inside the value or the
	// comment, and the double-quoted keys it wrote closed the value early.
	$tokens = array(
		'video in an end tag\'s attribute'      => '<p>a</p title="<video poster=\'https://i.ytimg.com/a.jpg\'>">',
		'embed in an end tag\'s attribute'      => '<p>a</p data-x=\'<embed src="https://www.youtube.com/embed/ID">\'>',
		'video in a processing instruction'    => '<?x <video poster="https://i.ytimg.com/a.jpg">',
		'video in a bogus <!x comment'         => '<!x <video poster="https://i.ytimg.com/a.jpg">',
		'video in a </ bogus comment'          => '</ <video poster="https://i.ytimg.com/a.jpg">',
		'style attribute in an end tag\'s value' => '<p>a</p title="<b style=\'background:url(https://i.ytimg.com/a.jpg)\'>">',
		'style attribute in a bogus comment'   => '<!x <b style="background:url(https://i.ytimg.com/a.jpg)">',
	);
	foreach ( $tokens as $what => $snippet ) {
		$fe   = faz_ob_frontend( $providers, $blocked );
		$html = '<html><body>' . $snippet . '</body></html>';
		assert_true( faz_ob_run( $fe, $html ) === $html, $what . ': byte-identical' );
	}

	// The real elements after such a token are judged as before.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><p>a</p title="<video poster=\'https://i.ytimg.com/a.jpg\'>"><video poster="https://i.ytimg.com/vi/ID/hqdefault.jpg"></video></body></html>' );
	assert_true( false !== strpos( $out, '</p title="<video poster=\'https://i.ytimg.com/a.jpg\'>">' ), 'the end tag is left as it was' );
	assert_true( '"https://i.ytimg.com/vi/ID/hqdefault.jpg"' === faz_raw_attr( $out, 'data-faz-poster' ) && 1 === substr_count( $out, 'data-faz-poster=' ), 'while the real <video> after it is parked, and only that one' );

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><!x <b style="background:url(https://i.ytimg.com/a.jpg)"><div style="background:url(https://i.ytimg.com/vi/ID/hq.jpg)">x</div></body></html>' );
	assert_true( false !== strpos( $out, '<!x <b style="background:url(https://i.ytimg.com/a.jpg)">' ), 'the bogus comment is left as it was' );
	assert_true( false === strpos( $out, '<div style="background:url(https://i.ytimg.com/vi/ID/hq.jpg)">' ), 'while the real style attribute after it is still gated' );

	// Upper-case and unquoted spellings of the real tags still match.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><IMG SRC=https://cdn.tracker.test/p.png><IFRAME SRC=https://www.youtube.com/embed/ID></IFRAME></body></html>' );
	assert_true( 2 === substr_count( $out, 'data-faz-src=' ), 'upper-case unquoted <IMG> and <IFRAME> are still parked' );

	echo "\n-- the raw-text bodies of iframe, noembed, noframes and plaintext --\n";

	// The browser reads these bodies as text up to their end tag (plaintext:
	// to the end of the document). The opening-tag walk did not: a quoted
	// value of a tag-shaped string inside the body ran past the end tag and
	// swallowed the real media element after it, which then shipped live.
	foreach ( array( 'noembed', 'noframes' ) as $raw ) {
		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><body><' . $raw . '><a title="</' . $raw . '><video src="https://cdn.tracker.test/v.mp4"></video></body></html>' );
		assert_true( '"https://cdn.tracker.test/v.mp4"' === faz_raw_attr( $out, 'data-faz-src' ) && false === strpos( $out, '<video src=' ), "a <video> after an open quote in a <{$raw}> body is still parked" );
		assert_true( false !== strpos( $out, '<' . $raw . '><a title="</' . $raw . '>' ), "and the <{$raw}> body comes back as it was" );

		$fe  = faz_ob_frontend( $providers, $blocked );
		$out = faz_ob_run( $fe, '<html><body><' . $raw . '><a title="<video src=https://cdn.tracker.test/v.mp4>"></' . $raw . '><video src="https://cdn.tracker.test/v.mp4"></video></body></html>' );
		assert_true( 1 === substr_count( $out, 'data-faz-src=' ) && false !== strpos( $out, '<a title="<video src=https://cdn.tracker.test/v.mp4>">' ), "<{$raw}>: the text in the body is left alone, the real <video> after it is parked" );
	}

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><iframe src="/local.html"><a title="</iframe><video src="https://cdn.tracker.test/v.mp4"></video></body></html>' );
	assert_true( '"https://cdn.tracker.test/v.mp4"' === faz_raw_attr( $out, 'data-faz-src' ) && false === strpos( $out, '<video src=' ), 'a <video> after an open quote in an <iframe> fallback body is still parked' );
	assert_true( false !== strpos( $out, '<iframe src="/local.html"><a title="</iframe>' ), 'and the first-party iframe comes back as it was' );

	// The iframe pass still sees the opening tag and parks a blocked frame,
	// with its fallback body restored inside the placeholder.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><iframe src="https://www.youtube.com/embed/ID" width="560"><p>fallback</p></iframe></body></html>' );
	assert_true( '"https://www.youtube.com/embed/ID"' === faz_raw_attr( $out, 'data-faz-src' ) && false !== strpos( $out, 'faz-placeholder' ), 'a blocked iframe with a body is still parked behind its placeholder' );
	assert_true( false !== strpos( $out, '<p>fallback</p></iframe>' ) && false === strpos( $out, '__FAZ_' ), 'with its fallback body restored and no stash marker left' );

	// Everything after <plaintext> is text to the parser: nothing there is an
	// element, so nothing there is rewritten.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><video src="https://cdn.tracker.test/a.mp4"></video><plaintext><video src="https://cdn.tracker.test/b.mp4"></video></body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( '"https://cdn.tracker.test/a.mp4"' === faz_raw_attr( $out, 'data-faz-src' ), 'a <video> before <plaintext> is parked' );
	assert_true( false !== strpos( $out, '<plaintext><video src="https://cdn.tracker.test/b.mp4"></video></body></html>' ), 'and the text after <plaintext> is left as it was' );

	// Foreign content: inside <svg> nothing is raw text, so nothing is stashed.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><svg><noembed><video src="https://cdn.tracker.test/s.mp4"></video></noembed></svg></body></html>' );
	assert_true( '"https://cdn.tracker.test/s.mp4"' === faz_raw_attr( $out, 'data-faz-src' ), 'inside <svg> a <noembed> body is not raw text and its <video> is still judged' );

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "ALL PASS ({$tests_passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$tests_failed}, passed: {$tests_passed}\n";
	exit( 1 );
}
