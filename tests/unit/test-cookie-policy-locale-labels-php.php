<?php
/**
 * Standalone unit tests: the Cookie Policy renderer's fixed gettext labels
 * resolve in the POLICY language, not the ambient request locale.
 *
 * Subsystem: cookie-policy-generator (Renderer::build_cookie_list_html,
 * Renderer::build_services_list).
 *
 * The scaffold body is picked per language from the bundled Markdown files,
 * the date and the disclaimer are localised per language, and the transfer
 * labels are resolved under switch_to_policy_locale(). The four table
 * headings ("Cookie", "Domain", "Duration", "Description"), the "N cookies"
 * counter and the "None declared." services fallback were the exception: they
 * went through esc_html__() against whatever locale the request happened to be
 * in, so an Italian policy on an en_US site — [faz_cookie_policy_complete
 * lang="it"], the admin "default language" setting, or the REST preview —
 * carried English headings over an Italian table.
 *
 * The harness installs a tiny two-locale catalogue behind esc_html__() and
 * real-shaped switch_to_locale()/restore_previous_locale()/get_locale() stubs,
 * so the assertion is on the OUTPUT: the Italian heading must appear when the
 * policy language is it and the ambient locale is en_US, and the ambient locale
 * must be exactly what it was once the renderer returns.
 *
 * Run:
 *   php tests/unit/test-cookie-policy-locale-labels-php.php
 *
 * @package FazCookie\Tests\Unit
 */

error_reporting( E_ALL & ~E_DEPRECATED );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// ---------------------------------------------------------------------------
// Locale state + a two-locale catalogue. Only the strings under test are
// translated; everything else echoes the msgid, like an untranslated site.
// ---------------------------------------------------------------------------
$GLOBALS['faz_locale']       = 'en_US';
$GLOBALS['faz_locale_stack'] = array();
$GLOBALS['faz_switches']     = 0;
$GLOBALS['faz_catalogue']    = array(
	'it_IT' => array(
		'Cookie'                        => 'Cookie',
		'Domain'                        => 'Dominio',
		'Duration'                      => 'Durata',
		'Description'                   => 'Descrizione',
		'cookies'                       => 'cookie',
		'None declared.'                => 'Nessuno dichiarato.',
		'Transfers personal data to: %s' => 'Trasferisce dati personali verso: %s',
	),
);

function faz_wp_locale( $lang ) {
	$map = array( 'en' => 'en_US', 'it' => 'it_IT', 'pt-BR' => 'pt_BR', 'es' => 'es_ES', 'bg' => 'bg_BG', 'de' => 'de_DE', 'fr' => 'fr_FR', 'nl' => 'nl_NL', 'cs' => 'cs_CZ', 'hr' => 'hr' );
	return isset( $map[ $lang ] ) ? $map[ $lang ] : $lang;
}
function get_locale() {
	return $GLOBALS['faz_locale'];
}
$GLOBALS['faz_unavailable_locales'] = array();
function switch_to_locale( $locale ) {
	if ( $locale === $GLOBALS['faz_locale'] ) {
		return false; // core: already in that locale
	}
	if ( in_array( $locale, $GLOBALS['faz_unavailable_locales'], true ) ) {
		return false; // core: the locale is not installed
	}
	$GLOBALS['faz_locale_stack'][] = $GLOBALS['faz_locale'];
	$GLOBALS['faz_locale']         = $locale;
	$GLOBALS['faz_switches']++;
	return true;
}
function restore_previous_locale() {
	if ( empty( $GLOBALS['faz_locale_stack'] ) ) {
		return false;
	}
	$GLOBALS['faz_locale'] = array_pop( $GLOBALS['faz_locale_stack'] );
	return $GLOBALS['faz_locale'];
}
function faz_translate( $text ) {
	$locale = $GLOBALS['faz_locale'];
	return isset( $GLOBALS['faz_catalogue'][ $locale ][ $text ] ) ? $GLOBALS['faz_catalogue'][ $locale ][ $text ] : $text;
}
function __( $t, $d = '' ) {
	return faz_translate( (string) $t );
}
function esc_html__( $t, $d = '' ) {
	return htmlspecialchars( faz_translate( (string) $t ), ENT_QUOTES, 'UTF-8' );
}

