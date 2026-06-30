<?php
/**
 * Standalone unit tests for the banner template cache fingerprint /
 * persistence in FazCookie\Admin\Modules\Banners\Includes\Template.
 *
 * Covers:
 *   - get_layout_signature() invalidation: the md5 fingerprint MUST change
 *     when FAZ_VERSION changes, when banner_control.per_service_consent /
 *     per_cookie_consent toggle, and when type / ptype / theme / law change;
 *   - load() regenerates (calls generate()) only when the stored signature is
 *     absent or differs, and re-uses the cache (set_template()) when it matches;
 *   - update() persists the *current* signature so the next load() is a hit;
 *   - edge cases: legacy stored entry missing the 'layout_signature' key forces
 *     regeneration; identical inputs yield an identical signature; flipping a
 *     single per_service flag flips the signature; empty/missing properties
 *     still produce a stable signature.
 *
 * get_layout_signature() / get_stored() / set_template() are private/protected,
 * so they are exercised through public load()/update() and via reflection where
 * a direct read of the fingerprint is needed. Heavy generate() DOM work is
 * neutralised by subclassing Template (see Faz_Test_Template) so the cache
 * branch logic can be asserted deterministically with no browser/DOM.
 *
 * FAZ_VERSION is a constant (permanent for the process), so the version-change
 * case captures the signature under the real constant and compares it against a
 * signature computed with a different injected version via reflection on the
 * private builder rather than redefining the constant.
 *
 * Run from project root:
 *   php tests/unit/test-template-cache-php.php
 *
 * Exit 0 = all pass; 1 = at least one failure. Lightweight CLI runner,
 * mirrors test-geolocation-db-path.php.
 *
 * @package FazCookie\Tests\Unit
 */

// ---------- Bootstrap ----------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'FAZ_VERSION' ) ) {
	define( 'FAZ_VERSION', '9.9.9-test' );
}

