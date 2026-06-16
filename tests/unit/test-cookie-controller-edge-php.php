<?php
/**
 * Standalone unit tests for the Cookie / Category controller CRUD result
 * contract — EDGE CASES.
 *
 * Subsystem: cookie-controller-php.
 *
 * This suite is complementary to test-cookie-controller-php.php. Where that file
 * pins the happy-vs-error matrix of update/create/delete, this one exercises the
 * harder edges the contract implies:
 *
 *  - Cookie_Controller::update_item()
 *      * 0-affected-rows "unchanged values" no-op returns int 0 (===, not false)
 *        and is distinguishable from the false error path by strict type.
 *      * a real change purges the per-service detected-name transient (the
 *        invalidation that makes a freshly-edited cookie visible to consent).
 *      * a 0/no-op does NOT purge that transient (no spurious invalidation).
 *  - Cookie_Controller::create_item()
 *      * a successful insert purges faz_detected_cookie_names too (a newly
 *        scanned cookie must surface to per-service matching).
 *  - Cookie_Controller::delete_item()
 *      * the "non-existent id" 0 path returns int 0 and is strictly !== false.
 *  - Category_Controller::get_items()
 *      * attaches the discovered=1 cookie to its category and surfaces the
 *        discovered flag through get_prepared_data() (so the frontend can tell a
 *        scanned cookie from a hand-added one).
 *      * a category whose id never appears in the cookie set gets an EMPTY array
 *        (no cross-category leakage), even when OTHER categories DO have cookies.
 *  - frontend/class-frontend.php::get_detected_cookie_names() (REAL method, via
 *    Reflection so the heavy constructor is bypassed):
 *      * a warm transient short-circuits the DB entirely (get_col never runs).
 *      * a cold cache runs the EXACT `discovered = 1 AND name <> ''` SELECT and
 *        the result is cached back into the transient for next time.
 *
 * Only $wpdb, the object cache, transients and a handful of WP/faz_* helpers are
 * polyfilled. Product code is untouched.
 *
 * Run:
 *   php tests/unit/test-cookie-controller-edge-php.php
 *
 * @package FazCookie\Tests\Unit
 */

error_reporting( E_ALL & ~E_DEPRECATED );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return false;
	}
}

// ---------------------------------------------------------------------------
// Action capture.
// ---------------------------------------------------------------------------
$GLOBALS['_actions_fired'] = array();
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['_actions_fired'][] = $hook;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$rest ) {
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$a ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$a ) {}
}

// ---------------------------------------------------------------------------
// Object cache.
// ---------------------------------------------------------------------------
$GLOBALS['_cache']         = array();
$GLOBALS['_cache_deletes'] = array();
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) {
		$k = $group . '|' . $key;
		return array_key_exists( $k, $GLOBALS['_cache'] ) ? $GLOBALS['_cache'][ $k ] : false;
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $ttl = 0 ) {
		$GLOBALS['_cache'][ $group . '|' . $key ] = $data;
		return true;
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		$GLOBALS['_cache_deletes'][] = $group . '|' . $key;
		unset( $GLOBALS['_cache'][ $group . '|' . $key ] );
		return true;
	}
}

// ---------------------------------------------------------------------------
// Transients.
// ---------------------------------------------------------------------------
$GLOBALS['_transients']        = array();
$GLOBALS['_transient_deletes'] = array();
$GLOBALS['_transient_sets']    = array();
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['_transients'] ) ? $GLOBALS['_transients'][ $key ] : false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['_transients'][ $key ]     = $value;
		$GLOBALS['_transient_sets'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		$GLOBALS['_transient_deletes'][] = $key;
		unset( $GLOBALS['_transients'][ $key ] );
		return true;
	}
}

