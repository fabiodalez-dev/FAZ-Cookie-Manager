<?php
/**
 * Round-trip test: reading a cookie description and saving it back must not
 * rewrite the row with bundled catalogue text.
 *
 * Subsystem: cookie-content-i18n (issue #214).
 *
 * The fallback resolver only ever runs on READ, which is what makes it safe —
 * but every admin save goes read → wp_json_encode() → UPDATE, so whatever a read
 * invents is one save away from being the stored value. That is how an
 * administrator's own description (reviewed legal copy, naming their processors
 * and their retention) came to be replaced by a generic bundled sentence, in a
 * language they never wrote and could not see they had "approved".
 *
 * The suites in test-cookie-content-i18n-php.php pin the resolver in isolation.
 * This one closes the loop through the REAL Cookie_Controller::update_item(),
 * capturing the exact column values handed to $wpdb and feeding them back in as
 * a fresh row:
 *
 *   1. administrator wording survives the trip untouched, in every language;
 *   2. stock bundled English still DOES translate on the way out (the positive
 *      control — without it, case 1 would also pass with the feature dead);
 *   3. a second save changes nothing (no slow drift over repeated edits).
 *
 * Only $wpdb, the object cache, transients and a handful of WP/faz_* helpers are
 * polyfilled. Product code is untouched.
 *
 * Run:
 *   php tests/unit/test-cookie-description-roundtrip-php.php
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

// ---------------------------------------------------------------------------
// WordPress polyfills.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$a ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$a ) {}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return false;
	}
}
$GLOBALS['_cache'] = array();
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
		unset( $GLOBALS['_cache'][ $group . '|' . $key ] );
		return true;
	}
}
$GLOBALS['_transients'] = array();
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['_transients'] ) ? $GLOBALS['_transients'][ $key ] : false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $v ) {
		$v = is_scalar( $v ) ? (string) $v : '';
		return trim( preg_replace( '/\s+/', ' ', strip_tags( $v ) ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $v ) {
		return is_scalar( $v ) ? strip_tags( (string) $v ) : '';
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $v ) {
		return preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( (string) $v ) );
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
		return '2026-08-11 00:00:00';
	}
}
if ( ! function_exists( 'faz_default_language' ) ) {
	function faz_default_language() {
		return $GLOBALS['faz_roundtrip_default_language'];
	}
}
if ( ! function_exists( 'faz_selected_languages' ) ) {
	function faz_selected_languages( $language = '' ) {
		return '' !== $language ? array( $language ) : $GLOBALS['faz_roundtrip_selected_languages'];
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
// $wpdb double: the UPDATE payload is the artefact under test.
// ---------------------------------------------------------------------------
class Faz_Roundtrip_WPDB {
	public $prefix      = 'wp_';
	public $options     = 'wp_options';
	public $insert_id   = 0;
	public $last_update = null;

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
		return $query;
	}
	public function update( $table, $data, $where, $format = array(), $where_format = array() ) {
		$this->last_update = $data;
		return 1;
	}
	public function insert( $table, $data, $format = array() ) {
		return 1;
	}
	public function delete( $table, $where, $where_format = array() ) {
		return 1;
	}
	public function get_row( $q ) {
		return null;
	}
	public function get_var( $q ) {
		if ( false !== stripos( $q, 'SHOW TABLES LIKE' ) ) {
			return $this->prefix . 'faz_cookies';
		}
		return 1;
	}
	public function get_results( $q ) {
		return array();
	}
	public function get_col( $q ) {
		return array();
	}
	public function query( $q ) {
		return true;
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-cache.php';
require_once $root . '/includes/class-store.php';
require_once $root . '/includes/class-base-controller.php';
require_once $root . '/includes/class-cookie-content-i18n.php';
require_once $root . '/admin/modules/cookies/includes/class-cookie.php';
require_once $root . '/admin/modules/cookies/includes/class-cookie-controller.php';

use FazCookie\Admin\Modules\Cookies\Includes\Cookie;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;

$tests_run = 0;
$failed    = 0;

function roundtrip_eq( $actual, $expected, $label ) {
	global $tests_run, $failed;
	$tests_run++;
	if ( $actual === $expected ) {
		echo "  PASS  {$label}\n";
		return;
	}
	$failed++;
	echo "  FAIL  {$label}\n        expected: " . var_export( $expected, true ) . "\n        actual:   " . var_export( $actual, true ) . "\n";
}

/**
 * Build a cookie row exactly as the table stores one.
 *
 * @param array $description Multilingual description map.
 * @param array $duration    Multilingual duration map.
 * @return stdClass
 */
function roundtrip_row( array $description, array $duration ) {
	$row                = new stdClass();
	$row->cookie_id     = 214;
	$row->name          = '_ga';
	$row->slug          = '_ga';
	$row->description   = wp_json_encode( $description );
	$row->duration      = wp_json_encode( $duration );
	$row->domain        = '.example.test';
	$row->category      = 3;
	$row->type          = 0;
	$row->discovered    = 1;
	$row->url_pattern   = '';
	$row->meta          = '{}';
	$row->date_created  = '2026-08-06 00:00:00';
	$row->date_modified = '2026-08-06 00:00:00';
	return $row;
}

