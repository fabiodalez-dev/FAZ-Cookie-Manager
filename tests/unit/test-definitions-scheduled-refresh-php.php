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
$http_code = 200;
$body = 'not JSON';
Definitions::cron_update();
check_refresh( $original === $options[Definitions::OPTION_KEY], 'malformed payload keeps definitions' );
$body = json_encode( array( 'new' => array( array( 'cookie' => '_new', 'category' => 'Analytics' ) ) ) );
Definitions::cron_update();
check_refresh( isset( $options[Definitions::OPTION_KEY]['new'] ) && true === $options['faz_definitions_refresh_status']['success'], 'successful update replaces definitions and clears the failure verdict' );
$options['faz_settings']['scanner']['auto_update_definitions'] = false;
Definitions::schedule_updates();
$before_requests = $requests;
Definitions::cron_update();
check_refresh( empty( $events ) && $requests === $before_requests, 'disabling removes the event and prevents an already queued callback from fetching' );
echo "$passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
