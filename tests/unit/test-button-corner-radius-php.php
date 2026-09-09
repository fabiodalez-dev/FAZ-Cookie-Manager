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

// Wiring: the banner sanitiser must route this key to the strict check, not to
// the switch's faz_sanitize_text() default, which would let `;` through.
$banner = file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-banner.php' );
t( false !== strpos( $banner, "case 'borderRadius':" ), 'sanitize_option has a borderRadius case' );
$case_at = strpos( $banner, "case 'borderRadius':" );
$next_break = strpos( $banner, 'break;', $case_at );
t(
	false !== strpos( substr( $banner, $case_at, $next_break - $case_at ), 'faz_sanitize_css_length' ),
	'the borderRadius case calls faz_sanitize_css_length'
);

// Wiring: the key must exist in the shipped defaults, or sanitize_settings()
// drops it before it is ever stored (it iterates the defaults, not the input).
foreach ( array( 'gdpr', 'ccpa' ) as $law ) {
	$cfg = json_decode( file_get_contents( dirname( __DIR__, 2 ) . "/admin/modules/banners/includes/configs/$law.json" ), true );
	t( is_array( $cfg ) , "$law.json parses" );
	t( array_key_exists( 'borderRadius', $cfg['settings'] ), "$law.json declares settings.borderRadius" );
	t( '' === $cfg['settings']['borderRadius'], "$law.json defaults it to empty (existing banners unchanged)" );
}

// Wiring: the template emits the variable only when the setting is non-empty.
$template = file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-template.php' );
t( false !== strpos( $template, "--faz-btn-border-radius" ), 'the template emits --faz-btn-border-radius' );
t( false !== strpos( $template, "if ( '' !== \$radius ) {" ), 'and only when the setting is non-empty' );

echo "$passed passed: corner-radius shape enforcement, CSS-injection rejection, and wiring\n";