// ---------------------------------------------------------------------------
// Remaining WP polyfills the renderer touches on these two paths.
// ---------------------------------------------------------------------------
function esc_html( $v ) {
	return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $v ) {
	return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $v ) {
	return (string) $v;
}
function wp_kses_post( $v ) {
	return (string) $v;
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function apply_filters( $hook, $value, ...$rest ) {
	return $value;
}
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function do_action( ...$a ) {}
function current_user_can( $cap ) {
	return true;
}
function absint( $v ) {
	return abs( (int) $v );
}
function sanitize_text_field( $v ) {
	return trim( strip_tags( is_scalar( $v ) ? (string) $v : '' ) );
}
function sanitize_key( $v ) {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) );
}
function faz_default_language() {
	return 'en';
}
function faz_selected_languages( $language = '' ) {
	return '' !== $language ? array( 'en', $language ) : array( 'en' );
}
$GLOBALS['_cache'] = array();
function wp_cache_get( $key, $group = '' ) {
	$k = $group . '|' . $key;
	return array_key_exists( $k, $GLOBALS['_cache'] ) ? $GLOBALS['_cache'][ $k ] : false;
}
function wp_cache_set( $key, $data, $group = '', $ttl = 0 ) {
	$GLOBALS['_cache'][ $group . '|' . $key ] = $data;
	return true;
}
function wp_cache_delete( $key, $group = '' ) {
	unset( $GLOBALS['_cache'][ $group . '|' . $key ] );
	return true;
}

// Mock $wpdb — the table probe and the inventory join only.
class Faz_Labels_WPDB {
	public $prefix       = 'wp_';
	public $cookies_rows = array();
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $a ) {
			$query = preg_replace( '/%d/', (string) (int) $a, $query, 1 );
			$query = preg_replace( '/%s/', "'" . addslashes( (string) $a ) . "'", $query, 1 );
		}
		return $query;
	}
	public function esc_like( $s ) {
		return addcslashes( $s, '_%\\' );
	}
	public function get_var( $query ) {
		if ( false !== stripos( $query, 'SHOW TABLES LIKE' ) && preg_match( "/LIKE\\s+'([^']+)'/i", $query, $m ) ) {
			return str_replace( '\\_', '_', $m[1] );
		}
		return null;
	}
	public function get_results( $query, $output = ARRAY_A ) {
		return $this->cookies_rows;
	}
}
global $wpdb;
$wpdb = new Faz_Labels_WPDB();
$wpdb->cookies_rows = array(
	array(
		'cookie_name'          => '_ga',
		'cookie_domain'        => 'example.com',
		'cookie_duration'      => wp_json_encode( array( 'en' => '2 years', 'it' => '2 anni' ) ),
		'cookie_description'   => wp_json_encode( array( 'en' => 'Analytics cookie', 'it' => 'Cookie analitico' ) ),
		'cookie_meta'          => wp_json_encode( array( 'opt_in_script' => '' ) ),
		'category_name'        => wp_json_encode( array( 'en' => 'Analytics', 'it' => 'Statistiche' ) ),
		'category_description' => '',
		'category_slug'        => 'analytics',
	),
);

// ---------------------------------------------------------------------------
// Load the real classes.
// ---------------------------------------------------------------------------
$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-store.php';
require_once $root . '/admin/modules/cookies/includes/class-cookie.php';
require_once $root . '/admin/modules/cookie-policy-generator/includes/class-generator.php';
require_once $root . '/admin/modules/cookie-policy-generator/includes/class-renderer.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;

// ---------------------------------------------------------------------------
// Assertion harness.
// ---------------------------------------------------------------------------
$tests_run = $tests_passed = $tests_failed = 0;
function assert_eq( $a, $e, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $a === $e ) {
		$tests_passed++;
		echo "  \033[32m✓\033[0m $label\n";
	} else {
		$tests_failed++;
		echo "  \033[31m✗\033[0m $label\n      expected: " . var_export( $e, true ) . "\n      actual:   " . var_export( $a, true ) . "\n";
	}
}
function assert_true( $c, $l ) {
	assert_eq( (bool) $c, true, $l );
}
function assert_contains( $haystack, $needle, $l ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( is_string( $haystack ) && false !== strpos( $haystack, $needle ) ) {
		$tests_passed++;
		echo "  \033[32m✓\033[0m $l\n";
	} else {
		$tests_failed++;
		echo "  \033[31m✗\033[0m $l\n      needle not found: " . var_export( $needle, true ) . "\n      in: " . var_export( $haystack, true ) . "\n";
	}
}
function assert_not_contains( $haystack, $needle, $l ) {
	assert_true( is_string( $haystack ) && false === strpos( $haystack, $needle ), $l );
}

