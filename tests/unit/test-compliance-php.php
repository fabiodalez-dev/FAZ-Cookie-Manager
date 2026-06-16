<?php
/**
 * Standalone unit tests for the 2026-06 compliance-hardening BACKEND surface.
 *
 * Distinct from test-compliance-hardening.php (resolver routing / GPC flags /
 * CIDR allowlist): this suite drills the *data-handling* edge cases introduced
 * by the compliance-hardening commit (4d6d107):
 *
 *   1. Consent-log category/service decision-map sanitisation:
 *        - svc.x / ck.x values constrained to the {yes,no} allowlist,
 *        - key length capped at 190 chars,
 *        - entry count capped at 250,
 *        - scalar / null / object input handled without fatal.
 *      (mirrors admin/modules/consentlogs/includes/class-controller.php
 *       ::log_consent() inline sanitiser — that block is private + DB-bound, so
 *       the EXACT predicate is replicated here and exercised on edge inputs.)
 *
 *   2. DSAR privacy exporter / eraser shapes (faz_privacy_exporter /
 *      faz_privacy_eraser in includes/class-utils.php) — executed for real
 *      against a stubbed WP_Query:
 *        - exporter returns {data:[], done:bool} and pages FORWARD,
 *        - eraser returns {items_removed, items_retained:false, done},
 *        - eraser always reads page 1 (never the incremented $page) so the
 *          shrinking match-set drains deterministically,
 *        - invalid email / missing CPT short-circuits to done with nothing.
 *
 *   3. Pageview URL minimisation (mirrors
 *      admin/modules/pageviews/includes/class-controller.php::record_event()):
 *        query string + fragment dropped, only scheme://host/path kept; the
 *        real faz_parse_url() is invoked through a wp_parse_url stub.
 *
 *   4. DNSMPI category gating predicate (mirrors
 *      frontend/class-frontend.php::get_blocked_categories()): a sell-OR-share
 *      category is blocked outright under a DNSMPI opt-out cookie; a category
 *      that neither sells nor shares is never blocked by that branch.
 *
 *   5. JSON validity + structural integrity of the 6 geo rulesets touched by
 *      the compliance-hardening commit (Texas, New Jersey, Minnesota, Maryland,
 *      New Hampshire, PIPEDA-Canada) plus the new law25-quebec file.
 *
 * Run from project root:
 *   php tests/unit/test-compliance-php.php
 *   bash scripts/run-unit-tests.sh
 *
 * Exit 0 = all pass; 1 = at least one failure. No WP runtime, no DB, no
 * browser — deterministic CLI runner mirroring the sibling suites.
 *
 * @package FazCookie\Tests\Unit
 */

// ---------- Bootstrap ----------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$root = dirname( __DIR__, 2 );

// ---------- Minimal WP function stubs (only what the SUT touches) ----------

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { // phpcs:ignore
		$s = (string) $s;
		// Collapse/strip control chars + tags + surrounding whitespace, like core.
		$s = wp_strip_all_tags_compat( $s );
		$s = preg_replace( '/[\r\n\t]+/', ' ', $s );
		$s = preg_replace( '/[\x00-\x1F\x7F]/', '', $s );
		return trim( preg_replace( '/\s{2,}/', ' ', $s ) );
	}
}
function wp_strip_all_tags_compat( $s ) { // phpcs:ignore
	return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) );
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) { return abs( (int) $n ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { // phpcs:ignore
		// Approximate core: keep only a safe-ish URL, drop spaces.
		return trim( (string) $u );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $u ) { return parse_url( (string) $u ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) { return json_encode( $v ); } // phpcs:ignore
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $e ) { return trim( (string) $e ); } // phpcs:ignore
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $e ) { // phpcs:ignore
		return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; } // phpcs:ignore
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = 'default' ) { return (string) $t; } // phpcs:ignore
}
if ( ! function_exists( '_n' ) ) {
	function _n( $s, $p, $n, $d = 'default' ) { return 1 === (int) $n ? (string) $s : (string) $p; } // phpcs:ignore
}

