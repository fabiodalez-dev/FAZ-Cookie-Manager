<?php
/**
 * Standalone tests for the page-cache purge adapters' choice of API.
 *
 * Subsystem: cache-purge-adapters
 *
 * Covers:
 *   - W3 Total Cache: a save flushes the page cache through w3tc_flush_posts()
 *     (w3tc_pgcache_flush() is its deprecated alias), never every W3TC cache;
 *   - Cache Enabler: the 1.6.0+ `cache_enabler_clear_complete_cache` action is
 *     used when registered, the deprecated `ce_clear_cache` only otherwise, in
 *     the save adapter and in the upgrade purge alike, and a site exposing only
 *     the current action is detected at all;
 *   - the adapters added for Nginx Helper, Proxy Cache Purge, WP-Optimize and
 *     Super Page Cache: registry, detection, the documented purge entry point,
 *     the `$clear === false` passthrough, and the upgrade purge.
 *
 * The cache plugins are modelled by the public symbols they expose, with
 * counters.
 *
 * Run from project root:
 *   php tests/unit/test-cache-purge-adapters-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Modules {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['faz_actions'] = array();
	$GLOBALS['faz_calls']   = array();

	function faz_pa_count( $name ) { // phpcs:ignore
		$GLOBALS['faz_calls'][ $name ] = ( $GLOBALS['faz_calls'][ $name ] ?? 0 ) + 1;
	}
	function faz_pa_calls( $name ) { // phpcs:ignore
		return $GLOBALS['faz_calls'][ $name ] ?? 0;
	}
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore
		$GLOBALS['faz_actions'][ $hook ][] = $callback;
		return true;
	}
	$GLOBALS['faz_filters'] = array();
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore
		$GLOBALS['faz_filters'][ $hook ][] = $callback;
		return true;
	}
	function apply_filters( $hook, $value ) { // phpcs:ignore
		foreach ( $GLOBALS['faz_filters'][ $hook ] ?? array() as $callback ) {
			$value = call_user_func( $callback, $value );
		}
		return $value;
	}
	function has_action( $hook ) { // phpcs:ignore
		return ! empty( $GLOBALS['faz_actions'][ $hook ] );
	}
	function do_action( $hook ) { // phpcs:ignore
		foreach ( $GLOBALS['faz_actions'][ $hook ] ?? array() as $callback ) {
			call_user_func( $callback );
		}
	}

	// W3 Total Cache public API (w3-total-cache-api.php).
	function w3tc_flush_posts() { // phpcs:ignore
		faz_pa_count( 'w3tc_flush_posts' );
	}
	function w3tc_pgcache_flush() { // phpcs:ignore
		faz_pa_count( 'w3tc_pgcache_flush' );
		return w3tc_flush_posts();
	}
	function w3tc_flush_all() { // phpcs:ignore
		faz_pa_count( 'w3tc_flush_all' );
	}

	// Proxy Cache Purge, WP-Optimize and Super Page Cache public symbols.
	// The shape of the real class: a public execute_purge_no_id() that queues
	// the full-site `/?vhp-regex` URL, sent by execute_purge() on shutdown,
	// and one instance in the global $purger.
	class VarnishPurger {
		public $purge_urls = array();
		public function execute_purge_no_id( $post_id ) {
			faz_pa_count( 'vhp_execute_purge_no_id' );
			$this->purge_urls[] = 'http://example.test/?vhp-regex';
		}
	}
	$GLOBALS['purger'] = new VarnishPurger();
	class Faz_Pa_Wpo_Page_Cache {
		public function purge() {
			faz_pa_count( 'wpo_page_cache_purge' );
			return true;
		}
	}
	class Faz_Pa_Wpo {
		public function get_page_cache() {
			return new Faz_Pa_Wpo_Page_Cache();
		}
	}
	function WP_Optimize() { // phpcs:ignore
		return new Faz_Pa_Wpo();
	}
	function wpo_cache_flush() { // phpcs:ignore
		faz_pa_count( 'wpo_cache_flush' );
	}
	define( 'SWCFPC_PLUGIN_PATH', __DIR__ . '/' );

	$faz_root = dirname( __DIR__, 2 );
	require_once $faz_root . '/admin/modules/cache/services/class-services.php';
	foreach ( glob( $faz_root . '/admin/modules/cache/services/class-*.php' ) as $faz_service_file ) {
		require_once $faz_service_file;
	}
	require_once $faz_root . '/includes/class-activator.php';
	require_once $faz_root . '/admin/modules/cache/class-cache.php';

	use FazCookie\Admin\Modules\Cache\Services\Cache_Enabler;
	use FazCookie\Admin\Modules\Cache\Services\W3_Total_Cache;
	use FazCookie\Includes\Activator;

	$faz_pass = 0;
	$faz_fail = 0;
	function faz_pa_ok( $condition, $label ) { // phpcs:ignore
		global $faz_pass, $faz_fail;
		if ( $condition ) {
			++$faz_pass;
			echo "  [PASS] {$label}\n";
			return;
		}
		++$faz_fail;
		echo "  [FAIL] {$label}\n";
	}

	/** Reset hooks and counters, then register the given Cache Enabler actions. */
	function faz_pa_cache_enabler( array $hooks ) { // phpcs:ignore
		$GLOBALS['faz_actions'] = array();
		$GLOBALS['faz_calls']   = array();
		foreach ( $hooks as $hook ) {
			add_action(
				$hook,
				static function () use ( $hook ) {
					faz_pa_count( $hook );
				}
			);
		}
	}

	echo "Page-cache purge adapters\n\n";

	// --- W3 Total Cache -------------------------------------------------------
	$GLOBALS['faz_actions'] = array();
	$w3tc                   = new W3_Total_Cache();
	faz_pa_ok( $w3tc->is_active(), '01 W3TC is detected' );
	$w3tc->clear_cache();
	faz_pa_ok( 1 === faz_pa_calls( 'w3tc_flush_posts' ), '02 a save flushes the W3TC page cache' );
	faz_pa_ok( 0 === faz_pa_calls( 'w3tc_pgcache_flush' ), '03 the deprecated w3tc_pgcache_flush() alias is not used when w3tc_flush_posts() exists' );
	faz_pa_ok( 0 === faz_pa_calls( 'w3tc_flush_all' ), '04 a save does not flush every W3TC cache (minify, object, CDN)' );
	$w3tc->clear_cache( false );
	faz_pa_ok( 1 === faz_pa_calls( 'w3tc_flush_posts' ), '05 clear_cache(false) is a no-op' );
	$GLOBALS['faz_calls'] = array();
	Activator::purge_page_caches();
	faz_pa_ok( 1 === faz_pa_calls( 'w3tc_flush_all' ), '06 an upgrade flushes every W3TC cache (new static assets)' );

	// --- Cache Enabler --------------------------------------------------------
	faz_pa_cache_enabler( array( 'cache_enabler_clear_complete_cache', 'ce_clear_cache' ) );
	$ce = new Cache_Enabler();
	faz_pa_ok( $ce->is_active(), '07 Cache Enabler is detected' );
	$ce->clear_cache();
	faz_pa_ok( 1 === faz_pa_calls( 'cache_enabler_clear_complete_cache' ), '08 a save uses the current cache_enabler_clear_complete_cache action' );
	faz_pa_ok( 0 === faz_pa_calls( 'ce_clear_cache' ), '09 the deprecated ce_clear_cache is not fired as well (no double purge)' );

	faz_pa_cache_enabler( array( 'cache_enabler_clear_complete_cache' ) );
	faz_pa_ok( ( new Cache_Enabler() )->is_active(), '10 a build exposing only the current action is still detected' );

	faz_pa_cache_enabler( array( 'ce_clear_cache' ) );
	$legacy = new Cache_Enabler();
	$legacy->clear_cache();
	faz_pa_ok( 1 === faz_pa_calls( 'ce_clear_cache' ), '11 a pre-1.6.0 build is purged through ce_clear_cache' );

	faz_pa_cache_enabler( array( 'ce_clear_cache' ) );
	Activator::purge_page_caches();
	faz_pa_ok( 1 === faz_pa_calls( 'ce_clear_cache' ), '12 the upgrade purge reaches a pre-1.6.0 build too' );
	faz_pa_cache_enabler( array( 'cache_enabler_clear_complete_cache', 'ce_clear_cache' ) );
	Activator::purge_page_caches();
	faz_pa_ok(
		1 === faz_pa_calls( 'cache_enabler_clear_complete_cache' ) && 0 === faz_pa_calls( 'ce_clear_cache' ),
		'13 the upgrade purge uses the same API as the save adapter'
	);

	// --- Adapters added for Nginx Helper, Proxy Cache Purge, WP-Optimize,
	// Super Page Cache --------------------------------------------------------
	$services = ( new \FazCookie\Admin\Modules\Cache\Cache() )->get_services();
	$expected = array(
		'nginx_helper'      => 'Nginx_Helper',
		'proxy_cache_purge' => 'Proxy_Cache_Purge',
		'wp_optimize'       => 'Wp_Optimize',
		'super_page_cache'  => 'Super_Page_Cache',
	);
	foreach ( $expected as $slug => $class ) {
		// Mirror Cache::load_services(): explode('_') → ucfirst → implode('_').
		$resolved = 'FazCookie\\Admin\\Modules\\Cache\\Services\\' . implode( '_', array_map( 'ucfirst', explode( '_', $slug ) ) );
		faz_pa_ok(
			in_array( $slug, $services, true ) && class_exists( $resolved ) && $resolved === 'FazCookie\\Admin\\Modules\\Cache\\Services\\' . $class,
			"14 {$slug} is registered and resolves to Services\\{$class}"
		);
	}

	$adapter = function ( $class ) {
		$name = 'FazCookie\\Admin\\Modules\\Cache\\Services\\' . $class;
		return class_exists( $name ) ? new $name() : null;
	};

	// Nginx Helper.
	faz_pa_cache_enabler( array( 'rt_nginx_helper_purge_all' ) );
	$nginx = $adapter( 'Nginx_Helper' );
	faz_pa_ok( $nginx && $nginx->is_active(), '15 Nginx Helper is detected by its purge action' );
	faz_pa_ok( $nginx && has_action( 'faz_after_update_banner' ) && has_action( 'faz_purge_page_cache' ), '16 the Nginx Helper adapter listens to the FAZ purge hooks' );
	if ( $nginx ) {
		$nginx->clear_cache();
		$nginx->clear_cache( false );
	}
	faz_pa_ok( 1 === faz_pa_calls( 'rt_nginx_helper_purge_all' ), '17 a save fires rt_nginx_helper_purge_all once; clear_cache(false) does not' );

	// Proxy Cache Purge: the FAZ action joins both documented event lists, and
	// a save fires it.
	$GLOBALS['faz_filters'] = array();
	if ( class_exists( 'FazCookie\\Admin\\Modules\\Cache\\Services\\Proxy_Cache_Purge' ) ) {
		\FazCookie\Admin\Modules\Cache\Services\Proxy_Cache_Purge::register_purge_events();
	}
	$events = apply_filters( 'varnish_http_purge_events', array( 'save_post' ) );
	$full   = apply_filters( 'varnish_http_purge_events_full', array( 'switch_theme' ) );
	faz_pa_ok(
		array( 'save_post', 'faz_proxy_cache_purge_all' ) === $events && array( 'switch_theme', 'faz_proxy_cache_purge_all' ) === $full,
		'18 the FAZ purge action is added to varnish_http_purge_events and _events_full'
	);
	faz_pa_ok( 'x' === apply_filters( 'varnish_http_purge_events', 'x' ), '19 a non-array event list is passed through' );
	faz_pa_cache_enabler( array( 'faz_proxy_cache_purge_all' ) ); // What Proxy Cache Purge subscribes on init.
	$pcp = $adapter( 'Proxy_Cache_Purge' );
	faz_pa_ok( $pcp && $pcp->is_active(), '20 Proxy Cache Purge is detected by VarnishPurger' );
	if ( $pcp ) {
		$pcp->clear_cache();
		$pcp->clear_cache( false );
	}
	faz_pa_ok( 1 === faz_pa_calls( 'faz_proxy_cache_purge_all' ), '21 a save fires the full-purge event once' );

	// WP-Optimize.
	faz_pa_cache_enabler( array() );
	$wpo = $adapter( 'Wp_Optimize' );
	faz_pa_ok( $wpo && $wpo->is_active(), '22 WP-Optimize is detected' );
	if ( $wpo ) {
		$wpo->clear_cache();
	}
	faz_pa_ok(
		1 === faz_pa_calls( 'wpo_page_cache_purge' ) && 0 === faz_pa_calls( 'wpo_cache_flush' ),
		'23 a save purges the WP-Optimize page cache without flushing the object cache'
	);

	// Super Page Cache.
	faz_pa_cache_enabler( array() );
	$spc = $adapter( 'Super_Page_Cache' );
	faz_pa_ok( $spc && $spc->is_active(), '24 Super Page Cache is detected before its action is registered' );
	faz_pa_cache_enabler( array( 'swcfpc_purge_cache' ) );
	if ( $spc ) {
		$spc->clear_cache();
		$spc->clear_cache( false );
	}
	faz_pa_ok( 1 === faz_pa_calls( 'swcfpc_purge_cache' ), '25 a save fires swcfpc_purge_cache once' );

	// The upgrade purge reaches the two that expose a hook at init time.
	faz_pa_cache_enabler( array( 'rt_nginx_helper_purge_all', 'swcfpc_purge_cache' ) );
	Activator::purge_page_caches();
	faz_pa_ok(
		1 === faz_pa_calls( 'rt_nginx_helper_purge_all' ) && 1 === faz_pa_calls( 'swcfpc_purge_cache' ),
		'26 an upgrade purges Nginx Helper and Super Page Cache'
	);

	// Proxy Cache Purge on an upgrade. Activator::purge_page_caches() runs on
	// init priority 5, before VarnishPurger::init() (priority 10) has hooked
	// the event lists, so firing the FAZ event there reached nothing. Its
	// public execute_purge_no_id() only queues the full purge, which the
	// shutdown hook init() adds then sends; called after init it is the same.
	$GLOBALS['faz_calls'] = array();
	$GLOBALS['purger']->purge_urls = array();
	Activator::purge_page_caches();
	faz_pa_ok(
		1 === faz_pa_calls( 'vhp_execute_purge_no_id' ) && array( 'http://example.test/?vhp-regex' ) === $GLOBALS['purger']->purge_urls,
		'27 an upgrade queues a full Proxy Cache Purge through its own API'
	);

	echo "\n" . ( 0 === $faz_fail ? "ALL PASS ({$faz_pass})\n" : "FAILED: {$faz_fail}, passed: {$faz_pass}\n" );
	exit( 0 === $faz_fail ? 0 : 1 );
}