$ref         = new ReflectionClass( Renderer::class );
$cookie_list = $ref->getMethod( 'build_cookie_list_html' );
$cookie_list->setAccessible( true );
$services = $ref->getMethod( 'build_services_list' );
$services->setAccessible( true );
$list_prop = $ref->getProperty( 'cookie_list_cache' );
$list_prop->setAccessible( true );
$rows_prop = $ref->getProperty( 'public_cookie_rows_cache' );
$rows_prop->setAccessible( true );
$reset = function () use ( $list_prop, $rows_prop ) {
	$list_prop->setValue( null, array() );
	$rows_prop->setValue( null, null );
	$GLOBALS['_cache'] = array();
};

echo "\n== Cookie Policy renderer: fixed labels follow the policy language ==\n\n";

// 1. Ambient en_US, policy language it (the lang="it" attribute / default_lang
//    / preview case): headings, counter and the inventory itself must be Italian.
$reset();
$GLOBALS['faz_locale'] = 'en_US';
$html_it = $cookie_list->invoke( null, 'it', 'gdpr-strict' );
assert_contains( $html_it, 'Statistiche', 'control: the inventory itself resolves to Italian (category name)' );
assert_contains( $html_it, '<th scope="col">Dominio</th>', 'heading "Domain" resolves in the policy language (Dominio), not the ambient en_US' );
assert_contains( $html_it, '<th scope="col">Durata</th>', 'heading "Duration" resolves in the policy language (Durata)' );
assert_contains( $html_it, '<th scope="col">Descrizione</th>', 'heading "Description" resolves in the policy language (Descrizione)' );
assert_contains( $html_it, '1 cookie</span>', 'the "N cookies" counter resolves in the policy language' );
assert_not_contains( $html_it, '<th scope="col">Domain</th>', 'no English heading survives in the Italian table' );
assert_contains( $html_it, 'data-label="Dominio"', 'the responsive data-label attributes follow the headings' );
assert_eq( $GLOBALS['faz_locale'], 'en_US', 'the ambient locale is restored after rendering the table' );
assert_eq( $GLOBALS['faz_locale_stack'], array(), 'no locale frame is left on the stack' );

// 2. Ambient already it_IT (WPML/Polylang per-page case): byte-identical output
//    and no switch at all — the common case stays a no-op.
$reset();
$GLOBALS['faz_locale']   = 'it_IT';
$GLOBALS['faz_switches'] = 0;
$html_native = $cookie_list->invoke( null, 'it', 'gdpr-strict' );
assert_eq( $html_native, $html_it, 'ambient == policy language renders byte-identically to the switched render' );
assert_eq( $GLOBALS['faz_switches'], 0, 'ambient == policy language performs no locale switch' );

// 3. The reverse mismatch: an English policy previewed by an Italian admin
//    (ambient it_IT, lang="en") must come out in English.
$reset();
$GLOBALS['faz_locale'] = 'it_IT';
$html_en = $cookie_list->invoke( null, 'en', 'gdpr-strict' );
assert_contains( $html_en, '<th scope="col">Domain</th>', 'an English policy under an Italian ambient locale keeps English headings' );
assert_contains( $html_en, '1 cookies</span>', 'the English counter is used for lang="en"' );
assert_eq( $GLOBALS['faz_locale'], 'it_IT', 'the Italian ambient locale is restored afterwards' );

