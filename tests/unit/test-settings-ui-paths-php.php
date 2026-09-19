<?php
/**
 * Every settings control in the admin must have a matching default.
 *
 * Settings::sanitize() iterates over the DEFAULTS, not over what the form
 * submitted:
 *
 *     foreach ( $defaults as $key => $data ) {
 *         $value = isset( $settings[ $key ] ) ? $settings[ $key ] : $data;
 *
 * So a `data-path` that has no corresponding default is never copied to the
 * output. The value does not fail to save — it is dropped, silently, with no
 * error and no notice. The administrator ticks the box, sees it ticked, saves,
 * and the setting quietly never existed.
 *
 * That is not hypothetical. `pageview_tracking` shipped in exactly that state
 * until 2026-03-14 (a36740e, "so the toggle actually persists across saves"),
 * which means roughly four months during which anyone who switched pageview
 * tracking on believed it was on, and it was not. It surfaced through a support
 * conversation, not through a test, because nothing here could see it.
 *
 * This suite is that missing check. It parses every `data-path` out of the
 * admin views and asserts each one resolves to a key in get_defaults().
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Store {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $value ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
		}
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $value ) {
			return abs( (int) $value );
		}
	}

	require_once __DIR__ . '/../../includes/class-formatting.php';
	require_once __DIR__ . '/../../admin/modules/settings/includes/class-settings.php';

	use FazCookie\Admin\Modules\Settings\Includes\Settings;

	$tests_run = $tests_passed = $tests_failed = 0;

	/**
	 * Assert helper.
	 *
	 * @param bool   $condition Condition under test.
	 * @param string $label     Human-readable description.
	 * @return void
	 */
	function faz_ui_assert( $condition, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $condition ) {
			$tests_passed++;
			echo "  \033[32mPASS\033[0m {$label}\n";
			return;
		}
		$tests_failed++;
		echo "  \033[31mFAIL\033[0m {$label}\n";
	}

	/**
	 * Flatten the defaults into dotted paths, exactly as `data-path` writes them.
	 *
	 * Numeric keys are skipped: a list value such as `target_regions` is a value,
	 * not a container of addressable sub-settings, and its indices are not paths
	 * anyone can bind a control to.
	 *
	 * @param array  $node   Defaults subtree.
	 * @param string $prefix Accumulated path.
	 * @return array<string,bool> Path => true.
	 */
	function faz_flatten_defaults( array $node, $prefix = '' ) {
		$out = array();
		foreach ( $node as $key => $value ) {
			if ( is_int( $key ) ) {
				continue;
			}
			$path         = ( '' === $prefix ) ? (string) $key : $prefix . '.' . $key;
			$out[ $path ] = true;
			if ( is_array( $value ) ) {
				$out = array_merge( $out, faz_flatten_defaults( $value, $path ) );
			}
		}
		return $out;
	}

	// Views whose controls are NOT backed by `faz_settings`. gcm.php writes to
	// its own `faz_gcm_settings` option through its own save handler, so its
	// bare paths (`status`, `wait_for_update`, …) are correct there and would be
	// false positives here. Kept as an explicit list rather than a pattern: a
	// new page with a separate store should have to be named, so nobody widens
	// the exemption by accident and re-opens the hole this suite exists to close.
	$faz_views_with_own_store = array( 'gcm.php' );

	$defaults  = ( new Settings() )->get_defaults();
	$flat      = faz_flatten_defaults( $defaults );
	$views_dir = __DIR__ . '/../../admin/views';

	echo "Settings UI paths must resolve to a default\n\n";

	faz_ui_assert( count( $flat ) > 50, 'the defaults flatten to a plausible number of paths (' . count( $flat ) . ')' );

	$missing   = array();
	$checked   = 0;
	$files     = glob( $views_dir . '/*.php' );
	sort( $files );

	foreach ( $files as $file ) {
		$base = basename( $file );
		if ( in_array( $base, $faz_views_with_own_store, true ) ) {
			continue;
		}
		preg_match_all( '/data-path="([^"]+)"/', (string) file_get_contents( $file ), $matches );
		foreach ( $matches[1] as $path ) {
			// Paths built inside a loop carry raw PHP in the attribute; the
			// prefix is what this suite can check, and the leaf is whatever the
			// loop yields at runtime.
			if ( false !== strpos( $path, '<?php' ) ) {
				$path = rtrim( substr( $path, 0, strpos( $path, '<?php' ) ), '.' );
				if ( '' === $path ) {
					continue;
				}
			}
			$checked++;
			if ( ! isset( $flat[ $path ] ) ) {
				$missing[ $path ] = $base;
			}
		}
	}

	faz_ui_assert( $checked > 30, "every view was read and yielded paths ({$checked} checked)" );

	if ( $missing ) {
		foreach ( $missing as $path => $file ) {
			faz_ui_assert( false, "'{$path}' ({$file}) has no default — Settings::sanitize() will drop it on save" );
		}
	} else {
		faz_ui_assert( true, 'every data-path in the admin views resolves to a default' );
	}

	// The regression that motivated the suite, pinned by name. If someone
	// removes pageview_tracking from the defaults again, the generic check above
	// already fails — but this says out loud which setting it was, so the next
	// reader does not have to find the commit to understand why the file exists.
	faz_ui_assert( isset( $flat['pageview_tracking'] ), 'pageview_tracking has a default (it did not, for four months)' );

	echo "\n";
	if ( $tests_failed > 0 ) {
		echo "\033[31mFAILED: {$tests_failed}\033[0m, passed: {$tests_passed}\n";
		exit( 1 );
	}
	echo "\033[32mALL PASS ({$tests_passed})\033[0m\n";
	exit( 0 );
}
