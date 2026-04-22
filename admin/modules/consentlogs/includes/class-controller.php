<?php
/**
 * Class Controller file.
 *
 * Local DB-backed consent log controller.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Consentlogs\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Consent Log Operations using local WordPress database.
 *
 * @class       Controller
 * @version     3.0.0
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
	private $table_name = 'faz_consent_logs';

	/**
	 * DB version option key
	 *
	 * @var string
	 */
	private $db_version = '1.1';

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
	 * Create the consent logs table if it does not exist.
	 *
	 * @return void
	 */
	public function maybe_create_table() {
		$installed_version = get_option( 'faz_consent_logs_db_version', '0' );
		if ( version_compare( $installed_version, $this->db_version, '>=' ) ) {
			return;
		}

		global $wpdb;
		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			log_id bigint(20) NOT NULL AUTO_INCREMENT,
			consent_id varchar(190) NOT NULL,
			status varchar(20) NOT NULL,
			categories longtext,
			ip_hash varchar(64) DEFAULT '',
			user_agent text,
			url varchar(500) DEFAULT '',
			banner_slug varchar(190) DEFAULT '',
			policy_revision bigint(20) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (log_id),
			KEY idx_consent_id (consent_id),
			KEY idx_status (status),
			KEY idx_created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Migrate legacy plaintext user_agent values to hashed form.
		$migration_ok = true;
		if ( version_compare( $installed_version, '1.1', '<' ) ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table_name}
					 SET user_agent = LOWER(SHA2(CONCAT(user_agent, %s), 256))
					 WHERE user_agent <> ''
					 AND user_agent NOT REGEXP %s",
					wp_salt( 'auth' ),
					'^[0-9a-f]{64}$'
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $result ) {
				$migration_ok = false;
			}
		}

		if ( $migration_ok ) {
			update_option( 'faz_consent_logs_db_version', $this->db_version );
		}
	}

	/**
	 * Hash an IP address for GDPR compliance.
	 *
	 * @param string $ip The IP address.
	 * @return string SHA256 hash of IP + salt.
	 */
	private function hash_ip( $ip ) {
		if ( empty( $ip ) ) {
			return '';
		}
		return hash( 'sha256', $ip . wp_salt() );
	}

	/**
	 * Hash the user agent so the raw fingerprint is not stored in the database.
	 *
	 * @param string $user_agent Raw user agent string.
	 * @return string
	 */
	private function hash_user_agent( $user_agent ) {
		if ( empty( $user_agent ) ) {
			return '';
		}

		return hash( 'sha256', $user_agent . wp_salt( 'auth' ) );
	}

	/**
	 * Drop query string and fragment before persisting a consent log URL.
	 *
	 * @param string $url URL to sanitize.
	 * @return string
	 */
	private function sanitize_log_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( false === $parts || ! is_array( $parts ) ) {
			return '';
		}

		$sanitized = '';
		if ( ! empty( $parts['scheme'] ) ) {
			$sanitized .= $parts['scheme'] . '://';
		}
		// Deliberately omit user:pass — never persist credentials in logs.
		if ( ! empty( $parts['host'] ) ) {
			$sanitized .= $parts['host'];
		}
		if ( ! empty( $parts['port'] ) ) {
			$sanitized .= ':' . absint( $parts['port'] );
		}
		if ( ! empty( $parts['path'] ) ) {
			$sanitized .= $parts['path'];
		}

		return $sanitized;
	}

	/**
	 * Generate a unique consent ID.
	 *
	 * @return string Base64-encoded random string.
	 */
	private function generate_consent_id() {
		return base64_encode( wp_generate_password( 24, false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Get the visitor's IP address.
	 *
	 * @return string
	 */
	private function get_visitor_ip() {
		return faz_resolve_client_ip();
	}

	/**
	 * Log a consent record.
	 *
	 * @param array $data Consent data with keys: consent_id, status, categories, url.
	 * @return array|false The inserted record data or false on failure.
	 */
	public function log_consent( $data ) {
		global $wpdb;

		$consent_id = ! empty( $data['consent_id'] ) ? sanitize_text_field( $data['consent_id'] ) : $this->generate_consent_id();
		$status     = isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'partial';
		$categories = isset( $data['categories'] ) ? $data['categories'] : array();
		$url        = isset( $data['url'] ) ? $this->sanitize_log_url( $data['url'] ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $this->hash_user_agent( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		$ip_hash    = $this->hash_ip( $this->get_visitor_ip() );
		$banner_slug = isset( $data['banner_slug'] ) ? sanitize_title( $data['banner_slug'] ) : '';
		$policy_revision = isset( $data['policy_revision'] ) ? max( 1, absint( $data['policy_revision'] ) ) : 1;

		if ( is_array( $categories ) || is_object( $categories ) ) {
			$categories = wp_json_encode( $categories );
		}

		$result = $wpdb->insert(
			$this->get_table_name(),
			array(
				'consent_id' => $consent_id,
				'status'     => $status,
				'categories' => $categories,
				'ip_hash'    => $ip_hash,
				'user_agent' => $user_agent,
				'url'        => $url,
				'banner_slug' => $banner_slug,
				'policy_revision' => $policy_revision,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $result ) {
			if ( ! empty( $wpdb->last_error ) ) {
				error_log( 'FAZ consent log insert failed: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return false;
		}

		return array(
			'log_id'     => $wpdb->insert_id,
			'consent_id' => $consent_id,
			'status'     => $status,
			'banner_slug' => $banner_slug,
			'policy_revision' => $policy_revision,
			'created_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Get paginated consent logs.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *     @type int    $paged    Current page. Default 1.
	 *     @type int    $per_page Items per page. Default 10.
	 *     @type string $search   Search term (matches consent_id or url).
	 *     @type string $status   Filter by status.
	 *     @type string $orderby  Column to order by. Default 'created_at'.
	 *     @type string $order    ASC or DESC. Default 'DESC'.
	 * }
	 * @return array {
	 *     @type array $items Array of log records.
	 *     @type int   $total Total number of matching records.
	 *     @type int   $pages Total number of pages.
	 * }
	 */
	public function get_logs( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'paged'    => 1,
			'per_page' => 10,
			'search'   => '',
			'status'   => '',
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		);
		$args     = wp_parse_args( $args, $defaults );
		$table    = $this->get_table_name();

		$where = array( '1=1' );
		$values = array();

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(consent_id LIKE %s OR url LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		$where_clause = implode( ' AND ', $where );

		// Whitelist orderby columns.
		$allowed_orderby = array( 'log_id', 'consent_id', 'status', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		// Count total — always use prepare() even when $values is empty.
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", $values ) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE 1=1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Get items.
		$per_page = absint( $args['per_page'] );
		$offset   = ( absint( $args['paged'] ) - 1 ) * $per_page;

		$query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$query_values = array_merge( $values, array( $per_page, $offset ) );
		$items = $wpdb->get_results( $wpdb->prepare( $query, $query_values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $items ) ) {
			$items = array();
		}

		// Decode categories JSON for each item.
		foreach ( $items as &$item ) {
			if ( ! empty( $item['categories'] ) ) {
				$decoded = json_decode( $item['categories'], true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					$item['categories'] = $decoded;
				}
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Get a single consent log by consent_id.
	 *
	 * @param string $consent_id The consent ID to look up.
	 * @return array|null The log record or null if not found.
	 */
	public function get_log_by_consent_id( $consent_id ) {
		global $wpdb;

		$table = $this->get_table_name();
		$item  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE consent_id = %s ORDER BY created_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$consent_id
			),
			ARRAY_A
		);

		if ( null === $item ) {
			return null;
		}

		if ( ! empty( $item['categories'] ) ) {
			$decoded = json_decode( $item['categories'], true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$item['categories'] = $decoded;
			}
		}

		return $item;
	}

	/**
	 * Get consent statistics grouped by status.
	 *
	 * @return array Array of objects with 'type' and 'count' keys.
	 */
	public function get_statistics() {
		global $wpdb;

		$table = $this->get_table_name();
		$results = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM {$table} GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! is_array( $results ) || empty( $results ) ) {
			return array();
		}

		$logs  = array();
		$total = 0;
		foreach ( $results as $row ) {
			$count  = absint( $row['count'] );
			$total += $count;
			$logs[] = array(
				'type'  => sanitize_text_field( $row['status'] ),
				'count' => $count,
			);
		}

		if ( $total <= 0 ) {
			return array();
		}

		return $logs;
	}

	/**
	 * Export consent logs as CSV.
	 *
	 * @param array $args Query arguments (same as get_logs, but per_page can be -1 for all).
	 * @return string CSV content.
	 */
	public function export_csv( $args = array() ) {
		global $wpdb;

		$table = $this->get_table_name();

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(consent_id LIKE %s OR url LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		$where_clause = implode( ' AND ', $where );

		if ( ! empty( $values ) ) {
			$query = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC", $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$query = "SELECT * FROM {$table} WHERE 1=1 ORDER BY created_at DESC"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$items = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$output = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $output ) {
			return '';
		}

		// CSV header row.
		fputcsv( $output, array( 'Log ID', 'Consent ID', 'Status', 'Categories', 'IP Hash', 'User Agent Hash', 'URL', 'Banner Slug', 'Policy Revision', 'Created At' ) );

		foreach ( $items as $item ) {
			fputcsv(
				$output,
				array_map(
					array( $this, 'sanitize_csv_cell' ),
					array(
						$item['log_id'],
						$item['consent_id'],
						$item['status'],
						$item['categories'], // Already JSON string from DB.
						$item['ip_hash'],
						$item['user_agent'],
						$item['url'],
						isset( $item['banner_slug'] ) ? $item['banner_slug'] : '',
						isset( $item['policy_revision'] ) ? $item['policy_revision'] : 1,
						$item['created_at'],
					)
				)
			);
		}

		rewind( $output );
		$csv = stream_get_contents( $output );
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $csv;
	}

	/**
	 * Sanitize a single CSV cell to prevent formula injection.
	 *
	 * Prefixes values starting with dangerous characters (=, +, -, @, \t, \r)
	 * with a single quote so spreadsheet apps do not interpret them as formulas.
	 *
	 * @param string $value Cell value.
	 * @return string Sanitized value.
	 */
	private function sanitize_csv_cell( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		// Strip leading whitespace/newlines that could bypass the prefix check.
		$trimmed = ltrim( $value, " \t\n\r\0\x0B" );
		if ( '' !== $trimmed && in_array( $trimmed[0], array( '=', '+', '-', '@', "\t", "\r", "\n" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Get detailed consent statistics: daily breakdown, totals, and per-category acceptance.
	 *
	 * @param int $days Number of days to look back.
	 * @return array {
	 *     @type array      $daily      Daily breakdown with date, accepted, rejected, partial, total.
	 *     @type array      $totals     Overall totals for the period.
	 *     @type array      $categories Per-category yes/no counts.
	 * }
	 */
	public function get_consent_stats( $days = 30 ) {
		global $wpdb;

		$table  = $this->get_table_name();
		$days   = absint( $days );
		// Use PHP-computed cutoff with current_time() for consistency with
		// how created_at is stored (via current_time('mysql') in log_consent).
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days', strtotime( current_time( 'mysql' ) ) ) );

		// Daily consent breakdown.
		$daily = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date,
						SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
						SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
						SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial,
						COUNT(*) as total
				 FROM {$table}
				 WHERE created_at >= %s
				 GROUP BY DATE(created_at)
				 ORDER BY date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			),
			ARRAY_A
		);

		if ( ! is_array( $daily ) ) {
			$daily = array();
		}

		// Overall totals.
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) as total,
						SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
						SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
						SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial
				 FROM {$table}
				 WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			),
			ARRAY_A
		);

		if ( ! is_array( $totals ) ) {
			$totals = array(
				'total'    => 0,
				'accepted' => 0,
				'rejected' => 0,
				'partial'  => 0,
			);
		}

		// Per-category acceptance rates — parse the JSON categories column.
		$category_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT categories FROM {$table}
				 WHERE created_at >= %s
				 AND categories IS NOT NULL AND categories != ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			),
			ARRAY_A
		);

		$cat_counts = array();
		if ( is_array( $category_rows ) ) {
			foreach ( $category_rows as $row ) {
				$cats = json_decode( $row['categories'], true );
				if ( ! is_array( $cats ) ) {
					continue;
				}
				foreach ( $cats as $cat => $value ) {
					$cat = sanitize_text_field( $cat );
					if ( ! isset( $cat_counts[ $cat ] ) ) {
						$cat_counts[ $cat ] = array( 'yes' => 0, 'no' => 0 );
					}
					if ( 'yes' === $value ) {
						$cat_counts[ $cat ]['yes']++;
					} else {
						$cat_counts[ $cat ]['no']++;
					}
				}
			}
		}

		return array(
			'daily'      => $daily,
			'totals'     => $totals,
			'categories' => $cat_counts,
		);
	}

	/**
	 * Cleanup old consent logs beyond a given retention period.
	 *
	 * @param int $months Number of months to retain. Logs older than this are deleted.
	 * @return int Number of rows deleted.
	 */
	public function cleanup_old_logs( $months = 12 ) {
		global $wpdb;

		$table    = $this->get_table_name();
		$months   = absint( $months );
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return (int) $deleted;
	}
}
