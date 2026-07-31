<?php
/**
 * Unit test for the Cookie Policy version ledger.
 *
 * WHY THIS EXISTS
 *
 * Version_Ledger decides whether the operator is shown a "your policy changed"
 * prompt, and the two failure modes are both bad in ways nobody would notice
 * quickly. A ledger that over-reports fires the notice on every admin page load
 * until the operator learns to ignore it — and then ignores the one that
 * mattered. A ledger that under-reports lets a rewritten policy sit behind
 * consents collected against the old text.
 *
 * The interesting properties are therefore not "does it store a string" but the
 * boundary conditions around WHEN it must stay silent:
 *
 *  - the first evaluation on an existing install must adopt the current hash
 *    without prompting (the feature's own arrival is not a policy change);
 *  - an install whose policy does not render at all (POPIA with mandatory
 *    fields still blank) must not seed anything, or the operator's first real
 *    configuration would look like a change;
 *  - the hash must not move because the operator opened a different admin
 *    screen ({{COOKIE_POLICY_URL}} comes from REQUEST_URI and is hashed), nor
 *    because the calendar advanced (LAST_UPDATED_DATE is a volatile key).
 *
 * WHAT IS REAL AND WHAT IS STUBBED
 *
 * Real: Version_Ledger, and the whole render path it measures — Generator,
 * Renderer, Section_Overrides, Template_Translations and the bundled Markdown
 * scaffolds on disk. The hash is read back off the rendered markup, exactly as
 * production does, rather than recomputed through a shortcut path.
 *
 * Stubbed: WordPress. The stub set is the one from
 * test-cookie-policy-golden-render.php, plus an update_option() that writes
 * back into the same in-memory options map get_option() reads — this suite
 * asserts on what the ledger persists, so a read-only stub would be useless.
 *
 * Run:
 *   php tests/unit/test-version-ledger.php
 *   bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'FAZ_VERSION' ) ) {
	define( 'FAZ_VERSION', '1.26.0-test' );
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
	'locale_stack'  => array(),
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
if ( ! function_exists( 'update_option' ) ) {
	// Writes into the same map get_option() reads. `$autoload` is accepted and
	// recorded so a test can assert the ledger asks for autoload=false.
	function update_option( $name, $value, $autoload = null ) {
		if ( ! empty( $GLOBALS['faz_test_state']['fail_updates'][ $name ] ) ) {
			$GLOBALS['faz_test_state']['fail_updates'][ $name ]--;
			return false;
		}
		$GLOBALS['faz_test_state']['options'][ $name ]  = $value;
		$GLOBALS['faz_test_state']['autoload'][ $name ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value, $deprecated = '', $autoload = null ) {
		if ( array_key_exists( $name, $GLOBALS['faz_test_state']['options'] ) ) {
			return false;
		}
		$GLOBALS['faz_test_state']['options'][ $name ]  = $value;
		$GLOBALS['faz_test_state']['autoload'][ $name ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['faz_test_state']['options'][ $name ] );
		return true;
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
// Fake $wpdb — only the calls the renderer makes.
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
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-renderer.php';
require_once $faz_root . '/admin/modules/cookie-policy-generator/includes/class-version-ledger.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Version_Ledger;

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------
$tests_run = $tests_passed = $tests_failed = 0;

function assert_true( $cond, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $cond ) { $tests_passed++; echo "  \033[32m✓\033[0m $label\n"; }
	else { $tests_failed++; echo "  \033[31m✗\033[0m $label\n"; }
}

function assert_same( $expected, $actual, $label ) {
	assert_true( $expected === $actual, $label );
	if ( $expected !== $actual ) {
		echo '      expected: ' . var_export( $expected, true ) . "\n";
		echo '      actual:   ' . var_export( $actual, true ) . "\n";
	}
}

/**
 * Reset the renderer's process-lifetime static caches.
 *
 * cookie_list_cache / transfer_cache / public_cookie_rows_cache survive between
 * render() calls by design (request-scoped caching). Without this reset every
 * case after the first would silently reuse the first one's inventory, and the
 * "hash changed" assertions would pass for the wrong reason.
 */
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

/** Wipe both options and the renderer caches — a clean install, every time. */
function faz_reset_world() {
	$GLOBALS['faz_test_state']['options'] = array();
	$GLOBALS['faz_test_state']['fail_updates'] = array();
	$GLOBALS['faz_test_state']['now']     = '2026-06-03 09:41:00';
	$GLOBALS['wpdb']->rows                = array();
	$GLOBALS['wpdb']->tables_present      = true;
	faz_reset_renderer_statics();
}

