<?php
/**
 * Standalone unit tests for the UI gate on
 * FazCookie\Frontend\Includes\Geo_Runtime::is_enabled().
 *
 * Two wordpress.org support reports (LiteSpeed and FlyingPress) shared one root
 * cause: switching Settings > Geolocation > Geo-Targeting OFF did not switch the
 * jurisdiction runtime off. is_enabled() defaulted to `true` and only the
 * `faz_geo_ruleset_runtime` filter could change it, so
 * Frontend::is_country_dependent_output() stayed true, `Cache-Control: no-store`
 * was emitted on every front-end response, and page caching never engaged.
 * Publishers had to hand-write
 * `add_filter( 'faz_geo_ruleset_runtime', '__return_false' )` in a theme.
 *
 * The contract asserted here is therefore not cosmetic — it is the whole fix:
 *
 *   - the admin toggle is the DEFAULT (off -> false, on -> true), including the
 *     shapes a real option can take: key absent, section absent, whole option
 *     not an array (a corrupted/short-circuited faz_settings must not fail open
 *     into an uncacheable site);
 *   - the filter still overrides in BOTH directions, so integrators who already
 *     shipped the documented workaround keep working, and so an integrator can
 *     force enforcement on without the UI;
 *   - the filter receives the settings array as its second argument, which is
 *     what lets a filter decide per-site without a second get_option() call.
 *
 * The E2E counterpart (tests/e2e/specs/geo-runtime-toggle-cache-headers.spec.ts)
 * proves the same toggle reaches the real response headers.
 *
 * Run from project root:
 *   php tests/unit/test-geo-runtime-ui-gate-php.php
 *
 * Exit code 0 = all pass; 1 = at least one failure. Not a PHPUnit suite —
 * mirrors the lightweight CLI runner pattern of test-geo-runtime-defaults.php.
 * is_enabled() only needs get_option() and apply_filters(), so no WordPress
 * runtime, database or reflection is involved.
 *
 * @package FazCookie\Tests\Unit
 */

// ---------- Bootstrap ----------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

/**
 * Whatever get_option( 'faz_settings' ) should return for the current case.
 * Deliberately typed as mixed: one of the regressions under test is a
 * faz_settings that is not an array at all.
 *
 * @var mixed
 */
$GLOBALS['faz_ui_gate_settings'] = array();

/**
 * null = no filter registered; otherwise the value the filter returns.
 *
 * @var mixed
 */
$GLOBALS['faz_ui_gate_filter_return'] = null;

/**
 * Every ( $tag, $value, $extra… ) tuple apply_filters() was called with, so the
 * arguments passed to the filter can be asserted rather than assumed.
 *
 * @var array<int,array>
 */
$GLOBALS['faz_ui_gate_filter_calls'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $name    Option name.
	 * @param mixed  $default Default when the option is unknown.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) { // phpcs:ignore
		return 'faz_settings' === $name ? $GLOBALS['faz_ui_gate_settings'] : $default;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Record the call, then optionally override the value.
	 *
	 * @param string $tag   Filter name.
	 * @param mixed  $value Filtered value.
	 * @param mixed  ...$args Extra arguments.
	 * @return mixed
	 */
	function apply_filters( $tag, $value, ...$args ) { // phpcs:ignore
		$GLOBALS['faz_ui_gate_filter_calls'][] = array_merge( array( $tag, $value ), $args );
		if ( 'faz_geo_ruleset_runtime' === $tag && null !== $GLOBALS['faz_ui_gate_filter_return'] ) {
			return $GLOBALS['faz_ui_gate_filter_return'];
		}
		return $value;
	}
}

require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-geo-runtime.php';

use FazCookie\Frontend\Includes\Geo_Runtime;

// ---------- Minimal assert helpers ----------

$tests_run    = 0;
$tests_passed = 0;
$tests_failed = 0;

/**
 * @param mixed  $actual   Observed value.
 * @param mixed  $expected Expected value.
 * @param string $label    Assertion description.
 * @return void
 */
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

/**
 * Evaluate is_enabled() against a given faz_settings value and filter state.
 *
 * @param mixed $settings      Value get_option( 'faz_settings' ) returns.
 * @param mixed $filter_return null for "no filter registered", else its return.
 * @return bool
 */
function faz_ui_gate_is_enabled( $settings, $filter_return = null ) {
	$GLOBALS['faz_ui_gate_settings']      = $settings;
	$GLOBALS['faz_ui_gate_filter_return'] = $filter_return;
	$GLOBALS['faz_ui_gate_filter_calls']  = array();
	return Geo_Runtime::is_enabled();
}

