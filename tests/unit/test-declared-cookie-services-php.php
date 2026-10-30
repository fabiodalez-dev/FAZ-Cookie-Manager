<?php
/**
 * Standalone unit tests: administrator-declared cookies as consent services (#360).
 *
 * Subsystem: cookies model / declaration surfaces.
 *
 * Covers the server side of the three #360 requests:
 *
 *   1. Cookie::get_service_id() — the stable public key a hand-declared cookie
 *      is reported under in window.getFazConsent().services: slug first, the
 *      name as fallback, normalised to the Known_Providers id alphabet.
 *   2. Cookie::set_policy_url() / get_policy_url() — absolute http(s) only; a
 *      javascript: scheme, a relative path or a non-string collapse to ''.
 *   3. Cookie::set_opt_in_run_each_load() / get_opt_in_run_each_load() — strict
 *      bool allowlist, and get_meta() does NOT coerce the stored bool through
 *      sanitize_textarea_field() (which would turn `false` into "" and the
 *      string "false" into a truthy value).
 *   4. Cookie::get_prepared_data() surfaces the three fields; a legacy row with
 *      none of the keys reports '' / false defaults.
 *   5. Shortcodes::render_policy_link() + faz_audit_table() /
 *      faz_audit_table_by_category(): the link renders ONLY when a URL is set,
 *      opens in a new tab with rel="noopener noreferrer", and the href is the
 *      operator's URL.
 *
 * Only WP/faz_* helpers are polyfilled. Product code is untouched.
 *
 * Run:
 *   php tests/unit/test-declared-cookie-services-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Frontend {
	class Frontend {
		public static function is_declaration_suppressed( $name ) {
			return 0 === strpos( (string) $name, 'wp-' );
		}
	}
}

namespace {

error_reporting( E_ALL & ~E_DEPRECATED );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// ---------------------------------------------------------------------------
// WP helper polyfills.
// ---------------------------------------------------------------------------
function do_action( $hook, ...$args ) {}
function apply_filters( $hook, $value, ...$rest ) { return $value; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function do_shortcode( $v ) { return $v; }
function current_user_can( $cap ) { return true; }
function absint( $v ) { return abs( (int) $v ); }
function wp_strip_all_tags( $v ) { return preg_replace( '#<[^>]*>#', '', (string) $v ); }
function sanitize_text_field( $v ) {
	$v = is_scalar( $v ) ? (string) $v : '';
	$v = wp_strip_all_tags( $v );
	$v = preg_replace( '/[\r\n\t]+/', ' ', $v );
	return trim( preg_replace( '/\s+/', ' ', $v ) );
}
// Array-hostile like WP core: an array cast to string is ''.
function sanitize_textarea_field( $v ) { return is_scalar( $v ) ? preg_replace( '#<[^>]*>#', '', (string) $v ) : ''; }
function sanitize_title( $v ) {
	$v = strtolower( trim( (string) $v ) );
	$v = preg_replace( '/[^a-z0-9_\-\s]+/', '', $v );
	return trim( preg_replace( '/[\s\-]+/', '-', $v ), '-' );
}
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
// Faithful to the one property under test: a disallowed scheme yields ''.
function esc_url_raw( $url, $protocols = null ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	if ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $url, $m ) ) {
		$allowed = is_array( $protocols ) ? $protocols : array( 'http', 'https', 'ftp', 'mailto', 'tel' );
		if ( ! in_array( strtolower( $m[1] ), $allowed, true ) ) {
			return '';
		}
	}
	return $url;
}
function esc_url( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_kses_post( $v ) { return strip_tags( (string) $v, '<a><strong><em><span><small><br><code>' ); }
function wp_kses( $string, $allowed = array() ) { return strip_tags( (string) $string, '<a><strong><em><span><small><br><code>' ); }
function wp_filter_post_kses( $v ) { return (string) $v; }
function faz_allowed_html() { return array(); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function current_time( $type ) { return '2026-01-01 00:00:00'; }
function faz_default_language() { return 'en'; }
function faz_current_language( $reset_cache = false ) { return 'en'; }
function faz_selected_languages( $language = '' ) { return array( 'en' ); }
function faz_array_search( $array, $key, $value ) {
	foreach ( (array) $array as $item ) {
		if ( isset( $item[ $key ] ) && $item[ $key ] === $value ) {
			return $item;
		}
	}
	return '';
}
$GLOBALS['_cache'] = array();
function wp_cache_get( $key, $group = '' ) { $k = $group . '|' . $key; return array_key_exists( $k, $GLOBALS['_cache'] ) ? $GLOBALS['_cache'][ $k ] : false; }
function wp_cache_set( $key, $data, $group = '', $ttl = 0 ) { $GLOBALS['_cache'][ $group . '|' . $key ] = $data; return true; }
function wp_cache_delete( $key, $group = '' ) { unset( $GLOBALS['_cache'][ $group . '|' . $key ] ); return true; }

// ---------------------------------------------------------------------------
// Load real plugin classes.
// ---------------------------------------------------------------------------
$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-store.php';
require_once $root . '/admin/modules/cookies/includes/class-cookie.php';
require_once $root . '/frontend/modules/shortcodes/class-shortcodes.php';

use FazCookie\Admin\Modules\Cookies\Includes\Cookie;
use FazCookie\Frontend\Modules\Shortcodes\Shortcodes;

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
function assert_true( $c, $l ) { assert_eq( (bool) $c, true, $l ); }
function assert_false( $c, $l ) { assert_eq( (bool) $c, false, $l ); }
function assert_contains( $h, $n, $l ) { assert_true( is_string( $h ) && false !== strpos( $h, $n ), $l ); }
function assert_not_contains( $h, $n, $l ) { assert_true( is_string( $h ) && false === strpos( $h, $n ), $l ); }

/** Build a Cookie from a raw DB-shaped row. */
function make_cookie( $name, $slug, $meta_array ) {
	$row                = new stdClass();
	$row->cookie_id     = 42;
	$row->name          = $name;
	$row->slug          = $slug;
	$row->description   = wp_json_encode( array( 'en' => 'Booking widget session' ) );
	$row->duration      = wp_json_encode( array( 'en' => 'session' ) );
	$row->domain        = 'booking.example';
	$row->category      = 2;
	$row->type          = 0;
	$row->discovered    = 0;
	$row->url_pattern   = '';
	$row->meta          = wp_json_encode( $meta_array );
	$row->date_created  = '2026-01-01 00:00:00';
	$row->date_modified = '2026-01-01 00:00:00';
	return new Cookie( $row );
}

