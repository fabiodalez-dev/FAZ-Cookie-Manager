<?php
/**
 * What counts as evidence that a cookie is gone.
 *
 * Deleting a cookie row removes an entry from the site's PUBLIC declaration,
 * and the trigger is a consecutive-miss tally. Two ways of feeding that tally a
 * lie are pinned here, and both are driven — the assertions CALL the code with
 * real inputs rather than grepping for a fragment, because the whole reason
 * these two shipped is that a source-text check reported the opposite in green.
 *
 * 1. Coverage. A 20-page run on a 500-page site finishing is not the same as a
 *    full crawl finishing, and the depth never crossed the wire at all, so the
 *    server counted every capped run as full-site evidence.
 *
 * 2. Clearing. The background header-replay pass re-confirms cookies the
 *    browser scan never sees — a first-visit session cookie is issued to that
 *    cookie-less client on every run — and had no channel to say so. Its tally
 *    climbed forever while the plugin watched the site set it. The channel that
 *    fixes it must be CLEAR-ONLY: the worker sees 20 URLs, so anything able to
 *    increment would turn one false positive into a site-wide one.
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
function ev_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		++$passed;
		echo "  [PASS] {$label}\n";
		return;
	}
	++$failed;
	echo "  [FAIL] {$label}\n";
}

/**
 * A payload from a genuine, uncapped, healthy full crawl.
 *
 * Every case below is this minus exactly one fact, so a case that goes green
 * for the wrong reason is visible: if the baseline itself stopped being
 * complete, the first assertion fails and takes the rest with it.
 *
 * @param array $overrides Fields to replace.
 * @return array
 */
function ev_full_scan_metrics( $overrides = array() ) {
	return array_merge(
		array(
			'incremental'     => false,
			'earlyStopReason' => null,
			'stoppedReason'   => null,
			'maxPages'        => 0,
			'isFullScan'      => true,
			// A clean run: no iframe timeout, no cross-origin refusal, no
			// failed server-scan, no resource cap. The client gate has always
			// required this to be zero before offering the stale action.
			'diagnosticsIssues' => 0,
		),
		$overrides
	);
}

echo "== A scan's silence only counts when the scan looked everywhere ==\n";

// ── Degraded capture is a way to be incomplete ────────────────────────────
//
// scanCoverageIsComplete() in admin/assets/js/pages/cookies.js has always
// required diagnostics.totalIssues === 0, but the metrics payload carried no
// such field, so the server's twin could not test it. A crawl with timed-out
// iframes or a failed server-scan therefore read here as full-site evidence,
// and every catalogue row it failed to observe took a miss toward deletion —
// MISSED_SCANS_THRESHOLD is 2, so two degraded runs are enough.
ev_ok(
	false === Controller::scan_coverage_is_complete( ev_full_scan_metrics( array( 'diagnosticsIssues' => 3 ) ), 40, false ),
	'a full-depth run that hit capture issues is NOT complete coverage'
);
ev_ok(
	true === Controller::scan_coverage_is_complete( ev_full_scan_metrics( array( 'diagnosticsIssues' => 0 ) ), 40, false ),
	'the same run with zero issues still counts — the term is not a blanket refusal'
);
// Fail CLOSED on absence: an older client that does not send the field must
// not be read as "no issues", or the gap re-opens for every un-upgraded tab.
ev_ok(
	false === Controller::scan_coverage_is_complete(
		array( 'incremental' => false, 'earlyStopReason' => null, 'stoppedReason' => null, 'maxPages' => 0, 'isFullScan' => true ),
		40,
		false
	),
	'a payload with no diagnosticsIssues field at all is refused, not assumed clean'
);
// Parity guard: the client must actually send what the server now demands, so
// the two gates cannot drift apart again.
$ev_engine_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/js/modules/scan-engine.js' );
ev_ok(
	false !== strpos( $ev_engine_src, 'diagnosticsIssues:' ),
	'the shipped scan engine sends diagnosticsIssues in its metrics payload'
);


