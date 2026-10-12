<?php
/**
 * Standalone unit tests for the DSAR form's built-in anti-bot layer.
 *
 * Context: a site reported "a Chain of Spam Submissions" through
 * [faz_dsar_form]. Their own protection (OpenPorte, ALTCHA-based) never saw the
 * form, because it posts to a plugin-specific `wp_ajax_faz_dsar_submit` action
 * that no third-party form-protection plugin knows about. What was left to stop
 * the flood was a honeypot with a field name hardcoded into every copy of the
 * plugin — omit it, zero cost — and a nonce, which for an anonymous visitor
 * WordPress derives from uid 0 and an empty session token, so one scraped value
 * is valid site-wide from every address for 12 to 24 hours. Neither is a
 * per-submission cost.
 *
 * What is pinned here is therefore both halves of the contract, and the second
 * half is the one that matters most:
 *
 *   - the cost: a per-render honeypot name, a signed minimum dwell time, and an
 *     opt-in proof of work, each of which a script posting straight to the
 *     endpoint fails;
 *   - the LIMIT of that cost: this form is how a person exercises GDPR
 *     Art. 15-22, and Art. 12(2) obliges the controller to facilitate it. A
 *     gate that refuses a genuine request is not an inconvenience, it is a
 *     denial of a right. So every check fails OPEN — including, explicitly,
 *     "proof of work enabled and JavaScript disabled", which must still be
 *     accepted rather than silently dropped.
 *
 * `time()` is stubbed inside the class's own namespace so the dwell window can
 * be walked second by second, exactly as the sibling spam-guard suite does.
 *
 * Run: php tests/unit/test-dsar-antibot-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	// Namespace-scoped stub: PHP resolves an unqualified time() call inside
	// FazCookie\Includes to this function before the global one.
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
	define( 'FAZ_PLUGIN_URL', 'https://example.test/wp-content/plugins/faz-cookie-manager/' );
	define( 'FAZ_VERSION', '1.34.2' );

	$GLOBALS['__faz_now']        = 1760000000;
	$GLOBALS['__faz_ip']         = '203.0.113.7';
	$GLOBALS['__faz_transients'] = array();
	$GLOBALS['__faz_filters']    = array();
	$GLOBALS['__faz_fired']      = array();
	$GLOBALS['__faz_mail']       = array();
	$GLOBALS['__faz_options']    = array( 'admin_email' => 'admin@example.test' );
	$GLOBALS['__faz_pending']    = 0;
	$GLOBALS['__faz_queries']    = 0;
	$GLOBALS['__faz_rand']       = 0;

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
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
	function esc_url( $url ) {
		return $url;
	}
	function esc_html_e( $text, $domain = null ) {
		echo htmlspecialchars( (string) $text, ENT_QUOTES );
	}
	function esc_attr_e( $text, $domain = null ) {
		echo htmlspecialchars( (string) $text, ENT_QUOTES );
	}
	function add_shortcode() {}
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		$atts = (array) $atts;
		$out  = array();
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}
		return $out;
	}
	function add_action( $hook, $cb = null, $prio = 10, $args = 1 ) {}
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
		$args                     = array_slice( func_get_args(), 1 );
		$GLOBALS['__faz_fired'][] = array_merge( array( $hook ), $args );
	}
	function wp_salt( $scheme = 'auth' ) {
		return 'unit-test-salt-' . $scheme;
	}
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
	function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) {
		if ( isset( $GLOBALS['__faz_options'][ $key ] ) ) {
			return false;
		}
		$GLOBALS['__faz_options'][ $key ] = $value;
		return true;
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
	// Deterministic, so a test can assert what render() printed; the real one
	// is seeded from random_bytes(), which issue_stamp() relies on and which
	// nothing here is trying to test.
	function wp_generate_password( $length = 12, $special = true, $extra = true ) {
		return substr( str_repeat( 'a1b2c3d4e5f6g7h8', 4 ), 0, $length );
	}
	function wp_rand( $min = 0, $max = 0 ) {
		return $min;
	}
	function wp_create_nonce( $action = '' ) {
		return 'nonce-' . $action;
	}
	function wp_register_style() {}
	function wp_enqueue_style() {}
	function wp_add_inline_style() {}
	function wp_script_is( $handle, $list = 'enqueued' ) {
		return false;
	}
	function wp_register_script() {}
	function wp_localize_script() {}
	function wp_enqueue_script() {}
	function faz_asset_suffix( $relative_path ) {
		return '.min';
	}
	function faz_load_shortcode_assets_everywhere( $asset ) {
		return false;
	}

	// Real helpers behind the IP layer; the resolver above stays stubbed.
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
		if ( $actual === $expected ) {
			global $pass;
			++$pass;
			return;
		}
		global $fail;
		++$fail;
		echo '  FAIL: ' . $label . ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
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
		$GLOBALS['__faz_now']        = 1760000000;
		$GLOBALS['__faz_ip']         = '203.0.113.7';
		$GLOBALS['__faz_options']    = array(
			'admin_email' => 'admin@example.test',
			// The version that stamps forms has been live for a month, so the
			// grace window for unstamped submissions is shut unless a test
			// opens it. Tests that care set this explicitly.
			'faz_dsar_stamped_since' => 1760000000 - ( 30 * DAY_IN_SECONDS ),
		);
		$_POST                       = array();
	}

	function ctx( $email = 'subject@example.test' ) {
		return array(
			'name'    => 'Jane Doe',
			'email'   => $email,
			'type'    => 'erasure',
			'message' => '',
		);
	}

	function fired( $hook ) {
		$out = array();
		foreach ( $GLOBALS['__faz_fired'] as $entry ) {
			if ( $hook === $entry[0] ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	function rejected_codes() {
		$codes = array();
		foreach ( fired( 'faz_dsar_rejected' ) as $entry ) {
			$codes[] = $entry[1];
		}
		return $codes;
	}

	/**
	 * The honeypot field name, derived independently of the implementation.
	 * If the two formulas ever diverge, every honeypot assertion below fails —
	 * which is the point: the server must be able to recompute what it rendered.
	 */
	function hp_name_for( $t ) {
		return 'faz_hp_' . substr( hash_hmac( 'sha256', 'faz_dsar_hp|' . (int) $t, wp_salt() ), 0, 12 );
	}

	/** A submission exactly as a browser posts it, from a given stamp. */
	function browser_post( $stamp, $overrides = array(), $hp_value = '' ) {
		$post = array(
			'action'     => 'faz_dsar_submit',
			'nonce'      => 'nonce-faz_dsar_submit',
			'faz_dsar_t' => (string) $stamp['t'],
			'faz_dsar_s' => $stamp['sig'],
			'dsar_name'  => 'Jane Doe',
			'dsar_email' => 'subject@example.test',
			'dsar_type'  => 'erasure',
		);
		// Every browser posts a text input it was given, empty, whether or not
		// JavaScript runs. That is the premise of the absence rule.
		$post[ hp_name_for( $stamp['t'] ) ] = $hp_value;
		if ( '' !== $stamp['salt'] ) {
			$post['faz_pow_salt'] = $stamp['salt'];
			$post['faz_pow_diff'] = (string) $stamp['diff'];
			$post['faz_pow_n']    = '';
		}
		foreach ( $overrides as $k => $v ) {
			if ( null === $v ) {
				unset( $post[ $k ] );
				continue;
			}
			$post[ $k ] = $v;
		}
		return $post;
	}

	/** What a browser's JavaScript does: search for an answer. */
	function solve_pow( $salt, $diff, $limit = 20000000 ) {
		$target = str_repeat( '0', (int) $diff );
		for ( $n = 0; $n < $limit; $n++ ) {
			if ( 0 === strncmp( hash( 'sha256', $salt . $n ), $target, (int) $diff ) ) {
				return (string) $n;
			}
		}
		return '';
	}

	function enable_pow( $diff = 2 ) {
		add_filter(
			'faz_dsar_pow_enabled',
			function () {
				return true;
			}
		);
		add_filter(
			'faz_dsar_pow_difficulty',
			function () use ( $diff ) {
				return $diff;
			}
		);
	}

	reset_state();
	$dsar = new FazCookie\Includes\DSAR_Shortcode();

	// ── 1. The legitimate submission ─────────────────────────────────────────

	echo "— a real browser submission still goes through\n";

	reset_state();
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8; // the time a person takes to fill three fields
	$_POST                 = browser_post( $stamp );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'a stamped submission eight seconds later is accepted' );
	assert_eq( rejected_codes(), array(), 'and nothing was reported as a refusal' );

	// With the proof of work on, and solved, as the shipped JS does.
	reset_state();
	enable_pow( 3 );
	$stamp = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	check( '' !== $stamp['salt'], 'the gate issues a salt when it is enabled' );
	assert_eq( $stamp['diff'], 3, 'and the requested difficulty' );
	$GLOBALS['__faz_now'] += 8;
	$_POST                 = browser_post( $stamp, array( 'faz_pow_n' => solve_pow( $stamp['salt'], $stamp['diff'] ) ) );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'a correctly solved challenge is accepted' );

	// ── 2. Straight to the endpoint ──────────────────────────────────────────

	echo "— a post straight to the AJAX endpoint\n";

	// The reported attack: scrape one nonce, post to admin-ajax.php forever.
	reset_state();
	$_POST = array(
		'action'     => 'faz_dsar_submit',
		'nonce'      => 'nonce-faz_dsar_submit',
		'dsar_name'  => 'Bot',
		'dsar_email' => 'bot@example.test',
		'dsar_type'  => 'erasure',
	);
	$verdict = $dsar->guard_submission( ctx( 'bot@example.test' ) );
	check( is_wp_error( $verdict ), 'a submission with no dwell signature at all is refused' );
	assert_eq( err_code( $verdict ), 'faz_dsar_stamp', 'coded as the missing/invalid stamp' );
	check( false !== strpos( err_msg( $verdict ), 'reload' ), 'and the visitor is told what to do about it' );

	// …but ONLY once a form rendered by an earlier version can no longer be
	// sitting in a page cache. Inside that window the same submission is
	// accepted, because refusing it would deny Art. 15-22 requests for as long
	// as the cache lives, and a valid nonce cannot tell the two apart.
	$GLOBALS['__faz_options']['faz_dsar_stamped_since'] = $GLOBALS['__faz_now'] - HOUR_IN_SECONDS;
	assert_eq( $dsar->guard_submission( ctx( 'bot@example.test' ) ), true, 'inside the post-update grace window an unstamped submission is accepted' );

	// An install where the activation hook never recorded an epoch must not
	// refuse anybody: an unknown epoch is not grounds for a denial.
	unset( $GLOBALS['__faz_options']['faz_dsar_stamped_since'] );
	assert_eq( $dsar->guard_submission( ctx( 'bot@example.test' ) ), true, 'with no recorded epoch the layer stands aside' );

	// The window closes on its own, and it is measured against the nonce's own
	// lifetime, because "the nonce verified" is what proves the render is recent.
	reset_state();
	$GLOBALS['__faz_options']['faz_dsar_stamped_since'] = $GLOBALS['__faz_now'] - ( 3 * DAY_IN_SECONDS );
	$_POST = array( 'dsar_email' => 'bot@example.test' );
	check( is_wp_error( $dsar->guard_submission( ctx() ) ), 'three days after the update the window is shut' );
	add_filter(
		'nonce_life',
		function () {
			return 30 * DAY_IN_SECONDS;
		}
	);
	assert_eq( $dsar->guard_submission( ctx() ), true, 'a site that lengthens nonce_life lengthens the window with it' );

	// The legacy static honeypot is still read on the unstamped path — a bot
	// replaying an old scraped form body gets caught by the old field too.
	reset_state();
	$GLOBALS['__faz_options']['faz_dsar_stamped_since'] = $GLOBALS['__faz_now'] - HOUR_IN_SECONDS;
	$_POST   = array( 'faz_hp_name' => 'http://spam.example' );
	$verdict = $dsar->guard_submission( ctx() );
	assert_eq( err_code( $verdict ), 'faz_dsar_honeypot', 'a filled legacy honeypot still refuses on the unstamped path' );

	// ── 3. Forging the stamp ─────────────────────────────────────────────────

	echo "— the signature is what stops a forged timestamp\n";

	reset_state();
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;

	// A bot that invents an old timestamp to clear the dwell check.
	$_POST   = browser_post( $stamp, array( 'faz_dsar_t' => (string) ( $GLOBALS['__faz_now'] - 600 ) ) );
	$verdict = $dsar->guard_submission( ctx() );
	assert_eq( err_code( $verdict ), 'faz_dsar_stamp', 'a forged dwell signature is refused' );

	// A bot that keeps the timestamp and invents the signature.
	$_POST   = browser_post( $stamp, array( 'faz_dsar_s' => str_repeat( 'f', 32 ) ) );
	assert_eq( err_code( $dsar->guard_submission( ctx() ) ), 'faz_dsar_stamp', 'an invented signature is refused' );

	// A truncated signature must not pass on a prefix comparison.
	$_POST = browser_post( $stamp, array( 'faz_dsar_s' => substr( $stamp['sig'], 0, 8 ) ) );
	assert_eq( err_code( $dsar->guard_submission( ctx() ) ), 'faz_dsar_stamp', 'a truncated signature is refused' );

	// Lowering the difficulty is covered by the same signature.
	reset_state();
	enable_pow( 4 );
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;
	$_POST                 = browser_post(
		$stamp,
		array(
			'faz_pow_diff' => '1',
			'faz_pow_n'    => solve_pow( $stamp['salt'], 1 ),
		)
	);
	assert_eq( err_code( $dsar->guard_submission( ctx() ) ), 'faz_dsar_stamp', 'a client cannot lower the difficulty it was given' );

	// Nor swap in a salt it has already solved.
	$_POST = browser_post(
		$stamp,
		array(
			'faz_pow_salt' => 'presolvedsalt0000',
			'faz_pow_n'    => solve_pow( 'presolvedsalt0000', $stamp['diff'] ),
		)
	);
	assert_eq( err_code( $dsar->guard_submission( ctx() ) ), 'faz_dsar_stamp', 'a client cannot swap in a pre-solved salt' );

	// ── 4. The dwell window ──────────────────────────────────────────────────

	echo "— minimum dwell time: a script always beats it, a person never does\n";

	$min = FazCookie\Includes\DSAR_Shortcode::MIN_DWELL_SECONDS;
	for ( $elapsed = 0; $elapsed < $min; $elapsed++ ) {
		reset_state();
		$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
		$GLOBALS['__faz_now'] += $elapsed;
		$_POST                 = browser_post( $stamp );
		$verdict               = $dsar->guard_submission( ctx() );
		assert_eq( err_code( $verdict ), 'faz_dsar_too_fast', "a submission $elapsed second(s) after the render is refused" );
		check( false !== strpos( err_msg( $verdict ), 'again' ), 'and is told to submit again, which time alone fixes' );
	}

	reset_state();
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += $min;
	$_POST                 = browser_post( $stamp );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'exactly the minimum is accepted — the boundary is inclusive' );

	// Autofill plus an immediate click is a real person, and the remedy costs
	// them one more click, never the request.
	reset_state();
	$stamp = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$_POST = browser_post( $stamp );
	check( is_wp_error( $dsar->guard_submission( ctx() ) ), 'an instant submission is refused' );
	$GLOBALS['__faz_now'] += $min + 1;
	assert_eq( $dsar->guard_submission( ctx() ), true, 'and the very same form body is accepted a moment later' );

	// Filterable off, for a site whose visitors are being refused.
	reset_state();
	add_filter(
		'faz_dsar_min_dwell_seconds',
		function () {
			return 0;
		}
	);
	$stamp = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$_POST = browser_post( $stamp );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'a dwell minimum filtered to 0 accepts an instant submission' );

	// A clock moved backwards must not refuse an hour of genuine requests.
	reset_state();
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] -= 3600;
	$_POST                 = browser_post( $stamp );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'a future-dated stamp (clock skew) skips the lower bound rather than refusing' );

	echo "— and a replayed signature expires\n";

	reset_state();
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += DAY_IN_SECONDS + 1;
	$_POST                 = browser_post( $stamp );
	$verdict               = $dsar->guard_submission( ctx() );
	assert_eq( err_code( $verdict ), 'faz_dsar_stale', 'a valid signature replayed after the window is refused' );
	check( false !== strpos( err_msg( $verdict ), 'reload' ), 'with the one remedy that works: reload the page' );

	// The upper bound is deliberately not tighter than the nonce beside it, so
	// on a long-cached page the nonce is what fails first.
	reset_state();
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += DAY_IN_SECONDS - 60;
	$_POST                 = browser_post( $stamp );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'a form just under 24 hours old still works' );

	reset_state();
	add_filter(
		'faz_dsar_max_form_age',
		function () {
			return 0;
		}
	);
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 400 * DAY_IN_SECONDS;
	$_POST                 = browser_post( $stamp );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'an upper bound filtered to 0 never expires a form' );

	// ── 5. The honeypot ──────────────────────────────────────────────────────

	echo "— the honeypot is no longer a field a script can simply omit\n";

	reset_state();
	$stamp = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$hp    = hp_name_for( $stamp['t'] );
	check( 'faz_hp_name' !== $hp, 'the field name is not the hardcoded one any more' );
	assert_eq( $stamp['hp'], $hp, 'and the server can recompute it from the stamp, with nothing stored' );

	$later = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 97;
	$other                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	check( $later['hp'] !== $other['hp'], 'a different render gets a different field name' );

	// Filled: the original behaviour, kept.
	reset_state();
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;
	$_POST                 = browser_post( $stamp, array(), 'http://spam.example' );
	$verdict               = $dsar->guard_submission( ctx() );
	assert_eq( err_code( $verdict ), 'faz_dsar_honeypot', 'a filled honeypot is refused' );
	assert_eq( err_msg( $verdict ), 'Submission rejected.', 'with nothing that tells a bot which check it tripped' );

	// Renamed, or omitted: a verified stamp proves the field was rendered, and
	// every browser posts a text input it was given — so absence is a client
	// that did not use the form.
	reset_state();
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;
	$_POST                 = browser_post( $stamp, array( hp_name_for( $stamp['t'] ) => null ) );
	assert_eq( err_code( $dsar->guard_submission( ctx() ) ), 'faz_dsar_honeypot', 'an omitted honeypot field is refused' );

	$_POST = browser_post( $stamp, array( hp_name_for( $stamp['t'] ) => null, 'faz_hp_name' => '' ) );
	assert_eq( err_code( $dsar->guard_submission( ctx() ) ), 'faz_dsar_honeypot', 'and so is the old static name in its place' );

	// …with an escape hatch, because a front end that strips hidden inputs
	// would otherwise refuse every genuine request on that site.
	add_filter(
		'faz_dsar_reject_missing_honeypot',
		function () {
			return false;
		}
	);
	$_POST = browser_post( $stamp, array( hp_name_for( $stamp['t'] ) => null ) );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'the absence rule can be switched off for a front end that strips the field' );
	$_POST = browser_post( $stamp, array( hp_name_for( $stamp['t'] ) => 'spam' ) );
	assert_eq( err_code( $dsar->guard_submission( ctx() ) ), 'faz_dsar_honeypot', 'switching it off does not stop a FILLED field refusing' );

	// ── 6. Proof of work ─────────────────────────────────────────────────────

	echo "— proof of work: off by default, and never a denial when it is on\n";

	reset_state();
	$stamp = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	assert_eq( $stamp['salt'], '', 'no challenge is issued by default' );
	assert_eq( $stamp['diff'], 0, 'and no difficulty' );
	$GLOBALS['__faz_now'] += 8;
	$_POST                 = browser_post( $stamp, array( 'faz_pow_n' => '12345' ) );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'an answer to a challenge that was never issued is simply ignored' );

	// THE assertion this whole gate is judged by: JavaScript off.
	reset_state();
	enable_pow( 3 );
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;
	$_POST                 = browser_post( $stamp ); // faz_pow_n left empty, as a browser with JS off leaves it
	assert_eq( $dsar->guard_submission( ctx() ), true, 'proof of work ON and JavaScript OFF: the submission is ACCEPTED' );
	assert_eq( count( fired( 'faz_dsar_pow_unanswered' ) ), 1, 'and the unanswered challenge is announced rather than swallowed' );
	assert_eq( rejected_codes(), array(), 'nothing was refused' );

	$_POST = browser_post( $stamp, array( 'faz_pow_n' => null ) );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'the field missing entirely is accepted too' );

	// A wrong answer, on the other hand, is a client that tried to fake it.
	reset_state();
	enable_pow( 3 );
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;
	$_POST                 = browser_post( $stamp, array( 'faz_pow_n' => '1' ) );
	assert_eq( err_code( $dsar->guard_submission( ctx() ) ), 'faz_dsar_pow', 'a wrong answer is refused' );

	foreach ( array( 'abc', '-5', '1.5', '0x10', '00000000000000000000001', str_repeat( '9', 64 ) ) as $junk ) {
		$_POST = browser_post( $stamp, array( 'faz_pow_n' => $junk ) );
		assert_eq( err_code( $dsar->guard_submission( ctx() ) ), 'faz_dsar_pow', 'a non-integer answer (' . substr( $junk, 0, 8 ) . ') is refused before hashing' );
	}

	// Whitespace is not a wrong answer, it is no answer — and no answer is the
	// JavaScript-off case, which is accepted. A field that a form serialiser
	// filled with a space must not become a refusal.
	$_POST = browser_post( $stamp, array( 'faz_pow_n' => "  \n" ) );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'a whitespace-only answer is treated as no answer, and accepted' );

	// An answer solved for a DIFFERENT salt than the one signed into the stamp.
	reset_state();
	enable_pow( 3 );
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;
	$foreign               = solve_pow( 'someothersalt123', $stamp['diff'] );
	$_POST                 = browser_post( $stamp, array( 'faz_pow_n' => $foreign ) );
	assert_eq( err_code( $dsar->guard_submission( ctx() ) ), 'faz_dsar_pow', "an answer for another salt is refused" );

	// Replay: one accepted answer per challenge, charged when the submission
	// is accepted, not when the answer is checked.
	reset_state();
	enable_pow( 3 );
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;
	$answer                = solve_pow( $stamp['salt'], $stamp['diff'] );
	$_POST                 = browser_post( $stamp, array( 'faz_pow_n' => $answer ) );
	assert_eq( $dsar->guard_submission( ctx( 'first@example.test' ) ), true, 'the first use of an answer is accepted' );
	$dsar->record_submission( 'first@example.test' );
	$verdict = $dsar->guard_submission( ctx( 'second@example.test' ) );
	assert_eq( err_code( $verdict ), 'faz_dsar_pow_replay', 'replaying it for a second submission is refused' );
	check( false !== strpos( err_msg( $verdict ), 'reload' ), 'and the remedy is a fresh challenge' );

	// A visitor refused by a LATER layer must be able to correct and resubmit
	// the form they still have open, so the challenge is only spent on a
	// submission that was actually recorded.
	reset_state();
	enable_pow( 3 );
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;
	$answer                = solve_pow( $stamp['salt'], $stamp['diff'] );
	$_POST                 = browser_post( $stamp, array( 'faz_pow_n' => $answer ) );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'the answer verifies' );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'and verifying it twice does not spend it — only recording does' );
	$dsar->record_submission( 'subject@example.test' );
	$dsar->release_submission( 'subject@example.test' );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'a submission that could not be stored gives the challenge back' );

	// Every key this gate writes must share the resettable prefix, like the
	// throttles: a key outside it would survive a reset and refuse later runs.
	reset_state();
	enable_pow( 3 );
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;
	$_POST                 = browser_post( $stamp, array( 'faz_pow_n' => solve_pow( $stamp['salt'], $stamp['diff'] ) ) );
	$dsar->record_submission( 'keys@example.test' );
	foreach ( array_keys( $GLOBALS['__faz_transients'] ) as $k ) {
		check( 0 === strpos( $k, 'faz_dsar_rl_' ), "the proof-of-work key $k shares the resettable prefix" );
	}
	$pow_keys = 0;
	foreach ( array_keys( $GLOBALS['__faz_transients'] ) as $k ) {
		if ( 0 === strpos( $k, 'faz_dsar_rl_pow_' ) ) {
			++$pow_keys;
		}
	}
	assert_eq( $pow_keys, 1, 'the spent challenge is recorded exactly once' );
	$keys = implode( ' ', array_keys( $GLOBALS['__faz_transients'] ) );
	check( false === strpos( $keys, $stamp['salt'] ), 'and the salt itself is not readable from the key' );

	// Difficulty is clamped: a well-meant "make it much harder" must not turn
	// into a phone grinding for a minute, which is a denial by another name.
	reset_state();
	add_filter(
		'faz_dsar_pow_difficulty',
		function () {
			return 64;
		}
	);
	assert_eq( FazCookie\Includes\DSAR_Shortcode::pow_difficulty(), 6, 'an absurd difficulty is clamped down' );
	reset_state();
	add_filter(
		'faz_dsar_pow_difficulty',
		function () {
			return 0;
		}
	);
	assert_eq( FazCookie\Includes\DSAR_Shortcode::pow_difficulty(), 1, 'and a zero difficulty is clamped up, not treated as "no hash needed"' );

	// A site that switches the gate off mid-flood must not start refusing the
	// challenges it has already issued, nor ignore answers to them.
	reset_state();
	enable_pow( 3 );
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$answer                = solve_pow( $stamp['salt'], $stamp['diff'] );
	$GLOBALS['__faz_now'] += 8;
	$GLOBALS['__faz_filters'] = array(); // gate switched off between render and submit
	$_POST                    = browser_post( $stamp, array( 'faz_pow_n' => $answer ) );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'an answer to an already-issued challenge is still honoured after the gate is switched off' );
	$_POST = browser_post( $stamp, array( 'faz_pow_n' => '7' ) );
	assert_eq( err_code( $dsar->guard_submission( ctx() ) ), 'faz_dsar_pow', 'and a faked one is still refused' );

	// The default difficulty, solved exactly as the browser does, end to end.
	reset_state();
	enable_pow( FazCookie\Includes\DSAR_Shortcode::POW_DIFFICULTY );
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;
	$answer                = solve_pow( $stamp['salt'], $stamp['diff'] );
	check( '' !== $answer, 'the default difficulty is solvable' );
	assert_eq( strncmp( hash( 'sha256', $stamp['salt'] . $answer ), '0000', 4 ), 0, 'and the answer really does have four leading hex zeros' );
	$_POST = browser_post( $stamp, array( 'faz_pow_n' => $answer ) );
	assert_eq( $dsar->guard_submission( ctx() ), true, 'the default-difficulty answer is accepted' );

	// ── 7. The rendered form ─────────────────────────────────────────────────

	echo "— what the rendered form actually carries\n";

	reset_state();
	$GLOBALS['__faz_now'] = 1760000123;
	$html                 = $dsar->render( array() );
	check( false !== strpos( $html, 'name="faz_dsar_t" value="1760000123"' ), 'the form carries its issue time' );
	$expected_sig = substr( hash_hmac( 'sha256', 'faz_dsar_stamp|1760000123||0', wp_salt() ), 0, 32 );
	check( false !== strpos( $html, 'name="faz_dsar_s" value="' . $expected_sig . '"' ), 'and the signature over it' );
	check( false !== strpos( $html, 'name="' . hp_name_for( 1760000123 ) . '"' ), 'the honeypot is rendered under the derived per-render name' );
	check( false === strpos( $html, 'name="faz_hp_name"' ), 'and the hardcoded name is gone from the markup' );
	check( false === strpos( $html, 'faz_pow_salt' ), 'no challenge is rendered while the gate is off' );

	// Accessibility of the honeypot is not negotiable: it must stay out of the
	// tab order and out of the accessibility tree.
	check( false !== strpos( $html, 'tabindex="-1"' ), 'the honeypot stays out of the tab order' );
	check( 1 < substr_count( $html, 'aria-hidden="true"' ), 'and out of the accessibility tree, wrapper and input' );
	check( false !== strpos( $html, 'autocomplete="off"' ), 'and is not offered to a password manager' );

	reset_state();
	enable_pow( 5 );
	$GLOBALS['__faz_now'] = 1760000123;
	$html                 = $dsar->render( array() );
	check( false !== strpos( $html, 'name="faz_pow_salt" value="' ), 'with the gate on the challenge salt is rendered' );
	check( false !== strpos( $html, 'name="faz_pow_diff" value="5"' ), 'with its difficulty' );
	check( false !== strpos( $html, 'name="faz_pow_n" value=""' ), 'and an empty answer field for the JS to fill' );

	// What the form posts must be exactly what the server expects to verify:
	// parse the rendered markup and submit it, with no help from issue_stamp().
	reset_state();
	enable_pow( 3 );
	$GLOBALS['__faz_now'] = 1760000200;
	$html                 = $dsar->render( array() );
	preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $html, $m, PREG_SET_ORDER );
	$posted = array();
	foreach ( $m as $one ) {
		$posted[ $one[1] ] = $one[2];
	}
	preg_match( '/<input type="text" name="(faz_hp_[a-f0-9]+)"/', $html, $hpm );
	check( ! empty( $hpm[1] ), 'the rendered honeypot name is readable from the markup' );
	$posted[ $hpm[1] ]     = '';
	$posted['dsar_name']   = 'Jane Doe';
	$posted['dsar_email']  = 'subject@example.test';
	$posted['dsar_type']   = 'erasure';
	$posted['faz_pow_n']   = solve_pow( $posted['faz_pow_salt'], (int) $posted['faz_pow_diff'] );
	$GLOBALS['__faz_now'] += 9;
	$_POST                 = $posted;
	assert_eq( $dsar->guard_submission( ctx() ), true, 'the rendered form, filled in and posted back, is accepted' );

	// ── 8. Nothing leaves the server ─────────────────────────────────────────

	echo "— the gate is self-contained\n";

	$source = file_get_contents( __DIR__ . '/../../includes/class-dsar-shortcode.php' );
	// The PHPDoc recipes for Turnstile / hCaptcha / ALTCHA deliberately contain
	// the string wp_remote_post, inside comments, as code for the SITE to run.
	$code_only = preg_replace( '!/\*.*?\*/!s', '', $source );
	$code_only = preg_replace( '!^\s*//.*$!m', '', $code_only );
	foreach ( array( 'wp_remote_', 'curl_', 'file_get_contents(', 'fsockopen' ) as $needle ) {
		check( false === strpos( $code_only, $needle ), "no $needle call in the executable code" );
	}
	$js = file_get_contents( __DIR__ . '/../../frontend/js/faz-dsar.js' );
	foreach ( array( 'http://', 'https://', 'importScripts', 'createElement(\'script\'' ) as $needle ) {
		check( false === strpos( $js, $needle ), "the frontend handler loads no $needle resource" );
	}

	// And the recipes a site owner needs are where they will look for them.
	foreach ( array( 'faz_dsar_pre_submit', 'challenges.cloudflare.com', 'api.hcaptcha.com', 'altcha.org', 'Akismet' ) as $needle ) {
		check( false !== strpos( $source, $needle ), "the filter's docblock documents $needle" );
	}

	// ── Summary ──────────────────────────────────────────────────────────────

	echo "\n";
	if ( $fail ) {
		echo "FAILED: $fail of " . ( $pass + $fail ) . "\n";
		exit( 1 );
	}
	echo "ALL PASS: $pass assertions passed\n";
	exit( 0 );
}
