<?php
/**
 * Standalone unit tests for the opt-in footer legal links.
 *
 * Two halves:
 *
 *   1. Legal_Links::build_html() — the render contract. Default OFF, no empty
 *      <nav>, unpublished pages dropped at render time, blank label falls back
 *      to the live page title, everything escaped, and — the important one —
 *      the output is INVARIANT: it must not change with the visitor's consent
 *      cookie, because Cache Compatibility Mode promises a single cached
 *      variant per URL.
 *
 *   2. Settings::sanitize_option( 'link_items' ) and the sanitize() round trip.
 *      The round-trip assertion is the regression guard for the get_excludes()
 *      entry: remove 'link_items' from Settings::get_excludes() and the
 *      recursive sanitiser walks each stored row against the EMPTY default
 *      array and wipes the list — this test must go red when that happens.
 *
 * Run from project root:
 *   php tests/unit/test-legal-links-php.php
 *   bash scripts/run-unit-tests.sh
 *
 * Exit code 0 = all tests pass; 1 = at least one failure.
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	// ---------- WP stubs (defined BEFORE the classes load) ----------

	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $url ) {
			return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) {
			return (string) $url;
		}
	}
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = '' ) {
			return $text;
		}
	}
	if ( ! function_exists( 'esc_attr__' ) ) {
		function esc_attr__( $text, $domain = '' ) {
			return esc_attr( $text );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $str ) ) );
		}
	}
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $str ) {
			return strip_tags( (string) $str );
		}
	}
	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( $title ) {
			return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '-', (string) $title ) );
		}
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $value ) {
			return abs( (int) $value );
		}
	}
	// Real coercions rather than a filter_var() stand-in: the shipped
	// faz_sanitize_bool() enumerates its negatives and faz_sanitize_bool_strict()
	// requires an explicit affirmative, and neither is what filter_var does. The
	// file guards every definition with function_exists(), so the remaining
	// stubs below still fill whatever it does not provide.
	require_once __DIR__ . '/../../includes/class-formatting.php';
	if ( ! function_exists( 'faz_sanitize_text' ) ) {
		function faz_sanitize_text( $value ) {
			if ( is_array( $value ) ) {
				return array_map( 'faz_sanitize_text', $value );
			}
			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
			return true;
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( $hook, ...$args ) {
			return null;
		}
	}
	if ( ! function_exists( 'get_site_url' ) ) {
		function get_site_url() {
			return 'https://example.test';
		}
	}
	// Backed by a global so a test can seed a stored faz_settings payload and
	// exercise the code paths that read the LIVE settings (the enqueue gate)
	// rather than an injected config. Settings::clear_cache() must be called
	// after every reseed — Settings::get() memoises in a static.
	$GLOBALS['faz_test_options'] = array();
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			return array_key_exists( $name, $GLOBALS['faz_test_options'] )
				? $GLOBALS['faz_test_options'][ $name ]
				: $default;
		}
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $name, $value ) {
			return true;
		}
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			return parse_url( (string) $url, $component );
		}
	}

	// ---------- Page fixture map, backing the four post helpers ----------
	//
	// Keyed by ID; each entry carries the publish status, the title and the
	// permalink the stubs hand back. Anything not in the map is a deleted page.

	$GLOBALS['faz_test_pages'] = array(
		10 => array( 'status' => 'publish', 'title' => 'Cookie Policy', 'url' => 'https://example.test/cookie-policy/' ),
		11 => array( 'status' => 'publish', 'title' => 'Privacy Policy', 'url' => 'https://example.test/privacy/' ),
		12 => array( 'status' => 'draft', 'title' => 'Imprint (draft)', 'url' => 'https://example.test/imprint/' ),
		13 => array( 'status' => 'trash', 'title' => 'Old Terms', 'url' => 'https://example.test/old-terms/' ),
		14 => array( 'status' => 'publish', 'title' => '', 'url' => 'https://example.test/untitled/' ),
		15 => array( 'status' => 'publish', 'title' => 'Terms & "Conditions"', 'url' => 'https://example.test/terms/?a=1&b=2' ),
	);

	if ( ! function_exists( 'get_post' ) ) {
		function get_post( $id = 0 ) {
			$id = absint( $id );
			if ( ! isset( $GLOBALS['faz_test_pages'][ $id ] ) ) {
				return null;
			}
			$row              = $GLOBALS['faz_test_pages'][ $id ];
			$post             = new stdClass();
			$post->ID         = $id;
			$post->post_status = $row['status'];
			$post->post_title = $row['title'];
			return $post;
		}
	}
	if ( ! function_exists( 'get_post_status' ) ) {
		function get_post_status( $post = null ) {
			return ( is_object( $post ) && isset( $post->post_status ) ) ? $post->post_status : false;
		}
	}
	if ( ! function_exists( 'get_permalink' ) ) {
		function get_permalink( $post = null ) {
			if ( ! is_object( $post ) || ! isset( $GLOBALS['faz_test_pages'][ $post->ID ] ) ) {
				return false;
			}
			return $GLOBALS['faz_test_pages'][ $post->ID ]['url'];
		}
	}
	if ( ! function_exists( 'get_the_title' ) ) {
		function get_the_title( $post = null ) {
			return ( is_object( $post ) && isset( $post->post_title ) ) ? $post->post_title : '';
		}
	}

	// ---------- Enqueue stubs, recording instead of enqueuing ----------

	$GLOBALS['faz_test_enqueued'] = array();
	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin() {
			return ! empty( $GLOBALS['faz_test_is_admin'] );
		}
	}
	if ( ! function_exists( 'plugins_url' ) ) {
		function plugins_url( $path = '', $plugin = '' ) {
			return 'https://example.test/wp-content/plugins/faz-cookie-manager/' . ltrim( (string) $path, '/' );
		}
	}
	if ( ! function_exists( 'wp_enqueue_style' ) ) {
		function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
			$GLOBALS['faz_test_enqueued'][] = $handle;
			return true;
		}
	}
	if ( ! defined( 'FAZ_PLUGIN_FILENAME' ) ) {
		define( 'FAZ_PLUGIN_FILENAME', __DIR__ . '/../../faz-cookie-manager.php' );
	}
	if ( ! defined( 'FAZ_VERSION' ) ) {
		define( 'FAZ_VERSION', '1.26.0' );
	}

	require_once __DIR__ . '/../../includes/class-store.php';
	require_once __DIR__ . '/../../admin/modules/settings/includes/class-settings.php';
	require_once __DIR__ . '/../../frontend/modules/legal-links/class-legal-links.php';

	use FazCookie\Admin\Modules\Settings\Includes\Settings;
	use FazCookie\Frontend\Modules\Legal_Links\Legal_Links;

	$tests_run = $tests_passed = $tests_failed = 0;

	function faz_assert_true( $condition, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $condition ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m $label\n";
			return;
		}
		$tests_failed++;
		echo "  \033[31m✗\033[0m $label\n";
	}

	function faz_assert_same( $actual, $expected, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $actual === $expected ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m $label\n";
			return;
		}
		$tests_failed++;
		echo "  \033[31m✗\033[0m $label\n";
		echo '      expected: ' . var_export( $expected, true ) . "\n";
		echo '      actual:   ' . var_export( $actual, true ) . "\n";
	}

	$links = new Legal_Links();

	echo "\n== Footer legal links: render contract ==\n\n";

	faz_assert_same(
		$links->build_html( array( 'enabled' => false, 'link_items' => array( array( 'page_id' => 10, 'label' => '' ) ) ) ),
		'',
		'default OFF: nothing rendered even with pages selected'
	);

	faz_assert_same(
		$links->build_html( array( 'enabled' => true, 'link_items' => array() ) ),
		'',
		'enabled with no pages selected renders nothing (no empty <nav>)'
	);

	faz_assert_same(
		$links->build_html( array( 'enabled' => true, 'link_items' => array( array( 'page_id' => 12, 'label' => '' ) ) ) ),
		'',
		'a single draft page renders nothing (no empty <nav>)'
	);

	$title_fallback = $links->build_html(
		array( 'enabled' => true, 'link_items' => array( array( 'page_id' => 10, 'label' => '' ) ) )
	);
	faz_assert_same(
		$title_fallback,
		'<nav class="faz-legal-links" aria-label="Legal information"><ul class="faz-legal-links-list">'
			. '<li class="faz-legal-links-item"><a href="https://example.test/cookie-policy/">Cookie Policy</a></li>'
			. '</ul></nav>',
		'blank label falls back to the live page title'
	);

	$custom_label = $links->build_html(
		array( 'enabled' => true, 'link_items' => array( array( 'page_id' => 10, 'label' => 'Cookies' ) ) )
	);
	faz_assert_true(
		false !== strpos( $custom_label, '>Cookies</a>' ) && false === strpos( $custom_label, 'Cookie Policy' ),
		'a custom label replaces the page title'
	);

	$hostile_label = $links->build_html(
		array( 'enabled' => true, 'link_items' => array( array( 'page_id' => 10, 'label' => '<b>x</b>' ) ) )
	);
	faz_assert_true(
		false !== strpos( $hostile_label, '&lt;b&gt;x&lt;/b&gt;' ) && false === strpos( $hostile_label, '<b>x</b>' ),
		'a label containing markup is escaped, not rendered as HTML'
	);

	// The URL and the auto-title both carry characters that must be escaped.
	$escaped_url = $links->build_html(
		array( 'enabled' => true, 'link_items' => array( array( 'page_id' => 15, 'label' => '' ) ) )
	);
	faz_assert_true(
		false !== strpos( $escaped_url, 'href="https://example.test/terms/?a=1&amp;b=2"' ),
		'the permalink is escaped through esc_url'
	);
	faz_assert_true(
		false !== strpos( $escaped_url, 'Terms &amp; &quot;Conditions&quot;' ),
		'the page title is escaped through esc_html'
	);

	// Draft, trashed, deleted and title-less pages all drop out; the two valid
	// ones survive, in the order the admin arranged them.
	$mixed = $links->build_html(
		array(
			'enabled'    => true,
			'link_items' => array(
				array( 'page_id' => 11, 'label' => '' ),   // publish → renders (second in output).
				array( 'page_id' => 12, 'label' => '' ),   // draft   → skipped.
				array( 'page_id' => 13, 'label' => '' ),   // trash   → skipped.
				array( 'page_id' => 99, 'label' => '' ),   // deleted → skipped.
				array( 'page_id' => 14, 'label' => '' ),   // no title, no label → skipped.
				array( 'page_id' => 10, 'label' => '' ),   // publish → renders.
			),
		)
	);
	faz_assert_same( substr_count( $mixed, '<li ' ), 2, 'draft, trashed, deleted and untitled pages are all skipped' );
	faz_assert_true(
		strpos( $mixed, '/privacy/' ) < strpos( $mixed, '/cookie-policy/' ),
		'rendered order follows the stored link_items order, not the page ID'
	);

	// Invariance — the whole point of the feature living outside the consent
	// pipeline. Same config, three different consent cookies, byte-identical
	// output, so Cache Compatibility Mode's one-variant-per-URL holds.
	$invariant_config = array(
		'enabled'    => true,
		'link_items' => array( array( 'page_id' => 10, 'label' => '' ), array( 'page_id' => 11, 'label' => 'Privacy' ) ),
	);
	unset( $_COOKIE['fazcookie-consent'] );
	$anon = $links->build_html( $invariant_config );
	$_COOKIE['fazcookie-consent'] = 'consent:yes,action:yes,categories:%5B%22necessary%22%2C%22analytics%22%5D';
	$accepted = $links->build_html( $invariant_config );
	$_COOKIE['fazcookie-consent'] = 'consent:no,action:yes,categories:%5B%22necessary%22%5D';
	$rejected = $links->build_html( $invariant_config );
	unset( $_COOKIE['fazcookie-consent'] );
	faz_assert_true( $anon === $accepted && $accepted === $rejected, 'output is byte-identical for anonymous, accepted and rejected visitors' );

	echo "\n== Footer legal links: sanitisation ==\n\n";

	faz_assert_same( Settings::sanitize_option( 'link_items', 'nope' ), array(), 'a non-array payload collapses to an empty list' );
	faz_assert_same( Settings::sanitize_option( 'link_items', null ), array(), 'null collapses to an empty list' );

	faz_assert_same(
		Settings::sanitize_option(
			'link_items',
			array(
				array( 'page_id' => 10, 'label' => 'A' ),
				array( 'page_id' => '10', 'label' => 'duplicate' ),
				array( 'page_id' => 0, 'label' => 'zero' ),
				array( 'page_id' => -5, 'label' => 'negative' ),
				'not-an-array',
				array( 'page_id' => 11, 'label' => ' B ', 'evil' => 'dropped' ),
			)
		),
		array(
			array( 'page_id' => 10, 'label' => 'A' ),
			array( 'page_id' => 11, 'label' => 'B' ),
		),
		'duplicates, zero/negative IDs, non-arrays and unknown keys are all dropped'
	);

	$clipped = Settings::sanitize_option( 'link_items', array( array( 'page_id' => 10, 'label' => str_repeat( 'x', 400 ) ) ) );
	faz_assert_same( strlen( $clipped[0]['label'] ), 120, 'an oversized label is clipped to 120 characters' );

	$oversized = array();
	for ( $i = 1; $i <= 40; $i++ ) {
		$oversized[] = array( 'page_id' => $i, 'label' => 'Page ' . $i );
	}
	faz_assert_same( count( Settings::sanitize_option( 'link_items', $oversized ) ), 20, 'the list is capped at 20 rows' );

	// Round trip through the real recursive sanitiser against the real defaults.
	// This is what proves the get_excludes() entry is present — without it the
	// stored rows are walked against the empty default array and vanish.
	$settings  = new Settings();
	$defaults  = $settings->get_defaults();
	$round_trip = Settings::sanitize(
		array(
			'legal_links' => array(
				'enabled'    => '1',
				'link_items' => array(
					array( 'page_id' => 10, 'label' => '' ),
					array( 'page_id' => 11, 'label' => 'Privacy' ),
				),
			),
		),
		$defaults
	);
	faz_assert_same( $round_trip['legal_links']['enabled'], true, "the 'enabled' flag rides the generic boolean case" );
	faz_assert_same(
		$round_trip['legal_links']['link_items'],
		array(
			array( 'page_id' => 10, 'label' => '' ),
			array( 'page_id' => 11, 'label' => 'Privacy' ),
		),
		'sanitize() round trip preserves link_items exactly (get_excludes entry present)'
	);

	$absent = Settings::sanitize( array(), $defaults );
	faz_assert_same( $absent['legal_links']['enabled'], false, 'legal_links defaults to OFF when absent from the payload' );
	faz_assert_same( $absent['legal_links']['link_items'], array(), 'link_items defaults to an empty list when absent' );

	// ---------- The stylesheet follows the OUTPUT, not the option ----------
	//
	// enabled + a non-empty link_items is NOT sufficient: every configured page
	// may since have been unpublished or deleted, in which case build_html()
	// returns '' and a stylesheet for markup that never appears would be pure
	// dead weight on every page of the site.

	echo "\n== Footer legal links: stylesheet gating ==\n\n";

	/**
	 * Seed the stored settings, reset the caches and run maybe_enqueue_styles().
	 *
	 * @param array $legal_links The legal_links group to store.
	 * @return array Handles enqueued during the call.
	 */
	function faz_test_enqueue_with( $legal_links ) {
		$GLOBALS['faz_test_options']['faz_settings'] = array( 'legal_links' => $legal_links );
		Settings::clear_cache();
		$GLOBALS['faz_test_enqueued'] = array();
		// A fresh instance each time: the memo is per-request by design.
		$instance = new Legal_Links();
		$instance->maybe_enqueue_styles();
		return $GLOBALS['faz_test_enqueued'];
	}

	faz_assert_same(
		faz_test_enqueue_with( array( 'enabled' => false, 'link_items' => array( array( 'page_id' => 10, 'label' => '' ) ) ) ),
		array(),
		'feature OFF enqueues no stylesheet'
	);

	faz_assert_same(
		faz_test_enqueue_with( array( 'enabled' => true, 'link_items' => array() ) ),
		array(),
		'enabled with an empty list enqueues no stylesheet'
	);

	faz_assert_same(
		faz_test_enqueue_with(
			array(
				'enabled'    => true,
				'link_items' => array(
					array( 'page_id' => 12, 'label' => '' ),
					array( 'page_id' => 99, 'label' => '' ),
				),
			)
		),
		array(),
		'enabled but every page unpublished or deleted: no stylesheet for markup that never renders'
	);

	faz_assert_same(
		faz_test_enqueue_with( array( 'enabled' => true, 'link_items' => array( array( 'page_id' => 10, 'label' => '' ) ) ) ),
		array( 'faz-legal-links' ),
		'a page that really renders does enqueue the stylesheet'
	);

	$GLOBALS['faz_test_is_admin'] = true;
	faz_assert_same(
		faz_test_enqueue_with( array( 'enabled' => true, 'link_items' => array( array( 'page_id' => 10, 'label' => '' ) ) ) ),
		array(),
		'admin requests never enqueue the frontend stylesheet'
	);
	$GLOBALS['faz_test_is_admin'] = false;

	// The memo must not change what gets printed: render() after an enqueue pass
	// has to emit exactly what a fresh build produces, or the cached value would
	// be a source of drift between the two hooks.
	$GLOBALS['faz_test_options']['faz_settings'] = array(
		'legal_links' => array( 'enabled' => true, 'link_items' => array( array( 'page_id' => 10, 'label' => '' ) ) ),
	);
	Settings::clear_cache();
	$memoised = new Legal_Links();
	$memoised->maybe_enqueue_styles();
	ob_start();
	$memoised->render();
	$rendered = ob_get_clean();
	faz_assert_same(
		$rendered,
		$memoised->build_html( array( 'enabled' => true, 'link_items' => array( array( 'page_id' => 10, 'label' => '' ) ) ) ),
		'render() after the enqueue pass prints exactly the freshly built markup'
	);

	echo "\n────────────────────────────────────────────\n";
	echo "$tests_passed passed, $tests_failed failed (of $tests_run)\n";
	exit( $tests_failed > 0 ? 1 : 0 );
}
