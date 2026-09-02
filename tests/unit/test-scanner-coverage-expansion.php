<?php
/**
 * Scanner maximal-coverage regression tests.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'YEAR_IN_SECONDS', 31536000 );

$GLOBALS['faz_test_transients']        = array();
$GLOBALS['faz_test_transient_expiry']   = array();
$GLOBALS['faz_test_user_meta']         = array();

/**
 * Simulate elapsed wall-clock time.
 *
 * Rewinding the recorded expiries is equivalent to advancing the clock and needs
 * no override of PHP's time(): a transient written with a 900s TTL and then
 * rewound by 901s is expired, exactly as it would be after 901 real seconds.
 * The sliding capture window is a claim about elapsed time, so a test of it has
 * to be able to move time.
 *
 * @param int $seconds Seconds to pretend have passed.
 * @return void
 */
function faz_test_advance_clock( $seconds ) {
	foreach ( $GLOBALS['faz_test_transient_expiry'] as $key => $expires ) {
		$GLOBALS['faz_test_transient_expiry'][ $key ] = $expires - (int) $seconds;
	}
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}

function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function __( $value, ...$unused ) { return $value; }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $url ) { return filter_var( $url, FILTER_SANITIZE_URL ); }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function is_ssl() { return true; }
function get_current_user_id() { return 7; }
// Transients here honour their TTL, because the capture session's whole
// behaviour is a TTL. Direct writes to faz_test_transients (used further down to
// stage a session) record no expiry and therefore never expire, so the older
// cases are unaffected.
function get_transient( $key ) {
	if ( ! isset( $GLOBALS['faz_test_transients'][ $key ] ) ) {
		return false;
	}
	if ( isset( $GLOBALS['faz_test_transient_expiry'][ $key ] ) && $GLOBALS['faz_test_transient_expiry'][ $key ] <= time() ) {
		unset( $GLOBALS['faz_test_transients'][ $key ], $GLOBALS['faz_test_transient_expiry'][ $key ] );
		return false;
	}
	return $GLOBALS['faz_test_transients'][ $key ];
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['faz_test_transients'][ $key ] = $value;
	if ( $ttl > 0 ) {
		$GLOBALS['faz_test_transient_expiry'][ $key ] = time() + (int) $ttl;
	} else {
		unset( $GLOBALS['faz_test_transient_expiry'][ $key ] );
	}
	return true;
}
function delete_transient( $key ) { unset( $GLOBALS['faz_test_transients'][ $key ], $GLOBALS['faz_test_transient_expiry'][ $key ] ); }
function wp_generate_uuid4() { return '12345678-1234-1234-1234-123456789abc'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_user_meta( $user_id, $key, $single = false ) {
	$values = isset( $GLOBALS['faz_test_user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['faz_test_user_meta'][ $user_id ][ $key ] : array();
	return $single ? ( isset( $values[0] ) ? $values[0] : '' ) : $values;
}
function delete_user_meta( $user_id, $key, $value = '' ) {
	if ( empty( $GLOBALS['faz_test_user_meta'][ $user_id ][ $key ] ) ) {
		return;
	}
	$GLOBALS['faz_test_user_meta'][ $user_id ][ $key ] = array_values(
		array_filter(
			$GLOBALS['faz_test_user_meta'][ $user_id ][ $key ],
			static function ( $stored ) use ( $value ) { return $stored !== $value; }
		)
	);
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-cookie-database.php';

use FazCookie\Admin\Modules\Scanner\Includes\Controller;
use FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database;

$checks = array();
function coverage_check( $condition, $label ) {
	global $checks;
	$checks[] = array( (bool) $condition, $label );
}

/**
 * Extract one method body, comments stripped.
 *
 * Two things go wrong when a claim about a single method is matched against a
 * whole file. A prose comment can satisfy an assertion that the code does NOT
 * call something, and an identical line in an unrelated method can satisfy one
 * that it does — `'blocking' => false` appears in the cron nudge as well, so a
 * file-wide search would keep passing after the httpOnly queue lost it.
 *
 * @param string $source PHP source of the file.
 * @param string $name   Method name.
 * @return string|null Body without comments, or null when the method is gone.
 */
function faz_method_body( $source, $name ) {
	$tokens = token_get_all( $source );
	$count  = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}
		if ( $j >= $count || ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] || $name !== $tokens[ $j ][1] ) {
			continue;
		}
		$depth = 0;
		$body  = '';
		for ( $k = $j; $k < $count; $k++ ) {
			$token = $tokens[ $k ];
			$text  = is_array( $token ) ? $token[1] : $token;
			if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
			} elseif ( '}' === $token ) {
				--$depth;
				if ( 0 === $depth ) {
					return $body;
				}
			}
			if ( $depth > 0 && ( ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) ) {
				$body .= $text;
			}
		}
		return null;
	}
	return null;
}

