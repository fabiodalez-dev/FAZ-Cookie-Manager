<?php
/**
 * Plugin Name: FAZ E2E FlyingPress Cloud Optimizer Mock
 * Description: Test-only. Answers FlyingPress's Cloud Optimizer (page-optimizer.flyingpress.com) with a deterministic optimisation set, serves stand-ins for self-hosted third-party files, and can move FAZ's own assets onto a fake CDN host. Activated only by flyingpress-optimization-compat.spec.ts. Never shipped — lives under tests/e2e/fixtures.
 * Version: 0.1.0
 *
 * Why: the FlyingPress copy on the test site carries a licence shim whose
 * `pre_http_request` callback (priority 10, flying-press.php) answers the Cloud
 * Optimizer with `{}`. Every optimisation that depends on that answer — the
 * third-party delay (external_domains), remove-unused-CSS (used_css), lazy
 * render (content_visibility_targets) — was therefore a no-op, and the tests
 * that covered them could not fail. This plugin answers at priority 20, after
 * the shim, with:
 *
 *   external_domains           ["cdn.faz-e2e.test", "www.google.com"]
 *       A fake CDN host and the reCAPTCHA host, i.e. the two field reports
 *       (FAZ served from a CDN; a captcha URL inside FAZ's inline config).
 *   used_css                   ".e2e-rucss-marker{…}body{margin:0}" plus, as
 *       the real service does, every rule of the page's same-origin
 *       stylesheets whose selector contains one of the request's
 *       css_rucss_include_selectors (read from the x-config header). Nothing
 *       FAZ-specific is in the answer unless the caller asked for it.
 *   content_visibility_targets every div/section/article/footer data-uid found
 *       OUTSIDE <script> bodies, height 300 — so lazy_render visibly runs.
 *   critical_* / non_critical_bg_elems   [] (nothing marked critical).
 *
 * Options (all off unless set to 'yes' / a slug):
 *
 *   faz_e2e_fp_cdn_assets = 'yes'
 *       Rewrites the src/href of every faz-cookie-manager-* / faz-fw-* handle
 *       to http://cdn.faz-e2e.test/<same path>, the way a CDN-rewrite plugin
 *       (BunnyCDN, a host's CDN integration) does.
 *
 *   faz_e2e_fp_selfhost_extra = 'yes'
 *       Adds cdn.faz-e2e.test and www.googletagmanager.com to
 *       `flying_press_selfhost_external_domains`. FlyingPress's own list holds
 *       only library CDNs, so without this "self-host third-party" never
 *       touched a tracker and its test proved nothing.
 *
 *   faz_e2e_fp_cdn_control_page = '<post_name>'
 *       On that page, prints <script id="faz-e2e-cdn-control-js"
 *       src="http://cdn.faz-e2e.test/faz-e2e/control-lib.js">: a non-FAZ,
 *       non-tracker script on the fake CDN, the positive control that the
 *       third-party delay and the self-host pass actually ran.
 *
 * Every Cloud Optimizer call is counted in the option faz_e2e_fp_mock_last
 * (hits, include selectors received, number of lazy-render targets), so a spec
 * can tell "the mock answered" from "FlyingPress used a cached answer".
 *
 * @package FazCookie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FAZ_E2E_FP_CDN_HOST = 'cdn.faz-e2e.test';

/**
 * Whether a fixture switch is on.
 *
 * @param string $name Option name.
 * @return bool
 */
function faz_e2e_fp_mock_flag( $name ) {
	return 'yes' === get_option( $name, 'no' );
}

/**
 * A WP_Http-shaped response.
 *
 * @param string $body         Body.
 * @param string $content_type Content type.
 * @return array
 */
