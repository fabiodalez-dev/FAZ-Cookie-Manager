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
	function wp_doing_cron() { return false; }
	function is_ssl() { return false; }
	function faz_request_is_https() { return true; }
	function faz_get_valid_consent_cookie() { return isset( $GLOBALS['faz_test_consent'] ) ? $GLOBALS['faz_test_consent'] : ''; }
	function get_option( $key, $default = array() ) {
		return 'faz_settings' === $key ? array( 'consent_logs' => array( 'status' => true ) ) : $default;
	}
	// Backed by an array rather than stubbed to false: the per-page guard is
	// part of what these cases check — a clear that happens before it, and a
	// return that happens after it, together lose the page's rows for a day.
	$GLOBALS['faz_test_transients'] = array();
	function get_transient( $key ) {
		return isset( $GLOBALS['faz_test_transients'][ $key ] ) ? $GLOBALS['faz_test_transients'][ $key ] : false;
	}
	function set_transient( $key, $value, $ttl ) {
		$GLOBALS['faz_test_transients'][ $key ] = $value;
		return true;
	}
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

	// A normal render with no placeholder deletes NOTHING.
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

	// A GPC request without prior consent is authoritative: consent cannot hide
	// a relevant placeholder, so an empty render means the old embed is gone.
	$_SERVER['HTTP_SEC_GPC'] = '1';
	Embed_Inventory::flush();
	inventory_check( array() === $GLOBALS['wpdb']->queries, 'GPC alone cannot replace a snapshot before a complete render' );
	Embed_Inventory::complete_render();
	Embed_Inventory::flush();
	inventory_check(
		1 === count( $GLOBALS['wpdb']->queries )
			&& false !== strpos( $GLOBALS['wpdb']->queries[0], 'DELETE FROM wp_faz_embed_placeholders' ),
		'a virgin GPC render replaces the historical page snapshot even when empty'
	);
	unset( $_SERVER['HTTP_SEC_GPC'] );
	$GLOBALS['wpdb']->queries = array();

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

	// The observed digest has the same no-write guard as an authoritative snapshot.
	$GLOBALS['wpdb']->queries = array();
	$note->invoke( null, 'google-maps', 'functional' );
	Embed_Inventory::flush();
	inventory_check( array() === $GLOBALS['wpdb']->queries, 'an identical non-GPC observation performs no database writes' );

	// A partial render may record observations but cannot erase prior history.
	$complete = new \ReflectionProperty( Embed_Inventory::class, 'render_complete' );
	$complete->setAccessible( true );
	$complete->setValue( null, false );
	$GLOBALS['wpdb']->queries = array();
	$GLOBALS['faz_test_transients'] = array();
	$_SERVER['HTTP_SEC_GPC'] = '1';
	$note->invoke( null, 'youtube', 'marketing' );
	Embed_Inventory::flush();
	$partial = $GLOBALS['wpdb']->queries;
	inventory_check(
		! empty( $partial ) && 0 === count( array_filter( $partial, static function ( $q ) { return false !== stripos( $q, 'DELETE' ); } ) ),
		'an interrupted GPC render records observed placeholders without clearing history'
	);
	Embed_Inventory::complete_render();
	unset( $_SERVER['HTTP_SEC_GPC'] );

	// The clear and the rewrite are one step, and the transient guard comes
	// first. Written the other way round — clear, then return because the digest
	// matched — the second visit from a GPC browser deleted the page's rows
	// without writing them back, and the transient held that empty state for a
	// day. Reproduced against the running site: three identical GPC requests
	// left 2 rows, then 0, then 0.
	$GLOBALS['wpdb']->queries = array();
	$GLOBALS['faz_test_transients'] = array();
	$_SERVER['HTTP_SEC_GPC'] = '1';
	$note = new \ReflectionMethod( Embed_Inventory::class, 'note' );
	$note->invoke( null, 'youtube', 'marketing' );
	Embed_Inventory::flush();
	$first = $GLOBALS['wpdb']->queries;

	$GLOBALS['wpdb']->queries = array();
	$note->invoke( null, 'youtube', 'marketing' );
	Embed_Inventory::flush();
	$second = $GLOBALS['wpdb']->queries;

	inventory_check(
		0 === count( array_filter( $second, static function ( $q ) {
			return false !== stripos( $q, 'DELETE' );
		} ) ),
		'a repeated identical GPC visit deletes nothing (it is short-circuited before the clear)'
	);
	unset( $_SERVER['HTTP_SEC_GPC'] );

	$source = file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
	$_SERVER['HTTP_SEC_GPC'] = '1';
	$GLOBALS['faz_test_transients'] = array();
	foreach ( array( array( 'youtube' ), array(), array( 'youtube' ), array() ) as $snapshot ) {
		$GLOBALS['wpdb']->queries = array();
		foreach ( $snapshot as $id ) { Embed_Inventory::note( $id, 'marketing' ); }
		Embed_Inventory::flush();
		inventory_check( ! empty( $GLOBALS['wpdb']->queries ), 'a changed snapshot is written even if this set was seen earlier' );
	}
	unset( $_SERVER['HTTP_SEC_GPC'] );
	inventory_check( faz_normalize_page_url( 'https://example.test/?p=123' ) !== faz_normalize_page_url( 'https://example.test/?p=456' ), 'plain permalink pages have distinct inventory keys' );
	inventory_check( faz_normalize_page_url( 'https://example.test/?p=123&utm_source=a' ) === faz_normalize_page_url( 'https://example.test/?p=123&utm_source=b' ), 'tracking parameters do not fragment a page' );
	inventory_check(
		false !== strpos( $source, "array( Embed_Inventory::class, 'begin' )" ),
		'the frontend starts inventory observation even when a page has no embeds'
	);

	echo "\nPassed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
