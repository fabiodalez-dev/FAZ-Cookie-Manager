<?php
/**
 * Production scanner bulk-write regression tests.
 *
 * Proves that successful imports invalidate once, and that a database failure
 * is not counted as a created cookie while the finally block restores cache
 * invalidation and flushes rows inserted before the failure.
 */

namespace FazCookie\Includes {
	class Base_Controller {
		public static $suspended = false;
		public static function suspend_cache_invalidation() { self::$suspended = true; }
		public static function resume_cache_invalidation() { self::$suspended = false; }
	}
	class Cookie_Definitions {
		public static function get_instance() { return new self(); }
		public function lookup( $name ) { return false; }
	}
}

namespace FazCookie\Admin\Modules\Scanner\Includes {
	class Scanner_Logger {
		public static function get_instance() { return new self(); }
		public function log( $message, $context = null ) {}
	}
	class Cookie_Database {
		public static function lookup( $name ) {
			return array(
				'category'    => 'necessary',
				'description' => 'Known cookie',
				'duration'    => 'session',
			);
		}
	}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Cookie {
		public $data = array();
		public function __construct( $row = null ) { $this->data = (array) $row; }
		public function __call( $name, $arguments ) { $this->data[ substr( $name, 4 ) ] = $arguments[0]; }
	}
	class Cookie_Controller {
		public static $rows = array();
		public static $fail_update = false;
		public static $calls = 0;
		public static $fail_on = 0;
		public static $flushes = 0;
		public static function get_instance() { return new self(); }
		public function get_item_from_db() { return self::$rows; }
		public function update_item( $cookie ) {
			if ( self::$fail_update ) { return false; }
			foreach ( self::$rows as $i => $row ) {
				if ( $row->name === $cookie->data['name'] ) { self::$rows[$i] = (object) $cookie->data; }
			}
			return 1;
		}
		public function create_item( $cookie ) {
			++self::$calls;
			if ( self::$calls === self::$fail_on ) { return false; }
			self::$rows[] = (object) $cookie->data;
		}
		public function delete_cache() { ++self::$flushes; }
	}
	class Category_Controller {
		public static $flushes = 0;
		public static function get_instance() { return new self(); }
		public function get_items() {
			return array( (object) array( 'slug' => 'necessary', 'category_id' => 1 ) );
		}
		public function delete_cache() { ++self::$flushes; }
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	$GLOBALS['faz_scanner_actions'] = array();
	function faz_default_language() { return 'en'; }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_title( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $value ) ); }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
	$GLOBALS['opts'] = array();
	$GLOBALS['purge_visibility'] = array();
	function get_option( $k, $d = false ) { return $GLOBALS['opts'][$k] ?? $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][$k] = $v; return true; }
	function delete_option( $k ) { unset($GLOBALS['opts'][$k]); return true; }
	function absint( $v ) { return abs((int) $v); }
	function do_action( $hook ) {
		$GLOBALS['faz_scanner_actions'][] = $hook;
		if ( 'faz_clear_cache' === $hook ) {
			$GLOBALS['purge_visibility'][] = \FazCookie\Frontend\Frontend::is_declaration_suppressed('_lscache_vary');
		}
	}

	require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
	use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;
	use FazCookie\Admin\Modules\Scanner\Includes\Controller;
	use FazCookie\Includes\Base_Controller;

	$run = 0;
	$fail = 0;
	function scanner_ok( $condition, $label ) {
		global $run, $fail;
		++$run;
		if ( $condition ) {
			echo "PASS: {$label}\n";
			return;
		}
		++$fail;
		echo "FAIL: {$label}\n";
	}

	$rows = array(
		array( 'name' => 'cookie-a' ),
		array( 'name' => 'cookie-b' ),
	);
	$controller = new Controller();
	$count      = $controller->save_cookies( $rows );
	scanner_ok( 2 === $count, 'successful bulk import returns the number of persisted rows' );
	scanner_ok( 1 === Cookie_Controller::$flushes && 1 === Category_Controller::$flushes, 'successful batch flushes each cache once' );
	scanner_ok( array( 'faz_after_create_cookie' ) === $GLOBALS['faz_scanner_actions'], 'successful batch fires the dataset action once' );
	scanner_ok( false === Base_Controller::$suspended, 'successful batch restores invalidation state' );

	Cookie_Controller::$calls   = 0;
	Cookie_Controller::$rows    = array();
	Cookie_Controller::$fail_on = 2;
	$GLOBALS['faz_scanner_actions'] = array();
	$threw = false;
	try {
		$controller->save_cookies( $rows );
	} catch ( RuntimeException $e ) {
		$threw = true;
	}
	scanner_ok( $threw, 'database rejection is surfaced instead of being counted as CREATED' );
	scanner_ok( false === Base_Controller::$suspended, 'failure path restores invalidation state' );
	scanner_ok( 2 === Cookie_Controller::$flushes && 2 === Category_Controller::$flushes, 'failure path flushes rows written before the error' );
	scanner_ok( array( 'faz_after_create_cookie' ) === $GLOBALS['faz_scanner_actions'], 'partial batch still fires one dataset invalidation action' );

	// Exercise the declaration with the real save_cookies(), not a replacement
	// that only records arguments and cannot see discovered=true or skipped INSERTs.
	Cookie_Controller::$fail_on = 0;
	Cookie_Controller::$rows = array();
	$controller->remember_set_aside_cookies(array(array('name'=>'_lscache_vary', 'domain'=>'example.test', 'duration'=>'2 days')));
	$controller->declare_set_aside_cookie('_lscache_vary');
	scanner_ok(false === Cookie_Controller::$rows[0]->discovered, 'new declaration is manual');
	$controller->record_scan_observations(array(), true);
	$controller->record_scan_observations(array(), true);
	scanner_ok(array() === $controller->deletable_stale_keys(), 'two silent scans cannot make a manual declaration stale');

	// Promote a row a previous scan already inserted. Preserve curated data and
	// clear an accumulated miss tally before allowing any subsequent purge.
	Cookie_Controller::$rows[0]->discovered = true;
	Cookie_Controller::$rows[0]->description = 'Curated description';
	Cookie_Controller::$rows[0]->category = 42;
	$controller->record_scan_observations(array(), true);
	$controller->record_scan_observations(array(), true);
	delete_option(\FazCookie\Frontend\Frontend::DECLARED_INTERNAL_OPTION);
	\FazCookie\Frontend\Frontend::flush_declared_internal_cache();
	$controller->remember_set_aside_cookies(array(array('name'=>'_lscache_vary', 'domain'=>'wrong.test', 'duration'=>'session')));
	$GLOBALS['faz_scanner_actions'] = array();
	$GLOBALS['purge_visibility'] = array();
	$controller->declare_set_aside_cookie('_lscache_vary');
	scanner_ok(count(Cookie_Controller::$rows) === 1 && false === Cookie_Controller::$rows[0]->discovered, 'existing discovery is promoted without duplication');
	scanner_ok('Curated description' === Cookie_Controller::$rows[0]->description && 42 === Cookie_Controller::$rows[0]->category && 'example.test' === Cookie_Controller::$rows[0]->domain, 'promotion preserves the curated catalogue row');
	scanner_ok(array() === $controller->deletable_stale_keys(), 'promotion clears old scan misses');
	scanner_ok(array(false) === $GLOBALS['purge_visibility'], 'existing-row declaration purges after lifting visibility suppression');
	scanner_ok(!in_array('faz_after_create_cookie', $GLOBALS['faz_scanner_actions'], true), 'existing-row purge does not depend on a create event');
	$controller->record_scan_observations(array(), true);
	$controller->record_scan_observations(array(), true);
	scanner_ok(array() === $controller->deletable_stale_keys(), 'promoted discovery stays protected in subsequent scans');

	Cookie_Controller::$rows[0]->discovered = true;
	Cookie_Controller::$fail_update = true;
	$controller->remember_set_aside_cookies(array(array('name'=>'_lscache_vary')));
	$threw = false;
	try { $controller->declare_set_aside_cookie('_lscache_vary'); } catch (RuntimeException $e) { $threw = true; }
	scanner_ok($threw && count($controller->set_aside_cookies()) === 1, 'failed promotion retains the pending decision for retry');

	$controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php' );
	$api_source        = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/scanner/api/class-api.php' );
	$cookies_api_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/cookies/api/class-cookies-api.php' );
	scanner_ok( false !== strpos( $controller_source, "record_scan_failure( 'browser'" ), 'browser import failures are recorded before they leave the controller' );
	scanner_ok( false !== strpos( $controller_source, "record_scan_failure( 'local'" ), 'scheduled local scan failures are recorded at the cron boundary' );
	scanner_ok( false !== strpos( $controller_source, "record_scan_failure( 'httponly'" ), 'httpOnly scan failures are recorded at the cron boundary' );
	scanner_ok( false !== strpos( $api_source, "'faz_scan_import_failed'" ), 'REST import converts scanner exceptions to WP_Error' );
	scanner_ok( false !== strpos( $cookies_api_source, "'faz_service_registration_failed'" ), 'service registration converts scanner exceptions to WP_Error' );

	echo "Tests: {$run}, failed: {$fail}\n";
	if ( $fail ) {
		exit( 1 );
	}
	echo "ALL PASS\n";
}
