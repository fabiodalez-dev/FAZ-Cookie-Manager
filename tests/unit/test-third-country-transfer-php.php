<?php
/**
 * Standalone unit tests for the Third-country transfer (Schrems II) disclosure.
 *
 * Subsystem: third-country-transfer.
 *
 * Covers the whole server-rendered pipeline of the opt-in per-cookie
 * "transfers personal data to an insecure third country" capability:
 *
 *   1. Cookie::set_transfer() → get_transfer() round-trips enabled +
 *      multilingual countries/safeguard through the meta JSON column.
 *   2. Cookie::get_meta() does NOT corrupt the array-valued `transfer` key
 *      (the $structured_keys carve-out — sanitize_textarea_field would blank it).
 *   3. Cookie::get_prepared_data() surfaces a normalised `transfer`, and a legacy
 *      row with no `transfer` key resolves to the disabled default.
 *   4. The sanitiser coerces enabled→bool, strips disallowed HTML from the
 *      safeguard, and collapses a non-array value to the disabled default.
 *   5. Shortcodes::render_transfer_disclosure() emits the disclosure ONLY for a
 *      flagged cookie and resolves the language with the default→first fallback.
 *   6. Renderer's international-transfers section is '' with no flags, is
 *      populated (naming country + safeguard, carrying the neutral Schrems-II
 *      sentence, making NO legality claim) with a flag, excludes the
 *      wordpress-internal category, and the policy version fingerprint changes
 *      when the transfer data changes.
 *
 * Only $wpdb, the object cache and a handful of WP/faz_* helpers are polyfilled.
 * Product code is untouched.
 *
 * Run:
 *   php tests/unit/test-third-country-transfer-php.php
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
// WP helper polyfills.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$rest ) {
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$a ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$a ) {}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return true;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) {
		return abs( (int) $v );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $v ) {
		$v = is_scalar( $v ) ? (string) $v : '';
		$v = wp_strip_all_tags_local( $v );
		$v = preg_replace( '/[\r\n\t]+/', ' ', $v );
		return trim( preg_replace( '/\s+/', ' ', $v ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags_local' ) ) {
	function wp_strip_all_tags_local( $v ) {
		return preg_replace( '#<[^>]*>#', '', (string) $v );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	// Deliberately array-hostile, exactly like WP core: casting an array to a
	// string yields '' — this is precisely the corruption the $structured_keys
	// carve-out must avoid, so the test relies on the faithful behaviour.
	function sanitize_textarea_field( $v ) {
		return is_scalar( $v ) ? preg_replace( '#<[^>]*>#', '', (string) $v ) : '';
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $v ) {
		$v = strtolower( (string) $v );
		return preg_replace( '/[^a-z0-9_-]+/', '-', $v );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $v ) {
		return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) {
		return json_encode( $v );
	}
}
if ( ! function_exists( 'stripslashes' ) ) {
	// PHP built-in; never redefined.
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $v ) {
		return wp_kses( $v, array() );
	}
}
if ( ! function_exists( 'wp_kses' ) ) {
	// Minimal but faithful: drop <script>/<style> blocks entirely, then keep
	// only a safe inline subset so "disallowed HTML is stripped" is observable.
	function wp_kses( $string, $allowed = array() ) {
		$string = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $string );
		return strip_tags( $string, '<a><strong><em><span><small><br><code>' );
	}
}
if ( ! function_exists( 'wp_filter_post_kses' ) ) {
	function wp_filter_post_kses( $v ) {
		return (string) $v;
	}
}
if ( ! function_exists( 'faz_allowed_html' ) ) {
	function faz_allowed_html() {
		return array();
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $v ) {
		return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $v ) {
		return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $v ) {
		return (string) $v;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = '' ) {
		return $t;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = '' ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return '2026-01-01 00:00:00';
	}
}

// faz_* helpers.
if ( ! function_exists( 'faz_default_language' ) ) {
	function faz_default_language() {
		return 'en';
	}
}
if ( ! function_exists( 'faz_selected_languages' ) ) {
	function faz_selected_languages( $language = '' ) {
		$langs = array( 'en' );
		if ( '' !== $language && ! in_array( $language, $langs, true ) ) {
			$langs[] = $language;
		}
		return $langs;
	}
}

// ---------------------------------------------------------------------------
// Object cache.
// ---------------------------------------------------------------------------
$GLOBALS['_cache'] = array();
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) {
		$k = $group . '|' . $key;
		return array_key_exists( $k, $GLOBALS['_cache'] ) ? $GLOBALS['_cache'][ $k ] : false;
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $ttl = 0 ) {
		$GLOBALS['_cache'][ $group . '|' . $key ] = $data;
		return true;
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['_cache'][ $group . '|' . $key ] );
		return true;
	}
}

// ---------------------------------------------------------------------------
// Mock $wpdb — just enough for the Renderer's transfer query + table probe.
// ---------------------------------------------------------------------------
class Faz_Transfer_WPDB {
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
		if ( false !== stripos( $query, 'SHOW TABLES LIKE' ) ) {
			if ( preg_match( "/LIKE\\s+'([^']+)'/i", $query, $m ) ) {
				return str_replace( '\\_', '_', $m[1] );
			}
			return $this->prefix . 'faz_cookies';
		}
		return null;
	}
	public function get_results( $query, $output = ARRAY_A ) {
		return $this->cookies_rows;
	}
}

// ---------------------------------------------------------------------------
// Load real plugin classes.
// ---------------------------------------------------------------------------
$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-store.php';
require_once $root . '/admin/modules/cookies/includes/class-cookie.php';
require_once $root . '/frontend/modules/shortcodes/class-shortcodes.php';
require_once $root . '/admin/modules/cookie-policy-generator/includes/class-generator.php';
require_once $root . '/admin/modules/cookie-policy-generator/includes/class-renderer.php';

use FazCookie\Admin\Modules\Cookies\Includes\Cookie;
use FazCookie\Frontend\Modules\Shortcodes\Shortcodes;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;

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
function assert_false( $c, $l ) {
	assert_eq( (bool) $c, false, $l );
}
function assert_contains( $haystack, $needle, $l ) {
	assert_true( is_string( $haystack ) && false !== strpos( $haystack, $needle ), $l );
}
function assert_not_contains( $haystack, $needle, $l ) {
	assert_true( is_string( $haystack ) && false === strpos( $haystack, $needle ), $l );
}

/** Build a Cookie from a raw DB-shaped row. */
function make_transfer_cookie( $meta_array ) {
	$row               = new stdClass();
	$row->cookie_id    = 10;
	$row->name         = '_ga';
	$row->slug         = '_ga';
	$row->description  = wp_json_encode( array( 'en' => 'Analytics cookie' ) );
	$row->duration     = wp_json_encode( array( 'en' => '2 years' ) );
	$row->domain       = 'example.com';
	$row->category     = 3;
	$row->type         = 0;
	$row->discovered   = 1;
	$row->url_pattern  = '';
	$row->meta         = wp_json_encode( $meta_array );
	$row->date_created = '2026-01-01 00:00:00';
	$row->date_modified = '2026-01-01 00:00:00';
	return new Cookie( $row );
}

