<?php
/**
 * Standalone tests: page caches are purged by writes that happen outside the
 * admin/REST requests that load the admin modules.
 *
 * Subsystem: cache-cron-cli-purge
 *
 * Covers:
 *   - Admin::maybe_load_modules() loads the cache module (purge listeners
 *     only) on WP-Cron and WP-CLI, and NOT on an ordinary page request;
 *   - the module is registered once even when rest_api_init later runs
 *     load_modules() in the same process;
 *   - a scheduled scan's faz_after_create_cookie then reaches FlyingPress;
 *   - the IAB vendor selection save, the manual GVL update and the weekly
 *     GVL cron refresh fire faz_purge_page_cache, which every page-cache
 *     adapter listens to; a failed download does not.
 *
 * FlyingPress is a counter-recording stub; WordPress is stubbed in-process.
 *
 * Run from project root:
 *   php tests/unit/test-cache-cron-cli-purge-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FlyingPress {
	class Purge {
		public static $pages = 0;
		public static function purge_pages() {
			++self::$pages;
		}
	}
}

namespace FazCookie\Includes {
	abstract class Rest_Controller {}
	class Activator {
		public static function maybe_check_unmatched_vendors() {}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['faz_actions']       = array();
	$GLOBALS['faz_filters']       = array();
	$GLOBALS['faz_fired']         = array( 'plugins_loaded' => 1 );
	$GLOBALS['faz_options']       = array();
	$GLOBALS['faz_is_cron']       = false;
	$GLOBALS['faz_http_response'] = null;

	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore
		$GLOBALS['faz_actions'][ $hook ][] = array( $callback, $accepted_args );
		return true;
	}
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore
		$GLOBALS['faz_filters'][ $hook ][] = array( $callback, $accepted_args );
		return true;
	}
	function apply_filters( $hook, $value ) { // phpcs:ignore
		$args = array_slice( func_get_args(), 1 );
		foreach ( $GLOBALS['faz_filters'][ $hook ] ?? array() as $entry ) {
			$args[0] = call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
		}
		return $args[0];
	}
	function do_action( $hook ) { // phpcs:ignore
		$args = array_slice( func_get_args(), 1 );
		$GLOBALS['faz_fired'][ $hook ] = ( $GLOBALS['faz_fired'][ $hook ] ?? 0 ) + 1;
		foreach ( $GLOBALS['faz_actions'][ $hook ] ?? array() as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
		}
	}
	function did_action( $hook ) { // phpcs:ignore
		return $GLOBALS['faz_fired'][ $hook ] ?? 0;
	}
	function has_action( $hook ) { // phpcs:ignore
		return ! empty( $GLOBALS['faz_actions'][ $hook ] );
	}
	function wp_doing_ajax() { // phpcs:ignore
		return false;
	}
	function wp_doing_cron() { // phpcs:ignore
		return (bool) $GLOBALS['faz_is_cron'];
	}
	function get_option( $name, $default = false ) { // phpcs:ignore
		return array_key_exists( $name, $GLOBALS['faz_options'] ) ? $GLOBALS['faz_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) { // phpcs:ignore
		$GLOBALS['faz_options'][ $name ] = $value;
		return true;
	}
	function absint( $value ) { // phpcs:ignore
		return abs( (int) $value );
	}
	function __( $text ) { // phpcs:ignore
		return $text;
	}
	function current_time( $type ) { // phpcs:ignore
		return '2026-10-16 00:00:00';
	}
	function faz_default_language() { // phpcs:ignore
		return 'en';
	}
	function faz_clear_banner_template_cache() { // phpcs:ignore
	}
	function is_wp_error( $thing ) { // phpcs:ignore
		return false;
	}
	function wp_remote_get( $url, $args = array() ) { // phpcs:ignore
		return $GLOBALS['faz_http_response'];
	}
	function wp_remote_retrieve_response_code( $response ) { // phpcs:ignore
		return $response['code'];
	}
	function wp_remote_retrieve_body( $response ) { // phpcs:ignore
		return $response['body'];
	}
	function wp_upload_dir() { // phpcs:ignore
		return array( 'basedir' => sys_get_temp_dir() );
	}
	function trailingslashit( $path ) { // phpcs:ignore
		return rtrim( $path, '/' ) . '/';
	}
	function wp_mkdir_p( $dir ) { // phpcs:ignore
		return false; // No disk writes from a unit test: the copy on disk "fails".
	}
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}

	// The GVL code logs its download failures; keep them out of the report.
	ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky

	$faz_root = dirname( __DIR__, 2 );
	require_once $faz_root . '/includes/class-modules.php';
	require_once $faz_root . '/admin/modules/cache/services/class-services.php';
	foreach ( glob( $faz_root . '/admin/modules/cache/services/class-*.php' ) as $faz_service_file ) {
		require_once $faz_service_file;
	}
	require_once $faz_root . '/admin/modules/cache/class-cache.php';
	require_once $faz_root . '/admin/class-admin.php';
	require_once $faz_root . '/includes/class-gvl.php';
	require_once $faz_root . '/admin/modules/gvl/api/class-api.php';

	use FazCookie\Admin\Admin;
	use FazCookie\Admin\Modules\Cache\Services\Flying_Press;

	$faz_pass = 0;
	$faz_fail = 0;
	function faz_cc_ok( $condition, $label ) { // phpcs:ignore
		global $faz_pass, $faz_fail;
		if ( $condition ) {
			++$faz_pass;
			echo "  [PASS] {$label}\n";
			return;
		}
		++$faz_fail;
		echo "  [FAIL] {$label}\n";
	}

	/** Number of FlyingPress adapters subscribed to a hook. */
	function faz_cc_flying_press_listeners( $hook ) { // phpcs:ignore
		$count = 0;
		foreach ( $GLOBALS['faz_actions'][ $hook ] ?? array() as $entry ) {
			if ( is_array( $entry[0] ) && $entry[0][0] instanceof Flying_Press ) {
				++$count;
			}
		}
		return $count;
	}

	/** Run Admin::maybe_load_modules() on an Admin built without its constructor. */
	function faz_cc_boot_admin() { // phpcs:ignore
		$reflection = new ReflectionClass( Admin::class );
		$admin      = $reflection->newInstanceWithoutConstructor();
		$modules    = $reflection->getProperty( 'modules' );
		$modules->setAccessible( true );
		$modules->setValue( null, $admin->get_default_modules() );
		$method = $reflection->getMethod( 'maybe_load_modules' );
		$method->setAccessible( true );
		$method->invoke( $admin );
		return $admin;
	}

	function faz_cc_reset_cache_flag() { // phpcs:ignore
		if ( property_exists( Admin::class, 'cache_module_loaded' ) ) {
			$flag = new ReflectionProperty( Admin::class, 'cache_module_loaded' );
			$flag->setAccessible( true );
			$flag->setValue( null, false );
		}
	}

	echo "Page-cache purge outside admin requests\n\n";

	// ---------------------------------------------------------------------
	// Module loading per request type.
	// ---------------------------------------------------------------------
	faz_cc_boot_admin();
	faz_cc_ok( 0 === faz_cc_flying_press_listeners( 'faz_after_create_cookie' ), '01 an ordinary page request does not load the cache module' );
	faz_cc_ok(
		! empty( $GLOBALS['faz_filters']['varnish_http_purge_events'] ) && ! empty( $GLOBALS['faz_filters']['varnish_http_purge_events_full'] ),
		'01a every request adds the FAZ purge action to Proxy Cache Purge before init'
	);

	$GLOBALS['faz_actions'] = array();
	$GLOBALS['faz_is_cron'] = true;
	$admin                  = faz_cc_boot_admin();
	faz_cc_ok( 1 === faz_cc_flying_press_listeners( 'faz_after_create_cookie' ), '02 WP-Cron loads the cache module, so a scheduled scan has a listener' );
	do_action( 'faz_after_create_cookie' );
	faz_cc_ok( 1 === \FlyingPress\Purge::$pages, '03 a cookie created by the scheduled scan purges FlyingPress' );
	faz_cc_boot_admin();
	do_action( 'rest_api_init' );
	faz_cc_ok( 1 === faz_cc_flying_press_listeners( 'faz_after_create_cookie' ), '04 the module is registered once even if load_modules() runs later in the request' );
	$GLOBALS['faz_is_cron'] = false;

	$GLOBALS['faz_actions'] = array();
	faz_cc_reset_cache_flag();
	define( 'WP_CLI', true );
	faz_cc_boot_admin();
	faz_cc_ok( 1 === faz_cc_flying_press_listeners( 'faz_after_update_banner' ), '05 WP-CLI loads the cache module too' );

	// ---------------------------------------------------------------------
	// Writes that change the page without a faz_after_* hook. A fresh adapter
	// is constructed directly so these checks do not depend on 01-05.
	// ---------------------------------------------------------------------
	$GLOBALS['faz_actions'] = array();
	new Flying_Press();
	faz_cc_ok( 1 === faz_cc_flying_press_listeners( 'faz_purge_page_cache' ), '06 page-cache adapters listen to faz_purge_page_cache' );

	$gvl_body = json_encode(
		array(
			'vendorListVersion' => 150,
			'vendors'           => array(
				'1' => array( 'id' => 1, 'name' => 'Vendor One' ),
				'2' => array( 'id' => 2, 'name' => 'Vendor Two' ),
			),
		)
	);
	$GLOBALS['faz_options']['faz_gvl_data'] = json_decode( $gvl_body, true );

	$request = new class() {
		public function get_json_params() {
			return array( 'vendor_ids' => array( 2, 1, 999 ) );
		}
	};
	$before   = \FlyingPress\Purge::$pages;
	$response = ( new \FazCookie\Admin\Modules\Gvl\Api\Api() )->save_selected( $request );
	faz_cc_ok( 200 === $response->status && array( 1, 2 ) === $GLOBALS['faz_options']['faz_gvl_selected_vendors'], '07 precondition: the vendor selection is saved' );
	faz_cc_ok( $before + 1 === \FlyingPress\Purge::$pages, '08 saving the IAB vendor selection purges the page cache' );

	$GLOBALS['faz_options']['faz_settings'] = array( 'iab' => array( 'enabled' => true ) );
	$GLOBALS['faz_http_response']           = array( 'code' => 200, 'body' => $gvl_body );
	$before                                 = \FlyingPress\Purge::$pages;
	\FazCookie\Includes\Gvl::cron_update();
	faz_cc_ok( $before + 1 === \FlyingPress\Purge::$pages, '09 the weekly GVL refresh purges the page cache once the list is replaced' );

	// The IAB publishes a new list roughly weekly, but not always between two
	// ticks; download() returns the version it fetched whether or not it is the
	// one already stored, so purging on "non-zero" emptied every page cache on
	// the site once a week for nothing.
	$GLOBALS['faz_http_response'] = array( 'code' => 200, 'body' => $gvl_body );
	$before                       = \FlyingPress\Purge::$pages;
	\FazCookie\Includes\Gvl::cron_update();
	faz_cc_ok( $before === \FlyingPress\Purge::$pages, '09a a weekly refresh that fetches the version already stored purges nothing' );

	$GLOBALS['faz_http_response'] = array( 'code' => 200, 'body' => $gvl_body );
	$before                       = \FlyingPress\Purge::$pages;
	( new \FazCookie\Admin\Modules\Gvl\Api\Api() )->update_gvl( $request );
	faz_cc_ok( $before + 1 === \FlyingPress\Purge::$pages, '10 the manual "Update GVL" purges the page cache' );

	$GLOBALS['faz_http_response'] = array( 'code' => 200, 'body' => str_replace( '"vendorListVersion":150', '"vendorListVersion":151', $gvl_body ) );
	$before                       = \FlyingPress\Purge::$pages;
	\FazCookie\Includes\Gvl::cron_update();
	faz_cc_ok( $before + 1 === \FlyingPress\Purge::$pages, '09b a weekly refresh that brings a new version purges once' );

	$GLOBALS['faz_http_response'] = array( 'code' => 503, 'body' => '' );
	$before                       = \FlyingPress\Purge::$pages;
	\FazCookie\Includes\Gvl::cron_update();
	faz_cc_ok( $before === \FlyingPress\Purge::$pages, '11 a failed GVL download leaves the page cache alone' );

	echo "\n" . ( 0 === $faz_fail ? "ALL PASS ({$faz_pass})\n" : "FAILED: {$faz_fail}, passed: {$faz_pass}\n" );
	exit( 0 === $faz_fail ? 0 : 1 );
}