function faz_e2e_fp_mock_response( $body, $content_type ) {
	return array(
		'headers'  => array( 'content-type' => $content_type ),
		'body'     => $body,
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * Local file behind a same-origin (or fake-CDN) stylesheet URL, or ''.
 *
 * @param string $href Stylesheet URL as printed.
 * @return string
 */
function faz_e2e_fp_mock_local_css( $href ) {
	$href = html_entity_decode( (string) $href, ENT_QUOTES );
	$host = (string) wp_parse_url( $href, PHP_URL_HOST );
	$path = (string) wp_parse_url( $href, PHP_URL_PATH );
	if ( '' === $path ) {
		return '';
	}
	$own_hosts = array( (string) wp_parse_url( home_url(), PHP_URL_HOST ), (string) wp_parse_url( site_url(), PHP_URL_HOST ), FAZ_E2E_FP_CDN_HOST );
	if ( '' !== $host && ! in_array( $host, $own_hosts, true ) ) {
		return '';
	}
	$site_path = rtrim( (string) wp_parse_url( site_url( '/' ), PHP_URL_PATH ), '/' );
	if ( '' !== $site_path && 0 === strpos( $path, $site_path ) ) {
		$path = substr( $path, strlen( $site_path ) );
	}
	$root = realpath( ABSPATH );
	$real = realpath( ABSPATH . ltrim( $path, '/' ) );
	if ( ! $root || ! $real || 0 !== strpos( $real, $root ) || '.css' !== substr( $real, -4 ) || ! is_readable( $real ) ) {
		return '';
	}
	return $real;
}

/**
 * Rules of $css whose selector contains one of $keywords, as `selector{decls}`.
 *
 * Flat on purpose (an @media wrapper is dropped and its inner rules are matched
 * on their own): enough to emulate "always include these selectors" and to
 * let a spec find a selector by its text.
 *
 * @param string   $css      Stylesheet text.
 * @param string[] $keywords Include keywords.
 * @return string
 */
function faz_e2e_fp_mock_matching_rules( $css, array $keywords ) {
	$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) $css );
	$out = '';
	if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
		return $out;
	}
	foreach ( $rules as $rule ) {
		$selector = trim( $rule[1] );
		if ( '' === $selector || '@' === $selector[0] ) {
			continue;
		}
		foreach ( $keywords as $keyword ) {
			if ( '' !== $keyword && false !== stripos( $selector, $keyword ) ) {
				$out .= $selector . '{' . trim( $rule[2] ) . '}';
				break;
			}
		}
	}
	return $out;
}

/**
 * The deterministic Cloud Optimizer answer for one request.
 *
 * @param array $args wp_remote_post() arguments FlyingPress sent.
 * @return array
 */