echo "\n== Third-country transfer (Schrems II) ==\n\n";

// =========================================================================
// 1. set_transfer() → get_transfer() round-trip through the meta JSON.
// =========================================================================
$c = make_transfer_cookie( array( 'opt_in_script' => '' ) );
$c->set_transfer( array(
	'enabled'   => true,
	'countries' => array( 'en' => 'United States', 'it' => 'Stati Uniti' ),
	'safeguard' => array( 'en' => 'EU-US Data Privacy Framework' ),
) );
$t = $c->get_transfer();
assert_true( true === $t['enabled'], 'set/get transfer: enabled round-trips as strict bool true' );
assert_eq( $t['countries']['en'], 'United States', 'set/get transfer: countries[en] preserved' );
assert_eq( $t['countries']['it'], 'Stati Uniti', 'set/get transfer: countries[it] (extra language) preserved' );
assert_eq( $t['safeguard']['en'], 'EU-US Data Privacy Framework', 'set/get transfer: safeguard[en] preserved' );

// The value must also survive a re-decode from the raw meta JSON that would be
// persisted (get_meta re-runs on the stored array — the corruption guard).
$raw_meta   = $c->get_meta();
$reencoded  = wp_json_encode( $raw_meta );
$roundtrip  = json_decode( $reencoded, true );
assert_true( isset( $roundtrip['transfer'] ) && is_array( $roundtrip['transfer'] ), 'set/get transfer: transfer survives a JSON persist round-trip as an array' );
assert_eq( $roundtrip['transfer']['countries']['it'] ?? null, 'Stati Uniti', 'set/get transfer: multilingual value survives the persist round-trip' );

