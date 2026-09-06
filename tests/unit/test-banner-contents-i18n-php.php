<?php
/**
 * Banner copy must resolve in the visitor's language.
 *
 * Follow-up to the category report on wordpress.org: the same three causes were
 * present in the banner resolver. No ru/uk catalogue shipped, so a Russian site
 * fell through to en.json; the bundled catalogue was only consulted for the 41
 * languages in a hard-coded list; and a catalogue was taken whole or not at all,
 * so a partial download dropped the bundled wording for everything it did not
 * cover.
 *
 * Run: php tests/unit/test-banner-contents-i18n-php.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['cache']   = array();
$GLOBALS['uploads'] = array();   // filename => decoded payload

function wp_cache_get( $k, $g = '' ) { return array_key_exists( "$g/$k", $GLOBALS['cache'] ) ? $GLOBALS['cache']["$g/$k"] : false; }
function wp_cache_set( $k, $v, $g = '', $t = 0 ) { $GLOBALS['cache']["$g/$k"] = $v; return true; }
function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['cache']["$g/$k"] ); return true; }
function sanitize_file_name( $v ) { return preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $v ); }
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( strip_tags( $v ) ) : ''; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function trailingslashit( $v ) { return rtrim( (string) $v, '/\\' ) . '/'; }
function wp_upload_dir() { return array( 'basedir' => '/faz-test-uploads' ); }
function __( $v, ...$u ) { return $v; }
function esc_html__( $v, ...$u ) { return $v; }
function esc_attr( $v ) { return $v; }
function esc_url( $v ) { return $v; }
function wp_kses_post( $v ) { return $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function apply_filters( $t, $v ) { return $v; }
function get_option( $n, $d = false ) { return $d; }
function absint( $v ) { return abs( (int) $v ); }
function wp_parse_args( $a, $d = array() ) { return array_merge( $d, (array) $a ); }

/** Reads real files from the plugin; uploads are served from the fixture map. */
function faz_read_json_file( $path ) {
	$name = basename( (string) $path );
	if ( false !== strpos( (string) $path, '/faz-test-uploads/' ) ) {
		return $GLOBALS['uploads'][ $name ] ?? false;
	}
	$raw = @file_get_contents( $path );
	return false === $raw ? false : json_decode( $raw, true );
}

require_once dirname( __DIR__, 2 ) . '/includes/class-store.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-banner.php';

use FazCookie\Admin\Modules\Banners\Includes\Banner;

$ok = 0; $ko = 0;
function t( $c, $l ) { global $ok, $ko; if ( $c ) { ++$ok; echo "  PASS $l\n"; } else { ++$ko; echo "  FAIL $l\n"; } }
function reset_cache() { $GLOBALS['cache'] = array(); $GLOBALS['uploads'] = array(); }

$dir = dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/contents/';
$en  = json_decode( file_get_contents( $dir . 'en.json' ), true );

// 1. The gap the report was about: a shipped catalogue for these languages.
foreach ( array( 'ru', 'uk' ) as $lang ) {
	reset_cache();
	$c = Banner::resolve_bundled_contents( $lang );
	t( isset( $c['gdpr'], $c['ccpa'] ), "$lang: both laws resolve" );
	$title = $c['gdpr']['notice']['elements']['title'] ?? '';
	t( '' !== $title && $title !== $en['gdpr']['notice']['elements']['title'],
		"$lang: the notice title is not the English one" );

	// Structure must match English exactly, or a field silently disappears from
	// the banner for this language only.
	$paths = function ( $node, $p = '' ) use ( &$paths ) {
		if ( ! is_array( $node ) ) { return array( $p ); }
		$out = array();
		foreach ( $node as $k => $v ) { $out = array_merge( $out, $paths( $v, "$p/$k" ) ); }
		return $out;
	};
	$expected = $paths( $en );
	$actual   = $paths( $c );
	sort( $expected ); sort( $actual );
	t( $expected === $actual, "$lang: catalogue covers every field English has" );

	// Every non-empty English leaf must differ, or something went untranslated.
	$leaves = function ( $node, $p = '' ) use ( &$leaves ) {
		if ( ! is_array( $node ) ) { return array( $p => $node ); }
		$out = array();
		foreach ( $node as $k => $v ) { $out += $leaves( $v, "$p/$k" ); }
		return $out;
	};
	$el = $leaves( $en ); $al = $leaves( $c );
	$same = array();
	foreach ( $el as $path => $value ) {
		if ( is_string( $value ) && '' !== trim( $value ) && ( $al[ $path ] ?? null ) === $value ) {
			$same[] = $path;
		}
	}
	t( array() === $same, "$lang: no leaf left in English" . ( $same ? ' (' . $same[0] . ')' : '' ) );
}

