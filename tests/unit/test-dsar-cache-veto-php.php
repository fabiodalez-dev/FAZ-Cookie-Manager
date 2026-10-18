<?php
/**
 * Standalone unit tests — the DSAR form must survive a full-page cache.
 *
 * The form bakes an anonymous nonce (valid 12 to 24 hours) and a signed stamp
 * (24 hours) into the page body. Nothing used to keep that page out of a
 * full-page cache, so on LiteSpeed (default public TTL seven days), Cloudflare
 * APO or a raised WP Rocket lifespan every submission after the first day was
 * refused with "please refresh the page" — and refreshing returned the same
 * cached HTML, nonce and all. No record, no mail, no admin signal: the rights
 * channel was dead for the life of the cache entry (GDPR Art. 12(2)).
 *
 * Pinned here:
 *
 *   - rendering [faz_dsar_form] vetoes the page cache the same way the plugin
 *     already vetoes it for country-dependent banners: the DONOTCACHEPAGE /
 *     DONOTCACHEOBJECT constants, the LiteSpeed action, the FlyingPress filter
 *     and — when output has not started — the no-store headers;
 *   - the veto can be filtered off for a site that fronts the form with its
 *     own cache rules;
 *   - every refusal that a stale page can produce (expired nonce, expired or
 *     forged stamp) names the email fallback the ceilings already name, so the
 *     visitor is never left with an instruction that cannot work;
 *   - the per-email hourly throttle names the same fallback, and the form says
 *     that several rights fit in one message, so a person exercising two
 *     rights is not refused with nowhere to go.
 *
 * Run: php tests/unit/test-dsar-cache-veto-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	function time() {
		return $GLOBALS['__faz_now'];
	}
	// Namespace-scoped stubs: the class calls header() / headers_sent()
	// unqualified, so these are what it reaches, and the test can both decide
	// whether output has started and read back what was sent.
	function headers_sent() {
		return $GLOBALS['__faz_headers_sent'];
	}
	function header( $value, $replace = true ) {
		$GLOBALS['__faz_headers'][] = $value;
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

	$GLOBALS['__faz_now']          = 1760000000;
	$GLOBALS['__faz_ip']           = '203.0.113.7';
	$GLOBALS['__faz_transients']   = array();
	$GLOBALS['__faz_filters']      = array();
	$GLOBALS['__faz_fired']        = array();
	$GLOBALS['__faz_mail']         = array();
	$GLOBALS['__faz_options']      = array( 'admin_email' => 'admin@example.test' );
	$GLOBALS['__faz_pending']      = 0;
	$GLOBALS['__faz_headers']      = array();
	$GLOBALS['__faz_headers_sent'] = false;
	$GLOBALS['__faz_nonce_ok']     = true;
	$GLOBALS['__faz_json']         = array();

	$GLOBALS['__faz_queried'] = null;
	if ( ! function_exists( 'is_singular' ) ) {
		function is_singular() {
			return $GLOBALS['__faz_queried'] instanceof WP_Post;
		}
	}
	if ( ! function_exists( 'get_queried_object' ) ) {
		function get_queried_object() {
			return $GLOBALS['__faz_queried'];
		}
	}
	if ( ! function_exists( 'has_shortcode' ) ) {
		function has_shortcode( $content, $tag ) {
			return false !== strpos( (string) $content, '[' . $tag );
		}
	}
	if ( ! function_exists( 'has_block' ) ) {
		function has_block( $name, $post = null ) {
			return false;
		}
	}
	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public $post_content = '';
			public function __construct( $content ) {
				$this->post_content = $content;
			}
		}
	}

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
	function __return_false() {
		return false;
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
	function sanitize_textarea_field( $value ) {
		return trim( (string) $value );
	}
	function sanitize_email( $value ) {
		return trim( (string) $value );
	}
	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) );
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
	function wp_generate_password( $length = 12, $special = true, $extra = true ) {
		return substr( str_repeat( 'a1b2c3d4e5f6g7h8', 4 ), 0, $length );
	}
	function wp_rand( $min = 0, $max = 0 ) {
		return $min;
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
	function wp_localize_script() {}
	function wp_enqueue_script() {}
	function faz_asset_suffix( $relative_path ) {
		return '.min';
	}
	function faz_load_shortcode_assets_everywhere( $asset ) {
		return false;
	}

	require_once __DIR__ . '/../../includes/class-utils.php';
	require_once __DIR__ . '/../../includes/class-ip-hasher.php';
	require_once __DIR__ . '/../../includes/class-cache-compatibility.php';
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
	function err_code( $verdict ) {
		return is_wp_error( $verdict ) ? $verdict->get_error_code() : '(accepted)';
	}
	function err_msg( $verdict ) {
		return is_wp_error( $verdict ) ? $verdict->get_error_message() : '(accepted)';
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
	function reset_state() {
		$GLOBALS['__faz_transients']   = array();
		$GLOBALS['__faz_filters']      = array();
		$GLOBALS['__faz_fired']        = array();
		$GLOBALS['__faz_mail']         = array();
		$GLOBALS['__faz_headers']      = array();
		$GLOBALS['__faz_headers_sent'] = false;
		$GLOBALS['__faz_nonce_ok']     = true;
		$GLOBALS['__faz_json']         = array();
		$GLOBALS['__faz_now']          = 1760000000;
		$GLOBALS['__faz_options']      = array(
			'admin_email'            => 'admin@example.test',
			'faz_dsar_stamped_since' => 1760000000 - ( 30 * DAY_IN_SECONDS ),
		);
		$_POST                         = array();
	}
	function ctx( $email = 'subject@example.test' ) {
		return array(
			'name'    => 'Jane Doe',
			'email'   => $email,
			'type'    => 'erasure',
			'message' => '',
		);
	}
	function hp_name_for( $t ) {
		return 'faz_hp_' . substr( hash_hmac( 'sha256', 'faz_dsar_hp|' . (int) $t, wp_salt() ), 0, 12 );
	}
	function browser_post( $stamp, $overrides = array() ) {
		$post = array(
			'action'     => 'faz_dsar_submit',
			'nonce'      => 'nonce-faz_dsar_submit',
			'faz_dsar_t' => (string) $stamp['t'],
			'faz_dsar_s' => $stamp['sig'],
			'dsar_name'  => 'Jane Doe',
			'dsar_email' => 'subject@example.test',
			'dsar_type'  => 'erasure',
		);
		$post[ hp_name_for( $stamp['t'] ) ] = '';
		foreach ( $overrides as $k => $v ) {
			$post[ $k ] = $v;
		}
		return $post;
	}
	function headers_contain( $needle ) {
		foreach ( $GLOBALS['__faz_headers'] as $h ) {
			if ( false !== stripos( $h, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	$dsar = new FazCookie\Includes\DSAR_Shortcode();

	// ── 1. Rendering the form keeps the page out of the page cache ──────────

	echo "— rendering [faz_dsar_form] vetoes the full-page cache\n";

	// A site that filtered the veto off: nothing may be touched. Tested FIRST
	// because the constants, once defined, stay defined for the process.
	reset_state();
	add_filter(
		'faz_dsar_veto_page_cache',
		function () {
			return false;
		}
	);
	$dsar->render( array() );
	check( ! defined( 'DONOTCACHEPAGE' ), 'with the veto filtered off DONOTCACHEPAGE is not defined' );
	check( array() === fired( 'litespeed_control_set_nocache' ), 'and LiteSpeed is not told anything' );
	check( ! isset( $GLOBALS['__faz_filters']['flying_press_is_cacheable'] ), 'and FlyingPress is not told anything' );
	check( array() === $GLOBALS['__faz_headers'], 'and no cache header is sent' );

	// The default: every signal the plugin already uses for country-dependent
	// banners, so whichever page cache the site runs sees the one it reads.
	reset_state();
	$html = $dsar->render( array() );
	check( false !== strpos( $html, 'name="nonce"' ), 'the form still carries its nonce' );
	check( defined( 'DONOTCACHEPAGE' ) && true === DONOTCACHEPAGE, 'DONOTCACHEPAGE is defined (WP Rocket, W3TC, WP Super Cache, Cache Enabler, SG Optimizer)' );
	check( defined( 'LSCACHE_NO_CACHE' ) && true === LSCACHE_NO_CACHE, 'and LSCACHE_NO_CACHE, as every page veto (Cache_Compatibility::define_no_cache_constants())' );
	check( ! defined( 'DONOTCACHEOBJECT' ), 'W3TC\'s object cache is left on: it holds nothing this page varies by' );
	check( 1 === count( fired( 'litespeed_control_set_nocache' ) ), 'LiteSpeed is told through its action (it does not read the constant until it buffers)' );
	check(
		isset( $GLOBALS['__faz_filters']['flying_press_is_cacheable'] )
			&& false === call_user_func( $GLOBALS['__faz_filters']['flying_press_is_cacheable'][0], true ),
		'FlyingPress is told through its filter, which is the only signal it reads'
	);
	check( headers_contain( 'no-store' ), 'and the response says no-store while headers can still be sent' );
	check( headers_contain( 'X-LiteSpeed-Cache-Control: no-cache' ), 'including the LiteSpeed request-time header' );
	check( headers_contain( 'CDN-Cache-Control: no-store' ) && headers_contain( 'Surrogate-Control: no-store' ), 'and the CDN-targeted directives of the shared no-store set' );

	// Two forms on one page: one veto, not two LiteSpeed actions and two
	// header sets. Headers are already recorded above, so count the action.
	$dsar->render( array() );
	check( 1 === count( fired( 'litespeed_control_set_nocache' ) ), 'a second form on the same page does not repeat the veto' );

	// Output already started (the usual case: the theme printed <head> long
	// before the_content ran). The header half is impossible, and must not be
	// attempted; the constant / action / filter half still goes out, because
	// that is what the PHP page caches read when they decide at shutdown.
	$fresh = new FazCookie\Includes\DSAR_Shortcode();
	reset_state();
	$GLOBALS['__faz_headers_sent'] = true;
	$fresh->render( array() );
	check( array() === $GLOBALS['__faz_headers'], 'with output started no header() call is attempted' );
	check( 1 === count( fired( 'litespeed_control_set_nocache' ) ), 'but LiteSpeed is still told' );
	check( isset( $GLOBALS['__faz_filters']['flying_press_is_cacheable'] ), 'and so is FlyingPress' );

	// Before output: a singular page whose content carries the shortcode is
	// vetoed on template_redirect, while headers can still be sent. Inside
	// the_content they almost never can, so the shortcode's own render is
	// too late for the no-store header.
	$early = new FazCookie\Includes\DSAR_Shortcode();
	reset_state();
	$GLOBALS['__faz_queried'] = new WP_Post( "Intro\n[faz_dsar_form]\n" );
	$early->maybe_veto_page_cache_early();
	check( headers_contain( 'no-store' ), 'a page that carries the form is vetoed before output, with the no-store header' );
	check( 1 === count( fired( 'litespeed_control_set_nocache' ) ), 'and LiteSpeed is told once' );
	$early->render( array() );
	check( 1 === count( fired( 'litespeed_control_set_nocache' ) ), 'the later render of the same form does not repeat the veto' );
	$other = new FazCookie\Includes\DSAR_Shortcode();
	reset_state();
	$GLOBALS['__faz_queried'] = new WP_Post( 'An ordinary page.' );
	$other->maybe_veto_page_cache_early();
	check( array() === $GLOBALS['__faz_headers'] && 0 === count( fired( 'litespeed_control_set_nocache' ) ), 'a page without the form keeps its cache' );
	$GLOBALS['__faz_queried'] = null;

	// ── 2. Every refusal a stale page can produce names the way out ──────────

	echo "— a stale page refuses with the email fallback, not only 'refresh'\n";

	// The nonce is what fails first on a page cached past its life.
	reset_state();
	$GLOBALS['__faz_nonce_ok'] = false;
	$_POST                     = array( 'nonce' => 'nonce-from-a-cached-page' );
	$dsar->handle_submit();
	check( 1 === count( $GLOBALS['__faz_json'] ) && false === $GLOBALS['__faz_json'][0]['success'], 'an expired nonce is refused' );
	$msg = isset( $GLOBALS['__faz_json'][0]['data'] ) ? (string) $GLOBALS['__faz_json'][0]['data'] : '';
	check( false !== stripos( $msg, 'refresh' ), 'the message still says to refresh (it works on an uncached page)' );
	check( false !== strpos( $msg, 'email us directly' ), 'AND names the email fallback, for the page a refresh cannot change' );
	// Every refusal goes through reject(), so a site watching faz_dsar_rejected
	// (alerting, an audit log) sees the one a stale cache produces most.
	$hits = fired( 'faz_dsar_rejected' );
	check( 1 === count( $hits ) && 'faz_dsar_nonce' === $hits[0][1], 'the expired-nonce refusal fires faz_dsar_rejected (faz_dsar_nonce)' );

	// A malformed consent ID is refused the same way, and names the way out.
	reset_state();
	$_POST = array( 'nonce' => 'nonce-faz_dsar_submit', 'dsar_name' => 'Ada', 'dsar_email' => 'ada@example.test', 'dsar_type' => 'access', 'dsar_consent_id' => '<not an id>' );
	$dsar->handle_submit();
	$msg  = isset( $GLOBALS['__faz_json'][0]['data'] ) ? (string) $GLOBALS['__faz_json'][0]['data'] : '';
	$hits = fired( 'faz_dsar_rejected' );
	check( 1 === count( $hits ) && 'faz_dsar_consent_id' === $hits[0][1], 'an invalid consent ID fires faz_dsar_rejected (faz_dsar_consent_id)' );
	check( isset( $hits[0][2]['consent_id'] ) && '' === $hits[0][2]['consent_id'], 'without passing the raw, unvalidated value on' );
	check( false !== strpos( $msg, 'email us directly' ), 'and the refusal names the email fallback' );

	// The stamp, when a site has raised nonce_life past the stamp window.
	reset_state();
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += DAY_IN_SECONDS + 1;
	$_POST                 = browser_post( $stamp );
	$verdict               = $dsar->guard_submission( ctx() );
	check( 'faz_dsar_stale' === err_code( $verdict ), 'an expired stamp is refused' );
	check( false !== strpos( err_msg( $verdict ), 'reload' ), 'the message still says to reload' );
	check( false !== strpos( err_msg( $verdict ), 'email us directly' ), 'and names the email fallback' );

	// A forged or absent stamp shares the message, and so shares the fallback.
	reset_state();
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;
	$_POST                 = browser_post( $stamp, array( 'faz_dsar_s' => str_repeat( 'f', 32 ) ) );
	$verdict               = $dsar->guard_submission( ctx() );
	check( 'faz_dsar_stamp' === err_code( $verdict ), 'a forged stamp is refused' );
	check( false !== strpos( err_msg( $verdict ), 'email us directly' ), 'with the same fallback' );

	// ── 3. The per-email hour does not strand a second right ─────────────────

	echo "— the per-email throttle names the fallback and the form says one message is enough\n";

	reset_state();
	$stamp                 = FazCookie\Includes\DSAR_Shortcode::issue_stamp();
	$GLOBALS['__faz_now'] += 8;
	$_POST                 = browser_post( $stamp );
	check( true === $dsar->guard_submission( ctx() ), 'the first right (access) is accepted' );
	$dsar->record_submission( 'subject@example.test' );
	$second         = ctx();
	$second['type'] = 'erasure';
	$verdict        = $dsar->guard_submission( $second );
	check( 'faz_dsar_email_throttled' === err_code( $verdict ), 'the second right (erasure) inside the hour is held by the per-email throttle' );
	check( false !== strpos( err_msg( $verdict ), '1 hour' ), 'the message still states the hour (F003)' );
	check( false !== strpos( err_msg( $verdict ), 'email us directly' ), 'and names the email fallback like the other ceilings' );

	reset_state();
	$html = $dsar->render( array() );
	check( false !== stripos( $html, 'more than one right' ), 'the form tells the visitor that more than one right fits in one submission' );
	check( 1 === preg_match( '/<select[^>]*name="dsar_type"[^>]*aria-describedby="[^"]*-type-hint"/', $html ), 'and the hint is wired to the request-type field for assistive tech' );

	// ── Summary ──────────────────────────────────────────────────────────────

	echo "— the nonce refresh endpoint is sent with the shared no-store set\n";
	reset_state();
	( new FazCookie\Includes\DSAR_Shortcode() )->handle_refresh();
	check( headers_contain( 'Cache-Control: private, no-store' ) && headers_contain( 'CDN-Cache-Control: no-store' ) && headers_contain( 'Surrogate-Control: no-store' ), 'handle_refresh() sends Cache_Compatibility::NO_STORE_HEADERS, not a lone Cache-Control line' );
	echo "\n";
	if ( $fail ) {
		echo "FAILED: $fail of " . ( $pass + $fail ) . "\n";
		exit( 1 );
	}
	echo "ALL PASS: $pass assertions passed\n";
	exit( 0 );
}
