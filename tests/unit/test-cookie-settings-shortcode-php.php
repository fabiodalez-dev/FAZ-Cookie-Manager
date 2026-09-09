<?php
/** Footer link rendering, compatibility, and escaping. */
define( 'ABSPATH', __DIR__ . '/' );
function add_shortcode( $tag, $callback ) { $GLOBALS['shortcodes'][$tag] = $callback; }
function shortcode_atts( $defaults, $atts, $tag ) { return array_merge( $defaults, array_intersect_key( $atts, $defaults ) ); }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function sanitize_html_class( $value ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', $value ); }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function __( $value, $domain ) { return $value; }
require_once dirname( __DIR__, 2 ) . '/includes/class-cookie-settings-shortcode.php';
$shortcode = new FazCookie\Includes\Cookie_Settings_Shortcode();
$cases = array(
 array( array(), '<button type="button" class="faz-cookie-settings-btn" data-faz-open-preferences="1" aria-haspopup="dialog">Manage consent preferences</button>' ),
 array( array( 'type' => 'link', 'text' => 'Cookie preferences', 'class' => 'footer-link custom' ), '<a href="#faz-consent" class="faz-cookie-settings-link footer-link custom" data-faz-open-preferences="1" aria-haspopup="dialog">Cookie preferences</a>' ),
 array( array( 'type' => 'invalid' ), '<button type="button" class="faz-cookie-settings-btn" data-faz-open-preferences="1" aria-haspopup="dialog">Manage consent preferences</button>' ),
 array( array( 'type' => 'link', 'text' => '<b>Cookies & privacy</b>', 'class' => 'footer"' ), '<a href="#faz-consent" class="faz-cookie-settings-link footer" data-faz-open-preferences="1" aria-haspopup="dialog">Cookies &amp; privacy</a>' ),
);
foreach ( $cases as $case ) {
 if ( $shortcode->render( $case[0] ) !== $case[1] ) { fwrite( STDERR, "FAIL: shortcode output\n" ); exit(1); }
}
echo "4 passed: default button, footer link, invalid type, escaping\n";