// 4. "None declared." (services fallback) follows the policy language too.
$GLOBALS['faz_locale'] = 'en_US';
assert_eq( $services->invoke( null, array(), 'it' ), 'Nessuno dichiarato.', '"None declared." resolves in the policy language' );
assert_eq( $GLOBALS['faz_locale'], 'en_US', 'the ambient locale is restored after the services fallback' );
assert_eq( $services->invoke( null, array(), 'en' ), 'None declared.', '"None declared." stays English for lang="en"' );
assert_eq( $services->invoke( null, array( 'third_party_services' => array( 'ga4' ) ), 'it' ), 'Google Analytics 4', 'a declared service list is unaffected by the language' );

// 5. A policy language the site has no translation for (pt-BR, es, bg on an
//    English site, or any template language whose WordPress locale is not
//    installed) printed the English msgid, and under an Italian ambient
//    locale whose switch failed it printed the ITALIAN label inside a
//    Brazilian policy. The label shipped for the template language is used.
$faz_template_langs = array( 'en', 'it', 'de', 'fr', 'es', 'pt-BR', 'bg', 'cs', 'nl', 'hr' );
$GLOBALS['faz_locale']              = 'en_US';
$GLOBALS['faz_unavailable_locales'] = array( 'pt_BR', 'es_ES', 'bg_BG', 'de_DE', 'fr_FR', 'nl_NL', 'cs_CZ', 'hr' );
$faz_seen = array();
foreach ( $faz_template_langs as $faz_l ) {
	$faz_label = $services->invoke( null, array(), $faz_l );
	if ( 'en' === $faz_l ) {
		assert_eq( $faz_label, 'None declared.', '"None declared." for lang="en"' );
	} else {
		assert_true( 'None declared.' !== $faz_label && '' !== $faz_label, '"None declared." is localised for lang="' . $faz_l . '" when the site has no ' . $faz_l . ' translation (got ' . $faz_label . ')' );
	}
	$faz_seen[ $faz_label ] = true;
}
assert_eq( count( $faz_seen ), count( $faz_template_langs ), 'every template language has its own "None declared." label' );
assert_eq( $services->invoke( null, array(), 'pt-BR' ), 'Nenhum declarado.', 'the pt-BR LGPD policy says "Nenhum declarado."' );
$GLOBALS['faz_locale'] = 'it_IT';
assert_eq( $services->invoke( null, array(), 'pt-BR' ), 'Nenhum declarado.', 'an Italian ambient locale does not leak into a pt-BR policy when the switch fails' );
assert_eq( $GLOBALS['faz_locale'], 'it_IT', 'and the ambient locale is untouched' );
$GLOBALS['faz_unavailable_locales'] = array();
$GLOBALS['faz_locale']              = 'en_US';
assert_eq( $services->invoke( null, array(), 'it' ), 'Nessuno dichiarato.', 'a translation the site has still wins over the shipped label' );

// 6. An empty inventory: the "Cookies used" heading stood over nothing. The
//    list now says so, in the policy language.
$faz_rows_saved     = $wpdb->cookies_rows;
$wpdb->cookies_rows = array();
$GLOBALS['faz_unavailable_locales'] = array( 'pt_BR', 'es_ES', 'bg_BG', 'de_DE', 'fr_FR', 'nl_NL', 'cs_CZ', 'hr' );
$faz_seen = array();
foreach ( $faz_template_langs as $faz_l ) {
	$reset();
	$faz_html = $cookie_list->invoke( null, $faz_l, 'gdpr-strict' );
	assert_true( 1 === preg_match( '#^<p>[^<]+</p>$#', $faz_html ), 'an empty inventory renders one paragraph for lang="' . $faz_l . '" (got ' . var_export( $faz_html, true ) . ')' );
	$faz_seen[ $faz_html ] = true;
}
assert_eq( count( $faz_seen ), count( $faz_template_langs ), 'every template language has its own empty-inventory sentence' );
$reset();
assert_eq( $cookie_list->invoke( null, 'en', 'gdpr-strict' ), '<p>No cookies declared.</p>', 'English empty-inventory sentence' );
$reset();
assert_eq( $cookie_list->invoke( null, 'pt-BR', 'lgpd-brazil' ), '<p>Nenhum cookie declarado.</p>', 'pt-BR empty-inventory sentence' );
$GLOBALS['faz_unavailable_locales'] = array();
$wpdb->cookies_rows = $faz_rows_saved;
$reset();

// ---------------------------------------------------------------------------
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
