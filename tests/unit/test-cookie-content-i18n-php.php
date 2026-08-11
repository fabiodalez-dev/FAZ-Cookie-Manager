<?php
/**
 * Regression tests for issue #214: legacy single-language cookie rows.
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) { return trim( strip_tags( is_scalar( $value ) ? (string) $value : '' ) ); }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) { return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : ''; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_\-]+/', '-', $value );
		return trim( $value, '-' );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $value ) { return (string) $value; }
}
if ( ! function_exists( 'wp_filter_post_kses' ) ) {
	function wp_filter_post_kses( $value ) { return (string) $value; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) { return json_encode( $value ); }
}
if ( ! function_exists( 'faz_default_language' ) ) {
	function faz_default_language() { return 'en'; }
}
if ( ! function_exists( 'faz_selected_languages' ) ) {
	function faz_selected_languages( $language = '' ) {
		if ( '' !== $language ) {
			return array( $language );
		}
		return isset( $GLOBALS['cookie_i18n_selected_languages'] )
			? $GLOBALS['cookie_i18n_selected_languages']
			: array( 'en', 'it' );
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		$GLOBALS['cookie_i18n_cache_deletes'][] = $group . ':' . $key;
		return true;
	}
}
if ( ! function_exists( 'faz_clear_banner_template_cache' ) ) {
	function faz_clear_banner_template_cache() {
		$GLOBALS['cookie_i18n_template_cache_clears'] = 1 + ( $GLOBALS['cookie_i18n_template_cache_clears'] ?? 0 );
	}
}

// Lightweight controller doubles let the upgrade migration be exercised
// without bootstrapping WordPress or touching a database.
eval( 'namespace FazCookie\Admin\Modules\Cookies\Includes; class Cookie_Controller { public static function get_instance(){ return new self(); } public function delete_cache(){ $GLOBALS["cookie_i18n_controller_clears"][] = "cookies"; } }' );
eval( 'namespace FazCookie\Admin\Modules\Cookies\Includes; class Category_Controller { public static function get_instance(){ return new self(); } public function delete_cache(){ $GLOBALS["cookie_i18n_controller_clears"][] = "categories"; } }' );

require_once dirname( __DIR__, 2 ) . '/includes/class-store.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-cookie-content-i18n.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/cookies/includes/class-cookie.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-renderer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-cookie-table-shortcode.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-activator.php';

use FazCookie\Admin\Modules\Cookies\Includes\Cookie;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;
use FazCookie\Includes\Activator;
use FazCookie\Includes\Cookie_Content_I18n;
use FazCookie\Includes\Cookie_Table_Shortcode;

$tests_run = 0;
$failed    = 0;

function cookie_i18n_eq( $actual, $expected, $label ) {
	global $tests_run, $failed;
	$tests_run++;
	if ( $actual === $expected ) {
		echo "  PASS  {$label}\n";
		return;
	}
	$failed++;
	echo "  FAIL  {$label}\n        expected: " . var_export( $expected, true ) . "\n        actual:   " . var_export( $actual, true ) . "\n";
}

function cookie_i18n_row( $description = array( 'en' => 'Google Analytics cookie used to distinguish users.' ), $duration = array( 'en' => '2 years' ) ) {
	$row                = new stdClass();
	$row->cookie_id     = 214;
	$row->name          = '_ga';
	$row->slug          = '_ga';
	$row->description   = wp_json_encode( $description );
	$row->duration      = wp_json_encode( $duration );
	$row->domain        = '.example.test';
	$row->category      = 3;
	$row->type          = 0;
	$row->discovered    = 1;
	$row->url_pattern   = '';
	$row->meta          = '{}';
	$row->date_created  = '2026-08-06 00:00:00';
	$row->date_modified = '2026-08-06 00:00:00';
	return $row;
}

echo "\n== Cookie content i18n (#214) ==\n\n";

cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'it' ), '2 anni', 'duration plural is translated to Italian' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '1 hour', 'it_IT' ), '1 ora', 'WordPress locale variant is normalized' );
cookie_i18n_eq( Cookie_Content_I18n::duration( 'session', 'it' ), 'sessione', 'session duration is translated' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'cs' ), '2 roky', 'Czech few plural form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '30 minutes', 'cs' ), '30 minut', 'Czech many plural form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '22 minutes', 'pl' ), '22 minuty', 'Polish few plural form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '12 minutes', 'pl' ), '12 minut', 'Polish teen plural form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '1 year or longer', 'it' ), '', 'ambiguous free-form duration is left to stored fallback' );
cookie_i18n_eq( Cookie_Content_I18n::description( '_ga', 'it' ), 'Cookie di Google Analytics utilizzato per distinguere gli utenti.', 'curated _ga description is translated' );
cookie_i18n_eq( Cookie_Content_I18n::description( '_ga_G-ABC123', 'it' ), 'Cookie di Google Analytics 4 utilizzato per mantenere lo stato della sessione.', 'prefix cookie definition covers concrete names' );

$legacy      = new Cookie( cookie_i18n_row() );
$durations   = $legacy->get_duration();
$descriptions = $legacy->get_description();
cookie_i18n_eq( $durations['en'], '2 years', 'legacy English duration remains unchanged' );
cookie_i18n_eq( $durations['it'], '2 anni', 'legacy row receives Italian duration without DB migration' );
cookie_i18n_eq( $descriptions['en'], 'Google Analytics cookie used to distinguish users.', 'legacy English description remains unchanged' );
cookie_i18n_eq( $descriptions['it'], 'Cookie di Google Analytics utilizzato per distinguere gli utenti.', 'legacy row receives Italian description without DB migration' );

$custom = new Cookie( cookie_i18n_row(
	array( 'en' => 'English custom text', 'it' => 'Testo italiano personalizzato' ),
	array( 'en' => '2 years', 'it' => 'Durata personalizzata' )
) );
cookie_i18n_eq( $custom->get_description( 'it' ), 'Testo italiano personalizzato', 'manual Italian description wins over bundled fallback' );
cookie_i18n_eq( $custom->get_duration()['it'], 'Durata personalizzata', 'manual Italian duration wins over bundled fallback' );

$method = new ReflectionMethod( Renderer::class, 'decode_cookie_i18n_text' );
$method->setAccessible( true );
cookie_i18n_eq(
	$method->invoke( null, wp_json_encode( array( 'en' => '2 years' ) ), 'it', '_ga', 'duration' ),
	'2 anni',
	'cookie-policy renderer uses shared duration fallback'
);
// STOCK text still translates: this is the feature working, and asserting it
// first keeps the guard below from being satisfied by simply never translating.
cookie_i18n_eq(
	$method->invoke( null, wp_json_encode( array( 'en' => 'Google Analytics cookie used to distinguish users.' ) ), 'it', '_ga', 'description' ),
	'Cookie di Google Analytics utilizzato per distinguere gli utenti.',
	'cookie-policy renderer translates the plugin\'s own English description'
);
// Administrator-authored text does NOT. This assertion previously demanded the
// opposite -- that "English source" be replaced by the generic Italian sentence
// -- which pinned the defect rather than the behaviour: the description column
// carries reviewed legal copy naming a site's own processors and retention, and
// the next save persisted the generic replacement over it. Showing the author's
// English to an Italian visitor is the honest fallback; putting words in their
// mouth in a language they never reviewed is not.
cookie_i18n_eq(
	$method->invoke( null, wp_json_encode( array( 'en' => 'English source' ) ), 'it', '_ga', 'description' ),
	'English source',
	'cookie-policy renderer keeps administrator wording in every language'
);

// Legacy/plain strings belong to the default language. They are often custom
// administrator wording, not stock catalogue text, so both parallel renderers
// must preserve them in that language and only consult the catalogue when a
// different requested language is missing.
$plain_custom = 'Custom analytics wording written by the administrator.';
cookie_i18n_eq(
	$method->invoke( null, $plain_custom, 'en', '_ga', 'description' ),
	$plain_custom,
	'cookie-policy renderer preserves a plain custom description in the default language'
);

$shortcode        = ( new ReflectionClass( Cookie_Table_Shortcode::class ) )->newInstanceWithoutConstructor();
$shortcode_method = new ReflectionMethod( Cookie_Table_Shortcode::class, 'localize_cookie_field' );
$shortcode_method->setAccessible( true );
cookie_i18n_eq(
	$shortcode_method->invoke( $shortcode, $legacy, $plain_custom, 'description', 'en', 'en', 'en' ),
	$plain_custom,
	'cookie-table shortcode preserves a plain custom description in the default language'
);
// This call site hands the value in separately from the Cookie object, so the
// resolver never saw the string it was about to replace and substituted it
// anyway. Both halves are asserted: stock text translates, custom text survives.
cookie_i18n_eq(
	$shortcode_method->invoke( $shortcode, $legacy, 'Google Analytics cookie used to distinguish users.', 'description', 'it', 'en', 'it' ),
	'Cookie di Google Analytics utilizzato per distinguere gli utenti.',
	'cookie-table shortcode translates a stock default-language description for another language'
);
cookie_i18n_eq(
	$shortcode_method->invoke( $shortcode, $legacy, $plain_custom, 'description', 'it', 'en', 'it' ),
	$plain_custom,
	'cookie-table shortcode keeps administrator wording for another language'
);

$GLOBALS['cookie_i18n_controller_clears']      = array();
$GLOBALS['cookie_i18n_cache_deletes']          = array();
$GLOBALS['cookie_i18n_template_cache_clears']  = 0;
Activator::refresh_cookie_translation_caches();
cookie_i18n_eq( $GLOBALS['cookie_i18n_controller_clears'], array( 'cookies', 'categories' ), 'upgrade migration rotates prepared cookie/category caches' );
$cookie_i18n_missing_frags = array_values( array_diff(
	array( 'faz_cookie_policy:faz_cookie_policy_list_en', 'faz_cookie_policy:faz_cookie_policy_list_it' ),
	$GLOBALS['cookie_i18n_cache_deletes']
) );
cookie_i18n_eq( $cookie_i18n_missing_frags, array(), 'upgrade migration clears language-specific policy fragments' );

cookie_i18n_eq( $GLOBALS['cookie_i18n_template_cache_clears'], 1, 'upgrade migration clears banner templates' );

// The policy is not rendered only in the SELECTED languages: resolve_lang()
// takes the shortcode `lang` attribute, the page default or the WordPress
// locale, none of which is bound to faz_selected_languages(). Clearing only the
// selected set left an English fragment serving pre-translation rows on a site
// whose selected language is Italian alone.
$GLOBALS['cookie_i18n_selected_languages'] = array( 'it' );
$GLOBALS['cookie_i18n_cache_deletes']      = array();
Activator::refresh_cookie_translation_caches();
cookie_i18n_eq(
	in_array( 'faz_cookie_policy:faz_cookie_policy_list_en', $GLOBALS['cookie_i18n_cache_deletes'], true ),
	true,
	'the English policy fragment is cleared even when English is not selected'
);
unset( $GLOBALS['cookie_i18n_selected_languages'] );

// --- Croatian, and the guard that would have caught it being absent ---
//
// hr_HR ships as a plugin locale but had no entry in duration-units.json, so a
// Croatian banner silently kept its English durations. Nothing failed: the
// fallback is deliberately non-destructive, which is exactly what makes a
// missing language invisible. The coverage assertion below is the real fix —
// the four cases after it only pin the grammar.
$faz_i18n_shipped = array();
foreach ( glob( dirname( __DIR__, 2 ) . '/languages/faz-cookie-manager-*.po' ) as $faz_i18n_po ) {
	if ( preg_match( '/faz-cookie-manager-([a-z]{2})_/', basename( $faz_i18n_po ), $faz_i18n_m ) ) {
		$faz_i18n_shipped[ $faz_i18n_m[1] ] = true;
	}
}
$faz_i18n_units   = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/cookies/includes/contents/duration-units.json' ), true );
$faz_i18n_missing = array_values( array_diff( array_keys( $faz_i18n_shipped ), array_keys( (array) $faz_i18n_units ) ) );
cookie_i18n_eq( $faz_i18n_missing, array(), 'every locale the plugin ships has a duration catalogue' );

// Capitalisation follows the source: inventories store "Session" more often
// than "session", and a lowercase translation next to a capitalised English
// value is the mixed look issue #214 reports.
cookie_i18n_eq( Cookie_Content_I18n::duration( 'Session', 'it' ), 'Sessione', 'capitalised source yields a capitalised translation' );
cookie_i18n_eq( Cookie_Content_I18n::duration( 'session', 'it' ), 'sessione', 'lowercase source stays lowercase' );
cookie_i18n_eq( Cookie_Content_I18n::duration( 'Persistent', 'it' ), 'Persistente', 'the same holds for persistent' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'it' ), '2 anni', 'numeric durations are unaffected by the case rule' );

cookie_i18n_eq( Cookie_Content_I18n::duration( '1 year', 'hr' ), '1 godina', 'Croatian singular form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'hr' ), '2 godine', 'Croatian few plural form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '5 years', 'hr' ), '5 godina', 'Croatian many plural form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '12 minutes', 'hr' ), '12 minuta', 'Croatian teens take the many form, not few' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '21 hours', 'hr' ), '21 sat', 'Croatian numbers ending in 1 take the ONE form (21 sat, not 21 sati)' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '11 hours', 'hr' ), '11 sati', 'except the 11 teen, which stays many' );
// Polish does NOT share that rule: CLDR gives it `one` for exactly 1, so 21 is
// "21 lat" and never "21 rok". Pinned because the obvious generalisation from
// Croatian to "all Slavic locales" produces exactly that error.
cookie_i18n_eq( Cookie_Content_I18n::duration( '21 years', 'pl' ), '21 lat', 'Polish 21 takes the many form, NOT one' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '1 year', 'pl' ), '1 rok', 'Polish one applies to exactly 1' );
cookie_i18n_eq( Cookie_Content_I18n::duration( 'session', 'hr' ), 'sesija', 'Croatian session duration is translated' );

echo "\n" . ( $tests_run - $failed ) . "/{$tests_run} passed\n";
exit( 0 === $failed ? 0 : 1 );
