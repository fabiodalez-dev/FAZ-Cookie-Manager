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
 *     the current action is detected at all.
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
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore
		return true;
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

	$faz_root = dirname( __DIR__, 2 );
	require_once $faz_root . '/admin/modules/cache/services/class-services.php';
	foreach ( glob( $faz_root . '/admin/modules/cache/services/class-*.php' ) as $faz_service_file ) {
		require_once $faz_service_file;
	}
	require_once $faz_root . '/includes/class-activator.php';

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

	echo "\n" . ( 0 === $faz_fail ? "ALL PASS ({$faz_pass})\n" : "FAILED: {$faz_fail}, passed: {$faz_pass}\n" );
	exit( 0 === $faz_fail ? 0 : 1 );
}
