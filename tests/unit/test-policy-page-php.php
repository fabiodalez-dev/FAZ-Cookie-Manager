<?php
/** Production policy-page helper: language, retries, permission and failures. */
namespace FazCookie\Admin\Modules\Languages\Includes {
	class Controller {
		public static function get_instance() { return new self(); }
		public function get_languages() { return array( 'English' => 'en', 'Italian' => 'it', 'French' => 'fr', 'Welsh' => 'cy' ); }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	class WP_Error {
		public $code;
		public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
	}
	function __( $s, $domain = '' ) { return $s; }
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
	function current_user_can( $cap ) { return $GLOBALS['allowed']; }
	function sanitize_key( $s ) { return strtolower( $s ); }
	function sanitize_title( $s ) { return strtolower( $s ); }
	function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
	function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function add_option( $key, $value, ...$args ) { if ( isset( $GLOBALS['options'][ $key ] ) ) { return false; } $GLOBALS['options'][ $key ] = $value; return true; }
	function update_option( $key, $value, ...$args ) { $GLOBALS['options'][ $key ] = $value; return true; }
	function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); }
	function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
	function get_posts( $args ) { return array_values( array_filter( $GLOBALS['posts'], function ( $p ) { return 'publish' === $p->post_status && '' === $p->post_password; } ) ); }
	function wp_insert_post( $data, $error = false ) {
		if ( $GLOBALS['insert_error'] ) { return new WP_Error( 'insert_failed' ); }
		$id = count( $GLOBALS['posts'] ) + 1;
		$GLOBALS['posts'][ $id ] = (object) array_merge( $data, array( 'ID' => $id, 'post_password' => '' ) );
		return $id;
	}
	function update_post_meta( ...$args ) {}
	function wp_generate_uuid4() { return uniqid(); }
	function wp_cache_delete( ...$args ) {}
	$GLOBALS['wpdb'] = new class {
		public $options = 'wp_options';
		public function delete( $table, $where, $formats ) {
			if ( ( $GLOBALS['options'][ $where['option_name'] ] ?? null ) === $where['option_value'] ) { unset( $GLOBALS['options'][ $where['option_name'] ] ); }
		}
	};
	function get_permalink( $post ) { return 'https://example.test/?page_id=' . $post->ID; }
	require __DIR__ . '/../../admin/modules/cookie-policy-generator/includes/class-generator.php';
	require __DIR__ . '/../../admin/modules/settings/includes/class-onboarding.php';
	require __DIR__ . '/../../admin/modules/settings/includes/class-policy-page.php';
	use FazCookie\Admin\Modules\Settings\Includes\Policy_Page;
	$GLOBALS['options'] = $GLOBALS['posts'] = array();
	$GLOBALS['allowed'] = true;
	$GLOBALS['insert_error'] = false;
	$passed = 0;
	function check( $ok, $label ) { global $passed; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } $passed++; }
	$it = Policy_Page::ensure( 'it', 'gdpr' );
	check( $it['language'] === 'it', 'Italian selected independently of admin locale' );
	check( strpos( get_post( $it['id'] )->post_content, 'lang="it" jurisdiction="gdpr-strict"' ) !== false, 'explicit language and jurisdiction in shortcode' );
	check( get_post( $it['id'] )->post_status === 'publish', 'published at finish' );
	check( Policy_Page::ensure( 'it', 'gdpr' )['id'] === $it['id'] && count( $GLOBALS['posts'] ) === 1, 'retry does not duplicate' );
	$GLOBALS['options'] = array();
	check( Policy_Page::ensure( 'it', 'gdpr' )['id'] === $it['id'], 'recover existing page after option loss' );
	$en = Policy_Page::ensure( 'en', 'gdpr' );
	check( $en['id'] !== $it['id'], 'different language gets separate page' );
	$ccpa = Policy_Page::ensure( 'it', 'ccpa' );
	check( $ccpa['id'] !== $it['id'], 'different law never reuses incompatible policy' );
	check( is_wp_error( Policy_Page::ensure( 'cy', 'gdpr' ) ), 'missing translation never publishes English fallback' );
	check( is_wp_error( Policy_Page::ensure( '../it', 'gdpr' ) ), 'invalid language rejected' );
	check( is_wp_error( Policy_Page::ensure( 'it', 'invalid' ) ), 'invalid law rejected' );
	$GLOBALS['allowed'] = false;
	check( is_wp_error( Policy_Page::ensure( 'it', 'gdpr' ) ), 'publish capability required' );
	$GLOBALS['allowed'] = true;
	$GLOBALS['insert_error'] = true;
	check( is_wp_error( Policy_Page::ensure( 'fr', 'gdpr' ) ), 'database failure returned' );
	check( ! isset( $GLOBALS['options']['faz_setup_policy_page_fr_gdpr-strict_lock'] ), 'lock released on failure' );
	$GLOBALS['insert_error'] = false;
	check( ! is_wp_error( Policy_Page::ensure( 'fr', 'gdpr' ) ), 'failed creation can be retried' );
	$GLOBALS['options']['faz_setup_policy_page_it_gdpr-strict_lock'] = time() . ':active';
	check( is_wp_error( Policy_Page::ensure( 'it', 'gdpr' ) ), 'concurrent creation rejected' );
	$GLOBALS['options']['faz_setup_policy_page_it_gdpr-strict_lock'] = ( time() - 301 ) . ':expired';
	check( ! is_wp_error( Policy_Page::ensure( 'it', 'gdpr' ) ), 'interrupted request lease expires safely' );
	$GLOBALS['posts'][ $it['id'] ]->post_status = 'draft';
	$count = count( $GLOBALS['posts'] );
	check( is_wp_error( Policy_Page::ensure( 'it', 'gdpr' ) ), 'draft is never linked publicly or republished' );
	check( count( $GLOBALS['posts'] ) === $count, 'draft retry creates no duplicate' );
	echo "policy-page: $passed passed, 0 failed\n";
}
