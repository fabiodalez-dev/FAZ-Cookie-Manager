<?php
/** Production policy-page helper: language, retries, permission and failures. */
namespace FazCookie\Admin\Modules\Languages\Includes {
	class Controller {
		public static function get_instance() { return new self(); }
		public function get_languages() { return array( 'English' => 'en', 'Italian' => 'it', 'French' => 'fr', 'Welsh' => 'cy' ); }
	}
}
// Minimal banner store for the wizard's advisory banner-link step: the active
// banner, its save(), and the read-back through a fresh Banner instance.
namespace FazCookie\Admin\Modules\Banners\Includes {
	class Banner {
		private $contents;
		public function __construct( $id = 0 ) { $this->contents = $GLOBALS['banner_store']; }
		public function get_contents() { return $this->contents; }
		public function set_contents( $contents ) { $this->contents = $contents; }
		public function save() { if ( ! $GLOBALS['banner_save_drops'] ) { $GLOBALS['banner_store'] = $this->contents; } return 1; }
	}
	class Controller {
		public static function get_instance() { return new self(); }
		public function get_active_banner() { return null === $GLOBALS['banner_store'] ? false : new Banner( 1 ); }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->message = $message; }
		public function get_error_message() { return $this->message; }
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
		// Monotonic, like a real AUTO_INCREMENT. Counting the surviving posts
		// re-issued the id of a page an earlier case had removed, and the new
		// page then inherited that page's metadata — which reads as the wizard
		// having marked a page it never touched.
		$id = ++$GLOBALS['next_post_id'];
		$GLOBALS['posts'][ $id ] = (object) array_merge( $data, array( 'ID' => $id, 'post_password' => '' ) );
		return $id;
	}
	function update_post_meta( $id, $key, $value, ...$args ) { $GLOBALS['post_meta'][ $id ][ $key ] = $value; return true; }
	function wp_generate_uuid4() { return uniqid(); }
	function wp_cache_delete( ...$args ) {}
	$GLOBALS['wpdb'] = new class {
		public $options = 'wp_options';
		public function delete( $table, $where, $formats ) {
			if ( ( $GLOBALS['options'][ $where['option_name'] ] ?? null ) === $where['option_value'] ) { unset( $GLOBALS['options'][ $where['option_name'] ] ); }
		}
	};
	function get_permalink( $post ) { return 'https://example.test/?page_id=' . $post->ID; }
	function home_url( $path = '' ) { return 'https://example.test' . $path; }
	function url_to_postid( $url ) { return preg_match( '/page_id=(\d+)$/', $url, $m ) ? (int) $m[1] : 0; }
	// Real per-post metadata. The shim used to answer 'it' for EVERY existing
	// post, which made a page the administrator chose indistinguishable from
	// one the wizard created — and telling those two apart is the whole job of
	// the third clause in link_banner().
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['post_meta'][ $id ][ $key ] ?? ''; }
	function faz_clear_banner_template_cache() { $GLOBALS['cache_cleared']++; }
	$GLOBALS['banner_store'] = null;
	$GLOBALS['post_meta'] = array();
	$GLOBALS['next_post_id'] = 0;
	$GLOBALS['banner_save_drops'] = false;
	$GLOBALS['cache_cleared'] = 0;
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
	// F001: the wizard's policy step is advisory. Setup has already been saved
	// when it runs, so every failure is a warning appended to finish()'s own.
	$GLOBALS['options'] = $GLOBALS['posts'] = array();
	$banner_link = function ( $lang = 'it' ) { return $GLOBALS['banner_store'][ $lang ]['notice']['elements']['privacyLink'] ?? null; };
	$GLOBALS['banner_store'] = array( 'it' => array( 'notice' => array( 'elements' => array( 'privacyLink' => '' ) ) ) );
	$done = Policy_Page::apply_to_setup( array( 'success' => true, 'warning' => '' ), 'it', 'gdpr' );
	check( isset( $done['cookie_page']['id'] ) && '' === $done['warning'], 'setup policy: success adds the page and no warning' );
	check( $banner_link() === $done['cookie_page']['url'] && 1 === $GLOBALS['cache_cleared'], 'setup policy: empty banner link points at the new page' );
	$missing = Policy_Page::apply_to_setup( array( 'success' => true, 'warning' => 'TCF was not enabled.' ), 'cy', 'gdpr' );
	check( ! is_wp_error( $missing ) && true === $missing['success'], 'setup policy: missing template never fails the finished setup' );
	check( ! isset( $missing['cookie_page'] ), 'setup policy: failed creation leaves cookie_page unset' );
	check( 0 === strpos( $missing['warning'], 'TCF was not enabled. ' ) && strlen( $missing['warning'] ) > strlen( 'TCF was not enabled. ' ), 'setup policy: failure is appended to the existing warning' );
	$GLOBALS['allowed'] = false;
	$denied = Policy_Page::apply_to_setup( array( 'success' => true, 'warning' => '' ), 'it', 'gdpr' );
	check( ! is_wp_error( $denied ) && '' !== $denied['warning'] && ! isset( $denied['cookie_page'] ), 'setup policy: missing capability becomes a warning' );
	$GLOBALS['allowed'] = true;
	$GLOBALS['banner_store'] = array( 'fr' => array( 'notice' => array( 'elements' => array( 'privacyLink' => '' ) ) ) );
	$GLOBALS['banner_save_drops'] = true;
	$unlinked = Policy_Page::apply_to_setup( array( 'success' => true, 'warning' => '' ), 'fr', 'gdpr' );
	check( ! is_wp_error( $unlinked ) && isset( $unlinked['cookie_page']['id'] ) && '' !== $unlinked['warning'], 'setup policy: banner-link read-back failure is a warning, page kept' );
	$GLOBALS['banner_save_drops'] = false;
	$GLOBALS['banner_store'] = array( 'it' => array( 'notice' => array( 'elements' => array( 'privacyLink' => 'https://example.test/my-privacy' ) ) ) );
	Policy_Page::apply_to_setup( array( 'success' => true, 'warning' => '' ), 'it', 'gdpr' );
	check( 'https://example.test/my-privacy' === $banner_link(), 'setup policy: custom banner link is preserved' );
	// The discriminating case. The rule overwrites a link that points at a page
	// the wizard itself created, and leaves alone one the administrator chose —
	// so a custom link that RESOLVES to an existing page must survive. With the
	// old shim every existing page looked wizard-made and this could not fail.
	$external_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Privacy', 'post_content' => '' ) );
	$external_link = 'https://example.test/?page_id=' . $external_id;
	$GLOBALS['banner_store'] = array( 'it' => array( 'notice' => array( 'elements' => array( 'privacyLink' => $external_link ) ) ) );
	Policy_Page::apply_to_setup( array( 'success' => true, 'warning' => '' ), 'it', 'gdpr' );
	check( $external_link === $banner_link(), 'setup policy: a link to an existing page nobody generated is left alone' );
	// And the counterpart: a link to a page the wizard DID create is re-pointed
	// at the current one, which is what the meta is read for.
	$GLOBALS['post_meta'][ $external_id ]['_faz_setup_policy_language'] = 'it';
	$GLOBALS['banner_store'] = array( 'it' => array( 'notice' => array( 'elements' => array( 'privacyLink' => $external_link ) ) ) );
	$relink = Policy_Page::apply_to_setup( array( 'success' => true, 'warning' => '' ), 'it', 'gdpr' );
	check( isset( $relink['cookie_page']['url'] ) && $banner_link() === $relink['cookie_page']['url'], 'setup policy: a link to a wizard-generated page is re-pointed' );
	// F005: a corrupted non-scalar privacyLink must not reach strpos().
	$GLOBALS['banner_store'] = array( 'it' => array( 'notice' => array( 'elements' => array( 'privacyLink' => array( 'bad' ) ) ) ) );
	$corrupt = Policy_Page::apply_to_setup( array( 'success' => true, 'warning' => '' ), 'it', 'gdpr' );
	check( isset( $corrupt['cookie_page']['url'] ) && $banner_link() === $corrupt['cookie_page']['url'], 'setup policy: non-scalar link is treated as empty and repaired' );
	// Item 5: template availability for the wizard checkbox.
	check( Policy_Page::has_template( 'it', 'gdpr' ) && Policy_Page::has_template( 'en', 'ccpa' ), 'shipped templates are reported available' );
	check( ! Policy_Page::has_template( 'cy', 'gdpr' ) && ! Policy_Page::has_template( 'it', 'invalid' ) && ! Policy_Page::has_template( array(), 'gdpr' ), 'missing template, bad law or bad language reported unavailable' );
	echo "policy-page: $passed passed, 0 failed\n";
}
