=== FAZ Cookie Manager ===
Contributors: fabiodalez
Donate link: https://buymeacoffee.com/fabiodalez
Tags: cookie, gdpr, ccpa, consent, privacy
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 1.19.0
Requires PHP: 7.4
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Free cookie consent with GDPR, CCPA, ePrivacy, Google Consent Mode v2, IAB TCF v2.3, and built-in Cookie Policy generator. No cloud required.

== Description ==

**Tired of cookie consent plugins that lock essential features behind paywalls, require cloud accounts, or send your visitors' data to third-party servers?**

FAZ Cookie Manager is a WordPress plugin that helps you implement cookie consent and privacy workflows for international regulations -- completely free, with no strings attached.

No account to create. The plugin requires no cloud service connection. Basic features like consent logging and geo-targeting are included -- no premium plan needed. Core consent features run on your own server, and you own all your data.

= Why FAZ Cookie Manager? =

Most cookie consent plugins follow the same pattern: a free version with crippled features, and a paid tier starting at $10-50/month that unlocks what you actually need (cookie scanning, consent logs, Google Consent Mode, IAB TCF). FAZ Cookie Manager breaks that model:

* **Cookie scanner** -- Scans your site directly from your browser. No external service, no API limits, no waiting.
* **Cookie Policy generator (NEW in 1.16.0)** -- Build a jurisdiction-aware Cookie Policy page directly from your admin. Pick GDPR / CCPA / LGPD, fill in your company details, and publish via the `[faz_cookie_policy_complete]` shortcode. Output is multilingual (en, it, fr, de, es, pt-BR, bg), pulls the live cookie inventory from the scanner, and ships with a non-removable disclaimer that the templates are starting points, not legal advice. The standalone `[faz_cookie_table]` shortcode (and the matching Gutenberg block) still works for embedding just the cookie list.
* **Consent logging with CSV export** -- Every consent is recorded locally in your database. Export anytime for audits.
* **Google Consent Mode v2** -- Sends all 7 consent signals to Google tags. No premium required.
* **IAB TCF v2.3** -- Full Transparency and Consent Framework API and UI, built in. To operate as a recognised CMP in the IAB framework you must enter your own registered IAB Europe CMP ID; without one the TCF interface stays inactive (no TC string is produced) so invalid signals are never broadcast to vendors.
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

Core banner functionality runs on your WordPress site. Optional update/download features may contact GitHub, IAB Europe, MaxMind, ip-api.com, ipinfo.io (opt-in VPN detection), or the AMP CDN depending on which features you enable and use.

= Cookie Policy generator (1.16.0+) =

Need a Cookie Policy page that explains the cookies your site sets, the jurisdiction it operates under, and who the visitor should contact about their data? FAZ Cookie Manager 1.16.0 ships a dedicated **Cookie Policy** admin tab plus the `[faz_cookie_policy_complete]` shortcode.

* **Jurisdiction-aware** -- pick GDPR (EU/EEA/UK), CCPA/CPRA (California), or LGPD (Brazil). Each jurisdiction ships its own template scaffold with the legal references and required sections for that framework.
* **Multilingual (7 languages out of the box)** -- en, it, fr, de, es, pt-BR, bg. Override per render with `[faz_cookie_policy_complete lang="it"]` or let the visitor's browser language pick.
* **Auto-populated cookie inventory** -- the rendered policy pulls live from `wp_faz_cookies`, so any cookie discovered by the scanner shows up at the next render with its category, duration and description, in the active language.
* **Filled with your company data** -- name, address, DPO email, third-party services, retention period: stored in `faz_cookie_policy_data` option, edited via the admin form, never seeded from `admin_email` or `blogname` (PII protection).
* **Non-removable legal disclaimer** -- every generated policy ends with a footer making explicit that the templates are starting points, not legal advice. The disclaimer is hardcoded in the renderer (not in the template files) so section overrides cannot suppress it.
* **Versioning hash** -- a `data-faz-policy-version` attribute on the rendered article tracks template + data drift over time. Display-only fields (the visible "Last updated" date) are excluded so the hash doesn't change daily.
* **Filter for site builders** -- `faz_cookie_policy_data` lets you inject custom placeholders before template substitution.
* **Backwards compatible** -- the long-standing `[faz_cookie_policy]` shortcode (with `site_name` / `contact` / `show_table` attributes from 1.7.0) is unchanged. The standalone `[faz_cookie_table]` shortcode and matching `faz/cookie-table` Gutenberg block still work for embedding just the cookie inventory table.

= Multi-banner geo-routing vs multilingual content (1.14.0+) =

These are two **orthogonal** features that combine freely — multi-banner is per **country**, multilingual content is per **language inside each banner**.

* **Multi-banner geo-routing** picks WHICH banner profile to serve based on the visitor's country. Typical setup: a strict GDPR banner for EU/EEA/UK and a CCPA opt-out banner for California (or any other per-region compliance profile). Country resolution chain: Cloudflare `CF-IPCountry` header (opt-in via the `faz_trust_cf_ipcountry_header` filter) → MaxMind GeoLite2 → ip-api.com fallback. Each banner row carries its own `target_countries` list and a `priority` integer for overlap resolution.

* **Multilingual content** lives INSIDE each banner. A single banner stores translations of its title, description and button labels for as many languages as you enable on the Languages page. The language displayed to the visitor is resolved CLIENT-SIDE from `navigator.languages` so a country-targeted banner can still be served from a full-page cache (LiteSpeed / WP Rocket / Cloudflare APO) and the right language renders on hydration.

