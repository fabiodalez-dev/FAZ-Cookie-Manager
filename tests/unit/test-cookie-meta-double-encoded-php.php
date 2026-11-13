<?php
/**
 * Regression test (2026-11 security audit, lead 3): the unfiltered_html gate
 * on cookie meta must strip opt_in_script / opt_out_script however many
 * JSON-string layers wrap them.
 *
 * Settings import passes each cookie's `meta` through
 * Cookies_API::sanitize_meta_for_current_user(). The gate decoded once and
 * returned any value that did not decode to an array unchanged. A meta whose
 * decoded value was itself the JSON text of {"opt_in_script":"…"} therefore
 * passed untouched; the readers decode twice (Cookie_Controller::prepare_json,
 * then Cookie::get_meta), and the next save by an unfiltered_html user wrote
 * it back as a live opt_in_script that the frontend runs for every visitor who
 * accepts the category. A site admin without unfiltered_html (multisite
 * subsite admin, DISALLOW_UNFILTERED_HTML) could plant script that way.
 *
 * Pure-logic: no browser, no DB, no live WordPress — WP surface is stubbed.
 *
 * Run: php tests/unit/test-cookie-meta-double-encoded-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Cookies\Api {
	/** Empty stand-in: the static gate under test inherits nothing it uses. */
	abstract class API_Controller {}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['faz_unfiltered_html'] = false;
	$GLOBALS['faz_stripped_events'] = 0;

	function current_user_can( $capability ) {
		return 'unfiltered_html' === $capability ? (bool) $GLOBALS['faz_unfiltered_html'] : true;
	}
	function do_action( $hook ) {
		if ( 'faz_meta_script_keys_stripped' === $hook ) {
			++$GLOBALS['faz_stripped_events'];
		}
	}
	function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) {}
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}

	require_once dirname( __DIR__, 2 ) . '/admin/modules/cookies/api/class-cookies-api.php';

	use FazCookie\Admin\Modules\Cookies\Api\Cookies_API;

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

	/** What a reader that keeps decoding JSON-string layers would end up with. */
	function fully_decoded( $value ) {
		for ( $i = 0; $i < 6 && is_string( $value ); $i++ ) {
			$next = json_decode( $value, true );
			if ( null === $next ) {
				break;
			}
			$value = $next;
		}
		return $value;
	}

	$payload = array(
		'opt_in_script'  => 'window.__faz_poc=1',
		'opt_out_script' => 'window.__faz_poc=2',
		'keep'           => 'value',
	);

	echo "\nlow-privilege import (no unfiltered_html)\n";

	$single = Cookies_API::sanitize_meta_for_current_user( json_encode( $payload ) );
	$dec    = fully_decoded( $single );
	check( is_array( $dec ) && ! isset( $dec['opt_in_script'] ) && ! isset( $dec['opt_out_script'] ), 'single-encoded JSON: script keys stripped (existing behaviour)' );
	check( is_array( $dec ) && 'value' === ( $dec['keep'] ?? null ), 'single-encoded JSON: other keys kept' );

	$double = Cookies_API::sanitize_meta_for_current_user( json_encode( json_encode( $payload ) ) );
	check( false === strpos( (string) $double, 'opt_in_script' ), 'double-encoded JSON: opt_in_script no longer survives the gate' );
	check( false === strpos( (string) $double, 'opt_out_script' ), 'double-encoded JSON: opt_out_script no longer survives the gate' );
	$dec = fully_decoded( $double );
	check( is_array( $dec ) && 'value' === ( $dec['keep'] ?? null ), 'double-encoded JSON: the legitimate keys are kept (normalised to one layer)' );
	check( is_array( json_decode( (string) $double, true ) ), 'double-encoded JSON: stored back as a single JSON object, the shape the readers expect' );

	$triple = Cookies_API::sanitize_meta_for_current_user( json_encode( json_encode( json_encode( $payload ) ) ) );
	check( false === strpos( (string) $triple, 'opt_in_script' ), 'triple-encoded JSON: stripped as well' );

	$deep = json_encode( $payload );
	for ( $i = 0; $i < 6; $i++ ) {
		$deep = json_encode( $deep );
	}
	$out = Cookies_API::sanitize_meta_for_current_user( $deep );
	check( false === strpos( (string) $out, 'opt_in_script' ), 'nesting beyond the depth bound is refused, not passed through' );

	$arr = Cookies_API::sanitize_meta_for_current_user( $payload );
	check( is_array( $arr ) && ! isset( $arr['opt_in_script'] ) && 'value' === $arr['keep'], 'array input: script keys stripped' );

	check( '"hello"' === Cookies_API::sanitize_meta_for_current_user( '"hello"' ), 'a JSON string literal that is not an object is returned unchanged' );
	check( 'plain text' === Cookies_API::sanitize_meta_for_current_user( 'plain text' ), 'non-JSON text is returned unchanged' );
	check( $GLOBALS['faz_stripped_events'] >= 4, 'the strip is announced (WP-CLI import warning) for every stripped value' );

	echo "\nprivileged import (unfiltered_html)\n";
	$GLOBALS['faz_unfiltered_html'] = true;
	$raw = json_encode( json_encode( $payload ) );
	check( $raw === Cookies_API::sanitize_meta_for_current_user( $raw ), 'a user with unfiltered_html keeps the value exactly as sent' );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
