=== FAZ Cookie Manager ===
Contributors: fabiodalez
Donate link: https://buymeacoffee.com/fabiodalez
Tags: cookie, gdpr, ccpa, consent, privacy
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.13.18
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

= Try it Live =

**[Try FAZ Cookie Manager in WordPress Playground](https://playground.wordpress.net/?plugin=faz-cookie-manager)** -- no account, no install, runs entirely in your browser.

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

= Note on third-party domain strings inside the plugin codebase =

The plugin source includes several third-party domain names (e.g. `js.stripe.com`, `connect.facebook.net`, `cdn.jsdelivr.net`, `unpkg.com`, `googletagmanager.com`, etc.) as **string patterns** for two purposes:

1. **Script-blocking detection patterns** — used to identify analytics, advertising, and tracking scripts that the *site administrator's other plugins* may inject, so we can block them until the visitor has given consent. The plugin itself does **not** load any of these scripts.
2. **Whitelist defaults** — domains such as `unpkg.com/`, `cdn.jsdelivr.net/`, `fonts.googleapis.com/`, `www.google.com/recaptcha/api`, etc. are seeded as default *whitelist* entries so the script blocker leaves them alone unless the admin explicitly removes them. They are configuration data, not outbound HTTP calls.

The only outbound HTTP requests this plugin makes are the four documented above (Open Cookie Database, IAB GVL, MaxMind, AMP CDN). All four are gated behind explicit administrator action or an enabled feature.

== Installation ==

= From the WordPress.org plugin directory (recommended) =

1. In your WordPress dashboard go to **Plugins > Add New Plugin**
2. Search for **FAZ Cookie Manager**
3. Click **Install Now**, then **Activate**
4. Go to **FAZ Cookie** in the admin sidebar to configure your banner

= Manual installation =

1. Download the ZIP from [wordpress.org/plugins/faz-cookie-manager](https://wordpress.org/plugins/faz-cookie-manager/)
2. In your WordPress dashboard go to **Plugins > Add New Plugin > Upload Plugin**
3. Upload the ZIP and click **Install Now**, then **Activate**
4. Go to **FAZ Cookie** in the admin sidebar to configure your banner

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

By default, no -- your consent logs, banner configuration and categories stay in the database so you can reinstall without losing work. To wipe everything on uninstall, enable **Settings → General → Remove all data on uninstall** or define `FAZ_REMOVE_ALL_DATA` as `true` in `wp-config.php` before deleting the plugin.

= Does the plugin include a CCPA "Do Not Sell" opt-out form? =

Yes. Place `[faz_do_not_sell]` on any page (e.g. your Privacy Policy) to show a California Consumer Privacy Act opt-out form. When a visitor submits the form, the opt-out is logged in the local consent table with a hashed IP address, a long-lived cookie is set so the visitor sees a confirmation on subsequent visits, and the site admin receives a notification email. Optional attributes: `title` (heading text) and `button` (submit label). No external service is involved.

= Does the plugin include a GDPR Data Subject Access Request (DSAR) form? =

Yes. Place `[faz_dsar_form]` on any page to show a GDPR-compliant request form covering six rights: Access (Art. 15), Erasure (Art. 17), Data Portability (Art. 20), Rectification (Art. 16), Restriction (Art. 18), and the Right to Object (Art. 21). On submission, the request is stored as a private post in the WordPress database (so it survives email failures), a notification is sent to the admin with a direct link to the record, and a confirmation is sent to the requester. The form includes a honeypot field and nonce verification to block spam bots. Optional attributes: `button` (submit label).

== Screenshots ==

1. **Cookie consent banner on the frontend** -- GDPR-ready banner in the bottom-left corner with "Customize", "Reject All" and equal-weight "Accept All" buttons. Shown only on the first visit until the visitor makes a choice.
2. **Preference center** -- Category-level opt-in modal. Necessary cookies are always active; every other category (Functional, Analytics, Uncategorized, Marketing) is opt-in by default, with a clear description for each.
3. **Admin dashboard** -- Overview of pageviews, banner impressions, accept rate and reject rate, with a 7/30/365-day pageviews chart and consent distribution.
4. **Banner editor** -- Configure layout, position, colours, copy and behaviour with a live in-iframe preview. Ships with GDPR Strict, High Contrast and Light Minimal design presets.
5. **Cookies management** -- Review and edit cookie categories, run the built-in scanner, and browse the bundled Open Cookie Database with 1,000+ definitions.
6. **IAB TCF v2.3 Global Vendor List** -- Browse the bundled GVL, filter by purpose, and select which vendors your site works with. Full Transparency and Consent Framework v2.3 support, no cloud required.
7. **Consent logs** -- Local, tamper-resistant audit trail of every visitor consent: status, categories, hashed IP, URL and timestamp. Filter, search and export to CSV for DPIA / audits.
8. **Google Consent Mode v2** -- Default vs. granted state for `ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`, `functionality_storage`, `personalization_storage` and `security_storage`. Works with GTM and gtag.
9. **Languages** -- Manage active languages and the default banner language. Works alongside WPML / Polylang; Italian, Dutch, German, French and Czech translations ship out of the box.
10. **Settings** -- Global controls: enable/disable the banner, exclude specific pages, cross-domain consent forwarding, hide from bots, GTM dataLayer events, consent log retention and scanner limits.

== Changelog ==

The full changelog (every release back to 1.0.0) lives at:
https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/blob/main/CHANGELOG.md
and on the GitHub Releases page:
https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/releases

= 1.13.18 =
* Fix: `wp_localize_script` and `wp_set_script_translations` payloads (inline `<script id="*-js-extra">` and `<script id="*-js-translations">`) are no longer false-positively blocked when their body contains a substring that matches a provider pattern. These ID shapes carry only data/i18n strings, never executable tracker code — the prior content-substring matcher would crash third-party plugins whose config keys happened to mention a provider (e.g. trx_addons emits the key `animate_to_mc4wp_form_submitted`, which matched MailChimp's `mc4wp` and broke the page with `ReferenceError: TRX_ADDONS_STORAGE is not defined`). `-js-before` and `-js-after` payloads stay on the regular blocking path.

= 1.13.17 =
* Fix: `dataLayer is not defined` when third-party trackers emit a bare `dataLayer.push()` before GTM bootstraps. Pre-init via `wp_add_inline_script('before')`. Closes wp.org thread "bug-report-datalayer-is-not-defined".
* Fix: cookie category counts stay stale after scan + auto-categorise — every cookie create/update/delete now invalidates Category controller cache, banner template, IAB unmatched-vendors transient, and 10 page-cache adapters. Closes wp.org thread "bug-report-cookie-categories-not-populated".
* Fix: REST `bulk_update` was silently dropping `opt_in_script` / `opt_out_script`. Now iterates schema editable fields through the same `sanitize_script_field` capability gate as single-cookie updates.
* Fix: `_cookieScripts` no longer truncates at 500 cookies (paged query, JSON-key-anchored LIKE, 10000-row ceiling).
* Fix: `sanitize_meta_for_current_user` intercepts every write path into `wp_faz_cookies.meta`. Closes a stored-XSS surface for multisite Site Administrators without `unfiltered_html`.
* Fix: own `wp_localize_script` payloads (`{handle}-js-extra`) can no longer be classified as analytics by the output-buffer blocker. Closes #99 and #101 (reported independently by @Myblueroom).
* Fix: WP Rocket "Load JavaScript deferred" no longer wraps our `_fazConfig` bootstrap payload in a `DOMContentLoaded` callback (which would scope `var _fazConfig` to the callback and break `script.js` with `Cannot set properties of undefined`). New `rocket_defer_inline_exclusions` filter excludes `_fazConfig`, `_fazCfg`, `_fazGcm`, `_fazTcfConfig` from DeferJS wrapping. Closes #95 (thanks @dominikkucharski for the diagnosis and reference patch).
* Fix: `<noscript>`-wrapped iframes injected by page builders (Bricks/Elementor/Divi) no longer become 0x0 phantom placeholders.
* Fix: Escape key no longer dismisses the consent banner without a recorded decision (EDPB dark-pattern). Preference center close-on-Escape preserved.
* Feature: `Necessary` selectable in Custom Blocking Rules dropdown. Closes wp.org thread "feature-request-add-necessary-category-to-script-blocker".
* Feature: Banner-status toggle now also appears at the top of the Cookie Banner admin page (mirrors Settings -> Banner Control).
* Compliance: CCPA 1798.135(c) - `[faz_do_not_sell]` renders a Withdraw opt-out button + `dns_rescinded` log entry.
* A11y: DSAR validation announces errors via `role=alert`, `aria-invalid` per field, focus on first invalid. `.faz-dsar-btn` / `.faz-dnsmpi-btn` carry a contrasting focus indicator (WCAG 1.4.11). DNSMPI error notice switches to `role=alert` on failure.
* Release: scripted 3-way ZIP builder (`scripts/build-release.sh`) for wp.org / GitHub / ClassicPress Directory. Refs #20.

= 1.13.16 =
* Fix: Plugins like Rank Math include tracker domain names inside inline JavaScript config. Tracker-domain patterns now match only against a script's `src` URL, not its inline content.
* Fix: `faz-skip` CSS class was matched as a plain substring (`faz-skipper` also exempted). Fixed to exact whitespace-delimited token match.
* Fix: Global variables in `uninstall.php` renamed to carry the `faz_` prefix.

= 1.13.15 =
* Fix: TinyMCE editors restored for Notice / Preference Description in banner admin.
* Fix: REST DELETE category was a silent no-op when the row was not loaded first; REST PUT wiped unspecified fields when starting from a blank object.
* Fix: Dynamic video placeholder (`_fazAddPlaceholder`) did not call `_fazSetPlaceHolder()` for non-YouTube providers.
* Fix: `faz_get_cookie_domain()` returned malformed IP suffix for IP-addressed sites; now returns `''` (host-only cookie) per RFC 6265.

= 1.13.14 =
* Fix: Fatal error on WordPress Playground - `maybe_create_table()` was called synchronously from a controller constructor during plugin loading. Deferred to `plugins_loaded` and guarded `wp_salt()` with `function_exists()`.

= 1.13.13 =
* Fix: Fatal error on fresh install - `wp_salt()` called without `\` prefix inside a namespaced class resolved as a non-existent namespaced function.
* Added: WordPress Playground Live Preview on the plugin directory page.

== Upgrade Notice ==

= 1.13.11 =
wp.org round-2 compliance pass. Custom CSS field removed from the Banner editor (use Customizer → Additional CSS instead); $_SERVER sanitization, FS_CHMOD globals, WP-CLI export path, and ob_start callback all hardened.

= 1.13.10 =
Final wp.org submission build. Plugin Check `library_core_files` ERROR on the ClassicPress polyfill resolved (file moved to GitHub-full ZIP only). `.distignore` realigned to release flow.

= 1.13.9 =
Plugin Check ERROR resolved (ClassicPress wp.apiFetch polyfill ships as static JS file) plus automatic page-cache invalidation on upgrade for 11 cache plugins (LiteSpeed, WP Rocket, W3 Total Cache, etc.). Recommended for everyone.

= 1.13.8 =
Bricks Builder support — Video element placeholder collapse fixed, Bricks Container Video Lightbox click intercepted before YouTube/Vimeo opens without consent, banner suppressed in Bricks visual editor. Recommended for any site on Bricks.

= 1.11.2 =
Fixes invisible preference center on dark presets, TypeError crash on ChromeOS/PMP-exempt members, and High Contrast preset button colors. Recommended for all 1.11.x installations.

= 1.11.3 =
WP 5.7+ inline script filter, Plugin Check compliance for wp.org submission, and returning-visitor unblock retry. Recommended before wp.org submission.

= 1.11.1 =
Critical fix release — addresses two production-impacting bugs in 1.11.0 (banner reappearing on every page load, PMP `exempt_levels` setting not persisting) plus nine smaller fixes and a new Czech translation. Strongly recommended for all 1.11.0 installations.

= 1.11.0 =
Consent versioning, non-personalized ads fallback for Google Consent Mode, and an optional Paid Memberships Pro integration. Review Settings → Force re-consent and GCM → Advanced after upgrade; existing cookies remain valid until you click "Invalidate all consents".

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
