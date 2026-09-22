<?php
/**
 * Which pages offer a blocked-embed placeholder, and for which service.
 *
 * A GPC exception is a visitor's claim that they clicked Accept on a specific
 * blocked embed. The consent cookie cannot prove that claim — a script on the
 * page can write the same pairs (issue #285) — so the server keeps the one
 * fact it does know and the client cannot invent: whether that page ever
 * rendered a placeholder for that service at all. An exception logged against
 * a page that never offered the embed is, at minimum, not what it says it is.
 *
 * This record is a property of the PAGE, never of the visit. The placeholder
 * is rendered into HTML that page caches serve to everyone, and Cache
 * Compatibility Mode guarantees that HTML does not vary by visitor; a
 * per-visitor write here would quietly break that guarantee and turn a cached
 * hit into a missing row. So: nothing is written during render, the flush runs
 * once per request at shutdown, and a transient keeps it to at most one write
 * per page per day while the page's embeds stay the same.
 *
 * Most visits only refresh what they saw: a page with no placeholder may
 * simply be one this visitor has consented to. A completed GPC render with no
 * existing consent can replace the snapshot of relevant placeholders.
 *
 * @package FazCookie\Frontend\Includes
 */

namespace FazCookie\Frontend\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Embed_Inventory
 */
class Embed_Inventory {

	/**
	 * Schema version for this table.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0';

	/**
	 * Services noted during THIS request: service_id => category.
	 *
	 * @var array<string,string>
	 */
	private static $seen = array();

	/**
	 * Whether the shutdown flush is already registered.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/** @var bool A complete HTML blocking pass succeeded for this request. */
	private static $render_complete = false;

	/**
	 * page_category() answers already given in this request: key|service => category.
	 *
	 * The audit asks the same (page, service) question once per exception in a
	 * payload, and a payload can carry many; one query per pair is enough.
	 *
	 * @var array<string,string>
	 */
	private static $category_memo = array();

	/** Record completion before the inventory can replace a page snapshot. */
	public static function complete_render() {
		self::$render_complete = true;
	}

	/**
	 * Start observing a front-end page.
	 *
	 * The output-buffer callback can build placeholders from a PHP shutdown
	 * function, after WordPress's `shutdown` action has already fired, so an
	 * add_action( 'shutdown' ) hook here would run before the set it is meant to
	 * persist exists. A native shutdown callback registered after the frontend
	 * buffer flusher sees the final set.
	 *
	 * @return void
	 */
	public static function begin() {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		register_shutdown_function( array( __CLASS__, 'flush' ) );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'faz_embed_placeholders';
	}

	/**
	 * Record that this request rendered a placeholder for a service.
	 *
	 * Costs one array write. No query, no option, no I/O: this runs inside the
	 * render path of every blocked embed on the page.
	 *
	 * @param string $service_id Service slug.
	 * @param string $category   Category slug the service belongs to.
	 * @return void
	 */
	public static function note( $service_id, $category ) {
		$service_id = sanitize_key( (string) $service_id );
		if ( '' === $service_id ) {
			return;
		}
		self::$seen[ $service_id ] = sanitize_key( (string) $category );

		self::begin();
	}

	/**
	 * Persist this request's placeholders, if the page's set has changed.
	 *
	 * @return void
	 */
	public static function flush() {
		$seen       = self::$seen;
		self::$seen = array();

		// Only a real front-end page view describes a page. REST, admin, cron
		// and AMP either have no URL worth recording or mint no exceptions.
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() || is_admin() ) {
			return;
		}

		// Consent logging off means there is no row this could ever corroborate.
		$settings = get_option( 'faz_settings', array() );
		if ( empty( $settings['consent_logs']['status'] ) ) {
			return;
		}

		$url = self::current_url();
		$key = self::key( $url );
		if ( '' === $key ) {
			return;
		}

		// Only pages that exist once are worth a row. A 404 and a search results
		// page each answer an unbounded set of URLs anyone can invent, so
		// recording them let a crawler — or a script — mint rows without limit.
		// This is a different question from the one faz_is_machine_readable_
		// request() answers, which deliberately still BLOCKS embeds on both:
		// a visitor sees those pages, and what renders there must stay gated.
		// Only the record of them is dropped.
		if ( ( function_exists( 'is_404' ) && is_404() ) || ( function_exists( 'is_search' ) && is_search() ) ) {
			return;
		}
		if ( ! self::routing_params_parsed() ) {
			return;
		}

