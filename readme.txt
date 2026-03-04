=== FAZ Cookie Manager ===
Contributors: fabiodalez
Tags: cookie, gdpr, ccpa, consent, privacy
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The only cookie consent plugin you need. 100% free, zero cloud dependencies, no subscriptions. Full GDPR, CCPA, ePrivacy, and IAB TCF v2.3 compliance out of the box.

== Description ==

**Tired of cookie consent plugins that lock essential features behind paywalls, require cloud accounts, or send your visitors' data to third-party servers?**

FAZ Cookie Manager is a WordPress plugin that gives you everything you need to make your site compliant with international privacy regulations -- completely free, with no strings attached.

No account to create. No cloud service to connect. No "premium" plan to unlock basic features like consent logging or geo-targeting. Everything runs on your own server, and you own all your data.

= Why FAZ Cookie Manager? =

Most cookie consent plugins follow the same pattern: a free version with crippled features, and a paid tier starting at $10-50/month that unlocks what you actually need (cookie scanning, consent logs, Google Consent Mode, IAB TCF). FAZ Cookie Manager breaks that model:

* **Cookie scanner** -- Scans your site directly from your browser. No external service, no API limits, no waiting.
* **Consent logging with CSV export** -- Every consent is recorded locally in your database. Export anytime for audits.
* **Google Consent Mode v2** -- Sends all 7 consent signals to Google tags. No premium required.
* **IAB TCF v2.3** -- Full Transparency and Consent Framework support, built in.
* **Geo-targeting** -- Show banners only to visitors from regulated regions (EU, California, etc.).
* **180+ languages** -- Translate every string in the banner, or use one of the built-in translations.
* **Script blocking** -- Tag any script with `data-faz-tag` to block it until the right category is accepted.
* **Microsoft UET/Clarity** -- Consent integration for Microsoft advertising and analytics tools.
* **Revisit consent widget** -- Floating button lets visitors change their preferences anytime.
* **Fully accessible** -- Keyboard navigation (Tab, Enter, Escape), WCAG compliant, mobile responsive.

= Compliance covered =

* **GDPR** (EU General Data Protection Regulation) -- Opt-in consent, granular categories, right to withdraw
* **CCPA / CPRA** (California Consumer Privacy Act) -- "Do Not Sell or Share" opt-out link
* **ePrivacy Directive** (EU Cookie Law) -- No cookies before consent, script blocking
* **Italian Garante Privacy** -- Cookie expiry capped at 6 months, proper consent recording
* **EDPB Guidelines** -- Scroll does not equal consent, no pre-checked categories, equal button prominence
* **LGPD** (Brazil General Data Protection Law) -- Consent-based model
* **POPIA** (South Africa Protection of Personal Information Act) -- Opt-in consent

= How it works =

1. Install and activate -- the cookie banner appears immediately with sensible defaults
2. Scan your site to detect cookies automatically
3. Customize the banner design, text, and colors to match your brand
4. Enable Google Consent Mode or IAB TCF if you use advertising tools
5. Monitor consent analytics on the dashboard

All data stays on your WordPress database. No tracking, no cloud dependencies. The only external calls are to GitHub (Open Cookie Database updates) and ip-api.com (optional geolocation fallback).

== Installation ==

1. Upload the `faz-cookie-manager` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **FAZ Cookie** in the admin sidebar to configure your banner
4. Click **Scan Site** on the Cookies page to detect cookies automatically
5. Customize the banner design, text, and regulation type on the Cookie Banner page

== Frequently Asked Questions ==

= Does this plugin require a cloud account or subscription? =

No. FAZ Cookie Manager is 100% self-hosted. There are no cloud services, no API keys, no accounts to create, and no paid tiers. All features are available from day one.

= Is it really free? What's the catch? =

It's free and open source (GPL-3.0). There are no premium upgrades, no feature gates, and no upsells. The plugin is a fork of CookieYes v3.4.0 with all premium features unlocked and all cloud dependencies removed.

= Is it compatible with Google Consent Mode v2? =

Yes. The plugin sends all 7 consent signals (`ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`, `functionality_storage`, `personalization_storage`, `security_storage`) and supports Google Additional Consent Mode (GACM) for ad technology providers.

= Does the banner block cookies before consent? =

Yes. Any script tagged with `data-faz-tag="category-name"` is blocked until the visitor grants consent for that category. This ensures full ePrivacy Directive compliance.

= How does the cookie scanner work? =

Go to **FAZ Cookie > Cookies** and click **Scan Site**. The scanner runs in your browser using iframes, crawling your site's pages to detect all cookies. Choose from quick scan (10 pages), standard (100), deep (1000), or full scan. No external service involved.

= Can I log consent for GDPR accountability? =

Yes. Every consent action (accept, reject, customize) is recorded in a local database table with timestamp, consent ID, categories chosen, anonymized IP, and page URL. Export to CSV anytime from the Consent Logs page.

= Does it support multiple languages? =

