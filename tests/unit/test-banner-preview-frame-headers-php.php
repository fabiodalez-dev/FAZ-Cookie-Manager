<?php
/**
 * Regression test (2026-11 security audit, hardening note): the admin banner
 * preview frame refuses to be framed by other sites.
 *
 * faz_is_banner_preview_request() gates the frame on manage_options only, with
 * no nonce. The frame only reads data, but it is an administrator-only view,
 * and nothing stopped another site from framing it in a logged-in admin's
 * browser. The response now carries CSP frame-ancestors (this site, plus the
 * wp-admin origin when it differs) and X-Frame-Options when both share one
 * origin.
 *
 * Run: php tests/unit/test-banner-preview-frame-headers-php.php
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
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
	function apply_filters( $tag, $value ) {
		return $value;
	}

	$root = dirname( __DIR__, 2 );
	require_once $root . '/includes/class-formatting.php';
	require_once $root . '/admin/modules/settings/includes/class-settings.php';
	require_once $root . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	$passed = 0;
	$failed = 0;
	function check( $cond, $label ) {
		global $passed, $failed;
		if ( $cond ) {
			++$passed;
			echo "  \033[32mPASS\033[0m {$label}\n";
		} else {
			++$failed;
			echo "  \033[31mFAIL\033[0m {$label}\n";
		}
	}

	echo "\nbanner preview frame: anti-framing headers\n";

	$has = method_exists( Frontend::class, 'banner_preview_frame_headers' );
	check( $has, 'Frontend::banner_preview_frame_headers() exists' );
	if ( ! $has ) {
		echo "\n{$passed} passed, " . ( $failed ) . " failed\n";
		exit( 1 );
	}

	$same = Frontend::banner_preview_frame_headers( 'https://example.test/', 'https://example.test/wp-admin/' );
	check( in_array( "Content-Security-Policy: frame-ancestors 'self'", $same, true ), 'same origin: frame-ancestors is this site only' );
	check( in_array( 'X-Frame-Options: SAMEORIGIN', $same, true ), 'same origin: X-Frame-Options SAMEORIGIN for older browsers' );

	$split = Frontend::banner_preview_frame_headers( 'https://www.example.test/', 'https://admin.example.test:8443/wp-admin/' );
	check( in_array( "Content-Security-Policy: frame-ancestors 'self' https://admin.example.test:8443", $split, true ), 'wp-admin on another origin: that origin may still frame the preview' );
	check( ! in_array( 'X-Frame-Options: SAMEORIGIN', $split, true ), 'wp-admin on another origin: no X-Frame-Options, which would break the customizer' );

	$case = Frontend::banner_preview_frame_headers( 'https://Example.test/', 'https://EXAMPLE.test/wp-admin/' );
	check( in_array( 'X-Frame-Options: SAMEORIGIN', $case, true ), 'host case does not make two origins' );

	// The frame renderer sends them.
	$src  = file_get_contents( $root . '/frontend/class-frontend.php' );
	$from = strpos( $src, 'function render_banner_preview_frame' );
	$body = substr( $src, $from, strpos( $src, 'exit;', $from ) - $from );
	check( false !== strpos( $body, 'self::banner_preview_frame_headers(' ), 'render_banner_preview_frame() sends those headers before the frame' );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
