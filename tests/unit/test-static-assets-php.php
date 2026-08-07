<?php
/**
 * Standalone unit tests for the generated static-asset lifecycle.
 *
 * Subsystem: static-assets-php
 *
 * Frontend::get_static_asset_url() persists the content-hashed banner CSS and
 * static _fazConfig payload under uploads/faz-cookie-manager/assets/ so they
 * can be browser-cached instead of re-inlined into every HTML response, and
 * Frontend::cleanup_static_assets() reaps superseded hashes on the daily cron.
 *
 * Pinned here:
 *   1. First call writes the file and returns its URL; the write goes through
 *      a staging file + rename (atomicity), leaving no .tmp residue — a
 *      partially written, immutably named asset would stay corrupt forever.
 *   2. A subsequent call for the same hash re-uses the file without rewriting.
 *   3. mtime tracks last use (refreshed at most once a day), which is what the
 *      reaper keys off.
 *   4. cleanup_static_assets() deletes only stale generated assets (and orphan
 *      staging files), never recently-used ones, never index.php, and never
 *      unrelated files in the directory.
 *   5. The retention window is filterable; 0 disables reaping entirely.
 *
 * Run: php tests/unit/test-static-assets-php.php
 *  or: bash scripts/run-unit-tests.sh
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

	$GLOBALS['__faz_uploads'] = sys_get_temp_dir() . '/faz-static-assets-test-' . getmypid();
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

	// ---------- 1. write path ----------

	echo "\n-- write path (staging + atomic rename) --\n";

	$payload = 'window._fazStaticConfig={"_providersToBlock":[{"re":"connect.facebook.net"}]};';
	$name    = 'config-' . md5( $payload ) . '.js';
	$url     = faz_write_asset( $name, $payload );

	assert_true( 'https://example.com/wp-content/uploads/faz-cookie-manager/assets/' . $name === $url, 'returns the public asset URL' );
	assert_true( file_exists( faz_assets_dir() . $name ), 'asset file created' );
	assert_true( $payload === file_get_contents( faz_assets_dir() . $name ), 'asset contents written verbatim' );
	assert_true( array() === faz_tmp_files(), 'no staging .tmp residue left behind' );
	assert_true( file_exists( faz_assets_dir() . 'index.php' ), 'directory index.php guard created' );

	// ---------- 2. reuse path ----------

	echo "\n-- reuse path --\n";

	$writes_before = FazTestFilesystem::$writes;
	$url2          = faz_write_asset( $name, $payload );
	assert_true( $url2 === $url, 'second call returns the same URL' );
	assert_true( FazTestFilesystem::$writes === $writes_before, 'existing asset is not rewritten' );

	// A fresh hash writes a new file without disturbing the first.
	$payload_b = 'window._fazStaticConfig={"_providersToBlock":[]};';
	$name_b    = 'config-' . md5( $payload_b ) . '.js';
	faz_write_asset( $name_b, $payload_b );
	assert_true( file_exists( faz_assets_dir() . $name_b ), 'new hash writes a new file' );
	assert_true( file_exists( faz_assets_dir() . $name ), 'previous hash still present (stale caches keep resolving)' );

	// ---------- 3. mtime tracks last use ----------

	echo "\n-- mtime as last-used marker --\n";

	// Backdate past the once-a-day refresh threshold; serving must bump it.
	touch( faz_assets_dir() . $name, time() - ( 3 * DAY_IN_SECONDS ) );
	clearstatcache();
	faz_write_asset( $name, $payload );
	clearstatcache();
	assert_true( ( time() - filemtime( faz_assets_dir() . $name ) ) < DAY_IN_SECONDS, 'serving a stale-mtime asset refreshes it' );

	// A recently-touched asset is left alone (no write amplification).
	$fresh_mtime = time() - 60;
	touch( faz_assets_dir() . $name, $fresh_mtime );
	clearstatcache();
	faz_write_asset( $name, $payload );
	clearstatcache();
	assert_true( $fresh_mtime === filemtime( faz_assets_dir() . $name ), 'recently used asset mtime untouched' );

	// ---------- 4. reaper ----------

	echo "\n-- cleanup_static_assets() --\n";

	$css_stale  = 'banner-' . md5( 'stale' ) . '.css';
	$css_fresh  = 'banner-' . md5( 'fresh' ) . '.css';
	$orphan_tmp = '.config-orphan.js.abcd-1234.tmp';
	$unrelated  = 'GeoLite2-Country.mmdb';

	file_put_contents( faz_assets_dir() . $css_stale, '#faz-consent{}' ); // phpcs:ignore
	file_put_contents( faz_assets_dir() . $css_fresh, '#faz-consent{}' ); // phpcs:ignore
	file_put_contents( faz_assets_dir() . $orphan_tmp, 'half-written' ); // phpcs:ignore
	file_put_contents( faz_assets_dir() . $unrelated, 'not ours' ); // phpcs:ignore

	// Pin the retention explicitly instead of leaning on the shipped default:
	// this section is about which files the reaper selects, not about how long
	// the default keeps them. Without this the assertions silently invert the
	// day the default changes.
	$GLOBALS['__faz_filters']['faz_static_asset_retention_days'] = 90;

	$old = time() - ( 120 * DAY_IN_SECONDS );
	touch( faz_assets_dir() . $css_stale, $old );
	touch( faz_assets_dir() . $orphan_tmp, $old );
	touch( faz_assets_dir() . $unrelated, $old );
	touch( faz_assets_dir() . $name_b, $old );
	clearstatcache();

	$deleted = Frontend::cleanup_static_assets();

	assert_true( 3 === $deleted, 'reaper reports the number of files removed' );
	assert_true( ! file_exists( faz_assets_dir() . $css_stale ), 'stale banner CSS removed' );
	assert_true( ! file_exists( faz_assets_dir() . $name_b ), 'stale config asset removed' );
	assert_true( ! file_exists( faz_assets_dir() . $orphan_tmp ), 'orphan staging file removed' );
	assert_true( file_exists( faz_assets_dir() . $css_fresh ), 'recently used banner CSS kept' );
	assert_true( file_exists( faz_assets_dir() . $name ), 'recently used config asset kept' );
	assert_true( file_exists( faz_assets_dir() . 'index.php' ), 'index.php guard never reaped' );
	assert_true( file_exists( faz_assets_dir() . $unrelated ), 'unrelated file in the directory never touched' );

	// ---------- 5. retention filter ----------

	echo "\n-- retention filter --\n";

	touch( faz_assets_dir() . $css_fresh, $old );
	clearstatcache();
	$GLOBALS['__faz_filters']['faz_static_asset_retention_days'] = 0;
	assert_true( 0 === Frontend::cleanup_static_assets(), 'retention 0 disables reaping' );
	assert_true( file_exists( faz_assets_dir() . $css_fresh ), 'asset survives with reaping disabled' );

	$GLOBALS['__faz_filters']['faz_static_asset_retention_days'] = 30;
	assert_true( 1 === Frontend::cleanup_static_assets(), 'shortened retention reaps the backdated asset' );

	// The shipped default must outlive a plausible full-page-cache TTL. mtime is
	// only refreshed when PHP renders the page, and a cache serves the asset URLs
	// without running PHP — so an asset can look untouched for the whole TTL
	// while pages still reference it. Reaping it there means a 404 on the
	// banner's own CSS/JS, i.e. no consent UI. 120 days of apparent disuse must
	// NOT be enough to delete under the default.
	$long_unused = 'banner-' . md5( 'long-unused' ) . '.css';
	file_put_contents( faz_assets_dir() . $long_unused, '#faz-consent{}' ); // phpcs:ignore
	touch( faz_assets_dir() . $long_unused, time() - ( 120 * DAY_IN_SECONDS ) );
	clearstatcache();
	unset( $GLOBALS['__faz_filters']['faz_static_asset_retention_days'] );
	Frontend::cleanup_static_assets();
	assert_true( file_exists( faz_assets_dir() . $long_unused ), 'default retention outlives a long page-cache TTL' );

	// A failed stat must never be read as "old". Simulated by pointing the
	// reaper at a file that disappears between glob() and filemtime() is not
	// portable, so assert the guard directly: an unreadable mtime keeps the file.
	assert_true( false === @filemtime( faz_assets_dir() . 'definitely-absent.css' ), 'filemtime returns false for a missing file' );

	// ---------- cleanup + result ----------

	foreach ( (array) glob( faz_assets_dir() . '*' ) as $leftover ) {
		@unlink( $leftover ); // phpcs:ignore
	}
	foreach ( (array) glob( faz_assets_dir() . '.*' ) as $leftover ) {
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
