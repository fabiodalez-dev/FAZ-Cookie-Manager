<?php
/**
 * `wp faz export` writes to a guarded, non-guessable place (2026-11 audit).
 *
 *  - the default file name carries a random part, not only the date;
 *  - the exports directory gets an index.php and an Apache deny rule, and
 *    files already there are not overwritten;
 *  - a symlink at the target itself is refused (realpath() checks only the
 *    parent directory).
 *
 * Run: php tests/unit/test-cli-export-target-php.php
 *
 * @package FazCookie\Tests\Unit
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', true );
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }

require_once dirname( __DIR__, 2 ) . '/includes/class-wp-cli-commands.php';
use FazCookie\Includes\WP_CLI_Commands;

$passed = 0;
$failed = 0;
function ce_check( $cond, $label ) {
	global $passed, $failed;
	if ( $cond ) { ++$passed; echo "  PASS: $label\n"; }
	else { ++$failed; echo "  FAIL: $label\n"; }
}
function ce_call( $method, array $args = array() ) {
	if ( ! method_exists( WP_CLI_Commands::class, $method ) ) {
		return null;
	}
	$m = new ReflectionMethod( WP_CLI_Commands::class, $method );
	$m->setAccessible( true );
	return $m->invokeArgs( null, $args );
}

echo "\n== wp faz export: target hardening ==\n";

$a = (string) ce_call( 'default_export_name' );
$b = (string) ce_call( 'default_export_name' );
ce_check( 1 === preg_match( '/^faz-settings-\d{4}-\d{2}-\d{2}-[0-9a-f]{12}\.json$/', $a ), 'default name is faz-settings-<date>-<12 hex>.json (got "' . $a . '")' );
ce_check( '' !== $a && $a !== $b, 'two default names differ' );

$dir = sys_get_temp_dir() . '/faz-cli-export-' . getmypid();
@mkdir( $dir, 0777, true );
ce_call( 'guard_exports_dir', array( $dir ) );
ce_check( is_file( $dir . '/index.php' ) && false !== strpos( (string) file_get_contents( $dir . '/index.php' ), 'Silence is golden' ), 'the exports directory gets an index.php' );
$ht = is_file( $dir . '/.htaccess' ) ? (string) file_get_contents( $dir . '/.htaccess' ) : '';
ce_check( false !== strpos( $ht, 'Require all denied' ) && false !== strpos( $ht, 'Deny from all' ), 'the exports directory gets an Apache 2.2/2.4 deny rule' );
file_put_contents( $dir . '/.htaccess', 'custom' );
ce_call( 'guard_exports_dir', array( $dir ) );
ce_check( 'custom' === file_get_contents( $dir . '/.htaccess' ), 'an existing .htaccess is not overwritten' );

$outside = $dir . '-outside.txt';
file_put_contents( $outside, 'do not touch' );
@symlink( $outside, $dir . '/link.json' );
ce_check( false === ce_call( 'is_writable_export_target', array( $dir . '/link.json' ) ), 'a symlink at the target is refused' );
@symlink( $dir . '/missing-target', $dir . '/dangling.json' );
ce_check( false === ce_call( 'is_writable_export_target', array( $dir . '/dangling.json' ) ), 'a dangling symlink at the target is refused' );
ce_check( false === ce_call( 'is_writable_export_target', array( $dir ) ), 'a directory as the target is refused' );
ce_check( true === ce_call( 'is_writable_export_target', array( $dir . '/plain.json' ) ), 'a plain new file is accepted' );

$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-wp-cli-commands.php' );
$pos_check = strpos( $src, 'self::is_writable_export_target( $file )' );
$pos_write = strpos( $src, 'file_put_contents( $file, $json )' );
ce_check( false !== $pos_check && false !== $pos_write && $pos_check < $pos_write, 'export_settings() checks the target before writing' );

foreach ( array( '/link.json', '/dangling.json', '/index.php', '/.htaccess' ) as $f ) { @unlink( $dir . $f ); }
@rmdir( $dir );
@unlink( $outside );

echo "\n$passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
