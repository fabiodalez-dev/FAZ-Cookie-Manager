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
 *   2. DSAR privacy exporter / eraser behaviour (faz_privacy_exporter /
 *      faz_privacy_eraser in includes/class-utils.php) — executed for real
 *      against a stubbed WP_Query and a real in-memory meta store:
 *        - exporter returns {data:[], done:bool} and pages FORWARD, and skips
 *          records whose erasure was already honoured (no `_dsar_email`),
 *        - eraser REDACTS each matching record in place instead of deleting it:
 *          name, message and address gone, `_dsar_status = erased` and
 *          `_dsar_erased_at` written, type / core-request ids / date kept, the
 *          requester's name stripped from post_title, NO hash of the address
 *          stored anywhere, and {items_removed>0, items_retained:true},
 *        - eraser always reads page 1 (never the incremented $page), and the
 *          match set still shrinks because `_dsar_email` is DELETED — pinned
 *          with a 25-record batch that must terminate in two passes, since a
 *          retention implemented as a skip would hang core's AJAX loop,
 *        - a second erasure for the same address is a no-op (idempotence),
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
$GLOBALS['faz_test_meta']       = array(); // post_id => array( meta_key => value ) — a real store.
$GLOBALS['faz_test_titles']     = array(); // post_id => post_title, seeded + rewritten by wp_update_post.
$GLOBALS['faz_test_slugs']      = array(); // post_id => post_name, derived from the title like wp_insert_post().
$GLOBALS['faz_test_live_match'] = false;   // WP_Query reads the meta store instead of fixed batches.

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $t ) { return (bool) $GLOBALS['faz_test_cpt_exists']; } // phpcs:ignore
}
// A real meta store, not a synthetic `$key#$id` string: the eraser now DELETES
// three keys and WRITES two, and the whole point of the fix is which keys are
// left afterwards — a stub that answers every read cannot show that.
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) { // phpcs:ignore
		$id = (int) $id;
		return isset( $GLOBALS['faz_test_meta'][ $id ][ $key ] )
			? $GLOBALS['faz_test_meta'][ $id ][ $key ]
			: '';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) { // phpcs:ignore
		$GLOBALS['faz_test_meta'][ (int) $id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $id, $key ) { // phpcs:ignore
		unset( $GLOBALS['faz_test_meta'][ (int) $id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $field, $id ) { // phpcs:ignore
		if ( 'post_title' === $field ) {
			return isset( $GLOBALS['faz_test_titles'][ (int) $id ] ) ? $GLOBALS['faz_test_titles'][ (int) $id ] : '';
		}
		return '2026-06-16 00:00:00';
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $args, $wp_error = false ) { // phpcs:ignore
		$id = isset( $args['ID'] ) ? (int) $args['ID'] : 0;
		if ( $id && isset( $args['post_title'] ) ) {
			$GLOBALS['faz_test_titles'][ $id ] = (string) $args['post_title'];
			// Mirror wp_insert_post(): an EMPTY post_name on a non-draft status
			// is regenerated from the (new) post_title, so blanking it is how
			// the old slug — which carried the requester's name — is dropped.
			$GLOBALS['faz_test_slugs'][ $id ] = array_key_exists( 'post_name', $args ) && '' === $args['post_name']
				? strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', (string) $args['post_title'] ), '-' ) )
				: ( isset( $GLOBALS['faz_test_slugs'][ $id ] ) ? $GLOBALS['faz_test_slugs'][ $id ] : '' );
		}
		return $id;
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? '2026-06-20 09:30:00' : time(); } // phpcs:ignore
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $id, $force = false ) { // phpcs:ignore
		$GLOBALS['faz_test_deleted'][] = (int) $id;
		unset( $GLOBALS['faz_test_meta'][ (int) $id ], $GLOBALS['faz_test_titles'][ (int) $id ], $GLOBALS['faz_test_slugs'][ (int) $id ] );
		return true; // deletion "succeeds".
	}
}
/**
 * Seed one stored DSAR record exactly as DSAR_Shortcode::store_request() writes
 * it: a title carrying the requester's NAME, and the five meta keys.
 */
function faz_test_seed_dsar( $id, $name, $email, $type = 'erasure', $message = 'please erase me', $status = 'pending' ) {
	$GLOBALS['faz_test_meta'][ (int) $id ] = array(
		'_dsar_name'    => $name,
		'_dsar_email'   => $email,
		'_dsar_type'    => $type,
		'_dsar_message' => $message,
		'_dsar_status'  => $status,
	);
	$GLOBALS['faz_test_titles'][ (int) $id ] = sprintf( '[%s] %s — %s', ucfirst( $type ), $name, '2026-06-16' );
	// store_request() never sets post_name, and `private` is not a status
	// wp_insert_post() skips slug generation for — so WordPress derives the slug
	// from that title, requester's name included.
	$GLOBALS['faz_test_slugs'][ (int) $id ] = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $GLOBALS['faz_test_titles'][ (int) $id ] ), '-' ) );
}
if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Stub WP_Query. Two modes:
	 *
	 *  - fixed batches: returns $GLOBALS['faz_test_batches'] keyed by the `paged`
	 *    value asked for (default: empty), and records every `paged` seen so the
	 *    suite can assert the eraser always reads page 1;
	 *  - live match ($GLOBALS['faz_test_live_match']): resolves the meta_query
	 *    against the real meta store above. This is what makes the drain
	 *    assertion meaningful — a record the eraser redacted has no `_dsar_email`
	 *    left, so it drops out of the match set here exactly as it would in the
	 *    database, and a "fix" that left the key in place would loop forever.
	 */
	class WP_Query { // phpcs:ignore
		public $posts;
		public $max_num_pages;
		public function __construct( $args ) {
			$paged = isset( $args['paged'] ) ? (int) $args['paged'] : 1;
			$GLOBALS['faz_test_paged_seen'][] = $paged;
			$per = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 20;

			if ( ! empty( $GLOBALS['faz_test_live_match'] ) && isset( $args['meta_query'][0]['key'] ) ) {
				$key     = $args['meta_query'][0]['key'];
				$value   = isset( $args['meta_query'][0]['value'] ) ? $args['meta_query'][0]['value'] : '';
				$matches = array();
				foreach ( $GLOBALS['faz_test_meta'] as $id => $meta ) {
					if ( isset( $meta[ $key ] ) && $meta[ $key ] === $value ) {
						$matches[] = (int) $id;
					}
				}
				sort( $matches );
				$this->posts         = array_slice( $matches, ( $paged - 1 ) * $per, $per );
				$this->max_num_pages = $per > 0 ? (int) ceil( count( $matches ) / $per ) : 1;
				return;
			}

			$batches             = isset( $GLOBALS['faz_test_batches'] ) ? $GLOBALS['faz_test_batches'] : array();
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
$GLOBALS['faz_test_meta']       = array();
$GLOBALS['faz_test_titles']     = array();
$GLOBALS['faz_test_slugs']      = array();
faz_test_seed_dsar( 11, 'Jane Doe', 'jane@example.com', 'erasure' );
faz_test_seed_dsar( 12, 'Jane Doe', 'jane@example.com', 'access' );
faz_test_seed_dsar( 13, 'Jane Doe', 'jane@example.com', 'portability' );
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

// 2c-bis. A record whose erasure was already honoured carries no `_dsar_email`.
// It cannot match the exporter's meta_query, but the exporter must skip it
// anyway: the retained accountability record must never become a new export
// surface, and the export must never carry anything derived from the address.
$GLOBALS['faz_test_max_pages'] = 1;
$GLOBALS['faz_test_batches']   = array( 1 => array( 11, 14 ) );
$GLOBALS['faz_test_meta'][14]  = array(
	'_dsar_type'      => 'erasure',
	'_dsar_status'    => 'erased',
	'_dsar_erased_at' => '2026-06-20 09:30:00',
);
$res = faz_privacy_exporter( 'jane@example.com', 1 );
assert_eq( count( $res['data'] ), 1, 'exporter skips the redacted record and exports only the live one' );
assert_eq( $res['data'][0]['item_id'], 'faz-dsar-11', 'the exported item is the record that still has an address' );
$flat = wp_json_encode( $res['data'] );
assert_true( false === strpos( $flat, 'erased' ), 'nothing from the redacted record leaks into the export' );

// 2d. Eraser REDACTS in place and ALWAYS reads page 1 (the shrinking-set fix).
//
// The previous shape of this block pinned the opposite behaviour — it asserted
// that every matching id was force-deleted. That is the bug: honouring an
// erasure destroyed the plugin's own evidence that the request was ever made
// (GDPR Art. 5(2) / Art. 12(3)), which is exactly what the admin screen tells
// the administrator the record is for. The personal data must go; the dated,
// non-identifying trace must stay, and `items_retained` must say so.
$GLOBALS['faz_test_cpt_exists'] = true;
$GLOBALS['faz_test_max_pages']  = 1;
$GLOBALS['faz_test_live_match'] = false;
$GLOBALS['faz_test_meta']       = array();
$GLOBALS['faz_test_titles']     = array();
$GLOBALS['faz_test_slugs']      = array();
// A full batch (== per_page 20) on page 1 means "not done, call me again".
$full_batch = range( 100, 119 ); // 20 ids
foreach ( $full_batch as $i => $id ) {
	// Mixed statuses: 18 still awaiting an answer, 2 already completed.
	faz_test_seed_dsar( $id, 'Jane Doe', 'jane@example.com', 0 === $i % 2 ? 'erasure' : 'access', 'please erase me', $i < 2 ? 'completed' : 'pending' );
}
// One of them was bridged into a core privacy request: the link to core's own
// record is part of the evidence and must survive the redaction.
$GLOBALS['faz_test_meta'][100]['_dsar_core_request_id']     = 77;
$GLOBALS['faz_test_meta'][100]['_dsar_core_request_action'] = 'remove_personal_data';
$GLOBALS['faz_test_batches']    = array( 1 => $full_batch );
$GLOBALS['faz_test_paged_seen'] = array();
$GLOBALS['faz_test_deleted']    = array();
$res = faz_privacy_eraser( 'jane@example.com', 1 );
assert_true(
	array_key_exists( 'items_removed', $res ) && array_key_exists( 'items_retained', $res )
		&& array_key_exists( 'done', $res ) && array_key_exists( 'messages', $res ),
	'eraser returns {items_removed,items_retained,messages,done} shape'
);
assert_eq( $res['items_removed'], 20, 'eraser removed the personal data of the full 20-id batch' );
assert_eq( $res['items_retained'], true, 'eraser items_retained=true (a non-identifying record is kept)' );
assert_eq( $res['done'], false, 'full batch (==per_page) → not done, expects another call' );
assert_eq( $GLOBALS['faz_test_deleted'], array(), 'eraser force-deleted NOTHING: the audit trail survives' );

// What is left of one record, key by key.
$left = $GLOBALS['faz_test_meta'][100];
assert_true( ! isset( $left['_dsar_name'] ), '_dsar_name is gone' );
assert_true( ! isset( $left['_dsar_message'] ), '_dsar_message is gone' );
assert_true( ! isset( $left['_dsar_email'] ), '_dsar_email is gone — and that is what shrinks the match set' );
assert_eq( $left['_dsar_status'], 'erased', '_dsar_status is erased' );
assert_eq( $left['_dsar_erased_at'], '2026-06-20 09:30:00', '_dsar_erased_at records when (UTC)' );
assert_eq( $left['_dsar_type'], 'erasure', '_dsar_type survives: which right was invoked' );
assert_eq( $left['_dsar_core_request_id'], 77, '_dsar_core_request_id survives: the link to core\'s own record' );
assert_eq( $left['_dsar_core_request_action'], 'remove_personal_data', '_dsar_core_request_action survives' );

// NOT ONE derived value of the address anywhere — no `_dsar_email_hash`, no
// HMAC under any other key. A salted but deterministic hash of an email is
// still pseudonymous personal data, re-identifiable by anyone holding the salt
// and a candidate address, so keeping one would contradict this erasure.
$all_keys   = array();
$all_values = array();
foreach ( $GLOBALS['faz_test_meta'] as $meta ) {
	$all_keys   = array_merge( $all_keys, array_keys( $meta ) );
	$all_values = array_merge( $all_values, array_map( 'strval', array_values( $meta ) ) );
}
$all_keys = array_unique( $all_keys );
assert_true( ! in_array( '_dsar_email_hash', $all_keys, true ), 'no _dsar_email_hash was written' );
$hash_keys = array_values( array_filter( $all_keys, function ( $k ) { return false !== stripos( $k, 'hash' ); } ) );
assert_eq( $hash_keys, array(), 'no meta key with "hash" in its name exists at all' );
$blob = implode( '|', $all_values ) . '|' . implode( '|', $GLOBALS['faz_test_titles'] ) . '|' . implode( '|', $GLOBALS['faz_test_slugs'] );
assert_true( false === stripos( $blob, 'jane' ), 'neither the address nor the name survives in any meta value, title or slug' );
assert_true( false === stripos( $blob, hash( 'sha256', 'jane@example.com' ) ), 'no plain sha256 of the address survives either' );

// The title kept the right invoked and the date, and lost the requester's name.
$title = $GLOBALS['faz_test_titles'][100];
assert_true( false === strpos( $title, 'Jane Doe' ), "post_title no longer carries the requester's name" );
assert_true( false !== strpos( $title, '2026-06-16' ), 'post_title keeps the submission date' );
assert_true( false !== strpos( $title, 'erasure' ), 'post_title keeps the type label' );

// And the slug with it: store_request() lets WordPress derive post_name from
// that title, so an "erasure" that only rewrote post_title would have left
// `erasure-jane-doe-2026-06-16` in wp_posts.
assert_true( false === strpos( $GLOBALS['faz_test_slugs'][100], 'jane-doe' ), 'post_name no longer carries the name either' );
assert_eq( $GLOBALS['faz_test_slugs'][100], 'erasure-2026-06-16', 'post_name was regenerated from the redacted title' );

// Core must be told both numbers, and told that unanswered requests were caught
// in the sweep: the eraser matches the ADDRESS, not one request, so an access
// request still waiting for a reply is redacted along with the erasure.
$joined = implode( ' ', $res['messages'] );
assert_true( false !== strpos( $joined, 'Consent history is located using consent IDs' ), 'the eraser explains the consent-ID lookup' );
assert_true( false !== strpos( $joined, 'Erased the personal data in 20' ), 'the message names how many records were redacted' );
assert_true( false !== strpos( $joined, '18 of those requests had not been answered yet' ), 'the message names how many were still unanswered' );
assert_true( false !== strpos( $joined, 'Art. 5(2)' ), 'the message states WHY a record is kept' );

// 2d-bis. Idempotence: a second erasure for the same address finds nothing,
// because the redacted records no longer carry `_dsar_email`. Nothing is
// resurrected, nothing is re-stamped, nothing is counted twice.
$GLOBALS['faz_test_live_match'] = true;
$GLOBALS['faz_test_paged_seen'] = array();
$before = $GLOBALS['faz_test_meta'];
$res    = faz_privacy_eraser( 'jane@example.com', 1 );
assert_eq( $res['items_removed'], 0, 'a second erasure removes nothing (idempotent)' );
assert_eq( $res['items_retained'], false, 'a second erasure retains nothing new' );
assert_eq( $res['done'], true, 'a second erasure is done immediately' );
assert_eq( $GLOBALS['faz_test_meta'], $before, 'a second erasure leaves every record byte-identical' );
assert_eq( $GLOBALS['faz_test_deleted'], array(), 'and still deletes nothing' );
$GLOBALS['faz_test_live_match'] = false;

// Critical regression: even when WP calls the eraser with page 3, it must
// query paged=1 (because each pass removes records from the match set under the
// offset — now by dropping `_dsar_email`, before by deleting the post).
$GLOBALS['faz_test_paged_seen'] = array();
$GLOBALS['faz_test_meta']       = array();
$GLOBALS['faz_test_titles']     = array();
$GLOBALS['faz_test_slugs']      = array();
faz_test_seed_dsar( 200, 'Jane Doe', 'jane@example.com' );
faz_test_seed_dsar( 201, 'Jane Doe', 'jane@example.com' );
$GLOBALS['faz_test_batches'] = array( 1 => array( 200, 201 ) ); // partial batch on page 1
$GLOBALS['faz_test_deleted'] = array();
$res = faz_privacy_eraser( 'jane@example.com', 3 );
assert_eq( $GLOBALS['faz_test_paged_seen'], array( 1 ), 'eraser called with $page=3 STILL queries paged=1' );
assert_eq( $res['items_removed'], 2, 'eraser drained the remaining 2 records' );
assert_eq( $res['done'], true, 'partial batch (<per_page) → done' );

// 2d-ter. TERMINATION, 25 records for one address, against a LIVE match set.
//
// This is the property that must not regress. Core drives the eraser in a loop
// and stops only when `done` comes back true; `done` is `$found < $per_page`,
// and `$found` only falls because each pass takes records OUT of the
// `_dsar_email = $email` set. Implementing the retention as a skip or an
// exemption that left `_dsar_email` in place would pin `$found` at 20 for ever
// and hang the administrator's erase screen on an unbounded AJAX loop — worse
// than the bug this fix addresses. The loop below is capped so that failure
// shows up as a failed assertion instead of a hanging test run.
$GLOBALS['faz_test_live_match'] = true;
$GLOBALS['faz_test_meta']       = array();
$GLOBALS['faz_test_titles']     = array();
$GLOBALS['faz_test_slugs']      = array();
$GLOBALS['faz_test_deleted']    = array();
$GLOBALS['faz_test_paged_seen'] = array();
for ( $i = 0; $i < 25; $i++ ) {
	faz_test_seed_dsar( 300 + $i, 'Jane Doe', 'jane@example.com', 'erasure', 'please erase me', $i < 5 ? 'completed' : 'pending' );
}
$passes        = 0;
$total_removed = 0;
$page          = 1;
do {
	$res            = faz_privacy_eraser( 'jane@example.com', $page );
	$total_removed += (int) $res['items_removed'];
	++$passes;
	++$page;
} while ( ! $res['done'] && $passes < 10 );

assert_true( $res['done'], '25 records / 20 per page: the batch TERMINATES (done=true)' );
assert_eq( $passes, 2, 'it terminates in exactly two passes, not by hitting the safety cap' );
assert_eq( $total_removed, 25, 'all 25 records had their personal data removed' );
assert_eq( $GLOBALS['faz_test_deleted'], array(), 'no record was force-deleted along the way' );
assert_eq( $GLOBALS['faz_test_paged_seen'], array( 1, 1 ), 'both passes read page 1' );
$still_matching = 0;
$still_erased   = 0;
foreach ( $GLOBALS['faz_test_meta'] as $meta ) {
	if ( isset( $meta['_dsar_email'] ) ) {
		++$still_matching;
	}
	if ( isset( $meta['_dsar_status'] ) && 'erased' === $meta['_dsar_status'] ) {
		++$still_erased;
	}
}
assert_eq( count( $GLOBALS['faz_test_meta'] ), 25, 'all 25 records still exist after the erasure' );
assert_eq( $still_matching, 0, 'none of them is in the match set any more (the drain invariant)' );
assert_eq( $still_erased, 25, 'all 25 read as erased' );
$GLOBALS['faz_test_live_match'] = false;

// 2d-quater. `post_content` and the excerpt need no redaction because nothing
// ever writes them: store_request() passes post_type, post_status, post_title
// and meta_input, and nothing else. Pinned structurally — if a future change
// starts storing the requester's words in post_content, the eraser above would
// leave them behind and this assertion is the warning.
$store_src = (string) file_get_contents( $root . '/includes/class-dsar-shortcode.php' );
$offset    = strpos( $store_src, 'private function store_request' );
$end       = strpos( $store_src, 'private function notify_admin' );
$block     = ( false === $offset || false === $end || $end <= $offset ) ? '' : substr( $store_src, $offset, $end - $offset );
assert_true( '' !== $block, 'store_request() was found in the shortcode class' );
assert_true( false === strpos( $block, 'post_content' ), 'store_request() never writes post_content' );
assert_true( false === strpos( $block, 'post_excerpt' ), 'store_request() never writes post_excerpt' );

// Eraser with invalid email still returns a well-formed result, removing nothing.
$GLOBALS['faz_test_paged_seen'] = array();
$res = faz_privacy_eraser( 'bogus', 1 );
assert_eq( $res['items_removed'], 0, 'invalid email → eraser removes nothing' );
assert_eq( $res['items_retained'], false, 'invalid email → nothing retained either' );
assert_eq( $res['done'], true, 'invalid email → eraser done=true' );
assert_eq( $GLOBALS['faz_test_paged_seen'], array(), 'invalid email → eraser never queries' );

// …and so does a valid address on a site where the CPT is not registered.
$GLOBALS['faz_test_cpt_exists'] = false;
$GLOBALS['faz_test_paged_seen'] = array();
$res = faz_privacy_eraser( 'jane@example.com', 1 );
assert_eq( $res['items_removed'], 0, 'no faz_dsar CPT → eraser removes nothing' );
assert_eq( $res['items_retained'], false, 'no faz_dsar CPT → nothing retained' );
assert_eq( $res['done'], true, 'no faz_dsar CPT → eraser done=true' );
assert_eq( $GLOBALS['faz_test_paged_seen'], array(), 'no faz_dsar CPT → eraser never queries' );
$GLOBALS['faz_test_cpt_exists'] = true;

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
