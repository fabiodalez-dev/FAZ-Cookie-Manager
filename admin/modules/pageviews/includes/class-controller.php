<?php
/**
 * Class Controller file.
 *
 * Local DB-backed pageview & banner interaction controller.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Pageviews\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Pageview and Banner Interaction tracking using local DB.
 *
 * @class       Controller
 * @version     1.0.0
 * @package     FazCookie
 */
class Controller {

	/**
	 * Instance of the current class
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Table name (without prefix)
	 *
	 * @var string
	 */
	private $table_name = 'faz_pageviews';

	/**
	 * DB version option key
	 *
	 * @var string
	 */
	private $db_version = '1.0';

	/**
	 * Return the current instance of the class
	 *
	 * @return Controller
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - ensure table exists.
	 */
	private function __construct() {
		$this->maybe_create_table();
	}

	/**
	 * Get the full table name with WP prefix.
	 *
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . $this->table_name;
	}

	/**
	 * Create the pageviews table if it does not exist.
	 *
	 * @return void
	 */
	public function maybe_create_table() {
		$installed_version = get_option( 'faz_pageviews_db_version', '0' );
		if ( version_compare( $installed_version, $this->db_version, '>=' ) ) {
			return;
		}

		global $wpdb;
		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			page_url varchar(500) NOT NULL DEFAULT '',
			page_title varchar(255) DEFAULT '',
			event_type varchar(50) NOT NULL DEFAULT 'pageview',
			session_id varchar(64) DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_event_type (event_type),
			KEY idx_created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'faz_pageviews_db_version', $this->db_version );
	}

	/**
	 * Record a pageview or banner interaction event.
	 *
	 * @param array $data Event data with keys: page_url, page_title, event_type, session_id.
	 * @return array|false The inserted record data or false on failure.
	 */
	public function record_event( $data ) {
		global $wpdb;

		$allowed_events = array( 'pageview', 'banner_view', 'banner_accept', 'banner_reject', 'banner_settings' );

		$page_url   = isset( $data['page_url'] ) ? esc_url_raw( $data['page_url'] ) : '';
		$page_title = isset( $data['page_title'] ) ? sanitize_text_field( $data['page_title'] ) : '';
		$event_type = isset( $data['event_type'] ) ? sanitize_text_field( $data['event_type'] ) : 'pageview';
		$session_id = isset( $data['session_id'] ) ? sanitize_text_field( $data['session_id'] ) : '';

		if ( ! in_array( $event_type, $allowed_events, true ) ) {
			$event_type = 'pageview';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- pageview writes by design must not be cached; every visitor pageview produces a fresh row.
		$result = $wpdb->insert(
			$this->get_table_name(),
			array(
				'page_url'   => $page_url,
				'page_title' => $page_title,
				'event_type' => $event_type,
				'session_id' => $session_id,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		return array(
			'id'         => $wpdb->insert_id,
			'event_type' => $event_type,
			'created_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Build a date-range WHERE clause and params array.
	 *
	 * @param int         $days Number of days to look back. 0 = all time.
	 * @param string|null $from Start date (Y-m-d).
	 * @param string|null $to   End date (Y-m-d), inclusive.
	 * @return array{string, array<string>} [ $where_sql, $params ]
	 */
	private function build_date_clause( $days, $from = null, $to = null ) {
		if ( $from && $to && strtotime( $from ) && strtotime( $to ) ) {
			$end = gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) );
			return array( 'AND created_at >= %s AND created_at < %s', array( $from, $end ) );
		}

		$days = absint( $days );
		if ( 0 === $days ) {
			return array( '', array() );
		}

		$cutoff = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		return array( 'AND created_at >= %s', array( $cutoff ) );
	}

	/**
	 * Get pageview chart data grouped by day for the last N days.
	 *
	 * @param int         $days Number of days to look back. Default 7.
	 * @param string|null $from Start date (Y-m-d).
	 * @param string|null $to   End date (Y-m-d), inclusive.
	 * @return array
	 */
	public function get_pageviews( $days = 7, $from = null, $to = null ) {
		global $wpdb;

		$table = $this->get_table_name();
		list( $date_clause, $date_params ) = $this->build_date_clause( $days, $from, $to );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix; $date_clause is built by build_date_clause() which returns either an empty string or "AND created_at BETWEEN %s AND %s" with $date_params holding the bound values.
		$sql = "SELECT DATE(created_at) as date, COUNT(*) as views
			FROM {$table}
			WHERE event_type = 'pageview' {$date_clause}
			GROUP BY DATE(created_at)
			ORDER BY date ASC";

		if ( ! empty( $date_params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql built above with bind-only placeholders; $date_params holds the values prepare() binds. Live admin analytics — caching would mask near-real-time data.
			$results = $wpdb->get_results( $wpdb->prepare( $sql, $date_params ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql produced above with no user input ($date_clause empty in this branch). Live admin analytics.
			$results = $wpdb->get_results( $sql, ARRAY_A );
		}

		$total_views = 0;
		$data        = array();

		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$views        = absint( $row['views'] );
				$total_views += $views;
				// Vue chart parses dates with moment('DD-MM-YYYY') format.
				$formatted_date = gmdate( 'd-m-Y', strtotime( $row['date'] ) );
				$data[]         = array(
					'date'          => $formatted_date,
					'views'         => $views,
					'overage_views' => 0,
				);
			}
		}

		return array(
			'total_views' => $total_views,
			'data'        => $data,
		);
	}

	/**
	 * Get banner interaction statistics for the last N days.
	 *
	 * @param int         $days Number of days to look back. Default 30.
	 * @param string|null $from Start date (Y-m-d).
	 * @param string|null $to   End date (Y-m-d), inclusive.
	 * @return array
	 */
	public function get_banner_stats( $days = 30, $from = null, $to = null ) {
		global $wpdb;

		$table = $this->get_table_name();
		list( $date_clause, $date_params ) = $this->build_date_clause( $days, $from, $to );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix; $date_clause is built by build_date_clause() with bind-only placeholders.
		$sql = "SELECT event_type, COUNT(*) as count
			FROM {$table}
			WHERE event_type LIKE 'banner%%' {$date_clause}
			GROUP BY event_type";

		if ( ! empty( $date_params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql with bind-only placeholders; $date_params bound by prepare(). Live admin analytics.
			$results = $wpdb->get_results( $wpdb->prepare( $sql, $date_params ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql with no user input ($date_clause empty). Live admin analytics.
			$results = $wpdb->get_results( $sql, ARRAY_A );
		}

		$stats = array(
			'banner_view'     => 0,
			'banner_accept'   => 0,
			'banner_reject'   => 0,
			'banner_settings' => 0,
		);

		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$type = sanitize_text_field( $row['event_type'] );
				if ( isset( $stats[ $type ] ) ) {
					$stats[ $type ] = absint( $row['count'] );
				}
			}
		}

		return $stats;
	}

	/**
	 * Get daily banner interaction trend for charts.
	 *
	 * @param int $days Number of days to look back. Default 30.
	 * @return array
	 */
	public function get_banner_daily_trend( $days = 30 ) {
		global $wpdb;

		$table   = $this->get_table_name();
		$days    = absint( $days );
		$cutoff  = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix; $cutoff is bound via prepare(%s). Live admin analytics — caching would mask near-real-time data.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, event_type, COUNT(*) as count
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY DATE(created_at), event_type
				ORDER BY date ASC",
				$cutoff
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$daily = array();
		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$date  = $row['date'];
				$type  = sanitize_text_field( $row['event_type'] );
				$count = absint( $row['count'] );
				if ( ! isset( $daily[ $date ] ) ) {
					$daily[ $date ] = array(
						'date'            => $date,
						'pageview'        => 0,
						'banner_view'     => 0,
						'banner_accept'   => 0,
						'banner_reject'   => 0,
						'banner_settings' => 0,
					);
				}
				if ( isset( $daily[ $date ][ $type ] ) ) {
					$daily[ $date ][ $type ] = $count;
				}
			}
		}

		return array_values( $daily );
	}

	/**
	 * Cleanup old pageview records beyond a given retention period.
	 *
	 * @param int $months Number of months to retain. Default 6.
	 * @return int Number of rows deleted.
	 */
	public function cleanup_old_records( $months = 6 ) {
		global $wpdb;

		$table    = $this->get_table_name();
		$months   = absint( $months );
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix; $cutoff is bound via prepare(%s). DELETE write — caching irrelevant.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return (int) $deleted;
	}
}