		// Usually, nothing seen is not information.
		//
		// A page can render no placeholder for any of several reasons: the embed
		// was removed, this visitor has already consented, that one category is
		// consented while others are not, or a whitelist entry let the request
		// through. One request cannot tell them apart. Deleting on absence
		// therefore threw away valid rows on the commonest case of all — a
		// visitor who accepted — and, with partial consent, deleted the rows of
		// the services that were allowed while keeping the blocked ones.
		//
		// A virgin GPC request is the exception: it carries no prior consent that
		// could hide a sale/share placeholder, while GPC itself closes every
		// relevant category. Its rendered set is therefore authoritative. This
		// also aligns validity with full-page caches: a record lives as long as the
		// cached HTML that created it, and is replaced when an uncached GPC render
		// observes the new page.
		$raw_consent  = function_exists( 'faz_get_valid_consent_cookie' ) ? (string) faz_get_valid_consent_cookie() : '';
		$gpc_snapshot = self::$render_complete && '' === $raw_consent
			&& isset( $_SERVER['HTTP_SEC_GPC'] )
			&& '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) );

		// A visit that saw nothing and is not authoritative has nothing to say.
		if ( empty( $seen ) && ! $gpc_snapshot ) {
			return;
		}

		// The guard: one write per page per day while its embeds are unchanged.
		// Cached pages render on a cache miss, so the cost follows misses, not
		// visitors — and an unchanged page costs a transient read. Keyed by the
		// set seen, so a visitor who sees a different subset (partial consent)
		// still refreshes those rows rather than being short-circuited by
		// another visitor's digest.
		//
		// This check comes BEFORE the clear, and the two must not be separated:
		// clearing first and then returning here — because the digest matched —
		// deleted the page's rows without writing them back, and the transient
		// then kept that state for a day. Every repeat visit from a GPC browser
		// left the inventory empty, so genuine exceptions read as unverified.
		//
		// The guard is asymmetric. A snapshot says everything an observation of
		// the same set could, so a stored `snapshot:<set>` short-circuits both
		// kinds of visitor. An observation cannot vouch for what is ABSENT, so a
		// stored `observed:<set>` short-circuits only another observation: a GPC
		// render of that set must still be allowed to clear stale rows. With a
		// plain equality check the two prefixes never matched each other, and a
		// page whose visitors alternated between GPC and non-GPC wrote on every
		// single visit. One transient per page either way.
		ksort( $seen );
		$set       = md5( serialize( $seen ) );
		$digest    = ( $gpc_snapshot ? 'snapshot:' : 'observed:' ) . $set;
		$guard_key = 'faz_embinv_current_' . $key;
		$stored    = get_transient( $guard_key );
		if ( 'snapshot:' . $set === $stored || ( ! $gpc_snapshot && 'observed:' . $set === $stored ) ) {
			return;
		}

		// An empty snapshot has nothing to write back, only something to clear.
		// When the page has no rows there is nothing to clear either, and
		// without this probe every 404-free URL a GPC browser visited — pages
		// with no embed at all, the commonest page there is — cost a DELETE and
		// a transient. The probe sits here, after the guard and before the
		// clear, so the ordering above still holds.
		if ( $gpc_snapshot && empty( $seen ) && ! self::page_has_rows( $key ) ) {
			return;
		}

