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
	function current_time( $type ) { return $GLOBALS['inventory_now'] ?? '2026-09-15 12:00:00'; }
	function esc_url_raw( $url ) { return (string) $url; }
	function wp_parse_url( $url ) { return parse_url( (string) $url ); }
	function absint( $value ) { return abs( (int) $value ); }
	function faz_parse_url( $url ) { return wp_parse_url( $url ); }
	// A 404 and a search results page are ordinary pages for BLOCKING, but
	// every one of them has its own URL, so recording them lets anyone mint
	// rows by requesting URLs that do not exist. Controlled per case.
	function is_404() { return ! empty( $GLOBALS['faz_test_is_404'] ); }
	function is_search() { return ! empty( $GLOBALS['faz_test_is_search'] ); }

	class FazTest_InventoryWPDB {
		public $prefix = 'wp_';
		public $queries = array();
		/** Reads, kept apart so "no write happened" assertions stay exact. */
		public $reads = array();
		/** Arguments of every update()/insert(), for the key a row is stored under. */
		public $writes = array();
		/** What an existence probe answers: '1' = the page has rows, null = none. */
		public $rows_exist = '1';
		public function prepare( $query, ...$args ) {
			if ( 0 === strpos( $query, 'INSERT INTO' ) ) { $this->writes[] = $args; }
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%s/', "'" . (string) $arg . "'", $query, 1 );
			}
			return $query;
		}
		public function query( $query ) { $this->queries[] = $query; return 1; }
		public function get_var( $query ) {
			$this->reads[] = $query;
			if ( 0 === strpos( $query, 'SELECT category' ) ) {
				return 'marketing';
			}
			return $this->rows_exist;
		}
		// Recorded like query() so a test can tell a refresh from a deletion:
		// the whole point of this table's write path is which of the two happens.
		public function update() { $this->queries[] = 'UPDATE ' . func_get_arg( 0 ); $this->writes[] = func_get_args(); return 0; }
		public function insert() { $this->queries[] = 'INSERT ' . func_get_arg( 0 ); $this->writes[] = func_get_args(); return 1; }
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


	// ============================================================
	// Page identity: one key for every spelling of the page.
	// ============================================================
	// The inventory is written from the rendering request and read from the
	// consent-log request, and the two see the scheme differently behind a
	// TLS-terminating proxy. Keying on the scheme-bearing URL made a page
	// written as https:// invisible to a lookup spelled http://.
	$reset = static function () {
		$GLOBALS['wpdb']->queries    = array();
		$GLOBALS['wpdb']->reads      = array();
		$GLOBALS['wpdb']->writes     = array();
		$GLOBALS['wpdb']->rows_exist = '1';
		$GLOBALS['faz_test_transients'] = array();
		$GLOBALS['faz_test_is_404']     = false;
		$GLOBALS['faz_test_is_search']  = false;
		unset( $_SERVER['HTTP_SEC_GPC'], $GLOBALS['wp'] );
		$_SERVER['REQUEST_URI'] = '/page/?campaign=one';
		$memo = new \ReflectionClass( Embed_Inventory::class );
		if ( $memo->hasProperty( 'category_memo' ) ) {
			$prop = $memo->getProperty( 'category_memo' );
			$prop->setAccessible( true );
			$prop->setValue( null, array() );
		}
	};
	$schemeless = md5( 'example.test/page' );

	$reset();
	Embed_Inventory::note( 'youtube', 'marketing' );
	Embed_Inventory::flush();
	$stored_hash = isset( $GLOBALS['wpdb']->writes[0][0] ) ? $GLOBALS['wpdb']->writes[0][0] : '';
	inventory_check( $schemeless === $stored_hash, 'a row is stored under the scheme-less page key' );
	$inserted = end( $GLOBALS['wpdb']->writes );
	inventory_check(
		isset( $inserted[3] ) && 'https://example.test/page' === $inserted[3],
		'the url column keeps the full scheme-bearing URL for the administrator'
	);
	inventory_check(
		isset( $GLOBALS['faz_test_transients'][ 'faz_embinv_current_' . $schemeless ] ),
		'the per-page write guard uses the same key'
	);

	$reset();
	Embed_Inventory::page_category( 'http://example.test/page/', 'youtube' );
	inventory_check(
		isset( $GLOBALS['wpdb']->reads[0] ) && false !== strpos( $GLOBALS['wpdb']->reads[0], "'" . $schemeless . "'" ),
		'an http:// lookup reaches the row an https:// render wrote'
	);
	$GLOBALS['wpdb']->reads = array();
	Embed_Inventory::page_offered( 'http://example.test/page', 'youtube' );
	inventory_check(
		isset( $GLOBALS['wpdb']->reads[0] ) && false !== strpos( $GLOBALS['wpdb']->reads[0], "'" . $schemeless . "'" ),
		'page_offered() uses the same key'
	);

	// The audit asks the same question for every exception in a payload:
	// one query per (page, service) per request is enough.
	$reset();
	Embed_Inventory::page_category( 'https://example.test/page', 'youtube' );
	Embed_Inventory::page_category( 'http://example.test/page/', 'youtube' );
	inventory_check( 1 === count( $GLOBALS['wpdb']->reads ), 'page_category() answers a repeated lookup from a per-request memo' );

	$reset();
	$_SERVER['HTTP_SEC_GPC'] = '1';
	Embed_Inventory::flush();
	inventory_check(
		1 === count( array_filter( $GLOBALS['wpdb']->queries, static function ( $q ) { return false !== strpos( $q, 'DELETE' ); } ) )
			&& false !== strpos( implode( ' ', $GLOBALS['wpdb']->queries ), "'" . $schemeless . "'" ),
		'an authoritative clear targets the scheme-less key'
	);

	// ============================================================
	// Cardinality: URLs anyone can invent write nothing.
	// ============================================================
	$reset();
	$GLOBALS['faz_test_is_404'] = true;
	Embed_Inventory::note( 'youtube', 'marketing' );
	Embed_Inventory::flush();
	inventory_check( array() === $GLOBALS['wpdb']->queries, 'a 404 page records nothing' );

	$reset();
	$GLOBALS['faz_test_is_search'] = true;
	Embed_Inventory::note( 'youtube', 'marketing' );
	Embed_Inventory::flush();
	inventory_check( array() === $GLOBALS['wpdb']->queries, 'a search results page records nothing' );

	// ?lang=<junk> on a site with no language plugin renders the same page and
	// would otherwise mint a new key per value.
	$reset();
	$_SERVER['REQUEST_URI'] = '/page/?lang=zz-junk';
	$GLOBALS['wp']          = (object) array( 'query_vars' => array( 'pagename' => 'page' ) );
	Embed_Inventory::note( 'youtube', 'marketing' );
	Embed_Inventory::flush();
	inventory_check( array() === $GLOBALS['wpdb']->queries, 'a routing parameter WordPress did not parse records nothing' );

	$reset();
	$_SERVER['REQUEST_URI'] = '/page/?lang=it';
	$GLOBALS['wp']          = (object) array( 'query_vars' => array( 'pagename' => 'page', 'lang' => 'it' ) );
	Embed_Inventory::note( 'youtube', 'marketing' );
	Embed_Inventory::flush();
	inventory_check( ! empty( $GLOBALS['wpdb']->queries ), 'a routing parameter WordPress did parse is recorded as usual' );

	$reset();
	$_SERVER['REQUEST_URI'] = '/page/?lang=it';
	Embed_Inventory::note( 'youtube', 'marketing' );
	Embed_Inventory::flush();
	inventory_check( ! empty( $GLOBALS['wpdb']->queries ), 'without a WP object to consult, the parameter check does not block the write' );

	// ============================================================
	// An empty authoritative snapshot only clears a page that has rows.
	// ============================================================
	$reset();
	$GLOBALS['wpdb']->rows_exist = null;
	$_SERVER['HTTP_SEC_GPC']     = '1';
	Embed_Inventory::flush();
	inventory_check( array() === $GLOBALS['wpdb']->queries, 'an empty GPC snapshot of a page with no rows deletes nothing' );
	inventory_check( array() === $GLOBALS['faz_test_transients'], 'and arms no write guard for it' );
	inventory_check( 1 === count( $GLOBALS['wpdb']->reads ), 'one indexed existence probe decides it' );

	// ============================================================
	// Throttle convergence between the two visitor classes.
	// ============================================================
	// A snapshot already describes everything an observation of the same set
	// could say; alternating GPC and non-GPC visitors used to write on every
	// single visit because the two digests never matched.
	$reset();
	$_SERVER['HTTP_SEC_GPC'] = '1';
	Embed_Inventory::note( 'youtube', 'marketing' );
	Embed_Inventory::flush();
	unset( $_SERVER['HTTP_SEC_GPC'] );
	$GLOBALS['wpdb']->queries = array();
	Embed_Inventory::note( 'youtube', 'marketing' );
	Embed_Inventory::flush();
	inventory_check( array() === $GLOBALS['wpdb']->queries, 'a non-GPC observation of the set a snapshot recorded writes nothing' );

	// The reverse does not hold: an observation cannot vouch for absence.
	$reset();
	Embed_Inventory::note( 'youtube', 'marketing' );
	Embed_Inventory::flush();
	$GLOBALS['wpdb']->queries = array();
	$_SERVER['HTTP_SEC_GPC']  = '1';
	Embed_Inventory::note( 'youtube', 'marketing' );
	Embed_Inventory::flush();
	inventory_check(
		0 < count( array_filter( $GLOBALS['wpdb']->queries, static function ( $q ) { return false !== strpos( $q, 'DELETE' ); } ) ),
		'a GPC snapshot is still written after an observation of the same set'
	);
	$GLOBALS['wpdb']->queries = array();
	Embed_Inventory::note( 'youtube', 'marketing' );
	Embed_Inventory::flush();
	inventory_check( array() === $GLOBALS['wpdb']->queries, 'and a repeat of that snapshot is short-circuited' );
	$reset();

	// Alternating visitors, the traffic every real page gets: only the first
	// GPC flush writes; everything after it is short-circuited.
	$reset();
	$writes = array();
	foreach ( array( true, false, true, false ) as $gpc ) {
		if ( $gpc ) { $_SERVER['HTTP_SEC_GPC'] = '1'; } else { unset( $_SERVER['HTTP_SEC_GPC'] ); }
		$GLOBALS['wpdb']->queries = array();
		Embed_Inventory::note( 'youtube', 'marketing' );
		Embed_Inventory::flush();
		$writes[] = count( $GLOBALS['wpdb']->queries );
	}
	inventory_check( $writes[0] > 0 && 0 === $writes[1] + $writes[2] + $writes[3], 'alternating GPC and non-GPC visits of an unchanged page write once' );
	$reset();

	// The consent-log payload names its page with the same function.
	$page_url = new \ReflectionMethod( Embed_Inventory::class, 'current_url' );
	inventory_check( $page_url->isPublic(), 'current_url() is public so the page can bake its own identity into the payload' );

	// Execute the upsert twice in the same second and once later. The SQLite
	// suffix below is the translation used by WordPress's database integration.
	$pdo = new PDO( 'sqlite::memory:' );
	$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$pdo->exec( 'CREATE TABLE wp_faz_embed_placeholders (url_hash TEXT, service_id TEXT, category TEXT, url TEXT, first_seen TEXT, last_seen TEXT, PRIMARY KEY (url_hash, service_id))' );
	$store = new \ReflectionMethod( Embed_Inventory::class, 'store' );
	foreach ( array( '2026-09-15 12:00:00', '2026-09-15 12:00:00', '2026-09-16 13:00:00' ) as $now ) {
		$GLOBALS['inventory_now'] = $now;
		$store->invoke( null, 'https://example.test/upsert', 'youtube', 'marketing' );
		$sql = end( $GLOBALS['wpdb']->queries );
		inventory_check( false !== strpos( $sql, 'ON DUPLICATE KEY UPDATE' ), 'each observation uses one atomic upsert' );
		$pdo->exec( str_replace( 'ON DUPLICATE KEY UPDATE', 'ON CONFLICT (url_hash, service_id) DO UPDATE SET', $sql ) );
	}
	$row = $pdo->query( 'SELECT * FROM wp_faz_embed_placeholders' )->fetch( PDO::FETCH_ASSOC );
	inventory_check( 1 === (int) $pdo->query( 'SELECT COUNT(*) FROM wp_faz_embed_placeholders' )->fetchColumn(), 'same-second observations keep exactly one row without a duplicate error' );
	inventory_check( '2026-09-15 12:00:00' === $row['first_seen'] && '2026-09-16 13:00:00' === $row['last_seen'], 'refresh preserves first_seen and advances last_seen' );

	echo "\nPassed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
