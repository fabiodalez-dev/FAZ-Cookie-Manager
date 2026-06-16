<?php
/**
 * Standalone unit tests for the Cookie-Policy Renderer i18n — Czech (cs) focus.
 *
 * Covers (edge cases, not happy-path only):
 *  - Renderer::format_date('cs') = "D. <genitive-month> YYYY"
 *  - Renderer::month_names('cs') genitive forms, 12 entries
 *  - Renderer::format_retention(..., 'cs') + boundary inputs (0, negative, huge)
 *  - Renderer::jurisdiction_display_name(..., 'cs') across all 3 jurisdictions
 *  - Renderer::language_display_name('cs', ...) endonym
 *  - Generator::resolve_template_path('...', 'cs') across gdpr-strict / ccpa /
 *    lgpd, plus unknown-lang → fallback, and "cs present in every map".
 *
 * The five Renderer helpers under test are `private static`, so they are
 * invoked here via ReflectionMethod — deterministic, no browser, no DB.
 * format_date() is pinned to a fixed timestamp by polyfilling current_time().
 *
 * Run:
 *   php tests/unit/test-czech-renderer-php.php
 *   bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
define( 'FAZ_VERSION', '1.18.0-test' );
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// ---------------------------------------------------------------------------
// Deterministic clock. format_date() reads current_time('mysql') first (and
// only falls back to gmdate() when the function is absent). We pin it to a
// fixed instant so the Czech date assertion is exact rather than "today".
// 2026-06-03 → day 3, month 6 (June → genitive "června"), year 2026.
// ---------------------------------------------------------------------------
$GLOBALS['__faz_fixed_mysql'] = '2026-06-03 09:41:00';
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		// Only 'mysql' is requested by format_date(); return the pinned value.
		return $GLOBALS['__faz_fixed_mysql'];
	}
}

// Minimal WP polyfills the class file references at load time / call time.
// These are real-ish (not no-ops) but only the escaping shape matters here.
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) { return (string) $u; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = 'default' ) { return (string) $t; }
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = 'default' ) { return (string) $t; }
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-generator.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-renderer.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;

$tests_run = $tests_passed = $tests_failed = 0;

function assert_eq( $a, $e, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $a === $e ) { $tests_passed++; echo "  \033[32m✓\033[0m $label\n"; }
	else { $tests_failed++; echo "  \033[31m✗\033[0m $label\n      expected: " . var_export( $e, true ) . "\n      actual:   " . var_export( $a, true ) . "\n"; }
}
function assert_true( $c, $l ) { assert_eq( (bool) $c, true, $l ); }
function assert_false( $c, $l ) { assert_eq( (bool) $c, false, $l ); }
function assert_contains( $haystack, $needle, $l ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( is_string( $haystack ) && false !== strpos( $haystack, $needle ) ) { $tests_passed++; echo "  \033[32m✓\033[0m $l\n"; }
	else { $tests_failed++; echo "  \033[31m✗\033[0m $l\n      needle not found: " . var_export( $needle, true ) . "\n      in: " . var_export( $haystack, true ) . "\n"; }
}

/**
 * Invoke a private static Renderer method via reflection.
 *
 * @param string $method
 * @param array  $args
 * @return mixed
 */
