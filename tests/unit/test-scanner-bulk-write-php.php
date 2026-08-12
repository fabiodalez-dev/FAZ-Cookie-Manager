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
		public function __call( $name, $arguments ) {}
	}
	class Cookie_Controller {
		public static $calls = 0;
		public static $fail_on = 0;
		public static $flushes = 0;
		public static function get_instance() { return new self(); }
		public function get_item_from_db() { return array(); }
		public function create_item( $cookie ) {
			++self::$calls;
			return self::$calls === self::$fail_on ? false : null;
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
	function do_action( $hook ) { $GLOBALS['faz_scanner_actions'][] = $hook; }

	require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';

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

	echo "Tests: {$run}, failed: {$fail}\n";
	if ( $fail ) {
		exit( 1 );
	}
	echo "ALL PASS\n";
}
