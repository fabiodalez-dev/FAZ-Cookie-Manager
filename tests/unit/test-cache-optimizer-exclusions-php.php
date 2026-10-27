<?php
/**
 * Standalone tests for the optimiser and page-cache seams added by the
 * 2026-10-23 cache-plugin matrix.
 *
 * Subsystem: cache-plugin-compat
 *
 * Covers, callback by callback, what each cache plugin's own API is handed:
 *   - W3 Total Cache "Minify (auto)": w3tc_keep_own_tag() answers false for a
 *     FAZ tag (by hint, by keyword, by own asset URL) and passes a third-party
 *     tag and a `false` decision through;
 *   - WP-Optimize "Minify": wpo_exclude_own_scripts() adds the FAZ URL
 *     keywords once, and passes a non-array through;
 *   - SiteGround Optimizer: sgo_exclude_own_handles() lists every FAZ handle
 *     for the minify/async passes AND the printed ids (`-js`, `-js-before`,
 *     `-css`) the combinator compares, under both base names;
 *     sgo_exclude_own_urls() feeds "Remove Query Strings" a pattern that
 *     matches FAZ assets in SGO's own regex and nothing else;
 *   - Super Page Cache: super_page_cache_bypass() is false until a FAZ veto
 *     has defined DONOTCACHEPAGE, then true, and never un-bypasses;
 *   - Hummingbird Asset Optimization: hummingbird_keep_own_resource() keeps
 *     FAZ handles (including the DSAR stylesheet) and lets the rest through;
 *   - Jetpack Boost: jetpack_boost_keep_own_scripts() removes FAZ tags
 *     (hinted, keyworded, inline bootstrap/config) from the move list and
 *     re-indexes it; jetpack_boost_do_concat() refuses own handles;
 *   - the `data-noptimize` hint (Autoptimize / Breeze) is on every own
 *     external AND inline tag;
 *   - WP Fastest Cache: Cache_Compatibility::define_no_cache_constants()
 *     calls wpfc_exclude_current_page() when it exists, exactly once per
 *     veto, since WP Fastest Cache reads neither DONOTCACHEPAGE (outside a
 *     403 / Wordfence 503 / Divi) nor Cache-Control.
 *
 * Validated against the unfixed build (origin/main at 0794ed21): every
 * section below reports FAIL there — the callbacks do not exist, the hint
 * is absent and the WPFC helper is never called.
 *
 * Run from project root:
 *   php tests/unit/test-cache-optimizer-exclusions-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'FAZ_PLUGIN_URL', 'https://example.test/wp-content/plugins/faz-cookie-manager/' );

	function is_admin() { // phpcs:ignore
		return false;
	}
	$GLOBALS['wpfc_calls'] = 0;
	function wpfc_exclude_current_page() { // phpcs:ignore
		$GLOBALS['wpfc_calls']++;
	}

	require dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
	require dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';

	use FazCookie\Frontend\Frontend;
	use FazCookie\Includes\Cache_Compatibility;

	$passed = 0;
	$failed = 0;
	function cx_check( $condition, $label ) { // phpcs:ignore
		global $passed, $failed;
		if ( $condition ) {
			$passed++;
			echo "  PASS {$label}\n";
			return;
		}
		$failed++;
		echo "  FAIL {$label}\n";
	}
	/** Call a callback that may not exist on an unfixed build: a missing method is a FAIL, not a fatal. */
	function cx_call( $frontend, $method, ...$args ) { // phpcs:ignore
		if ( ! method_exists( $frontend, $method ) ) {
			return '__missing__';
		}
		return $frontend->$method( ...$args );
	}

	$frontend = ( new ReflectionClass( Frontend::class ) )->newInstanceWithoutConstructor();
	$name     = new ReflectionProperty( Frontend::class, 'plugin_name' );
	$name->setAccessible( true );
	$name->setValue( $frontend, 'faz-cookie-manager' );

	$hinted_tag   = '<script data-wp-strategy="defer" defer id="faz-cookie-manager-js" src="' . FAZ_PLUGIN_URL . 'frontend/js/script.min.js?ver=1.34.3" data-no-defer="1" data-no-optimize="1" data-no-minify="1" data-cfasync="false" data-ao-skip="1" data-noptimize="1"></script>';
	$bare_own_tag = '<script src="' . FAZ_PLUGIN_URL . 'frontend/js/a11y.min.js?ver=1.34.3"></script>';
	$inline_boot  = '<script id="faz-cookie-manager-js-before">window._fazBootstrap={};</script>';
	$inline_cfg   = '<script>var _fazConfig = {"a":1};</script>';
	$theme_tag    = '<script src="https://example.test/wp-content/themes/x/app.js?ver=2" id="theme-app-js"></script>';
	$vendor_tag   = '<script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>';

	echo "W3 Total Cache: w3tc_minify_js_do_tag_minification / _css_\n";
	cx_check( false === cx_call( $frontend, 'w3tc_keep_own_tag', true, $hinted_tag, FAZ_PLUGIN_URL . 'frontend/js/script.min.js' ), 'hinted FAZ tag is kept out of the bundle' );
	cx_check( false === cx_call( $frontend, 'w3tc_keep_own_tag', true, '<script id="renamed-js"></script>', FAZ_PLUGIN_URL . 'frontend/js/a11y.min.js' ), 'an own asset URL is enough without hints or keywords in the tag' );
	cx_check( false === cx_call( $frontend, 'w3tc_keep_own_tag', true, $bare_own_tag, '' ), 'an own src in the tag is enough without a resolved file' );
	cx_check( false === cx_call( $frontend, 'w3tc_keep_own_tag', true, '<link rel="stylesheet" id="faz-cookie-manager-css-css" href="https://example.test/wp-content/uploads/faz-cookie-manager/assets/banner.css">', 'https://example.test/wp-content/uploads/faz-cookie-manager/assets/banner.css' ), 'the banner stylesheet (uploads URL, keyword in the tag) is kept out' );
	cx_check( true === cx_call( $frontend, 'w3tc_keep_own_tag', true, $theme_tag, 'https://example.test/wp-content/themes/x/app.js' ), 'a theme script is still minified' );
	cx_check( false === cx_call( $frontend, 'w3tc_keep_own_tag', false, $theme_tag, 'https://example.test/wp-content/themes/x/app.js' ), 'a decision already false is passed through' );

	echo "WP-Optimize: wp-optimize-minify-default-exclusions\n";
	$wpo = cx_call( $frontend, 'wpo_exclude_own_scripts', array( 'jquery.js' ) );
	cx_check( is_array( $wpo ) && in_array( 'faz-cookie-manager', $wpo, true ) && in_array( 'faz-fw', $wpo, true ) && in_array( 'jquery.js', $wpo, true ), 'both FAZ keywords are appended to the existing list' );
	cx_check( is_array( $wpo ) && $wpo === cx_call( $frontend, 'wpo_exclude_own_scripts', $wpo ), 'applying it twice adds nothing' );
	cx_check( 'x' === cx_call( $frontend, 'wpo_exclude_own_scripts', 'x' ), 'a non-array list is passed through' );

	echo "SiteGround Optimizer: handle lists, ids and Remove Query Strings\n";
	$sgo = cx_call( $frontend, 'sgo_exclude_own_handles', array( 'jquery-core' ) );
	$sgo = is_array( $sgo ) ? $sgo : array();
	foreach ( array( 'faz-cookie-manager', 'faz-cookie-manager-static-config', 'faz-cookie-manager-a11y', 'faz-cookie-manager-css', 'faz-cookie-manager-css-page', 'faz-fw-css', 'faz-fw-css-page', 'faz-cookie-manager-tcf-cmp', 'faz-fw', 'faz-fw-a11y', 'faz-dsar-form', 'faz-dnsmpi-form', 'faz-dsar' ) as $h ) {
		cx_check( in_array( $h, $sgo, true ), "minify/async exclude carries the handle {$h}" );
	}
	foreach ( array( 'faz-cookie-manager-js', 'faz-cookie-manager-js-before', 'faz-cookie-manager-js-extra', 'faz-cookie-manager-static-config-js', 'faz-fw-static-config-js', 'faz-cookie-manager-css-css' ) as $id ) {
		cx_check( in_array( $id, $sgo, true ), "combine exclude carries the printed id {$id}" );
	}
	cx_check( in_array( 'jquery-core', $sgo, true ), 'the site\'s own exclusions are kept' );
	cx_check( count( $sgo ) === count( array_unique( $sgo ) ), 'no duplicate entries' );
	cx_check( 'x' === cx_call( $frontend, 'sgo_exclude_own_handles', 'x' ), 'a non-array list is passed through' );
	$rqs = cx_call( $frontend, 'sgo_exclude_own_urls', array() );
	$rqs = is_array( $rqs ) ? $rqs : array();
	$sgo_regex = '~' . implode( '|', $rqs ) . '~'; // Exactly how Front_End_Optimization::remove_query_strings() uses the list.
	cx_check( ! empty( $rqs ) && 1 === preg_match( $sgo_regex, FAZ_PLUGIN_URL . 'frontend/js/script.min.js?ver=1.34.3' ), 'Remove Query Strings keeps ?ver= on script.min.js' );
	cx_check( ! empty( $rqs ) && 1 === preg_match( $sgo_regex, 'https://example.test/wp-content/uploads/faz-cookie-manager/assets/config-abc.js' ), 'and on the content-hashed config file' );
	cx_check( ! empty( $rqs ) && 0 === preg_match( $sgo_regex, 'https://example.test/wp-content/themes/x/app.js?ver=2' ), 'a theme asset still loses its query string' );

	echo "Super Page Cache: swcfpc_cache_bypass\n";
	cx_check( false === cx_call( $frontend, 'super_page_cache_bypass', false ), 'no FAZ veto yet: not bypassed' );
	cx_check( true === cx_call( $frontend, 'super_page_cache_bypass', true ), 'a bypass decided by Super Page Cache itself is never undone' );

	echo "Hummingbird: wphb_minify_resource / wphb_combine_resource\n";
	cx_check( false === cx_call( $frontend, 'hummingbird_keep_own_resource', true, 'faz-cookie-manager' ), 'the runtime handle is kept as is' );
	cx_check( false === cx_call( $frontend, 'hummingbird_keep_own_resource', true, 'faz-cookie-manager-static-config' ), 'the config handle is kept as is' );
	cx_check( false === cx_call( $frontend, 'hummingbird_keep_own_resource', true, 'faz-dsar' ), 'the DSAR stylesheet handle is kept as is' );
	cx_check( true === cx_call( $frontend, 'hummingbird_keep_own_resource', true, 'theme-app' ), 'a theme handle is still optimised' );
	cx_check( false === cx_call( $frontend, 'hummingbird_keep_own_resource', false, 'theme-app' ), 'a decision already false is passed through' );

	echo "Jetpack Boost: render-blocking exclusion and concatenation\n";
	// Boost's real payload: preg_match_all( …, PREG_OFFSET_CAPTURE ) elements, `array( markup, offset )`.
	$offset_list = array( array( $theme_tag, 10 ), array( $hinted_tag, 200 ), array( $inline_boot, 900 ), array( $vendor_tag, 1200 ), array( $inline_cfg, 1500 ) );
	$moved       = cx_call( $frontend, 'jetpack_boost_keep_own_scripts', $offset_list );
	cx_check( array( array( $theme_tag, 10 ), array( $vendor_tag, 1200 ) ) === $moved, 'PREG_OFFSET_CAPTURE shape: FAZ entries (hinted, inline bootstrap, inline config) leave the move list, offsets intact, list re-indexed' );
	$moved_strings = cx_call( $frontend, 'jetpack_boost_keep_own_scripts', array( $theme_tag, $hinted_tag, $inline_boot, $vendor_tag, $inline_cfg ) );
	cx_check( array( $theme_tag, $vendor_tag ) === $moved_strings, 'plain-string shape: same result' );
	cx_check( array( array( 'x' => 1 ), 42 ) === cx_call( $frontend, 'jetpack_boost_keep_own_scripts', array( array( 'x' => 1 ), 42 ) ), 'an element of another shape is left to Boost' );
	cx_check( 'x' === cx_call( $frontend, 'jetpack_boost_keep_own_scripts', 'x' ), 'a non-array list is passed through' );
	cx_check( false === cx_call( $frontend, 'jetpack_boost_do_concat', true, 'faz-cookie-manager-a11y' ), 'js_do_concat refuses an own handle' );
	cx_check( false === cx_call( $frontend, 'jetpack_boost_do_concat', true, 'faz-dsar' ), 'css_do_concat refuses the DSAR stylesheet' );
	cx_check( true === cx_call( $frontend, 'jetpack_boost_do_concat', true, 'jquery-core' ), 'other handles concatenate as before' );

	echo "Autoptimize / Breeze: data-noptimize on every own tag\n";
	$tagged = cx_call( $frontend, 'tag_own_scripts_nooptimize', '<script src="' . FAZ_PLUGIN_URL . 'frontend/js/script.min.js"></script>', 'faz-cookie-manager' );
	cx_check( is_string( $tagged ) && false !== strpos( $tagged, 'data-noptimize="1"' ) && false !== strpos( $tagged, 'data-ao-skip="1"' ), 'external tag: data-noptimize next to the five existing hints' );
	$attrs = cx_call( $frontend, 'tag_own_inline_attributes_nooptimize', array( 'id' => 'faz-cookie-manager-js-before' ), 'window._fazBootstrap={};' );
	cx_check( is_array( $attrs ) && isset( $attrs['data-noptimize'] ) && '1' === $attrs['data-noptimize'], 'inline tag (wp_inline_script_attributes): data-noptimize set' );
	$theme_attrs = cx_call( $frontend, 'tag_own_inline_attributes_nooptimize', array( 'id' => 'theme-app-js-before' ), 'x' );
	cx_check( is_array( $theme_attrs ) && ! isset( $theme_attrs['data-noptimize'] ), 'a theme inline tag is not touched' );

	echo "WP Fastest Cache: the per-page exclusion is called by the shared veto\n";
	$GLOBALS['wpfc_calls'] = 0;
	Cache_Compatibility::define_no_cache_constants();
	cx_check( 1 === $GLOBALS['wpfc_calls'], 'define_no_cache_constants() calls wpfc_exclude_current_page() once' );
	cx_check( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'DONOTCACHEPAGE is still defined for every other cache' );
	cx_check( true === cx_call( $frontend, 'super_page_cache_bypass', false ), 'after the veto, Super Page Cache is told to bypass' );

	echo "\n" . ( 0 === $failed ? "ALL PASS ({$passed})\n" : "FAILED: {$failed}, passed: {$passed}\n" );
	exit( 0 === $failed ? 0 : 1 );
}