echo "\n== Declared cookies as consent services (#360) ==\n\n";

// =========================================================================
// 1. get_service_id(): slug first, name fallback, normalised alphabet.
// =========================================================================
assert_eq( make_cookie( 'bk_session', 'bk_session', array() )->get_service_id(), 'bk_session', 'service_id: slug used as-is when already a valid key' );
assert_eq( make_cookie( 'Booking Widget', '', array() )->get_service_id(), 'booking-widget', 'service_id: falls back to the name when the slug is empty (admin form never sets one)' );
assert_eq( make_cookie( '_ga', '', array() )->get_service_id(), '_ga', 'service_id: a leading underscore survives (matches the cookie name the visitor sees)' );
assert_eq( make_cookie( 'Weird <b>Name</b>!', '', array() )->get_service_id(), 'weird-name', 'service_id: markup and punctuation never reach the key' );
assert_eq( make_cookie( '', '', array() )->get_service_id(), '', 'service_id: no slug and no name → empty (caller skips the row)' );

// =========================================================================
// 2. policy_url: absolute http(s) only.
// =========================================================================
$c = make_cookie( 'bk_session', '', array() );
$c->set_policy_url( '  https://booking.example/privacy?lang=en  ' );
assert_eq( $c->get_policy_url(), 'https://booking.example/privacy?lang=en', 'policy_url: https URL round-trips (trimmed)' );
$c->set_policy_url( 'http://booking.example/privacy' );
assert_eq( $c->get_policy_url(), 'http://booking.example/privacy', 'policy_url: plain http is accepted' );
$c->set_policy_url( 'javascript:alert(1)' );
assert_eq( $c->get_policy_url(), '', 'policy_url: javascript: scheme collapses to empty' );
$c->set_policy_url( '/privacy' );
assert_eq( $c->get_policy_url(), '', 'policy_url: a relative path is refused (must be absolute)' );
$c->set_policy_url( 'booking.example/privacy' );
assert_eq( $c->get_policy_url(), '', 'policy_url: a scheme-less host is refused' );
$c->set_policy_url( array( 'https://x.example' ) );
assert_eq( $c->get_policy_url(), '', 'policy_url: a non-scalar collapses to empty' );
$c->set_policy_url( '' );
assert_eq( $c->get_policy_url(), '', 'policy_url: empty clears the value' );
assert_eq( Cookie::sanitize_policy_url_value( 'https://a.example/b c' ), '', 'sanitize_policy_url_value: whitespace inside the URL is refused' );

