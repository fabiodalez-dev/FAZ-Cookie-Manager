<?php
/**
 * Standalone unit tests: the legacy [faz_cookie_table] shortcode discloses a
 * flagged third-country transfer like the other two declaration surfaces.
 *
 * Subsystem: third-country-transfer / includes/class-cookie-table-shortcode.php.
 *
 * The banner preference centre (Shortcodes::render_transfer_disclosure) and the
 * generated policy (Renderer::build_cookie_list_html) both append the
 * operator-flagged recipient country + safeguard to a cookie's row. The legacy
 * table — embedded by [faz_cookie_policy] as its "Cookies We Use" section and
 * wrapped by the faz/cookie-table and faz/cookie-policy Gutenberg blocks — never
 * read Cookie::get_transfer(), so the one document a visitor opens for GDPR
 * Art. 13(1)(f) information omitted the fact the operator entered, while the
 * banner for the same cookie showed it. The feature commit even shipped the
 * `.faz-audit-transfer*` rules in this shortcode's own stylesheet; this test
 * pins that the markup those rules style is actually emitted.
 *
 * Only the two controllers, the suppression helper and the WP template-tag
 * surface are stubbed; Cookie, Cookie_Categories, Store and the shortcode are
 * the real classes.
 *
 * Run:
 *   php tests/unit/test-cookie-table-shortcode-transfer-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Category_Controller {
		public static function get_instance() { return new self(); }
		public function get_items() { return $GLOBALS['faz_cts_categories']; }
	}
	class Cookie_Controller {
		public static function get_instance() { return new self(); }
		public function get_items() { return $GLOBALS['faz_cts_cookies']; }
		public function get_items_by_category( $id ) {
			return array_values( array_filter( $GLOBALS['faz_cts_cookies'], function ( $c ) use ( $id ) { return (int) $c->category === (int) $id; } ) );
		}
	}
}
namespace FazCookie\Frontend {
	class Frontend {
		public static function is_declaration_suppressed( $name ) { return 0 === strpos( (string) $name, 'wp-' ); }
	}
}
namespace {
	error_reporting( E_ALL & ~E_DEPRECATED );
	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
	define( 'FAZ_VERSION', '1.34.2-test' );

	// ---- WP template tags / helpers --------------------------------------------
	$GLOBALS['faz_cts_lang'] = 'en';
	function add_shortcode( ...$a ) {}
	function shortcode_atts( $pairs, $atts, $tag = '' ) { return array_merge( $pairs, is_array( $atts ) ? array_intersect_key( $atts, $pairs ) : array() ); }
	function sanitize_text_field( $v ) { return trim( strip_tags( is_scalar( $v ) ? (string) $v : '' ) ); }
	function sanitize_textarea_field( $v ) { return is_scalar( $v ) ? trim( strip_tags( (string) $v ) ) : ''; }
	function sanitize_title( $v ) { return preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( (string) $v ) ); }
	function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
	function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
	function wp_kses_post( $v ) { return strip_tags( (string) $v, '<a><strong><em><span><br>' ); }
	function wp_kses( $v, $allowed = array() ) { return wp_kses_post( $v ); }
	function wp_filter_post_kses( $v ) { return (string) $v; }
	function faz_allowed_html() { return array(); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function absint( $v ) { return abs( (int) $v ); }
	function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
	function __( $t, $d = '' ) { return (string) $t; }
	function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
	function get_locale() { return 'en_US'; }
	function faz_current_language() { return $GLOBALS['faz_cts_lang']; }
	function faz_default_language() { return 'en'; }
	function faz_selected_languages( $language = '' ) { return '' !== $language ? array( 'en', $language ) : array( 'en', 'it' ); }
	function apply_filters( $hook, $value, ...$rest ) { return $value; }
	function add_filter( ...$a ) {}
	function wp_register_style( ...$a ) {}
	function wp_enqueue_style( ...$a ) {}
	function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/faz-cookie-manager/'; }
	function plugin_dir_path( $f ) { return dirname( __DIR__, 2 ) . '/'; }
	function wp_cache_get( $key, $group = '' ) { return false; }
	function wp_cache_set( ...$a ) { return true; }
	function wp_cache_delete( ...$a ) { return true; }
	function wp_upload_dir() { return array( 'basedir' => '/nonexistent-uploads' ); }
	function faz_read_json_file( $path ) { return is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : array(); }

	$root = dirname( __DIR__, 2 );
	require_once $root . '/includes/class-store.php';
	require_once $root . '/admin/modules/cookies/includes/class-cookie.php';
	require_once $root . '/admin/modules/cookies/includes/class-cookie-categories.php';
	require_once $root . '/includes/class-cookie-table-shortcode.php';

	use FazCookie\Includes\Cookie_Table_Shortcode;

	// ---- Fixtures ----------------------------------------------------------------
	function faz_cts_category( $id, $slug, $name ) {
		return (object) array(
			'category_id' => $id, 'name' => wp_json_encode( array( 'en' => $name ) ), 'slug' => $slug,
			'description' => wp_json_encode( array( 'en' => '' ) ), 'prior_consent' => 0, 'visibility' => 1, 'priority' => $id,
			'sell_personal_data' => 1, 'share_personal_data' => 1, 'meta' => '', 'cookies' => 0,
			'date_created' => '2026-01-01 00:00:00', 'date_modified' => '2026-01-01 00:00:00',
		);
	}
	function faz_cts_cookie( $id, $name, $category, $meta ) {
		return (object) array(
			'cookie_id' => $id, 'name' => $name, 'slug' => $name, 'category' => $category,
			'description' => wp_json_encode( array( 'en' => "Description of {$name}", 'it' => "Descrizione di {$name}" ) ),
			'duration' => wp_json_encode( array( 'en' => '1 year' ) ), 'domain' => 'example.test', 'type' => 0, 'discovered' => 1,
			'url_pattern' => '', 'meta' => wp_json_encode( $meta ), 'date_created' => '2026-01-01 00:00:00', 'date_modified' => '2026-01-01 00:00:00',
		);
	}
	$GLOBALS['faz_cts_categories'] = array( faz_cts_category( 3, 'analytics', 'Analytics' ), faz_cts_category( 9, 'wordpress-internal', 'WordPress internal' ) );
	$GLOBALS['faz_cts_cookies']    = array(
		faz_cts_cookie( 1, '_ga', 3, array(
			'opt_in_script' => '',
			'transfer'      => array(
				'enabled'   => true,
				'countries' => array( 'en' => 'United States', 'it' => 'Stati Uniti' ),
				'safeguard' => array( 'en' => 'EU-US Data Privacy Framework' ),
			),
		) ),
		faz_cts_cookie( 2, '_gid', 3, array( 'opt_in_script' => '' ) ),
		faz_cts_cookie( 3, '_nocountry', 3, array( 'transfer' => array( 'enabled' => true, 'countries' => array(), 'safeguard' => array() ) ) ),
		// Flagged, but WordPress-internal: must never surface at all.
		faz_cts_cookie( 4, 'wp-settings-1', 9, array( 'transfer' => array( 'enabled' => true, 'countries' => array( 'en' => 'United States' ), 'safeguard' => array() ) ) ),
	);

	// ---- Harness -------------------------------------------------------------------
	$tests_run = $tests_passed = $tests_failed = 0;
	function assert_true( $c, $l ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $c ) { $tests_passed++; echo "  \033[32m✓\033[0m $l\n"; }
		else { $tests_failed++; echo "  \033[31m✗\033[0m $l\n"; }
	}
	function assert_contains( $h, $n, $l ) { assert_true( is_string( $h ) && false !== strpos( $h, $n ), $l . ( false === strpos( (string) $h, $n ) ? "\n      needle not found: " . var_export( $n, true ) : '' ) ); }
	function assert_not_contains( $h, $n, $l ) { assert_true( is_string( $h ) && false === strpos( $h, $n ), $l ); }
	function faz_cts_cell( $html, $cookie, $label ) {
		// The <tr> that carries the cookie name, then its cell with that data-label.
		if ( ! preg_match( '/<tr>(?:(?!<\/tr>).)*?' . preg_quote( $cookie, '/' ) . '(?:(?!<\/tr>).)*<\/tr>/s', $html, $row ) ) { return null; }
		return preg_match( '/<td data-label="' . preg_quote( $label, '/' ) . '">(.*?)<\/td>/s', $row[0], $cell ) ? $cell[1] : null;
	}

	echo "\n== [faz_cookie_table] third-country transfer disclosure ==\n\n";

	$sc   = new Cookie_Table_Shortcode();
	$html = $sc->render( array() );

	// 1. The flagged cookie's row carries the same disclosure the banner shows.
	$ga_desc = faz_cts_cell( $html, '_ga', 'Description' );
	assert_true( null !== $ga_desc, 'the _ga row renders a Description cell' );
	assert_contains( $ga_desc, 'Description of _ga', 'the description text itself is still there' );
	assert_contains( $ga_desc, 'class="faz-audit-transfer"', 'flagged cookie: the disclosure wrapper (.faz-audit-transfer, styled by this shortcode\'s own CSS) is emitted' );
	assert_contains( $ga_desc, 'faz-audit-transfer-country', 'flagged cookie: the recipient-country line is emitted' );
	assert_contains( $ga_desc, 'United States', 'flagged cookie: the recipient country is named' );
	assert_contains( $ga_desc, 'faz-audit-transfer-safeguard', 'flagged cookie: the safeguard line is emitted' );
	assert_contains( $ga_desc, 'EU-US Data Privacy Framework', 'flagged cookie: the operator-described safeguard is named' );

	// 2. An unflagged cookie renders exactly as before (default-OFF surface guard).
	$gid_desc = faz_cts_cell( $html, '_gid', 'Description' );
	assert_true( null !== $gid_desc && false === strpos( $gid_desc, 'faz-audit-transfer' ), 'unflagged cookie: no transfer markup at all' );

	// 3. Flagged with no country named: one neutral line, no orphan "Safeguard:".
	$nc_desc = faz_cts_cell( $html, '_nocountry', 'Description' );
	assert_true( null !== $nc_desc && false !== strpos( $nc_desc, 'faz-audit-transfer-country' ), 'no-country flag: a disclosure line is still emitted' );
	assert_not_contains( (string) $nc_desc, 'Safeguard:', 'no-country flag: no orphan "Safeguard:" fragment' );
	assert_not_contains( (string) $nc_desc, 'EU adequacy', 'no-country flag: the legacy table is not bound to a law, so the line names no regime' );

	// 4. WordPress-internal cookies never surface, flagged or not.
	assert_not_contains( $html, 'wp-settings-1', 'a flagged WordPress-internal cookie is still excluded' );

	// 5. Language follows the table language with the plugin's fallback chain.
	$GLOBALS['faz_cts_lang'] = 'it';
	$html_it = ( new Cookie_Table_Shortcode() )->render( array() );
	$ga_it   = faz_cts_cell( $html_it, '_ga', 'Description' );
	assert_contains( (string) $ga_it, 'Stati Uniti', 'italian table: the country resolves to the active language' );
	assert_contains( (string) $ga_it, 'EU-US Data Privacy Framework', 'italian table: the safeguard falls back to the default language when it has no translation' );
	$GLOBALS['faz_cts_lang'] = 'en';

	// 6. The disclosure survives a column list without "description": it moves to
	//    the first column rather than disappearing with the cell it usually sits in.
	$html_cols = ( new Cookie_Table_Shortcode() )->render( array( 'columns' => 'name,duration' ) );
	assert_not_contains( $html_cols, 'data-label="Description"', 'control: the description column is really absent' );
	assert_contains( $html_cols, 'United States', 'without a description column the transfer is still disclosed' );
	assert_contains( (string) faz_cts_cell( $html_cols, '_ga', 'Cookie' ), 'faz-audit-transfer', 'without a description column the disclosure sits in the first (Cookie) cell' );

	// 7. Safeguard HTML is filtered, never echoed raw.
	$GLOBALS['faz_cts_cookies'][0]->meta = wp_json_encode( array( 'transfer' => array(
		'enabled' => true, 'countries' => array( 'en' => 'United States' ), 'safeguard' => array( 'en' => 'SCCs <script>alert(1)</script>' ),
	) ) );
	$html_xss = ( new Cookie_Table_Shortcode() )->render( array() );
	assert_not_contains( $html_xss, '<script', 'safeguard markup is filtered before output' );
	assert_contains( $html_xss, 'SCCs', 'the safe part of the safeguard text survives the filter' );

	echo "\n--\nTests:  $tests_run\nPassed: $tests_passed\nFailed: $tests_failed\n\n";
	if ( $tests_failed > 0 ) { echo "\033[31mFAIL\033[0m\n"; exit( 1 ); }
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
