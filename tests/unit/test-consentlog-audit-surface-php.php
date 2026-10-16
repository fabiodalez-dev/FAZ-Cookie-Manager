<?php
/**
 * The evidence the consent log collects must reach the surfaces that produce it.
 *
 * Three defects in the proof-production path, pinned against the REAL
 * Controller and Api classes:
 *
 *  1. The CSV export (the path readme.txt advertises "for DPIA / audits") wrote
 *     ten fixed columns and dropped the seven Migration_V2 added — country,
 *     region, ruleset, GPC/DNT received, TC string, GPP string — so a TCF site
 *     asked to evidence a visitor's TC String could not do it from the export,
 *     and the REST schema did not declare them either.
 *  2. created_at is site-local wall-clock time with no offset (the WordPress
 *     convention, shared with post_date), but the CSV labelled it bare
 *     "Created At", the REST schema promised RFC 3339 (`format: date-time`) for
 *     a `Y-m-d H:i:s` string, and the retention purge computed its cutoff in
 *     UTC while the stats computed theirs in site time. The export now carries
 *     an explicit UTC column next to the site-time one, REST adds
 *     `created_at_gmt` (RFC 3339) and describes `created_at` truthfully, and
 *     every cutoff is on the clock the column is on. The site-time column
 *     keeps its 1.34.1 name, 'Created At', so integrations that read the
 *     export by column name keep working; the UTC column beside it names the
 *     clock.
 *  3. The single-record route excluded '-', so the ids the Do-Not-Sell logger
 *     writes (dnsmpi-…, dnsmpi-rsc-…) answered 404 rest_no_route.
 *
 * Run: php tests/unit/test-consentlog-audit-surface-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	/** The WP_REST_Controller surface the Api touches. */
	abstract class Rest_Controller {
		public function add_additional_fields_schema( $schema ) { return $schema; }
		public function get_public_item_schema() { return $this->get_item_schema(); }
		public function get_context_param( $args = array() ) { return $args; }
		abstract public function get_item_schema();
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	date_default_timezone_set( 'UTC' );

	// ---------- WordPress doubles ----------
	function sanitize_text_field( $s ) { return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', strip_tags( (string) $s ) ) ); }
	function sanitize_title( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s ) ); }
	function absint( $n ) { return abs( (int) $n ); }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
	function esc_url_raw( $u ) { return trim( (string) $u ); }
	function wp_parse_url( $u ) { return parse_url( (string) $u ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
	function wp_salt( $scheme = 'auth' ) { return 'UNIT-TEST-SALT-' . $scheme; }
	function wp_generate_password( $len = 24, $special = false ) { return str_repeat( 'x', (int) $len ); }
	// A site at UTC+2 (Europe/Rome in summer): "now" in site time, and the
	// site-to-UTC conversion WordPress performs with the site's timezone.
	function current_time( $type, $gmt = 0 ) { return $gmt ? '2026-06-19 10:00:00' : '2026-06-19 12:00:00'; }
	function get_gmt_from_date( $string, $format = 'Y-m-d H:i:s' ) {
		$ts = strtotime( (string) $string );
		return false === $ts ? '' : gmdate( $format, $ts - 2 * HOUR_IN_SECONDS );
	}
	function faz_get_valid_consent_cookie() { return ''; }
	function get_option( $k, $d = false ) { return $GLOBALS['__faz_options'][ $k ] ?? $d; }
	function update_option( $k, $v ) { $GLOBALS['__faz_options'][ $k ] = $v; return true; }
	function did_action( $h ) { return 1; }
	function add_action( ...$a ) { return true; }
	function apply_filters( $hook, $value ) { return $value; }
	function faz_resolve_client_ip() { return '203.0.113.7'; }
	function __( $t, $d = 'default' ) { return (string) $t; } // phpcs:ignore
	class WP_REST_Response {
		public $data;
		public $headers = array();
		public function __construct( $data ) { $this->data = $data; }
		public function header( $k, $v ) { $this->headers[ $k ] = $v; }
		public function get_data() { return $this->data; }
	}
	function rest_ensure_response( $r ) { return $r instanceof WP_REST_Response ? $r : new WP_REST_Response( $r ); }
	$GLOBALS['faz_routes'] = array();
	function register_rest_route( $ns, $route, $args ) { $GLOBALS['faz_routes'][] = array( 'ns' => $ns, 'route' => $route, 'args' => $args ); return true; }

	class WP_REST_Server { const READABLE = 'GET'; }
	class WP_Error {
		public $code;
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; }
		public function get_error_code() { return $this->code; }
	}
	class WP_REST_Request {
		private $params;
		public function __construct( array $params = array() ) { $this->params = $params; }
		public function get_param( $key ) { return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null; }
	}

	$GLOBALS['__faz_options'] = array( 'faz_consent_logs_db_version' => '9.9' );

	/** $wpdb double: a row store the export, lookup and retention paths read. */
	class FazTest_SurfaceWPDB {
		public $prefix     = 'wp_';
		public $insert_id  = 0;
		public $last_error = '';
		public $rows       = array();
		public $queries    = array();
		public function get_charset_collate() { return ''; }
		public function prepare( $q, ...$a ) {
			if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; }
			foreach ( $a as $v ) {
				$q = preg_replace( '/%d/', (string) (int) $v, $q, 1 );
				$q = preg_replace( '/%s/', "'" . addslashes( (string) $v ) . "'", $q, 1 );
			}
			return $q;
		}
		public function esc_like( $s ) { return $s; }
		public function get_row( $q, $o = null ) {
			$this->queries[] = $q;
			$best = null;
			foreach ( $this->rows as $row ) {
				if ( false !== strpos( $q, "'" . addslashes( $row['consent_id'] ) . "'" ) ) { $best = $row; }
			}
			return $best;
		}
		public function get_results( $q, $o = null ) {
			$this->queries[] = $q;
			if ( false !== strpos( $q, 'SELECT *' ) && false !== strpos( $q, 'ORDER BY log_id DESC' ) ) {
				$out = array_values( $this->rows );
				usort( $out, function ( $a, $b ) { return (int) $b['log_id'] <=> (int) $a['log_id']; } );
				return $out;
			}
			if ( false !== strpos( $q, 'SELECT *' ) ) {
				return array_values( $this->rows );
			}
			return array();
		}
		public function get_col( $q ) { $this->queries[] = $q; return array(); }
		public function get_var( $q ) { $this->queries[] = $q; return 0; }
		public function query( $q ) { $this->queries[] = $q; return 0; }
	}
	if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
	if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
	$GLOBALS['wpdb'] = new FazTest_SurfaceWPDB();

	require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-gpc-exception-audit.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/includes/class-controller.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/consentlogs/api/class-api.php';

	use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;
	use FazCookie\Admin\Modules\Consentlogs\Api\Api;

	$run = 0; $pass = 0; $fail = 0;
	function sf_ok( $cond, $label ) {
		global $run, $pass, $fail; $run++;
		if ( $cond ) { $pass++; echo "  \033[32m✓\033[0m $label\n"; }
		else { $fail++; echo "  \033[31m✗\033[0m $label\n"; }
	}
	function sf_eq( $a, $b, $label ) {
		global $run, $pass, $fail; $run++;
		if ( $a === $b ) { $pass++; echo "  \033[32m✓\033[0m $label\n"; }
		else {
			$fail++; echo "  \033[31m✗\033[0m $label\n";
			echo '      expected: ' . var_export( $b, true ) . "\n";
			echo '      actual:   ' . var_export( $a, true ) . "\n";
		}
	}

	$ctrl = ( new ReflectionClass( Controller::class ) )->newInstanceWithoutConstructor();
	$api  = new Api();

	echo "\n== consent-log audit surface: CSV, REST schema, single-record route ==\n\n";

	// ---------- 1. CSV carries the seven v2 columns ----------
	echo "-- CSV export columns --\n";
	$GLOBALS['wpdb']->rows = array(
		// A TCF-enabled, geo-routed row with every v2 column populated.
		2 => array(
			'log_id' => 2, 'consent_id' => 'cid-tcf', 'status' => 'accepted', 'categories' => '{"analytics":"yes"}',
			'ip_hash' => 'h2', 'user_agent' => 'ua2', 'url' => 'https://example.test/', 'banner_slug' => 'main', 'policy_revision' => 3,
			'created_at' => '2026-06-19 12:00:00',
			'country_at_consent' => 'DE', 'region_at_consent' => 'DE-BY', 'ruleset_id_at_consent' => 'gdpr-strict',
			'signal_gpc_received' => 1, 'signal_dnt_received' => 0,
			'tc_string' => 'CPyyyAAA.AAAAxxxxx_BB.fakeAA-9999.YY', 'gpp_string' => 'DBABLA~CPyyyAAA~~~',
		),
		// A row from before Migration_V2 (or an install where it is pending):
		// the v2 keys are absent entirely, not NULL.
		1 => array(
			'log_id' => 1, 'consent_id' => 'cid-legacy', 'status' => 'rejected', 'categories' => '{}',
			'ip_hash' => 'h1', 'user_agent' => 'ua1', 'url' => 'https://example.test/old', 'banner_slug' => '', 'policy_revision' => 1,
			'created_at' => '2026-01-10 01:30:00',
		),
	);
	$stream = fopen( 'php://temp', 'r+' );
	sf_eq( $ctrl->stream_csv( $stream ), true, 'stream_csv() succeeds' );
	rewind( $stream );
	$header = fgetcsv( $stream, 0, ',', '"', '\\' );
	$row_tcf = fgetcsv( $stream, 0, ',', '"', '\\' );
	$row_old = fgetcsv( $stream, 0, ',', '"', '\\' );
	fclose( $stream );

	$expected_header = array(
		'Log ID', 'Consent ID', 'Status', 'Categories', 'IP Hash', 'User Agent Hash', 'URL', 'Banner Slug', 'Policy Revision',
		'Created At', 'Created At (UTC)',
		'Country', 'Region', 'Ruleset', 'GPC Received', 'DNT Received', 'TC String', 'GPP String',
	);
	sf_eq( $header, $expected_header, 'the header names the seven jurisdiction/signal/TCF/GPP columns and both clocks' );
	// The 1.34.1 header, verbatim. An integration that reads the export by
	// column name or by position must find every one of these where it was;
	// new columns may only follow them.
	$shipped_1341 = array( 'Log ID', 'Consent ID', 'Status', 'Categories', 'IP Hash', 'User Agent Hash', 'URL', 'Banner Slug', 'Policy Revision', 'Created At' );
	sf_eq( array_slice( (array) $header, 0, count( $shipped_1341 ) ), $shipped_1341, 'the first ten columns are the 1.34.1 header, names and order unchanged' );
	$col = array_flip( $expected_header );
	sf_eq( $row_tcf[ $col['Consent ID'] ] ?? null, 'cid-tcf', 'newest row first' );
	sf_eq( $row_tcf[ $col['Country'] ] ?? null, 'DE', 'country_at_consent is exported' );
	sf_eq( $row_tcf[ $col['Region'] ] ?? null, 'DE-BY', 'region_at_consent is exported' );
	sf_eq( $row_tcf[ $col['Ruleset'] ] ?? null, 'gdpr-strict', 'ruleset_id_at_consent is exported' );
	sf_eq( $row_tcf[ $col['GPC Received'] ] ?? null, '1', 'signal_gpc_received is exported' );
	sf_eq( $row_tcf[ $col['DNT Received'] ] ?? null, '0', 'signal_dnt_received is exported' );
	sf_eq( $row_tcf[ $col['TC String'] ] ?? null, 'CPyyyAAA.AAAAxxxxx_BB.fakeAA-9999.YY', 'the TC String the visitor consented to is exported' );
	sf_eq( $row_tcf[ $col['GPP String'] ] ?? null, 'DBABLA~CPyyyAAA~~~', 'the GPP string is exported' );
	sf_eq( count( $row_old ), count( $expected_header ), 'a pre-migration row still has every column' );
	foreach ( array( 'Country', 'Region', 'Ruleset', 'GPC Received', 'DNT Received', 'TC String', 'GPP String' ) as $name ) {
		sf_eq( $row_old[ $col[ $name ] ] ?? null, '', "…with an empty {$name} cell rather than a shifted column" );
	}

	// ---------- 2. Timestamps: site time under its 1.34.1 name, UTC alongside ----------
	echo "-- timestamps --\n";
	sf_eq( $row_tcf[ $col['Created At'] ] ?? null, '2026-06-19 12:00:00', 'the stored site-time value is exported as such' );
	sf_eq( $row_tcf[ $col['Created At (UTC)'] ] ?? null, '2026-06-19T10:00:00Z', 'and next to it the same instant in UTC, RFC 3339, so a server-log or vendor correlation needs no guess at the offset' );
	sf_eq( $row_old[ $col['Created At (UTC)'] ] ?? null, '2026-01-09T23:30:00Z', 'a conversion that crosses midnight moves the date as well' );
	sf_eq( Controller::created_at_gmt( '' ), '', 'an empty created_at yields an empty UTC cell, not a 1970 date' );
	sf_eq( Controller::created_at_gmt( 'not a date' ), '', 'and so does an unparseable one' );

	// REST: items carry created_at_gmt; the schema says what each field is.
	$list  = $api->get_items( new WP_REST_Request( array( 'per_page' => 10 ) ) );
	$items = $list instanceof WP_REST_Response ? $list->get_data() : null;
	sf_ok( is_array( $items ) && ! empty( $items ), 'get_items() returns the rows' );
	$first = is_array( $items ) && isset( $items[0] ) ? $items[0] : array();
	sf_eq( $first['created_at'] ?? null, '2026-06-19 12:00:00', 'created_at stays the stored site-time string (what the admin table and existing integrations read)' );
	sf_eq( $first['created_at_gmt'] ?? null, '2026-06-19T10:00:00Z', 'created_at_gmt is added: the same instant as RFC 3339 UTC' );
	sf_ok( array_key_exists( 'X-WP-Total', $list->headers ) && array_key_exists( 'X-WP-TotalPages', $list->headers ), 'the pagination headers are still sent' );
	$one    = $api->get_item( new WP_REST_Request( array( 'consent_id' => 'cid-tcf' ) ) );
	$single = $one instanceof WP_REST_Response ? $one->get_data() : array();
	sf_eq( $single['created_at_gmt'] ?? null, '2026-06-19T10:00:00Z', 'the single-record route carries it too' );

	$schema = $api->get_item_schema();
	$props  = $schema['properties'] ?? array();
	sf_ok( ! isset( $props['created_at']['format'] ), 'created_at no longer claims format=date-time for a value that is neither RFC 3339 nor offset-bearing' );
	sf_ok( isset( $props['created_at']['description'] ) && false !== stripos( $props['created_at']['description'], 'site' ), 'created_at is described as site time' );
	sf_eq( $props['created_at_gmt']['format'] ?? null, 'date-time', 'created_at_gmt is the field that is RFC 3339' );

	// ---------- 3. Schema declares the v2 columns ----------
	echo "-- REST schema --\n";
	foreach ( array( 'country_at_consent', 'region_at_consent', 'ruleset_id_at_consent', 'tc_string', 'gpp_string' ) as $name ) {
		sf_eq( $props[ $name ]['type'] ?? null, 'string', "schema declares {$name} as a string" );
	}
	foreach ( array( 'signal_gpc_received', 'signal_dnt_received' ) as $name ) {
		sf_eq( $props[ $name ]['type'] ?? null, 'integer', "schema declares {$name} as an integer flag" );
	}

	// ---------- 4. Retention cutoff on the same clock as the column ----------
	echo "-- retention cutoff --\n";
	$GLOBALS['wpdb']->queries = array();
	$ctrl->cleanup_old_logs( 12 );
	$retention_select = '';
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( $q, 'SELECT log_id FROM' ) ) { $retention_select = $q; break; }
	}
	sf_ok( '' !== $retention_select, 'the purge reads the expired ids' );
	sf_ok( false !== strpos( $retention_select, "created_at < '2025-06-19 12:00:00'" ), 'its cutoff is twelve months before NOW IN SITE TIME — the clock created_at is on — not UTC' );

	// ---------- 5. Single-record route accepts every id the plugin writes ----------
	echo "-- single-record route --\n";
	$api->register_routes();
	$single_route = '';
	foreach ( $GLOBALS['faz_routes'] as $r ) {
		if ( false !== strpos( $r['route'], '(?P<consent_id>' ) ) { $single_route = $r['route']; }
	}
	sf_ok( '' !== $single_route, 'the single-record route is registered' );
	// WordPress anchors every route as '@^' . $route . '$@i' (class-wp-rest-server.php).
	$matches = function ( $path ) use ( $single_route ) {
		return 1 === preg_match( '@^' . $single_route . '$@i', $path );
	};
	sf_ok( $matches( '/consent_logs/dnsmpi-3f2a9c0011223344' ), 'a Do-Not-Sell opt-out id (dnsmpi-<hex>) is routable' );
	sf_ok( $matches( '/consent_logs/dnsmpi-rsc-3f2a9c0011223344' ), 'a Do-Not-Sell rescission id (dnsmpi-rsc-<hex>) is routable' );
	sf_ok( $matches( '/consent_logs/a0cwUGdtRzFHbllmRVJJMmx5clIwS3AwT0g5QXhtTFY=' ), 'a base64 server-generated id is still routable' );
	sf_ok( $matches( '/consent_logs/Xk3mP9qR2sT7vW1yZ4aB6cD8eF0gH5jK' ), 'a 32-char alphanumeric browser id is still routable' );
	sf_ok( ! $matches( '/consent_logs/' ), 'an empty id does not match' );
	sf_ok( ! $matches( '/consent_logs/a b' ), 'whitespace does not match' );
	sf_ok( ! $matches( "/consent_logs/a\nb" ), 'a newline does not match' );

	echo "\n--\nTests:  $run\nPassed: $pass\nFailed: $fail\n\n";
	if ( $fail > 0 ) { echo "\033[31mFAIL\033[0m\n"; exit( 1 ); }
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