Practical example: an install needs only TWO banner rows, not eight. One EU-targeted GDPR banner with English + Italian + German + French + Polish translations inside, and one US-targeted CCPA banner with English + Spanish translations inside. The country selects the banner; the browser selects the translation inside the banner. Visitors hitting the right cache key get the right banner + the right language.

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

Used to download a GeoLite2 database for optional geo-targeting. You choose the edition in Settings → GeoIP Database: the smaller Country edition (default, country-level only) or the larger City edition (adds region/subdivision data for sub-national province/state routing such as Quebec Law 25). City is a much larger download; pick it only if you rely on region-level routing.

Triggered when: you enter a MaxMind license key in Settings and start the database download.

Data sent: your server IP address, the license key you provide, and standard HTTP request headers.

Service URL:
* https://download.maxmind.com/app/geoip_download

Terms of Service / Privacy Policy:
* https://www.maxmind.com/en/terms-of-use
* https://www.maxmind.com/en/privacy-policy

= ip-api.com =

Used as a fallback geolocation lookup for the optional geo-targeting and multi-banner geo-routing features, only when MaxMind is unavailable.

Triggered when: a frontend page renders the banner while geo-targeting / multi-banner geo-routing is enabled AND neither the Cloudflare CF-IPCountry header (opt-in) nor the MaxMind GeoLite2 database produces a result. The visitor's IP is sent to ip-api.com for country resolution; the resolved country code is cached in a transient (hash-keyed by IP) for one hour to avoid repeating the lookup.

Data sent: the visitor's IP address and standard HTTP request headers.

Service URL:
* http://ip-api.com/json/{ip}?fields=countryCode

Terms of Service / Privacy Policy:
* https://ip-api.com/docs/legal

= ipinfo.io (geo-routing v2 only) =

Used for VPN/proxy/Tor detection when the admin opts in to enhanced geo detection via Settings → Geo-routing → ipinfo settings. The plugin sends the visitor IP to ipinfo.io to determine whether the visitor is masking their location; when VPN is detected, the most-protective rule-set is applied regardless of the visitor's apparent country.

Triggered when: a frontend page renders the banner AND the admin has configured an ipinfo API key AND has explicitly attested to having a DPF / SCC / DPA agreement with ipinfo.io for cross-border data transfer of EU/UK visitor IPs. Without the admin opt-in, ipinfo is NEVER called.

Data sent: the visitor's IP address (in cleartext, as required by ipinfo's lookup contract), the configured API key, and standard HTTP request headers. The plugin caches the VPN classification locally for 24 hours hash-keyed by the IP (with monthly salt rotation) so repeat visitors do not trigger fresh calls.

Service URL:
* https://ipinfo.io/{ip}/privacy

Terms of Service / Privacy Policy:
* https://ipinfo.io/terms-of-service
* https://ipinfo.io/privacy-policy
* DPA (Data Processing Agreement) available on request: https://ipinfo.io/contact

= Plugin REST endpoint /faz/v1/banner (public) =

Used by the plugin's own front-end JavaScript (`script.js`) to fetch the per-language / per-country banner payload after the page has loaded. This is an INTERNAL endpoint hosted by the plugin on the same WordPress install — no third-party network call leaves the visitor's browser to a remote service. It is documented here only because the response carries `bannerSlug` and `activeLaw`, two strings that describe which banner profile and which legal regime (gdpr / ccpa) currently applies to the visitor.

Triggered when: the front-end banner script bootstraps on a page that has multi-banner geo-routing active.

Data sent: only what the visitor's browser already sends with any page request to the same origin. The plugin does not forward the request to any remote service.

Service URL:
* https://{your-site}/wp-json/faz/v1/banner

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

The only outbound HTTP requests this plugin makes are the six documented above (Open Cookie Database, IAB GVL, MaxMind, ip-api.com fallback, ipinfo.io VPN detection (opt-in), AMP CDN). All six are gated behind explicit administrator action or an enabled feature. The internal `/faz/v1/banner` endpoint described above is hosted by this plugin on the same site — no third-party network call leaves the visitor's browser to a remote service.

== Cache Plugin Compatibility ==

When multi-banner geo-routing (1.14.0+) is active, the rendered HTML can legitimately vary by visitor country. This plugin asks the page-cache layer to bypass caching on those requests by emitting:

* `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
* `Pragma: no-cache`
* `X-LiteSpeed-Cache-Control: no-cache`
* `Vary: CF-IPCountry` (when the trust filter `faz_trust_cf_ipcountry_header` is enabled)
* `DONOTCACHEPAGE`, `DONOTCACHEOBJECT`, `DONOTCACHEDB` PHP constants (industry-standard bypass hints)
* `do_action( 'litespeed_control_set_nocache', ... )` when LiteSpeed Cache is installed

= Verified compatible (no extra configuration needed) =

* **LiteSpeed Cache** — uses the explicit `litespeed_control_set_nocache` action + `X-LiteSpeed-Cache-Control` header.
* **WP Rocket** — honors `DONOTCACHEPAGE` natively.
* **W3 Total Cache** — honors `DONOTCACHEPAGE` / `DONOTCACHEOBJECT` natively.
* **WP Super Cache** — honors `DONOTCACHEPAGE` natively.
* **Hummingbird (WPMU DEV)** — honors `DONOTCACHEPAGE` natively.
* **Cloudflare APO** — honors the `Cache-Control: no-store` header. With CF in front, also enable the trust filter so the `Vary: CF-IPCountry` header is emitted and CF caches per-country variants instead of bypassing entirely.

= Known limitations =

* **CDNs without origin Cache-Control honoring** (e.g. some legacy CloudFront configurations) — verify the response Cache-Control header reaches the edge. If not, add a CF-IPCountry or country-based cache key rule at the CDN level.
* **Minor / regional cache plugins** (Comet Cache, Cachify, Swift Performance Lite) — not formally tested. Most still honor `DONOTCACHEPAGE`; verify by inspecting the response Cache-Control on a country-targeted page.

Override the bypass logic per request via the `faz_country_dependent_banner_output` filter (return false to force the cache to ignore the country dimension on a specific URL).

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

Yes. The Languages page lets you select from 180+ available languages. Each banner you create carries its own translations for every language you enable — the banner text (title, description, button labels) is stored per-language inside the banner row, and the language displayed to the visitor is resolved client-side from `navigator.languages`. WPML / Polylang URL-based language switching is auto-detected and always cache-safe.

= Does multi-banner mean one banner per language? =

No — multi-banner routing is per visitor **country** (e.g. GDPR vs CCPA, EU vs US), not per language. Each banner row carries its OWN multilingual content: title, description and button labels translated for every language you support. The visitor's country selects the banner; the visitor's browser language then selects which translated strings to render inside that banner. So an install with one EU-targeted GDPR banner (carrying English + Italian + German + French translations) and one US-targeted CCPA banner (carrying English + Spanish translations) needs only TWO banner rows, not eight. See the "Multi-banner geo-routing vs multilingual content" section in the Description for the full architecture.

= Can users change their consent after accepting? =

Yes. A floating revisit widget appears on every page, letting visitors reopen the preference center and change their choices at any time.

= Is the banner accessible? =

Yes. The banner supports full keyboard navigation (Tab, Enter, Escape), proper ARIA labels, and is responsive down to 375px viewports. Buttons have equal visual prominence to avoid dark patterns.

= Does it work with caching plugins? =

Yes. The consent banner is rendered via JavaScript from a cached template, so it works with all major caching plugins (WP Super Cache, W3 Total Cache, LiteSpeed Cache, etc.).

= Does the plugin send any data home or collect telemetry? =

No. The plugin contains no telemetry, no analytics beacon, and no "phone home". Dashboard numbers are computed locally from your own `wp_faz_pageviews` and `wp_faz_consent_logs` tables. Every outbound request that *can* happen is documented in the "External services" section and is gated behind an explicit admin action.

= Where is the source of the bundled minified JavaScript? =

The minified files we ship are `frontend/js/script.min.js`, `frontend/js/gcm.min.js`, `frontend/js/tcf-cmp.min.js` and `frontend/js/a11y.min.js`. The full, unminified sources live next to each one as `script.js`, `gcm.js`, `tcf-cmp.js` and `a11y.js`, and the build command `npm run build:min` rebuilds them all with `terser`. No obfuscation is used.

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
6. **IAB TCF v2.3 Global Vendor List** -- Browse the bundled GVL, filter by purpose, and select which vendors your site works with. Full Transparency and Consent Framework v2.3 API and UI, no cloud required. Note: broadcasting valid TC strings to vendors requires your own registered IAB Europe CMP ID; until one is configured the TCF layer stays inactive by design.
7. **Consent logs** -- Local, tamper-resistant audit trail of every visitor consent: status, categories, hashed IP, URL and timestamp. Filter, search and export to CSV for DPIA / audits.
8. **Google Consent Mode v2** -- Default vs. granted state for `ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`, `functionality_storage`, `personalization_storage` and `security_storage`. Works with GTM and gtag.
9. **Languages** -- Manage active languages and the default banner language. Works alongside WPML / Polylang; Italian, Dutch, German, French and Czech translations ship out of the box.
10. **Settings** -- Global controls: enable/disable the banner, exclude specific pages, cross-domain consent forwarding, hide from bots, GTM dataLayer events, consent log retention and scanner limits.

== Changelog ==

The full changelog (every release back to 1.0.0) lives at:
https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/blob/main/CHANGELOG.md
and on the GitHub Releases page:
https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/releases

= 1.18.2 =
* Change: the experimental opt-in features added in 1.18.0 (per-service / per-cookie consent toggles and the `faz_geo_ruleset_runtime` runtime geo-routing) are temporarily disabled pending a correctness rework — they did not, when enabled, deliver the granular guarantees their UI implied. They are now hard-off at their entry points. The default category-level consent flow (the path covered by the compliance suite) is byte-for-byte unchanged.
* Change: per-service / per-cookie toggles are hidden in Settings and forced off. As shipped a denied cookie was not enforced server-side or on reload, the granular decisions were not written to the consent log, a large override set could exceed the browser's ~4 KB cookie limit, and the list showed catalogue wildcards rather than detected cookies.
* Change: runtime geo-routing no longer applies a resolved ruleset to the live banner (a CCPA-style jurisdiction was mapped to a GDPR banner without rendering its Do-Not-Sell / GPC / sensitive-opt-in obligations). Catalogue-based multi-banner geo-routing — choosing which saved banner to show per country — is unaffected.
* Fix: corrected an overstated per-cookie help text that claimed a denied cookie "is deleted whenever it appears." That enforcement only ran client-side at save time and did not persist, so the claim was inaccurate.

= 1.18.1 =
* Fix: the Cookies admin "Scan Site" and "Auto-categorize" dropdown menus are no longer clipped by the card's rounded-corner overflow — the menu now drops over the table below and shows all options.

= 1.18.0 =
* Feature: geo-routing runtime (opt-in). With the `faz_geo_ruleset_runtime` filter enabled, the resolved per-jurisdiction ruleset drives the live banner — pre-consent default state, script blocking, Google Consent Mode v2 defaults and banner selection follow the visitor's jurisdiction (GDPR, CCPA/CPRA, Quebec Law 25, POPIA, LGPD, …). Off by default: existing sites are unchanged until you enable the filter.
* Feature: GeoLite2 edition choice (Country vs City) under Settings > GeoIP Database. Country (~10 MB) stays the default; City (~60 MB) adds province/state detection needed by sub-national rules such as Quebec's Law 25. The UI explains the size/use trade-off, and the existing Country download keeps working exactly as before.
* Feature: granular per-cookie consent toggles (opt-in, requires per-service consent). A nested toggle for each cookie a service declares, so visitors can opt out of specific cookies within an accepted service. A denied cookie is deleted whenever it appears — the same enforcement used for per-service opt-out.
* Fix: GeoLite2 database activation is validated and atomic. A corrupt or wrong-edition download is rejected instead of silently breaking lookups; the previous database is preserved on error, and the edition preference is saved only after a successful download.
* Translations: all six bundled locales (Italian, French, German, Dutch, Croatian, Czech) completed and re-synced (1144 strings each).
* Hardening: per-cookie consent keys escape special characters so an exotic custom cookie name can't corrupt the consent cookie; runtime geo-routing custom saves honour the visitor's per-category toggles and fail closed when an opt-in ruleset has no matching banner; the GeoLite2 edition setting is whitelisted on save.

= 1.17.2 =
* Feature: new `[faz_cookie_settings]` shortcode renders a "Manage consent preferences" button that re-opens the consent preference center on any page where the banner runtime is active (e.g. inside the generated Cookie Policy or a footer) — the equivalent of the common `[cookie_settings]` shortcode. It needs `script.js` + the preference-center template, so it stays inert on pages excluded from the banner (the admin snippet documents this). Accepts optional `text` (custom label) and `class` (extra CSS classes, sanitised) attributes. No inline JS: a single delegated click handler in the already-enqueued banner script binds every `.faz-cookie-settings-btn` / `[data-faz-open-preferences]` trigger to the same opener the banner's settings button uses. The button is styled to match the banner's primary button and inherits the colours configured in Banner > Colours (the `--faz-accept-button-*` custom properties), so it tracks the admin's theme automatically. A copyable shortcode snippet is shown on the Banner > Advanced > Revisit Consent card for discoverability.
* Feature: Bulgarian (bg) added to the Cookie Policy generator as the 7th language — full gdpr-strict, ccpa-california and lgpd-brazil scaffolds, the language dropdown, jurisdiction / language display names, retention labels and the footer disclaimer. `bg_BG` site installs now resolve to the Bulgarian policy automatically.
* Fix: `[faz_cookie_policy_complete lang="…"]` ignored the language when the attribute value contained curly / smart quotes (e.g. `lang=”it”`), which the WordPress block and visual editors substitute for straight quotes — the shortcode parser kept the curly quote inside the value so it never matched a supported language and fell back to English. The `lang` and `jurisdiction` attributes are now sanitised to `[A-Za-z0-9_-]` after `shortcode_atts`, so smart quotes, stray spaces and other punctuation are stripped before matching (the underscore is kept so the locale form `lang="pt_BR"` still resolves to pt-BR). Reported by Bozhidar.
* Fix: the "Last updated" date in generated Cookie Policies localised its month name to the *site* locale instead of the policy's template language (an Italian policy on an English site showed "June" not "giugno"). Month names are now rendered from a per-template-language table with the correct date format per language (en `June 3, 2026`, de `3. Juni 2026`, es/pt-BR `3 de junio de 2026`, bg `3 юни 2026 г.`, it/fr `3 giugno 2026`).
* Fix: LiteSpeed Cache compatibility. The anti-FOUC banner reveal (hide `[data-faz-tag]` until the script adds `faz-ready` to `<html>`) broke when LiteSpeed's CSS Combine moved the inline guard style into a combined stylesheet — the banner stayed invisible and its controls non-functional. The guard `<style>` and the reveal markup now carry `data-no-optimize` / `data-noptimize` so LiteSpeed (and Autoptimize) leave them inline. Reported by Bozhidar.
* Feature: per-element banner colours. The Banner > Colours tab gained individual colour pickers for the description "show details" link and the category toggles; the values flow through `faz_sanitize_color` and are emitted as CSS custom properties on both `#faz-consent` and every `.faz-modal` sibling so the preference-center modal inherits them too.
* Fix: GVL auto-detect status now reports "already in session" correctly when zero new vendors are added (the `added.length === 0` branch previously reused the suggested count). Accessibility: aria-labels on the new colour-picker controls. readme.txt now lists all four shipped minified frontend bundles.
* i18n: regenerated the `faz-cookie-manager.pot` template and re-synced the bundled `.po` catalogs so the new admin / shortcode strings (the `[faz_cookie_settings]` label and help, the "Bulgarian" option) are translatable. Translations of the brand-new strings into the shipped locales are still pending, so the compiled `.mo` files are unchanged.
* Compliance (GDPR/ePrivacy/CCPA): Global Privacy Control is now actually honoured. When a visitor's browser asserts GPC and the banner's "Respect GPC" toggle is on, the plugin auto-applies a law-aware opt-out (reject non-necessary for GDPR-family laws; deny sale/sharing categories for CCPA), suppresses the banner, and records it with a `gpc` marker — previously the toggle was saved but never read. New CCPA banners ship with GPC enabled by default (CPPA Reg. §7025 mandates it with no admin opt-in) and existing CCPA banners are migrated on upgrade.
* Compliance (Google Consent Mode v2): the "non-personalized ads fallback" no longer grants `ad_storage` after a reject. `ad_storage` / `ad_user_data` / `ad_personalization` stay denied and the plugin signals `npa = 1`, so Consent Mode v2 serves cookieless non-personalized ads — lawful in the EEA/UK/CH with no geofencing.
* Compliance (IAB TCF): the CMP no longer activates without a registered IAB Europe CMP ID (IDs 0 and 1 are reserved/invalid), so a TC string carrying an invalid CmpId is never broadcast. PurposesLegitimateInterest encoding fixed — LI is established by default and cleared only by an explicit objection, not by declining consent. The `__tcfapi` postMessage reply now targets the calling frame's origin instead of "*".
* Compliance (pre-consent blocking): the default "never block before consent" whitelist was narrowed to security / anti-abuse endpoints only — Google Fonts, Google Maps, the YouTube/Translation APIs, OAuth and generic CDNs (jsDelivr, unpkg) are no longer exempt by default. Domain whitelist matching is now host-anchored so a look-alike host (e.g. `evilgoogleapis.com`) can no longer satisfy a `googleapis.com/` entry. Pre-consent pageview analytics are aggregate-only — the former sessionStorage visitor id and `session_id` field were removed (and were never read back).
* Compliance (EDPB Guidelines 03/2022 — deceptive design): Accept and Reject ship with equal visual weight by default (Reject is no longer a low-emphasis outline); the "uncategorized" cookie bucket is no longer pre-ticked; scroll / navigation / idle no longer imply consent in the default config; and the "GDPR Strict" preset no longer uses green-accept / red-reject traffic-light colouring.
* Compliance (Garante "Linee guida cookie" 2021): the consent-cookie lifetime is capped at 182 days for opt-in (GDPR-family) banners regardless of the configured value; CCPA banners default to 365 days (CPRA bars re-asking an opt-out more than once per 12 months).
* Compliance (CCPA/CPRA): the `[faz_do_not_sell]` opt-out is relabelled "Do Not Sell or Share My Personal Information" (CPRA covers "sharing" for cross-context behavioural advertising); the per-category `sell_personal_data` default is now consistent across the schema and object layers; and a dedicated `share_personal_data` flag now sits alongside it (DB column, REST field, import/export, and a "Sale / Sharing" toggle pair in the Cookies editor) so sharing is distinguishable from a sale. A category is opt-out-able when sold OR shared.
* Compliance (CCPA/CPRA): server-side script blocking is now opt-out-aware. Under CCPA/CPRA the banner is a NOTICE, not a gate — sale/sharing-flagged scripts run on first visit and are blocked only once the visitor exercises the "Do Not Sell or Share" opt-out; opt-in (GDPR/ePrivacy) banners still block every non-necessary script until consent.
* Fix (Cookie Policy): the generated policy no longer prints its own "Cookie Policy" H1 by default — the WordPress page it sits in already has a title, so the second heading duplicated it. The policy still names itself in the intro text; add show_title="true" to the shortcode to bring the heading back for a title-less embed. The policy's <code> elements (cookie names) are also reset to a neutral, border-less, transparent token so a theme's global code{} styling (coloured background/border) doesn't bleed into the legal document.
* Compliance (Paid Memberships Pro): the "pay-or-accept" auto-grant is now (1) a revocable default — a member who opens the preference center and changes or withdraws a category keeps that choice across page loads instead of having it silently re-granted; (2) recorded in the consent log under a distinct "pmp_grant" status so the audit trail never conflates a membership-basis grant with an explicit, freely-given consent; and (3) accompanied by cleanup of residual vendor/TCF state for ex-members (via a value-only "fazVendorSource=pmp" marker) without ever clearing a standard visitor's own cookies.
* Consent engine: applicable-law routing is now paradigm-based (opt-in vs opt-out), so LGPD (Brazil) and other opt-in regimes are handled as opt-in end-to-end and can never be misrouted into the opt-out path.
* Hardening: fixed a wrong `function_exists()` guard that could leave the URL-path matcher undefined; a scalar `languages.selected` no longer fatals on PHP 8; bulk banner save and settings import are transaction-safe, and an empty import set no longer wipes the categories/cookies tables; the Cookie Policy generator no longer double-encodes HTML entities; the unfiltered cookie query is bounded; the multisite network overview is cached; and Microsoft UET/Clarity consent tolerates renamed categories.

