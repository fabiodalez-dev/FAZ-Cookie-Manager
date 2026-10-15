<?php
/**
 * Uninstall must also remove the plugin's user-meta rows.
 *
 * uninstall.php sweeps custom tables, transients, DSAR posts, every faz_*
 * option and the upload directories, but wp_usermeta is a different table
 * and the faz_% LIKE on wp_options can never reach it. The plugin writes four
 * user-meta keys: three admin-notice dismissal flags (admin/class-admin.php)
 * and the browser-assisted scan observations
 * (Scanner Controller::BROWSER_SCAN_META). The dismissal flags survive every
 * opted-in uninstall; the observations survive when a scan was abandoned and
 * that administrator never started another one. readme.txt promises that the
 * opt-in wipes everything, so none of them may remain.
 *
 * User meta is network-global on multisite, so the delete must run exactly
 * once per network, and only when at least one source of intent (a per-site
 * opt-in or FAZ_REMOVE_ALL_DATA) asked for data removal.
 *
 * uninstall.php declares functions and reads constants, so each scenario runs
 * in a child PHP process that loads it once with stubbed WordPress functions
 * and prints the recorded delete_metadata() calls as JSON.
 *
 * Run: php tests/unit/test-uninstall-user-meta-php.php
 *
 * @package FazCookie\Tests\Unit
 */

$faz_scenario = null;
foreach ( $argv as $faz_arg ) {
	if ( 0 === strpos( $faz_arg, '--scenario=' ) ) {
		$faz_scenario = substr( $faz_arg, strlen( '--scenario=' ) );
	}
}

if ( null !== $faz_scenario ) {
	faz_uninstall_scenario( $faz_scenario );
	exit( 0 );
}

/**
 * Child process: load uninstall.php under one configuration and report.
 *
 * @param string $scenario Scenario id.
 * @return void
 */
function faz_uninstall_scenario( $scenario ) {
	$multisite    = 0 === strpos( $scenario, 'multisite' );
	$blog_opt_in  = array();
	$force_remove = false;

	switch ( $scenario ) {
		case 'single-opted-in':
			$blog_opt_in = array( 1 => true );
			break;
		case 'single-not-opted-in':
			$blog_opt_in = array( 1 => false );
			break;
		case 'single-constant':
			$blog_opt_in  = array( 1 => false );
			$force_remove = true;
			break;
		case 'multisite-one-of-three':
			$blog_opt_in = array( 1 => false, 2 => true, 3 => false );
			break;
		case 'multisite-all-three':
			$blog_opt_in = array( 1 => true, 2 => true, 3 => true );
			break;
		case 'multisite-none':
			$blog_opt_in = array( 1 => false, 2 => false, 3 => false );
			break;
		case 'multisite-constant-no-sites':
			$blog_opt_in  = array();
			$force_remove = true;
			break;
		default:
			fwrite( STDERR, "unknown scenario {$scenario}\n" );
			exit( 2 );
	}

	$GLOBALS['faz_t_multisite']   = $multisite;
	$GLOBALS['faz_t_blog_opt_in'] = $blog_opt_in;
	$GLOBALS['faz_t_current_blog'] = 1;
	$GLOBALS['faz_t_meta_deletes'] = array();
	$GLOBALS['faz_t_option_deletes'] = 0;

	define( 'WP_UNINSTALL_PLUGIN', true );
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	if ( $force_remove ) {
		define( 'FAZ_REMOVE_ALL_DATA', true );
	}

	require_once __DIR__ . '/helpers/uninstall-wp-stubs.php';

	require dirname( __DIR__, 2 ) . '/uninstall.php';

	echo "\nFAZ_UNINSTALL_RESULT:" . json_encode(
		array(
			'meta_deletes'   => $GLOBALS['faz_t_meta_deletes'],
			'option_deletes' => $GLOBALS['faz_t_option_deletes'],
		)
	);
}

