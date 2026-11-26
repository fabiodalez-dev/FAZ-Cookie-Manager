<?php
/**
 * The banner loads its custom images only from the site itself.
 *
 * The banner template is printed on every page, first visit included, and a
 * browser requests an <img> as soon as it is in the document. A custom image
 * for the revisit widget on another host was therefore fetched from that host
 * before the visitor had chosen anything, and again after a refusal. The brand
 * logo, in the notice itself, was fetched the same way on every first visit.
 *
 * Drives the real Template::prepare_html() on a cut-down template, so what is
 * asserted is the markup a visitor receives.
 *
 * Run: php tests/unit/test-banner-image-host-php.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'FAZ_APP_ASSETS_URL', 'https://shop.example/wp-content/plugins/faz-cookie-manager/frontend/images/' );

	$GLOBALS['faz_test_filters'] = array();
	$GLOBALS['faz_test_options'] = array(
		'home'    => 'https://shop.example',
		'siteurl' => 'https://shop.example/wp',
	);
	$GLOBALS['faz_test_uploads'] = 'https://shop.example/wp-content/uploads';

	function get_option( $name, $fallback = false ) {
		return isset( $GLOBALS['faz_test_options'][ $name ] ) ? $GLOBALS['faz_test_options'][ $name ] : $fallback;
	}
	function home_url( $path = '' ) { return 'https://www.shop.example' . $path; }
	function site_url( $path = '' ) { return 'https://www.shop.example/wp' . $path; }
	function wp_get_upload_dir() { return array( 'baseurl' => $GLOBALS['faz_test_uploads'] ); }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function wp_basename( $path ) { return basename( $path ); }
	function set_url_scheme( $url, $scheme = null ) { return $url; }
	function is_ssl() { return true; }
	function apply_filters( $hook, $value, ...$args ) {
		return isset( $GLOBALS['faz_test_filters'][ $hook ] ) ? call_user_func( $GLOBALS['faz_test_filters'][ $hook ], $value, ...$args ) : $value;
	}
	function do_shortcode( $html ) { return $html; }
	function esc_url( $url ) { return (string) $url; }
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
	// Like WordPress: a protocol outside the list empties the URL.
	function esc_url_raw( $url, $protocols = null ) {
		$url = trim( (string) $url );
		if ( is_array( $protocols ) && preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $url, $m ) && ! in_array( strtolower( $m[1] ), $protocols, true ) ) {
			return '';
		}
		return $url;
	}
	function sanitize_text_field( $v ) { return (string) $v; }
	function sanitize_hex_color( $v ) { return preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $v ) ? $v : ''; }
	function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
	function wp_kses_post( $v ) { return (string) $v; }
	function wp_kses( $v, $allowed = array() ) { return (string) $v; }
	function sanitize_textarea_field( $v ) { return (string) $v; }
	function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
	function absint( $v ) { return abs( (int) $v ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function __( $v, $d = '' ) { return $v; }
	// The plugin's own lookup, copied: the first array that carries the pair.
	function faz_array_search( $array = array(), $key = '', $value = '' ) {
		$results = array();
		if ( is_array( $array ) ) {
			if ( isset( $array[ $key ] ) && $array[ $key ] === $value ) {
				$results = $array;
			}
			foreach ( $array as $sub_array ) {
				$results = array_merge( $results, faz_array_search( $sub_array, $key, $value ) );
			}
		}
		return $results;
	}

	$root = dirname( __DIR__, 2 );
	require_once $root . '/includes/class-formatting.php';
	require_once $root . '/includes/class-store.php';
	require_once $root . '/admin/modules/banners/includes/class-banner.php';
	require_once $root . '/admin/modules/banners/includes/class-template.php';

	use FazCookie\Admin\Modules\Banners\Includes\Template;

	/** Template without the constructor: prepare_html() on a cut-down template. */
	class Image_Host_Test_Template extends Template {
		public function __construct() {}

		public function render( $revisit_url, $logo_url = '#' ) {
			$this->template   = array(
				'html' => '<div class="faz-btn-revisit-wrapper" data-faz-tag="revisit-consent"><button class="faz-btn-revisit"><img src="/wp-content/plugins/faz-cookie-manager/frontend/images/revisit.svg" alt="Revisit consent button"/></button></div>'
					. '<div class="faz-consent-container"><div class="faz-consent-bar" data-faz-tag="notice"><div class="faz-custom-brand-logo-wrapper" data-faz-tag="brand-logo"><img src="" alt="Brand logo" class="faz-custom-brand-logo"/></div><p data-faz-tag="title">Title</p></div></div>',
			);
			$this->properties = array(
				'settings' => array(),
				'config'   => array(
					'revisitConsent' => array( 'tag' => 'revisit-consent', 'status' => true, 'meta' => array( 'url' => $revisit_url ) ),
					'notice'         => array(
						'tag'      => 'notice',
						'status'   => true,
						'elements' => array(
							'brandLogo' => array( 'tag' => 'brand-logo', 'status' => '#' !== $logo_url, 'meta' => array( 'url' => $logo_url, 'placement' => '' ) ),
							'title'     => array( 'tag' => 'title', 'status' => true ),
						),
					),
				),
			);
			$method           = new \ReflectionMethod( Template::class, 'prepare_html' );
			$method->setAccessible( true );
			return (string) $method->invoke( $this );
		}
	}

	$passed = 0;
	function t( $cond, $label ) {
		global $passed;
		if ( $cond ) { $passed++; return; }
		fwrite( STDERR, "FAIL: $label\n" );
		exit( 1 );
	}

	/** Every src the markup would make a browser request. */
	function image_sources( $html ) {
		preg_match_all( '/<img[^>]*\ssrc="([^"]*)"/', $html, $m );
		return $m[1];
	}
	function revisit_image( $html ) {
		return preg_match( '/<button class="faz-btn-revisit">\s*<img[^>]*>/', $html, $m ) ? $m[0] : '';
	}

	$template = new Image_Host_Test_Template();
	$default  = FAZ_APP_ASSETS_URL . 'revisit.svg';

	echo "== Revisit widget image ==\n";

	$html = $template->render( '#' );
	t( false !== strpos( revisit_image( $html ), 'src="' . $default . '"' ), 'no custom image: the default icon' );

	// Images of the site itself are used as before.
	foreach ( array(
		'https://shop.example/wp-content/uploads/2026/11/widget.png' => 'the Media Library',
		'https://www.shop.example/wp-content/uploads/widget.png'     => 'the site with www.',
		'http://SHOP.example/wp-content/uploads/widget.png'          => 'another scheme and case',
		'/wp-content/uploads/widget.png'                             => 'a path on the site',
	) as $url => $what ) {
		$image = revisit_image( $template->render( $url ) );
		t( false !== strpos( $image, 'src="' . $url . '"' ) && false !== strpos( $image, 'faz-revisit-custom-image' ), "$what is used" );
	}

	// An offloaded Media Library lives on the host of the uploads folder.
	$GLOBALS['faz_test_uploads'] = 'https://media.shop-cdn.example/uploads';
	t( false !== strpos( revisit_image( $template->render( 'https://media.shop-cdn.example/uploads/widget.png' ) ), 'faz-revisit-custom-image' ), 'the host of the uploads folder is used' );
	$GLOBALS['faz_test_uploads'] = 'https://shop.example/wp-content/uploads';

	// Anything else would be fetched from a third party before the choice.
	foreach ( array(
		'https://images.thirdparty.example/widget.png',
		'https://media.shop-cdn.example/uploads/widget.png',
		'//images.thirdparty.example/widget.png',
		'https://shop.example.thirdparty.example/widget.png',
		'https://shop.example@thirdparty.example/widget.png',
		'https://notshop.example/widget.png',
		'/\\thirdparty.example/widget.png',
		'https:\\\\thirdparty.example\\widget.png',
		'javascript:alert(1)',
	) as $url ) {
		$html  = $template->render( $url );
		$image = revisit_image( $html );
		t( false !== strpos( $image, 'src="' . $default . '"' ), "$url: the default icon stays" );
		t( false === strpos( $image, 'faz-revisit-custom-image' ), "$url: not marked as a custom image, so script.js draws the default icon" );
		foreach ( image_sources( $html ) as $src ) {
			t( '' === $src || 0 === strpos( $src, 'https://shop.example/' ), "$url: nothing in the template is requested from another host ($src)" );
		}
	}

	// A host the site owner answers for can be added.
	$GLOBALS['faz_test_filters']['faz_banner_image_hosts'] = function ( $hosts, $url ) {
		$hosts[] = 'WWW.Images.Thirdparty.example';
		return $hosts;
	};
	t( false !== strpos( revisit_image( $template->render( 'https://images.thirdparty.example/widget.png' ) ), 'faz-revisit-custom-image' ), 'faz_banner_image_hosts adds a host' );
	t( false === strpos( revisit_image( $template->render( 'https://other.example/widget.png' ) ), 'faz-revisit-custom-image' ), 'and only that host' );
	$GLOBALS['faz_test_filters']['faz_banner_image_hosts'] = function () {
		return 'not-an-array';
	};
	t( false === strpos( revisit_image( $template->render( 'https://shop.example/wp-content/uploads/widget.png' ) ), 'faz-revisit-custom-image' ), 'a filter that returns no list allows nothing' );
	$GLOBALS['faz_test_filters'] = array();

	echo "== Brand logo ==\n";

	// The logo is in the notice, so it is requested on every first visit.
	$own  = 'https://shop.example/wp-content/uploads/2026/11/logo.png';
	$html = $template->render( '#', $own );
	t( false !== strpos( $html, 'data-faz-tag="brand-logo"' ) && in_array( $own, image_sources( $html ), true ), 'a logo from the Media Library is shown' );
	t( in_array( '/wp-content/uploads/logo.png', image_sources( $template->render( '#', '/wp-content/uploads/logo.png' ) ), true ), 'a path on the site is shown' );
	foreach ( array(
		'https://images.thirdparty.example/logo.png',
		'//images.thirdparty.example/logo.png',
		'https://shop.example@thirdparty.example/logo.png',
	) as $url ) {
		$html = $template->render( '#', $url );
		t( false === strpos( $html, 'data-faz-tag="brand-logo"' ) && false === strpos( $html, 'faz-custom-brand-logo' ), "$url: the logo is left out, with no empty image in its place" );
		t( false === strpos( $html, 'thirdparty.example' ), "$url: the host is not in the template at all" );
		t( false !== strpos( $html, 'data-faz-tag="title"' ), "$url: the rest of the notice is rendered" );
	}

	echo "$passed passed: the banner loads its custom images only from the site itself\n";
}