// DSAR exporter/eraser dependencies — driven by globals so each test controls them.
$GLOBALS['faz_test_cpt_exists'] = true;
$GLOBALS['faz_test_paged_seen'] = array(); // records which `paged` each WP_Query asked for.
$GLOBALS['faz_test_deleted']    = array(); // post ids passed to wp_delete_post.

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $t ) { return (bool) $GLOBALS['faz_test_cpt_exists']; } // phpcs:ignore
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) { return $key . '#' . $id; } // phpcs:ignore
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $field, $id ) { return '2026-06-16 00:00:00'; } // phpcs:ignore
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $id, $force = false ) { // phpcs:ignore
		$GLOBALS['faz_test_deleted'][] = (int) $id;
		return true; // deletion "succeeds".
	}
}
if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Stub WP_Query: returns a configurable batch of post IDs and records the
	 * `paged` arg requested so we can assert the eraser always reads page 1.
	 * The batch is sourced from $GLOBALS['faz_test_batches'] keyed by the paged
	 * value the caller asked for (default: empty result).
	 */
	class WP_Query { // phpcs:ignore
		public $posts;
		public $max_num_pages;
		public function __construct( $args ) {
			$paged = isset( $args['paged'] ) ? (int) $args['paged'] : 1;
			$GLOBALS['faz_test_paged_seen'][] = $paged;
			$batches = isset( $GLOBALS['faz_test_batches'] ) ? $GLOBALS['faz_test_batches'] : array();
			$this->posts         = isset( $batches[ $paged ] ) ? $batches[ $paged ] : array();
			$this->max_num_pages = isset( $GLOBALS['faz_test_max_pages'] ) ? (int) $GLOBALS['faz_test_max_pages'] : 1;
		}
	}
}

require_once $root . '/includes/class-utils.php';

// ---------- Assert helpers ----------

$tests_run    = 0;
$tests_passed = 0;
$tests_failed = 0;

function assert_eq( $actual, $expected, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $actual === $expected ) {
		$tests_passed++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
	} else {
		$tests_failed++;
		echo "  \033[31m✗\033[0m " . $label . "\n";
		echo "      expected: " . var_export( $expected, true ) . "\n";
		echo "      actual:   " . var_export( $actual, true ) . "\n";
	}
}
function assert_true( $actual, $label ) {
	assert_eq( (bool) $actual, true, $label );
}
function load_json( $path ) {
	return json_decode( (string) file_get_contents( $path ), true );
}

$rulesets_dir = $root . '/admin/modules/geo-routing/rulesets';

echo "\n== FAZ compliance-php backend unit tests ==\n";

// ===========================================================================
// 1. Consent-log category/service decision-map sanitiser
// ===========================================================================
// Faithful replica of the inline block in Controller::log_consent() (private +
// DB-bound). Caps at 250 entries, 190-char keys, values constrained to yes/no,
// non-array input yields an empty map. Returns the cleaned array.
echo "\n-- Consent-log categories sanitiser --\n";

function faz_sanitize_consent_categories( $categories ) {
	$clean = array();
	if ( is_array( $categories ) || is_object( $categories ) ) {
		$count = 0;
		foreach ( (array) $categories as $key => $value ) {
			if ( $count >= 250 ) {
				break;
			}
			$key   = substr( sanitize_text_field( (string) $key ), 0, 190 );
			$value = sanitize_text_field( (string) $value );
			if ( '' === $key || ! in_array( $value, array( 'yes', 'no' ), true ) ) {
				continue;
			}
			$clean[ $key ] = $value;
			++$count;
		}
	}
	return $clean;
}

// Edge: a crafted cookie folds svc.* / ck.* keys with junk values.
$crafted = array(
	'analytics'           => 'yes',
	'marketing'           => 'no',
	'svc.google-analytics' => 'yes',
	'ck.somecookie'       => 'no',
	'svc.evil'            => 'maybe',            // value not in {yes,no} → dropped
	'svc.inject'          => '<script>1</script>', // sanitised → '1' → dropped
	'svc.bool'            => true,               // "1" after cast → not yes/no → dropped
	''                    => 'yes',              // empty key → dropped
	'svc.numeric'         => 0,                  // "0" → dropped
);
$out = faz_sanitize_consent_categories( $crafted );
assert_eq(
	$out,
	array( 'analytics' => 'yes', 'marketing' => 'no', 'svc.google-analytics' => 'yes', 'ck.somecookie' => 'no' ),
	'only {yes,no}-valued svc./ck. keys survive; junk values & empty key dropped'
);

// Edge: 190-char key cap — a 250-char key is truncated to exactly 190.
$long_key = str_repeat( 'a', 250 );
$out      = faz_sanitize_consent_categories( array( $long_key => 'yes' ) );
$keys     = array_keys( $out );
assert_eq( strlen( $keys[0] ), 190, 'over-length key truncated to 190 chars' );
assert_eq( $out[ $keys[0] ], 'yes', 'truncated key keeps its yes value' );