// ---------------------------------------------------------------------------
// Sanitizers / misc WP helpers.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $v ) {
		$v = is_scalar( $v ) ? (string) $v : '';
		$v = strip_tags( $v );
		$v = preg_replace( '/[\r\n\t]+/', ' ', $v );
		return trim( preg_replace( '/\s+/', ' ', $v ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $v ) {
		return is_scalar( $v ) ? strip_tags( (string) $v ) : '';
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $v ) {
		$v = strtolower( (string) $v );
		return preg_replace( '/[^a-z0-9_-]+/', '-', $v );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $v ) {
		return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) {
		return abs( (int) $v );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) {
		return json_encode( $v );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $v ) {
		return (string) $v;
	}
}
if ( ! function_exists( 'wp_filter_post_kses' ) ) {
	function wp_filter_post_kses( $v ) {
		return (string) $v;
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return '2026-01-01 00:00:00';
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = '' ) {
		return $t;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $v ) {
		return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
	}
}

// faz_* helpers used by the Store/Cookie object graph.
if ( ! function_exists( 'faz_default_language' ) ) {
	function faz_default_language() {
		return 'en';
	}
}
if ( ! function_exists( 'faz_selected_languages' ) ) {
	function faz_selected_languages( $language = '' ) {
		return array( 'en' );
	}
}
if ( ! function_exists( 'faz_is_admin_request' ) ) {
	function faz_is_admin_request() {
		return false;
	}
}
if ( ! function_exists( 'faz_is_admin_page' ) ) {
	function faz_is_admin_page() {
		return false;
	}
}

// ---------------------------------------------------------------------------
// Mock $wpdb.
// ---------------------------------------------------------------------------
class Faz_Edge_WPDB {
	public $prefix    = 'wp_';
	public $options   = 'wp_options';
	public $insert_id = 0;

	public $update_queue = array();
	public $insert_queue = array();
	public $delete_queue = array();

	public $last_update = null;
	public $last_insert = null;
	public $last_delete = null;

	public $cookies_rows  = array();
	public $category_rows = array();
	public $detected_col  = array();

	/** Captured get_col SQL + invocation count (proves the warm-cache short-circuit). */
	public $last_get_col_sql = '';
	public $get_col_calls    = 0;

	public function has_cap( $c ) {
		return false;
	}
	public function get_charset_collate() {
		return '';
	}
	public function esc_like( $s ) {
		return addcslashes( $s, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $a ) {
			$query = preg_replace( '/%d/', (string) (int) $a, $query, 1 );
			$query = preg_replace( '/%s/', "'" . addslashes( (string) $a ) . "'", $query, 1 );
		}
		return $query;
	}

	private function dequeue( &$queue, $default ) {
		if ( ! empty( $queue ) ) {
			return array_shift( $queue );
		}
		return $default;
	}

	public function insert( $table, $data, $format = array() ) {
		$this->last_insert = array( $table, $data );
		$res               = $this->dequeue( $this->insert_queue, 1 );
		if ( false !== $res ) {
			$this->insert_id = 4242;
		}
		return $res;
	}

	public function update( $table, $data, $where, $format = array(), $where_format = array() ) {
		$this->last_update = array( $table, $data, $where );
		return $this->dequeue( $this->update_queue, 1 );
	}

	public function delete( $table, $where, $where_format = array() ) {
		$this->last_delete = array( $table, $where );
		return $this->dequeue( $this->delete_queue, 1 );
	}

	public function get_row( $query ) {
		return null;
	}

	/**
	 * Satisfies table_exist() (SHOW TABLES LIKE …) and data_exist()
	 * (SELECT COUNT(*) …) so the controllers treat the custom tables as
	 * present + populated. Returns the bare table name for SHOW TABLES (the
	 * existence probe compares case-insensitively) and a positive row count
	 * for COUNT(*).
	 */
	public function get_var( $query ) {
		if ( false !== stripos( $query, 'SHOW TABLES LIKE' ) ) {
			if ( preg_match( "/LIKE\\s+'([^']+)'/i", $query, $m ) ) {
				// esc_like escaped the underscores; unescape for the comparison.
				return str_replace( '\\_', '_', $m[1] );
			}
			return $this->prefix . 'faz_cookies';
		}
		if ( false !== stripos( $query, 'COUNT(*)' ) ) {
			return 1; // Non-empty table.
		}
		return null;
	}

