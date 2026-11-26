<?php
/**
 * Unit test — the CCPA opt-out form submitted by the browser itself.
 *
 * The [faz_do_not_sell] form had no method and no action, and its handlers
 * only spoke JSON to frontend/js/faz-dnsmpi.js. With JavaScript off (or the
 * script blocked, deferred into oblivion by an optimiser, or broken) pressing
 * the button was a GET of the same page: no opt-out cookie, no audit row, and
 * nothing on screen to say so. The form is the mechanism Cal. Civ. Code
 * 1798.135(a) requires the page to offer.
 *
 * It now posts to admin-ajax.php with a marker in its action URL, and the
 * same handlers answer that request with a page:
 *
 *   - the opt-out is applied and logged exactly as through the script;
 *   - the answer is a confirmation page, not JSON;
 *   - an expired nonce (a cached page older than the nonce life) is not a
 *     dead end: the page carries the request again with a fresh nonce;
 *   - a cross-site post is still refused, cookie and row included;
 *   - the script's JSON contract is untouched.
 *
 * The handlers run for real; WordPress is stubbed and the consent logger is a
 * counter.
 *
 * Run: php tests/unit/test-dnsmpi-plain-post-php.php
 */

namespace FazCookie\Admin\Modules\Consentlogs\Includes {
	class Controller {
		public static $rows = array();
		public static function get_instance() {
			return new self();
		}
		public function log_consent( $row ) {
			self::$rows[] = $row;
		}
	}
}

namespace FazCookie\Includes {
	function time() {
		return (int) $GLOBALS['faz_test_now'];
	}
	// Every cookie the handlers set or clear, in order: array( name, value ).
	function setcookie( $name, $value = '', $options = array() ) {
		$GLOBALS['faz_test_cookies'][] = array( $name, $value );
		return true;
	}
}

namespace FazCookie\Tests\Unit {
	/** What the handler answered: JSON for the script, or a page. */
	class Response extends \Exception {
		public $kind;
		public $success;
		public $data;
		public $html;
		public $status;
		public function __construct( $kind, $success = null, $data = null, $html = '', $status = 0 ) {
			parent::__construct( $kind );
			$this->kind    = $kind;
			$this->success = $success;
			$this->data    = $data;
			$this->html    = $html;
			$this->status  = $status;
		}
	}
}

namespace {

