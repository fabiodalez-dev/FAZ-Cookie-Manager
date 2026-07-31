<?php
/**
 * Unit tests for Document_Registry / Document_Config and the render_for()
 * extraction.
 *
 * WHY THIS EXISTS
 *
 * The golden-render suite proves the cookie policy still renders byte-for-byte
 * as it did. It cannot prove WHY: it only ever calls `Renderer::render()`, so a
 * registry that silently returned the wrong config, or a `render_for()` that
 * quietly ignored its `$doc` and kept reading the old constants, would still be
 * green. This suite pins the seam itself — the registry contents, the config
 * validation, and the equivalence of every "with $doc" / "without $doc" pair
 * that the refactor introduced.
 *
 * Same harness as test-cookie-policy-golden-render.php: WordPress is stubbed
 * deterministically and the classes under test are required directly, with no
 * autoloader and no plugin bootstrap. That is not just convenience — it is the
 * constraint that forces Document_Registry to build its configs lazily and to
 * name the shortcode as a literal, and this suite is what would catch a
 * regression on either point.
 *
 * Run:
 *   php tests/unit/test-legal-documents-registry.php
 *   bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'FAZ_VERSION' ) ) {
	define( 'FAZ_VERSION', '1.25.1-test' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// ---------------------------------------------------------------------------
// Deterministic environment.
// ---------------------------------------------------------------------------
$GLOBALS['faz_test_state'] = array(
	'now'           => '2026-06-03 09:41:00',
	'locale'        => 'en_US',
	'home'          => 'https://example.test',
	'options'       => array(),
	'privacy_url'   => '',
	'is_admin_user' => true,
);
$_SERVER['REQUEST_URI'] = '/cookie-policy/';

// ---------------------------------------------------------------------------
// WordPress stubs.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) {
		$u = (string) $u;
		$u = str_replace( array( '"', "'", '<', '>' ), '', $u );
		return trim( $u );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = 'default' ) { return (string) $t; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = 'default' ) { return esc_html( (string) $t ); }
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context = '', $domain = 'default' ) { return (string) $text; }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $v ) { return (string) $v; }
}
if ( ! function_exists( 'wp_kses_allowed_html' ) ) {
	function wp_kses_allowed_html( $context = 'post' ) {
		$attrs = array( 'class' => true, 'id' => true, 'style' => true );
		return array(
			'p'       => $attrs,
			'span'    => $attrs,
			'div'     => $attrs,
			'section' => $attrs,
			'details' => $attrs,
			'summary' => $attrs,
			'table'   => $attrs,
			'thead'   => $attrs,
			'tbody'   => $attrs,
			'tr'      => $attrs,
			'th'      => array( 'scope' => true ) + $attrs,
			'td'      => $attrs,
			'h1'      => $attrs,
			'h2'      => $attrs,
			'h3'      => $attrs,
			'ul'      => $attrs,
			'li'      => $attrs,
			'a'       => array( 'href' => true, 'rel' => true, 'target' => true ) + $attrs,
			'strong'  => $attrs,
			'em'      => $attrs,
			'small'   => $attrs,
			'br'      => array(),
		);
	}
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $html, $allowed = array(), $protocols = array() ) { return (string) $html; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_test_state']['options'] )
			? $GLOBALS['faz_test_state']['options'][ $name ]
			: $default;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) {
		return rtrim( $GLOBALS['faz_test_state']['home'], '/' ) . '/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'get_privacy_policy_url' ) ) {
	function get_privacy_policy_url() { return (string) $GLOBALS['faz_test_state']['privacy_url']; }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) {
		return 'timestamp' === $type
			? strtotime( $GLOBALS['faz_test_state']['now'] )
			: $GLOBALS['faz_test_state']['now'];
	}
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() { return (string) $GLOBALS['faz_test_state']['locale']; }
}
if ( ! function_exists( 'determine_locale' ) ) {
	function determine_locale() { return get_locale(); }
}
if ( ! function_exists( 'switch_to_locale' ) ) {
	function switch_to_locale( $locale ) {
		if ( (string) $locale === (string) $GLOBALS['faz_test_state']['locale'] ) {
			return false;
		}
		$GLOBALS['faz_test_state']['locale_stack'][] = $GLOBALS['faz_test_state']['locale'];
		$GLOBALS['faz_test_state']['locale']         = (string) $locale;
		return true;
	}
}
if ( ! function_exists( 'restore_previous_locale' ) ) {
	function restore_previous_locale() {
		if ( ! empty( $GLOBALS['faz_test_state']['locale_stack'] ) ) {
			$GLOBALS['faz_test_state']['locale'] = array_pop( $GLOBALS['faz_test_state']['locale_stack'] );
			return $GLOBALS['faz_test_state']['locale'];
		}
		return false;
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) { $found = false; return false; }
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) { return true; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) { return 0; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return ! empty( $GLOBALS['faz_test_state']['is_admin_user'] ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v, $flags = 0, $depth = 512 ) { return json_encode( $v, $flags, $depth ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $decimals = 0 ) { return number_format( (float) $n, (int) $decimals ); }
}

// ---------------------------------------------------------------------------
// Fake $wpdb — same three calls the renderer makes.
// ---------------------------------------------------------------------------
class FAZ_Fake_WPDB {
	public $prefix         = 'wp_';
	public $tables_present = true;
	public $rows           = array();

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . (string) $arg . "'", $query, 1 );
		}
		return $query;
	}

	public function get_var( $query ) {
		if ( ! $this->tables_present ) {
			return null;
		}
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", (string) $query, $m ) ) {
			return $m[1];
		}
		return null;
	}

	public function get_results( $query, $output = OBJECT ) {
		return $this->rows;
	}
}
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

$GLOBALS['wpdb'] = new FAZ_Fake_WPDB();

// ---------------------------------------------------------------------------
// Units under test.
// ---------------------------------------------------------------------------
$faz_root = dirname( __DIR__, 2 );
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-generator.php';
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-template-translations.php';
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-section-overrides.php';
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-document-config.php';
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-document-registry.php';
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-renderer.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Document_Config;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Document_Registry;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Template_Translations;

// ---------------------------------------------------------------------------
// Assertions.
// ---------------------------------------------------------------------------
$tests_run = $tests_passed = $tests_failed = 0;

function assert_true( $cond, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $cond ) { $tests_passed++; echo "  \033[32m✓\033[0m $label\n"; }
	else { $tests_failed++; echo "  \033[31m✗\033[0m $label\n"; }
}

/** Reset the renderer's three per-request caches between comparable renders. */
function faz_reset_renderer_statics() {
	foreach ( array(
		'cookie_list_cache'        => array(),
		'transfer_cache'           => array(),
		'public_cookie_rows_cache' => null,
	) as $prop => $value ) {
		$ref = new ReflectionProperty( Renderer::class, $prop );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}
}