	public function get_results( $query ) {
		if ( false !== strpos( $query, 'faz_cookie_categories' ) ) {
			return $this->category_rows;
		}
		if ( false !== strpos( $query, 'faz_cookies' ) ) {
			return $this->cookies_rows;
		}
		return array();
	}

	public function get_col( $query ) {
		$this->last_get_col_sql = $query;
		++$this->get_col_calls;
		return $this->detected_col;
	}

	public function query( $sql ) {
		return true;
	}
}

// ---------------------------------------------------------------------------
// Load the real plugin classes (dependency order).
// ---------------------------------------------------------------------------
$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-cache.php';
require_once $root . '/includes/class-store.php';
require_once $root . '/includes/class-base-controller.php';
require_once $root . '/admin/modules/cookies/includes/class-cookie.php';
require_once $root . '/admin/modules/cookies/includes/class-cookie-categories.php';
require_once $root . '/admin/modules/cookies/includes/class-cookie-controller.php';
require_once $root . '/admin/modules/cookies/includes/class-category-controller.php';

use FazCookie\Admin\Modules\Cookies\Includes\Cookie;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
use FazCookie\Includes\Cache;

// ---------------------------------------------------------------------------
// Assertion harness.
// ---------------------------------------------------------------------------
$tests_run = $tests_passed = $tests_failed = 0;
function assert_eq( $a, $e, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $a === $e ) {
		$tests_passed++;
		echo "  \033[32m✓\033[0m $label\n";
	} else {
		$tests_failed++;
		echo "  \033[31m✗\033[0m $label\n      expected: " . var_export( $e, true ) . "\n      actual:   " . var_export( $a, true ) . "\n";
	}
}
function assert_true( $c, $l ) {
	assert_eq( (bool) $c, true, $l );
}
function assert_false( $c, $l ) {
	assert_eq( (bool) $c, false, $l );
}

function reset_side_effects() {
	$GLOBALS['_actions_fired']     = array();
	$GLOBALS['_cache_deletes']     = array();
	$GLOBALS['_transient_deletes'] = array();
	$GLOBALS['_transient_sets']    = array();
}
function transient_purged( $key ) {
	return in_array( $key, $GLOBALS['_transient_deletes'], true );
}

function make_cookie( $id, $name = 'test_cookie', $discovered = true ) {
	$row                = new stdClass();
	$row->cookie_id     = $id;
	$row->name          = $name;
	$row->slug          = $name;
	$row->description    = wp_json_encode( array( 'en' => 'desc' ) );
	$row->duration       = wp_json_encode( array( 'en' => 'session' ) );
	$row->domain         = 'example.com';
	$row->category       = 3;
	$row->type           = 0;
	$row->discovered     = $discovered ? 1 : 0;
	$row->url_pattern    = '';
	$row->meta           = wp_json_encode( array() );
	$row->date_created   = '2026-01-01 00:00:00';
	$row->date_modified  = '2026-01-01 00:00:00';
	return new Cookie( $row );
}

global $wpdb;
$wpdb = new Faz_Edge_WPDB();
$ctrl = Cookie_Controller::get_instance();

echo "\n== Cookie / Category controller — edge cases ==\n\n";

// =========================================================================
// 1. update_item() "unchanged values" no-op returns INT 0, strictly !== false.
//    This is the boundary the contract leans on: a MySQL UPDATE that matched a
//    row but changed nothing reports 0 affected rows, and that must NOT be
//    conflated with the error sentinel false.
// =========================================================================
reset_side_effects();
$wpdb->update_queue = array( 0 );
$res                = $ctrl->update_item( make_cookie( 55, 'unchanged' ) );
assert_eq( $res, 0, 'update_item: unchanged values → returns int 0' );
assert_true( 0 === $res && false !== $res, 'update_item: the 0 no-op is strictly distinct from the false error path' );

// =========================================================================
// 2. update_item() no-op does NOT purge the detected-name transient — a write
//    that changed nothing must not trigger per-service list invalidation.
// =========================================================================
$GLOBALS['_transients']['faz_detected_cookie_names'] = array( '_ga' );
reset_side_effects();
$wpdb->update_queue = array( 0 );
$ctrl->update_item( make_cookie( 56 ) );
assert_false( transient_purged( 'faz_detected_cookie_names' ), 'update_item(0): does NOT purge faz_detected_cookie_names (no spurious invalidation)' );

