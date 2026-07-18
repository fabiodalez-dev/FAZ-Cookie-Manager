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
			<h3><?php esc_html_e( 'Banner Control', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.status">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable cookie banner', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'When disabled, the cookie consent banner will not appear on your site and no scripts will be blocked.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Excluded Pages', 'faz-cookie-manager' ); ?></label>
				<textarea class="faz-textarea" data-path="banner_control.excluded_pages" rows="3" placeholder="<?php esc_attr_e( 'One per line: page ID or URL pattern like /privacy/*', 'faz-cookie-manager' ); ?>"></textarea>
				<div class="faz-help"><?php esc_html_e( 'Enter page IDs or URL patterns, one per line.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.hide_from_bots">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Hide banner from search engine bots', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Automatically detects search engine crawlers (Googlebot, Bingbot, etc.) and skips the banner for them. Improves SEO by serving cleaner HTML to crawlers.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.gtm_datalayer">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Push consent events to GTM Data Layer', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Pushes a faz_consent_update event with per-category granted/denied values to window.dataLayer after each consent action. Enable if you use Google Tag Manager.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.alternative_asset_path">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Ad-blocker compatibility mode', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Uses generic script handle names to prevent ad blockers from blocking the cookie banner. Enable if visitors report the banner not appearing.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.per_service_consent">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable per-service consent', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'When enabled, visitors can accept or reject individual services (e.g., Google Analytics, YouTube) instead of entire categories. Services detected by the scanner are shown immediately, and embedded providers blocked at runtime are revealed when the browser sees them on the page. This keeps the preference center present-aware but makes it more complex.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.per_cookie_consent">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable per-cookie consent', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Requires per-service consent. Adds a nested row for each cookie a service declares. First-party cookies that the site can write are enforced by client cleanup and the server-side shredder. Cookies set by embedded third-party services on their own domains (for example YouTube, Vimeo, Maps, social embeds) cannot be deleted individually by this site; those rows are shown as disabled and are controlled by allowing or blocking the whole embed.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.subdomain_sharing">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Share consent across subdomains', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Scope the consent cookie to your registrable domain (e.g. .example.com) so it is shared across www, shop, app, etc. Recommended only when all subdomains belong to you and are covered by the same privacy policy. Public-suffix-aware for multi-level TLDs (.co.uk, .com.au).', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.cache_compatibility">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Cache compatibility mode', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php echo wp_kses_post( __( 'Keep the page fully cacheable by LiteSpeed, QUIC.cloud, Varnish, Nginx FastCGI or WP Rocket. The plugin stops emitting <code>no-cache</code>/<code>no-store</code> headers and the <code>DONOTCACHEPAGE</code> constant for anonymous visitors, so the static HTML is cached and the banner runs entirely client-side from the consent cookie. <strong>Only enable this if your banner output does NOT vary by visitor country.</strong> With IAB TCF, geo-targeting, country-targeted banners or runtime geo-routing active, the same cached HTML would be served to every region (e.g. an EU TCF <code>gdprApplies=true</code> page reaching a US visitor) — keep this OFF in that case, or vary the cache by country at the CDN.', 'faz-cookie-manager' ) ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Cross-Domain Consent', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="consent_forwarding.enabled">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable cross-domain consent forwarding', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Share consent choices across multiple domains. When a visitor accepts or rejects cookies on one domain, the same choice is forwarded to all configured target domains via secure postMessage.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Target Domains', 'faz-cookie-manager' ); ?></label>
				<textarea class="faz-textarea" data-path="consent_forwarding.target_domains" rows="3" placeholder="<?php esc_attr_e( 'One per line: https://shop.example.com', 'faz-cookie-manager' ); ?>"></textarea>
				<div class="faz-help"><?php esc_html_e( 'Full URLs of other sites that should receive consent state. Each site must also have FAZ Cookie Manager installed. One URL per line.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Pageview Tracking', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="pageview_tracking">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable pageview and banner interaction tracking', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Tracks pageviews and banner interactions (accept, reject, settings) for the dashboard analytics. Data is aggregate-only: it sends the page URL and title with no per-visitor identifier or cookie, so the events cannot be linked across pages or sessions. Counts are stored first-party in your own database. Disable for stricter compliance.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Script Blocking', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Pages Excluded from Script Blocking', 'faz-cookie-manager' ); ?></label>
				<textarea class="faz-textarea" data-path="script_blocking.excluded_pages" rows="3" placeholder="<?php esc_attr_e( 'One per line: /checkout/* or /cart/*', 'faz-cookie-manager' ); ?>"></textarea>
				<div class="faz-help"><?php echo wp_kses_post( __( 'URL patterns where script blocking is disabled (banner still shows). One per line, supports wildcards (e.g. <code>/checkout/*</code>).', 'faz-cookie-manager' ) ); ?></div>
			</div>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Script Blocking Exceptions', 'faz-cookie-manager' ); ?></label>
				<textarea class="faz-textarea" data-path="script_blocking.whitelist_patterns" rows="3" placeholder="<?php esc_attr_e( 'One per line: googleapis.com/youtube/v3, recaptcha, my-inline-script-id', 'faz-cookie-manager' ); ?>"></textarea>
				<div class="faz-help"><?php echo wp_kses_post( __( 'Scripts that should never be blocked, even before consent. One per line. Accepts three types of pattern:<br>- <strong>URL fragment</strong> (contains <code>.</code> or <code>/</code>): matched against the script\'s <code>src</code> or related URL attribute, e.g. <code>googleapis.com/youtube/v3</code>.<br>- <strong>Script ID</strong> (no dots/slashes): matched against the <code>id</code> attribute of the script tag, e.g. <code>my-product-form-data</code>.<br>- <strong>CSS class</strong> (no dots/slashes): matched against the script\'s <code>class</code> attribute, e.g. <code>recaptcha</code>.<br>These exceptions bypass blocking entirely. Use them only for scripts that genuinely do not set tracking cookies. <strong>Be specific</strong> to avoid accidentally unblocking trackers.<br><strong>Tip:</strong> You can also add <code>class="faz-skip"</code> directly to any script tag to exclude it without adding anything here — no configuration needed.', 'faz-cookie-manager' ) ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="script_blocking.aggressive_css_url_blocking">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Advanced inline CSS URL blocking', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php echo wp_kses_post( __( 'Optional high-coverage mode for CSS-based third-party loads. The default blocker already handles server-rendered <code>&lt;style&gt;</code> tags and direct <code>HTMLStyleElement</code> updates. Enable this only if you have confirmed that a theme, page builder, or CSS-in-JS library injects blocked <code>url()</code> or <code>@import</code> rules through broader runtime channels such as <code>Element.innerHTML</code>, <code>insertAdjacentHTML</code>, <code>CharacterData</code> edits inside a style tag, or Constructable Stylesheets. This strengthens pre-consent blocking but hooks global browser APIs and may affect builders, editors, icon fonts, or CSS-in-JS libraries. Test the site before enabling in production.', 'faz-cookie-manager' ) ); ?></div>
			</div>
			<?php if ( class_exists( '\\FazCookie\\Frontend\\Frontend' ) ) : ?>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Payment gateway scripts (e-commerce)', 'faz-cookie-manager' ); ?></label>
				<div class="faz-help"><?php echo wp_kses_post( __( '<strong>Only enable a gateway if your site takes payments.</strong> A payment provider\'s SDK (PayPal, Stripe, …) can set cookies and fingerprint the visitor, so it is blocked until consent by default. Enabling a gateway here loads its <em>payment</em> scripts before consent across your site — needed for payment forms that live outside a WooCommerce checkout (Forminator, Paid Memberships Pro, Easy Digital Downloads, Give, …). This is a compliance decision and <strong>your responsibility</strong> for your jurisdiction. A real WooCommerce checkout/cart page already loads these automatically as strictly necessary; a gateway\'s marketing pixel (e.g. PayPal\'s <code>pptm.js</code>) stays blocked regardless.', 'faz-cookie-manager' ) ); ?></div>
				<?php foreach ( \FazCookie\Frontend\Frontend::payment_gateway_catalog() as $faz_gw_key => $faz_gw ) : ?>
				<label class="faz-toggle" style="margin-top:10px">
					<input type="checkbox" data-path="script_blocking.payment_gateways.<?php echo esc_attr( $faz_gw_key ); ?>">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php
						/* translators: %s: payment gateway name, e.g. PayPal. */
						printf( esc_html__( 'Allow %s payment scripts before consent', 'faz-cookie-manager' ), esc_html( $faz_gw['label'] ) );
					?></span>
				</label>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Consent Logs', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="consent_logs.status">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable consent logging', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Records each visitor\'s consent choice (accepted, rejected, or partial) for GDPR accountability. Required by Art. 7(1) GDPR to demonstrate that consent was given.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Retention Period (months)', 'faz-cookie-manager' ); ?></label>
				<input type="number" class="faz-input faz-input-sm" data-path="consent_logs.retention" value="12" min="1" max="120" style="width:120px;">
				<div class="faz-help"><?php esc_html_e( 'How long consent records are kept before automatic deletion. Most DPAs recommend 12 months. Logs older than this period are purged daily.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Scanner', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Max Pages to Scan', 'faz-cookie-manager' ); ?></label>
				<input type="number" class="faz-input faz-input-sm" data-path="scanner.max_pages" value="100" min="1" style="width:120px;">
				<div class="faz-help"><?php esc_html_e( 'Maximum number of pages the cookie scanner will crawl. Higher values find more cookies but take longer. 100 pages is sufficient for most sites.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="scanner.debug_mode">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Scanner Debug Mode', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'When enabled, the scanner logs every categorization decision. Download logs from the Cookies page.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Automatic Scanning', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="scanner.auto_scan">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable automatic cookie scanning', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Automatically scan your site for new cookies on a schedule. You will receive an email notification if new uncategorized cookies are found.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Scan Frequency', 'faz-cookie-manager' ); ?></label>
				<select class="faz-select" data-path="scanner.scan_frequency" style="width:auto;max-width:200px;">
					<option value="daily"><?php esc_html_e( 'Daily', 'faz-cookie-manager' ); ?></option>
					<option value="weekly"><?php esc_html_e( 'Weekly', 'faz-cookie-manager' ); ?></option>
					<option value="monthly"><?php esc_html_e( 'Monthly', 'faz-cookie-manager' ); ?></option>
				</select>
				<div class="faz-help"><?php esc_html_e( 'How often the scanner runs automatically. Weekly is recommended for most sites.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Microsoft Consent APIs', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="microsoft.uet_consent_mode">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Microsoft UET Consent Mode', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Signals consent status to Microsoft Advertising (Bing Ads) via the UET tag. Enable if you use Microsoft Advertising and need to respect consent for ad tracking.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="microsoft.clarity_consent">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Microsoft Clarity Consent API', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Signals consent status to Microsoft Clarity (heatmaps and session recordings). Enable if you use Clarity and want it to pause tracking until consent is given.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Age Verification', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="age_gate.enabled">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Require age verification for consent', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Under GDPR Art. 8, children below the minimum age cannot give valid consent for data processing. When enabled, visitors must confirm they meet the minimum age before accepting optional cookies.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Minimum Age', 'faz-cookie-manager' ); ?></label>
				<input type="number" class="faz-input faz-input-sm" data-path="age_gate.min_age" min="13" max="18" style="width:80px;">
				<div class="faz-help"><?php esc_html_e( 'GDPR default is 16. Some EU member states allow 13-15. Check your local law.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'IAB TCF', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="iab.enabled">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable IAB TCF v2.3', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Enables the IAB Transparency & Consent Framework. Required if you work with ad-tech vendors that need a standardised TC String for programmatic advertising in the EU.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group" data-show-if="iab.enabled" style="margin-top:12px;">
				<label for="faz-iab-publisher-cc" style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'Publisher Country Code', 'faz-cookie-manager' ); ?></label>
				<input type="text" id="faz-iab-publisher-cc" data-path="iab.publisher_cc" maxlength="2" style="width:60px;text-transform:uppercase;" placeholder="IT">
				<div class="faz-help"><?php esc_html_e( 'ISO 3166-1 alpha-2 code of the publisher\'s country (e.g. IT, DE, FR). Used in the TCF consent string.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group" data-show-if="iab.enabled" style="margin-top:12px;">
				<label for="faz-iab-cmp-id" style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'CMP ID', 'faz-cookie-manager' ); ?></label>
				<input type="number" id="faz-iab-cmp-id" class="faz-input faz-input-sm" data-path="iab.cmp_id" min="0" max="4095" style="width:120px;" placeholder="0">
				<div class="faz-help"><?php echo wp_kses_post( __( 'Your registered IAB CMP ID (<a href="https://iabeurope.eu/cmp-list/" target="_blank" rel="noopener noreferrer">IAB CMP List</a>). With ID&nbsp;0 the banner and cookie blocking work normally, but ad-tech vendors will ignore the TC String. Google Consent Mode v2 works regardless of CMP registration.', 'faz-cookie-manager' ) ); ?></div>
			</div>
			<div class="faz-form-group" data-show-if="iab.enabled" style="margin-top:12px;">
				<label class="faz-toggle">
					<input type="checkbox" data-path="iab.purpose_one_treatment">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Purpose One Treatment', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Set to true if Purpose 1 consent was NOT disclosed (e.g. publisher in a country where Purpose 1 is not required).', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group" data-show-if="iab.enabled" style="margin-top:12px;">
				<div id="faz-gvl-status" role="status" aria-live="polite" aria-atomic="true" style="padding:10px;border-radius:6px;background:var(--faz-bg-secondary);">
					<span style="color:var(--faz-text-secondary);"><?php esc_html_e( 'Loading GVL status...', 'faz-cookie-manager' ); ?></span>
				</div>
				<button class="faz-btn faz-btn-secondary" id="faz-gvl-update" type="button" style="margin-top:8px;"><?php esc_html_e( 'Update GVL Now', 'faz-cookie-manager' ); ?></button>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Geo-Targeting', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="geolocation.geo_targeting">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable geo-targeted banner display', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Show the cookie banner only to visitors from specific regions. Requires a MaxMind GeoLite2 database (configured below) or Cloudflare CF-IPCountry header.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group" data-show-if="geolocation.geo_targeting">
				<label><?php esc_html_e( 'Target Regions', 'faz-cookie-manager' ); ?></label>
				<div id="faz-geo-regions" style="display:flex;flex-wrap:wrap;gap:8px;">
					<?php
					$region_labels = array(
						'eu' => __( 'EU / EEA', 'faz-cookie-manager' ),
						'uk' => __( 'United Kingdom', 'faz-cookie-manager' ),
						'us' => __( 'United States', 'faz-cookie-manager' ),
						'ca' => __( 'Canada', 'faz-cookie-manager' ),
						'br' => __( 'Brazil', 'faz-cookie-manager' ),
						'au' => __( 'Australia', 'faz-cookie-manager' ),
						'jp' => __( 'Japan', 'faz-cookie-manager' ),
						'ch' => __( 'Switzerland', 'faz-cookie-manager' ),
					);
					foreach ( $region_labels as $code => $label ) :
					?>
					<label style="display:flex;align-items:center;gap:4px;padding:4px 10px;background:var(--faz-bg-secondary);border-radius:6px;font-size:13px;cursor:pointer;">
						<input type="checkbox" data-path="geolocation.target_regions" value="<?php echo esc_attr( $code ); ?>">
						<?php echo esc_html( $label ); ?>
					</label>
					<?php endforeach; ?>
				</div>
				<div class="faz-help"><?php esc_html_e( 'Select which regions should see the cookie banner. Visitors from other regions will not see it (if "Hide banner" is selected below).', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group" data-show-if="geolocation.geo_targeting">
				<label><?php esc_html_e( 'Non-target visitors', 'faz-cookie-manager' ); ?></label>
				<select class="faz-select" data-path="geolocation.default_behavior" style="width:auto;max-width:280px;">
					<option value="show_banner"><?php esc_html_e( 'Show banner anyway (safest)', 'faz-cookie-manager' ); ?></option>
					<option value="no_banner"><?php esc_html_e( 'Hide banner (scripts load normally)', 'faz-cookie-manager' ); ?></option>
				</select>
				<div class="faz-help"><?php esc_html_e( 'What happens when a visitor is from outside the target regions. "Show banner anyway" is the safest option and recommended for sites with global audiences.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'GeoIP Database (MaxMind GeoLite2)', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<p style="margin:0 0 12px;color:var(--faz-text-secondary);">
				<?php echo wp_kses_post( __( 'Geo-targeting requires a MaxMind GeoLite2 database. <a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" rel="noopener">Get a free license key</a>.', 'faz-cookie-manager' ) ); ?>
			</p>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Database edition', 'faz-cookie-manager' ); ?></label>
				<select class="faz-select" data-path="geolocation.geolite2_edition" style="width:auto;max-width:340px;">
					<option value="country"><?php esc_html_e( 'Country &#8212; country-level only (recommended)', 'faz-cookie-manager' ); ?></option>
					<option value="city"><?php esc_html_e( 'City &#8212; adds region / state detection', 'faz-cookie-manager' ); ?></option>
				</select>
				<div class="faz-help">
					<?php echo wp_kses_post( __( '<strong>Country</strong> (~10 MB) resolves the visitor&#8217;s country only &#8212; enough for country-based banner targeting and most per-jurisdiction rules. <strong>City</strong> (~60&#8211;110 MB) additionally resolves the region / province / state, which a few rules need: sub-national regimes such as <em>Quebec Law 25</em> apply only inside one region of a country, so without City (or a Cloudflare <code>CF-Region-Code</code> header) the plugin can only see &#8220;Canada&#8221; and cannot route that visitor to the Quebec ruleset. Choose City only if you rely on region-level routing &#8212; it is a much larger download. Changing this and clicking &#8220;Update Database&#8221; replaces the installed database.', 'faz-cookie-manager' ) ); ?>
				</div>
			</div>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'MaxMind License Key', 'faz-cookie-manager' ); ?></label>
				<input type="password" class="faz-input" data-path="geolocation.maxmind_license_key" placeholder="<?php esc_attr_e( 'Enter your MaxMind license key', 'faz-cookie-manager' ); ?>" style="max-width:400px;">
			</div>
			<div id="faz-geodb-status" style="margin:12px 0;padding:10px;border-radius:6px;background:var(--faz-bg-secondary);display:none;">
			</div>
			<button class="faz-btn faz-btn-secondary" id="faz-geodb-update" type="button"><?php esc_html_e( 'Update Database', 'faz-cookie-manager' ); ?></button>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Data Management', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="general.remove_data_on_uninstall">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Remove all data on uninstall', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help" style="color:var(--faz-danger);"><?php esc_html_e( 'When enabled, deleting the plugin will permanently remove ALL data: cookies, categories, consent logs, pageviews, banner settings, and scan history. Keep this OFF if you plan to reinstall or update the plugin.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<?php if ( \FazCookie\Includes\Integrations\Paid_Memberships_Pro::is_pmp_active() ) : ?>
	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Paid Memberships Pro integration', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<p style="margin:0 0 12px;color:var(--faz-text-secondary);">
				<?php echo wp_kses_post( __( 'Implement a "Pay-or-Accept" model: logged-in members on selected PMP levels skip the cookie banner and have consent <strong>programmatically recorded as granted for all categories</strong> via a server-side cookie. Marketing, analytics, functional and other non-necessary scripts will run for them without an explicit banner interaction. Free visitors continue to see the standard banner and consent flow.', 'faz-cookie-manager' ) ); ?>
			</p>
			<p style="margin:0 0 12px;padding:10px 12px;border-radius:6px;background:var(--faz-bg-secondary);color:var(--faz-text-secondary);font-size:13px;">
				<strong><?php esc_html_e( 'Legal note:', 'faz-cookie-manager' ); ?></strong>
				<?php esc_html_e( 'Because this option grants consent on behalf of the member (it does not merely hide the banner), you must disclose it clearly in your Terms of Service, Privacy Policy, and at the point of subscription so the membership fee is understood as a genuine alternative to giving consent (EDPB Opinion 08/2024). Members must remain able to revoke or adjust their consent at any time via the standard revisit widget.', 'faz-cookie-manager' ); ?>
			</p>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="integrations.paid_memberships_pro.enabled">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable PMP integration', 'faz-cookie-manager' ); ?></span>
				</label>
			</div>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Exempt membership level IDs', 'faz-cookie-manager' ); ?></label>
				<input type="text" class="faz-input" data-path="integrations.paid_memberships_pro.exempt_levels" placeholder="<?php esc_attr_e( 'e.g. 2, 3, 5', 'faz-cookie-manager' ); ?>" style="max-width:300px;">
				<div class="faz-help">
					<?php echo wp_kses_post( __( 'Comma-separated PMP level IDs whose members should be exempted. Find level IDs in <strong>Memberships → Settings → Levels</strong>. Leave empty to disable exemption.', 'faz-cookie-manager' ) ); ?>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Force re-consent', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<p style="margin:0 0 12px;color:var(--faz-text-secondary);">
				<?php echo wp_kses_post( __( 'Show the cookie banner again to all returning visitors. Useful when you change which cookies or services are used on your site (e.g. new AdSense tags, added analytics) and want prior visitors to renew their consent before those services run.', 'faz-cookie-manager' ) ); ?>
			</p>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Current consent revision', 'faz-cookie-manager' ); ?></label>
				<div style="display:flex;align-items:center;gap:12px;">
					<input type="number" class="faz-input faz-input-sm" data-path="general.consent_revision" readonly disabled style="width:100px;background:var(--faz-bg-secondary);">
					<button class="faz-btn faz-btn-secondary" id="faz-invalidate-consents" type="button">
						<?php esc_html_e( 'Invalidate all consents', 'faz-cookie-manager' ); ?>
					</button>
				</div>
				<div class="faz-help"><?php esc_html_e( 'Visitors whose stored consent has a lower revision will see the banner again on their next visit. This does not affect the current page load.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

</div>

<div style="margin-top:8px;">
	<button class="faz-btn faz-btn-primary" id="faz-settings-save"><?php esc_html_e( 'Save Settings', 'faz-cookie-manager' ); ?></button>
</div>
