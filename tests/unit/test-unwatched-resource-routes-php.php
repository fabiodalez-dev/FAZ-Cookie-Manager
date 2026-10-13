<?php
/**
 * Standalone unit tests for the resource routes the server-side pass used to
 * miss: the SVG namespace, the legacy `background` attribute, and srcdoc.
 *
 * Subsystem: unwatched-resource-routes-php
 *
 * <image> is an <img> by another name and <use href="https://x/sprite.svg#icon">
 * fetches an external document, both through `href` or the legacy `xlink:href`.
 * No pass in class-frontend.php had ever looked at those attributes, and neither
 * had the client: the bootstrap's element map is keyed on nodeName, which is
 * uppercase only for HTML, so the whole namespace was invisible there too. An
 * SVG tracking pixel in server markup therefore went out before consent.
 *
 *   1. A blocked provider in `xlink:href` is parked into data-faz-href with a
 *      category, and the tag's shape (self-closing or not) survives.
 *   2. The same through the SVG2 spelling `href`.
 *   3. <use> is gated the same way — an external sprite reference is a fetch.
 *   4. First-party and unrecognised URLs are left byte-identical: over-parking
 *      an icon sprite breaks the page for nothing.
 *   5. A tag already carrying data-faz-href is left alone, so a second pass
 *      (or the client having parked it first) cannot double-rewrite it.
 *   6. A whitelisted tag is exempt, like every other pass here.
 *   8. An <iframe srcdoc> is judged whole — its markup becomes a document
 *      whose fetches no gate on either side can reach — and parked into
 *      data-faz-srcdoc when it names a blocked provider.
 *   7. The legacy `background` attribute, which fetches an image on <body> and
 *      on every table part, is parked the same way — on whichever element
 *      carried it, since the attribute is what fetches and not the tag.
 *
 * Run: php tests/unit/test-unwatched-resource-routes-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	// Double for the `use FazCookie\Includes\Known_Providers` alias inside
	// class-frontend.php — no autoload, no JSON read.
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

namespace {

	// ---------- Bootstrap ----------

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

	// ---------- assert helpers ----------

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

	// ---------- reflection harness ----------

	/**
	 * @param array $service_consent  service id => 'yes'|'no'. Empty = per-service
	 *                                consent off, which is the default everywhere
	 *                                below except the per-service cases.
	 * @param array $pattern_services URL pattern => list of service ids.
	 */
	function faz_ob_frontend( array $providers, array $blocked, array $whitelist = array(), array $service_consent = array(), array $pattern_services = array() ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		$preset = array(
			'blocked_categories_cache'   => $blocked,
			'provider_map_cache'         => $providers,
			'service_consent_cache'      => $service_consent,
			'whitelist_cache'            => $whitelist,
			'settings_option_cache'      => array(),
			'pattern_service_cache'      => $pattern_services,
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

	$providers = array(
		'connect.facebook.net' => 'marketing',
		'cdn.tracker.test'     => 'marketing',
	);
	$blocked   = array( 'marketing' );

	echo "\n-- SVG <image> / <use>: href and xlink:href --\n";

	// 1. The legacy spelling.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$out  = faz_ob_run( $fe, '<html><body><svg><image xlink:href="https://cdn.tracker.test/pixel.png" width="1" height="1"/></svg></body></html>' );
	assert_true( false === strpos( $out, 'xlink:href="https://cdn.tracker.test' ), 'xlink:href is not left live' );
	assert_true( false !== strpos( $out, 'data-faz-href="https://cdn.tracker.test/pixel.png"' ), 'it is parked into data-faz-href' );
	assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), 'with the category the restore pass needs' );
	assert_true( false !== strpos( $out, '/>' ), 'and the self-closing shape survives' );

	// 2. The SVG2 spelling.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><svg><image href="https://connect.facebook.net/p.png"/></svg></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-href="https://connect.facebook.net/p.png"' ), 'href is parked too' );
	assert_true( false === strpos( $out, ' href="https://connect.facebook.net' ), 'and is not left live' );

	// 3. <use> fetches an external document.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><svg><use xlink:href="https://cdn.tracker.test/sprite.svg#icon"/></svg></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-href="https://cdn.tracker.test/sprite.svg#icon"' ), '<use> is gated as well' );

	// 4. The other error direction: nothing to gate, nothing touched.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><svg><use href="/assets/sprite.svg#icon"/><image href="/logo.png"/></svg></body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'first-party SVG references pass through byte-identical' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><svg><image xlink:href="https://unknown.example/x.png"/></svg></body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'an unrecognised third party is not gated by this pass' );

	// 5. Idempotent: already parked stays as it is.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><svg><image data-faz-category="marketing" data-faz-href="https://cdn.tracker.test/pixel.png"/></svg></body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'a tag already parked is not rewritten a second time' );

	// 6. The whitelist exemption every pass here honours.
	$fe   = faz_ob_frontend( $providers, $blocked, array( 'cdn.tracker.test' ) );
	$html = '<html><body><svg><image xlink:href="https://cdn.tracker.test/pixel.png"/></svg></body></html>';
	$out  = faz_ob_run( $fe, $html );
	assert_true( $out === $html, 'a whitelisted URL is exempt' );

	echo "\n-- the legacy background attribute --\n";

	// Any element that honours it, not just the body.
	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body background="https://cdn.tracker.test/bg.png"><table background="https://cdn.tracker.test/t.png"><tr><td background="https://cdn.tracker.test/c.png">x</td></tr></table></body></html>' );
	assert_true( 3 === substr_count( $out, 'data-faz-background=' ), 'body, table and cell are all parked' );
	assert_true( false === strpos( $out, ' background="https://cdn.tracker.test' ), 'and none is left live' );
	assert_true( 3 === substr_count( $out, 'data-faz-category="marketing"' ), 'each one carries the category' );

	// First-party backgrounds are ordinary page decoration.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body background="/images/paper.png"><table background="/images/grid.png"></table></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'first-party backgrounds pass through byte-identical' );

	// Already parked stays parked.
	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body data-faz-category="marketing" data-faz-background="https://cdn.tracker.test/bg.png"></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'a parked background is not rewritten twice' );

	echo "\n-- iframe srcdoc: a document in an attribute --\n";

	$fe  = faz_ob_frontend( $providers, $blocked );
	$out = faz_ob_run( $fe, '<html><body><iframe srcdoc="<img src=&quot;https://cdn.tracker.test/pixel.png&quot;>"></iframe></body></html>' );
	assert_true( false !== strpos( $out, 'data-faz-srcdoc=' ), 'a srcdoc naming a blocked provider is parked' );
	assert_true( false !== strpos( $out, 'data-faz-category="marketing"' ), 'with its category' );
	assert_true( false === strpos( $out, ' srcdoc="' ), 'and the live attribute is gone' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><iframe srcdoc="<p>plain local markup</p>"></iframe></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'a srcdoc with no third-party URL is untouched' );

	$fe   = faz_ob_frontend( $providers, $blocked );
	$html = '<html><body><iframe srcdoc="<img src=&quot;https://unknown.example/x.png&quot;>"></iframe></body></html>';
	assert_true( faz_ob_run( $fe, $html ) === $html, 'an unrecognised third party inside srcdoc is not gated by this pass' );

	// ---------- result ----------

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "ALL PASS ({$tests_passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$tests_failed}, passed: {$tests_passed}\n";
	exit( 1 );
}
