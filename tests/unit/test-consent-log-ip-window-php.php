<?php
/**
 * A withdrawal seconds after an acceptance must survive the per-IP window too.
 *
 * The consent endpoint has two throttles: one per IP (10 s) and one per
 * consent_id (300 s). A status change bypassed the second — the register must
 * record the visitor's latest choice, not the first one — but the first ran
 * before it, unconditionally, and the handler returned `{throttled:true}` with
 * an HTTP 200 the fire-and-forget beacon never reads. So "accept by mistake,
 * open the preference centre, reject seconds later" — the very case the bypass
 * was written for — was dropped whenever the two clicks were within 10 s of
 * each other, and the surviving row said "accepted" for a visitor who had
 * withdrawn. The compliance suite's own TF14 performs that sequence in ~4 s.
 *
 * These cases drive the REAL handle_rest_consent() against doubles for the
 * WordPress surface it touches, with a fake clock on the throttle store, and
 * pin the narrow rule that fixes it: the IP gate yields only to a decision from
 * the SAME consent_id that the log already knows (a previous row exists) and
 * whose allowlisted status differs from that row's. Everything else the IP gate
 * refused before, it still refuses — a different consent_id from the same
 * address inside 10 s stays throttled (the ipBucketOnly E2E case), a replay of
 * the same status stays throttled, and sustained alternation is bounded by the
 * chg1/chgn caps.
 *
 * Run: php tests/unit/test-consent-log-ip-window-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Consentlogs\Includes {
	/**
	 * Stand-in for the controller: an in-memory log, newest row per id, and the
	 * write the handler performs when nothing refuses the request.
	 */
	class Controller {
		public static $rows     = array();
		private static $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function get_log_by_consent_id( $consent_id ) {
			$best = null;
			foreach ( self::$rows as $row ) {
				if ( $row['consent_id'] === $consent_id ) {
					$best = $row;
				}
			}
			return $best;
		}
		public function log_consent( $data ) {
			$row = array(
				'log_id'     => count( self::$rows ) + 1,
				'consent_id' => (string) $data['consent_id'],
				'status'     => (string) $data['status'],
				'categories' => is_array( $data['categories'] ) ? $data['categories'] : array(),
				'at'         => $GLOBALS['faz_fake_now'],
			);
			self::$rows[] = $row;
			return $row;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	foreach ( array( 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800, 'MINUTE_IN_SECONDS' => 60 ) as $faz_const => $faz_value ) {
		if ( ! defined( $faz_const ) ) {
			define( $faz_const, $faz_value );
		}
	}
	// The refusal tally writes one line to the error log per hour; keep the
	// runner's output to its own assertions.
	ini_set( 'error_log', sys_get_temp_dir() . '/faz-consent-ip-window-test.log' );

	$GLOBALS['faz_fake_now']   = 0;
	$GLOBALS['faz_client_ip']  = '203.0.113.7';
	$GLOBALS['faz_throttles']  = array();
	$GLOBALS['faz_options']    = array();
	$GLOBALS['faz_transients'] = array();

	/**
	 * The shipped faz_throttle_request(), modelled: keyed on prefix + the
	 * client address, armed on the first call, closed while the TTL has not
	 * elapsed. WordPress transients expire when `$timeout < time()`, so a 10 s
	 * window set at t=0 is still CLOSED at t=10 and opens at t=11; the model
	 * keeps that boundary so an advance of exactly the TTL cannot pass here
	 * while the real endpoint still refuses.
	 */
	function faz_throttle_request( $prefix = 'faz_throttle', $ttl = 1 ) {
		$key = $prefix . '_' . md5( $GLOBALS['faz_client_ip'] );
		$now = $GLOBALS['faz_fake_now'];
		if ( isset( $GLOBALS['faz_throttles'][ $key ] ) && $GLOBALS['faz_throttles'][ $key ] >= $now ) {
			return true;
		}
		$GLOBALS['faz_throttles'][ $key ] = $now + (int) $ttl;
		return false;
	}
	function faz_advance( $seconds ) {
		$GLOBALS['faz_fake_now'] += (int) $seconds;
	}
	function faz_reset() {
		$GLOBALS['faz_fake_now']   = 0;
		$GLOBALS['faz_throttles']  = array();
		$GLOBALS['faz_transients'] = array();
		\FazCookie\Admin\Modules\Consentlogs\Includes\Controller::$rows = array();
	}

	// WordPress doubles, one line each: the body is its own description.
	function wp_hash( $data, $scheme = 'auth' ) { return hash_hmac( 'md5', (string) $data, 'unit-salt' ); }
	function apply_filters( $hook, $value ) { return $value; }
	function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['faz_options'] ) ? $GLOBALS['faz_options'][ $name ] : $default; }
	function update_option( $name, $value, $autoload = null ) { $GLOBALS['faz_options'][ $name ] = $value; return true; }
	function get_transient( $name ) { return array_key_exists( $name, $GLOBALS['faz_transients'] ) ? $GLOBALS['faz_transients'][ $name ] : false; }
	function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['faz_transients'][ $name ] = $value; return true; }
	function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $key ) ); }
	function sanitize_text_field( $value ) { return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', strip_tags( (string) $value ) ) ); }
	function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
	function home_url( $path = '' ) { return 'https://example.test' . $path; }
	function wp_parse_url( $url ) { return parse_url( (string) $url ); }
	function esc_url_raw( $url ) { return (string) $url; }
	function __( $text, $domain = 'default' ) { return (string) $text; } // phpcs:ignore
	function rest_ensure_response( $response ) { return $response; }

	/** The two REST types the handler returns or reads. */
	class WP_Error {
		public $code;
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; }
		public function get_error_code() { return $this->code; }
	}
	class WP_REST_Request {
		private $params;
		public function __construct( array $params ) { $this->params = $params; }
		public function get_param( $key ) { return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null; }
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/modules/consent-logger/class-consent-logger.php';

	use FazCookie\Frontend\Modules\Consent_Logger\Consent_Logger;
	use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;

	$passed = 0;
	$failed = 0;
	function ipw_check( $actual, $label ) {
		global $passed, $failed;
		if ( $actual ) { $passed++; echo "  [PASS] {$label}\n"; }
		else { $failed++; echo "  [FAIL] {$label}\n"; }
	}

	$logger = ( new \ReflectionClass( Consent_Logger::class ) )->newInstanceWithoutConstructor();
	$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';

	/**
	 * POST one consent decision as the inline beacon would, from the current
	 * client address, at the current fake instant.
	 *
	 * @return string 'logged', 'throttled', or the WP_Error code.
	 */
	$post = function ( $consent_id, $status, array $categories = array( 'analytics' => 'yes' ) ) use ( $logger ) {
		$request = new WP_REST_Request( array(
			'token'      => Consent_Logger::current_token(),
			'consent_id' => $consent_id,
			'status'     => $status,
			'categories' => $categories,
			'url'        => 'https://example.test/',
		) );
		$result = $logger->handle_rest_consent( $request );
		if ( $result instanceof WP_Error ) {
			return $result->get_error_code();
		}
		if ( is_array( $result ) && ! empty( $result['throttled'] ) ) {
			return 'throttled';
		}
		return 'logged';
	};
	$newest_status = function ( $consent_id ) {
		$row = Controller::get_instance()->get_log_by_consent_id( $consent_id );
		return is_array( $row ) ? $row['status'] : '';
	};

	$id = 'a0cwUGdtRzFHbllmRVJJMmx5clIwS3AwT0g5QXhtTFY';

	echo "\n== The per-IP window must not drop a same-visitor correction ==\n";

	// 1. The reported sequence, at TF14's pace: accept, then reject 6 s later
	//    from the same browser — same consent_id, same address, no transient
	//    cleared in between.
	faz_reset();
	ipw_check( 'logged' === $post( $id, 'accepted' ), 'the acceptance at t=0 is logged' );
	faz_advance( 6 );
	ipw_check( 'logged' === $post( $id, 'rejected' ), 'the withdrawal at t=6 from the same address is logged, not throttled' );
	ipw_check( 'rejected' === $newest_status( $id ), 'and the newest row for the visitor now says "rejected", not "accepted"' );

	// 2. The same for a partial save (accept, then Customize -> Save).
	faz_reset();
	$post( $id, 'accepted' );
	faz_advance( 4 );
	ipw_check( 'logged' === $post( $id, 'partial', array( 'analytics' => 'no', 'marketing' => 'no' ) ), 'accept then a partial save 4 s later is logged' );
	ipw_check( 'partial' === $newest_status( $id ), 'and the register carries the partial decision' );

	// 3. What the IP gate is for is untouched: a DIFFERENT consent_id from the
	//    same address inside the window is still refused (the ipBucketOnly E2E
	//    decision, 6b4fa219).
	faz_reset();
	$post( $id, 'accepted' );
	faz_advance( 6 );
	ipw_check( 'throttled' === $post( 'another-visitor-id-0123456789abcdef', 'rejected' ), 'a different consent_id from the same address inside 10 s is still throttled' );
	ipw_check( '' === $newest_status( 'another-visitor-id-0123456789abcdef' ), 'and nothing was written for it' );
	faz_advance( 5 ); // t=11: the 10 s window has elapsed (WordPress opens it at TTL+1).
	ipw_check( 'logged' === $post( 'another-visitor-id-0123456789abcdef', 'rejected' ), 'once the window has passed the other id is logged — it is a window, not a wall' );

	// 4. A replay of the same status is not a correction and stays throttled by
	//    both gates.
	faz_reset();
	$post( $id, 'accepted' );
	faz_advance( 6 );
	ipw_check( 'throttled' === $post( $id, 'accepted' ), 'a replay of the same status 6 s later is throttled' );

	// 5. No previous row, no bypass. The first post for an id "differs" from
	//    nothing; letting that through would hand a flooder a fresh id per
	//    request and the IP gate would never close.
	faz_reset();
	$post( $id, 'accepted' );
	faz_advance( 6 );
	ipw_check( 'throttled' === $post( 'fresh-id-with-no-row-0123456789abcdef', 'accepted' ), 'a first-ever post for an id does not open the IP gate' );

	// 6. A status outside the allowlist is not a change either.
	faz_reset();
	$post( $id, 'accepted' );
	faz_advance( 6 );
	ipw_check( 'throttled' === $post( $id, 'not-a-real-status' ), 'junk status on a known id is not a change and stays throttled' );

	// 7. Sustained alternation is bounded: the chg1/chgn caps still apply, so
	//    the bypass cannot become an unthrottled write path. The first write
	//    arms chg1 (its status "differs" from no row at all), so inside the
	//    10 s chgn window exactly one correction is free — the same bound the
	//    withdrawal suite pins for the per-id gate (case 4c there).
	faz_reset();
	$post( $id, 'accepted' );                 // t=0, lands and arms chg1
	faz_advance( 2 );
	ipw_check( 'logged' === $post( $id, 'rejected' ), 'the correction (t=2) lands' );
	faz_advance( 2 );
	ipw_check( 'throttled' === $post( $id, 'accepted' ), 'a second flip inside the 10 s chgn window is throttled — alternation is bounded' );
	faz_advance( 2 );
	ipw_check( 'throttled' === $post( $id, 'rejected' ), 'and so is a third' );
	ipw_check( 'rejected' === $newest_status( $id ), 'the register keeps the last decision that landed' );
	faz_advance( 7 ); // t=13: both the IP window (10 s) and the chgn window have elapsed.
	ipw_check( 'logged' === $post( $id, 'accepted' ), 'once the windows have passed a change is logged again' );

	// 8. No consent_id at all: nothing to compare with, the IP gate stands.
	faz_reset();
	$post( $id, 'accepted' );
	faz_advance( 6 );
	ipw_check( 'throttled' === $post( '', 'rejected' ), 'a post without a consent_id inside the window is throttled' );

	echo "\nconsent-log IP window: {$passed} passed, {$failed} failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
