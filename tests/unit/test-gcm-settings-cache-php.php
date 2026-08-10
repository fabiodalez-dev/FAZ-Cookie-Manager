<?php
/** Production GCM settings memoization regression tests. */

namespace FazCookie\Includes {
	class Store {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	$GLOBALS['faz_gcm_option_reads'] = 0;
	$GLOBALS['faz_gcm_option']       = array( 'status' => false, 'wait_for_update' => 500 );
	$GLOBALS['faz_gcm_hook_value']   = null;

	function get_option( $name, $default = false ) {
		++$GLOBALS['faz_gcm_option_reads'];
		return $GLOBALS['faz_gcm_option'];
	}
	function update_option( $name, $value ) {
		$GLOBALS['faz_gcm_option'] = $value;
		return true;
	}
	function do_action( $hook, $value = null ) {
		if ( 'faz_after_update_settings' === $hook ) {
			$GLOBALS['faz_gcm_hook_value'] = $value;
		}
	}
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
	function absint( $value ) { return abs( (int) $value ); }
	function faz_sanitize_bool( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === strtolower( (string) $value );
	}
	function faz_sanitize_text( $value ) { return is_scalar( $value ) ? trim( (string) $value ) : ''; }

	require_once dirname( __DIR__, 2 ) . '/admin/modules/gcm/includes/class-gcm-settings.php';

	use FazCookie\Admin\Modules\Gcm\Includes\Gcm_Settings;

	$run = 0;
	$fail = 0;
	function gcm_cache_ok( $condition, $label ) {
		global $run, $fail;
		++$run;
		if ( $condition ) {
			echo "PASS: {$label}\n";
			return;
		}
		++$fail;
		echo "FAIL: {$label}\n";
	}

	$settings = new Gcm_Settings();
	gcm_cache_ok( false === $settings->get( 'status' ), 'first read returns sanitized stored status' );
	gcm_cache_ok( false === $settings->get( 'status' ), 'second read returns the memoized status' );
	gcm_cache_ok( 1 === $GLOBALS['faz_gcm_option_reads'], 'repeated get() calls read wp_options once per request' );

	$GLOBALS['faz_gcm_option']['status'] = true;
	gcm_cache_ok( false === $settings->get( 'status' ), 'external mutation cannot bypass an existing request memo' );
	Gcm_Settings::flush_runtime_cache();
	gcm_cache_ok( true === $settings->get( 'status' ), 'explicit flush makes an external migration write visible' );

	$settings->update( array( 'status' => false, 'wait_for_update' => 750 ) );
	gcm_cache_ok( false === $settings->get( 'status' ), 'model update invalidates and reloads the memo' );
	gcm_cache_ok( 750 === $settings->get( 'wait_for_update' ), 'updated numeric setting survives sanitization and cache reload' );
	gcm_cache_ok( false === $GLOBALS['faz_gcm_hook_value']['status'], 'after-update hook receives the sanitized persisted tree' );

	echo "Tests: {$run}, failed: {$fail}\n";
	if ( $fail ) {
		exit( 1 );
	}
	echo "ALL PASS\n";
}
