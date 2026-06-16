<?php
/**
 * Standalone unit tests for the per-service consent backend.
 *
 * Subsystem: per-service-php
 *
 * Covers the four Frontend helpers that drive granular per-service (svc.*)
 * consent on the frontend:
 *   - Frontend::get_detected_cookie_names()   (discovered=1 filter + transient)
 *   - Frontend::provider_has_detected_cookie() (wildcard + exact, case-insens.)
 *   - Frontend::get_per_service_services()     (active-category + detected gate)
 *   - Frontend::get_service_consent()          (svc.* parse, toggle, allowlist)
 * plus the svc.* precedence rule (explicit no > explicit yes > category) as
 * implemented by check_per_service_blocking().
 *
 * Run from project root:
 *   php tests/unit/test-per-service-php.php
 * or via the wrapper:
 *   bash scripts/run-unit-tests.sh
 *
 * Exit code 0 = all tests pass; 1 = at least one failure.
 *
 * These are pure-logic tests. They do NOT touch a browser, a real DB, or a
 * live WordPress runtime. The Frontend object is built with
 * ReflectionClass::newInstanceWithoutConstructor() (the real constructor wires
 * dozens of WP hooks and sub-objects we don't need), the WP functions the four
 * methods call are stubbed, and Known_Providers is replaced with a controllable
 * test double so each case asserts a specific, deterministic behaviour.
 *
 * The whole file uses braced namespace blocks because we must define a
 * Known_Providers double in the FazCookie\Includes namespace *before* loading
 * the real Frontend class (so its `use` alias resolves to our double, never the
 * real JSON-backed class).
 *
 * @package FazCookie\Tests\Unit
 */

