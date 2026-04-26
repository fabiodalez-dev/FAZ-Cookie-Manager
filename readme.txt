=== FAZ Cookie Manager ===
Contributors: fabiodalez
Tags: cookie, gdpr, ccpa, consent, privacy
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.13.6
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
9. **Languages** -- Manage active languages and the default banner language. Works alongside WPML / Polylang; Italian, Dutch, German, French and Czech translations ship out of the box.
10. **Settings** -- Global controls: enable/disable the banner, exclude specific pages, cross-domain consent forwarding, hide from bots, GTM dataLayer events, consent log retention and scanner limits.

== Changelog ==

= 1.13.6 =
* New: blocker-template parity with the runtime detection layer. Every provider already auto-detected by `Known_Providers` (143 of them — Google Analytics, Adobe, Plausible, Microsoft Clarity, Mixpanel, Segment, Stripe, Mailchimp, Klaviyo, HubSpot, Pinterest, Snapchat, Reddit, Quora, Outbrain, Taboola, Yandex Metrica, Baidu Analytics, etc.) now appears in WP Admin → FAZ Cookie Manager → Cookies → "Add from template". Previously only 11 templates were exposed in the admin picker; the runtime always blocked them all, but admins couldn't see them in the picker. Discovery-friendly without changing the privacy contract.
* Internal: 131 generated blocker-template JSONs auto-derived from `includes/data/known-providers.json` (single source of truth). Each template inherits the provider's `label`, `category`, `patterns`, and `cookies`.

= 1.13.5 =
* New: Matomo (formerly Piwik) is now available as a blocker template in Cookies → Blocker Templates. Covers self-hosted and Matomo Cloud, including Matomo Tag Manager, the matomo.js / piwik.js trackers, the matomo.php / piwik.php tracking endpoint, and all `_pk_*`, `MATOMO_SESSID`, and `mtm_consent*` cookies. Requested by a user on the back of a successful 1.13.4 install.

= 1.13.4 =
* Fix: `wp_localize_script` payloads (`{handle}-js-extra`) and translation tags (`{handle}-js-translations`) for FAZ scripts now also carry the 5 cache opt-out attributes. Those inline tags do not travel through `script_loader_tag` so the 1.13.1 / 1.13.2 / 1.13.3 attribute injection missed them, and a delay-aware optimizer (LiteSpeed Guest Mode in particular) re-typed them to `litespeed/javascript`. Added a hook on `wp_inline_script_attributes` (WP 5.7+) that recognises our handle prefix and injects the same 5 hints.

= 1.13.3 =
* Fix: banner invisible on first paint when LiteSpeed Cache "Delay JS" had a hand-added entry mentioning `faz-cookie-manager` without the full `wp-content/plugins/...` prefix. The 1.13.2 path-anchored scrubber was strict-anchored and skipped those entries; 1.13.3 also matches `faz-cookie-manager` as a complete token, while still leaving third-party companion names like `my-integration-faz-cookie-manager-compat.js` untouched. Reported by gooloo.de.

= 1.13.2 =
* Fix: GDPR Strict preset "Customize" button unreadable (light-blue text on dark-blue background) — classic template CSS hardcoded `color: #1863dc` instead of reading the preset's `--faz-settings-button-color` variable.
* Fix: consent banner invisible on LiteSpeed Guest Mode installs — added the missing `litespeed_optm_gm_js_exc` filter so Guest Mode's separate JS exclude list also recognises our scripts.
* Fix: alt-asset mode (`faz-fw` alias) children (`faz-fw-gcm`, `faz-fw-tcf-cmp`, `faz-fw-a11y`) now correctly tagged with the cache-plugin opt-out attributes.
* Fix: `litespeed_optm_js_delay_inc` scrubbing now path-anchored (`plugins/faz-cookie-manager/`) so third-party integration entries are never collaterally removed.
* New: `faz_auto_exclude_cache_plugins` filter for admins who want to disable the automatic cache-plugin exclusion block.

= 1.13.1 =
* New: auto-exclude FAZ scripts from cache/optimization plugins' Delay JS. Every FAZ `<script>` now carries `data-no-defer`, `data-no-optimize`, `data-no-minify`, `data-cfasync="false"` and `data-ao-skip`, and the matching pattern-based exclude filters are hooked for LiteSpeed Cache, WP Rocket, Autoptimize, SG Optimizer, Hummingbird, Cloudflare Rocket Loader and W3 Total Cache. Fixes the "banner only appears on second tap" reports from publishers using those plugins.