// ===========================================================================
echo "\n\033[1mDocument_Registry — contents\033[0m\n";
// ===========================================================================

assert_true(
	array( 'cookie-policy' ) === Document_Registry::slugs(),
	'the registry holds exactly one document: cookie-policy'
);
assert_true(
	Document_Registry::get( 'cookie-policy' ) instanceof Document_Config,
	'get("cookie-policy") returns a Document_Config'
);
assert_true(
	null === Document_Registry::get( 'privacy-policy' ),
	'an unregistered slug returns null rather than a half-built config'
);
assert_true(
	Document_Registry::get( 'cookie-policy' ) === Document_Registry::all()['cookie-policy'],
	'configs are built once and memoised (same instance on every access)'
);

$config = Document_Registry::get( 'cookie-policy' );

// ===========================================================================
echo "\n\033[1mDocument_Config — cookie-policy integrity\033[0m\n";
// ===========================================================================

assert_true( 'cookie-policy' === $config->slug(), 'slug()' );
assert_true( 'faz_cookie_policy_data' === $config->option(), 'option() is the shipped wp_options key' );
assert_true( 'faz_cookie_policy_complete' === $config->shortcode(), 'shortcode() matches the registered tag' );
assert_true( 'faz-cookie-policy' === $config->wrapper_class(), 'wrapper_class() matches the shipped CSS hook' );
assert_true( Generator::JURISDICTIONS === $config->jurisdictions(), 'jurisdictions() mirrors Generator::JURISDICTIONS' );
assert_true(
	'gdpr-strict' === $config->jurisdictions()[0],
	'gdpr-strict is FIRST — it is the fallback regime resolve_jurisdiction() falls back to'
);
assert_true( Generator::HTML_TOKENS === $config->html_tokens(), 'html_tokens() mirrors Generator::HTML_TOKENS' );
assert_true( Generator::templates_dir() === $config->templates_dir(), 'templates_dir() points at the shipped template tree' );
assert_true( is_readable( $config->gettext_catalog() ), 'gettext_catalog() points at a readable catalogue file' );

