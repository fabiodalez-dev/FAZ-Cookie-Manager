<?php
/**
 * Paid Memberships Pro privacy-alternative regression tests.
 *
 * Exercises the real cookie builder with minimal WordPress/model doubles. The
 * critical invariant is semantic: payment may suppress the banner but may not
 * manufacture opt-in for optional purposes.
 */

namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Category_Controller {
		public static function get_instance() {
			return new self();
		}
		public function get_items() {
			return $GLOBALS['faz_pmp_categories'];
		}
	}

	class Cookie_Categories {
		private $data;
		public function __construct( $data ) {
			$this->data = $data;
		}
		public function get_visibility() {
			return ! empty( $this->data['visibility'] );
		}
		public function get_slug() {
			return $this->data['slug'];
		}
		public function get_prior_consent() {
			return ! empty( $this->data['prior_consent'] );
		}
	}
}

namespace FazCookie\Admin\Modules\Settings\Includes {
	class Settings {
		public function get( $group = '', $key = '' ) {
			return ( 'general' === $group && 'consent_revision' === $key ) ? 7 : array();
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $value ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
		}
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $value ) {
			return abs( (int) $value );
		}
	}
	if ( ! function_exists( 'faz_parse_consent_cookie' ) ) {
		function faz_parse_consent_cookie( $cookie ) {
			$out = array();
			foreach ( explode( ',', (string) $cookie ) as $part ) {
				$pair = explode( ':', $part, 2 );
				if ( 2 === count( $pair ) ) {
					$out[ $pair[0] ] = $pair[1];
				}
			}
			return $out;
		}
	}

	$GLOBALS['faz_pmp_categories'] = array(
		array( 'slug' => 'necessary', 'visibility' => 1, 'prior_consent' => 1 ),
		array( 'slug' => 'security-custom', 'visibility' => 1, 'prior_consent' => 1 ),
		array( 'slug' => 'analytics', 'visibility' => 1, 'prior_consent' => 0 ),
		array( 'slug' => 'marketing', 'visibility' => 1, 'prior_consent' => 0 ),
		array( 'slug' => 'wordpress-internal', 'visibility' => 1, 'prior_consent' => 1 ),
		array( 'slug' => 'hidden', 'visibility' => 0, 'prior_consent' => 0 ),
	);

	require_once __DIR__ . '/../../includes/integrations/class-paid-memberships-pro.php';

	$class  = new \ReflectionClass( '\\FazCookie\\Includes\\Integrations\\Paid_Memberships_Pro' );
	$method = $class->getMethod( 'build_exempted_privacy_cookie_value' );
	$method->setAccessible( true );
	$value  = $method->invoke( $class->newInstanceWithoutConstructor() );
	$parts  = faz_parse_consent_cookie( $value );

	$checks = array(
		'automatic state is not called a user action' => isset( $parts['action'] ) && 'auto' === $parts['action'],
		'aggregate consent is denied'                => isset( $parts['consent'] ) && 'no' === $parts['consent'],
		'necessary remains available'                => isset( $parts['necessary'] ) && 'yes' === $parts['necessary'],
		'custom preactive category is still denied'  => isset( $parts['security-custom'] ) && 'no' === $parts['security-custom'],
		'analytics is not silently granted'          => isset( $parts['analytics'] ) && 'no' === $parts['analytics'],
		'marketing is not silently granted'          => isset( $parts['marketing'] ) && 'no' === $parts['marketing'],
		'internal category is omitted'               => ! isset( $parts['wordpress-internal'] ),
		'hidden category is omitted'                 => ! isset( $parts['hidden'] ),
		'policy revision is preserved'               => isset( $parts['rev'] ) && '7' === $parts['rev'],
		'PMP migration/source marker is preserved'   => isset( $parts['source'] ) && 'pmp' === $parts['source'],
		'no persistent identifier is created'         => ! isset( $parts['consentid'] ),
	);

	// A record a privacy signal created is not the member's own choice — the
	// managed privacy default must still be applied to it. Before 1.31.0 this
	// branch read `action:yes` alone, and nothing tested it at all.
	$own = array( '\\FazCookie\\Includes\\Integrations\\Paid_Memberships_Pro', 'is_member_own_choice' );
	$checks['a saved banner choice is the member\'s own']            = true === call_user_func( $own, array( 'action' => 'yes', 'consent' => 'yes' ) );
	$checks['a GPC/DNSMPI record (undecided:1) is not']              = false === call_user_func( $own, array( 'action' => 'yes', 'consent' => 'no', 'undecided' => '1', 'gpc' => '1' ) );
	$checks['an answered record that once was undecided is again']   = true === call_user_func( $own, array( 'action' => 'yes', 'consent' => 'no', 'gpc' => '1' ) );
	$checks['no recorded action is not a choice']                    = false === call_user_func( $own, array( 'consent' => 'no' ) );
	$checks['the PMP-managed state itself is not a choice']          = false === call_user_func( $own, array( 'action' => 'auto', 'source' => 'pmp' ) );

	// Teardown covers the Right-to-Object record too. fazVendorLI holds the
	// objections of a choice; when that choice is replaced by the managed
	// state, or cleared when membership ends, objections left behind are
	// re-applied to whatever the visitor decides next.
	$GLOBALS['faz_pmp_expired'] = array();
	function faz_expire_browser_cookie( $name ) {
		$GLOBALS['faz_pmp_expired'][] = $name;
		unset( $_COOKIE[ $name ] );
	}
	function faz_set_browser_cookie( $name, $value ) {
		$_COOKIE[ $name ] = $value;
	}
	function faz_get_valid_consent_cookie() {
		return isset( $_COOKIE['fazcookie-consent'] ) ? $_COOKIE['fazcookie-consent'] : '';
	}
	function faz_is_pmp_managed_consent_cookie( $cookie ) {
		$parsed = faz_parse_consent_cookie( $cookie );
		return isset( $parsed['source'] ) && 'pmp' === $parsed['source'];
	}
	function faz_clear_consent_tracking_cookies() {
		foreach ( array( 'fazcookie-consent', 'fazVendorConsent', 'fazVendorLI', 'euconsent-v2' ) as $name ) {
			faz_expire_browser_cookie( $name );
		}
	}
	function do_action() {}
	function do_action_deprecated() {}
	function get_current_user_id() { return 7; }
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}
	$pmp_double = new class() extends \FazCookie\Includes\Integrations\Paid_Memberships_Pro {
		public $exempt = false;
		public function __construct() {}
		public function is_current_user_exempted() { return $this->exempt; }
	};

	$_COOKIE = array( 'fazVendorSource' => 'pmp', 'fazVendorConsent' => 'd:4,c:*', 'fazVendorLI' => 'p2:no,v:g' );
	$pmp_double->sync_consent_cookie();
	$checks['membership ended: the Right-to-Object record is cleared with the vendor record'] = ! isset( $_COOKIE['fazVendorLI'] ) && ! isset( $_COOKIE['fazVendorConsent'] );

	$pmp_double->exempt = true;
	$_COOKIE = array( 'fazcookie-consent' => $value, 'fazVendorLI' => 'p2:no,v:g' );
	$GLOBALS['faz_pmp_expired'] = array();
	$pmp_double->sync_consent_cookie();
	$checks['exempt member with the managed state already set: a leftover fazVendorLI alone triggers the refresh and is cleared'] = in_array( 'fazVendorLI', $GLOBALS['faz_pmp_expired'], true ) && ! isset( $_COOKIE['fazVendorLI'] );

	$failed = 0;
	foreach ( $checks as $label => $passed ) {
		if ( $passed ) {
			echo "  [PASS] {$label}\n";
		} else {
			$failed++;
			echo "  [FAIL] {$label}\n";
		}
	}

	if ( $failed ) {
		exit( 1 );
	}
	echo "PMP privacy alternative: " . count( $checks ) . " passed.\n";
}