/** A configured GDPR install: everything the policy needs, nothing exotic. */
function faz_fx_gdpr_settings() {
	return array(
		'jurisdiction'     => 'gdpr-strict',
		'language'         => 'en',
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
	);
}

/** The stored ledger entry, or null when the option was never written. */
function faz_stored_ledger() {
	$options = $GLOBALS['faz_test_state']['options'];
	return array_key_exists( Version_Ledger::OPTION, $options ) ? $options[ Version_Ledger::OPTION ] : null;
}

echo "\n== Cookie Policy version ledger ==\n\n";

// ---------------------------------------------------------------------------
// (a) Seeding: the feature's own arrival must never prompt.
// ---------------------------------------------------------------------------
echo "-- seeds silently on first evaluation --\n";

faz_reset_world();
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data'] = faz_fx_gdpr_settings();

$first = Version_Ledger::evaluate();
assert_same( 'seeded', $first['status'], 'a configured install with no ledger entry seeds rather than prompts' );
assert_true(
	is_string( $first['current'] ) && (bool) preg_match( Version_Ledger::HASH_PATTERN, $first['current'] ),
	'the seeded hash has the <6hex>.<6hex> shape the renderer stamps'
);
assert_same( $first['current'], $first['acknowledged'], 'seeding reports the same hash as acknowledged' );

$stored = faz_stored_ledger();
assert_true(
	is_array( $stored ) && isset( $stored[ Version_Ledger::DOCUMENT ]['hash'] )
		&& $stored[ Version_Ledger::DOCUMENT ]['hash'] === $first['current'],
	'the option now holds the hash, keyed by document slug'
);
assert_true(
	is_array( $stored ) && isset( $stored[ Version_Ledger::DOCUMENT ]['acknowledged_at'] )
		&& is_int( $stored[ Version_Ledger::DOCUMENT ]['acknowledged_at'] ),
	'the entry records when it was acknowledged'
);
assert_same(
	false,
	$GLOBALS['faz_test_state']['autoload'][ Version_Ledger::OPTION ] ?? null,
	'the ledger is stored with autoload disabled (read only on one admin screen)'
);

faz_reset_renderer_statics();
$second = Version_Ledger::evaluate();
assert_same( 'unchanged', $second['status'], 'an immediate second evaluation is quiet' );
assert_same( $first['current'], $second['current'], 'and reports the same hash' );

// ---------------------------------------------------------------------------
// (b) Change detection, and acknowledging it.
// ---------------------------------------------------------------------------
echo "\n-- detects an edited policy, and settles once acknowledged --\n";

$settings                    = faz_fx_gdpr_settings();
$settings['company']['name'] = 'Esempio S.r.l. (renamed)';
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data'] = $settings;
faz_reset_renderer_statics();

$changed = Version_Ledger::evaluate();
assert_same( 'changed', $changed['status'], 'editing the controller name surfaces as a change' );
assert_true( $changed['acknowledged'] !== $changed['current'], 'both hashes are reported and they differ' );
assert_same( $first['current'], $changed['acknowledged'], 'the acknowledged hash is still the seeded one' );
assert_true(
	(bool) preg_match( Version_Ledger::HASH_PATTERN, $changed['current'] ),
	'the new hash keeps the pinned shape'
);

assert_true( Version_Ledger::acknowledge( $changed['current'] ), 'acknowledge() accepts a well-formed hash' );
faz_reset_renderer_statics();
$settled = Version_Ledger::evaluate();
assert_same( 'unchanged', $settled['status'], 'acknowledging silences the notice' );
assert_same( $changed['current'], $settled['acknowledged'], 'and the new hash is what is now stored' );

// ---------------------------------------------------------------------------
// (c) acknowledge() refuses anything that is not a hash.
// ---------------------------------------------------------------------------
echo "\n-- refuses malformed hashes without touching the option --\n";

