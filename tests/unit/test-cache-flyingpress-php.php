<?php
/**
 * Standalone unit tests for the FlyingPress cache-service adapter
 * (issue #125: stale FlyingPress page cache kept serving the old banner
 * markup after a banner/cookie/settings save).
 *
 * Subsystem: cache-flyingpress
 *
 * Covers:
 *   - the module registry: 'flying_press' is a registered cache service and
 *     the loader's slug→class transform resolves it to Services\Flying_Press;
 *   - hook wiring: an active adapter subscribes clear_cache() to every
 *     faz_after_* CRUD hook (banner, cookie, category, settings, activate,
 *     faz_clear_cache);
 *   - purge behaviour: firing faz_after_update_banner purges the whole
 *     FlyingPress cache (Purge::purge_everything) and queues a re-warm
 *     (Preload::preload_cache), per the API documented at
 *     https://docs.flyingpress.com/en/articles/11406092-programmatically-purge-and-preload-cache
 *   - the $clear === false passthrough is a no-op;
 *   - an inactive service (FlyingPress absent) registers no hooks.
 *
 * FlyingPress itself is simulated by counter-recording stub classes in the
 * FlyingPress namespace; add_action is a recorder so the Services base-class
 * wiring can be asserted without WordPress.
 *
 * Run from project root:
 *   php tests/unit/test-cache-flyingpress-php.php
 *
 * Exit 0 = all pass; 1 = at least one failure.
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	// Parent of the Admin\Modules\Cache\Cache module; behaviour irrelevant here.
	class Modules {}
}

namespace FlyingPress {
	/** Counter-recording stand-in for FlyingPress\Purge. */
	class Purge {
		public static $everything = 0;
		public static $pages      = 0;
		public static function purge_everything() {
			++self::$everything;
		}
		public static function purge_pages() {
			++self::$pages;
		}
	}
	/** Counter-recording stand-in for FlyingPress\Preload. */
	class Preload {
		public static $preloads = 0;
		public static function preload_cache() {
			++self::$preloads;
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	// add_action recorder: hook => list of callbacks.
	$GLOBALS['faz_actions'] = array();
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore
			$GLOBALS['faz_actions'][ $hook ][] = $callback;
			return true;
		}
	}
	if ( ! function_exists( 'did_action' ) ) {
		function did_action( $hook ) { // phpcs:ignore
			return 1; // plugins_loaded already ran — the module loads services inline.
		}
	}

	$faz_root = dirname( __DIR__, 2 );
	require_once $faz_root . '/admin/modules/cache/services/class-services.php';
	require_once $faz_root . '/admin/modules/cache/services/class-flying-press.php';
	require_once $faz_root . '/admin/modules/cache/class-cache.php';

	use FazCookie\Admin\Modules\Cache\Cache as Cache_Module;
	use FazCookie\Admin\Modules\Cache\Services\Flying_Press;

	// ---------- Assertion harness ----------

	$faz_pass = 0;
	$faz_fail = 0;
	function faz_ok( $cond, $label ) { // phpcs:ignore
		global $faz_pass, $faz_fail;
		if ( $cond ) {
			++$faz_pass;
			echo "  [PASS] $label\n";
		} else {
			++$faz_fail;
			echo "  [FAIL] $label\n";
		}
	}

	echo "FlyingPress cache-service adapter (issue #125)\n\n";

	// ---------------------------------------------------------------------
	// Registry + loader slug→class resolution.
	// ---------------------------------------------------------------------
	$module   = new Cache_Module();
	$services = $module->get_services();
	faz_ok( in_array( 'flying_press', $services, true ), '01 flying_press is a registered cache service' );

	// Mirror load_services()' transform exactly: explode('_') → ucfirst each
	// → implode('_') → prefix namespace.
	$parts = array_map( 'ucfirst', explode( '_', 'flying_press' ) );
	$class = 'FazCookie\\Admin\\Modules\\Cache\\Services\\' . ucfirst( implode( '_', $parts ) );
	faz_ok( class_exists( $class ), '02 loader transform resolves flying_press to an existing class' );
	faz_ok( Flying_Press::class === $class, '03 resolved class is Services\\Flying_Press' );

	// ---------------------------------------------------------------------
	// Hook wiring on construction (FlyingPress present → is_active() true).
	// ---------------------------------------------------------------------
	$GLOBALS['faz_actions'] = array();
	$adapter                = new Flying_Press();
	faz_ok( $adapter->is_active(), '04 is_active() detects FlyingPress\\Purge' );

	$expected_hooks = array(
		'faz_after_update_banner',
		'faz_after_update_cookie',
		'faz_after_create_cookie',
		'faz_after_delete_cookie',
		'faz_after_update_cookie_category',
		'faz_after_delete_cookie_category',
		'faz_after_update_settings',
		'faz_after_activate',
		'faz_clear_cache',
	);
	$missing = array_diff( $expected_hooks, array_keys( $GLOBALS['faz_actions'] ) );
	faz_ok( array() === $missing, '05 clear_cache subscribed to every faz CRUD/purge hook' );

	// ---------------------------------------------------------------------
	// Purge behaviour when a banner is saved.
	// ---------------------------------------------------------------------
	foreach ( $GLOBALS['faz_actions']['faz_after_update_banner'] as $cb ) {
		call_user_func( $cb );
	}
	faz_ok( 1 === \FlyingPress\Purge::$everything, '06 banner save fires exactly one full FlyingPress purge' );
	faz_ok( 0 === \FlyingPress\Purge::$pages, '07 purge_pages() fallback not used when purge_everything() exists' );
	faz_ok( 1 === \FlyingPress\Preload::$preloads, '08 purged cache is queued for re-warm (preload_cache)' );

	// $clear === false passthrough (hook arg) must be a no-op.
	$adapter->clear_cache( false );
	faz_ok(
		1 === \FlyingPress\Purge::$everything && 1 === \FlyingPress\Preload::$preloads,
		'09 clear_cache(false) is a no-op (no purge, no preload)'
	);

	// Explicit call purges again (idempotent counters advance by one).
	$adapter->clear_cache();
	faz_ok(
		2 === \FlyingPress\Purge::$everything && 2 === \FlyingPress\Preload::$preloads,
		'10 clear_cache() purges and re-warms once per invocation'
	);

	// ---------------------------------------------------------------------
	// Inactive service (FlyingPress absent) must not register hooks.
	// ---------------------------------------------------------------------
	$GLOBALS['faz_actions'] = array();
	$inactive               = new class() extends Flying_Press {
		public function is_active() {
			return false; // Simulates class_exists('\FlyingPress\Purge') === false.
		}
	};
	faz_ok( array() === $GLOBALS['faz_actions'], '11 inactive adapter registers no hooks (Services base-class gate)' );
	unset( $inactive );

	// ---------------------------------------------------------------------
	// The real module loader path instantiates the adapter (and therefore
	// wires the hooks) via get_services()+class_exists, exactly once.
	// ---------------------------------------------------------------------
	$GLOBALS['faz_actions'] = array();
	$module->load_services_once();
	$wired = isset( $GLOBALS['faz_actions']['faz_after_update_banner'] ) ? count( $GLOBALS['faz_actions']['faz_after_update_banner'] ) : 0;
	faz_ok( 1 === $wired, '12 module load_services() wires the FlyingPress adapter exactly once' );
	$module->load_services_once();
	$wired = count( $GLOBALS['faz_actions']['faz_after_update_banner'] );
	faz_ok( 1 === $wired, '13 double init stays idempotent ($loaded guard)' );

	// ---------- Result ----------
	echo "\n" . ( 0 === $faz_fail ? "ALL PASS ($faz_pass)\n" : "FAILED: $faz_fail, passed: $faz_pass\n" );
	exit( 0 === $faz_fail ? 0 : 1 );
}
