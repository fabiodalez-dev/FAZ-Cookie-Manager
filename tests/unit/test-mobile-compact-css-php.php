<?php
/**
 * Standalone tests for the compact phone layout stylesheet.
 *
 * Drives the real Frontend::prepare_banner_styles() against the real 6.2.0
 * templates, so what is asserted is the CSS a visitor receives, not a copy of
 * it. The geometry itself (no clipped label, accept/reject identical, focus
 * order equal to visual order, chevron clear of the label) is measured by the
 * E2E spec mobile-compact-layout.spec.ts; this file pins the rules that
 * geometry depends on, so a later edit that reintroduces one of the reviewed
 * defects fails here in milliseconds instead of only on a full browser run.
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	if ( ! defined( 'FAZ_VERSION' ) ) {
		define( 'FAZ_VERSION', 'unit' );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}

	$GLOBALS['faz_test_layout']     = 'comfortable';
	$GLOBALS['faz_test_transients'] = array();

	function get_option( $name, $fallback = false ) {
		if ( 'faz_settings' === $name ) {
			return array( 'banner_control' => array( 'mobile_layout' => $GLOBALS['faz_test_layout'] ) );
		}
		return $fallback;
	}
	function get_transient( $key ) {
		return false; // Always rebuild: the test is about the assembled CSS.
	}
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['faz_test_transients'][ $key ] = $value;
		return true;
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;

	function faz_mcss_assert( $condition, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		++$tests_run;
		if ( $condition ) {
			++$tests_passed;
			echo "  \033[32m✓\033[0m {$label}\n";
			return;
		}
		++$tests_failed;
		echo "  \033[31m✗\033[0m {$label}\n";
	}

	function faz_mcss_build( $layout, $raw_css ) {
		$GLOBALS['faz_test_layout']     = $layout;
		$GLOBALS['faz_test_transients'] = array();
		$css                            = \FazCookie\Frontend\Frontend::prepare_banner_styles( $raw_css );
		return array( $css, array_keys( $GLOBALS['faz_test_transients'] ) );
	}

	/**
	 * Body of the first `@media (max-width:440px){...}` block that starts at or
	 * after $offset, brace-matched so nested rules do not cut it short.
	 */
	function faz_mcss_media_body( $css, $query, $offset = 0 ) {
		$start = strpos( $css, $query, $offset );
		if ( false === $start ) {
			return '';
		}
		$open  = strpos( $css, '{', $start );
		$depth = 0;
		$len   = strlen( $css );
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $css[ $i ] ) {
				++$depth;
			} elseif ( '}' === $css[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $css, $open + 1, $i - $open - 1 );
				}
			}
		}
		return '';
	}

	echo "\n== Compact phone layout CSS ==\n";

	$templates = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/templates/6.2.0/template.json' ), true );
	faz_mcss_assert( is_array( $templates ) && count( $templates ) > 0, '6.2.0 templates load' );

	$by_type = array();
	foreach ( (array) $templates as $template ) {
		$by_type[ $template['type'] ] = $template['css'];
	}

	foreach ( array( 'box', 'banner', 'classic', 'popup' ) as $type ) {
		$raw = isset( $by_type[ $type ] ) ? $by_type[ $type ] : '';
		faz_mcss_assert( '' !== $raw, "{$type}: template CSS present" );

		list( $comfortable, $comfortable_keys ) = faz_mcss_build( 'comfortable', $raw );
		list( $compact, $compact_keys )         = faz_mcss_build( 'compact', $raw );
		list( $invalid, )                       = faz_mcss_build( 'compact;}body{display:none', $raw );

		// The default must stay the stylesheet existing installs already get:
		// the compact rules are appended, never interleaved, and only on opt-in.
		faz_mcss_assert( 0 === strpos( $compact, $comfortable ), "{$type}: compact = comfortable stylesheet + an appended block (default untouched)" );
		faz_mcss_assert( $invalid === $comfortable, "{$type}: an unknown stored layout falls back to the comfortable stylesheet" );
		faz_mcss_assert( false === strpos( $comfortable, 'align-items:stretch' ), "{$type}: comfortable carries none of the compact rules" );
		faz_mcss_assert( $comfortable_keys !== $compact_keys && false !== strpos( (string) reset( $compact_keys ), '_compact_' ), "{$type}: the layout is part of the stylesheet cache key" );

		$block = substr( $compact, strlen( $comfortable ) );
		$m440  = faz_mcss_media_body( $block, '@media (max-width:440px)' );
		$m360  = faz_mcss_media_body( $block, '@media (max-width:360px)' );
		faz_mcss_assert( '' !== $m440 && '' !== $m360, "{$type}: compact emits the 440px and 360px blocks" );

		// P2-1: labels wrap instead of being clipped, and the row stretches so
		// a two-line accept is never taller than a one-line reject.
		faz_mcss_assert( false === strpos( $block, 'nowrap' ), "{$type}: no compact rule forces labels onto one line" );
		faz_mcss_assert( 1 === preg_match( '/#faz-consent \.faz-notice-btn-wrapper \.faz-btn\{[^}]*white-space:normal/', $m440 ), "{$type}: compact buttons wrap their labels" );
		faz_mcss_assert( 1 === preg_match( '/#faz-consent \.faz-notice-btn-wrapper \.faz-btn\{[^}]*overflow-wrap:break-word/', $m440 ), "{$type}: an over-long word breaks inside the box instead of overflowing it" );
		faz_mcss_assert( 1 === preg_match( '/#faz-consent \.faz-notice-btn-wrapper\{[^}]*align-items:stretch/', $m440 ), "{$type}: the button row stretches every button to the tallest" );
		faz_mcss_assert( 1 === preg_match( '/#faz-consent \.faz-notice-btn-wrapper \.faz-btn\{[^}]*flex:1 1 0;/', $m440 ), "{$type}: accept and reject share one flex basis (equal width by construction)" );
		faz_mcss_assert( 1 === preg_match( '/#faz-consent \.faz-notice-btn-wrapper \.faz-btn\{[^}]*min-height:44px/', $m440 ), "{$type}: tap targets stay at least 44px tall" );

		// P2-3: visual order must equal DOM (= focus) order. The only `order`
		// the compact block may set is 0, which hands the sequence back to
		// the markup; any other value reorders boxes without reordering Tab.
		preg_match_all( '/order:\s*([^;}]+)/', $block, $orders );
		$non_zero = array_filter(
			$orders[1],
			function ( $value ) {
				return '0' !== trim( $value );
			}
		);
		faz_mcss_assert( count( $orders[1] ) > 0 && array() === $non_zero, "{$type}: compact resets order to 0 and never sets another value" );
		faz_mcss_assert( 1 === preg_match( '/#faz-consent \.faz-notice-btn-wrapper \.faz-btn\{[^}]*order:0/', $m440 ), "{$type}: the reset targets every notice button" );

		// Specificity: the reset must be able to beat the template's boosted
		// `.faz-notice-btn-wrapper .faz-btn-accept{order:1}` (1-2-0) on
		// document order, so it has to come after it.
		$template_order = strpos( $compact, '.faz-notice-btn-wrapper .faz-btn-accept{order: 1' );
		if ( false !== $template_order ) {
			faz_mcss_assert( strpos( $compact, '.faz-notice-btn-wrapper .faz-btn{order:0' ) > $template_order, "{$type}: the order reset comes after the template rule it overrides" );
		}

		// P2-2: the classic chevron keeps the end padding it is drawn inside.
		faz_mcss_assert( 1 === preg_match( '/#faz-consent\.faz-classic-top \.faz-notice-btn-wrapper \.faz-btn-customize,#faz-consent\.faz-classic-bottom \.faz-notice-btn-wrapper \.faz-btn-customize\{padding-right:28px;\}/', $m440 ), "{$type}: classic customise keeps 28px end padding for its chevron" );

		// 360px: accept/reject paired, customise on a row of its own.
		faz_mcss_assert( false !== strpos( $m360, '.faz-btn-reject{flex:1 1 40%;}' ) && false !== strpos( $m360, '.faz-btn-customize{flex:1 1 100%;}' ), "{$type}: at 360px and below customise takes its own row and the pair stays together" );

		// The Do-Not-Sell control. It is the fourth item in this wrapper and the
		// one the compared-pair rules do not describe, so every property below
		// is load-bearing rather than decorative.
		//
		// Reached in ordinary operation, not only by hand: Geo_Runtime turns
		// donotSell on for a US visitor while applicableLaw stays 'gdpr', and
		// class-template.php keeps the button precisely because its status is
		// true. For that visitor this row IS the opt-out.
		$dns = array();
		preg_match( '/#faz-consent \.faz-notice-btn-wrapper \[data-faz-tag="donotsell-button"\]\{([^}]*)\}/', $m440, $dns );
		faz_mcss_assert( ! empty( $dns ), "{$type}: compact describes the Do-Not-Sell control" );
		$dns_body = isset( $dns[1] ) ? $dns[1] : '';

		// Without a full basis it shares the row with the pair, and at 360px
		// their 40% bases leave it almost nothing to grow into: it collapsed to
		// a few pixels wide.
		faz_mcss_assert( false !== strpos( $dns_body, 'flex:1 1 100%' ), "{$type}: the Do-Not-Sell control takes a full row, not a share of the pair's" );
		// Anchored to a declaration boundary, not a substring: `width:100%`
		// also matches `min-width:100%` and `max-width:100%`, and neither of
		// those makes the row span the wrapper — so the plain substring check
		// could not have caught that regression.
		// Bounded on both sides: `width:100%junk` is an invalid value a browser
		// discards, so without the trailing boundary the test could pass on a
		// rule that sets no usable width at all.
		faz_mcss_assert( 1 === preg_match( '/(?:^|;)width:100%(?=;|$)/', $dns_body ), "{$type}: the Do-Not-Sell row spans the wrapper" );
		// "Do Not Sell or Share My Personal Information" is statutory wording
		// that cannot be shortened, and it does not fit one line on a phone.
		faz_mcss_assert( false !== strpos( $dns_body, 'white-space:normal' ), "{$type}: the statutory Do-Not-Sell label is allowed to wrap" );
		// A link, not one of the compared buttons: the template draws it
		// left-aligned and borderless, which the centred flex box would undo.
		faz_mcss_assert( false !== strpos( $dns_body, 'display:block' ), "{$type}: the Do-Not-Sell control keeps its link look, not the button box" );

		// Matched by data attribute on purpose. The shortcode emits either
		// `.faz-btn.faz-btn-do-not-sell` or a bare <a> with no class at all, and
		// the attribute is the only thing both variants carry: narrowing this
		// selector to the class would silently drop the link variant, which is a
		// flex item just the same.
		faz_mcss_assert( false === strpos( $m440, '.faz-btn-do-not-sell{' ), "{$type}: the Do-Not-Sell rule matches the attribute, so the <a> variant is covered too" );

		// Source order, not specificity, decides this one. Both selectors are
		// 1-1-0 (#faz-consent + .faz-notice-btn-wrapper + one class/attribute),
		// so the generic `.faz-btn{flex:1 1 0}` above wins unless the
		// Do-Not-Sell rule comes after it. Reordering the block would restore
		// the collapse with no visible change to either rule.
		$generic_at = strpos( $m440, '#faz-consent .faz-notice-btn-wrapper .faz-btn{' );
		$dns_at     = strpos( $m440, '#faz-consent .faz-notice-btn-wrapper [data-faz-tag="donotsell-button"]{' );
		faz_mcss_assert( false !== $generic_at && false !== $dns_at && $dns_at > $generic_at, "{$type}: the Do-Not-Sell rule comes after the generic button rule it overrides" );
	}

	// The template does draw that chevron with an absolute ::after anchored to
	// the end padding - the reason the classic rule above exists. If the
	// template ever stops doing that, the rule is dead weight and should go.
	faz_mcss_assert( 1 === preg_match( '/\.faz-btn-customize::after\{position: absolute;[^}]*right: 12px/', $by_type['classic'] ), 'classic template still draws the customise chevron 12px from the end' );

	echo "\n{$tests_passed}/{$tests_run} assertions passed\n";
	exit( $tests_failed > 0 ? 1 : 0 );
}