/**
 * Read a row, save it back through the real controller, return the row the
 * database would now hold.
 *
 * @param stdClass $row Stored row.
 * @return stdClass
 */
function roundtrip_save( stdClass $row ) {
	global $wpdb;
	$cookie = new Cookie( $row );
	Cookie_Controller::get_instance()->update_item( $cookie );
	$saved              = clone $row;
	$saved->description = $wpdb->last_update['description'];
	$saved->duration    = $wpdb->last_update['duration'];
	return $saved;
}

global $wpdb;
$wpdb                                      = new Faz_Roundtrip_WPDB();
$GLOBALS['faz_roundtrip_default_language'] = 'en';
$GLOBALS['faz_roundtrip_selected_languages'] = array( 'en', 'it' );

echo "\n== Cookie description round trip (read → save → re-read) ==\n\n";

$stock_en  = 'Google Analytics cookie used to distinguish users.';
$stock_it  = 'Cookie di Google Analytics utilizzato per distinguere gli utenti.';
$authored  = 'This cookie is set by our own Analytics installation, hosted in Frankfurt, and is deleted after 14 months per our retention policy.';

// -------------------------------------------------------------------------
// 1. Administrator wording. The row must come back out of the save exactly as
//    it went in — including the Italian slot, which must NOT acquire the
//    bundled sentence just because Italian is a selected language.
// -------------------------------------------------------------------------
$saved   = roundtrip_save( roundtrip_row( array( 'en' => $authored ), array( 'en' => '2 years' ) ) );
$persisted = json_decode( $saved->description, true );

roundtrip_eq( $persisted['en'], $authored, 'the administrator English description is written back verbatim' );
roundtrip_eq( isset( $persisted['it'] ) && false !== strpos( $persisted['it'], 'Google Analytics' ) && $persisted['it'] === $stock_it, false, 'the bundled Italian sentence is NOT persisted over authored wording' );
roundtrip_eq( $persisted['it'], '', 'the Italian read fallback is not persisted as authored data' );

// Re-read: the row the database now holds still shows the author's text.
$reread = new Cookie( $saved );
roundtrip_eq( $reread->get_description( 'en' ), $authored, 'the re-read row still carries the authored English' );
roundtrip_eq( $reread->get_description( 'it' ), $authored, 'and the re-read Italian is the author\'s text, not the catalogue' );

// -------------------------------------------------------------------------
// 2. Positive control. Stock bundled English is exactly the case the feature
//    exists for, and it must still translate on the way out — otherwise test 1
//    above would pass just as well with the whole fallback removed.
// -------------------------------------------------------------------------
$stock_saved     = roundtrip_save( roundtrip_row( array( 'en' => $stock_en ), array( 'en' => '2 years' ) ) );
$stock_persisted = json_decode( $stock_saved->description, true );
roundtrip_eq( $stock_persisted['en'], $stock_en, 'stock English is preserved' );
roundtrip_eq( $stock_persisted['it'], '', 'stock Italian remains a read-time fallback, not stored data' );
roundtrip_eq( ( new Cookie( $stock_saved ) )->get_description( 'it' ), $stock_it, 'stock English DOES translate to Italian on read' );
roundtrip_eq( ( new Cookie( $stock_saved ) )->get_duration()['it'], '2 anni', 'the duration translates on read without being persisted' );

// Changing the default and adding a language must not make that new language
// inherit a fallback that an earlier save materialised in the old locale.
$GLOBALS['faz_roundtrip_default_language']  = 'it';
$GLOBALS['faz_roundtrip_selected_languages'] = array( 'en', 'it', 'fr' );
$after_language_change = roundtrip_save( $stock_saved );
$changed_persisted     = json_decode( $after_language_change->description, true );
roundtrip_eq( $changed_persisted['it'], '', 'changing the default language does not materialise the old Italian fallback' );
roundtrip_eq( $changed_persisted['fr'], '', 'a newly selected language is stored empty until explicitly authored' );
$fr_read = ( new Cookie( $after_language_change ) )->get_description( 'fr' );
roundtrip_eq( '' !== $fr_read && $stock_it !== $fr_read, true, 'the new language resolves independently instead of inheriting Italian' );

// -------------------------------------------------------------------------
// 3. Idempotence. Two consecutive saves must be a fixed point: a drift that
//    only shows up on the second cycle is the same bug, arriving later.
// -------------------------------------------------------------------------
$GLOBALS['faz_roundtrip_default_language']   = 'en';
$GLOBALS['faz_roundtrip_selected_languages'] = array( 'en', 'it' );
$again = roundtrip_save( $saved );
roundtrip_eq( json_decode( $again->description, true ), $persisted, 'a second save of the authored row changes nothing' );
$stock_again = roundtrip_save( $stock_saved );
roundtrip_eq( json_decode( $stock_again->description, true ), $stock_persisted, 'a second save of the translated stock row changes nothing either' );

echo "\n" . ( $tests_run - $failed ) . "/{$tests_run} passed\n";
exit( 0 === $failed ? 0 : 1 );
