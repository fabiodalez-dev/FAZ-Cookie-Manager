<?php
/**
 * Unit test — the one-time migration that moves the Sourcebuster cookies
 * (sbjs_*) to Marketing.
 *
 * FAZ now gates sourcebuster.js on Marketing, the category WooCommerce Order
 * Attribution asks for. Sites that scanned the cookies earlier saved them as
 * Analytics, and the saved list is what the runtime consults first: a visitor
 * who accepted Marketing alone got the script, WooCommerce wrote the cookies,
 * and FAZ deleted them again because Analytics was not accepted. Measured on
 * the test site: seven cookies written, gone within 200 ms.
 *
 * What matters is the restraint. Only rows the scanner wrote and nobody saved
 * since are moved. A row an administrator saved keeps their choice; so does an
 * imported row (zero dates), whose category may have been somebody's choice
 * on another site. Both are only counted, so the notice can explain what the
 * category now means. And it runs once per site.
 *
 * The migration's SQL runs for real, against an in-memory SQLite database —
 * which is also the portability check (no MySQL-only syntax). LIKE carries
 * ESCAPE '\', as WordPress's SQLite integration adds, so the escaping of the
 * `_` in `sbjs_` is exercised: unescaped, it would match any single character.
 *
 * Self-contained: WordPress is stubbed, so this runs under a bare `php`.
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
		return $this->fail ? false : $this->pdo->exec( $this->sqlite( $query ) );
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-activator.php';

use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;
use FazCookie\Includes\Activator;

$passed = 0;
$failed = 0;
function sb_eq( $actual, $expected, $label ) {
	global $passed, $failed;
	if ( $actual === $expected ) {
		$passed++;
		echo "  \033[32mPASS\033[0m {$label}\n";
	} else {
		$failed++;
		echo "  \033[31mFAIL\033[0m {$label}\n";
		echo '        expected: ' . var_export( $expected, true ) . "\n";
		echo '        actual:   ' . var_export( $actual, true ) . "\n";
	}
}

const ANALYTICS = 3;
const MARKETING = 7;
const NECESSARY = 1;
const SCANNED   = '2026-05-01 10:00:00';

/**
 * A fresh site. Each cookie row: [ name, category, date_created, date_modified ].
 */
function sb_site( array $rows, $with_marketing = true, $tables = true ) {
	$GLOBALS['faz_test_options'] = array();
	Cookie_Controller::$busts    = 0;
	$db                          = new Faz_Sqlite_Wpdb();
	if ( $tables ) {
		$db->pdo->exec( 'CREATE TABLE wp_faz_cookie_categories (category_id INTEGER PRIMARY KEY, slug TEXT)' );
		$db->pdo->exec( 'CREATE TABLE wp_faz_cookies (cookie_id INTEGER PRIMARY KEY, name TEXT, category INTEGER, date_created TEXT, date_modified TEXT, discovered INTEGER)' );
		$db->pdo->exec( "INSERT INTO wp_faz_cookie_categories VALUES (1,'necessary'),(3,'analytics')" );
		if ( $with_marketing ) {
			$db->pdo->exec( "INSERT INTO wp_faz_cookie_categories VALUES (7,'marketing')" );
		}
		$st = $db->pdo->prepare( 'INSERT INTO wp_faz_cookies (name, category, date_created, date_modified, discovered) VALUES (?,?,?,?,?)' );
		foreach ( $rows as $row ) {
			$st->execute( array_pad( $row, 5, 1 ) );
		}
	}
	$GLOBALS['wpdb'] = $db;
	return $db;
}
function sb_category( $db, $name ) {
	$st = $db->pdo->prepare( 'SELECT category FROM wp_faz_cookies WHERE name = ?' );
	$st->execute( array( $name ) );
	return (int) $st->fetchColumn();
}

echo "Sourcebuster → Marketing migration (SQLite)\n\n";

// 1. The case the migration exists for: scanned rows, nobody saved them.
$db = sb_site(
	array(
		array( 'sbjs_session', ANALYTICS, SCANNED, SCANNED ),
		array( 'sbjs_first', ANALYTICS, SCANNED, SCANNED ),
		array( 'sbjs_udata', ANALYTICS, '0000-00-00 00:00:00', '0000-00-00 00:00:00' ),
		array( '_ga', ANALYTICS, SCANNED, SCANNED ),
		array( 'sbjsx_other', ANALYTICS, SCANNED, SCANNED ),
	)
);
Activator::move_sourcebuster_to_marketing();
sb_eq( sb_category( $db, 'sbjs_session' ), MARKETING, 'an unsaved sbjs_ row moves to Marketing' );
sb_eq( sb_category( $db, 'sbjs_first' ), MARKETING, 'every unsaved sbjs_ row moves' );
// Zero dates are what the settings import leaves: the category came from a
// file, maybe another site's scan, maybe somebody's decision. Not evidence of
// an untouched row, so it is kept and counted, never moved.
sb_eq( sb_category( $db, 'sbjs_udata' ), ANALYTICS, 'an imported row (zero dates) keeps its category' );
sb_eq( sb_category( $db, '_ga' ), ANALYTICS, 'another Analytics cookie is not touched' );
sb_eq( sb_category( $db, 'sbjsx_other' ), ANALYTICS, 'the _ in sbjs_ is literal: sbjsx_other is not a Sourcebuster cookie' );
sb_eq( get_option( 'faz_sourcebuster_marketing_notice' ), array( 'moved' => 2, 'kept' => 1 ), 'the notice counts what moved and the imported row it left' );
sb_eq( Cookie_Controller::$busts, 1, 'the cookie cache is busted, so the page stops declaring the old category' );
sb_eq( get_option( 'faz_move_sourcebuster_marketing_done' ), 1, 'and the marker is written' );