// Edge: 250-entry cap — feed 300 valid entries, expect exactly 250 kept.
$big = array();
for ( $i = 0; $i < 300; $i++ ) {
	$big[ 'k' . $i ] = 'yes';
}
$out = faz_sanitize_consent_categories( $big );
assert_eq( count( $out ), 250, '300 valid entries capped at 250' );
assert_true( isset( $out['k0'] ) && ! isset( $out['k250'] ), 'cap keeps the first 250 in iteration order' );

// Edge: scalar / null input must not fatal and yields an empty decision map.
assert_eq( faz_sanitize_consent_categories( 'all=yes' ), array(), 'scalar string input → empty map' );
assert_eq( faz_sanitize_consent_categories( null ), array(), 'null input → empty map' );
assert_eq( faz_sanitize_consent_categories( 42 ), array(), 'integer input → empty map' );
// Object input is accepted (cast to array) — public props become keys.
$obj = (object) array( 'analytics' => 'yes', 'marketing' => 'sometimes' );
assert_eq( faz_sanitize_consent_categories( $obj ), array( 'analytics' => 'yes' ), 'object input cast to array; invalid value dropped' );

// ===========================================================================
// 2. DSAR privacy exporter / eraser shapes (executed for real)
// ===========================================================================
echo "\n-- DSAR exporter / eraser --\n";

// 2a. Invalid email → short-circuit: empty data, done=true, no query at all.
$GLOBALS['faz_test_cpt_exists'] = true;
$GLOBALS['faz_test_paged_seen'] = array();
$res = faz_privacy_exporter( 'not-an-email', 1 );
assert_true( array_key_exists( 'data', $res ) && array_key_exists( 'done', $res ), 'exporter returns {data,done} shape' );
assert_eq( $res['data'], array(), 'invalid email → no export items' );
assert_eq( $res['done'], true, 'invalid email → done=true (nothing to page)' );
assert_eq( $GLOBALS['faz_test_paged_seen'], array(), 'invalid email → WP_Query never constructed' );

// 2b. Missing CPT → short-circuit even with a valid email.
$GLOBALS['faz_test_cpt_exists'] = false;
$GLOBALS['faz_test_paged_seen'] = array();
$res = faz_privacy_exporter( 'jane@example.com', 1 );
assert_eq( $res['data'], array(), 'valid email but no faz_dsar CPT → no items' );
assert_eq( $res['done'], true, 'no CPT → done=true' );

// 2c. Exporter pages FORWARD and reports done from max_num_pages.
$GLOBALS['faz_test_cpt_exists'] = true;
$GLOBALS['faz_test_max_pages']  = 2;
$GLOBALS['faz_test_batches']    = array( 1 => array( 11, 12 ), 2 => array( 13 ) );
$GLOBALS['faz_test_paged_seen'] = array();
$res = faz_privacy_exporter( 'jane@example.com', 1 );
assert_eq( count( $res['data'] ), 2, 'exporter page 1 yields 2 export items' );
assert_eq( $res['done'], false, 'exporter not done while page 1 < max_num_pages' );
assert_eq( $GLOBALS['faz_test_paged_seen'], array( 1 ), 'exporter page 1 queried paged=1' );
$GLOBALS['faz_test_paged_seen'] = array();
$res = faz_privacy_exporter( 'jane@example.com', 2 );
assert_eq( $GLOBALS['faz_test_paged_seen'], array( 2 ), 'exporter page 2 queries paged=2 (pages forward)' );
assert_eq( $res['done'], true, 'exporter done when current page >= max_num_pages' );
// Export item shape sanity: each item carries group_id + data array.
$item = $res['data'][0];
assert_true( isset( $item['group_id'] ) && isset( $item['item_id'] ) && is_array( $item['data'] ), 'export item has group_id/item_id/data[]' );

// 2d. Eraser shape + ALWAYS reads page 1 (the shrinking-set fix).
$GLOBALS['faz_test_cpt_exists'] = true;
$GLOBALS['faz_test_max_pages']  = 1;
// A full batch (== per_page 20) on page 1 means "not done, call me again".
$full_batch                     = range( 100, 119 ); // 20 ids
$GLOBALS['faz_test_batches']    = array( 1 => $full_batch );
$GLOBALS['faz_test_paged_seen'] = array();
$GLOBALS['faz_test_deleted']    = array();
$res = faz_privacy_eraser( 'jane@example.com', 1 );
assert_true(
	array_key_exists( 'items_removed', $res ) && array_key_exists( 'items_retained', $res )
		&& array_key_exists( 'done', $res ) && array_key_exists( 'messages', $res ),
	'eraser returns {items_removed,items_retained,messages,done} shape'
);
assert_eq( $res['items_removed'], 20, 'eraser removed the full 20-id batch' );
assert_eq( $res['items_retained'], false, 'eraser items_retained=false (nothing retained)' );
assert_eq( $res['done'], false, 'full batch (==per_page) → not done, expects another call' );
assert_eq( $GLOBALS['faz_test_deleted'], $full_batch, 'eraser force-deleted exactly the batch ids' );

