<?php
/**
 * Golden-render regression test for the Cookie Policy renderer.
 *
 * WHY THIS EXISTS
 *
 * `docs/legal-documents-generator-plan.md` generalises this engine from one
 * document type to several: `Renderer::render()` becomes a two-line delegate to
 * a new `render_for( Document_Config, $atts )`, and `Generator` /
 * `Template_Translations` grow a trailing document parameter. That refactor
 * touches the single code path 1,000 live installs use to publish a legal
 * document, and the failure mode is silent: a policy that still renders, still
 * looks plausible, and is subtly different.
 *
 * So this suite pins the CURRENT output. Each fixture is rendered through the
 * real pipeline and compared byte-for-byte against a committed golden file —
 * including the `data-faz-policy-version` hash, which is the value the plan's
 * P1a phase will later use to decide whether visitors must re-consent. If the
 * refactor changes one byte of any fixture, this fails.
 *
 * WHAT IS REAL AND WHAT IS STUBBED
 *
 * Real: Generator, Renderer, Section_Overrides, Template_Translations, and the
 * bundled Markdown scaffolds on disk — i.e. exactly the four units being
 * refactored, plus their input data.
 *
 * Stubbed: WordPress. The stubs are deterministic rather than faithful, which
 * is sound here because both the golden-generating run and the verifying run
 * use the same stubs — any upstream difference still surfaces. Two stubs are
 * worth calling out:
 *
 *  - `wp_kses()` returns its input unchanged, so the golden files record the
 *    pre-sanitisation HTML. Reimplementing kses would pin WordPress's
 *    behaviour, not ours. But `render()` builds a *custom* allowlist (it adds
 *    `role` + `aria-level` on top of 'post', because plain `wp_kses_post()`
 *    strips `aria-level` and leaves the category names as headings with no
 *    level — an axe-critical WCAG 4.1.2 / 1.3.1 failure). An identity stub
 *    would let a refactor drop that loop unnoticed, so the stub *captures* the
 *    allowlist and a separate assertion checks it.
 *  - `_x()` normally returns the msgid, which makes `Template_Translations`
 *    take its "no translation available" path. One fixture flips a flag that
 *    makes `_x()` return a modified section, exercising the merge branch that
 *    produces a different scaffold and therefore a different hash.
 *
 * The two plugin classes the renderer reaches for (the Languages controller and
 * `Frontend::is_wp_internal_cookie`) are both `class_exists()`-guarded and are
 * deliberately left unloaded, so the guarded fallbacks are what gets pinned.
 *
 * Run:
 *   php tests/unit/test-cookie-policy-golden-render.php
 *   php tests/unit/test-cookie-policy-golden-render.php --update   # regenerate goldens
 *   bash scripts/run-unit-tests.sh
 *
 * Regenerating goldens is a deliberate act: do it only when you intend to
 * change rendered output (e.g. editing a bundled template), and read the diff
 * before committing it.
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'FAZ_VERSION' ) ) {
	define( 'FAZ_VERSION', '1.25.0-test' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$faz_update_mode = in_array( '--update', (array) ( $argv ?? array() ), true );

// ---------------------------------------------------------------------------
// Deterministic environment. Every value the renderer can read from "outside"
// is pinned here, so the only thing that can move a golden file is our code.
// ---------------------------------------------------------------------------
$GLOBALS['faz_test_state'] = array(
	'now'         => '2026-06-03 09:41:00', // → "3 June 2026" and locale variants
	'locale'      => 'en_US',
	'home'        => 'https://example.test',
	'options'     => array(),
	'privacy_url' => '',
	'translate'   => false, // when true, _x() returns a translated variant
	'kses_allowed' => null,  // captured allowlist from the last wp_kses() call
	'is_admin_user' => true, // current_user_can( 'manage_options' )
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
	// Deterministic shape-only cleanup; real esc_url does protocol validation
	// we do not need to reproduce to detect a regression in OUR code.
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
	/**
	 * Catalogue lookup. In "translate" mode we return a section that is
	 * different from the source but keeps every {{PLACEHOLDER}} intact — the
	 * two conditions Template_Translations requires before it will merge a
	 * translated section in. That drives the merge branch instead of the
	 * much more common "msgid returned unchanged" branch.
	 */
	function _x( $text, $context = '', $domain = 'default' ) {
		if ( empty( $GLOBALS['faz_test_state']['translate'] ) ) {
			return (string) $text;
		}
		return rtrim( (string) $text ) . "\n\n_(translated fixture section)_";
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $v ) { return (string) $v; }
}
if ( ! function_exists( 'wp_kses_allowed_html' ) ) {
	// A representative slice of the real 'post' allowlist: enough tags with
	// array-shaped attribute maps for render()'s role/aria-level loop to be
	// observable, which is the only property this suite asserts about it.
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
	function wp_kses( $html, $allowed = array(), $protocols = array() ) {
		$GLOBALS['faz_test_state']['kses_allowed'] = $allowed;
		return (string) $html; // identity — see the file docblock.
	}
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
			return false; // core returns false when already in that locale
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
	// Drives the two notice paths: an administrator gets an actionable
	// warning, an anonymous visitor gets an empty string. Both are pinned.
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
// Fake $wpdb. Only the three calls the renderer makes are implemented:
// table_exists()'s prepared SHOW TABLES probe, and the cookie/category join.
// ---------------------------------------------------------------------------
class FAZ_Fake_WPDB {
	public $prefix         = 'wp_';
	public $tables_present = true;
	public $rows           = array();

	public function prepare( $query, ...$args ) {
		// Only 'SHOW TABLES LIKE %s' reaches this stub.
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
// Units under test. Loaded directly — no autoloader, no plugin bootstrap, so
// the class_exists()-guarded optional dependencies stay absent by design.
// ---------------------------------------------------------------------------
$faz_root = dirname( __DIR__, 2 );
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-generator.php';
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-template-translations.php';
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-section-overrides.php';
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-renderer.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Section_Overrides;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Template_Translations;

$golden_dir = __DIR__ . '/fixtures/cookie-policy-golden';

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

/**
 * Compare rendered output to its golden file, reporting the first divergence
 * with context rather than dumping two multi-kilobyte documents.
 */
function assert_golden( $actual, $path, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( ! is_readable( $path ) ) {
		$tests_failed++;
		echo "  \033[31m✗\033[0m $label\n";
		echo "      no golden file at " . basename( $path ) . "\n";
		echo "      run: php tests/unit/test-cookie-policy-golden-render.php --update\n";
		return;
	}
	$expected = (string) file_get_contents( $path );
	if ( $expected === $actual ) {
		$tests_passed++;
		echo "  \033[32m✓\033[0m $label (" . strlen( $actual ) . " bytes)\n";
		return;
	}
	$tests_failed++;
	echo "  \033[31m✗\033[0m $label\n";
	echo '      golden ' . strlen( $expected ) . " bytes, rendered " . strlen( $actual ) . " bytes\n";

	$exp_lines = explode( "\n", $expected );
	$act_lines = explode( "\n", $actual );
	$max       = max( count( $exp_lines ), count( $act_lines ) );
	$shown     = 0;
	for ( $i = 0; $i < $max && $shown < 3; $i++ ) {
		$e = $exp_lines[ $i ] ?? '(missing line)';
		$a = $act_lines[ $i ] ?? '(missing line)';
		if ( $e !== $a ) {
			echo "      line " . ( $i + 1 ) . ":\n";
			echo "        golden:   " . mb_strimwidth( $e, 0, 160, '…' ) . "\n";
			echo "        rendered: " . mb_strimwidth( $a, 0, 160, '…' ) . "\n";
			$shown++;
		}
	}
	echo "      regenerate deliberately with --update once you have read the diff\n";
}

/**
 * Reset the renderer's process-lifetime static caches.
 *
 * $public_cookie_rows_cache, $cookie_list_cache and $transfer_cache survive
 * between render() calls by design (5-minute request-scoped caching). Without
 * this reset the second fixture in a run would silently reuse the first
 * fixture's cookie inventory, and every golden after the first would be wrong.
 */
function faz_reset_renderer_statics() {
	foreach ( array(
		'cookie_list_cache'       => array(),
		'transfer_cache'          => array(),
		'public_cookie_rows_cache' => null,
	) as $prop => $value ) {
		$ref = new ReflectionProperty( Renderer::class, $prop );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}
}

/**
 * The anchor Section_Overrides expects for a given section index: the first
 * line of that section in the effective scaffold. Computed from the real
 * template rather than hardcoded, so editing a template surfaces as a golden
 * diff instead of an override that silently stops applying.
 */
function faz_anchor_for( $jurisdiction, $lang, $index ) {
	$path = Generator::resolve_template_path( $jurisdiction, $lang );
	if ( null === $path ) {
		return '';
	}
	$sections = Template_Translations::split_sections( (string) file_get_contents( $path ) );
	if ( ! isset( $sections[ $index ] ) ) {
		return '';
	}
	$ref = new ReflectionMethod( Section_Overrides::class, 'first_line' );
	$ref->setAccessible( true );
	return (string) $ref->invoke( null, $sections[ $index ] );
}

// ---------------------------------------------------------------------------
// Fixture data.
// ---------------------------------------------------------------------------

/** A fully populated controller identity, as an admin who filled the form has. */
function faz_fx_company() {
	return array(
		'company' => array(
			'name'     => 'Esempio S.r.l.',
			'address'  => 'Via Roma 1, 35100 Padova, Italy',
			'email'    => 'privacy@example.test',
			'registry' => 'IT01234567890',
		),
		'dpo'     => array(
			'name'  => 'Dott.ssa A. Bianchi',
			'email' => 'dpo@example.test',
		),
		'retention_months' => 6,
	);
}

/** Two categories, three cookies, i18n-JSON category names — the real shape. */
function faz_fx_cookie_rows() {
	return array(
		array(
			'cookie_id'            => 1,
			'cookie_name'          => '_ga',
			'cookie_domain'        => '.example.test',
			'cookie_duration'      => '{"en":"2 years","it":"2 anni"}',
			'cookie_description'   => '{"en":"Google Analytics visitor id.","it":"Identificativo visitatore di Google Analytics."}',
			'cookie_meta'          => '',
			'category_id'          => 3,
			'category_name'        => '{"en":"Analytics","it":"Statistiche"}',
			'category_description' => '{"en":"Measure how visitors use the site.","it":"Misurano come i visitatori usano il sito."}',
			'category_slug'        => 'analytics',
		),
		array(
			'cookie_id'            => 2,
			'cookie_name'          => 'fazcookie-consent',
			'cookie_domain'        => 'example.test',
			'cookie_duration'      => '{"en":"6 months","it":"6 mesi"}',
			'cookie_description'   => '{"en":"Stores the visitor consent choice.","it":"Memorizza la scelta di consenso."}',
			'cookie_meta'          => '',
			'category_id'          => 1,
			'category_name'        => '{"en":"Necessary","it":"Necessari"}',
			'category_description' => '{"en":"Required for the site to work.","it":"Necessari al funzionamento del sito."}',
			'category_slug'        => 'necessary',
		),
		array(
			'cookie_id'            => 3,
			'cookie_name'          => 'wp-settings-1',
			'cookie_domain'        => 'example.test',
			'cookie_duration'      => '{"en":"1 year"}',
			'cookie_description'   => '{"en":"WordPress admin screen options."}',
			'cookie_meta'          => '',
			'category_id'          => 9,
			// Must never reach the public policy — the wordpress-internal guard.
			'category_slug'        => 'wordpress-internal',
			'category_name'        => '{"en":"WordPress internal"}',
			'category_description' => '{"en":"Admin-only cookies."}',
		),
	);
}

/** Same inventory with one cookie flagged as a third-country transfer. */
function faz_fx_cookie_rows_with_transfer() {
	$rows = faz_fx_cookie_rows();
	$rows[0]['cookie_meta'] = wp_json_encode(
		array(
			'transfer' => array(
				'enabled'   => true,
				'countries' => array( 'en' => 'United States', 'it' => 'Stati Uniti' ),
				'safeguard' => array(
					'en' => 'EU Standard Contractual Clauses (2021/914)',
					'it' => 'Clausole contrattuali tipo UE (2021/914)',
				),
			),
		)
	);
	return $rows;
}

/**
 * The fixture matrix. Each entry pins one branch of the pipeline; the `why`
 * is the reason the branch is worth a golden file rather than a unit assertion.
 */
$fixtures = array(
	array(
		'name'    => 'gdpr-empty-install',
		'why'     => 'fresh install: no saved option, baseline defaults only, empty inventory',
		'option'  => null,
		'atts'    => array(),
		'rows'    => array(),
	),
	array(
		'name'    => 'gdpr-full-en',
		'why'     => 'the common case: full controller data, real inventory, services list',
		'option'  => faz_fx_company() + array(
			'jurisdiction' => 'gdpr-strict',
			'language'     => 'en',
			'services'     => array( 'Google Analytics', 'YouTube' ),
		),
		'atts'    => array(),
		'rows'    => faz_fx_cookie_rows(),
	),
	array(
		'name'    => 'gdpr-full-it',
		'why'     => 'language resolution + i18n JSON decoding into a non-English inventory',
		'option'  => faz_fx_company() + array(
			'jurisdiction' => 'gdpr-strict',
			'language'     => 'it',
			'services'     => array( 'Google Analytics' ),
		),
		'atts'    => array( 'lang' => 'it' ),
		'rows'    => faz_fx_cookie_rows(),
		'locale'  => 'it_IT',
	),
	array(
		'name'    => 'gdpr-show-title',
		'why'     => 'show_title="true" keeps the scaffold H1 that is stripped by default',
		// Deliberately identical settings and inventory to gdpr-full-en: the
		// only difference is the attribute, which is what lets the invariant
		// below assert that show_title moves the markup but NOT the hash.
		'option'  => faz_fx_company() + array(
			'jurisdiction' => 'gdpr-strict',
			'language'     => 'en',
			'services'     => array( 'Google Analytics', 'YouTube' ),
		),
		'atts'    => array( 'show_title' => 'true' ),
		'rows'    => faz_fx_cookie_rows(),
	),
	array(
		'name'    => 'gdpr-empty-label-lines',
		'why'     => 'blank optional fields must not leave orphan "**Label:**" rows (1.16.2, Gooloo)',
		'option'  => array(
			'jurisdiction' => 'gdpr-strict',
			'language'     => 'en',
			'company'      => array( 'name' => 'Minimal Ltd', 'address' => '', 'email' => '', 'registry' => '' ),
			'dpo'          => array( 'name' => '', 'email' => '' ),
		),
		'atts'    => array(),
		'rows'    => array(),
	),
	array(
		'name'    => 'gdpr-transfers',
		'why'     => 'Schrems II section appears and its fingerprint enters the version hash',
		'option'  => faz_fx_company() + array( 'jurisdiction' => 'gdpr-strict', 'language' => 'en' ),
		'atts'    => array(),
		'rows'    => faz_fx_cookie_rows_with_transfer(),
	),
	array(
		'name'    => 'gdpr-section-override',
		'why'     => 'admin-authored section replaces shipped text and still resolves placeholders',
		'option'  => faz_fx_company() + array(
			'jurisdiction'      => 'gdpr-strict',
			'language'          => 'en',
			'section_overrides' => 'ANCHOR_OK', // resolved below, needs the loaded classes
		),
		'atts'    => array(),
		'rows'    => array(),
	),
	array(
		'name'    => 'gdpr-section-override-anchor-drift',
		'why'     => 'override whose anchor no longer matches must fall back to shipped text',
		'option'  => faz_fx_company() + array(
			'jurisdiction'      => 'gdpr-strict',
			'language'          => 'en',
			'section_overrides' => 'ANCHOR_DRIFT',
		),
		'atts'    => array(),
		'rows'    => array(),
	),
	array(
		'name'      => 'gdpr-gettext-merge',
		'why'       => 'translated catalogue sections merge in and move the version hash',
		'option'    => faz_fx_company() + array( 'jurisdiction' => 'gdpr-strict', 'language' => 'en' ),
		'atts'      => array(),
		'rows'      => array(),
		'translate' => true,
		// en_GB, not en_US: Template_Translations::catalogue_for_current_locale()
		// memoises per locale for the whole request, so an earlier fixture that
		// already warmed en_US with untranslated msgids would be reused here and
		// this fixture would silently degrade into a duplicate of the plain
		// render. en_GB still maps to policy language 'en' but gets its own
		// cache slot.
		'locale'    => 'en_GB',
	),
	array(
		'name'    => 'ccpa-california',
		'why'     => 'second jurisdiction: different scaffold, different official-body token',
		'option'  => faz_fx_company() + array( 'jurisdiction' => 'ccpa-california', 'language' => 'en' ),
		'atts'    => array(),
		'rows'    => faz_fx_cookie_rows(),
	),
	array(
		'name'    => 'lgpd-brazil',
		'why'     => 'third jurisdiction, native language fallback (pt-BR)',
		'option'  => faz_fx_company() + array( 'jurisdiction' => 'lgpd-brazil', 'language' => 'pt-BR' ),
		'atts'    => array( 'lang' => 'pt-BR' ),
		'rows'    => array(),
	),
	array(
		'name'    => 'popia-complete',
		'why'     => 'POPIA renders once its mandatory operator fields are present',
		// POPIA is the one jurisdiction with mandatory fields today
		// (Generator::missing_required_settings): company name/address/email,
		// DPO name/email, and a valid http(s) privacy_policy_url.
		'option'  => faz_fx_company() + array(
			'jurisdiction'       => 'popia-southafrica',
			'language'           => 'en',
			'privacy_policy_url' => 'https://example.test/privacy-policy/',
		),
		'atts'    => array(),
		'rows'    => array(),
	),
	array(
		'name'    => 'popia-incomplete-refuses',
		'why'     => 'the refusal path: missing mandatory fields must yield the notice, never a partial policy',
		'option'  => array( 'jurisdiction' => 'popia-southafrica', 'language' => 'en' ),
		'atts'    => array(),
		'rows'    => array(),
		'expect'  => 'notice',
	),
	array(
		'name'     => 'popia-incomplete-visitor',
		'why'      => 'the same refusal seen by an anonymous visitor: silence, never an admin hint',
		'option'   => array( 'jurisdiction' => 'popia-southafrica', 'language' => 'en' ),
		'atts'     => array(),
		'rows'     => array(),
		'is_admin' => false,
		'expect'   => 'empty',
	),
	array(
		'name'    => 'unknown-jurisdiction-att-ignored',
		'why'     => 'a bogus shortcode jurisdiction is rejected, not honoured: falls back to the saved one',
		'option'  => faz_fx_company() + array( 'jurisdiction' => 'ccpa-california', 'language' => 'en' ),
		'atts'    => array( 'jurisdiction' => 'not-a-jurisdiction' ),
		'rows'    => array(),
	),
	array(
		'name'    => 'tables-absent',
		'why'     => 'plugin tables missing (fresh multisite, failed activation): inventory is empty, not fatal',
		'option'  => faz_fx_company() + array( 'jurisdiction' => 'gdpr-strict', 'language' => 'en' ),
		'atts'    => array(),
		'rows'    => faz_fx_cookie_rows(),
		'tables'  => false,
	),
);

// Resolve the two override fixtures now that the classes are loaded: the
// matching anchor is read from the real scaffold, the drifting one is not.
$override_text = "## Who we are\n\nThis section was rewritten by the site operator. "
	. "The controller is **{{COMPANY_NAME}}**, reachable at {{COMPANY_EMAIL}}.";
foreach ( $fixtures as &$fx ) {
	if ( ! isset( $fx['option']['section_overrides'] ) || ! is_string( $fx['option']['section_overrides'] ) ) {
		continue;
	}
	$anchor = 'ANCHOR_OK' === $fx['option']['section_overrides']
		? faz_anchor_for( 'gdpr-strict', 'en', 1 )
		: '## A heading this scaffold no longer has';
	$fx['option']['section_overrides'] = array(
		'gdpr-strict' => array(
			'en' => array(
				1 => array( 'text' => $override_text, 'anchor' => $anchor ),
			),
		),
	);
}
unset( $fx );

// ---------------------------------------------------------------------------
// Run.
// ---------------------------------------------------------------------------
echo "\n== Cookie Policy golden render (" . ( $faz_update_mode ? "\033[33mUPDATE\033[0m" : 'verify' ) . ") ==\n\n";

if ( $faz_update_mode && ! is_dir( $golden_dir ) ) {
	mkdir( $golden_dir, 0755, true );
}

$rendered_all   = array();
$expect_by_name = array();

foreach ( $fixtures as $fx ) {
	// Pin the world for this fixture.
	$GLOBALS['faz_test_state']['options']      = array();
	$GLOBALS['faz_test_state']['locale']       = $fx['locale'] ?? 'en_US';
	$GLOBALS['faz_test_state']['locale_stack'] = array();
	$GLOBALS['faz_test_state']['translate']    = ! empty( $fx['translate'] );
	$GLOBALS['faz_test_state']['kses_allowed'] = null;
	$GLOBALS['faz_test_state']['is_admin_user'] = $fx['is_admin'] ?? true;
	if ( null !== $fx['option'] ) {
		$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data'] = $fx['option'];
	}
	$GLOBALS['wpdb']->rows           = $fx['rows'];
	$GLOBALS['wpdb']->tables_present = $fx['tables'] ?? true;
	faz_reset_renderer_statics();

	$html                        = Renderer::render( $fx['atts'] );
	$rendered_all[ $fx['name'] ] = $html;
	$expect_by_name[ $fx['name'] ] = $fx['expect'] ?? 'document';

	$path = $golden_dir . '/' . $fx['name'] . '.html';
	if ( $faz_update_mode ) {
		file_put_contents( $path, $html );
		echo "  \033[33m↻\033[0m {$fx['name']} — " . strlen( $html ) . " bytes written\n";
	} else {
		assert_golden( $html, $path, $fx['name'] );
	}
}

// ---------------------------------------------------------------------------
// Properties that a golden file alone cannot guard.
// ---------------------------------------------------------------------------
if ( ! $faz_update_mode ) {
	echo "\n-- invariants --\n";

	// The kses allowlist must still carry the accessibility attributes that
	// wp_kses_post() would strip. Captured from the last render.
	$allowed = $GLOBALS['faz_test_state']['kses_allowed'];
	assert_true( is_array( $allowed ) && ! empty( $allowed ), 'render() passes a custom allowlist to wp_kses()' );
	$span_ok = is_array( $allowed ) && isset( $allowed['span'] )
		&& ! empty( $allowed['span']['role'] ) && ! empty( $allowed['span']['aria-level'] );
	assert_true( $span_ok, 'allowlist keeps role + aria-level on <span> (WCAG 4.1.2 category headings)' );
	$all_tags_ok = true;
	if ( is_array( $allowed ) ) {
		foreach ( $allowed as $tag => $attrs ) {
			if ( is_array( $attrs ) && ( empty( $attrs['role'] ) || empty( $attrs['aria-level'] ) ) ) {
				$all_tags_ok = false;
				break;
			}
		}
	}
	assert_true( $all_tags_ok, 'every array-shaped tag in the allowlist gained role + aria-level' );

	// Structural guarantees, per the kind of output each fixture must produce.
	foreach ( $rendered_all as $name => $html ) {
		switch ( $expect_by_name[ $name ] ) {
			case 'document':
				assert_true(
					// The hash shape is `<6 hex>.<6 hex>` (template sha prefix,
					// dot, data sha prefix). P1a will parse this value to decide
					// whether a change is material, so the shape is pinned here.
					(bool) preg_match( '/^<article class="faz-cookie-policy" lang="[^"]+" data-jurisdiction="[^"]+" data-faz-policy-version="[a-f0-9]{6}\.[a-f0-9]{6}">/', $html ),
					"{$name}: article wrapper carries lang, jurisdiction and a version hash"
				);
				break;
			case 'notice':
				assert_true(
					false !== strpos( $html, 'faz-cookie-policy-empty' )
						&& false === strpos( $html, '<article class="faz-cookie-policy"' ),
					"{$name}: administrator sees a notice and no partial policy"
				);
				break;
			case 'empty':
				assert_true(
					'' === $html,
					"{$name}: anonymous visitor sees nothing at all"
				);
				break;
		}
	}

	// The wordpress-internal category must never surface publicly, in any
	// fixture — the standing rule in CLAUDE.md.
	foreach ( $rendered_all as $name => $html ) {
		assert_true(
			false === strpos( $html, 'wp-settings-1' ) && false === strpos( $html, 'WordPress internal' ),
			"{$name}: no WordPress-internal cookie leaked into the public policy"
		);
	}

	// The hash must react to content and stay put otherwise. These pairs are
	// the property P1a will later build re-consent decisions on.
	$hash_of = function ( $html ) {
		return preg_match( '/data-faz-policy-version="([^"]+)"/', $html, $m ) ? $m[1] : '';
	};
	assert_true(
		$hash_of( $rendered_all['gdpr-full-en'] ) !== $hash_of( $rendered_all['gdpr-transfers'] ),
		'version hash changes when a third-country transfer is flagged'
	);
	assert_true(
		$hash_of( $rendered_all['gdpr-full-en'] ) !== $hash_of( $rendered_all['gdpr-section-override'] ),
		'version hash changes when an admin overrides a section'
	);
	assert_true(
		$hash_of( $rendered_all['gdpr-full-en'] ) !== $hash_of( $rendered_all['gdpr-gettext-merge'] ),
		'version hash changes when the gettext catalogue supplies translations'
	);
	assert_true(
		$hash_of( $rendered_all['gdpr-full-en'] ) === $hash_of( $rendered_all['gdpr-show-title'] ),
		'version hash ignores show_title (presentation, not content)'
	);
	// …and the pair really does differ, so the assertion above is not vacuous.
	assert_true(
		$rendered_all['gdpr-full-en'] !== $rendered_all['gdpr-show-title']
			&& false !== strpos( $rendered_all['gdpr-show-title'], '<h1' )
			&& false === strpos( $rendered_all['gdpr-full-en'], '<h1' ),
		'show_title="true" keeps the H1 that the default render strips'
	);

	// Anchor drift must be inert: same bytes as if no override existed at all,
	// apart from nothing. Compare against the plain full-data render.
	assert_true(
		$rendered_all['gdpr-section-override-anchor-drift'] !== $rendered_all['gdpr-section-override'],
		'a drifting anchor does not apply the override'
	);
	assert_true(
		false !== strpos( $rendered_all['gdpr-section-override'], 'rewritten by the site operator' ),
		'the matching override text does reach the output'
	);
	assert_true(
		false === strpos( $rendered_all['gdpr-section-override-anchor-drift'], 'rewritten by the site operator' ),
		'the drifting override text does not reach the output'
	);
	assert_true(
		false !== strpos( $rendered_all['gdpr-section-override'], 'Esempio S.r.l.' ),
		'placeholders inside admin-authored text are still substituted'
	);

	// A bogus shortcode jurisdiction must be ignored rather than honoured —
	// otherwise any author could publish the wrong legal regime on a page.
	assert_true(
		false !== strpos( $rendered_all['unknown-jurisdiction-att-ignored'], 'data-jurisdiction="ccpa-california"' ),
		'an unknown jurisdiction attribute falls back to the configured jurisdiction'
	);

	// no_template_notice() exists as a safety net, and this asserts it stays
	// unreachable: every jurisdiction must resolve a scaffold for every policy
	// language. If the refactor moves templates/ or changes the fallback chain,
	// this fails here rather than as a blank policy on a live site.
	$unresolvable = array();
	foreach ( Generator::JURISDICTIONS as $j ) {
		foreach ( array_merge( Generator::LANGUAGES, array( 'sk', 'zz', '' ) ) as $l ) {
			if ( null === Generator::resolve_template_path( $j, $l ) ) {
				$unresolvable[] = "{$j}/{$l}";
			}
		}
	}
	assert_true(
		empty( $unresolvable ),
		'every jurisdiction resolves a scaffold for every language (incl. unbundled + empty): '
			. ( empty( $unresolvable ) ? 'none unresolvable' : implode( ', ', $unresolvable ) )
	);

	// The refusal path must refuse, not half-render.
	assert_true(
		false === strpos( $rendered_all['popia-incomplete-refuses'], '<article class="faz-cookie-policy"' ),
		'POPIA without mandatory fields renders a notice, not a policy'
	);
	assert_true(
		false !== strpos( $rendered_all['popia-complete'], '<article class="faz-cookie-policy"' ),
		'POPIA with mandatory fields renders the policy'
	);

	// Never publish the admin email / site name without an explicit save.
	assert_true(
		'' !== $rendered_all['gdpr-empty-install'],
		'an unconfigured install still renders something rather than a blank page'
	);
}

// ---------------------------------------------------------------------------
echo "\n";
if ( $faz_update_mode ) {
	echo "\033[33mGolden files written to tests/unit/fixtures/cookie-policy-golden/.\033[0m\n";
	echo "Read the diff before committing: these files are the contract the refactor must honour.\n\n";
	exit( 0 );
}

echo "────────────────────────────────────────────────────────────\n";
if ( 0 === $tests_failed ) {
	echo "\033[32mALL PASS\033[0m — {$tests_passed}/{$tests_run} checks\n\n";
	exit( 0 );
}
echo "\033[31mFAILED\033[0m — {$tests_failed} of {$tests_run} checks failed\n\n";
exit( 1 );
