<?php
/**
 * Standalone unit tests for Placeholder_Builder::button_background().
 *
 * The "Accept cookies" button of a video placeholder sits on the service's
 * brand colour with white 14px text, which WCAG AA requires at 4.5:1. PageSpeed
 * flagged the YouTube one (white on #ff0000 is 4.0:1, wp.org topic
 * render-blocking-requests-2). Every service colour must yield a background
 * that passes, colours that already pass must be left alone, and the markup
 * must carry the safe colour where the CSS reads it.
 *
 * Run from project root:
 *   php tests/unit/test-placeholder-button-contrast-php.php
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( '__' ) ) {
	function __( $s ) { return $s; }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return (string) $s; }
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $s ) { return (string) $s; }
}
if ( ! function_exists( 'wp_kses_allowed_html' ) ) {
	function wp_kses_allowed_html() { return array(); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}

// Embed_Inventory::note(), which build() calls, reaches for these.
foreach ( array( 'get_transient', 'wp_doing_cron', 'is_admin' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}() { return false; }" ); // phpcs:ignore Squiz.PHP.Eval -- test stub.
	}
}
foreach ( array( 'set_transient', 'update_option', 'add_action' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}() { return true; }" ); // phpcs:ignore Squiz.PHP.Eval -- test stub.
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) { return $default; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) { return time(); }
}

require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-embed-inventory.php';
require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-placeholder-builder.php';

use FazCookie\Frontend\Includes\Placeholder_Builder;

$run  = 0;
$fail = 0;
function pbc_check( $ok, $label ) {
	global $run, $fail;
	++$run;
	if ( $ok ) {
		echo "  \033[32m✓\033[0m {$label}\n";
		return;
	}
	++$fail;
	echo "  \033[31m✗\033[0m {$label}\n";
}

/** Independent WCAG 2 contrast of white on #rrggbb. */
function pbc_white_contrast( $hex ) {
	$hex = ltrim( $hex, '#' );
	$l   = 0.0;
	foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $w ) {
		$c  = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
		$c  = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		$l += $w * $c;
	}
	return 1.05 / ( $l + 0.05 );
}

// getStaticProperties() rather than getStaticPropertyValue(): the latter
// honours visibility on PHP 7.4.0–7.4.8 and throws on a private property.
$colors = ( new ReflectionClass( Placeholder_Builder::class ) )->getStaticProperties()['service_colors'];

echo "\n-- every service colour gives an AA button --\n";
foreach ( $colors as $service => $brand ) {
	$btn   = Placeholder_Builder::button_background( $brand );
	$ratio = pbc_white_contrast( $btn );
	pbc_check( $ratio >= 4.5, sprintf( '%-24s %s -> %s  white %.2f:1', $service, $brand, $btn, $ratio ) );
	if ( pbc_white_contrast( $brand ) >= 4.6 ) {
		pbc_check( strtolower( $btn ) === strtolower( $brand ), "{$service}: a colour that already passes is unchanged" );
	}
}
pbc_check( '#e50000' === Placeholder_Builder::button_background( '#ff0000' ), 'YouTube red keeps its hue (#e50000)' );
pbc_check( 'not-a-colour' === Placeholder_Builder::button_background( 'not-a-colour' ), 'anything that is not #rrggbb is returned as is' );

echo "\n-- the markup carries the safe colour the CSS reads --\n";
$html = Placeholder_Builder::build( 'youtube', 'YouTube', 'marketing', '<iframe src="https://www.youtube.com/embed/x"></iframe>' );
pbc_check( false !== strpos( $html, '--faz-svc-btn:#e50000' ), 'the YouTube placeholder sets --faz-svc-btn' );
$social = Placeholder_Builder::build_social( 'instagram', 'Instagram', 'marketing' );
pbc_check( false !== strpos( $social, '--faz-svc-btn:' . Placeholder_Builder::button_background( '#e4405f' ) ), 'the social placeholder sets --faz-svc-btn too' );
$css = Placeholder_Builder::get_css();
pbc_check( false !== strpos( $css, 'background:var(--faz-svc-btn,' ), 'the video button reads --faz-svc-btn first' );
pbc_check( false === strpos( $css, 'background:var(--faz-svc-color,#ff0000)' ), 'no button background falls back to #ff0000 behind the white text' );
// A filter on the button darkens the white text along with the background
// and drops a borderline colour (Twitch, 4.64:1) under 4.5:1 on hover; the
// hover darkens the background only.
pbc_check( false === strpos( $css, 'filter:brightness' ), 'the hover does not dim the button text with a filter' );
// Non-video placeholders keep a neutral blue on purpose (the brand colour is
// a video-placeholder accent), but with the same margin over 4.5:1.
$generic = preg_match( '/\.faz-placeholder \.faz-placeholder-btn\{background:(#[0-9a-f]{6})/i', $css, $m ) ? $m[1] : '';
pbc_check( '' !== $generic && pbc_white_contrast( $generic ) >= 4.6, sprintf( 'the generic button background (%s) clears 4.6:1 with white text', $generic ) );
pbc_check( false !== strpos( $css, '.faz-placeholder--video .faz-placeholder-btn:hover{background:var(--faz-svc-btn,' ), 'the hover keeps the AA background underneath' );

echo "\nTests run: {$run}\n";
if ( $fail > 0 ) {
	echo "\033[31mFailed: {$fail}\033[0m\n";
	exit( 1 );
}
echo "\033[32mAll placeholder button-contrast tests passed.\033[0m\n";
exit( 0 );
