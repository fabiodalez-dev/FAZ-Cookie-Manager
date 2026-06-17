<?php
/**
 * Standalone unit tests for per-service consent on BLOCK-FIRST sites (#134/#146).
 *
 * Subsystem: per-service-embeds-php
 *
 * The per-service feature originally only enforced/exposed providers whose
 * cookie the scanner had already observed (discovered=1). On a site that
 * correctly blocks an embed before it can set a cookie (e.g. a YouTube video
 * declined at category level), that provider's cookie is never detected, so:
 *   - clicking "Accept" on its placeholder fell back to accepting the WHOLE
 *     category instead of just the service (#134), and
 *   - its per-service sub-toggle never appeared in the preference center (#146).
 *
 * The fix decouples ENFORCEMENT from detection: Frontend::get_enforceable_services()
 * returns every Known_Providers entry in an active (non-necessary) category, and
 * get_service_consent() / get_pattern_service_map() resolve against it — so an
 * explicit svc.<id>:yes|no is honoured for any real provider, detected or not.
 * The visible toggle list (get_per_service_services()) stays scanner-detected
 * and is widened separately by inferring cookies from embedded scripts/iframes.
 *
 * These tests pin the enforcement half (the security-sensitive one): they assert
 * that get_enforceable_services() has the right membership, that an explicit
 * per-service choice for a known-but-undetected provider is honoured, and that
 * the svc.* precedence over category blocking still holds.
 *
 * Run: php tests/unit/test-per-service-embeds-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

// --- Known_Providers double in FazCookie\Includes BEFORE class-frontend.php
//     loads, so the `use FazCookie\Includes\Known_Providers` alias resolves to
//     it (no autoload, no JSON read). get_enforceable_services() calls get_all().
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

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

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
	function faz_arrange( $cookie, $option_on = true ) {
		$GLOBALS['__faz_consent_cookie'] = $cookie;
		$GLOBALS['__faz_providers']      = faz_providers();
		$fe = faz_new_frontend();
		faz_set_prop( $fe, 'settings_option_cache', array(
			'banner_control' => array( 'per_service_consent' => $option_on ),
		) );
		// Detected (visible) list stays narrow & empty — the whole point of the
		// block-first scenario is that no provider cookie was observed.
		faz_set_prop( $fe, 'per_service_cache', array() );
		// Enforceable set = every known provider in an active category.
		faz_set_prop( $fe, 'enforceable_cache', faz_call( $fe, 'get_enforceable_services', array( faz_active_cats() ) ) );
		return $fe;
	}

	echo "\n  per-service-embeds (#134/#146)\n";
	echo "  ──────────────────────────────\n";

	// ===== Group A — get_enforceable_services() membership =====
	$GLOBALS['__faz_providers'] = faz_providers();
	$fe   = faz_new_frontend();
	$enf  = faz_call( $fe, 'get_enforceable_services', array( faz_active_cats() ) );
	$ids  = array_column( $enf, 'id' );

	assert_eq( in_array( 'youtube', $ids, true ), true, 'A1 enforceable set includes a marketing provider (youtube) with no detected cookie' );
	assert_eq( in_array( 'google-analytics', $ids, true ), true, 'A2 enforceable set includes an analytics provider' );
	assert_eq( in_array( 'a-necessary', $ids, true ), false, 'A3 necessary-category provider is excluded from enforcement' );
	assert_eq( in_array( 'old-thing', $ids, true ), false, 'A4 provider in an inactive category (social) is excluded' );

	$yt = null;
	foreach ( $enf as $e ) {
		if ( 'youtube' === $e['id'] ) {
			$yt = $e;
		}
	}
	assert_eq( is_array( $yt ) && array_keys( $yt ) === array( 'id', 'label', 'category', 'patterns', 'cookies' ), true, 'A5 enforceable entry is shaped {id,label,category,patterns,cookies}' );
	assert_eq( $yt['category'], 'marketing', 'A6 enforceable entry carries the provider category' );
	assert_eq( in_array( 'youtube.com/embed', $yt['patterns'], true ), true, 'A7 enforceable entry carries the provider URL patterns (used to match embeds)' );

	// Caching: mutate the catalogue, second call must return the cached result.
	$cached_before              = faz_call( $fe, 'get_enforceable_services', array( faz_active_cats() ) );
	$GLOBALS['__faz_providers'] = array(); // would yield empty if recomputed
	$cached_after               = faz_call( $fe, 'get_enforceable_services', array( faz_active_cats() ) );
	assert_eq( $cached_after, $cached_before, 'A8 enforceable set is memoised (second call ignores catalogue change)' );

	// Empty catalogue → empty enforceable set.
	$GLOBALS['__faz_providers'] = array();
	$fe_empty                   = faz_new_frontend();
	assert_eq( faz_call( $fe_empty, 'get_enforceable_services', array( faz_active_cats() ) ), array(), 'A9 empty provider catalogue → empty enforceable set' );

	// ===== Group B — get_service_consent() honours explicit choices for
	//        known-but-UNDETECTED providers (the #134 core) =====

	// B1 — svc.youtube:yes is KEPT even though youtube is NOT in the detected
	//      (per_service_cache) list — the old code dropped it.
	$fe = faz_arrange( 'necessary:yes,marketing:no,svc.youtube:yes' );
	assert_eq( faz_call( $fe, 'get_service_consent' ), array( 'youtube' => 'yes' ), 'B1 svc.youtube:yes honoured for a known provider with no detected cookie' );

	// B2 — svc.youtube:no kept.
	$fe = faz_arrange( 'necessary:yes,marketing:no,svc.youtube:no' );
	assert_eq( faz_call( $fe, 'get_service_consent' ), array( 'youtube' => 'no' ), 'B2 svc.youtube:no honoured for a known undetected provider' );

	// B3 — svc.<unknown> is DROPPED (not a real Known_Provider → not enforceable).
	$fe = faz_arrange( 'necessary:yes,svc.not-a-provider:yes,svc.youtube:yes' );
	assert_eq( faz_call( $fe, 'get_service_consent' ), array( 'youtube' => 'yes' ), 'B3 svc.* for an unknown id is dropped; known one kept' );

	// B4 — per_service_consent OFF → empty map (svc.* ignored entirely).
	$fe = faz_arrange( 'necessary:yes,svc.youtube:yes', false );
	assert_eq( faz_call( $fe, 'get_service_consent' ), array(), 'B4 per-service OFF → svc.* ignored, enforcement stays category-level' );

	// B5 — empty consent cookie → empty map.
	$fe = faz_arrange( '' );
	assert_eq( faz_call( $fe, 'get_service_consent' ), array(), 'B5 empty consent cookie → empty service map' );

	// B6 — category tokens are not mistaken for svc.* entries.
	$fe = faz_arrange( 'necessary:yes,marketing:no,analytics:no' );
	assert_eq( faz_call( $fe, 'get_service_consent' ), array(), 'B6 plain category tokens are ignored (only svc.* parsed)' );

	// ===== Group C — check_per_service_blocking() with undetected providers =====

	// C1 — a YouTube iframe with svc.youtube:yes is ALLOWED (false) even though
	//      youtube is undetected and its category (marketing) is denied.
	$fe = faz_arrange( 'necessary:yes,marketing:no,svc.youtube:yes' );
	assert_eq( faz_call( $fe, 'check_per_service_blocking', array( ' src="https://www.youtube.com/embed/x" ', '' ) ), false, 'C1 svc.youtube:yes allows the YouTube embed despite marketing denied' );

	// C2 — svc.youtube:no blocks the YouTube embed (true).
	$fe = faz_arrange( 'necessary:yes,marketing:yes,svc.youtube:no' );
	assert_eq( faz_call( $fe, 'check_per_service_blocking', array( ' src="https://www.youtube.com/embed/x" ', '' ) ), true, 'C2 svc.youtube:no blocks the YouTube embed even when marketing allowed' );

	// C3 — no explicit svc choice → null (fall back to category blocking).
	$fe = faz_arrange( 'necessary:yes,marketing:no,svc.vimeo:yes' );
	assert_eq( faz_call( $fe, 'check_per_service_blocking', array( ' src="https://www.youtube.com/embed/x" ', '' ) ), null, 'C3 YouTube embed with no svc.youtube choice → null (category fallback)' );

	// C4 — an embed matching NO provider pattern → null regardless of svc.* set.
	$fe = faz_arrange( 'necessary:yes,svc.youtube:no' );
	assert_eq( faz_call( $fe, 'check_per_service_blocking', array( ' src="https://example.com/app.js" ', '' ) ), null, 'C4 non-provider embed → null (no per-service decision)' );

	// C5 — per-service OFF → null even with svc tokens present in the cookie.
	$fe = faz_arrange( 'necessary:yes,svc.youtube:no', false );
	assert_eq( faz_call( $fe, 'check_per_service_blocking', array( ' src="https://www.youtube.com/embed/x" ', '' ) ), null, 'C5 per-service OFF → check returns null (category-level only)' );

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
