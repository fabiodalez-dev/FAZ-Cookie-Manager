<?php
/**
 * Standalone unit tests — [faz_do_not_sell] must render the same markup for
 * every visitor.
 *
 * render() used to read `$_COOKIE['fazcookie-dnsmpi']` and emit ONE of two
 * documents: the opt-out form, or a "you have already opted out" notice with
 * only a "withdraw" button. Under a full-page cache the first logged-out
 * visitor to warm the entry decided which of the two everybody else got: a
 * returning opted-out visitor primed the rescind-only page, and until the
 * entry was purged no California visitor had any control to opt OUT with
 * (Cal. Civ. Code 1798.135(a)). The plugin's own Cache Compatibility contract
 * names the DNSMPI opt-out among the states a shared render must not depend
 * on (frontend/class-frontend.php, get_blocked_categories()); this shortcode
 * was the one render that did.
 *
 * Pinned here:
 *
 *   - the markup does not depend on the cookie at all: byte-identical with
 *     and without it;
 *   - both states ship in that one document — the opt-out form live, the
 *     rescind state inert inside a <template> — so frontend/js/faz-dnsmpi.js
 *     can pick from document.cookie, which is the visitor's own and never
 *     cached, while the DOM holds exactly one state at a time;
 *   - a stale nonce (the other thing a cached page freezes) is reported with
 *     a machine-readable code, and a no-store endpoint hands out fresh ones,
 *     so the client can recover instead of telling the visitor to refresh a
 *     page that refreshing does not change.
 *
 * Run: php tests/unit/test-dnsmpi-cache-invariance-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	define( 'FAZ_PLUGIN_URL', 'https://example.test/wp-content/plugins/faz-cookie-manager/' );
	define( 'FAZ_VERSION', '1.34.2' );
	define( 'DAY_IN_SECONDS', 86400 );

	$GLOBALS['__faz_actions']   = array();
	$GLOBALS['__faz_localized'] = array();
	$GLOBALS['__faz_json']      = array();
	$GLOBALS['__faz_nonce_ok']  = true;

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
	function esc_html_e( $text, $domain = null ) {
		echo htmlspecialchars( (string) $text, ENT_QUOTES );
	}
	function esc_url_raw( $url ) {
		return $url;
	}
	function add_shortcode( $tag, $cb ) {}
	function add_action( $hook, $cb = null, $prio = 10, $args = 1 ) {
		$GLOBALS['__faz_actions'][] = $hook;
	}
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		$atts = (array) $atts;
		$out  = array();
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}
		return $out;
	}
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
	function wp_unslash( $value ) {
		return $value;
	}
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . $path;
	}
	function wp_create_nonce( $action = '' ) {
		return 'nonce-' . $action;
	}
	function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
		return $GLOBALS['__faz_nonce_ok'];
	}
	function wp_send_json_error( $data = null, $status_code = null ) {
		$GLOBALS['__faz_json'][] = array( 'success' => false, 'data' => $data );
	}
	function wp_send_json_success( $data = null, $status_code = null ) {
		$GLOBALS['__faz_json'][] = array( 'success' => true, 'data' => $data );
	}
	function wp_register_style() {}
	function wp_enqueue_style() {}
	function wp_add_inline_style() {}
	function wp_script_is( $handle, $list = 'enqueued' ) {
		return false;
	}
	function wp_register_script() {}
	function wp_localize_script( $handle, $name, $data ) {
		$GLOBALS['__faz_localized'][ $name ] = $data;
	}
	function wp_enqueue_script() {}
	function faz_asset_suffix( $relative_path ) {
		return '.min';
	}
	function faz_load_shortcode_assets_everywhere( $asset ) {
		return false;
	}
	function faz_resolve_client_ip() {
		return '203.0.113.7';
	}
	function wp_salt( $scheme = 'auth' ) {
		return 'unit-test-salt-' . $scheme;
	}
	// Multisite naming as faz-cookie-manager.php applies it: on a network every
	// consent-tracking cookie carries the network and blog ids.
	$GLOBALS['__faz_multisite'] = false;
	function faz_consent_cookie_name( $name = 'fazcookie-consent' ) {
		return empty( $GLOBALS['__faz_multisite'] ) ? $name : $name . '-n1-b2';
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-ip-hasher.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-do-not-sell-shortcode.php';

	use FazCookie\Includes\Do_Not_Sell_Shortcode;

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
	function last_json() {
		return end( $GLOBALS['__faz_json'] );
	}

	$dns = new Do_Not_Sell_Shortcode();

	// ── 1. One document for every visitor ────────────────────────────────────

	echo "— the render does not depend on the visitor's cookie\n";

	unset( $_COOKIE[ Do_Not_Sell_Shortcode::COOKIE_NAME ] );
	$without = $dns->render( array() );
	$_COOKIE[ Do_Not_Sell_Shortcode::COOKIE_NAME ] = '1';
	$with                                           = $dns->render( array() );
	unset( $_COOKIE[ Do_Not_Sell_Shortcode::COOKIE_NAME ] );

	check( $with === $without, 'the markup is byte-identical with and without the opt-out cookie' );

	// The live state is the opt-out mechanism: the one the law requires the
	// page to offer, and the one the visitor with no cookie needs.
	check( false !== strpos( $without, 'class="faz-dnsmpi-form"' ), 'the opt-out form is rendered live' );
	check( false !== strpos( $without, 'name="action" value="faz_dnsmpi_optout"' ), 'with its action' );
	check( false !== strpos( $without, 'name="nonce"  value="nonce-faz_dnsmpi_optout"' ), 'and its nonce' );

	// The rescind state travels in the same document, inert, for the client
	// to swap in when document.cookie says the visitor has opted out.
	$tpl_at = strpos( $without, '<template class="faz-dnsmpi-rescind-template"' );
	check( false !== $tpl_at, 'the rescind state is rendered inside a <template>' );
	$rescind_at = strpos( $without, 'class="faz-dnsmpi-rescind-form"' );
	check( false !== $rescind_at && false !== $tpl_at && $rescind_at > $tpl_at, 'and the rescind form is inside it, not live' );
	check( false !== strpos( $without, 'name="nonce"  value="nonce-faz_dnsmpi_rescind"' ), 'with its own nonce' );
	check( false !== strpos( $without, 'You have already submitted an opt-out request' ), 'and the standing notice' );
	check( 1 === substr_count( $without, '<template ' ) && 1 === substr_count( $without, '</template>' ), 'exactly one template, closed' );

	// The two states are distinguishable to the client by one attribute each.
	check( false !== strpos( $without, 'data-faz-dnsmpi-state="optout"' ), 'the live state is labelled optout' );
	check( false !== strpos( $without, 'data-faz-dnsmpi-state="rescind"' ), 'the inert state is labelled rescind' );

	// The client needs to know which cookie to read and where fresh nonces come from.
	$cfg = isset( $GLOBALS['__faz_localized']['fazDnsmpiConfig'] ) ? $GLOBALS['__faz_localized']['fazDnsmpiConfig'] : array();
	check( isset( $cfg['cookieName'] ) && Do_Not_Sell_Shortcode::COOKIE_NAME === $cfg['cookieName'], 'the script is told the cookie name' );
	check( isset( $cfg['nonceAction'] ) && 'faz_dnsmpi_nonce' === $cfg['nonceAction'], 'and the nonce refresh action' );

	// On multisite the server writes and reads `fazcookie-dnsmpi-n<net>-b<blog>`
	// (faz_consent_cookie_name()). Told the bare name, the script read a cookie
	// that never exists there and showed the opt-out form to a visitor who had
	// already opted out on this site.
	$GLOBALS['__faz_multisite'] = true;
	$GLOBALS['__faz_localized'] = array();
	$dns->render( array() );
	$cfg = isset( $GLOBALS['__faz_localized']['fazDnsmpiConfig'] ) ? $GLOBALS['__faz_localized']['fazDnsmpiConfig'] : array();
	check( isset( $cfg['cookieName'] ) && 'fazcookie-dnsmpi-n1-b2' === $cfg['cookieName'], 'multisite: the script is told the per-site cookie name the server writes' );
	$GLOBALS['__faz_multisite'] = false;

	// ── 2. A stale nonce is recoverable, not a dead end ─────────────────────

	echo "— a stale nonce is reported with a code and fresh ones are available\n";

	$GLOBALS['__faz_json']     = array();
	$GLOBALS['__faz_nonce_ok'] = false;
	$dns->handle_optout();
	$res = last_json();
	check( is_array( $res ) && false === $res['success'], 'an expired opt-out nonce is refused' );
	check( is_array( $res['data'] ) && isset( $res['data']['code'] ) && 'stale_nonce' === $res['data']['code'], 'with the machine-readable code stale_nonce' );
	check( is_array( $res['data'] ) && isset( $res['data']['message'] ) && false !== strpos( $res['data']['message'], 'Invalid security token' ), 'and the human message' );

	$GLOBALS['__faz_json'] = array();
	$dns->handle_rescind();
	$res = last_json();
	check( is_array( $res ) && false === $res['success'] && is_array( $res['data'] ) && isset( $res['data']['code'] ) && 'stale_nonce' === $res['data']['code'], 'an expired rescind nonce is refused with the same code' );

	// The refresh endpoint: registered for visitors and users, read-only, and
	// hands out both nonces so whichever form is live can retry.
	check( in_array( 'wp_ajax_nopriv_faz_dnsmpi_nonce', $GLOBALS['__faz_actions'], true ), 'the nonce refresh is registered for anonymous visitors' );
	check( in_array( 'wp_ajax_faz_dnsmpi_nonce', $GLOBALS['__faz_actions'], true ), 'and for logged-in users' );
	check( method_exists( $dns, 'handle_nonce_refresh' ), 'the refresh handler exists' );
	if ( method_exists( $dns, 'handle_nonce_refresh' ) ) {
		$GLOBALS['__faz_json'] = array();
		$dns->handle_nonce_refresh();
		$res = last_json();
		check( is_array( $res ) && true === $res['success'], 'the refresh succeeds' );
		check( isset( $res['data']['optout'] ) && 'nonce-faz_dnsmpi_optout' === $res['data']['optout'], 'with a fresh opt-out nonce' );
		check( isset( $res['data']['rescind'] ) && 'nonce-faz_dnsmpi_rescind' === $res['data']['rescind'], 'and a fresh rescind nonce' );
	}

	// ── 3. The shipped JS reads what the server writes ───────────────────────

	echo "— the frontend handler and the render agree on their contract\n";

	$js = file_get_contents( dirname( __DIR__, 2 ) . '/frontend/js/faz-dnsmpi.js' );
	check( false !== strpos( $js, 'faz-dnsmpi-rescind-template' ), 'the script looks for the template the render emits' );
	check( false !== strpos( $js, 'stale_nonce' ), 'and recognises the stale-nonce code the handlers send' );
	check( false !== strpos( $js, 'document.cookie' ), 'and reads the cookie client-side' );

	// ── Summary ──────────────────────────────────────────────────────────────

	echo "\n";
	if ( $fail ) {
		echo "FAILED: $fail of " . ( $pass + $fail ) . "\n";
		exit( 1 );
	}
	echo "ALL PASS: $pass assertions passed\n";
	exit( 0 );
}
