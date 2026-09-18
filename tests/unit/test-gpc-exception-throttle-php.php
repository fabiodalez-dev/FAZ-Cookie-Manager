<?php
/**
 * A GPC exception must survive the per-consent_id throttle.
 *
 * The 300s window drops a post whose status has not changed, which is right
 * for a replay. But an exception minted from a blocked embed does not change
 * the status: a visitor who saves preferences and then opens a map posts
 * "partial" twice, and inside the window the second post was dropped — so the
 * exception, which is an exception to a binding opt-out and the single riskiest
 * consent event this plugin records, was never written at all.
 *
 * These cases pin the narrow rule that fixes it: a NEW meta.gpc_exception.<id>
 * key counts as a change; one already on record does not, so replays stay
 * throttled and the bypass cannot be used as an unbounded write path.
 *
 * Run: php tests/unit/test-gpc-exception-throttle-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Consentlogs\Includes {
	/** Stub of the real controller: only the row lookup the rule consults. */
	class Controller {
		public static $row = null;
		private static $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function get_log_by_consent_id( $consent_id ) {
			return self::$row;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $key ) ); }
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
	}
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( $path = '' ) { return 'https://example.test' . $path; }
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url ) { return parse_url( (string) $url ); }
	}
	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) { return (string) $url; }
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/modules/consent-logger/class-consent-logger.php';

	use FazCookie\Frontend\Modules\Consent_Logger\Consent_Logger;
	use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;

	$passed = 0;
	$failed = 0;
	function gpcx_check( $actual, $label ) {
		global $passed, $failed;
		if ( $actual ) { $passed++; echo "  [PASS] {$label}\n"; }
		else { $failed++; echo "  [FAIL] {$label}\n"; }
	}

	$logger = ( new \ReflectionClass( Consent_Logger::class ) )->newInstanceWithoutConstructor();
	$method = new \ReflectionMethod( Consent_Logger::class, 'has_new_gpc_exception' );
	$method->setAccessible( true );
	// The row is handed in: the handler fetches it once and feeds both the
	// status comparison and this check. The stub is left EMPTY so a version that
	// re-queries on its own reads nothing and gets these cases wrong.
	$is_new = function ( $consent_id, $categories, $stored_row ) use ( $logger, $method ) {
		Controller::$row = null;
		return (bool) $method->invoke( $logger, $consent_id, $categories, $stored_row );
	};

	$row = function ( array $categories ) {
		return array( 'status' => 'partial', 'categories' => $categories );
	};
	function wp_json_encode_shim( $value ) { return json_encode( $value ); }

	echo "== GPC exception vs the consent_id throttle ==\n";

	// The reported case: preferences saved, then a map accepted, same status.
	gpcx_check(
		$is_new( 'abc', array( 'necessary' => 'yes', 'meta.gpc_exception.google-maps' => 'yes' ), $row( array( 'necessary' => 'yes' ) ) ),
		'an exception absent from the last row is a change'
	);

	// A replay of the same post must stay throttled, or the bypass becomes a
	// free write path — the thing the chg1/chgn caps exist to deny.
	gpcx_check(
		! $is_new( 'abc', array( 'meta.gpc_exception.google-maps' => 'yes' ), $row( array( 'meta.gpc_exception.google-maps' => 'yes' ) ) ),
		'an exception already on record is not a change'
	);

	// A second, different embed accepted later is its own event.
	gpcx_check(
		$is_new( 'abc', array( 'meta.gpc_exception.google-maps' => 'yes', 'meta.gpc_exception.youtube' => 'yes' ), $row( array( 'meta.gpc_exception.google-maps' => 'yes' ) ) ),
		'a second service is a change even when the first is on record'
	);

	// Ordinary posts must not acquire a bypass.
	gpcx_check( ! $is_new( 'abc', array( 'necessary' => 'yes', 'analytics' => 'no' ), $row( array( 'necessary' => 'yes' ) ) ), 'a post with no exception is not a change' );
	gpcx_check( ! $is_new( 'abc', 'not-an-array', $row( array() ) ), 'a non-array categories payload is not a change' );
	gpcx_check( ! $is_new( '', array( 'meta.gpc_exception.google-maps' => 'yes' ), $row( array() ) ), 'an empty consent id is not a change' );

    // First row for this id: there is nothing it could be a replay of.
	gpcx_check( $is_new( 'abc', array( 'meta.gpc_exception.google-maps' => 'yes' ), null ), 'the first row carrying an exception is a change' );
	gpcx_check( $is_new( 'abc', array( 'meta.gpc_exception.google-maps' => 'yes' ), array( 'status' => 'partial', 'categories' => 'not json' ) ), 'an unreadable stored map is treated as not carrying it' );

	// Near-miss keys must not be mistaken for exceptions.
	gpcx_check( ! $is_new( 'abc', array( 'meta.gpc_exception' => 'yes', 'meta.age_affirmed' => 'yes' ), $row( array() ) ), 'other meta keys do not open the bypass' );

	// One newest-row lookup per request, shared by both comparisons. The
	// second, identical SELECT ran before the throttle verdict, so it was paid
	// by exactly the replay traffic the early return exists to stop paying for.
	$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/frontend/modules/consent-logger/class-consent-logger.php' );
	gpcx_check( 1 === substr_count( $src, 'get_log_by_consent_id(' ), 'the handler fetches the previous row exactly once' );

	// The docblock of last_logged_status() sits on last_logged_status(): the
	// helper inserted above it had split the two apart.
	gpcx_check(
		(bool) preg_match( '/@return string Previous status[^\n]*\n\s*\*\/\s*\n\s*private function last_logged_status\(/', $src ),
		'last_logged_status() is directly preceded by its own docblock'
	);

	// The scan is bounded like the stored map: a crafted payload is not walked
	// in full before any cap applies.
	$flood = array();
	for ( $i = 0; $i < 400; $i++ ) { $flood[ 'k' . $i ] = 'yes'; }
	$flood['meta.gpc_exception.late'] = 'yes';
	gpcx_check( ! $is_new( 'abc', $flood, $row( array() ) ), 'an exception past the 250th key does not open the bypass' );

	echo "Passed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
