<?php
/**
 * Button corner radius (issue #191): shape enforcement and wiring.
 *
 * The value reaches the page as a CSS custom-property value in
 * class-template.php, where esc_attr() does NOT strip `{`, `}` or `;`. The
 * shape check therefore has to happen on save, exactly as it already does for
 * colours — hence the injection cases below.
 */
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_text_field( $v ) { return (string) $v; }
function sanitize_hex_color( $v ) { return preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $v ) ? $v : ''; }
// The settings round-trip below walks EVERY default key, so the switch's other
// branches run too and need their WordPress helpers present.
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

// Accepted lengths.
foreach ( array(
	'0'       => '0',
	'8px'     => '8px',
	'50px'    => '50px',
	'0.5rem'  => '0.5rem',
	'2em'     => '2em',
	'100%'    => '100%',
	'  12px ' => '12px',
) as $in => $expected ) {
	t( faz_sanitize_css_length( $in ) === $expected, "accepts $in" );
}

// Rejected: anything that is not a bare non-negative length.
foreach ( array(
	'4px;}body{display:none}',      // the injection this guard exists for
	'2px;color:red',
	'}',
	'inherit',
	'red',
	'-4px',                          // negative radius is meaningless
	'12',                            // unitless, and not the bare zero
	'8 px',
	'calc(4px + 1em)',
	'expression(alert(1))',
	'url(x)',
	'8pxx',
	'',
) as $bad ) {
	t( '' === faz_sanitize_css_length( $bad ), 'rejects ' . var_export( $bad, true ) );
}
t( '' === faz_sanitize_css_length( array( '8px' ) ), 'rejects a non-scalar' );
t( '' === faz_sanitize_css_length( null ), 'rejects null' );

// Behaviour, not source text. The previous version asserted with strpos() that
// class-banner.php contained "case 'borderRadius':" and that class-template.php
// contained the variable name — which passes when the code is renamed into
// uselessness and fails when it is merely reformatted. These call the real
// code instead.
$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-store.php';
require_once $root . '/admin/modules/banners/includes/class-banner.php';
$banner = 'FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner';

// The key must reach the strict sanitiser rather than the switch's
// faz_sanitize_text() default, which would let `;` through into the CSS.
foreach ( array(
	'8px'                     => '8px',
	'0'                       => '0',
	'0.5rem'                  => '0.5rem',
	'50%'                     => '50%',
	'4px;}body{display:none}' => '',
	'2px;color:red'           => '',
	'red'                     => '',
	'-4px'                    => '',
	'12'                      => '',
	'calc(4px + 1em)'         => '',
) as $in => $expected ) {
	t(
		$banner::sanitize_option( 'borderRadius', $in ) === $expected,
		'sanitize_option(borderRadius, ' . var_export( $in, true ) . ') === ' . var_export( $expected, true )
	);
}

// A whole settings round-trip against the SHIPPED defaults. sanitize_settings()
// walks the defaults, so this also proves the key is declared in them — without
// it the value is dropped silently on the way to the database, and no amount of
// admin UI would help.
foreach ( array( 'gdpr', 'ccpa' ) as $law ) {
	$defaults = json_decode( file_get_contents( $root . "/admin/modules/banners/includes/configs/$law.json" ), true );
	t( is_array( $defaults ), "$law.json parses" );
	t( '' === $defaults['settings']['borderRadius'], "$law defaults to empty, so existing banners are unchanged" );

	$kept = $banner::sanitize_settings(
		array( $banner, 'sanitize_option' ),
		array( 'settings' => array( 'borderRadius' => '14px' ) ) + $defaults,
		$defaults
	);
	t( '14px' === $kept['settings']['borderRadius'], "$law: a valid radius survives the round-trip" );

	$rejected = $banner::sanitize_settings(
		array( $banner, 'sanitize_option' ),
		array( 'settings' => array( 'borderRadius' => '9px;}html{display:none}' ) ) + $defaults,
		$defaults
	);
	t( '' === $rejected['settings']['borderRadius'], "$law: an injection attempt round-trips to empty" );
}

// Empty is the contract the template relies on to emit nothing at all, so the
// rendered CSS keeps its shipped 2px. That the emitted declaration then reaches
// the button is covered end to end by the browser test in
// tests/e2e/specs/footer-link-radius-and-tracking.spec.ts, which reads the
// computed style rather than the source.

echo "$passed passed: corner-radius shape enforcement, CSS-injection rejection, and settings round-trip\n";
