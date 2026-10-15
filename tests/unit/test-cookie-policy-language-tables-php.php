<?php
/**
 * Every language the generator ships a scaffold for must have a row in each of
 * the renderer's hardcoded locale tables.
 *
 * Generator::LANGUAGES lists the Markdown scaffolds the plugin ships. The
 * renderer fills {{LAST_UPDATED_DATE}}, {{RETENTION_PERIOD}}, the disclaimer
 * paragraph and the jurisdiction label from tables keyed by the same codes,
 * each with a silent English fallback. When nl and hr scaffolds were added the
 * tables were not extended, so a Dutch or Croatian policy printed
 * "Laatst bijgewerkt: 3 June 2026", "worden 12 months … bewaard" and an
 * all-English disclaimer — two languages in one notice.
 *
 * This runner pins the nl/hr values and sweeps every code in
 * Generator::LANGUAGES, so a future scaffold language cannot land without its
 * renderer rows.
 *
 * Run: php tests/unit/test-cookie-policy-language-tables-php.php
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
define( 'FAZ_VERSION', '1.34.2-test' );
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// Pinned clock: 2026-06-03 → day 3, June.
$GLOBALS['__faz_fixed_mysql'] = '2026-06-03 09:41:00';
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return $GLOBALS['__faz_fixed_mysql'];
	}
}
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
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $v ) { return (string) $v; }
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-generator.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-renderer.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;

$tests_run = $tests_passed = $tests_failed = 0;

function lt_eq( $a, $e, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $a === $e ) { $tests_passed++; echo "  ✓ $label\n"; }
	else { $tests_failed++; echo "  ✗ $label\n      expected: " . var_export( $e, true ) . "\n      actual:   " . var_export( $a, true ) . "\n"; }
}
function lt_true( $c, $label, $actual = null ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $c ) { $tests_passed++; echo "  ✓ $label\n"; }
	else { $tests_failed++; echo "  ✗ $label\n" . ( null !== $actual ? '      actual:   ' . var_export( $actual, true ) . "\n" : '' ); }
}
function lt_call( $method, array $args = array() ) {
	$ref = new ReflectionMethod( Renderer::class, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

echo "\n== Renderer locale tables cover every scaffold language ==\n\n";

// ---------- Dutch ----------
lt_eq( lt_call( 'format_date', array( 'nl' ) ), '3 juni 2026', 'format_date(nl) = "3 juni 2026"' );
lt_eq( lt_call( 'format_retention', array( array( 'retention_months' => 12 ), 'nl' ) ), '12 maanden', 'format_retention(nl, 12) = "12 maanden"' );
lt_eq( lt_call( 'format_retention', array( array( 'retention_months' => 1 ), 'nl' ) ), '1 maand', 'format_retention(nl, 1) = "1 maand" (singular)' );
lt_eq( lt_call( 'jurisdiction_display_name', array( 'gdpr-strict', 'nl' ) ), 'AVG (EU/EER/VK)', 'jurisdiction_display_name(gdpr, nl) uses the Dutch name of the GDPR, as the nl scaffold does' );
lt_eq( lt_call( 'jurisdiction_display_name', array( 'popia-southafrica', 'nl' ) ), 'POPIA (Zuid-Afrika)', 'jurisdiction_display_name(popia, nl)' );
lt_eq( lt_call( 'language_display_name', array( 'nl', 'nl' ) ), 'Nederlands', 'language_display_name(nl) = "Nederlands"' );
$nl_disc = lt_call( 'disclaimer', array( 'gdpr-strict', 'nl', array( 'OFFICIAL_RESOURCES_URL' => 'https://edpb.europa.eu/' ), array() ) );
lt_true( false !== strpos( $nl_disc, 'Dit cookiebeleid' ), 'disclaimer(nl) is written in Dutch', $nl_disc );
lt_true( false !== strpos( $nl_disc, 'AVG (EU/EER/VK)' ), 'disclaimer(nl) names the jurisdiction in Dutch', $nl_disc );

// ---------- Croatian ----------
lt_eq( lt_call( 'format_date', array( 'hr' ) ), '3. lipnja 2026.', 'format_date(hr) = "3. lipnja 2026." (ordinal day, genitive month, year dot)' );
lt_eq( lt_call( 'format_retention', array( array( 'retention_months' => 12 ), 'hr' ) ), '12 mjeseci', 'format_retention(hr, 12) = "12 mjeseci"' );
lt_eq( lt_call( 'format_retention', array( array( 'retention_months' => 1 ), 'hr' ) ), '1 mjesec', 'format_retention(hr, 1) = "1 mjesec"' );
lt_eq( lt_call( 'format_retention', array( array( 'retention_months' => 3 ), 'hr' ) ), '3 mjeseca', 'format_retention(hr, 3) = "3 mjeseca" (paucal)' );
lt_eq( lt_call( 'format_retention', array( array( 'retention_months' => 13 ), 'hr' ) ), '13 mjeseci', 'format_retention(hr, 13) = "13 mjeseci" (teens take the plural)' );
lt_eq( lt_call( 'format_retention', array( array( 'retention_months' => 21 ), 'hr' ) ), '21 mjesec', 'format_retention(hr, 21) = "21 mjesec"' );
lt_eq( lt_call( 'format_retention', array( array( 'retention_months' => 24 ), 'hr' ) ), '24 mjeseca', 'format_retention(hr, 24) = "24 mjeseca"' );
lt_eq( lt_call( 'jurisdiction_display_name', array( 'gdpr-strict', 'hr' ) ), 'GDPR (EU/EGP/UK)', 'jurisdiction_display_name(gdpr, hr)' );
lt_eq( lt_call( 'jurisdiction_display_name', array( 'lgpd-brazil', 'hr' ) ), 'LGPD (Brazil)', 'jurisdiction_display_name(lgpd, hr) — Croatian spells Brazil the same way' );
lt_eq( lt_call( 'jurisdiction_display_name', array( 'popia-southafrica', 'hr' ) ), 'POPIA (Južna Afrika)', 'jurisdiction_display_name(popia, hr)' );
lt_eq( lt_call( 'language_display_name', array( 'hr', 'hr' ) ), 'Hrvatski', 'language_display_name(hr) = "Hrvatski"' );
$hr_disc = lt_call( 'disclaimer', array( 'gdpr-strict', 'hr', array( 'OFFICIAL_RESOURCES_URL' => 'https://edpb.europa.eu/' ), array() ) );
lt_true( false !== strpos( $hr_disc, 'Ova pravila o kolačićima' ), 'disclaimer(hr) is written in Croatian', $hr_disc );

// ---------- Sweep: every Generator::LANGUAGES code has its own row ----------
$en_months     = lt_call( 'month_names', array( 'en' ) );
$en_retention  = lt_call( 'format_retention', array( array( 'retention_months' => 6 ), 'en' ) );
$en_disclaimer = lt_call( 'disclaimer', array( 'gdpr-strict', 'en', array(), array() ) );
foreach ( Generator::LANGUAGES as $lang ) {
	$months = lt_call( 'month_names', array( $lang ) );
	lt_true( 12 === count( $months ), "month_names($lang) has 12 entries" );
	lt_true( 'en' === $lang || $months !== $en_months, "month_names($lang) is its own row, not the English fallback" );
	$ret = lt_call( 'format_retention', array( array( 'retention_months' => 6 ), $lang ) );
	lt_true( 'en' === $lang || $ret !== $en_retention, "format_retention($lang) is its own row, not \"6 months\"", $ret );
	$disc = lt_call( 'disclaimer', array( 'gdpr-strict', $lang, array(), array() ) );
	lt_true( 'en' === $lang || $disc !== $en_disclaimer, "disclaimer($lang) is its own text, not the English paragraph" );
	lt_true( lt_call( 'language_display_name', array( $lang, $lang ) ) !== $lang, "language_display_name($lang) is an endonym, not the raw code" );
	foreach ( array( 'gdpr-strict', 'popia-southafrica' ) as $j ) {
		$label = lt_call( 'jurisdiction_display_name', array( $j, $lang ) );
		$en    = lt_call( 'jurisdiction_display_name', array( $j, 'en' ) );
		lt_true( 'en' === $lang || $label !== $en, "jurisdiction_display_name($j, $lang) is localised", $label );
	}
}

echo "\n--\nTests:  $tests_run\nPassed: $tests_passed\nFailed: $tests_failed\n";
exit( $tests_failed > 0 ? 1 : 0 );
