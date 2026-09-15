<?php
/**
 * Regression tests for the page-level embed inventory used by GPC auditing.
 *
 * Run: php tests/unit/test-embed-inventory-php.php
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}

	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
	function wp_unslash( $value ) { return $value; }
	function is_admin() { return false; }
	function is_ssl() { return false; }
	function faz_request_is_https() { return true; }
	function get_option( $key, $default = array() ) {
		return 'faz_settings' === $key ? array( 'consent_logs' => array( 'status' => true ) ) : $default;
	}
	function get_transient( $key ) { return false; }
	function set_transient( $key, $value, $ttl ) { return true; }
	function current_time( $type ) { return '2026-09-15 12:00:00'; }
	function esc_url_raw( $url ) { return (string) $url; }
	function wp_parse_url( $url ) { return parse_url( (string) $url ); }
	function absint( $value ) { return abs( (int) $value ); }
	function faz_parse_url( $url ) { return wp_parse_url( $url ); }

	class FazTest_InventoryWPDB {
		public $prefix = 'wp_';
		public $queries = array();
		public function prepare( $query, ...$args ) {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%s/', "'" . (string) $arg . "'", $query, 1 );
			}
			return $query;
		}
		public function query( $query ) { $this->queries[] = $query; return 1; }
		// Recorded like query() so a test can tell a refresh from a deletion:
		// the whole point of this table's write path is which of the two happens.
		public function update() { $this->queries[] = 'UPDATE ' . func_get_arg( 0 ); return 0; }
		public function insert() { $this->queries[] = 'INSERT ' . func_get_arg( 0 ); return 1; }
	}

	$GLOBALS['wpdb'] = new FazTest_InventoryWPDB();
	require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-embed-inventory.php';

	use FazCookie\Frontend\Includes\Embed_Inventory;

	$passed = 0;
	$failed = 0;
	function inventory_check( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) { ++$passed; echo "  [PASS] {$label}\n"; }
		else { ++$failed; echo "  [FAIL] {$label}\n"; }
	}

	$_SERVER['HTTP_HOST']   = 'example.test';
	$_SERVER['REQUEST_URI'] = '/page/?campaign=one';

	$current_url = new \ReflectionMethod( Embed_Inventory::class, 'current_url' );
	$current_url->setAccessible( true );
	inventory_check(
		'https://example.test/page' === $current_url->invoke( null ),
		'inventory uses the proxy-aware HTTPS signal and normalises the page URL'
	);

	// A render with no placeholder deletes NOTHING.
	//
	// It was written the other way first — an empty render treated as a snapshot
	// that clears the page — and that reads well until you ask why a page
	// rendered no placeholder. "The embed was removed" is one answer. "This
	// visitor already consented" is the far commoner one, and on that reading
	// the first visitor to accept wiped the page's rows, after which every
	// genuine exception on that page was recorded as unverified. Reproduced
	// against the running site before this was changed.
	Embed_Inventory::flush();
	inventory_check(
		array() === $GLOBALS['wpdb']->queries,
		'a render with no placeholder issues no query at all'
	);

	// Partial consent is the case that also breaks "delete only when something
	// was seen": with marketing consented and functional not, the visitor sees
	// a placeholder for one service and not the other, and treating that as the
	// page's full set would drop the consented service's row while it is still
	// on the page.
	$note = new \ReflectionMethod( Embed_Inventory::class, 'note' );
	$note->invoke( null, 'google-maps', 'functional' );
	Embed_Inventory::flush();
	$queries = $GLOBALS['wpdb']->queries;
	inventory_check(
		! empty( $queries ) && 0 === count( array_filter( $queries, static function ( $q ) {
			return false !== stripos( $q, 'DELETE' );
		} ) ),
		'seeing one service of two refreshes it without deleting the other'
	);

	// Rows nobody refreshes are what expires, and on their own window — a row
	// that outlives the page it describes can only corroborate something untrue,
	// so it must not inherit the consent log's months-long retention.
	inventory_check(
		defined( 'FazCookie\\Frontend\\Includes\\Embed_Inventory::STALE_AFTER_DAYS' )
			&& Embed_Inventory::STALE_AFTER_DAYS > 0
			&& Embed_Inventory::STALE_AFTER_DAYS <= 30,
		'stale rows expire on a short window of their own (' . Embed_Inventory::STALE_AFTER_DAYS . ' days)'
	);

	$source = file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
	inventory_check(
		false !== strpos( $source, "array( Embed_Inventory::class, 'begin' )" ),
		'the frontend starts inventory observation even when a page has no embeds'
	);

	echo "\nPassed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