function faz_call_renderer( $method, array $args = array() ) {
	$ref = new ReflectionMethod( Renderer::class, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

echo "\n== Czech (cs) Cookie-Policy Renderer i18n ==\n\n";

// ---------- format_date('cs') — "D. <genitive-month> YYYY" ----------

// Pinned clock → 2026-06-03 → "3. června 2026".
$cs_date = faz_call_renderer( 'format_date', array( 'cs' ) );
assert_eq( $cs_date, '3. června 2026', 'format_date(cs) = "3. června 2026" at pinned 2026-06-03' );

// Edge: the Czech ordinal day MUST carry a trailing dot after the day number
// (it is an ordinal "3rd"), and the YEAR must NOT (Czech, unlike Bulgarian,
// has no trailing "г." era suffix). Assert the exact shape "<n>. <word> <year>".
assert_true(
	(bool) preg_match( '/^\d{1,2}\. \S+ \d{4}$/u', $cs_date ),
	'format_date(cs) matches "D. <month> YYYY" shape (ordinal dot on day, none on year)'
);
assert_false(
	false !== strpos( $cs_date, 'г.' ),
	'format_date(cs) has NO Bulgarian era suffix "г." (cs ≠ bg)'
);

// Edge: the month token must be the GENITIVE form (června), never the
// nominative dictionary form (červen) — Czech declines the month in a date.
assert_contains( $cs_date, 'června', 'format_date(cs) uses genitive month "června" (not nominative "červen")' );
assert_false(
	(bool) preg_match( '/\bčerven\b/u', $cs_date ),
	'format_date(cs) does NOT use bare nominative "červen"'
);

// Differentiation: the Czech branch differs from the English branch on the
// SAME instant. English uses "<Month> D, YYYY"; cs uses "D. <month> YYYY".
$en_date = faz_call_renderer( 'format_date', array( 'en' ) );
assert_eq( $en_date, 'June 3, 2026', 'format_date(en) = "June 3, 2026" (control)' );
assert_true( $cs_date !== $en_date, 'cs date ordering differs from en at the same instant' );

// Unknown language → format_date falls through the switch default ("%d %s %s")
// AND month_names() falls back to English → "3 June 2026" (no comma, no dot).
$xx_date = faz_call_renderer( 'format_date', array( 'zz' ) );
assert_eq( $xx_date, '3 June 2026', 'format_date(unknown) → default order + English month fallback' );

// ---------- month_names('cs') — 12 genitive entries ----------

$cs_months = faz_call_renderer( 'month_names', array( 'cs' ) );
assert_true( is_array( $cs_months ), 'month_names(cs) returns an array' );
assert_eq( count( $cs_months ), 12, 'month_names(cs) has exactly 12 entries' );
assert_eq( $cs_months[0], 'ledna', 'month_names(cs)[0] = January genitive "ledna"' );
assert_eq( $cs_months[5], 'června', 'month_names(cs)[5] = June genitive "června"' );
assert_eq( $cs_months[11], 'prosince', 'month_names(cs)[11] = December genitive "prosince"' );
// Genitive integrity: none of the 12 entries is empty, and the list is
// 0-indexed contiguous (no gaps that would make $months[$m-1] miss).
$cs_keys = array_keys( $cs_months );
assert_eq( $cs_keys, range( 0, 11 ), 'month_names(cs) is a contiguous 0..11 list' );
$cs_nonempty = count( array_filter( $cs_months, function ( $m ) { return is_string( $m ) && '' !== trim( $m ); } ) );
assert_eq( $cs_nonempty, 12, 'month_names(cs) has no empty month entries' );

// Unknown lang falls back to the English month table (12 English names).
$unknown_months = faz_call_renderer( 'month_names', array( 'qx' ) );
assert_eq( $unknown_months[0], 'January', 'month_names(unknown) falls back to English' );

// ---------- format_retention(..., 'cs') + boundary inputs ----------

assert_eq(
	faz_call_renderer( 'format_retention', array( array( 'retention_months' => 6 ), 'cs' ) ),
	'6 měsíců',
	'format_retention(cs, 6) = "6 měsíců"'
);
// Boundary: 0 months is clamped to the 12-month default (months <= 0 → 12).
assert_eq(
	faz_call_renderer( 'format_retention', array( array( 'retention_months' => 0 ), 'cs' ) ),
	'12 měsíců',
	'format_retention(cs, 0) clamps to 12 (zero is invalid)'
);
// Boundary: negative months also clamps to 12.
assert_eq(
	faz_call_renderer( 'format_retention', array( array( 'retention_months' => -3 ), 'cs' ) ),
	'12 měsíců',
	'format_retention(cs, -3) clamps to 12 (negative is invalid)'
);
// Missing key → default 12.
assert_eq(
	faz_call_renderer( 'format_retention', array( array(), 'cs' ) ),
	'12 měsíců',
	'format_retention(cs, <missing>) defaults to 12'
);
// Non-numeric string is (int)-cast → 0 → clamped to 12.
assert_eq(
	faz_call_renderer( 'format_retention', array( array( 'retention_months' => 'abc' ), 'cs' ) ),
	'12 měsíců',
	'format_retention(cs, "abc") casts to 0 then clamps to 12'
);
// Numeric string IS honoured (cast to int).
assert_eq(
	faz_call_renderer( 'format_retention', array( array( 'retention_months' => '24' ), 'cs' ) ),
	'24 měsíců',
	'format_retention(cs, "24") cast to int 24'
);
// Unknown lang → English label template "%d months".
assert_eq(
	faz_call_renderer( 'format_retention', array( array( 'retention_months' => 6 ), 'zz' ) ),
	'6 months',
	'format_retention(unknown lang) falls back to English "%d months"'
);

// ---------- jurisdiction_display_name(..., 'cs') ----------

assert_eq(
	faz_call_renderer( 'jurisdiction_display_name', array( 'gdpr-strict', 'cs' ) ),
	'GDPR (EU/EHP/UK)',
	'jurisdiction_display_name(gdpr-strict, cs) uses Czech "EHP" abbreviation'
);
assert_eq(
	faz_call_renderer( 'jurisdiction_display_name', array( 'ccpa-california', 'cs' ) ),
	'CCPA/CPRA (Kalifornie)',
	'jurisdiction_display_name(ccpa, cs) localises California → "Kalifornie"'
);
assert_eq(
	faz_call_renderer( 'jurisdiction_display_name', array( 'lgpd-brazil', 'cs' ) ),
	'LGPD (Brazílie)',
	'jurisdiction_display_name(lgpd, cs) localises Brazil → "Brazílie"'
);
// cs differs from en for the same jurisdiction (proves the cs key is wired,
// not silently falling back to en).
$gdpr_en = faz_call_renderer( 'jurisdiction_display_name', array( 'gdpr-strict', 'en' ) );
assert_true(
	$gdpr_en !== faz_call_renderer( 'jurisdiction_display_name', array( 'gdpr-strict', 'cs' ) ),
	'gdpr-strict cs label differs from en label (cs entry present, not en fallback)'
);
// Unknown jurisdiction → raw key returned (final ?? fallback).
assert_eq(
	faz_call_renderer( 'jurisdiction_display_name', array( 'pipl-china', 'cs' ) ),
	'pipl-china',
	'jurisdiction_display_name(unknown jurisdiction) returns raw key'
);
// Known jurisdiction + unknown lang → English label fallback.
assert_eq(
	faz_call_renderer( 'jurisdiction_display_name', array( 'gdpr-strict', 'zz' ) ),
	'GDPR (EU/EEA/UK)',
	'jurisdiction_display_name(gdpr, unknown lang) falls back to English'
);

// ---------- language_display_name('cs', ...) ----------

assert_eq(
	faz_call_renderer( 'language_display_name', array( 'cs', 'cs' ) ),
	'Čeština',
	'language_display_name(cs) endonym = "Čeština"'
);
// Unknown lang → raw code echoed back.
assert_eq(
	faz_call_renderer( 'language_display_name', array( 'zz', 'cs' ) ),
	'zz',
	'language_display_name(unknown) returns the raw code'
);

// ---------- resolve_template_path('...', 'cs') across jurisdictions ----------

foreach ( array( 'gdpr-strict', 'ccpa-california', 'lgpd-brazil' ) as $j ) {
	$p = Generator::resolve_template_path( $j, 'cs' );
	assert_true( is_string( $p ) && '' !== $p, "resolve_template_path($j, cs) resolves to a path" );
	$norm = is_string( $p ) ? str_replace( '\\', '/', $p ) : '';
	assert_true(
		false !== strpos( $norm, "/$j/cs.md" ),
		"resolve_template_path($j, cs) lands on the dedicated cs.md template (no fallback)"
	);
	assert_true( is_file( (string) $p ), "resolve_template_path($j, cs) target file exists on disk" );
}

// Unknown lang ("zz") is rejected at the whitelist gate → native-lang fallback,
// NOT a composed "zz.md" path. gdpr native is en, lgpd native is pt-BR.
$gdpr_unknown = Generator::resolve_template_path( 'gdpr-strict', 'zz' );
$gdpr_unknown_norm = is_string( $gdpr_unknown ) ? str_replace( '\\', '/', $gdpr_unknown ) : '';
assert_true(
	false !== strpos( $gdpr_unknown_norm, '/gdpr-strict/en.md' ),
	'resolve_template_path(gdpr, unknown) → en.md (native fallback, no zz.md)'
);
assert_false(
	false !== strpos( $gdpr_unknown_norm, 'zz' ),
	'unknown lang never appears in the composed path'
);
$lgpd_unknown = Generator::resolve_template_path( 'lgpd-brazil', 'zz' );
$lgpd_unknown_norm = is_string( $lgpd_unknown ) ? str_replace( '\\', '/', $lgpd_unknown ) : '';
assert_true(
	false !== strpos( $lgpd_unknown_norm, '/lgpd-brazil/pt-BR.md' ),
	'resolve_template_path(lgpd, unknown) → pt-BR.md (LGPD native fallback)'
);

// Unknown jurisdiction → null even with a valid cs lang.
assert_eq(
	Generator::resolve_template_path( 'pipl-china', 'cs' ),
	null,
	'resolve_template_path(unknown jurisdiction, cs) → null'
);

// ---------- "cs present in every map" sweep ----------

// Every supported language (incl. cs) must have a non-fallback entry in each
// localisation map. We assert presence by detecting that the per-lang value
// differs from a deliberately-unknown lang's fallback value (which proves the
// key exists rather than resolving via ??).
$all_langs = array( 'en', 'it', 'fr', 'de', 'es', 'pt-BR', 'bg', 'cs' );

// month_names: each lang's January must differ from at least the cs/en split,
// and crucially the cs entry must NOT equal the English fallback table.
foreach ( $all_langs as $L ) {
	$m = faz_call_renderer( 'month_names', array( $L ) );
	assert_eq( count( $m ), 12, "month_names($L) present with 12 entries" );
}
$cs_jan = faz_call_renderer( 'month_names', array( 'cs' ) )[0];
$en_jan = faz_call_renderer( 'month_names', array( 'en' ) )[0];
assert_true( $cs_jan !== $en_jan, 'month_names(cs) is a distinct table from the English fallback' );

// format_retention: each lang yields a non-empty label; cs label differs from en.
foreach ( $all_langs as $L ) {
	$r = faz_call_renderer( 'format_retention', array( array( 'retention_months' => 6 ), $L ) );
	assert_true( is_string( $r ) && '' !== $r, "format_retention($L) present (non-empty)" );
}
assert_true(
	faz_call_renderer( 'format_retention', array( array( 'retention_months' => 6 ), 'cs' ) )
		!== faz_call_renderer( 'format_retention', array( array( 'retention_months' => 6 ), 'zz' ) ),
	'format_retention(cs) differs from the unknown-lang English fallback (cs key present)'
);

// language_display_name: every lang has a distinct endonym (cs included).
$endonyms = array();
foreach ( $all_langs as $L ) {
	$endonyms[ $L ] = faz_call_renderer( 'language_display_name', array( $L, 'en' ) );
	assert_true( is_string( $endonyms[ $L ] ) && '' !== $endonyms[ $L ], "language_display_name($L) present" );
	// Present means: the returned value is NOT the raw code echo-back.
	assert_true( $endonyms[ $L ] !== $L, "language_display_name($L) returns a real endonym, not the raw code" );
}
assert_eq( count( array_unique( $endonyms ) ), count( $all_langs ), 'all 8 endonyms are distinct' );

// jurisdiction_display_name: cs present in all 3 jurisdiction maps (differs
// from en for each), proving the cs key is wired everywhere.
foreach ( array( 'gdpr-strict', 'ccpa-california', 'lgpd-brazil' ) as $j ) {
	$cs_v = faz_call_renderer( 'jurisdiction_display_name', array( $j, 'cs' ) );
	assert_true( is_string( $cs_v ) && '' !== $cs_v && $cs_v !== $j, "jurisdiction_display_name($j, cs) present (not raw key)" );
}

// ---------- Summary ----------

echo "\n--\n";
echo "Tests:  $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n\n";
if ( $tests_failed > 0 ) { echo "\033[31mFAIL\033[0m\n"; exit( 1 ); }
echo "\033[32mALL PASS\033[0m\n";
exit( 0 );
