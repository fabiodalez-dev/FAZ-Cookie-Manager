<?php
/**
 * Standalone unit tests for the Do-Not-Sell runtime compatibility layer.
 *
 * Covers the three Banner methods that make a CCPA / "Both" (GDPR + Do-Not-Sell)
 * banner render with a working opt-out UI without persisting any change:
 *
 *   - Banner::apply_runtime_layout_compatibility()
 *       Classic + (CCPA|Both)        → box + popup (+ enable donotSell/optoutPopup)
 *       Full-width(banner)+Pushdown  → popup
 *       GDPR-only / already-popup     → untouched
 *   - Banner::apply_runtime_law_content_compatibility()
 *       Re-syncs the notice copy to the law-appropriate default ONLY when the
 *       stored copy is empty or still equals the OTHER law's bundled default.
 *       Customised copy is never overwritten. "Both" is treated like GDPR.
 *   - Banner::get_law_notice_descriptions()
 *       Returns { gdpr, ccpa } strings from the bundled en.json, memoised.
 *
 * Run from project root:
 *   php tests/unit/test-donotsell-php.php
 *   bash scripts/run-unit-tests.sh
 *
 * Exit code 0 = all tests pass; 1 = at least one failure.
 *
 * The suite stubs the handful of WordPress / FAZ helpers the methods touch
 * (object cache is a no-op pass-through so nothing is memoised across tests,
 * JSON reads go straight to the bundled files on disk, the Languages controller
 * always reports "not translated" so the en.json baseline is used) and then
 * drives a thin Banner subclass that injects settings/contents directly,
 * bypassing the Controller + DB constructor path. No WP runtime, no browser.
 *
 * @package FazCookie\Tests\Unit
 */

