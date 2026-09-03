<?php
/**
 * FAZ Cookie Manager — System Status view
 *
 * Displays environment info, plugin configuration, database stats,
 * cron jobs, and active plugins for diagnostic / support purposes.
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$settings     = get_option( 'faz_settings' );
$gcm_settings = get_option( 'faz_gcm_settings' );
$active_plugins = get_option( 'active_plugins', array() );
$theme = wp_get_theme();

// DB table sizes — cached for 2 minutes to avoid 10 queries per page load.
$table_info = get_transient( 'faz_system_status_tables' );
if ( false === $table_info ) {
	$tables     = array( 'faz_banners', 'faz_cookies', 'faz_cookie_categories', 'faz_consent_logs', 'faz_pageviews' );
	$table_info = array();
	foreach ( $tables as $t ) {
		$full   = $wpdb->prefix . $t;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- system-status table existence probe; bound via prepare(%s). Result cached at the function level via the transient set after this loop.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $full is $wpdb->prefix + an allowlisted plugin-table suffix from the foreach above. Result transient-cached after the loop.
			$row = $wpdb->get_row( "SELECT COUNT(*) as cnt FROM {$full}" );
			$table_info[ $t ] = $row ? absint( $row->cnt ) : 0;
		} else {
			$table_info[ $t ] = -1; // table missing
		}
	}
	set_transient( 'faz_system_status_tables', $table_info, 2 * MINUTE_IN_SECONDS );
}

// Cron status.
$next_scan    = wp_next_scheduled( 'faz_scheduled_scan' );
$next_cleanup = wp_next_scheduled( 'faz_daily_cleanup' );
$blocked_server_cookies = get_transient( 'faz_recent_blocked_server_cookies' );
$blocked_server_cookies = is_array( $blocked_server_cookies ) ? array_reverse( $blocked_server_cookies ) : array();
?>
<div id="faz-system-status">

	<div style="margin-bottom:12px;">
		<button class="faz-btn faz-btn-outline" id="faz-copy-status" type="button">
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
			<?php esc_html_e( 'Copy Status to Clipboard', 'faz-cookie-manager' ); ?>
		</button>
	</div>

	<div class="faz-card">
		<div class="faz-card-header"><h3><?php esc_html_e( 'Environment', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<table class="faz-status-table">
				<tr><td><?php esc_html_e( 'Plugin Version', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( FAZ_VERSION ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'WordPress Version', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'PHP Version', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( PHP_VERSION ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'MySQL Version', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( $wpdb->db_version() ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'Server', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown' ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'Memory Limit', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( WP_MEMORY_LIMIT ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'Max Execution Time', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( ini_get( 'max_execution_time' ) ); ?>s</code></td></tr>
				<tr><td><?php esc_html_e( 'PCRE Backtrack Limit', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( ini_get( 'pcre.backtrack_limit' ) ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'Multisite', 'faz-cookie-manager' ); ?></td><td><code><?php echo is_multisite() ? 'Yes' : 'No'; ?></code></td></tr>
				<tr><td><?php esc_html_e( 'HTTPS', 'faz-cookie-manager' ); ?></td><td><code><?php echo is_ssl() ? 'Yes' : 'No'; ?></code></td></tr>
				<tr><td><?php esc_html_e( 'Active Theme', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ); ?></code></td></tr>
			</table>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header"><h3><?php esc_html_e( 'Plugin Configuration', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<table class="faz-status-table">
				<tr><td><?php esc_html_e( 'Banner Enabled', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['banner_control']['status'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Consent Logging', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['consent_logs']['status'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Google Consent Mode', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $gcm_settings['status'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'IAB TCF v2.3', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['iab']['enabled'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Pageview Tracking', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['pageview_tracking'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Auto Scan', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['scanner']['auto_scan'] ) ? faz_status_flag( true ) . ' &mdash; ' . esc_html( $settings['scanner']['scan_frequency'] ?? 'weekly' ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Geo-Targeting', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['geolocation']['geo_targeting'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<?php
				// Both features were masked off in an earlier release and this
				// page said so. The mask was removed when they began to be driven
				// from the saved setting — see
				// Activator::reset_stale_per_cookie_consent() — but the copy here
				// was left behind, so the one page whose job is to report the
				// effective configuration went on reporting a live, enforced
				// feature as unavailable. Read the option, like every other row
				// does. The sanitiser already forces per_cookie false whenever
				// per_service is off, so a plain read IS the effective state.
				?>
				<tr><td><?php esc_html_e( 'Per-Service Consent', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['banner_control']['per_service_consent'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Per-Cookie Consent', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['banner_control']['per_cookie_consent'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Bot Detection', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ( ! isset( $settings['banner_control']['hide_from_bots'] ) || ! empty( $settings['banner_control']['hide_from_bots'] ) ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'GTM Data Layer', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['banner_control']['gtm_datalayer'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Age Gate', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['age_gate']['enabled'] ) ? faz_status_flag( true ) . ' ' . sprintf( /* translators: %d: minimum age in years. */ esc_html__( '(min age %d)', 'faz-cookie-manager' ), absint( $settings['age_gate']['min_age'] ?? 16 ) ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Cross-Domain Consent', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['consent_forwarding']['enabled'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Ad-Blocker Compat', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['banner_control']['alternative_asset_path'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Microsoft UET', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['microsoft']['uet_consent_mode'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Microsoft Clarity', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['microsoft']['clarity_consent'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'PHP Set-Cookie Blocking', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['script_blocking']['block_server_cookies'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
			</table>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header"><h3><?php esc_html_e( 'Recently Blocked Server Cookies', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<?php if ( empty( $blocked_server_cookies ) ) : ?>
				<p><?php esc_html_e( 'No outgoing PHP Set-Cookie header has been blocked in the last 24 hours.', 'faz-cookie-manager' ); ?></p>
			<?php else : ?>
				<?php
				/*
				 * Four DATA columns, so this one carries faz-status-table-data.
				 * Every other table on this page is a label:value pair, which is
				 * what the bare .faz-status-table rules are built for — they bold
				 * the first cell and give it 40% width. Applied here that made the
				 * cookie name read as a row label and left the remaining three
				 * columns captionless, on the page an administrator uses to check
				 * that PHP Set-Cookie blocking is not breaking checkout or login.
				 */
				?>
				<table class="faz-status-table faz-status-table-data">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Cookie', 'faz-cookie-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Category', 'faz-cookie-manager' ); ?></th>
							<?php
							/*
							 * "Path", not "URI": record_blocked_server_cookies()
							 * stores strtok( REQUEST_URI, '?' ) capped at 255
							 * chars, because WordPress query strings routinely
							 * carry personal data (?email=, order_key, the
							 * password-reset key+login pair, search terms) and
							 * this row is written by anonymous visitors. An
							 * administrator reading this column to check that
							 * Set-Cookie blocking is not breaking checkout or
							 * login must not expect a query string that is
							 * deliberately never captured.
							 */
							?>
							<th scope="col"><?php esc_html_e( 'Request Path', 'faz-cookie-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Blocked At', 'faz-cookie-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $blocked_server_cookies, 0, 20 ) as $blocked_cookie ) : ?>
							<tr>
								<td><code><?php echo esc_html( isset( $blocked_cookie['name'] ) ? $blocked_cookie['name'] : '' ); ?></code></td>
								<td><?php echo esc_html( isset( $blocked_cookie['category'] ) ? $blocked_cookie['category'] : '' ); ?></td>
								<td><code><?php echo esc_html( isset( $blocked_cookie['request'] ) ? $blocked_cookie['request'] : '' ); ?></code></td>
								<td><?php echo ! empty( $blocked_cookie['blocked_at'] ) ? esc_html( date_i18n( 'Y-m-d H:i:s', absint( $blocked_cookie['blocked_at'] ) ) ) : '&mdash;'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header"><h3><?php esc_html_e( 'Database', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<table class="faz-status-table">
				<?php foreach ( $table_info as $name => $count ) : ?>
				<tr><td><code><?php echo esc_html( $wpdb->prefix . $name ); ?></code></td><td><?php
					if ( -1 === $count ) {
						echo '<span style="color:red;">' . esc_html__( 'Table missing', 'faz-cookie-manager' ) . '</span>';
					} else {
						echo esc_html( number_format_i18n( $count ) ) . ' ' . esc_html__( 'rows', 'faz-cookie-manager' );
					}
				?></td></tr>
				<?php endforeach; ?>
			</table>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header"><h3><?php esc_html_e( 'Cron Jobs', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<table class="faz-status-table">
				<tr>
					<td><?php esc_html_e( 'Next Scheduled Scan', 'faz-cookie-manager' ); ?></td>
					<td><?php echo wp_kses_post( faz_status_schedule( $next_scan ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Next Consent Log Cleanup', 'faz-cookie-manager' ); ?></td>
					<td><?php echo wp_kses_post( faz_status_schedule( $next_cleanup ) ); ?></td>
				</tr>
				<?php
				// The definitions' age belongs beside the cron rows, because the
				// two questions are the same one: "why are my definitions old?"
				// is usually answered by an overdue schedule two lines up.
				$faz_ocd_meta = class_exists( '\\FazCookie\\Includes\\Cookie_Definitions' )
					? ( new \FazCookie\Includes\Cookie_Definitions() )->get_meta()
					: array();
				$faz_gvl_meta = class_exists( '\\FazCookie\\Includes\\Gvl' )
					? ( new \FazCookie\Includes\Gvl() )->get_meta()
					: array();
				?>
				<tr>
					<td><?php esc_html_e( 'Cookie definitions updated', 'faz-cookie-manager' ); ?></td>
					<td><?php
					// Key on `source`, not on the presence of a date. get_meta()
					// always returns an updated_at now — the download's, or the
					// bundled snapshot's capture date — so testing the date alone
					// made the bundled branch unreachable and printed a snapshot
					// date as though the site had downloaded it that day. Two
					// different situations report source 'bundled': never
					// downloaded at all, and downloaded once but superseded by a
					// newer bundle, so the copy must not claim "never downloaded"
					// for both.
					if ( isset( $faz_ocd_meta['source'] ) && 'bundled' === $faz_ocd_meta['source'] && ! empty( $faz_ocd_meta['updated_at'] ) ) {
						printf(
							/* translators: %s: date the bundled snapshot's data was captured. */
							esc_html__( 'bundled data in use, captured %s', 'faz-cookie-manager' ),
							esc_html( $faz_ocd_meta['updated_at'] )
						);
					} elseif ( ! empty( $faz_ocd_meta['updated_at'] ) ) {
						echo esc_html( $faz_ocd_meta['updated_at'] );
					} else {
						esc_html_e( 'never downloaded (bundled data in use)', 'faz-cookie-manager' );
					}
					?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'IAB vendor list updated', 'faz-cookie-manager' ); ?></td>
					<td><?php echo ! empty( $faz_gvl_meta['last_updated'] )
						? esc_html( $faz_gvl_meta['last_updated'] )
						: esc_html__( 'never downloaded', 'faz-cookie-manager' ); ?></td>
				</tr>
			</table>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header"><h3><?php esc_html_e( 'Active Plugins', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<div style="font-size:13px;line-height:1.8;max-height:300px;overflow-y:auto;">
				<?php
				foreach ( $active_plugins as $p ) {
					$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $p, false, false );
					echo esc_html( $plugin_data['Name'] ?? $p ) . ' <code>' . esc_html( $plugin_data['Version'] ?? '?' ) . '</code><br>';
				}
				?>
			</div>
		</div>
	</div>

</div>

<?php
/*
 * Page-specific styles live in admin/assets/css/faz-admin.css under the
 * "System Status page" block — automatically enqueued for every FAZ
 * admin page.
 *
 * Page-specific behaviour lives in admin/assets/js/pages/system-status.js —
 * automatically enqueued by class-admin.php::enqueue_scripts() when the
 * current page view is "system-status". The localized "Status copied"
 * string is registered in the same enqueue block under
 * fazConfig.i18n.systemStatus.copied.
 */
?>
