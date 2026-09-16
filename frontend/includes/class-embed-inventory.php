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
		if ( '' === $url ) {
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
		ksort( $seen );
		$digest = ( $gpc_snapshot ? 'snapshot:' : 'observed:' ) . md5( serialize( $seen ) );
		// One cache entry describes the latest write, not every historical set.
		$key    = 'faz_embinv_current_' . md5( $url );
		if ( get_transient( $key ) === $digest ) {
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
		set_transient( $key, $digest, DAY_IN_SECONDS );
	}

	/**
	 * Insert or refresh one (page, service) pair.
	 *
	 * UPDATE first, INSERT when it touched nothing: no ON DUPLICATE KEY, which
	 * SQLite does not speak. This project has already shipped a purge that was
	 * a permanent silent no-op on SQLite because it used MySQL-only syntax.
	 *
	 * @param string $url        Normalised page URL.
	 * @param string $service_id Service slug.
	 * @param string $category   Category slug.
	 * @return void
	 */
	private static function store( $url, $service_id, $category ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );
		$hash  = md5( $url );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table; the transient above is the cache, and this write is what it guards.
		$updated = $wpdb->update(
			$table,
			array(
				'last_seen' => $now,
				'category'  => $category,
			),
			array(
				'url_hash'   => $hash,
				'service_id' => $service_id,
			),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);

		if ( $updated ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
		$wpdb->insert(
			$table,
			array(
				'url_hash'   => $hash,
				'service_id' => $service_id,
				'category'   => $category,
				'url'        => substr( $url, 0, 500 ),
				'first_seen' => $now,
				'last_seen'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
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
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE url_hash = %s", md5( $url ) ) );
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
		$url        = faz_normalize_page_url( $url );
		$service_id = sanitize_key( (string) $service_id );
		if ( '' === $url || '' === $service_id ) {
			return false;
		}

		global $wpdb;
		$table = self::table();
		if ( '' !== $not_after ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is plugin prefix + literal; every value is bound. Audit lookup: a cached answer could contradict the row being written.
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE url_hash = %s AND service_id = %s AND first_seen <= %s LIMIT 1", md5( $url ), $service_id, $not_after ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE url_hash = %s AND service_id = %s LIMIT 1", md5( $url ), $service_id ) );
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
	 * @param string $url        Page URL, in any spelling.
	 * @param string $service_id Service slug.
	 * @return string Category slug, or ''.
	 */
	public static function page_category( $url, $service_id ) {
		$url        = faz_normalize_page_url( $url );
		$service_id = sanitize_key( (string) $service_id );
		if ( '' === $url || '' === $service_id ) {
			return '';
		}

		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is plugin prefix + literal; both values bound. Audit lookup: a cached answer could contradict the row being written.
		$category = $wpdb->get_var( $wpdb->prepare( "SELECT category FROM {$table} WHERE url_hash = %s AND service_id = %s LIMIT 1", md5( $url ), $service_id ) );

		return null === $category ? '' : (string) $category;
	}

	/**
	 * The URL of the page being rendered, normalised.
	 *
	 * @return string
	 */
	private static function current_url() {
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
