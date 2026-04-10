=== FAZ Cookie Manager ===
Contributors: fabiodalez
Tags: cookie, gdpr, ccpa, consent, privacy
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.9.2
Requires PHP: 7.4
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Free cookie consent with GDPR, CCPA, ePrivacy, Google Consent Mode v2, and IAB TCF v2.3. No cloud required.

== Description ==

**Tired of cookie consent plugins that lock essential features behind paywalls, require cloud accounts, or send your visitors' data to third-party servers?**

FAZ Cookie Manager is a WordPress plugin that helps you implement cookie consent and privacy workflows for international regulations -- completely free, with no strings attached.

No account to create. The plugin requires no cloud service connection. Basic features like consent logging and geo-targeting are included -- no premium plan needed. Core consent features run on your own server, and you own all your data.

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
* **Accessibility-focused** -- Keyboard navigation (Tab, Enter, Escape), screen-reader support, mobile responsive.

= Helps with these frameworks =

This plugin assists consent and privacy workflows. It does not itself create, provide, or guarantee legal compliance, and you remain responsible for the final configuration for your site and jurisdiction.

* **GDPR** (EU General Data Protection Regulation) -- Opt-in consent, granular categories, right to withdraw
* **CCPA / CPRA** (California Consumer Privacy Act) -- "Do Not Sell or Share" opt-out link
* **ePrivacy Directive** (EU Cookie Law) -- Consent-based script blocking support
* **Italian Garante Privacy** -- 6-month consent expiry setting and consent logging controls
* **EDPB Guidelines** -- No scroll-as-consent, no pre-checked categories, equal button prominence options
* **LGPD** (Brazil General Data Protection Law) -- Consent-based model
* **POPIA** (South Africa Protection of Personal Information Act) -- Opt-in consent

= How it works =

1. Install and activate -- the cookie banner appears immediately with sensible defaults
2. Scan your site to detect cookies automatically
3. Customize the banner design, text, and colors to match your brand
4. Enable Google Consent Mode or IAB TCF if you use advertising tools
5. Monitor consent analytics on the dashboard

Core banner functionality runs on your WordPress site. Optional update/download features may contact GitHub, IAB Europe, MaxMind, or the AMP CDN depending on which features you enable and use.

== External Services ==

= GitHub / Raw GitHubusercontent (Open Cookie Database) =

Used to refresh the built-in cookie definitions snapshot for the optional auto-categorize feature.

Triggered when: you click the definitions update action in the Cookies screen.

Data sent: your server IP address and standard HTTP request headers.

Service URLs:
* https://raw.githubusercontent.com/fabiodalez-dev/Open-Cookie-Database/master/open-cookie-database.json

Terms of Service / Privacy Policy:
* https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement

= IAB Europe / vendor-list.consensu.org =

Used to download the Global Vendor List and purpose translations for the optional IAB TCF feature.

Triggered when: you manually update the vendor list, and weekly while IAB TCF is enabled.

Data sent: your server IP address and standard HTTP request headers.

Service URLs:
* https://vendor-list.consensu.org/v3/vendor-list.json
* https://vendor-list.consensu.org/v3/purposes-en.json

Privacy Policy:
* https://iabeurope.eu/privacy-policy/

= MaxMind =

Used to download the GeoLite2 Country database for optional geo-targeting.

Triggered when: you enter a MaxMind license key in Settings and start the database download.

Data sent: your server IP address, the license key you provide, and standard HTTP request headers.

Service URL:
* https://download.maxmind.com/app/geoip_download

Terms of Service / Privacy Policy:
* https://www.maxmind.com/en/terms-of-use
* https://www.maxmind.com/en/privacy-policy

= AMP Project CDN =

Used only on AMP pages when the AMP consent integration is active, to load the official `amp-consent` component required by AMP.

Triggered when: an AMP page renders the AMP consent banner.

Data sent: the visitor IP address and standard browser request data to the AMP CDN.

Service URL:
* https://cdn.ampproject.org/v0/amp-consent-0.1.js

