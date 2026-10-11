<?php
/**
 * Standalone unit tests for the DSAR form's spam gate and cumulative throttles.
 *
 * Context: a site reported a "chain of spam submissions" through
 * [faz_dsar_form] despite the nonce, the honeypot, the 60-second per-IP lock
 * and the one-per-email-per-hour limit. Both halves of that report are pinned
 * here:
 *
 *   - the 60-second lock only SPACES submissions, so the cumulative 24-hour
 *     ceilings are what actually bound a flood;
 *   - a third-party protection plugin (ALTCHA / OpenPorte, Turnstile, Akismet)
 *     could not see this form at all, so `faz_dsar_pre_submit` must be able to
 *     refuse a submission and have its own message reach the visitor.
 *
 * The window arithmetic is pinned too, because the obvious implementation is
 * wrong in a way that is invisible until a real flood: re-setting the counter
 * transient with a full 24-hour TTL on every hit turns the fixed window into a
 * rolling one that never closes while submissions keep arriving — the form
 * would stay shut long after the flood stopped. `time()` is stubbed inside the
 * class's own namespace so the window can be walked hour by hour.
 *
 * Run: php tests/unit/test-dsar-spam-guard-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	// Namespace-scoped stub: PHP resolves an unqualified time() call inside
	// FazCookie\Includes to this function before the global one, which is what
	// lets the fixed-window assertions below advance the clock.
	function time() {
		return $GLOBALS['__faz_now'];
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'DAY_IN_SECONDS', 86400 );

	$GLOBALS['__faz_now']        = 1760000000;
	$GLOBALS['__faz_ip']         = '203.0.113.7';
	$GLOBALS['__faz_transients'] = array();
	$GLOBALS['__faz_filters']    = array();
	$GLOBALS['__faz_actions']    = array();
	$GLOBALS['__faz_mail']       = array();
	$GLOBALS['__faz_queries']    = 0;
	$GLOBALS['__faz_pending']    = 7;
	$GLOBALS['__faz_options']    = array( 'admin_email' => 'admin@example.test' );

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private $code;
			private $message;
			public function __construct( $code = '', $message = '' ) {
				$this->code    = $code;
				$this->message = $message;
			}
			public function get_error_code() {
				return $this->code;
			}
			public function get_error_message() {
				return $this->message;
			}
		}
	}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}

	// ── WordPress stubs ──────────────────────────────────────────────────────

	function __( $text, $domain = null ) {
		return $text;
	}
	function add_shortcode() {}
	function add_action( $hook, $cb = null, $prio = 10, $args = 1 ) {
		$GLOBALS['__faz_actions'][ $hook ][] = $cb;
	}
	function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
		$GLOBALS['__faz_filters'][ $hook ][] = $cb;
	}
	function apply_filters( $hook, $value ) {
		$args = array_slice( func_get_args(), 1 );
		foreach ( ( isset( $GLOBALS['__faz_filters'][ $hook ] ) ? $GLOBALS['__faz_filters'][ $hook ] : array() ) as $cb ) {
			$args[0] = call_user_func_array( $cb, $args );
		}
		return $args[0];
	}
	function do_action( $hook ) {
		$args = array_slice( func_get_args(), 1 );
		$GLOBALS['__faz_fired'][] = array_merge( array( $hook ), $args );
	}
	function wp_salt( $scheme = 'auth' ) {
		return 'unit-test-salt-' . $scheme;
	}
	function faz_resolve_client_ip() {
		return $GLOBALS['__faz_ip'];
	}
	function is_email( $email ) {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
	function get_option( $key, $default = false ) {
		return isset( $GLOBALS['__faz_options'][ $key ] ) ? $GLOBALS['__faz_options'][ $key ] : $default;
	}
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__faz_options'][ $key ] = $value;
		return true;
	}
	function delete_option( $key ) {
		unset( $GLOBALS['__faz_options'][ $key ] );
		return true;
	}
	function get_bloginfo( $what = 'name' ) {
		return 'Example Site';
	}
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . $path;
	}
	function post_type_exists( $type ) {
		return 'faz_dsar' === $type;
	}
	// Minimal stand-in for the one query pending_count() runs.
	class WP_Query {
		public $found_posts = 0;
		public function __construct( $args = array() ) {
			++$GLOBALS['__faz_queries'];
			$this->found_posts = $GLOBALS['__faz_pending'];
		}
	}
	function wp_mail( $to, $subject, $body, $headers = array() ) {
		$GLOBALS['__faz_mail'][] = compact( 'to', 'subject', 'body' );
		return true;
	}

	// Transients honour the stubbed clock, including expiry, so an elapsed
	// window really does disappear instead of being assumed away.
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['__faz_transients'][ $key ] = array(
			'value'   => $value,
			'expires' => $ttl ? $GLOBALS['__faz_now'] + (int) $ttl : 0,
			'ttl'     => (int) $ttl,
		);
		return true;
	}
	function get_transient( $key ) {
		if ( ! isset( $GLOBALS['__faz_transients'][ $key ] ) ) {
			return false;
		}
		$entry = $GLOBALS['__faz_transients'][ $key ];
		if ( $entry['expires'] && $entry['expires'] <= $GLOBALS['__faz_now'] ) {
			unset( $GLOBALS['__faz_transients'][ $key ] );
			return false;
		}
		return $entry['value'];
	}
	function delete_transient( $key ) {
		unset( $GLOBALS['__faz_transients'][ $key ] );
		return true;
	}

	require_once __DIR__ . '/../../includes/class-ip-hasher.php';
	require_once __DIR__ . '/../../includes/class-dsar-shortcode.php';

	// ── Harness ──────────────────────────────────────────────────────────────

	$pass = 0;
	$fail = 0;

	function check( $cond, $label ) {
		global $pass, $fail;
		if ( $cond ) {
			++$pass;
		} else {
			++$fail;
			echo "  FAIL: $label\n";
		}
	}
	function assert_eq( $actual, $expected, $label ) {
		$ok = $actual === $expected;
		if ( ! $ok ) {
			echo '  FAIL: ' . $label . ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
			global $fail;
			++$fail;
			return;
		}
		global $pass;
		++$pass;
	}

	// Readable failures instead of a stack trace: a regression that makes the
	// guard accept where it should refuse must report as a FAIL, not fatal on a
	// method call against `true`.
	function err_code( $verdict ) {
		return is_wp_error( $verdict ) ? $verdict->get_error_code() : '(accepted)';
	}
	function err_msg( $verdict ) {
		return is_wp_error( $verdict ) ? $verdict->get_error_message() : '(accepted)';
	}

	function reset_state() {
		$GLOBALS['__faz_transients'] = array();
		$GLOBALS['__faz_filters']    = array();
		$GLOBALS['__faz_fired']      = array();
		$GLOBALS['__faz_mail']       = array();
		$GLOBALS['__faz_queries']    = 0;
		$GLOBALS['__faz_pending']    = 7;
		$GLOBALS['__faz_now']        = 1760000000;
		$GLOBALS['__faz_ip']         = '203.0.113.7';
		$GLOBALS['__faz_options']    = array( 'admin_email' => 'admin@example.test' );
	}

	function ctx( $email = 'subject@example.test' ) {
		return array(
			'name'    => 'Jane Doe',
			'email'   => $email,
			'type'    => 'erasure',
			'message' => '',
		);
	}

	function fired_codes() {
		$codes = array();
		foreach ( $GLOBALS['__faz_fired'] as $entry ) {
			if ( 'faz_dsar_rejected' === $entry[0] ) {
				$codes[] = $entry[1];
			}
		}
		return $codes;
	}

	reset_state();
	$dsar = new FazCookie\Includes\DSAR_Shortcode();

	// ── 1. The third-party gate ──────────────────────────────────────────────

	echo "— faz_dsar_pre_submit (the ALTCHA / OpenPorte integration point)\n";

	reset_state();
	assert_eq( $dsar->guard_submission( ctx() ), true, 'no gate installed => accepted' );

	reset_state();
	add_filter(
		'faz_dsar_pre_submit',
		function () {
			return new WP_Error( 'captcha', 'Please complete the verification.' );
		}
	);
	$verdict = $dsar->guard_submission( ctx() );
	check( is_wp_error( $verdict ), 'a gate returning WP_Error rejects the submission' );
	assert_eq( err_msg( $verdict ), 'Please complete the verification.', "the gate's own message reaches the visitor verbatim" );
	assert_eq( err_code( $verdict ), 'faz_dsar_gate', 'rejection is coded as the gate' );
	assert_eq( fired_codes(), array( 'faz_dsar_gate' ), 'faz_dsar_rejected fires for logging' );

	reset_state();
	add_filter(
		'faz_dsar_pre_submit',
		function () {
			return new WP_Error( 'nope', '' );
		}
	);
	$verdict = $dsar->guard_submission( ctx() );
	assert_eq( err_msg( $verdict ), 'Submission rejected.', 'an empty gate message falls back to a generic refusal' );

	reset_state();
	add_filter(
		'faz_dsar_pre_submit',
		function () {
			return null;
		}
	);
	assert_eq( $dsar->guard_submission( ctx() ), true, 'a gate returning null accepts' );

	reset_state();
	add_filter(
		'faz_dsar_pre_submit',
		function () {
			return true;
		}
	);
	assert_eq( $dsar->guard_submission( ctx() ), true, 'a gate returning true accepts' );

	// A rejected bot must not consume the throttle budget of the next visitor.
	reset_state();
	add_filter(
		'faz_dsar_pre_submit',
		function () {
			return new WP_Error( 'captcha', 'nope' );
		}
	);
	for ( $i = 0; $i < 20; $i++ ) {
		$dsar->guard_submission( ctx() );
	}
	$GLOBALS['__faz_filters'] = array();
	assert_eq( $dsar->guard_submission( ctx() ), true, '20 gate-rejected attempts do not burn the ceilings' );

	// The gate receives the sanitized submission so it can score the content.
	reset_state();
	$seen = null;
	add_filter(
		'faz_dsar_pre_submit',
		function ( $verdict, $context ) use ( &$seen ) {
			$seen = $context;
			return $verdict;
		},
		10,
		2
	);
	$dsar->guard_submission( ctx( 'spammer@example.test' ) );
	assert_eq( isset( $seen['email'] ) ? $seen['email'] : '', 'spammer@example.test', 'the gate is handed the submission context' );

	// ── 2. Per-email hourly limit ────────────────────────────────────────────

	echo "— per-email hourly limit\n";

	reset_state();
	$dsar->record_submission( 'subject@example.test' );
	$verdict = $dsar->guard_submission( ctx( 'subject@example.test' ) );
	check( is_wp_error( $verdict ), 'the same address is refused within the hour' );
	assert_eq( err_code( $verdict ), 'faz_dsar_email_throttled', 'coded as the email throttle' );
	assert_eq( $dsar->guard_submission( ctx( 'other@example.test' ) ), true, 'a different address still passes' );

	$GLOBALS['__faz_now'] += HOUR_IN_SECONDS + 1;
	assert_eq( $dsar->guard_submission( ctx( 'subject@example.test' ) ), true, 'the address is accepted again an hour later' );

	// The address must not be readable from the storage key.
	reset_state();
	$dsar->record_submission( 'subject@example.test' );
	$keys = implode( ' ', array_keys( $GLOBALS['__faz_transients'] ) );
	check( false === strpos( $keys, 'subject@example.test' ), 'the throttle key does not contain the plaintext address' );
	check( false !== strpos( $keys, 'faz_dsar_rl_em_' ), 'the per-email key is namespaced' );

	// Every throttle key must sit under one prefix: the E2E suites and the
	// uninstall sweep reset them by `faz_dsar_rl_`, and a key outside it would
	// outlive a reset and refuse submissions nothing had accounted for.
	foreach ( array_keys( $GLOBALS['__faz_transients'] ) as $k ) {
		check( 0 === strpos( $k, 'faz_dsar_rl_' ), "throttle key $k shares the resettable prefix" );
	}
	foreach ( array_keys( $GLOBALS['__faz_options'] ) as $k ) {
		if ( 'admin_email' === $k ) {
			continue;
		}
		check( 0 === strpos( $k, 'faz_dsar_rl_' ), "throttle option $k shares the resettable prefix" );
	}

	// Case folding: the limit must not be defeated by capitalisation.
	reset_state();
	$dsar->record_submission( 'Subject@Example.test' );
	check( is_wp_error( $dsar->guard_submission( ctx( 'subject@example.test' ) ) ), 'the limit is case-insensitive' );

	// ── 3. Per-IP 24-hour ceiling ────────────────────────────────────────────

	echo "— per-IP 24-hour ceiling\n";

	reset_state();
	$cap = FazCookie\Includes\DSAR_Shortcode::MAX_PER_IP_PER_DAY;
	for ( $i = 0; $i < $cap; $i++ ) {
		// A fresh address each time, and an hour apart, so neither the email
		// limit nor the 60-second lock is what refuses the next one.
		assert_eq( $dsar->guard_submission( ctx( "s$i@example.test" ) ), true, "submission " . ( $i + 1 ) . " of $cap is accepted" );
		$dsar->record_submission( "s$i@example.test" );
		$GLOBALS['__faz_now'] += HOUR_IN_SECONDS + 1;
	}
	$verdict = $dsar->guard_submission( ctx( 'one-too-many@example.test' ) );
	check( is_wp_error( $verdict ), 'the submission after the ceiling is refused' );
	assert_eq( err_code( $verdict ), 'faz_dsar_ip_throttled', 'coded as the IP ceiling' );
	check( false !== strpos( err_msg( $verdict ), 'email us directly' ), 'the refusal names a fallback channel' );

	$GLOBALS['__faz_ip'] = '198.51.100.9';
	assert_eq( $dsar->guard_submission( ctx( 'elsewhere@example.test' ) ), true, 'another address is unaffected' );

	// ── 4. The window is fixed, not rolling ──────────────────────────────────

	echo "— the 24-hour window is fixed, not rolling\n";

	reset_state();
	$t0 = $GLOBALS['__faz_now'];
	$dsar->record_submission( 'a@example.test' );
	$ip_key = null;
	foreach ( array_keys( $GLOBALS['__faz_transients'] ) as $k ) {
		if ( 0 === strpos( $k, 'faz_dsar_rl_cnt_ip_' ) ) {
			$ip_key = $k;
		}
	}
	check( null !== $ip_key, 'a per-IP counter was written' );
	assert_eq( $GLOBALS['__faz_transients'][ $ip_key ]['expires'], $t0 + DAY_IN_SECONDS, 'the window ends 24h after the first submission' );

	$GLOBALS['__faz_now'] = $t0 + ( 12 * HOUR_IN_SECONDS );
	$dsar->record_submission( 'b@example.test' );
	assert_eq( $GLOBALS['__faz_transients'][ $ip_key ]['expires'], $t0 + DAY_IN_SECONDS, 'a submission 12h in does NOT push the window out' );
	assert_eq( $GLOBALS['__faz_transients'][ $ip_key ]['value']['n'], 2, 'the count advanced' );

	$GLOBALS['__faz_now'] = $t0 + ( 23 * HOUR_IN_SECONDS );
	$dsar->record_submission( 'c@example.test' );
	assert_eq( $GLOBALS['__faz_transients'][ $ip_key ]['expires'], $t0 + DAY_IN_SECONDS, 'still anchored to the first submission at 23h' );

	// Past the window the counter starts over — a flood must not lock a site out
	// for longer than the window it filled.
	$GLOBALS['__faz_now'] = $t0 + DAY_IN_SECONDS + 1;
	$dsar->record_submission( 'd@example.test' );
	assert_eq( $GLOBALS['__faz_transients'][ $ip_key ]['value']['n'], 1, 'the counter resets once the window has passed' );
	assert_eq( $GLOBALS['__faz_transients'][ $ip_key ]['expires'], $GLOBALS['__faz_now'] + DAY_IN_SECONDS, 'a new window opens at the next submission' );

	// ── 5. Site-wide ceiling ─────────────────────────────────────────────────

	echo "— site-wide ceilings: mail is bounded, the right to submit is not\n";

	// The ceiling an attacker can reach must not be the one that denies data
	// subjects. A botnet filling the form must cost the admin's inbox, not
	// everyone's Art. 15-22 rights (GDPR Art. 12(2)).
	reset_state();
	check(
		FazCookie\Includes\DSAR_Shortcode::MAX_STORED_PER_DAY > FazCookie\Includes\DSAR_Shortcode::MAX_PER_DAY,
		'the hard ceiling sits well above the notification ceiling'
	);

	reset_state();
	add_filter(
		'faz_dsar_max_per_ip_per_day',
		function () {
			return 0;
		}
	);
	add_filter(
		'faz_dsar_max_per_day',
		function () {
			return 1;
		}
	);
	// Rotating addresses defeat the per-IP ceiling. Past the notification
	// ceiling the submissions must still be ACCEPTED.
	for ( $i = 0; $i < 6; $i++ ) {
		$GLOBALS['__faz_ip'] = "198.51.100.$i";
		assert_eq( $dsar->guard_submission( ctx( "r$i@example.test" ) ), true, 'submission ' . ( $i + 1 ) . ' past the notification ceiling is still accepted' );
		$dsar->record_submission( "r$i@example.test" );
	}

	// …but only the allowance is mailed one by one.
	reset_state();
	add_filter(
		'faz_dsar_max_per_day',
		function () {
			return 2;
		}
	);
	$dsar->record_submission( 'm1@example.test' );
	assert_eq( $dsar->per_request_mail_exhausted(), false, 'the 1st submission is mailed individually' );
	$dsar->record_submission( 'm2@example.test' );
	assert_eq( $dsar->per_request_mail_exhausted(), false, 'the 2nd, on the ceiling, is still mailed individually' );
	$dsar->record_submission( 'm3@example.test' );
	assert_eq( $dsar->per_request_mail_exhausted(), true, 'the 3rd is not mailed one by one' );

	// The predicate must stay a predicate: deciding whether to mail cannot
	// itself send mail, or the decision changes what it reports on.
	assert_eq( count( $GLOBALS['__faz_mail'] ), 0, 'asking the question sends nothing' );
	$dsar->per_request_mail_exhausted();
	assert_eq( count( $GLOBALS['__faz_mail'] ), 0, 'asking it twice still sends nothing' );

	// A ceiling filtered off means "mail everything", not "mail nothing".
	reset_state();
	add_filter(
		'faz_dsar_max_per_day',
		function () {
			return 0;
		}
	);
	for ( $i = 0; $i < 20; $i++ ) {
		$dsar->record_submission( "u$i@example.test" );
	}
	assert_eq( $dsar->per_request_mail_exhausted(), false, 'a notification ceiling filtered to 0 never silences mail' );

	// ── 5a. Suppression is a change of channel, never silence ───────────────

	echo "— past the ceiling the admin is still told, hourly and in aggregate\n";

	// This is the finding that the first fix created: if a flood could stop the
	// notifications, an attacker could let a real request arrive unseen, and
	// Art. 12(3) gives one month to answer it. So suppression owes a digest.
	reset_state();
	$GLOBALS['__faz_pending'] = 7;
	for ( $i = 0; $i < 60; $i++ ) {
		$dsar->record_submission( "d$i@example.test" );
	}
	$dsar->announce_suppressed( 50 );
	assert_eq( count( $GLOBALS['__faz_mail'] ), 1, 'the admin is told that requests are arriving unannounced' );
	$body = $GLOBALS['__faz_mail'][0]['body'];
	check( false !== strpos( $body, '60' ), 'the digest reports how many arrived in the window' );
	check( false !== strpos( $body, '7' ), 'the digest reports how many are awaiting action' );
	check( false !== strpos( $body, 'edit.php?post_type=faz_dsar' ), 'the digest links to the stored requests' );
	check( false !== strpos( $body, 'faz_dsar_pre_submit' ), 'the digest names the gate the site is missing' );
	assert_eq( $GLOBALS['__faz_mail'][0]['to'], 'admin@example.test', 'it goes to the site admin' );

	// Hourly, not once per day: a single mail at the moment the ceiling tripped
	// would be the easiest thing in the world to bury.
	$dsar->announce_suppressed( 50 );
	assert_eq( count( $GLOBALS['__faz_mail'] ), 1, 'it is not repeated within the hour' );
	$GLOBALS['__faz_now'] += HOUR_IN_SECONDS + 1;
	$dsar->announce_suppressed( 50 );
	assert_eq( count( $GLOBALS['__faz_mail'] ), 2, 'and it comes back the next hour, while the flood lasts' );

	// A missing admin address must not fatal the path.
	reset_state();
	$GLOBALS['__faz_options']['admin_email'] = 'not-an-address';
	$dsar->announce_suppressed( 50 );
	assert_eq( count( $GLOBALS['__faz_mail'] ), 0, 'nothing is mailed into the void' );
	$GLOBALS['__faz_options']['admin_email'] = 'admin@example.test';

	// ── 5a-bis. The site-wide counter outlives the object cache ─────────────

	echo "— the site-wide counter is not resettable by a cache flush\n";

	// Where an external object cache is installed, a transient with an expiry
	// never reaches the options table: it lives in that cache alone, and a
	// flush or an eviction takes it with it. These ceilings bound a flood for
	// the whole site, so they may only be reset by time.
	reset_state();
	add_filter(
		'faz_dsar_max_per_ip_per_day',
		function () {
			return 0;
		}
	);
	add_filter(
		'faz_dsar_max_stored_per_day',
		function () {
			return 2;
		}
	);
	$dsar->record_submission( 'c1@example.test' );
	$dsar->record_submission( 'c2@example.test' );
	check( is_wp_error( $dsar->guard_submission( ctx( 'c3@example.test' ) ) ), 'the hard ceiling holds' );

	// Simulate exactly what an object-cache flush destroys, and nothing else.
	$GLOBALS['__faz_transients'] = array();
	check( is_wp_error( $dsar->guard_submission( ctx( 'c4@example.test' ) ) ), 'and it still holds after every transient is gone' );

	// Time, and only time, reopens it.
	$GLOBALS['__faz_now'] += DAY_IN_SECONDS + 1;
	assert_eq( $dsar->guard_submission( ctx( 'c5@example.test' ) ), true, 'the window reopens when it expires' );

	// ── 5b. The count that no amount of traffic can suppress ────────────────

	echo "— the pending count behind the menu badge\n";

	reset_state();
	$GLOBALS['__faz_pending'] = 4;
	assert_eq( FazCookie\Includes\DSAR_Shortcode::pending_count(), 4, 'the count reports requests awaiting action' );
	assert_eq( $GLOBALS['__faz_queries'], 1, 'it queries once' );
	$GLOBALS['__faz_pending'] = 99;
	assert_eq( FazCookie\Includes\DSAR_Shortcode::pending_count(), 4, 'and is memoised' );
	assert_eq( $GLOBALS['__faz_queries'], 1, 'with no second query inside the window' );
	$GLOBALS['__faz_now'] += ( 5 * MINUTE_IN_SECONDS ) + 1;
	assert_eq( FazCookie\Includes\DSAR_Shortcode::pending_count(), 99, 'the memo expires so the badge cannot go stale' );

	// ── 5c. The hard ceiling ────────────────────────────────────────────────

	echo "— the hard ceiling is the only one that refuses\n";

	reset_state();
	add_filter(
		'faz_dsar_max_per_ip_per_day',
		function () {
			return 0;
		}
	);
	add_filter(
		'faz_dsar_max_stored_per_day',
		function () {
			return 2;
		}
	);
	for ( $i = 0; $i < 2; $i++ ) {
		$GLOBALS['__faz_ip'] = "203.0.113.$i";
		assert_eq( $dsar->guard_submission( ctx( "h$i@example.test" ) ), true, 'submission ' . ( $i + 1 ) . ' under the hard ceiling is accepted' );
		$dsar->record_submission( "h$i@example.test" );
	}
	$GLOBALS['__faz_ip'] = '203.0.113.250';
	$verdict             = $dsar->guard_submission( ctx( 'blocked@example.test' ) );
	check( is_wp_error( $verdict ), 'the hard ceiling refuses a fresh IP' );
	assert_eq( err_code( $verdict ), 'faz_dsar_site_throttled', 'coded as the site ceiling' );
	check( false !== strpos( err_msg( $verdict ), 'email us directly' ), 'the refusal keeps a lawful channel open (Art. 12(2))' );

	// ── 5b. Claiming and releasing a slot ───────────────────────────────────

	echo "— claiming and releasing a slot\n";

	reset_state();
	$dsar->record_submission( 'claim@example.test' );
	check( is_wp_error( $dsar->guard_submission( ctx( 'claim@example.test' ) ) ), 'a claimed address is held' );
	$dsar->release_submission( 'claim@example.test' );
	assert_eq( $dsar->guard_submission( ctx( 'claim@example.test' ) ), true, 'releasing lets the address retry at once' );

	// The counters are deliberately NOT given back: re-reading and rewriting
	// them would widen the very race the early claim narrows.
	reset_state();
	add_filter(
		'faz_dsar_max_per_ip_per_day',
		function () {
			return 1;
		}
	);
	$dsar->record_submission( 'kept@example.test' );
	$dsar->release_submission( 'kept@example.test' );
	check( is_wp_error( $dsar->guard_submission( ctx( 'other@example.test' ) ) ), 'a released slot is not handed back to the ceiling' );

	// ── 6. Cap filters ───────────────────────────────────────────────────────

	echo "— cap filters\n";

	reset_state();
	add_filter(
		'faz_dsar_max_per_ip_per_day',
		function () {
			return 1;
		}
	);
	$dsar->record_submission( 'first@example.test' );
	check( is_wp_error( $dsar->guard_submission( ctx( 'second@example.test' ) ) ), 'a tightened per-IP cap takes effect' );

	reset_state();
	add_filter(
		'faz_dsar_max_per_ip_per_day',
		function () {
			return -5;
		}
	);
	for ( $i = 0; $i < 3; $i++ ) {
		$dsar->record_submission( "n$i@example.test" );
	}
	assert_eq( $dsar->guard_submission( ctx( 'neg@example.test' ) ), true, 'a negative cap is clamped to "disabled", never to a refusal' );

	// ── Summary ──────────────────────────────────────────────────────────────

	echo "\n";
	if ( $fail ) {
		echo "FAILED: $fail of " . ( $pass + $fail ) . "\n";
		exit( 1 );
	}
	echo "ALL PASS: $pass assertions passed\n";
	exit( 0 );
}