/**
 * The single `faz_geo_ruleset_runtime` call recorded by the last evaluation.
 *
 * @return array|null
 */
function faz_ui_gate_last_runtime_call() {
	foreach ( array_reverse( $GLOBALS['faz_ui_gate_filter_calls'] ) as $call ) {
		if ( 'faz_geo_ruleset_runtime' === $call[0] ) {
			return $call;
		}
	}
	return null;
}

/**
 * Build a faz_settings array carrying a given geo_targeting value.
 *
 * @param mixed $value geo_targeting value.
 * @return array
 */
function faz_ui_gate_settings_with( $value ) {
	return array(
		'banner_control' => array( 'status' => true ),
		'geolocation'    => array(
			'geo_targeting'    => $value,
			'default_behavior' => 'no_banner',
		),
	);
}

// ---------- The admin toggle is the default ----------

echo "\n\033[1mGeo_Runtime::is_enabled() — Settings > Geolocation > Geo-Targeting is the default\033[0m\n";

assert_eq(
	faz_ui_gate_is_enabled( faz_ui_gate_settings_with( true ) ),
	true,
	'geo_targeting = true  -> enforcement on'
);
assert_eq(
	faz_ui_gate_is_enabled( faz_ui_gate_settings_with( false ) ),
	false,
	'geo_targeting = false -> enforcement off (the LiteSpeed/FlyingPress report)'
);
// The REST settings controller stores checkboxes as '1'/'' more often than as
// real booleans, and a legacy row can hold 'yes'/'no'/0/1. Anything PHP-falsy
// must read as off, anything truthy as on — otherwise the string '' would
// enable enforcement and re-break page caching on exactly the installs the fix
// targets.
assert_eq( faz_ui_gate_is_enabled( faz_ui_gate_settings_with( '1' ) ), true, "geo_targeting = '1'   -> on (checkbox string)" );
assert_eq( faz_ui_gate_is_enabled( faz_ui_gate_settings_with( 1 ) ), true, 'geo_targeting = 1     -> on' );
assert_eq( faz_ui_gate_is_enabled( faz_ui_gate_settings_with( 'yes' ) ), true, "geo_targeting = 'yes' -> on" );
assert_eq( faz_ui_gate_is_enabled( faz_ui_gate_settings_with( '' ) ), false, "geo_targeting = ''    -> off (unchecked checkbox)" );
assert_eq( faz_ui_gate_is_enabled( faz_ui_gate_settings_with( 0 ) ), false, 'geo_targeting = 0     -> off' );
assert_eq( faz_ui_gate_is_enabled( faz_ui_gate_settings_with( '0' ) ), false, "geo_targeting = '0'   -> off" );
assert_eq( faz_ui_gate_is_enabled( faz_ui_gate_settings_with( null ) ), false, 'geo_targeting = null  -> off' );

// ---------- Absent / malformed option shapes fail CLOSED ----------

echo "\n\033[1mGeo_Runtime::is_enabled() — absent and malformed faz_settings fail closed\033[0m\n";

assert_eq(
	faz_ui_gate_is_enabled( array( 'geolocation' => array( 'default_behavior' => 'no_banner' ) ) ),
	false,
	'geo_targeting key absent -> off'
);
assert_eq(
	faz_ui_gate_is_enabled( array( 'banner_control' => array( 'status' => true ) ) ),
	false,
	'geolocation section absent -> off'
);
assert_eq( faz_ui_gate_is_enabled( array() ), false, 'faz_settings = empty array -> off (fresh install)' );
// A non-array option is not academic: an `pre_option_faz_settings`
// short-circuit, a corrupted serialised row, or a caching layer returning false
// all land here. Reading a key off a scalar must not throw, and must not fail
// OPEN — failing open would silently make the whole site uncacheable.
assert_eq( faz_ui_gate_is_enabled( false ), false, 'faz_settings = false (option missing) -> off' );
assert_eq( faz_ui_gate_is_enabled( null ), false, 'faz_settings = null -> off' );
assert_eq( faz_ui_gate_is_enabled( 'corrupted' ), false, 'faz_settings = string -> off' );
assert_eq( faz_ui_gate_is_enabled( 42 ), false, 'faz_settings = int -> off' );
assert_eq(
	faz_ui_gate_is_enabled( array( 'geolocation' => 'not-an-array' ) ),
	false,
	'faz_settings[geolocation] = string -> off'
);

// ---------- Return type ----------