// =========================================================================
// 3. update_item() real change PURGES the detected-name transient so a
//    freshly-edited cookie is re-derived for per-service consent matching.
// =========================================================================
$GLOBALS['_transients']['faz_detected_cookie_names'] = array( '_ga' );
reset_side_effects();
$wpdb->update_queue = array( 1 );
$res                = $ctrl->update_item( make_cookie( 57, 'edited' ) );
assert_eq( $res, 1, 'update_item: real change → returns affected count 1' );
assert_true( transient_purged( 'faz_detected_cookie_names' ), 'update_item(1): purges faz_detected_cookie_names so edits surface to per-service matching' );

// =========================================================================
// 4. create_item() success also purges the detected-name transient — a newly
//    scanned/added cookie must become visible to provider matching.
// =========================================================================
$GLOBALS['_transients']['faz_detected_cookie_names'] = array( '_ga' );
reset_side_effects();
$wpdb->insert_queue = array( 1 );
$cookie             = make_cookie( 0, 'brand_new' );
$ctrl->create_item( $cookie );
assert_eq( $cookie->get_id(), 4242, 'create_item(success): assigns insert_id 4242 to the object' );
assert_true( transient_purged( 'faz_detected_cookie_names' ), 'create_item(success): purges faz_detected_cookie_names (new cookie surfaces)' );

// =========================================================================
// 5. delete_item() non-existent id returns INT 0, strictly !== false, no action.
// =========================================================================
reset_side_effects();
$wpdb->delete_queue = array( 0 );
$res                = $ctrl->delete_item( make_cookie( 7777777 ) );
assert_eq( $res, 0, 'delete_item: non-existent id (0 rows) → returns int 0' );
assert_true( 0 === $res && false !== $res, 'delete_item: the 0 no-op is strictly distinct from the false error path' );
assert_false( in_array( 'faz_after_delete_cookie', $GLOBALS['_actions_fired'], true ), 'delete_item(0): fires nothing' );

// =========================================================================
// 6. Category_Controller::get_items() attaches the discovered=1 cookie to its
//    category and the discovered flag survives into get_prepared_data(), while
//    a category id absent from the cookie set gets an EMPTY array even though a
//    DIFFERENT category has cookies (no cross-category leakage).
// =========================================================================
reset_side_effects();
$GLOBALS['_cache']      = array();
$GLOBALS['_transients'] = array();
Cache::reset_prefix_cache();

$cat_ctrl = Category_Controller::get_instance();

$cat3                      = new stdClass();
$cat3->category_id         = 3;
$cat3->name                = wp_json_encode( array( 'en' => 'Analytics' ) );
$cat3->slug                = 'analytics';
$cat3->description         = wp_json_encode( array( 'en' => '' ) );
$cat3->prior_consent       = 0;
$cat3->visibility          = 1;
$cat3->priority            = 1;
$cat3->sell_personal_data  = 1;
$cat3->share_personal_data = 1;
$cat3->meta                = wp_json_encode( array() );
$cat3->date_created        = '2026-01-01 00:00:00';
$cat3->date_modified       = '2026-01-01 00:00:00';

$cat9              = clone $cat3;
$cat9->category_id = 9;
$cat9->slug        = 'empty-cat';

$wpdb->category_rows = array( $cat3, $cat9 );

$ck1                = new stdClass();
$ck1->cookie_id     = 100;
$ck1->name          = '_ga';
$ck1->slug          = '_ga';
$ck1->description    = wp_json_encode( array( 'en' => '' ) );
$ck1->duration       = wp_json_encode( array( 'en' => '2 years' ) );
$ck1->domain         = 'example.com';
$ck1->category       = 3;
$ck1->type           = 0;
$ck1->discovered     = 1;
$ck1->url_pattern    = '';
$ck1->meta           = wp_json_encode( array() );
$ck1->date_created   = '2026-01-01 00:00:00';
$ck1->date_modified  = '2026-01-01 00:00:00';
$wpdb->cookies_rows = array( $ck1 );

