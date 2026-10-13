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
	// The resolver is stubbed — these tests are about what the guard does with
	// the address, not about how it is found. Everything downstream of it
	// (faz_client_ip_identifies_visitor() and the trusted-proxy helpers) is the
	// REAL implementation, loaded from includes/class-utils.php below: the
	// verdict "this address identifies one visitor" is exactly what gates the
	// per-IP ceiling, so stubbing it would test the stub.
	function faz_resolve_client_ip() {
		return $GLOBALS['__faz_ip'];
	}
	function sanitize_text_field( $value ) {
		return trim( preg_replace( '/[\r\n\t\0\x0B]+/', '', (string) $value ) );
	}
	function wp_unslash( $value ) {
		return $value;
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
	// add_option() is the lock: it succeeds once and fails while the row
	// exists, which is what makes it atomic on MySQL (INSERT IGNORE).
	function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $key, $GLOBALS['__faz_options'] ) ) {
			return false;
		}
		$GLOBALS['__faz_options'][ $key ] = $value;
		return true;
	}
	// For the REAL faz_throttle_request(), loaded from class-utils.php below.
	function absint( $n ) {
		return abs( (int) $n );
	}
	function wp_using_ext_object_cache() {
		return false;
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

	// Real helpers: faz_client_ip_identifies_visitor(), faz_trusted_proxy_cidrs(),
	// faz_remote_addr_is_trusted_proxy(), faz_forwarded_client_ip(). The stubbed
	// faz_resolve_client_ip() above is declared first, so the function_exists
	// guard in this file leaves it in place.
	require_once __DIR__ . '/../../includes/class-utils.php';
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

	// NOTE on what the fixed address below means. Holding ONE address across
	// several different email addresses is the attacker this ceiling exists for
	// — and it is also, on the wrong topology, every visitor of a site behind a
	// proxy. The two cases are told apart by the address itself: 203.0.113.7 is
	// a public address that identifies one client, so the ceiling applies here.
	// Section 3b below covers the other half, which this section cannot see.
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

	// ── 3b. The ceiling needs a key that is ONE visitor's ───────────────────

	echo "— the per-IP ceiling only applies where the address identifies a visitor\n";

	// The ceiling above is keyed on faz_resolve_client_ip(). Behind a CDN or a
	// reverse proxy whose forwarded header cannot be read, that is the PROXY's
	// address and every visitor shares it: five submissions would then refuse
	// everybody else's Art. 15-22 request for 24 hours, which is the site-wide
	// denial the design explicitly rejects at 500 and must not reintroduce at
	// 5. So where the address cannot be shown to identify a visitor the layer
	// is skipped, and the site-wide ceilings bound the flood instead.

	// 172.70.100.5 is inside Cloudflare's published ranges (172.64.0.0/13):
	// what comes back when CF-Connecting-IP was missing or unusable.
	foreach ( array(
		'a Cloudflare edge address' => '172.70.100.5',
		'an RFC1918 peer'           => '10.0.0.5',
		'a loopback peer'           => '127.0.0.1',
		'no address at all'         => '',
	) as $what => $shared_ip ) {
		reset_state();
		$GLOBALS['__faz_ip'] = $shared_ip;
		$subjects            = 12; // More than twice MAX_PER_IP_PER_DAY.
		$refused             = 0;
		for ( $i = 0; $i < $subjects; $i++ ) {
			if ( is_wp_error( $dsar->guard_submission( ctx( "shared$i@example.test" ) ) ) ) {
				++$refused;
			}
			$dsar->record_submission( "shared$i@example.test" );
			$GLOBALS['__faz_now'] += HOUR_IN_SECONDS + 1;
		}
		assert_eq( $refused, 0, "$subjects distinct subjects behind $what are all accepted" );
	}

	// …while a real attacker on one real public address is still capped. This
	// is the reported spam chain, and the reason the ceiling exists at all.
	reset_state();
	$GLOBALS['__faz_ip'] = '203.0.113.7';
	$refused             = 0;
	for ( $i = 0; $i < 12; $i++ ) {
		if ( is_wp_error( $dsar->guard_submission( ctx( "flood$i@example.test" ) ) ) ) {
			++$refused;
		}
		$dsar->record_submission( "flood$i@example.test" );
		$GLOBALS['__faz_now'] += HOUR_IN_SECONDS + 1;
	}
	assert_eq( $refused, 12 - $cap, 'one public address is still capped at MAX_PER_IP_PER_DAY' );

	// Only the site-wide ceiling bounds the unidentifiable case — which is the
	// trade: 500 rows a day instead of closing the form at 5.
	reset_state();
	$GLOBALS['__faz_ip'] = '172.70.100.5';
	add_filter(
		'faz_dsar_max_stored_per_day',
		function () {
			return 3;
		}
	);
	for ( $i = 0; $i < 3; $i++ ) {
		assert_eq( $dsar->guard_submission( ctx( "w$i@example.test" ) ), true, 'submission ' . ( $i + 1 ) . ' under the hard ceiling is accepted behind a shared address' );
		$dsar->record_submission( "w$i@example.test" );
		$GLOBALS['__faz_now'] += HOUR_IN_SECONDS + 1;
	}
	$verdict = $dsar->guard_submission( ctx( 'bounded@example.test' ) );
	check( is_wp_error( $verdict ), 'the site-wide ceiling is what eventually bounds a shared address' );
	assert_eq( err_code( $verdict ), 'faz_dsar_site_throttled', 'and it is the site ceiling that says so, not the per-IP one' );

	// A skipped layer must not be invisible: the admin has a filter to set and
	// no way to discover it otherwise.
	reset_state();
	$GLOBALS['__faz_ip'] = '172.70.100.5';
	$dsar->guard_submission( ctx( 'quiet@example.test' ) );
	$skipped = false;
	foreach ( $GLOBALS['__faz_fired'] as $entry ) {
		if ( 'faz_dsar_ip_layer_skipped' === $entry[0] ) {
			$skipped = true;
		}
	}
	check( $skipped, 'skipping the layer fires faz_dsar_ip_layer_skipped' );
	check( false !== get_transient( 'faz_dsar_rl_ip_unidentified' ), 'and records the condition for the digest' );
	foreach ( array_keys( $GLOBALS['__faz_transients'] ) as $k ) {
		check( 0 === strpos( $k, 'faz_dsar_rl_' ), "the recorded condition key $k shares the resettable prefix" );
	}
	assert_eq( count( $GLOBALS['__faz_mail'] ), 0, 'and it does not mail once per request' );

	// It rides the hourly digest instead.
	for ( $i = 0; $i < 60; $i++ ) {
		$dsar->record_submission( "q$i@example.test" );
	}
	$dsar->announce_suppressed( 50 );
	assert_eq( count( $GLOBALS['__faz_mail'] ), 1, 'the digest still goes out' );
	check( false !== strpos( $GLOBALS['__faz_mail'][0]['body'], 'faz_trusted_proxy_cidrs' ), 'and names the filter that gives the ceiling a per-visitor key' );

	// A refusal, on the other hand, must announce itself: announce_suppressed()
	// is keyed to the MAIL ceiling and is only reachable once a request has
	// been stored, so it cannot carry this.
	reset_state();
	$GLOBALS['__faz_ip'] = '203.0.113.7';
	for ( $i = 0; $i < $cap; $i++ ) {
		$dsar->record_submission( "n$i@example.test" );
	}
	$GLOBALS['__faz_mail'] = array();
	$verdict               = $dsar->guard_submission( ctx( 'refused@example.test' ) );
	assert_eq( err_code( $verdict ), 'faz_dsar_ip_throttled', 'the ceiling refuses an identified address' );
	assert_eq( count( $GLOBALS['__faz_mail'] ), 1, 'and the admin is told that it refused' );
	$ip_body = $GLOBALS['__faz_mail'][0]['body'];
	check( false !== strpos( $ip_body, 'faz_dsar_max_per_ip_per_day' ), 'the notice names the ceiling filter' );
	check( false !== strpos( $ip_body, 'faz_trust_proxy_headers' ), 'and the proxy filters to configure' );
	check( false !== strpos( $ip_body, 'faz_trusted_proxy_cidrs' ), 'including the shared range list' );
	$dsar->guard_submission( ctx( 'refused2@example.test' ) );
	assert_eq( count( $GLOBALS['__faz_mail'] ), 1, 'at most one such notice per hour' );
	$GLOBALS['__faz_now'] += HOUR_IN_SECONDS + 1;
	$dsar->guard_submission( ctx( 'refused3@example.test' ) );
	assert_eq( count( $GLOBALS['__faz_mail'] ), 2, 'and it comes back the next hour while refusals last' );
	foreach ( array_keys( $GLOBALS['__faz_transients'] ) as $k ) {
		check( 0 === strpos( $k, 'faz_dsar_rl_' ), "the notice key $k shares the resettable prefix" );
	}

	// ── 3d. The 60-second lock steps aside for the same reason ──────────────

	echo "— the 60-second lock only applies where the address identifies a visitor\n";

	// The lock is keyed on the same hash as the ceiling. Behind a proxy whose
	// forwarded header cannot be read, a flood from ONE source holds the lock
	// for the whole site, and every legitimate subject is told to wait a
	// minute — which, repeated, is the same denial as the ceiling's, a minute
	// at a time. So the lock is skipped where the address is shared, and the
	// per-email limit and the cumulative ceilings keep bounding the flood.

	// A real public address: the lock spaces submissions a minute apart.
	reset_state();
	$GLOBALS['__faz_ip'] = '203.0.113.7';
	check( $dsar->claim_connection_lock(), 'a free lock is claimed' );
	check( ! $dsar->claim_connection_lock(), 'and a second claim from the same address inside the minute is refused' );
	$dsar->release_connection_lock( true );
	check( ! $dsar->claim_connection_lock(), 'releasing after a stored submission keeps the 60-second window' );
	$GLOBALS['__faz_now'] += 61;
	check( $dsar->claim_connection_lock(), 'the window expires after a minute' );
	$dsar->release_connection_lock( false );
	check( $dsar->claim_connection_lock(), 'rolling the window back (server error) lets the visitor retry at once' );
	$lock_keys = array_filter(
		array_keys( $GLOBALS['__faz_options'] ),
		function ( $k ) {
			return 0 === strpos( $k, 'faz_dsar_lock_' );
		}
	);
	assert_eq( count( $lock_keys ), 1, 'the lock row is an option named faz_dsar_lock_*' );

	// A shared address: no lock, and nothing written under its key.
	foreach ( array(
		'a Cloudflare edge address' => '172.70.100.5',
		'an RFC1918 peer'           => '10.0.0.5',
		'a loopback peer'           => '127.0.0.1',
		'no address at all'         => '',
	) as $what => $shared_ip ) {
		reset_state();
		$GLOBALS['__faz_ip'] = $shared_ip;
		$held                = 0;
		for ( $i = 0; $i < 5; $i++ ) {
			if ( ! $dsar->claim_connection_lock() ) {
				++$held;
			}
			$dsar->release_connection_lock( true );
		}
		assert_eq( $held, 0, "5 back-to-back submissions behind $what are never told to wait" );
		foreach ( array_keys( $GLOBALS['__faz_options'] ) as $k ) {
			check( 0 !== strpos( $k, 'faz_dsar_lock_' ), "no lock row is written for $what ($k)" );
		}
		foreach ( array_keys( $GLOBALS['__faz_transients'] ) as $k ) {
			check( 0 !== strpos( $k, 'faz_dsar_rl_' ) || 0 === strpos( $k, 'faz_dsar_rl_cnt_' ) || 'faz_dsar_rl_ip_unidentified' === $k, "no window transient is armed for $what ($k)" );
		}
	}

	// ── 3e. faz_throttle_request() states the same policy, and now keeps it ──

	echo "— faz_throttle_request() skips an address that does not identify a visitor\n";

	// The helper the consent log and the AMP bridge throttle on. Its docblock
	// claimed it already skipped for unidentifiable callers, but it only
	// skipped an EMPTY address: behind a loopback proxy every visitor shared
	// one bucket, and the consent log of every second visitor was dropped.
	reset_state();
	$GLOBALS['__faz_ip'] = '203.0.113.7';
	check( ! faz_throttle_request( 'faz_unit', 10 ), 'a first request from a public address is not throttled' );
	check( faz_throttle_request( 'faz_unit', 10 ), 'a second one inside the window is' );
	$GLOBALS['__faz_now'] += 11;
	check( ! faz_throttle_request( 'faz_unit', 10 ), 'and the window expires' );

	foreach ( array(
		'a Cloudflare edge address' => '172.70.100.5',
		'an RFC1918 peer'           => '10.0.0.5',
		'a loopback peer'           => '127.0.0.1',
		'no address at all'         => '',
	) as $what => $shared_ip ) {
		reset_state();
		$GLOBALS['__faz_ip'] = $shared_ip;
		$throttled           = 0;
		for ( $i = 0; $i < 5; $i++ ) {
			if ( faz_throttle_request( 'faz_unit', 10 ) ) {
				++$throttled;
			}
		}
		assert_eq( $throttled, 0, "5 back-to-back requests behind $what are never throttled" );
		assert_eq( count( $GLOBALS['__faz_transients'] ), 0, "and no bucket is written for $what" );
	}

	// ── 3c. Which forwarded value may be believed ───────────────────────────


	echo "— forwarded headers: the resolved address is not the client's to choose\n";

	// These exercise the REAL helpers behind faz_resolve_client_ip() (the
	// resolver itself stays stubbed above, since the sections around this one
	// drive it by $GLOBALS['__faz_ip']). Both bugs they pin were real: a
	// leftmost X-Forwarded-For entry is whatever the client sent, because a
	// proxy APPENDS to that header, and an nginx that writes X-Real-IP never
	// touches X-Forwarded-For at all, so reading XFF there reads the client.
	//
	// resolved() is the resolver's DEFAULT path, reproduced from its two real
	// halves in the order it calls them, because the resolver itself is stubbed
	// at the top of this file for the sections around this one. Both judgments
	// it composes — "is this peer infrastructure?" and "does it forward a
	// client address that may be believed?" — are the shipped implementations,
	// as is faz_client_ip_identifies_visitor() below.
	$resolved = function ( $remote_addr ) {
		if ( faz_remote_addr_is_trusted_proxy( $remote_addr ) ) {
			$forwarded = faz_forwarded_client_ip( $remote_addr );
			if ( '' !== $forwarded ) {
				return $forwarded;
			}
		}
		return $remote_addr;
	};

	// An UNCONFIGURED loopback peer reads nothing. This is the default, and it
	// has to be: nginx's own convention is `proxy_set_header X-Real-IP
	// $remote_addr;` with nothing managing X-Forwarded-For, so on that host —
	// which is this repo's own faz-test — an X-Forwarded-For default would have
	// read a header written by nobody but the visitor. The visitor would then
	// have been naming their own throttle key and their own consent-log
	// ip_hash, which is worse than the shared key the per-IP ceiling is being
	// fixed for: a shared key costs the ceiling, a chosen key costs the ceiling
	// AND poisons the log.
	$_SERVER             = array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' );
	$GLOBALS['__faz_ip'] = $resolved( '127.0.0.1' );
	assert_eq( faz_forwarded_client_ip( '127.0.0.1' ), '', 'an unconfigured loopback peer reads no header at all' );
	assert_eq( $GLOBALS['__faz_ip'], '127.0.0.1', 'so it resolves to the peer address, not to the address the client sent' );
	check( ! faz_client_ip_identifies_visitor(), 'and that address does not identify a visitor, so the per-IP ceiling is skipped rather than chosen' );

	// Self-appending a plausible trusted hop does not help either: the walk
	// never starts, because no header was named.
	$_SERVER             = array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 172.70.1.1' );
	$GLOBALS['__faz_ip'] = $resolved( '127.0.0.1' );
	check( '9.9.9.9' !== faz_forwarded_client_ip( '127.0.0.1' ), 'a forged trusted-looking tail does not promote the client entry' );
	assert_eq( $GLOBALS['__faz_ip'], '127.0.0.1', 'the unconfigured peer address still stands' );
	check( ! faz_client_ip_identifies_visitor(), 'and still does not identify a visitor' );

	// Named header, nginx recipe 1: `proxy_set_header X-Forwarded-For
	// $proxy_add_x_forwarded_for;`. The proxy appends, so the client's own
	// entries stay to the LEFT and the rightmost hop is the one it saw.
	add_filter(
		'faz_loopback_proxy_header',
		function () {
			return 'HTTP_X_FORWARDED_FOR';
		}
	);
	$_SERVER = array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.77' );
	assert_eq( faz_forwarded_client_ip( '127.0.0.1' ), '198.51.100.77', 'the chain is walked from the right, not the left' );
	assert_eq( $resolved( '127.0.0.1' ), '198.51.100.77', 'and that is what the peer resolves to once its header is named' );
	$_SERVER = array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, garbage' );
	assert_eq( faz_forwarded_client_ip( '127.0.0.1' ), '', 'an unusable rightmost hop stops the walk instead of trusting the left' );
	$GLOBALS['__faz_filters']['faz_loopback_proxy_header'] = array();

	// Named header, nginx recipe 2: X-Real-IP is the header it writes,
	// X-Forwarded-For is the client's.
	add_filter(
		'faz_loopback_proxy_header',
		function () {
			return 'HTTP_X_REAL_IP';
		}
	);
	$_SERVER = array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_X_REAL_IP' => '198.51.100.90' );
	assert_eq( faz_forwarded_client_ip( '127.0.0.1' ), '198.51.100.90', 'the named header is used and the forged X-Forwarded-For ignored' );
	$_SERVER = array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' );
	assert_eq( faz_forwarded_client_ip( '127.0.0.1' ), '', 'with the named header absent there is no fallback to the forged one' );
	$GLOBALS['__faz_filters']['faz_loopback_proxy_header'] = array();

	// Cloudflare: CF-Connecting-IP only, and only from inside its ranges.
	$_SERVER = array( 'REMOTE_ADDR' => '172.70.100.5', 'HTTP_X_REAL_IP' => '1.2.3.4' );
	assert_eq( faz_forwarded_client_ip( '172.70.100.5' ), '', 'a CF edge does not hand over a forged X-Real-IP' );
	$_SERVER = array( 'REMOTE_ADDR' => '172.70.100.5', 'HTTP_CF_CONNECTING_IP' => '198.51.100.91' );
	assert_eq( faz_forwarded_client_ip( '172.70.100.5' ), '198.51.100.91', 'a CF edge is believed on CF-Connecting-IP' );
	$_SERVER = array( 'REMOTE_ADDR' => '203.0.113.99', 'HTTP_CF_CONNECTING_IP' => '8.8.8.8' );
	check( ! faz_remote_addr_is_trusted_proxy( '203.0.113.99' ), 'a public peer outside the ranges is not a proxy' );

	// A range an admin added for their own CDN: it must actually do something,
	// and that something must not be "believe Cloudflare's header from a peer
	// that is not Cloudflare".
	add_filter(
		'faz_trusted_proxy_cidrs',
		function ( $cidrs ) {
			return array_merge( (array) $cidrs, array( '203.0.113.0/24' ) );
		}
	);
	check( faz_remote_addr_is_trusted_proxy( '203.0.113.9' ), 'an admin-added range makes its peer infrastructure' );
	$_SERVER             = array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.5' );
	$GLOBALS['__faz_ip'] = $resolved( '203.0.113.9' );
	assert_eq( faz_forwarded_client_ip( '203.0.113.9' ), '198.51.100.5', 'and its X-Forwarded-For is read, so adding the range is not a no-op' );
	assert_eq( $GLOBALS['__faz_ip'], '198.51.100.5', 'the resolved address is the forwarded client' );
	check( faz_client_ip_identifies_visitor(), 'which identifies a visitor, so the per-IP ceiling applies again' );

	// The same chain shape as the forged one above, but from a peer that really
	// does append: the rightmost non-hop entry is the client.
	$_SERVER = array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.5, 203.0.113.9' );
	assert_eq( faz_forwarded_client_ip( '203.0.113.9' ), '198.51.100.5', 'its own hop in the chain is stepped over' );

	// CF-Connecting-IP is bound to the SHIPPED ranges, not to the filtered
	// list: an admin-added range must never make a header that CDN does not
	// write authoritative.
	$_SERVER             = array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '8.8.8.8' );
	$GLOBALS['__faz_ip'] = $resolved( '203.0.113.9' );
	assert_eq( faz_forwarded_client_ip( '203.0.113.9' ), '', 'CF-Connecting-IP is ignored from a peer matching only an admin-added range' );
	assert_eq( $GLOBALS['__faz_ip'], '203.0.113.9', 'so that request resolves to the edge, not to the address it claimed' );
	check( ! faz_client_ip_identifies_visitor(), 'and the edge address does not identify a visitor' );
	check( ! faz_ip_in_cidr_list( '203.0.113.9', faz_cloudflare_proxy_cidrs() ), 'the shipped Cloudflare list is not widened by the filter' );
	$GLOBALS['__faz_filters']['faz_trusted_proxy_cidrs'] = array();

	// An RFC1918 peer is NOT a verified proxy: on a LAN-hosted or
	// containerised site the visitor's own peer address can be private, which
	// would make the visitor the proxy and the header theirs to write.
	check( ! faz_remote_addr_is_trusted_proxy( '10.0.0.5' ), 'an RFC1918 peer is not a verified proxy' );
	check( faz_remote_addr_is_trusted_proxy( '127.0.0.1' ), 'loopback is' );
	check( faz_remote_addr_is_trusted_proxy( '172.70.100.5' ), 'and so is a shipped trusted-proxy range' );
	$_SERVER = array();
	reset_state();

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
