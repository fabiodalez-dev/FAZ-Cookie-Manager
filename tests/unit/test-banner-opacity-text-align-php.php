<?php
/**
 * Notice background opacity and text alignment: shape enforcement and wiring.
 *
 * Requested on the wp.org forum ("More Control than Colours"). Both values end
 * up in class-template.php as custom-property values, where esc_attr() does not
 * strip `{`, `}` or `;`, so — as for the colours and the button radius — the
 * shape is enforced on save. The browser half (the computed style of the real
 * banner) is in tests/e2e/specs/banner-opacity-text-align.spec.ts.
 */
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_text_field( $v ) { return (string) $v; }
function sanitize_hex_color( $v ) { return preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $v ) ? $v : ''; }
function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
function wp_kses_post( $v ) { return (string) $v; }
function sanitize_textarea_field( $v ) { return (string) $v; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function esc_url_raw( $v ) { return (string) $v; }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function __( $v, $d = '' ) { return $v; }
require_once dirname( __DIR__, 2 ) . '/includes/class-formatting.php';

$passed = 0;
function t( $cond, $label ) {
	global $passed;
	if ( $cond ) { $passed++; return; }
	fwrite( STDERR, "FAIL: $label\n" );
	exit( 1 );
}

// Opacity: a whole percentage from 50 to 100.
foreach ( array( '50' => '50', '75' => '75', '100' => '100', ' 90 ' => '90', 80 => '80' ) as $in => $expected ) {
	t( faz_sanitize_opacity_percent( $in ) === $expected, "opacity accepts $in" );
}
foreach ( array(
	'49', '0', '101', '-60', '75.5', '75%', '0.8', 'abc', '',
	'80;}body{display:none}', '80);}html{opacity:0', '1000',
) as $bad ) {
	t( '' === faz_sanitize_opacity_percent( $bad ), 'opacity rejects ' . var_export( $bad, true ) );
}
t( '' === faz_sanitize_opacity_percent( array( '80' ) ), 'opacity rejects a non-scalar' );

// Alignment: an allow-list.
foreach ( array( 'left' => 'left', 'center' => 'center', 'right' => 'right', ' Center ' => 'center' ) as $in => $expected ) {
	t( faz_sanitize_text_align( $in ) === $expected, "align accepts $in" );
}
foreach ( array( 'justify', 'start', 'centre', 'left;}body{display:none}', '', null, array( 'left' ) ) as $bad ) {
	t( '' === faz_sanitize_text_align( $bad ), 'align rejects ' . var_export( $bad, true ) );
}

// Only the background colour becomes translucent.
t( 'rgba(255,255,255,0.8)' === faz_color_with_opacity( '#FFFFFF', '80' ), 'hex6 to rgba' );
t( 'rgba(17,34,51,0.5)' === faz_color_with_opacity( '#123', '50' ), 'hex3 to rgba' );
t( 'rgba(10,20,30,0.45)' === faz_color_with_opacity( 'rgba(10,20,30,0.5)', '90' ), 'rgba alpha is scaled' );
t( '#FFFFFF' === faz_color_with_opacity( '#FFFFFF', '' ), 'empty keeps the colour' );
t( '#FFFFFF' === faz_color_with_opacity( '#FFFFFF', '100' ), '100 keeps the colour' );
t( 'transparent' === faz_color_with_opacity( 'transparent', '80' ), 'a keyword has no alpha to set' );
// The result goes back through the colour sanitiser in class-template.php.
t( faz_color_with_opacity( '#FFFFFF', '80' ) === faz_sanitize_color( faz_color_with_opacity( '#FFFFFF', '80' ) ), 'result survives faz_sanitize_color' );

// The keys reach the strict sanitisers, not faz_sanitize_text(), and are
// declared in the shipped defaults — otherwise sanitize_settings() drops them
// on the way to the database.
$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-store.php';
require_once $root . '/admin/modules/banners/includes/class-banner.php';
$banner = 'FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner';

t( '70' === $banner::sanitize_option( 'noticeOpacity', '70' ), 'sanitize_option(noticeOpacity, 70)' );
t( '' === $banner::sanitize_option( 'noticeOpacity', '70;}a{b:c' ), 'sanitize_option rejects an injected opacity' );
t( 'center' === $banner::sanitize_option( 'textAlign', 'center' ), 'sanitize_option(textAlign, center)' );
t( '' === $banner::sanitize_option( 'textAlign', 'center;}a{b:c' ), 'sanitize_option rejects an injected alignment' );

foreach ( array( 'gdpr', 'ccpa' ) as $law ) {
	$defaults = json_decode( file_get_contents( $root . "/admin/modules/banners/includes/configs/$law.json" ), true );
	t( is_array( $defaults ), "$law.json parses" );
	t( '' === $defaults['settings']['noticeOpacity'], "$law: opacity defaults to empty" );
	t( '' === $defaults['settings']['textAlign'], "$law: alignment defaults to empty" );

	$kept = $banner::sanitize_settings(
		array( $banner, 'sanitize_option' ),
		array( 'settings' => array( 'noticeOpacity' => '85', 'textAlign' => 'right' ) ) + $defaults,
		$defaults
	);
	t( '85' === $kept['settings']['noticeOpacity'], "$law: opacity survives the round-trip" );
	t( 'right' === $kept['settings']['textAlign'], "$law: alignment survives the round-trip" );

	$rejected = $banner::sanitize_settings(
		array( $banner, 'sanitize_option' ),
		array( 'settings' => array( 'noticeOpacity' => '85;}html{display:none}', 'textAlign' => 'x;}html{display:none}' ) ) + $defaults,
		$defaults
	);
	t( '' === $rejected['settings']['noticeOpacity'], "$law: injected opacity round-trips to empty" );
	t( '' === $rejected['settings']['textAlign'], "$law: injected alignment round-trips to empty" );
}

echo "$passed passed: banner opacity and text alignment shape enforcement, colour conversion, settings round-trip\n";
