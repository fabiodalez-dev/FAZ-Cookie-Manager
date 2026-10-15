<?php
/**
 * Standalone unit tests — enabling IAB TCF fetches the Global Vendor List.
 *
 * Subsystem: gvl-bootstrap-on-enable-php
 *
 * The TCF layer used to activate on iab.enabled + cmp_id alone. Nothing
 * downloaded the GVL when the operator switched TCF on: the only triggers were
 * the "Update GVL Now" button and the weekly cron (whose first tick after the
 * switch is up to seven days away, longer without WP-Cron). In between, every
 * visitor received TC strings with VendorListVersion 0 and an empty
 * DisclosedVendors segment, which the IAB CMP validator rejects (Controls
 * Catalogue checks 6/7).
 *
 * Settings::update() is the one write path every iab.enabled change goes
 * through (settings REST, onboarding wizard, import), so the bootstrap lives
 * there: when the flag flips from off to on and no GVL is stored, download it.
 *
 * Run from project root:
 *   php tests/unit/test-gvl-bootstrap-on-enable-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Store {}

	/** Gvl double: counts downloads, reports whatever has_data the case needs. */
	class Gvl {
		public static $has_data  = false;
		public static $downloads = 0;
		public static $purposes  = array();
		private static $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function has_data() {
			return self::$has_data;
		}
		public function download() {
			self::$downloads++;
			self::$has_data = true;
			return array( 'success' => true, 'message' => 'ok', 'version' => 180, 'vendor_count' => 1 );
		}
		public function download_purposes( $lang ) {
			self::$purposes[] = $lang;
			return array( 'success' => true, 'message' => 'ok' );
		}
		public static function reset( $has_data ) {
			self::$has_data  = $has_data;
			self::$downloads = 0;
			self::$purposes  = array();
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	$GLOBALS['faz_test_options'] = array();
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_test_options'] ) ? $GLOBALS['faz_test_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_test_options'][ $name ] = $value;
		return true;
	}
	function do_action() {}
	function apply_filters( $tag, $value ) {
		return $value;
	}
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
	function absint( $value ) {
		return abs( (int) $value );
	}
	function sanitize_text_field( $v ) {
		return trim( (string) $v );
	}
	function __( $text, $domain = 'default' ) {
		return $text;
	}
	function faz_default_language() {
		return 'it';
	}

	require_once __DIR__ . '/../../includes/class-formatting.php';
	require_once __DIR__ . '/../../admin/modules/settings/includes/class-settings.php';

	use FazCookie\Admin\Modules\Settings\Includes\Settings;
	use FazCookie\Includes\Gvl;

	$tests_run = $tests_passed = $tests_failed = 0;
	function assert_eq( $actual, $expected, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $actual === $expected ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m $label\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m $label\n";
			echo '      expected: ' . var_export( $expected, true ) . "\n";
			echo '      actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	/** Store a previous faz_settings with the given iab.enabled, then update() to the new one. */
	function faz_flip_iab( $previous_enabled, $new_enabled, $has_data ) {
		$settings = new Settings();
		$base     = $settings->get_defaults();
		$base['iab']['cmp_id'] = 123;
		$prev = $base;
		$prev['iab']['enabled'] = $previous_enabled;
		$GLOBALS['faz_test_options']['faz_settings'] = $prev;
		Settings::clear_cache();
		Gvl::reset( $has_data );

		$next = $base;
		$next['iab']['enabled'] = $new_enabled;
		$settings->update( $next );
	}

	echo "\n\033[1mGVL bootstrap when IAB TCF is switched on\033[0m\n\n";

	faz_flip_iab( false, true, false );
	assert_eq( Gvl::$downloads, 1, 'iab.enabled off → on with no GVL stored: the GVL is downloaded once' );
	assert_eq( Gvl::$purposes, array( 'it' ), 'and the purpose translations for the default language come with it' );
	assert_eq( get_option( 'faz_settings' )['iab']['enabled'], true, 'the setting itself is saved' );

	faz_flip_iab( false, true, true );
	assert_eq( Gvl::$downloads, 0, 'iab.enabled off → on with a GVL already stored: nothing is re-downloaded on save' );

	faz_flip_iab( true, true, false );
	assert_eq( Gvl::$downloads, 0, 'iab.enabled already on: an ordinary settings save never triggers a download (the weekly cron owns refreshes)' );

	faz_flip_iab( true, false, false );
	assert_eq( Gvl::$downloads, 0, 'iab.enabled on → off: no download' );

	faz_flip_iab( false, false, false );
	assert_eq( Gvl::$downloads, 0, 'iab.enabled stays off: no download' );

	echo "\n" . ( $tests_failed > 0 ? "\033[31m" : "\033[32m" ) . "{$tests_passed}/{$tests_run} passed\033[0m\n";
	exit( $tests_failed > 0 ? 1 : 0 );
}
