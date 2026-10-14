<?php
/**
 * Standalone unit tests for the stored-DSAR admin screen's decision logic.
 *
 * The screen exists because the record created by [faz_dsar_form] used to be a
 * dead end — the editor showed a title and nothing else, so honouring a request
 * meant copying the address out of the notification email and retyping it into
 * Tools → Erase Personal Data. What is pinned here is the part of that bridge
 * where a mistake would be silent or unsafe:
 *
 *   - which core privacy request each GDPR right maps to (sending an "access"
 *     request down the erasure path would destroy data the subject asked to see);
 *   - that creating a core request demands the CORE privacy capability, so the
 *     DSAR capabilities granted to the administrator role never become a way
 *     around manage_privacy_options;
 *   - that each action URL's nonce is bound to its own record;
 *   - that a record written before the status meta existed still reads as pending.
 *
 * Run: php tests/unit/test-dsar-admin-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	$GLOBALS['__faz_meta']   = array();
	$GLOBALS['__faz_nonces'] = array();

	function __( $text, $domain = null ) {
		return $text;
	}
	function add_action() {}
	function add_filter() {}
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . $path;
	}
	// Mirrors WordPress: add_query_arg() urlencode_deep()s only the query
	// string already present on the URL, then merges the new pairs and runs
	// build_query(), which is _http_build_query( …, $urlencode = false ). The
	// values it is handed are therefore NOT encoded. A stub built on
	// http_build_query() encodes them, which is how a missing rawurlencode()
	// in core_tool_url() passed its own test for a while.
	function add_query_arg( $args, $url = '' ) {
		$sep  = false === strpos( $url, '?' ) ? '?' : '&';
		$pairs = array();
		foreach ( (array) $args as $k => $v ) {
			$pairs[] = $k . '=' . $v;
		}
		return $url . $sep . implode( '&', $pairs );
	}
	function wp_nonce_url( $url, $action ) {
		$GLOBALS['__faz_nonces'][] = $action;
		return $url . '&_wpnonce=' . md5( (string) $action );
	}
	function esc_url( $url ) {
		return $url;
	}
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
	function get_post_meta( $post_id, $key, $single = false ) {
		return isset( $GLOBALS['__faz_meta'][ $post_id ][ $key ] ) ? $GLOBALS['__faz_meta'][ $post_id ][ $key ] : '';
	}
	// What confirmation_outcome() needs: the send filter, core's mailer and its
	// error type. The mailer's verdict is scripted per case and every call is
	// counted, so "the filter switched it off" is a count of zero, not a guess.
	$GLOBALS['__faz_filters']    = array();
	$GLOBALS['__faz_send_result'] = true;
	$GLOBALS['__faz_send_calls']  = array();
	class WP_Error {
		public function __construct( $code = '', $message = '' ) {}
	}
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	function apply_filters( $hook, $value ) {
		return array_key_exists( $hook, $GLOBALS['__faz_filters'] ) ? $GLOBALS['__faz_filters'][ $hook ] : $value;
	}
	function wp_send_user_request( $request_id ) {
		$GLOBALS['__faz_send_calls'][] = $request_id;
		return $GLOBALS['__faz_send_result'];
	}

	require_once __DIR__ . '/../../includes/class-dsar-admin.php';

	use FazCookie\Includes\DSAR_Admin;

	$pass = 0;
	$fail = 0;

	function assert_eq( $actual, $expected, $label ) {
		global $pass, $fail;
		if ( $actual === $expected ) {
			++$pass;
			return;
		}
		++$fail;
		echo '  FAIL: ' . $label . ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
	function check( $cond, $label ) {
		global $pass, $fail;
		if ( $cond ) {
			++$pass;
			return;
		}
		++$fail;
		echo "  FAIL: $label\n";
	}

	// ── Right invoked → core request ─────────────────────────────────────────

	echo "— GDPR right to core privacy request\n";

	assert_eq( DSAR_Admin::suggested_action( 'erasure' ), 'remove_personal_data', 'Art. 17 erasure => core erasure request' );
	assert_eq( DSAR_Admin::suggested_action( 'access' ), 'export_personal_data', 'Art. 15 access => core export request' );
	assert_eq( DSAR_Admin::suggested_action( 'portability' ), 'export_personal_data', 'Art. 20 portability => core export request' );

	// The three rights WordPress has no automated flow for must suggest NOTHING.
	// Suggesting erasure for an objection would delete data the subject never
	// asked to have deleted.
	assert_eq( DSAR_Admin::suggested_action( 'rectify' ), '', 'Art. 16 rectification => no automated counterpart' );
	assert_eq( DSAR_Admin::suggested_action( 'restrict' ), '', 'Art. 18 restriction => no automated counterpart' );
	assert_eq( DSAR_Admin::suggested_action( 'object' ), '', 'Art. 21 objection => no automated counterpart' );
	assert_eq( DSAR_Admin::suggested_action( 'nonsense' ), '', 'an unknown type suggests nothing' );

	// ── Capability separation ────────────────────────────────────────────────

	echo "— capability separation\n";

	assert_eq( DSAR_Admin::required_cap( 'remove_personal_data' ), 'erase_others_personal_data', 'erasure demands the core erase capability' );
	assert_eq( DSAR_Admin::required_cap( 'export_personal_data' ), 'export_others_personal_data', 'export demands the core export capability' );

	foreach ( array( 'remove_personal_data', 'export_personal_data' ) as $action ) {
		$cap = DSAR_Admin::required_cap( $action );
		check( false === strpos( $cap, 'faz_dsar' ), "$action is not gated by a plugin capability" );
		check( false !== strpos( $cap, '_others_personal_data' ), "$action is gated by a core privacy capability" );
	}

	// ── Core tool links ──────────────────────────────────────────────────────

	echo "— core tool links\n";

	$url = DSAR_Admin::core_tool_url( 'remove_personal_data', 'jane@example.test' );
	check( false !== strpos( $url, 'tools.php' ), 'the erasure link points at tools.php' );
	check( false !== strpos( $url, 'page=remove_personal_data' ), 'the erasure link opens the erase tool' );
	check( false !== strpos( $url, 'jane%40example.test' ), "the link pre-filters the tool by the requester's address" );

	$url = DSAR_Admin::core_tool_url( 'export_personal_data', 'jane@example.test' );
	check( false !== strpos( $url, 'page=export_personal_data' ), 'the export link opens the export tool' );

	// A plus-addressed mailbox is a real address and must survive the round trip
	// into the tool's search box, where a bare + would decode back to a space.
	$url = DSAR_Admin::core_tool_url( 'export_personal_data', 'jane+gdpr@example.test' );
	check( false !== strpos( $url, 'jane%2Bgdpr%40example.test' ), 'a plus-addressed mailbox is encoded, not turned into a space' );

	check( DSAR_Admin::core_tool_label( 'remove_personal_data' ) !== DSAR_Admin::core_tool_label( 'export_personal_data' ), 'the two tools are labelled distinctly' );

	// ── Action URLs ──────────────────────────────────────────────────────────

	echo "— action links, bound to their record\n";

	// A POST form would be stricter, and a meta box cannot have one: it renders
	// inside the editor's own <form id="post">, nesting is invalid, and the
	// parser lets the inner </form> close the EDITOR's form. A nonce'd GET link
	// is also how WordPress ships the same privacy operations.
	$GLOBALS['__faz_nonces'] = array();
	ob_start();
	DSAR_Admin::action_link( 42, 'remove_personal_data', 'Create erasure request', true );
	$html = ob_get_clean();

	check( false === strpos( $html, '<form' ), 'no nested form: it would close the editor\'s own' );
	check( false !== strpos( $html, 'admin-post.php' ), 'the action goes to admin-post.php' );
	check( false !== strpos( $html, 'action=' . DSAR_Admin::ACTION ), 'the handler is addressed by name' );
	check( false !== strpos( $html, 'post=42' ), 'the record id travels with it' );
	check( false !== strpos( $html, 'faz_action=remove_personal_data' ), 'the requested action travels with it' );
	check( false !== strpos( $html, '_wpnonce=' ), 'it carries a nonce' );
	check( false !== strpos( $html, 'rel="nofollow"' ), 'crawlers are told to leave it alone' );
	check( false !== strpos( $html, 'button-primary' ), 'the suggested action is marked as primary' );
	assert_eq( $GLOBALS['__faz_nonces'], array( DSAR_Admin::ACTION . '_42_remove_personal_data' ), 'the nonce action names both the record and the operation' );

	ob_start();
	DSAR_Admin::action_link( 43, 'complete', 'Mark as completed' );
	$html = ob_get_clean();
	check( false === strpos( $html, 'button-primary' ), 'a secondary action is not styled as primary' );

	// A nonce minted for one record must not validate for another…
	check(
		DSAR_Admin::nonce_action( 42, 'complete' ) !== DSAR_Admin::nonce_action( 43, 'complete' ),
		'each record has its own nonce action'
	);
	// …nor for a different operation on the same record. Bound to the record
	// alone, the nonce from "Mark as completed" would have validated a request
	// to create an erasure request, so anything able to alter faz_action in
	// flight could upgrade a harmless click into one with legal consequences.
	check(
		DSAR_Admin::nonce_action( 42, 'complete' ) !== DSAR_Admin::nonce_action( 42, 'remove_personal_data' ),
		'each operation has its own nonce action'
	);
	check(
		DSAR_Admin::nonce_action( 42, 'remove_personal_data' ) !== DSAR_Admin::nonce_action( 42, 'export_personal_data' ),
		'erasure and export are not interchangeable'
	);
	assert_eq( DSAR_Admin::nonce_action( '42abc', 'complete' ), DSAR_Admin::ACTION . '_42_complete', 'the record id is cast, so nothing can be smuggled through it' );
	assert_eq( DSAR_Admin::nonce_action( 42, 'Complete Me!' ), DSAR_Admin::ACTION . '_42_completeme', 'the action is sanitised into the nonce string' );

	// ── Notices cannot claim what did not happen ─────────────────────────────

	echo "— a notice may not claim an outcome the record does not show\n";

	// The outcome travels back in the URL, which anyone can write. A crafted
	// link would otherwise tell an administrator "erasure request created" for a
	// request nobody created — a believable lie, in the site's own admin, about
	// the one thing on this screen with legal consequences.
	$GLOBALS['__faz_meta'][50] = array();
	check( ! DSAR_Admin::notice_is_true( 'created_erasure', 50 ), 'a creation claim is refused with no core request on the record' );
	check( ! DSAR_Admin::notice_is_true( 'created_export', 50 ), 'the same for an export claim' );

	$GLOBALS['__faz_meta'][50] = array(
		'_dsar_core_request_id'     => 9,
		'_dsar_core_request_action' => 'remove_personal_data',
	);
	check( DSAR_Admin::notice_is_true( 'created_erasure', 50 ), 'the erasure claim is shown once the record carries the request' );
	check( ! DSAR_Admin::notice_is_true( 'created_export', 50 ), 'an export claim is still refused: the kinds must match' );

	$GLOBALS['__faz_meta'][51] = array( '_dsar_status' => 'completed' );
	check( DSAR_Admin::notice_is_true( 'completed', 51 ), 'a completion claim matches the stored status' );
	check( ! DSAR_Admin::notice_is_true( 'reopened', 51 ), 'and a reopen claim does not' );

	check( ! DSAR_Admin::notice_is_true( 'created_erasure', 0 ), 'no record, no claim' );

	// ── The confirmation email is reported as sent only when it was ─────────

	echo "— the confirmation mail's outcome is the notice's outcome\n";

	// wp_send_user_request() returns a WP_Error when the mail could not be
	// handed over, and the redirect used to say "WordPress has emailed the
	// requester" regardless. The request exists either way (core keeps it as
	// request-pending with a "Resend email" link), so the notice must say
	// created-but-unsent, not sent.
	$GLOBALS['__faz_filters']     = array();
	$GLOBALS['__faz_send_calls']  = array();
	$GLOBALS['__faz_send_result'] = true;
	assert_eq( DSAR_Admin::confirmation_outcome( 50, 'remove_personal_data', 9 ), 'created_erasure', 'mail sent => the erasure notice' );
	assert_eq( DSAR_Admin::confirmation_outcome( 50, 'export_personal_data', 9 ), 'created_export', 'mail sent => the export notice' );
	assert_eq( $GLOBALS['__faz_send_calls'], array( 9, 9 ), 'the core request is what gets mailed' );

	$GLOBALS['__faz_send_result'] = new WP_Error( 'privacy_email_failed', 'Unable to send' );
	assert_eq( DSAR_Admin::confirmation_outcome( 50, 'remove_personal_data', 9 ), 'created_unsent', 'mail refused => created-but-unsent, not "emailed"' );
	assert_eq( DSAR_Admin::confirmation_outcome( 50, 'export_personal_data', 9 ), 'created_unsent', 'the same for an export request' );

	// The filter that switches the confirmation off: nothing is sent, and the
	// outcome is the plain creation.
	$GLOBALS['__faz_send_calls']                                  = array();
	$GLOBALS['__faz_filters']['faz_dsar_send_core_confirmation'] = false;
	assert_eq( DSAR_Admin::confirmation_outcome( 50, 'remove_personal_data', 9 ), 'created_erasure', 'with the confirmation switched off the request is simply created' );
	assert_eq( $GLOBALS['__faz_send_calls'], array(), 'and the mailer is not called at all' );
	$GLOBALS['__faz_filters']     = array();
	$GLOBALS['__faz_send_result'] = true;

	// The unsent notice claims a request was created, so it is held to the same
	// proof as the sent ones: the record must carry a core request.
	$GLOBALS['__faz_meta'][52] = array();
	check( ! DSAR_Admin::notice_is_true( 'created_unsent', 52 ), 'an unsent claim is refused with no core request on the record' );
	$GLOBALS['__faz_meta'][52] = array(
		'_dsar_core_request_id'     => 11,
		'_dsar_core_request_action' => 'export_personal_data',
	);
	check( DSAR_Admin::notice_is_true( 'created_unsent', 52 ), 'and shown once the record carries one, of either kind' );
	$GLOBALS['__faz_meta'][52]['_dsar_core_request_action'] = 'something_else';
	check( ! DSAR_Admin::notice_is_true( 'created_unsent', 52 ), 'but not for a request kind the core tools do not have' );


	// ── Status ───────────────────────────────────────────────────────────────

	echo "— status\n";

	// Records stored before the status meta existed carry no value at all.
	assert_eq( DSAR_Admin::get_status( 7 ), 'pending', 'a record with no status reads as pending' );

	$GLOBALS['__faz_meta'][7] = array( '_dsar_status' => 'forwarded' );
	assert_eq( DSAR_Admin::get_status( 7 ), 'forwarded', 'a stored status is honoured' );

	$GLOBALS['__faz_meta'][7] = array( '_dsar_status' => '' );
	assert_eq( DSAR_Admin::get_status( 7 ), 'pending', 'an empty status reads as pending' );

	// `erased` is written by faz_privacy_eraser() when an erasure is honoured:
	// the record is redacted in place instead of destroyed, so the vocabulary the
	// eraser writes and the vocabulary this screen can render must stay the same
	// set. Without the label the screen printed the raw key.
	$GLOBALS['__faz_meta'][7] = array( '_dsar_status' => 'erased' );
	assert_eq( DSAR_Admin::get_status( 7 ), 'erased', 'an erased record reports the erased status' );

	foreach ( array( 'pending', 'forwarded', 'completed', 'erased' ) as $status ) {
		check( DSAR_Admin::status_label( $status ) !== $status, "$status has a human-readable label" );
	}
	assert_eq( DSAR_Admin::status_label( 'something-else' ), 'something-else', 'an unknown status is shown as-is rather than blanked' );

	echo "\n";
	if ( $fail ) {
		echo "FAILED: $fail of " . ( $pass + $fail ) . "\n";
		exit( 1 );
	}
	echo "ALL PASS: $pass assertions passed\n";
	exit( 0 );
}
