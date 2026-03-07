<?php
/**
 * FAZ Cookie Manager — Settings Page
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="faz-settings">

	<div class="faz-card">
		<div class="faz-card-header">
			<h3>Banner Control</h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.status">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label">Enable cookie banner</span>
				</label>
			</div>
			<div class="faz-form-group">
				<label>Excluded Pages</label>
				<textarea class="faz-textarea" data-path="banner_control.excluded_pages" rows="3" placeholder="One per line: page ID or URL pattern like /privacy/*"></textarea>
				<div class="faz-help">Enter page IDs or URL patterns, one per line.</div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3>Consent Logs</h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="consent_logs.status">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label">Enable consent logging</span>
				</label>
			</div>
			<div class="faz-form-group">
				<label>Retention Period (months)</label>
				<input type="number" class="faz-input faz-input-sm" data-path="consent_logs.retention" value="12" min="1" max="120" style="width:120px;">
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3>Scanner</h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label>Max Pages to Scan</label>
				<input type="number" class="faz-input faz-input-sm" data-path="scanner.max_pages" value="100" min="1" style="width:120px;">
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3>Microsoft Consent APIs</h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="microsoft.uet_consent_mode">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label">Microsoft UET Consent Mode</span>
				</label>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="microsoft.clarity_consent">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label">Microsoft Clarity Consent API</span>
				</label>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3>IAB TCF</h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="iab.enabled">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label">Enable IAB TCF v2.3</span>
				</label>
			</div>
			<div class="faz-form-group" data-show-if="iab.enabled" style="margin-top:12px;">
				<label for="faz-iab-publisher-cc" style="display:block;margin-bottom:4px;font-weight:600;">Publisher Country Code</label>
				<input type="text" id="faz-iab-publisher-cc" data-path="iab.publisher_cc" maxlength="2" style="width:60px;text-transform:uppercase;" placeholder="IT">
				<p class="description" style="margin-top:4px;color:var(--faz-text-secondary);">ISO 3166-1 alpha-2 code of the publisher's country (e.g. IT, DE, FR). Used in the TCF consent string.</p>
			</div>
			<div class="faz-form-group" data-show-if="iab.enabled" style="margin-top:12px;">
				<label for="faz-iab-cmp-id" style="display:block;margin-bottom:4px;font-weight:600;">CMP ID</label>
				<input type="number" id="faz-iab-cmp-id" class="faz-input faz-input-sm" data-path="iab.cmp_id" min="0" max="4095" style="width:120px;" placeholder="0">
				<p class="description" style="margin-top:4px;color:var(--faz-text-secondary);">Your registered IAB CMP ID (<a href="https://iabeurope.eu/cmp-list/" target="_blank" rel="noopener noreferrer">IAB CMP List</a>). With ID&nbsp;0 the banner and cookie blocking work normally, but ad-tech vendors will ignore the TC String. Google Consent Mode v2 works regardless of CMP registration.</p>
			</div>
			<div class="faz-form-group" data-show-if="iab.enabled" style="margin-top:12px;">
				<label class="faz-toggle">
					<input type="checkbox" data-path="iab.purpose_one_treatment">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label">Purpose One Treatment</span>
				</label>
				<p class="description" style="margin-top:4px;color:var(--faz-text-secondary);">Set to true if Purpose 1 consent was NOT disclosed (e.g. publisher in a country where Purpose 1 is not required).</p>
			</div>
			<div class="faz-form-group" data-show-if="iab.enabled" style="margin-top:12px;">
				<div id="faz-gvl-status" role="status" aria-live="polite" aria-atomic="true" style="padding:10px;border-radius:6px;background:var(--faz-bg-secondary);">
					<span style="color:var(--faz-text-secondary);">Loading GVL status...</span>
				</div>
				<button class="faz-btn faz-btn-secondary" id="faz-gvl-update" type="button" style="margin-top:8px;">Update GVL Now</button>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3>GeoIP Database (MaxMind GeoLite2)</h3>
		</div>
		<div class="faz-card-body">
			<p style="margin:0 0 12px;color:var(--faz-text-secondary);">
				Geo-targeting requires a MaxMind GeoLite2-Country database.
				<a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" rel="noopener">Get a free license key</a>.
			</p>
			<div class="faz-form-group">
				<label>MaxMind License Key</label>
				<input type="password" class="faz-input" data-path="geolocation.maxmind_license_key" placeholder="Enter your MaxMind license key" style="max-width:400px;">
			</div>
			<div id="faz-geodb-status" style="margin:12px 0;padding:10px;border-radius:6px;background:var(--faz-bg-secondary);display:none;">
			</div>
			<button class="faz-btn faz-btn-secondary" id="faz-geodb-update" type="button">Update Database</button>
		</div>
	</div>

	<div style="margin-top:8px;">
		<button class="faz-btn faz-btn-primary" id="faz-settings-save">Save Settings</button>
	</div>
</div>
