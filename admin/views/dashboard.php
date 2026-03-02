<?php
/**
 * FAZ Cookie Manager — Dashboard Page
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;
?>

<div id="faz-dashboard">
	<div class="faz-grid faz-grid-4" id="faz-stats-row">
		<div class="faz-stat-card">
			<div class="faz-stat-icon faz-stat-icon-primary">
				<span class="dashicons dashicons-visibility"></span>
			</div>
			<div class="faz-stat-value" id="faz-stat-pageviews">--</div>
			<div class="faz-stat-label">Total Pageviews</div>
		</div>
		<div class="faz-stat-card">
			<div class="faz-stat-icon faz-stat-icon-warning">
				<span class="dashicons dashicons-megaphone"></span>
			</div>
			<div class="faz-stat-value" id="faz-stat-banner">--</div>
			<div class="faz-stat-label">Banner Views</div>
		</div>
		<div class="faz-stat-card">
			<div class="faz-stat-icon faz-stat-icon-success">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
			<div class="faz-stat-value" id="faz-stat-accept">--</div>
			<div class="faz-stat-label">Accept Rate</div>
		</div>
		<div class="faz-stat-card">
			<div class="faz-stat-icon faz-stat-icon-danger">
				<span class="dashicons dashicons-dismiss"></span>
			</div>
			<div class="faz-stat-value" id="faz-stat-reject">--</div>
			<div class="faz-stat-label">Reject Rate</div>
		</div>
	</div>

	<div class="faz-grid faz-grid-2" style="margin-top:20px;">
		<div class="faz-card">
			<div class="faz-card-header">
				<h3>Pageviews — Last 7 Days</h3>
			</div>
			<div class="faz-card-body">
				<div class="faz-chart-wrap">
					<canvas id="faz-chart-pageviews" width="600" height="220" style="width:100%;height:220px;"></canvas>
					<div id="faz-chart-empty" class="faz-chart-empty faz-hidden">
						<span class="dashicons dashicons-chart-area"></span>
						<p>No pageview data yet.<br>Data will appear once visitors interact with your site.</p>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header">
				<h3>Consent Distribution</h3>
			</div>
			<div class="faz-card-body">
				<div class="faz-chart-wrap">
					<canvas id="faz-chart-consent" width="300" height="220" style="width:100%;height:220px;"></canvas>
					<div id="faz-consent-empty" class="faz-chart-empty faz-hidden">
						<span class="dashicons dashicons-chart-pie"></span>
						<p>No consent data yet.<br>Data will appear once visitors respond to the banner.</p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3>Quick Links</h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-grid faz-grid-3">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=faz-cookie-manager-cookies' ) ); ?>" class="faz-quick-link">
					<span class="dashicons dashicons-admin-generic"></span>
					<span class="faz-quick-link-text">Manage Cookies</span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=faz-cookie-manager-banner' ) ); ?>" class="faz-quick-link">
					<span class="dashicons dashicons-megaphone"></span>
					<span class="faz-quick-link-text">Cookie Banner</span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=faz-cookie-manager-gcm' ) ); ?>" class="faz-quick-link">
					<span class="dashicons dashicons-chart-bar"></span>
					<span class="faz-quick-link-text">Google Consent Mode</span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=faz-cookie-manager-consent-logs' ) ); ?>" class="faz-quick-link">
					<span class="dashicons dashicons-list-view"></span>
					<span class="faz-quick-link-text">Consent Logs</span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=faz-cookie-manager-languages' ) ); ?>" class="faz-quick-link">
					<span class="dashicons dashicons-translation"></span>
					<span class="faz-quick-link-text">Languages</span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=faz-cookie-manager-settings' ) ); ?>" class="faz-quick-link">
					<span class="dashicons dashicons-admin-settings"></span>
					<span class="faz-quick-link-text">Settings</span>
				</a>
			</div>
		</div>
	</div>
</div>