foreach ( Generator::NATIVE_LANG as $jurisdiction => $native ) {
	assert_true(
		$native === $config->native_lang( $jurisdiction ),
		"native_lang( $jurisdiction ) === $native"
	);
}
assert_true(
	'en' === $config->native_lang( 'not-a-jurisdiction' ),
	'native_lang() defaults to en for an unmapped jurisdiction'
);

foreach ( $config->jurisdictions() as $jurisdiction ) {
	assert_true(
		is_readable( $config->templates_dir() . '/' . $jurisdiction . '/en.md' ),
		"$jurisdiction ships an English scaffold under templates_dir()"
	);
}

// The two callables must reach the real implementations, not a stale copy.
assert_true(
	array() === $config->missing_required_settings( 'gdpr-strict', array() ),
	'missing_required_settings() delegates to the POPIA-only gating (GDPR needs nothing)'
);
assert_true(
	in_array( 'company.name', $config->missing_required_settings( 'popia-southafrica', array() ), true ),
	'missing_required_settings() still reports the POPIA mandatory fields'
);
$built = $config->build_data( array(), 'gdpr-strict', 'en' );
assert_true(
	isset( $built['COOKIE_CATEGORIES'] ) && isset( $built['JURISDICTION_NAME'] ),
	'build_data() delegates to the renderer token builder'
);

// ===========================================================================
echo "\n\033[1mDocument_Config — constructor validation\033[0m\n";
// ===========================================================================

/** Run a constructor call and report whether it threw InvalidArgumentException. */
function faz_rejects( array $config ) {
	try {
		new Document_Config( $config );
	} catch ( InvalidArgumentException $e ) {
		return true;
	} catch ( Throwable $e ) {
		return false;
	}
	return false;
}

/** A structurally valid entry, used as the base for the mutation cases below. */
function faz_valid_config_array() {
	return array(
		'slug'            => 'cookie-policy',
		'shortcode'       => 'faz_cookie_policy_complete',
		'option'          => Renderer::SETTINGS_OPTION,
		'templates_dir'   => Generator::templates_dir(),
		'jurisdictions'   => Generator::JURISDICTIONS,
		'native_lang'     => Generator::NATIVE_LANG,
		'html_tokens'     => Generator::HTML_TOKENS,
		'gettext_catalog' => Template_Translations::CATALOG_FILE,
		'wrapper_class'   => 'faz-cookie-policy',
		'data_builder'    => array( Renderer::class, 'build_data' ),
		'required_fields' => array( Generator::class, 'missing_required_settings' ),
	);
}

assert_true( faz_rejects( array() ), 'an empty config is rejected' );
assert_true(
	faz_rejects( array_merge( faz_valid_config_array(), array( 'jurisdictions' => 'gdpr-strict' ) ) ),
	'a scalar where an array belongs (jurisdictions) is rejected'
);
assert_true(
	faz_rejects( array_merge( faz_valid_config_array(), array( 'jurisdictions' => array() ) ) ),
	'an empty jurisdiction list is rejected — there would be no fallback regime'
);
assert_true(
	faz_rejects( array_merge( faz_valid_config_array(), array( 'data_builder' => 'faz_not_a_function' ) ) ),
	'a non-callable data_builder is rejected'
);
assert_true(
	! faz_rejects( faz_valid_config_array() ),
	'the shipped shape is accepted'
);

// ===========================================================================
echo "\n\033[1mGenerator::resolve_template_path() — with and without \$doc\033[0m\n";
// ===========================================================================

