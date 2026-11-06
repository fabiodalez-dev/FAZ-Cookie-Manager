<?php
/** Public email encoding and legacy policy shortcode regression tests. */
namespace FazCookie\Includes {
	class Privacy_Policy_Text {
		public static function withdrawal_sentence( $footer ) { return 'Manage consent below.'; }
	}
	class Withdrawal_Path {
		const PATH_FOOTER_LINK = 'footer';
		public static function configured_path() { return 'button'; }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'FAZ_VERSION', 'test' );
	function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false ); }
	function esc_attr( $value ) { return esc_html( $value ); }
	function __( $value, $domain = '' ) { return $value; }
	function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
	function esc_html_e( $value, $domain = '' ) { echo esc_html( $value ); }
	function get_option( $key, $default = false ) {
		return $GLOBALS['options'][ $key ] ?? $default;
	}
	function get_bloginfo( $key ) { return 'Example'; }
	function shortcode_atts( $defaults, $atts, $tag ) { return array_merge( $defaults, $atts ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
	function sanitize_email( $value ) { return $value; }
	function add_shortcode( $tag, $callback ) {}
	function wp_register_style( ...$args ) {}
	function wp_enqueue_style( ...$args ) {}
	function wp_add_inline_style( ...$args ) {}
	function date_i18n( $format ) { return '2026-11-05'; }
	function absint( $value ) { return abs( (int) $value ); }
	function is_email( $value ) { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function rest_sanitize_boolean( $value ) {
		return is_string( $value ) && in_array( strtolower( $value ), array( 'false', '0' ), true ) ? false : (bool) $value;
	}
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; return true; }
	function do_action( $hook ) { $GLOBALS['actions'][] = $hook; }
	class WP_REST_Request {
		private $body;
		public function __construct( $body ) { $this->body = $body; }
		public function get_json_params() { return $this->body; }
	}
	class WP_REST_Response {
		public $data;
		public function __construct( $data, $status = 200 ) { $this->data = $data; }
	}
	require_once __DIR__ . '/../../includes/class-cookie-policy-shortcode.php';
	require_once __DIR__ . '/../../admin/modules/cookie-policy-generator/includes/class-generator.php';
	require_once __DIR__ . '/../../admin/modules/cookie-policy-generator/api/class-cookie-policy-api.php';

	use FazCookie\Admin\Modules\Cookie_Policy_Generator\Api\Cookie_Policy_Api;
	use FazCookie\Includes\Email_Obfuscation;
	use FazCookie\Includes\Cookie_Policy_Shortcode;
	$passed = 0;
	function check( $ok, $label ) {
		global $passed;
		if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
		$passed++;
	}
	$email = "privacy+test&'box@example.test";
	$html = '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
	$variants = array();
	for ( $i = 0; $i < 20; $i++ ) {
		$encoded = Email_Obfuscation::apply( $html, array( $email, $email, '' ) );
		check( html_entity_decode( $encoded, ENT_QUOTES, 'UTF-8' ) === html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ), 'special characters and mailto preserved' );
		check( false === strpos( $encoded, '@' ) && false === strpos( $encoded, 'privacy' ), 'all address characters encoded' );
		check( 1 === substr_count( $encoded, '<a ' ) && 2 === substr_count( $encoded, '"' ), 'encoding cannot break out of an attribute' );
		$variants[ $encoded ] = true;
	}
	check( 1 === count( $variants ), 'the same address always produces the same markup' );
	$only = array_keys( $variants )[0];
	check( 1 === preg_match( '/&#x[0-9a-f]+;/', $only ) && 1 === preg_match( '/&#[0-9]+;/', $only ), 'decimal and hexadecimal entities are mixed' );
	$other = Email_Obfuscation::apply( '<p>' . esc_html( 'dpo@example.test' ) . '</p>', array( 'dpo@example.test' ) );
	check( html_entity_decode( $other, ENT_QUOTES, 'UTF-8' ) === '<p>dpo@example.test</p>', 'a second address decodes to itself' );
	check( Email_Obfuscation::apply( $html, array( '' ) ) === $html, 'empty fields do not modify HTML' );
	$GLOBALS['options'] = array( 'admin_email' => $email, 'date_format' => 'Y-m-d' );
	$shortcode = new Cookie_Policy_Shortcode();
	$default = $shortcode->render( array( 'show_table' => 'no' ) );
	check( false === strpos( $default, '@' ), 'legacy shortcode defaults to encoded email' );
	check( false !== strpos( html_entity_decode( $default, ENT_QUOTES, 'UTF-8' ), 'mailto:' . $email ), 'legacy mailto decodes correctly' );
	$GLOBALS['options']['faz_cookie_policy_data'] = array( 'obfuscate_emails' => false );
	$plain = $shortcode->render( array( 'show_table' => 'no' ) );
	check( false !== strpos( $plain, esc_html( $email ) ), 'legacy shortcode honors disabled option' );
	check( html_entity_decode( $default, ENT_QUOTES, 'UTF-8' ) === html_entity_decode( $plain, ENT_QUOTES, 'UTF-8' ), 'legacy visible output is unchanged' );
	$GLOBALS['options']['faz_cookie_policy_data']['obfuscate_emails'] = true;
	$custom = $shortcode->render( array( 'show_table' => 'no', 'contact' => 'custom@example.test' ) );
	check( false === strpos( $custom, 'custom@example.test' ) && false !== strpos( html_entity_decode( $custom, ENT_QUOTES, 'UTF-8' ), 'custom@example.test' ), 'custom shortcode contact is also encoded' );

	$api = Cookie_Policy_Api::get_instance();
	unset( $GLOBALS['options']['faz_cookie_policy_data'] );
	check( true === $api->get_settings()->data['obfuscate_emails'], 'GET defaults to enabled on existing installs' );
	$settings = array(
		'company' => array( 'name' => 'Example', 'email' => 'privacy@example.test' ),
		'dpo'     => array( 'email' => 'dpo@example.test' ),
	);
	foreach ( array( false, true, 'false', 'true', '0', '1' ) as $value ) {
		$settings['obfuscate_emails'] = $value;
		$result = $api->set_settings( new WP_REST_Request( $settings ) );
		$expected = rest_sanitize_boolean( $value );
		check( true === $result->data['saved'], 'settings save succeeds' );
		check( $expected === $api->get_settings()->data['obfuscate_emails'], 'toggle is saved and reloaded as a boolean' );
		check( 'privacy@example.test' === $result->data['data']['company']['email'] && 'dpo@example.test' === $result->data['data']['dpo']['email'], 'both stored email fields remain plain text' );
	}
	check( in_array( 'faz_purge_page_cache', $GLOBALS['actions'], true ), 'settings changes purge public page caches' );
	echo "$passed checks passed\n";
}