// =========================================================================
// 2. get_meta() does NOT corrupt the array-valued transfer key.
//    (sanitize_textarea_field would blank an array → '' without the carve-out.)
// =========================================================================
$c2   = make_transfer_cookie( array(
	'opt_in_script' => '',
	'transfer'      => array(
		'enabled'   => true,
		'countries' => array( 'en' => 'Canada' ),
		'safeguard' => array( 'en' => 'Adequacy decision' ),
	),
) );
$meta = $c2->get_meta();
assert_true( is_array( $meta['transfer'] ), 'get_meta: transfer stays an array (not blanked to string)' );
assert_eq( $meta['transfer']['countries']['en'], 'Canada', 'get_meta: array-valued transfer content intact' );
assert_eq( $meta['opt_in_script'], '', 'get_meta: sibling script key still handled as string' );

// =========================================================================
// 3. get_prepared_data() surfaces normalised transfer; legacy row → default.
// =========================================================================
$prepared = $c2->get_prepared_data();
assert_true( isset( $prepared['transfer'] ) && is_array( $prepared['transfer'] ), 'get_prepared_data: includes a transfer key' );
assert_true( true === $prepared['transfer']['enabled'], 'get_prepared_data: flagged cookie reports enabled=true' );

$legacy          = make_transfer_cookie( array( 'opt_in_script' => 'x=1' ) ); // no transfer key at all
$legacy_prepared = $legacy->get_prepared_data();
assert_eq(
	$legacy_prepared['transfer'],
	array( 'enabled' => false, 'countries' => array(), 'safeguard' => array() ),
	'get_prepared_data: legacy row with no transfer key → disabled default (backward-compat)'
);

// =========================================================================
// 4. Sanitiser: enabled→bool, disallowed HTML stripped from safeguard,
//    non-array value collapses to the disabled default.
// =========================================================================
$c4 = make_transfer_cookie( array() );
$c4->set_transfer( array(
	'enabled'   => '1',
	'countries' => array( 'en' => '  United States  ' ),
	'safeguard' => array( 'en' => 'SCCs <script>alert(1)</script>' ),
) );
$t4 = $c4->get_transfer();
assert_true( true === $t4['enabled'], 'sanitiser: enabled="1" coerces to bool true' );
assert_not_contains( $t4['safeguard']['en'], '<script', 'sanitiser: <script> stripped from safeguard' );
assert_contains( $t4['safeguard']['en'], 'SCCs', 'sanitiser: safe safeguard text retained' );

$c4b = make_transfer_cookie( array() );
$c4b->set_transfer( 'not-an-array' );
assert_eq(
	$c4b->get_transfer(),
	array( 'enabled' => false, 'countries' => array(), 'safeguard' => array() ),
	'sanitiser: a non-array value collapses to the disabled default'
);

$c4c = make_transfer_cookie( array() );
$c4c->set_transfer( array( 'enabled' => 'false', 'countries' => array(), 'safeguard' => array() ) );
assert_false( $c4c->get_transfer()['enabled'], 'sanitiser: enabled="false" coerces to bool false' );

// =========================================================================
// 5. Shortcodes::render_transfer_disclosure() — flagged only, lang fallback.
// =========================================================================
$ref_sc = new ReflectionClass( Shortcodes::class );
$sc     = $ref_sc->newInstanceWithoutConstructor();
$lang_prop = $ref_sc->getProperty( 'language' );
$lang_prop->setAccessible( true );
$lang_prop->setValue( $sc, 'it' );

// Flagged cookie: country has an `it` value, safeguard only `en` (fallback).
$flagged_cookie = array(
	'name'     => '_ga',
	'transfer' => array(
		'enabled'   => true,
		'countries' => array( 'en' => 'United States', 'it' => 'Stati Uniti' ),
		'safeguard' => array( 'en' => 'Standard Contractual Clauses' ),
	),
);
$disclosure = $sc->render_transfer_disclosure( $flagged_cookie );
assert_true( '' !== $disclosure, 'render_transfer_disclosure: emits markup for a flagged cookie' );
assert_contains( $disclosure, 'Stati Uniti', 'render_transfer_disclosure: resolves country to active lang (it)' );
assert_contains( $disclosure, 'Standard Contractual Clauses', 'render_transfer_disclosure: safeguard falls back to en when it missing' );

// Not flagged → empty string (default-OFF surface guard).
$unflagged_cookie = array(
	'name'     => '_gid',
	'transfer' => array( 'enabled' => false, 'countries' => array(), 'safeguard' => array() ),
);
assert_eq( $sc->render_transfer_disclosure( $unflagged_cookie ), '', 'render_transfer_disclosure: unflagged cookie renders nothing' );