// Persist round-trip: the sanitised URL survives get_meta() re-decoding.
$c->set_policy_url( 'https://booking.example/privacy' );
$roundtrip = json_decode( wp_json_encode( $c->get_meta() ), true );
assert_eq( $roundtrip['policy_url'] ?? null, 'https://booking.example/privacy', 'policy_url: survives a JSON persist round-trip' );

// A URL smuggled into the raw meta column (older sanitiser, direct DB edit)
// is re-judged on read, not trusted.
$raw = make_cookie( 'bk_session', '', array( 'policy_url' => 'javascript:alert(2)' ) );
assert_eq( $raw->get_policy_url(), '', 'policy_url: a javascript: value already in the column is refused on read' );

// =========================================================================
// 3. opt_in_run_each_load: strict bool, not blanked or stringified by get_meta().
// =========================================================================
$f = make_cookie( 'bk_session', '', array( 'opt_in_script' => 'window.bk = 1;' ) );
assert_false( $f->get_opt_in_run_each_load(), 'each_load: default is off' );
$f->set_opt_in_run_each_load( true );
assert_true( $f->get_opt_in_run_each_load(), 'each_load: true round-trips' );
assert_true( true === $f->get_meta()['opt_in_run_each_load'], 'each_load: stored as a strict bool, not the string "1"' );
$f->set_opt_in_run_each_load( 'false' );
assert_false( $f->get_opt_in_run_each_load(), 'each_load: the string "false" (a posted checkbox) means OFF' );
$f->set_opt_in_run_each_load( '1' );
assert_true( $f->get_opt_in_run_each_load(), 'each_load: "1" means ON' );
$f->set_opt_in_run_each_load( 'nonsense' );
assert_false( $f->get_opt_in_run_each_load(), 'each_load: an unrecognised token resolves to OFF (allowlist)' );
assert_eq( $f->get_meta()['opt_in_script'], 'window.bk = 1;', 'each_load: the sibling script key is untouched by the flag writes' );

// Raw column already holding the bool: get_meta() must keep it a bool.
$g = make_cookie( 'bk_session', '', array( 'opt_in_run_each_load' => true ) );
assert_true( true === $g->get_meta()['opt_in_run_each_load'], 'get_meta: a stored true stays bool true (not coerced through sanitize_textarea_field)' );
$h = make_cookie( 'bk_session', '', array( 'opt_in_run_each_load' => 'false' ) );
assert_true( false === $h->get_meta()['opt_in_run_each_load'], 'get_meta: a stored "false" string resolves to bool false' );

// =========================================================================
// 4. get_prepared_data() surfaces the three fields; legacy row → defaults.
// =========================================================================
$p = make_cookie( 'bk_session', '', array(
	'policy_url'           => 'https://booking.example/privacy',
	'opt_in_run_each_load' => true,
) )->get_prepared_data();
assert_eq( $p['service_id'] ?? null, 'bk_session', 'prepared: service_id present' );
assert_eq( $p['policy_url'] ?? null, 'https://booking.example/privacy', 'prepared: policy_url present' );
assert_true( true === ( $p['opt_in_run_each_load'] ?? null ), 'prepared: opt_in_run_each_load present as bool' );
assert_false( array_key_exists( 'opt_in_script', $p ), 'prepared: scripts still excluded (admin-only)' );

$legacy = make_cookie( '_gid', '_gid', array( 'opt_in_script' => 'x=1' ) )->get_prepared_data();
assert_eq( $legacy['policy_url'], '', 'prepared (legacy row): policy_url defaults to empty' );
assert_true( false === $legacy['opt_in_run_each_load'], 'prepared (legacy row): flag defaults to false' );
assert_eq( $legacy['service_id'], '_gid', 'prepared (legacy row): service_id derived anyway' );

