<?php
/**
 * Regression tests for the backend performance migration.
 *
 * Runs the production Activator::demote_bulky_autoloaded_options() method and
 * verifies that a partial WordPress 6.4+ bulk update is treated as a failed
 * migration instead of silently advancing the consolidated version flag.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['faz_autoload_names']   = array();
$GLOBALS['faz_autoload_results'] = array();
$GLOBALS['faz_autoload_calls']   = 0;

function wp_set_option_autoload_values( array $options ) {
	++$GLOBALS['faz_autoload_calls'];
	$GLOBALS['faz_autoload_request'] = $options;
	return $GLOBALS['faz_autoload_results'];
}

class Faz_Performance_Wpdb {
	public $options = 'wp_options';

	public function esc_like( $value ) {
		return $value;
	}

	public function prepare( $query, $values ) {
		return $query;
	}

	public function get_col( $query ) {
		return $GLOBALS['faz_autoload_names'];
	}
}

$GLOBALS['wpdb'] = new Faz_Performance_Wpdb();

require_once dirname( __DIR__, 2 ) . '/includes/class-activator.php';

use FazCookie\Includes\Activator;

$run = 0;
$fail = 0;
function perf_ok( $condition, $label ) {
	global $run, $fail;
	++$run;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$fail;
	echo "FAIL: {$label}\n";
}

$GLOBALS['faz_autoload_names'] = array( 'faz_banner_template', 'faz_scan_history' );
$GLOBALS['faz_autoload_results'] = array(
	'faz_banner_template' => true,
	'faz_scan_history'    => true,
);
Activator::demote_bulky_autoloaded_options();
perf_ok(
	$GLOBALS['faz_autoload_request'] === array(
		'faz_banner_template' => false,
		'faz_scan_history'    => false,
	),
	'production migration submits every selected option with autoload=false'
);

$GLOBALS['faz_autoload_results']['faz_scan_history'] = false;
$threw = false;
try {
	Activator::demote_bulky_autoloaded_options();
} catch ( RuntimeException $e ) {
	$threw = true;
}
perf_ok( $threw, 'partial core bulk update throws so run_pending_migrations retries' );

$before                         = $GLOBALS['faz_autoload_calls'];
$GLOBALS['faz_autoload_names'] = array();
Activator::demote_bulky_autoloaded_options();
perf_ok( $before === $GLOBALS['faz_autoload_calls'], 'empty migration does not call the bulk update API' );

echo "Tests: {$run}, failed: {$fail}\n";
if ( $fail ) {
	exit( 1 );
}
echo "ALL PASS\n";