// If this one goes red the whole section is meaningless, so it comes first.
ev_ok(
	true === Controller::scan_coverage_is_complete( ev_full_scan_metrics(), 40, false ),
	'a healthy uncapped full crawl IS complete — the gate is not simply always false'
);

// The finding. Delete the maxPages/isFullScan terms from
// Controller::scan_coverage_is_complete() and this pair goes green-to-red
// immediately: it is the exact payload the default 20-page dropdown produces.
ev_ok(
	false === Controller::scan_coverage_is_complete( ev_full_scan_metrics( array( 'maxPages' => 20, 'isFullScan' => false ) ), 20, false ),
	'the default 20-page run is NOT full-site evidence, however cleanly it finished'
);
ev_ok(
	false === Controller::scan_coverage_is_complete( ev_full_scan_metrics( array( 'maxPages' => 1000, 'isFullScan' => false ) ), 1000, false ),
	'the 1000-page Deep scan is not full-site evidence either — safeMode forces incremental=false, removing the only escape hatch it used to trip'
);

// Structural: a client that never learned to send the depth must not be read as
// having claimed a full crawl. Change either `array_key_exists` guard to a
// truthiness test with a permissive default and this goes red.
ev_ok(
	false === Controller::scan_coverage_is_complete(
		array( 'incremental' => false, 'earlyStopReason' => null, 'stoppedReason' => null ),
		40,
		false
	),
	'a payload that says nothing about depth fails CLOSED, it does not default to complete'
);
ev_ok(
	false === Controller::scan_coverage_is_complete( ev_full_scan_metrics( array( 'isFullScan' => false ) ), 40, false )
		&& false === Controller::scan_coverage_is_complete( ev_full_scan_metrics( array( 'maxPages' => 20 ) ), 40, false ),
	'a payload whose two depth fields contradict each other is read the narrow way'
);

// $capture_truncated was computed ninety lines above the gate and consulted by
// nothing. Drop the third argument from the call in class-api.php, or the
// `! empty( $capture_truncated )` branch from the method, and this goes red.
ev_ok(
	false === Controller::scan_coverage_is_complete( ev_full_scan_metrics(), 40, true ),
	'a run whose server-side capture hit its cap and dropped observations is not complete'
);

// Pre-existing terms, kept driven rather than grepped.
ev_ok( false === Controller::scan_coverage_is_complete( ev_full_scan_metrics(), 0, false ), 'a scan that visited nothing is never complete' );
ev_ok( false === Controller::scan_coverage_is_complete( ev_full_scan_metrics( array( 'incremental' => true ) ), 40, false ), 'an incremental run is not complete' );
ev_ok( false === Controller::scan_coverage_is_complete( ev_full_scan_metrics( array( 'earlyStopReason' => 'budget' ) ), 40, false ), 'an early-stopped run is not complete' );
ev_ok( false === Controller::scan_coverage_is_complete( ev_full_scan_metrics( array( 'stoppedReason' => 'cancelled' ) ), 40, false ), 'a cancelled run is not complete' );
ev_ok( false === Controller::scan_coverage_is_complete( 'not-an-array', 40, false ), 'a non-array metrics payload cannot claim coverage' );

echo "== Re-confirming a cookie clears its tally, and can do nothing else ==\n";

$controller = new Controller();

$GLOBALS['faz_test_options'][ Controller::MISSED_SCANS_OPTION ] = array(
	'phpsessid|example.test'    => 1,
	'wp_woocommerce_session|example.test' => 2,
	'_ga|example.test'          => 1,
);

// The trigger the validator found: the replay is a cookie-less client, so a
// first-visit session cookie is re-issued to IT on every run and to the admin's
// browser never. That name lands in jar_cookies at import and can never reach
// record_scan_observations()'s observed set.
$after = $controller->clear_scan_observations( array( 'PHPSESSID' ) );