// 'sk' has no bundled scaffold (it exercises the fallback chain) and '' is the
// invalid-code branch that falls back to the jurisdiction's native language.
$path_langs = array_merge( Generator::LANGUAGES, array( 'sk', '' ) );
$path_drift = array();
foreach ( Generator::JURISDICTIONS as $jurisdiction ) {
	foreach ( $path_langs as $lang ) {
		$without = Generator::resolve_template_path( $jurisdiction, $lang );
		$with    = Generator::resolve_template_path( $jurisdiction, $lang, $config );
		if ( $without !== $with ) {
			$path_drift[] = $jurisdiction . '/' . ( '' === $lang ? '(empty)' : $lang );
		}
	}
}
assert_true(
	array() === $path_drift,
	'every jurisdiction × language resolves to the same file with or without the config'
		. ( $path_drift ? ' — drifted: ' . implode( ', ', $path_drift ) : '' )
);
assert_true(
	null === Generator::resolve_template_path( 'not-a-jurisdiction', 'en', $config ),
	'an unknown jurisdiction still resolves to null when a config is passed'
);

// ===========================================================================
echo "\n\033[1mTemplate_Translations::apply() — with and without \$doc\033[0m\n";
// ===========================================================================

$translation_drift = array();
foreach ( Generator::JURISDICTIONS as $jurisdiction ) {
	$path     = Generator::resolve_template_path( $jurisdiction, 'en' );
	$scaffold = (string) file_get_contents( $path );
	$without  = Template_Translations::apply( $jurisdiction, 'en', $scaffold );
	$with     = Template_Translations::apply( $jurisdiction, 'en', $scaffold, $config );
	if ( $without !== $with ) {
		$translation_drift[] = $jurisdiction;
	}
}
assert_true(
	array() === $translation_drift,
	'the gettext merge produces the same scaffold with or without the config'
		. ( $translation_drift ? ' — drifted: ' . implode( ', ', $translation_drift ) : '' )
);

// ===========================================================================
echo "\n\033[1mRenderer::render() === Renderer::render_for( cookie-policy )\033[0m\n";
// ===========================================================================

$GLOBALS['faz_test_state']['options'][ Renderer::SETTINGS_OPTION ] = array(
	'company'          => array(
		'name'     => 'Esempio S.r.l.',
		'address'  => 'Via Roma 1, 35100 Padova, Italy',
		'email'    => 'privacy@example.test',
		'registry' => 'IT01234567890',
	),
	'dpo'              => array(
		'name'  => 'Dott.ssa A. Bianchi',
		'email' => 'dpo@example.test',
	),
	'retention_months' => 6,
	'jurisdiction'     => 'gdpr-strict',
	'language'         => 'en',
	'services'         => array( 'Google Analytics', 'YouTube' ),
);
$GLOBALS['wpdb']->rows = array(
	array(
		'cookie_id'            => 1,
		'cookie_name'          => '_ga',
		'cookie_domain'        => '.example.test',
		'cookie_duration'      => '{"en":"2 years"}',
		'cookie_description'   => '{"en":"Google Analytics visitor id."}',
		'cookie_meta'          => '',
		'category_id'          => 3,
		'category_name'        => '{"en":"Analytics"}',
		'category_description' => '{"en":"Measure how visitors use the site."}',
		'category_slug'        => 'analytics',
	),
	array(
		'cookie_id'            => 2,
		'cookie_name'          => 'fazcookie-consent',
		'cookie_domain'        => 'example.test',
		'cookie_duration'      => '{"en":"6 months"}',
		'cookie_description'   => '{"en":"Stores the visitor consent choice."}',
		'cookie_meta'          => '',
		'category_id'          => 1,
		'category_name'        => '{"en":"Necessary"}',
		'category_description' => '{"en":"Required for the site to work."}',
		'category_slug'        => 'necessary',
	),
);

faz_reset_renderer_statics();
$via_render = Renderer::render( array() );
faz_reset_renderer_statics();
$via_render_for = Renderer::render_for( $config, array() );

