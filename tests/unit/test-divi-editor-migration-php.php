<?php
/**
 * Unit test — the one-time migration that files the Divi Builder editor
 * cookies already stored under Uncategorized as WordPress Internal.
 *
 * The SQL runs for real against an in-memory SQLite database (harness from
 * test-sourcebuster-marketing-migration-php.php), so the LIKE escaping of
 * the prefixes is exercised.
 *
 * Run: php tests/unit/test-divi-editor-migration-php.php
 */

namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Cookie_Controller {
		public static $busts = 0;
		public static function get_instance() {
			return new self();
		}
		public function delete_cache() {
			self::$busts++;
		}
	}
}

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$GLOBALS['faz_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_test_options'] ) ? $GLOBALS['faz_test_options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		if ( in_array( $name, $GLOBALS['faz_test_refuse_options'] ?? array(), true ) ) {
			return false;
		}
		$GLOBALS['faz_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $value ) {
		return $value;
	}
}

/**
 * The slice of $wpdb the migration uses, backed by a real SQLite database.
 */
class Faz_Sqlite_Wpdb {
	public $prefix = 'wp_';
	public $fail   = false;
	/** Fail only the Nth UPDATE (1-based); 0 = never. */
	public $fail_on = 0;
	private $updates = 0;
	public $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}
	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}
	public function prepare( $query, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $value : $this->pdo->quote( (string) $value );
			},
			$query
		);
	}
	private function sqlite( $query ) {
		$query = str_replace( '`', '"', $query );
		return preg_replace( "/LIKE ('(?:[^']|'')*')/", "LIKE $1 ESCAPE '\\\\'", $query );
	}
	public function get_var( $query ) {
		if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $query, $m ) ) {
			$st = $this->pdo->query( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $this->pdo->quote( $m[1] ) );
		} else {
			$st = $this->pdo->query( $this->sqlite( $query ) );
		}
		$value = $st->fetchColumn();
		return false === $value ? null : $value;
	}
	public function query( $query ) {
		if ( 0 === stripos( ltrim( $query ), 'UPDATE' ) && ++$this->updates === $this->fail_on ) {
			return false;
		}
		return $this->fail ? false : $this->pdo->exec( $this->sqlite( $query ) );
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/frontend/class-frontend.php';
require_once $root . '/includes/class-activator.php';

use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;
use FazCookie\Includes\Activator;

$passed = 0;
$failed = 0;
function dv_eq( $actual, $expected, $label ) {
	global $passed, $failed;
	if ( $actual === $expected ) {
		$passed++;
		echo "  PASS {$label}\n";
	} else {
		$failed++;
		echo "  FAIL {$label}\n        expected: " . var_export( $expected, true ) . "\n        actual:   " . var_export( $actual, true ) . "\n";
	}
}

const UNCATEGORIZED = 9;
const INTERNAL      = 11;
const MARKETING     = 7;

function dv_site( array $rows, $with_internal = true ) {
	$GLOBALS['faz_test_options'] = array();
	Cookie_Controller::$busts    = 0;
	$db                          = new Faz_Sqlite_Wpdb();
	$db->pdo->exec( 'CREATE TABLE wp_faz_cookie_categories (category_id INTEGER PRIMARY KEY, slug TEXT)' );
	$db->pdo->exec( 'CREATE TABLE wp_faz_cookies (cookie_id INTEGER PRIMARY KEY, name TEXT, category INTEGER)' );
	$db->pdo->exec( 'INSERT INTO wp_faz_cookie_categories VALUES (' . UNCATEGORIZED . ", 'uncategorized'), (" . MARKETING . ", 'marketing')" );
	if ( $with_internal ) {
		$db->pdo->exec( 'INSERT INTO wp_faz_cookie_categories VALUES (' . INTERNAL . ", 'wordpress-internal')" );
	}
	foreach ( $rows as $r ) {
		$db->pdo->exec( 'INSERT INTO wp_faz_cookies (name, category) VALUES (' . $db->pdo->quote( $r[0] ) . ', ' . (int) $r[1] . ')' );
	}
	$GLOBALS['wpdb'] = $db;
	return $db;
}
function dv_cat( $db, $name ) {
	return (int) $db->pdo->query( 'SELECT category FROM wp_faz_cookies WHERE name = ' . $db->pdo->quote( $name ) )->fetchColumn();
}

echo "Divi editor cookies move to WordPress Internal\n";
$db = dv_site( array(
	array( 'et-saved-post-303900-bb', UNCATEGORIZED ),
	array( 'et-editing-post-12-fb', UNCATEGORIZED ),
	array( 'et-pb-recent-items-colors', UNCATEGORIZED ),
	array( 'et_pb_ab_view_page_12', UNCATEGORIZED ),   // split testing: visitor-facing
	array( 'etXsaved-post-1', UNCATEGORIZED ),          // `-` is literal, not a wildcard
	array( 'et-saved-post-7-bb', MARKETING ),           // someone's deliberate choice
) );
Activator::move_divi_editor_cookies_to_internal();
dv_eq( dv_cat( $db, 'et-saved-post-303900-bb' ), INTERNAL, 'et-saved-post-* moves from Uncategorized' );
dv_eq( dv_cat( $db, 'et-editing-post-12-fb' ), INTERNAL, 'et-editing-post-* moves' );
dv_eq( dv_cat( $db, 'et-pb-recent-items-colors' ), INTERNAL, 'et-pb-recent-items-* moves' );
dv_eq( dv_cat( $db, 'et_pb_ab_view_page_12' ), UNCATEGORIZED, 'a split-testing cookie stays where it is' );
dv_eq( dv_cat( $db, 'etXsaved-post-1' ), UNCATEGORIZED, 'a near-miss name is not matched' );
dv_eq( dv_cat( $db, 'et-saved-post-7-bb' ), MARKETING, 'a row filed in another category on purpose is left alone' );
dv_eq( Cookie_Controller::$busts, 1, 'the cookie cache is busted once' );
dv_eq( get_option( 'faz_move_divi_editor_internal_done' ), 1, 'and the marker is set' );

$db->pdo->exec( 'UPDATE wp_faz_cookies SET category = ' . UNCATEGORIZED . " WHERE name = 'et-saved-post-303900-bb'" );
Activator::move_divi_editor_cookies_to_internal();
dv_eq( dv_cat( $db, 'et-saved-post-303900-bb' ), UNCATEGORIZED, 'it runs once per site: a row moved back by the admin stays' );

$db = dv_site( array( array( 'et-saved-post-1-bb', UNCATEGORIZED ) ) );
$db->fail = true;
$threw    = false;
try {
	Activator::move_divi_editor_cookies_to_internal();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
dv_eq( $threw, true, 'a failed UPDATE throws so the batch retries' );
dv_eq( get_option( 'faz_move_divi_editor_internal_done', 0 ), 0, 'and leaves the marker unset' );

$db = dv_site( array( array( 'et-saved-post-1-bb', UNCATEGORIZED ) ), false );
dv_eq( Activator::move_divi_editor_cookies_to_internal(), false, 'the missing prerequisite defers completion' );
dv_eq( dv_cat( $db, 'et-saved-post-1-bb' ), UNCATEGORIZED, 'without an internal category nothing moves' );
dv_eq( get_option( 'faz_move_divi_editor_internal_done' ), false, 'a missing category does not set the marker' );
$db->pdo->exec( 'INSERT INTO wp_faz_cookie_categories VALUES (' . INTERNAL . ", 'wordpress-internal')" );
dv_eq( Activator::move_divi_editor_cookies_to_internal(), true, 'retry completes when the category exists' );
dv_eq( dv_cat( $db, 'et-saved-post-1-bb' ), INTERNAL, 'the previously skipped row is moved on retry' );

$db = dv_site( array() );
$db->pdo->exec( 'DROP TABLE wp_faz_cookies' );
dv_eq( Activator::move_divi_editor_cookies_to_internal(), false, 'a missing table also defers completion' );
dv_eq( get_option( 'faz_move_divi_editor_internal_done' ), false, 'the missing table does not set the marker' );

echo "\nPartial failure, then retry\n";
$db = dv_site( array(
	array( 'et-saved-post-1-bb', UNCATEGORIZED ),
	array( 'et-saving-post-1-bb', UNCATEGORIZED ),
	array( 'et-pb-recent-items-fonts', UNCATEGORIZED ),
	array( 'et-saved-post-2-bb', MARKETING ),
) );
$db->fail_on = 2; // the first prefix moves, the second UPDATE fails
$threw = false;
try {
	Activator::move_divi_editor_cookies_to_internal();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
dv_eq( $threw, true, 'a failure on a later UPDATE throws too' );
dv_eq( get_option( 'faz_move_divi_editor_internal_done', 0 ), 0, 'and leaves the marker unset' );
dv_eq( Cookie_Controller::$busts, 1, 'the rows already moved bust the cache' );
$db->fail_on = 0;
dv_eq( Activator::move_divi_editor_cookies_to_internal(), true, 'the retry completes' );
dv_eq( dv_cat( $db, 'et-saved-post-1-bb' ), INTERNAL, 'the row moved before the failure stays moved' );
dv_eq( dv_cat( $db, 'et-saving-post-1-bb' ), INTERNAL, 'the row of the failed UPDATE moves on retry' );
dv_eq( dv_cat( $db, 'et-pb-recent-items-fonts' ), INTERNAL, 'the rows after it move on retry' );
dv_eq( dv_cat( $db, 'et-saved-post-2-bb' ), MARKETING, 'Marketing is still left alone' );

echo "\nMarker write refused\n";
$db = dv_site( array( array( 'et-saved-post-1-bb', UNCATEGORIZED ) ) );
$GLOBALS['faz_test_refuse_options'] = array( 'faz_move_divi_editor_internal_done' );
dv_eq( Activator::move_divi_editor_cookies_to_internal(), false, 'a refused marker write reports the migration incomplete' );
dv_eq( get_option( 'faz_move_divi_editor_internal_done', 0 ), 0, 'and the marker stays absent' );
dv_eq( Cookie_Controller::$busts, 1, 'the moved rows still bust the cache' );
$GLOBALS['faz_test_refuse_options'] = array();
dv_eq( Activator::move_divi_editor_cookies_to_internal(), true, 'the retry reports completion' );
dv_eq( dv_cat( $db, 'et-saved-post-1-bb' ), INTERNAL, 'and the row is where the first run put it' );

echo "\nMissing category, nothing to move\n";
$db = dv_site( array( array( 'some-other-cookie', UNCATEGORIZED ) ), false );
dv_eq( Activator::move_divi_editor_cookies_to_internal(), true, 'with no editor cookie stored a missing category does not hold the batch back' );
dv_eq( get_option( 'faz_move_divi_editor_internal_done' ), 1, 'and the marker is set' );

echo "\ndivi editor migration: $passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );

}
