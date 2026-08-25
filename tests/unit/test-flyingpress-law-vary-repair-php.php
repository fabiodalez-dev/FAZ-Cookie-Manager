<?php
/**
 * Standalone regression tests for CLI::remove_legacy_flyingpress_law_vary().
 *
 * Subsystem: flyingpress-law-vary-repair
 *
 * This routine is hooked on admin_init to rewrite FlyingPress's generated
 * advanced-cache.php drop-in when an older 1.27.0 build baked the retired
 * `faz-law` cookie into it. Two review findings are pinned here:
 *
 *   1. SECURITY — admin_init is not an administrator-only hook. It fires on
 *      every authenticated wp-admin hit (a Subscriber loading profile.php) and
 *      on every admin-ajax.php request, logged-out ones included. Without a
 *      capability check, any of those could drive a filesystem drop-in rewrite
 *      and two option writes. The routine must do nothing at all without
 *      `manage_options`.
 *
 *   2. UNBOUNDED RETRIES — the completion marker used to be written only when
 *      the regenerated drop-in no longer contained `faz-law`. A FlyingPress
 *      build that regenerates but keeps the key (or throws) therefore never
 *      recorded anything, so the whole read → lock → regenerate → re-read
 *      sequence repeated on EVERY admin request, rewriting the drop-in
 *      indefinitely. The marker must now record the attempt, while staying a
 *      content fingerprint so a later genuine FlyingPress regeneration re-arms it.
 *
 * FlyingPress is simulated by a counter-recording stub whose behaviour is
 * switchable (repairs / keeps the key / throws / deletes the drop-in). The
 * options table is an in-memory array so the atomic add_option() lock claim and
 * its release can be asserted directly.
 *
 * Run from project root:
 *   php tests/unit/test-flyingpress-law-vary-repair-php.php
 *
 * Exit 0 = all pass; 1 = at least one failure.
 *
 * @package FazCookie\Tests\Unit
 */

namespace FlyingPress {
	/**
	 * Counter-recording stand-in for FlyingPress\AdvancedCache.
	 *
	 * $mode drives what the regeneration actually does to the drop-in:
	 *   'clean'  — rewrites it without the `faz-law` key (a working repair);
	 *   'keep'   — rewrites it but the key survives (the unrepairable case);
	 *   'throw'  — blows up, as a broken/incompatible FlyingPress build would;
	 *   'delete' — unlinks the drop-in instead of rewriting it.
	 */
	class AdvancedCache {
		public static $calls  = 0;
		public static $mode   = 'clean';
		public static $target = '';
		public static $seq    = 0;

		public static function add_advanced_cache() {
			++self::$calls;
			if ( 'throw' === self::$mode ) {
				throw new \RuntimeException( 'simulated FlyingPress drop-in write failure' );
			}
			if ( 'delete' === self::$mode ) {
				if ( file_exists( self::$target ) ) {
					unlink( self::$target );
				}
				return;
			}
			++self::$seq;
			$body = "<?php // FlyingPress advanced cache\n";
			if ( 'keep' === self::$mode ) {
				$body .= "\$vary_cookies = array( 'faz-law' );\n";
			}
			// Pad by a growing amount so each regeneration has a distinct
			// identity even inside the same one-second mtime window.
			$body .= '// ' . str_repeat( 'x', self::$seq ) . "\n";
			file_put_contents( self::$target, $body );
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	}

	$faz_dropin_dir = sys_get_temp_dir() . '/faz-fp-law-vary-' . getmypid();
	if ( ! is_dir( $faz_dropin_dir ) ) {
		mkdir( $faz_dropin_dir, 0777, true );
	}
	define( 'WP_CONTENT_DIR', $faz_dropin_dir );
	$faz_dropin = $faz_dropin_dir . '/advanced-cache.php';
	\FlyingPress\AdvancedCache::$target = $faz_dropin;