$before_bad = faz_stored_ledger();
$bad_inputs = array(
	'abcdef'         => 'a single 6-hex group (no dot)',
	'ABCDEF.ABCDEF'  => 'uppercase hex',
	'abcdef.abcdef1' => 'a 7-char second group',
	'<script>'       => 'markup',
);
foreach ( $bad_inputs as $bad => $why ) {
	assert_same( false, Version_Ledger::acknowledge( $bad ), "acknowledge() rejects {$why}" );
}
assert_same( false, Version_Ledger::acknowledge( array() ), 'acknowledge() rejects a non-string' );
assert_same( $before_bad, faz_stored_ledger(), 'no rejected input reached the stored ledger' );

// ---------------------------------------------------------------------------
// (d) Nothing renders → nothing is written.
// ---------------------------------------------------------------------------
echo "\n-- an unconfigured POPIA install is left alone --\n";

faz_reset_world();
// POPIA is the one jurisdiction with mandatory fields; with them blank the
// renderer refuses and emits a notice that carries no version attribute.
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data'] = array(
	'jurisdiction' => 'popia-southafrica',
	'language'     => 'en',
);

assert_same( '', Version_Ledger::current_hash(), 'a policy that refuses to render has no hash' );
faz_reset_renderer_statics();
$unavailable = Version_Ledger::evaluate();
assert_same( 'unavailable', $unavailable['status'], 'the ledger reports unavailable rather than guessing' );
assert_same( '', $unavailable['current'], 'and offers no current hash' );
assert_same( null, faz_stored_ledger(), 'the option is NOT seeded — the first real save must not read as a change' );

// ---------------------------------------------------------------------------
// (e) The hash must not depend on which admin screen computed it.
// ---------------------------------------------------------------------------
echo "\n-- the hash does not follow the request URL --\n";

faz_reset_world();
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data'] = faz_fx_gdpr_settings();

$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=faz-cookie-manager-cookie-policy';
faz_reset_renderer_statics();
$from_admin = Version_Ledger::current_hash();
assert_same(
	'/wp-admin/admin.php?page=faz-cookie-manager-cookie-policy',
	$_SERVER['REQUEST_URI'],
	'current_hash() restores REQUEST_URI when it is done'
);

$_SERVER['REQUEST_URI'] = '/cookie-policy/';
faz_reset_renderer_statics();
$from_frontend_path = Version_Ledger::current_hash();

assert_true( '' !== $from_admin, 'the admin-screen call still produced a hash' );
assert_same( $from_admin, $from_frontend_path, 'the same policy hashes the same from any request path' );

// A live render from the same admin URL — i.e. WITHOUT the pin — must differ,
// otherwise the assertion above would hold for the trivial reason that
// {{COOKIE_POLICY_URL}} does not reach the hash at all, and the pin in
// current_hash() would be dead code nobody would notice removing.
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=faz-cookie-manager-cookie-policy';
faz_reset_renderer_statics();
$unpinned = preg_match( '/data-faz-policy-version="([a-f0-9]{6}\.[a-f0-9]{6})"/', Renderer::render( array() ), $m )
	? $m[1]
	: '';
assert_true(
	'' !== $unpinned && $unpinned !== $from_admin,
	'an unpinned render from the admin URL really does hash differently (the pin is load-bearing)'
);
$_SERVER['REQUEST_URI'] = '/cookie-policy/';

// ---------------------------------------------------------------------------
// (f) The hash must not move because a day passed.
// ---------------------------------------------------------------------------
echo "\n-- the hash ignores the calendar --\n";

faz_reset_world();
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data'] = faz_fx_gdpr_settings();
faz_reset_renderer_statics();
$day_one = Version_Ledger::current_hash();

$GLOBALS['faz_test_state']['now'] = '2026-06-04 09:41:00';
faz_reset_renderer_statics();
$day_two = Version_Ledger::current_hash();

assert_true( '' !== $day_one, 'the day-one render produced a hash' );
assert_same( $day_one, $day_two, 'a new calendar day does not move the hash (LAST_UPDATED_DATE stays volatile)' );

// ---------------------------------------------------------------------------
// (g) A saved non-default variant participates in the review token.
// ---------------------------------------------------------------------------
echo "\n-- saved jurisdiction/language variants participate in review --\n";

faz_reset_world();
$variant_settings = faz_fx_gdpr_settings();
$variant_settings['section_overrides'] = array(
	'gdpr-strict' => array(
		'it' => array(
			'1' => array(
				'anchor' => '## Chi siamo',
				'text'   => "## Chi siamo\nLa versione italiana personalizzata di {{COMPANY_NAME}}.",
			),
		),
	),
);
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data'] = $variant_settings;
faz_reset_renderer_statics();
$default_before = Version_Ledger::current_hash();
$review_before  = Version_Ledger::review_hash();
Version_Ledger::acknowledge( $review_before );

