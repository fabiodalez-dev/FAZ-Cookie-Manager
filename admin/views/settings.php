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
				<div class="faz-help"><?php esc_html_e( 'Automatically detects search engine crawlers (Googlebot, Bingbot, etc.) and skips the banner for them. Improves SEO by serving cleaner HTML to crawlers. Note: with Cache Compatibility Mode enabled the bot-skip is bypassed — cached pages must stay identical for every visitor, so bots receive the banner too.', 'faz-cookie-manager' ); ?></div>
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
				<div class="faz-help"><?php esc_html_e( 'Serves every frontend bundle (banner, accessibility, GCM, TCF, WP Consent API and Microsoft consent) through generic inline handles so plugin-URL filter rules cannot block only part of the consent runtime.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.adblock_resilience">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Keep banner visible against ad blockers', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( "If an ad blocker's cosmetic filter list hides the consent banner (some lists hide any element whose name contains \"cookie\" or \"consent\"), this re-asserts the banner's visibility a few times over the first seconds after it loads, so the legally required notice is not suppressed even when the filter list is applied late. It never forces interaction and respects a visitor who has already accepted, rejected or dismissed. This is different from Ad-blocker compatibility mode above, which stops the banner script itself from being blocked.", 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.per_service_consent">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Enable per-service consent', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'When enabled, visitors can accept or reject individual services (e.g., Google Analytics, YouTube) instead of entire categories. Services detected by the scanner are shown immediately, and embedded providers blocked at runtime are revealed when the browser sees them on the page. This keeps the preference center present-aware but makes it more complex.', 'faz-cookie-manager' ); ?></div>
			</div>
			<?php
			/*
			 * Gated on its parent because Settings::sanitize() forces
			 * per_cookie_consent off whenever per_service_consent is off. Left
			 * ungated, the admin could tick a nested-cookie mode that the server
			 * silently discarded, get the generic "Settings saved successfully."
			 * toast, and keep looking at an enabled toggle for the rest of the
			 * session — the one thing a settings screen must never do.
			 *
			 * Hiding alone DOES keep it out of the payload: FAZ.serializeForm()
			 * skips any [data-path] whose [data-show-if] wrapper computes to
			 * display:none (faz-admin.js). An earlier version of this comment
			 * claimed the opposite. Corrected rather than deleted, because a
			 * comment that misdescribes the serializer is how somebody later adds
			 * a redundant guard, or removes a load-bearing one.
			 *
			 * data-clear-when-hidden is still needed, for a different reason: the
			 * box keeps its ticked state while hidden, so re-enabling the parent
			 * would restore a choice the server had already discarded. See
			 * settings.js applyShowIf().
			 */
			?>
			<div class="faz-form-group" data-show-if="banner_control.per_service_consent" data-clear-when-hidden>
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
				<div class="faz-help"><?php echo wp_kses_post( __( 'Keep a visitor-invariant page fully cacheable when jurisdiction enforcement is off and one banner law applies to everyone. When per-country enforcement is on, use the Cache-safe jurisdiction bootstrap under Jurisdiction &amp; Geo-routing instead. Developers can override the runtime with the <code>faz_geo_ruleset_runtime</code> filter. When this compatibility mode is active, it also pauses server-side A/B splitting and bot-specific output.', 'faz-cookie-manager' ) ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="banner_control.ab_test.status">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'A/B test banner variants', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php echo wp_kses_post( __( 'Run two or more of your existing banners at the same time. A visitor is randomly shown one eligible variant without creating an experiment cookie before consent; after a choice, the banner slug in the consent record keeps the assignment stable. Acceptance rate per variant is reported on the Dashboard. Variants must use the same privacy-law and Do-Not-Sell model; country targeting is always respected, so the experiment can vary presentation but never a visitor\'s legal regime. <strong>Requires Cache Compatibility Mode above to be OFF</strong>, because a per-visitor split cannot run behind full-page caching.', 'faz-cookie-manager' ) ); ?></div>
			</div>
			<div class="faz-form-group" data-show-if="banner_control.ab_test.status">
				<label><?php esc_html_e( 'Variants in the test group', 'faz-cookie-manager' ); ?></label>
				<div class="faz-help"><?php esc_html_e( 'Select two or more active banners with the same consent model. Incompatible or country-ineligible banners are ignored for that visitor.', 'faz-cookie-manager' ); ?></div>
				<div id="faz-abtest-variants" class="faz-abtest-variants">
					<p style="color:var(--faz-text-muted);"><?php esc_html_e( 'Loading banners…', 'faz-cookie-manager' ); ?></p>
				</div>
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
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Pageview and interaction retention (months)', 'faz-cookie-manager' ); ?></label>
				<input type="number" class="faz-input faz-input-sm" data-path="pageviews.retention" value="6" min="0" max="120" style="width:120px;">
				<div class="faz-help"><?php esc_html_e( 'How long aggregate pageview and banner-interaction records are kept before automatic deletion. Enter 0 to disable automatic deletion; choose and document a period appropriate to your analytics purpose. Default is 6 months.', 'faz-cookie-manager' ); ?></div>
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
				<div class="faz-help"><?php echo wp_kses_post( __( 'Scripts that should never be blocked, even before consent. One per line. Accepts three types of pattern:<br>- <strong>URL fragment</strong> (contains <code>.</code> or <code>/</code>): matched against the script\'s <code>src</code> or related URL attribute, e.g. <code>googleapis.com/youtube/v3</code>.<br>- <strong>Script ID</strong> (no dots/slashes): matched against the <code>id</code> attribute of the script tag, e.g. <code>my-product-form-data</code>.<br>- <strong>CSS class</strong> (no dots/slashes): matched against the script\'s <code>class</code> attribute, e.g. <code>recaptcha</code>.<br>- <strong>How IDs and classes match:</strong> whole words, plus their hyphen or underscore children. <code>recaptcha</code> matches <code>recaptcha</code>, <code>recaptcha-badge</code> and <code>recaptcha_frame</code>, but not <code>myrecaptcha</code>. Ending a pattern with <code>-</code> or <code>_</code> (e.g. <code>wp-</code>) matches <em>every</em> word starting with it, which is much broader — prefer the exact ID or class wherever you can.<br>These exceptions bypass blocking entirely. Use them only for scripts that genuinely do not set tracking cookies. <strong>Be specific</strong> to avoid accidentally unblocking trackers.<br><strong>Tip:</strong> You can also add <code>class="faz-skip"</code> directly to any script tag to exclude it without adding anything here — no configuration needed.', 'faz-cookie-manager' ) ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="script_blocking.aggressive_css_url_blocking">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Advanced inline CSS URL blocking', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php echo wp_kses_post( __( 'Optional high-coverage mode for CSS-based third-party loads. The default blocker already handles server-rendered <code>&lt;style&gt;</code> tags and direct <code>HTMLStyleElement</code> updates. Enable this only if you have confirmed that a theme, page builder, or CSS-in-JS library injects blocked <code>url()</code> or <code>@import</code> rules through broader runtime channels such as <code>Element.innerHTML</code>, <code>insertAdjacentHTML</code>, <code>CharacterData</code> edits inside a style tag, or Constructable Stylesheets. This strengthens pre-consent blocking but hooks global browser APIs and may affect builders, editors, icon fonts, or CSS-in-JS libraries. Test the site before enabling in production.', 'faz-cookie-manager' ) ); ?></div>
			</div>
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="script_blocking.block_server_cookies">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Block non-consented cookies emitted by PHP', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php echo wp_kses_post( __( '<strong>Advanced, off by default.</strong> Filters outgoing <code>Set-Cookie</code> headers from pages, AJAX, REST and redirects, including HttpOnly cookies that JavaScript cannot remove. Necessary, WordPress-internal, security and checkout cookies are preserved, and deletion headers always pass. Enable only after testing login, forms, cart and checkout; unknown cookies fail permissive until the scanner classifies them.', 'faz-cookie-manager' ) ); ?></div>
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
				<label><?php esc_html_e( 'Consent log retention (months)', 'faz-cookie-manager' ); ?></label>
				<input type="number" class="faz-input faz-input-sm" data-path="consent_logs.retention" value="12" min="1" max="120" style="width:120px;">
				<div class="faz-help"><?php esc_html_e( 'How long consent records are kept before automatic deletion. Choose a documented period appropriate to your accountability obligations. Logs older than this period are purged daily.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Data Subject Requests', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Data subject request retention (months)', 'faz-cookie-manager' ); ?></label>
				<input type="number" class="faz-input faz-input-sm" data-path="dsar.retention" value="24" min="1" max="120" style="width:120px;">
				<div class="faz-help"><?php esc_html_e( 'How long completed data-subject request records are kept before automatic deletion. Keep a period that supports your ability to demonstrate and manage requests.', 'faz-cookie-manager' ); ?></div>
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
				<label><?php esc_html_e( 'Static public IP (optional)', 'faz-cookie-manager' ); ?></label>
				<input type="text" class="faz-input" data-path="scanner.static_ip" placeholder="203.0.113.10" style="max-width:320px;">
				<div class="faz-help"><?php esc_html_e( 'Pin every server-side scanner request — sitemap discovery and page fetches — to this site IP while preserving the hostname, HTTPS certificate validation and SNI. Only public IPv4/IPv6 addresses are accepted; requires the WordPress cURL transport. Leave blank to use DNS.', 'faz-cookie-manager' ); ?></div>
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
				<div class="faz-help"><?php esc_html_e( 'Under GDPR Art. 8, children below the minimum age cannot give valid consent for data processing. When enabled, the visitor sees an age-confirmation checkbox directly above the Accept/Reject buttons; it gates only the Accept path — Reject stays available and is never blocked, and the checkbox is never pre-ticked.', 'faz-cookie-manager' ); ?></div>
				<div class="faz-help"><?php esc_html_e( 'This is a self-declared age affirmation only. It is NOT a substitute for verifying parental consent for under-age children under GDPR Art. 8(2), which requires separate reasonable-efforts verification this feature does not provide.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Minimum Age', 'faz-cookie-manager' ); ?></label>
				<input type="number" class="faz-input faz-input-sm" data-path="age_gate.min_age" min="13" max="18" style="width:80px;">
				<div class="faz-help"><?php esc_html_e( 'GDPR default is 16. Some EU member states allow 13-15 (e.g. Italy 14, Spain 14, France 15, Germany 16). Check your local law.', 'faz-cookie-manager' ); ?></div>
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
			<h3><?php esc_html_e( 'Jurisdiction & Geo-routing', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="geolocation.geo_targeting">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Apply jurisdiction rules by visitor location', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Recommended and enabled by default. Detect each visitor\'s location to enforce the matching jurisdiction rules and mandatory controls, and optionally limit banner display to selected regions. Turning this off means the law saved on the active banner applies to every visitor; for example, a CCPA banner will no longer gain GDPR blocking for an EEA visitor. Location requires a MaxMind GeoLite2 database (configured below), or a trusted country signal such as Cloudflare CF-IPCountry.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group" data-show-if="geolocation.geo_targeting">
				<label class="faz-toggle">
					<input type="checkbox" data-path="geolocation.cache_geo_bootstrap">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Cache-safe jurisdiction bootstrap', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Allows compatible full-page caches and CDNs to share one strict GDPR shell. The browser resolves the live jurisdiction through a no-store endpoint before mounting the banner or releasing optional scripts. If the configuration is unsupported or the endpoint fails, FAZ keeps the stricter shell and preserves the normal page-cache bypass.', 'faz-cookie-manager' ); ?></div>
				<div id="faz-geo-bootstrap-status" role="status" aria-live="polite" aria-atomic="true" style="margin-top:8px;padding:10px;border-radius:6px;background:var(--faz-bg-secondary);">
					<span style="color:var(--faz-text-secondary);"><?php esc_html_e( 'Checking cache-safe bootstrap readiness...', 'faz-cookie-manager' ); ?></span>
				</div>
				<div class="faz-help"><?php esc_html_e( 'Currently excluded: AMP, IAB TCF, country-based language fallback, country-targeted banner rows, hiding the banner outside selected regions, and custom country-dependent output. Excluded configurations remain protected but bypass full-page caching.', 'faz-cookie-manager' ); ?></div>
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
						'za' => __( 'South Africa', 'faz-cookie-manager' ),
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
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Privacy Request Retention Period (months)', 'faz-cookie-manager' ); ?></label>
				<input type="number" class="faz-input faz-input-sm" data-path="dsar.retention" value="24" min="1" max="120" style="width:120px;">
				<div class="faz-help"><?php esc_html_e( 'How long completed privacy (DSAR) request records are kept before automatic deletion. They are the evidence that a data-subject request was answered, so a minimum of 12–24 months is recommended. Default is 24 months.', 'faz-cookie-manager' ); ?></div>
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
				<?php echo wp_kses_post( __( 'Offer selected Paid Memberships Pro levels a privacy-preserving membership alternative: logged-in members skip the cookie banner, necessary storage remains available, and <strong>all optional purposes stay denied</strong> until the member explicitly changes them in the preference center. Other visitors continue to use the standard consent flow.', 'faz-cookie-manager' ) ); ?>
			</p>
			<p style="margin:0 0 12px;padding:10px 12px;border-radius:6px;background:var(--faz-bg-secondary);color:var(--faz-text-secondary);font-size:13px;">
				<strong><?php esc_html_e( 'Legal note:', 'faz-cookie-manager' ); ?></strong>
				<?php esc_html_e( 'This option does not grant consent on the member\'s behalf. Disclose the privacy-preserving membership alternative in your Terms and Privacy Policy, keep the paid service genuinely equivalent, and leave the standard preference control available so members can change any optional purpose at any time. Assess your specific model with qualified counsel; EDPB Opinion 08/2024 does not make every consent-or-pay implementation lawful.', 'faz-cookie-manager' ); ?>
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
			<h3><?php esc_html_e( 'Footer legal links', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-form-group">
				<label class="faz-toggle">
					<input type="checkbox" data-path="legal_links.enabled">
					<span class="faz-toggle-track"></span>
					<span class="faz-toggle-label"><?php esc_html_e( 'Show legal links in the footer', 'faz-cookie-manager' ); ?></span>
				</label>
				<div class="faz-help"><?php esc_html_e( 'Off by default. When enabled, the pages you select below are printed as a small navigation block in your theme footer (via wp_footer). The markup is identical for every visitor — it does not depend on consent, login state or country — so it stays safe to serve from a page cache.', 'faz-cookie-manager' ); ?></div>
			</div>
			<div class="faz-form-group">
				<label><?php esc_html_e( 'Pages to link', 'faz-cookie-manager' ); ?></label>
				<?php
				// Server-rendered on purpose: the list is static for the request,
				// so saveSettings() never has to guard against an async list that
				// has not loaded yet (unlike the A/B-test variant checkboxes).
				// NOTE: these inputs deliberately carry NO data-path — FAZ.serializeForm
				// must skip them; settings.js collects them through its own serializer.
				//
				// 'number' bounds both the query and the DOM: without it a site with
				// thousands of published pages pays for an unbounded query and then
				// renders two inputs per page into this screen. 200 is far above what
				// a legal-links footer needs (the stored list is capped at 20) while
				// still covering ordinary sites in full.
				//
				// Stored selections are rendered FIRST even when they are unpublished,
				// deleted, or outside this query's first 200 rows. Every persisted link
				// therefore has a visible checkbox the operator can remove.
				$faz_stored_settings = get_option( 'faz_settings', array() );
				$faz_stored_items    = isset( $faz_stored_settings['legal_links']['link_items'] ) && is_array( $faz_stored_settings['legal_links']['link_items'] )
					? $faz_stored_settings['legal_links']['link_items']
					: array();
				$faz_legal_pages = get_pages(
					array(
						'post_status' => 'publish',
						'number'      => 200,
					)
				);
				$faz_legal_rows = array();
				$faz_selected_ids = array();
				foreach ( $faz_stored_items as $faz_stored_item ) {
					$faz_page_id = isset( $faz_stored_item['page_id'] ) ? absint( $faz_stored_item['page_id'] ) : 0;
					if ( ! $faz_page_id || isset( $faz_selected_ids[ $faz_page_id ] ) ) {
						continue;
					}
					$faz_selected_ids[ $faz_page_id ] = true;
					$faz_post = get_post( $faz_page_id );
					if ( ! $faz_post || 'page' !== $faz_post->post_type ) {
						/* translators: %d: WordPress page ID. */
						$faz_title = sprintf( __( 'Page #%d (unavailable)', 'faz-cookie-manager' ), $faz_page_id );
					} else {
						if ( '' !== trim( (string) $faz_post->post_title ) ) {
							$faz_title = $faz_post->post_title;
						} else {
							/* translators: %d: WordPress page ID. */
							$faz_title = sprintf( __( 'Page #%d (untitled)', 'faz-cookie-manager' ), $faz_page_id );
						}
						if ( 'publish' !== $faz_post->post_status ) {
							$faz_status = get_post_status_object( $faz_post->post_status );
							$faz_title .= sprintf(
								' (%s)',
								$faz_status && isset( $faz_status->label ) ? $faz_status->label : $faz_post->post_status
							);
						}
					}
					$faz_legal_rows[] = array(
						'id'       => $faz_page_id,
						'title'    => $faz_title,
						'label'    => isset( $faz_stored_item['label'] ) ? (string) $faz_stored_item['label'] : '',
						'selected' => true,
					);
				}
				foreach ( $faz_legal_pages as $faz_legal_page ) {
					if ( isset( $faz_selected_ids[ $faz_legal_page->ID ] ) ) {
						continue;
					}
					$faz_legal_rows[] = array(
						'id'       => $faz_legal_page->ID,
						'title'    => $faz_legal_page->post_title,
						'label'    => '',
						'selected' => false,
					);
				}
				?>
				<?php if ( empty( $faz_legal_rows ) ) : ?>
					<div class="faz-help"><?php esc_html_e( 'No published pages yet. Publish your Cookie Policy or Privacy Policy page first.', 'faz-cookie-manager' ); ?></div>
				<?php else : ?>
					<div id="faz-legal-links-pages" style="max-height:260px;overflow:auto;padding:8px;border-radius:6px;background:var(--faz-bg-secondary);">
						<?php foreach ( $faz_legal_rows as $faz_legal_row ) : ?>
							<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
								<label class="faz-checkbox" style="flex:1;min-width:0;">
									<input type="checkbox" class="faz-legal-link-page" value="<?php echo esc_attr( $faz_legal_row['id'] ); ?>" <?php checked( $faz_legal_row['selected'] ); ?>>
									<span style="margin-left:6px;"><?php echo esc_html( $faz_legal_row['title'] ); ?></span>
								</label>
								<input type="text" class="faz-input faz-input-sm faz-legal-link-label" data-page-id="<?php echo esc_attr( $faz_legal_row['id'] ); ?>" value="<?php echo esc_attr( $faz_legal_row['label'] ); ?>" placeholder="<?php esc_attr_e( 'Custom label (optional)', 'faz-cookie-manager' ); ?>" style="max-width:220px;">
							</div>
						<?php endforeach; ?>
					</div>
					<div class="faz-help"><?php esc_html_e( 'Selected links appear first in their saved order. Leave the label empty to use the page title. Unpublished or unavailable selections stay visible here so you can remove them; they are never printed in the footer.', 'faz-cookie-manager' ); ?></div>
				<?php endif; ?>
			</div>
		</div>
	</div>

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
