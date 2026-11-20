<?php
/**
 * Unit test — cookie and category text is stored without slashes, and the rows
 * written by older versions are unslashed exactly once.
 *
 * wp.org support, "Extra slashes in cookie tables": with [faz_cookie_table] an
 * apostrophe in a cookie description showed as \' to visitors. The setters
 * (Store::set_description, Cookie::set_duration, Cookie_Categories::set_name)
 * sanitised with wp_filter_post_kses(), a `pre_*` filter that returns SLASHED
 * output — addslashes( wp_kses( stripslashes( $data ) ) ) — so the table held
 * "It\'s". The getters stripped it again on read; every reader that goes to the
 * columns directly (the shortcode, the block, the policy generator, the export)
 * did not.
 *
 * Covered here:
 *   1. the setters store what they are given (the root cause);
 *   2. the getters unslash only while the migration is pending, so a real
 *      backslash survives once it has run;
 *   3. Store::unslash_text_column() on every stored shape;
 *   4. Activator::unslash_cookie_text() against a real SQLite database: legacy
 *      rows unslashed, clean rows untouched, run once (no double strip), the
 *      mutex, a failed write rolls back, missing tables retry;
 *   5. the import unslashes a pre-fix export only when the site is migrated.
 *
 * wp_filter_post_kses() is stubbed with WordPress's real definition: polyfilling
 * it as identity, as the older suites do, is what hid the bug from them.
 *
 * Run:
 *   php tests/unit/test-cookie-text-slashes-php.php
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
	class Category_Controller {
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

error_reporting( E_ALL & ~E_DEPRECATED );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$GLOBALS['faz_test_options'] = array();

function get_option( $name, $default = false ) {
	if ( array_key_exists( $name, $GLOBALS['faz_test_options'] ) ) {
		return $GLOBALS['faz_test_options'][ $name ];
	}
	// The migration writes its flag with SQL inside its transaction, so the
	// stub falls back to the test database the way the options API reads it.
	if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof Faz_Sqlite_Wpdb ) {
		$value = $GLOBALS['wpdb']->get_var( "SELECT option_value FROM wp_options WHERE option_name = '" . str_replace( "'", "''", $name ) . "'" );
		return null === $value ? $default : $value;
	}
	return $default;
}
$GLOBALS['faz_test_cache_deletes'] = array();
function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['faz_test_cache_deletes'][] = $group . ':' . $key;
	return true;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['faz_test_options'][ $name ] = $value;
	return true;
}
function esc_sql( $value ) {
	return $value;
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function wp_kses_post( $value ) {
	return (string) $value;
}
// WordPress's own definition (wp-includes/kses.php). Not identity.
function wp_filter_post_kses( $data ) {
	return addslashes( wp_kses_post( stripslashes( $data ) ) );
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function sanitize_title( $value ) {
	return strtolower( (string) $value );
}
function absint( $value ) {
	return abs( (int) $value );
}
function faz_selected_languages( $language = '' ) {
	return '' !== $language ? array( $language ) : array( 'en' );
}
function faz_default_language() {
	return 'en';
}
function do_action( $hook, ...$args ) {}
function apply_filters( $hook, $value, ...$args ) {
	return $value;
}

/**
 * The slice of $wpdb the migration uses, backed by a real SQLite database.
 */