Documentation / Privacy:
* https://amp.dev/documentation/components/amp-consent
* https://policies.google.com/privacy

== Installation ==

1. Upload the `faz-cookie-manager` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **FAZ Cookie** in the admin sidebar to configure your banner
4. Click **Scan Site** on the Cookies page to detect cookies automatically
5. Customize the banner design, text, and regulation type on the Cookie Banner page

== Frequently Asked Questions ==

= Does this plugin require a cloud account or subscription? =

No required cloud account or subscription is needed. Core consent features run locally, while some optional refresh/download features can contact documented third-party services such as GitHub, IAB Europe, MaxMind, or AMP infrastructure.

= Is it really free? What's the catch? =

It's free and open source (GPL-3.0). There are no premium upgrades, no feature gates, and no upsells. The plugin is based on the GPL-licensed CookieYes v3.4.0 codebase, with cloud dependencies removed and all included features running locally.

= Is it compatible with Google Consent Mode v2? =

Yes. The plugin sends all 7 consent signals (`ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`, `functionality_storage`, `personalization_storage`, `security_storage`) and supports Google Additional Consent Mode (GACM) for ad technology providers.

= Does the banner block cookies before consent? =

Yes. Any script tagged with `data-faz-tag="category-name"` is blocked until the visitor grants consent for that category. This helps you implement consent-based blocking for ePrivacy/GDPR workflows.

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

= Does the plugin send any data home or collect telemetry? =

No. The plugin contains no telemetry, no analytics beacon, and no "phone home". Dashboard numbers are computed locally from your own `wp_faz_pageviews` and `wp_faz_consent_logs` tables. Every outbound request that *can* happen is documented in the "External services" section and is gated behind an explicit admin action.

= Where is the source of the bundled minified JavaScript? =

The only minified files we ship are `frontend/js/gcm.min.js` and `frontend/js/tcf-cmp.min.js`. The full, unminified sources live next to them as `gcm.js` and `tcf-cmp.js`, and the build command `npm run build:min` rebuilds them with `terser`. No obfuscation is used.

= Does uninstalling the plugin remove my data? =

By default, no — your consent logs, banner configuration and categories stay in the database so you can reinstall without losing work. To wipe everything on uninstall, enable **Settings → General → Remove all data on uninstall** or define `FAZ_REMOVE_ALL_DATA` as `true` in `wp-config.php` before deleting the plugin.

== Screenshots ==

1. **Cookie consent banner on the frontend** -- GDPR-ready banner in the bottom-left corner with "Customize", "Reject All" and equal-weight "Accept All" buttons. Shown only on the first visit until the visitor makes a choice.
2. **Preference center** -- Category-level opt-in modal. Necessary cookies are always active; every other category (Functional, Analytics, Uncategorized, Marketing) is opt-in by default, with a clear description for each.
3. **Admin dashboard** -- Overview of pageviews, banner impressions, accept rate and reject rate, with a 7/30/365-day pageviews chart and consent distribution.
4. **Banner editor** -- Configure layout, position, colours, copy and behaviour with a live in-iframe preview. Ships with GDPR Strict, High Contrast and Light Minimal design presets.
5. **Cookies management** -- Review and edit cookie categories, run the built-in scanner, and browse the bundled Open Cookie Database with 1,000+ definitions.
6. **IAB TCF v2.3 Global Vendor List** -- Browse the bundled GVL, filter by purpose, and select which vendors your site works with. Full Transparency and Consent Framework v2.3 support, no cloud required.
7. **Consent logs** -- Local, tamper-resistant audit trail of every visitor consent: status, categories, hashed IP, URL and timestamp. Filter, search and export to CSV for DPIA / audits.
8. **Google Consent Mode v2** -- Default vs. granted state for `ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`, `functionality_storage`, `personalization_storage` and `security_storage`. Works with GTM and gtag.
9. **Languages** -- Manage active languages and the default banner language. Works alongside WPML / Polylang; Italian and Dutch translations ship out of the box.
10. **Settings** -- Global controls: enable/disable the banner, exclude specific pages, cross-domain consent forwarding, hide from bots, GTM dataLayer events, consent log retention and scanner limits.