// In-memory wp_options store the stubbed get/update_option operate on.
$GLOBALS['faz_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) { // phpcs:ignore
		return array_key_exists( $name, $GLOBALS['faz_test_options'] )
			? $GLOBALS['faz_test_options'][ $name ]
			: $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) { // phpcs:ignore
		$GLOBALS['faz_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { // phpcs:ignore
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() { // phpcs:ignore
		return true;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { // phpcs:ignore
		return is_string( $s ) ? trim( $s ) : '';
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) { // phpcs:ignore
		return abs( (int) $n );
	}
}
if ( ! function_exists( 'faz_current_language' ) ) {
	function faz_current_language() { // phpcs:ignore
		return 'en';
	}
}
if ( ! function_exists( 'faz_allowed_html' ) ) {
	function faz_allowed_html() { // phpcs:ignore
		return array();
	}
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $string, $allowed = array() ) { // phpcs:ignore
		return (string) $string;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) { // phpcs:ignore
		return wp_json_encode_compat( $data );
	}
	function wp_json_encode_compat( $data ) { // phpcs:ignore
		return json_encode( $data );
	}
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-template.php';

use FazCookie\Admin\Modules\Banners\Includes\Template;

/**
 * Test double: a Template whose generate() / set_template() are observable and
 * side-effect-free. The real generate() builds a DOMDocument from template JSON
 * and shortcodes — irrelevant to the cache-decision logic under test and not
 * reproducible without a full WP runtime. We only need to know *which* branch
 * load() took.
 */
class Faz_Test_Template extends Template {
	public $generated  = 0;
	public $set_called = 0;

	/** Inject banner/properties/language without running the heavy constructor. */
	public function faz_setup( $banner, array $properties, $language = 'en' ) {
		$this->banner     = $banner;
		$this->properties = $properties;
		$this->language   = $language;
	}

	public function generate() {
		++$this->generated;
		// Pretend generate() produced markup so update() has something to store.
		$this->html   = '<div class="faz-consent-bar">x</div>';
		$this->styles = '.faz-consent-bar{color:#000}';
	}

	public function set_template() {
		++$this->set_called;
		parent::set_template();
	}

	/** Expose the private fingerprint for direct comparison in assertions. */
	public function faz_signature() {
		$ref = new ReflectionMethod( Template::class, 'get_layout_signature' );
		$ref->setAccessible( true );
		return $ref->invoke( $this );
	}
}

/** Minimal banner double: only the methods get_layout_signature() touches. */
class Faz_Test_Banner {
	private $id;
	private $desc;
	public function __construct( $id = 1, $desc = 'We value your privacy' ) {
		$this->id   = $id;
		$this->desc = $desc;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_notice_description( $language = 'en' ) {
		return $this->desc;
	}
}

// ---------- Assertion harness ----------

$faz_pass = 0;
$faz_fail = 0;
function faz_ok( $cond, $label ) {
	global $faz_pass, $faz_fail;
	if ( $cond ) {
		++$faz_pass;
		echo "  [PASS] $label\n";
	} else {
		++$faz_fail;
		echo "  [FAIL] $label\n";
	}
}

/** Fresh Template double seeded with the given settings/config + banner_control. */
function faz_make_template( array $settings, array $config = array(), array $banner_control = array(), $banner = null ) {
	$GLOBALS['faz_test_options']['faz_settings'] = array( 'banner_control' => $banner_control );
	$tpl                                         = new Faz_Test_Template();
	$tpl->faz_setup(
		$banner ?: new Faz_Test_Banner(),
		array(
			'settings' => $settings,
			'config'   => $config,
		)
	);
	return $tpl;
}

$base_settings = array(
	'versionID'            => 'v1',
	'type'                 => 'box',
	'preferenceCenterType' => 'popup',
	'theme'                => 'light',
	'applicableLaw'        => 'gdpr',
);

echo "Template cache: get_layout_signature() / load() / update()  (15 assertions)\n";

// 1: identical inputs -> identical signature (deterministic md5).
$a = faz_make_template( $base_settings );
$b = faz_make_template( $base_settings );
$sig_base = $a->faz_signature();
faz_ok( $sig_base === $b->faz_signature() && 32 === strlen( $sig_base ), '01 identical inputs -> identical 32-char signature' );

// 2: changing type flips the signature.
$t = faz_make_template( array_merge( $base_settings, array( 'type' => 'banner' ) ) );
faz_ok( $sig_base !== $t->faz_signature(), '02 type box->banner flips signature' );

// 3: changing ptype flips the signature.
$t = faz_make_template( array_merge( $base_settings, array( 'preferenceCenterType' => 'sidebar' ) ) );
faz_ok( $sig_base !== $t->faz_signature(), '03 ptype popup->sidebar flips signature' );

// 4: changing theme flips the signature.
$t = faz_make_template( array_merge( $base_settings, array( 'theme' => 'dark' ) ) );
faz_ok( $sig_base !== $t->faz_signature(), '04 theme light->dark flips signature' );

// 5: changing law flips the signature.
$t = faz_make_template( array_merge( $base_settings, array( 'applicableLaw' => 'ccpa' ) ) );
faz_ok( $sig_base !== $t->faz_signature(), '05 law gdpr->ccpa flips signature' );

// 6: toggling per_service_consent ON flips the signature (single-flag flip).
$on_service = faz_make_template( $base_settings, array(), array( 'per_service_consent' => true ) );
faz_ok( $sig_base !== $on_service->faz_signature(), '06 per_service_consent OFF->ON flips signature' );

// 7: toggling per_cookie_consent ON flips the signature.
$on_cookie = faz_make_template( $base_settings, array(), array( 'per_cookie_consent' => true ) );
faz_ok( $sig_base !== $on_cookie->faz_signature(), '07 per_cookie_consent OFF->ON flips signature' );

// 8: per_service and per_cookie are independent dimensions -> distinct sigs.
//     NOTE: get_layout_signature() reads banner_control from the *live*
//     faz_settings option (not instance state), so each fingerprint must be
//     captured while its own option value is in place.
$sig_service_only = faz_make_template( $base_settings, array(), array( 'per_service_consent' => true ) )->faz_signature();
$sig_cookie_only  = faz_make_template( $base_settings, array(), array( 'per_cookie_consent' => true ) )->faz_signature();
faz_ok( $sig_service_only !== $sig_cookie_only, '08 per_service vs per_cookie produce distinct signatures' );

// 9: per_service truthiness uses ! empty() -> '0'/'' are OFF, '1'/true are ON.
$service_zero = faz_make_template( $base_settings, array(), array( 'per_service_consent' => 0 ) );
faz_ok( $sig_base === $service_zero->faz_signature(), '09 per_service_consent=0 is treated as OFF (== base)' );

// 10: FAZ_VERSION participates in the fingerprint — a different plugin_version
//     yields a different md5 (proven by recomputing the same array with a bumped
//     version, mirroring the builder exactly).
$desc_md5 = md5( 'We value your privacy' );
// Mirror the resolved build locale that get_layout_signature() now folds in
// (#164). Read it from the real resolver so the mirror matches whatever the
// environment yields (empty here: no faz_wp_locale stub) without hardcoding.
function faz_build_locale( $tpl ) {
	$m = new ReflectionMethod( Template::class, 'resolve_build_locale' );
	$m->setAccessible( true );
	return $m->invoke( $tpl );
}
function faz_sig_for_version( $version, $base_settings, $desc_md5, $build_locale ) {
	return md5(
		wp_json_encode(
			array(
				'plugin_version' => $version,
				'version'        => $base_settings['versionID'],
				'type'           => $base_settings['type'],
				'ptype'          => $base_settings['preferenceCenterType'],
				'theme'          => $base_settings['theme'],
				'law'            => $base_settings['applicableLaw'],
				'do_not_sell'    => false,
				'optout_popup'   => false,
				'per_service'    => false,
				'per_cookie'     => false,
				'description'    => $desc_md5,
				'build_locale'   => $build_locale,
			)
		)
	);
}
$base_build_locale = faz_build_locale( $a );
$sig_v1 = faz_sig_for_version( FAZ_VERSION, $base_settings, $desc_md5, $base_build_locale );
$sig_v2 = faz_sig_for_version( '0.0.1-other', $base_settings, $desc_md5, $base_build_locale );
faz_ok( $sig_v1 === $sig_base, '10a recomputed builder matches real signature (version field present)' );
faz_ok( $sig_v1 !== $sig_v2, '10b changing plugin_version changes the signature' );

// 11: load() with an empty cache -> generate() runs (no stored template).
$GLOBALS['faz_test_options']['faz_banner_template'] = array();
$ld = faz_make_template( $base_settings );
$ld->load();
faz_ok( 1 === $ld->generated && 0 === $ld->set_called, '11 empty cache -> load() regenerates' );

// 12: update() persists the current signature; a subsequent load() is a cache
//     HIT -> set_template(), not generate().
$ld->update();
$hit = faz_make_template( $base_settings );
$hit->load();
faz_ok( 0 === $hit->generated && 1 === $hit->set_called, '12 matching stored signature -> load() reuses cache (set_template)' );

// 13: legacy stored entry missing 'layout_signature' -> load() regenerates.
$GLOBALS['faz_test_options']['faz_banner_template'] = array(
	'banner_1:en' => array(
		'html'   => '<div>legacy</div>',
		'styles' => '.legacy{}',
		// no 'layout_signature' key — pre-fingerprint cache format.
	),
);
$legacy = faz_make_template( $base_settings );
$legacy->load();
faz_ok( 1 === $legacy->generated && 0 === $legacy->set_called, '13 legacy stored entry (no layout_signature) -> regenerates' );

// 14: stored signature that DIFFERS from current inputs -> regenerate. Persist a
//     cache for the base layout, then toggle per_service ON: signature differs.
$GLOBALS['faz_test_options']['faz_banner_template'] = array();
$seed = faz_make_template( $base_settings );
$seed->load();
$seed->update(); // stored under base signature
// Now the same banner_1:en slot is requested but per_service_consent is ON.
$GLOBALS['faz_test_options']['faz_settings'] = array( 'banner_control' => array( 'per_service_consent' => true ) );
$changed = new Faz_Test_Template();
$changed->faz_setup( new Faz_Test_Banner(), array( 'settings' => $base_settings, 'config' => array() ) );
$changed->load();
faz_ok( 1 === $changed->generated && 0 === $changed->set_called, '14 stored signature stale (per_service flipped) -> load() regenerates' );

// 15: update() then immediate re-load after the SAME toggle is a hit again
//     (round-trip: stale -> regenerate -> persist -> hit).
$changed->update();
$rehit = new Faz_Test_Template();
$rehit->faz_setup( new Faz_Test_Banner(), array( 'settings' => $base_settings, 'config' => array() ) );
$rehit->load();
faz_ok( 0 === $rehit->generated && 1 === $rehit->set_called, '15 after update() the flipped layout is cached -> next load() is a hit' );

// ---------- Result ----------
echo "\n" . ( 0 === $faz_fail ? "ALL PASS ($faz_pass)\n" : "FAILED: $faz_fail, passed: $faz_pass\n" );
exit( 0 === $faz_fail ? 0 : 1 );
