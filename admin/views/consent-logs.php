<?php
/**
 * FAZ Cookie Manager — Consent Logs Page
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;
?>

<div id="faz-consent-logs">

	<!-- Statistics Row -->
	<div class="faz-grid faz-grid-4" id="faz-log-stats">
		<div class="faz-stat-card">
			<div class="faz-stat-icon faz-stat-icon-primary">
				<span class="dashicons dashicons-list-view"></span>
			</div>
			<div class="faz-stat-value" id="faz-stat-total">--</div>
			<div class="faz-stat-label"><?php esc_html_e( 'Total Logs', 'faz-cookie-manager' ); ?></div>
		</div>
		<div class="faz-stat-card">
			<div class="faz-stat-icon faz-stat-icon-success">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
			<div class="faz-stat-value" id="faz-stat-accepted">--</div>
			<div class="faz-stat-label"><?php esc_html_e( 'Accepted', 'faz-cookie-manager' ); ?></div>
		</div>
		<div class="faz-stat-card">
			<div class="faz-stat-icon faz-stat-icon-danger">
				<span class="dashicons dashicons-dismiss"></span>
			</div>
			<div class="faz-stat-value" id="faz-stat-rejected">--</div>
			<div class="faz-stat-label"><?php esc_html_e( 'Rejected', 'faz-cookie-manager' ); ?></div>
		</div>
		<div class="faz-stat-card">
			<div class="faz-stat-icon faz-stat-icon-warning">
				<span class="dashicons dashicons-marker"></span>
			</div>
			<div class="faz-stat-value" id="faz-stat-partial">--</div>
			<div class="faz-stat-label"><?php esc_html_e( 'Partial', 'faz-cookie-manager' ); ?></div>
		</div>
	</div>

	<!-- Filters & Export -->
	<div class="faz-card" style="margin-top:20px;">
		<div class="faz-card-body" style="padding:12px 16px;">
			<div class="faz-filter-bar">
				<div class="faz-filter-group">
					<select class="faz-select" id="faz-log-status" style="width:auto;min-width:140px;">
						<option value=""><?php esc_html_e( 'All Statuses', 'faz-cookie-manager' ); ?></option>
						<option value="accepted"><?php esc_html_e( 'Accepted', 'faz-cookie-manager' ); ?></option>
						<option value="rejected"><?php esc_html_e( 'Rejected', 'faz-cookie-manager' ); ?></option>
						<option value="partial"><?php esc_html_e( 'Partial', 'faz-cookie-manager' ); ?></option>
					</select>
					<input type="text" class="faz-input" id="faz-log-search" placeholder="<?php esc_attr_e( 'Search consent ID or URL...', 'faz-cookie-manager' ); ?>" style="width:260px;">
					<button class="faz-btn faz-btn-secondary" id="faz-log-filter">
						<span class="dashicons dashicons-search" style="margin-top:3px;"></span> <?php esc_html_e( 'Filter', 'faz-cookie-manager' ); ?>
					</button>
				</div>
				<div class="faz-filter-group">
					<button class="faz-btn faz-btn-secondary" id="faz-log-export">
						<span class="dashicons dashicons-download" style="margin-top:3px;"></span> <?php esc_html_e( 'Export CSV', 'faz-cookie-manager' ); ?>
					</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Logs Table -->
	<div class="faz-card" style="margin-top:16px;">
		<div class="faz-card-body" style="padding:0;">
			<table class="faz-table" id="faz-logs-table">
				<thead>
					<tr>
						<th style="width:155px;"><?php esc_html_e( 'Date', 'faz-cookie-manager' ); ?></th>
						<th><?php esc_html_e( 'Consent ID', 'faz-cookie-manager' ); ?></th>
						<th style="width:95px;"><?php esc_html_e( 'Status', 'faz-cookie-manager' ); ?></th>
						<th><?php esc_html_e( 'Categories', 'faz-cookie-manager' ); ?></th>
						<th style="width:110px;"><?php esc_html_e( 'IP Hash', 'faz-cookie-manager' ); ?></th>
						<th><?php esc_html_e( 'Page URL', 'faz-cookie-manager' ); ?></th>
					</tr>
				</thead>
				<tbody id="faz-logs-body">
					<tr><td colspan="6" class="faz-text-center faz-text-muted" style="padding:40px;"><?php esc_html_e( 'Loading...', 'faz-cookie-manager' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<div class="faz-card-footer" id="faz-log-footer" style="display:none;">
			<div class="faz-pagination-info" id="faz-log-info"></div>
			<div class="faz-pagination" id="faz-log-pagination"></div>
		</div>
	</div>
</div>
