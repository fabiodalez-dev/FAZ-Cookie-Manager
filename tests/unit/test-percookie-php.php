<?php
/**
 * Standalone unit tests for the per-cookie consent backend (#135).
 *
 * Subsystem: percookie-php
 *
 * Covers the two Frontend helpers that drive server-side per-cookie (ck.*)
 * enforcement, extracted from shred_non_consented_cookies():
 *   - Frontend::ck_escape_cookie_name()          (mirror of JS _fazCkKey escaping)
 *   - Frontend::resolve_service_cookie_decision() (ck.* > svc.* > '' precedence)
 *
 * Pure-logic tests: no browser, DB, or live WordPress. The Frontend object is
 * built with ReflectionClass::newInstanceWithoutConstructor() and the private
 * methods are invoked via reflection. The full end-to-end shredder (cookie
 * removal on a live request) is validated by the E2E suite.
 *
 * Run from project root:
 *   php tests/unit/test-percookie-php.php
 *
 * Exit code 0 = all tests pass; 1 = at least one failure.
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
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}

	if ( ! class_exists( 'FazTest_WPDB' ) ) {
		class FazTest_WPDB {
			public $prefix = 'wp_';
			public function get_col( $query ) {
				return array();
			}
		}
	}
	$GLOBALS['wpdb'] = new FazTest_WPDB();

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

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
			echo '      expected: ' . var_export( $expected, true ) . "\n";
			echo '      actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	function faz_new_frontend() {
		$rc = new ReflectionClass( Frontend::class );
		return $rc->newInstanceWithoutConstructor();
	}

	function faz_call( $fe, $method, array $args = array() ) {
		$m = new ReflectionMethod( Frontend::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $fe, $args );
	}

	echo "\n\033[1mPer-cookie consent backend (#135)\033[0m\n\n";
	$fe = faz_new_frontend();

	// ---- ck_escape_cookie_name(): mirror of JS _fazCkKey escaping ----
	echo "ck_escape_cookie_name()\n";
	assert_eq( faz_call( $fe, 'ck_escape_cookie_name', array( 'YSC' ) ), 'YSC', 'plain name unchanged' );
	assert_eq( faz_call( $fe, 'ck_escape_cookie_name', array( '_ga_*' ) ), '_ga_*', 'wildcard * is not escaped' );
	assert_eq( faz_call( $fe, 'ck_escape_cookie_name', array( 'a:b' ) ), 'a%3Ab', 'colon -> %3A' );
	assert_eq( faz_call( $fe, 'ck_escape_cookie_name', array( 'a,b' ) ), 'a%2Cb', 'comma -> %2C' );
	assert_eq( faz_call( $fe, 'ck_escape_cookie_name', array( 'a%b' ) ), 'a%25b', 'percent -> %25' );
	// Percent must be escaped FIRST, else %3A would become %253A wrongly here.
	assert_eq( faz_call( $fe, 'ck_escape_cookie_name', array( '%3A' ) ), '%253A', 'literal %3A double-escapes to %253A (percent first)' );

	// ---- resolve_service_cookie_decision(): ck.* > svc.* > '' ----
	echo "\nresolve_service_cookie_decision()\n";
	$call = function ( $svc_id, $pattern, $svc, $cookie, $enabled ) use ( $fe ) {
		return faz_call( $fe, 'resolve_service_cookie_decision', array( $svc_id, $pattern, $svc, $cookie, $enabled ) );
	};

	// Per-cookie OFF: always the per-service decision (normalised).
	assert_eq( $call( 'youtube', 'YSC', 'yes', array( 'ck.youtube.YSC' => 'no' ), false ), 'yes', 'per-cookie off: ck token ignored, svc=yes wins' );
	assert_eq( $call( 'youtube', 'YSC', 'no', array(), false ), 'no', 'per-cookie off: svc=no' );
	assert_eq( $call( 'youtube', 'YSC', '', array(), false ), '', 'per-cookie off: no svc decision -> empty' );

	// Per-cookie ON: explicit ck.* overrides the per-service decision.
	assert_eq( $call( 'youtube', 'YSC', 'yes', array( 'ck.youtube.YSC' => 'no' ), true ), 'no', 'ck=no overrides svc=yes (deny one cookie in an accepted service)' );
	assert_eq( $call( 'youtube', 'PREF', 'no', array( 'ck.youtube.PREF' => 'yes' ), true ), 'yes', 'ck=yes overrides svc=no (allow one cookie in a denied service)' );
	assert_eq( $call( 'youtube', 'YSC', 'yes', array( 'ck.youtube.OTHER' => 'no' ), true ), 'yes', 'no ck token for THIS cookie -> svc=yes kept' );
	assert_eq( $call( 'youtube', 'YSC', '', array( 'ck.youtube.YSC' => 'no' ), true ), 'no', 'ck=no with no svc decision -> no' );
	assert_eq( $call( 'youtube', 'YSC', 'yes', array( 'ck.youtube.YSC' => 'maybe' ), true ), 'yes', 'invalid ck value ignored -> svc=yes' );

	// ck key uses the escaped cookie name (colon in a declared pattern).
	assert_eq( $call( 'matomo', '_pk_id.1', 'yes', array( 'ck.matomo._pk_id.1' => 'no' ), true ), 'no', 'dotted cookie name resolves its ck token' );
	assert_eq( $call( 'svc1', 'a:b', 'yes', array( 'ck.svc1.a%3Ab' => 'no' ), true ), 'no', 'colon cookie name resolves via %3A-escaped ck key' );

	echo "\n";
	if ( $tests_failed > 0 ) {
		echo "\033[31m✗ {$tests_failed} failed\033[0m, {$tests_passed} passed ({$tests_run} total)\n";
		exit( 1 );
	}
	echo "\033[32m✓ all {$tests_passed} passed\033[0m ({$tests_run} total)\n";
	exit( 0 );
}
