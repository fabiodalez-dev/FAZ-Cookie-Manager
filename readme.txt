=== FAZ Cookie Manager ===
Contributors: fabiodalez
Donate link: https://buymeacoffee.com/fabiodalez
Tags: cookie, gdpr, ccpa, consent, privacy
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 1.34.2
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
* **Script blocking** -- mark a script `type="text/plain" data-faz-category="analytics"` to hold it until that category is accepted; it runs on its own as soon as consent is granted, and on every later page load.
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

Core banner functionality runs on your WordPress site. Optional update/download features may contact GitHub, IAB Europe, MaxMind, ipinfo.io (opt-in VPN detection), or the AMP CDN depending on which features you enable and use.

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

Geo-routing picks a banner per country — typically a strict GDPR banner for the EU/EEA/UK and a CCPA opt-out banner for California — resolving the country from Cloudflare's `CF-IPCountry` header (opt-in), then a server GeoIP module or extension, then the self-hosted MaxMind GeoLite2 database. All four are local to your server or your CDN edge; no visitor IP is sent to a third party for country resolution. When none of them is available the most-protective GDPR ruleset is applied to every visitor. Translations live inside each banner and are resolved **client-side** from `navigator.languages`, so a country-targeted banner still works behind a full-page cache.

In practice that means two banner rows rather than eight: one EU banner holding English, Italian, German, French and Polish, one US banner holding English and Spanish.

== External Services ==

**Summary.** This plugin is cloud-free: consent is stored on your own site and there is no vendor account, dashboard or telemetry. Below is the full outbound picture, one heading per item -- the optional features that contact an external host (none run unless you enable them), the public REST endpoints this plugin exposes on your own domain, and a note on third-party domain strings that appear in the code as matching patterns and are never contacted. Each entry states its trigger, what leaves your server, and the provider's terms.

= GitHub / Raw GitHubusercontent (Open Cookie Database) =

Used to refresh the built-in cookie definitions snapshot for the optional auto-categorize feature.

Triggered when: you click the definitions update action in the Cookies screen, or once a week after explicitly enabling "Update cookie definitions weekly" in Settings. Automatic updates are off by default; disabling the option cancels scheduled downloads. Failed downloads retain the existing database. The bundled snapshot remains available without enabling network updates.

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

= Can I put a consent link in my footer? =

Yes. Add a Shortcode block to your site-wide footer containing `[faz_cookie_settings type="link" text="Cookie preferences"]`. The link uses your theme’s styling. Omit `type="link"` for the existing banner-coloured button. Both support a custom `text` and additional CSS `class`.

To replace the floating widget, put the link on every page and disable **Banner → Advanced → Show revisit consent widget**. Jurisdiction routing may lock that widget on; in that case both controls remain available. The shortcode needs the banner runtime and does not work on pages excluded from the banner. Standard navigation menu labels do not execute shortcodes; custom HTML can use `<a href="#faz-consent" data-faz-open-preferences="1" aria-haspopup="dialog">Cookie preferences</a>`.

= Do scheduled scans require WP-CLI? =

No. A hosting cron that calls WordPress’s `wp-cron.php` runs the plugin’s scheduled events too. If your host already provides that job, do not add a duplicate. **System Status → Cron Jobs** includes a cPanel example and explains how to check the next scheduled times.

= Why does the dashboard show -- for pageviews? =

Pageview and banner interaction metrics show `--` when pageview tracking is disabled. This means unavailable, rather than zero visits. Consent logging is a separate setting and can remain enabled.


= Does this plugin require a cloud account or subscription? =

No required cloud account or subscription is needed. Core consent features run locally, while some optional refresh/download features can contact documented third-party services such as GitHub, IAB Europe, MaxMind, or AMP infrastructure.

= Is it really free? What's the catch? =

It's free and open source (GPL-3.0). There are no premium upgrades, no feature gates, and no upsells. The plugin is based on the GPL-licensed CookieYes v3.4.0 codebase, with cloud dependencies removed and all included features running locally.

= Is it compatible with Google Consent Mode v2? =

Yes. The plugin sends all 7 consent signals (`ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`, `functionality_storage`, `personalization_storage`, `security_storage`) and supports Google Additional Consent Mode (GACM) for ad technology providers.

= Does the banner block cookies before consent? =

Yes. Known third-party scripts are blocked automatically. To gate one of your own, give it a non-executable type and name the category it belongs to:

`<script type="text/plain" data-faz-category="analytics">/* your code */</script>`

Both parts are required. The type is what stops the browser from running it before consent; `data-faz-category` is what the plugin looks for when it runs it afterwards. A script with only the type never runs at all — including after consent — and a script with only the attribute runs immediately, before any consent is given.

The same pair works for iframes, images and stylesheets using `data-faz-src` or `data-faz-href` in place of the real attribute.

Once consent is granted the plugin runs the script itself, on that page and on every later page load. You do not need to listen for `fazcookie_consent_update` to start it; that event is for your own code that has nothing to do with these tags.

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

Yes. See the **Cache Plugin Compatibility** section below for the verified plugins, emitted cache-control signals, automatic FlyingPress handling and known CDN limitations.

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

One caveat on timing: the event tells you the consent state, which is not the same as the plugin having already re-activated the scripts it was blocking. That unblock pass runs shortly afterwards. Your own code can act immediately; if you depend on a resource the plugin itself gated (a `data-faz-category` script or iframe), wait for it rather than assuming it is live in the same tick.

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

= The DSAR or Do-Not-Sell form, or the Cookie Policy styling, stopped working inside a popup or a page loaded by AJAX =

Since 1.34.0 the DSAR form script, the Do-Not-Sell form script and the Cookie Policy stylesheet load only on pages where their shortcode is rendered by WordPress. If your page builder injects the shortcode markup in the browser after the page has loaded (AJAX popups, "load more" buttons, Bricks client-side rendering, swup or Barba page transitions), the asset is not on that page. Return `true` from the `faz_load_shortcode_assets_everywhere` filter for the asset you need -- `'dsar'`, `'dnsmpi'` or `'cookie_policy'` -- to load it on every page, as before:

`add_filter( 'faz_load_shortcode_assets_everywhere', function ( $everywhere, $asset ) { return 'dsar' === $asset ? true : $everywhere; }, 10, 2 );`

== Screenshots ==

1. **Cookie consent banner on the frontend** -- GDPR-ready banner in the bottom-left corner with "Customize", "Reject All" and equal-weight "Accept All" buttons. Shown only on the first visit until the visitor makes a choice.
2. **Preference center** -- Category-level opt-in modal. Necessary cookies are always active; every other category (Functional, Analytics, Uncategorized, Marketing) is opt-in by default, with a clear description for each.
3. **Admin dashboard** -- Overview of pageviews, banner impressions, accept rate and reject rate, with a 7/30/365-day pageviews chart and consent distribution.
4. **Banner editor** -- Configure layout, position, colours, copy and behaviour with a live in-iframe preview. Ships with GDPR Strict, High Contrast and Light Minimal design presets.
5. **Cookies management** -- Review and edit cookie categories, run the built-in scanner, and browse the bundled Open Cookie Database with 1,000+ definitions.
6. **IAB TCF v2.3 Global Vendor List** -- Browse the bundled GVL, filter by purpose, and select which vendors your site works with. Full Transparency and Consent Framework v2.3 API and UI, no cloud required. Note: broadcasting valid TC strings to vendors requires your own registered IAB Europe CMP ID; until one is configured the TCF layer stays inactive by design.
7. **Consent logs** -- Local, tamper-resistant audit trail of every visitor consent: status, categories, hashed IP, URL and timestamp. Filter, search and export to CSV for DPIA / audits.
8. **Google Consent Mode v2** -- Default vs. granted state for `ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`, `functionality_storage`, `personalization_storage` and `security_storage`. Works with GTM and gtag.
9. **Languages** -- Manage active languages and the default banner language. Works alongside WPML / Polylang. The admin interface ships translated into Italian, Dutch, German, French, Czech and Croatian; banner and cookie-category text ships in those plus Spanish, Finnish, Hungarian, Polish, Portuguese, Brazilian Portuguese, Russian and Ukrainian.
10. **Settings** -- Global controls: enable/disable the banner, exclude specific pages, cross-domain consent forwarding, hide from bots, GTM dataLayer events, consent log retention and scanner limits.

== Cache Plugin Compatibility ==

<!-- Placed between Screenshots and Changelog on purpose. wp.org's parser
     recognises only description/installation/faq/screenshots/changelog and
     folds any other section into the one ABOVE it. Above Description this
     block pushed it past the truncation cap (that is why it went missing in
     e341be7); below Changelog it pushed that past the 5,000-word cap. Here it
     folds into Screenshots, which has no cap. -->