/** Collapse whitespace so a reformat cannot fail a test whose subject did not change. */
function faz_squeeze( $text ) {
	return preg_replace( '/\s+/', ' ', (string) $text );
}

/** Whitespace-insensitive containment. */
function faz_contains_code( $haystack, $needle ) {
	return false !== strpos( faz_squeeze( $haystack ), faz_squeeze( $needle ) );
}

$controller = new Controller();

$first_scan_id  = str_repeat( '1', 32 );
$second_scan_id = str_repeat( '2', 32 );
$first_token    = $controller->start_browser_scan_session( $first_scan_id );
coverage_check( is_string( $first_token ) && 32 === strlen( $first_token ), 'discover creates an isolated browser capture session' );
coverage_check( $first_token === $controller->start_browser_scan_session( $first_scan_id ), 'a retry with the same scan ID reuses its active session' );
coverage_check( is_wp_error( $controller->start_browser_scan_session( $second_scan_id ) ), 'a second tab cannot overwrite an active browser scan' );

$names = $controller->extract_request_cookie_names(
	'wordpress_logged_in_hash=secret; brikpanel_vid=private; malformed name=x; tk_ai=dashboard; foo.bar=value',
	array( 'foo_bar' => 'value', 'brikpanel_vid' => 'private' )
);
coverage_check( in_array( 'brikpanel_vid', $names, true ), 'request-cookie probe retains HttpOnly-compatible cookie names' );
coverage_check( in_array( 'foo.bar', $names, true ) && ! in_array( 'foo_bar', $names, true ), 'raw Cookie header preserves dotted names without PHP-normalized duplicates' );
coverage_check( ! in_array( 'malformed name', $names, true ), 'invalid cookie names are rejected' );
coverage_check( count( array_keys( $names, 'brikpanel_vid', true ) ) === 1, 'request-cookie names are deduplicated' );
coverage_check( array( 'fallback_cookie' ) === $controller->extract_request_cookie_names( '', array( 'fallback_cookie' => 'value' ) ), 'parsed request cookies remain a fallback when the raw header is unavailable' );

