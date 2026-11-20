<?php
/**
 * Pageview retention must be able to catch up with ingest.
 *
 * cleanup_old_records() removes at most faz_retention_max_rows rows per run
 * (200000 by default) and ran once a day, while one address may insert five
 * rows a second (432000 a day). A purge that stops at its cap therefore has
 * to resume before the next daily run, or the expired backlog only grows.
 *
 * Drives the real Controller against a $wpdb double holding expired rows.
 *
 * Run: php tests/unit/test-pageview-retention-catchup-php.php
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
	date_default_timezone_set( 'UTC' );

	$GLOBALS['pv_filters']   = array();
	$GLOBALS['pv_scheduled'] = array();
	$GLOBALS['pv_options']   = array( 'faz_pageviews_db_version' => '9.9' );
	$GLOBALS['pv_now']       = '2026-09-15 12:00:00';

	function apply_filters( $tag, $value ) { return array_key_exists( $tag, $GLOBALS['pv_filters'] ) ? $GLOBALS['pv_filters'][ $tag ] : $value; }
	function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['pv_options'] ) ? $GLOBALS['pv_options'][ $key ] : $default; }
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['pv_options'][ $key ] = $value; return true; }
	function current_time( $type, $gmt = 0 ) { return $GLOBALS['pv_now']; }
	function wp_next_scheduled( $hook, $args = array() ) {
		foreach ( $GLOBALS['pv_scheduled'] as $event ) {
			if ( $event['hook'] === $hook ) {
				return $event['at'];
			}
		}
		return false;
	}
	function wp_schedule_single_event( $at, $hook, $args = array() ) {
		$GLOBALS['pv_scheduled'][] = array( 'at' => $at, 'hook' => $hook, 'args' => $args );
		return true;
	}
	function sanitize_text_field( $v ) { return trim( (string) $v ); }
	function absint( $v ) { return abs( (int) $v ); }

	class PV_Retention_WPDB {
		public $prefix     = 'wp_';
		public $last_error = '';
		/** id => created_at */
		public $rows       = array();
		public $selects    = array();
		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return array( $query, $args );
		}
		public function get_col( $prepared ) {
			list( $query, $args ) = $prepared;
			$this->selects[]      = $args[0];
			$out = array();
			foreach ( $this->rows as $id => $created ) {
				if ( $created < $args[0] ) {
					$out[] = (string) $id;
				}
				if ( count( $out ) >= (int) $args[1] ) {
					break;
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
	$GLOBALS['wpdb'] = new PV_Retention_WPDB();

	require_once dirname( __DIR__, 2 ) . '/admin/modules/pageviews/includes/class-controller.php';
	use FazCookie\Admin\Modules\Pageviews\Includes\Controller;

	$passed = 0;
	$failed = 0;
	function pv_check( $ok, $label ) {
		global $passed, $failed;
		if ( $ok ) { ++$passed; echo "  [PASS] {$label}\n"; }
		else { ++$failed; echo "  [FAIL] {$label}\n"; }
	}

	$ctor = new \ReflectionClass( Controller::class );
	$ctrl = $ctor->newInstanceWithoutConstructor();
	$prop = $ctor->getProperty( 'instance' );
	$prop->setAccessible( true );
	$prop->setValue( null, $ctrl );

	// 250 rows a year old, 5 fresh ones; batch and cap of 100 per run.
	$GLOBALS['pv_filters'] = array( 'faz_retention_batch_size' => 100, 'faz_retention_max_rows' => 100 );
	for ( $i = 1; $i <= 250; $i++ ) {
		$GLOBALS['wpdb']->rows[ $i ] = '2025-09-01 00:00:00';
	}
	for ( $i = 251; $i <= 255; $i++ ) {
		$GLOBALS['wpdb']->rows[ $i ] = '2026-09-15 11:00:00';
	}

	$deleted = $ctrl->cleanup_old_records( 6 );
	pv_check( 100 === $deleted, 'one run removes no more than its cap' );
	pv_check( 1 === count( $GLOBALS['pv_scheduled'] ) && 'faz_pageview_retention_continue' === $GLOBALS['pv_scheduled'][0]['hook'], 'a run that stopped at its cap schedules a continuation' );

	$ctrl->cleanup_old_records( 6 );
	pv_check( 1 === count( $GLOBALS['pv_scheduled'] ), 'a continuation already pending is not scheduled twice' );

	// The continuation runs (the cron event fires): it reads the setting again.
	$GLOBALS['pv_scheduled'] = array();
	$GLOBALS['pv_options']['faz_settings'] = array( 'pageviews' => array( 'retention' => 6 ) );
	Controller::continue_retention();
	pv_check( 5 === count( $GLOBALS['wpdb']->rows ), 'the continuation finishes the backlog and keeps the fresh rows' );
	pv_check( array() === $GLOBALS['pv_scheduled'], 'a run that ends below its cap schedules nothing' );

	// Retention turned off while a slice was pending: the slice deletes nothing.
	for ( $i = 1; $i <= 50; $i++ ) {
		$GLOBALS['wpdb']->rows[ 1000 + $i ] = '2025-09-01 00:00:00';
	}
	$GLOBALS['pv_options']['faz_settings'] = array( 'pageviews' => array( 'retention' => 0 ) );
	Controller::continue_retention();
	pv_check( 55 === count( $GLOBALS['wpdb']->rows ), 'a continuation after the purge was disabled deletes nothing' );

	// The cutoff is on the site clock record_event() writes created_at with.
	$GLOBALS['wpdb']->selects = array();
	$GLOBALS['pv_now']        = '2020-01-15 12:00:00';
	$ctrl->cleanup_old_records( 1 );
	pv_check( isset( $GLOBALS['wpdb']->selects[0] ) && '2019-12-15 12:00:00' === $GLOBALS['wpdb']->selects[0], 'the cutoff is computed from current_time( mysql ), not UTC now' );

	echo "\npageview retention catch-up: {$passed} passed, {$failed} failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
