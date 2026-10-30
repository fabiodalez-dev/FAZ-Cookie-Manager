<?php
/**
 * Notice button order (#367): whitelist, settings round-trip, and the markup
 * rewrite on every shipped template.
 *
 * Only orders that keep Accept All and Reject All adjacent are allowed —
 * position is part of the equal weight EDPB 03/2022 asks for — so the two
 * orders that separate them must fall back to the default rather than pass
 * through as text.
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

$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-store.php';
require_once $root . '/admin/modules/banners/includes/class-banner.php';
$banner = 'FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner';

// The allowed set: exactly the four orders with Accept and Reject adjacent.
t( 4 === count( $banner::BUTTON_ORDERS ), 'four orders are offered' );
t( 'settings-reject-accept' === $banner::BUTTON_ORDERS[0], 'the default is the order every template ships with' );
foreach ( $banner::BUTTON_ORDERS as $order ) {
	$parts = explode( '-', $order );
	sort( $parts );
	t( array( 'accept', 'reject', 'settings' ) === $parts, "$order names each button once" );
	$pos = array_flip( explode( '-', $order ) );
	t( 1 === abs( $pos['accept'] - $pos['reject'] ), "$order keeps Accept and Reject adjacent" );
	t( $order === $banner::sanitize_button_order( $order ), "$order is accepted" );
}

// Everything else resolves to the default.
foreach ( array(
	'accept-settings-reject', // Accept first, Reject last behind Customise
	'reject-settings-accept', // the same, mirrored
	'accept-reject',
	'accept-reject-settings-donotsell',
	'settings,reject,accept',
	'<script>',
	'',
) as $bad ) {
	t( 'settings-reject-accept' === $banner::sanitize_button_order( $bad ), 'rejects ' . var_export( $bad, true ) );
}
t( 'settings-reject-accept' === $banner::sanitize_button_order( array( 'accept-reject-settings' ) ), 'rejects a non-string' );
t( 'settings-reject-accept' === $banner::sanitize_button_order( null ), 'rejects null' );
t( 'accept-reject-settings' === $banner::sanitize_button_order( ' Accept-Reject-Settings ' ), 'normalises case and whitespace' );

// The key reaches the whitelist, not the switch's faz_sanitize_text() default.
t( 'settings-reject-accept' === $banner::sanitize_option( 'buttonOrder', 'accept-settings-reject' ), 'sanitize_option routes buttonOrder to the whitelist' );
t( 'reject-accept-settings' === $banner::sanitize_option( 'buttonOrder', 'reject-accept-settings' ), 'sanitize_option keeps an allowed order' );

// Settings round-trip against every shipped config: the key must be declared
// in the defaults or sanitize_settings() drops it on the way to the database.
foreach ( array( '', '6.0.0/', '6.2.0/' ) as $dir ) {
	foreach ( array( 'gdpr', 'ccpa' ) as $law ) {
		$file     = "configs/{$dir}{$law}.json";
		$defaults = json_decode( file_get_contents( $root . '/admin/modules/banners/includes/' . $file ), true );
		t( is_array( $defaults ), "$file parses" );
		t( 'settings-reject-accept' === $defaults['settings']['buttonOrder'], "$file defaults to the shipped order" );

		$kept = $banner::sanitize_settings(
			array( $banner, 'sanitize_option' ),
			array( 'settings' => array( 'buttonOrder' => 'accept-reject-settings' ) ) + $defaults,
			$defaults
		);
		t( 'accept-reject-settings' === $kept['settings']['buttonOrder'], "$file: an allowed order survives the round-trip" );

		$rejected = $banner::sanitize_settings(
			array( $banner, 'sanitize_option' ),
			array( 'settings' => array( 'buttonOrder' => 'accept-settings-reject' ) ) + $defaults,
			$defaults
		);
		t( 'settings-reject-accept' === $rejected['settings']['buttonOrder'], "$file: a separating order round-trips to the default" );
	}
}

// The rewrite on every shipped template, read from the real template file.
$templates = json_decode( file_get_contents( $root . '/admin/modules/banners/includes/templates/6.2.0/template.json' ), true );
t( is_array( $templates ) && 6 === count( $templates ), 'six shipped templates' );
$wrapper_shortcodes = function ( $html ) {
	t( 1 === preg_match( '/data-faz-tag="notice-buttons">(.*?)<\/div>/s', $html, $m ), 'template has a notice-buttons wrapper' );
	preg_match_all( '/\[faz_(settings|reject|accept|donot_sell)\]/', $m[1], $all );
	return $all[1];
};
foreach ( $templates as $tpl ) {
	$type = $tpl['type'];
	$html = $tpl['html'];
	t( $html === $banner::apply_button_order( $html, 'settings-reject-accept' ), "$type: the default leaves the template byte-for-byte unchanged" );
	t( $html === $banner::apply_button_order( $html, 'accept-settings-reject' ), "$type: a disallowed order leaves it unchanged" );
	$shipped = $wrapper_shortcodes( $html );
	foreach ( $banner::BUTTON_ORDERS as $order ) {
		$out = $banner::apply_button_order( $html, $order );
		$got = $wrapper_shortcodes( $out );
		$want = explode( '-', $order );
		t( $want === array_slice( $got, 0, 3 ), "$type: $order renders in that order" );
		t( array_slice( $shipped, 3 ) === array_slice( $got, 3 ), "$type: $order leaves Do Not Sell where it was" );
		t( strlen( $html ) === strlen( $out ), "$type: $order only moves shortcodes" );
		t( 1 === substr_count( $out, '[faz_accept]' ) && 1 === substr_count( $out, '[faz_reject]' ) && 1 === substr_count( $out, '[faz_settings]' ), "$type: $order keeps one of each" );
	}
}

// A template without the shipped run (a custom one) is returned as it was.
$custom = '<div data-faz-tag="notice-buttons">[faz_accept][faz_reject]</div>';
t( $custom === $banner::apply_button_order( $custom, 'reject-accept-settings' ), 'a custom template without the run is untouched' );

echo "$passed passed: button-order whitelist, settings round-trip, and markup rewrite on every template\n";