// Critical regression: even when WP calls the eraser with page 3, it must
// query paged=1 (because earlier deletions shrank the set under the offset).
$GLOBALS['faz_test_paged_seen'] = array();
$GLOBALS['faz_test_batches']    = array( 1 => array( 200, 201 ) ); // partial batch on page 1
$GLOBALS['faz_test_deleted']    = array();
$res = faz_privacy_eraser( 'jane@example.com', 3 );
assert_eq( $GLOBALS['faz_test_paged_seen'], array( 1 ), 'eraser called with $page=3 STILL queries paged=1' );
assert_eq( $res['items_removed'], 2, 'eraser drained the remaining 2 records' );
assert_eq( $res['done'], true, 'partial batch (<per_page) → done' );

// Eraser with invalid email still returns a well-formed result, removing nothing.
$GLOBALS['faz_test_paged_seen'] = array();
$res = faz_privacy_eraser( 'bogus', 1 );
assert_eq( $res['items_removed'], 0, 'invalid email → eraser removes nothing' );
assert_eq( $res['done'], true, 'invalid email → eraser done=true' );
assert_eq( $GLOBALS['faz_test_paged_seen'], array(), 'invalid email → eraser never queries' );

// ===========================================================================
// 3. Pageview URL minimisation
// ===========================================================================
// Replica of Controller::record_event() minimisation, invoking the REAL
// faz_parse_url() (→ wp_parse_url stub). Asserts query+fragment are dropped.
echo "\n-- Pageview URL minimisation --\n";

function faz_minimise_pageview_url( $url ) {
	$page_url = '';
	$raw_url  = esc_url_raw( (string) $url );
	$parts    = $raw_url ? faz_parse_url( $raw_url ) : false;
	if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
		$scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//';
		$path     = isset( $parts['path'] ) ? $parts['path'] : '';
		$page_url = esc_url_raw( $scheme . $parts['host'] . $path );
	} else {
		$page_url = (string) preg_replace( '/[?#].*$/', '', $raw_url );
	}
	return $page_url;
}

assert_eq(
	faz_minimise_pageview_url( 'https://example.com/account/reset?reset_key=SECRET&email=a@b.com' ),
	'https://example.com/account/reset',
	'absolute URL: query string with token/email stripped'
);
assert_eq(
	faz_minimise_pageview_url( 'https://example.com/page#section-pii' ),
	'https://example.com/page',
	'absolute URL: fragment stripped'
);
assert_eq(
	faz_minimise_pageview_url( 'https://example.com/' ),
	'https://example.com/',
	'absolute URL with no query/fragment passes through (path kept)'
);
assert_eq(
	faz_minimise_pageview_url( '/relative/path?token=xyz#frag' ),
	'/relative/path',
	'relative URL (no host): defensive preg strip of ?…/#… '
);
assert_eq(
	faz_minimise_pageview_url( '' ),
	'',
	'empty URL → empty string (no fatal)'
);
// Edge: a path that itself contains an encoded query-like sequence keeps host+path,
// but the literal `?`/`#` boundary is the cut point.
assert_eq(
	faz_minimise_pageview_url( 'http://h.test/a/b/c?x=1' ),
	'http://h.test/a/b/c',
	'multi-segment path retained, query removed'
);

// ===========================================================================
// 4. DNSMPI category gating predicate (sell/share)
// ===========================================================================
// Mirror of the get_blocked_categories() DNSMPI branch: under a binding
// do-not-sell-or-share opt-out, a category that SELLS or SHARES personal data
// is blocked outright; one that does neither is not blocked by this branch.
echo "\n-- DNSMPI sell/share gating --\n";

function faz_dnsmpi_blocks_category( $dnsmpi_optout, $sells, $shares, $slug ) {
	if ( 'necessary' === $slug ) {
		return false; // necessary is always skipped before the DNSMPI check.
	}
	return $dnsmpi_optout && ( (bool) $sells || (bool) $shares );
}

