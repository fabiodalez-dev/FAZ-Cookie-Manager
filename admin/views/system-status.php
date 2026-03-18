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

// DB table sizes.
$tables = array( 'faz_banners', 'faz_cookies', 'faz_cookie_categories', 'faz_consent_logs', 'faz_pageviews' );
$table_info = array();
foreach ( $tables as $t ) {
	$full   = $wpdb->prefix . $t;
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $exists ) {
		$row = $wpdb->get_row( "SELECT COUNT(*) as cnt FROM {$full}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_info[ $t ] = $row ? absint( $row->cnt ) : 0;
	} else {
		$table_info[ $t ] = -1; // table missing
	}
}

// Cron status.
$next_scan    = wp_next_scheduled( 'faz_scheduled_scan' );
$next_cleanup = wp_next_scheduled( 'faz_daily_cleanup' );
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
				<tr><td><?php esc_html_e( 'Banner Enabled', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['banner_control']['status'] ) ? '&#9989;' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Consent Logging', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['consent_logs']['status'] ) ? '&#9989;' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Google Consent Mode', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $gcm_settings['status'] ) ? '&#9989;' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'IAB TCF v2.3', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['iab']['enabled'] ) ? '&#9989;' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Pageview Tracking', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['pageview_tracking'] ) ? '&#9989;' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Auto Scan', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['scanner']['auto_scan'] ) ? '&#9989; ' . esc_html( $settings['scanner']['scan_frequency'] ?? 'weekly' ) : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Geo-Targeting', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['geolocation']['geo_targeting'] ) ? '&#9989;' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Per-Service Consent', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['banner_control']['per_service_consent'] ) ? '&#9989;' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Bot Detection', 'faz-cookie-manager' ); ?></td><td><?php echo ( ! isset( $settings['banner_control']['hide_from_bots'] ) || ! empty( $settings['banner_control']['hide_from_bots'] ) ) ? '&#9989;' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'GTM Data Layer', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['banner_control']['gtm_datalayer'] ) ? '&#9989;' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Age Gate', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['age_gate']['enabled'] ) ? '&#9989; (min ' . absint( $settings['age_gate']['min_age'] ?? 16 ) . ')' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Cross-Domain Consent', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['consent_forwarding']['enabled'] ) ? '&#9989;' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Ad-Blocker Compat', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['banner_control']['alternative_asset_path'] ) ? '&#9989;' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Microsoft UET', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['microsoft']['uet_consent_mode'] ) ? '&#9989;' : '&#10060;'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Microsoft Clarity', 'faz-cookie-manager' ); ?></td><td><?php echo ! empty( $settings['microsoft']['clarity_consent'] ) ? '&#9989;' : '&#10060;'; ?></td></tr>
			</table>
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
					<td><?php echo $next_scan ? esc_html( wp_date( 'Y-m-d H:i:s', $next_scan ) ) : '&mdash;'; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Next Consent Log Cleanup', 'faz-cookie-manager' ); ?></td>
					<td><?php echo $next_cleanup ? esc_html( wp_date( 'Y-m-d H:i:s', $next_cleanup ) ) : '&mdash;'; ?></td>
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

<style>
.faz-status-table { width:100%; border-collapse:collapse; }
.faz-status-table td { padding:6px 12px; border-bottom:1px solid var(--faz-border); font-size:13px; }
.faz-status-table td:first-child { font-weight:600; width:40%; color:var(--faz-text-secondary); }
</style>

<script>
document.getElementById('faz-copy-status').addEventListener('click', function() {
	var text = 'FAZ Cookie Manager — System Status\n' + '='.repeat(50) + '\n\n';
	document.querySelectorAll('#faz-system-status .faz-card').forEach(function(card) {
		var heading = card.querySelector('.faz-card-header h3');
		if (heading) text += heading.textContent + '\n' + '-'.repeat(30) + '\n';
		var table = card.querySelector('.faz-status-table');
		if (table) {
			table.querySelectorAll('tr').forEach(function(row) {
				var cells = row.querySelectorAll('td');
				if (cells.length >= 2) {
					text += cells[0].textContent.trim() + ': ' + cells[1].textContent.trim() + '\n';
				}
			});
		}
		var list = card.querySelector('div[style*="line-height"]');
		if (list) text += list.textContent.trim().replace(/\n\s+/g, '\n') + '\n';
		text += '\n';
	});
	navigator.clipboard.writeText(text).then(function() {
		FAZ.notify('Status copied to clipboard!', 'success');
	});
});
</script>