class Faz_Sqlite_Wpdb {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public $fail_updates = false;
	public $fail_queries = array(); // Exact statements that return false.
	public $fail_once    = array(); // Same, for the first matching statement only.
	public $fail_reads   = false;   // Reads of the categories table report an error.
	public $last_error   = '';
	public $engine       = 'InnoDB'; // What INFORMATION_SCHEMA reports for wp_options.
	public $fail_flag_read = false;  // Reads of the completion flag report an error.
	public $pdo;
	public $log = array();

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
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
		$query = preg_replace( '/^INSERT IGNORE INTO/', 'INSERT OR IGNORE INTO', $query );
		$query = str_replace( 'ON DUPLICATE KEY UPDATE', 'ON CONFLICT("option_name") DO UPDATE SET', $query );
		return 'START TRANSACTION' === $query ? 'BEGIN' : $query;
	}
	public function get_var( $query ) {
		$this->last_error = '';
		if ( 0 === strpos( $query, 'SELECT `ENGINE` FROM INFORMATION_SCHEMA.TABLES' ) ) {
			return $this->engine;
		}
		if ( $this->fail_flag_read && false !== strpos( $query, "'faz_cookie_text_unslashed_done'" ) ) {
			// wpdb::get_var() returns null for a failed query, as for a missing row.
			$this->last_error = 'Lost connection to MySQL server during query';
			return null;
		}
		if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $query, $m ) ) {
			$st = $this->pdo->query( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $this->pdo->quote( $m[1] ) );
		} else {
			$st = $this->pdo->query( $this->sqlite( $query ) );
		}
		$value = $st->fetchColumn();
		return false === $value ? null : $value;
	}
	public function get_results( $query, $output = ARRAY_A ) {
		$this->last_error = '';
		// wpdb::get_results() hands back an empty array when the query fails.
		if ( $this->fail_reads && false !== strpos( $query, 'wp_faz_cookie_categories' ) ) {
			$this->last_error = 'Table is marked as crashed';
			return array();
		}
		return $this->pdo->query( $this->sqlite( $query ) )->fetchAll( PDO::FETCH_ASSOC );
	}
	public function query( $query ) {
		$this->log[] = $query;
		$this->last_error = '';
		foreach ( $this->fail_once as $k => $prefix ) {
			if ( 0 === strpos( $query, $prefix ) ) {
				unset( $this->fail_once[ $k ] );
				return false;
			}
		}
		foreach ( $this->fail_queries as $prefix ) {
			if ( 0 === strpos( $query, $prefix ) ) {
				return false;
			}
		}
		if ( $this->fail_updates && 0 === strpos( $query, 'UPDATE `wp_faz_cookie_categories`' ) ) {
			return false;
		}
		return $this->pdo->exec( $this->sqlite( $query ) );
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-store.php';
require_once $root . '/admin/modules/cookies/includes/class-cookie.php';
require_once $root . '/admin/modules/cookies/includes/class-cookie-categories.php';
require_once $root . '/includes/class-activator.php';

use FazCookie\Includes\Activator;
use FazCookie\Includes\Store;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories;

$passed = 0;
$failed = 0;
function sl_eq( $actual, $expected, $label ) {
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

/** A cookie row as Cookie_Controller::prepare_item() hands it over. */
function sl_cookie_row( $description, $duration = array( 'en' => '1 year' ) ) {
	return (object) array(
		'cookie_id'     => 9,
		'name'          => 'probe',
		'slug'          => 'probe',
		'description'   => $description,
		'duration'      => $duration,
		'domain'        => '.example.com',
		'category'      => 1,
		'type'          => 0,
		'discovered'    => 0,
		'url_pattern'   => '',
		'meta'          => array(),
		'date_created'  => '2026-01-01 00:00:00',
		'date_modified' => '2026-01-01 00:00:00',
	);
}

$apostrophe = "It's the visitor's id";
$backslash  = 'Path C:\\temp, not C:temp'; // One real backslash.

echo "Cookie text slashes\n\n";

// ---------------------------------------------------------------------------
// 1. The root cause: the setters store exactly what they were given.
// ---------------------------------------------------------------------------
echo "1. setters\n";
$GLOBALS['faz_test_options'] = array();
$cookie = new Cookie();
$cookie->set_description( array( 'en' => $apostrophe ) );
$cookie->set_duration( array( 'en' => "until the browser's closed" ) );
$data = $cookie->get_data();
sl_eq( $data['description']['en'], $apostrophe, 'cookie description is stored without a slash before the apostrophe' );
sl_eq( $data['duration']['en'], "until the browser's closed", 'cookie duration is stored without a slash' );
sl_eq( json_encode( $data['description'] ), '{"en":"It\'s the visitor\'s id"}', 'the JSON written to wp_faz_cookies.description carries no backslash' );

$cookie = new Cookie();
$cookie->set_description( array( 'en' => $backslash ) );
sl_eq( $cookie->get_data()['description']['en'], $backslash, 'a real backslash is kept, not eaten by stripslashes() on the way in' );

$category = new Cookie_Categories();
$category->set_name( array( 'en' => "Partners' cookies" ) );
$category->set_description( array( 'en' => "Set by our partners' scripts" ) );
$cat_data = $category->get_data();
sl_eq( $cat_data['name']['en'], "Partners' cookies", 'category name is stored without a slash' );
sl_eq( $cat_data['description']['en'], "Set by our partners' scripts", 'category description is stored without a slash' );

// ---------------------------------------------------------------------------
// 2. Getters: unslash only while the migration is pending.
// ---------------------------------------------------------------------------
echo "\n2. getters\n";
$GLOBALS['faz_test_options'] = array(); // Migration pending: rows still slashed.
$legacy = new Cookie( sl_cookie_row( array( 'en' => "It\\'s the visitor\\'s id" ), array( 'en' => "browser\\'s session" ) ) );
sl_eq( $legacy->get_description( 'en' ), $apostrophe, 'pending: a legacy slashed description still reads clean' );
sl_eq( $legacy->get_duration()['en'], "browser's session", 'pending: a legacy slashed duration still reads clean' );
$legacy_cat = new Cookie_Categories( (object) array( 'category_id' => 3, 'slug' => 'analytics', 'name' => array( 'en' => "Partners\\' cookies" ), 'description' => array( 'en' => "partners\\' scripts" ), 'prior_consent' => 0, 'visibility' => 1, 'priority' => 0, 'sell_personal_data' => 0, 'share_personal_data' => 0, 'meta' => array(), 'cookies' => array(), 'date_created' => '', 'date_modified' => '' ) );
sl_eq( $legacy_cat->get_name( 'en' ), "Partners' cookies", 'pending: a legacy slashed category name still reads clean' );
sl_eq( $legacy_cat->get_description( 'en' ), "partners' scripts", 'pending: a legacy slashed category description still reads clean' );

$GLOBALS['faz_test_options'] = array( Store::TEXT_UNSLASHED_OPTION => 1 ); // Migrated.
$clean = new Cookie( sl_cookie_row( array( 'en' => $backslash ), array( 'en' => '1 year' ) ) );
sl_eq( $clean->get_description( 'en' ), $backslash, 'migrated: a real backslash survives the getter' );
$clean = new Cookie( sl_cookie_row( array( 'en' => $apostrophe ) ) );
sl_eq( $clean->get_description( 'en' ), $apostrophe, 'migrated: a clean apostrophe reads as stored' );

// ---------------------------------------------------------------------------
// 3. Store::unslash_text_column() on every stored shape.
// ---------------------------------------------------------------------------
echo "\n3. unslash_text_column\n";
sl_eq( Store::unslash_text_column( '{"en":"It\\\\\'s","it":"L\\\\\'id"}' ), '{"en":"It\'s","it":"L\'id"}', 'a JSON column is decoded, unslashed per language and re-encoded' );
sl_eq( Store::unslash_text_column( '{"en":"plain"}' ), '{"en":"plain"}', 'a JSON column with nothing to strip keeps its exact bytes' );
sl_eq( Store::unslash_text_column( "It\\'s" ), "It's", 'a legacy plain-string column is unslashed' );
sl_eq( Store::unslash_text_column( array( 'en' => "It\\'s", 'n' => 3 ) ), array( 'en' => "It's", 'n' => 3 ), 'a decoded array is unslashed, non-strings passed through' );
sl_eq( Store::unslash_text_column( null ), null, 'null passes through (import of a missing column)' );
sl_eq( Store::unslash_text_column( '' ), '', 'empty string passes through' );

// ---------------------------------------------------------------------------
// 4. The migration, on SQLite.
// ---------------------------------------------------------------------------
echo "\n4. migration\n";
function sl_site( $tables = true ) {
	$GLOBALS['faz_test_options'] = array();
	\FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller::$busts   = 0;
	\FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::$busts = 0;
	$db = new Faz_Sqlite_Wpdb();
	$db->pdo->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)' );
	if ( $tables ) {
		$db->pdo->exec( 'CREATE TABLE wp_faz_cookies (cookie_id INTEGER PRIMARY KEY, name TEXT, description TEXT, duration TEXT)' );
		$db->pdo->exec( 'CREATE TABLE wp_faz_cookie_categories (category_id INTEGER PRIMARY KEY, slug TEXT, name TEXT, description TEXT)' );
		$c = $db->pdo->prepare( 'INSERT INTO wp_faz_cookies (cookie_id, name, description, duration) VALUES (?,?,?,?)' );
		// What the old setters wrote: json_encode( addslashes( text ) ).
		$c->execute( array( 1, 'legacy', json_encode( array( 'en' => addslashes( "It's the visitor's id" ), 'it' => addslashes( "L'id del visitatore" ) ) ), json_encode( array( 'en' => addslashes( "browser's session" ) ) ) ) );
		$c->execute( array( 2, 'legacy_backslash', json_encode( array( 'en' => addslashes( 'Path C:\\temp' ) ) ), json_encode( array( 'en' => '1 year' ) ) ) );
		$c->execute( array( 3, 'clean', json_encode( array( 'en' => 'Plain text' ) ), json_encode( array( 'en' => '1 day' ) ) ) );
		$c->execute( array( 4, 'plain_string', addslashes( "Visitor's legacy plain text" ), '' ) );
		$c->execute( array( 5, 'null_cols', null, null ) );
		$k = $db->pdo->prepare( 'INSERT INTO wp_faz_cookie_categories (category_id, slug, name, description) VALUES (?,?,?,?)' );
		$k->execute( array( 1, 'necessary', json_encode( array( 'en' => 'Necessary' ) ), json_encode( array( 'en' => addslashes( "Can't be switched off" ) ) ) ) );
		$k->execute( array( 2, 'partners', json_encode( array( 'en' => addslashes( "Partners' cookies" ) ) ), json_encode( array( 'en' => '' ) ) ) );
	}
	$GLOBALS['wpdb'] = $db;
	return $db;
}
function sl_col( $db, $table, $id_col, $id, $col ) {
	$st = $db->pdo->prepare( "SELECT {$col} FROM {$table} WHERE {$id_col} = ?" );
	$st->execute( array( $id ) );
	return $st->fetchColumn();
}
function sl_cookie( $db, $id, $col = 'description' ) {
	$raw = sl_col( $db, 'wp_faz_cookies', 'cookie_id', $id, $col );
	$dec = json_decode( (string) $raw, true );
	return is_array( $dec ) ? $dec : $raw;
}
function sl_cat( $db, $id, $col ) {
	return json_decode( (string) sl_col( $db, 'wp_faz_cookie_categories', 'category_id', $id, $col ), true );
}

$db = sl_site();
sl_eq( sl_cookie( $db, 1 )['en'], "It\\'s the visitor\\'s id", 'fixture: the legacy row holds the slashed text the report describes' );
sl_eq( Activator::unslash_cookie_text(), true, 'the migration reports done' );
sl_eq( sl_cookie( $db, 1 ), array( 'en' => "It's the visitor's id", 'it' => "L'id del visitatore" ), 'legacy description unslashed in every language' );
sl_eq( sl_cookie( $db, 1, 'duration' ), array( 'en' => "browser's session" ), 'legacy duration unslashed' );
sl_eq( sl_cookie( $db, 2 )['en'], 'Path C:\\temp', 'a backslash the administrator typed comes back exactly (addslashes undone, not stripped)' );
sl_eq( sl_col( $db, 'wp_faz_cookies', 'cookie_id', 3, 'description' ), '{"en":"Plain text"}', 'a row with nothing to strip is not rewritten' );
sl_eq( sl_cookie( $db, 4 ), "Visitor's legacy plain text", 'a legacy plain-string description is unslashed' );
sl_eq( sl_col( $db, 'wp_faz_cookies', 'cookie_id', 5, 'description' ), null, 'NULL columns are left alone' );
sl_eq( sl_cat( $db, 1, 'description' )['en'], "Can't be switched off", 'category description unslashed' );
sl_eq( sl_cat( $db, 2, 'name' )['en'], "Partners' cookies", 'category name unslashed' );
sl_eq( Store::text_unslashed_in_db(), true, 'the completion flag is written' );
sl_eq( in_array( 'options:alloptions', $GLOBALS['faz_test_cache_deletes'], true ), true, 'and the cached options are dropped so get_option() sees it' );
sl_eq( Store::legacy_slashes_pending(), false, 'and the getters stop unslashing' );
sl_eq( null === $db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'faz_cookie_text_unslash_lock'" ), true, 'the mutex is released' );
sl_eq( \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller::$busts > 0, true, 'the cookie caches are busted' );

// Exactly once: a second call must not strip the backslash again.
sl_eq( Activator::unslash_cookie_text(), true, 'a second call is a no-op that reports done' );
sl_eq( sl_cookie( $db, 2 )['en'], 'Path C:\\temp', 'no double strip: the real backslash is still there' );

// Another request holds the lock: decline (retry later), touch nothing.
$db = sl_site();
$db->pdo->exec( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('faz_cookie_text_unslash_lock', '" . time() . "', 'no')" );
sl_eq( Activator::unslash_cookie_text(), false, 'lock held by another request: declines' );
sl_eq( sl_cookie( $db, 1 )['en'], "It\\'s the visitor\\'s id", 'and leaves the rows to the request that holds it' );
sl_eq( get_option( Store::TEXT_UNSLASHED_OPTION, 0 ), 0, 'and writes no flag' );

// An abandoned lock is taken over.
$db = sl_site();
$db->pdo->exec( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('faz_cookie_text_unslash_lock', '" . ( time() - 3600 ) . "', 'no')" );
sl_eq( Activator::unslash_cookie_text(), true, 'an abandoned lock is taken over' );
sl_eq( sl_cookie( $db, 1 )['en'], "It's the visitor's id", 'and the rows are unslashed' );

// A failed write rolls everything back, throws, and leaves no flag or lock.
$db = sl_site();
$db->fail_updates = true; // Cookies succeed, the categories UPDATE fails.
$threw = false;
try {
	Activator::unslash_cookie_text();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
sl_eq( $threw, true, 'a failed UPDATE throws, so the batch withholds its version marker' );
sl_eq( sl_cookie( $db, 1 )['en'], "It\\'s the visitor\\'s id", 'the cookie rows already rewritten are rolled back (a retry cannot double-strip them)' );
sl_eq( get_option( Store::TEXT_UNSLASHED_OPTION, 0 ), 0, 'no flag claims it happened' );
sl_eq( null === $db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'faz_cookie_text_unslash_lock'" ), true, 'the mutex is released for the retry' );
$db->fail_updates = false;
sl_eq( Activator::unslash_cookie_text(), true, 'the retry succeeds' );
sl_eq( sl_cookie( $db, 2 )['en'], 'Path C:\\temp', 'and strips exactly once' );

// The flag is written inside the transaction: a COMMIT that fails leaves
// neither converted rows nor a flag, so the retry converts them exactly once.
$db = sl_site();
$db->fail_queries = array( 'COMMIT' );
$threw = false;
try {
	Activator::unslash_cookie_text();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
sl_eq( $threw, true, 'a failed COMMIT throws' );
sl_eq( Store::text_unslashed_in_db(), false, 'and no flag survives it' );
sl_eq( sl_cookie( $db, 1 )['en'], "It\\'s the visitor\\'s id", 'nor converted rows' );
$flag_at = null;
foreach ( $db->log as $i => $q ) {
	if ( false !== strpos( $q, 'ON DUPLICATE KEY UPDATE' ) ) {
		$flag_at = $i;
	}
}
$commit_at = array_search( 'COMMIT', $db->log, true );
sl_eq( null !== $flag_at && false !== $commit_at && $flag_at < $commit_at, true, 'the flag is written before the COMMIT, in the same transaction' );
$db->fail_queries = array();
sl_eq( Activator::unslash_cookie_text(), true, 'the retry succeeds' );
sl_eq( sl_cookie( $db, 2 )['en'], 'Path C:\\temp', 'and strips exactly once' );

// The flag write itself fails: rolled back, no flag, no rows.
$db = sl_site();
$db->fail_queries = array( 'INSERT INTO `wp_options`' );
$threw = false;
try {
	Activator::unslash_cookie_text();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
sl_eq( $threw, true, 'a flag that cannot be written throws, so the batch withholds its marker' );
sl_eq( sl_cookie( $db, 1 )['en'], "It\\'s the visitor\\'s id", 'and the rows are rolled back with it' );

// START TRANSACTION fails: nothing is written outside a transaction.
$db = sl_site();
$db->fail_queries = array( 'START TRANSACTION' );
$threw = false;
try {
	Activator::unslash_cookie_text();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
sl_eq( $threw, true, 'a transaction that cannot start throws' );
sl_eq( sl_cookie( $db, 1 )['en'], "It\\'s the visitor\\'s id", 'before any row is rewritten' );
sl_eq( null === $db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'faz_cookie_text_unslash_lock'" ), true, 'and the mutex is released' );

// A failed read looks like an empty table to is_array(); last_error tells them apart.
$db = sl_site();
$db->fail_reads = true;
$threw = false;
try {
	Activator::unslash_cookie_text();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
sl_eq( $threw, true, 'a categories read that fails is not taken for an empty table' );
sl_eq( sl_cookie( $db, 1 )['en'], "It\\'s the visitor\\'s id", 'the cookie rows converted before it are rolled back' );
sl_eq( Store::text_unslashed_in_db(), false, 'and no flag is written' );
sl_eq( null === $db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'faz_cookie_text_unslash_lock'" ), true, 'and the mutex is released' );

// Another request finished between this request's flag read and its claim:
// the cached read said pending, the database says done.
$db = sl_site();
$db->pdo->exec( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('faz_cookie_text_unslashed_done', '1', 'yes')" );
$db->pdo->exec( "UPDATE wp_faz_cookies SET description = '{\"en\":\"Path C:\\\\temp\"}' WHERE cookie_id = 2" ); // Clean, as that run left it.
$GLOBALS['faz_test_options'] = array( Store::TEXT_UNSLASHED_OPTION => 0 ); // Stale cache.
sl_eq( Activator::unslash_cookie_text(), true, 'a run that finds the flag once it holds the lock reports done' );
sl_eq( sl_cookie( $db, 2 )['en'], 'Path C:\\temp', 'and does not strip the converted rows again' );
sl_eq( null === $db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'faz_cookie_text_unslash_lock'" ), true, 'and releases the mutex' );

// The flag cannot be read once the lock is held: a failed query is not "pending".
$db = sl_site();
$db->pdo->exec( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('faz_cookie_text_unslashed_done', '1', 'yes')" );
$db->pdo->exec( "UPDATE wp_faz_cookies SET description = '{\"en\":\"Path C:\\\\temp\"}' WHERE cookie_id = 2" );
$GLOBALS['faz_test_options'] = array( Store::TEXT_UNSLASHED_OPTION => 0 ); // Stale cache.
$db->fail_flag_read = true;
$threw = false;
try {
	Store::text_unslashed_in_db();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
sl_eq( $threw, true, 'text_unslashed_in_db(): a failed read throws instead of answering "not done"' );
$threw = false;
try {
	Activator::unslash_cookie_text();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
sl_eq( $threw, true, 'the migration stops when its re-check after the lock cannot read the flag' );
sl_eq( sl_cookie( $db, 2 )['en'], 'Path C:\\temp', 'and does not strip the converted rows again' );
sl_eq( null === $db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'faz_cookie_text_unslash_lock'" ), true, 'and releases the mutex' );
$db->fail_flag_read = true;
sl_eq( Store::ensure_text_unslashed(), false, 'ensure_text_unslashed(): false when the flag cannot be read, so the import is refused' );
sl_eq( sl_cookie( $db, 2 )['en'], 'Path C:\\temp', 'and nothing is converted' );

// wp_options on MyISAM: a ROLLBACK cannot take the flag back, so it is written
// after the COMMIT, never inside a transaction that may be rolled back.
$db = sl_site();
$db->engine = 'MyISAM';
$db->fail_queries = array( 'COMMIT' );
$threw = false;
try {
	Activator::unslash_cookie_text();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
sl_eq( $threw, true, 'MyISAM options: a failed COMMIT throws' );
$flag_writes = count( array_filter( $db->log, function ( $q ) { return false !== strpos( $q, 'ON DUPLICATE KEY UPDATE' ); } ) );
sl_eq( $flag_writes, 0, 'MyISAM options: no flag was written inside the rolled-back transaction' );
$db->fail_queries = array();
sl_eq( Activator::unslash_cookie_text(), true, 'MyISAM options: the retry succeeds' );
$flag_at   = null;
foreach ( $db->log as $i => $q ) {
	if ( false !== strpos( $q, 'ON DUPLICATE KEY UPDATE' ) ) {
		$flag_at = $i;
	}
}
$commit_at = max( array_keys( $db->log, 'COMMIT', true ) );
sl_eq( null !== $flag_at && $flag_at > $commit_at, true, 'MyISAM options: the flag is written after the COMMIT' );
sl_eq( Store::text_unslashed_in_db(), true, 'MyISAM options: and is set' );
sl_eq( sl_cookie( $db, 2 )['en'], 'Path C:\\temp', 'MyISAM options: the rows are stripped once' );
$db = sl_site();
$db->engine = 'MyISAM';
$db->fail_queries = array( 'INSERT INTO `wp_options`' );
$threw = false;
try {
	Activator::unslash_cookie_text();
} catch ( \RuntimeException $e ) {
	$threw = true;
}
sl_eq( $threw, true, 'MyISAM options: a flag that cannot be written after the COMMIT throws' );
sl_eq( null !== $db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'faz_cookie_text_unslash_lock'" ), true, 'MyISAM options: and keeps the mutex, so no run strips the committed rows again before it expires' );

// MyISAM: the first write after the COMMIT fails, the second one records it.
$db = sl_site();
$db->engine = 'MyISAM';
$db->fail_once = array( 'INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES (\'faz_cookie_text_unslashed_done\'' );
sl_eq( Activator::unslash_cookie_text(), true, 'MyISAM options: a flag write that fails once is retried and the run reports done' );
$flag_writes = count( array_filter( $db->log, function ( $q ) { return false !== strpos( $q, 'ON DUPLICATE KEY UPDATE' ); } ) );
sl_eq( $flag_writes, 2, 'MyISAM options: two attempts were made' );
sl_eq( Store::text_unslashed_in_db(), true, 'MyISAM options: the flag is set' );
sl_eq( null === $db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'faz_cookie_text_unslash_lock'" ), true, 'MyISAM options: and the mutex is released' );
sl_eq( sl_cookie( $db, 2 )['en'], 'Path C:\\temp', 'MyISAM options: the text is converted once' );

// An engine the probe cannot read is not taken for InnoDB.
$db = sl_site();
$db->engine = null;
$db->fail_queries = array( 'COMMIT' );
try {
	Activator::unslash_cookie_text();
} catch ( \RuntimeException $e ) {
	unset( $e );
}
$flag_writes = count( array_filter( $db->log, function ( $q ) { return false !== strpos( $q, 'ON DUPLICATE KEY UPDATE' ); } ) );
sl_eq( $flag_writes, 0, 'unknown engine: no flag inside a transaction that may not take it back' );

// The import and the export run the migration first (Store::ensure_text_unslashed()).
$db = sl_site();
sl_eq( Store::ensure_text_unslashed(), true, 'ensure_text_unslashed(): a pending site is converted on the spot' );
sl_eq( sl_cookie( $db, 1 )['en'], "It's the visitor's id", 'its rows are clean before the import writes' );
$db = sl_site();
$db->pdo->exec( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('faz_cookie_text_unslash_lock', '" . time() . "', 'no')" );
sl_eq( Store::ensure_text_unslashed(), false, 'ensure_text_unslashed(): false while another request holds the lock, so the import is refused' );
$db = sl_site();
$db->fail_queries = array( 'START TRANSACTION' );
sl_eq( Store::ensure_text_unslashed(), false, 'ensure_text_unslashed(): false, not a throw, when the migration fails' );

// No tables yet: retried later.
sl_site( false );
sl_eq( Activator::unslash_cookie_text(), false, 'no tables: declines so it is retried' );
sl_eq( get_option( Store::TEXT_UNSLASHED_OPTION, 0 ), 0, 'and writes no flag' );

// ---------------------------------------------------------------------------
// 5. Wiring.
// ---------------------------------------------------------------------------
echo "\n5. wiring\n";
$activator = file_get_contents( $root . '/includes/class-activator.php' );
sl_eq( 1 === preg_match( '/function run_pending_migrations\(\).*?self::unslash_cookie_text\(\).*?self::refresh_necessary_disclosure_text\(\)/s', $activator ), true, 'run_pending_migrations() runs it, before the stock-text comparison' );
$api = file_get_contents( $root . '/admin/modules/settings/api/class-api.php' );
sl_eq( 1 === preg_match( "/if \( ! \\\\FazCookie\\\\Includes\\\\Store::ensure_text_unslashed\(\) \) \{\s*return new WP_Error\( 'faz_text_migration_pending'.*?\\\$unslash_text = empty\( \\\$data\['text_unslashed'\] \);/s", $api ), true, 'import converts the site first, refuses while it cannot, then unslashes only a pre-fix file' );
foreach ( array( 'REST' => $api, 'WP-CLI' => file_get_contents( $root . '/includes/class-wp-cli-commands.php' ) ) as $where => $src ) {
	$export = substr( $src, (int) strpos( $src, 'if ( ! \\FazCookie\\Includes\\Store::ensure_text_unslashed() ) {' ) );
	sl_eq( 1 === preg_match( "/^if \\( ! \\\\FazCookie\\\\Includes\\\\Store::ensure_text_unslashed\\(\\) \\) \\{\\s*(return new WP_Error\\( 'faz_text_migration_pending'|WP_CLI::error\\().*?'text_unslashed' => true,/s", $export ), true, "the $where export is refused until the rows are clean, and then marks them clean" );
}
sl_eq( substr_count( $api, '$text_column( $c' ), 4, 'import routes the four text columns through it' );
sl_eq( substr_count( $api, "'text_unslashed' =>" ), 1, 'the REST export marks its format' );
sl_eq( substr_count( file_get_contents( $root . '/includes/class-wp-cli-commands.php' ), "'text_unslashed' =>" ), 1, 'the WP-CLI export marks its format' );
$uninstall = file_get_contents( $root . '/uninstall.php' );
sl_eq( false !== strpos( $uninstall, "'faz_cookie_text_unslashed_done'" ), true, 'uninstall.php removes the flag' );
$setters = file_get_contents( $root . '/includes/class-store.php' ) . file_get_contents( $root . '/admin/modules/cookies/includes/class-cookie.php' ) . file_get_contents( $root . '/admin/modules/cookies/includes/class-cookie-categories.php' );
sl_eq( preg_match( '/=\s*[^;]*wp_filter_post_kses\(/', $setters ), 0, 'no setter calls wp_filter_post_kses() any more' );

echo "\n" . ( 0 === $failed ? "\033[32m" : "\033[31m" ) . "{$passed} passed, {$failed} failed\033[0m\n";
exit( 0 === $failed ? 0 : 1 );
}