assert_true( faz_dnsmpi_blocks_category( true, true, false, 'marketing' ), 'opt-out + sells → blocked' );
assert_true( faz_dnsmpi_blocks_category( true, false, true, 'analytics' ), 'opt-out + shares → blocked' );
assert_true( faz_dnsmpi_blocks_category( true, true, true, 'profiling' ), 'opt-out + sells&shares → blocked' );
assert_true( ! faz_dnsmpi_blocks_category( true, false, false, 'functional' ), 'opt-out but neither sells nor shares → NOT blocked' );
assert_true( ! faz_dnsmpi_blocks_category( false, true, true, 'marketing' ), 'no opt-out → not blocked by DNSMPI branch even if sells/shares' );
assert_true( ! faz_dnsmpi_blocks_category( true, true, true, 'necessary' ), 'necessary never blocked (skipped before DNSMPI check)' );

// ===========================================================================
// 5. Geo rulesets touched by the compliance-hardening commit
// ===========================================================================
echo "\n-- Geo rulesets (compliance-hardening set) --\n";

$cmp_rulesets = array(
	'tdpsa-texas',
	'njdpl-newjersey',
	'mcdpa-minnesota',
	'modpa-maryland',
	'nhpl-newhampshire',
	'pipeda-canada',
	'law25-quebec', // genuinely new file added by the same hardening work.
);

foreach ( $cmp_rulesets as $rid ) {
	$path = "$rulesets_dir/$rid.json";
	assert_true( file_exists( $path ), "$rid.json exists" );
	$raw  = file_get_contents( $path );
	$json = json_decode( $raw, true );
	assert_true( JSON_ERROR_NONE === json_last_error() && is_array( $json ), "$rid.json is valid JSON" );
	if ( ! is_array( $json ) ) {
		continue;
	}
	// Structural integrity required by the resolver / runtime consumers.
	assert_eq( $json['id'] ?? null, $rid, "$rid.json id matches filename" );
	assert_true( isset( $json['signals'] ) && is_array( $json['signals'] ), "$rid.json has signals{}" );
	assert_true( isset( $json['ui']['default_categories'] ) && is_array( $json['ui']['default_categories'] ), "$rid.json has ui.default_categories{}" );
	assert_true(
		array_key_exists( 'gpc_honored', $json['signals'] ) && is_bool( $json['signals']['gpc_honored'] ),
		"$rid.json signals.gpc_honored is a bool"
	);
	// necessary must be locked-on in every ruleset (cannot be denied).
	$nec = $json['ui']['default_categories']['necessary'] ?? null;
	assert_true(
		in_array( $nec, array( 'granted-locked', 'granted' ), true ),
		"$rid.json necessary category is granted/granted-locked (never denied)"
	);
}

// The 5 US universal-opt-out states in this set must honour GPC (UOOM mandate).
foreach ( array( 'tdpsa-texas', 'njdpl-newjersey', 'mcdpa-minnesota', 'modpa-maryland', 'nhpl-newhampshire' ) as $rid ) {
	$json = load_json( "$rulesets_dir/$rid.json" );
	assert_eq( $json['signals']['gpc_honored'] ?? null, true, "$rid honours GPC (UOOM state)" );
}

// PIPEDA-Canada must be the hybrid model with opt-in marketing/profiling.
$pipeda = load_json( "$rulesets_dir/pipeda-canada.json" );
assert_eq( $pipeda['model'] ?? null, 'hybrid', 'pipeda-canada model = hybrid' );
assert_eq( $pipeda['ui']['default_categories']['marketing'] ?? null, 'denied-until-action', 'pipeda-canada marketing denied-until-action (express opt-in)' );

// law25-quebec routes the CA-QC region and is the strictest Canadian ruleset.
$qc = load_json( "$rulesets_dir/law25-quebec.json" );
assert_true( in_array( 'CA-QC', $qc['applies_to']['regions'] ?? array(), true ), 'law25-quebec applies_to regions includes CA-QC' );
assert_eq( $qc['ui']['default_categories']['profiling'] ?? null, 'denied-until-action', 'law25-quebec profiling opt-in' );

// ---------- Summary ----------

echo "\n";
echo "Tests run:    $tests_run\n";
echo "\033[32mPassed:       $tests_passed\033[0m\n";
if ( $tests_failed > 0 ) {
	echo "\033[31mFailed:       $tests_failed\033[0m\n";
	exit( 1 );
}
echo "\033[32mAll compliance-php backend unit tests passed.\033[0m\n";
exit( 0 );