= 1.17.1 =
* Fix: empty cookie categories are no longer listed in the preference center or the revisit banner. A category with no cookies has nothing for the visitor to consent to, but the preference-center modal and the revisit widget still rendered every category. The empty-category removal previously only applied to the inline category-preview chip and was skipped entirely in revisit mode; it now also removes the modal accordion item, in both normal and revisit mode (the "necessary" category is always shown). Consent recording is unaffected — accept/reject/save iterate the in-memory category list, not the DOM.

= 1.17.0 =
* Feature: Auto-detect from the cookie scanner. The IAB TCF Global Vendor List page and the Cookie Policy "Third-party services" tab each gain an "Auto-detect from cookie scan" button that pre-ticks the vendors / services whose tracking domains the scanner has actually observed on the site. Matching uses a bundled domain->vendor / domain->service map with a dot-prefix suffix guard (`.notgoogle.com` cannot match `google.com`); only scanner-discovered rows (`discovered = 1`) feed suggestions, never manually-added cookies. The REST endpoints (`gvl/suggest`, `cookie-policy/suggest-services`, `cookie-policy/detected-services`) are gated behind a `manage_options` capability check and are read-only (the write endpoints alongside them additionally verify a nonce).
* Feature: Redundant geo-routing cache-bypass warning. When geo-routing is enabled with "hide banner outside target regions" as the default behaviour but no target regions are selected and no banner carries a target-countries list, the plugin emits `Cache-Control: no-store` on every page for no functional benefit — the per-country gate can never actually fire. A dismissible admin notice now flags this configuration and offers a one-click "Disable Geo-routing" action plus a 30-day dismiss. When target regions are configured the banner genuinely varies by visitor country, so the no-store is justified and the notice stays hidden.
* Accessibility (WCAG 2.2 AA): recording a consent choice now announces "preferences saved" through a visually-hidden `aria-live` status region (SC 4.1.3); banner slide-in animations respect `prefers-reduced-motion`; the close-button image is marked decorative (`alt=""`) since the button already carries a localized `aria-label`; and the Banner > Colours admin tab gained a live colour-contrast checker that warns (non-blocking) when any text/background pair drops below the 4.5:1 AA minimum.
* Hardening: the scanned-cookie suggest/detected pair derives `scan_available` and the matched service/vendor list from a single `SELECT` (closes a TOCTOU where a concurrent delete could make the two disagree); `SHOW TABLES LIKE` existence probes wrap the table name in `$wpdb->esc_like()`; the cookie-name wildcard matcher was reimplemented without a dynamic `RegExp` (zero ReDoS surface); cross-domain consent forwarding validates `event.origin` against an explicit allow-list before reading message data; the `deepGet` / `deepSet` / `setPathValue` dot-path helpers were refactored to prototype-pollution-safe forms; and CI pins `@wordpress/env` and Plugin Check to fixed versions for reproducible runs.
* Fix: removed the redundant `({{COOKIE_POLICY_URL}})` parenthetical from the intro paragraph of all 18 Cookie Policy template scaffolds (gdpr-strict, ccpa-california, lgpd-brazil x en/it/fr/de/es/pt-BR) — it duplicated a link already present elsewhere on the rendered page; the placeholder itself remains supported for section overrides.
* i18n: refreshed `faz-cookie-manager.pot` and the bundled `.po` / `.mo` catalogs (cs_CZ, de_DE, fr_FR, hr_HR, it_IT, nl_NL) with the new admin strings.

