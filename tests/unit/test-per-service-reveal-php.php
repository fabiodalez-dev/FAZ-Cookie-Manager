<?php
/**
 * Regression test for the two service-level-consent bugs reported on the
 * per-service-reveal branch (tester report, 2026-06) — first reproduced, then
 * locked down by the get_per_service_services() fix:
 *
 *   BUG-1  The backend scanner never surfaces a block-first embed service
 *          (e.g. YouTube). Its toggle only appears on a page that currently
 *          carries the blocked embed; the homepage and the rest of the site
 *          show services: [].
 *
 *   BUG-2  After the visitor ACCEPTS the YouTube placeholder, the per-service
 *          (and per-cookie) toggles DISAPPEAR — even on the page with the
 *          videos — so the granted service can no longer be REVOKED from the
 *          preference center. (GDPR Art. 7(3): withdrawal must be as easy as
 *          consent.)
 *
 * Both symptoms share ONE architectural root cause, proven here against the
 * REAL shipped helpers:
 *
 *   get_per_service_services()  — the VISIBLE toggle list (_services) — gates
 *   every provider on provider_has_detected_cookie(), i.e. a cookie the scanner
 *   recorded with discovered=1. A blocked YouTube never sets a cookie, so it is
 *   NEVER in this list, on ANY page.
 *
 *   get_enforceable_services()  — the ENFORCEMENT set — includes YouTube
 *   regardless of detection (the #134/#146 fix).
 *
 * So the visible toggle for YouTube can only ever come from a page-level reveal
 * keyed to the blocked placeholder. Remove the placeholder (accept → real
 * iframe) and the reveal source is gone → the toggle vanishes and consent can't
 * be withdrawn. This test pins that divergence so a fix (surface an enforceable
 * service that has an explicit svc.* decision, independent of detection /
 * placeholder presence) can be verified against it.
 *
 * Run:  php tests/unit/test-per-service-reveal-repro-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Known_Providers {
		public static function get_all() { return $GLOBALS['__faz_providers']; }
		public static function get_cookie_map() { return array(); }
		public static function get_pattern_map() { return array(); }
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

	$GLOBALS['__faz_transients'] = array();
	$GLOBALS['__faz_db_rows']    = array();
	$GLOBALS['__faz_providers']  = array();

	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $k ) { return $GLOBALS['__faz_transients'][ $k ] ?? false; }
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__faz_transients'][ $k ] = $v; return true; }
	}
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $s ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) ); }
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $s ) ) ); }
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $t, $v ) { return $v; }
	}
	$GLOBALS['__faz_consent_cookie'] = '';
	if ( ! function_exists( 'faz_get_valid_consent_cookie' ) ) {
		function faz_get_valid_consent_cookie() { return $GLOBALS['__faz_consent_cookie']; }
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $k, $d = false ) { return $d; }
	}

	if ( ! class_exists( 'FazTest_WPDB' ) ) {
		class FazTest_WPDB {
			public $prefix = 'wp_';
			public function get_col( $q ) { return $GLOBALS['__faz_db_rows']; }
		}
	}
	$GLOBALS['wpdb'] = new FazTest_WPDB();

	// Load the real Placeholder_Builder so is_third_party_service() resolves it
	// (the class_exists guard would otherwise degrade the flag to false).
	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-placeholder-builder.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
	use FazCookie\Frontend\Frontend;

	$run = 0; $pass = 0; $fail = 0;
	function eq( $a, $b, $l ) {
		global $run, $pass, $fail; $run++;
		if ( $a === $b ) { $pass++; echo "  \033[32m✓\033[0m $l\n"; }
		else { $fail++; echo "  \033[31m✗\033[0m $l\n      expected: " . var_export( $b, true ) . "\n      actual:   " . var_export( $a, true ) . "\n"; }
	}
	function tt( $c, $l ) { eq( (bool) $c, true, $l ); }

	function fe_fresh() {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		foreach ( array( 'per_service_cache', 'enforceable_cache', 'service_consent_cache', 'pattern_service_cache', 'settings_option_cache' ) as $p ) {
			$pp = $rc->getProperty( $p ); $pp->setAccessible( true ); $pp->setValue( $fe, null );
		}
		return $fe;
	}
	function priv( $fe, $m, array $a = array() ) {
		$mm = new ReflectionMethod( Frontend::class, $m ); $mm->setAccessible( true ); return $mm->invokeArgs( $fe, $a );
	}
	function set_prop( $fe, $p, $v ) {
		$pp = new ReflectionProperty( Frontend::class, $p ); $pp->setAccessible( true ); $pp->setValue( $fe, $v );
	}

	// YouTube: a real block-first embed provider. Its cookies (set only once the
	// iframe actually loads) are NOT in the detected set on a block-first site.
	$GLOBALS['__faz_providers'] = array(
		'youtube' => array(
			'label'    => 'YouTube',
			'category' => 'marketing',
			'patterns' => array( 'youtube.com/embed', 'youtube-nocookie.com', 'youtu.be' ),
			'cookies'  => array( 'VISITOR_INFO1_LIVE', 'YSC', 'PREF' ),
		),
		'google-analytics' => array(
			'label'    => 'Google Analytics',
			'category' => 'analytics',
			'patterns' => array( 'google-analytics.com/analytics.js' ),
			'cookies'  => array( '_ga', '_gid' ),
		),
	);

	echo "\n== per-service-reveal — bug reproduction (real shipped helpers) ==\n\n";

	// Scenario: a real visit where GA's _ga IS detected (it ran) but YouTube was
	// blocked, so NONE of its cookies were ever observed by the scanner.
	echo "-- BUG-1: block-first YouTube is invisible to the detection-gated list --\n";
	$fe = fe_fresh();
	$GLOBALS['__faz_transients']['faz_detected_cookie_names'] = array( '_ga', '_gid' ); // GA only; no YouTube cookie.

	$visible = priv( $fe, 'get_per_service_services', array( array( 'analytics', 'marketing' ) ) );
	$visible_ids = array_column( $visible, 'id' );
	tt( in_array( 'google-analytics', $visible_ids, true ), 'detected GA IS in the visible per-service list' );
	eq( in_array( 'youtube', $visible_ids, true ), false,
		'block-first YouTube is NOT in the visible list (no detected cookie) — toggle cannot appear from the server list' );

	$fe2 = fe_fresh();
	$enforceable = priv( $fe2, 'get_enforceable_services', array( array( 'analytics', 'marketing' ) ) );
	$enf_ids = array_column( $enforceable, 'id' );
	tt( in_array( 'youtube', $enf_ids, true ),
		'YouTube IS in the ENFORCEABLE set (so svc.youtube can be enforced) — the visible/enforceable divergence is the root cause' );

	// third_party flag: YouTube is an embed (cookies on youtube.com, not
	// shreddable first-party) → flagged so the per-cookie note can clarify it;
	// Google Analytics sets _ga first-party on the site domain → not flagged.
	$enf_by_id = array();
	foreach ( $enforceable as $e ) { $enf_by_id[ $e['id'] ] = $e; }
	eq( $enf_by_id['youtube']['third_party'] ?? null, true, 'YouTube entry carries third_party=true (embed, cookies not shreddable)' );
	$ga_visible = null;
	foreach ( $visible as $v ) { if ( 'google-analytics' === $v['id'] ) { $ga_visible = $v; } }
	eq( $ga_visible['third_party'] ?? null, false, 'first-party Google Analytics entry carries third_party=false' );

	echo "\n-- FIX: an ACCEPTED service stays in the visible list for revocation --\n";
	// After accept there is no blocked placeholder on the page AND still no
	// detected cookie (the scanner can't see an iframe's cookies). The fix keys
	// the visible toggle off the persisted svc.youtube:yes decision instead, so
	// the preference center can render — and let the visitor REVOKE — it.
	$fe3 = fe_fresh();
	$GLOBALS['__faz_transients']['faz_detected_cookie_names'] = array( '_ga', '_gid' ); // unchanged after accept.
	$GLOBALS['__faz_consent_cookie'] = 'necessary:yes,marketing:no,svc.youtube:yes';
	set_prop( $fe3, 'settings_option_cache', array( 'banner_control' => array( 'per_service_consent' => true ) ) );
	// Seed the enforceable set the fix resolves the decided id against.
	set_prop( $fe3, 'enforceable_cache', array(
		array( 'id' => 'youtube', 'label' => 'YouTube', 'category' => 'marketing',
		       'patterns' => array( 'youtube.com/embed' ), 'cookies' => array( 'YSC', 'VISITOR_INFO1_LIVE' ) ),
	) );
	$fixed   = priv( $fe3, 'get_per_service_services', array( array( 'analytics', 'marketing' ) ) );
	$fixed_ids = array_column( $fixed, 'id' );
	tt( in_array( 'youtube', $fixed_ids, true ),
		'FIX: granted svc.youtube:yes now surfaces YouTube in the visible list (toggle persists → can be withdrawn)' );
	tt( in_array( 'google-analytics', $fixed_ids, true ), 'detected GA still present alongside the granted service' );
	// The surfaced entry carries the service cookies so per-cookie sub-toggles render too.
	$yt = null; foreach ( $fixed as $s ) { if ( 'youtube' === $s['id'] ) { $yt = $s; } }
	tt( $yt && in_array( 'YSC', $yt['cookies'], true ), 'surfaced YouTube entry carries its cookies (per-cookie toggles render)' );

	// An explicit DENIAL must surface the toggle too (review/undo a rejection).
	$fe4 = fe_fresh();
	$GLOBALS['__faz_transients']['faz_detected_cookie_names'] = array();
	$GLOBALS['__faz_consent_cookie'] = 'svc.youtube:no';
	set_prop( $fe4, 'settings_option_cache', array( 'banner_control' => array( 'per_service_consent' => true ) ) );
	set_prop( $fe4, 'enforceable_cache', array(
		array( 'id' => 'youtube', 'label' => 'YouTube', 'category' => 'marketing',
		       'patterns' => array( 'youtube.com/embed' ), 'cookies' => array( 'YSC' ) ),
	) );
	$denied_ids = array_column( priv( $fe4, 'get_per_service_services', array( array( 'marketing' ) ) ), 'id' );
	tt( in_array( 'youtube', $denied_ids, true ), 'FIX: an explicit svc.youtube:no also surfaces the toggle (rejection is reviewable)' );

	// Robustness: a decided service whose category is no longer active (renamed/
	// disabled on this site) must NOT be surfaced from a stale svc.* token.
	$fe6 = fe_fresh();
	$GLOBALS['__faz_transients']['faz_detected_cookie_names'] = array();
	$GLOBALS['__faz_consent_cookie'] = 'svc.youtube:yes';
	set_prop( $fe6, 'settings_option_cache', array( 'banner_control' => array( 'per_service_consent' => true ) ) );
	set_prop( $fe6, 'enforceable_cache', array(
		array( 'id' => 'youtube', 'label' => 'YouTube', 'category' => 'marketing',
		       'patterns' => array( 'youtube.com/embed' ), 'cookies' => array( 'YSC' ) ),
	) );
	// marketing is NOT in the active set passed to the visible-list builder.
	$inactive_ids = array_column( priv( $fe6, 'get_per_service_services', array( array( 'analytics' ) ) ), 'id' );
	eq( in_array( 'youtube', $inactive_ids, true ), false,
		'decided service in an inactive category is NOT surfaced (category guard)' );

	// Pre-decision with no embed context still excludes it (BUG-1 reveal scope
	// stays with the page-level placeholder reveal; unchanged here by design).
	$fe5 = fe_fresh();
	$GLOBALS['__faz_consent_cookie'] = '';
	set_prop( $fe5, 'settings_option_cache', array( 'banner_control' => array( 'per_service_consent' => true ) ) );
	$GLOBALS['__faz_transients']['faz_detected_cookie_names'] = array( '_ga' );
	$pre_ids = array_column( priv( $fe5, 'get_per_service_services', array( array( 'analytics', 'marketing' ) ) ), 'id' );
	eq( in_array( 'youtube', $pre_ids, true ), false,
		'pre-decision (no svc.* token, no detected cookie): YouTube still surfaced only by the page-level reveal' );

	echo "\n--\nTests:  $run\nPassed: $pass\nFailed: $fail\n\n";
	if ( $fail > 0 ) { echo "\033[31mFAIL\033[0m\n"; exit( 1 ); }
	echo "\033[32mPASS — accepted/denied per-service decisions persist in the visible list.\033[0m\n";
	exit( 0 );
}
