<?php
/**
 * Regression test (2026-11 security audit, hardening note): the REST refusal
 * of opt_in_script / opt_out_script for a user without unfiltered_html is a
 * 403 from the permission check, not a sanitiser.
 *
 * The schema set sanitize_callback at property level, which core's
 * rest_get_endpoint_args_for_schema() never copies, so the refusal never ran
 * on POST /faz/v1/cookies(/id). Moving it under arg_options made it run, but
 * WordPress sanitises before the permission callback and wraps a sanitiser's
 * WP_Error in a 400 rest_invalid_param, so even a subscriber got 400. The
 * gate now lives in create_item_permissions_check() and
 * update_item_permissions_check(); the E2E spec cookie-api-security.spec.ts
 * checks the status codes against real WordPress. This test replays core's
 * arg building on the real schema and checks no WP_Error sanitiser is back.
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

	echo "\nscript fields: the gate is not a sanitiser (that would answer 400)\n";
	foreach ( array( 'opt_in_script', 'opt_out_script' ) as $field ) {
		$cb = $args[ $field ]['sanitize_callback'] ?? null;
		check( 'rest_sanitize_request_arg' === $cb, "{$field}: the endpoint arg keeps core's sanitiser" );
		check( 10000 === ( $schema['properties'][ $field ]['maxLength'] ?? null ), "{$field}: maxLength stays on the schema for rest_validate_request_arg" );
	}
	foreach ( array( 'create_item_permissions_check', 'update_item_permissions_check' ) as $method ) {
		$declared = ( new ReflectionMethod( Cookies_API::class, $method ) )->getDeclaringClass()->getName();
		check( Cookies_API::class === $declared, "{$method}: Cookies_API carries the script gate" );
	}

	$GLOBALS['faz_unfiltered_html'] = false;
	$out = Cookies_API::sanitize_script_field( 'alert(1)', null, 'opt_in_script' );
	check( is_wp_error( $out ) && 403 === ( $out->data['status'] ?? null ), 'sanitize_script_field: a non-empty script from a user without unfiltered_html is refused with 403' );
	check( '' === Cookies_API::sanitize_script_field( '', null, 'opt_in_script' ), 'sanitize_script_field: an empty value is accepted from that user' );
	$GLOBALS['faz_unfiltered_html'] = true;
	check( 'console.log(1)' === Cookies_API::sanitize_script_field( 'console.log(1)', null, 'opt_in_script' ), 'sanitize_script_field: a user with unfiltered_html saves the script' );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
