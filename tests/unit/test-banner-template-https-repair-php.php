<?php
/**
 * The banner template auto-repair writes https:// plugin URLs back.
 *
 * On an https request with a server-verified signal (is_ssl() or an https
 * siteurl), Frontend::banner_html() rewrites the http:// plugin URLs of the
 * cached template to https:// and saves the result to wp_options for every
 * later visitor. A refactor that moved the per-request rewrite into
 * https_plugin_urls_for_request() left the repair reading an undefined
 * $https_url: str_replace() with null deleted the plugin URL prefix and
 * saved relative icon URLs that 404.
 *
 * Runs the real banner_html() up to the repair's update_option(), which the
 * stub turns into a stop, and checks what would have been saved.
 *
 * Run: php tests/unit/test-banner-template-https-repair-php.php
 */

define( 'WPINC', 'wp-includes' );

const FAZ_REPAIR_PLUGIN_HTTP = 'http://origin.example.test/wp-content/plugins/faz-cookie-manager/';

$GLOBALS['faz_repair_options'] = array();

function plugin_basename( $file ) { return basename( $file ); }
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/\\' ) . '/'; }
function plugin_dir_url( $file ) { return FAZ_REPAIR_PLUGIN_HTTP; }
function get_option( $name, $default = false ) {
	if ( 'WPLANG' === $name ) { return 'en_US'; }
	if ( 'siteurl' === $name ) { return 'http://origin.example.test'; }
	return array_key_exists( $name, $GLOBALS['faz_repair_options'] ) ? $GLOBALS['faz_repair_options'][ $name ] : $default;
}
// The server itself says https: the durable repair runs on this signal only.
function is_ssl() { return true; }
function wp_unslash( $value ) { return $value; }
function set_url_scheme( $url, $scheme = null ) {
	if ( null === $scheme ) { $scheme = is_ssl() ? 'https' : 'http'; }
	return preg_replace( '#^https?://#i', $scheme . '://', $url );
}
function add_filter() { return true; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function faz_disable_banner() { return false; }

// Banner template cache generation (class-i18n-helpers.php): the request saw
// 'g1'; the stored value is what a fresh read returns.
$GLOBALS['faz_repair_generation'] = array( 'request' => 'g1', 'stored' => 'g1' );
function faz_banner_template_generation( $fresh = false ) {
	return $GLOBALS['faz_repair_generation'][ $fresh ? 'stored' : 'request' ];
}
function faz_banner_template_generation_is_current( $generation ) {
	return (string) $generation === faz_banner_template_generation( true );
}

// Reached once the repair has decided not to write (the second case below).
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function faz_allowed_html() { return array(); }
function wp_kses( $html, $allowed = array() ) { return (string) $html; }

class Faz_Repair_Stop extends RuntimeException {}
function add_action() { throw new Faz_Repair_Stop(); }
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['faz_repair_saved'] = array( $name, $value );
	throw new Faz_Repair_Stop();
}

try {
	require dirname( __DIR__, 2 ) . '/faz-cookie-manager.php';
} catch ( Faz_Repair_Stop $e ) {
	// Expected: constants and helpers are loaded, the rest of WordPress is not.
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

$passed = 0;
$failed = 0;
function repair_check( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  [PASS] $label\n";
	} else {
		$failed++;
		echo "  [FAIL] $label\n";
	}
}

echo "\n== Banner template https auto-repair ==\n\n";

$icon = '<img src="' . FAZ_REPAIR_PLUGIN_HTTP . 'frontend/images/revisit.svg">';
$GLOBALS['faz_repair_options']['faz_banner_template'] = array( 'en' => array( 'html' => $icon ) );

$frontend = new class() extends \FazCookie\Frontend\Frontend {
	public function __construct() {
		$this->template = array( 'html' => '<div class="faz-consent-container">' . $GLOBALS['faz_repair_options']['faz_banner_template']['en']['html'] . '</div>' );
	}
	protected function is_banner_ui_suppressed() {
		return false;
	}
};

repair_check( faz_request_is_https(), 'the request is https by a server-verified signal' );

ob_start();
try {
	$frontend->banner_html();
} catch ( Faz_Repair_Stop $e ) {
	// The repair reached update_option().
}
ob_end_clean();

$saved = isset( $GLOBALS['faz_repair_saved'] ) ? $GLOBALS['faz_repair_saved'] : null;
repair_check( is_array( $saved ) && 'faz_banner_template' === $saved[0], 'the cached template is written back' );
$saved_html = is_array( $saved ) && isset( $saved[1]['en']['html'] ) ? (string) $saved[1]['en']['html'] : '';
repair_check(
	false !== strpos( $saved_html, 'src="https://origin.example.test/wp-content/plugins/faz-cookie-manager/frontend/images/revisit.svg"' ),
	'the saved icon URL is the https:// plugin URL'
);
repair_check(
	false === strpos( $saved_html, 'src="frontend/' ),
	'the plugin URL prefix is not deleted'
);

// An admin save cleared the cache after this request read it: the repair of
// its pre-save copy must not be written back over the new generation.
unset( $GLOBALS['faz_repair_saved'] );
$GLOBALS['faz_repair_generation']['stored'] = 'g2';
ob_start();
try {
	$frontend->banner_html();
} catch ( Faz_Repair_Stop $e ) {
	// Reached update_option(): the stale copy was written.
}
$rendered = ob_get_clean();
repair_check( ! isset( $GLOBALS['faz_repair_saved'] ), 'a repair read before a cache clear is not written back' );
repair_check( false !== strpos( $rendered, 'https://origin.example.test/wp-content/plugins/faz-cookie-manager/frontend/images/revisit.svg' ), 'the request itself still gets the https:// icon URL' );

echo "\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