= 1.16.2 =
* Fix: Cookie Policy generator `[faz_cookie_policy_complete]` — Gooloo feedback round. (1) `{{COOKIE_POLICY_URL}}` no longer leaks `?preview_id=&preview_nonce=` query strings from the WordPress preview flow into the public policy text. (2) Cookie inventory now renders as collapsible HTML5 accordion (`<details>/<summary>`) with a real `<table>` per category — previously a flat `<dl>` produced a 700+-line wall of definition pairs. (3) Footer disclaimer is now admin-configurable (toggle + custom text) and wrapped in `<div>` instead of `<footer>`. (4) Empty list-item rows like `**Register / USt-ID:**` are removed when the corresponding placeholder is blank. (5) German DPO label dropped the non-standard "(DSB)" acronym; Italian, French, Spanish and Portuguese GDPR scaffolds dropped the redundant "(DPO)" suffix; "Supervisory authority" section with the European Data Protection Board reference removed from all six GDPR templates. (6) `Google Ads` and `Criteo` added to the third-party services allowlist (previously missing). (7) WordPress-internal cookies (`wp-settings-*`, `wordpress_logged_in_*`, the dedicated "wordpress-internal" admin category) are now excluded from the public policy, matching the same filter already applied to the consent banner.

= 1.16.1 =
* Fix: Cookie Policy generator (`[faz_cookie_policy_complete]`) was rendering literal JSON like `{"en":"Functional"}` for category names, category descriptions, cookie descriptions, and cookie durations on multilingual installs. Root cause: `build_cookie_list_html()` bypassed the model getters with a JOIN'd raw `SELECT *` and called `esc_html()` directly on i18n-encoded JSON columns. Added a private `decode_i18n_text()` helper that mirrors `Cookie_Table_Shortcode::localize_category_name()` — pick active language, fall back to `en`, then to the first non-empty entry. Description columns now flow through `wp_kses_post()` so the inline `<p>` tags they may contain survive. Reported by James in the wp.org support thread "Performance Impact???".