$items = $cat_ctrl->get_items();

assert_eq( count( $items[3]->cookies ), 1, 'get_items: category 3 has exactly its 1 cookie attached' );
assert_eq( $items[3]->cookies[0]['name'], '_ga', 'get_items: attached cookie carries the right name' );
assert_eq( (int) $items[3]->cookies[0]['discovered'], 1, 'get_items: discovered=1 flag survives into get_prepared_data()' );
assert_eq( $items[9]->cookies, array(), 'get_items: a cookie-less category gets EMPTY array while cat 3 has cookies (no leakage)' );

// =========================================================================
// 7. frontend get_detected_cookie_names(): WARM transient short-circuits the
//    DB — get_col() is never called. Invoked on the REAL method via Reflection
//    (the constructor is heavy, so we bypass it with newInstanceWithout
//    constructor).
// =========================================================================
require_once $root . '/frontend/class-frontend.php';
$frontend_fqcn = 'FazCookie\\Frontend\\Frontend';
assert_true( class_exists( $frontend_fqcn ), 'frontend: Frontend class is loadable' );

$ref      = new ReflectionClass( $frontend_fqcn );
$instance = $ref->newInstanceWithoutConstructor();
$method   = $ref->getMethod( 'get_detected_cookie_names' );
$method->setAccessible( true );

$wpdb->get_col_calls                                 = 0;
$GLOBALS['_transients']['faz_detected_cookie_names'] = array( '_warm', '_cached' );
$warm                                                = $method->invoke( $instance );
assert_eq( $warm, array( '_warm', '_cached' ), 'get_detected_cookie_names: warm transient returns the cached list verbatim' );
assert_eq( $wpdb->get_col_calls, 0, 'get_detected_cookie_names: warm cache short-circuits the DB (get_col NOT called)' );

// =========================================================================
// 8. frontend get_detected_cookie_names(): COLD cache runs the EXACT
//    discovered=1 + name<>'' SELECT and writes the result back to the transient.
// =========================================================================
unset( $GLOBALS['_transients']['faz_detected_cookie_names'] );
$GLOBALS['_transient_sets'] = array();
$wpdb->get_col_calls        = 0;
$wpdb->detected_col         = array( '_ga', '_gid' );
$cold                       = $method->invoke( $instance );

$expected_sql = "SELECT DISTINCT name FROM {$wpdb->prefix}faz_cookies WHERE name <> '' AND discovered = 1";
assert_eq( $wpdb->get_col_calls, 1, 'get_detected_cookie_names: cold cache runs exactly one get_col' );
assert_eq( $wpdb->last_get_col_sql, $expected_sql, 'get_detected_cookie_names: runs the exact discovered=1 + name<>\'\' SELECT' );
assert_eq( $cold, array( '_ga', '_gid' ), 'get_detected_cookie_names: cold cache returns discovered/non-empty names' );
assert_true(
	isset( $GLOBALS['_transient_sets']['faz_detected_cookie_names'] ) && array( '_ga', '_gid' ) === $GLOBALS['_transient_sets']['faz_detected_cookie_names'],
	'get_detected_cookie_names: result is cached back into the transient for the next request'
);

// =========================================================================
// 9. frontend get_detected_cookie_names(): a malformed (non-array) get_col
//    result degrades to an empty list — never a warning, never a string leak.
// =========================================================================
unset( $GLOBALS['_transients']['faz_detected_cookie_names'] );
$wpdb->detected_col = null;
$malformed          = $method->invoke( $instance );
assert_eq( $malformed, array(), 'get_detected_cookie_names: non-array get_col result → empty list (defensive cast)' );

// ---------------------------------------------------------------------------
echo "\n--\n";
echo "Tests:  $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n\n";
if ( $tests_failed > 0 ) {
	echo "\033[31mFAIL\033[0m\n";
	exit( 1 );
}
echo "\033[32mPASS\033[0m\n";
exit( 0 );
