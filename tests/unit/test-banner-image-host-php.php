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
	// A Media Library whose files an offload plugin (WP Offload Media and the
	// like) serves from a CDN host. As on a real site: the uploads folder is
	// left alone, core's attachment_url_to_postid() only knows URLs under it,
	// and wp_get_attachment_url() is filtered to the CDN address.
	//   id => array( file under uploads, served base URL, sizes )
	$GLOBALS['faz_test_attachments'] = array();
	function attachment_url_to_postid( $url ) {
		$base = $GLOBALS['faz_test_uploads'] . '/';
		if ( 0 !== strpos( $url, $base ) ) {
			return 0;
		}
		foreach ( $GLOBALS['faz_test_attachments'] as $id => $a ) {
			if ( substr( $url, strlen( $base ) ) === $a['file'] ) {
				return $id;
			}
		}
		return 0;
	}
	// Like WP_Query for what the lookup asks: ordering by ID, then the page
	// (`offset`, or `paged` with `posts_per_page`), then the limit. A mock
	// that returned every match hid a lookup that read the first page only.
	function get_posts( $args ) {
		$GLOBALS['faz_test_get_posts'][] = $args;
		$needle = isset( $args['meta_query'][0]['value'] ) && ! is_array( $args['meta_query'][0]['value'] ) ? (string) $args['meta_query'][0]['value'] : '';
		$ids    = array();
		// LIKE is a substring test (WordPress wraps the value in %); '=' is
		// the whole stored path.
		$compare = isset( $args['meta_query'][0]['compare'] ) ? (string) $args['meta_query'][0]['compare'] : 'LIKE';
		$values  = isset( $args['meta_query'][0]['value'] ) ? (array) $args['meta_query'][0]['value'] : array();
		foreach ( $GLOBALS['faz_test_attachments'] as $id => $a ) {
			if ( 'IN' === $compare ? in_array( $a['file'], $values, true ) : ( '=' === $compare ? $a['file'] === $needle : ( '' !== $needle && false !== strpos( $a['file'], $needle ) ) ) ) {
				$ids[] = (int) $id;
			}
		}
		if ( isset( $args['orderby'] ) && 'ID' === $args['orderby'] ) {
			sort( $ids );
			if ( ! isset( $args['order'] ) || 'ASC' !== strtoupper( (string) $args['order'] ) ) {
				$ids = array_reverse( $ids );
			}
		}
		$limit = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : ( isset( $args['numberposts'] ) ? (int) $args['numberposts'] : 5 );
		if ( $limit < 0 ) {
			return $ids;
		}
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : ( max( 1, isset( $args['paged'] ) ? (int) $args['paged'] : 1 ) - 1 ) * $limit;
		return array_slice( $ids, $offset, $limit );
	}
	function wp_get_attachment_url( $id ) {
		if ( isset( $GLOBALS['faz_test_url_for'][ $id ] ) ) {
			return $GLOBALS['faz_test_url_for'][ $id ];
		}
		$a = isset( $GLOBALS['faz_test_attachments'][ $id ] ) ? $GLOBALS['faz_test_attachments'][ $id ] : null;
		return $a ? $a['base'] . '/' . $a['file'] : false;
	}
	function wp_get_attachment_metadata( $id ) {
		$a = isset( $GLOBALS['faz_test_attachments'][ $id ] ) ? $GLOBALS['faz_test_attachments'][ $id ] : null;
		return $a ? array( 'file' => $a['file'], 'sizes' => $a['sizes'] ) : false;
	}
	function wp_get_attachment_image_src( $id, $size = 'thumbnail' ) {
		$a = isset( $GLOBALS['faz_test_attachments'][ $id ] ) ? $GLOBALS['faz_test_attachments'][ $id ] : null;
		if ( ! $a || ! isset( $a['sizes'][ $size ] ) ) {
			return false;
		}
		return array( $a['base'] . '/' . dirname( $a['file'] ) . '/' . $a['sizes'][ $size ]['file'], 1, 1, true );
	}
	function get_post_meta( $id, $key = '', $single = false ) { return ''; }
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

	echo "== A Media Library served from an offload CDN ==\n";

	// The upgrade case: the picker stored the URL the offload plugin reports
	// for the attachment. The host is not the site's and not the uploads
	// folder's, yet the image is the site's own Media Library file.
	$cdn = 'https://d1234abcd.cloudfront.example';
	$GLOBALS['faz_test_attachments'] = array(
		41 => array( 'file' => '2026/11/logo.png', 'base' => $cdn . '/wp-content/uploads', 'sizes' => array( 'medium' => array( 'file' => 'logo-300x100.png' ) ) ),
		42 => array( 'file' => '2026/11/widget.png', 'base' => $cdn . '/wp-content/uploads', 'sizes' => array() ),
		// Same file name, not offloaded: served by the site itself.
		43 => array( 'file' => '2025/01/logo.png', 'base' => 'https://shop.example/wp-content/uploads', 'sizes' => array() ),
	);
	$offloaded_logo = $cdn . '/wp-content/uploads/2026/11/logo.png';
	t( true === Template::is_first_party_image_url( $offloaded_logo ), 'an attachment WordPress itself serves from the offload host is accepted' );
	$html = $template->render( '#', $offloaded_logo );
	t( false !== strpos( $html, 'data-faz-tag="brand-logo"' ) && in_array( $offloaded_logo, image_sources( $html ), true ), 'an offloaded Media Library logo is shown' );
	t( in_array( $cdn . '/wp-content/uploads/2026/11/logo-300x100.png', image_sources( $template->render( '#', $cdn . '/wp-content/uploads/2026/11/logo-300x100.png' ) ), true ), 'one of its generated sizes too' );
	t( in_array( $offloaded_logo . '?X-Amz-Signature=abc', image_sources( $template->render( '#', $offloaded_logo . '?X-Amz-Signature=abc' ) ), true ), 'and a signed URL of it' );
	t( false !== strpos( revisit_image( $template->render( $cdn . '/wp-content/uploads/2026/11/widget.png' ) ), 'faz-revisit-custom-image' ), 'an offloaded revisit widget image is used' );
	t( 41 === Template::brand_logo_attachment_id( $offloaded_logo ), 'and the attachment is found, so the logo keeps its alt text' );

	// What makes it the site's own is that WordPress reports that very URL
	// for the attachment. The same file name anywhere else proves nothing.
	foreach ( array(
		'https://images.thirdparty.example/wp-content/uploads/2026/11/logo.png' => 'the same path on a host WordPress does not serve the attachment from',
		$cdn . '/wp-content/uploads/2026/11/other.png'                          => 'a file on the offload host that is no attachment',
		$cdn . '/tracker/logo.png'                                              => 'another path on the offload host that ends in an attachment file name',
		$cdn . '/wp-content/uploads/2026/11/logo-1x1.png'                       => 'a size the attachment does not have',
		'https://images.thirdparty.example/pixel.gif?f=logo.png'                => 'a file name in a query string',
	) as $url => $what ) {
		t( false === Template::is_first_party_image_url( $url ), "$what is refused" );
		$html = $template->render( $url, $url );
		t( false === strpos( $html, 'data-faz-tag="brand-logo"' ) && false === strpos( revisit_image( $html ), 'faz-revisit-custom-image' ), "$what: neither image is rendered" );
	}
	// A library that reuses a file name: the offloaded logo is older than
	// more attachments of that name than one page of the lookup holds.
	$kept = $GLOBALS['faz_test_attachments'];
	for ( $i = 0; $i < 60; $i++ ) {
		$GLOBALS['faz_test_attachments'][ 100 + $i ] = array( 'file' => '2027/' . sprintf( '%02d', $i ) . '/logo.png', 'base' => 'https://shop.example/wp-content/uploads', 'sizes' => array() );
	}
	t( true === Template::is_first_party_image_url( $offloaded_logo ), 'an offloaded logo older than 60 attachments of the same file name is still accepted' );
	t( 41 === Template::brand_logo_attachment_id( $offloaded_logo ), 'and its attachment is found behind them' );
	t( in_array( $offloaded_logo, image_sources( $template->render( '#', $offloaded_logo ) ), true ), 'so the logo is shown' );
	// Past the walk's cap the stored path still finds it: WordPress keeps file
	// names unique within an uploads folder, so `2026/11/logo.png` names one
	// attachment however many `logo.png` the library holds.
	for ( $i = 0; $i < 600; $i++ ) {
		$GLOBALS['faz_test_attachments'][ 3000 + $i ] = array( 'file' => '2029/' . $i . '/logo.png', 'base' => 'https://shop.example/wp-content/uploads', 'sizes' => array() );
	}
	t( 41 === Template::brand_logo_attachment_id( $offloaded_logo ), 'an offloaded logo behind 600 newer attachments of the same file name is found by its stored path' );
	t( in_array( $cdn . '/wp-content/uploads/2026/11/logo-300x100.png', image_sources( $template->render( '#', $cdn . '/wp-content/uploads/2026/11/logo-300x100.png' ) ), true ), 'and so is one of its generated sizes' );
	// WP Offload Media's object versioning puts a timestamp folder before the
	// file name (`2026/11/26113405/logo.png` in the URL) while the stored
	// path stays `2026/11/logo.png`.
	$GLOBALS['faz_test_attachments'][44] = array( 'file' => '2026/10/logo.png', 'base' => $cdn . '/wp-content/uploads', 'sizes' => array() );
	$versioned = $cdn . '/wp-content/uploads/2026/10/26113405/logo.png';
	$GLOBALS['faz_test_url_for'][44] = $versioned;
	t( 44 === Template::brand_logo_attachment_id( $versioned ), 'a versioned offload URL behind 600 newer attachments of the same name is found by its stored path' );
	unset( $GLOBALS['faz_test_url_for'][44], $GLOBALS['faz_test_attachments'][44] );
	// A stored path deeper than year and month (a folder plugin's
	// `clients/acme/2024/01/logo.png`).
	$GLOBALS['faz_test_attachments'][45] = array( 'file' => 'clients/acme/2024/01/logo.png', 'base' => $cdn . '/wp-content/uploads', 'sizes' => array() );
	// Served versioned, so the direct URL lookup cannot resolve it either.
	$deep = $cdn . '/wp-content/uploads/clients/acme/2024/01/26113405/logo.png';
	$GLOBALS['faz_test_url_for'][45] = $deep;
	t( 45 === Template::brand_logo_attachment_id( $deep ), 'a stored path of five segments, served versioned, behind 600 newer attachments of the same name is found' );
	unset( $GLOBALS['faz_test_url_for'][45], $GLOBALS['faz_test_attachments'][45] );
	foreach ( array_keys( $GLOBALS['faz_test_attachments'] ) as $id ) {
		if ( $id >= 3000 ) {
			unset( $GLOBALS['faz_test_attachments'][ $id ] );
		}
	}
	// The walk ends: a name nothing serves from that URL stops at the cap.
	for ( $i = 0; $i < 700; $i++ ) {
		$GLOBALS['faz_test_attachments'][ 1000 + $i ] = array( 'file' => '2028/' . $i . '/pixel.png', 'base' => 'https://shop.example/wp-content/uploads', 'sizes' => array() );
	}
	$GLOBALS['faz_test_get_posts'] = array();
	t( false === Template::is_first_party_image_url( 'https://images.thirdparty.example/pixel.png' ), 'a file name shared by 700 attachments, none served from that URL, is refused' );
	// Only the substring walk is capped by pages; the exact stored-path
	// lookups before it are one query each.
	$walk    = array_filter( $GLOBALS['faz_test_get_posts'], function ( $query ) { return ! isset( $query['meta_query'][0]['compare'] ) || ! in_array( $query['meta_query'][0]['compare'], array( '=', 'IN' ), true ); } );
	$queries = count( $walk );
	$largest = 0;
	foreach ( $walk as $query ) {
		$largest = max( $largest, isset( $query['posts_per_page'] ) ? (int) $query['posts_per_page'] : PHP_INT_MAX );
	}
	t( $queries > 1 && $queries <= 10 && $largest > 0 && $largest <= 50, "and the lookup stops at its cap ($queries queries of at most $largest)" );
	$GLOBALS['faz_test_attachments'] = $kept;
	// An image on the site itself never needs the lookup.
	$GLOBALS['faz_test_get_posts'] = array();
	Template::is_first_party_image_url( 'https://shop.example/wp-content/uploads/2026/11/logo.png' );
	t( array() === $GLOBALS['faz_test_get_posts'], 'a URL on the site is accepted without a Media Library query' );
	$GLOBALS['faz_test_attachments'] = array();

	echo "== The editor is told which stored image is not shown ==\n";

	$settings = function ( $logo, $logo_on, $revisit ) {
		return array(
			'config' => array(
				'revisitConsent' => array( 'meta' => array( 'url' => $revisit ) ),
				'notice'         => array( 'elements' => array( 'brandLogo' => array( 'status' => $logo_on, 'meta' => array( 'url' => $logo ) ) ) ),
			),
		);
	};
	$warn = function ( $s ) {
		return method_exists( Template::class, 'banner_image_warnings' ) ? Template::banner_image_warnings( $s ) : 'missing method';
	};
	t( array() === $warn( $settings( '#', false, '#' ) ), 'no custom image: no warning' );
	t( array() === $warn( $settings( $own, true, '/wp-content/uploads/widget.png' ) ), 'images of the site: no warning' );
	t( array( 'brandLogo', 'revisitConsent' ) === $warn( $settings( 'https://images.thirdparty.example/logo.png', true, 'https://images.thirdparty.example/widget.png' ) ), 'both images on another host: both are reported' );
	t( array( 'revisitConsent' ) === $warn( $settings( 'https://images.thirdparty.example/logo.png', false, 'https://images.thirdparty.example/widget.png' ) ), 'a logo that is switched off is not reported' );
	t( array() === $warn( array() ) && array() === $warn( 'not-an-array' ), 'a banner without the settings reports nothing' );

	$root_dir = dirname( __DIR__, 2 );
	$api      = (string) file_get_contents( $root_dir . '/admin/modules/banners/api/class-api.php' );
	t( false !== strpos( $api, "'image_warnings'   => Template::banner_image_warnings( " . '$object->get_settings() )' ) && false !== strpos( $api, 'use FazCookie\\Admin\\Modules\\Banners\\Includes\\Template;' ), 'the banner REST response carries image_warnings' );
	$js = (string) file_get_contents( $root_dir . '/admin/assets/js/pages/banner.js' );
	t( false !== strpos( $js, 'image_warnings' ) && false !== strpos( $js, "'banner.logoNotOnSite'" ) && false !== strpos( $js, "'banner.revisitImageNotOnSite'" ), 'the editor shows a warning under each reported field' );
	$admin = (string) file_get_contents( $root_dir . '/admin/class-admin.php' );
	t( false !== strpos( $admin, "'logoNotOnSite'" ) && false !== strpos( $admin, "'revisitImageNotOnSite'" ), 'with translatable text' );

	echo "$passed passed: the banner loads its custom images only from the site itself\n";
}
