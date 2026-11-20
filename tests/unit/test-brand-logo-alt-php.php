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

namespace FazCookie\Includes {
	// class-banners.php extends it; the callback under test needs nothing from it.
	if ( ! class_exists( Modules::class ) ) {
		abstract class Modules {}
	}
}

namespace FazCookie\Admin\Modules\Banners\Includes {
	// The banners the alt listener checks the edited image against.
	class Controller {
		public static $items = array();
		public static function get_instance() {
			return new self();
		}
		public function get_items() {
			return self::$items;
		}
	}
}

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
	function esc_url( $url ) {
		return (string) $url;
	}
	function faz_clear_banner_template_cache() {
		$GLOBALS['faz_test_cleared']++;
	}

	require_once dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-template.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/banners/class-banners.php';

	use FazCookie\Admin\Modules\Banners\Banners;
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
	$logo_banner = function ( $url, $status = true ) {
		return (object) array( 'settings' => array( 'config' => array( 'notice' => array( 'elements' => array( 'brandLogo' => array( 'status' => $status, 'meta' => array( 'url' => $url ) ) ) ) ) ) );
	};
	\FazCookie\Admin\Modules\Banners\Includes\Controller::$items = array( 1 => $logo_banner( $up . 'no-alt.png', false ), 2 => $logo_banner( $up . 'acme-logo-300x100.png' ) );
	check( true === Banners::maybe_flush_on_alt_change( 7, 41, '_wp_attachment_image_alt' ), "the logo's own alt: it says to purge the page cache" );
	check( 1 === $GLOBALS['faz_test_cleared'], 'and clears the template cache' );
	check( false === Banners::maybe_flush_on_alt_change( 7, 42, '_wp_attachment_image_alt' ), 'the alt of an image no banner shows (or shows with the logo off): no page purge' );
	check( 2 === $GLOBALS['faz_test_cleared'], 'but the template is still rebuilt' );
	check( false === Banners::maybe_flush_on_alt_change( 8, 41, '_wp_attached_file' ) && false === Banners::maybe_flush_on_alt_change( 9, 41, '_edit_lock' ), 'any other meta key: no purge' );
	check( 2 === $GLOBALS['faz_test_cleared'], 'and no template rebuild' );
	\FazCookie\Admin\Modules\Banners\Includes\Controller::$items = array();
	check( false === Banners::maybe_flush_on_alt_change( 7, 41, '_wp_attachment_image_alt' ), 'no banner with a logo: no purge' );
	// Hooked on every request by Admin::__construct(), not by Banners::init():
	// the attachment edit screen saves through post.php, where the admin
	// modules are not loaded (constructing Admin here would boot the plugin).
	$admin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/class-admin.php' );
	$ctor  = substr( $admin, (int) strpos( $admin, 'public function __construct(' ) );
	$ctor  = substr( $ctor, 0, (int) strpos( $ctor, "\n\t}\n" ) );
	check( 1 === preg_match( "/array\\(\\s*'added_post_meta',\\s*'updated_post_meta',\\s*'deleted_post_meta'\\s*\\)/", $ctor ), 'Admin::__construct() hooks added, updated and deleted post meta' );
	check( false !== strpos( $ctor, "array( \$this, 'on_attachment_alt_change' ), 10, 3 )" ), 'with on_attachment_alt_change() and its three arguments' );
	check( 1 === preg_match( "/function on_attachment_alt_change\\(.*?Banners::maybe_flush_on_alt_change\\(.*?has_action\\( 'faz_purge_page_cache' \\).*?load_cache_module\\(\\).*?do_action\\( 'faz_purge_page_cache' \\)/s", $admin ), 'which loads the cache module where it is missing and purges cached pages for a logo' );
	$banners_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/banners/class-banners.php' );
	check( false === strpos( $banners_src, "add_action( \$hook, array( \$this, 'maybe_flush_on_alt_change' )" ), 'Banners::init() no longer registers a second copy' );

	echo "\n== The rendered logo carries it ==\n";
	$render = function ( $url ) {
		$doc = new DOMDocument();
		$doc->loadHTML( '<div data-faz-tag="brand-logo"><img src="#" alt="Brand logo"></div>' );
		$el = $doc->getElementsByTagName( 'div' )->item( 0 );
		Template::apply_brand_logo( $el, $url );
		$img = $el->getElementsByTagName( 'img' )->item( 0 );
		return array( $img->getAttribute( 'src' ), $img->getAttribute( 'alt' ) );
	};
	list( $src, $alt ) = $render( $up . 'acme-logo.png' );
	check( $up . 'acme-logo.png' === $src && 'Acme Srl logo' === $alt, 'a logo with alt text renders it on the img' );
	list( $src, $alt ) = $render( $up . 'no-alt.png' );
	check( $up . 'no-alt.png' === $src && 'Brand logo' === $alt, 'a logo without alt text keeps "Brand logo"' );
	list( , $alt ) = $render( 'https://cdn.other.test/logo.png' );
	check( 'Brand logo' === $alt, 'an external logo keeps "Brand logo"' );
	$tpl = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-template.php' );
	check( false !== strpos( $tpl, "self::apply_brand_logo( \$element, (string) \$config['meta']['url'] );" ), 'prepare_html() renders the brand-logo block through apply_brand_logo()' );

	echo "\nPassed: $passed\nFailed: $failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