// 2. THE RESTRAINT. A row an administrator saved keeps their choice, in
//    whatever category it is, and is counted for the notice instead.
$db = sb_site(
	array(
		array( 'sbjs_session', ANALYTICS, SCANNED, '2026-06-02 09:00:00' ),
		array( 'sbjs_first', NECESSARY, SCANNED, '2026-06-02 09:00:00' ),
		array( 'sbjs_current', ANALYTICS, SCANNED, SCANNED ),
	)
);
Activator::move_sourcebuster_to_marketing();
sb_eq( sb_category( $db, 'sbjs_session' ), ANALYTICS, 'a row saved as Analytics by an administrator stays Analytics' );
sb_eq( sb_category( $db, 'sbjs_first' ), NECESSARY, 'a row saved in any other category stays there' );
sb_eq( sb_category( $db, 'sbjs_current' ), MARKETING, 'while its unsaved sibling still moves' );
sb_eq( get_option( 'faz_sourcebuster_marketing_notice' ), array( 'moved' => 1, 'kept' => 2 ), 'the kept rows are counted, so the notice can explain them' );

// A manual cookie is not scanner-owned even when its two dates are equal.
$db = sb_site( array( array( 'sbjs_manual', ANALYTICS, SCANNED, SCANNED, 0 ) ) );
Activator::move_sourcebuster_to_marketing();
sb_eq( sb_category( $db, 'sbjs_manual' ), ANALYTICS, 'a newly created manual Sourcebuster row keeps its category' );
sb_eq( get_option( 'faz_sourcebuster_marketing_notice' ), array( 'moved' => 0, 'kept' => 1 ), 'the manual row is reported as preserved' );

// 3. Only kept rows: nothing moves, the cache is left alone, the notice still
//    tells the administrator what their choice now means.
$db = sb_site( array( array( 'sbjs_session', ANALYTICS, SCANNED, '2026-06-02 09:00:00' ) ) );
Activator::move_sourcebuster_to_marketing();
sb_eq( sb_category( $db, 'sbjs_session' ), ANALYTICS, 'nothing unsaved: nothing moves' );
sb_eq( Cookie_Controller::$busts, 0, 'no move, no cache bust' );
sb_eq( get_option( 'faz_sourcebuster_marketing_notice' ), array( 'moved' => 0, 'kept' => 1 ), 'but the notice is armed for the kept row' );

// 4. Already Marketing, or no Sourcebuster at all: silence.
$db = sb_site( array( array( 'sbjs_session', MARKETING, SCANNED, SCANNED ), array( '_ga', ANALYTICS, SCANNED, SCANNED ) ) );
Activator::move_sourcebuster_to_marketing();
sb_eq( get_option( 'faz_sourcebuster_marketing_notice' ), false, 'nothing to say: no notice' );
sb_eq( get_option( 'faz_move_sourcebuster_marketing_done' ), 1, 'the marker is still written' );

// 5. Once per site. A later MIGRATIONS_VERSION bump must not overrule a
//    category set after this ran — even an unsaved-looking one (an import
//    writes rows without dates).
$db = sb_site( array( array( 'sbjs_session', ANALYTICS, SCANNED, SCANNED ) ) );
$GLOBALS['faz_test_options']['faz_move_sourcebuster_marketing_done'] = 1;
Activator::move_sourcebuster_to_marketing();
sb_eq( sb_category( $db, 'sbjs_session' ), ANALYTICS, 'a site already migrated is not touched again' );

// 6. No tables yet: nothing, and no marker, so it runs once they exist.
sb_site( array(), true, false );
Activator::move_sourcebuster_to_marketing();
sb_eq( get_option( 'faz_move_sourcebuster_marketing_done' ), false, 'no tables: no marker, retried later' );

// 7. No Marketing category: nothing to move to, now or later.
$db = sb_site( array( array( 'sbjs_session', ANALYTICS, SCANNED, SCANNED ) ), false );
Activator::move_sourcebuster_to_marketing();
sb_eq( sb_category( $db, 'sbjs_session' ), ANALYTICS, 'no Marketing category: the row is left alone' );
sb_eq( get_option( 'faz_move_sourcebuster_marketing_done' ), 1, 'and the site is not reconsidered forever' );

// 8. A failed write throws, so run_pending_migrations() withholds the
//    version and the move is retried — and no marker claims it happened.
$db       = sb_site( array( array( 'sbjs_session', ANALYTICS, SCANNED, SCANNED ) ) );
$db->fail = true;
$threw    = false;
try {
	Activator::move_sourcebuster_to_marketing();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
sb_eq( $threw, true, 'a failed UPDATE throws' );
sb_eq( get_option( 'faz_move_sourcebuster_marketing_done' ), false, 'and leaves no marker behind' );

// 9. Registered where every upgrade runs it.
$source = file_get_contents( $root . '/includes/class-activator.php' );
sb_eq( 1 === preg_match( '/function run_pending_migrations\(\).*?self::move_sourcebuster_to_marketing\(\);/s', $source ), true, 'run_pending_migrations() calls the migration' );

echo "\n" . ( 0 === $failed ? "\033[32m" : "\033[31m" ) . "{$passed} passed, {$failed} failed\033[0m\n";
exit( 0 === $failed ? 0 : 1 );
}
