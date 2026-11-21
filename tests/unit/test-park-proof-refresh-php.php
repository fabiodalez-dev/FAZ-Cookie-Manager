<?php
/**
 * The output buffer signs the values it parks and re-keys signed parks it
 * serves again.
 *
 * Subsystem: park-proof-refresh-php
 *
 * A park into object data, style or background is restored by the runtime
 * only with a proof keyed to the response. A fragment cached with its parks
 * and embedded by a later response carried the old key and stayed parked
 * after consent. The buffer now signs each park (Park_Proof) and, on any
 * later response, gives a tag whose signature verifies this response's key;
 * forged, changed and author-written proofs are left as they are, and so is
 * the text of a script.
 *
 * Run: php tests/unit/test-park-proof-refresh-php.php
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

	function wp_salt( $scheme = 'auth' ) {
		return 'unit-test-salt-' . $scheme;
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-park-proof.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-embed-inventory.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;
	use FazCookie\Frontend\Includes\Park_Proof;

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


	function faz_key() {
		$m = new ReflectionMethod( Frontend::class, 'srcdoc_key' );
		$m->setAccessible( true );
		return $m->invoke( null );
	}
	function faz_attr_value( $html, $name ) {
		$raw = faz_raw_attr( $html, $name );
		if ( null === $raw ) {
			return null;
		}
		$raw = ( '"' === $raw[0] || "'" === $raw[0] ) ? substr( $raw, 1, -1 ) : $raw;
		return html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	$providers = array(
		'i.ytimg.com'       => 'marketing',
		'www.youtube.com'   => 'marketing',
		'cdn.tracker.test'  => 'marketing',
	);
	$blocked   = array( 'marketing' );
	$poster    = 'https://i.ytimg.com/vi/ID/maxresdefault.jpg';
	$key       = faz_key();

	echo "\n-- the server signs what it parks --\n";

	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), '<html><body><div style="background-image:url(&quot;' . $poster . '&quot;)">x</div></body></html>' );
	$val = faz_attr_value( $out, 'data-faz-style' );
	assert_true( $key === faz_attr_value( $out, 'data-faz-park-key' ) && 'style' === faz_attr_value( $out, 'data-faz-park' ), 'a parked style carries this response\'s proof' );
	assert_true( Park_Proof::signature( array( 'style' ), array( $val ) ) === faz_attr_value( $out, 'data-faz-park-sig' ), 'and a signature over the value the browser reads (references decoded)' );

	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), '<html><body><object data="https://www.youtube.com/embed/ID" type="text/html"></object></body></html>' );
	assert_true( Park_Proof::signature( array( 'data' ), array( 'https://www.youtube.com/embed/ID' ) ) === faz_attr_value( $out, 'data-faz-park-sig' ), 'a parked <object data> is signed' );

	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), '<html><body><table><tr><td background="https://cdn.tracker.test/bg.png">x</td></tr></table></body></html>' );
	assert_true( Park_Proof::signature( array( 'background' ), array( 'https://cdn.tracker.test/bg.png' ) ) === faz_attr_value( $out, 'data-faz-park-sig' ), 'a parked background is signed' );

	$out = faz_ob_run( faz_ob_frontend( $providers, $blocked ), '<html><body><div data-faz-park-sig="' . str_repeat( 'a', 64 ) . '" style="background:url(' . $poster . ')">x</div></body></html>' );
	assert_true( 1 === substr_count( $out, 'data-faz-park-sig=' ) && str_repeat( 'a', 64 ) !== faz_attr_value( $out, 'data-faz-park-sig' ), 'an author-written signature on a parked tag is replaced, not kept' );

	echo "\n-- a signed park served by a later response is re-keyed --\n";

	$css    = 'background:url(' . $poster . ')';
	$signed = Park_Proof::signature( array( 'style' ), array( $css ) );
	$frag   = '<div style="background:url(&quot;data:application/octet-stream,&quot;)" data-faz-category="marketing" data-faz-park="style" data-faz-park-key="old-response-key" data-faz-park-sig="' . $signed . '" data-faz-style="' . htmlspecialchars( $css, ENT_QUOTES ) . '">x</div>';

	foreach ( array( 'with categories blocked' => $blocked, 'with nothing blocked' => array() ) as $label => $cats ) {
		$out = faz_ob_run( faz_ob_frontend( $providers, $cats ), '<html><body>' . $frag . '</body></html>' );
		assert_true( $key === faz_attr_value( $out, 'data-faz-park-key' ), "a cached fragment with a valid signature gets this response's key ($label)" );
		assert_true( $signed === faz_attr_value( $out, 'data-faz-park-sig' ) && $css === faz_attr_value( $out, 'data-faz-style' ), "with its signature and parked value unchanged ($label)" );
	}

	$forged = str_replace( $signed, str_repeat( 'c', 64 ), $frag );
	$out    = faz_ob_run( faz_ob_frontend( $providers, array() ), '<html><body>' . $forged . '</body></html>' );
	assert_true( 'old-response-key' === faz_attr_value( $out, 'data-faz-park-key' ), 'a forged signature is not re-keyed' );

	$swapped = str_replace( htmlspecialchars( $css, ENT_QUOTES ), 'background:url(https://evil.test/x)', $frag );
	$out     = faz_ob_run( faz_ob_frontend( $providers, array() ), '<html><body>' . $swapped . '</body></html>' );
	assert_true( 'old-response-key' === faz_attr_value( $out, 'data-faz-park-key' ), 'a value changed after it was signed is not re-keyed' );

	$author = '<div data-faz-park="style" data-faz-park-key="guess" data-faz-style="background:url(https://evil.test/x)">x</div>';
	$out    = faz_ob_run( faz_ob_frontend( $providers, array() ), '<html><body>' . $author . '</body></html>' );
	assert_true( false !== strpos( $out, $author ), 'an author-written proof without a signature is left byte-identical' );

	$in_script = '<script>var t = \'' . str_replace( "'", "\\'", $frag ) . '\';</script>';
	$out       = faz_ob_run( faz_ob_frontend( $providers, array() ), '<html><body>' . $in_script . '</body></html>' );
	assert_true( false !== strpos( $out, $in_script ), 'a signed tag inside a script string is not an element and is not rewritten' );

	echo "\n-- a cached park on a tag that gets one more park keeps its own --\n";

	$bg     = 'https://cdn.tracker.test/bg.png';
	$bg_sig = Park_Proof::signature( array( 'background' ), array( $bg ) );
	$td     = '<table><tr><td style="background-image:url(' . $poster . ')" data-faz-category="marketing" data-faz-park="background" data-faz-park-key="old-response-key" data-faz-park-sig="' . $bg_sig . '" data-faz-background="' . $bg . '">x</td></tr></table>';
	$out    = faz_ob_run( faz_ob_frontend( $providers, $blocked ), '<html><body>' . $td . '</body></html>' );
	$names  = preg_split( '/\s+/', (string) faz_attr_value( $out, 'data-faz-park' ), -1, PREG_SPLIT_NO_EMPTY );
	sort( $names );
	assert_true( array( 'background', 'style' ) === $names, 'the style park extends the signed background list instead of replacing it (got ' . implode( ' ', $names ) . ')' );
	assert_true( $key === faz_attr_value( $out, 'data-faz-park-key' ), 'under this response\'s key' );
	$listed = preg_split( '/\s+/', (string) faz_attr_value( $out, 'data-faz-park' ), -1, PREG_SPLIT_NO_EMPTY );
	$vals   = array_map( function ( $n ) use ( $out ) {
		return (string) faz_attr_value( $out, 'data-faz-' . $n );
	}, $listed );
	assert_true( Park_Proof::signature( $listed, $vals ) === faz_attr_value( $out, 'data-faz-park-sig' ), 'with one signature over both parked values' );

	$forged_td = str_replace( $bg_sig, str_repeat( 'd', 64 ), $td );
	$out       = faz_ob_run( faz_ob_frontend( $providers, $blocked ), '<html><body>' . $forged_td . '</body></html>' );
	assert_true( 'style' === faz_attr_value( $out, 'data-faz-park' ), 'a foreign list whose signature does not verify is still replaced, not inherited' );

	echo "\n-- the signature is over the value getAttribute() returns --\n";

	// What the browser reads (see tests/browser-intent/park-proof-value-as-read.spec.ts).
	foreach (
		array(
			'a numeric reference spelling a named one' => array( 'background:url(' . $poster . '?a=1&#38;quot;b)', 'background:url(' . $poster . '?a=1&quot;b)' ),
			'a literal CRLF'                           => array( "background:url({$poster})\r\n;color:red", "background:url({$poster})\n;color:red" ),
			'a literal CR'                             => array( "background:url({$poster})\r;color:red", "background:url({$poster})\n;color:red" ),
		) as $label => list( $raw, $read )
	) {
		$sig  = Park_Proof::signature( array( 'style' ), array( $read ) );
		$frag = '<div style="" data-faz-category="marketing" data-faz-park="style" data-faz-park-key="old-response-key" data-faz-park-sig="' . $sig . '" data-faz-style="' . $raw . '">x</div>';
		$out  = faz_ob_run( faz_ob_frontend( $providers, array() ), '<html><body>' . $frag . '</body></html>' );
		assert_true( $key === faz_attr_value( $out, 'data-faz-park-key' ), "a fragment signed over the browser's reading is re-keyed: $label" );
	}

	echo "\n";
	echo "park proof refresh: {$tests_passed} passed, {$tests_failed} failed\n";
	exit( $tests_failed > 0 ? 1 : 0 );
}