= 1.13.0 =
* Fix: per-service consent cookie stays under the browser's 4 KB limit (issue #80). Previously a ~160-service install dropped every "Save My Preferences" click because the oversized cookie write was silently discarded.
* Fix: scanner `discover_urls` places recently-modified pages in the priority bucket (issue #78) so freshly-edited posts aren't skipped by the client-side early-stop threshold.
* Fix: server-side cookie shredder honours the frontend whitelist on every request, not just the first page load.
* Fix: whitelist pattern match is unidirectional with a 3-character minimum guard — entering `"js"` or `"com"` no longer whitelists nearly every provider.
* Fix: preference center focus-retry timers cancelled on close (no more focus theft after rapid open/close).
* Fix: dynamic scripts preserve their original `type` attribute (`module`, etc.) through the block/unblock round-trip.

= 1.12.1 =
* Fix: LiteSpeed Cache cookies (`_lscache_vary`, `_litespeed_*`) added to the internal whitelist so they're not shredded by the server-side cookie cleanup.

= 1.12.0 =
* Security audit: closed all findings from a 20-agent code audit (H2-H5, M1-M28).
* data: URI blocking — decoded payload matched against provider patterns on both PHP and JS sides.
* Uppercase HTML tag handling in output buffer guards (`strpos` → `stripos`).
* Consent logging: throttle fix for empty consent_id, URL credential stripping, UA hashing.
* TCF/IAB v2.3: `buildConsentArtifacts`, Purpose 1 treatment, euconsent-v2 cleanup.
* Accessibility: extended focus trap, summary support, localized aria-labels.
* Performance: `faz_settings` memoized, N+1 queries eliminated, `faz_current_language()` cached.
* Plugin Check: 0 ERRORS — all escaping, WP_Filesystem, and ABSPATH issues resolved.
* DB migration 3.4.1: banner table indexes for existing installs.

= 1.11.3 =
* New: WP 5.7+ `wp_inline_script_tag` filter intercepts inline scripts before the output buffer for cleaner blocking. Backward compatible with WP < 5.7.
* New: returning visitor unblock retry — blocked scripts are restored at multiple delays + on `load` event, fixing late-rendered content for returning visitors.
* Fix: WordPress Plugin Check compliance — all `OutputNotEscaped`, `MissingTranslatorsComment`, and `NoExplicitVersion` errors resolved for wp.org submission.
* Fix: inline script whitelist bypass — `is_whitelisted()` no longer matches against inline script body content.
* Refactor: `_fazBuildRestoredScript()` deduplicates script-cloning logic.

= 1.11.2 =
* Fix: preference center invisible on dark design presets — all 5 presets now include full modal color palettes (background, text, buttons, toggle states).
* Fix: TypeError crash on ChromeOS and PMP-exempt members — null guard in `_fazRenderBanner()` prevents crash when the banner template element is absent.
* Fix: `applyDesignPreset()` deep-replaces preference center and optout popup config — the old cherry-pick missed toggle states.
* Fix: `const` → `var` in WP Consent API inline script for broader browser compatibility.
* Fix: removed `#000000` → transparent skip in template CSS — High Contrast preset buttons now render as intended black.

= 1.11.1 =
* **Critical fix**: banner reappearing on every page load — the consent cookie was written without URL-encoding, so the re-read couldn't extract `rev` and the stale-check wiped the cookie every time. URL-encode on write, two-pass decode on read. Reported by a live publisher running 1.11.0.
* **Critical fix**: PMP `exempt_levels` setting didn't persist — the settings sanitizer coerced the CSV input to an empty array before the per-key handler could parse it. Without this fix the entire Paid Memberships Pro integration was silently non-functional.
* Fix: Non-personalized ads fallback also forces `ad_user_data` / `ad_personalization` to `denied` in the region-default code path, aligning with the post-"reject all" state.
* Fix: PMP auto-grant cookie now writes `consent:yes` (the token `script.js::_fazUnblock()` gates on) instead of `consent:accepted`, so exempt members get their scripts actually unblocked client-side.
* Fix: `setAdditionalConsent(null)` no longer fires during the stale-revision window — would otherwise clobber the live GACM provider list.
* Fix: Settings page race condition between `loadSettings()` and `invalidateConsents()` — bumped revision is no longer reverted by a late-arriving GET.
* Fix: Cross-domain consent forwarding regex accepts base64 (`+`, `/`, `=`) so forwarded consentids aren't silently dropped.
* Fix: `wca.js` and `microsoft-consent.js` requested `.min.js` files that don't exist on the installation, 404'ing the WP Consent API and Microsoft UET/Clarity integrations. Suffix is now computed per-file.
* Fix: PMP auto-grant cookie filters internal/admin categories (`wordpress-internal`, invisible ones) so they don't leak into a visitor's consent record.
* Fix: changelog wording on NPA fallback clarified — `ad_storage = granted` still allows advertising identifiers for frequency capping/fraud detection; what NPA removes is profiling and ad-user-data signals.
* New: Czech (cs_CZ) translation — 441 fully translated strings contributed by Vaclav.
* Refactor: `faz_get_cookie_domain()` is now the single source of truth; `Frontend::get_cookie_domain()` delegates. No more TLD-list drift between server-side writes and client-side localization.

= 1.11.0 =
* New: Non-personalized ads fallback for Google Consent Mode (GCM → Advanced). When a visitor denies marketing consent, keep `ad_storage = granted` while forcing `ad_user_data` and `ad_personalization` to `denied` — the Google-sanctioned configuration for serving non-personalized ads to visitors who reject the banner. Publishers still earn revenue on those pageviews. Disabled by default; admins enable it explicitly.
* New: Force re-consent (Settings → Force re-consent). "Invalidate all consents" button bumps a server-side revision counter; returning visitors whose stored cookie carries a lower revision see the banner again on their next visit. Useful after adding new ad/analytics services or tightening your cookie policy.
* New: Paid Memberships Pro integration (Settings → Paid Memberships Pro integration, visible only when PMP is active). Select comma-separated level IDs whose members are exempted from the banner and auto-granted consent across all categories — the "Pay-or-Accept" (PUR) model. Non-paying visitors are unaffected. No-op when PMP is not installed.
* Fix: GCM race condition on revisit — returning visitors with a saved consent cookie now see `gtag("consent", "default", …)` emitted directly with their granted states, removing the brief denied→granted window during which AdSense/GTM could fire the first request with ads blocked.
* Fix: `wait_for_update` default aligned between admin UI (500 ms) and PHP defaults (previously 2000 ms) — the UI number now matches what new installations actually use.

= 1.10.2 =
* Fix: preference center text colour on sites with a dark theme (follow-up to #57). The 1.10.1 fix for the transparent background exposed a pre-existing issue: several rules inside the preference center used `color: inherit`, which on dark-theme host sites inherited a light text colour from `body`, producing unreadable "light on white" text. Locked the text colour to `var(--faz-detail-color, #212121)` on the preference center, preference, header, footer wrapper, body wrapper and description paragraphs. The default is dark regardless of host theme, and users can still override the colour from the banner editor because the CSS variable is fed from the stored banner config.
* Test: new E2E regression that injects a dark-theme stylesheet on the host site, opens the preference center, and asserts every text-bearing element inherits the locked-down dark colour instead of the injected light one.

= 1.10.1 =
* Fix: preference center transparent background on classic (full-width + pushdown) banner type. The `.faz-preference-center` CSS used `background-color: inherit` which left the modal visually empty when the classic template was active, because that template wraps the preference center in `.faz-preference-wrapper` (not `.faz-modal`) and no ancestor provided a colour. Replaced with `background-color: var(--faz-detail-background-color, #ffffff)` so the default is always a solid background, regardless of template variant. Reported as issue #57.
* Test: new E2E regression that switches the banner to classic + pushdown, opens the preference center, and asserts the computed `background-color` of `.faz-preference-center` is not transparent.

= 1.10.0 =
* New: German (de_DE) translation — covers [faz_cookie_policy], [faz_cookie_table], cookie category names and common banner labels. Fixes a gooloo.de user report where the Cookie Policy shortcode stayed in English on a de_DE site because no de_DE .mo file was bundled.
* New: Admin JavaScript i18n infrastructure — 128 localized keys exposed via fazConfig.i18n.*, organized in 8 namespaces (cookies, banner, settings, GCM, consent logs, languages, GVL, import/export, dashboard).
* New: WordPress.org submission assets — 10 publish-ready screenshots, PUBLISHING-GUIDE.md with the full submission/SVN workflow, Playwright capture script.
* New: FAQ entries on telemetry ("Does the plugin send any data home?"), minified JS source ("Where is the source of the bundled minified JavaScript?") and data removal on uninstall.
* Fix: Cookie definitions metadata normalization — legacy installs upgrading from < 1.9 no longer send the UI down the wrong "downloaded vs bundled" branch.
* Fix: META_KEY now stored with autoload=false, keeping metadata out of the autoload bucket.
* Fix: importFailed i18n string now contains %s so the actual error detail is surfaced instead of being swallowed by String.replace.
* Fix: GVL admin page fully localized — 8 previously hardcoded strings converted to esc_html_e / esc_attr_e.
* Fix: GVL REST API error message "vendor_ids must be an array." is now translatable.
* Fix: JS i18n payload uses __() instead of esc_html__() so HTML entities no longer leak into the UI.
* Test: New E2E regression for the gooloo.de scenario — sets WPLANG=de_DE, creates a page with [faz_cookie_policy], asserts German strings render and English fallbacks do not.

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