function faz_e2e_fp_mock_optimizations( $args ) {
	$html    = isset( $args['body'] ) ? (string) $args['body'] : '';
	$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? array_change_key_case( $args['headers'], CASE_LOWER ) : array();
	$config  = isset( $headers['x-config'] ) ? json_decode( (string) $headers['x-config'], true ) : array();
	$include = ( is_array( $config ) && isset( $config['css_rucss_include_selectors'] ) && is_array( $config['css_rucss_include_selectors'] ) )
		? array_values( array_filter( array_map( 'strval', $config['css_rucss_include_selectors'] ) ) )
		: array();

	$used_css = '.e2e-rucss-marker{outline:0}body{margin:0}';
	if ( ! empty( $include ) && preg_match_all( '/<link[^>]*\srel=[\'"]stylesheet[\'"][^>]*>/i', $html, $links ) ) {
		foreach ( $links[0] as $link ) {
			if ( ! preg_match( '/\shref=[\'"]([^\'"]+)[\'"]/i', $link, $href ) ) {
				continue;
			}
			$file = faz_e2e_fp_mock_local_css( $href[1] );
			if ( '' !== $file ) {
				$used_css .= faz_e2e_fp_mock_matching_rules( (string) file_get_contents( $file ), $include ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}
	}

	$targets = array();
	$outside = (string) preg_replace( '#<script\b[^>]*>.*?</script\s*>#is', '', $html );
	if ( preg_match_all( '/<(?:div|section|article|footer)\b[^>]*\sdata-uid="(\d+)"/i', $outside, $uids ) ) {
		foreach ( array_slice( array_unique( $uids[1] ), 0, 200 ) as $uid ) {
			$targets[] = array(
				'uid'    => (int) $uid,
				'height' => 300,
			);
		}
	}

	$previous = get_option( 'faz_e2e_fp_mock_last', array() );
	update_option(
		'faz_e2e_fp_mock_last',
		array(
			'hits'              => ( is_array( $previous ) && isset( $previous['hits'] ) ? (int) $previous['hits'] : 0 ) + 1,
			'include_selectors' => $include,
			'targets'           => count( $targets ),
			'used_css_bytes'    => strlen( $used_css ),
		),
		false
	);

	return array(
		'external_domains'           => array( FAZ_E2E_FP_CDN_HOST, 'www.google.com' ),
		'used_css'                   => $used_css,
		'content_visibility_targets' => $targets,
		'critical_images'            => array(),
		'critical_bg_images'         => array(),
		'critical_video_posters'     => array(),
		'critical_iframes'           => array(),
		'critical_fonts'             => array(),
		'non_critical_bg_elems'      => array(),
	);
}

add_filter(
	'pre_http_request',
	static function ( $response, $args, $url ) {
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( 'page-optimizer.flyingpress.com' === $host ) {
			return faz_e2e_fp_mock_response( (string) wp_json_encode( faz_e2e_fp_mock_optimizations( is_array( $args ) ? $args : array() ) ), 'application/json' );
		}
		// Self-host downloads: a deterministic stand-in instead of the network.
		if ( FAZ_E2E_FP_CDN_HOST === $host || 'www.googletagmanager.com' === $host ) {
			return faz_e2e_fp_mock_response(
				'/* FAZ E2E self-host stand-in */ window.__fazE2eSelfHosted = (window.__fazE2eSelfHosted || 0) + 1;',
				'application/javascript'
			);
		}
		return $response;
	},
	20,
	3
);

add_filter(
	'flying_press_selfhost_external_domains',
	static function ( $domains ) {
		if ( ! faz_e2e_fp_mock_flag( 'faz_e2e_fp_selfhost_extra' ) ) {
			return $domains;
		}
		$domains   = is_array( $domains ) ? $domains : array();
		$domains[] = FAZ_E2E_FP_CDN_HOST;
		$domains[] = 'www.googletagmanager.com';
		return $domains;
	},
	PHP_INT_MAX
);

if ( faz_e2e_fp_mock_flag( 'faz_e2e_fp_cdn_assets' ) ) {
	$faz_e2e_fp_cdn_rewrite = static function ( $src, $handle ) {
		$handle = (string) $handle;
		$own    = 'faz-cookie-manager' === $handle || 0 === strpos( $handle, 'faz-cookie-manager-' )
			|| 'faz-fw' === $handle || 0 === strpos( $handle, 'faz-fw-' );
		if ( ! $own || ! is_string( $src ) || '' === $src ) {
			return $src;
		}
		foreach ( array( untrailingslashit( site_url() ), untrailingslashit( home_url() ) ) as $origin ) {
			if ( '' !== $origin && 0 === strpos( $src, $origin . '/' ) ) {
				return 'http://' . FAZ_E2E_FP_CDN_HOST . substr( $src, strlen( $origin ) );
			}
		}
		if ( '/' === substr( $src, 0, 1 ) && '//' !== substr( $src, 0, 2 ) ) {
			return 'http://' . FAZ_E2E_FP_CDN_HOST . $src;
		}
		return $src;
	};
	add_filter( 'script_loader_src', $faz_e2e_fp_cdn_rewrite, PHP_INT_MAX, 2 );
	add_filter( 'style_loader_src', $faz_e2e_fp_cdn_rewrite, PHP_INT_MAX, 2 );
}

add_action(
	'wp_footer',
	static function () {
		$slug = (string) get_option( 'faz_e2e_fp_cdn_control_page', '' );
		if ( '' === $slug || ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || $slug !== $post->post_name ) {
			return;
		}
		echo '<script id="faz-e2e-cdn-control-js" src="http://' . esc_attr( FAZ_E2E_FP_CDN_HOST ) . '/faz-e2e/control-lib.js" defer></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
	},
	7
);
