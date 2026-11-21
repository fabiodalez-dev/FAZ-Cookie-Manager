<?php
/**
 * Regression test (2026-11 security audit, hardening note): settings import
 * sanitises the stored text columns at import time.
 *
 * import_settings() stored category name/description and cookie description
 * raw through encode_json_column(), and banner contents/settings sent as JSON
 * STRINGS reached the recursive sanitizers as one opaque string whose <
 * escapes hid the markup from kses. The read-time getters neutralised this,
 * but a reader of the raw column (Cookie_Table_Shortcode reads $cat->name)
 * depended on output escaping alone.
 *
 * Run: php tests/unit/test-settings-import-sanitize-text-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	abstract class Rest_Controller {}

	/**
	 * The one-time unslash migration (Store::ensure_text_unslashed()) is taken
	 * as done, and a pre-fix file's unslash step as a no-op: these texts carry
	 * no slashes. The unslash itself is pinned by
	 * test-cookie-text-slashes-php.php; this test pins the sanitising after it.
	 */
	class Store {
		public static function ensure_text_unslashed() {
			return true;
		}
		public static function unslash_text_column( $value ) {
			return $value;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	/** kses stand-in: drops script elements and on* attributes. */
	function wp_kses_post( $v ) {
		$v = preg_replace( '#<script\b[^>]*>.*?</script\s*>#is', '', (string) $v );
		return preg_replace( '#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|\S+)#i', '', $v );
	}
	function sanitize_text_field( $v ) {
		return is_scalar( $v ) ? trim( strip_tags( (string) $v ) ) : '';
	}
	function absint( $v ) {
		return abs( (int) $v );
	}
	function wp_json_encode( $v ) {
		return json_encode( $v );
	}
	function __( $t, $d = '' ) {
		return $t;
	}
	function do_action( ...$a ) {}
	function faz_clear_banner_template_cache() {}
	function rest_ensure_response( $v ) {
		return $v;
	}

	class Faz_Import_Request {
		private $body;
		public function __construct( $body ) {
			$this->body = $body;
		}
		public function get_json_params() {
			return $this->body;
		}
	}
	class Faz_Import_Wpdb {
		public $prefix = 'wp_';
		public $rows   = array();
		public function query( $q ) {
			return true;
		}
		public function prepare( $q, ...$a ) {
			return $q;
		}
		public function get_var( $q ) {
			return null;
		}
		public function insert( $table, $row ) {
			$this->rows[ $table ][] = $row;
			return 1;
		}
		public function update( $table, $row, $where ) {
			$this->rows[ $table ][] = $row;
			return 1;
		}
	}
	$GLOBALS['wpdb'] = new Faz_Import_Wpdb();

	require_once dirname( __DIR__, 2 ) . '/admin/modules/settings/api/class-api.php';

	use FazCookie\Admin\Modules\Settings\Api\Api;

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
	/** Every JSON layer peeled off, the way a lenient reader would. */
	function peel( $v ) {
		for ( $i = 0; $i < 6 && is_string( $v ); $i++ ) {
			$n = json_decode( $v, true );
			if ( null === $n ) {
				break;
			}
			$v = $n;
		}
		return $v;
	}

	$xss  = 'Necessary<script>alert(1)</script>';
	$xss2 = '<img src=x onerror=alert(1)>Stats';
	$api  = ( new ReflectionClass( Api::class ) )->newInstanceWithoutConstructor();
	$api->import_settings(
		new Faz_Import_Request(
			array(
				'plugin'     => 'faz-cookie-manager',
				'categories' => array(
					array(
						'category_id' => 1,
						'slug'        => 'necessary',
						'name'        => array( 'en' => $xss ),
						'description' => json_encode( json_encode( array( 'en' => $xss2 ) ) ),
					),
				),
				'cookies'    => array(
					array(
						'cookie_id'   => 1,
						'name'        => '_ga',
						'category'    => 1,
						'description' => json_encode( array( 'en' => $xss, 'it' => 'Statistiche' ) ),
					),
				),
				'banners'    => array(
					array(
						'banner_id' => 1,
						'name'      => 'GDPR',
						'contents'  => json_encode( array( 'en' => array( 'notice' => array( 'title' => $xss ) ) ) ),
						'settings'  => json_encode( array( 'config' => array( 'label' => $xss2 ) ) ),
					),
				),
			)
		)
	);
	$rows = $GLOBALS['wpdb']->rows;

	echo "\nimport: text columns are stored sanitised\n";
	$cat = $rows['wp_faz_cookie_categories'][0] ?? array();
	check( 'Necessary' === ( peel( $cat['name'] ?? '' )['en'] ?? null ), 'category name: script removed, text kept' );
	$cdesc = peel( $cat['description'] ?? '' );
	check( is_array( $cdesc ) && false === stripos( (string) ( $cdesc['en'] ?? '' ), 'onerror' ), 'category description sent double-encoded: handler removed' );
	check( is_array( json_decode( (string) $cat['description'], true ) ), 'category description: stored as one JSON layer' );

	$cookie = $rows['wp_faz_cookies'][0] ?? array();
	$kdesc  = peel( $cookie['description'] ?? '' );
	check( 'Necessary' === ( $kdesc['en'] ?? null ), 'cookie description: script removed' );
	check( 'Statistiche' === ( $kdesc['it'] ?? null ), 'cookie description: other languages kept' );

	$banner   = $rows['wp_faz_banners'][0] ?? array();
	$contents = peel( $banner['contents'] ?? '' );
	check( 'Necessary' === ( $contents['en']['notice']['title'] ?? null ), 'banner contents sent as a JSON string: kses reaches the values' );
	$settings = peel( $banner['settings'] ?? '' );
	check( is_array( $settings ) && false === stripos( (string) ( $settings['config']['label'] ?? '' ), '<img' ), 'banner settings sent as a JSON string: sanitize_text_field reaches the values' );
	check( is_array( json_decode( (string) $banner['contents'], true ) ), 'banner contents: stored as a JSON object' );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