// 2. A language with no catalogue still resolves, in English.
reset_cache();
$sw = Banner::resolve_bundled_contents( 'sw' );
t( ( $sw['gdpr']['notice']['elements']['title'] ?? '' ) === $en['gdpr']['notice']['elements']['title'],
	'a language with no catalogue falls back to English' );

// 3. A downloaded catalogue wins — but only field by field. The old shape took
//    one source whole, so a partial download dropped everything it omitted.
reset_cache();
$ru = json_decode( file_get_contents( $dir . 'ru.json' ), true );
$GLOBALS['uploads']['ru.json'] = array( 'banner_data' => array(
	'gdpr' => array( 'notice' => array( 'elements' => array( 'title' => 'Загруженный заголовок' ) ) ),
) );
$c = Banner::resolve_bundled_contents( 'ru' );
t( 'Загруженный заголовок' === ( $c['gdpr']['notice']['elements']['title'] ?? '' ),
	'a downloaded translation takes priority' );
t( ( $c['gdpr']['notice']['elements']['description'] ?? '' ) === $ru['gdpr']['notice']['elements']['description'],
	'and a partial download keeps the bundled wording it did not cover' );
t( isset( $c['ccpa']['notice']['elements']['title'] ),
	'and the law it never mentioned is still present' );

// 4. Downloaded catalogues carry untranslated strings verbatim. Letting one
//    through would overwrite the bundled translation with the English it fell
//    back from.
reset_cache();
$GLOBALS['uploads']['ru.json'] = array( 'banner_data' => array(
	'gdpr' => array( 'notice' => array( 'elements' => array( 'title' => $en['gdpr']['notice']['elements']['title'] ) ) ),
) );
$c = Banner::resolve_bundled_contents( 'ru' );
t( ( $c['gdpr']['notice']['elements']['title'] ?? '' ) === $ru['gdpr']['notice']['elements']['title'],
	'an English value in a download does not overwrite the bundled Russian' );

// 5. For English itself that skip must NOT apply, or the catalogue would be
//    unable to state its own values.
reset_cache();
$GLOBALS['uploads']['en.json'] = array( 'banner_data' => array(
	'gdpr' => array( 'notice' => array( 'elements' => array( 'title' => $en['gdpr']['notice']['elements']['title'] ) ) ),
) );
$c = Banner::resolve_bundled_contents( 'en' );
t( ( $c['gdpr']['notice']['elements']['title'] ?? '' ) === $en['gdpr']['notice']['elements']['title'],
	'English resolves to English' );

// 6. The invariant that motivated sharing one resolver: the baseline reported by
//    get_law_notice_descriptions() cannot disagree with what the frontend renders.
foreach ( array( 'ru', 'uk', 'en', 'sw' ) as $lang ) {
	reset_cache();
	$desc = Banner::get_law_notice_descriptions( $lang );
	$full = Banner::resolve_bundled_contents( $lang );
	$agree = true;
	foreach ( array( 'gdpr', 'ccpa' ) as $law ) {
		if ( $desc[ $law ] !== ( $full[ $law ]['notice']['elements']['description'] ?? '' ) ) { $agree = false; }
	}
	t( $agree, "$lang: the reported baseline matches the rendered copy" );
}

echo "\nbanner contents i18n: $ok passed, $ko failed\n";
exit( $ko > 0 ? 1 : 0 );