$variant_settings['section_overrides']['gdpr-strict']['it']['1']['text'] = "## Chi siamo\nTesto italiano aggiornato per {{COMPANY_NAME}}.";
$GLOBALS['faz_test_state']['options']['faz_cookie_policy_data'] = $variant_settings;
faz_reset_renderer_statics();
$default_after = Version_Ledger::current_hash();
$variant_change = Version_Ledger::evaluate();

assert_same( $default_before, $default_after, 'editing only Italian leaves the default English render hash unchanged' );
assert_true( $review_before !== $variant_change['current'], 'the aggregate review token changes for the saved Italian variant' );
assert_same( 'changed', $variant_change['status'], 'a non-default variant change surfaces the review notice' );

// ---------------------------------------------------------------------------
// (h) Retry after the bump but before ledger persistence never bumps twice.
// ---------------------------------------------------------------------------
echo "\n-- material-change retries are idempotent after a partial failure --\n";

$material_hash = $variant_change['current'];
$revision      = 7;
$bump_calls    = 0;
$read_revision = static function () use ( &$revision ) { return $revision; };
$bump_revision = static function ( $before ) use ( &$revision, &$bump_calls ) {
	$bump_calls++;
	$revision = $before + 1;
	return $revision;
};

// Simulate the precise dangerous window: revision persisted, then the ledger
// write fails. The pending intent must make the next HTTP attempt resumable.
$GLOBALS['faz_test_state']['fail_updates'][ Version_Ledger::OPTION ] = 1;
$partial = Version_Ledger::acknowledge_material( $material_hash, $read_revision, $bump_revision );
assert_same( false, $partial['success'], 'the first request reports the failed ledger write' );
assert_same( 8, $revision, 'the consent revision was already persisted once' );
assert_true( null !== get_option( Version_Ledger::PENDING_OPTION, null ), 'the recovery intent remains stored' );

$retry = Version_Ledger::acknowledge_material( $material_hash, $read_revision, $bump_revision );
assert_same( true, $retry['success'], 'a retry resumes and completes the same decision' );
assert_same( true, $retry['replayed'], 'the API result identifies the resumed operation' );
assert_same( 8, $revision, 'the retry does not increment consent revision again' );
assert_same( 1, $bump_calls, 'the irreversible bump callback ran exactly once' );
assert_same( $material_hash, Version_Ledger::acknowledged_hash(), 'the resumed request finishes the ledger acknowledgement' );
assert_same( null, get_option( Version_Ledger::PENDING_OPTION, null ), 'the recovery intent is removed after completion' );

// An abandoned intent that never reached the bump may be superseded by the
// current version. Otherwise one failed old click would 500 every later policy
// decision forever.
$old_hash = '111111.222222';
$new_hash = '333333.444444';
$revision = 10;
$GLOBALS['faz_test_state']['options'][ Version_Ledger::PENDING_OPTION ] = array(
	'hash'            => $old_hash,
	'revision_before' => 10,
	'revision_after'  => 0,
	'started_at'      => time(),
);
$replacement_bumps = 0;
$replacement = Version_Ledger::acknowledge_material(
	$new_hash,
	static function () use ( &$revision ) { return $revision; },
	static function ( $before ) use ( &$revision, &$replacement_bumps ) {
		$replacement_bumps++;
		$revision = $before + 1;
		return $revision;
	}
);
assert_same( true, $replacement['success'], 'a newer token replaces an abandoned pre-bump intent' );
assert_same( 11, $revision, 'the replacement decision performs one revision bump' );
assert_same( 1, $replacement_bumps, 'the abandoned intent does not add a phantom bump' );
assert_same( $new_hash, Version_Ledger::acknowledged_hash(), 'the newer review token is the one acknowledged' );

// ---------------------------------------------------------------------------
echo "\n────────────────────────────────────────────────────────────\n";
if ( 0 === $tests_failed ) {
	echo "\033[32mALL PASS\033[0m — {$tests_passed}/{$tests_run} checks\n\n";
	exit( 0 );
}
echo "\033[31mFAILED\033[0m — {$tests_failed} of {$tests_run} checks failed\n\n";
exit( 1 );
