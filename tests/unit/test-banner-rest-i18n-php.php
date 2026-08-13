<?php
/**
 * Regression tests for the language-swapped banner's REST i18n payload.
 *
 * Browser-language detection replaces the server-default banner with a REST
 * payload for the visitor's language. Every string used by client-rendered UI
 * must therefore be present in that payload; otherwise Object.assign() keeps
 * the initial server-locale value and produces a mixed-language banner.
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['faz_banner_rest_settings'] = array(
	'age_gate' => array( 'min_age' => 16 ),
);

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return 'faz_settings' === $name ? $GLOBALS['faz_banner_rest_settings'] : $default;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return '[target locale] ' . $text;
	}
}

require_once dirname( __DIR__, 2 ) . '/frontend/modules/banner-rest/class-banner-rest.php';

use FazCookie\Frontend\Modules\Banner_Rest\Banner_Rest;

$tests_run = 0;
$failed    = 0;

function banner_rest_i18n_eq( $actual, $expected, $label ) {
	global $tests_run, $failed;
	$tests_run++;
	if ( $actual === $expected ) {
		echo "  \033[32mPASS\033[0m {$label}\n";
		return;
	}
	$failed++;
	echo "  \033[31mFAIL\033[0m {$label}\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        actual:   ' . var_export( $actual, true ) . "\n";
}

$controller = ( new ReflectionClass( Banner_Rest::class ) )->newInstanceWithoutConstructor();
$method     = new ReflectionMethod( Banner_Rest::class, 'build_i18n_payload' );
$method->setAccessible( true );
$payload    = $method->invoke( $controller );

$client_i18n_keys = array(
	'privacy_region_label',
	'consent_saved',
	'optout_preferences_label',
	'customise_consent_preferences_label',
	'service_consent_label',
	'cookies',
	'cookie_consent_label',
	'vendor_consent_label',
	'third_party_cookie_note',
	'age_confirm_label',
	'age_confirm_error',
	'optout_autoclose_countdown',
);

banner_rest_i18n_eq(
	array_keys( $payload ),
	$client_i18n_keys,
	'language-swap REST payload carries every client-side i18n key'
);
banner_rest_i18n_eq(
	$payload['optout_autoclose_countdown'],
	'[target locale] Banner closes automatically in 0 s...',
	'countdown fallback is translated in the requested locale before substitution'
);
banner_rest_i18n_eq(
	$payload['third_party_cookie_note'],
	'[target locale] These cookies are set by the embedded service on its own domain and are controlled by allowing or blocking the embed above — they cannot be removed individually.',
	'dynamically rendered per-cookie note comes from the requested locale'
);

echo "\n" . ( $tests_run - $failed ) . "/{$tests_run} passed\n";
exit( $failed > 0 ? 1 : 0 );
