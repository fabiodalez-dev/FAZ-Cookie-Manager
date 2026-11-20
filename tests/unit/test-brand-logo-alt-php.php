<?php
/**
 * Unit test — the banner's brand logo takes its alt text from the Media
 * Library (#404).
 *
 * The template printed a fixed alt="Brand logo" for every site, so a screen
 * reader announced a generic name instead of whose logo it is (WCAG 1.1.1).
 * Template::brand_logo_alt() finds the attachment behind the configured URL,
 * a generated size included, and returns its alt text; '' keeps the generic
 * text. Changing an alt in the Media Library rebuilds the cached template.
 *
 * Run: php tests/unit/test-brand-logo-alt-php.php
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	// Media Library: URL of the original => attachment id, id => alt.
	$GLOBALS['faz_test_attachments'] = array(
		'https://example.test/wp-content/uploads/2026/11/acme-logo.png' => 41,
		'https://example.test/wp-content/uploads/2026/11/no-alt.png'    => 42,
	);
	$GLOBALS['faz_test_alts']        = array(
		41 => 'Acme Srl <b>logo</b> ',
		42 => '',
	);
	$GLOBALS['faz_test_filters']     = array();
	$GLOBALS['faz_test_cleared']     = 0;

	function attachment_url_to_postid( $url ) {
		return $GLOBALS['faz_test_attachments'][ $url ] ?? 0;
	}
	function get_post_meta( $id, $key = '', $single = false ) {
		return '_wp_attachment_image_alt' === $key ? ( $GLOBALS['faz_test_alts'][ $id ] ?? '' ) : '';
	}
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
	function apply_filters( $hook, $value, ...$args ) {
		return isset( $GLOBALS['faz_test_filters'][ $hook ] ) ? call_user_func( $GLOBALS['faz_test_filters'][ $hook ], $value, ...$args ) : $value;
	}
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['faz_test_actions'][ $hook ][] = $callback;
	}
	function faz_clear_banner_template_cache() {
		$GLOBALS['faz_test_cleared']++;
	}

	require_once dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-template.php';

	use FazCookie\Admin\Modules\Banners\Includes\Template;

	$passed = 0;
	$failed = 0;
	function check( $cond, $label ) {
		global $passed, $failed;
		if ( $cond ) {
			$passed++;
			echo "  [PASS] $label\n";
		} else {
			$failed++;
			echo "  [FAIL] $label\n";
		}
	}

	$up = 'https://example.test/wp-content/uploads/2026/11/';

	echo "== Template::brand_logo_alt() ==\n";
	check( 'Acme Srl logo' === Template::brand_logo_alt( $up . 'acme-logo.png' ), 'the Media Library alt text is used, as plain text' );
	check( 'Acme Srl logo' === Template::brand_logo_alt( $up . 'acme-logo-300x100.png' ), 'a generated size resolves to the original image' );
	check( 'Acme Srl logo' === Template::brand_logo_alt( $up . 'acme-logo.png?ver=3' ), 'a query string does not hide the attachment' );
	check( '' === Template::brand_logo_alt( $up . 'no-alt.png' ), 'an image without alt text keeps the generic text' );
	check( '' === Template::brand_logo_alt( 'https://cdn.other.test/logo.png' ), 'an external URL keeps the generic text' );
	check( '' === Template::brand_logo_alt( '#' ), 'the "no logo" placeholder URL keeps the generic text' );
	$GLOBALS['faz_test_filters']['faz_brand_logo_alt'] = function ( $alt, $url ) {
		return 'Filtered ' . $alt;
	};
	check( 'Filtered Acme Srl logo' === Template::brand_logo_alt( $up . 'acme-logo.png' ), 'the faz_brand_logo_alt filter can override it' );
	$GLOBALS['faz_test_filters'] = array();

	echo "\n== The cached template is rebuilt when an alt changes ==\n";
	$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/banners/class-banners.php' );
	check( false !== strpos( $src, "'_wp_attachment_image_alt' === \$meta_key" ), 'the banners module watches the alt meta key' );
	foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
		check( false !== strpos( $src, "'" . $hook . "'" ), "it listens on $hook" );
	}

	echo "\n== The template build sets it on the logo image ==\n";
	$tpl = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-template.php' );
	check( 1 === preg_match( '/brand_logo_alt\(.*?\$logo_img->setAttribute\(\s*\'alt\',\s*\$logo_alt\s*\)/s', $tpl ), 'prepare_html() writes the alt on the brand-logo img' );

	echo "\nPassed: $passed\nFailed: $failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