		// Clear and rewrite as one step, only when something is actually being
		// written back.
		if ( $gpc_snapshot ) {
			self::clear_page( $url );
		}
		foreach ( $seen as $service_id => $category ) {
			self::store( $url, $service_id, $category );
		}
		set_transient( $guard_key, $digest, DAY_IN_SECONDS );
	}

	/**
	 * The key a page's rows live under.
	 *
	 * The scheme is left out on purpose. The row is written by the rendering
	 * request and read by the consent-log request, and behind a proxy that
	 * terminates TLS the two can disagree about it — one sees https, the other
	 * http — so a scheme-bearing key made a page's rows unreachable from the
	 * lookup that needed them, and every genuine exception read as unverified.
	 * The host still comes from the request, not home_url(): an install served
	 * under several domains keeps each domain's pages apart.
	 *
	 * Every join point goes through here: store, clear, both lookups and the
	 * write guard. A second spelling anywhere is the bug this exists to prevent.
	 *
	 * @param string $url Page URL, in any spelling.
	 * @return string md5 key, or '' when the URL reduces to nothing.
	 */
	private static function key( $url ) {
		$normalized = faz_normalize_page_url( (string) $url );
		$normalized = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', $normalized );
		return '' === $normalized ? '' : md5( $normalized );
	}

	/**
	 * Whether every routing parameter the page URL keeps was really routing.
	 *
	 * faz_normalize_page_url() keeps a fixed list of WordPress routing
	 * parameters so that distinct posts and language variants cannot share
	 * evidence. On a site where nothing reads one of them — `?lang=` without a
	 * language plugin — the value changes nothing on the page but still changes
	 * the key, so `?lang=<anything>` minted a fresh row per value. Only the
	 * write side asks this; the lookup keeps the same normalisation, so the
	 * two sides still agree on every URL that does get recorded.
	 *
	 * @return bool True when the write may proceed.
	 */
	private static function routing_params_parsed() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return true;
		}
		$query = faz_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		if ( ! is_array( $query ) || empty( $query['query'] ) ) {
			return true;
		}
		// No WP object to consult (an early exit, a non-standard bootstrap):
		// nothing to compare against, so do not guess.
		if ( ! isset( $GLOBALS['wp'] ) || ! is_object( $GLOBALS['wp'] ) || ! isset( $GLOBALS['wp']->query_vars ) || ! is_array( $GLOBALS['wp']->query_vars ) ) {
			return true;
		}
		$kept = faz_normalize_page_url( 'http://h/?' . $query['query'] );
		$pos  = strpos( $kept, '?' );
		if ( false === $pos ) {
			return true;
		}
		parse_str( substr( $kept, $pos + 1 ), $route );
		foreach ( array_keys( $route ) as $param ) {
			if ( ! array_key_exists( $param, $GLOBALS['wp']->query_vars ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether any row exists for a page key. One indexed lookup on url_hash.
	 *
	 * @param string $key Page key, from key().
	 * @return bool
	 */
	private static function page_has_rows( $key ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table; table name is prefix + literal and the key is bound. The transient guard is the cache.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE url_hash = %s LIMIT 1", $key ) );
		return ! empty( $found );
	}

	/**
	 * Insert or refresh one (page, service) pair.
	 *
	 * One atomic upsert avoids duplicate-key errors when concurrent renders
	 * observe the same pair in the same second. WordPress's SQLite integration
	 * translates ON DUPLICATE KEY UPDATE into ON CONFLICT DO UPDATE.
	 *
	 * @param string $url        Normalised page URL; the row is keyed by key()
	 *                           of it and the full URL is kept for display.
	 * @param string $service_id Service slug.
	 * @param string $category   Category slug.
	 * @return void
	 */
	private static function store( $url, $service_id, $category ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );
		$hash  = self::key( $url );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table from prefix + literal; values bound; transient guards repeat writes. WordPress SQLite translates this upsert.
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (url_hash, service_id, category, url, first_seen, last_seen) VALUES (%s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE category = %s, last_seen = %s",
			$hash, $service_id, $category, substr( $url, 0, 500 ), $now, $now, $category, $now
		) );
	}

	/**
	 * Remove the previous snapshot for one authoritatively rendered page.
	 *
	 * @param string $url Normalised page URL.
	 * @return void
	 */
	private static function clear_page( $url ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table; table name is prefix + literal and the hash is bound.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE url_hash = %s", self::key( $url ) ) );
	}

	/**
	 * Whether a page is on record as having offered this service's placeholder.
	 *
	 * @param string $url        Page URL, in any spelling.
	 * @param string $service_id Service slug.
	 * @param string $not_after  Optional MySQL datetime; the placeholder must
	 *                           have been first seen at or before it.
	 * @return bool
	 */
	public static function page_offered( $url, $service_id, $not_after = '' ) {
		$key        = self::key( $url );
		$service_id = sanitize_key( (string) $service_id );
		if ( '' === $key || '' === $service_id ) {
			return false;
		}

		global $wpdb;
		$table = self::table();
		if ( '' !== $not_after ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is plugin prefix + literal; every value is bound. Audit lookup: a cached answer could contradict the row being written.
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE url_hash = %s AND service_id = %s AND first_seen <= %s LIMIT 1", $key, $service_id, $not_after ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE url_hash = %s AND service_id = %s LIMIT 1", $key, $service_id ) );
		}

		return ! empty( $found );
	}

	/**
	 * The category a page offered a service under, or '' when it never did.
	 *
	 * One lookup answers both questions the audit asks — whether the page
	 * carried that placeholder, and which category it belonged to — so the
	 * verdict does not have to consult the service catalogue separately and
	 * cannot disagree with what was actually rendered.
	 *
	 * Memoised per request on the page key, so every spelling of one page
	 * shares a single lookup. Nothing in a consent-log request writes this
	 * table, so the memo cannot go stale within it.
	 *
	 * @param string $url        Page URL, in any spelling.
	 * @param string $service_id Service slug.
	 * @return string Category slug, or ''.
	 */
	public static function page_category( $url, $service_id ) {
		$key        = self::key( $url );
		$service_id = sanitize_key( (string) $service_id );
		if ( '' === $key || '' === $service_id ) {
			return '';
		}
		$memo_key = $key . '|' . $service_id;
		if ( isset( self::$category_memo[ $memo_key ] ) ) {
			return self::$category_memo[ $memo_key ];
		}

		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is plugin prefix + literal; both values bound. Audit lookup: a persistent cache could contradict the row being written; the memo above lives for this request only.
		$category = $wpdb->get_var( $wpdb->prepare( "SELECT category FROM {$table} WHERE url_hash = %s AND service_id = %s LIMIT 1", $key, $service_id ) );

		self::$category_memo[ $memo_key ] = null === $category ? '' : (string) $category;
		return self::$category_memo[ $memo_key ];
	}

	/**
	 * The URL of the page being rendered, normalised.
	 *
	 * Public because the page bakes it into the consent-log payload
	 * (`_fazConsentLog.pageUrl`): the row the log writes and the row this class
	 * wrote then name the page with the same function, instead of the browser
	 * rebuilding a URL that drops the routing parameters kept here.
	 *
	 * @return string
	 */
	public static function current_url() {
		if ( empty( $_SERVER['HTTP_HOST'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$https  = function_exists( 'faz_request_is_https' ) ? faz_request_is_https() : is_ssl();
		$scheme = $https ? 'https://' : 'http://';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		$uri    = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		return faz_normalize_page_url( $scheme . $host . $uri );
	}

	/**
	 * Create the table when missing or outdated.
	 *
	 * @return void
	 */
	public static function maybe_create_table() {
		if ( version_compare( (string) get_option( 'faz_embed_inventory_db_version', '0' ), self::DB_VERSION, '>=' ) ) {
			return;
		}

		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// url_hash is the key, not url: an index over a 500-char column costs
		// more than it returns, and the hash answers the only question asked.
		// The url itself is kept so an administrator reading the table can see
		// which page a row is about.
		$sql = "CREATE TABLE {$table} (
			url_hash char(32) NOT NULL,
			service_id varchar(64) NOT NULL,
			category varchar(64) NOT NULL DEFAULT '',
			url varchar(500) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (url_hash,service_id),
			KEY idx_last_seen (last_seen)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'faz_embed_inventory_db_version', self::DB_VERSION, false );
	}

	/**
	 * Drop rows older than the consent-log retention window.
	 *
	 * Page changes are handled by authoritative GPC snapshots. Time-based expiry
	 * follows the evidence this table corroborates; using a shorter window would
	 * invalidate genuine exceptions served from long-lived full-page caches.
	 *
	 * @param int $months Consent-log retention in months.
	 * @return void
	 */
	public static function prune( $months ) {
		$months = max( 1, (int) $months );
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is plugin prefix + literal; cutoff is bound. Portable DELETE (no LIMIT): SQLite rejects DELETE … LIMIT.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", $cutoff ) );
	}
}
