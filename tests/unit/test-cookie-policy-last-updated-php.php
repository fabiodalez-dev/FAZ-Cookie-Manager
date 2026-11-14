<?php
/**
 * "Last updated" on the generated Cookie Policy moves only when the text does.
 *
 * It used to print the day of the request, so a declaration nobody had touched
 * showed a new date every day. It now prints the day the exact text a visitor
 * reads first appeared, keyed by the policy version hash computed without the
 * page URL (an anonymous visitor must not be able to mint new keys by asking
 * for a different path).
 *
 * The WordPress stubs and the fake $wpdb are the ones of
 * test-cookie-policy-golden-render.php, plus a writable update_option().
 *
 * Run: php tests/unit/test-cookie-policy-last-updated-php.php
 */

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_test_state']['options'][ $name ] = $value;
		$GLOBALS['faz_test_state']['writes']          = ( $GLOBALS['faz_test_state']['writes'] ?? 0 ) + 1;
		return true;
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'FAZ_VERSION' ) ) {
	define( 'FAZ_VERSION', '1.25.0-test' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}


// ---------------------------------------------------------------------------
// Deterministic environment. Every value the renderer can read from "outside"
// is pinned here, so the only thing that can move a golden file is our code.
// ---------------------------------------------------------------------------
$GLOBALS['faz_test_state'] = array(
	'now'         => '2026-06-03 09:41:00', // → "3 June 2026" and locale variants
	'locale'      => 'en_US',
	'home'        => 'https://example.test',
	'options'     => array(),
	'privacy_url' => '',
	'translate'   => false, // when true, _x() returns a translated variant
	'kses_allowed' => null,  // captured allowlist from the last wp_kses() call
	'is_admin_user' => true, // current_user_can( 'manage_options' )
);
$_SERVER['REQUEST_URI'] = '/cookie-policy/';

// ---------------------------------------------------------------------------
// WordPress stubs.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	// Deterministic shape-only cleanup; real esc_url does protocol validation
	// we do not need to reproduce to detect a regression in OUR code.
	function esc_url( $u ) {
		$u = (string) $u;
		$u = str_replace( array( '"', "'", '<', '>' ), '', $u );
		return trim( $u );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = 'default' ) { return (string) $t; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = 'default' ) { return esc_html( (string) $t ); }
}
if ( ! function_exists( '_x' ) ) {
	/**
	 * Catalogue lookup. In "translate" mode we return a section that is
	 * different from the source but keeps every {{PLACEHOLDER}} intact — the
	 * two conditions Template_Translations requires before it will merge a
	 * translated section in. That drives the merge branch instead of the
	 * much more common "msgid returned unchanged" branch.
	 */
	function _x( $text, $context = '', $domain = 'default' ) {
		if ( empty( $GLOBALS['faz_test_state']['translate'] ) ) {
			return (string) $text;
		}
		return rtrim( (string) $text ) . "\n\n_(translated fixture section)_";
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $v ) { return (string) $v; }
}
if ( ! function_exists( 'wp_kses_allowed_html' ) ) {
	// A representative slice of the real 'post' allowlist: enough tags with
	// array-shaped attribute maps for render()'s role/aria-level loop to be
	// observable, which is the only property this suite asserts about it.
	function wp_kses_allowed_html( $context = 'post' ) {
		$attrs = array( 'class' => true, 'id' => true, 'style' => true );
		return array(
			'p'       => $attrs,
			'span'    => $attrs,
			'div'     => $attrs,
			'section' => $attrs,
			'details' => $attrs,
			'summary' => $attrs,
			'table'   => $attrs,
			'thead'   => $attrs,
			'tbody'   => $attrs,
			'tr'      => $attrs,
			'th'      => array( 'scope' => true ) + $attrs,
			'td'      => $attrs,
			'h1'      => $attrs,
			'h2'      => $attrs,
			'h3'      => $attrs,
			'ul'      => $attrs,
			'li'      => $attrs,
			'a'       => array( 'href' => true, 'rel' => true, 'target' => true ) + $attrs,
			'strong'  => $attrs,
			'em'      => $attrs,
			'small'   => $attrs,
			'br'      => array(),
		);
	}
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $html, $allowed = array(), $protocols = array() ) {
		$GLOBALS['faz_test_state']['kses_allowed'] = $allowed;
		return (string) $html; // identity — see the file docblock.
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_test_state']['options'] )
			? $GLOBALS['faz_test_state']['options'][ $name ]
			: $default;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) {
		return rtrim( $GLOBALS['faz_test_state']['home'], '/' ) . '/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'get_privacy_policy_url' ) ) {
	function get_privacy_policy_url() { return (string) $GLOBALS['faz_test_state']['privacy_url']; }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) {
		return 'timestamp' === $type
			? strtotime( $GLOBALS['faz_test_state']['now'] )
			: $GLOBALS['faz_test_state']['now'];
	}
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() { return (string) $GLOBALS['faz_test_state']['locale']; }
}
if ( ! function_exists( 'determine_locale' ) ) {
	function determine_locale() { return get_locale(); }
}
if ( ! function_exists( 'switch_to_locale' ) ) {
	function switch_to_locale( $locale ) {
		if ( (string) $locale === (string) $GLOBALS['faz_test_state']['locale'] ) {
			return false; // core returns false when already in that locale
		}
		$GLOBALS['faz_test_state']['locale_stack'][] = $GLOBALS['faz_test_state']['locale'];
		$GLOBALS['faz_test_state']['locale']         = (string) $locale;
		return true;
	}
}
if ( ! function_exists( 'restore_previous_locale' ) ) {
	function restore_previous_locale() {
		if ( ! empty( $GLOBALS['faz_test_state']['locale_stack'] ) ) {
			$GLOBALS['faz_test_state']['locale'] = array_pop( $GLOBALS['faz_test_state']['locale_stack'] );
			return $GLOBALS['faz_test_state']['locale'];
		}
		return false;
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) { $found = false; return false; }
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) { return true; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) { return 0; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	// Drives the two notice paths: an administrator gets an actionable
	// warning, an anonymous visitor gets an empty string. Both are pinned.
	function current_user_can( $cap ) { return ! empty( $GLOBALS['faz_test_state']['is_admin_user'] ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v, $flags = 0, $depth = 512 ) { return json_encode( $v, $flags, $depth ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $decimals = 0 ) { return number_format( (float) $n, (int) $decimals ); }
}

// ---------------------------------------------------------------------------
// Fake $wpdb. Only the three calls the renderer makes are implemented:
// table_exists()'s prepared SHOW TABLES probe, and the cookie/category join.
// ---------------------------------------------------------------------------
class FAZ_Fake_WPDB {
	public $prefix         = 'wp_';
	public $tables_present = true;
	public $rows           = array();

	public function prepare( $query, ...$args ) {
		// Only 'SHOW TABLES LIKE %s' reaches this stub.
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . (string) $arg . "'", $query, 1 );
		}
		return $query;
	}

	public function get_var( $query ) {
		if ( ! $this->tables_present ) {
			return null;
		}
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", (string) $query, $m ) ) {
			return $m[1];
		}
		return null;
	}

	public function get_results( $query, $output = OBJECT ) {
		return $this->rows;
	}
}
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

