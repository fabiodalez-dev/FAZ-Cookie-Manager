<?php
/**
 * Standalone unit tests for the age-affirmation accountability record
 * (youth / age-appropriate consent gate — GDPR Art. 8, Art. 5(2)/7(1)).
 *
 * Subsystem: consentlog-controller (age-gate accountability)
 *
 * Drives the REAL shipped class
 *   admin/modules/consentlogs/includes/class-controller.php
 * against a $wpdb double to prove the design's accountability contract:
 *
 *   1. The reserved meta.age_affirmed:yes key folded into the consent
 *      `categories` map survives the yes/no sanitiser and round-trips through
 *      log_consent() → categories JSON → get_log_by_consent_id() with NO new DB
 *      column (it rides the existing categories longtext).
 *   2. A crafted meta.age_affirmed value that is NOT yes/no (e.g. "evil") is
 *      dropped by the shipped sanitiser — a client-writable cookie can never
 *      push an arbitrary value into the audit row.
 *   3. get_consent_stats() does NOT count meta.* keys as consent categories, so
 *      the age flag never draws a phantom bar in the dashboard chart, while the
 *      real categories (analytics/marketing) are still counted.
 *
 * No browser, no real DB, no live WP.
 *
 * Run:  php tests/unit/test-age-affirmation-log-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	// ---------- WP function stubs (only what the controller touches) ----------

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $s ) {
			$s = (string) $s;
			$s = preg_replace( '/<[^>]*>/', '', $s );
			$s = preg_replace( '/[\r\n\t]+/', ' ', $s );
			$s = preg_replace( '/[\x00-\x1F\x7F]/', '', $s );
			return trim( preg_replace( '/\s{2,}/', ' ', $s ) );
		}
	}
	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s ) ); }
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $n ) { return abs( (int) $n ); }
	}
	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $u ) { return trim( (string) $u ); }
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $u ) { return parse_url( (string) $u ); }
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $v ) { return json_encode( $v ); }
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
	}
	if ( ! function_exists( 'wp_salt' ) ) {
		function wp_salt( $scheme = 'auth' ) { return 'UNIT-TEST-SALT-' . $scheme; }
	}
	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type ) { return '2026-07-21 12:00:00'; }
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $k, $d = false ) { return $GLOBALS['__faz_options'][ $k ] ?? $d; }
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $k, $v ) { $GLOBALS['__faz_options'][ $k ] = $v; return true; }
	}
	if ( ! function_exists( 'did_action' ) ) {
		function did_action( $h ) { return 1; }
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( ...$a ) { return true; }
	}
	if ( ! function_exists( 'faz_resolve_client_ip' ) ) {
		function faz_resolve_client_ip() { return $GLOBALS['__faz_client_ip'] ?? '203.0.113.7'; }
	}

	$GLOBALS['__faz_options']   = array( 'faz_consent_logs_db_version' => '1.1' );
	$GLOBALS['__faz_client_ip'] = '203.0.113.7';

	// ---------- $wpdb double: records inserts, replays them on read ----------

	class FazTest_AgeWPDB {
		public $prefix     = 'wp_';
		public $insert_id  = 0;
		public $last_error = '';
		public $rows       = array();
		private $auto      = 0;

		public function get_charset_collate() { return ''; }
		public function insert( $table, $data, $format = null ) {
			$this->auto++;
			$data['log_id']            = $this->auto;
			$data['created_at']        = $data['created_at'] ?? '2026-07-21 12:00:00';
			$this->rows[ $this->auto ] = $data;
			$this->insert_id           = $this->auto;
			return 1;
		}
		public function update( $table, $data, $where, $df = null, $wf = null ) {
			$id = (int) ( $where['log_id'] ?? 0 );
			if ( isset( $this->rows[ $id ] ) ) {
				$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
			}
			return 1;
		}
		public function prepare( $q, ...$a ) {
			if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; }
			foreach ( $a as $v ) {
				$q = preg_replace( '/%d/', (string) (int) $v, $q, 1 );
				$q = preg_replace( '/%s/', "'" . addslashes( (string) $v ) . "'", $q, 1 );
			}
			return $q;
		}
		public function esc_like( $s ) { return $s; }
		public function get_row( $q, $output = OBJECT ) {
			$best = null;
			foreach ( $this->rows as $row ) {
				if ( false !== strpos( $q, "'" . addslashes( $row['consent_id'] ) . "'" ) ) {
					$best = $row;
				}
			}
			return $best;
		}
		public function get_results( $q, $output = OBJECT ) {
			// get_consent_stats() per-category query.
			if ( false !== strpos( $q, 'SELECT categories FROM' ) ) {
				$out = array();
				foreach ( $this->rows as $row ) {
					if ( isset( $row['categories'] ) && '' !== $row['categories'] ) {
						$out[] = array( 'categories' => $row['categories'] );
					}
				}
				return $out;
			}
			return array();
		}
		public function get_var( $q ) { return 0; }
		public function query( $q ) { return 0; }
	}

	if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
	if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

	$GLOBALS['wpdb'] = new FazTest_AgeWPDB();

	require_once dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/includes/class-controller.php';

	use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;

	// ---------- assert helpers ----------

	$run = 0; $pass = 0; $fail = 0;
	function ok( $cond, $label ) {
		global $run, $pass, $fail; $run++;
		if ( $cond ) { $pass++; echo "  \033[32m✓\033[0m $label\n"; }
		else { $fail++; echo "  \033[31m✗\033[0m $label\n"; }
	}
	function eq( $a, $b, $label ) {
		global $run, $pass, $fail; $run++;
		if ( $a === $b ) { $pass++; echo "  \033[32m✓\033[0m $label\n"; }
		else {
			$fail++; echo "  \033[31m✗\033[0m $label\n";
			echo '      expected: ' . var_export( $b, true ) . "\n";
			echo '      actual:   ' . var_export( $a, true ) . "\n";
		}
	}

	function faz_age_controller() {
		$rc = new ReflectionClass( Controller::class );
		return $rc->newInstanceWithoutConstructor();
	}

	echo "\n== age-affirmation accountability — PRODUCTION path unit tests ==\n\n";

	$ctrl = faz_age_controller();
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (UnitTest) Gecko';

	// ============================================================
	// 1. meta.age_affirmed:yes round-trips through log_consent()
	// ============================================================
	echo "-- meta.age_affirmed round-trip (Art. 5(2)/7(1)) --\n";

	$ctrl->log_consent( array(
		'consent_id' => 'cid-age-yes',
		'status'     => 'accepted',
		'categories' => array(
			'necessary'         => 'yes',
			'analytics'         => 'yes',
			'marketing'         => 'yes',
			'meta.age_affirmed' => 'yes',
		),
		'url'         => 'https://example.com/',
		'banner_slug' => 'gdpr',
	) );

	$read = $ctrl->get_log_by_consent_id( 'cid-age-yes' );
	ok( is_array( $read ), 'get_log_by_consent_id() finds the affirmed row' );
	$cats = $read['categories'];
	ok( is_array( $cats ), 'categories column decodes back to an array (real JSON round-trip)' );
	eq( $cats['meta.age_affirmed'] ?? null, 'yes', 'meta.age_affirmed:yes survives the yes/no sanitiser & is persisted' );
	eq( $cats['analytics'] ?? null, 'yes', 'real category kept alongside the audit key' );
	ok( ! array_key_exists( 'age_affirmed_column', $read ), 'no new DB column — the flag rides the existing categories JSON' );

	// ============================================================
	// 2. A crafted non-yes/no meta value is dropped
	// ============================================================
	echo "\n-- crafted meta.age_affirmed value is rejected --\n";

	$ctrl->log_consent( array(
		'consent_id' => 'cid-age-evil',
		'status'     => 'accepted',
		'categories' => array(
			'necessary'         => 'yes',
			'meta.age_affirmed' => 'evil',   // not in {yes,no}
			'meta.injected'     => '<script>',
		),
		'url'         => 'https://example.com/',
		'banner_slug' => 'gdpr',
	) );

	$evil = $ctrl->get_log_by_consent_id( 'cid-age-evil' );
	$evilCats = is_array( $evil ) ? $evil['categories'] : array();
	ok( ! isset( $evilCats['meta.age_affirmed'] ), 'meta.age_affirmed:"evil" is dropped (value not yes/no)' );
	ok( ! isset( $evilCats['meta.injected'] ), 'meta.injected:"<script>" is dropped (value not yes/no)' );
	eq( $evilCats['necessary'] ?? null, 'yes', 'the legitimate necessary:yes key still persists' );

	// ============================================================
	// 3. get_consent_stats() excludes meta.* from the category chart
	// ============================================================
	echo "\n-- get_consent_stats() skips the reserved meta.* prefix --\n";

	$stats = $ctrl->get_consent_stats( 30 );
	ok( is_array( $stats ) && isset( $stats['categories'] ), 'get_consent_stats() returns a categories breakdown' );
	$catStats = $stats['categories'];
	ok( ! isset( $catStats['meta.age_affirmed'] ), 'meta.age_affirmed is NOT counted as a category (no phantom bar)' );
	ok( ! isset( $catStats['meta.injected'] ), 'no meta.* key leaks into the category chart' );
	ok( isset( $catStats['analytics'] ), 'real category analytics IS counted' );
	ok( isset( $catStats['necessary'] ), 'real category necessary IS counted' );
	// analytics appeared once with yes (the affirmed row); necessary appeared in
	// both rows — the counts must reflect the real categories only.
	eq( $catStats['analytics']['yes'] ?? null, 1, 'analytics yes-count reflects the one accepted row' );

	// ---------- summary ----------
	echo "\n";
	echo $fail === 0
		? "\033[32m$pass passed, $fail failed ($run assertions)\033[0m\n"
		: "\033[31m$pass passed, $fail failed ($run assertions)\033[0m\n";

	exit( $fail === 0 ? 0 : 1 );
}