Yes. The Languages page lets you select from 180+ available languages. The banner text is automatically translated based on the visitor's browser language, and you can customize every string.

= Can users change their consent after accepting? =

Yes. A floating revisit widget appears on every page, letting visitors reopen the preference center and change their choices at any time.

= Is the banner accessible? =

Yes. The banner supports full keyboard navigation (Tab, Enter, Escape), proper ARIA labels, and is responsive down to 375px viewports. Buttons have equal visual prominence to avoid dark patterns.

= Does it work with caching plugins? =

Yes. The consent banner is rendered via JavaScript from a cached template, so it works with all major caching plugins (WP Super Cache, W3 Total Cache, LiteSpeed Cache, etc.).

== Screenshots ==

1. **Cookie consent banner** -- GDPR-compliant banner with Customize, Reject All, and Accept All buttons. Appears on first visit, fully responsive.
2. **Dashboard** -- Analytics overview with pageviews chart, consent distribution (accept/reject rates), and quick links to all sections.
3. **Cookie Banner editor** -- Customize layout, position, theme, regulation type (GDPR/CCPA/both), with live preview on the right side. Includes tabs for Content, Colours, Buttons, Preference Center, and Advanced settings.
4. **Cookies management** -- View all detected cookies organized by category (Necessary, Functional, Analytics, Performance, Advertisement). Edit, delete, or add cookies manually. Includes Open Cookie Database integration with 2,242 cookie definitions.
5. **Cookie scanner** -- Built-in browser-based scanner with multiple scan depths: Quick (10 pages), Standard (100 pages), Deep (1,000 pages), or Full scan. No external service required.
6. **Consent Logs** -- Complete audit trail of every visitor's consent decision. Shows consent ID, status, categories accepted/rejected, anonymized IP, and page URL. Filter, search, and export to CSV.
7. **Google Consent Mode v2** -- Configure all 7 consent signal types with default/granted states. Includes Google Additional Consent Mode (GACM) for ad technology provider IDs. Advanced settings for URL passthrough and ads data redaction.
8. **Languages** -- Select from 180+ languages to translate the banner. Set a default language and add as many as you need. Banner text adapts automatically to visitor's browser language.
9. **Settings** -- Global controls: enable/disable banner, exclude specific pages, configure consent log retention, set scanner limits, enable Microsoft UET/Clarity consent APIs, and toggle IAB TCF v2.3 support.

== Changelog ==

= 1.1.0 =
* IAB TCF v2.3 with Global Vendor List (GVL v3) -- server-side download, caching, weekly auto-update, admin page for vendor selection
* Real vendor consent in TC Strings -- vendor consent bits, legitimate interest (honoring Right to Object), DisclosedVendors segment
* Vendor consent UI in preference center -- per-vendor toggles with details, privacy policy, purpose declarations
* GVL admin page -- browse, search, filter 1,100+ IAB vendors, paginated, purpose filter
* IAB settings -- CMP ID, Purpose One Treatment, publisher country code
* Dynamic TCF config -- ConsentLanguage, publisherCC, gdprApplies from server settings
* CMP stub -- inline __tcfapi responds to ping before main script loads
* getVendorList command -- returns complete GVL structure
* euconsent-v2 cookie -- standard TCF cookie, written only after explicit consent
* Security hardening -- cookie overflow protection, iframe URL validation, atomic file writes
* Dead code cleanup -- removed ~4.3 MB unused modules and cloud stubs
* CodeQL code scanning workflow
* GeoLite2 download fix (PR #9)
* 175 automated compliance tests (expanded from 21)

= 1.0.5 =
* Unified text domain and plugin slug to `faz-cookie-manager`
* WordPress.com Marketplace compliance (headers, readme.txt)
* Replaced all backward-compat constant aliases with FAZ_* equivalents
* Cleaned up admin page slugs
* Added PHPStan bootstrap for static analysis
* Google Consent Mode v2 support

= 1.0.4 =
* Full uninstall/reinstall support with clean data removal
* Fixed consent cookie handling on reject

= 1.0.3 =
* Browser-based cookie scanner with iframe detection
* Local consent log storage with database table and CSV export
* Dashboard analytics with pageview tracking

= 1.0.2 =
* Unlocked all premium features for local use
* Removed all cloud dependencies and external API calls

= 1.0.1 =
* Complete de-branding (renamed all prefixes, namespaces, CSS classes)
* PHP namespace rename to FazCookie

= 1.0.0 =
* Initial release based on CookieYes v3.4.0 fork
* Full GDPR, CCPA, ePrivacy Directive compliance
* Self-hosted cookie scanner and consent logging

== Upgrade Notice ==

= 1.1.0 =
Major update: IAB TCF v2.3 with full Global Vendor List integration. New GVL admin page for vendor management. 175 automated compliance tests. Clear caches after upgrading.

= 1.0.5 =
Admin page URLs have changed. Update any bookmarks. Clear caches after upgrading.
