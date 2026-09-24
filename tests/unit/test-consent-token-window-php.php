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

	// The WordPress surface this class touches, as one-line doubles: each body
	// IS its own description, so they carry no separate docblock — the same
	// shape every standalone runner in tests/unit uses. Only the ones whose
	// behaviour the assertions steer are commented, below.
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
	// The refusal tally gates its own write on this, so the suite would fatal
	// on an undefined function without it. Switchable, because the ceiling it
	// adds is itself something to assert.
	$GLOBALS['faz_throttled'] = false;
	if ( ! function_exists( 'faz_throttle_request' ) ) {
		function faz_throttle_request( $prefix = 'faz_throttle', $ttl = 1 ) { return (bool) $GLOBALS['faz_throttled']; }
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

	/**
	 * Record one assertion.
	 *
	 * @param mixed  $actual Truthy for a pass.
	 * @param string $label  What the case proves, phrased as the property.
	 */
	function tok_check( $actual, $label ) {
		global $passed, $failed;
		if ( $actual ) { $passed++; echo "  [PASS] {$label}\n"; }
		else { $failed++; echo "  [FAIL] {$label}\n"; }
	}
	/**
	 * Pin the accepted token window through the public filter.
	 *
	 * Set through `apply_filters` rather than a constant, because the clamps are
	 * applied to the filtered value and are part of what the cases assert.
	 *
	 * @param int $window Seconds a token stays acceptable.
	 */
	function tok_filter( $window ) {
		$GLOBALS['faz_filters']['faz_consent_token_max_age'] = function ( $value ) use ( $window ) { return $window; };
	}
	/** Drop the filter, returning the window to the shipped default. */
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
	// The pageview endpoint carried the identical defect and the identical
	// duplicated arithmetic; both are now single-sourced the same way.
	tok_check( false === strpos( $frontend, "wp_hash( 'faz_pageview_" ), 'the pageview token is not hashed in the frontend either' );
	$pv_api = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/pageviews/api/class-api.php' );
	tok_check( false !== strpos( $pv_api, 'function token_is_valid' ), 'the pageview endpoint owns a windowed token check' );
	tok_check( false === strpos( $pv_api, '12 * HOUR_IN_SECONDS ) )' ), 'and no longer accepts only two 12-hour buckets' );
	tok_check( false !== strpos( $pv_api, 'faz_pageview_token_max_age' ), 'with its own filter for a longer-cached site' );

	// And the pageview window is driven, not read. Every check above is source
	// text, which cannot notice a window that is wrong at the boundary — the
	// whole of the defect. The E2E suite posts real tokens at the real endpoint,
	// but that needs a running WordPress; this runs everywhere, for free.
	require_once __DIR__ . '/fixtures/rest-controller-stub.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/pageviews/api/class-api.php';
	$pv_now   = 1780000000;
	$pv_token = \FazCookie\Admin\Modules\Pageviews\Api\Api::current_token( $pv_now );
	tok_check(
		true === \FazCookie\Admin\Modules\Pageviews\Api\Api::token_is_valid( $pv_token, $pv_now ),
		'a freshly minted pageview token is accepted'
	);
	// Two buckets on is where the old shape stopped: it accepted the current
	// bucket and the previous one, so this is the assertion that fails if the
	// pageview validator alone is put back to twelve hours.
	tok_check(
		true === \FazCookie\Admin\Modules\Pageviews\Api\Api::token_is_valid( $pv_token, $pv_now + 2 * \FazCookie\Admin\Modules\Pageviews\Api\Api::TOKEN_BUCKET ),
		'and is still accepted a day later, which the two-bucket window refused'
	);
	tok_check(
		false === \FazCookie\Admin\Modules\Pageviews\Api\Api::token_is_valid( $pv_token, $pv_now + 8 * DAY_IN_SECONDS ),
		'while the window still ends: eight days on it is refused'
	);

	// 7b. The token argument must NOT be declared `required`. WordPress rejects
	//     a missing required arg in has_valid_params() and answers 400 before
	//     the callback — and the check is keyed on `null === $param`, so an
	//     empty token reaches the handler while an absent one does not. The
	//     token would still be enforced, but by a gate that counts nothing: the
	//     one shape the "No origin token" cause names in its own copy is exactly
	//     the shape that would never reach the code counting it. Restoring
	//     `required` looks like tidying and silently re-opens that hole.
	$logger_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/frontend/modules/consent-logger/class-consent-logger.php' );
	$token_arg  = preg_match( "/'token'\s*=>\s*array\((.*?)\),/s", $logger_src, $faz_m ) ? $faz_m[1] : '';
	tok_check( '' !== $token_arg, 'the consent route declares a token argument' );
	tok_check(
		false === strpos( $token_arg, 'required' ),
		'and does not declare it required, so a request with no token at all reaches the handler that counts it'
	);

	// Both throttled sites, by count. The E2E suite drives the per-IP one for
	// real, which is the branch a visitor can hit; the per-consent_id one needs
	// two posts more than ten seconds apart to get past the IP gate first, and
	// an eleven-second sleep in every CI run is a poor trade for a branch whose
	// only risk is a missing call or a mistyped constant. That risk is what this
	// counts — a direct record_refusal() call in a unit test cannot see either.
	tok_check(
		2 === substr_count( $logger_src, 'record_refusal( self::CAUSE_THROTTLED )' ),
		'both throttled branches record the cause: the per-IP one and the per-consent_id one'
	);

	// 8. A refusal is recorded instead of passing in silence: a consent log
	//    that stops recording without complaining is the part nobody notices.
	//    The tally is seven per-day buckets, per cause. The anchored shape it
	//    replaces expired wholesale once its first refusal aged out, so a site
	//    refusing continuously watched the figure collapse from thousands to
	//    one the instant the window rolled — a sawtooth reported as "the last
	//    7 days". Every case below fails against that shape.
	$GLOBALS['faz_options']    = array();
	$GLOBALS['faz_transients'] = array();
	$GLOBALS['faz_throttled']  = false;
	$reject = new \ReflectionMethod( Consent_Logger::class, 'record_refusal' );
	if ( PHP_VERSION_ID < 80100 ) {
		$reject->setAccessible( true );
	}
	$reject->invoke( null, Consent_Logger::CAUSE_STALE_TOKEN );
	$reject->invoke( null, Consent_Logger::CAUSE_STALE_TOKEN );
	$tally = Consent_Logger::rejection_tally();
	tok_check( 2 === $tally['count'], 'each refusal is counted' );
	tok_check( 2 === ( $tally['causes'][ Consent_Logger::CAUSE_STALE_TOKEN ] ?? 0 ), 'under the cause that produced it' );
	tok_check( $tally['since'] > 0 && $tally['last'] > 0, 'with the window start and the last occurrence' );
	tok_check( 1 === (int) get_transient( 'faz_consent_token_rejected_notice' ), 'and the error-log notice is throttled to one an hour' );

	// Every discard path on the endpoint is counted, not just the stale token:
	// a cross-origin refusal is reachable by a real browser on a site whose
	// host, scheme or port differs from its WordPress Address, and a failed
	// database write is always a genuine consent that was lost behind a 500 the
	// fire-and-forget client never inspects.
	$reject->invoke( null, Consent_Logger::CAUSE_CROSS_ORIGIN );
	$reject->invoke( null, Consent_Logger::CAUSE_WRITE_FAILED );
	$tally = Consent_Logger::rejection_tally();
	tok_check( 4 === $tally['count'], 'refusals from different causes add to the same total' );
	tok_check( 1 === ( $tally['causes'][ Consent_Logger::CAUSE_CROSS_ORIGIN ] ?? 0 ), 'and a cross-origin refusal is counted separately' );
	tok_check( 1 === ( $tally['causes'][ Consent_Logger::CAUSE_WRITE_FAILED ] ?? 0 ), 'as is a failed database write' );
	$reject->invoke( null, 'not-a-cause' );
	tok_check( 4 === Consent_Logger::rejection_tally()['count'], 'an unknown cause is ignored rather than stored' );

	// THE CASE THE ANCHORED SHAPE FAILED. Ten days of continuous refusals: the
	// figure must be the trailing-window sum, never a count that restarted when
	// the oldest day aged out.
	$now  = time();
	$day  = (int) floor( $now / DAY_IN_SECONDS );
	$days = array();
	for ( $i = 0; $i < 10; $i++ ) {
		$days[ $day - $i ] = array( Consent_Logger::CAUSE_STALE_TOKEN => 300 );
	}
	$GLOBALS['faz_options'][ Consent_Logger::REJECTION_OPTION ] = array( 'days' => $days, 'last' => $now );
	$rolling = Consent_Logger::rejection_tally( $now );
	tok_check( 2100 === $rolling['count'], 'ten days of refusals report the trailing seven, not a reset counter' );
	tok_check( 1 !== $rolling['count'], 'and never collapse to a single refusal at the boundary' );
	tok_check( $rolling['since'] === ( $day - 6 ) * DAY_IN_SECONDS, 'dated from the oldest day still inside the window' );

	// The property the anchored shape DID get right, which the buckets must
	// keep: a cause that stopped over a week ago reads as zero on its own, with
	// no new refusal needed to roll the window over.
	$GLOBALS['faz_options'][ Consent_Logger::REJECTION_OPTION ] = array(
		'days' => array( ( $day - 9 ) => array( Consent_Logger::CAUSE_STALE_TOKEN => 5000 ) ),
		'last' => $now - 9 * DAY_IN_SECONDS,
	);
	tok_check( 0 === Consent_Logger::rejection_tally( $now )['count'], 'an expired tally reads as zero, with no new refusal needed' );
	$reject->invoke( null, Consent_Logger::CAUSE_STALE_TOKEN );
	tok_check( 1 === Consent_Logger::rejection_tally()['count'], 'and the next refusal starts from one' );

	// A row from the first cut of this feature must not fatal or vanish.
	$GLOBALS['faz_options'][ Consent_Logger::REJECTION_OPTION ] = array( 'since' => $now - DAY_IN_SECONDS, 'count' => 42, 'last' => $now );
	$legacy = Consent_Logger::rejection_tally( $now );
	tok_check( 42 === $legacy['count'], 'a legacy flat tally is still reported' );
	tok_check( 42 === ( $legacy['causes'][ Consent_Logger::CAUSE_STALE_TOKEN ] ?? 0 ), 'attributed to the only cause that shape could hold' );
	$GLOBALS['faz_options'][ Consent_Logger::REJECTION_OPTION ] = array( 'since' => $now - 9 * DAY_IN_SECONDS, 'count' => 900, 'last' => $now - 9 * DAY_IN_SECONDS );
	tok_check( 0 === Consent_Logger::rejection_tally( $now )['count'], 'and an expired legacy tally still reads as zero' );

	// Junk in the option must read as nothing rather than fatal, and a bucket
	// dated in the future is skew, not data — keeping it would hold the tally
	// open indefinitely.
	$GLOBALS['faz_options'][ Consent_Logger::REJECTION_OPTION ] = 'not an array';
	tok_check( 0 === Consent_Logger::rejection_tally()['count'], 'a corrupt option reads as zero' );
	$GLOBALS['faz_options'][ Consent_Logger::REJECTION_OPTION ] = array( 'days' => array( ( $day + 3 ) => array( Consent_Logger::CAUSE_STALE_TOKEN => 9 ) ), 'last' => $now );
	tok_check( 0 === Consent_Logger::rejection_tally( $now )['count'], 'a bucket dated in the future is not counted' );
	$GLOBALS['faz_options'][ Consent_Logger::REJECTION_OPTION ] = array( 'days' => array( $day => array( Consent_Logger::CAUSE_STALE_TOKEN => -5 ) ), 'last' => $now );
	tok_check( 0 === Consent_Logger::rejection_tally( $now )['count'], 'and a negative count cannot subtract from the total' );
	$GLOBALS['faz_options'][ Consent_Logger::REJECTION_OPTION ] = array( 'days' => array( $day => array( Consent_Logger::CAUSE_STALE_TOKEN => 3 ) ), 'last' => $now + 5 * DAY_IN_SECONDS );
	tok_check( Consent_Logger::rejection_tally( $now )['last'] <= $now, 'a last-seen timestamp from the future is clamped to now' );

	// The write is rate-limited per client per cause. The endpoint's own per-IP
	// throttle runs AFTER the token check, so without this any anonymous caller
	// bought one guaranteed database write per request on the one route that has
	// to stay reachable without authentication.
	$GLOBALS['faz_options']   = array();
	$GLOBALS['faz_throttled'] = false;
	$reject->invoke( null, Consent_Logger::CAUSE_STALE_TOKEN );
	$GLOBALS['faz_throttled'] = true;
	$reject->invoke( null, Consent_Logger::CAUSE_STALE_TOKEN );
	$reject->invoke( null, Consent_Logger::CAUSE_STALE_TOKEN );
	tok_check( 1 === Consent_Logger::rejection_tally()['count'], 'a throttled client cannot drive the tally past one per window' );
	$GLOBALS['faz_throttled'] = false;
	$reject->invoke( null, Consent_Logger::CAUSE_STALE_TOKEN );
	tok_check( 2 === Consent_Logger::rejection_tally()['count'], 'and counting resumes when the window reopens' );
	$GLOBALS['faz_throttled'] = false;

	// The view must not read the option itself: that is how the two copies of
	// the 7-day rule came apart in the first place.
	$view = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/views/system-status.php' );
	tok_check( false !== strpos( $view, 'Consent_Logger::rejection_tally()' ), 'System Status reads the tally through the shared accessor' );
	tok_check( false === strpos( $view, "get_option( \\FazCookie\\Frontend\\Modules\\Consent_Logger\\Consent_Logger::REJECTION_OPTION" ), 'and not through get_option()' );

	// A count is printed, not cast. printf's %d applied to the grouped STRING
	// number_format_i18n() returns stops at the thousands separator, so 2.002
	// refused records rendered as "2" — a thousandfold understatement on the one
	// row whose job is to report how many records were lost. Every %d in this
	// view must be fed a raw integer, never a formatted one.
	// Bounded look-back rather than a balanced-block match: the format string
	// and its argument always sit within a few lines of each other, and a
	// greedy printf-block regex silently swallows half the file and reports a
	// false positive. Verified to flag both occurrences in the pre-fix view.
	$faz_formatted_into_d = function ( $src, $look_back = 6 ) {
		$lines = explode( "\n", $src );
		$hits  = array();
		foreach ( $lines as $i => $line ) {
			if ( false === strpos( $line, 'number_format_i18n' ) ) {
				continue;
			}
			for ( $j = max( 0, $i - $look_back ); $j < $i; $j++ ) {
				$candidate = preg_replace( '#/\*.*?\*/#', '', $lines[ $j ] );
				if ( preg_match( '/%\d*\$?d/', $candidate ) ) {
					$hits[] = $j + 1;
					break;
				}
			}
		}
		return $hits;
	};
	tok_check(
		0 === count( $faz_formatted_into_d( $view ) ),
		'no %d placeholder in System Status is fed a locale-formatted string'
	);
	// And the guard must be able to fail: it flags both offenders in the shape
	// this fix replaced, so a green result means something.
	tok_check(
		2 === count( $faz_formatted_into_d( (string) file_get_contents( __DIR__ . '/fixtures/system-status-pre-1321.php' ) ) ),
		'and the guard itself still flags the pre-fix shape'
	);
	tok_check( false !== strpos( $view, 'REJECTION_WINDOW_DAYS' ), 'the row names the tally window from the constant, not a literal' );
	// The row has to appear at zero too: one that shows up only on bad news
	// makes its own absence unreadable, and a site losing every record through a
	// cause nothing counted looked exactly like a healthy one.
	// Matched by shape, not by the old variable's name: `$faz_rejected_count` no
	// longer exists anywhere in the view, so a literal search for it could never
	// fail, and re-wrapping the row in `if ( $faz_refused > 0 ) :` would have
	// left this green. The alternative-syntax colon is what distinguishes a row
	// guard from the inner `if ( $faz_refused > 0 && … ) {` that decides whether
	// to add the "Most recent" sentence, which is legitimate.
	$faz_row_guard = '/if\s*\(\s*\$faz_\w+\s*>\s*0\s*\)\s*:/';
	tok_check( 0 === preg_match( $faz_row_guard, $view ), 'the row is no longer hidden when the tally is zero' );
	tok_check(
		1 === preg_match( $faz_row_guard, (string) file_get_contents( __DIR__ . '/fixtures/system-status-pre-1321.php' ) ),
		'and that guard flags the pre-fix shape, so a green result means something'
	);

	echo "\nPassed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
