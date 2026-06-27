<?php
/**
 * FAZ Cookie Manager — Google Consent Mode Page
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="faz-gcm">

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Google Consent Mode v2', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="status" id="faz-gcm-enabled">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable Google Consent Mode', 'faz-cookie-manager' ); ?></span>
				</label>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Consent Signal Defaults', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-table-wrap">
				<table class="faz-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Consent Type', 'faz-cookie-manager' ); ?></th>
							<th><?php esc_html_e( 'Default (before consent)', 'faz-cookie-manager' ); ?></th>
							<th><?php esc_html_e( 'When Granted', 'faz-cookie-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><strong>ad_storage</strong></td>
							<td><span class="faz-badge faz-badge-danger"><?php esc_html_e( 'denied', 'faz-cookie-manager' ); ?></span></td>
							<td><span class="faz-badge faz-badge-success"><?php esc_html_e( 'granted', 'faz-cookie-manager' ); ?></span></td>
						</tr>
						<tr>
							<td><strong>analytics_storage</strong></td>
							<td><span class="faz-badge faz-badge-danger"><?php esc_html_e( 'denied', 'faz-cookie-manager' ); ?></span></td>
							<td><span class="faz-badge faz-badge-success"><?php esc_html_e( 'granted', 'faz-cookie-manager' ); ?></span></td>
						</tr>
						<tr>
							<td><strong>ad_user_data</strong></td>
							<td><span class="faz-badge faz-badge-danger"><?php esc_html_e( 'denied', 'faz-cookie-manager' ); ?></span></td>
							<td><span class="faz-badge faz-badge-success"><?php esc_html_e( 'granted', 'faz-cookie-manager' ); ?></span></td>
						</tr>
						<tr>
							<td><strong>ad_personalization</strong></td>
							<td><span class="faz-badge faz-badge-danger"><?php esc_html_e( 'denied', 'faz-cookie-manager' ); ?></span></td>
							<td><span class="faz-badge faz-badge-success"><?php esc_html_e( 'granted', 'faz-cookie-manager' ); ?></span></td>
						</tr>
						<tr>
							<td><strong>functionality_storage</strong></td>
							<td><span class="faz-badge faz-badge-danger"><?php esc_html_e( 'denied', 'faz-cookie-manager' ); ?></span></td>
							<td><span class="faz-badge faz-badge-success"><?php esc_html_e( 'granted', 'faz-cookie-manager' ); ?></span></td>
						</tr>
						<tr>
							<td><strong>personalization_storage</strong></td>
							<td><span class="faz-badge faz-badge-danger"><?php esc_html_e( 'denied', 'faz-cookie-manager' ); ?></span></td>
							<td><span class="faz-badge faz-badge-success"><?php esc_html_e( 'granted', 'faz-cookie-manager' ); ?></span></td>
						</tr>
						<tr>
							<td><strong>security_storage</strong></td>
							<td><span class="faz-badge faz-badge-success"><?php esc_html_e( 'granted', 'faz-cookie-manager' ); ?></span></td>
							<td><span class="faz-badge faz-badge-success"><?php esc_html_e( 'granted', 'faz-cookie-manager' ); ?></span></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Advanced Settings', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Wait for Update (ms)', 'faz-cookie-manager' ); ?></label>
				<input type="number" class="faz-input faz-input-sm" data-path="wait_for_update" value="500" min="0" style="width:120px;">
				<div class="faz-help"><?php esc_html_e( 'Milliseconds to wait for consent update before firing tags. Default: 500.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="url_passthrough">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'URL Passthrough', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Pass ad click information through URLs when ad_storage is denied.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="ads_data_redaction">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Ads Data Redaction', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Redact ad click identifiers when ad_storage is denied.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="non_personalized_ads_fallback" aria-describedby="faz-gcm-npa-fallback-help">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Serve non-personalized ads when consent is denied', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help" id="faz-gcm-npa-fallback-help">
					<?php esc_html_e( 'When a visitor rejects marketing cookies, ad_storage / ad_user_data / ad_personalization all stay denied (no ad cookies are written) and the plugin signals npa = 1. Under Consent Mode v2 this already serves non-personalized, cookieless ads — compliant in the EEA/UK/CH. The plugin no longer grants ad_storage after a reject.', 'faz-cookie-manager' ); ?>
				</div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="advanced_mode" aria-describedby="faz-gcm-advanced-help">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Advanced Consent Mode (load Google tags before consent)', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help" id="faz-gcm-advanced-help">
					<strong><?php esc_html_e( 'This lets Google tags (gtag.js / GA4 / Google Ads) load BEFORE consent.', 'faz-cookie-manager' ); ?></strong><br>
					<?php esc_html_e( 'They run with consent default → denied, so pre-consent hits are cookieless (Google\'s "Advanced"/modeled mode) and upgrade to full measurement once the visitor accepts. Non-Google trackers and the Google Tag Manager container (gtm.js) stay fully blocked.', 'faz-cookie-manager' ); ?><br>
					<?php esc_html_e( 'Leave this OFF for the strictest, block-everything-before-consent behaviour. Loading Google before consent is a legal judgement call: some EU authorities still treat cookieless pings as a third-party transfer, so enabling it is your responsibility as the site operator. Default: off.', 'faz-cookie-manager' ); ?>
				</div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Google Additional Consent Mode (GACM)', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="gacm_enabled">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable GACM', 'faz-cookie-manager' ); ?></span>
				</label>
			</div>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Ad Technology Provider IDs', 'faz-cookie-manager' ); ?></label>
				<textarea class="faz-textarea" data-path="gacm_provider_ids" rows="3" placeholder="<?php esc_attr_e( 'Comma-separated provider IDs, e.g. 89,91,128', 'faz-cookie-manager' ); ?>"></textarea>
				<div class="faz-help"><?php esc_html_e( 'Google Ad Tech Provider IDs to include in the AC string.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div style="margin-top:8px;">
		<button class="faz-btn faz-btn-primary" id="faz-gcm-save"><?php esc_html_e( 'Save GCM Settings', 'faz-cookie-manager' ); ?></button>
	</div>
</div>
