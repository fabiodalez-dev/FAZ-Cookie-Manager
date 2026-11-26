<?php
/**
 * Standalone unit tests: the `advanced_mode_gtm` setting (#385) survives every
 * path that validates GCM settings.
 *
 * Subsystem: gcm-advanced-gtm-settings-php
 *
 * Gcm_Settings::sanitize() walks the DEFAULTS, so a key missing from
 * get_defaults() is silently dropped on every save — admin page, REST and the
 * settings import, which all end in Gcm_Settings::update(). The REST
 * controller additionally copies only keys declared in its schema. Pinned:
 * the default is off, the value round-trips as a boolean through update(),
 * an install that never stored it reads off, the REST schema declares it,
 * and is_advanced_gtm_mode() is never true without Advanced mode (nor
 * without GCM itself).
 *
 * Run: php tests/unit/test-gcm-advanced-gtm-settings-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Store {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	$GLOBALS['faz_gtm_option'] = array( 'status' => true, 'advanced_mode' => true );

	function get_current_blog_id() {
		return 1;
	}
	function get_option( $name, $default = false ) {
		return null === $GLOBALS['faz_gtm_option'] ? $default : $GLOBALS['faz_gtm_option'];
	}
	function update_option( $name, $value ) {
		$GLOBALS['faz_gtm_option'] = $value;
		return true;
	}
	function do_action( $hook, $value = null ) {}
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, (array) $args );
	}
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
	function absint( $value ) {
		return abs( (int) $value );
	}
	// Same contract as includes/class-formatting.php: a non-scalar is never true.
	function faz_sanitize_bool( $value ) {
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( 'false', '0', 'no', 'off', 'null', 'undefined', '' ), true );
		}
		if ( ! is_scalar( $value ) && null !== $value ) {
			return false;
		}
		return (bool) $value;
	}
	function faz_sanitize_text( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-known-providers.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-google-tag.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/gcm/includes/class-gcm-settings.php';

	use FazCookie\Admin\Modules\Gcm\Includes\Gcm_Settings;

	$run  = 0;
	$fail = 0;
	function gtm_ok( $condition, $label ) {
		global $run, $fail;
		++$run;
		if ( $condition ) {
			echo "  PASS {$label}\n";
			return;
		}
		++$fail;
		echo "  FAIL {$label}\n";
	}
	/** A fresh read: the settings memo is per request. */
	function gtm_fresh() {
		Gcm_Settings::flush_runtime_cache();
		return new Gcm_Settings();
	}
	function gtm_adv( $s ) {
		return method_exists( $s, 'is_advanced_gtm_mode' ) ? $s->is_advanced_gtm_mode() : 'missing is_advanced_gtm_mode()';
	}

	$s = gtm_fresh();
	$defaults = $s->get_defaults();
	gtm_ok( array_key_exists( 'advanced_mode_gtm', $defaults ), 'the setting is in the defaults (or sanitize() drops it)' );
	gtm_ok( isset( $defaults['advanced_mode_gtm'] ) && false === $defaults['advanced_mode_gtm'], 'and defaults to off' );

	// An install that stored its settings before the key existed.
	gtm_ok( false === $s->get( 'advanced_mode_gtm' ), 'a legacy option without the key reads off' );
	gtm_ok( false === gtm_adv( $s ), 'and the container stays blocked' );

	// Round-trip through update(), the path the admin page, REST and the
	// settings import all end in.
	$s->update( array( 'advanced_mode_gtm' => '1' ) );
	gtm_ok( isset( $GLOBALS['faz_gtm_option']['advanced_mode_gtm'] ) && true === $GLOBALS['faz_gtm_option']['advanced_mode_gtm'], 'update() stores it as boolean true' );
	$s = gtm_fresh();
	gtm_ok( true === $s->get( 'advanced_mode_gtm' ), 'and it reads back on' );
	gtm_ok( true === gtm_adv( $s ), 'is_advanced_gtm_mode(): GCM on + Advanced on + opt-in on' );

	$s->update( array( 'advanced_mode_gtm' => 'false' ) );
	gtm_ok( false === gtm_fresh()->get( 'advanced_mode_gtm' ), 'the string "false" is stored as off' );
	// A malformed import (an array where a boolean belongs) must never read as
	// the opt-in. Gcm_Settings::sanitize() recurses into arrays for every key,
	// as it always has, so what is stored is not a boolean — but nothing that
	// reads it can turn it into an exemption.
	// (The recursion warns about the scalar default; that warning is not
	// what this file pins, so it is silenced for these reads only.)
	set_error_handler( function () { return true; }, E_WARNING );
	$s->update( array( 'advanced_mode_gtm' => array( 'x' ) ) );
	gtm_ok( true !== gtm_fresh()->get( 'advanced_mode_gtm' ), 'a non-scalar is not stored as on' );
	gtm_ok( false === gtm_adv( gtm_fresh() ), 'and does not enable the container' );
	$s->update( array( 'advanced_mode_gtm' => false ) );
	restore_error_handler();

	// The opt-in is an extension of Advanced mode, never a mode of its own.
	$s = gtm_fresh();
	$s->update( array( 'advanced_mode_gtm' => true, 'advanced_mode' => false ) );
	$s = gtm_fresh();
	gtm_ok( true === $s->get( 'advanced_mode_gtm' ), 'the stored opt-in is kept while Advanced mode is off' );
	gtm_ok( false === gtm_adv( $s ), 'but is_advanced_gtm_mode() is false without Advanced mode' );
	$s->update( array( 'advanced_mode' => true, 'status' => false ) );
	gtm_ok( false === gtm_adv( gtm_fresh() ), 'and false with GCM itself off' );

	// The REST controller copies only keys its schema declares.
	$api = file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/gcm/api/class-api.php' );
	$at    = strpos( $api, "'advanced_mode_gtm' => array(" );
	$block = false === $at ? '' : substr( $api, $at, (int) strpos( $api, "'context'", $at ) - $at );
	gtm_ok( 1 === preg_match( "/'type'\s*=>\s*'boolean'/", $block ), 'the REST schema declares advanced_mode_gtm as a boolean' );

	// The admin form binds the field.
	$view = file_get_contents( dirname( __DIR__, 2 ) . '/admin/views/gcm.php' );
	gtm_ok( false !== strpos( $view, 'data-path="advanced_mode_gtm"' ), 'the GCM admin page has a field bound to it' );

	echo "\n{$run} run, " . ( $run - $fail ) . " passed, {$fail} failed\n";
	exit( $fail ? 1 : 0 );
}