// ---------- Bootstrap ----------
//
// This file mixes the plugin's namespaced classes with global-scope test code,
// so every statement lives inside an explicit brace-delimited namespace block
// (PHP forbids unbraced top-level code alongside namespace { } blocks).

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// ---------- WP / FAZ helper stubs (defined BEFORE the classes load) ----------

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $name ) {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $name );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( preg_replace( '/\s+/', ' ', (string) $str ) );
	}
}
if ( ! function_exists( 'faz_sanitize_text' ) ) {
	function faz_sanitize_text( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
if ( ! function_exists( 'faz_sanitize_content' ) ) {
	function faz_sanitize_content( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
if ( ! function_exists( 'faz_sanitize_bool' ) ) {
	function faz_sanitize_bool( $value ) {
		return (bool) $value;
	}
}
if ( ! function_exists( 'faz_sanitize_color' ) ) {
	function faz_sanitize_color( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
if ( ! function_exists( 'faz_default_language' ) ) {
	function faz_default_language() {
		return 'en';
	}
}
if ( ! function_exists( 'faz_selected_languages' ) ) {
	function faz_selected_languages( $language = '' ) {
		return array( 'en' );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $path ) {
		return rtrim( (string) $path, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		// A non-existent basedir guarantees the translated-file branch is never
		// taken, so get_law_notice_descriptions() reads the bundled en.json.
		return array( 'basedir' => sys_get_temp_dir() . '/faz-nonexistent-uploads-' . wp_rand() );
	}
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 2147483647 ) {
		return mt_rand( $min, $max );
	}
}

// Object cache: no-op pass-through. wp_cache_get ALWAYS misses so every call
// re-reads from disk — deterministic and keeps tests independent of order.
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) {
		return false;
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		return true;
	}
}

// Read JSON straight off disk (bypasses the WP_Filesystem singleton that the
// real faz_read_json_file() uses).
if ( ! function_exists( 'faz_read_json_file' ) ) {
	function faz_read_json_file( $file_path = '' ) {
		if ( ! is_string( $file_path ) || ! file_exists( $file_path ) ) {
			return array();
		}
		$decoded = json_decode( (string) file_get_contents( $file_path ), true );
		return is_array( $decoded ) ? $decoded : array();
	}
}

} // end global-scope bootstrap namespace block

// ---------- Fake Languages Controller (always "not translated" → en.json) ----------
//
// get_law_notice_descriptions() calls
// \FazCookie\Admin\Modules\Languages\Includes\Controller::get_instance()
//   ->is_faz_translated( $lang ). Define a stand-in in that exact namespace
// BEFORE class-banner.php loads so the autoloader never pulls the real one.

namespace FazCookie\Admin\Modules\Languages\Includes {
	if ( ! class_exists( __NAMESPACE__ . '\\Controller' ) ) {
		class Controller {
			private static $instance;
			public static function get_instance() {
				if ( null === self::$instance ) {
					self::$instance = new self();
				}
				return self::$instance;
			}
			public function is_faz_translated( $lang ) {
				return false; // Force the bundled en.json baseline everywhere.
			}
		}
	}
}

// ---------- Load the real Store + Banner classes ----------

namespace {

	$root = dirname( __DIR__, 2 );
	require_once $root . '/includes/class-store.php';
	require_once $root . '/admin/modules/banners/includes/class-banner.php';

	use FazCookie\Admin\Modules\Banners\Includes\Banner;

	/**
	 * Thin test double: bypasses the Controller + DB constructor and lets the
	 * test inject the raw settings/contents the methods consume. get_settings()
	 * returns the injected settings verbatim (the real one sanitises against
	 * Controller defaults, which would need the full DB/JSON machinery).
	 */
	class Test_Banner extends Banner {
		/** @var array */
		public $injected_settings = array();

		public function __construct() {
			// Intentionally skip parent::__construct (no Controller / DB).
			$this->data = array(
				'settings' => array(),
				'contents' => array(),
			);
		}

		public function set_injected_settings( array $settings ) {
			$this->injected_settings    = $settings;
			$this->data['settings'] = $settings;
		}

		public function set_injected_contents( $contents ) {
			$this->data['contents'] = $contents;
		}

		// Return injected settings as-is (no Controller-backed sanitisation).
		public function get_settings() {
			return $this->injected_settings;
		}

		// Public window onto the protected get_object_data() so tests can read
		// what the runtime methods wrote back into $this->data.
		public function peek( $key ) {
			return $this->get_object_data( $key );
		}

		// Remove a data slot to exercise the "key missing" guards.
		public function unset_data_key( $key ) {
			unset( $this->data[ $key ] );
		}
	}

	// ---------- Minimal assert helpers ----------

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;

	function assert_eq( $actual, $expected, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $actual === $expected ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m " . $label . "\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m " . $label . "\n";
			echo "      expected: " . var_export( $expected, true ) . "\n";
			echo "      actual:   " . var_export( $actual, true ) . "\n";
		}
	}

	function assert_true( $actual, $label ) {
		assert_eq( (bool) $actual, true, $label );
	}

	// ---------- Shared helpers ----------

	/**
	 * Build a settings payload. $dns toggles the nested donotSell.status branch,
	 * which is the ONLY Do-Not-Sell flag the runtime layer reads.
	 */
	function faz_make_settings( $law, $type, $ptype, $dns = false, $position = null ) {
		$settings = array(
			'applicableLaw'        => $law,
			'type'                 => $type,
			'preferenceCenterType' => $ptype,
		);
		if ( null !== $position ) {
			$settings['position'] = $position;
		}
		$config = array(
			'categoryPreview' => array( 'status' => true ),
			'notice'          => array(
				'elements' => array(
					'buttons' => array(
						'elements' => array(
							'donotSell' => array( 'status' => (bool) $dns ),
						),
					),
				),
			),
		);
		return array(
			'settings' => $settings,
			'config'   => $config,
		);
	}

	function faz_banner_with( $law, $type, $ptype, $dns = false, $position = null ) {
		$b = new Test_Banner();
		$b->set_injected_settings( faz_make_settings( $law, $type, $ptype, $dns, $position ) );
		return $b;
	}

	echo "\n== Do-Not-Sell runtime compatibility — unit tests ==\n";

	// =====================================================================
	// get_law_notice_descriptions() — the memoised per-law copy source
	// =====================================================================
	echo "\n-- get_law_notice_descriptions() --\n";

	$desc = Banner::get_law_notice_descriptions( 'en' );
	assert_true(
		is_array( $desc ) && array_key_exists( 'gdpr', $desc ) && array_key_exists( 'ccpa', $desc ),
		'returns a { gdpr, ccpa } array'
	);
	assert_true(
		'' !== $desc['gdpr'] && '' !== $desc['ccpa'],
		'both gdpr and ccpa defaults are non-empty (read from bundled en.json)'
	);
	assert_true(
		$desc['gdpr'] !== $desc['ccpa'],
		'gdpr and ccpa default copy differ (CCPA names the opt-out link)'
	);
	// The CCPA default mentions opting out / Do Not Sell; the GDPR one does not.
	assert_true(
		false !== stripos( $desc['ccpa'], 'opt' ),
		'ccpa default copy mentions opting out'
	);
	// Unknown / malformed language falls back to en.json (sanitize_file_name
	// strips it to a code with no matching file → en.json).
	$desc_bogus = Banner::get_law_notice_descriptions( '../../etc/passwd' );
	assert_eq(
		$desc_bogus['gdpr'],
		$desc['gdpr'],
		'path-traversal lang sanitised → falls back to en.json gdpr copy'
	);
	// Empty language argument is the documented default ('en').
	$desc_empty = Banner::get_law_notice_descriptions( '' );
	assert_eq(
		$desc_empty['ccpa'],
		$desc['ccpa'],
		"empty lang arg → en.json baseline (same as 'en')"
	);

	// =====================================================================
	// apply_runtime_layout_compatibility() — layout migration gating
	// =====================================================================
	echo "\n-- apply_runtime_layout_compatibility() --\n";

	// 1. GDPR Classic, no Do-Not-Sell → UNTOUCHED (the most important edge case:
	//    a pure GDPR banner must never be migrated to box+popup).
	$b = faz_banner_with( 'gdpr', 'classic', 'pushdown', false );
	$changed = $b->apply_runtime_layout_compatibility();
	assert_eq( $changed, false, 'GDPR classic (no DNS) → no change' );
	$out = $b->peek( 'settings' );
	assert_eq(
		$out['settings']['type'],
		'classic',
		'GDPR classic → type stays classic (NOT migrated to box)'
	);
	assert_eq(
		$out['settings']['preferenceCenterType'],
		'pushdown',
		'GDPR classic → preferenceCenterType stays pushdown (untouched)'
	);

	// 2. CCPA Classic → migrated to box + popup, position normalised, donotSell &
	//    optoutPopup enabled, inline category toggles suppressed.
	$b = faz_banner_with( 'ccpa', 'classic', 'pushdown', false, 'top' );
	$changed = $b->apply_runtime_layout_compatibility();
	assert_eq( $changed, true, 'CCPA classic → changed' );
	$out = $b->peek( 'settings' );
	assert_eq( $out['settings']['type'], 'box', 'CCPA classic → type box' );
	assert_eq( $out['settings']['preferenceCenterType'], 'popup', 'CCPA classic → preferenceCenterType popup' );
	assert_eq( $out['settings']['position'], 'bottom-left', "CCPA classic invalid 'top' position → bottom-left" );
	assert_eq( $out['config']['categoryPreview']['status'], false, 'CCPA classic → inline category toggles suppressed' );
	assert_eq(
		$out['config']['notice']['elements']['buttons']['elements']['donotSell']['status'],
		true,
		'CCPA classic → donotSell button force-enabled'
	);
	assert_eq( $out['config']['optoutPopup']['status'], true, 'CCPA classic → optoutPopup force-enabled' );

	// 2b. CCPA Classic with an ALREADY-VALID box position is preserved, not reset.
	$b = faz_banner_with( 'ccpa', 'classic', 'pushdown', false, 'bottom-right' );
	$b->apply_runtime_layout_compatibility();
	$out = $b->peek( 'settings' );
	assert_eq( $out['settings']['position'], 'bottom-right', 'CCPA classic valid position bottom-right → preserved' );

	// 3. "Both" = GDPR + nested donotSell.status ON → gated exactly like CCPA.
	$b = faz_banner_with( 'gdpr', 'classic', 'pushdown', true );
	$changed = $b->apply_runtime_layout_compatibility();
	assert_eq( $changed, true, 'Both (gdpr + DNS on) classic → changed (gated like CCPA)' );
	$out = $b->peek( 'settings' );
	assert_eq( $out['settings']['type'], 'box', 'Both classic → type box' );
	assert_eq( $out['settings']['preferenceCenterType'], 'popup', 'Both classic → popup' );

	// 4. CCPA Full-width(banner) + Pushdown → preferenceCenterType popup, type
	//    stays "banner" (only the pushdown is downgraded).
	$b = faz_banner_with( 'ccpa', 'banner', 'pushdown', false );
	$changed = $b->apply_runtime_layout_compatibility();
	assert_eq( $changed, true, 'CCPA banner+pushdown → changed' );
	$out = $b->peek( 'settings' );
	assert_eq( $out['settings']['preferenceCenterType'], 'popup', 'CCPA banner+pushdown → popup' );
	assert_eq( $out['settings']['type'], 'banner', 'CCPA banner+pushdown → type stays banner (not box)' );
	assert_eq( $out['config']['categoryPreview']['status'], false, 'CCPA banner+pushdown → inline toggles suppressed' );

	// 5. CCPA Full-width(banner) ALREADY popup → only the donotSell/optoutPopup
	//    enable flags fire; the layout (type/ptype) is left alone but the method
	//    still reports "changed" because it enabled the opt-out entry points.
	$b = faz_banner_with( 'ccpa', 'banner', 'popup', false );
	$changed = $b->apply_runtime_layout_compatibility();
	$out = $b->peek( 'settings' );
	assert_eq( $out['settings']['type'], 'banner', 'CCPA banner already-popup → type unchanged' );
	assert_eq( $out['settings']['preferenceCenterType'], 'popup', 'CCPA banner already-popup → ptype stays popup' );
	assert_eq( $changed, true, 'CCPA banner already-popup → still changed (opt-out entry points enabled)' );

	// 6. CCPA banner already popup AND opt-out entry points already enabled →
	//    truly idempotent: no change at all.
	$settings = faz_make_settings( 'ccpa', 'banner', 'popup', false );
	$settings['config']['optoutPopup'] = array( 'status' => true );
	$settings['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] = true;
	$b = new Test_Banner();
	$b->set_injected_settings( $settings );
	$changed = $b->apply_runtime_layout_compatibility();
	assert_eq( $changed, false, 'CCPA banner+popup+optout already on → idempotent no-op' );

	// 7. CCPA Box + popup (the canonical good layout) → already-popup, opt-out
	//    entry points enabled (so "changed") but type/position untouched.
	$b = faz_banner_with( 'ccpa', 'box', 'popup', false, 'bottom-left' );
	$changed = $b->apply_runtime_layout_compatibility();
	$out = $b->peek( 'settings' );
	assert_eq( $out['settings']['type'], 'box', 'CCPA box+popup → type stays box' );
	assert_eq( $out['settings']['position'], 'bottom-left', 'CCPA box+popup → position untouched' );

	// 8. Malformed / empty settings array → returns false, never fatals.
	$b = new Test_Banner();
	$b->set_injected_settings( array() );
	assert_eq( $b->apply_runtime_layout_compatibility(), false, 'empty settings payload → false (no fatal)' );

	// 9. Missing applicableLaw defaults to gdpr → classic with no DNS untouched.
	$b = new Test_Banner();
	$b->set_injected_settings(
		array(
			'settings' => array( 'type' => 'classic', 'preferenceCenterType' => 'pushdown' ),
			'config'   => array(),
		)
	);
	assert_eq( $b->apply_runtime_layout_compatibility(), false, 'missing applicableLaw (→gdpr) classic no-DNS → no change' );

	// =====================================================================
	// apply_runtime_law_content_compatibility() — copy re-sync gating
	// =====================================================================
	echo "\n-- apply_runtime_law_content_compatibility() --\n";

	$law_desc = Banner::get_law_notice_descriptions( 'en' );

	// Helper: build a full content tree for one language with a chosen notice
	// description, so array_empty_assoc() sees a non-blank language.
	$make_content = function ( $description ) {
		return array(
			'en' => array(
				'notice' => array(
					'elements' => array(
						'title'       => 'Cookie notice',
						'description' => $description,
					),
				),
			),
		);
	};

	// 10. CCPA banner whose copy still equals the GDPR default → re-synced to the
	//     CCPA default (the core re-sync behaviour).
	$b = new Test_Banner();
	$b->set_injected_settings( faz_make_settings( 'ccpa', 'box', 'popup', false ) );
	$b->set_injected_contents( $make_content( $law_desc['gdpr'] ) );
	$changed = $b->apply_runtime_law_content_compatibility();
	assert_eq( $changed, true, 'CCPA + stale GDPR-default copy → re-synced (changed)' );
	$contents = $b->peek( 'contents' );
	assert_eq(
		$contents['en']['notice']['elements']['description'],
		$law_desc['ccpa'],
		'CCPA re-sync → description becomes the CCPA default'
	);
	assert_eq(
		$contents['en']['notice']['elements']['title'],
		'Cookie notice',
		'CCPA re-sync → unrelated title field left intact'
	);

	// 11. CCPA banner with CUSTOMISED copy → never overwritten.
	$custom = '<p>Our own bespoke privacy notice that we wrote by hand.</p>';
	$b = new Test_Banner();
	$b->set_injected_settings( faz_make_settings( 'ccpa', 'box', 'popup', false ) );
	$b->set_injected_contents( $make_content( $custom ) );
	$changed = $b->apply_runtime_law_content_compatibility();
	assert_eq( $changed, false, 'CCPA + customised copy → not changed' );
	$contents = $b->peek( 'contents' );
	assert_eq(
		$contents['en']['notice']['elements']['description'],
		$custom,
		'CCPA + customised copy → description preserved verbatim'
	);

	// 12. CCPA banner whose copy is already the CCPA default → no-op.
	$b = new Test_Banner();
	$b->set_injected_settings( faz_make_settings( 'ccpa', 'box', 'popup', false ) );
	$b->set_injected_contents( $make_content( $law_desc['ccpa'] ) );
	assert_eq(
		$b->apply_runtime_law_content_compatibility(),
		false,
		'CCPA + already-CCPA copy → no-op (correct copy already in place)'
	);

	// 13. GDPR ("Both" included) banner whose copy still equals the CCPA default →
	//     re-synced back to the neutral GDPR default.
	$b = new Test_Banner();
	$b->set_injected_settings( faz_make_settings( 'gdpr', 'box', 'popup', true ) ); // Both
	$b->set_injected_contents( $make_content( $law_desc['ccpa'] ) );
	$changed = $b->apply_runtime_law_content_compatibility();
	assert_eq( $changed, true, 'Both/GDPR + stale CCPA-default copy → re-synced (changed)' );
	$contents = $b->peek( 'contents' );
	assert_eq(
		$contents['en']['notice']['elements']['description'],
		$law_desc['gdpr'],
		'Both/GDPR re-sync → description becomes the GDPR default'
	);

	// 14. Blank language (no meaningful content) → skipped entirely so the
	//     whole-language en.json fallback is not defeated by a partial write.
	$b = new Test_Banner();
	$b->set_injected_settings( faz_make_settings( 'ccpa', 'box', 'popup', false ) );
	$b->set_injected_contents( array( 'en' => array() ) );
	$changed = $b->apply_runtime_law_content_compatibility();
	assert_eq( $changed, false, 'blank language entry → skipped (no partial write)' );
	$contents = $b->peek( 'contents' );
	assert_true(
		! isset( $contents['en']['notice']['elements']['description'] ),
		'blank language → no description injected (left fully blank for JSON fallback)'
	);

	// 15. Missing contents key on the object → returns false, no fatal.
	$b = new Test_Banner();
	$b->unset_data_key( 'contents' ); // simulate an object without a contents slot ($data is protected)
	$b->set_injected_settings( faz_make_settings( 'ccpa', 'box', 'popup', false ) );
	assert_eq(
		$b->apply_runtime_law_content_compatibility(),
		false,
		'missing contents key → false (no fatal)'
	);

	// 16. A language stored as a JSON STRING whose copy is the GDPR default →
	//     decoded, then re-synced (the string-decode edge inside the loop).
	$b = new Test_Banner();
	$b->set_injected_settings( faz_make_settings( 'ccpa', 'box', 'popup', false ) );
	$json_lang = json_encode(
		array(
			'notice' => array(
				'elements' => array(
					'title'       => 'JSON title',
					'description' => $law_desc['gdpr'],
				),
			),
		)
	);
	$b->set_injected_contents( array( 'en' => $json_lang ) );
	$changed = $b->apply_runtime_law_content_compatibility();
	assert_eq( $changed, true, 'JSON-string language with stale GDPR copy → decoded + re-synced' );

	// ---------- Summary ----------

	echo "\n";
	echo "Tests run:    $tests_run\n";
	echo "\033[32mPassed:       $tests_passed\033[0m\n";
	if ( $tests_failed > 0 ) {
		echo "\033[31mFailed:       $tests_failed\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mAll donotsell-php unit tests passed.\033[0m\n";
	exit( 0 );
}
