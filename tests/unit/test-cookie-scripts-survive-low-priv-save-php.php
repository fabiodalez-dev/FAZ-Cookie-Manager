<?php
/**
 * Regression test (2026-11 security audit, hardening note): an administrator's
 * opt-in / opt-out scripts survive a cookie being loaded and saved by a user
 * without unfiltered_html.
 *
 * Cookie::set_data() used to route the stored meta through set_meta(), whose
 * capability gate strips the script keys for a user below unfiltered_html. A
 * READ by such a user (a multisite site admin, DISALLOW_UNFILTERED_HTML)
 * therefore dropped the scripts from memory, and any later save by that user
 * (a description edit, a bulk category move) persisted the loss and silently
 * removed the consent-gated blocker behaviour. What that user WRITES must
 * still be filtered.
 *
 * Persistence goes through Cookie::get_meta() (Cookie_Controller writes
 * wp_json_encode( $object->get_meta() )), so asserting on get_meta() is
 * asserting on what would be stored.
 *
 * Run: php tests/unit/test-cookie-scripts-survive-low-priv-save-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Cookies\Api {
	/** Empty stand-in: the static gate under test inherits nothing it uses. */
	abstract class API_Controller {}
}

namespace {
	error_reporting( E_ALL & ~E_DEPRECATED );
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['faz_unfiltered_html'] = false;
	$GLOBALS['faz_stripped_events'] = 0;

	function current_user_can( $capability ) {
		return 'unfiltered_html' === $capability ? (bool) $GLOBALS['faz_unfiltered_html'] : true;
	}
	function do_action( $hook, ...$args ) {
		if ( 'faz_meta_script_keys_stripped' === $hook ) {
			++$GLOBALS['faz_stripped_events'];
		}
	}
	function add_action( ...$a ) {}
	function add_filter( ...$a ) {}
	function apply_filters( $hook, $value, ...$rest ) {
		return $value;
	}
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
	function sanitize_textarea_field( $v ) {
		return is_scalar( $v ) ? trim( strip_tags( (string) $v ) ) : '';
	}
	function sanitize_text_field( $v ) {
		return is_scalar( $v ) ? trim( strip_tags( (string) $v ) ) : '';
	}
	function sanitize_key( $v ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) );
	}
	function sanitize_title( $v ) {
		return sanitize_key( str_replace( ' ', '-', (string) $v ) );
	}
	function esc_url_raw( $v ) {
		return (string) $v;
	}
	function wp_kses_post( $v ) {
		return (string) $v;
	}
	function wp_filter_post_kses( $v ) {
		return (string) $v;
	}
	function wp_kses( $v, $allowed = array() ) {
		return (string) $v;
	}
	function faz_allowed_html() {
		return array();
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
	function esc_url( $v ) {
		return (string) $v;
	}
	function absint( $v ) {
		return abs( (int) $v );
	}
	function __( $t, $d = '' ) {
		return $t;
	}
	function faz_default_language() {
		return 'en';
	}
	function faz_selected_languages( $language = '' ) {
		return array( 'en' );
	}
	function faz_current_language( $reset = false ) {
		return 'en';
	}

	$root = dirname( __DIR__, 2 );
	require_once $root . '/includes/class-store.php';
	require_once $root . '/admin/modules/cookies/includes/class-cookie.php';
	require_once $root . '/admin/modules/cookies/api/class-cookies-api.php';

	use FazCookie\Admin\Modules\Cookies\Includes\Cookie;

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

	function stored_row() {
		return (object) array(
			'cookie_id'     => 7,
			'name'          => '_ga',
			'slug'          => '_ga',
			'description'   => '{"en":"Analytics"}',
			'domain'        => 'example.test',
			'duration'      => '{"en":"1 year"}',
			'category'      => 3,
			'type'          => '',
			'discovered'    => 0,
			'url_pattern'   => '',
			'meta'          => json_encode(
				array(
					'opt_in_script'  => 'gtag("consent","update",{analytics_storage:"granted"})',
					'opt_out_script' => 'gtag("consent","update",{analytics_storage:"denied"})',
					'policy_url'     => 'https://provider.example/privacy',
				)
			),
			'date_created'  => '2026-01-01 00:00:00',
			'date_modified' => '2026-01-01 00:00:00',
		);
	}
	$in  = 'gtag("consent","update",{analytics_storage:"granted"})';
	$out = 'gtag("consent","update",{analytics_storage:"denied"})';

	echo "\nlow-privilege user (no unfiltered_html)\n";
	$GLOBALS['faz_unfiltered_html'] = false;

	$cookie = new Cookie( stored_row() );
	check( $in === $cookie->get_opt_in_script(), 'loading the row keeps the administrator opt-in script in memory' );
	check( $out === $cookie->get_opt_out_script(), 'loading the row keeps the administrator opt-out script in memory' );

	$GLOBALS['faz_stripped_events'] = 0;
	$cookie->set_policy_url( 'https://provider.example/new-privacy' );
	$meta = $cookie->get_meta();
	check( $in === ( $meta['opt_in_script'] ?? null ) && $out === ( $meta['opt_out_script'] ?? null ), 'a meta write by that user (policy URL) keeps both scripts in what is saved' );
	check( 'https://provider.example/new-privacy' === ( $meta['policy_url'] ?? null ), 'and the field the user changed is changed' );
	check( 0 === $GLOBALS['faz_stripped_events'], 'and no strip is reported: the stored scripts are not the user\'s write' );

	$cookie->set_opt_in_script( 'alert(document.cookie)' );
	check( $in === $cookie->get_opt_in_script(), 'a script the user writes is still refused: the stored opt-in script stays' );
	check( $GLOBALS['faz_stripped_events'] >= 1, 'and that refusal is reported (WP-CLI import warning)' );

	$cookie->set_meta( '{"opt_out_script":"steal()","keep":"x"}' );
	$meta = $cookie->get_meta();
	check( $out === ( $meta['opt_out_script'] ?? null ), 'a whole-meta JSON write cannot replace the stored opt-out script' );
	check( 'x' === ( $meta['keep'] ?? null ), 'while its other keys are written' );
	check( $in === ( $meta['opt_in_script'] ?? null ), 'and cannot drop the opt-in script by leaving it out' );

	$fresh = new Cookie();
	$fresh->set_opt_in_script( 'alert(1)' );
	check( '' === $fresh->get_opt_in_script(), 'a new cookie: the user\'s script is stripped as before' );

	echo "\nprivileged user (unfiltered_html)\n";
	$GLOBALS['faz_unfiltered_html'] = true;
	$admin_cookie = new Cookie( stored_row() );
	$admin_cookie->set_opt_in_script( 'console.log(1)' );
	check( 'console.log(1)' === $admin_cookie->get_opt_in_script(), 'an administrator can change the script' );
	$admin_cookie->set_opt_out_script( '' );
	check( '' === $admin_cookie->get_opt_out_script(), 'and clear it' );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