// =========================================================================
// 5. Shortcodes: link rendered only when set, new tab, no opener/referrer.
// =========================================================================
$ref_sc = new ReflectionClass( Shortcodes::class );
$sc     = $ref_sc->newInstanceWithoutConstructor();
foreach ( array( 'language' => 'en', 'law' => 'gdpr' ) as $prop => $value ) {
	$pp = $ref_sc->getProperty( $prop );
	$pp->setAccessible( true );
	$pp->setValue( $sc, $value );
}

$link = $sc->render_policy_link( array( 'name' => 'bk_session', 'policy_url' => 'https://booking.example/privacy?a=1&b=2' ) );
assert_contains( $link, 'href="https://booking.example/privacy?a=1&amp;b=2"', 'render_policy_link: href is the operator URL, attribute-escaped' );
assert_contains( $link, 'target="_blank"', 'render_policy_link: opens in a new tab' );
assert_contains( $link, 'rel="noopener noreferrer"', 'render_policy_link: no opener, no referrer handed to the third party' );
assert_contains( $link, 'class="faz-audit-policy-link"', 'render_policy_link: carries the stylable class' );
assert_eq( $sc->render_policy_link( array( 'name' => 'bk_session', 'policy_url' => '' ) ), '', 'render_policy_link: empty URL renders nothing' );
assert_eq( $sc->render_policy_link( array( 'name' => 'bk_session' ) ), '', 'render_policy_link: missing key renders nothing (legacy rows)' );
assert_eq( $sc->render_policy_link( array( 'name' => 'bk_session', 'policy_url' => 'javascript:alert(1)' ) ), '', 'render_policy_link: refuses a non-http(s) href even if it reached the array' );

// Full preference-centre list render.
foreach ( array(
	'shortcodes' => array( array( 'uiTag' => 'audit-table', 'content' => array( 'container' => '<ul>[CONTENT]</ul>' ) ) ),
	'properties' => array( 'config' => array( array( 'tag' => 'audit-table', 'status' => true ) ) ),
	'contents'   => array( 'auditTable' => array( 'elements' => array( 'headers' => array( 'elements' => array() ) ) ) ),
) as $prop => $value ) {
	$pp = $ref_sc->getProperty( $prop );
	$pp->setAccessible( true );
	$pp->setValue( $sc, $value );
}
$cookies = array(
	array(
		'name'        => 'bk_session',
		'description' => array( 'en' => 'Booking widget session' ),
		'duration'    => array( 'en' => 'session' ),
		'transfer'    => array( 'enabled' => false, 'countries' => array(), 'safeguard' => array() ),
		'policy_url'  => 'https://booking.example/privacy',
	),
	array(
		'name'        => '_gid',
		'description' => array( 'en' => 'Analytics' ),
		'duration'    => array( 'en' => '1 day' ),
		'transfer'    => array( 'enabled' => false, 'countries' => array(), 'safeguard' => array() ),
		'policy_url'  => '',
	),
);
$table = $sc->faz_audit_table( $cookies );
assert_eq( substr_count( $table, 'faz-audit-policy-link' ), 1, 'faz_audit_table: exactly one link — only the cookie with a URL gets a row' );
assert_contains( $table, '<li class="faz-audit-policy"><div>Privacy policy</div>', 'faz_audit_table: the link sits in its own labelled row' );
assert_contains( $table, 'href="https://booking.example/privacy"', 'faz_audit_table: href is the operator URL' );

// Category table layout.
$cat = new class( $cookies ) {
	private $c;
	public function __construct( $c ) { $this->c = $c; }
	public function get_cookies() { return $this->c; }
};
$by_cat = $sc->faz_audit_table_by_category( $cat );
assert_eq( substr_count( $by_cat, 'faz-audit-policy-link' ), 1, 'faz_audit_table_by_category: one link, in the description cell' );
assert_contains( $by_cat, '<div class="faz-audit-policy"><a class="faz-audit-policy-link"', 'faz_audit_table_by_category: wrapped in the stylable block' );

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

} // namespace
