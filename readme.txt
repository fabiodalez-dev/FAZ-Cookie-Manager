=== FAZ Cookie Manager ===
Contributors: fabiodalez
Donate link: https://buymeacoffee.com/fabiodalez
Tags: cookie, gdpr, ccpa, consent, privacy
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 1.27.1
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

* **Cookie scanner** -- scans your site directly from your browser. No external service, no API limits, no waiting.
* **Finds the cookies a JavaScript scanner cannot see** -- cookies set by PHP before the page renders, including `HttpOnly` ones your browser hides from scripts, are captured from the server response itself: from pages, AJAX, REST calls and sub-resources, then replayed across the URLs the crawl actually visited. Those are exactly the cookies that get set *before consent*, so a declaration built without them is incomplete.
* **Cookie Policy generator** -- a jurisdiction-aware policy page (GDPR / CCPA / LGPD / POPIA) built from your own company details and the scanner's live cookie inventory, published with `[faz_cookie_policy_complete]`. Ships in en, it, fr, de, es, pt-BR, bg and cs, and every section can be rewritten per jurisdiction and language.
* **Consent logging with CSV export** -- every consent is recorded locally in your database. Export anytime for audits.
* **Google Consent Mode v2** -- all 7 consent signals sent to Google tags. No premium required.
* **IAB TCF v2.3** -- full Transparency and Consent Framework API and UI. Operating as a recognised CMP needs your own registered IAB Europe CMP ID; without one the TCF interface stays inactive and no TC string is produced, so invalid signals are never broadcast to vendors.
* **Script blocking** -- tag any script with `data-faz-tag` to hold it until its category is accepted.
* **Geo-targeting and 180+ languages** -- serve the right banner per region and translate every string, or use a built-in translation.
* **Guided setup wizard** -- a first-run wizard detects your environment (multilingual plugin, page cache, WooCommerce, existing consent data) and configures jurisdiction-appropriate defaults, explaining each choice in plain language. Existing sites are treated as already set up and are never nagged.
* **A/B test your consent banner** -- run two or more existing banners with a persistent random split and read the accept rate per variant. Only active, independently compliant banners take part, so improving your wording can never quietly become a dark pattern. Off by default.
* **Schrems II transfer disclosure** -- flag per cookie that a service sends personal data to a country without an EU adequacy decision, with the safeguard you rely on. Worded neutrally: it states the fact and your described safeguard, and never claims that safeguard is legally sufficient. Off by default.
* **Age-appropriate consent (GDPR Art. 8)** -- an optional age-confirmation checkbox above the buttons. It gates only Accept, never Reject or withdraw, so the two keep equal weight. This is a self-declared affirmation and is not a substitute for the parental-consent verification Art. 8(2) requires. Off by default.
* **Ad-blocker resilience** -- keeps the legally required notice visible when a cosmetic filter list hides elements whose class contains "cookie" or "consent". A single deferred re-assert: no loop, no cookie wall. It protects a mandatory notice; it does not circumvent a privacy tool. Off by default.
* **Editable "Do Not Sell" opt-out text** -- customise the title, description and toggle label of the CCPA / US State Laws opt-out popup, per language.
* **E-commerce & payment friendly** -- a per-gateway opt-in (PayPal, Stripe, Square, Braintree, Klarna, Mollie, Amazon Pay) lets payment SDKs load before consent when you enable that gateway, so pre-consent blocking never breaks a payment button. Off by default; a real WooCommerce checkout/cart is exempt automatically.
* **Cache & object-cache compatible** -- purges and bypasses FlyingPress, LiteSpeed, WP Rocket, W3 Total Cache and more on save, epoch-invalidates Redis / Memcached object caches, and keeps WPML, Polylang, TranslatePress and Weglot banners in the right language behind a full-page cache. Details in the FAQ.
* **Microsoft UET/Clarity, revisit widget, accessibility** -- consent integration for Microsoft tags, a floating button so visitors can change their mind, and keyboard/screen-reader support throughout.

= Helps with these frameworks =

This plugin assists consent and privacy workflows. It does not itself create, provide, or guarantee legal compliance, and you remain responsible for the final configuration for your site and jurisdiction.

* **GDPR** (EU General Data Protection Regulation) -- Opt-in consent, granular categories, right to withdraw
* **CCPA / CPRA** (California Consumer Privacy Act) -- "Do Not Sell or Share" opt-out link
* **ePrivacy Directive** (EU Cookie Law) -- Consent-based script blocking support
* **Italian Garante Privacy** -- 6-month consent expiry setting and consent logging controls
* **EDPB Guidelines** -- No scroll-as-consent, no pre-checked categories, equal button prominence options
* **LGPD** (Brazil General Data Protection Law) -- Consent-based model
* **POPIA** (South Africa Protection of Personal Information Act) -- Conservative consent-based preset under s.11(1)(a); other s.11(1)(b)-(f) justifications require separate assessment

= Try it Live =