$GLOBALS['wpdb'] = new FAZ_Fake_WPDB();

// ---------------------------------------------------------------------------
// Units under test. Loaded directly — no autoloader, no plugin bootstrap, so
// the class_exists()-guarded optional dependencies stay absent by design.
// ---------------------------------------------------------------------------
$faz_root = dirname( __DIR__, 2 );
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-generator.php';
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-template-translations.php';
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-section-overrides.php';
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-renderer.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;

function faz_reset_renderer_statics() {
	foreach ( array(
		'cookie_list_cache'        => array(),
		'transfer_cache'           => array(),
		'public_cookie_rows_cache' => null,
	) as $prop => $value ) {
		$ref = new ReflectionProperty( Renderer::class, $prop );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}
}

$ok = 0; $ko = 0;
function t( $c, $l ) { global $ok, $ko; if ( $c ) { ++$ok; echo "  PASS $l\n"; } else { ++$ko; echo "  FAIL $l\n"; } }

$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data'] = array(
	'company'          => array( 'name' => 'Esempio S.r.l.', 'address' => 'Via Roma 1, Padova', 'email' => 'privacy@example.test' ),
	'retention_months' => 6,
	'obfuscate_emails' => false,
);
$GLOBALS['wpdb']->rows = array();

$render = static function ( $now, $path = '/cookie-policy/' ) {
	$GLOBALS['faz_test_state']['now'] = $now;
	$_SERVER['REQUEST_URI']           = $path;
	faz_reset_renderer_statics();
	return Renderer::render( array( 'lang' => 'en' ) );
};
$date_of = static function ( $html ) {
	return preg_match( '/Last updated:<\/strong>\s*([^<]+)/', $html, $m ) ? trim( $m[1] ) : '';
};

