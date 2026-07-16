<?php
/**
 * Standalone regression tests for FlyingPress frontend optimisation exclusions.
 *
 * Subsystem: flyingpress-frontend-bootstrap
 *
 * The cache-service adapter is intentionally admin/REST scoped because it owns
 * purge hooks. These tests construct the real Frontend class and prove that the
 * public-page bootstrap independently wires FlyingPress v4 filters and the v5
 * runtime-config bridge for both plugin load orders.
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Settings\Includes {
	class Settings {}
}

namespace FazCookie\Admin\Modules\Gcm\Includes {
	class Gcm_Settings {}
}

namespace FazCookie\Frontend\Modules\Consent_Logger {
	class Consent_Logger {}
}

namespace FazCookie\Frontend\Modules\Banner_Rest {
	class Banner_Rest {}
}

namespace FazCookie\Includes {
	class Geolocation {}
	class Gvl {}
	class Known_Providers {}
	class Cookie_Table_Shortcode {}
	class Cookie_Policy_Shortcode {}
	class Do_Not_Sell_Shortcode {}
	class Cookie_Settings_Shortcode {}
}

namespace FazCookie\Frontend {
	class AMP_Consent {}
	class Translation_Compat {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['faz_test_filters']       = array();
	$GLOBALS['faz_test_actions']       = array();
	$GLOBALS['faz_test_actions_fired'] = array();

	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore
		$GLOBALS['faz_test_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
		return true;
	}

	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore
		$GLOBALS['faz_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
		return true;
	}

	function apply_filters( $hook, $value ) { // phpcs:ignore
		$args      = array_slice( func_get_args(), 2 );
		$callbacks = isset( $GLOBALS['faz_test_filters'][ $hook ] ) ? $GLOBALS['faz_test_filters'][ $hook ] : array();
		usort(
			$callbacks,
			static function ( $a, $b ) {
				return $a[1] <=> $b[1];
			}
		);
		foreach ( $callbacks as $entry ) {
			$call_args = array_slice( array_merge( array( $value ), $args ), 0, $entry[2] );
			$value     = call_user_func_array( $entry[0], $call_args );
		}
		return $value;
	}

	function do_action( $hook ) { // phpcs:ignore
		$args = array_slice( func_get_args(), 1 );
		$GLOBALS['faz_test_actions_fired'][ $hook ] = did_action( $hook ) + 1;
		$callbacks = isset( $GLOBALS['faz_test_actions'][ $hook ] ) ? $GLOBALS['faz_test_actions'][ $hook ] : array();
		usort(
			$callbacks,
			static function ( $a, $b ) {
				return $a[1] <=> $b[1];
			}
		);
		foreach ( $callbacks as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, $entry[2] ) );
		}
	}

	function did_action( $hook ) { // phpcs:ignore
		return isset( $GLOBALS['faz_test_actions_fired'][ $hook ] ) ? $GLOBALS['faz_test_actions_fired'][ $hook ] : 0;
	}

	function is_admin() { // phpcs:ignore
		return false;
	}

	// faz_is_front_end_request() gates the v5 reflection patch (front-end only,
	// so it never mutates FlyingPress's shared config on an AJAX/REST settings
	// save). Controllable via a global so the non-front-end path is testable.
	$GLOBALS['faz_test_is_frontend'] = true;
	function faz_is_front_end_request() { // phpcs:ignore
		return isset( $GLOBALS['faz_test_is_frontend'] ) ? (bool) $GLOBALS['faz_test_is_frontend'] : true;
	}

	$faz_pass = 0;
	$faz_fail = 0;
	function faz_fp_ok( $condition, $label ) { // phpcs:ignore
		global $faz_pass, $faz_fail;
		if ( $condition ) {
			++$faz_pass;
			echo "  [PASS] {$label}\n";
			return;
		}
		++$faz_fail;
		echo "  [FAIL] {$label}\n";
	}

	function faz_fp_filter_registered( $hook ) { // phpcs:ignore
		return ! empty( $GLOBALS['faz_test_filters'][ $hook ] );
	}

	function faz_fp_action_registered( $hook ) { // phpcs:ignore
		return ! empty( $GLOBALS['faz_test_actions'][ $hook ] );
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	echo "FlyingPress frontend bootstrap (issue #125)\n\n";

	// FAZ loads before FlyingPress: the real Frontend constructor must register
	// every compatibility seam before the FlyingPress classes exist.
	$frontend = new Frontend( 'faz-cookie-manager', 'test' );
	faz_fp_ok( ! class_exists( '\\FlyingPress\\Config' ), '01 fixture starts with FlyingPress not loaded' );
	foreach ( array(
		'flying_press_exclude_from_delay:js',
		'flying_press_exclude_from_defer:js',
		'flying_press_exclude_from_minify:js',
	) as $filter ) {
		faz_fp_ok( faz_fp_filter_registered( $filter ), '02 normal frontend registers ' . $filter );
	}
	faz_fp_ok( ! faz_fp_filter_registered( 'option_FLYING_PRESS_CONFIG' ), '03 saved FlyingPress option is never intercepted' );
	faz_fp_ok( faz_fp_action_registered( 'plugins_loaded' ), '04 both-load-orders runtime bridge registered' );
	faz_fp_ok( faz_fp_action_registered( 'flying_press_update_config:after' ), '05 settings-update runtime bridge registered' );

	$filtered = apply_filters(
		'flying_press_exclude_from_delay:js',
		array( 'existing', 'faz-cookie-manager' )
	);
	faz_fp_ok(
		array( 'existing', 'faz-cookie-manager', 'faz-fw' ) === $filtered,
		'06 v4 filter preserves order and does not duplicate keywords'
	);
	faz_fp_ok(
		array( 'faz-cookie-manager', 'faz-fw' ) === apply_filters( 'flying_press_exclude_from_minify:js', null ),
		'07 non-array v4/v5 filter seed is normalized safely'
	);

	$config = $frontend->flying_press_add_delay_exclusions_to_config(
		array(
			'js_delay'          => true,
			'js_delay_excludes' => array( 'user-rule' ),
		)
	);
	faz_fp_ok(
		array( 'user-rule', 'faz-cookie-manager', 'faz-fw' ) === $config['js_delay_excludes'],
		'08 runtime helper injects v5 delay keywords while preserving user rules'
	);
	faz_fp_ok(
		array() === $frontend->flying_press_add_delay_exclusions_to_config( array() ),
		'09 empty/unmigrated FlyingPress config is left untouched'
	);
	faz_fp_ok(
		'broken' === $frontend->flying_press_add_delay_exclusions_to_config( 'broken' ),
		'10 invalid FlyingPress config is left untouched'
	);

	// FlyingPress now loads and initializes its static config. plugins_loaded
	// must patch that already-loaded value without writing the option.
	eval( 'namespace FlyingPress; class Config { public static $config = array("js_delay" => true, "js_delay_excludes" => array("runtime-user-rule")); }' );
	do_action( 'plugins_loaded' );
	faz_fp_ok(
		array( 'runtime-user-rule', 'faz-cookie-manager', 'faz-fw' ) === \FlyingPress\Config::$config['js_delay_excludes'],
		'11 plugins_loaded patches FlyingPress-first runtime config'
	);

	// Mirror FlyingPress v5's real delay decision: it searches the full script
	// tag for a partial keyword from Config::$config['js_delay_excludes'].
	$v5_would_delay = static function ( $script_tag ) {
		foreach ( \FlyingPress\Config::$config['js_delay_excludes'] as $keyword ) {
			if ( false !== stripos( $script_tag, $keyword ) ) {
				return false;
			}
		}
		return true;
	};
	faz_fp_ok(
		false === $v5_would_delay( '<script id="faz-cookie-manager-js" src="/wp-content/plugins/faz-cookie-manager/frontend/js/script.min.js"></script>' ),
		'12 FlyingPress v5 leaves the main FAZ script executable at page load'
	);
	faz_fp_ok(
		false === $v5_would_delay( '<script id="faz-fw-gcm-js" src="/assets/gcm.js"></script>' ),
		'13 FlyingPress v5 leaves alternate faz-fw assets executable at page load'
	);
	faz_fp_ok(
		true === $v5_would_delay( '<script id="unrelated-js" src="/plugins/unrelated/app.js"></script>' ),
		'14 unrelated scripts remain eligible for FlyingPress delay'
	);

	// A FlyingPress settings update replaces the static config in the current
	// request; its documented after-action must reapply only the runtime merge.
	\FlyingPress\Config::$config = array(
		'js_delay'          => true,
		'js_delay_excludes' => array( 'after-save-rule' ),
	);
	do_action( 'flying_press_update_config:after', \FlyingPress\Config::$config );
	faz_fp_ok(
		array( 'after-save-rule', 'faz-cookie-manager', 'faz-fw' ) === \FlyingPress\Config::$config['js_delay_excludes'],
		'15 FlyingPress settings update cannot drop the current-request exclusions'
	);

	// If FlyingPress loaded before a late FAZ bootstrap and plugins_loaded has
	// already fired, the constructor applies the bridge immediately.
	$GLOBALS['faz_test_filters'] = array();
	$GLOBALS['faz_test_actions'] = array();
	\FlyingPress\Config::$config = array(
		'js_delay'          => true,
		'js_delay_excludes' => array( 'late-load-rule' ),
	);
	$late_frontend = new Frontend( 'faz-cookie-manager', 'test' );
	faz_fp_ok(
		array( 'late-load-rule', 'faz-cookie-manager', 'faz-fw' ) === \FlyingPress\Config::$config['js_delay_excludes'],
		'16 late FAZ bootstrap patches an already-loaded FlyingPress config immediately'
	);
	unset( $late_frontend );

	// The existing developer opt-out must disable every optimization mutation,
	// while leaving the independent country-dependent page-cache veto active.
	$GLOBALS['faz_test_filters'] = array(
		'faz_auto_exclude_cache_plugins' => array(
			array( '__return_false', 10, 1 ),
		),
	);
	$GLOBALS['faz_test_actions'] = array();
	\FlyingPress\Config::$config = array(
		'js_delay'          => true,
		'js_delay_excludes' => array( 'opt-out-rule' ),
	);
	if ( ! function_exists( '__return_false' ) ) {
		function __return_false() { // phpcs:ignore
			return false;
		}
	}
	$opted_out = new Frontend( 'faz-cookie-manager', 'test' );
	faz_fp_ok( ! faz_fp_filter_registered( 'flying_press_exclude_from_delay:js' ), '17 developer opt-out removes delay exclusion filter' );
	faz_fp_ok( ! faz_fp_action_registered( 'plugins_loaded' ), '18 developer opt-out removes v5 bootstrap mutation' );
	faz_fp_ok( ! faz_fp_action_registered( 'flying_press_update_config:after' ), '19 developer opt-out removes v5 runtime mutation' );
	faz_fp_ok(
		array( 'opt-out-rule' ) === \FlyingPress\Config::$config['js_delay_excludes'],
		'20 developer opt-out leaves FlyingPress runtime config unchanged'
	);
	faz_fp_ok( faz_fp_filter_registered( 'flying_press_is_cacheable' ), '21 independent country cache-safety bridge remains active' );
	unset( $frontend, $opted_out );

	// The v5 reflection patch must run on genuine front-end renders ONLY. Frontend
	// is also constructed on AJAX/REST (Consent_Logger REST routes), where this
	// runs at plugins_loaded BEFORE a FlyingPress settings save — mutating the
	// shared static config there risks the keywords leaking into the persisted
	// option. faz_is_front_end_request() === false must make it a no-op.
	$GLOBALS['faz_test_filters']     = array();
	$GLOBALS['faz_test_actions']     = array();
	$GLOBALS['faz_test_is_frontend'] = false;
	\FlyingPress\Config::$config     = array(
		'js_delay'          => true,
		'js_delay_excludes' => array( 'admin-request-rule' ),
	);
	$admin_frontend = new Frontend( 'faz-cookie-manager', 'test' );
	$admin_frontend->flying_press_apply_runtime_delay_exclusions();
	faz_fp_ok(
		array( 'admin-request-rule' ) === \FlyingPress\Config::$config['js_delay_excludes'],
		'22 v5 runtime bridge does not mutate FlyingPress config on admin/AJAX/REST requests'
	);
	$GLOBALS['faz_test_is_frontend'] = true;
	unset( $admin_frontend );

	echo "\n" . ( 0 === $faz_fail ? "ALL PASS ({$faz_pass})\n" : "FAILED: {$faz_fail}, passed: {$faz_pass}\n" );
	exit( 0 === $faz_fail ? 0 : 1 );
}
