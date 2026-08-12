<?php
/**
 * Standalone unit tests for the PRODUCTION consent-log controller.
 *
 * Subsystem: consentlog-controller
 *
 * Unlike test-compliance-php.php (which replicates the sanitiser inline), this
 * suite loads and drives the REAL shipped class
 *   admin/modules/consentlogs/includes/class-controller.php
 * — the normal-behaviour persistence path every visitor consent hits on a
 * standard WordPress install — against a $wpdb double. It proves:
 *
 *   1. Per-service (svc.*) and per-cookie (ck.*) decisions round-trip through
 *      the real log_consent() → categories JSON → get_log_by_consent_id()
 *      (the P1-3 GDPR-accountability contract) on the production code, not a
 *      copy.
 *   2. The decision-map hardening is enforced by the SHIPPED code: yes/no-only
 *      values, 190-char key cap, 250-entry cap, nested values rejected.
 *   3. The DNSMPI scalar path stores '' (not '[]') in the categories column.
 *   4. Privacy: user_agent and IP are stored hashed (sha256 + salt), never raw.
 *   5. status is constrained to the known set (unknown → 'partial').
 *   6. URL minimisation drops query string + fragment + credentials.
 *   7. The 1.19.2 SQLite-portability migration (hash_legacy_user_agents) hashes
 *      legacy plaintext UAs in PHP with the SAME algorithm as hash_user_agent()
 *      and is idempotent (already-hashed 64-hex rows are skipped) — the behaviour
 *      that must hold identically on MySQL and SQLite-backed WordPress.
 *
 * No browser, no real DB, no live WP. The $wpdb double records inserts/updates
 * and replays them for the read-back, so the assertions exercise the genuine
 * control flow of the production controller.
 *
 * Run:  php tests/unit/test-consentlog-controller-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Consentlogs\Includes {
	// Nothing to predeclare — the real Controller lives in this namespace and is
	// loaded below; the braced block only fixes namespace context for clarity.
}

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
	if ( ! function_exists( 'wp_generate_password' ) ) {
		function wp_generate_password( $len = 24, $special = false ) { return str_repeat( 'x', (int) $len ); }
	}
	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type ) { return '2026-06-19 12:00:00'; }
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

	$GLOBALS['__faz_options']   = array( 'faz_consent_logs_db_version' => '1.1' ); // table already current → maybe_create_table no-ops.
	$GLOBALS['__faz_client_ip'] = '203.0.113.7';

	// ---------- $wpdb double: records inserts, replays them on read ----------

	class FazTest_ConsentWPDB {
		public $prefix     = 'wp_';
		public $insert_id  = 0;
		public $last_error = '';
		public $rows       = array(); // log_id => row
		private $auto      = 0;
		public $updates    = array(); // record of update() calls
		public $queries    = array(); // record export queries for pagination assertions

		public function get_charset_collate() { return ''; }
		public function insert( $table, $data, $format = null ) {
			$this->auto++;
			$data['log_id']      = $this->auto;
			$this->rows[ $this->auto ] = $data;
			$this->insert_id     = $this->auto;
			return 1;
		}
		public function update( $table, $data, $where, $df = null, $wf = null ) {
			$id = (int) ( $where['log_id'] ?? 0 );
			if ( isset( $this->rows[ $id ] ) ) {
				$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
			}
			$this->updates[] = array( 'where' => $where, 'data' => $data );
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
			// Return the most recent row whose consent_id appears in the query.
			$best = null;
			foreach ( $this->rows as $row ) {
				if ( false !== strpos( $q, "'" . addslashes( $row['consent_id'] ) . "'" ) ) {
					$best = $row;
				}
			}
			return $best; // ARRAY_A assumed by caller.
		}
		public function get_results( $q, $output = OBJECT ) {
			$this->queries[] = $q;
			// Used by the migration: return rows with log_id > cursor.
			if ( false !== strpos( $q, 'SELECT log_id, user_agent' ) ) {
				if ( preg_match( '/log_id > (\d+)/', $q, $m ) ) {
					$cursor = (int) $m[1];
					$out    = array();
					foreach ( $this->rows as $row ) {
						if ( (int) $row['log_id'] > $cursor ) {
							$out[] = array( 'log_id' => $row['log_id'], 'user_agent' => $row['user_agent'] );
						}
					}
					usort( $out, function ( $a, $b ) { return $a['log_id'] - $b['log_id']; } );
					return $out;
				}
			}
			// Used by export_csv(): emulate descending keyset pagination.
			if ( false !== strpos( $q, 'SELECT *' ) && false !== strpos( $q, 'ORDER BY log_id DESC' ) ) {
				$cursor = null;
				if ( preg_match( "/log_id < '?([0-9]+)'?/", $q, $m ) ) {
					$cursor = (int) $m[1];
				}
				$limit = preg_match( '/LIMIT (\d+)/', $q, $m ) ? (int) $m[1] : 1000;
				$out   = array_values( array_filter( $this->rows, function ( $row ) use ( $cursor ) {
					return null === $cursor || (int) $row['log_id'] < $cursor;
				} ) );
				usort( $out, function ( $a, $b ) { return (int) $b['log_id'] <=> (int) $a['log_id']; } );
				return array_slice( $out, 0, $limit );
			}
			return array();
		}
		public function get_var( $q ) { return 0; }
		public function query( $q ) { return 0; }
	}

	if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
	if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

	$GLOBALS['wpdb'] = new FazTest_ConsentWPDB();

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

	// Build the singleton without the plugins_loaded side effect, then reset.
	function faz_controller() {
		$rc = new ReflectionClass( Controller::class );
		$c  = $rc->newInstanceWithoutConstructor();
		return $c;
	}
	function call_priv( $obj, $method, array $args = array() ) {
		$m = new ReflectionMethod( Controller::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $obj, $args );
	}

	echo "\n== consentlog-controller — PRODUCTION path unit tests ==\n\n";

	$ctrl = faz_controller();

	// ============================================================
	// 1. svc.*/ck.* round-trip through the real log_consent()
	// ============================================================
	echo "-- per-service/per-cookie decision round-trip (P1-3) --\n";

	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (UnitTest) Gecko';

	$rec = $ctrl->log_consent( array(
		'consent_id' => 'cid-round-trip',
		'status'     => 'partial',
		'categories' => array(
			'necessary'            => 'yes',
			'analytics'            => 'yes',
			'marketing'            => 'no',
			'svc.google-analytics' => 'no',   // service denied INSIDE accepted analytics
			'svc.youtube'          => 'yes',   // service allowed inside denied marketing
			'ck.google-analytics._ga' => 'no',
		),
		'url'        => 'https://example.com/checkout?token=SECRET#frag',
		'banner_slug' => 'Main Banner',
	) );

	ok( is_array( $rec ) && ! empty( $rec['log_id'] ), 'log_consent() returns an inserted record with a log_id' );

	$read = $ctrl->get_log_by_consent_id( 'cid-round-trip' );
	ok( is_array( $read ), 'get_log_by_consent_id() finds the row' );
	$cats = $read['categories'];
	ok( is_array( $cats ), 'categories column decodes back to an array (real JSON round-trip)' );
	eq( $cats['svc.google-analytics'] ?? null, 'no', 'svc.google-analytics:no persisted & read back (denied service in accepted category)' );
	eq( $cats['svc.youtube'] ?? null, 'yes', 'svc.youtube:yes persisted & read back (allowed service in denied category)' );
	eq( $cats['ck.google-analytics._ga'] ?? null, 'no', 'per-cookie ck.* decision persisted & read back' );
	eq( $cats['analytics'] ?? null, 'yes', 'category-level summary kept alongside svc.*' );
	eq( $cats['marketing'] ?? null, 'no', 'category marketing:no kept' );

	// ============================================================
	// 2. Hardening enforced by the SHIPPED code (not a replica)
	// ============================================================
	echo "\n-- decision-map hardening (shipped sanitiser) --\n";

	$ctrl->log_consent( array(
		'consent_id' => 'cid-harden',
		'categories' => array(
			'analytics'   => 'yes',
			'svc.evil'    => 'maybe',                 // not yes/no → dropped
			'svc.inject'  => '<script>1</script>',    // sanitised → '1' → dropped
			'svc.nested'  => array( 'x' => 'yes' ),   // non-scalar → dropped (no fatal)
			''            => 'yes',                    // empty key → dropped
		),
	) );
	$h = $ctrl->get_log_by_consent_id( 'cid-harden' )['categories'];
	eq( $h, array( 'analytics' => 'yes' ), 'only valid yes/no scalar svc.* survive; maybe/markup/nested/empty dropped' );

	// 190-char key cap
	$ctrl->log_consent( array(
		'consent_id' => 'cid-longkey',
		'categories' => array( str_repeat( 'a', 250 ) => 'yes' ),
	) );
	$lk = $ctrl->get_log_by_consent_id( 'cid-longkey' )['categories'];
	$keys = array_keys( $lk );
	eq( strlen( $keys[0] ), 190, 'over-length decision key truncated to 190 by shipped code' );

	// 250-entry cap
	$big = array();
	for ( $i = 0; $i < 300; $i++ ) { $big[ 'k' . $i ] = 'yes'; }
	$ctrl->log_consent( array( 'consent_id' => 'cid-bigmap', 'categories' => $big ) );
	$bm = $ctrl->get_log_by_consent_id( 'cid-bigmap' )['categories'];
	eq( count( $bm ), 250, '300 decisions capped at 250 by shipped code' );

	// ============================================================
	// 3. DNSMPI scalar path stores '' (not '[]')
	// ============================================================
	echo "\n-- DNSMPI scalar categories path --\n";
	$ctrl->log_consent( array(
		'consent_id' => 'cid-dnsmpi',
		'status'     => 'dnsmpi_optout',
		'categories' => '', // scalar, not an array
	) );
	$dn = $GLOBALS['wpdb']->get_row( "consent_id = 'cid-dnsmpi'", ARRAY_A );
	eq( $dn['categories'], '', "scalar '' categories stored as '' (not '[]')" );
	eq( $dn['status'], 'dnsmpi_optout', 'internal dnsmpi_optout status preserved verbatim' );

	// ============================================================
	// 4. Privacy: UA + IP stored hashed, never raw
	// ============================================================
	echo "\n-- privacy hashing of UA + IP --\n";
	$row = $GLOBALS['wpdb']->get_row( "consent_id = 'cid-round-trip'", ARRAY_A );
	$expected_ua = hash( 'sha256', 'Mozilla/5.0 (UnitTest) Gecko' . wp_salt( 'auth' ) );
	$expected_ip = hash( 'sha256', '203.0.113.7' . wp_salt() );
	eq( $row['user_agent'], $expected_ua, 'user_agent stored as sha256(ua + auth-salt), not raw' );
	ok( false === strpos( $row['user_agent'], 'Mozilla' ), 'raw user-agent string never appears in the row' );
	eq( $row['ip_hash'], $expected_ip, 'IP stored as sha256(ip + salt)' );

	// ============================================================
	// 5. status constrained; unknown → partial
	// ============================================================
	echo "\n-- status allowlist --\n";
	$ctrl->log_consent( array( 'consent_id' => 'cid-badstatus', 'status' => 'hacked"; DROP', 'categories' => array() ) );
	$bs = $GLOBALS['wpdb']->get_row( "consent_id = 'cid-badstatus'", ARRAY_A );
	eq( $bs['status'], 'partial', 'unknown status folds to partial (dashboard stat integrity)' );

	// ============================================================
	// 6. URL minimisation (query + fragment + credentials dropped)
	// ============================================================
	echo "\n-- consent-log URL minimisation --\n";
	eq( call_priv( $ctrl, 'sanitize_log_url', array( 'https://u:p@example.com/a/b?x=1#f' ) ),
		'https://example.com/a/b', 'sanitize_log_url drops credentials, query and fragment' );
	eq( $row['url'], 'https://example.com/checkout', 'logged row URL minimised (token query stripped)' );

	// ============================================================
	// 7. SQLite-portable legacy-UA migration (1.19.2)
	// ============================================================
	echo "\n-- portable hash_legacy_user_agents migration --\n";
	// Seed a legacy plaintext UA row and an already-hashed row directly.
	$legacy_hash = hash( 'sha256', 'LegacyAgent/1.0' . wp_salt( 'auth' ) );
	$GLOBALS['wpdb']->rows[1001] = array( 'log_id' => 1001, 'consent_id' => 'legacy', 'user_agent' => 'LegacyAgent/1.0' );
	$GLOBALS['wpdb']->rows[1002] = array( 'log_id' => 1002, 'consent_id' => 'already', 'user_agent' => $legacy_hash );
	$GLOBALS['wpdb']->updates    = array();

	$migrated = call_priv( $ctrl, 'hash_legacy_user_agents' );
	ok( true === $migrated, 'migration returns true (success / nothing-left)' );
	eq( $GLOBALS['wpdb']->rows[1001]['user_agent'], $legacy_hash,
		'legacy plaintext UA hashed in PHP to the byte-identical sha256(ua+auth-salt)' );
	// Idempotent: the already-hashed 64-hex row was skipped (no update for log_id 1002).
	$touched_1002 = false;
	foreach ( $GLOBALS['wpdb']->updates as $u ) {
		if ( (int) ( $u['where']['log_id'] ?? 0 ) === 1002 ) { $touched_1002 = true; }
	}
	ok( ! $touched_1002, 'already-hashed (64-hex) row is skipped — migration is idempotent' );

	// ============================================================
	// 8. Bounded CSV export uses a cursor from the database
	// ============================================================
	echo "\n-- keyset-paginated CSV export --\n";
	$GLOBALS['wpdb']->rows    = array();
	$GLOBALS['wpdb']->queries = array();
	for ( $i = 0; $i < 1001; $i++ ) {
		$id = 3000000000 + $i; // Deliberately above a signed 32-bit PHP_INT_MAX.
		$GLOBALS['wpdb']->rows[ $id ] = array(
			'log_id'         => $id,
			'consent_id'     => 'export-' . $id,
			'status'         => 'accepted',
			'categories'     => '{}',
			'ip_hash'        => 'ip-hash',
			'user_agent'     => 'ua-hash',
			'url'            => 'https://example.test/path',
			'banner_slug'    => 'main',
			'policy_revision' => 1,
			'created_at'     => '2026-08-10 12:00:00',
		);
	}
	$csv   = $ctrl->export_csv();
	$lines = array_values( array_filter( preg_split( '/\r?\n/', trim( $csv ) ), 'strlen' ) );
	eq( count( $lines ), 1002, 'export emits one header plus all 1001 rows across two batches' );
	ok( false !== strpos( $lines[1], '3000001000' ), 'export remains newest-first for BIGINT ids above 32-bit range' );
	ok( false !== strpos( end( $lines ), '3000000000' ), 'second keyset batch reaches the oldest row exactly once' );
	$export_queries = array_values( array_filter( $GLOBALS['wpdb']->queries, function ( $query ) {
		return false !== strpos( $query, 'SELECT *' );
	} ) );
	ok( false === strpos( $export_queries[0], 'log_id <' ), 'first batch has no architecture-sized synthetic cursor' );
	ok(
		false !== strpos( $export_queries[1], "log_id < '3000000001'" ),
		'next batch binds the last database BIGINT as a platform-safe decimal string'
	);

	// The streamed path must produce byte-identical output to the buffered one.
	// It exists because the HTTP download used to build the whole export in
	// memory and only then echo it — so peak memory held the complete file, on
	// the plugin's highest-volume table, for the site with the most rows. That is
	// the administrator who needs the export and the one it failed for.
	$stream = fopen( 'php://temp', 'r+' );
	$ok     = $ctrl->stream_csv( $stream );
	rewind( $stream );
	$streamed = stream_get_contents( $stream );
	fclose( $stream );
	eq( $ok, true, 'stream_csv reports success on a usable stream' );
	eq( $streamed, $csv, 'the streamed export is byte-identical to the buffered one' );
	eq( false === strpos( $streamed, 'Log ID' ) ? 0 : 1, 1, 'and still carries the header row' );

	// A caller that hands over something unusable gets false rather than a
	// warning and a half-written response.
	eq( $ctrl->stream_csv( null ), false, 'a non-resource is refused' );
	eq( $ctrl->stream_csv( 'not a stream' ), false, 'and so is a string' );

	// BIGINT ids must never travel through a platform integer. On 32-bit PHP
	// absint() and %d both truncate at 2147483647, so a larger id is rewritten
	// to that value — and if a row with THAT id exists, the DELETE removes
	// somebody else's consent record. The column is BIGINT for exactly this
	// reason, so the fix is to keep the ids as decimal strings end to end.
	$ctrl_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/includes/class-controller.php' );
	eq( false === strpos( $ctrl_src, "array_map( 'absint', (array) \$ids )" ), true, 'retention ids are not passed through absint()' );
	eq( false !== strpos( $ctrl_src, "ctype_digit" ), true, 'they are validated as decimal digit strings instead' );
	eq( false === strpos( $ctrl_src, "array_fill( 0, count( \$ids ), '%d' )" ), true, 'and bound with %s, not %d' );

	// A failed export query returns an empty array exactly like a finished one.
	// Conflating them handed back a CSV that LOOKS complete — the worst outcome
	// for a file exported to answer a data-subject request.
	eq( false !== strpos( $ctrl_src, 'consent-log export query failed' ), true, 'a failed export query is detected' );
	eq( false !== strpos( $ctrl_src, "\$complete = \$this->write_csv" ), true, 'and its failure is propagated to export_csv()' );

	// A cap checked only in the loop condition is exceeded on every run where it
	// applies, because the last SELECT still reads a whole batch.
	eq( false !== strpos( $ctrl_src, 'min( $batch_size, max( 0, $max_rows - $deleted ) )' ), true, 'the per-run cap bounds the SELECT, not just the loop' );

	// The REST download must use the streaming entry point, not the string one.
	// Behaviourally indistinguishable from a unit test — both produce the same
	// bytes — so the call site is asserted directly; using export_csv() there
	// would restore the original memory profile while every test above passed.
	$api_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/api/class-api.php' );
	eq( false !== strpos( $api_src, 'stream_csv( $handle, $args )' ), true, 'the REST export streams to php://output' );
	// Anchored on the header CALL, not the words: the first version of this
	// matched the comment that explains why the header is absent, so it failed
	// because of prose. An assertion a comment can satisfy is checking the wrong
	// thing in both directions.
	eq( false === strpos( $api_src, "header( 'Content-Length" ), true, 'and sends no Content-Length, which cannot be known before the last row' );
	eq( false !== strpos( $api_src, 'ob_end_clean' ), true, 'output buffers are discarded, or streaming would accumulate in one anyway' );

	// ---------- summary ----------
	echo "\n--\nTests:  $run\nPassed: $pass\nFailed: $fail\n\n";
	if ( $fail > 0 ) { echo "\033[31mFAIL\033[0m\n"; exit( 1 ); }
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