$day1 = $date_of( $render( '2026-06-03 09:41:00' ) );
t( 'June 3, 2026' === $day1, "first render records its own day (got '$day1')" );

$day2 = $date_of( $render( '2026-06-04 10:00:00' ) );
t( 'June 3, 2026' === $day2, "an unchanged policy keeps that date the next day (got '$day2')" );

$later = $date_of( $render( '2026-09-30 08:00:00' ) );
t( 'June 3, 2026' === $later, "and months later (got '$later')" );

$writes = $GLOBALS['faz_test_state']['writes'] ?? 0;
$render( '2026-09-30 08:00:00', '/cookie-policy/amp/' );
$render( '2026-09-30 08:00:00', '/cookie-policy/?x=1' );
$render( '2026-09-30 08:00:00', '/some/other/path/' );
t( $writes === ( $GLOBALS['faz_test_state']['writes'] ?? 0 ), 'requesting other paths writes nothing to the database' );
t( 'June 3, 2026' === $date_of( $render( '2026-09-30 08:00:00', '/other/' ) ), 'and shows the same date on any path' );

// A material change: the controller's contact moves.
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data']['company']['email'] = 'dpo@example.test';
$changed = $date_of( $render( '2026-10-12 11:00:00' ) );
t( 'October 12, 2026' === $changed, "a change to the text moves the date to that day (got '$changed')" );
$after = $date_of( $render( '2026-10-20 11:00:00' ) );
t( 'October 12, 2026' === $after, "and it stays there afterwards (got '$after')" );

// The cookie inventory is part of the text too.
$GLOBALS['wpdb']->rows = array(
	array(
		'cookie_id'            => 1,
		'cookie_name'          => '_ga',
		'cookie_domain'        => '.example.test',
		'cookie_duration'      => '{"en":"2 years"}',
		'cookie_description'   => '{"en":"Google Analytics visitor id."}',
		'cookie_meta'          => '',
		'category_id'          => 3,
		'category_name'        => '{"en":"Analytics"}',
		'category_description' => '{"en":"Measure how visitors use the site."}',
		'category_slug'        => 'analytics',
	),
);
$inv = $date_of( $render( '2026-11-14 09:00:00' ) );
t( 'November 14, 2026' === $inv, "a new declared cookie moves the date (got '$inv')" );

// The version hash visitors' consent hangs on is unchanged by any of this.
$h1 = preg_match( '/data-faz-policy-version="([^"]+)"/', $render( '2026-11-15 09:00:00' ), $m1 ) ? $m1[1] : '';
$h2 = preg_match( '/data-faz-policy-version="([^"]+)"/', $render( '2026-12-01 09:00:00' ), $m2 ) ? $m2[1] : '';
t( '' !== $h1 && $h1 === $h2, 'the policy version hash stays stable across days' );

// Bounded storage.
$dates = array();
for ( $i = 0; $i < 70; $i++ ) {
	$dates[ sprintf( '%06x.%06x', $i, $i ) ] = sprintf( '2025-01-%02d 00:00:00', 1 + ( $i % 28 ) );
}
$GLOBALS['faz_test_state']['options'][ Renderer::CONTENT_DATES_OPTION ] = $dates;
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data']['company']['email'] = 'new@example.test';
$render( '2026-12-02 09:00:00' );
$stored = $GLOBALS['faz_test_state']['options'][ Renderer::CONTENT_DATES_OPTION ];
t( count( $stored ) === Renderer::CONTENT_DATES_MAX, 'the stored history is capped at ' . Renderer::CONTENT_DATES_MAX . ' versions (got ' . count( $stored ) . ')' );
t( in_array( '2026-12-02 09:00:00', $stored, true ), 'and the cap drops the oldest, not the newest' );

echo "\ncookie policy last updated: $ok passed, $ko failed\n";
exit( $ko > 0 ? 1 : 0 );