When multi-banner geo-routing is active, the rendered HTML can legitimately vary by visitor country. This plugin asks the page-cache layer to bypass caching on those requests by emitting:

* `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
* `Pragma: no-cache`
* `X-LiteSpeed-Cache-Control: no-cache`
* `CDN-Cache-Control: no-store` and `Cloudflare-CDN-Cache-Control: no-store` (banner REST endpoint only, so an edge that overrides the browser directive still refuses to store the country-dependent payload)
* `Vary: CF-IPCountry` (when the trust filter `faz_trust_cf_ipcountry_header` is enabled). It is emitted for symmetry but is inert on these responses: nothing is stored, so there is no cache key to vary. `Vary` only does work on the storable responses — the banner REST endpoint's *non*-country-dependent answers, which are served `public, max-age=300` and carry the same header.
* `DONOTCACHEPAGE`, `DONOTCACHEOBJECT`, `DONOTCACHEDB` PHP constants (industry-standard bypass hints)
* `do_action( 'litespeed_control_set_nocache', ... )` when LiteSpeed Cache is installed

The jurisdiction runtime follows **Settings → Geolocation → Jurisdiction & Geo-routing** and is enabled by default on new installations. Turning it off makes the law saved on the active banner apply to every visitor; for example, a CCPA banner no longer gains GDPR blocking for an EEA visitor.

For sites that need per-country enforcement and a shared page cache, enable **Cache-safe jurisdiction bootstrap** on the same screen. Compatible normal pages use one visitor-invariant, strict GDPR shell and fetch the live no-store jurisdiction payload before the banner mounts or optional scripts can run. The settings screen reports whether it is actually active. AMP, IAB TCF, country-derived language fallback, country-targeted banner rows, `no_banner` regional visibility and custom country-dependent output currently retain the normal page-cache bypass. If the live request fails, the strict shell and blocked optional resources remain in place.

When jurisdiction enforcement is off, Cache Compatibility Mode can still keep normal pages cacheable without a PHP snippet. LiteSpeed CSS/JS optimisation and FlyingPress delay/minify receive automatic exclusions for the banner assets.

= Verified compatible (no extra configuration needed) =
* **LiteSpeed Cache** — uses the explicit `litespeed_control_set_nocache` action + `X-LiteSpeed-Cache-Control` header.
* **WP Rocket** — honors `DONOTCACHEPAGE` natively.
* **W3 Total Cache** — honors `DONOTCACHEPAGE` / `DONOTCACHEOBJECT` natively.
* **WP Super Cache** — honors `DONOTCACHEPAGE` natively.
* **Hummingbird (WPMU DEV)** — honors `DONOTCACHEPAGE` natively.
* **FlyingPress** — the plugin purges FlyingPress's cached HTML pages automatically whenever a banner, cookie, category or setting is saved, so a change never keeps serving stale banner markup. Only the rendered HTML is purged (that is all a consent change alters); FlyingPress's site-wide preload crawl is not triggered, matching the purge-only behaviour of the other supported caches. FlyingPress does not honor `DONOTCACHEPAGE`, so the plugin also hooks its documented `flying_press_is_cacheable` filter to skip caching on country-dependent pages, matching the bypass every other supported cache gets. The consent scripts are excluded automatically from minification and from "Delay all JavaScript": recent FlyingPress 4.x exposes delay/defer exclusion filters (added around 4.16), while FlyingPress 5 receives the same keywords in its in-memory delay-exclusion config without changing your saved FlyingPress settings. You normally do not need to touch anything. If FlyingPress 5 changes those internals the v5 bridge degrades quietly and leaves a note in the debug log when `WP_DEBUG` is on; on older FlyingPress builds that predate the delay/defer filters the automatic exclusion simply does not apply (the plugin cannot detect this). Either way, if you ever notice the banner appearing only after the first click, add `faz-cookie-manager` to FlyingPress's "Delay JavaScript" exclusion keywords as a fallback.
* **Redis Object Cache / Memcached (persistent object caches)** — the plugin's internal banner/cookie caches are epoch-invalidated on save, which works on external object-cache backends too (fixed: previously a stale copy could survive in Redis and a banner save appeared not to stick).
* **Cloudflare APO** — honors the `Cache-Control: no-store` header, and the banner REST endpoint backs it with `CDN-Cache-Control: no-store` / `Cloudflare-CDN-Cache-Control: no-store` so country-dependent output is not stored at the edge even where the edge is configured to override browser cache directives. With CF in front, also enable the trust filter: it adds `Vary: CF-IPCountry` to the banner REST endpoint's *non*-country-dependent responses (`public, max-age=300`), so CF keys those short-lived entries per country instead of sharing one entry across countries.
* **Multilingual plugins under a full-page cache (WPML, Polylang, TranslatePress, Weglot)** — with Cache Compatibility Mode on, the banner still renders in the visitor's language. WPML directory/domain modes, Polylang, TranslatePress and Weglot all encode the language in the URL, so a URL-keyed page cache already stores one entry per language; the plugin resolves the per-URL language for each and stays cache-friendly. Only WPML's "language as a URL parameter" mode falls back to the site default (a query string is not a reliable cache key).

= Known limitations =

* **CDNs without origin Cache-Control honoring** (e.g. some legacy CloudFront configurations) — verify the response Cache-Control header reaches the edge. If not, add a CF-IPCountry or country-based cache key rule at the CDN level.
* **Minor / regional cache plugins** (Comet Cache, Cachify, Swift Performance Lite) — not formally tested. Most still honor `DONOTCACHEPAGE`; verify by inspecting the response Cache-Control on a country-targeted page.

Override the bypass logic per request via the `faz_country_dependent_banner_output` filter (return false to force the cache to ignore the country dimension on a specific URL).

== Changelog ==

The full changelog (every release back to 1.0.0) lives at:
https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/blob/main/CHANGELOG.md
and on the GitHub Releases page:
https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/releases

= 1.34.2 =
* Fixed: The generated configuration and main banner JavaScript load with ordered defer, removing their render-blocking requests. A small inline bootstrap protects dynamic scripts and requests until the full consent runtime is ready.
* Fixed: Google Consent Mode defaults and the TCF command stub remain available before page scripts; a failed configuration download keeps pending resources blocked.

= 1.34.1 =
* Fixed: A reopen link in the footer could be accepted as valid on the strength of text no visitor can click, so the plugin hid its own revisit widget and the site was left with no usable way to withdraw consent. The check removes script, style, title and textarea regions before looking for the link, but it ended those regions at the first tag whose name merely started the same way — `</titlex>` closed `<title>` — and treated the text after it as real markup. Those regions now end exactly where HTML says they do.

= 1.34.0 =
* Fixed: The banner did not render at all with WPSpeed by JExtensions active, for two separate reasons. Its image optimiser rewrites the page through an HTML4 parser that truncates the banner template at the first closing tag, leaving a 0 px consent bar with no buttons; and because it combines and defers the scripts, initialisation ran before part of the file existed and died halfway. The template is now written so both parsers keep it intact, and initialisation runs in the same order whether the script is deferred, async, combined or delayed.
* Fixed: A reopen link verified in the footer could leave a visitor with no way to withdraw consent. Verification was a text match, so a control inside a template, hidden, inert or hidden by an inline style counted as usable. The markup is now parsed, `disabled` is judged as the HTML specification defines it — never on a link, inherited only from a `<fieldset disabled>` — and the plugin's own widget reveals itself in the browser whenever no usable control is present, including when the theme hides the footer, a breakpoint drops it, or a stylesheet finishes loading after the page.
* Fixed: Jurisdiction routing said it was active on sites where nothing can resolve a country. The switch governs two jobs — applying a jurisdiction's rules, which always works through the most protective fallback, and routing by country, which needs a geo source — and every screen described the second. The admin now reports four distinct states, and a saved MaxMind licence key no longer counts as proof that lookups work: that is checked with a real lookup.
* Fixed: AMP pages keep their own native reopen control instead of relying on a footer link verified for the regular frontend. The two are different mechanisms, and a footer probe cannot prove an AMP tap action exists.
* Added: A compact phone layout for the notice buttons, under Banner Control in Settings. Below 440px the shipped templates give each button a row of its own, which takes 44% of a typical phone screen; compact puts accept and reject on one row and brings that to 29%. The default is unchanged, so no existing site looks different after the update. Accept and reject stay exactly the same size in every language, every button keeps a 44px tap target, and long labels wrap instead of being cut off.
* Changed: Lighter pages. The service catalogue — about 43 KB with per-service consent on — moved from the inline configuration into the cached, content-hashed configuration file, so it is downloaded once instead of with every page. The DSAR form script, the Do-Not-Sell form script and the 8 KB Cookie Policy stylesheet now load only where their shortcode renders. Provider matching is cached per URL: on a reported homepage it was costing about 200 ms of main-thread time, now 2 ms in the browser, with identical results.
* Added: Filter `faz_load_shortcode_assets_everywhere` restores loading a shortcode's assets on every page, per asset, for page builders that inject the shortcode markup client-side.

= 1.33.0 =
* Fix: A frontend request that had to write a new config or banner asset could answer 500 instead of rendering the page, on hosts where the PHP user does not own the files and the FTP extension is loaded (#300). The plugin discarded the result of WP_Filesystem(), which leaves an unconnected object in place when the connection fails, so the first write hit a fatal in ftp_fput() before the inline fallback could run. An unusable filesystem is now refused and writes report failure, so the banner falls back to inline assets instead of breaking the page.
* Added: Choose the HTML tag used for the banner title, the preferences title and the category titles — H1 to H6, paragraph, div or span, with H2/H2/H3 as defaults. The choice survives a banner language change.
* Fix: A script restored after consent could run before the script it depends on had finished loading, so GTM4WP's WooCommerce script called into its generic library before that library existed. Restoration now uses one ordered queue, rechecks consent before each entry, and keeps explicitly asynchronous scripts asynchronous.
* Fix: A provider pattern whose category is allowed no longer exempts a script that a different matching pattern blocks, in either blocking layer and whatever the order of the provider map. A pattern shared by several cookie categories keeps a denied category instead of the first allowed one.
* Fix: GTM4WP's two e-commerce scripts are recognised by filename, so both are blocked where WordPress gives them no enqueue handle. Whitelist entries and per-service choices still apply.
* Fix: A resource matching several consent categories stays blocked while any matching category is denied, including when the server attached a different, permitted category to that element. Per-service choices and blocking exceptions still apply.
* Fix: Scripts blocked at runtime, and scripts inside a placeholder, join the same ordered restoration queue as server-blocked scripts and keep their original document positions, so dependent code waits for the libraries before it.
* Fix: Inline scripts added with wp_add_inline_script() are gated on the hook WordPress actually provides, wp_inline_script_attributes. The previous registration named a filter that does not exist in core, so inline blocking rested entirely on the output-buffer fallback. Nonces, module types, localised configuration and Consent Mode exemptions are preserved.

= 1.32.1 =
* Fix: Consent records were refused in silence on sites whose page cache holds HTML for more than a day. The origin token is accepted for seven days now, and the faz_consent_token_max_age filter widens the window for a longer-lived cache (#292).
* Fix: A refused record is reported instead of lost. System Status shows the count, the most recent occurrence and all five causes separately, and the row appears even at zero. The figure was previously truncated above 999 and its window reset rather than rolled.
* Fix: Pageview, banner-view and banner-choice events posted from cached pages were refused after about a day, so the Dashboard under-reported without saying so (#296).
* Fix: A script whose URL merely contained a Google ad domain, in a parameter or a look-alike host, could load before consent. The domains are matched as hosts now.
* Fix: A blocked image, iframe or stylesheet whose URL carried a colon outside the scheme stayed blocked after consent.
* Fix: "Copy status" keeps every column of the blocked Set-Cookie table, and no longer runs sentences or list entries together.

= 1.32.0 =
* Fix: GPC audit verdicts stop at revocation. Concurrent embed inventory writes preserve their first observation; malformed definition feeds retain the last usable dataset.
* Fix: Bricks Google Maps widgets show consent placeholders and initialise after delayed scripts load. No artificial cookie record is needed to reveal a blocked map in per-service preferences.
* Fix: Elementor Video widgets respect custom blocking categories, whitelist entries and faz-skip.
* Fix: WooCommerce order attribution resumes when marketing consent restores Sourcebuster, without duplicate initialisation. Untouched scanned Sourcebuster cookies migrate from Analytics to Marketing; manually created, edited or imported classifications are preserved and flagged for review.
* Fix: WP Rocket no longer delays FAZ's inline bootstrap or its consent logger; the runtime merges the loaded static configuration before blocking decisions.
* Added: Publish a language-specific cookie policy page from the setup wizard (off by default). Repeated submissions reuse the page, languages without a template are unavailable, and a page that cannot be created no longer stops setup. Page-link fields now suggest published pages, with keyboard navigation and manual URL support.
* Added: The consent log records whether the server would have served each GPC exception, so inconsistent markers can be identified after the fact (#285). A script on the page can write the same cookie values a real click writes, so the record claims consistency rather than authenticity.
* Added: System Status lists the services that can be blocked without a visible placeholder (#279) — they set no cookies, and when a script or stylesheet of theirs is blocked before consent it simply does not load, so the symptom points at the theme or the cache instead of here.
* Fixed: A GPC exception accepted within five minutes of saving preferences was never logged at all, because it does not change the consent status and the repeat throttle dropped it.
* Fixed: The Dashboard no longer implies data will arrive when pageview tracking is off, and says that consent records are kept regardless.
* Fixed: On sites with plain permalinks or query-string language URLs, consent-log rows recorded the home page; the parameters that identify the page are now kept.

= 1.31.0 =
* Fixed: Consent Logs no longer reports "Failed to load consent logs." on sites that have logs (#284): the page formatted dates with the WordPress locale (de_DE), which the browser rejects, and the error was mistaken for a failed request. The Dashboard and geo-routing timestamps shared the flaw.
* Fixed: Per-cookie grants obey GPC and Do Not Sell server-side; old GPC exception markers cannot return after the signal is switched off and on without a new choice.
* Fixed: Visitors sending Global Privacy Control can open a blocked map or video by clicking Accept on it. Only that service is granted: the category stays denied, Accept All cannot re-grant it, and a Do Not Sell request still wins.
* Fixed: Legacy Functional sale/sharing defaults are corrected, with an admin notice when changed. Sites without a Do Not Sell surface can also have saved/imported flags reset; the now-visible controls let administrators restore intentional classifications.
* Fixed: Under opt-in laws, GPC alone leaves the unanswered banner available on later pages. Under opt-out laws, or after an explicit Do Not Sell request, the banner is not re-offered. The signal is no longer re-sent as a new consent on every page.
* Fixed: The GPC exception can be withdrawn from the preference centre, is created through the embed’s Accept handler, and is enforced identically by the browser, the server and the AMP bridge; a standing Do Not Sell request binds the AMP endpoints too.
* Fixed: The Functional migration now also covers sites with no Do Not Sell link, where the controls were hidden, and says so with an admin notice; the consent log marks records created by a privacy signal rather than by an answer.
* Fixed: The Sale / Sharing column is shown on every site, and the Respect GPC note explains what is actually enforced: a GPC signal is honoured whether the switch is on or off.

= 1.30.0 =
* Added: Footer consent links with [faz_cookie_settings type="link"] and configurable banner button radius (#191).
* Added: Cookie Policy templates in Dutch and Croatian, plus Russian and Ukrainian banner and category translations.
* Added: Cookies observed by a scan but not attributable to a page can now be declared from the Cookies screen, with the domain and lifetime the scan measured (#243). Declaring never makes a cookie deletable, and WordPress authentication cookies cannot be declared.
* Fixed: Explicit consent choices remain authoritative across all 47 jurisdiction profiles, including targeted sale/share opt-outs and separately gated categories.
* Fixed: Per-service grants from blocked embeds persist on CCPA banners without granting unrelated categories; standing sale/share opt-outs remain binding.
* Fixed: Simultaneous browser scan starts by the same administrator are serialized before session lookup; competing starts return HTTP 409.
* Fixed: Category translations preserve drafts and catalogue fallback; translation cache invalidation uses normalized language keys.
* Fixed: Blocked Vimeo and YouTube embeds restore correctly after consent, and keyboard focus stays inside the visible consent dialog.
* Fixed: Dashboard metrics show -- when pageview tracking is disabled and use the actual pageview total. Administrative dates and time-range labels follow the selected language.
* Changed: System Status explains hosting cron integration. Administrative translation keys no longer pollute gettext extraction.

= 1.29.0 =
* Fixed: pressing "Update definitions" once froze the cookie database at that moment, permanently. The downloaded copy always won over the snapshot shipped with the plugin, and nothing ever revisited that choice — no version check, no date comparison, no refresh on upgrade. Sites that never pressed the button kept receiving fresher data with every plugin update; sites that pressed it fell further behind for as long as they ran. The bundled snapshot now wins when it is newer, so the button offered as the cure for stale definitions is no longer what makes staleness permanent.
* Fixed: in Advanced Consent Mode a visitor who refused Analytics but allowed Performance was reported to Google as having allowed analytics storage. Advanced mode deliberately does not block Google's own tags, so the consent signal is the only control left — and it was pointing the wrong way, letting tags write against an explicit refusal.
* Fixed: the System Status report lost every yes/no answer when it was copied. Each row rendered as a bare tick or cross, and those characters do not survive most clipboards — support reports arrived with every boolean blank, unable to answer the question they were attached to. Every row now carries the word as well as the icon. An overdue scheduled task says so and by how much instead of printing a date nobody checks, and the cookie-database and vendor-list ages are in the report.
* Fixed: the "manage preferences" shortcode opened a preference panel that was present but invisible after a visitor had already given consent.
* Fixed: the cookie scanner reported a failure while the scan was working, and a scan session left behind by a closed tab now explains itself and can be ended.
* Fixed: eight compliance defects found in an audit of the whole plugin, including TCF Purpose 1 being asserted for visitors who had not acted, consent-log withdrawal being lost to rate limiting, and Jetpack Tracks cookies being treated as neither blockable nor disclosable.
* Fixed: accessibility and HTTPS parity with upstream CookieYes 3.5.5 — preference triggers now name their own panel, a trigger with no panel no longer announces a dialog that does not exist, and mixed-content repair reaches every asset path.
* Security: a forged `X-Forwarded-Proto` header could rewrite the cached banner for every visitor. The header can still correct asset URLs for the request that sent it, but it can no longer cause a persistent write.
* Fixed: the ad-blocker resilience guard could not see a cosmetic filter list that arrived late. It ran a single check about a second after load, which only ever meets a rule already applied at first paint; a rule injected after it left the legally required notice hidden for the rest of the visit. It now runs a short bounded series and then stops. Opt-in and off by default, as before.
* Fixed: three more locales pointed at translations WordPress does not have. `es_PY`, `en_IN` and `en_IE` have never existed, so a Paraguayan visitor was served English instead of Spanish, and Ireland and India silently lost the British spelling that mapping exists to preserve.
* Localisation: 20 new strings added to the catalogues and translated into Italian.

= 1.28.1 =
* Fixed: a cached page could serve one visitor's jurisdiction to another when Cloudflare's country header was trusted. The check deciding whether output varies by country required the `CF-IPCountry` header on the current request, so a cache warmer — or anything reaching the origin without passing through Cloudflare — was told no country source existed, and its response was cached without the country cache-bust. A later visitor whose header did identify their country could then be served that cached page with the fallback rule set and banner instead of their own. Narrow to reach, since trusting the header is opt-in and off by default, but the failure is an EEA visitor served an opt-out banner from cache.

= 1.28.0 =
* Changed: fresh installs now enable the 47-law jurisdiction runtime, and a new cache-safe bootstrap can serve one strict GDPR shell and resolve the live law before the banner mounts, so a compatible page cache no longer has to be given up for correct per-country rules. Every unsupported configuration is named in the admin and falls back to the existing no-cache path.
* Changed: sites upgrading from 1.27.x keep the enforcement they already had. The Geo-Targeting toggle is now authoritative, so a one-time migration turns it on and normalises a dormant "no banner" default, leaving banner visibility unchanged. A dismissible notice explains the change.
* Fixed: turning Geo-Targeting off restores full-page caching without a PHP snippet, and LiteSpeed CSS optimisation no longer produces an unstyled banner first paint.
* Fixed: WooCommerce look-alike scripts are blockable again. The three strictly-necessary handles were also reaching the general whitelist, which matches by token prefix against id and class, so names like `wc-settings-tracker-js` were being exempted from consent blocking.
* Fixed: Croatian translations load again — the catalogue shipped under a locale WordPress does not have — and ten further invalid country-to-locale mappings are corrected.
* Fixed: admin controls that the jurisdiction runtime overrides now say so instead of silently doing nothing, and preference-centre colours reach every rendered element including the audit table and the "Always Active" label.


= Older versions =
Older releases (1.25.0 and earlier) are listed in the full changelog on GitHub, linked at the top of this section.
