<?php
/**
 * Standalone unit tests: a hand-declared cookie's toggle blocks the script
 * that sets it (#360, 1.40.0 compliance review M3).
 *
 * Subsystem: per-service consent / declared cookies as services.
 *
 * The defect: Frontend::get_declared_cookie_services() built every declared
 * service with `'patterns' => array()`. With per-service consent on, the
 * cookie got a toggle, but get_pattern_service_map() had nothing to match a
 * script against, so check_per_service_blocking() answered null and the
 * output buffer judged the script by category alone: a visitor who accepted
 * Functional and switched "booking_sess" off still received the booking
 * widget's script.
 *
 * Covers, on the real Frontend class:
 *   1. get_declared_cookie_services() carries the row's url_pattern (as the
 *      inventory map reports it) into `patterns`, and tolerates a map entry
 *      without the key.
 *   2. get_enforceable_services() / get_pattern_service_map() list the
 *      pattern under the declared service id.
 *   3. check_per_service_blocking(): svc.<id>:no blocks the matching script
 *      inside an accepted category, svc.<id>:yes releases it, no token defers
 *      to the category, and a declared row without a pattern never matches.
 *
 * The inventory map itself (Cookie_Controller::get_declared_service_map())
 * is exercised against a mock $wpdb in test-cookie-controller-edge-php.php.
 *
 * Run:
 *   php tests/unit/test-declared-service-patterns-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	// Empty catalogue: every service in these tests is a declared one.
	class Known_Providers {
		public static function get_all() {
			return array();
		}
		public static function get_cookie_map() {
			return array();
		}
		public static function get_pattern_map() {
			return array();
		}
	}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {
	// Stand-ins: get_declared_cookie_services() only asks the controller for
	// the map and checks that the Cookie class is loaded.
	class Cookie {}
	class Cookie_Controller {
		public static $map = array( 'services' => array(), 'by_cookie' => array() );
		private static $instance;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function get_declared_service_map() {
			return self::$map;
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	function get_transient( $key ) { return false; }
	function set_transient( $key, $value, $ttl = 0 ) { return true; }
	function wp_strip_all_tags( $str ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) ); }
	function sanitize_text_field( $str ) {
		return trim( wp_strip_all_tags( preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) ) );
	}
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
	function apply_filters( $tag, $value ) { return $value; }
	function get_option( $key, $default = false ) { return $default; }

	require_once dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-withdrawal-path.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;
	use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;

	$tests_run    = 0;
	$tests_failed = 0;
	function assert_eq( $actual, $expected, $label ) {
		global $tests_run, $tests_failed;
		$tests_run++;
		if ( $actual === $expected ) {
			echo "  \033[32m✓\033[0m " . $label . "\n";
			return;
		}
		$tests_failed++;
		echo "  \033[31m✗\033[0m " . $label . "\n";
		echo '      expected: ' . var_export( $expected, true ) . "\n";
		echo '      actual:   ' . var_export( $actual, true ) . "\n";
	}

	function faz_call( $fe, $method, array $args = array() ) {
		$m = new ReflectionMethod( Frontend::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $fe, $args );
	}
	function faz_set_prop( $fe, $prop, $value ) {
		$p = new ReflectionProperty( Frontend::class, $prop );
		$p->setAccessible( true );
		$p->setValue( $fe, $value );
	}
	/**
	 * A Frontend with no constructor side effects and the given svc.* decisions.
	 *
	 * @param array $service_consent id => yes|no, as get_service_consent() returns it.
	 */
	function faz_frontend( array $service_consent ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		faz_set_prop( $fe, 'service_consent_cache', $service_consent );
		return $fe;
	}

	// The inventory: booking_sess names its script, plain_cookie names none, and
	// legacy_shape is an entry without the key (a map built by older code).
	Cookie_Controller::$map = array(
		'services'  => array(
			'booking_sess' => array(
				'id'       => 'booking_sess',
				'label'    => 'booking_sess',
				'category' => 'functional',
				'cookies'  => array( 'booking_sess' ),
				'patterns' => array( 'widget.booking.example', '' ),
			),
			'plain_cookie' => array(
				'id'       => 'plain_cookie',
				'label'    => 'plain_cookie',
				'category' => 'functional',
				'cookies'  => array( 'plain_cookie' ),
				'patterns' => array(),
			),
			'legacy_shape' => array(
				'id'       => 'legacy_shape',
				'label'    => 'legacy_shape',
				'category' => 'functional',
				'cookies'  => array( 'legacy_shape' ),
			),
		),
		'by_cookie' => array( 1 => 'booking_sess', 2 => 'plain_cookie', 3 => 'legacy_shape' ),
	);
	$valid = array( 'necessary', 'functional', 'analytics' );

	echo "\n== declared services carry their script pattern (M3) ==\n\n";

	// 1. The declared service list.
	$fe       = faz_frontend( array() );
	$declared = faz_call( $fe, 'get_declared_cookie_services', array( $valid ) );
	$by_id    = array_column( $declared, null, 'id' );
	assert_eq( $by_id['booking_sess']['patterns'], array( 'widget.booking.example' ), 'the row\'s url_pattern becomes the declared service\'s pattern (empty values dropped)' );
	assert_eq( $by_id['plain_cookie']['patterns'], array(), 'a row without a url_pattern has no pattern' );
	assert_eq( $by_id['legacy_shape']['patterns'], array(), 'a map entry without the key is read as "no pattern", not a notice' );
	assert_eq( $by_id['booking_sess']['declared'], true, 'the service stays marked as declared' );

	// 2. Enforcement lists.
	$enforceable = array_column( faz_call( $fe, 'get_enforceable_services', array( $valid ) ), null, 'id' );
	assert_eq( $enforceable['booking_sess']['patterns'], array( 'widget.booking.example' ), 'the enforceable set carries the pattern (feeds _providersToBlock[].service)' );
	assert_eq( faz_call( $fe, 'get_pattern_service_map' ), array( 'widget.booking.example' => array( 'booking_sess' ) ), 'the pattern→service map names the declared service, and nothing for pattern-less rows' );

	// 3. The output buffer's per-service verdict.
	$booking = ' src="https://widget.booking.example/loader.js" ';
	$other   = ' src="https://cdn.other.example/app.js" ';

	$fe = faz_frontend( array( 'booking_sess' => 'no' ) );
	faz_call( $fe, 'get_enforceable_services', array( $valid ) );
	assert_eq( faz_call( $fe, 'check_per_service_blocking', array( $booking, '' ) ), true, 'svc.booking_sess:no blocks the booking script although its category is accepted' );
	assert_eq( faz_call( $fe, 'check_per_service_blocking', array( $other, '' ) ), null, '…and says nothing about an unrelated script' );

	$fe = faz_frontend( array( 'booking_sess' => 'yes' ) );
	faz_call( $fe, 'get_enforceable_services', array( $valid ) );
	assert_eq( faz_call( $fe, 'check_per_service_blocking', array( $booking, '' ) ), false, 'svc.booking_sess:yes releases the booking script' );

	$fe = faz_frontend( array( 'plain_cookie' => 'no' ) );
	faz_call( $fe, 'get_enforceable_services', array( $valid ) );
	assert_eq( faz_call( $fe, 'check_per_service_blocking', array( $booking, '' ) ), null, 'another service\'s "no" leaves the booking script to its category' );

	// A category that is no longer active drops the service and its pattern.
	$fe = faz_frontend( array( 'booking_sess' => 'no' ) );
	faz_call( $fe, 'get_enforceable_services', array( array( 'necessary', 'analytics' ) ) );
	assert_eq( faz_call( $fe, 'get_pattern_service_map' ), array(), 'a service whose category is not active contributes no pattern' );

	echo "\n--\nTests:  $tests_run\nFailed: $tests_failed\n\n";
	if ( $tests_failed > 0 ) {
		echo "\033[31mFAIL\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
