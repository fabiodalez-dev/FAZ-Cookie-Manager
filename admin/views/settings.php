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
			<h3><?php echo esc_html__( 'Banner Control', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.status">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php echo esc_html__( 'Enable cookie banner', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php echo esc_html__( 'When enabled, the cookie consent banner is shown to visitors who have not yet given or denied consent.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label><?php echo esc_html__( 'Excluded Pages', 'faz-cookie-manager' ); ?></label>
				<textarea class="faz-textarea" data-path="banner_control.excluded_pages" rows="3" placeholder="<?php echo esc_attr__( 'One per line: page ID or URL pattern like /privacy/*', 'faz-cookie-manager' ); ?>"></textarea>
				<div class="faz-help"><?php echo esc_html__( 'Enter page IDs or URL patterns (one per line) where the banner should not appear. Supports wildcards, e.g. /checkout/*', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php echo esc_html__( 'Consent Logs', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="consent_logs.status">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php echo esc_html__( 'Enable consent logging', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php echo esc_html__( 'Records every visitor consent action (accept, reject, customise) in your local database. Required by GDPR Article 7 for demonstrating valid consent.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label><?php echo esc_html__( 'Retention Period (months)', 'faz-cookie-manager' ); ?></label>
				<input type="number" class="faz-input faz-input-sm" data-path="consent_logs.retention" value="12" min="1" max="120" style="width:120px;">
				<div class="faz-help"><?php echo esc_html__( 'How long consent records are kept before automatic deletion. Most regulations require at least 12 months.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php echo esc_html__( 'Scanner', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label><?php echo esc_html__( 'Max Pages to Scan', 'faz-cookie-manager' ); ?></label>
				<input type="number" class="faz-input faz-input-sm" data-path="scanner.max_pages" value="100" min="1" style="width:120px;">
				<div class="faz-help"><?php echo esc_html__( 'Upper limit for the Standard scan depth. The scanner crawls your site pages in the browser to detect cookies. Higher values find more cookies but take longer.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php echo esc_html__( 'Microsoft Consent APIs', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="microsoft.uet_consent_mode">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php echo esc_html__( 'Microsoft UET Consent Mode', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php echo esc_html__( 'Enable if you use Microsoft Advertising (Bing Ads). Sends consent signals via the UET tag so Microsoft respects visitor choices.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="microsoft.clarity_consent">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php echo esc_html__( 'Microsoft Clarity Consent API', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php echo esc_html__( 'Enable if you use Microsoft Clarity for session recordings and heatmaps. Passes consent status so Clarity only records when the visitor has consented.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php echo esc_html__( 'IAB TCF', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="iab.enabled">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php echo esc_html__( 'Enable IAB TCF v2.3', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php echo esc_html__( 'The IAB Transparency and Consent Framework is required by most programmatic advertising platforms (Google AdSense, AdX, header bidding). Enable this if you run ads on your site.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group" data-show-if="iab.enabled" style="margin-top:12px;">
				<label for="faz-iab-publisher-cc" style="display:block;margin-bottom:4px;font-weight:600;"><?php echo esc_html__( 'Publisher Country Code', 'faz-cookie-manager' ); ?></label>
				<input type="text" id="faz-iab-publisher-cc" data-path="iab.publisher_cc" maxlength="2" style="width:60px;text-transform:uppercase;" placeholder="IT">
				<div class="faz-help"><?php echo esc_html__( 'ISO 3166-1 alpha-2 code of the country where the publisher (you) is established (e.g. IT, DE, FR, US). This is embedded in the TCF consent string.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group" data-show-if="iab.enabled" style="margin-top:12px;">
				<label for="faz-iab-cmp-id" style="display:block;margin-bottom:4px;font-weight:600;"><?php echo esc_html__( 'CMP ID', 'faz-cookie-manager' ); ?></label>
				<input type="number" id="faz-iab-cmp-id" class="faz-input faz-input-sm" data-path="iab.cmp_id" min="0" max="4095" style="width:120px;" placeholder="0">
				<div class="faz-help"><?php
					printf(
						/* translators: %s: URL to IAB CMP list */
						esc_html__( 'Your registered IAB CMP ID (0–4095). You can find the official list at %s. With ID 0 the banner works normally, but ad-tech vendors will ignore the TC String. Google Consent Mode works regardless.', 'faz-cookie-manager' ),
						'<a href="https://iabeurope.eu/cmp-list/" target="_blank" rel="noopener noreferrer">iabeurope.eu/cmp-list</a>'
					);
				?></div>
			</div>
			<div class="faz-form-group" data-show-if="iab.enabled" style="margin-top:12px;">
				<label class="faz-toggle">
					<input type="checkbox" data-path="iab.purpose_one_treatment">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php echo esc_html__( 'Purpose One Treatment', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php echo esc_html__( 'Enable only if your country does not require consent for Purpose 1 (Store and/or access information on a device). Most EU countries require it, so leave this off unless you are certain.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group" data-show-if="iab.enabled" style="margin-top:12px;">
				<div id="faz-gvl-status" role="status" aria-live="polite" aria-atomic="true" style="padding:10px;border-radius:6px;background:var(--faz-bg-secondary);">
					<span style="color:var(--faz-text-secondary);"><?php echo esc_html__( 'Loading GVL status...', 'faz-cookie-manager' ); ?></span>
				</div>
				<button class="faz-btn faz-btn-secondary" id="faz-gvl-update" type="button" style="margin-top:8px;"><?php echo esc_html__( 'Update GVL Now', 'faz-cookie-manager' ); ?></button>
				<div class="faz-help"><?php echo esc_html__( 'The Global Vendor List (GVL) is maintained by IAB Europe and contains all registered ad-tech vendors. It is updated automatically every week.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php echo esc_html__( 'GeoIP Database (MaxMind GeoLite2)', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-help" style="margin:0 0 12px;">
				<?php
				printf(
					/* translators: %s: URL to MaxMind signup */
					esc_html__( 'Geo-targeting lets you show the consent banner only to visitors from regulated regions (e.g. EU for GDPR, California for CCPA). It requires a MaxMind GeoLite2-Country database. %s to get a free license key.', 'faz-cookie-manager' ),
					'<a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" rel="noopener">' . esc_html__( 'Sign up at MaxMind', 'faz-cookie-manager' ) . '</a>'
				);
				?>
			</div>
			<div class="faz-form-group">
				<label><?php echo esc_html__( 'MaxMind License Key', 'faz-cookie-manager' ); ?></label>
				<input type="password" class="faz-input" data-path="geolocation.maxmind_license_key" placeholder="<?php echo esc_attr__( 'Enter your MaxMind license key', 'faz-cookie-manager' ); ?>" style="max-width:400px;">
				<div class="faz-help"><?php echo esc_html__( 'Your GeoLite2 license key from your MaxMind account. The key is stored locally and used only to download the database file.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div id="faz-geodb-status" style="margin:12px 0;padding:10px;border-radius:6px;background:var(--faz-bg-secondary);display:none;">
			</div>
			<button class="faz-btn faz-btn-secondary" id="faz-geodb-update" type="button"><?php echo esc_html__( 'Update Database', 'faz-cookie-manager' ); ?></button>
		</div>
	</div>

	<div style="margin-top:8px;">
		<button class="faz-btn faz-btn-primary" id="faz-settings-save"><?php echo esc_html__( 'Save Settings', 'faz-cookie-manager' ); ?></button>
	</div>
</div>
