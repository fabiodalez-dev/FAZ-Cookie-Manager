<?php
/**
 * Standalone regression tests for Notice::dismiss() expiry coercion.
 *
 * The dismiss endpoints hand request input to dismiss(), which adds the expiry
 * to time(). Before the coercion, a non-numeric string was a TypeError (a 500
 * on the REST route) and the string '0' slipped past the strict `0 !== $expiry`
 * check, storing an already-expired timestamp instead of a permanent dismissal
 * — the notice came straight back on the next page load.
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

// In-memory option store: only get_option/update_option are needed.
$GLOBALS['faz_test_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['faz_test_options'] ) ? $GLOBALS['faz_test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['faz_test_options'][ $key ] = $value;
	return true;
}

require_once __DIR__ . '/../../includes/class-notice.php';

use FazCookie\Includes\Notice;

$run    = 0;
$failed = 0;
function notice_assert( $condition, $label ) {
	global $run, $failed;
	$run++;
	if ( $condition ) {
		echo "  \033[32m✓\033[0m {$label}\n";
		return;
	}
	$failed++;
	echo "  \033[31m✗\033[0m {$label}\n";
}

echo "\n== Notice dismissal expiry coercion ==\n\n";

$notice = new Notice();

// Integer zero: permanent dismissal, stored as false.
$notice->dismiss( 'a', 0 );
$stored = get_option( 'faz_admin_notices', array() );
notice_assert( false === $stored['a'], 'integer 0 stores a permanent dismissal' );

// String '0' — what a form-encoded request delivers — must behave like 0, not
// like "expire immediately".
$notice->dismiss( 'b', '0' );
$stored = get_option( 'faz_admin_notices', array() );
notice_assert( false === $stored['b'], "string '0' stores a permanent dismissal" );

// A positive expiry stores a future timestamp.
$notice->dismiss( 'c', 3600 );
$stored = get_option( 'faz_admin_notices', array() );
notice_assert( is_int( $stored['c'] ) && $stored['c'] > time(), 'positive expiry stores a future timestamp' );

// Numeric string expiry is honoured as its integer value.
$notice->dismiss( 'd', '7200' );
$stored = get_option( 'faz_admin_notices', array() );
notice_assert( is_int( $stored['d'] ) && $stored['d'] > time() + 3600, 'numeric string expiry is coerced to an integer' );

// Non-numeric string: previously `time() + 'abc'` — a fatal TypeError on
// PHP 8. Must degrade to a permanent dismissal, never an error.
$notice->dismiss( 'e', 'abc' );
$stored = get_option( 'faz_admin_notices', array() );
notice_assert( false === $stored['e'], 'non-numeric string degrades to a permanent dismissal instead of a TypeError' );

// Non-scalar input (a malformed JSON body) must not fatal either.
$notice->dismiss( 'f', array( 'x' ) );
$stored = get_option( 'faz_admin_notices', array() );
notice_assert( false === $stored['f'], 'array expiry degrades to a permanent dismissal instead of a TypeError' );

echo "\n--\nChecks: {$run}\nFailed: {$failed}\n\n";
if ( $failed > 0 ) {
	echo "\033[31mFAIL\033[0m\n";
	exit( 1 );
}
echo "\033[32mPASS\033[0m\n";
exit( 0 );
