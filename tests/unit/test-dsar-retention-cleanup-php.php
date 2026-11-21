<?php
/**
 * Activator::cleanup_old_dsar_requests() purges EVERY expired DSAR record.
 *
 * DSAR posts hold the requester's name, email and message in the clear. The
 * daily cleanup used to delete one batch of 200, newest first, with
 * post_status 'any': the form accepts up to 500 records a day, so a sustained
 * flood left the OLDEST expired records (the ones furthest past the
 * retention) in place, and 'any' never matched the trash, so a record an
 * administrator binned was never purged.
 *
 * Pinned here against the shipped Activator with a get_posts() double that
 * models WP_Query's status, order, date and limit semantics:
 *   - one run removes more than 200 expired records;
 *   - trashed expired records are removed too;
 *   - records inside the retention are kept, whatever their status;
 *   - a record wp_delete_post() refuses does not loop the run forever.
 *
 * Run: php tests/unit/test-dsar-retention-cleanup-php.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
		define( 'MONTH_IN_SECONDS', 30 * 86400 );
	}
	$GLOBALS['posts']     = array();
	$GLOBALS['undeletable'] = array();
	$GLOBALS['queries']   = 0;
	function get_option( $k, $d = false ) { return $d; }
	function apply_filters( $tag, $value ) { return $value; }
	function post_type_exists( $t ) { return 'faz_dsar' === $t; }
	function wp_delete_post( $id, $force = false ) {
		if ( isset( $GLOBALS['undeletable'][ $id ] ) ) {
			return false;
		}
		unset( $GLOBALS['posts'][ $id ] );
		return true;
	}
	// WP_Query semantics that matter here: 'any' (string or inside an array)
	// matches every status except the exclude_from_search ones (trash,
	// auto-draft), unless the array names them; default order is date DESC.
	function get_posts( $args ) {
		$GLOBALS['queries']++;
		$status = $args['post_status'] ?? 'publish';
		$status = is_array( $status ) ? $status : array( $status );
		$match  = function ( $s ) use ( $status ) {
			if ( in_array( $s, $status, true ) ) { return true; }
			return in_array( 'any', $status, true ) && ! in_array( $s, array( 'trash', 'auto-draft' ), true );
		};
		$before = $args['date_query'][0]['before'];
		$hits   = array();
		foreach ( $GLOBALS['posts'] as $id => $p ) {
			if ( $p['date_gmt'] < $before && $match( $p['status'] ) ) {
				$hits[ $id ] = $p['date_gmt'];
			}
		}
		if ( 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ) { asort( $hits ); } else { arsort( $hits ); }
		return array_slice( array_keys( $hits ), 0, (int) $args['posts_per_page'] );
	}

	require dirname( __DIR__, 2 ) . '/includes/class-activator.php';
	$A = '\\FazCookie\\Includes\\Activator';

	$checks = 0; $failed = 0;
	function dr_check( $ok, $label ) { global $checks, $failed; ++$checks; if ( $ok ) { echo "  PASS {$label}\n"; return; } ++$failed; echo "  FAIL {$label}\n"; }

	$expired_ts = time() - 30 * MONTH_IN_SECONDS;
	$id = 1;
	// 1200 expired records (three days of a flooded form), 50 of them trashed.
	for ( $i = 0; $i < 1200; $i++, $id++ ) {
		$GLOBALS['posts'][ $id ] = array(
			'date_gmt' => gmdate( 'Y-m-d H:i:s', $expired_ts - $i * 60 ),
			'status'   => $i < 50 ? 'trash' : 'private',
		);
	}
	// 30 recent records, inside the 24-month retention, some trashed.
	for ( $i = 0; $i < 30; $i++, $id++ ) {
		$GLOBALS['posts'][ $id ] = array(
			'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $i * 86400 ),
			'status'   => $i % 3 ? 'private' : 'trash',
		);
	}
	$oldest = 1200; // highest index above = oldest date

	$n = $A::cleanup_old_dsar_requests( array( 'dsar' => array( 'retention' => 24 ) ) );
	$left_expired = 0; $left_trash = 0;
	foreach ( $GLOBALS['posts'] as $pid => $p ) {
		if ( $pid <= 1200 ) { $left_expired++; if ( 'trash' === $p['status'] ) { $left_trash++; } }
	}
	dr_check( 1200 === $n, 'one run deletes every expired record (' . $n . ' of 1200)' );
	dr_check( 0 === $left_expired, 'no expired record is left behind (' . $left_expired . ' left)' );
	dr_check( ! isset( $GLOBALS['posts'][ $oldest ] ), 'the oldest expired record is gone' );
	dr_check( 0 === $left_trash, 'trashed expired records are purged too (' . $left_trash . ' left)' );
	dr_check( 30 === count( $GLOBALS['posts'] ), 'the 30 records inside the retention are kept, trashed ones included' );

	// A record that cannot be deleted must not spin the run.
	$GLOBALS['posts'] = array(); $GLOBALS['queries'] = 0;
	for ( $i = 0; $i < 200; $i++ ) {
		$GLOBALS['posts'][ $i + 1 ] = array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', $expired_ts - $i ), 'status' => 'private' );
		$GLOBALS['undeletable'][ $i + 1 ] = true;
	}
	$n2 = $A::cleanup_old_dsar_requests( array( 'dsar' => array( 'retention' => 24 ) ) );
	dr_check( 0 === $n2 && $GLOBALS['queries'] <= 2, 'a batch nothing could be deleted from ends the run (' . $GLOBALS['queries'] . ' queries)' );

	// Retention 0 still disables the purge.
	$GLOBALS['queries'] = 0;
	dr_check( 0 === $A::cleanup_old_dsar_requests( array( 'dsar' => array( 'retention' => 0 ) ) ) && 0 === $GLOBALS['queries'], 'retention 0 runs no query' );

	echo "\n{$checks} checks, {$failed} failed\n";
	echo 0 === $failed ? "ALL PASS\n" : "FAILURES\n";
	exit( 0 === $failed ? 0 : 1 );
}