echo "\n\033[1mGeo_Runtime::is_enabled() — always a real bool\033[0m\n";

// Callers use `if ( Geo_Runtime::is_enabled() )` and store the result in
// serialised payloads; a truthy string leaking out of the filter would be a
// different value in JSON than the boolean the client expects.
assert_eq( faz_ui_gate_is_enabled( faz_ui_gate_settings_with( 'yes' ) ), true, "truthy setting is cast to bool true (not 'yes')" );
assert_eq( faz_ui_gate_is_enabled( faz_ui_gate_settings_with( false ), 'on' ), true, "truthy filter return is cast to bool true (not 'on')" );
assert_eq( faz_ui_gate_is_enabled( faz_ui_gate_settings_with( true ), 0 ), false, 'falsy filter return is cast to bool false (not 0)' );

// ---------- The filter still overrides, in both directions ----------

echo "\n\033[1mGeo_Runtime::is_enabled() — faz_geo_ruleset_runtime overrides both ways\033[0m\n";

assert_eq(
	faz_ui_gate_is_enabled( faz_ui_gate_settings_with( true ), false ),
	false,
	'filter returning false disables an enabled UI toggle (the documented workaround keeps working)'
);
assert_eq(
	faz_ui_gate_is_enabled( faz_ui_gate_settings_with( false ), true ),
	true,
	'filter returning true enables enforcement with the UI toggle off'
);
assert_eq(
	faz_ui_gate_is_enabled( false, true ),
	true,
	'filter returning true wins even when faz_settings is unreadable'
);

// ---------- The filter's arguments ----------

echo "\n\033[1mGeo_Runtime::is_enabled() — filter argument contract\033[0m\n";

$settings_on = faz_ui_gate_settings_with( true );
faz_ui_gate_is_enabled( $settings_on );
$call = faz_ui_gate_last_runtime_call();
assert_eq( is_array( $call ), true, 'faz_geo_ruleset_runtime is applied' );
assert_eq( isset( $call[1] ) ? $call[1] : null, true, 'arg 1 is the UI-derived default (true when the toggle is on)' );
assert_eq( isset( $call[2] ) ? $call[2] : null, $settings_on, 'arg 2 is the complete faz_settings array' );
assert_eq( is_array( $call ) ? count( $call ) - 1 : 0, 2, 'the filter is applied with exactly 2 arguments' );

$settings_off = faz_ui_gate_settings_with( false );
faz_ui_gate_is_enabled( $settings_off );
$call = faz_ui_gate_last_runtime_call();
assert_eq( isset( $call[1] ) ? $call[1] : null, false, 'arg 1 is false when the toggle is off' );
assert_eq( isset( $call[2] ) ? $call[2] : null, $settings_off, 'arg 2 carries the off-state settings too' );

// A filter reading $settings must see what get_option() returned, warts and
// all — normalising it to array() here would hide a corrupted row from the
// only code able to react to it.
faz_ui_gate_is_enabled( 'corrupted' );
$call = faz_ui_gate_last_runtime_call();
assert_eq( isset( $call[1] ) ? $call[1] : null, false, 'arg 1 is false for an unreadable option' );
assert_eq( isset( $call[2] ) ? $call[2] : null, 'corrupted', 'arg 2 is passed through unmodified, not coerced to array()' );

// ---------- Source-level guard: no unconditional default ----------

echo "\n\033[1mGeo_Runtime source — the runtime is not hardcoded on\033[0m\n";

$geo_runtime_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/frontend/includes/class-geo-runtime.php' );
// The exact regression: `return (bool) apply_filters( 'faz_geo_ruleset_runtime', true );`
// — a literal `true` as the filter default, with no reference to the setting.
assert_eq(
	(bool) preg_match( "/apply_filters\(\s*'faz_geo_ruleset_runtime',\s*(?:true|1)\s*[,)]/", $geo_runtime_source ),
	false,
	'is_enabled() does not pass a literal true as the filter default'
);
assert_eq(
	false !== strpos( $geo_runtime_source, "\$settings['geolocation']['geo_targeting']" ),
	true,
	'is_enabled() reads the Geo-Targeting setting'
);

// ---------- Summary ----------

echo "\n";
echo "\033[1mRan {$tests_run} assertions: ";
echo "\033[32m{$tests_passed} passed\033[0m";
if ( $tests_failed > 0 ) {
	echo ", \033[31m{$tests_failed} failed\033[0m";
}
echo "\033[0m\n";

exit( $tests_failed > 0 ? 1 : 0 );
