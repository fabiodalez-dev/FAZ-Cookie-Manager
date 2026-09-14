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

		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 99 );
		}
	}

	/**
	 * Persist this request's placeholders, if the page's set has changed.
	 *
	 * @return void
	 */
	public static function flush() {
		if ( empty( self::$seen ) ) {
			return;
		}
		$seen       = self::$seen;
		self::$seen = array();

		// Only a real front-end page view describes a page. REST, admin, cron
		// and AMP either have no URL worth recording or mint no exceptions.
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || is_admin() ) {
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

		// The guard: one write per page per day while its embeds are unchanged.
		// Cached pages render on a cache miss, so the cost follows misses, not
		// visitors — and an unchanged page costs a transient read.
		$key    = 'faz_embinv_' . md5( $url );
		$ids    = array_keys( $seen );
		sort( $ids );
		$digest = implode( ',', $ids );
		if ( get_transient( $key ) === $digest ) {
			return;
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
		$scheme = is_ssl() ? 'https://' : 'http://';
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
	 * A row that can no longer corroborate any log is only a record of which
	 * pages carry which embeds, which is not something to keep indefinitely.
	 *
	 * @param int $months Retention in months.
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