== Changelog ==

= 1.9.2 =
* Fix: Settings API no longer re-injects default language into selected list on every read

= 1.9.1 =
* Fix: Default language now uses WordPress site locale instead of hardcoded English
* Fix: Theme link colors (Divi, Elementor) no longer override banner button colors

= 1.9.0 =
* New: WCAG 2.2 accessibility (a11y.js) — dialog roles, heading hierarchy, role="switch", dynamic labels, Escape key
* New: CSS custom properties — CSP-compatible banner styling via --faz-* vars
* New: Dutch language support (573 strings)
* New: Admin UI refresh with real-time iframe banner preview and design presets
* New: Focus management — preference center restores focus on close
* Fix: Settings save no longer accumulates duplicate array entries
* Fix: Blocker templates auto-save when clicked
* Fix: .faz-accordion-heading CSS normalized across all template types
* Security: SSRF redirect protection, path traversal sanitization, ABSPATH guard
* Security: Banner API returns WP_Error on database failures
* Performance: a11y.js loaded in footer (non render-blocking)
* 10 rounds of code review, 155+ E2E tests

= 1.8.0 =
* New: Admin UI refresh with modern design system
* New: Real-time iframe-based banner preview in admin
* New: WooCommerce-aware scanner with priority page discovery
* New: Scanner debug mode with downloadable logs
* New: OCD auto-download (7400+ definitions)
* New: Remove all data on uninstall setting
* Fix: Inferred cookies use site domain
* Fix: Auto-categorize serialized to prevent rate limiting

= 1.7.0 =
* New: 26 features including import/export, WP-CLI, per-service consent, age gate
* New: Cookie policy shortcode, blocker templates, design presets
* Security: Full input validation and nonce hardening

= 1.6.0 =
* New: WooCommerce compatibility with payment gateway whitelist
* New: Video placeholder system for blocked embeds

= 1.5.0 =
* New: Link text colour picker in Banner → Colours tab for customising link colours in the consent notice
* New: 21 Playwright E2E tests covering all banner settings tabs
* Fix: TinyMCE re-render on tab switch limited to the activated tab's editor only
* Fix: Output buffer null guard against null from preg_replace_callback
* Fix: PCRE error logging instead of silent fallback
* Fix: Accessibility — aria-labels on link colour picker inputs
* Fix: Admin preview link selector aligned with frontend (includes optout-popup links)

= 1.4.1 =
* Fix: ClassicPress polyfill not loading — prints directly in admin_head instead of wp_add_inline_script

= 1.4.0 =
* New: ClassicPress compatibility layer — wp.apiFetch polyfill with nonce middleware and FilePond fallback
* New: 5-layer script blocking — WP hook filters, HTML content filters, output buffer processing, client-side interceptors, and cookie shredding
* New: Known Providers database — 147+ services with 500+ URL/script patterns for automatic categorization
* New: Video embed placeholders — YouTube/Vimeo iframes replaced with consent-required placeholder
* New: Social embed blocking — Facebook, Instagram, Twitter/X embeds blocked until consent
* New: Iframe placeholder system — visual placeholder with consent button for blocked third-party iframes
* New: Custom blocking rules — admin UI for user-defined script/iframe blocking patterns per category
* New: Script dependency chains — data-faz-waitfor attribute for scripts that depend on consent-blocked resources
* New: Network request interception — XHR, fetch, and sendBeacon requests to blocked providers silently dropped
* New: Cookie shredding — automatic cleanup of cookies from revoked categories
* Fix: TinyMCE content preserved across banner tab switches
* Fix: SRI/CSP-safe script clone attribute ordering
* Fix: Non-executable script types never blocked
* Fix: ReadMore link enabled in banner
* Fix: Close button functionality restored
* Security: URL scheme validation prevents javascript:/data: injection on restored URLs
* Security: Word-boundary-safe regex for src/href attribute renaming