// Enabled but no country/safeguard → single generic neutral line (no orphan label).
$generic_cookie = array(
	'name'     => '_x',
	'transfer' => array( 'enabled' => true, 'countries' => array(), 'safeguard' => array() ),
);
$generic = $sc->render_transfer_disclosure( $generic_cookie );
assert_true( '' !== $generic, 'render_transfer_disclosure: enabled-but-empty still renders a neutral line' );
assert_not_contains( $generic, 'Safeguard:', 'render_transfer_disclosure: no orphan "Safeguard:" fragment when empty' );

// =========================================================================
// 6. Renderer international-transfers section + policy version fingerprint.
// =========================================================================
global $wpdb;
$wpdb = new Faz_Transfer_WPDB();

$ref_r         = new ReflectionClass( Renderer::class );
$collect       = $ref_r->getMethod( 'collect_transfer_disclosures' );
$collect->setAccessible( true );
$section       = $ref_r->getMethod( 'international_transfers_section' );
$section->setAccessible( true );
$transfer_prop = $ref_r->getProperty( 'transfer_cache' );
$transfer_prop->setAccessible( true );

$reset_renderer = function () use ( $transfer_prop ) {
	$transfer_prop->setValue( null, array() );
	$GLOBALS['_cache'] = array();
};

// 6a. No flagged cookies → section is ''.
$reset_renderer();
$wpdb->cookies_rows = array(
	array( 'cookie_name' => '_plain', 'cookie_meta' => wp_json_encode( array( 'opt_in_script' => '' ) ), 'category_slug' => 'analytics' ),
);
assert_eq( $collect->invoke( null, 'en' ), array(), 'collect_transfer_disclosures: no flags → empty list' );
assert_eq( $section->invoke( null, 'en' ), '', 'international_transfers_section: empty-state → empty string' );

// 6b. A flagged cookie + a flagged wordpress-internal cookie (must be excluded).
$reset_renderer();
$wpdb->cookies_rows = array(
	array(
		'cookie_name'   => '_ga',
		'cookie_meta'   => wp_json_encode( array(
			'transfer' => array(
				'enabled'   => true,
				'countries' => array( 'en' => 'United States' ),
				'safeguard' => array( 'en' => 'EU-US Data Privacy Framework' ),
			),
		) ),
		'category_slug' => 'analytics',
	),
	array(
		'cookie_name'   => 'wp-settings-1',
		'cookie_meta'   => wp_json_encode( array(
			'transfer' => array( 'enabled' => true, 'countries' => array( 'en' => 'United States' ), 'safeguard' => array() ),
		) ),
		'category_slug' => 'wordpress-internal',
	),
);
$rows = $collect->invoke( null, 'en' );
assert_eq( count( $rows ), 1, 'collect_transfer_disclosures: wordpress-internal category flagged cookie is excluded' );
assert_eq( $rows[0]['name'], '_ga', 'collect_transfer_disclosures: only the visitor-facing flagged cookie survives' );

$reset_renderer();
$section_html = $section->invoke( null, 'en' );
assert_contains( $section_html, 'International data transfers', 'international_transfers_section: renders the section heading' );
assert_contains( $section_html, 'United States', 'international_transfers_section: names the recipient country' );
assert_contains( $section_html, 'EU-US Data Privacy Framework', 'international_transfers_section: names the safeguard' );
assert_contains( $section_html, 'Articles 44 to 49', 'international_transfers_section: carries the neutral GDPR Art. 44-49 framing' );
assert_not_contains( $section_html, 'wp-settings-1', 'international_transfers_section: never leaks the wordpress-internal cookie' );
// Must NOT assert the transfer is legally valid.
assert_not_contains( $section_html, 'legally valid', 'international_transfers_section: makes NO legality claim' );
assert_not_contains( $section_html, 'is compliant', 'international_transfers_section: makes NO compliance claim' );

// 6c. Policy version fingerprint bumps when transfer data changes.
$data_none = array( 'COMPANY_NAME' => 'Acme' );
$data_fp1  = array( 'COMPANY_NAME' => 'Acme', 'INTERNATIONAL_TRANSFERS_FP' => sha1( 'us-dpf' ) );
$data_fp2  = array( 'COMPANY_NAME' => 'Acme', 'INTERNATIONAL_TRANSFERS_FP' => sha1( 'us-sccs' ) );
$hash_none = Generator::policy_version_hash( '', $data_none, '' );
$hash_fp1  = Generator::policy_version_hash( '', $data_fp1, '' );
$hash_fp2  = Generator::policy_version_hash( '', $data_fp2, '' );
assert_true( $hash_none !== $hash_fp1, 'policy_version_hash: adding the transfer fingerprint bumps the version' );
assert_true( $hash_fp1 !== $hash_fp2, 'policy_version_hash: changing the transfer data bumps the version again' );

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