= 1.16.0 =
* Feature: Cookie Policy Generator (Spec 002). New admin tab "Cookie Policy" + new `[faz_cookie_policy_complete]` shortcode renders a jurisdiction-aware, multi-language Cookie Policy from a template scaffold filled with the admin's company data. Covers GDPR (EU/EEA/UK), CCPA/CPRA (California), LGPD (Brazil) in six languages (en, it, fr, de, es, pt-BR) — 18 scaffolds total. Auto-populated cookie inventory pulled live from `wp_faz_cookies` so additions via the scanner reflect at the next render. Non-removable disclaimer at the bottom of every generated policy makes explicit that templates are starting points, not legal advice.
* Feature: REST API `faz/v1/cookie-policy/*` (`/settings` GET/POST, `/preview` POST) — `manage_options` + nonce. Preview renders without persisting so admins can iterate inside a sandboxed-iframe modal.
* Feature: Policy version hash emitted as `<meta name="faz-policy-version">` for future material-change re-prompt detection (display-only fields excluded from the hash so the version doesn't drift daily).
* Filter: `faz_cookie_policy_data` lets site builders inject custom placeholders before template substitution.
* Compatibility: long-standing `[faz_cookie_policy]` shortcode (with `site_name` / `contact` / `show_table` attributes) and the standalone `[faz_cookie_table]` shortcode both unchanged and supported. The new generator is opt-in via the `_complete` suffix.
* Fix: Frontend focus-trap listener accumulation (issue #124). Reopening the preference center repeatedly no longer stacks keydown handlers; `_fazAttachFocusLoop` now tracks attached handlers per `(element, direction)` slot in a module-scope WeakMap and replaces previous listeners before attaching new ones.
* Fix: Plugin Check compatibility — removed `wp_cache_flush_group()` / `wp_cache_supports()` fast-path on cache invalidation (both require WP 6.1+, plugin minimum stays at WP 5.0). Manual `wp_cache_delete` loop replaces it; cache epoch bump on the line above is what actually invalidates live reads.
* Compatibility: verified against WordPress 7.0 (May 2026 final). No code changes required: plugin does not use `the_author_meta`/`get_the_author_link`, all three Gutenberg blocks already declare `api_version: 3`, plugin does not bundle CodeMirror nor use the Interactivity API. `Tested up to` bumped to 7.0.

= 1.15.0 =
* Feature: Geo-routing v2 — jurisdictional rule-sets. 47 ruleset JSON files cover EU (gdpr-strict + 7 country-specific), UK (uk-gdpr-pecr), 19 US states with privacy law (CCPA + CPA + CTDPA + VCDPA + UCPA + ICDPA + TIPA + MCDPA + TDPSA + OCPA + FDBR + Delaware + NJDPL + NHPL + KCDPA + MODPA + MCDPA + RIDTPPA + ICDPA), 18 international (LGPD/PIPL/APPI/PIPA/POPIA/PDPA-Singapore/Thailand/Vietnam/India/Malaysia/AU/NZ/UAE/KSA/Israel/Turkey/Canada/Quebec Law 25), plus most-protective fallback for unknown / VPN visitors.
* Feature: New `admin/modules/geo-routing/` module with REST API (`/faz/v1/geo/*`), admin tab UI (status / coverage / overrides / preview / ipinfo / PIPL), field-by-field per-country override editor using dot-notation deltas.
* Feature: VPN/proxy/Tor detection via ipinfo.io (opt-in, gated by admin DPF/SCC attestation). When VPN detected, the most-protective ruleset is forced regardless of apparent country. API key encrypted at rest.
* Feature: PIPL cross-border attestation UI (audit-trail only).
* Migration: `wp_faz_consent_logs` schema gains 7 NULL-default columns (`country_at_consent`, `region_at_consent`, `ruleset_id_at_consent`, `signal_gpc_received`, `signal_dnt_received`, `tc_string`, `gpp_string`). Online DDL on MySQL 5.7.6+ / MariaDB 10.3+.
* External Services: new `ipinfo.io` entry documents the opt-in VPN detection lookup.

= 1.14.3 =
* Filter: new `faz_country_detection_consensus` filter, introduced with **2 arguments** (`$require_consensus`, `$votes`). When the filter resolves to `true` AND at least two detection sources disagree on the visitor country, `Geolocation::detect_country()` returns an empty string (fail-open — banner is shown to everyone). Off by default to preserve the CF-first priority order. Plugins that legitimately need the visitor IP for their own logic should hook `faz_visitor_country` instead, which exposes it for trusted overrides and test fixtures.
* Fix (F101–F112, adamsreview review#2): transactional delete with InnoDB row-lock-promoted fallback default; multisite-aware uninstall sweep that honours per-site opt-in and the `FAZ_REMOVE_ALL_DATA` constant; banner_id pollution fixed by removing the post-save `$wpdb->insert_id` re-read; LSCache `Vary: CF-IPCountry` emitted only when the `faz_trust_cf_ipcountry_header` filter opts in; AMP geo-resolution no longer buckets GB into the `eu` regional set.
* Fix (F301–F308 + CodeRabbit#1#2, adamsreview review#3): cache-poisoning race in `promote_fallback_default` closed by moving `delete_cache()` to callers (post-COMMIT); both fallback SELECTs now use `FOR UPDATE` and prefer non-default active peers; `faz_cookies` + `faz_cookie_categories` enforced to InnoDB on install AND migrated on upgrade so settings-import START TRANSACTION calls are no longer silent no-ops on legacy MyISAM hosts; uninstall network sweep now also fires under bare `FAZ_REMOVE_ALL_DATA` when `get_sites()` is empty; cache epoch generation switched to `sprintf('%.6F', microtime(true))` for true microsecond resolution; create_item slug-probe now attempts a focused UPDATE retry before falling back to the cache-invalidate tail.
* Fix (R4-S001–S004, adamsreview review#4): `update_item()` now wraps its UPDATE + invariant section in a `START TRANSACTION` so the `FOR UPDATE` lock in `promote_fallback_default` actually serializes (was a no-op under autocommit, leaving the update path with the same race F302 closed for delete); create_item slug-probe runs the invariant tail unconditionally on slug mismatch so a successful focused-UPDATE retry no longer bypasses the at-most-one-default invariant; `clear_default_on_others()` no longer self-flushes the cache (caller's responsibility, mirrors F301); F303 ALTER ENGINE loop now records partial-migration failures in `faz_innodb_migration_pending` instead of silently bumping `db_version` past 1.14.3.
* Fix: missing-banner notice in admin when `?banner_id=…` does not resolve (deleted row, stale bookmark, phantom redirect) — the editor body is hidden and a recovery CTA points at the install's default banner.
* Fix: prefcenter renders every visible category even when its cookie list is empty (regression introduced by the audit-list refactor that early-returned on empty cookies).
* Fix: empty-state preference-center category wrapper now matches the populated-state DOM shape (`<div class="faz-table-wrapper">`) so CSS targeting the table wrapper applies uniformly across empty and populated categories.

= 1.14.0 =
* Feature: Multi-banner geo-routing (refs #103). New schema columns `target_countries` and `priority` on `wp_faz_banners` let admins serve different banners per visitor country — e.g. a Reject-mandatory GDPR banner to EU/EEA/UK and a CCPA-style banner with the close (X) button to US visitors, picked automatically by `Controller::get_active_banner_for_country()` from the Cloudflare CF-IPCountry header (opt-in) or MaxMind/ip-api.com fallback.
* Feature: Per-banner override of the EDPB/Garante close-button dark-pattern auto-hide (`settings.allowCloseButtonWithReject`). Default false preserves the compliance behaviour; opting in is documented as an EU/EEA/UK violation but unblocks non-EU jurisdictions where Accept + Reject + X is legal.
* Feature: Cache busting for country-dependent output via `DONOTCACHEPAGE`/`DONOTCACHEOBJECT`/`DONOTCACHEDB` constants + `Cache-Control: no-store` + `Vary: CF-IPCountry` (with the trust filter on) so CDNs and full-page caches do not serve the wrong banner to the wrong country.
* Feature: AMP `<amp-consent>` resolver is now country-aware via `Geolocation::get_visitor_country()` with the same geo guards as the classic JS flow.
* Feature: Scope-change consent invalidation. Consent cookies now carry `__scope.banner` and `__scope.law` so a visitor that crosses a jurisdiction (CCPA → GDPR) re-prompts instead of inheriting consent from a different legal regime.
* Fix: `banner_default` mutual-exclusion finally enforced server-side — saving a banner with the default flag clears it on every peer row (matches the admin help text). Without this, more than one banner could simultaneously hold the flag and the fallback picker was non-deterministic.
* Fix: `Controller::get_active_banner()` preserves its pre-1.14.0 contract for third-party callers. An install with a single status=1, country-targeted, non-default banner now still receives that banner back when the call passes no country.
* Fix: `has_country_dependent_banners()` and `Frontend::is_geo_blocked()` iterate the entire `ruleSet`, not just the first entry. A ruleSet like `[{code:ALL}, {code:US}]` is now consistently classified between the cache-vary headers and the runtime show/block decision.
* Fix: `Frontend::send_geo_cache_headers()` gates on `faz_is_front_end_request()` so REST API / heartbeat / sitemap / robots requests no longer trigger the country-dependent DB chain on every poll.
* Fix: `is_country_dependent_output()` also marks IAB-TCF output as country-dependent (TCF `gdprApplies` is derived from visitor country at render time and must not be served from a shared page cache).
* Fix: `update_db_350` clears `faz_banners_table_version` before re-running `install_tables()`, so dbDelta actually adds the new `target_countries` + `priority` columns on upgrade.
* Fix: Geolocation rejects the Cloudflare 'XX' (anonymous proxy / unknown) sentinel both on the CF-IPCountry branch and after the `faz_visitor_country` filter — a third-party filter implementer reintroducing 'XX' no longer leaks it into geo-routing.
* Fix: `_fazConsentScopeChanged()` no longer invalidates valid pre-1.14.0 consent on the first page load after upgrade. Absent scope keys are treated as "upgrade case, no scope info known" and the existing consent stands.
* Compatibility: New `Banner::set_target_countries()` / `set_priority()` / `get_target_countries()` / `get_priority()` accessors with normalisation (upper-case, dedup, `^[A-Z]{2}$` validation, non-negative integer clamping). REST schema exposes both fields on `/faz/v1/banners/{id}` with `[A-Z]{2}` pattern validation.

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

= 1.18.2 =
Recommended for anyone who enabled the 1.18.0 opt-in features. The per-service / per-cookie consent toggles and the runtime geo-routing filter are temporarily disabled pending a correctness rework — they did not deliver the granular guarantees their UI implied. Default category-level consent is unchanged.

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
