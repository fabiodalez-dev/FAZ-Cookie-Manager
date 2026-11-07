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
			<h3><?php esc_html_e( 'Google Tag', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label for="faz-gcm-google-tag-ids"><?php esc_html_e( 'Google tag IDs', 'faz-cookie-manager' ); ?></label>
				<input type="text" class="faz-input" id="faz-gcm-google-tag-ids" data-path="google_tag_ids" autocomplete="off" spellcheck="false" placeholder="G-XXXXXXXXXX, GTM-XXXXXXX" aria-describedby="faz-gcm-google-tag-help" style="max-width:420px;">
				<div class="faz-help" id="faz-gcm-google-tag-help">
					<p><?php esc_html_e( 'Let the plugin load Google Analytics 4 or a Google tag (G-, GT-), Google Ads or Floodlight (AW-, DC-) and Google Tag Manager containers (GTM-), separated by commas, up to five. Leave it empty if the tag is already installed by your theme or another plugin.', 'faz-cookie-manager' ); ?></p>
					<p><?php esc_html_e( 'Each tag waits for the same consent a copy pasted into the theme would: Google Analytics for Analytics, Google Ads and Floodlight for Marketing, the Tag Manager container for its catalogue category. With Advanced Consent Mode on, Google Analytics and Google Ads load before consent in cookieless mode; a Tag Manager container waits for consent, because it can run tags that ignore Consent Mode, unless you also turn on the Tag Manager option under Advanced Consent Mode.', 'faz-cookie-manager' ); ?></p>
					<p><?php esc_html_e( 'This works whether or not Google Consent Mode is enabled. Nothing is loaded on pages where the consent banner is turned off, since visitors there have no way to consent.', 'faz-cookie-manager' ); ?></p>
				</div>
				<div class="faz-help" id="faz-gcm-google-tag-notice" role="status" hidden style="margin-top:.5rem;border-left:3px solid #f59e0b;padding:.5rem .75rem;background:#fffbeb;color:#78350f;"></div>
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
					<p><?php esc_html_e( 'When Analytics and Performance are both offered, Advanced mode makes analytics_storage follow Analytics. Moving GA4 or another Google analytics tag to Performance or a custom category does not change this mapping: granting that category while denying Analytics keeps analytics_storage denied. Keep Google analytics tags in Analytics when using Advanced mode. Basic mode also considers Performance.', 'faz-cookie-manager' ); ?></p>
					<strong><?php esc_html_e( 'This lets Google tags (gtag.js / GA4 / Google Ads) load BEFORE consent.', 'faz-cookie-manager' ); ?></strong><br>
					<?php esc_html_e( 'They run with consent default → denied, so pre-consent hits are cookieless (Google\'s "Advanced"/modeled mode) and upgrade to full measurement once the visitor accepts. Non-Google trackers stay fully blocked, and so does the Google Tag Manager container (gtm.js) unless you turn on the option below.', 'faz-cookie-manager' ); ?><br>
					<?php esc_html_e( 'Leave this OFF for the strictest, block-everything-before-consent behaviour. Loading Google before consent is a legal judgement call: some EU authorities still treat cookieless pings as a third-party transfer, so enabling it is your responsibility as the site operator. Default: off.', 'faz-cookie-manager' ); ?>
				</div>
			</div>
			<div class="faz-form-group" id="faz-gcm-advanced-gtm-group" data-show-if="advanced_mode" style="margin-left:24px;">
				<label class="faz-toggle">
					<input type="checkbox" data-path="advanced_mode_gtm" id="faz-gcm-advanced-gtm" aria-describedby="faz-gcm-advanced-gtm-help faz-gcm-advanced-gtm-warning">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Also load the Google Tag Manager container before consent', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help" id="faz-gcm-advanced-gtm-help">
					<?php esc_html_e( 'For sites that configure Google Analytics 4 and Google Ads conversions inside Google Tag Manager. The container (gtm.js) loads before consent behind the same consent default → denied, so the Google tags in it can send cookieless pings that Google uses for conversion modelling. The noscript iframe stays blocked, and an explicit rejection of the Google Tag Manager service still blocks the container. Only works together with Advanced Consent Mode. Default: off.', 'faz-cookie-manager' ); ?>
				</div>
				<div class="faz-help" id="faz-gcm-advanced-gtm-warning" role="note" style="margin-top:.5rem;border-left:3px solid #f59e0b;padding:.5rem .75rem;background:#fffbeb;color:#78350f;">
					<strong><?php esc_html_e( 'You become responsible for every tag inside the container.', 'faz-cookie-manager' ); ?></strong>
					<?php esc_html_e( 'A container can run tags that do not read Consent Mode: Meta Pixel, LinkedIn, TikTok, Hotjar, custom HTML. The plugin still blocks the trackers it recognises when the container injects them, but it cannot see every tag a container can run, so it no longer guarantees that nothing tracks before consent. Before turning this on, open each non-Google tag in Tag Manager and set its Consent Settings to "Require additional consent for tag to fire" with the matching consent type (for example ad_storage), or use a trigger that waits for consent.', 'faz-cookie-manager' ); ?>
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
