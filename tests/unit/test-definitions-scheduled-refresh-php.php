<?php
/** Opt-in scheduling and preservation of the last usable definitions. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
$options = array();
$events = array();
$requests = 0;
$http_code = 503;
$body = '';
function get_option( $key, $default = false ) { global $options; return $options[$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) { global $options; $options[$key] = $value; return true; }
function wp_next_scheduled( $hook ) { global $events; return $events[$hook] ?? false; }
function wp_schedule_event( $when, $frequency, $hook ) { global $events; $events[$hook] = $when; return true; }
function wp_clear_scheduled_hook( $hook ) { global $events; unset( $events[$hook] ); }
function wp_remote_get( $url, $args ) { global $requests; ++$requests; return array(); }
function is_wp_error( $response ) { return false; }
function wp_remote_retrieve_response_code( $response ) { global $http_code; return $http_code; }
function wp_remote_retrieve_body( $response ) { global $body; return $body; }
function current_time( $format, $gmt = false ) { return '2026-09-16 12:00:00'; }
function delete_transient( $key ) { return true; }
// Refresh-path messages are wrapped in __() so they are translatable at the
// source (F024); this harness has no i18n runtime, so echo the string back.
function __( $text, $domain = 'default' ) { return $text; }
require_once dirname( __DIR__, 2 ) . '/includes/class-cookie-definitions.php';
use FazCookie\Includes\Cookie_Definitions as Definitions;
$failed = 0;
$passed = 0;
function check_refresh( $condition, $message ) {
	global $failed, $passed;
	if ( $condition ) { ++$passed; echo "PASS: $message\n"; }
	else { ++$failed; echo "FAIL: $message\n"; }
}
Definitions::schedule_updates();
Definitions::cron_update();
check_refresh( empty( $events ) && 0 === $requests, 'default settings neither schedule nor contact a remote host' );
$options['faz_settings']['scanner']['auto_update_definitions'] = true;
Definitions::schedule_updates();
check_refresh( isset( $events['faz_weekly_definitions_update'] ), 'opt-in schedules the refresh' );
$before = $events;
Definitions::schedule_updates();
check_refresh( $events === $before, 'repeated scheduling preserves the existing event' );
$options[Definitions::OPTION_KEY] = array( 'old' => array( array( 'cookie' => '_old', 'category' => 'Analytics' ) ) );
$original = $options[Definitions::OPTION_KEY];
Definitions::cron_update();
check_refresh( $original === $options[Definitions::OPTION_KEY] && false === $options['faz_definitions_refresh_status']['success'], 'HTTP failure keeps definitions and records an admin-visible failure' );
// F024: the message text must come out of __() so translators can pick it up
// at the source, rather than being a raw English literal buried in the class.
check_refresh( 'HTTP 503 from GitHub' === $options['faz_definitions_refresh_status']['message'], 'HTTP-failure message is built through __() with the code interpolated' );
$http_code = 200;
$body = 'not JSON';
Definitions::cron_update();
check_refresh( $original === $options[Definitions::OPTION_KEY], 'malformed payload keeps definitions' );
check_refresh( 'Invalid JSON or empty dataset' === $options['faz_definitions_refresh_status']['message'], 'malformed-payload message is translatable' );
$body = json_encode( array( 'platform' => array() ) );
Definitions::cron_update();
check_refresh( $original === $options[Definitions::OPTION_KEY] && false === $options['faz_definitions_refresh_status']['success'], 'valid JSON with zero definitions preserves the dataset and records failure' );
check_refresh( 'No valid cookie definitions in response' === $options['faz_definitions_refresh_status']['message'], 'zero-definitions message is translatable' );
$body = json_encode( array( 'new' => array( array( 'cookie' => '_new', 'category' => 'Analytics' ) ) ) );
Definitions::cron_update();
check_refresh( isset( $options[Definitions::OPTION_KEY]['new'] ) && true === $options['faz_definitions_refresh_status']['success'], 'successful update replaces definitions and clears the failure verdict' );
check_refresh( 'Downloaded 1 cookie definitions' === $options['faz_definitions_refresh_status']['message'], 'success message is built through __() with the count interpolated' );
$options['faz_settings']['scanner']['auto_update_definitions'] = false;
Definitions::schedule_updates();
$before_requests = $requests;
Definitions::cron_update();
check_refresh( empty( $events ) && $requests === $before_requests, 'disabling removes the event and prevents an already queued callback from fetching' );
// F024: translatable at the source, not just correct at runtime — a plain
// literal and a __()-wrapped one produce the same text through this harness's
// identity stub, so the runtime checks above cannot tell them apart. Read the
// class source directly and require the __() wrapper on every refresh-path
// message that isn't already WP's own (get_error_message() is translated by
// WP itself and is deliberately not asserted here).
$defs_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cookie-definitions.php' );
check_refresh(
	false !== strpos( $defs_source, "sprintf( __( 'HTTP %d from GitHub', 'faz-cookie-manager' )" ),
	'HTTP-failure literal is wrapped in __() at the source'
);
check_refresh(
	false !== strpos( $defs_source, "__( 'Invalid JSON or empty dataset', 'faz-cookie-manager' )" ),
	'malformed-payload literal is wrapped in __() at the source'
);
check_refresh(
	false !== strpos( $defs_source, "__( 'No valid cookie definitions in response', 'faz-cookie-manager' )" ),
	'zero-definitions literal is wrapped in __() at the source'
);
check_refresh(
	false !== strpos( $defs_source, "sprintf( __( 'Downloaded %d cookie definitions', 'faz-cookie-manager' )" ),
	'success literal is wrapped in __() at the source'
);

// F024: the Cookies-page notice must format the stored current_time('mysql')
// timestamp for the admin's locale via date_i18n(), and must not read into
// the stored option's keys without confirming it is actually an array.
$cookies_source = file_get_contents( dirname( __DIR__, 2 ) . '/admin/views/cookies.php' );
check_refresh( false !== strpos( $cookies_source, 'date_i18n(' ), 'the refresh timestamp is formatted with date_i18n()' );
check_refresh(
	(bool) preg_match( '/is_array\(\s*\$faz_refresh\s*\)/', $cookies_source ),
	'the refresh-status option is guarded with is_array() before its keys are read'
);

// F024: the notice must live inside the card body, alongside the rest of the
// card's content, not floating above the header.
$card_pos       = strpos( $cookies_source, 'id="faz-cookie-definitions-card"' );
$body_pos       = strpos( $cookies_source, 'faz-card-body', $card_pos );
$notice_pos     = strpos( $cookies_source, 'Last automatic definitions update attempt', $card_pos );
check_refresh(
	false !== $card_pos && false !== $body_pos && false !== $notice_pos && $notice_pos > $body_pos,
	'the last-refresh notice is inside the card body, not floating above the header'
);

echo "$passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
