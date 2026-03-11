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
				<div class="faz-help"><?php esc_html_e( 'Enable if you use any Google service (Analytics, Ads, Tag Manager). Google Consent Mode adjusts how Google tags behave based on visitor consent. When denied, Google uses cookieless pings for basic measurement without identifying visitors.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Consent Signal Defaults', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-help" style="margin:0 0 12px;"><?php esc_html_e( 'These are the default consent states sent to Google before the visitor interacts with the banner. All signals start as "denied" (except security_storage) and switch to "granted" when the visitor accepts the corresponding category.', 'faz-cookie-manager' ); ?></div>
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
				<div class="faz-help"><?php esc_html_e( 'Milliseconds Google tags wait for the consent response before firing. Default 500 ms. Increase if your banner loads slowly; decrease for faster page interaction.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="url_passthrough">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'URL Passthrough', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'When ad_storage is denied, passes ad click information (gclid, dclid) through page URLs instead of cookies. Improves conversion measurement accuracy without storing cookies.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="ads_data_redaction">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Ads Data Redaction', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'When ad_storage is denied, redacts ad click identifiers sent in network requests. Provides extra privacy protection at the cost of reduced conversion tracking accuracy.', 'faz-cookie-manager' ); ?></div>
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
				<div class="faz-help"><?php esc_html_e( 'Google Additional Consent Mode extends TCF by covering ad-tech providers not registered in the IAB Global Vendor List. Required only if you work with non-IAB ad-tech providers through Google.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Ad Technology Provider IDs', 'faz-cookie-manager' ); ?></label>
				<textarea class="faz-textarea" data-path="gacm_provider_ids" rows="3" placeholder="<?php echo esc_attr__( 'Comma-separated provider IDs, e.g. 89,91,128', 'faz-cookie-manager' ); ?>"></textarea>
				<div class="faz-help"><?php esc_html_e( 'Comma-separated list of Google Ad Technology Provider IDs to include in the Additional Consent (AC) string. You can find your provider IDs in your Google Ad Manager or AdSense settings.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div style="margin-top:8px;">
		<button class="faz-btn faz-btn-primary" id="faz-gcm-save"><?php esc_html_e( 'Save GCM Settings', 'faz-cookie-manager' ); ?></button>
	</div>
</div>
