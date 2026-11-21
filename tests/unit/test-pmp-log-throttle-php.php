<?php
/**
 * The Paid Memberships Pro necessary-only state is logged once per member and
 * state per day, not once per cookie-less request.
 *
 * sync_consent_cookie() treats a request without the consent cookie as a new
 * privacy state and writes a consent-log row. A member whose client never
 * stores the cookie added one row per request. Pinned against the shipped
 * integration with doubles for the settings, the consent-log controller and
 * the transient store:
 *   - repeated cookie-less requests from one member write one row;
 *   - another member still gets their own row;
 *   - a changed state (new policy revision) is still logged;
 *   - once the window expires the state is logged again.
 *
 * Run: php tests/unit/test-pmp-log-throttle-php.php
 */
namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Category_Controller {
		public static function get_instance() { return new self(); }
		public function get_items() {
			return array(
				array( 'slug' => 'necessary', 'visibility' => 1, 'prior_consent' => 1 ),
				array( 'slug' => 'analytics', 'visibility' => 1, 'prior_consent' => 0 ),
			);
		}
	}
	class Cookie_Categories {
		private $d;
		public function __construct( $d ) { $this->d = $d; }
		public function get_visibility() { return ! empty( $this->d['visibility'] ); }
		public function get_slug() { return $this->d['slug']; }
		public function get_prior_consent() { return ! empty( $this->d['prior_consent'] ); }
	}
}

namespace FazCookie\Admin\Modules\Settings\Includes {
	class Settings {
		public function get( $group = '', $key = '' ) {
			if ( 'consent_logs' === $group && 'status' === $key ) { return true; }
			if ( 'general' === $group && 'consent_revision' === $key ) { return $GLOBALS['rev']; }
			return array();
		}
	}
}

namespace FazCookie\Admin\Modules\Consentlogs\Includes {
	class Controller {
		public static function get_instance() { return new self(); }
		public function log_consent( $row ) { $GLOBALS['rows'][] = $row; }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	$GLOBALS['rows'] = array(); $GLOBALS['transients'] = array(); $GLOBALS['uid'] = 7; $GLOBALS['rev'] = 3;
	function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
	function absint( $v ) { return abs( (int) $v ); }
	function faz_parse_consent_cookie( $c ) {
		$o = array();
		foreach ( explode( ',', (string) $c ) as $p ) { $kv = explode( ':', $p, 2 ); if ( 2 === count( $kv ) ) { $o[ $kv[0] ] = $kv[1]; } }
		return $o;
	}
	function faz_get_valid_consent_cookie() { return ''; } // the client never keeps the cookie
	function faz_is_pmp_managed_consent_cookie( $c ) { return false; }
	function faz_expire_browser_cookie( $n ) {}
	function faz_set_browser_cookie( $n, $v, $e = 0 ) {}
	function do_action() {}
	function do_action_deprecated() {}
	function get_current_user_id() { return $GLOBALS['uid']; }
	function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
	function set_transient( $k, $v, $ttl ) { $GLOBALS['transients'][ $k ] = $v; return true; }

	require dirname( __DIR__, 2 ) . '/includes/integrations/class-paid-memberships-pro.php';

	$pmp = new class() extends \FazCookie\Includes\Integrations\Paid_Memberships_Pro {
		public function __construct() {}
		public function is_current_user_exempted() { return true; }
	};

	$checks = 0; $failed = 0;
	function pl_check( $ok, $label ) { global $checks, $failed; ++$checks; if ( $ok ) { echo "  PASS {$label}\n"; return; } ++$failed; echo "  FAIL {$label}\n"; }

	for ( $i = 0; $i < 20; $i++ ) { $_COOKIE = array(); $pmp->sync_consent_cookie(); }
	pl_check( 1 === count( $GLOBALS['rows'] ), '20 cookie-less requests from one member write one row (' . count( $GLOBALS['rows'] ) . ')' );
	pl_check( isset( $GLOBALS['rows'][0]['categories']['meta.pmp_privacy'] ) && 'rejected' === $GLOBALS['rows'][0]['status'], 'and that row is the necessary-only record' );

	$GLOBALS['uid'] = 8;
	$_COOKIE = array(); $pmp->sync_consent_cookie();
	pl_check( 2 === count( $GLOBALS['rows'] ), 'another member gets their own row' );

	$GLOBALS['rev'] = 4;
	$_COOKIE = array(); $pmp->sync_consent_cookie();
	pl_check( 3 === count( $GLOBALS['rows'] ) && 4 === $GLOBALS['rows'][2]['policy_revision'], 'a new policy revision is a new state and is logged' );

	$GLOBALS['transients'] = array();
	$_COOKIE = array(); $pmp->sync_consent_cookie();
	pl_check( 4 === count( $GLOBALS['rows'] ), 'once the window has expired the state is logged again' );

	echo "\n{$checks} checks, {$failed} failed\n";
	echo 0 === $failed ? "ALL PASS\n" : "FAILURES\n";
	exit( 0 === $failed ? 0 : 1 );
}