$safe_urls = $controller->sanitize_scanned_urls(
	array(
		'https://example.test/product/one',
		'https://www.example.test/checkout?step=2',
		'https://example.test/index.php?probe=1',
		'https://example.test/wp-sitemap.xml',
		'https://evil.test/steal',
		'https://example.test:8443/internal-service',
		'file:///etc/passwd',
		'https://user:pass@example.test/private',
	)
);
// A bare count is not a check on normalisation: it went 2 -> 4 only because two
// URLs were added to the INPUT, and it stayed green with normalize_url()
// reverted. What the filter is FOR is the split — four same-site URLs kept,
// four hostile shapes refused — so assert both halves by name. The two
// in_array() checks below pin the normalisation itself.
$unsafe_expected = array(
	'https://evil.test/steal',                 // cross-origin
	'https://example.test:8443/internal-service', // non-standard port
	'file:///etc/passwd',                      // non-http scheme
	'https://user:pass@example.test/private',  // embedded credentials
);
$unsafe_present = array_values( array_intersect( $unsafe_expected, $safe_urls ) );
coverage_check( 4 === count( $safe_urls ), 'the four same-site URLs survive the safety filter' );
coverage_check( array() === $unsafe_present, 'every hostile URL shape is refused — cross-origin, odd port, non-http scheme, embedded credentials' );
coverage_check( false !== strpos( implode( ' ', $safe_urls ), '/checkout/' ), 'www/apex equivalent URLs remain eligible' );
coverage_check( in_array( 'https://example.test/index.php?probe=1', $safe_urls, true ), 'file-like PHP routes keep their original path during replay' );
coverage_check( in_array( 'https://example.test/wp-sitemap.xml', $safe_urls, true ), 'file-like XML routes are not rewritten to a different trailing-slash URL' );
coverage_check( false === strpos( implode( ' ', $safe_urls ), 'evil.test' ), 'foreign hosts are excluded from server replay' );
coverage_check( false === strpos( implode( ' ', $safe_urls ), ':8443' ), 'alternate same-host ports are excluded from server replay' );

$token = str_repeat( 'a', 32 );
$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $token;
$GLOBALS['faz_test_transients'][ 'faz_scan_session_' . hash( 'sha256', $token ) ] = array( 'user_id' => 7, 'created_at' => time() );
$runtime_scan_id = str_repeat( 'b', 32 );
$GLOBALS['faz_test_transients'][ 'faz_scan_session_' . hash( 'sha256', $token ) ]['scan_id'] = $runtime_scan_id;
coverage_check( Controller::is_browser_scan_request(), 'a live scanner marker is accepted by the PHP Set-Cookie observer and guard' );
$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = str_repeat( 'f', 32 );
coverage_check( ! Controller::is_browser_scan_request(), 'a forged scanner marker cannot bypass PHP Set-Cookie blocking' );
$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $token;
$GLOBALS['faz_test_user_meta'][7][ Controller::BROWSER_SCAN_META ] = array(
	array(
		'token'       => $token,
		'observed_at' => time(),
		'name'        => 'brikpanel_vid',
		'domain'      => '',
		'path'        => '/',
		'expires'     => '',
		'max-age'     => '31536000',
		'httponly'    => true,
	),
	array(
		'token'       => $token,
		'observed_at' => time(),
		'name'        => 'cleared_cookie',
		'domain'      => '',
		'path'        => '/',
		'expires'     => '',
		'max-age'     => '-1',
		'httponly'    => true,
	),
	array(
		'token'       => $token,
		'observed_at' => time(),
		'name'        => 'ordered_cookie',
		'domain'      => 'example.test',
		'path'        => '/account',
		'expires'     => '',
		'max-age'     => '3600',
	),
	array(
		'token'       => $token,
		'observed_at' => time(),
		'name'        => 'ordered_cookie',
		'domain'      => 'example.test',
		'path'        => '/account',
		'expires'     => '',
		'max-age'     => '0',
	),
	array(
		'token'       => $token,
		'observed_at' => time(),
		'name'        => 'path_cookie',
		'domain'      => 'example.test',
		'path'        => '/one',
		'expires'     => '',
		'max-age'     => '3600',
	),
	array(
		'token'       => $token,
		'observed_at' => time(),
		'name'        => 'path_cookie',
		'domain'      => 'example.test',
		'path'        => '/two',
		'expires'     => '',
		'max-age'     => '-1',
	),
	array(
		'token'       => $token,
		'observed_at' => time(),
		'name'        => 'expired_cookie',
		'domain'      => 'example.test',
		'path'        => '/',
		'expires'     => 'Thu, 01 Jan 1970 00:00:00 GMT',
		'max-age'     => '',
	),
);
$runtime         = $controller->collect_browser_scan_session( $runtime_scan_id );
$runtime_by_name = array();
foreach ( $runtime as $runtime_cookie ) {
	$runtime_by_name[ $runtime_cookie['name'] ] = $runtime_cookie;
}
coverage_check( isset( $runtime_by_name['brikpanel_vid'] ), 'runtime Set-Cookie observation is collected into the inventory' );
coverage_check( 'server-runtime' === $runtime_by_name['brikpanel_vid']['source'], 'runtime observation preserves its discovery provenance' );
coverage_check( '1 year' === $runtime_by_name['brikpanel_vid']['duration'], 'runtime Max-Age metadata becomes a useful duration' );
coverage_check( ! isset( $runtime_by_name['cleared_cookie'] ), 'a deletion-only runtime directive is not presented as a session cookie' );
coverage_check( ! isset( $runtime_by_name['ordered_cookie'] ), 'a later Max-Age=0 removes the earlier observation with the same name/domain/path' );
coverage_check( isset( $runtime_by_name['path_cookie'] ), 'a deletion with a different path cannot erase an active cookie identity' );
coverage_check( ! isset( $runtime_by_name['expired_cookie'] ), 'an Expires date in the past is treated as deletion when Max-Age is absent' );
coverage_check( 7 === count( $GLOBALS['faz_test_user_meta'][7][ Controller::BROWSER_SCAN_META ] ), 'collection remains non-destructive until persistence succeeds' );
coverage_check( $controller->browser_scan_session_matches( $runtime_scan_id ), 'collection leaves the same scan id retryable' );
coverage_check( $controller->finish_browser_scan_session( $runtime_scan_id ), 'a successful import can explicitly close its capture session' );
coverage_check( empty( $GLOBALS['faz_test_user_meta'][7][ Controller::BROWSER_SCAN_META ] ), 'finishing removes the collected observations' );
coverage_check( ! $controller->browser_scan_session_matches( $runtime_scan_id ), 'finishing removes the session transient' );