// ---------------------------------------------------------------------
// Parent process: run every scenario and assert on the recorded calls.
// ---------------------------------------------------------------------

$passed = 0;
$failed = 0;

function uninstall_meta_check( $condition, $label, $detail = '' ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  [PASS] {$label}\n";
	} else {
		$failed++;
		echo "  [FAIL] {$label}\n";
		if ( '' !== $detail ) {
			echo "         {$detail}\n";
		}
	}
}

/**
 * Run one scenario in a child PHP process.
 *
 * @param string $scenario Scenario id.
 * @return array{meta_deletes:array,option_deletes:int}|null
 */
function uninstall_meta_run( $scenario ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --scenario=' . escapeshellarg( $scenario ) . ' 2>&1';
	$out = (string) shell_exec( $cmd );
	$marker = 'FAZ_UNINSTALL_RESULT:';
	$pos    = strrpos( $out, $marker );
	if ( false === $pos ) {
		echo "         child output: " . trim( $out ) . "\n";
		return null;
	}
	$json = json_decode( substr( $out, $pos + strlen( $marker ) ), true );
	if ( ! is_array( $json ) ) {
		echo "         child output: " . trim( $out ) . "\n";
		return null;
	}
	return $json;
}

/**
 * Count how many recorded delete_metadata() calls target a key with the
 * "every user, every value" shape.
 *
 * @param array  $deletes Recorded calls.
 * @param string $key     Meta key.
 * @return int
 */
function uninstall_meta_count( array $deletes, $key ) {
	$n = 0;
	foreach ( $deletes as $call ) {
		if ( 'user' === $call['type'] && $key === $call['key'] && 0 === (int) $call['object_id'] && '' === $call['value'] && true === $call['delete_all'] ) {
			$n++;
		}
	}
	return $n;
}

$expected_keys = array(
	'_faz_scan_cookie_observation',
	'faz_wc_notice_dismissed',
	'faz_smash_balloon_notice_dismissed',
	'faz_payment_gateway_notice_dismissed',
);

echo "== Uninstall removes the plugin's user meta ==\n";

foreach ( array( 'single-opted-in', 'single-constant', 'multisite-one-of-three', 'multisite-all-three', 'multisite-constant-no-sites' ) as $scenario ) {
	$result = uninstall_meta_run( $scenario );
	uninstall_meta_check( null !== $result, "{$scenario}: uninstall.php ran to completion" );
	if ( null === $result ) {
		continue;
	}
	foreach ( $expected_keys as $key ) {
		$count = uninstall_meta_count( $result['meta_deletes'], $key );
		uninstall_meta_check(
			1 === $count,
			"{$scenario}: delete_metadata('user', 0, '{$key}', '', true) runs exactly once",
			'calls recorded: ' . $count . ' / all calls: ' . json_encode( $result['meta_deletes'] )
		);
	}
	// With FAZ_REMOVE_ALL_DATA and no sites to iterate the per-blog loop has
	// nothing to sweep; everywhere else the option sweep must still run.
	if ( 'multisite-constant-no-sites' !== $scenario ) {
		uninstall_meta_check( $result['option_deletes'] > 0, "{$scenario}: the option sweep still runs (user-meta cleanup did not replace it)" );
	}
}

echo "== Uninstall leaves user meta alone when nobody opted in ==\n";

foreach ( array( 'single-not-opted-in', 'multisite-none' ) as $scenario ) {
	$result = uninstall_meta_run( $scenario );
	uninstall_meta_check( null !== $result, "{$scenario}: uninstall.php ran to completion" );
	if ( null === $result ) {
		continue;
	}
	uninstall_meta_check(
		array() === $result['meta_deletes'],
		"{$scenario}: no delete_metadata() call",
		'calls: ' . json_encode( $result['meta_deletes'] )
	);
	uninstall_meta_check( 0 === (int) $result['option_deletes'], "{$scenario}: no option deleted either" );
}

echo "Passed: {$passed}; Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