	ob_start();

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}

	$GLOBALS['faz_test_now']        = 5000;
	$GLOBALS['faz_test_options']    = array();
	$GLOBALS['faz_test_transients'] = array();
	$GLOBALS['faz_test_cookies']    = array();
	$GLOBALS['faz_test_nonce_ok']   = true;
	$GLOBALS['faz_test_filters']    = array();
	$GLOBALS['faz_test_mails']      = 0;

	function check_ajax_referer( $action, $arg = false, $die = true ) {
		return $GLOBALS['faz_test_nonce_ok'] && isset( $_REQUEST[ $arg ] ) && 'nonce-' . $action === $_REQUEST[ $arg ];
	}
	function wp_create_nonce( $action = '' ) {
		return 'nonce-' . $action;
	}
	function wp_send_json_success( $data = null ) {
		throw new \FazCookie\Tests\Unit\Response( 'json', true, $data );
	}
	function wp_send_json_error( $data = null ) {
		throw new \FazCookie\Tests\Unit\Response( 'json', false, $data );
	}
	// wp_die() as the default (HTML page) handler leaves it: the message
	// wrapped in one paragraph.
	function wp_die( $message = '', $title = '', $args = array() ) {
		$handler = isset( $GLOBALS['faz_test_filters']['wp_die_ajax_handler'] )
			? call_user_func( $GLOBALS['faz_test_filters']['wp_die_ajax_handler'], '_ajax_wp_die_handler' )
			: '_ajax_wp_die_handler';
		// The AJAX handler prints the bare message; only the default one
		// prints a page. A response that forgot to ask for the page fails.
		$kind = '_default_wp_die_handler' === $handler ? 'page' : 'bare';
		throw new \FazCookie\Tests\Unit\Response( $kind, null, $title, '<p>' . $message . '</p>', isset( $args['response'] ) ? (int) $args['response'] : 500 );
	}
	function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
		$GLOBALS['faz_test_filters'][ $hook ] = $cb;
		return true;
	}
	function apply_filters( $hook, $value, ...$args ) {
		if ( 'faz_dnsmpi_max_notifications_per_day' === $hook ) {
			return 0; // No mail in this test.
		}
		return $value;
	}
	// Core's contract: the _wp_http_referer field first, then the header,
	// and only a URL on this site.
	function wp_get_referer() {
		$raw = isset( $_REQUEST['_wp_http_referer'] ) ? $_REQUEST['_wp_http_referer'] : ( isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '' );
		return ( is_string( $raw ) && 0 === strpos( $raw, 'https://example.test/' ) ) ? $raw : false;
	}
	function is_ssl() {
		return true;
	}
	function wp_using_ext_object_cache() {
		return false;
	}
	function get_transient( $key ) {
		$t = $GLOBALS['faz_test_transients'][ $key ] ?? null;
		return ( null === $t || $t[1] <= $GLOBALS['faz_test_now'] ) ? false : $t[0];
	}
	function set_transient( $key, $value, $ttl ) {
		$GLOBALS['faz_test_transients'][ $key ] = array( $value, $GLOBALS['faz_test_now'] + $ttl );
		return true;
	}
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_test_options'] ) ? $GLOBALS['faz_test_options'][ $name ] : $default;
	}
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $name, $GLOBALS['faz_test_options'] ) ) {
			return false;
		}
		$GLOBALS['faz_test_options'][ $name ] = $value;
		return true;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_test_options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( $GLOBALS['faz_test_options'][ $name ] );
		return true;
	}
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt';
	}
	function __( $text, $domain = 'default' ) {
		return $text;
	}
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES );
	}
	function esc_url_raw( $url ) {
		return $url;
	}
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . $path;
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-ip-hasher.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-do-not-sell-shortcode.php';

	use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;
	use FazCookie\Includes\Do_Not_Sell_Shortcode;
	use FazCookie\Tests\Unit\Response;

	$passed = 0;
	$failed = 0;
	function check( $cond, $label ) {
		global $passed, $failed;
		if ( $cond ) {
			$passed++;
			echo "  [PASS] $label\n";
		} else {
			$failed++;
			echo "  [FAIL] $label\n";
		}
	}

	$handler = ( new ReflectionClass( Do_Not_Sell_Shortcode::class ) )->newInstanceWithoutConstructor();

	/**
	 * One POST of the form. `$plain` is the browser submitting it by itself
	 * (the marker in the action URL); otherwise it is the script's fetch().
	 *
	 * @return Response|null
	 */
	function faz_post( $handler, $method, array $fields, $plain, array $server = array(), $with_cookie = false ) {
		$GLOBALS['faz_test_now']    += 120; // Each post in its own window.
		$GLOBALS['faz_test_filters'] = array();
		$_GET                        = $plain ? array( 'faz_dnsmpi_plain' => '1' ) : array();
		$_POST                       = $fields;
		$_REQUEST                    = array_merge( $_GET, $_POST );
		$_COOKIE                     = $with_cookie ? array( Do_Not_Sell_Shortcode::COOKIE_NAME => '1' ) : array();
		foreach ( array( 'HTTP_SEC_FETCH_SITE', 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $k ) {
			unset( $_SERVER[ $k ] );
		}
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		foreach ( $server + array( 'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_REFERER' => 'https://example.test/do-not-sell/' ) as $k => $v ) {
			$_SERVER[ $k ] = $v;
		}
		try {
			$handler->$method();
		} catch ( Response $r ) {
			return $r;
		}
		return null;
	}
	function faz_cookie_writes( $value ) {
		return count( array_filter( $GLOBALS['faz_test_cookies'], function ( $c ) use ( $value ) {
			return Do_Not_Sell_Shortcode::COOKIE_NAME === $c[0] && $value === $c[1];
		} ) );
	}

	$optout  = array( 'action' => 'faz_dnsmpi_optout', 'nonce' => 'nonce-faz_dnsmpi_optout' );
	$rescind = array( 'action' => 'faz_dnsmpi_rescind', 'nonce' => 'nonce-faz_dnsmpi_rescind' );
	$url     = 'https://example.test/wp-admin/admin-ajax.php?faz_dnsmpi_plain=1';

	echo "== The browser submits the opt-out form itself ==\n";
	$r = faz_post( $handler, 'handle_optout', $optout, true );
	check( 1 === faz_cookie_writes( '1' ), 'the opt-out cookie is set' );
	check( 1 === count( Controller::$rows ) && Do_Not_Sell_Shortcode::STATUS_FIELD === Controller::$rows[0]['status'], 'and the audit row is written' );
	check( $r instanceof Response && 'page' === $r->kind, 'the answer is a page, not JSON (got ' . ( $r ? $r->kind : 'nothing' ) . ')' );
	$html = $r ? $r->html : '';
	check( 200 === ( $r ? $r->status : 0 ), 'served with a 200' );
	check( false !== strpos( $html, 'Your opt-out request has been received.' ), 'that says the opt-out was received' );
	check( false !== strpos( $html, '<a href="https://example.test/do-not-sell/">' ), 'and links back to the page the form was on' );
	check( false !== strpos( $html, 'name="action" value="faz_dnsmpi_rescind"' ) && false !== strpos( $html, 'name="nonce" value="nonce-faz_dnsmpi_rescind"' ), 'and offers the withdrawal, which the shortcode keeps in a template only a script can reach' );
	check( false !== strpos( $html, '<form method="post" action="' . htmlspecialchars( $url, ENT_QUOTES ) . '">' ), 'through a form that posts to the same plain-form URL' );
	check( substr_count( $html, '<p>' ) === substr_count( $html, '</p>' ) && false === strpos( $html, '<p><form' ) && ! preg_match( '#<p>(?:(?!</p>).)*<form#s', $html ), 'no form ends up inside a paragraph' );

	echo "\n== Submitted again by a browser that already opted out ==\n";
	$rows = count( Controller::$rows );
	$r    = faz_post( $handler, 'handle_optout', $optout, true, array(), true );
	check( $r && 'page' === $r->kind && false !== strpos( $r->html, 'You have already opted out.' ), 'the page says so' );
	check( $rows === count( Controller::$rows ), 'and no second row is written' );

	echo "\n== The withdrawal, the same way ==\n";
	$GLOBALS['faz_test_cookies'] = array();
	$r                           = faz_post( $handler, 'handle_rescind', $rescind, true, array(), true );
	check( 1 === faz_cookie_writes( '' ), 'the opt-out cookie is cleared' );
	check( Do_Not_Sell_Shortcode::STATUS_RESCIND === end( Controller::$rows )['status'], 'and the withdrawal is logged' );
	check( $r && 'page' === $r->kind && false !== strpos( $r->html, 'Your opt-out has been withdrawn.' ), 'the page confirms it' );
	check( $r && false === strpos( $r->html, '<form' ), 'and offers nothing further to press' );

	echo "\n== An expired nonce (a cached page) is not a dead end ==\n";
	$GLOBALS['faz_test_cookies']  = array();
	$rows                         = count( Controller::$rows );
	$GLOBALS['faz_test_nonce_ok'] = false;
	$r                            = faz_post( $handler, 'handle_optout', array( 'action' => 'faz_dnsmpi_optout', 'nonce' => 'expired' ), true );
	$GLOBALS['faz_test_nonce_ok'] = true;
	check( 0 === faz_cookie_writes( '1' ) && $rows === count( Controller::$rows ), 'nothing is changed on an expired nonce' );
	$html = $r ? $r->html : '';
	check( $r && 'page' === $r->kind && false !== strpos( $html, 'nothing has been changed yet' ), 'the page says nothing was changed' );
	check( false !== strpos( $html, 'name="action" value="faz_dnsmpi_optout"' ) && false !== strpos( $html, 'name="nonce" value="nonce-faz_dnsmpi_optout"' ), 'and carries the same request again with a fresh nonce' );
	check( false !== strpos( $html, 'name="_wp_http_referer" value="https://example.test/do-not-sell/"' ), 'remembering the page the visitor came from' );
	// The visitor presses that button: the post now comes from admin-ajax.php.
	$r = faz_post(
		$handler,
		'handle_optout',
		$optout + array( '_wp_http_referer' => 'https://example.test/do-not-sell/' ),
		true,
		array( 'HTTP_REFERER' => $url )
	);
	check( 1 === faz_cookie_writes( '1' ) && $rows + 1 === count( Controller::$rows ), 'pressing it applies and logs the opt-out' );
	check( $r && false !== strpos( $r->html, '<a href="https://example.test/do-not-sell/">' ), 'and the confirmation still links back to the original page' );

	echo "\n== Refusals stay refusals ==\n";
	$GLOBALS['faz_test_cookies'] = array();
	$rows                        = count( Controller::$rows );
	$r                           = faz_post( $handler, 'handle_optout', $optout, true, array( 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.test', 'HTTP_REFERER' => 'https://evil.test/x' ) );
	check( 0 === faz_cookie_writes( '1' ) && $rows === count( Controller::$rows ), 'a cross-site post of the form sets no cookie and writes no row' );
	check( $r && 'page' === $r->kind && 403 === $r->status && false !== strpos( $r->html, 'must originate from this website' ), 'and is answered with a 403 page that says why' );
	check( $r && false === strpos( $r->html, '<form' ) && false === strpos( $r->html, 'nonce-' ), 'which hands out no form and no nonce' );
	check( $r && false !== strpos( $r->html, '<a href="https://example.test/">' ) && false === strpos( $r->html, 'evil.test' ), 'and whose link goes home, never to the foreign referrer' );

	// A link or an auto-submitting form on another site that opens the handler
	// with NO valid nonce. The nonce used to be judged before the origin, so
	// this came back as "expired token" and the page printed the retry form
	// with a freshly minted nonce: a genuine page of this site, one click away
	// from clearing the visitor's opt-out (or planting one).
	echo "\n== A cross-site navigation without a nonce is handed no retry form ==\n";
	$cross = array( 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_REFERER' => 'https://evil.test/x' );
	foreach ( array(
		'handle_rescind' => array( 'faz_dnsmpi_rescind', true ),
		'handle_optout'  => array( 'faz_dnsmpi_optout', false ),
	) as $method => $case ) {
		$GLOBALS['faz_test_cookies'] = array();
		$rows                        = count( Controller::$rows );
		$r                           = faz_post( $handler, $method, array( 'action' => $case[0] ), true, $cross, $case[1] );
		$html                        = $r ? $r->html : '';
		check( $r && 'page' === $r->kind && 403 === $r->status, "$method: answered with a 403 page (got " . ( $r ? $r->status : 'nothing' ) . ')' );
		check( false === strpos( $html, '<form' ) && false === strpos( $html, 'nonce-' ), "$method: with no form and no fresh nonce" );
		check( false !== strpos( $html, 'must originate from this website' ), "$method: that says the request did not come from this site" );
		check( false !== strpos( $html, '<a href="https://example.test/">' ) && false === strpos( $html, 'evil.test' ), "$method: and only the link back to the site" );
		check( array() === $GLOBALS['faz_test_cookies'] && $rows === count( Controller::$rows ), "$method: nothing is changed" );
	}
	// The same with a wrong nonce, and through the script's endpoint: the
	// origin is what is reported, so nothing invites a retry.
	$r = faz_post( $handler, 'handle_rescind', array( 'action' => 'faz_dnsmpi_rescind', 'nonce' => 'guess' ), true, $cross, true );
	check( $r && 403 === $r->status && false === strpos( $r->html, '<form' ), 'a wrong nonce from another site: still no form' );
	$r = faz_post( $handler, 'handle_rescind', array( 'action' => 'faz_dnsmpi_rescind' ), false, $cross, true );
	check( $r && 'json' === $r->kind && false === $r->success && is_string( $r->data ), 'the JSON answer to a cross-site request never carries the stale_nonce retry code' );
	// A same-origin visitor with an expired nonce keeps the retry form.
	$r = faz_post( $handler, 'handle_rescind', array( 'action' => 'faz_dnsmpi_rescind', 'nonce' => 'expired' ), true, array(), true );
	check( $r && 200 === $r->status && false !== strpos( $r->html, 'name="nonce" value="nonce-faz_dnsmpi_rescind"' ), 'a same-origin withdrawal with an expired nonce still gets the retry form' );

	echo "\n== The script's contract is untouched ==\n";
	$GLOBALS['faz_test_cookies'] = array();
	$r                           = faz_post( $handler, 'handle_optout', $optout, false );
	check( $r && 'json' === $r->kind && true === $r->success && isset( $r->data['message'] ), 'without the marker the opt-out is answered with JSON success' );
	check( 1 === faz_cookie_writes( '1' ), 'and applied' );
	$GLOBALS['faz_test_nonce_ok'] = false;
	$r                            = faz_post( $handler, 'handle_optout', $optout, false );
	$GLOBALS['faz_test_nonce_ok'] = true;
	check( $r && 'json' === $r->kind && false === $r->success && isset( $r->data['code'] ) && 'stale_nonce' === $r->data['code'], 'a stale nonce still carries the code the script retries on' );
	$r = faz_post( $handler, 'handle_optout', $optout, false, array( 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.test' ) );
	check( $r && 'json' === $r->kind && false === $r->success && is_string( $r->data ), 'and a cross-site fetch still gets the plain-string refusal' );

	ob_end_flush();
	echo "\nPassed: $passed\nFailed: $failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