/* ── The capture window must outlive the crawl it was opened for ──────
 *
 * BROWSER_SCAN_TTL is opened once by scans/discover and scans/import hard-409s
 * on an expired session, so a fixed wall clock means a long crawl spends its
 * whole run collecting evidence and then throws away 100% of it. These cases
 * pin the idle-timeout semantics: an ACTIVE crawl of any length survives, an
 * ABANDONED tab still releases, and a wedged one cannot slide forever.
 */
$GLOBALS['faz_test_transients']      = array();
$GLOBALS['faz_test_transient_expiry'] = array();
$GLOBALS['faz_test_user_meta']       = array();

$slide_scan_id = str_repeat( 'c', 32 );
$slide_token   = $controller->start_browser_scan_session( $slide_scan_id );
$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $slide_token;

// 800s in — inside the window, and the point at which a scanned page arrives.
faz_test_advance_clock( 800 );
coverage_check( $controller->browser_scan_session_matches( $slide_scan_id ), 'a session is still live 800s into a crawl' );
coverage_check( $controller->touch_browser_scan_session( $slide_token, $slide_scan_id ), 'a scanned page slides the capture window forward' );

// Past the original 900s deadline. Under the fixed wall clock this is where
// the import used to 409 and discard the entire run.
faz_test_advance_clock( 300 );
coverage_check( $controller->browser_scan_session_matches( $slide_scan_id ), 'a touched session outlives the original TTL — 1100s in and still importable' );
coverage_check( false !== get_transient( 'faz_scan_active_7' ), 'the active-scan lock is renewed alongside the session, not left to expire under it' );

// A renewal must belong to the session presenting it. Two tabs still collide.
coverage_check( ! $controller->touch_browser_scan_session( $slide_token, str_repeat( 'd', 32 ) ), 'a foreign scan id cannot renew someone else\'s session' );

// The absolute ceiling: a wedged tab must not hold the lock indefinitely.
$slide_key = 'faz_scan_session_' . hash( 'sha256', $slide_token );
$GLOBALS['faz_test_transients'][ $slide_key ]['created_at'] = time() - ( Controller::BROWSER_SCAN_MAX_AGE + 60 );
coverage_check( ! $controller->touch_browser_scan_session( $slide_token, $slide_scan_id ), 'a session past BROWSER_SCAN_MAX_AGE stops sliding' );