assert_true( '' !== $via_render, 'render() produced a document (the comparison is not two empty strings)' );
assert_true( $via_render === $via_render_for, 'render() and render_for( cookie-policy ) are byte-identical' );

// The same must hold on the attribute-driven branches, where render_for() reads
// the jurisdiction list and the mandatory-field gate off the config.
foreach ( array(
	'lang attribute'         => array( 'lang' => 'it' ),
	'jurisdiction attribute' => array( 'jurisdiction' => 'ccpa-california' ),
	'unknown jurisdiction'   => array( 'jurisdiction' => 'not-a-jurisdiction' ),
	'show_title'             => array( 'show_title' => 'true' ),
) as $label => $atts ) {
	faz_reset_renderer_statics();
	$a = Renderer::render( $atts );
	faz_reset_renderer_statics();
	$b = Renderer::render_for( $config, $atts );
	assert_true( $a === $b, "render() === render_for() with the $label" );
}

// The refusal path too: POPIA without its mandatory fields must refuse
// identically through both entry points.
$GLOBALS['faz_test_state']['options'][ Renderer::SETTINGS_OPTION ]['jurisdiction'] = 'popia-southafrica';
$GLOBALS['faz_test_state']['options'][ Renderer::SETTINGS_OPTION ]['dpo']          = array();
faz_reset_renderer_statics();
$refuse_a = Renderer::render( array() );
faz_reset_renderer_statics();
$refuse_b = Renderer::render_for( $config, array() );
assert_true(
	$refuse_a === $refuse_b && false !== strpos( $refuse_a, 'faz-cookie-policy' ),
	'the POPIA incomplete-configuration refusal is identical through both entry points'
);

// ===========================================================================
echo "\n\033[1mrender_for() reads the config, not the old constants\033[0m\n";
// ===========================================================================
//
// The equivalence checks above cannot catch a render_for() that accepted a
// $doc and then ignored it — render() delegates to it, so both sides would
// move together. These two cases pass a config that DIFFERS from the shipped
// one and require the difference to show up in the output.

$GLOBALS['faz_test_state']['options'][ Renderer::SETTINGS_OPTION ]['jurisdiction'] = 'gdpr-strict';
$GLOBALS['faz_test_state']['options'][ Renderer::SETTINGS_OPTION ]['dpo']          = array(
	'name'  => 'Dott.ssa A. Bianchi',
	'email' => 'dpo@example.test',
);

$other_wrapper = new Document_Config(
	array_merge( faz_valid_config_array(), array( 'wrapper_class' => 'faz-test-document' ) )
);
faz_reset_renderer_statics();
$baseline = Renderer::render( array() );
faz_reset_renderer_statics();
$rewrapped = Renderer::render_for( $other_wrapper, array() );
assert_true(
	0 === strpos( $rewrapped, '<article class="faz-test-document"' ),
	'wrapper_class() from the config reaches the <article> wrapper'
);
assert_true(
	str_replace( 'faz-test-document', 'faz-cookie-policy', $rewrapped ) === $baseline,
	'the wrapper class is the ONLY difference — the body is untouched by the swap'
);

$GLOBALS['faz_test_state']['options']['faz_test_other_document'] = array(
	'company'      => array( 'name' => 'Altra Ditta S.p.A.' ),
	'jurisdiction' => 'gdpr-strict',
	'language'     => 'en',
);
$other_option = new Document_Config(
	array_merge( faz_valid_config_array(), array( 'option' => 'faz_test_other_document' ) )
);
faz_reset_renderer_statics();
$other_settings = Renderer::render_for( $other_option, array() );
assert_true(
	false !== strpos( $other_settings, 'Altra Ditta S.p.A.' )
		&& false === strpos( $other_settings, 'Esempio S.r.l.' ),
	'option() from the config decides which saved settings are rendered'
);

// ---------------------------------------------------------------------------
echo "\n────────────────────────────────────────────────────────────\n";
if ( 0 === $tests_failed ) {
	echo "\033[32mALL PASS\033[0m — {$tests_passed}/{$tests_run} checks\n\n";
	exit( 0 );
}
echo "\033[31mFAILED\033[0m — {$tests_failed} of {$tests_run} checks failed\n\n";
exit( 1 );
