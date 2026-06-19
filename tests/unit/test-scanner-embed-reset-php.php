<?php
/**
 * Standalone unit test for scanner embed URL state reset.
 *
 * Run: php tests/unit/test-scanner-embed-reset-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Known_Providers {
		public static function get_all() {
			return array(
				'youtube' => array(
					'label'    => 'YouTube',
					'category' => 'marketing',
					'patterns' => array( 'youtube.com/embed' ),
					'cookies'  => array( 'YSC' ),
				),
			);
		}
		public static function get_cookie_map() {
			return array();
		}
	}
}

namespace FazCookie\Admin\Modules\Scanner\Includes {
	class Scanner_Logger {
		public static function get_instance() {
			return new self();
		}
		public function start( $context ) {}
		public function log( $message, $context = null ) {}
		public function finish() {}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['__faz_options'] = array();

	if ( ! function_exists( 'absint' ) ) {
		function absint( $value ) {
			return abs( (int) $value );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) {
			return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
		}
	}
	if ( ! function_exists( 'wp_parse_args' ) ) {
		function wp_parse_args( $args, $defaults = array() ) {
			return array_merge( (array) $defaults, (array) $args );
		}
	}
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( $path = '' ) {
			return 'https://example.test/' . ltrim( (string) $path, '/' );
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $key, $default = false ) {
			return array_key_exists( $key, $GLOBALS['__faz_options'] ) ? $GLOBALS['__faz_options'][ $key ] : $default;
		}
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $key, $value ) {
			$GLOBALS['__faz_options'][ $key ] = $value;
			return true;
		}
	}
	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type ) {
			return '2026-06-19 12:00:00';
		}
	}

	require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';

	class FazTest_Scanner_Controller extends \FazCookie\Admin\Modules\Scanner\Includes\Controller {
		public $saved_cookies = null;

		public function discover_pages( $site_url, $max ) {
			return array( $site_url );
		}

		public function scan_page( $url ) {
			return array();
		}

		public function save_cookies( $cookies ) {
			$this->saved_cookies = $cookies;
		}
	}

	function assert_eq( $actual, $expected, $label ) {
		if ( $actual !== $expected ) {
			echo "FAIL: {$label}\n";
			echo 'expected: ' . var_export( $expected, true ) . "\n";
			echo 'actual:   ' . var_export( $actual, true ) . "\n";
			exit( 1 );
		}
		echo "PASS: {$label}\n";
	}

	$controller = new FazTest_Scanner_Controller();
	$prop       = new ReflectionProperty( \FazCookie\Admin\Modules\Scanner\Includes\Controller::class, 'scanned_embed_urls' );
	$prop->setAccessible( true );
	$prop->setValue( $controller, array( 'https://www.youtube.com/embed/stale-video' ) );

	$controller->run_scan( 1 );

	assert_eq( $controller->saved_cookies, array(), 'run_scan resets stale embed URLs before scanning' );
	assert_eq( $prop->getValue( $controller ), array(), 'embed URL accumulator remains empty when scanned pages have no embeds' );

	echo "ALL PASS\n";
	exit( 0 );
}
