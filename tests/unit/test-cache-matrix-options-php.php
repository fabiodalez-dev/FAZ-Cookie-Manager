<?php
/** Regression: the cache matrix restores option values, absence and autoload. */
define( 'WP_CLI', true );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_CLI {
	public static function error( $message ) { throw new RuntimeException( $message ); }
}
function wp_cache_flush() { $GLOBALS['flushes']++; }

/** Narrow database seam: execute the helper's selection and transaction logic. */
class Matrix_Test_DB {
	public $options = 'test_options';
	public $last_error = '';
	public $rows = array();
	public $fail_insert = false;
	public $fail_on_insert = 0;
	public $insert_calls = 0;
	private $pattern;
	private $transaction;
	public function prepare( $sql, $active, $faz, $pattern ) {
		$this->pattern = $pattern;
		return 'matrix_option_selection';
	}
	private function selected( $row ) {
		return in_array( $row['option_name'], array( 'active_plugins', 'faz_settings' ), true )
			|| 1 === preg_match( '/' . $this->pattern . '/', $row['option_name'] );
	}
	public function get_results( $sql, $format ) {
		return array_values( array_filter( $this->rows, array( $this, 'selected' ) ) );
	}
	public function query( $sql ) {
		if ( 'START TRANSACTION' === $sql ) { $this->transaction = $this->rows; }
		if ( 'ROLLBACK' === $sql ) { $this->rows = $this->transaction; }
		if ( 0 === strpos( $sql, 'DELETE FROM' ) ) {
			$this->rows = array_values( array_filter( $this->rows, function ( $row ) { return ! $this->selected( $row ); } ) );
		}
		return 1;
	}
	public function insert( $table, $row ) {
		$this->insert_calls++;
		if ( $this->fail_on_insert === $this->insert_calls ) { return false; }
		if ( $this->fail_insert ) { return false; }
		$this->rows[] = $row;
		return 1;
	}
}

function matrix_run( $mode, $file ) {
	$args = array( $mode, $file );
	require dirname( __DIR__, 2 ) . '/scripts/cache-plugin-matrix-options.php';
}
function matrix_row( $name, $value, $autoload = 'yes' ) {
	return array( 'option_name' => $name, 'option_value' => $value, 'autoload' => $autoload );
}
$passed = 0;
function matrix_check( $condition, $label ) {
	global $passed;
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	$passed++;
	echo "PASS $label\n";
}

$wpdb = new Matrix_Test_DB();
$GLOBALS['flushes'] = 0;
$file = tempnam( sys_get_temp_dir(), 'faz-matrix-options-' );
try {
	$original = array(
		matrix_row( 'active_plugins', serialize( array( 'w3-total-cache/w3-total-cache.php' ) ) ),
		matrix_row( 'faz_settings', serialize( array( 'locale' => 'città' ) ), 'auto-on' ),
		matrix_row( 'w3tc_config', 'original W3TC value', 'no' ),
		matrix_row( 'litespeed.conf', serialize( array( 'css' => false ) ), 'auto-off' ),
		matrix_row( 'autoptimize_js', 'off' ),
	);
	$wpdb->rows = array_merge( $original, array( matrix_row( 'unrelated_option', 'before' ) ) );
	matrix_run( 'snapshot', $file );
	matrix_check( unserialize( file_get_contents( $file ) ) === $original, 'only matrix-owned options are captured, byte-for-byte' );
	$wpdb->rows = array(
		matrix_row( 'active_plugins', 'temporary plugin set' ),
		matrix_row( 'w3tc_config', 'temporary config' ),
		matrix_row( 'jetpack_boost_status_page-cache', 'new option' ),
		matrix_row( 'unrelated_option', 'changed independently' ),
		matrix_row( 'litespeedXunrelated', 'not a LiteSpeed option' ),
	);
	matrix_run( 'restore', $file );
	$expected = array_merge( array(
		matrix_row( 'unrelated_option', 'changed independently' ),
		matrix_row( 'litespeedXunrelated', 'not a LiteSpeed option' ),
	), $original );
	matrix_check( $wpdb->rows === $expected, 'values/autoload restored, new cache options removed, unrelated writes kept' );
	matrix_check( 1 === $GLOBALS['flushes'], 'cached WordPress options invalidated' );

	$wpdb->fail_insert = true;
	try {
		matrix_run( 'restore', $file );
		matrix_check( false, 'insert failure must surface' );
	} catch ( RuntimeException $error ) {
		matrix_check( false !== strpos( $error->getMessage(), 'Could not restore' ), 'restore failures are reported' );
	}
	matrix_check( $wpdb->rows === $expected, 'failed restore rolls back the option deletion' );
	matrix_check( is_file( $file ), 'backup retained on restore failure' );
	$wpdb->fail_insert = false;

	$wpdb->insert_calls = 0;
	$wpdb->fail_on_insert = 2;
	try {
		matrix_run( 'restore', $file );
		matrix_check( false, 'second insert failure must surface' );
	} catch ( RuntimeException $error ) {
		matrix_check( false !== strpos( $error->getMessage(), 'Could not restore' ), 'partial restore failures are reported' );
	}
	matrix_check( 2 === $wpdb->insert_calls, 'one option was reinserted before the next insertion failed' );
	matrix_check( $wpdb->rows === $expected, 'partial restore rolls back every inserted option' );
	$wpdb->fail_on_insert = 0;

	file_put_contents( $file, serialize( array( array( 'option_name' => 'incomplete row' ) ) ) );
	try {
		matrix_run( 'restore', $file );
		matrix_check( false, 'invalid snapshot must fail' );
	} catch ( RuntimeException $error ) {
		matrix_check( false !== strpos( $error->getMessage(), 'Invalid cache plugin option row' ), 'malformed snapshot rejected before writes' );
	}
	matrix_check( $wpdb->rows === $expected, 'malformed snapshot leaves database intact' );
} finally {
	unlink( $file );
}
echo "ALL PASS ($passed)\n";