// And the idle timeout still bites on an abandoned tab.
$GLOBALS['faz_test_transients']      = array();
$GLOBALS['faz_test_transient_expiry'] = array();
$idle_scan_id = str_repeat( 'e', 32 );
$idle_token   = $controller->start_browser_scan_session( $idle_scan_id );
$_COOKIE[ Controller::BROWSER_SCAN_COOKIE ] = $idle_token;
faz_test_advance_clock( Controller::BROWSER_SCAN_TTL + 1 );
coverage_check( ! $controller->browser_scan_session_matches( $idle_scan_id ), 'an untouched session still expires on schedule, so an abandoned tab releases the lock' );
coverage_check( ! $controller->touch_browser_scan_session( $idle_token, $idle_scan_id ), 'an already-expired session cannot be revived by a late heartbeat' );

// One canonical key on both sides of the stale-cookie tally. A leading dot or a
// :port on either end is what would make the client/server intersection empty
// forever — inert, while looking wired.
coverage_check( '_ga|example.test' === Controller::canonical_key( '  _GA  ', '.Example.TEST:8443' ), 'canonical_key folds case, leading dots and ports exactly as the browser does' );
coverage_check( '' === Controller::canonical_key( '   ', 'example.test' ), 'a nameless row has no canonical identity' );
coverage_check( '_ga|' === Controller::canonical_key( '_ga', '' ), 'a domainless row still canonicalizes to a usable key' );

// Automattic Tracks is inventoried as ANALYTICS, not internal. The category is a
// behaviour, not a label: `wordpress-internal` is allowed before any consent check
// and filtered out of every visitor-facing declaration, so classifying a cookie
// Jetpack also sets outside wp-admin there made it permanently unblockable and
// undisclosed. The property this line exists to pin — that the cookie stays
// INVENTORIED rather than dropping out of the database — is unchanged.
coverage_check( is_array( Cookie_Database::lookup( 'tk_ai' ) ), 'Automattic Tracks cookies stay inventoried' );
coverage_check( 'analytics' === Cookie_Database::lookup( 'tk_ai' )['category'], 'and are classified analytics, so they can be gated and must be declared' );
coverage_check( 'analytics' === Cookie_Database::lookup( 'brikpanel_vid' )['category'], 'Brikpanel visitor ID is classified when observed' );

