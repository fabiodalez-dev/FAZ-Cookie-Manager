<?php
/**
 * L10: wp_faz_consent_logs carries the instant of each record in UTC.
 *
 * created_at is the site's wall clock (current_time('mysql')), which is
 * ambiguous once the value leaves the site and wrong by an hour around a DST
 * change. 1.34.2 adds created_at_gmt, written on every insert, and an upgrade
 * migration that adds the column and backfills existing rows in batches.
 *
 * The backfill converts created_at with the site's CURRENT timezone: it is
 * the only information available for an old row. A site that changed its
 * timezone setting since a row was written gets that row converted with the
 * new one; the column is new, so no recorded UTC value is overwritten.
 *
 * Pinned here, against the shipped Activator and the shipped controller with
 * a $wpdb double that models the table:
 *   - the migration adds the column to a table without it, with nothing else
 *     in the ALTER, and backfills every row in batches by log_id;
 *   - it touches no other column and no row that already has a value;
 *   - running it again changes nothing;
 *   - an insert writes created_at_gmt once the column is known to exist;
 *   - CSV/REST read the column, and fall back to the computed value only for
 *     rows without one.
 *
 * Run: php tests/unit/test-consent-log-gmt-column-php.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['opts'] = array();
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
	function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
	function esc_sql( $s ) { return $s; }
	function wp_next_scheduled( $h ) { return $GLOBALS['scheduled'][ $h ] ?? false; }
	function wp_schedule_single_event( $t, $h ) { $GLOBALS['scheduled'][ $h ] = $t; return true; }
	function wp_clear_scheduled_hook( $h ) { unset( $GLOBALS['scheduled'][ $h ] ); return 1; }
	// Site timezone Europe/Rome: +01:00 in winter, +02:00 in summer.
	function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) {
		$d = date_create( $date, new DateTimeZone( 'Europe/Rome' ) );
		if ( ! $d ) { return false; }
		$d->setTimezone( new DateTimeZone( 'UTC' ) );
		return $d->format( $format );
	}
	function current_time( $type, $gmt = 0 ) { return $gmt ? '2026-01-15 09:00:00' : '2026-01-15 10:00:00'; }

	class Gmt_Test_DB {
		public $prefix = 'wp_';
		public $rows = array();
		public $columns = array( 'log_id', 'consent_id', 'status', 'categories', 'ip_hash', 'user_agent', 'url', 'banner_slug', 'policy_revision', 'created_at' );
		public $alters = array();
		public $updates = array();
		public $last_error = '';
		public function prepare( $sql, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
			foreach ( $args as $a ) { $sql = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $sql, 1 ); }
			return $sql;
		}
		public function get_var( $sql ) {
			if ( 0 === stripos( $sql, 'SHOW TABLES LIKE' ) ) { return 'wp_faz_consent_logs'; }
			if ( 0 === stripos( $sql, 'SHOW COLUMNS' ) ) { return in_array( 'created_at_gmt', $this->columns, true ) ? 'created_at_gmt' : null; }
			return null;
		}
		public function query( $sql ) {
			$this->alters[] = $sql;
			if ( preg_match( '/ADD COLUMN `?created_at_gmt`?/i', $sql ) ) { $this->columns[] = 'created_at_gmt'; foreach ( $this->rows as &$r ) { $r['created_at_gmt'] = null; } return 1; }
			return false;
		}
		public function get_results( $sql, $out ) {
			if ( ! preg_match( '/log_id > (\d+).*LIMIT (\d+)/s', $sql, $m ) ) { return array(); }
			$res = array();
			foreach ( $this->rows as $id => $r ) {
				if ( $id > (int) $m[1] && null === ( $r['created_at_gmt'] ?? null ) ) { $res[] = array( 'log_id' => (string) $id, 'created_at' => $r['created_at'] ); }
				if ( count( $res ) >= (int) $m[2] ) { break; }
			}
			return $res;
		}
		public function update( $table, $data, $where, $f = null, $wf = null ) {
			$this->updates[] = array( $data, $where );
			$id = (int) $where['log_id'];
			if ( ! isset( $this->rows[ $id ] ) || null !== $this->rows[ $id ]['created_at_gmt'] ) { return 0; }
			foreach ( $data as $k => $v ) { $this->rows[ $id ][ $k ] = $v; }
			return 1;
		}
	}
	$wpdb = new Gmt_Test_DB();
	for ( $i = 1; $i <= 1203; $i++ ) {
		$wpdb->rows[ $i ] = array( 'consent_id' => 'c' . $i, 'status' => 'accepted', 'created_at' => ( $i % 2 ? '2026-01-15 10:00:00' : '2026-07-15 10:00:00' ) );
	}
	$wpdb->rows[1203]['created_at'] = '0000-00-00 00:00:00';

	require dirname( __DIR__, 2 ) . '/includes/class-activator.php';
	$A = '\\FazCookie\\Includes\\Activator';

	$checks = 0; $failed = 0;
	function gmt_check( $ok, $label ) { global $checks, $failed; ++$checks; if ( $ok ) { echo "  PASS {$label}\n"; return; } ++$failed; echo "  FAIL {$label}\n"; }

	gmt_check( method_exists( $A, 'ensure_consent_log_gmt_column' ), 'the upgrade path has a created_at_gmt migration' );
	if ( method_exists( $A, 'ensure_consent_log_gmt_column' ) ) {
		$before = $wpdb->rows;
		$rounds = 0;
		do { $done = $A::ensure_consent_log_gmt_column(); $rounds++; } while ( ! $done && $rounds < 20 );
		gmt_check( $done, 'the migration completes' );
		gmt_check( $rounds > 1, 'in more than one batch for a table of 1203 rows (' . $rounds . ' rounds)' );
		gmt_check( 1 === count( $wpdb->alters ) && 1 === preg_match( '/^ALTER TABLE `wp_faz_consent_logs` ADD COLUMN `created_at_gmt` datetime NULL DEFAULT NULL AFTER `created_at`$/', $wpdb->alters[0] ), 'one ALTER, adding only the new nullable column' );
		gmt_check( '2026-01-15 09:00:00' === $wpdb->rows[1]['created_at_gmt'], 'a winter row is converted with the +01:00 offset' );
		gmt_check( '2026-07-15 08:00:00' === $wpdb->rows[2]['created_at_gmt'], 'a summer row with the +02:00 offset' );
		gmt_check( null === $wpdb->rows[1203]['created_at_gmt'], 'an unparseable created_at is left without a value' );
		$untouched = true; $all = true;
		foreach ( $wpdb->rows as $id => $row ) {
			foreach ( $before[ $id ] as $k => $v ) { if ( $row[ $k ] !== $v ) { $untouched = false; } }
			if ( 1203 !== $id && empty( $row['created_at_gmt'] ) ) { $all = false; }
		}
		gmt_check( $untouched, 'no other column of any row changed' );
		gmt_check( $all, 'every parseable row was backfilled' );
		$only_gmt = true;
		foreach ( $wpdb->updates as $u ) { if ( array( 'created_at_gmt' ) !== array_keys( $u[0] ) ) { $only_gmt = false; } }
		gmt_check( $only_gmt, 'every UPDATE writes created_at_gmt alone' );
		gmt_check( empty( $GLOBALS['scheduled']['faz_consent_log_gmt_backfill'] ), 'no continuation is left scheduled once done' );
		$snapshot = $wpdb->rows; $alters = count( $wpdb->alters ); $updates = count( $wpdb->updates );
		gmt_check( true === $A::ensure_consent_log_gmt_column(), 'running it again reports complete' );
		gmt_check( $snapshot === $wpdb->rows && $alters === count( $wpdb->alters ) && $updates === count( $wpdb->updates ), 'and changes nothing' );
		unset( $GLOBALS['opts']['faz_consent_log_gmt_done'] );
		$A::ensure_consent_log_gmt_column();
		gmt_check( $alters === count( $wpdb->alters ) && $snapshot === $wpdb->rows, 'even with its completion marker lost: no second ALTER, no row changed' );
		// A row recorded with a value is never re-converted.
		$wpdb2 = new Gmt_Test_DB();
		$wpdb2->columns[] = 'created_at_gmt';
		$wpdb2->rows[1] = array( 'created_at' => '2026-01-15 10:00:00', 'created_at_gmt' => '2026-01-15 09:30:00' );
		$wpdb2->rows[2] = array( 'created_at' => '2026-01-15 10:00:00', 'created_at_gmt' => null );
		$GLOBALS['wpdb'] = $wpdb2; $GLOBALS['opts'] = array();
		// Bounded like the run above: a cursor that stopped advancing must fail
		// the suite, not hang it until CI kills the job.
		$rounds2 = 0;
		do { $done2 = $A::ensure_consent_log_gmt_column(); $rounds2++; } while ( ! $done2 && $rounds2 < 20 );
		gmt_check( $done2, 'the migration completes on a table that already has the column' );
		gmt_check( 0 === count( $wpdb2->alters ), 'an install whose table already has the column gets no ALTER' );
		gmt_check( '2026-01-15 09:30:00' === $wpdb2->rows[1]['created_at_gmt'] && '2026-01-15 09:00:00' === $wpdb2->rows[2]['created_at_gmt'], 'a recorded value is kept, a missing one is filled' );
		gmt_check( ! empty( $GLOBALS['opts']['faz_consent_log_gmt_column_added'] ), 'and inserts are told the column exists' );
	}

	// Readers: the column first, the computed value only for rows without one.
	if ( ! function_exists( 'did_action' ) ) { function did_action( $h ) { return 1; } }
	if ( ! function_exists( 'add_action' ) ) { function add_action( ...$a ) { return true; } }
	require dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/includes/class-controller.php';
	$C = '\\FazCookie\\Admin\\Modules\\Consentlogs\\Includes\\Controller';
	gmt_check( method_exists( $C, 'row_created_at_gmt' ), 'the readers have one helper for the UTC instant' );
	if ( method_exists( $C, 'row_created_at_gmt' ) ) {
		gmt_check( '2026-01-15T09:30:00Z' === $C::row_created_at_gmt( array( 'created_at' => '2026-01-15 10:00:00', 'created_at_gmt' => '2026-01-15 09:30:00' ) ), 'a stored value is read as is' );
		gmt_check( '2026-07-15T08:00:00Z' === $C::row_created_at_gmt( array( 'created_at' => '2026-07-15 10:00:00', 'created_at_gmt' => null ) ), 'a row without one falls back to the computed value' );
	}
	$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/includes/class-controller.php' );
	gmt_check( false !== strpos( $src, "created_at_gmt datetime DEFAULT NULL" ), 'fresh installs create the column with the table' );
	gmt_check( 1 === preg_match( "/'created_at_gmt'\\]?\\s*=>?\\s*current_time\\(\\s*'mysql',\\s*true\\s*\\)/", $src ), 'log_consent() writes current_time( mysql, true )' );
	gmt_check( false !== strpos( $src, 'self::row_created_at_gmt( $item )' ), 'the CSV export reads the column' );
	$api = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/api/class-api.php' );
	gmt_check( false !== strpos( $api, 'Controller::row_created_at_gmt( $item )' ), 'the REST API reads the column' );
	$un = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
	gmt_check( false !== strpos( $un, "'faz_consent_log_gmt_column_added'" ) && false !== strpos( $un, "faz_consent_log_gmt_backfill" ), 'uninstall removes the migration state and its event' );

	echo "consent-log-gmt-column: " . ( $checks - $failed ) . " passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
