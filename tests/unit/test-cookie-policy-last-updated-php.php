<?php
/**
 * "Last updated" on the generated Cookie Policy moves only when the text does.
 *
 * It used to print the day of the request, so a declaration nobody had touched
 * showed a new date every day. It now prints the day the exact text a visitor
 * reads changed, keyed by the sanitized body with the default self URL
 * normalized so the request path cannot change the date.
 *
 * The WordPress stubs and the fake $wpdb are the ones of
 * test-cookie-policy-golden-render.php, plus writable add_option() and
 * update_option() and an apply_filters() that runs registered callbacks.
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

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $name, $GLOBALS['faz_test_state']['options'] ) ) {
			return false;
		}
		$GLOBALS['faz_test_state']['options'][ $name ] = $value;
		$GLOBALS['faz_test_state']['writes']          = ( $GLOBALS['faz_test_state']['writes'] ?? 0 ) + 1;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		foreach ( $GLOBALS['faz_test_filters'][ $tag ] ?? array() as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
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
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-version-ledger.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Version_Ledger;

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

$rows_of = static function () {
	return array_filter(
		$GLOBALS['faz_test_state']['options'],
		static function ( $k ) { return 0 === strpos( $k, Renderer::CONTENT_DATE_PREFIX ); },
		ARRAY_FILTER_USE_KEY
	);
};

// Changing the text and then changing it back is a change too.
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data']['company']['email'] = 'temp@example.test';
$render( '2026-12-02 09:00:00' );
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data']['company']['email'] = 'dpo@example.test';
$reverted = $date_of( $render( '2026-12-05 09:00:00' ) );
t( 'December 5, 2026' === $reverted, "reverting to an earlier text moves the date to the revert (got '$reverted')" );

// One row per variant: changes overwrite it instead of piling up.
t( 1 === count( $rows_of() ), 'a single language keeps a single row however often its text changes (got ' . count( $rows_of() ) . ')' );

// Each language has its own row, so one cannot evict or overwrite another.
$GLOBALS['faz_test_state']['now'] = '2026-12-06 09:00:00';
faz_reset_renderer_statics();
$it = preg_match( '/Ultimo aggiornamento:<\/strong>\s*([^<]+)/', Renderer::render( array( 'lang' => 'it' ) ), $mi ) ? trim( $mi[1] ) : '';
t( '6 dicembre 2026' === $it, "a second language records its own day (got '$it')" );
t( 2 === count( $rows_of() ), 'and its own row' );
t( 'December 5, 2026' === $date_of( $render( '2026-12-07 09:00:00' ) ), 'the first language keeps its date' );

// The admin preview of unsaved settings never dates a text.
$writes = $GLOBALS['faz_test_state']['writes'] ?? 0;
$saved  = $GLOBALS['faz_test_state']['options']['faz_cookie_policy_data'];
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data']['company']['email'] = 'draft@example.test';
$GLOBALS['faz_test_state']['now'] = '2026-12-08 09:00:00';
faz_reset_renderer_statics();
$preview = $date_of( Renderer::render_preview( array( 'lang' => 'en' ) ) );
t( $writes === ( $GLOBALS['faz_test_state']['writes'] ?? 0 ), 'previewing a draft writes nothing' );
t( 'December 8, 2026' === $preview, "the preview shows today for a text not yet published (got '$preview')" );
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data'] = $saved;
t( 'December 5, 2026' === $date_of( Renderer::render_preview( array( 'lang' => 'en' ) ) ), 'previewing the published text shows its real date' );
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data']['company']['email'] = 'draft@example.test';
$published = $date_of( $render( '2026-12-12 09:00:00' ) );
t( 'December 12, 2026' === $published, "the draft saved days later is dated on the day it is served, not the preview day (got '$published')" );

// A date set through the public filter wins.
$GLOBALS['faz_test_filters']['faz_cookie_policy_data'][] = static function ( $data ) {
	$data['LAST_UPDATED_DATE'] = 'January 1, 2026';
	return $data;
};
$pinned = $date_of( $render( '2026-12-20 09:00:00' ) );
t( 'January 1, 2026' === $pinned, "a date set by the faz_cookie_policy_data filter is kept (got '$pinned')" );
$GLOBALS['faz_test_filters'] = array();

$GLOBALS['faz_test_filters']['faz_cookie_policy_data'][] = static function ( $data ) {
	$data['LAST_UPDATED_DATE'] = 'December 20, 2026';
	return $data;
};
t( 'December 20, 2026' === $date_of( $render( '2026-12-20 09:00:00' ) ), 'an explicit date that is today still overrides the saved date' );
$GLOBALS['faz_test_filters'] = array();

$policy = &$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data'];
$policy['disclaimer'] = array( 'text' => 'Updated disclaimer.' );
$html = $render( '2026-12-21 09:00:00' );
t( false !== strpos( $html, 'Updated disclaimer.' ) && 'December 21, 2026' === $date_of( $html ), 'editing only the visible disclaimer changes the date' );
$policy['disclaimer']['show'] = false;
t( 'December 22, 2026' === $date_of( $render( '2026-12-22 09:00:00' ) ), 'hiding the disclaimer changes the date too' );

$policy['section_overrides']['gdpr-strict']['en'][0] = array(
	'anchor' => '# Cookie Policy',
	'text'   => "# Cookie Policy\n\n**Last updated:** {{LAST_UPDATED_DATE}}\n\n[Read this policy]({{COOKIE_POLICY_URL}}).",
);
$html = $render( '2026-12-23 09:00:00', '/original-policy/' );
t( false !== strpos( $html, 'href="https://example.test/original-policy/"' ), 'the displayed self-link has the real request URL' );
$html = $render( '2026-12-24 09:00:00', '/alias-policy/' );
t( false !== strpos( $html, 'href="https://example.test/alias-policy/"' ) && 'December 23, 2026' === $date_of( $html ), 'a different self-link leaves the date unchanged' );

$policy['jurisdiction'] = 'ccpa-california';
t( 'December 24, 2026' === $date_of( $render( '2026-12-24 09:00:00' ) ), 'the CCPA variant establishes its own date' );
$policy['dpo']['name'] = 'Unpublished DPO';
t( 'December 24, 2026' === $date_of( $render( '2026-12-25 09:00:00' ) ), 'a DPO field not displayed in CCPA does not change the date' );

// A preview that throws must not leave recording switched off for the rest
// of the request: the next public render of a changed text records its date.
$GLOBALS['faz_test_filters']['faz_cookie_policy_data'][] = static function ( $data ) {
	throw new \RuntimeException( 'filter failure' );
};
$threw = false;
try {
	faz_reset_renderer_statics();
	Renderer::render_preview( array( 'lang' => 'en' ) );
} catch ( \RuntimeException $e ) {
	$threw = true;
}
$GLOBALS['faz_test_filters'] = array();
t( $threw, 'the failing preview propagates its exception' );
$policy['dpo']['name'] = 'After the failed preview';
$policy['jurisdiction'] = 'gdpr-strict';
$render( '2026-12-26 09:00:00' );
// Rendered again the next day: only a date that was really recorded survives.
t( 'December 26, 2026' === $date_of( $render( '2026-12-27 08:00:00' ) ), 'after a failed preview a public render still records the new date' );

// The admin's version ledger renders the policy too, from wp-admin where the
// locale and the variants differ from what visitors read. It must not date
// anything: neither a new variant row nor a changed text.
$policy['dpo']['name'] = 'Changed only for the ledger';
$writes = $GLOBALS['faz_test_state']['writes'] ?? 0;
$rows   = $rows_of();
$GLOBALS['faz_test_state']['now'] = '2026-12-27 09:00:00';
faz_reset_renderer_statics();
$hash = Version_Ledger::current_hash( array( 'lang' => 'fr' ) );
faz_reset_renderer_statics();
Version_Ledger::current_hash( array( 'lang' => 'en' ) );
t( '' !== $hash, 'the ledger still reads a version hash' );
t( $writes === ( $GLOBALS['faz_test_state']['writes'] ?? 0 ) && $rows === $rows_of(), 'the version ledger writes no policy date' );
t( 'December 28, 2026' === $date_of( $render( '2026-12-28 09:00:00' ) ), 'the changed text is dated when a visitor is first served it' );

echo "\ncookie policy last updated: $ok passed, $ko failed\n";
exit( $ko > 0 ? 1 : 0 );
