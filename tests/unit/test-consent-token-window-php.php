<?php
/**
 * The consent-log origin token has to outlive the page cache that carries it.
 *
 * The token is minted into the HTML and posted back when the visitor answers
 * the banner. It was accepted for the current 12-hour bucket and the previous
 * one only, so a page held by a full-page cache stopped being able to record
 * consent 12 to 24 hours after it was stored — while LiteSpeed Cache ships a
 * 7-day public TTL and WP Rocket and W3TC defaults are measured in days. The
 * banner kept working (the POST is fire-and-forget), so the only casualty was
 * the Art. 7(1) accountability record. Reported with production numbers in
 * issue #292: 57 consents logged and 286 refused on one site in one day.
 *
 * These cases pin the window, the filter that lets a longer-cached site widen
 * it, the clamps, and the fact that one place now mints what the other accepts
 * — the two used to be separate copies of the same arithmetic, in two files.
 *
 * Run: php tests/unit/test-consent-token-window-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Consentlogs\Includes {
	/** Unused here; the logger's file expects the class to exist. */
	class Controller {
		private static $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function get_log_by_consent_id( $consent_id ) {
			return null;
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

	// WordPress doubles. wp_hash() is what makes the token unforgeable off-site:
	// it is keyed by the installation's salts, so a different "site" below
	// produces a different token for the same bucket.
	$GLOBALS['faz_salt']       = 'site-a-salt';
	$GLOBALS['faz_options']    = array();
	$GLOBALS['faz_transients'] = array();
	$GLOBALS['faz_filters']    = array();

	if ( ! function_exists( 'wp_hash' ) ) {
		function wp_hash( $data, $scheme = 'auth' ) { return hash_hmac( 'md5', (string) $data, $GLOBALS['faz_salt'] ); }
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value ) {
			return isset( $GLOBALS['faz_filters'][ $hook ] ) ? call_user_func( $GLOBALS['faz_filters'][ $hook ], $value ) : $value;
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['faz_options'] ) ? $GLOBALS['faz_options'][ $name ] : $default; }
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $name, $value, $autoload = null ) { $GLOBALS['faz_options'][ $name ] = $value; return true; }
	}
	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $name ) { return array_key_exists( $name, $GLOBALS['faz_transients'] ) ? $GLOBALS['faz_transients'][ $name ] : false; }
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['faz_transients'][ $name ] = $value; return true; }
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

	$passed = 0;
	$failed = 0;
	function tok_check( $actual, $label ) {
		global $passed, $failed;
		if ( $actual ) { $passed++; echo "  [PASS] {$label}\n"; }
		else { $failed++; echo "  [FAIL] {$label}\n"; }
	}
	function tok_filter( $window ) {
		$GLOBALS['faz_filters']['faz_consent_token_max_age'] = function ( $value ) use ( $window ) { return $window; };
	}
	function tok_unfilter() {
		unset( $GLOBALS['faz_filters']['faz_consent_token_max_age'] );
	}

	echo "\n== Consent-log origin token: the window has to outlast the page cache ==\n";

	// A fixed instant so the cases do not sit on a bucket boundary by accident.
	$now   = 1758556800; // 2026-09-22 16:00:00 UTC.
	$token = Consent_Logger::current_token( $now );

	// 1. The reported failure. A page cached now is still serving a day, two
	//    days and a week later; every one of those visitors must be recorded.
	tok_check( Consent_Logger::token_is_valid( $token, $now ), 'the token minted now is accepted now' );
	tok_check( Consent_Logger::token_is_valid( $token, $now + DAY_IN_SECONDS ), 'a page cached for a day still records consent' );
	tok_check( Consent_Logger::token_is_valid( $token, $now + 2 * DAY_IN_SECONDS ), 'and after two days' );
	tok_check( Consent_Logger::token_is_valid( $token, $now + 6 * DAY_IN_SECONDS ), 'and after six days, inside LiteSpeed\'s 7-day default TTL' );

	// 2. The window still ends. It is a cache allowance, not "forever".
	tok_check( ! Consent_Logger::token_is_valid( $token, $now + 8 * DAY_IN_SECONDS ), 'a token older than the window is refused' );

	// 3. A site whose cache outlives the default widens the window itself.
	tok_filter( 14 * DAY_IN_SECONDS );
	tok_check( Consent_Logger::token_is_valid( $token, $now + 10 * DAY_IN_SECONDS ), 'the faz_consent_token_max_age filter widens the window' );
	tok_check( 14 * DAY_IN_SECONDS === Consent_Logger::token_max_age(), 'and token_max_age() reports what the filter set' );

	// 4. The clamps. Below one bucket the current token would expire before the
	//    page finished loading; a month is as far as a token says anything.
	tok_filter( 60 );
	tok_check( Consent_Logger::TOKEN_BUCKET === Consent_Logger::token_max_age(), 'a window shorter than one bucket is raised to one bucket' );
	tok_check( Consent_Logger::token_is_valid( $token, $now + 3 * HOUR_IN_SECONDS ), 'and the current bucket is still accepted under that clamp' );
	tok_filter( 365 * DAY_IN_SECONDS );
	tok_check( Consent_Logger::TOKEN_MAX_AGE_LIMIT === Consent_Logger::token_max_age(), 'a window beyond the limit is capped' );
	tok_check( ! Consent_Logger::token_is_valid( $token, $now + 60 * DAY_IN_SECONDS ), 'so a two-month-old token is still refused' );
	tok_unfilter();
	tok_check( Consent_Logger::TOKEN_MAX_AGE === Consent_Logger::token_max_age(), 'without a filter the default is seven days' );

	// 5. What the token proves is unchanged by the longer window: it is keyed
	//    to this installation's salts, so another site's token never passes,
	//    at any age. Replay from another origin is stopped by the same-origin
	//    check, not by the token expiring.
	$GLOBALS['faz_salt'] = 'site-b-salt';
	$foreign             = Consent_Logger::current_token( $now );
	$GLOBALS['faz_salt'] = 'site-a-salt';
	tok_check( ! Consent_Logger::token_is_valid( $foreign, $now ), 'a token minted with another site\'s salts is refused' );
	tok_check( ! Consent_Logger::token_is_valid( 'not-a-token', $now ), 'a made-up token is refused' );
	tok_check( ! Consent_Logger::token_is_valid( '', $now ), 'an empty token is refused' );
	tok_check( ! Consent_Logger::token_is_valid( null, $now ), 'a non-string token is refused' );

	// 6. A token from the future (clock skew between web and DB hosts, or a
	//    page rendered a moment before the bucket rolled) is not accepted from
	//    an unbounded distance ahead.
	tok_check( ! Consent_Logger::token_is_valid( Consent_Logger::current_token( $now + 5 * DAY_IN_SECONDS ), $now ), 'a token minted days in the future is refused' );

	// 7. One place mints, one place accepts. The bug class that produced this
	//    issue is two copies of the same arithmetic free to drift apart, so the
	//    frontend must not compute a bucket of its own any more.
	$frontend = (string) file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
	tok_check( false !== strpos( $frontend, 'Consent_Logger::current_token()' ), 'the frontend mints the token through the logger' );
	tok_check( false === strpos( $frontend, "wp_hash( 'faz_consent_" ), 'and no longer hashes a bucket of its own' );

	// 8. A refusal is recorded instead of passing in silence: a consent log
	//    that stops recording without complaining is the part nobody notices.
	$GLOBALS['faz_options']    = array();
	$GLOBALS['faz_transients'] = array();
	$reject = new \ReflectionMethod( Consent_Logger::class, 'record_token_rejection' );
	if ( PHP_VERSION_ID < 80100 ) {
		$reject->setAccessible( true );
	}
	$reject->invoke( null );
	$reject->invoke( null );
	$tally = get_option( Consent_Logger::REJECTION_OPTION, array() );
	tok_check( is_array( $tally ) && 2 === (int) $tally['count'], 'each refusal is counted' );
	tok_check( ! empty( $tally['since'] ) && ! empty( $tally['last'] ), 'with the window start and the last occurrence' );
	tok_check( 1 === (int) get_transient( 'faz_consent_token_rejected_notice' ), 'and the error-log notice is throttled to one an hour' );

	// A tally older than its 7-day window starts again rather than keeping a
	// warning on screen for a cache problem that a purge already fixed. The
	// window has to apply when the tally is READ as well: rolling it over only
	// on the next refusal left System Status reporting a fortnight-old count as
	// "in the last 7 days" for as long as nothing else was refused.
	$GLOBALS['faz_options'][ Consent_Logger::REJECTION_OPTION ] = array( 'since' => time() - 8 * DAY_IN_SECONDS, 'count' => 900, 'last' => time() - 8 * DAY_IN_SECONDS );
	$stale = Consent_Logger::rejection_tally();
	tok_check( 0 === $stale['count'], 'an expired tally reads as zero, with no new refusal needed' );
	$reject->invoke( null );
	$tally = Consent_Logger::rejection_tally();
	tok_check( 1 === $tally['count'], 'and the next refusal starts a new window' );
	tok_check( $tally['since'] >= time() - 5, 'dated from that refusal, not from the expired window' );

	// A live tally is reported as it stands.
	$GLOBALS['faz_options'][ Consent_Logger::REJECTION_OPTION ] = array( 'since' => time() - DAY_IN_SECONDS, 'count' => 7, 'last' => time() );
	tok_check( 7 === Consent_Logger::rejection_tally()['count'], 'a tally inside the window is reported as it stands' );
	$GLOBALS['faz_options'][ Consent_Logger::REJECTION_OPTION ] = 'not an array';
	tok_check( 0 === Consent_Logger::rejection_tally()['count'], 'a corrupt option reads as zero' );

	// The view must not read the option itself: that is how the two copies of
	// the 7-day rule came apart in the first place.
	$view = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/views/system-status.php' );
	tok_check( false !== strpos( $view, 'Consent_Logger::rejection_tally()' ), 'System Status reads the tally through the shared accessor' );
	tok_check( false === strpos( $view, "get_option( \\FazCookie\\Frontend\\Modules\\Consent_Logger\\Consent_Logger::REJECTION_OPTION" ), 'and not through get_option()' );

	echo "\nPassed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
