<?php
/**
 * FAZ Cookie Manager — Import / Export Page
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="faz-import-export">

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Export Settings', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<p><?php esc_html_e( 'Download all plugin settings, banner configuration, cookie categories, and cookies as a JSON file. Consent logs, pageview data, and API keys are excluded for privacy and security.', 'faz-cookie-manager' ); ?></p>
			<button class="faz-btn faz-btn-primary" id="faz-export-btn">
				<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
				<?php esc_html_e( 'Export Settings', 'faz-cookie-manager' ); ?>
			</button>
		</div>
	</div>

	<div class="faz-card" style="margin-top:16px;">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Import Settings', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<p><?php esc_html_e( 'Upload a previously exported JSON file to restore or migrate settings. This will overwrite your current plugin configuration.', 'faz-cookie-manager' ); ?></p>
			<div class="faz-form-group">
				<label for="faz-import-file"><?php esc_html_e( 'Select JSON file', 'faz-cookie-manager' ); ?></label>
				<input type="file" id="faz-import-file" accept=".json,application/json" class="faz-input" style="max-width:400px;">
			</div>
			<div id="faz-import-preview" style="display:none;margin:16px 0;">
				<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Import Preview', 'faz-cookie-manager' ); ?></h4>
				<div id="faz-import-summary" style="padding:12px;background:var(--faz-bg-secondary);border-radius:6px;font-size:13px;"></div>
			</div>
			<button class="faz-btn faz-btn-primary" id="faz-import-btn" disabled>
				<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
				<?php esc_html_e( 'Import Settings', 'faz-cookie-manager' ); ?>
			</button>
			<div id="faz-import-status" role="status" aria-live="polite" style="margin-top:12px;display:none;"></div>
		</div>
	</div>

</div>
