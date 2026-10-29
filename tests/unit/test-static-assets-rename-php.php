<?php
/**
 * Standalone unit test: a failed rename() over a short copy falls back inline.
 *
 * Subsystem: static-assets-php
 *
 * Frontend::get_static_asset_url() stages the payload and renames it over the
 * content-hashed name. When that rename fails, a parallel request may have
 * landed the same bytes first, and that copy is used; but a copy that is NOT
 * complete must never be handed to a browser under its immutable name. The
 * function then returns '' (the payload stays inline) and leaves no staging
 * file behind.
 *
 * Its own process: `$write_failed` is a static inside get_static_asset_url(),
 * so after the first failure every later call in the same process returns ''
 * before writing anything, and a second failure case next to the short-write
 * one in test-static-assets-php.php would prove nothing.
 *
 * Run: php tests/unit/test-static-assets-rename-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	// Double for the `use FazCookie\Includes\Known_Providers` alias in
	// class-frontend.php — no autoload, no JSON read.
	class Known_Providers {
		public static function get_all() {
			return array();
		}
		public static function get_cookie_map() {
			return array();
		}
		public static function get_pattern_map() {
			return array();
		}
	}

	/**
	 * Double for the WP_Filesystem wrapper: writes straight to disk and counts
	 * writes so the tests can assert the "already exists" path never rewrites.
	 */
	class Filesystem {
		public static $writes         = 0;
		public static $can_access     = true;
		public static $short_write    = false;
		private static $instance      = null;

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function get_uploads_dir( $assets_dir ) {
			return array(
				'path' => $GLOBALS['__faz_uploads'] . '/' . $assets_dir . '/',
				'url'  => 'https://example.com/wp-content/uploads/' . $assets_dir . '/',
			);
		}

		public function can_access_filesystem() {
			return self::$can_access;
		}

		public function put_contents( $file_path, $data ) {
			self::$writes++;
			if ( self::$short_write && '.tmp' === substr( $file_path, -4 ) ) {
				file_put_contents( $file_path, substr( $data, 0, 10 ) );
				return true;
			}
			return false !== file_put_contents( $file_path, $data ); // phpcs:ignore
		}
	}
}

namespace {

	// ---------- Bootstrap ----------

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	$GLOBALS['__faz_uploads'] = sys_get_temp_dir() . '/faz-static-assets-rename-test-' . getmypid();
	$GLOBALS['__faz_filters'] = array();
	@mkdir( $GLOBALS['__faz_uploads'], 0777, true );

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return array_key_exists( $tag, $GLOBALS['__faz_filters'] )
				? $GLOBALS['__faz_filters'][ $tag ]
				: $value;
		}
	}
	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		function wp_mkdir_p( $dir ) {
			return is_dir( $dir ) || mkdir( $dir, 0777, true ); // phpcs:ignore
		}
	}
	if ( ! function_exists( 'wp_generate_uuid4' ) ) {
		function wp_generate_uuid4() {
			return sprintf( '%04x%04x-%04x-%04x', wp_rand_int(), wp_rand_int(), wp_rand_int(), wp_rand_int() );
		}
	}
	function wp_rand_int() {
		static $seq = 0;
		return 0x1000 + ( ++$seq );
	}
	if ( ! function_exists( 'wp_delete_file' ) ) {
		function wp_delete_file( $file ) {
			@unlink( $file ); // phpcs:ignore
		}
	}
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $str ) {
			return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			return trim( wp_strip_all_tags( preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) ) );
		}
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $value ) {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			return false;
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $ttl = 0 ) {
			return true;
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;
	use FazCookie\Includes\Filesystem as FazTestFilesystem;

	// ---------- assert helpers ----------

	$tests_passed = 0;
	$tests_failed = 0;

	function assert_true( $cond, $label ) {
		global $tests_passed, $tests_failed;
		if ( $cond ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m " . $label . "\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m " . $label . "\n";
		}
	}

	function faz_assets_dir() {
		return $GLOBALS['__faz_uploads'] . '/faz-cookie-manager/assets/';
	}

	function faz_write_asset( $filename, $contents ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		$m  = new ReflectionMethod( Frontend::class, 'get_static_asset_url' );
		$m->setAccessible( true );
		return $m->invoke( $fe, $filename, $contents );
	}

	function faz_tmp_files() {
		$found = glob( faz_assets_dir() . '.*.tmp' );
		return is_array( $found ) ? $found : array();
	}

	echo "\n-- rename() cannot replace an incomplete copy --\n";

	$payload = 'window._fazStaticConfig={"_providersToBlock":[{"re":"connect.facebook.net"}]};';
	$blocked = 'config-' . md5( 'rename-blocked' ) . '.js';
	wp_mkdir_p( faz_assets_dir() );
	// A directory under the asset's name: rename() cannot replace it, and it
	// is not a complete copy of the payload either.
	mkdir( faz_assets_dir() . $blocked ); // phpcs:ignore
	assert_true( '' === faz_write_asset( $blocked, $payload ), 'a rename that cannot replace an incomplete copy falls back to the inline payload' );
	assert_true( array() === faz_tmp_files(), 'no staging file is left behind after the failed rename' );
	assert_true( is_dir( faz_assets_dir() . $blocked ), 'the blocking entry is left as it was, not published as the asset' );

	// ---------- cleanup + result ----------

	@rmdir( faz_assets_dir() . $blocked ); // phpcs:ignore
	foreach ( (array) glob( faz_assets_dir() . '{,.}*', GLOB_BRACE ) as $leftover ) {
		if ( is_file( $leftover ) ) {
			@unlink( $leftover ); // phpcs:ignore
		}
	}
	@rmdir( faz_assets_dir() ); // phpcs:ignore
	@rmdir( $GLOBALS['__faz_uploads'] . '/faz-cookie-manager' ); // phpcs:ignore
	@rmdir( $GLOBALS['__faz_uploads'] ); // phpcs:ignore

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "ALL PASS ({$tests_passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$tests_failed}, passed: {$tests_passed}\n";
	exit( 1 );
}