**[Try FAZ Cookie Manager in WordPress Playground](https://playground.wordpress.net/?plugin=faz-cookie-manager)** -- no account, no install, runs entirely in your browser.

= How it works =

1. Install and activate -- the cookie banner appears immediately with sensible defaults
2. Scan your site to detect cookies automatically
3. Customize the banner design, text, and colors to match your brand
4. Enable Google Consent Mode or IAB TCF if you use advertising tools
5. Monitor consent analytics on the dashboard

Core banner functionality runs on your WordPress site. Optional update/download features may contact GitHub, IAB Europe, MaxMind, ip-api.com, ipinfo.io (opt-in VPN detection), or the AMP CDN depending on which features you enable and use.

= Cookie Policy generator =

A dedicated **Cookie Policy** admin tab and the `[faz_cookie_policy_complete]` shortcode build a policy page from the cookies your site actually sets.

* **Jurisdiction-aware** -- GDPR (EU/EEA/UK), CCPA/CPRA, LGPD or POPIA, each with the legal references and sections that framework requires.
* **Auto-populated** -- the inventory renders live from the scanner, so a newly discovered cookie appears with its category, duration and description.
* **Multilingual** -- en, it, fr, de, es, pt-BR, bg, cs; override per render with `lang="it"` or let the browser decide.
* **Editable per jurisdiction and language** -- replace any section with your own Markdown, placeholders included; leave one empty and it keeps receiving reviewed updates.
* **Your company data** -- name, address, DPO email, retention period. Never seeded from `admin_email` or `blogname`.
* **Honest by default** -- a localised disclaimer states the templates are a starting point, not legal advice.

The older `[faz_cookie_policy]` and `[faz_cookie_table]` shortcodes and the `faz/cookie-table` block are unchanged.

= Multi-banner geo-routing and multilingual content =

Two orthogonal features that combine freely: the visitor's **country** decides which banner is served, the visitor's **browser language** decides the translation shown inside it.

Geo-routing picks a banner per country — typically a strict GDPR banner for the EU/EEA/UK and a CCPA opt-out banner for California — resolving the country from Cloudflare's `CF-IPCountry` header (opt-in), then MaxMind GeoLite2, then ip-api.com. Translations live inside each banner and are resolved **client-side** from `navigator.languages`, so a country-targeted banner still works behind a full-page cache.

In practice that means two banner rows rather than eight: one EU banner holding English, Italian, German, French and Polish, one US banner holding English and Spanish.

== External Services ==

**Summary.** This plugin is cloud-free: consent is stored on your own site and there is no vendor account, dashboard or telemetry. Below is the full outbound picture, one heading per item -- the optional features that contact an external host (none run unless you enable them), the public REST endpoints this plugin exposes on your own domain, and a note on third-party domain strings that appear in the code as matching patterns and are never contacted. Each entry states its trigger, what leaves your server, and the provider's terms.

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

= ipinfo.io (optional live VPN detection and admin preview) =

The live geo-ruleset runtime applies jurisdiction-specific consent defaults and mandatory controls. If an administrator explicitly enables ipinfo.io, the jurisdiction pipeline may use it to classify a visitor as VPN/proxy/Tor and apply the most-protective fallback; the Geo-routing admin preview uses the same detector. Leave this integration disabled to keep visitor geolocation entirely on trusted headers and the local GeoLite2 database.

Triggered when: an administrator has configured an ipinfo API key, confirmed the transfer terms, and enabled the integration, then either a visitor-facing jurisdiction lookup or an admin preview runs the geo detector. Without that explicit opt-in, ipinfo is never called.

Data sent: the visitor IP address or the IP entered/resolved for an admin preview, the configured API key, and standard HTTP request headers. The result is cached locally for 24 hours hash-keyed by IP.

Service URL:
* https://ipinfo.io/{ip}/privacy

Terms of Service / Privacy Policy:
* https://ipinfo.io/terms-of-service
* https://ipinfo.io/privacy-policy
* DPA (Data Processing Agreement) available on request: https://ipinfo.io/contact

= Plugin REST endpoint /faz/v1/banner (public) =

Serves the banner configuration to the visitor's browser under Cache Compatibility Mode, so a full-page cache can store one visitor-invariant HTML document while the banner still resolves per request. Hosted by this WordPress install; no third-party host is involved.

Triggered when: Cache Compatibility Mode is enabled and a visitor loads a page with no stored consent.

Data sent: nothing about the visitor. The response carries banner text, categories and styling only.

Service URL:
* https://{your-site}/wp-json/faz/v1/banner

= Plugin REST endpoints /faz/v1/amp-consent/check and /update (public) =

Used by the plugin's AMP banner to reconcile the AMP consent cache with the first-party FAZ consent cookie. Both are hosted by the same WordPress install. Requests must pass AMP CORS provenance checks -- the publisher origin, or that publisher's exact HTTPS Google AMP Cache origin with the matching `__amp_source_origin`. Arbitrary origins, another publisher's cache subdomain, and requests without AMP provenance are rejected before consent can change. Sites on another registered AMP cache can add their own verified exact origin with the `faz_amp_consent_allowed_cache_origin` filter.

Triggered when: an AMP page checks an existing decision, or the visitor saves AMP cookie preferences.

Data sent: banner scope, consent state, per-category purpose choices, and the AMP-generated user ID that `amp-consent` includes. FAZ neither stores nor logs that ID, and does not derive its consent identifier from it. The update endpoint tries to synchronise the first-party cookie with `SameSite=None; Secure`; a browser that blocks third-party cookies may refuse it behind an AMP Cache, and the bridge then **fails closed** and asks again rather than claiming cross-origin parity it cannot guarantee.

Service URLs:
* https://{your-site}/wp-json/faz/v1/amp-consent/check
* https://{your-site}/wp-json/faz/v1/amp-consent/update

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

The source contains third-party domain names (`js.stripe.com`, `connect.facebook.net`, `googletagmanager.com` and others) purely as **string patterns**, for two purposes:

1. **Blocking detection** -- to recognise analytics, advertising and tracking scripts injected by the site's *other* plugins, so they can be held until consent. This plugin loads none of them itself.
2. **Explicit exceptions** -- no whole third-party plugin is whitelisted and no profiling resource is: Google Fonts, Google Maps, OAuth endpoints and generic CDNs stay blocked until consent. The only defaults are four anti-abuse challenge endpoints (reCAPTCHA, its gstatic assets, Cloudflare Turnstile, hCaptcha), which gate a form the visitor is actively submitting and are therefore strictly necessary. An administrator can add a narrow audited exception in Settings, and can remove the CAPTCHA defaults too.

Every outbound request documented above happens only when its feature is used. `/faz/v1/banner` is hosted by this plugin on the same site: no third-party call leaves the visitor's browser.

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

When multi-banner geo-routing is active, the rendered HTML can legitimately vary by visitor country. This plugin asks the page-cache layer to bypass caching on those requests by emitting:

* `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
* `Pragma: no-cache`
* `X-LiteSpeed-Cache-Control: no-cache`
* `Vary: CF-IPCountry` (when the trust filter `faz_trust_cf_ipcountry_header` is enabled)
* `DONOTCACHEPAGE`, `DONOTCACHEOBJECT`, `DONOTCACHEDB` PHP constants (industry-standard bypass hints)
* `do_action( 'litespeed_control_set_nocache', ... )` when LiteSpeed Cache is installed

**Verified compatible (no extra configuration needed)**

* **LiteSpeed Cache** — uses the explicit `litespeed_control_set_nocache` action + `X-LiteSpeed-Cache-Control` header.
* **WP Rocket** — honors `DONOTCACHEPAGE` natively.
* **W3 Total Cache** — honors `DONOTCACHEPAGE` / `DONOTCACHEOBJECT` natively.
* **WP Super Cache** — honors `DONOTCACHEPAGE` natively.
* **Hummingbird (WPMU DEV)** — honors `DONOTCACHEPAGE` natively.
* **FlyingPress** — the plugin purges FlyingPress's cached HTML pages automatically whenever a banner, cookie, category or setting is saved, so a change never keeps serving stale banner markup. Only the rendered HTML is purged (that is all a consent change alters); FlyingPress's site-wide preload crawl is not triggered, matching the purge-only behaviour of the other supported caches. FlyingPress does not honor `DONOTCACHEPAGE`, so the plugin also hooks its documented `flying_press_is_cacheable` filter to skip caching on country-dependent pages, matching the bypass every other supported cache gets. The consent scripts are excluded automatically from minification and from "Delay all JavaScript": recent FlyingPress 4.x exposes delay/defer exclusion filters (added around 4.16), while FlyingPress 5 receives the same keywords in its in-memory delay-exclusion config without changing your saved FlyingPress settings. You normally do not need to touch anything. If FlyingPress 5 changes those internals the v5 bridge degrades quietly and leaves a note in the debug log when `WP_DEBUG` is on; on older FlyingPress builds that predate the delay/defer filters the automatic exclusion simply does not apply (the plugin cannot detect this). Either way, if you ever notice the banner appearing only after the first click, add `faz-cookie-manager` to FlyingPress's "Delay JavaScript" exclusion keywords as a fallback.
* **Redis Object Cache / Memcached (persistent object caches)** — the plugin's internal banner/cookie caches are epoch-invalidated on save, which works on external object-cache backends too (fixed: previously a stale copy could survive in Redis and a banner save appeared not to stick).
* **Cloudflare APO** — honors the `Cache-Control: no-store` header. With CF in front, also enable the trust filter so the `Vary: CF-IPCountry` header is emitted and CF caches per-country variants instead of bypassing entirely.
* **Multilingual plugins under a full-page cache (WPML, Polylang, TranslatePress, Weglot)** — with Cache Compatibility Mode on, the banner still renders in the visitor's language. WPML directory/domain modes, Polylang, TranslatePress and Weglot all encode the language in the URL, so a URL-keyed page cache already stores one entry per language; the plugin resolves the per-URL language for each and stays cache-friendly. Only WPML's "language as a URL parameter" mode falls back to the site default (a query string is not a reliable cache key).

**Known limitations**

* **CDNs without origin Cache-Control honoring** (e.g. some legacy CloudFront configurations) — verify the response Cache-Control header reaches the edge. If not, add a CF-IPCountry or country-based cache key rule at the CDN level.
* **Minor / regional cache plugins** (Comet Cache, Cachify, Swift Performance Lite) — not formally tested. Most still honor `DONOTCACHEPAGE`; verify by inspecting the response Cache-Control on a country-targeted page.

Override the bypass logic per request via the `faz_country_dependent_banner_output` filter (return false to force the cache to ignore the country dimension on a specific URL).

= Short answer =

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

= How do I run my own script when a category is consented? =

Listen for `fazcookie_consent_ready` on `document`. It announces the initial consent state once on **every** page load and fires again with `action: 'update'` if the visitor changes consent on that page, so the code runs both where the visitor accepts and on every page afterwards:

`document.addEventListener('fazcookie_consent_ready', function (e) {
    if (e.detail.accepted.indexOf('functional') !== -1) {
        showMap();
    }
});`

`e.detail` is `{ accepted: [slug, ...], rejected: [slug, ...], action: 'init' | 'restore' | 'gpc' | 'update' }` -- `init` on a first visit before any choice, `restore` for a visitor whose choice was already stored, `gpc` when a Global Privacy Control signal was auto-applied, and `update` right after the visitor accepts, rejects or saves preferences. Register the listener before the plugin's script runs, for example from an inline `<script>` in the head.

One caveat on timing: the event tells you the consent state, which is not the same as the plugin having already re-activated the scripts it was blocking. That unblock pass runs shortly afterwards. Your own code can act immediately; if you depend on a resource the plugin itself gated (a `data-faz-tag` script or iframe), wait for it rather than assuming it is live in the same tick.

Use `fazcookie_consent_update` instead when you want to react to a **change**: it fires when the visitor accepts, rejects or saves preferences, and not on a plain page load by someone who already decided. A snippet that sends an analytics event belongs there, or it would fire on every page view.

`window.getFazConsent()` returns the same state on demand -- `{ activeLaw, categories: { slug: true|false }, services: { id: true|false }, isUserActionCompleted, consentID, languageCode }` -- for code that runs after the plugin has initialised and cannot wait for an event.

= How do I check one service or one cookie instead of a whole category? =

Categories are the right granularity on most sites, and while **Per-service consent** is off (the default) a granted category does mean every service in it is allowed. Once you enable it, a visitor can grant Functional and still deny one embed inside it, and a check on the category alone would run a script the visitor declined.

`window.getFazCookieConsent('cookie_name')` answers for a single declared cookie. It returns `true` when allowed, `false` when denied, and `null` when this site declares no service for that cookie -- which is the answer on every site with per-service consent off. Treat `null` as "ask about the category instead":

`document.addEventListener('fazcookie_consent_ready', function (e) {
    var osm = getFazCookieConsent('_osm_session');
    var allowed = osm === null
        ? e.detail.accepted.indexOf('functional') !== -1
        : osm;
    if (allowed) { showMap(); }
});`

`getFazConsent().services` gives the same answer keyed by service id instead of cookie name, and is empty when per-service consent is off. Both apply the resolution the blocker and the cookie shredder use: a per-cookie override wins over the per-service choice, which wins over the category, and the most restrictive answer wins when several services declare the same cookie.

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

= 1.27.1 =
* Fixed: WooCommerce block checkout could show "You must be logged in to checkout" to guests. The plugin was holding back WooCommerce's own `wc-settings` script, which publishes the `wcSettings` object the block checkout reads to learn whether guest checkout is allowed. With it inert the block fell back to its most restrictive default. `wc-settings`, `wc-blocks-middleware` and `wc-mini-cart-block-frontend` are now treated as strictly necessary and are never held back — they carry configuration only, set no cookies and are not a tracking surface. Reported on the support forum; reproduced and fixed with a regression test that fails if the handle is ever neutralised again.

= 1.27.0 =
* Changed: the jurisdiction geo-ruleset runtime now runs on live visitor traffic. With the ipinfo.io integration enabled and an API key stored, a visitor's IP address can be sent to ipinfo.io during ordinary browsing to classify VPN/proxy/Tor use — previously only the admin geo preview ran that lookup. Both switches are off by default and ipinfo.io is never contacted without them; revoking the opt-in also stops cached lookups. The External Services section describes the data flow.
* Added: authenticated browser-scan capture for PHP/HttpOnly Set-Cookie metadata from pages, AJAX, REST and subresources, plus background header replay limited to the URLs the browser actually visited.
* Added: granular AMP consent synchronized with the standard FAZ cookie. Category decisions are scope- and revision-bound, stale cached decisions fail closed, and AMP components stay blocked until their purposes are granted.
* Added: evidence-based stale-cookie deletion after repeated complete scans, recoverable bulk deletion, and a persistent recycle-bin Undo action that survives page reloads.
* Changed: scan completeness now includes selected depth, diagnostics, early stops and capture truncation. Administrator-only jar cookies are reported for review but never imported as public declarations.
* Changed: the opt-in outgoing Set-Cookie guard is separate from the established cookie shredder, respects banner/geo/excluded-page context, preserves necessary/CAPTCHA boundaries, and records value-free diagnostics without query strings.
* Fixed: the [faz_do_not_sell] opt-out is enforced again — its HttpOnly cookie was invisible to the frontend, which restored the sell/share scripts the server had blocked; it is now script-readable and reconciled on load. On the combined GDPR+CCPA setting the opt-out confirm revoked nothing; it now revokes every sell/share category, never age-gated.
* Fixed: a bare script-whitelist entry such as "js" substring-matched every URL and disabled client-side blocking wholesale — bare tokens now match element id/class only, as on the server; and a custom rule carrying a deleted category no longer leaves a built-in tracker unblocked before consent.
* Fixed: Microsoft Clarity now hears revocation, not only grants; the banner REST endpoint honours the site-wide disable switch; hiding from bots now removes the banner markup and CSS too; and pageview tracking is strictly gated, stores no session id, and its retention windows (pageviews, DSAR) gained Settings fields.
* Fixed: every settings write now runs the sanitiser and cache invalidation — partial saves no longer blank unrelated groups or leave page caches on stale consent configuration, an out-of-range scanner page limit no longer silences scheduled scans, and a malformed notice-dismissal expiry no longer errors.
* Fixed: the Cookie Banner editor opens the surviving default banner instead of hard-coding ID 1, and scanner loopback fetches relax unsafe-URL rejection only for the site's proven loopback alias, with redirects disabled while relaxed.
* Fixed: transient scan-import failures retry the exact payload and scan ID; exhausted retries close the session before another scan can start, and HttpOnly observations survive until persistence succeeds.
* Fixed: hyphenated AMP category slugs now use one collision-safe identifier across purposeConsentRequired, checkboxes, REST responses and component blocking attributes.
* Fixed: file-like replay URLs keep their path, cookie-clearing headers remove the matching observation instead of becoming inventory entries, and AMP-looking strings inside JSON, CSS or comments are not rewritten.
* Updated: translation catalogues resynchronized with the current source strings.

= 1.26.0 =
* Changed: all bundled jurisdiction rule-sets are now enforced by default across pre-consent defaults, blocking, mandatory banner controls and Consent Mode. The faz_geo_ruleset_runtime filter remains an emergency kill switch, and Cache Compatibility Mode is ignored while the response varies by jurisdiction.
* Compliance: GPC now overrides conflicting prior and same-page sale/share grants without erasing unrelated choices. The classic and AMP paths retain an audit marker and remove granular overrides that could bypass the opt-out.
* Changed: AMP consent now reconciles purpose choices, scope, revision and expiry through strict same-site/AMP-cache endpoints, gates known AMP components by purpose and mirrors the classic runtime's banner, bot, exclusion and revisit settings.
* Changed: Ad-blocker compatibility mode covers the banner, accessibility, GCM, TCF, WP Consent API and Microsoft bundles; Scanner Static IP is now configurable and preserves hostname/TLS/SNI across discovery and fetches; unused duplicate site settings are removed on upgrade.
* Added: Cookie Policy change review linked to consent revision. Mark a change minor to keep existing consents, or material to re-show the banner. The review token includes the default policy and every saved jurisdiction/language override variant.
* Added: opt-in footer legal links with ordered page selections and optional custom labels. Output is visitor-invariant and cache-safe; unpublished or unavailable selections remain visible in Settings so they can be removed, but never render publicly.
* Added: a safe snapshot collector for privacy-policy text registered by installed plugins, preserving operator wording while tracking upstream changes.
* Added: cookie duration and description are now shown in the visitor's language, not only the category labels (#214). A bundled catalogue supplies wording for known cookies and translates simple retention periods such as "2 years". It replaces a value only when that value is empty or is the plugin's own English text, so a description you wrote yourself is never replaced, while the English wording the scanner left in an Italian or German row does get translated. An unrecognised or free-form duration keeps exactly the value you entered. Applies to the banner, the [faz_cookie_table] shortcode and the generated Cookie Policy; no database change is involved.
* Added: two filters, `faz_cookie_content_i18n_description` and `faz_cookie_content_i18n_duration`, let a site override or switch off the bundled cookie wording per cookie and per language, without editing plugin files.
* Changed: the legal-document renderer now reads validated document coordinates from a registry while the existing Cookie Policy output remains byte-identical under the golden suite.
* Fixed: reCAPTCHA renders again on sites installed before 1.17.2, which allowed its API endpoint but blocked the widget script it loads. A one-time migration adds the missing pattern only where the endpoint is already whitelisted, and never removes anything.
* Fixed: material-change retries are idempotent after partial failure, identical third-party policy text cannot exchange plugin identities or overrides, oversized collected HTML stays balanced, and the REST route guard must exercise visible tabs.
* Fixed: a payment gateway left switched off in Settings > Script Blocking could still be treated as allowed, so its scripts loaded before consent. A saved "no" is now read as off, per-service and per-cookie toggles can be switched back off and report the state the server actually stored, and the compatibility warnings on that screen describe what really runs.
* Fixed: with per-service or per-cookie consent enabled, cookies belonging to a category the visitor had accepted were deleted anyway, so analytics broke for the very visitors who agreed to it. Category consent is honoured again, while an explicit per-cookie or per-service refusal still wins over it.
* Added: `fazcookie_consent_ready`, a JavaScript event that announces the initial consent state on every page load and the new state after a same-page update. Scripts keyed on consent previously had nothing to react to on the pages after the visitor accepted, so an embed started on consent worked once and then stopped. Use `fazcookie_consent_update` for side effects that must run only when a choice changes; use this event to read and apply the current state.
* Added: `getFazConsent().services` and `window.getFazCookieConsent( name )` for sites using per-service consent, answering for one service or one declared cookie instead of the whole category.
* Fixed: the WP Consent API and Microsoft UET/Clarity bridges were never told a returning visitor's consent, so Consent-API aware plugins and Microsoft Advertising treated somebody who had accepted as denied for the rest of the session.
* Fixed: tracking resources inside a `<noscript>` block are gated per resource. A block mixing providers was decided by whichever matched first, so a consented embed listed before a denied pixel let that pixel load before consent for visitors without JavaScript, and gated tags could be labelled with another provider's category.
* Fixed: on SQLite-backed installs the retention cleanup silently deleted nothing, so consent logs and pageviews were kept past the retention window you set. The purge now runs on both database engines and reports honest counts.
* Fixed: the script-blocking whitelist was re-seeded with default entries on every upgrade, undoing entries an administrator had removed and allowing more than the plugin's own defaults. It is now seeded once, and your list is left alone from then on.
* Fixed: Smash Balloon Instagram Feed in its own GDPR mode is no longer double-blocked; the template explains when and why the rule is inactive. Script Blocking Exceptions using a feed container ID or CSS class now work as documented.
* Fixed: Elementor's first-party lightbox helper is no longer blocked as a tracker; real third-party embeds remain governed by their own provider rules.
* Changed: backend queries, scanner bulk writes, retention cleanup, CSV export and generated frontend assets now use bounded, cached or streamed paths to reduce per-request memory and database overhead.
* Fixed: CSV export cannot loop forever on a locked output buffer, and multisite blog switches cannot reuse another site's Google Consent Mode settings.
* Updated: the Czech catalogue is synchronized with the current source strings and compiled binary.

= 1.25.0 =
* Added: administrator-editable Cookie Policy sections, isolated by jurisdiction and language. Shipped text remains the empty textarea placeholder, authored Markdown keeps the normal placeholder substitution pipeline, and unbundled languages such as Slovak can be written against the reviewed jurisdiction fallback. A stored section-heading anchor disables stale overrides after scaffold drift instead of placing legal text under the wrong heading.
* Added: POPIA (South Africa) jurisdiction - a conservative s.11(1)(a) consent-based setup preset (with explicit notice that POPIA also permits the s.11(1)(b)-(f) justifications), a ZA geo region, and Cookie Policy templates in every bundled language covering the Information Officer, data-subject rights under s.23-25, objection under s.11(3), and the PAIA s.25 30-day access window.
* Added: guided first-run setup wizard (8 steps) - detects the environment (multilingual plugin, page cache, WooCommerce, existing consent data) and configures jurisdiction-correct defaults. First setup—or switching consent model—applies the expiry and notice controls shown in review; reopening without changing model preserves custom expiry and button visibility. Upgrading installs are treated as already onboarded and are never nagged.
* Added: A/B testing of banner variants - run two or more active banners with a persistent random split and read the accept rate per variant on the Dashboard. Only active, independently compliant banners take part. Default off; skipped under Cache Compatibility Mode.
* Added: inline age-appropriate consent gate (GDPR Art. 8) - an optional age-confirmation checkbox that gates only the accept path, never Reject, withdraw or close, so button weight stays equal. Self-declared affirmation only, not a substitute for the parental-consent verification Art. 8(2) requires. Default off.
* Added: per-cookie Schrems II third-country transfer disclosure, shown in the preference-center declaration and the generated Cookie Policy. The wording names the fact and the safeguard the admin describes, and never asserts that the safeguard is legally sufficient. Default off.
* Added: opt-in banner resilience against ad-block cosmetic filter lists - one deferred re-assert that keeps the mandatory notice visible, with no loop and no cookie wall. Default off.
* Added: placeholder blocking for Smash Balloon Instagram Feed and the Elementor Video widget, contributed by @roboes (#190).
* Added: the generated Cookie Policy text is now editable - one box per section, per jurisdiction and per language, on a collapsed "Policy text" card. An empty box keeps the reviewed shipped text; placeholders keep resolving inside your own wording. Languages the plugin ships no template for can be selected too, so a policy can be written in any language without editing plugin files. Each override remembers the heading it was written against and deactivates if a later release reorders the templates.
* Changed: a jurisdiction="..." shortcode override no longer bypasses that jurisdiction's mandatory fields. It previously rendered even with those fields unset, on the reasoning that a degraded policy beat a blank page - the wrong trade for a legal document. Administrators now see the configuration notice; anonymous visitors receive nothing, so an incomplete policy is never published. If you use a shortcode override, fill in that jurisdiction's required fields.
* Security: the consent dashboard widget is now gated on capability, and the CCPA opt-out endpoints enforce a strict same-origin (Fetch Metadata with Referer fallback) check.
* Fixed: provider scripts whose blocking pattern ends on a separator were never blocked - the HubSpot tracker ran before consent (#196). A pattern such as js.hs-scripts.com/ already carries its own right-hand boundary, so demanding another separator after it meant js.hs-scripts.com/12345.js went unblocked. Twenty shipped provider definitions were affected; fixed in both the PHP and JavaScript matchers.
* Fixed: a banner cached under a previous site address kept requesting assets from the old origin (#195) - an address change now drops the cache, and a render-time repair rewrites and persists a stale origin, which also covers a restored database that never fires the hook.
* Fixed: the setup wizard's scan reported a fraction of the cookies the Cookies page found. The browser engine is now shared by both surfaces, retries public paths through the admin origin when home/admin hosts differ, and refuses to import misleading server-only findings when no page is observable. Wizard completion is atomic across banner/GCM/settings, preserves same-model customisations on re-entry, uses the site locale and jurisdiction-aligned geo defaults, and safely normalises false-like REST values.
* Fixed: a blocked Cookie Policy save now names the offending field, opens its section and focuses it, instead of doing nothing; background scans under a web SAPI run through WP-Cron with honest counts; third-country transfer labels resolve in the banner or policy language rather than the ambient request locale.

= 1.24.0 =
* Added: editable opt-out (Do Not Sell) modal text (#187) — a new "Opt-out (Do Not Sell) Text" card on the Cookie Banner > Preference Center tab edits the "Opt-out Preferences" popup's title, description and toggle label, per language, on CCPA / US State Laws (and Both) banners. Previously that copy was fixed to the bundled default. Translated into every bundled locale.
* Added: FlyingPress cache integration (#125) — saving a banner/cookie/category/setting purges FlyingPress's cached HTML; country-dependent pages bypass its cache via flying_press_is_cacheable; the consent scripts are excluded from its JS delay/defer/minify so the banner is never held back.
* Added: the Cookie Policy generator now flows through the WordPress gettext pipeline, so the policy honours the site locale and .mo overrides.
* Changed: payment-gateway scripts are now a per-gateway opt-in (Settings > Script Blocking > Payment gateways) instead of an automatic allow-list. A payment SDK can track, so it stays blocked until consent unless the store owner enables that gateway or it is strictly necessary on a real WooCommerce checkout/cart (the marketing pixel stays blocked either way). Migration: if you use Stripe elements outside a WooCommerce checkout, enable Stripe there after updating.
* Changed: the server-side cookie shredder moved to template_redirect (reliable checkout/cart conditionals), and an explicit per-service/per-cookie denial now wins over the admin cookie whitelist on both server and client.
* Fixed: category toggles rendering as editable text fields when another active plugin filters wp_kses_allowed_html (#188) — the <input> allow-list no longer loses type="checkbox" regardless of filter order.
* Fixed: banner/cookie saves not sticking on sites with a persistent object cache (Redis Object Cache, Memcached) — internal cache invalidation now rotates the transient prefix instead of scanning wp_options (#125).
* Fixed: WPML, TranslatePress and Weglot banners showing only the default language under Cache Compatibility Mode — URL-keyed language negotiation (directory/domain) now resolves the per-URL language while staying cache-friendly.
* Fixed: the per-service consent toggle now appears for JS-injected embeds on block-first sites (#134/#146); the consent banner no longer double-initialises under Cloudflare Rocket Loader (#185); the icon-only notice dismiss link is now labelled for screen readers.

= 1.23.0 =
* Added: "Box (centered)" banner type - positions the consent box in the centre of the screen via CSS transform, a common pattern on European sites.
* Added: "Dim the page behind the banner" option - a semi-transparent overlay greys out the page to draw attention to the banner. The overlay is a visual cue only (pointer-events: none) and never blocks reading, scrolling, or clicking, so it does not act as a cookie wall. Available for Box corner, Box centered, and Full-width Banner types; automatically disabled for the Classic layout.
* Changed: geo-routing admin clarity - corrected the misleading "automatic per-country" copy (runtime rule-set application is off; the catalogue is preview/reference only, while per-country banner selection still works), exposed the runtime off-state in the geo status endpoint, and finished i18n of the Pipeline-status panel.

= 1.22.0 =
* Added: inline-CSS url()/@import blocking before consent — a Google Fonts @font-face src url() or @import in a <style> tag previously reached the provider with consent denied; any url()/@import pointing at a blocked provider in a denied category is now neutralised (inert data: placeholder, restored on consent). Server-rendered <style> and direct runtime HTMLStyleElement writes are covered by default; a new opt-in "Advanced inline CSS URL blocking" setting (default off) additionally hooks page-builder/CSS-in-JS channels (innerHTML/insertAdjacentHTML, CharacterData incl. nodeValue/replaceWith, replaceChildren/insertAdjacentText, Constructable Stylesheets/insertRule).
* Added: wider runtime resource blocking for <img>/<iframe>/<link>/<source> (extends #163/#167) — beyond the src/href property setters, the setAttribute('src'|'href'|'srcset') path and the srcset property setter are gated, blocked <source> src/srcset are parked, and the MutationObserver also parks parsed img/link/source.
* Added: Advanced Consent Mode for Google Consent Mode v2 (#165) — opt-in (default off); the Google tag stack (gtag.js/GA4/Ads) may load before consent with a synchronous denied consent default, while non-Google trackers and the GTM container stay blocked.
* Added: manual service registration from the built-in catalogue (#161) — register a known provider's cookies into the declaration table from the Cookies page without a scan.
* Fixed: map tiles, lazy-loaded embeds and runtime-injected stylesheets now blocked before consent (#163, #167). Leaflet/OpenStreetMap and Bricks Map tiles load as runtime <img>, Bricks lazy-load swaps a URL into iframe.src, and Web Font Loader injects a Google Fonts <link> at runtime — all bypassed the blocker. The src/href property setters are now gated on the image, iframe and link prototypes: a cross-origin resource matching a blocked provider in a denied category is parked until consent, then restored.
* Fixed: banner chrome (Always Active, cookie-table headers) now translates on non-English single-language sites (#164); European Portuguese banner content corrected (#159).

= 1.21.1 =
* Fix: on full-page-cached sites with Cache Compatibility Mode enabled, the cookie banner could fail to appear on the first visit (and trackers could run) because the rendered page still varied per visitor and one cached copy is shared between everyone — a search-engine or cache-warming crawler produced a banner-less copy, or a wrong-jurisdiction/wrong-language copy, that the cache then served to all visitors. Under Cache Compatibility Mode the render is now fully visitor-invariant: the banner script is always enqueued (no bot/geo skip), the IAB TCF gdprApplies signal is conservative, AMP banner selection is country-neutral, and the banner language no longer reads cookie/session state from TranslatePress, Weglot or WPML "No language in URLs" mode (URL-based Polylang/WPML stay correct; the visitor's real language is still corrected client-side). Reported on gooloo.de.
* Fix: the consent script-blocker no longer interferes with the WordPress 6.5+ Interactivity API (native type="module"/importmap scripts) or with optimiser-deferred scripts (LiteSpeed Cache / WP Rocket "Delay JS"), while still blocking trackers — including a tracker shipped as a module or restored in place by the optimiser.

= 1.21.0 =
* Feature: Cache Compatibility Mode (#158). A new Banner Control toggle keeps the page fully cacheable by LiteSpeed, QUIC.cloud, Varnish, Nginx FastCGI and WP Rocket. When enabled, the plugin stops emitting the no-cache/no-store/X-LiteSpeed-Cache-Control headers and the DONOTCACHEPAGE constant for anonymous visitors and renders a single visitor-invariant page — the default banner, with every non-necessary script blocked server-side and no per-country or per-consent variance — so the static HTML can be cached and the banner runs entirely client-side from the consent cookie. Off by default; keep it off when the banner output varies by country (IAB TCF, geo-targeting, country-targeted banners or runtime geo-routing), where a cached page would otherwise reach the wrong jurisdiction. Applied across the initial render, the AMP consent path and the REST banner endpoint.
* Fix: the bundled "Always Active", "Show more" and "Show less" default labels are now translatable while preserving any admin-customised text.

= 1.20.0 =
* Feature: per-cookie consent (#135). With per-service consent enabled, a new "Enable per-cookie consent" setting adds a nested row for each cookie a service declares. Cookies the site can write on its own domain are enforced on both sides — the client-side cleanup and the server-side template_redirect shredder both read the same ck.<service>.<cookie> tokens (per-cookie > per-service > category), so a denied first-party cookie is removed on every request. Cookies set by embedded third-party services on their own domains (for example YouTube, Vimeo, Maps and social embeds) cannot be deleted individually by a first-party banner; those rows are shown disabled with an explanation, and the enforceable control is allowing or blocking the whole embed. Payment-gateway cookies stay exempt only when that gateway is explicitly enabled or strictly necessary on the current WooCommerce checkout/cart request; admin-whitelisted cookies remain exempt from category fallback, while an explicit per-service/per-cookie denial still wins. Opt-in, off by default.
* Feature: per-service consent for blocked embeds on block-first sites (#134, #146). Per-service toggles now appear for embedded providers blocked before they can set a cookie, which the scanner never detected. The preference center is present-aware: a toggle is revealed for every provider the page actually blocks — server placeholders, JS-injected embeds caught by the runtime MutationObserver, lazy iframes and page-builder lightbox video links — without dumping the whole catalogue. A service the visitor explicitly accepted or rejected stays visible for withdrawal even on pages without its embed (GDPR Art. 7(3)). Added a fail-open banner watchdog so the banner still appears even if a JS/CSS-optimiser strips the inline reveal, plus a read-only fazcookie._diag() support snapshot.
* Fix: the Cookie Policy generator no longer lands on a blank admin.php page when its script does not run (the form refuses the native submit and shows a recoverable message). Server provider-URL matching now uses the same word-boundary check as the client, so notyoutube.com/embed is no longer treated as youtube.com/embed. Completed the provider catalogue (parity test added) and renamed openstreetmaps to openstreetmap. Accessibility: aria-describedby on disabled third-party cookie rows, aria-atomic on runtime-revealed service rows, theme-adaptive note colour, cursor:not-allowed on locked rows.

= 1.19.2 =
* Fix: the consent-log user-agent migration no longer errors on SQLite-backed WordPress (e.g. WordPress Playground). It previously used MySQL's SHA2()/REGEXP, which do not exist on SQLite, so the migration failed and emitted a database error on every request; it now runs in PHP with the identical hash.
* Fix: the Google Consent Mode non-personalized-ads `npa` signal is now most-restrictive across regions. Because `npa` is a global signal that cannot be region-scoped, the pre-consent default emits a single value (non-personalized whenever any configured region denies ads) instead of letting the last-evaluated region win; the region-scoped Consent Mode v2 states are unaffected.

= 1.19.1 =
* Fix: legacy "Both" (GDPR + US) banners no longer silently lose their Do-Not-Sell opt-out. Very old banners stored it only in a legacy key that the settings sanitiser drops; the runtime now back-fills the opt-out from the raw stored settings so the US control still renders.
* Fix: the Google Consent Mode non-personalized-ads fallback now signals `npa` on the FIRST visit too (legacy non-Consent-Mode ad tags previously only got it after a reject), and the signal is two-sided — it clears within the session once marketing is granted.
* Hardening: the consent-log `status` column is constrained to the known set (unknown values fold to `partial`) so a crafted REST payload can't pollute the dashboard statistics; the client-side cookie cleanup gained a longer-tail pass to catch trackers that write a cookie well after page load; and an admin's explicit custom block rule is no longer silently exempted when it is a substring of an always-allowed payment-gateway pattern.

= 1.19.0 =
* Feature: per-service consent is reintroduced and now actually enforced. Granular per-service sub-toggles return under each category in the preference center (opt-in, sourced from the cookies actually detected on the site). A denied service is enforced server-side (pre-consent script block + cookie shredder) and client-side, an explicit allow overrides a denied category, and the choice persists across reloads and is written to the consent log. Enable it in Settings > Per-service consent. Extension filters: `faz_per_service_services`, `faz_store_data`.
* Feature: Czech (cs_CZ) cookie-policy templates for the GDPR, CCPA and LGPD generators, with correct legal terminology and date grammar.
* Feature: opt-out success message for US state-law / CCPA "Do Not Sell or Share" — an accessible confirmation (`role="status"` + `aria-live`, focus moved, countdown, auto-close) instead of a silent disappear. Headline/subtext editable via `[faz_optout_success_text]` / `[faz_optout_success_subtext]`.
* Compliance: Quebec / Law 25 sub-national routing, Do-Not-Sell-My-Personal-Information enforcement, DSAR export/erase wiring, scanner TLS verify-by-default (loopback-exempt), and new geo rulesets (Minnesota, Maryland, New Hampshire, New Jersey, Texas, Canada / PIPEDA).
* Fix: changing the banner's applicable law now reloads the law-appropriate notice copy — a CCPA description could survive on a GDPR banner and tell visitors to click a Do-Not-Sell link no longer rendered — without overwriting a customised description.
* Fix: the "Do Not Sell or Share" link on a Classic-layout CCPA (or "Both") banner is no longer a dead click; such banners are migrated to a popup-capable layout in the editor and at runtime, with a re-show fallback.
* Fix: the banner template cache signature now includes the plugin version and the per-service / per-cookie flags, so a plugin update can no longer serve a stale cached template to the updated script.
* Fix: blocked-embed placeholder keeps its branded styling; a service-level placeholder accept records the choice; toggling a service no longer collapses its category accordion.
* Fix: the geo "source not configured" admin notice no longer fires when a GeoLite2 database (or `FAZ_MAXMIND_DB_PATH`) is actually configured.
* Change: per-cookie consent remains hard-off pending its correctness rework, and is now also rejected on the settings REST / import path.

= 1.18.2 =
* Change: the experimental opt-in features added in 1.18.0 (per-service / per-cookie consent toggles and the `faz_geo_ruleset_runtime` runtime geo-routing) are temporarily disabled pending a correctness rework — they did not, when enabled, deliver the granular guarantees their UI implied. They are now hard-off at their entry points. The default category-level consent flow (the path covered by the compliance suite) is byte-for-byte unchanged.
* Change: per-service / per-cookie toggles are hidden in Settings and forced off. As shipped a denied cookie was not enforced server-side or on reload, the granular decisions were not written to the consent log, a large override set could exceed the browser's ~4 KB cookie limit, and the list showed catalogue wildcards rather than detected cookies.
* Change: runtime geo-routing no longer applies a resolved ruleset to the live banner (a CCPA-style jurisdiction was mapped to a GDPR banner without rendering its Do-Not-Sell / GPC / sensitive-opt-in obligations). Catalogue-based multi-banner geo-routing — choosing which saved banner to show per country — is unaffected.
* Fix: corrected an overstated per-cookie help text that claimed a denied cookie "is deleted whenever it appears." That enforcement only ran client-side at save time and did not persist, so the claim was inaccurate.


= Older versions =
Older releases (1.18.1 and earlier) are listed in the full changelog on GitHub, linked at the top of this section.
