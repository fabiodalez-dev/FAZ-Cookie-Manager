<?php
/**
 * The visitor-check ledger: what the anonymous replay may and may not do.
 *
 * After every browser scan import, the background header replay re-fetches the
 * visited URLs with an empty jar — anonymous by construction. This suite pins
 * the bookkeeping built on top of it: the per-scan ledger, the three-bucket
 * diff, and above all the safety rule the whole feature rests on —
 *
 *   ANONYMOUS PRESENCE MAY PROMOTE. ANONYMOUS ABSENCE MAY NEVER DEMOTE.
 *
 * A page cache can suppress a Set-Cookie; it cannot invent one. So the diff
 * must never feed the consecutive-miss tally (MISSED_SCANS_OPTION), never call
 * record_scan_observations(), and never remove a catalogue row. The central
 * assertion here is behavioural, not a grep: finalize runs against a populated
 * tally and the tally must come out byte-identical.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'YEAR_IN_SECONDS', 31536000 );

$GLOBALS['faz_test_options'] = array();

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code, $message, $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}

function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function __( $value, ...$unused ) { return $value; }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $url ) { return filter_var( $url, FILTER_SANITIZE_URL ); }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function is_ssl() { return true; }
function get_current_user_id() { return 7; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl = 0 ) { return true; }
function delete_transient( $key ) {}
function wp_generate_uuid4() { return '12345678-1234-1234-1234-123456789abc'; }
function current_time( $type ) { return '2026-08-22 12:00:00'; }

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['faz_test_options'] ) ? $GLOBALS['faz_test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['faz_test_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) { unset( $GLOBALS['faz_test_options'][ $key ] ); return true; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $key, $GLOBALS['faz_test_options'] ) ) {
		return false;
	}
	$GLOBALS['faz_test_options'][ $key ] = $value;
	return true;
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';

use FazCookie\Admin\Modules\Scanner\Includes\Controller;

$passed = 0;
$failed = 0;
function vc_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		++$passed;
		echo "  [PASS] {$label}\n";
		return;
	}
	++$failed;
	echo "  [FAIL] {$label}\n";
}

function vc_reset() {
	$GLOBALS['faz_test_options'] = array();
}

$controller = Controller::get_instance();

echo "== Ledger lifecycle ==\n";

// ── begin_visitor_check persists the classification and opens the target ──
vc_reset();
$controller->begin_visitor_check(
	42,
	array(
		array( 'name' => '_ga', 'domain' => '.Example.test:9998' ), // messy domain on purpose.
		array( 'name' => 'session_x' ),                             // no domain → site host.
	),
	array( '_ga', '_GID', 'session_x' ),                            // merged persisted names, mixed case.
	array(
		array( 'name' => 'wordpress_logged_in_abc' ),
		array( 'name' => 'faz_jar_promote', 'domain' => 'example.test' ),
	)
);
$stored = get_option( Controller::VISITOR_CHECK_OPTION, array() );
vc_ok( isset( $stored['42'] ) && 'pending' === $stored['42']['status'], 'begin opens a pending ledger keyed by the numeric scan id' );
vc_ok( 42 === absint( get_option( Controller::VISITOR_CHECK_TARGET_OPTION, 0 ) ), 'begin points the replay target at that scan id' );
vc_ok(
	in_array( '_ga|example.test', $stored['42']['imported_keys'], true ),
	'imported keys are canonical: lowercased, leading dot and :port stripped'
);
vc_ok(
	in_array( 'session_x|example.test', $stored['42']['imported_keys'], true ),
	'an imported cookie without a domain is keyed on the site host, matching the replay side'
);
vc_ok(
	in_array( '_gid', $stored['42']['imported_names'], true ),
	'imported names are canonicalised through canonical_name()'
);
vc_ok(
	isset( $stored['42']['jar']['wordpress_logged_in_abc|example.test'] ),
	'a jar entry without a domain defaults to the site host too'
);

// ── a REAL scan id opens the ledger ───────────────────────────────────────
// start_browser_scan_session() enforces /^[a-f0-9]{32}$/, so every id that
// reaches this method in production is a hex STRING. The ledger keyed it with
// absint(), which is 0 for all of them, and the guard below then returned
// before writing a byte — the anonymous pass measured nothing on every
// install, silently, and every assertion in this file passed because they all
// used integer ids the real flow never produces. Pin the real shape.
vc_reset();
$hex_id = str_repeat( 'a1b2c3d4', 4 ); // 32 chars, matches the session regex
$controller->begin_visitor_check( $hex_id, array(), array( 'shop_session' ), array( array( 'name' => 'tk_ai' ) ) );
$hex_stored = get_option( Controller::VISITOR_CHECK_OPTION, array() );
vc_ok( isset( $hex_stored[ $hex_id ] ), 'a 32-char hex scan id opens a ledger keyed on itself' );
vc_ok(
	isset( $hex_stored[ $hex_id ]['status'] ) && 'pending' === $hex_stored[ $hex_id ]['status'],
	'that ledger is pending, so the replay worker will fill it'
);
vc_ok(
	$hex_id === get_option( Controller::VISITOR_CHECK_TARGET_OPTION, null ),
	'the target points at the hex id, so observations land in the right ledger'
);

// ── zero/invalid scan id refuses to open anything ─────────────────────────
vc_reset();
$controller->begin_visitor_check( 0, array(), array(), array() );
vc_ok( array() === $GLOBALS['faz_test_options'], 'scan id 0 writes nothing at all' );

echo "== Observations: append-only, positive-only ==\n";

vc_reset();
$controller->begin_visitor_check( 7, array(), array(), array() );
$controller->record_visitor_observations(
	array(
		array( 'name' => 'anon_only', 'domain' => '.example.test' ),
		array( 'name' => 'anon_only', 'domain' => 'example.test' ), // canonical duplicate.
		array( 'name' => '' ),                                       // nameless → ignored.
		'not-an-array',                                              // malformed → ignored.
	)
);
$stored = get_option( Controller::VISITOR_CHECK_OPTION, array() );
vc_ok(
	array( 'anon_only|example.test' => 'anon_only' ) === $stored['7']['observed'],
	'observations dedupe on the canonical key and drop malformed rows'
);

// With no open target the recorder must be inert.
vc_reset();
$controller->record_visitor_observations( array( array( 'name' => 'x' ) ) );
vc_ok( array() === $GLOBALS['faz_test_options'], 'recording without an open ledger writes nothing' );

// A non-pending ledger is closed to further writes.
vc_reset();
$controller->begin_visitor_check( 7, array(), array(), array() );
$checks                = get_option( Controller::VISITOR_CHECK_OPTION );
$checks['7']['status'] = 'complete';
update_option( Controller::VISITOR_CHECK_OPTION, $checks );
$controller->record_visitor_observations( array( array( 'name' => 'late' ) ) );
$stored = get_option( Controller::VISITOR_CHECK_OPTION );
vc_ok( array() === $stored['7']['observed'], 'a completed ledger rejects late observations' );

// The ledger is bounded by BROWSER_SCAN_OBSERVATION_LIMIT.
vc_reset();
$controller->begin_visitor_check( 7, array(), array(), array() );
$bulk = array();
for ( $i = 0; $i < Controller::BROWSER_SCAN_OBSERVATION_LIMIT + 25; $i++ ) {
	$bulk[] = array( 'name' => 'c' . $i, 'domain' => 'example.test' );
}
$controller->record_visitor_observations( $bulk );
$stored = get_option( Controller::VISITOR_CHECK_OPTION );
vc_ok(
	Controller::BROWSER_SCAN_OBSERVATION_LIMIT === count( $stored['7']['observed'] ),
	'the observation ledger is hard-capped at BROWSER_SCAN_OBSERVATION_LIMIT'
);

echo "== The three-bucket diff ==\n";

vc_reset();
$controller->begin_visitor_check(
	42,
	array( array( 'name' => '_ga', 'domain' => 'example.test' ) ),
	array( '_ga', '_gid' ), // _gid exists only as a merged inferred name.
	array(
		array( 'name' => 'wordpress_logged_in_abc' ),
		array( 'name' => 'faz_jar_promote' ),
	)
);
$controller->record_visitor_observations(
	array(
		array( 'name' => 'anon_only', 'domain' => 'example.test' ),        // visitor-only.
		array( 'name' => 'faz_jar_promote', 'domain' => 'example.test' ),  // jar name, observed → promoted.
		array( 'name' => '_ga', 'domain' => 'example.test' ),              // imported → no diff.
		array( 'name' => '_gid', 'domain' => 'other.example' ),            // imported by NAME (inferred row) → no diff.
	)
);
$diff = $controller->finalize_visitor_check();
vc_ok( is_array( $diff ) && 'complete' === $diff['status'], 'finalize completes the open ledger' );
vc_ok( array( 'anon_only' ) === $diff['diff']['visitor_only'], 'visitor-only: observed anonymously, never imported under key OR name' );
vc_ok( array( 'faz_jar_promote' ) === $diff['diff']['jar_promoted'], 'jar promotion: the jar name the anonymous pass actually observed being set' );
vc_ok( array( 'wordpress_logged_in_abc' ) === $diff['diff']['admin_only'], 'admin-only: jar name the anonymous pass never saw — reported, unchanged' );
vc_ok( ! array_key_exists( Controller::VISITOR_CHECK_TARGET_OPTION, $GLOBALS['faz_test_options'] ), 'finalize closes the replay target' );
$stored = get_option( Controller::VISITOR_CHECK_OPTION );
vc_ok(
	! isset( $stored['42']['observed'] ) && ! isset( $stored['42']['imported_keys'] ) && ! isset( $stored['42']['jar'] ),
	'finalize drops the working sets and keeps only the diff'
);

// Finalizing twice, or with no target, is a no-op.
vc_ok( null === $controller->finalize_visitor_check(), 'a second finalize finds no open target and returns null' );

// ── the replay worker must not close a ledger it never filled ─────────────
// run_httponly_check() captures the target when it starts and hands it back
// here. An import that lands mid-drain opens a NEW ledger; without this guard
// the draining worker would freeze that fresh ledger as complete with zero
// observations, and the worker that was meant to fill it would then find the
// target already deleted and record nothing. One scan loses its whole check,
// with no error anywhere.
vc_reset();
$controller->begin_visitor_check( str_repeat( 'f0', 16 ), array(), array( 'shop_session' ), array() );
$race_id = str_repeat( 'f0', 16 );
vc_ok(
	null === $controller->finalize_visitor_check( str_repeat( 'ee', 16 ) ),
	'a worker holding a STALE target closes nothing'
);
$race_stored = get_option( Controller::VISITOR_CHECK_OPTION, array() );
vc_ok(
	isset( $race_stored[ $race_id ]['status'] ) && 'pending' === $race_stored[ $race_id ]['status'],
	'the newly opened ledger is left pending for its own worker'
);
vc_ok(
	$race_id === get_option( Controller::VISITOR_CHECK_TARGET_OPTION, null ),
	'and the target survives, so that worker can still find it'
);
vc_ok(
	is_array( $controller->finalize_visitor_check( $race_id ) ),
	'the worker holding the MATCHING target does close it'
);

echo "== THE safety rule: anonymous absence never demotes ==\n";

// The tally that deletion offers are built from. If the visitor check ever
// feeds it, a cached site starts demoting live cookies. Populate it, run the
// entire ledger lifecycle around an admin-only (i.e. anonymously ABSENT)
// cookie, and require the tally byte-identical afterwards.
vc_reset();
$tally_before = array(
	'wordpress_logged_in_abc|example.test' => 1,
	'anon_only|example.test'               => 2,
);
update_option( Controller::MISSED_SCANS_OPTION, $tally_before );
$controller->begin_visitor_check(
	43,
	array( array( 'name' => '_ga', 'domain' => 'example.test' ) ),
	array( '_ga' ),
	array( array( 'name' => 'wordpress_logged_in_abc' ) ) // will NOT be observed anonymously.
);
$controller->record_visitor_observations( array( array( 'name' => 'anon_only', 'domain' => 'example.test' ) ) );
$diff        = $controller->finalize_visitor_check();
$tally_after = get_option( Controller::MISSED_SCANS_OPTION );
vc_ok(
	serialize( $tally_before ) === serialize( $tally_after ),
	'SAFETY: the missed-scan tally is byte-identical across begin → record → finalize'
);
vc_ok(
	array( 'wordpress_logged_in_abc' ) === $diff['diff']['admin_only'],
	'SAFETY: the anonymously absent jar cookie is bucketed admin-only, nothing more'
);
// And the option surface as a whole: the lifecycle may only ever touch its own
// two options. Anything else appearing here is a demotion channel.
$touched = array_diff(
	array_keys( $GLOBALS['faz_test_options'] ),
	array( Controller::MISSED_SCANS_OPTION, Controller::VISITOR_CHECK_OPTION, Controller::VISITOR_CHECK_TARGET_OPTION )
);
vc_ok( array() === $touched, 'SAFETY: no option beyond the ledger pair (and the untouched tally) was written' );

echo "== Pruning and superseding ==\n";

vc_reset();
for ( $i = 1; $i <= Controller::VISITOR_CHECK_HISTORY_LIMIT + 5; $i++ ) {
	$controller->begin_visitor_check( $i, array(), array(), array() );
}
$stored = get_option( Controller::VISITOR_CHECK_OPTION );
vc_ok( Controller::VISITOR_CHECK_HISTORY_LIMIT === count( $stored ), 'the ledger option is pruned like faz_scan_history (newest 50 win)' );
vc_ok( ! isset( $stored['1'] ) && isset( $stored[ (string) ( Controller::VISITOR_CHECK_HISTORY_LIMIT + 5 ) ] ), 'the oldest entries are the ones dropped' );
vc_ok( 'superseded' === $stored[ (string) ( Controller::VISITOR_CHECK_HISTORY_LIMIT + 4 ) ]['status'], 'an overtaken pending ledger is named superseded, not left lying' );

echo "== Latest completed check for the UI ==\n";

vc_reset();
vc_ok( null === $controller->latest_visitor_check(), 'no ledger → null, so the strip stays hidden' );
$controller->begin_visitor_check( 10, array(), array(), array( array( 'name' => 'tk_ai' ) ) );
vc_ok( null === $controller->latest_visitor_check(), 'a pending ledger is not surfaced — only measured results are' );
$controller->finalize_visitor_check();
$controller->begin_visitor_check( 11, array(), array(), array() );
$controller->record_visitor_observations( array( array( 'name' => 'anon_two', 'domain' => 'example.test' ) ) );
$controller->finalize_visitor_check();
$latest = $controller->latest_visitor_check();
vc_ok( is_array( $latest ) && 11 === $latest['scan_id'], 'the newest completed check wins' );
vc_ok( array( 'anon_two' ) === $latest['visitor_only'], 'its buckets come back as plain sanitized name lists' );

echo "== Structural wiring (source-order checks) ==\n";

// Behavioural coverage above proves the methods; these pin the CALLSITES so a
// refactor cannot silently unhook them.
$controller_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php' );
$api_src        = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/scanner/api/class-api.php' );
vc_ok(
	false !== strpos( $controller_src, '$this->record_visitor_observations( $page_cookies );' ),
	'run_httponly_check() records observations beside its checkpoint writes'
);
vc_ok(
	false !== strpos( $controller_src, '$this->finalize_visitor_check( $visitor_target );' ),
	'run_httponly_check() finalizes the ledger it opened with, when the queue drains'
);
$begin_pos    = strpos( $api_src, 'begin_visitor_check(' );
$schedule_pos = strpos( $api_src, 'schedule_httponly_check( $scanned_urls )' );
vc_ok(
	false !== $begin_pos && false !== $schedule_pos && $begin_pos < $schedule_pos,
	'import opens the ledger BEFORE scheduling the replay, so the worker never races an absent ledger'
);
vc_ok(
	false !== strpos( $api_src, "\$safe['visitor_check'] = \$this->controller->latest_visitor_check();" ),
	'scans/info exposes the latest completed check to the Cookies page'
);
$cookies_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/js/pages/cookies.js' );
vc_ok(
	false !== strpos( $cookies_js, 'faz-visitor-check-bar' ) && false !== strpos( $cookies_js, 'visitorCheckDisclaimer' ),
	'the Cookies page renders the strip, disclaimer included'
);
vc_ok(
	false === stripos( $cookies_js, 'visitor view verified' ),
	'no shipped copy claims the visitor view is verified'
);

echo "\n";
if ( $failed > 0 ) {
	echo "FAILED: {$failed} failed, {$passed} passed\n";
	exit( 1 );
}
echo "ALL PASS ({$passed} assertions)\n";
exit( 0 );