$root = dirname( __DIR__, 2 );
$engine = (string) file_get_contents( $root . '/admin/assets/js/modules/scan-engine.js' );
$api = (string) file_get_contents( $root . '/admin/modules/scanner/api/class-api.php' );
$bootstrap = (string) file_get_contents( $root . '/faz-cookie-manager.php' );
$controller_source = (string) file_get_contents( $root . '/admin/modules/scanner/includes/class-controller.php' );
coverage_check( false === strpos( $engine, 'EARLY_STOP_THRESHOLD' ), 'scanner has no implicit path that overrides the administrator-selected depth' );
coverage_check( false !== strpos( $engine, 'scanned_urls: scanMetrics.scannedUrls' ), 'browser-visited URLs are sent to server enrichment' );
coverage_check( false !== strpos( $engine, "getEntriesByType('resource')" ), 'runtime network resources feed provider inference' );
coverage_check( false !== strpos( $engine, 'triggerDelayedScripts();' ), 'interaction-delayed scripts receive a controlled activation signal' );
coverage_check( false !== strpos( $engine, 'MAX_RESOURCE_URLS' ), 'runtime resource payloads are capped and diagnosed' );
$import_body = faz_method_body( $api, 'import_cookies' );
coverage_check( null !== $import_body && false !== strpos( $import_body, 'collect_browser_scan_session( $scan_id )' ), 'REST import reads only its own runtime Set-Cookie observations without consuming them' );
$save_position     = null !== $import_body ? strpos( $import_body, 'save_scan_result(' ) : false;
$finish_position   = null !== $import_body ? strpos( $import_body, 'finish_browser_scan_session( $scan_id )' ) : false;
$schedule_position = null !== $import_body ? strpos( $import_body, 'schedule_httponly_check( $scanned_urls )' ) : false;
coverage_check(
	false !== $save_position
		&& false !== $finish_position
		&& false !== $schedule_position
		&& $finish_position > $save_position
		&& $schedule_position > $save_position,
	'session teardown and background replay both happen only after persistence'
);
// strpos finds the FIRST occurrence, so the check above is satisfied by a
// teardown placed after the save even when a SECOND one sits inside the catch
// — which is the exact regression it is named after, verified by injecting it.
// The catch is where the promise lives: import_cookies() answers 500 saying the
// administrator may retry, and a retry is only possible if nothing in that
// branch destroys the session. Assert the branch itself is inert.
$catch_at   = null !== $import_body ? strpos( $import_body, 'catch (' ) : false;
$catch_body = '';
if ( false !== $catch_at ) {
	// Brace-match the catch BLOCK. Taking substr() to the end of the method
	// instead would sweep in the legitimate post-try teardown and schedule
	// calls and make this assertion permanently red — the mirror-image error
	// of the strpos check above, and worth naming so it is not "fixed" that
	// way again.
	$open  = strpos( $import_body, '{', $catch_at );
	$depth = 0;
	for ( $i = $open; false !== $open && $i < strlen( $import_body ); $i++ ) {
		if ( '{' === $import_body[ $i ] ) {
			$depth++;
		} elseif ( '}' === $import_body[ $i ] ) {
			$depth--;
			if ( 0 === $depth ) {
				$catch_body = substr( $import_body, $open, $i - $open + 1 );
				break;
			}
		}
	}
}
coverage_check(
	'' !== $catch_body
		&& false === strpos( $catch_body, 'finish_browser_scan_session(' )
		&& false === strpos( $catch_body, 'schedule_httponly_check(' ),
	'the failure branch neither tears the session down nor queues a replay — the retry it promises stays possible'
);
coverage_check( false !== strpos( $bootstrap, 'register_browser_scan_observer()' ), 'Set-Cookie observer is registered on every same-origin request' );
$observer_body = faz_method_body( $controller_source, 'register_browser_scan_observer' );
coverage_check(
	null !== $observer_body
		&& false === strpos( $observer_body, 'header_register_callback' )
		&& faz_contains_code( $observer_body, "add_action( 'shutdown'," ),
	'runtime observer coexists with other PHP header callbacks'
);
coverage_check( false !== strpos( $controller_source, "'redirection'        => 0" ) && false !== strpos( $controller_source, 'WP_Http::make_absolute_url' ), 'server replay validates every redirect hop and retains intermediate headers' );
coverage_check( false !== strpos( $controller_source, 'BROWSER_SCAN_OBSERVATION_LIMIT' ), 'repeated runtime Set-Cookie observations are deduplicated and capped' );
$httponly_queue_body = faz_method_body( $controller_source, 'schedule_httponly_check' );
coverage_check(
	null !== $httponly_queue_body
		&& faz_contains_code( $httponly_queue_body, 'wp_schedule_single_event( time() + 1, self::HTTPONLY_CRON_HOOK )' )
		&& faz_contains_code( $httponly_queue_body, "'blocking' => false" ),
	'httpOnly enrichment is durably queued without holding the REST import open'
);

$failed = 0;
foreach ( $checks as $index => $check ) {
	if ( $check[0] ) {
		echo 'PASS ' . ( $index + 1 ) . ': ' . $check[1] . "\n";
	} else {
		++$failed;
		echo 'FAIL ' . ( $index + 1 ) . ': ' . $check[1] . "\n";
	}
}
echo count( $checks ) . ' checks, ' . $failed . " failed\n";
exit( $failed > 0 ? 1 : 0 );
