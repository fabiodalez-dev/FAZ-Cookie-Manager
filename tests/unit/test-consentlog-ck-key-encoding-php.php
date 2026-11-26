<?php
/**
 * Per-cookie decisions on unusual cookie names stay in the consent log.
 *
 * A consent row is the record of what the visitor decided. A per-cookie
 * refusal (ck.<service>.<cookie>:no) is the part of it that says "the service
 * is accepted, but not this cookie", so a row that loses it shows MORE consent
 * than was given: svc.<id>:yes with nothing overriding it.
 *
 * log_consent() used to drop every key outside printable ASCII. The browser
 * only escapes %, : and , in a cookie name (_fazCkKey() in script.js), so a
 * cookie an administrator declared with a space, an accented letter, a quote
 * or a backslash in its name lost its decision. And sanitize_text_field()
 * removed the %3A / %2C / %25 escapes the browser does write, folding
 * "a:b" onto "ab".
 *
 * The controller now stores such a key percent-encoded instead of dropping it.
 * Drives the real log_consent() against a $wpdb double.
 *
 * Run: php tests/unit/test-consentlog-ck-key-encoding-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	// The REST endpoint is loaded only to exercise its private buffer-drain
	// helper. Its WordPress parent is irrelevant to that isolated behaviour.
	abstract class Rest_Controller {}
}

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
		// Faithful to WordPress where it matters here: _sanitize_text_fields()
		// strips tags, collapses whitespace AND removes every percent-encoded
		// octet. The lighter stub the sibling suite uses keeps octets, which
		// hides what the real function does to an escaped key.
		function sanitize_text_field( $s ) {
			$s = (string) $s;
			$s = preg_replace( '/<[^>]*>/', '', $s );
			$s = preg_replace( '/[\r\n\t ]+/', ' ', $s );
			$s = trim( $s );
			while ( preg_match( '/%[a-f0-9]{2}/i', $s, $m ) ) {
				$s = str_replace( $m[0], '', $s );
			}
			return trim( preg_replace( '/ +/', ' ', $s ) );
		}
	}
	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s ) ); }
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $n ) { return abs( (int) $n ); }
	}
	if ( ! function_exists( 'wp_parse_args' ) ) {
		function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
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
		function current_time( $type, $gmt = 0 ) { return $gmt ? '2026-06-19 10:00:00' : '2026-06-19 12:00:00'; }
	}
	if ( ! function_exists( 'faz_get_valid_consent_cookie' ) ) {
		// The carry gate (I-1) consults the cookie the request carried; these
		// tests drive it through a global to say what the runtime would send.
		function faz_get_valid_consent_cookie() { return (string) ( $GLOBALS['faz_test_consent_cookie'] ?? '' ); }
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
		/** @var string[] Every get_row() query, for the previous-row plumbing cases. */
		public $row_queries = array();
		public function get_row( $q, $output = OBJECT ) {
			$this->row_queries[] = $q;
			// A `categories LIKE '%<needle>%'` filter is honoured, so the query
			// that asks for the newest row RECORDING an exception really skips
			// newer rows that do not.
			$needle = null;
			if ( preg_match( "/categories LIKE '%([^%']*)%'/", $q, $m ) ) {
				$needle = stripslashes( $m[1] );
			}
			// Return the most recent row whose consent_id appears in the query.
			$best = null;
			foreach ( $this->rows as $row ) {
				if ( false !== strpos( $q, "'" . addslashes( $row['consent_id'] ) . "'" ) ) {
					if ( null !== $needle && false === strpos( (string) ( $row['categories'] ?? '' ), $needle ) ) {
						continue;
					}
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

	// The real normaliser, so the URL-minimisation assertions below run through
	// the function production uses rather than the controller's fallback.
	require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-gpc-exception-audit.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/includes/class-controller.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/api/class-api.php';

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

	echo "\n== consent log — per-cookie keys on unusual cookie names ==\n\n";

	$ctrl = faz_controller();
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (UnitTest) Gecko';

	/** Log one decision map and return what the row stores. */
	function logged_map( $ctrl, $id, array $categories ) {
		$ctrl->log_consent( array( 'consent_id' => $id, 'status' => 'partial', 'categories' => $categories ) );
		$row = $ctrl->get_log_by_consent_id( $id );
		return is_array( $row ) && is_array( $row['categories'] ) ? $row['categories'] : array();
	}

	// The scenario of the finding: the service is accepted, one cookie refused.
	echo "-- the refusal survives --\n";
	$m = logged_map( $ctrl, 'cid-space', array(
		'analytics'         => 'yes',
		'svc.acme'          => 'yes',
		'ck.acme.my cookie' => 'no',
	) );
	eq( $m['svc.acme'] ?? null, 'yes', 'the service decision is logged' );
	eq( $m['ck.acme.my%20cookie'] ?? null, 'no', 'a refusal of a cookie with a space in its name is logged, percent-encoded' );

	$m = logged_map( $ctrl, 'cid-utf8', array( 'svc.acme' => 'yes', "ck.acme.sessi\xC3\xB3n" => 'no' ) );
	eq( $m['ck.acme.sessi%C3%B3n'] ?? null, 'no', 'a non-ASCII cookie name is logged as its UTF-8 bytes, percent-encoded' );

	$m = logged_map( $ctrl, 'cid-quote', array( 'svc.acme' => 'yes', 'ck.acme.a"b\\c' => 'no' ) );
	eq( $m['ck.acme.a%22b%5Cc'] ?? null, 'no', 'a quote and a backslash are encoded, not a reason to drop the decision' );

	$m = logged_map( $ctrl, 'cid-tag', array( 'svc.acme' => 'yes', 'ck.acme.<b>id</b>' => 'no' ) );
	eq( $m['ck.acme.%3Cb%3Eid%3C/b%3E'] ?? null, 'no', 'angle brackets are encoded, so no tag is stored and the decision is kept' );

	$m = logged_map( $ctrl, 'cid-ctl', array( 'svc.acme' => 'yes', "ck.acme.a\nb\tc" => 'no' ) );
	eq( $m['ck.acme.a%0Ab%09c'] ?? null, 'no', 'control characters are encoded' );

	// What the browser already escapes must arrive intact.
	echo "\n-- the escapes _fazCkKey() writes are kept --\n";
	$m = logged_map( $ctrl, 'cid-legacy', array( 'svc.acme' => 'yes', 'ck.acme.a%3Ab%2Cc%25d' => 'no' ) );
	eq( $m['ck.acme.a%3Ab%2Cc%25d'] ?? null, 'no', '%3A / %2C / %25 written by the browser are stored as sent' );
	ok( ! isset( $m['ck.acme.abcd'] ), 'and are not stripped into a different cookie name' );

	$m = logged_map( $ctrl, 'cid-distinct', array(
		'svc.acme'       => 'no',
		'ck.acme.ab'     => 'yes',
		'ck.acme.a b'    => 'no',
		'ck.acme.a%3Ab'  => 'no',
	) );
	eq( $m['ck.acme.ab'] ?? null, 'yes', 'a plain name keeps its own decision' );
	eq( $m['ck.acme.a%20b'] ?? null, 'no', 'a name that differs by a space keeps a separate one' );
	eq( $m['ck.acme.a%3Ab'] ?? null, 'no', 'a name that differs by a colon keeps a separate one' );

	// Ordinary names are untouched: the catalogue's keys read as before.
	echo "\n-- ordinary keys are unchanged --\n";
	$plain = array(
		'necessary'                  => 'yes',
		'svc.google-analytics'       => 'no',
		'ck.google-analytics._ga'    => 'no',
		'ck.matomo._pk_ses.*'        => 'yes',
		'ck.wp.wp-settings-{user}'   => 'no',
		'ck.x.__Secure-3PSID[0]|a=b' => 'yes',
		'meta.signal_only'           => 'yes',
	);
	eq( logged_map( $ctrl, 'cid-plain', $plain ), $plain, 'printable-ASCII keys are stored byte for byte' );

	// The hardening the drop was there for still holds.
	echo "\n-- the stored map stays bounded and well-formed --\n";
	$m = logged_map( $ctrl, 'cid-other', array(
		'analytics'        => 'yes',
		"cat\xC3\xA9gorie" => 'yes',
		'svc.a b'          => 'yes',
		'svc.a"b'          => 'yes',
		"meta.\xC3\xA9"    => 'yes',
	) );
	eq( $m, array( 'analytics' => 'yes' ), 'only ck.* is encoded: a category, svc.* or meta.* key outside printable ASCII is still dropped' );

	$m    = logged_map( $ctrl, 'cid-long', array( 'ck.acme.' . str_repeat( "\xC3\xA9", 200 ) => 'no' ) );
	$keys = array_keys( $m );
	ok( 1 === count( $keys ) && strlen( $keys[0] ) <= 190, 'an encoded key is still capped at 190 bytes' );
	ok( 1 === count( $keys ) && 1 === preg_match( '/^ck\.acme\.(?:%[0-9A-F]{2})+~[0-9a-f]{16}$/', $keys[0] ), 'and the cap never leaves half an escape before the digest that ends a shortened key' );

	// Shortening must not merge two cookies. 31 x "é" + one letter is a 63-byte
	// name; encoded it is 187 bytes after the 8 of "ck.acme.", so the old cut at
	// 190 removed the only byte that told the two names apart and the second
	// decision overwrote the first — here a "yes" over a refusal.
	echo "\n-- two long names never share a key --\n";
	$long_a = 'ck.acme.' . str_repeat( "\xC3\xA9", 31 ) . 'A';
	$long_b = 'ck.acme.' . str_repeat( "\xC3\xA9", 31 ) . 'B';
	$m      = logged_map( $ctrl, 'cid-collide', array( 'svc.acme' => 'yes', $long_a => 'no', $long_b => 'yes' ) );
	unset( $m['svc.acme'] );
	$vals = array_values( $m );
	sort( $vals );
	eq( $vals, array( 'no', 'yes' ), 'a refusal and an acceptance of two long names that differ only at the end are both logged' );
	$keys  = array_keys( $m );
	$key_a = call_priv( $ctrl, 'encode_cookie_decision_key', array( $long_a ) );
	$key_b = call_priv( $ctrl, 'encode_cookie_decision_key', array( $long_b ) );
	ok( $key_a !== $key_b, 'the two names get different stored keys' );
	eq( $m[ $key_a ] ?? null, 'no', 'the refusal stays on the first name' );
	eq( $m[ $key_b ] ?? null, 'yes', 'the acceptance stays on the second' );
	$bounded = true;
	foreach ( $keys as $k ) {
		if ( strlen( (string) $k ) > 190 || ! preg_match( '/^[\x21\x23-\x3B\x3D\x3F-\x5B\x5D-\x7E]+$/', (string) $k ) ) { $bounded = false; }
	}
	ok( 2 === count( $keys ) && $bounded, 'both shortened keys stay within 190 bytes of the stored alphabet' );
	eq( call_priv( $ctrl, 'encode_cookie_decision_key', array( $long_a ) ), $key_a, 'the same name always shortens to the same key, so its rows stay comparable' );

	$fits = 'ck.acme.' . str_repeat( 'a', 182 );
	eq( call_priv( $ctrl, 'encode_cookie_decision_key', array( $fits ) ), $fits, 'a key of exactly 190 bytes is stored unchanged' );
	$over = call_priv( $ctrl, 'encode_cookie_decision_key', array( $fits . 'b' ) );
	ok( 190 === strlen( $over ) && 0 === strpos( $over, 'ck.acme.aaaa' ) && $over !== $fits, 'one byte more is shortened to 190, keeps its readable start and does not land on the 190-byte name' );

	$every = '';
	for ( $b = 0; $b < 256; $b++ ) { $every .= chr( $b ); }
	$m    = logged_map( $ctrl, 'cid-bytes', array( 'ck.acme.' . substr( $every, 0, 40 ) => 'no', 'ck.acme.' . substr( $every, 120, 30 ) => 'no' ) );
	$all  = true;
	foreach ( array_keys( $m ) as $k ) {
		if ( ! preg_match( '/^[\x21\x23-\x3B\x3D\x3F-\x5B\x5D-\x7E]+$/', (string) $k ) ) { $all = false; }
	}
	ok( 2 === count( $m ) && $all, 'whatever bytes arrive, a stored key is printable ASCII without space, quote, backslash or angle brackets' );
	ok( false !== json_encode( $m ), 'and the map still encodes as JSON' );

	echo "\n";
	echo "consent-log ck key encoding: {$pass} passed, {$fail} failed (of {$run})\n";
	exit( $fail > 0 ? 1 : 0 );
}