// --- Known_Providers test double, defined BEFORE class-frontend.php loads so
//     the `use FazCookie\Includes\Known_Providers` alias resolves to it (no
//     autoload, no JSON file read). get_per_service_services() calls get_all().
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

	// --- Controllable global test state (driven by the stubs below) ---
	$GLOBALS['__faz_transients']     = array(); // name => value
	$GLOBALS['__faz_set_transients'] = array(); // record of set_transient() calls
	$GLOBALS['__faz_db_rows']        = array(); // rows the $wpdb stub returns
	$GLOBALS['__faz_consent_cookie'] = '';      // value faz_get_valid_consent_cookie() returns
	$GLOBALS['__faz_providers']      = array(); // what the Known_Providers double returns

	// --- WP function stubs (only what the four methods touch) ---

	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			return array_key_exists( $key, $GLOBALS['__faz_transients'] )
				? $GLOBALS['__faz_transients'][ $key ]
				: false;
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $ttl = 0 ) {
			$GLOBALS['__faz_transients'][ $key ]     = $value;
			$GLOBALS['__faz_set_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl );
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
		// No filters registered in these tests — return the value verbatim.
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'faz_get_valid_consent_cookie' ) ) {
		function faz_get_valid_consent_cookie() {
			return $GLOBALS['__faz_consent_cookie'];
		}
	}

	// --- $wpdb stub: get_col() returns whatever __faz_db_rows holds ---
	if ( ! class_exists( 'FazTest_WPDB' ) ) {
		class FazTest_WPDB {
			public $prefix = 'wp_';
			public function get_col( $query ) {
				return $GLOBALS['__faz_db_rows'];
			}
		}
	}
	$GLOBALS['wpdb'] = new FazTest_WPDB();

	// Now load the real Frontend class. It only *defines* the Frontend class;
	// the unrelated `use` aliases are not triggered unless a method body uses
	// them, and we only exercise the four per-service helpers.
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	// ---------- Minimal assert helpers ----------

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

	// ---------- Reflection harness ----------

	/**
	 * Build a fresh Frontend with no constructor side effects and reset the
	 * per-request memo caches so each test starts clean.
	 */
	function faz_new_frontend() {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		foreach ( array(
			'per_service_cache',
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

	/** Reset all controllable global state between tests. */
	function faz_reset_state() {
		$GLOBALS['__faz_transients']     = array();
		$GLOBALS['__faz_set_transients'] = array();
		$GLOBALS['__faz_db_rows']        = array();
		$GLOBALS['__faz_consent_cookie'] = '';
		$GLOBALS['__faz_providers']      = array();
	}

	// A small realistic provider catalogue used by several tests.
	function faz_sample_providers() {
		return array(
			'google-analytics' => array(
				'label'    => 'Google Analytics',
				'category' => 'analytics',
				'patterns' => array( 'google-analytics.com/analytics.js', 'googletagmanager.com/gtag/js' ),
				'cookies'  => array( '_ga', '_ga_*', '_gid' ),
			),
			'hotjar' => array(
				'label'    => 'Hotjar',
				'category' => 'analytics',
				'patterns' => array( 'static.hotjar.com' ),
				'cookies'  => array( '_hjSessionUser_*' ),
			),
			'facebook-pixel' => array(
				'label'    => 'Facebook Pixel',
				'category' => 'marketing',
				'patterns' => array( 'connect.facebook.net' ),
				'cookies'  => array( '_fbp' ),
			),
			'core-session' => array(
				'label'    => 'Core Session',
				'category' => 'necessary',
				'patterns' => array( 'example.com/core.js' ),
				'cookies'  => array( 'PHPSESSID' ),
			),
		);
	}

	echo "\n== per-service-php — backend unit tests ==\n\n";

	// ============================================================
	//  A. provider_has_detected_cookie()  (wildcard + exact match)
	// ============================================================

	$fe = faz_new_frontend();

	// A1. Exact match, case-insensitive.
	assert_eq(
		faz_call( $fe, 'provider_has_detected_cookie', array( array( '_ga' ), array( '_GA' ) ) ),
		true,
		'exact match is case-insensitive (_ga vs _GA)'
	);

	// A2. No overlap → false.
	assert_eq(
		faz_call( $fe, 'provider_has_detected_cookie', array( array( '_ga' ), array( '_fbp', '_gid' ) ) ),
		false,
		'no overlap between provider and detected → false'
	);

	// A3. Empty detected list → always false (edge: nothing scanned yet).
	assert_eq(
		faz_call( $fe, 'provider_has_detected_cookie', array( array( '_ga', '_gid' ), array() ) ),
		false,
		'empty detected list → false'
	);

	// A4. Wildcard _ga_* matches a concrete detected _ga_ABC123.
	assert_eq(
		faz_call( $fe, 'provider_has_detected_cookie', array( array( '_ga_*' ), array( '_ga_ABC123' ) ) ),
		true,
		'wildcard _ga_* matches detected _ga_ABC123'
	);

	// A5. Wildcard is anchored: _ga_* must NOT match the bare "_ga" prefix
	//     (regex is ^_ga_.*$, so "_ga" lacks the trailing underscore segment).
	assert_eq(
		faz_call( $fe, 'provider_has_detected_cookie', array( array( '_ga_*' ), array( '_ga' ) ) ),
		false,
		'anchored wildcard _ga_* does NOT match bare _ga (full-string anchor)'
	);

	// A6. Wildcard match is case-insensitive too.
	assert_eq(
		faz_call( $fe, 'provider_has_detected_cookie', array( array( '_HJSESSIONUSER_*' ), array( '_hjSessionUser_42' ) ) ),
		true,
		'wildcard match is case-insensitive'
	);

	// A7. Empty / blank provider cookie names are skipped, not matched.
	assert_eq(
		faz_call( $fe, 'provider_has_detected_cookie', array( array( '', '   ' ), array( '_ga' ) ) ),
		false,
		'empty provider cookie names are skipped (no spurious match)'
	);

	// A8. A literal "*" provider entry behaves as match-anything against a
	//     non-empty detected list (regex ^.*$). Boundary of the wildcard branch.
	assert_eq(
		faz_call( $fe, 'provider_has_detected_cookie', array( array( '*' ), array( 'anything' ) ) ),
		true,
		'lone "*" wildcard matches any detected cookie'
	);

	// A9. Malformed (non-array) provider arg is coerced via (array) cast.
	assert_eq(
		faz_call( $fe, 'provider_has_detected_cookie', array( '_ga', array( '_ga' ) ) ),
		true,
		'scalar provider arg is (array)-cast and still matched'
	);

	// ============================================================
	//  B. get_detected_cookie_names()  (discovered=1 filter + cache)
	// ============================================================

	// B1. Transient hit short-circuits the DB entirely.
	faz_reset_state();
	$fe = faz_new_frontend();
	$GLOBALS['__faz_transients']['faz_detected_cookie_names'] = array( '_cached_only' );
	$GLOBALS['__faz_db_rows'] = array( '_should_not_be_used' );
	assert_eq(
		faz_call( $fe, 'get_detected_cookie_names' ),
		array( '_cached_only' ),
		'cached transient short-circuits DB query'
	);
	assert_eq(
		isset( $GLOBALS['__faz_set_transients']['faz_detected_cookie_names'] ),
		false,
		'cache hit does NOT re-write the transient'
	);

	// B2. No transient → DB result is normalised (strval + filter empties) and
	//     cached. The discovered=1 filter lives in the SQL WHERE clause; the
	//     stubbed get_col() returns the rows the query would yield, so we assert
	//     the post-processing the method layers on top (array_values, strval,
	//     array_filter of empties).
	faz_reset_state();
	$fe = faz_new_frontend();
	$GLOBALS['__faz_db_rows'] = array( '_ga', '', '_gid', 0, '_fbp' );
	$names = faz_call( $fe, 'get_detected_cookie_names' );
	assert_eq(
		$names,
		array( '_ga', '_gid', '_fbp' ),
		'DB rows: empties/zero dropped, values re-indexed and string-cast'
	);
	assert_eq(
		$GLOBALS['__faz_set_transients']['faz_detected_cookie_names']['value'],
		array( '_ga', '_gid', '_fbp' ),
		'normalised result is written back to the transient'
	);
	assert_eq(
		$GLOBALS['__faz_set_transients']['faz_detected_cookie_names']['ttl'],
		6 * HOUR_IN_SECONDS,
		'transient TTL is 6 hours'
	);

	// B3. Empty DB result → empty array (still cached, no fatal).
	faz_reset_state();
	$fe = faz_new_frontend();
	$GLOBALS['__faz_db_rows'] = array();
	assert_eq(
		faz_call( $fe, 'get_detected_cookie_names' ),
		array(),
		'empty detected set → empty array'
	);

	// ============================================================
	//  C. get_per_service_services()  (active-category + detected gate)
	// ============================================================

	// C1. Service in an active category WITH a detected cookie → exposed; the
	//     necessary-category service is excluded; the marketing service whose
	//     cookie is NOT detected is excluded.
	faz_reset_state();
	$fe = faz_new_frontend();
	$GLOBALS['__faz_providers'] = faz_sample_providers();
	$GLOBALS['__faz_transients']['faz_detected_cookie_names'] = array( '_ga_X1', '_gid' ); // GA only
	$services = faz_call( $fe, 'get_per_service_services', array( array( 'analytics', 'marketing' ) ) );
	assert_eq(
		array_column( $services, 'id' ),
		array( 'google-analytics' ),
		'only detected, active-category, non-necessary services exposed'
	);
	assert_eq(
		$services[0]['category'],
		'analytics',
		'exposed service carries its sanitised category slug'
	);

	// C2. Necessary category is ALWAYS excluded even if explicitly passed active.
	faz_reset_state();
	$fe = faz_new_frontend();
	$GLOBALS['__faz_providers'] = faz_sample_providers();
	$GLOBALS['__faz_transients']['faz_detected_cookie_names'] = array( 'PHPSESSID' );
	$services = faz_call( $fe, 'get_per_service_services', array( array( 'necessary', 'analytics' ) ) );
	assert_eq(
		array_column( $services, 'id' ),
		array(),
		'necessary-category service never exposed even when category is active'
	);

	// C3. Service in a NON-active category is excluded despite a detected cookie.
	faz_reset_state();
	$fe = faz_new_frontend();
	$GLOBALS['__faz_providers'] = faz_sample_providers();
	$GLOBALS['__faz_transients']['faz_detected_cookie_names'] = array( '_fbp' ); // FB pixel cookie present
	$services = faz_call( $fe, 'get_per_service_services', array( array( 'analytics' ) ) ); // marketing NOT active
	assert_eq(
		array_column( $services, 'id' ),
		array(),
		'service in non-active category excluded even with a detected cookie'
	);

	// C4. Wildcard detection drives exposure: only "_ga_*"-shaped cookie present.
	faz_reset_state();
	$fe = faz_new_frontend();
	$GLOBALS['__faz_providers'] = faz_sample_providers();
	$GLOBALS['__faz_transients']['faz_detected_cookie_names'] = array( '_ga_QWERTY' );
	$services = faz_call( $fe, 'get_per_service_services', array( array( 'analytics', 'marketing' ) ) );
	assert_eq(
		array_column( $services, 'id' ),
		array( 'google-analytics' ),
		'wildcard-only detection (_ga_QWERTY) still exposes the GA service'
	);

	// C5. Empty detected list → no services exposed (nothing scanned yet).
	faz_reset_state();
	$fe = faz_new_frontend();
	$GLOBALS['__faz_providers'] = faz_sample_providers();
	$GLOBALS['__faz_transients']['faz_detected_cookie_names'] = array();
	$services = faz_call( $fe, 'get_per_service_services', array( array( 'analytics', 'marketing' ) ) );
	assert_eq(
		$services,
		array(),
		'empty detected list → no per-service services exposed'
	);

	// C6. per_service_cache is honoured: a populated cache short-circuits.
	faz_reset_state();
	$fe = faz_new_frontend();
	$GLOBALS['__faz_providers'] = faz_sample_providers();
	$GLOBALS['__faz_transients']['faz_detected_cookie_names'] = array( '_ga' );
	faz_set_prop( $fe, 'per_service_cache', array( array( 'id' => 'pre-cached' ) ) );
	$services = faz_call( $fe, 'get_per_service_services', array( array( 'analytics' ) ) );
	assert_eq(
		array_column( $services, 'id' ),
		array( 'pre-cached' ),
		'populated per_service_cache short-circuits recomputation'
	);

	// ============================================================
	//  D. get_service_consent()  (svc.* parse, toggle on/off, allowlist)
	// ============================================================

	// Helper: arrange a frontend with the per-service option ON/OFF, a given
	// cookie, and a fixed active-service list (so we don't depend on detection).
	function faz_arrange_consent( $cookie, $active_ids, $option_on = true ) {
		faz_reset_state();
		$fe = faz_new_frontend();
		$GLOBALS['__faz_consent_cookie'] = $cookie;
		faz_set_prop( $fe, 'settings_option_cache', array(
			'banner_control' => array( 'per_service_consent' => $option_on ),
		) );
		// Pre-seed the per-service cache so get_service_consent()'s array_column()
		// over get_per_service_services() yields exactly $active_ids.
		$svc = array();
		foreach ( $active_ids as $id ) {
			$svc[] = array( 'id' => $id, 'label' => $id, 'category' => 'analytics', 'patterns' => array(), 'cookies' => array() );
		}
		faz_set_prop( $fe, 'per_service_cache', $svc );
		return $fe;
	}

	// D1. Per-service OFF → empty map regardless of cookie content.
	$fe = faz_arrange_consent( 'svc.google-analytics:no,svc.hotjar:yes', array( 'google-analytics', 'hotjar' ), false );
	assert_eq(
		faz_call( $fe, 'get_service_consent' ),
		array(),
		'per_service_consent OFF → empty consent map (enforcement stays category-level)'
	);

	// D2. Per-service ON, empty cookie → empty map.
	$fe = faz_arrange_consent( '', array( 'google-analytics' ), true );
	assert_eq(
		faz_call( $fe, 'get_service_consent' ),
		array(),
		'per-service ON but empty consent cookie → empty map'
	);

	// D3. Valid svc.* entries are parsed into id => yes|no.
	$fe = faz_arrange_consent(
		'necessary:yes,analytics:no,svc.google-analytics:no,svc.hotjar:yes',
		array( 'google-analytics', 'hotjar' ),
		true
	);
	assert_eq(
		faz_call( $fe, 'get_service_consent' ),
		array( 'google-analytics' => 'no', 'hotjar' => 'yes' ),
		'valid svc.* entries parsed to id=>yes|no, category keys ignored'
	);

	// D4. Allowlist gate: svc.* entries for services NOT currently exposed are
	//     dropped (stale cookie from a service later removed/recategorised).
	$fe = faz_arrange_consent(
		'svc.google-analytics:no,svc.removed-service:yes',
		array( 'google-analytics' ), // removed-service is no longer exposed
		true
	);
	assert_eq(
		faz_call( $fe, 'get_service_consent' ),
		array( 'google-analytics' => 'no' ),
		'svc.* entry for a non-exposed service is dropped (allowlist gate)'
	);

	// D5. Malformed svc.* entries (bad value, missing value, empty id) are
	//     ignored by the strict regex; only the well-formed one survives.
	$fe = faz_arrange_consent(
		'svc.google-analytics:maybe,svc.hotjar:,svc.:yes,svc.facebook-pixel:no',
		array( 'google-analytics', 'hotjar', 'facebook-pixel' ),
		true
	);
	assert_eq(
		faz_call( $fe, 'get_service_consent' ),
		array( 'facebook-pixel' => 'no' ),
		'malformed svc.* entries (svc.x:maybe, svc.x:, svc.:yes) ignored; only valid kept'
	);

	// D6. service_consent_cache short-circuits a second call (option flip ignored).
	$fe = faz_arrange_consent( 'svc.google-analytics:no', array( 'google-analytics' ), true );
	$first = faz_call( $fe, 'get_service_consent' );
	// Flip the cookie AND the option underneath; cache must win.
	$GLOBALS['__faz_consent_cookie'] = 'svc.google-analytics:yes';
	faz_set_prop( $fe, 'settings_option_cache', array( 'banner_control' => array( 'per_service_consent' => false ) ) );
	$second = faz_call( $fe, 'get_service_consent' );
	assert_eq( $second, $first, 'service_consent_cache short-circuits the second call' );
	assert_eq( $second, array( 'google-analytics' => 'no' ), 'cached value is the first parse, not the flipped cookie' );

	// ============================================================
	//  E. svc.* precedence: explicit NO > explicit YES > category
	//     (via check_per_service_blocking)
	// ============================================================

	/**
	 * Arrange a frontend whose per-service services + consent are fixed, so
	 * check_per_service_blocking() exercises the precedence branch cleanly.
	 */
	function faz_arrange_blocking( $service_consent, $services ) {
		faz_reset_state();
		$fe = faz_new_frontend();
		faz_set_prop( $fe, 'service_consent_cache', $service_consent );
		faz_set_prop( $fe, 'per_service_cache', $services );
		faz_set_prop( $fe, 'pattern_service_cache', null );
		return $fe;
	}

	// Two services share the URL pattern "shared.example.com/t.js".
	$shared_services = array(
		array( 'id' => 'svc-a', 'label' => 'A', 'category' => 'analytics', 'patterns' => array( 'shared.example.com/t.js' ), 'cookies' => array() ),
		array( 'id' => 'svc-b', 'label' => 'B', 'category' => 'analytics', 'patterns' => array( 'shared.example.com/t.js' ), 'cookies' => array() ),
	);
	$attrs = ' src="https://shared.example.com/t.js" ';

	// E1. Explicit NO wins even when another matched service says YES.
	$fe = faz_arrange_blocking( array( 'svc-a' => 'yes', 'svc-b' => 'no' ), $shared_services );
	assert_eq(
		faz_call( $fe, 'check_per_service_blocking', array( $attrs, '' ) ),
		true,
		'precedence: explicit NO wins over explicit YES on a shared pattern → blocked'
	);

	// E2. Only explicit YES present → allowed (false).
	$fe = faz_arrange_blocking( array( 'svc-a' => 'yes' ), $shared_services );
	assert_eq(
		faz_call( $fe, 'check_per_service_blocking', array( $attrs, '' ) ),
		false,
		'precedence: explicit YES (no NO) → explicitly allowed (false)'
	);

	// E3. No svc.* decision for the matched services → null (defer to category).
	$fe = faz_arrange_blocking( array( 'some-other-service' => 'no' ), $shared_services );
	assert_eq(
		faz_call( $fe, 'check_per_service_blocking', array( $attrs, '' ) ),
		null,
		'precedence: no decision for matched services → null (category fallback)'
	);

	// E4. Empty service-consent map → null immediately (per-service off / no cookie).
	$fe = faz_arrange_blocking( array(), $shared_services );
	assert_eq(
		faz_call( $fe, 'check_per_service_blocking', array( $attrs, '' ) ),
		null,
		'empty service-consent map → null (no per-service enforcement)'
	);

	// E5. Script matches NOTHING in the pattern map → null even with a NO present.
	$fe = faz_arrange_blocking( array( 'svc-a' => 'no' ), $shared_services );
	assert_eq(
		faz_call( $fe, 'check_per_service_blocking', array( ' src="https://unrelated.example.org/x.js" ', '' ) ),
		null,
		'no pattern match → null (svc.* NO does not block an unmatched script)'
	);

	// ---------- Summary ----------

	echo "\n--\n";
	echo "Tests:  $tests_run\n";
	echo "Passed: $tests_passed\n";
	echo "Failed: $tests_failed\n\n";

	if ( $tests_failed > 0 ) {
		echo "\033[31mFAIL\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