ev_ok(
	! isset( $after['phpsessid|example.test'] ),
	'a cookie the header replay watched the site set has its tally cleared — case-folded, as the tally keys are'
);
ev_ok(
	! isset( $GLOBALS['faz_test_options'][ Controller::MISSED_SCANS_OPTION ]['phpsessid|example.test'] ),
	'the clear is PERSISTED, not merely returned'
);

// The reason this is a separate method and not a second call to
// record_scan_observations(): the worker holds a 20-URL batch of an
// arbitrarily large site, so anything able to increment would tally a miss
// against every catalogue row the batch happened not to touch.
ev_ok(
	2 === $after['wp_woocommerce_session|example.test'] && 1 === $after['_ga|example.test'],
	'every entry the batch did not touch is left EXACTLY as it was — not incremented, not reset'
);
ev_ok(
	2 === count( $after ) && 2 === count( $GLOBALS['faz_test_options'][ Controller::MISSED_SCANS_OPTION ] ),
	'clearing one of three keys leaves two — it removes, and adds none'
);

// A name that is not in the catalogue must not create a tally entry: the
// channel can only ever subtract.
$before_unknown = $GLOBALS['faz_test_options'][ Controller::MISSED_SCANS_OPTION ];
$after_unknown  = $controller->clear_scan_observations( array( 'never_seen_before' ) );
ev_ok(
	$after_unknown === $before_unknown,
	'an unrecognised replayed name changes nothing — the channel can never add a key'
);

// An empty batch is the shape a failed replay produces. It must be inert, not
// read as "nothing was observed, so everything is missing".
$after_empty = $controller->clear_scan_observations( array() );
ev_ok( $after_empty === $before_unknown, 'an empty replay result is inert' );
$after_junk = $controller->clear_scan_observations( array( '', '   ' ) );
ev_ok( $after_junk === $before_unknown, 'blank names are discarded rather than matched against a blank key' );

// Domain independence: a Set-Cookie domain routinely carries a leading dot that
// the catalogue row does not, so matching on the full "name|domain" key would
// make this channel silently never fire — inert while looking wired, the exact
// failure mode this branch's review was about.
$GLOBALS['faz_test_options'][ Controller::MISSED_SCANS_OPTION ] = array( 'sessioncookie|.example.test' => 2 );
$after_dotted = $controller->clear_scan_observations( array( 'sessioncookie' ) );
ev_ok( array() === $after_dotted, 'a replayed name clears its tally whatever domain form the key carries' );

echo "== The worker actually uses the channel ==\n";

// Source-level, and deliberately so: run_httponly_check() is a cron worker that
// issues real HTTP requests, so it cannot be driven here. The claim is narrow —
// the clear is wired INSIDE the per-URL checkpoint block, beside the save, so a
// worker that dies on a later URL keeps what it proved.
$controller_src = file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php' );
$squeezed       = preg_replace( '/\s+/', ' ', $controller_src );
$saved_at       = strpos( $squeezed, '$this->save_cookies( $page_cookies );' );
$cleared_at     = strpos( $squeezed, '$this->clear_scan_observations( $replayed_names );' );
ev_ok(
	false !== $saved_at && false !== $cleared_at && $cleared_at > $saved_at && ( $cleared_at - $saved_at ) < 800,
	'the replay clears the tally in the same checkpoint that saves the row, not in a separate end-of-run pass'
);
// The guard the fix_proposal insisted on: reusing the incrementing path here
// would turn one false positive into a site-wide one.
ev_ok(
	false === strpos( $squeezed, 'record_scan_observations' )
		|| false === strpos( substr( $squeezed, $saved_at, 800 ), 'record_scan_observations' ),
	'the worker never reaches the INCREMENTING path — a 20-URL batch may not judge the whole catalogue'
);

echo "\nPassed: {$passed}; Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
