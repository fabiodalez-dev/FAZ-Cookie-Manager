<?php
/**
 * Consent-log retention must be able to catch up with ingest.
 *
 * cleanup_old_logs() removes at most faz_retention_max_rows rows per run
 * (200000 by default) and ran once a day. A site that records more decisions
 * than that a day never emptied its expired backlog: the rows past the
 * retention period only accumulated (GDPR Art. 5(1)(e)). A purge that stops at
 * its cap therefore has to resume before the next daily run, as the pageview
 * purge already does.
 *
 * Drives the real Controller against a $wpdb double holding expired rows, and
 * reads the activation / deactivation / uninstall sources for the hook being
 * registered and cleared.
 *
 * Run: php tests/unit/test-consentlog-retention-catchup-php.php
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
	date_default_timezone_set( 'UTC' );

	$GLOBALS['cl_filters']   = array();
	$GLOBALS['cl_scheduled'] = array();
	$GLOBALS['cl_options']   = array( 'faz_consent_logs_db_version' => '9.9' );
	$GLOBALS['cl_now']       = '2026-09-15 12:00:00';

	function apply_filters( $tag, $value ) { return array_key_exists( $tag, $GLOBALS['cl_filters'] ) ? $GLOBALS['cl_filters'][ $tag ] : $value; }
	function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['cl_options'] ) ? $GLOBALS['cl_options'][ $key ] : $default; }
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['cl_options'][ $key ] = $value; return true; }
	function current_time( $type, $gmt = 0 ) { return $GLOBALS['cl_now']; }
	function did_action( $hook ) { return 1; }
	function add_action( ...$args ) { return true; }
	function wp_next_scheduled( $hook, $args = array() ) {
		foreach ( $GLOBALS['cl_scheduled'] as $event ) {
			if ( $event['hook'] === $hook ) {
				return $event['at'];
			}
		}
		return false;
	}
	function wp_schedule_single_event( $at, $hook, $args = array() ) {
		$GLOBALS['cl_scheduled'][] = array( 'at' => $at, 'hook' => $hook, 'args' => $args );
		return true;
	}
	function sanitize_text_field( $v ) { return trim( (string) $v ); }
	function absint( $v ) { return abs( (int) $v ); }

	class CL_Retention_WPDB {
		public $prefix     = 'wp_';
		public $last_error = '';
		/** log_id => created_at */
		public $rows       = array();
		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return array( $query, $args );
		}
		public function get_col( $prepared ) {
			list( $query, $args ) = $prepared;
			$out = array();
			foreach ( $this->rows as $id => $created ) {
				if ( count( $out ) >= (int) $args[1] ) {
					break;
				}
				if ( $created < $args[0] ) {
					$out[] = (string) $id;
				}
			}
			return $out;
		}
		public function query( $prepared ) {
			list( $query, $args ) = $prepared;
			$n = 0;
			foreach ( $args as $id ) {
				if ( isset( $this->rows[ $id ] ) ) {
					unset( $this->rows[ $id ] );
					++$n;
				}
			}
			return $n;
		}
		public function get_charset_collate() { return ''; }
	}

	/** A database whose DELETE always fails. */
	class CL_Retention_Failing_WPDB extends CL_Retention_WPDB {
		public function query( $prepared ) {
			$this->last_error = 'boom';
			return false;
		}
	}
	$GLOBALS['wpdb'] = new CL_Retention_WPDB();

	require_once dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/includes/class-controller.php';
	use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;

	$passed = 0;
	$failed = 0;
	function cl_check( $ok, $label ) {
		global $passed, $failed;
		if ( $ok ) { ++$passed; echo "  [PASS] {$label}\n"; }
		else { ++$failed; echo "  [FAIL] {$label}\n"; }
	}

	$hook = 'faz_consent_log_retention_continue';

	$ctor = new \ReflectionClass( Controller::class );
	$ctrl = $ctor->newInstanceWithoutConstructor();
	$prop = $ctor->getProperty( 'instance' );
	$prop->setAccessible( true );
	$prop->setValue( null, $ctrl );

	// 250 rows two years old, 5 fresh ones; batch and cap of 100 per run.
	$GLOBALS['cl_filters'] = array( 'faz_retention_batch_size' => 100, 'faz_retention_max_rows' => 100 );
	for ( $i = 1; $i <= 250; $i++ ) {
		$GLOBALS['wpdb']->rows[ $i ] = '2024-09-01 00:00:00';
	}
	for ( $i = 251; $i <= 255; $i++ ) {
		$GLOBALS['wpdb']->rows[ $i ] = '2026-09-15 11:00:00';
	}

	$deleted = $ctrl->cleanup_old_logs( 12 );
	cl_check( 100 === $deleted, 'one run removes no more than its cap' );
	cl_check( 1 === count( $GLOBALS['cl_scheduled'] ) && $hook === $GLOBALS['cl_scheduled'][0]['hook'], 'a run that stopped at its cap schedules a continuation' );
	cl_check( isset( $GLOBALS['cl_scheduled'][0] ) && $GLOBALS['cl_scheduled'][0]['at'] > time(), 'the continuation is in the future, not an immediate re-entry' );

	$ctrl->cleanup_old_logs( 12 );
	cl_check( 1 === count( $GLOBALS['cl_scheduled'] ), 'a continuation already pending is not scheduled twice' );

	// The continuation runs (the cron event fires): it reads the setting again.
	$GLOBALS['cl_scheduled'] = array();
	$GLOBALS['cl_options']['faz_settings'] = array( 'consent_logs' => array( 'retention' => 12 ) );
	if ( method_exists( Controller::class, 'continue_retention' ) ) {
		Controller::continue_retention();
	}
	cl_check( 5 === count( $GLOBALS['wpdb']->rows ), 'the continuation finishes the backlog and keeps the fresh rows' );
	cl_check( array() === $GLOBALS['cl_scheduled'], 'a run that ends below its cap schedules nothing' );

	// Retention turned off while a slice was pending: the slice deletes nothing.
	for ( $i = 1; $i <= 50; $i++ ) {
		$GLOBALS['wpdb']->rows[ 1000 + $i ] = '2024-09-01 00:00:00';
	}
	$before = count( $GLOBALS['wpdb']->rows );
	$GLOBALS['cl_options']['faz_settings'] = array( 'consent_logs' => array( 'retention' => 0 ) );
	if ( method_exists( Controller::class, 'continue_retention' ) ) {
		Controller::continue_retention();
	}
	cl_check( $before === count( $GLOBALS['wpdb']->rows ), 'a continuation after the purge was disabled deletes nothing' );

	// A failed DELETE is not "reached the cap": nothing is rescheduled on it.
	$GLOBALS['cl_scheduled'] = array();
	$real                    = $GLOBALS['wpdb'];
	$GLOBALS['wpdb']         = new CL_Retention_Failing_WPDB();
	$GLOBALS['wpdb']->rows   = $real->rows;
	$log_to                  = ini_set( 'error_log', '/dev/null' );
	$ctrl->cleanup_old_logs( 12 );
	ini_set( 'error_log', (string) $log_to );
	$GLOBALS['wpdb'] = $real;
	cl_check( array() === $GLOBALS['cl_scheduled'], 'a run stopped by a database error schedules no continuation' );

	// The event is wired to its callback and cleared with the other cron hooks.
	$root        = dirname( __DIR__, 2 );
	$activator   = (string) file_get_contents( $root . '/includes/class-activator.php' );
	$deactivator = (string) file_get_contents( $root . '/includes/class-deactivator.php' );
	$uninstall   = (string) file_get_contents( $root . '/uninstall.php' );
	cl_check( 1 === preg_match( "/add_action\(\s*'" . $hook . "'\s*,[^;]*'continue_retention'/", $activator ), 'the continuation hook is registered to continue_retention' );
	cl_check( false !== strpos( $deactivator, "wp_clear_scheduled_hook( '" . $hook . "' )" ), 'deactivation clears the continuation hook' );
	cl_check( false !== strpos( $uninstall, "wp_clear_scheduled_hook( '" . $hook . "' )" ), 'uninstall clears the continuation hook' );

	echo "\nconsent-log retention catch-up: {$passed} passed, {$failed} failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