	register_shutdown_function(
		static function () use ( $faz_dropin, $faz_dropin_dir ) {
			if ( file_exists( $faz_dropin ) ) {
				unlink( $faz_dropin );
			}
			if ( is_dir( $faz_dropin_dir ) ) {
				rmdir( $faz_dropin_dir );
			}
		}
	);

	// ---- WordPress stubs ---------------------------------------------------

	// Capability recorder. $GLOBALS['faz_can'] is the verdict;
	// $GLOBALS['faz_caps_asked'] records which capability was actually queried,
	// so the test can prove the guard asks for manage_options and not something
	// a Subscriber holds.
	$GLOBALS['faz_can']       = true;
	$GLOBALS['faz_caps_asked'] = array();
	function current_user_can( $cap ) { // phpcs:ignore
		$GLOBALS['faz_caps_asked'][] = $cap;
		return (bool) $GLOBALS['faz_can'];
	}

	$GLOBALS['faz_options'] = array();
	function get_option( $option, $default_value = false ) { // phpcs:ignore
		return array_key_exists( $option, $GLOBALS['faz_options'] )
			? $GLOBALS['faz_options'][ $option ]
			: $default_value;
	}
	function update_option( $option, $value, $autoload = null ) { // phpcs:ignore
		$GLOBALS['faz_options'][ $option ] = $value;
		return true;
	}
	// Mirrors WordPress: add_option() is a claim that fails when the row exists.
	function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) { // phpcs:ignore
		if ( array_key_exists( $option, $GLOBALS['faz_options'] ) ) {
			return false;
		}
		$GLOBALS['faz_options'][ $option ] = $value;
		return true;
	}
	function delete_option( $option ) { // phpcs:ignore
		if ( ! array_key_exists( $option, $GLOBALS['faz_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['faz_options'][ $option ] );
		return true;
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-cli.php';

	use FazCookie\Includes\CLI;

	$faz_pass = 0;
	$faz_fail = 0;
	function faz_lv_ok( $condition, $label ) { // phpcs:ignore
		global $faz_pass, $faz_fail;
		if ( $condition ) {
			++$faz_pass;
			echo "  [PASS] {$label}\n";
			return;
		}
		++$faz_fail;
		echo "  [FAIL] {$label}\n";
	}

	const FAZ_LV_MARKER = 'faz_flyingpress_law_vary_repaired';
	const FAZ_LV_LOCK   = 'faz_flyingpress_law_vary_repairing';

	/** Put the drop-in back into the "legacy, still contains faz-law" state. */
	function faz_lv_seed_legacy_dropin( $padding = 0 ) { // phpcs:ignore
		global $faz_dropin;
		file_put_contents(
			$faz_dropin,
			"<?php // FlyingPress advanced cache\n\$vary_cookies = array( 'faz-law' );\n"
			. '// ' . str_repeat( 'y', $padding ) . "\n"
		);
		clearstatcache( true, $faz_dropin );
	}

	function faz_lv_reset( $mode ) { // phpcs:ignore
		$GLOBALS['faz_options']     = array();
		$GLOBALS['faz_caps_asked']  = array();
		\FlyingPress\AdvancedCache::$calls = 0;
		\FlyingPress\AdvancedCache::$mode  = $mode;
		faz_lv_seed_legacy_dropin();
	}

	function faz_lv_current_fingerprint() { // phpcs:ignore
		global $faz_dropin;
		clearstatcache( true, $faz_dropin );
		return (string) filemtime( $faz_dropin ) . ':' . (string) filesize( $faz_dropin ) . ':' . hash_file( 'sha256', $faz_dropin );
	}

	echo "FlyingPress legacy faz-law drop-in repair\n\n";

	// ---- A. Capability guard (review finding 1, MAJOR/security) ------------
	// admin_init fires for Subscribers on profile.php and for every
	// admin-ajax.php request. Nothing may happen without manage_options.
	faz_lv_reset( 'clean' );
	$before_md5 = md5_file( $faz_dropin );
	$GLOBALS['faz_can'] = false;
	CLI::remove_legacy_flyingpress_law_vary();
	faz_lv_ok( 0 === \FlyingPress\AdvancedCache::$calls, 'A1 non-admin never triggers a drop-in rewrite' );
	clearstatcache( true, $faz_dropin );
	faz_lv_ok( $before_md5 === md5_file( $faz_dropin ), 'A2 non-admin leaves the drop-in byte-identical' );
	faz_lv_ok( array() === $GLOBALS['faz_options'], 'A3 non-admin writes no options (no marker, no lock)' );
	faz_lv_ok(
		array( 'manage_options' ) === $GLOBALS['faz_caps_asked'],
		'A4 the guard asks for manage_options, and asks before any other work'
	);

	// ---- B. Administrator, repairable drop-in ------------------------------
	faz_lv_reset( 'clean' );
	$GLOBALS['faz_can'] = true;
	CLI::remove_legacy_flyingpress_law_vary();
	faz_lv_ok( 1 === \FlyingPress\AdvancedCache::$calls, 'B1 administrator triggers the repair once' );
	faz_lv_ok(
		false === strpos( (string) file_get_contents( $faz_dropin ), 'faz-law' ),
		'B2 the regenerated drop-in no longer carries faz-law'
	);
	faz_lv_ok(
		faz_lv_current_fingerprint() === (string) get_option( FAZ_LV_MARKER, '' ),
		'B3 the marker records the repaired drop-in fingerprint'
	);
	faz_lv_ok( ! array_key_exists( FAZ_LV_LOCK, $GLOBALS['faz_options'] ), 'B4 the concurrency lock is released' );
	CLI::remove_legacy_flyingpress_law_vary();
	faz_lv_ok( 1 === \FlyingPress\AdvancedCache::$calls, 'B5 a repaired drop-in is not touched again' );

	// ---- C. Unrepairable drop-in (review finding 2, loop A) -----------------
	// FlyingPress regenerates but the key survives. Before the fix, the marker
	// was written only when faz-law had disappeared, so this rewrote the
	// drop-in on every single admin request forever.
	faz_lv_reset( 'keep' );
	CLI::remove_legacy_flyingpress_law_vary();
	faz_lv_ok( 1 === \FlyingPress\AdvancedCache::$calls, 'C1 an unrepairable drop-in is attempted once' );
	faz_lv_ok(
		false !== strpos( (string) file_get_contents( $faz_dropin ), 'faz-law' ),
		'C2 the fixture really is unrepairable (key survives regeneration)'
	);
	faz_lv_ok(
		faz_lv_current_fingerprint() === (string) get_option( FAZ_LV_MARKER, '' ),
		'C3 the attempt is recorded even though the key survived'
	);
	faz_lv_ok( ! array_key_exists( FAZ_LV_LOCK, $GLOBALS['faz_options'] ), 'C4 the lock is released after a failed repair' );
	CLI::remove_legacy_flyingpress_law_vary();
	CLI::remove_legacy_flyingpress_law_vary();
	faz_lv_ok(
		1 === \FlyingPress\AdvancedCache::$calls,
		'C5 a drop-in that cannot be repaired is NOT rewritten on every admin request'
	);
	// A genuine FlyingPress regeneration changes the content fingerprint and must
	// re-arm the attempt — the marker is deliberately not a boolean one-shot.
	faz_lv_seed_legacy_dropin( 32 );
	CLI::remove_legacy_flyingpress_law_vary();
	faz_lv_ok(
		2 === \FlyingPress\AdvancedCache::$calls,
		'C6 a later genuine FlyingPress regeneration re-arms the repair'
	);

	// ---- D. FlyingPress throws (review finding 2, same loop) ---------------
	faz_lv_reset( 'throw' );
	CLI::remove_legacy_flyingpress_law_vary();
	faz_lv_ok( 1 === \FlyingPress\AdvancedCache::$calls, 'D1 a throwing FlyingPress does not escape into wp-admin' );
	faz_lv_ok(
		faz_lv_current_fingerprint() === (string) get_option( FAZ_LV_MARKER, '' ),
		'D2 the attempt is recorded even when the regeneration threw'
	);
	faz_lv_ok( ! array_key_exists( FAZ_LV_LOCK, $GLOBALS['faz_options'] ), 'D3 the lock is released on the throwing path' );
	CLI::remove_legacy_flyingpress_law_vary();
	CLI::remove_legacy_flyingpress_law_vary();
	faz_lv_ok(
		1 === \FlyingPress\AdvancedCache::$calls,
		'D4 a build that throws on every call cannot become a per-request retry loop'
	);

	// ---- E. No identity → no marker (review finding 2, loop B) -------------
	// The fingerprint is the whole bookkeeping mechanism, so the code must cope
	// with not having one. The reachable case is FlyingPress unlinking the
	// drop-in rather than rewriting it: nothing is recorded, no warning is
	// raised by stat'ing a missing path, and the next request stops at the
	// readability check instead of looping.
	faz_lv_reset( 'delete' );
	CLI::remove_legacy_flyingpress_law_vary();
	faz_lv_ok( ! file_exists( $faz_dropin ), 'E1 fixture: FlyingPress removed the drop-in' );
	faz_lv_ok(
		! array_key_exists( FAZ_LV_MARKER, $GLOBALS['faz_options'] ),
		'E2 no marker is written for a drop-in with no identity'
	);
	faz_lv_ok( ! array_key_exists( FAZ_LV_LOCK, $GLOBALS['faz_options'] ), 'E3 the lock is released when the drop-in vanished' );
	CLI::remove_legacy_flyingpress_law_vary();
	faz_lv_ok(
		1 === \FlyingPress\AdvancedCache::$calls,
		'E4 a missing drop-in stops at the readability check, it does not loop'
	);

	// The fingerprint helper's own contract, which the '' bail at the top of
	// the routine depends on. That particular bail is TOCTOU-only in production
	// (the path passed is_readable() microseconds earlier) and cannot be driven
	// in-process without a stream wrapper, so the contract is pinned directly.
	$faz_fp_method = new ReflectionMethod( CLI::class, 'flyingpress_dropin_fingerprint' );
	$faz_fp_method->setAccessible( true );
	faz_lv_ok(
		// @-silenced: stat'ing a deliberately missing path is the point of the
		// assertion, and the two warnings it raises are not the test's subject.
		'' === @$faz_fp_method->invoke( null, $faz_dropin_dir . '/does-not-exist.php' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		'E5 the fingerprint of an unstattable path is the empty string'
	);

	// mtime and byte size are not a sufficient identity: generated PHP can be
	// replaced within one filesystem tick by different content of equal length.
	// The digest must re-arm the repair in that exact collision case.
	$faz_fixed_mtime = 1700000000;
	file_put_contents( $faz_dropin, 'same-size-A' );
	touch( $faz_dropin, $faz_fixed_mtime );
	clearstatcache( true, $faz_dropin );
	$faz_first_fingerprint = $faz_fp_method->invoke( null, $faz_dropin );
	file_put_contents( $faz_dropin, 'same-size-B' );
	touch( $faz_dropin, $faz_fixed_mtime );
	clearstatcache( true, $faz_dropin );
	$faz_second_fingerprint = $faz_fp_method->invoke( null, $faz_dropin );
	faz_lv_ok(
		$faz_first_fingerprint !== $faz_second_fingerprint,
		'E6 equal mtime and size with different contents produce different fingerprints'
	);

	echo "\n" . ( 0 === $faz_fail ? "ALL PASS ({$faz_pass})\n" : "FAILED: {$faz_fail}, passed: {$faz_pass}\n" );
	exit( 0 === $faz_fail ? 0 : 1 );
}
