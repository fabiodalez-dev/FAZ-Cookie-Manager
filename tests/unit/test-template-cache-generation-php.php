<?php
/**
 * The banner template cache rejects a write from before the last clear.
 *
 * Template::update() and the two repairs in Frontend::banner_html() rewrite
 * the whole faz_banner_template option from the copy their request read. An
 * anonymous render that read the banner before an admin save could write the
 * pre-save template back just after faz_clear_banner_template_cache(), and the
 * layout signature (which leaves out colours, button texts, category names)
 * would then serve it to everyone until the next clear.
 *
 * Every clear now starts a new cache generation; a slot records the
 * generation it was built under, is served only while that is current, and a
 * write whose generation is no longer current is skipped.
 *
 * Runs the real faz_clear_banner_template_cache() and Template::load()/update()
 * on an in-memory options table; generate() is a stub (no DOM work).
 *
 * Run: php tests/unit/test-template-cache-generation-php.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'FAZ_VERSION', '9.9.9-test' );

$GLOBALS['faz_gen_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['faz_gen_options'] ) ? $GLOBALS['faz_gen_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['faz_gen_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['faz_gen_options'][ $name ] );
	return true;
}
function apply_filters( $tag, $value ) { return $value; }
function add_action() { return true; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function faz_current_language() { return 'en'; }
function faz_allowed_html() { return array(); }
function wp_kses( $s, $allowed = array() ) { return (string) $s; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function get_locale() { return 'en_US'; }

/** $wpdb double: a fresh read goes to the same in-memory table. */
class Faz_Gen_Wpdb {
	public $options = 'wp_options';
	public $fresh_reads = 0;
	public function esc_like( $s ) { return $s; }
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_var( $prepared ) {
		++$this->fresh_reads;
		$name = $prepared[1][0];
		return array_key_exists( $name, $GLOBALS['faz_gen_options'] ) ? (string) $GLOBALS['faz_gen_options'][ $name ] : null;
	}
	public function get_col( $prepared ) { return array(); }
}
$GLOBALS['wpdb'] = new Faz_Gen_Wpdb();

require_once dirname( __DIR__, 2 ) . '/includes/class-i18n-helpers.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-store.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-banner.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-template.php';
require_once dirname( __DIR__, 2 ) . '/frontend/modules/shortcodes/class-shortcodes.php';

use FazCookie\Admin\Modules\Banners\Includes\Template;

class Faz_Gen_Banner {
	public function get_id() { return 1; }
	public function get_notice_description( $language = 'en' ) { return 'We value your privacy'; }
}

/** Template whose generate() records the call and runs $during before storing. */
class Faz_Gen_Template extends Template {
	public $generated = 0;
	public $served    = 0;
	public $during    = null;
	public $markup    = 'fresh';
	public function faz_setup() {
		$this->banner     = new Faz_Gen_Banner();
		$this->language   = 'en';
		$this->properties = array(
			'settings' => array( 'versionID' => 'v1', 'type' => 'box', 'preferenceCenterType' => 'popup', 'theme' => 'light', 'applicableLaw' => 'gdpr' ),
			'config'   => array(),
		);
		return $this;
	}
	public function generate() {
		++$this->generated;
		$this->html   = '<div>' . $this->markup . '</div>';
		$this->styles = '';
		if ( is_callable( $this->during ) ) {
			call_user_func( $this->during );
		}
		$this->update();
	}
	public function set_template() {
		++$this->served;
		parent::set_template();
	}
}
function faz_gen_template( $markup = 'fresh', $during = null ) {
	$t         = ( new Faz_Gen_Template() )->faz_setup();
	$t->markup = $markup;
	$t->during = $during;
	return $t;
}
function faz_gen_slot() {
	$row = get_option( 'faz_banner_template', array() );
	return is_array( $row ) && isset( $row['banner_1:en'] ) ? $row['banner_1:en'] : null;
}

$pass = 0;
$fail = 0;
function gen_ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { ++$pass; echo "  [PASS] $label\n"; } else { ++$fail; echo "  [FAIL] $label\n"; }
}

echo "\n== Banner template cache generation ==\n\n";

// 1. A slot stored before the generation existed is still served.
$GLOBALS['faz_banner_template_seed'] = faz_gen_template();
$sig = ( function () {
	$m = new ReflectionMethod( Template::class, 'get_layout_signature' );
	$m->setAccessible( true );
	return $m->invoke( $GLOBALS['faz_banner_template_seed'] );
} )();
$GLOBALS['faz_gen_options']['faz_banner_template'] = array( 'banner_1:en' => array( 'html' => '<div>legacy</div>', 'styles' => '', 'layout_signature' => $sig ) );
$t = faz_gen_template();
$t->load();
gen_ok( 0 === $t->generated && 1 === $t->served && '<div>legacy</div>' === $t->get_html(), '01 a slot saved before the generation existed is served from cache' );

// 2. The clear starts a new generation.
faz_clear_banner_template_cache();
$gen = get_option( FAZ_TEMPLATE_GENERATION_OPTION, '' );
gen_ok( is_string( $gen ) && 16 === strlen( $gen ), '02 the clear writes a new generation token' );
gen_ok( false === get_option( 'faz_banner_template', false ), '02b the clear deletes the template row' );

// 3. A render that started before an admin clear does not write its template back.
$stale = faz_gen_template( 'pre-save', function () { faz_clear_banner_template_cache(); } );
$stale->load();
gen_ok( 1 === $stale->generated, '03 the racing render generated' );
gen_ok( null === faz_gen_slot(), '03b its pre-save template is not written after the clear' );

// 4. A late write that landed anyway (whole-row copy) is not served.
$GLOBALS['faz_gen_options']['faz_banner_template'] = array( 'banner_1:en' => array( 'html' => '<div>pre-save</div>', 'styles' => '', 'layout_signature' => $sig, 'generation' => 'older-generation' ) );
$t = faz_gen_template( 'post-save' );
$t->load();
gen_ok( 1 === $t->generated && 0 === $t->served, '04 a slot from an older generation is regenerated, not served' );
$slot = faz_gen_slot();
gen_ok( is_array( $slot ) && '<div>post-save</div>' === $slot['html'], '04b the regenerated template replaces it' );
gen_ok( is_array( $slot ) && get_option( FAZ_TEMPLATE_GENERATION_OPTION ) === $slot['generation'], '04c and carries the current generation' );

// 5. Within one generation the cache is hit as before.
$t = faz_gen_template( 'other' );
$t->load();
gen_ok( 0 === $t->generated && 1 === $t->served && '<div>post-save</div>' === $t->get_html(), '05 a current slot is served from cache' );

echo "\nPassed: $pass\nFailed: $fail\n";
exit( $fail > 0 ? 1 : 0 );