= 1.3.0 =
* New: Cookie scanner optimization — incremental scans, page discovery from DB, settle watchdog, scan metrics
* New: Advertisement → Marketing category rename with idempotent DB migration
* New: Taxonomy-aware scan fingerprint (detects term renames/additions for accurate incremental scans)
* Fix: Boundary-aware provider hostname matching in script blocking
* Fix: CSS transient cache key includes plugin version to prevent stale styles after upgrades
* Fix: TCF Special Features always return false per IAB spec (no category-based derivation)
* Fix: Scanner iframe cookies use scanned page hostname instead of admin hostname
* Fix: Scanner fingerprint persisted only after successful import
* Fix: German category translation typo ("Werbekampagne nzu" → "Werbekampagnen zu")
* Security: Inline-safe URL handling for banner preview sinks
* Security: Hardened admin URL handling and stale bar actions

= 1.2.1 =
* Fix: CSV export no longer wraps data in JSON encoding — produces valid CSV files
* Fix: consent log now correctly records "rejected" status when visitors click Reject All
* Fix: consent logger skips page-load init events to prevent false "partial" entries for returning visitors
* Security: prototype pollution guard in deepSet utility function (CodeQL)
* Security: DOM XSS prevention — logo URL validated to https only, privacy link href sanitized (CodeQL)
* Security: CSV export type guard and anti-cache headers for privacy
* New: Composer/Packagist support — install via `composer require fabiodalez/faz-cookie-manager`

= 1.2.0 =
* Security: proxy header trust filter (faz_trust_proxy_headers) — proxy headers only parsed when explicitly enabled
* Security: dual-guardrail consent throttle (per-IP + per-consent_id) to prevent flooding
* Security: TTL normalization in rate limiter — prevents zero/negative TTL bypass
* UX: necessary category toggle now uses active blue color instead of gray
* UX: "Always active" label positioned right-aligned next to toggle
* Cleanup: removed orphan methods from deprecated languages API
* Hardening: trailingslashit() for GVL path in uninstall
* E2E tests: custom dataLayerName support, try/finally context cleanup, safer element iteration
* Playwright test suite: 11 e2e tests with proper fixtures and global setup

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
* Moved included features to local/self-hosted operation
* Removed all cloud dependencies and external API calls

= 1.0.1 =
* Complete de-branding (renamed all prefixes, namespaces, CSS classes)
* PHP namespace rename to FazCookie

= 1.0.0 =
* Initial release based on CookieYes v3.4.0 fork
* GDPR, CCPA, and ePrivacy Directive consent workflows
* Self-hosted cookie scanner and consent logging

== Upgrade Notice ==

= 1.9.2 =
Fixes the "English always comes back" language bug. Clear caches after upgrading.

= 1.9.1 =
Fixes default language fallback and theme color bleed on banner buttons. Clear caches after upgrading.

= 1.9.0 =
WCAG 2.2 accessibility, CSS custom properties for CSP compatibility, Dutch language, admin UI refresh with live preview, security hardening, and 155+ E2E tests. Clear caches after upgrading.

= 1.5.0 =
New link text colour picker for banner links. 21 new E2E tests. TinyMCE, accessibility, and output buffer fixes. Clear caches after upgrading.

= 1.4.1 =
Fixes ClassicPress polyfill loading. Clear caches after upgrading.

= 1.4.0 =
Major update: 5-layer script blocking with Known Providers database (147+ services), video/social embed placeholders, cookie shredding on revocation, ClassicPress compatibility. Clear caches after upgrading.

= 1.2.1 =
Fixes CSV export formatting, consent log accuracy (rejected now tracked), and CodeQL security alerts. Adds Composer/Packagist support. Clear caches after upgrading.

= 1.2.0 =
Security hardening (proxy trust filter, dual-throttle consent logging, TTL normalization). Improved necessary toggle UX. Clear caches after upgrading.

= 1.1.0 =
Major update: IAB TCF v2.3 with full Global Vendor List integration. New GVL admin page for vendor management. 175 automated compliance tests. Clear caches after upgrading.

= 1.0.5 =
Admin page URLs have changed. Update any bookmarks. Clear caches after upgrading.
