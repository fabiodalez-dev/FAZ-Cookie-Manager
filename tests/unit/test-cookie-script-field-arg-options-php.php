<?php
/**
 * Regression test (2026-11 security audit, hardening note): the REST refusal
 * of opt_in_script / opt_out_script for a user without unfiltered_html really
 * runs on single-item writes.
 *
 * The schema set sanitize_callback at property level. WordPress builds the
 * endpoint args with rest_get_endpoint_args_for_schema(), which copies
 * callbacks only from arg_options, so sanitize_script_field() never ran on
 * POST /faz/v1/cookies(/id) and the only protection left was the silent strip
 * in Cookie::set_meta(). This test replays core's arg building on the real
 * schema and checks the callback that comes out of it.
 *
 * Run: php tests/unit/test-cookie-script-field-arg-options-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Cookies\Api {
	/** Stand-in for the REST base: only what get_item_schema() calls. */
	abstract class API_Controller {
		public function add_additional_fields_schema( $schema ) {
			return $schema;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	$GLOBALS['faz_unfiltered_html'] = false;

	class WP_Error {
		public $code;
		public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code;
			$this->data = $data;
		}
	}
	function is_wp_error( $v ) {
		return $v instanceof WP_Error;
	}
	function current_user_can( $capability ) {
		return 'unfiltered_html' === $capability ? (bool) $GLOBALS['faz_unfiltered_html'] : true;
	}
	function __( $t, $d = '' ) {
		return $t;
	}
	function add_action( ...$a ) {}
	function do_action( ...$a ) {}

	/**
	 * The part of core's rest_get_endpoint_args_for_schema() that matters
	 * here: defaults first, then arg_options merged over them. Property-level
	 * sanitize_callback is not consulted.
	 */
	function core_endpoint_args( $schema ) {
		$args = array();
		foreach ( $schema['properties'] as $field_id => $params ) {
			if ( ! empty( $params['readonly'] ) ) {
				continue;
			}
			$args[ $field_id ] = array(
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
			);
			if ( isset( $params['arg_options'] ) ) {
				$args[ $field_id ] = array_merge( $args[ $field_id ], $params['arg_options'] );
			}
		}
		return $args;
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

	$api    = ( new ReflectionClass( Cookies_API::class ) )->newInstanceWithoutConstructor();
	$schema = $api->get_item_schema();
	$args   = core_endpoint_args( $schema );

	echo "\nscript fields: the REST arg runs sanitize_script_field\n";
	foreach ( array( 'opt_in_script', 'opt_out_script' ) as $field ) {
		$cb = $args[ $field ]['sanitize_callback'] ?? null;
		check( array( Cookies_API::class, 'sanitize_script_field' ) === $cb, "{$field}: the endpoint arg carries sanitize_script_field" );
		check( 10000 === ( $schema['properties'][ $field ]['maxLength'] ?? null ), "{$field}: maxLength stays on the schema for rest_validate_request_arg" );

		$GLOBALS['faz_unfiltered_html'] = false;
		$out = is_callable( $cb ) ? call_user_func( $cb, 'alert(1)', null, $field ) : null;
		check( is_wp_error( $out ) && 403 === ( $out->data['status'] ?? null ), "{$field}: a non-empty script from a user without unfiltered_html is refused with 403" );
		$out = is_callable( $cb ) ? call_user_func( $cb, '', null, $field ) : null;
		check( '' === $out, "{$field}: an empty value is still accepted from that user" );

		$GLOBALS['faz_unfiltered_html'] = true;
		$out = is_callable( $cb ) ? call_user_func( $cb, 'console.log(1)', null, $field ) : null;
		check( 'console.log(1)' === $out, "{$field}: a user with unfiltered_html saves the script" );
	}

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
