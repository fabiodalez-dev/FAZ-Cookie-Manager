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

Yes. Every consent action (accept, reject, customize) is recorded in a local database table with timestamp, consent ID, categories chosen, a pseudonymised IP (salted one-way hash), and page URL. Export to CSV anytime from the Consent Logs page.

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

Yes. Place `[faz_dsar_form]` on any page to show a GDPR-compliant request form covering six rights: Access (Art. 15), Erasure (Art. 17), Data Portability (Art. 20), Rectification (Art. 16), Restriction (Art. 18), and the Right to Object (Art. 21). On submission, the request is stored as a private post in the WordPress database (so it survives email failures), a notification is sent to the admin with a direct link to the record, and a confirmation is sent to the requester. The form includes a honeypot field, a signed dwell-time stamp, an optional proof of work and nonce verification to block spam bots; the proof of work and the dwell stamp only charge clients that run the form's JavaScript and never reject a client that omits them (fail-open by design), so the cumulative daily ceilings are the control that actually stops a spam chain. Optional attributes: `button` (submit label).

= My site is behind Cloudflare or a reverse proxy. Are the data request form's limits still per visitor? =

They need to be, and since 1.34.1 the plugin resolves the visitor's address on its own for the one case it can verify by itself: a request arriving from Cloudflare's published edge ranges, where `CF-Connecting-IP` is read, and only there, because Cloudflare writes that header itself. Every other topology is resolved once you declare it, one line each.

For another CDN, add its published edge ranges and every per-visitor key in the plugin follows. A peer matched that way is read through `faz_proxy_forwarded_header`, which defaults to `HTTP_X_FORWARDED_FOR` and is read from the right-hand end of the chain, so for an edge that appends to `X-Forwarded-For` the range is all you need:

`add_filter( 'faz_trusted_proxy_cidrs', function ( $cidrs ) { return array_merge( $cidrs, array( '203.0.113.0/24' ) ); } );`

If that edge overwrites a header of its own instead, name it as well — the range trusts the peer, this filter says what to read:

`add_filter( 'faz_proxy_forwarded_header', function () { return 'HTTP_TRUE_CLIENT_IP'; } );`

For a reverse proxy on the same host, name the header it writes. Nothing is read from a loopback peer until you do: `faz_loopback_proxy_header` is unset by default, so an unconfigured site keeps using the connection's own address and the per-connection limit is skipped rather than shared. Use whichever line your nginx config actually contains — `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` means `HTTP_X_FORWARDED_FOR`, `proxy_set_header X-Real-IP $remote_addr;` means `HTTP_X_REAL_IP`:

`add_filter( 'faz_loopback_proxy_header', function () { return 'HTTP_X_REAL_IP'; } );`

There is no default here because guessing would be unsafe, not just imprecise: nginx's own convention writes `X-Real-IP` and leaves `X-Forwarded-For` alone, and a header the local proxy does not write arrives from the visitor untouched. Reading it anyway would let a visitor pick their own limit.

Anything else keeps using the connection's own address and ignores forwarded headers, because those are forgeable by anyone who can reach the site directly. To trust a load balancer of your own, enable `faz_trust_proxy_headers` and scope it with `faz_trusted_proxies`.

Where no per-visitor address can be resolved, the per-connection limit on `[faz_dsar_form]` is **skipped** rather than applied to everyone at once: five submissions must never close the form for unrelated people for 24 hours, since GDPR Art. 12(2) requires the controller to facilitate requests. The site-wide limits and the one-per-address-per-hour limit still bound a flood, the hourly summary email says the per-connection limit is not in force, and `faz_dsar_max_per_ip_per_day` changes it (`0` switches it off).

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
6. **IAB TCF v2.3 Global Vendor List** -- Browse the Global Vendor List (downloaded from IAB Europe when you switch TCF on, and refreshed weekly), filter by purpose, and select which vendors your site works with. Full Transparency and Consent Framework v2.3 API and UI, no cloud required. Note: broadcasting valid TC strings to vendors requires your own registered IAB Europe CMP ID; until one is configured the TCF layer stays inactive by design.
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

* `Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0`
* `Pragma: no-cache`
* `X-LiteSpeed-Cache-Control: no-cache`
* `CDN-Cache-Control: no-store`, `Cloudflare-CDN-Cache-Control: no-store` and `Surrogate-Control: no-store`, so an edge that overrides the browser directive still refuses to store the response. The same set is sent by every other FAZ response that must not be stored: the plugin's REST routes except the banner endpoint, the data-request form page and the nonce/token refresh endpoints. The banner endpoint sends it only on its country-dependent answers; the others are served `public, max-age=300` (next point).
* `Vary: CF-IPCountry` (when the trust filter `faz_trust_cf_ipcountry_header` is enabled). It is emitted for symmetry but is inert on these responses: nothing is stored, so there is no cache key to vary. `Vary` only does work on the storable responses — the banner REST endpoint's *non*-country-dependent answers, which are served `public, max-age=300` and carry the same header.
* `DONOTCACHEPAGE` and `LSCACHE_NO_CACHE` PHP constants (industry-standard bypass hints). W3 Total Cache's `DONOTCACHEOBJECT` / `DONOTCACHEDB` are not set on front-end pages: its object and database caches hold nothing that varies by visitor, so they stay on.
* `do_action( 'litespeed_control_set_nocache', ... )` when LiteSpeed Cache is installed

The jurisdiction runtime follows **Settings → Geolocation → Jurisdiction & Geo-routing** and is enabled by default on new installations. Turning it off makes the law saved on the active banner apply to every visitor; for example, a CCPA banner no longer gains GDPR blocking for an EEA visitor.

For sites that need per-country enforcement and a shared page cache, enable **Cache-safe jurisdiction bootstrap** on the same screen. Compatible normal pages use one visitor-invariant, strict GDPR shell and fetch the live no-store jurisdiction payload before the banner mounts or optional scripts can run. The settings screen reports whether it is actually active. AMP, IAB TCF, country-derived language fallback, country-targeted banner rows, `no_banner` regional visibility and custom country-dependent output currently retain the normal page-cache bypass. If the live request fails, the strict shell and blocked optional resources remain in place.

When jurisdiction enforcement is off, Cache Compatibility Mode can still keep normal pages cacheable without a PHP snippet. LiteSpeed CSS/JS optimisation and FlyingPress delay/minify receive automatic exclusions for the banner assets.

= Verified compatible (no extra configuration needed) =
* **LiteSpeed Cache** — uses the explicit `litespeed_control_set_nocache` action, `LSCACHE_NO_CACHE` and the `X-LiteSpeed-Cache-Control` header. Every page-cache bypass also sends `Cache-Control: private, no-store`, `CDN-Cache-Control`, `Cloudflare-CDN-Cache-Control` and `Surrogate-Control: no-store` for QUIC.cloud and other edges. FAZ asset URLs carry `_litespeed_rm_qs=0`, so "Remove Query Strings" keeps their `?ver=` and an update is never served with the previous consent runtime.
* **WP Rocket** — honors `DONOTCACHEPAGE` natively.
* **W3 Total Cache** — honors `DONOTCACHEPAGE` natively; its object and database caches keep working on the pages FAZ keeps out of the page cache.
* **WP Super Cache** — honors `DONOTCACHEPAGE` natively.
* **Hummingbird (WPMU DEV)** — honors `DONOTCACHEPAGE` natively.
* **FlyingPress** — the plugin purges FlyingPress's cached HTML pages automatically whenever a banner, cookie, category or setting is saved, so a change never keeps serving stale banner markup. Only the rendered HTML is purged (that is all a consent change alters); FlyingPress's site-wide preload crawl is not triggered, matching the purge-only behaviour of the other supported caches. FlyingPress does not honor `DONOTCACHEPAGE`, so the plugin also hooks its documented `flying_press_is_cacheable` filter to skip caching on country-dependent pages, matching the bypass every other supported cache gets. The consent scripts are excluded automatically from minification, "Delay all JavaScript" and "Delay third-party scripts", and the banner selectors are kept by "Remove unused CSS": FlyingPress 4.x receives them through its delay/defer filters, FlyingPress 5 through its in-memory configuration, without changing your saved FlyingPress settings. If any FlyingPress pass still rewrites a FAZ script or the banner stylesheet (for example a "Delay selected scripts" keyword such as gtag or recaptcha that also matches the banner configuration), the plugin restores it before FlyingPress caches the page and writes one note a day to the PHP error log. If you see that note, or the banner appears only after the first click, add `faz-cookie-manager` to the exclusions of both "Delay JavaScript" and "Delay third-party scripts", and remove any "Delay selected scripts" keyword that matches the plugin.
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
* Added: Your consent ID is now shown in the cookie preferences, the data request form has an optional Consent ID field filled in automatically, and administrators can open the matching consent history from the request. The request also records whether the ID matches the consent cookie of the browser that sent it, and warns the administrator when it does not (#327). WordPress export and erasure tools include that history once the administrator confirms the ID belongs to the requester (filter: faz_consent_log_erasure_retain).
* Added: The suggested privacy policy text is now built from the features actually enabled on the site, including consent IDs, hashed IP and browser data, retention periods, geolocation, the optional ipinfo.io lookup and privacy requests.
* Added: Each consent record now also stores its time in UTC. Existing records are converted in the background after the update, using the site's current time zone.
* Fixed: On WordPress multisite, a cookie choice made on one site could be applied on another site of the same network, including subdirectory networks and shared cookie domains. Each site now keeps its own choice, also in the Do Not Sell form; visitors of multisite networks are asked once more after updating.
* Fixed: The geo rule sets declared IAB GPP support that the plugin does not provide. The declaration is removed, and custom rule sets that still contain it keep working; a GPP string supplied by another consent platform is still recorded in the consent log, also when the visitor leaves the page right after choosing, and only when it has the shape of a GPP string.
* Fixed: Embedded documents held back by server-side blocking could be released by copied or forged markup. They are now restored only when they belong to the page that was served, and a forged hold marker in the page no longer keeps a provider address loading in the browser.
* Fixed: The "How to Manage Cookies" section of the cookie policy always pointed to the floating cookie icon; it now describes the withdrawal route configured on the site. Where the controller's name is still missing, the opening sentence of a generated policy now names "the operator of this website", in the policy's language, so it keeps stating the law the policy follows; a sentence that only gives a missing contact is left out, so visitors see no asterisks and no sentence ending on a colon over an empty list. The CCPA and LGPD texts present the retention figure as a fallback instead of the retention of all personal data, and the transfer note in the preferences follows the rules applied to the visitor.
* Fixed: A few spellings still let a provider load before consent: a <source> address set by a script, a CSS @import written with a comment, character references without a semicolon, named references and line breaks inside embedded documents, and an inline data script followed by code after a line break. They are now held back, and a "lite" video embed added by a script keeps its preview instead of being replaced by the placeholder.
* Fixed: While the consent runtime was still loading, a script that inserted an element next to one that was being held (insertBefore, replaceChild, before, replaceWith) lost the new element, and with replaceChild and replaceWith both.
* Fixed: An acceptance given on an AMP page by a visitor in an opt-in region, over the kept US banner, was stored for a year; it now gets the opt-in lifetime of at most six months.
* Fixed: Before the visitor had made a choice, the TCF API reported legitimate interest as established for every vendor while the preference centre showed it switched off. Both now say off until a choice.
* Fixed: An expired form and an invalid consent ID on the data request form now trigger faz_dsar_rejected like every other refusal, and the consent ID error says the field can be left empty. After an erasure, the administrator is no longer told that an unverified consent ID can still be verified.
* Fixed: On AMP pages, a site whose geo rules required opt-in consent but which had no GDPR-family banner (for example one set up as "US only" by the wizard) lost both the consent prompt and the blocking, so analytics, ads and pixels on AMP ran without consent and were cached that way by the AMP caches. The prompt and the blocking now stay, as they already did on regular pages.
* Fixed: A consent given under one law or banner (for example the US notice) was honoured by the server and by Google Consent Mode on a page served under another (for example the GDPR banner after travelling or a VPN), until the banner script noticed and withdrew it. Every layer now rejects a consent cookie from a different scope before anything runs. Sites without geographic routing ask again only when the law changes, not when a banner is renamed or the WordPress salts are rotated, and on cached pages Google Consent Mode waits for the visitor's region and then restores the stored choice.
* Fixed: Vendors that queued a request on the TCF API before the consent framework finished loading never got an answer: the queue was discarded. Queued commands are now replayed.
* Fixed: The consent log stored the TC string of the previous decision, so a Reject row carried the Accept All string. Each row now records the string of its own decision.
* Added: Right to Object in the IAB TCF section of the preference centre, per purpose and per vendor. Legitimate-interest signals are no longer sent for vendors the visitor switched off or after Reject All, the switches are never shown pre-ticked, and vendor consent is only signalled for vendors that were actually shown to the visitor. Vendors the Global Vendor List marks as deleted are no longer listed or signalled, the list is downloaded as soon as TCF is switched on, and the TCF dates follow the IAB day-level rule.
* Fixed: A withdrawal made within ten seconds of an acceptance, from the same browser, was dropped by the flood limit, so the log kept saying "accepted". A correction by the same visitor now goes through; new visitors from one address are still limited.
* Fixed: With Advanced Consent Mode, a custom blocking rule that happened to match the inline Consent Mode default could neutralise it while the Google tags still ran. The default is now protected from the blocker.
* Fixed: Server-side blocking missed video and audio sources and posters, legacy object and embed players, inline style attributes, compact CSS imports and stylesheets inside noscript, all of which load before any script runs. They are now held back until consent like images and frames, and the consent runtime gates the same elements when scripts create them.
* Fixed: A theme or edge service that supplies the visitor's country through the faz_visitor_country filter was not counted as a country source when deciding whether a page may be cached, so a page rendered for one jurisdiction could be served from cache to another. Tor exits flagged by Cloudflare now go to the most protective rules, a country looked up for an explicit IP address no longer comes from the current visitor's Cloudflare or mod_geoip header, and geolocation caches no longer store a plain hash of the visitor's IP.
* Fixed: The data request form refused every submission when its page was served from a full-page cache for longer than the security token lives, and the Do Not Sell form could cache one visitor's state for everyone. Both pages are now kept out of page caches, the Do Not Sell form recovers an expired token on its own, and every refusal names the email fallback.
* Fixed: The consent log now records the jurisdiction that was actually enforced, includes the TC string, GPP string, GPC and DNT signals, country and ruleset in the CSV export, adds the time in UTC, and single Do Not Sell records can be fetched by id.
* Fixed: Generated cookie policies could be published with no controller name or contact for GDPR, CCPA and LGPD; publishing now requires them, while a page already live keeps showing for visitors. International transfers are described under the document's own law (LGPD, POPIA or neutral) instead of always citing the GDPR, the legacy cookie table shows transfer disclosures too, Dutch and Croatian policies are fully translated, and the consent-record period comes from the consent-log setting.
* Fixed: The WP Consent API was told the banner's law instead of the regime actually applied; the IAB labels are now translatable; GPC no longer rewrites the consent cookie on every page view; explicit blocking rules are kept on the client under the payment-gateway exemption; restored embeds keep the site's sandbox and referrer settings; uninstall with data removal also removes the plugin's user meta; and wp faz status reports per-service consent correctly.
* Fixed: The inline bootstrap that protects the page while the consent runtime downloads now stands down when an optimiser runs it after the runtime, and hands over to a runtime that arrives late, instead of staying in its fallback mode for the whole visit.
* Fixed: Query Monitor's admin bar stopped working on any page that loaded a tracker, because its inline data block names the page's scripts and FAZ read a tracker signature inside it (#322). An inline script that only assigns JSON data is no longer searched for tracker signatures, in both places FAZ judges inline scripts; the script is still judged by its own id, and any call or statement next to the data is checked as before.
* Fixed: The "Site Kit by Google" entry of the provider list and its blocking template matched every file of the Site Kit plugin, so its admin bar, dashboard scripts and consent-mode bridge were blocked until analytics was accepted, for the administrator too (#322). The entry now matches only the gtag.js tag Site Kit prints for Google Analytics.
* Fixed: With LiteSpeed, or any script that moves elements one by one, pages could freeze and run the browser out of memory: "Remove Query Strings" served the previous consent runtime under the new page, and the inline protection never let such a loop advance. Held elements now move at once, the hold has a hard limit, an older runtime is handed the page, and FAZ asset URLs keep their version under "Remove Query Strings".
* Fixed: A page rendered for a returning visitor whose consent, Global Privacy Control signal or Do Not Sell choice changed the server-side blocking could be cached and served to everyone. Such responses now stay out of shared caches (filter: faz_veto_cache_for_visitor_state). Under CCPA, sale and sharing scripts are held in the cached page and released in the browser only when the visitor has not opted out (filter: faz_ccpa_park_sell_share_by_default).
* Fixed: Sites that cannot resolve a country no longer disable the page cache because TCF, country banners or AMP are configured. Cache bypasses now also reach CDN caches, Vary: Accept-Language is gone, a server-side A/B test no longer freezes one variant in cache, and the Pinterest app is no longer treated as a crawler.
* Fixed: FlyingPress delayed the banner until the first click when FAZ was served from a CDN or the page used reCAPTCHA. FAZ is now excluded from "Delay third-party scripts" and "Remove unused CSS", and rewritten FAZ tags are restored before caching. Page caches are also purged after scheduled scans, vendor-list updates and Cookie Policy saves, with new support for Nginx Helper, Proxy Cache Purge, WP-Optimize and Super Page Cache.
* Fixed: With more than about 420 IAB vendors, Accept All granted no vendor consent. The vendor record is now compact, the banner asks again when vendors are added, and the vendor list downloads in the background.
* Fixed: An inline script containing video, image or source markup could be broken by the server-side blocker, and unusual comment or SVG markup could hide a tracker from it. When the blocker cannot finish a page, it now blocks instead of letting the page through.
* Fixed: Tooltips and popovers that keep HTML in an attribute (data-html, title) were broken by the server-side blocker when that HTML held an image or an embedded frame. Tags written inside an attribute value are now left alone; the images and frames on the page are blocked as before.
* Fixed: Consent records and data requests from pages cached for days were refused; both now fetch a fresh token before sending.
* Fixed: The browser-language switch gave up after 500 ms, so visitors on slower hosts kept the default-language banner.
* Fixed: System Status "Copy" included help text and the full provider catalogue, which forums rejected as spam; it now copies the readings only.
* Fixed: The Czech cookie policy said consent was asked before strictly necessary cookies were set; it now says before optional ones, in the templates and in the translated policy strings (thanks to vvvamik, #331).
* Fixed: Generated cookie policies no longer leave the "Cookies used" heading empty when no cookie is declared: it now says so, and that note and the "None declared." note under third-party services are written in the policy's language, Brazilian Portuguese, Spanish and Bulgarian included, instead of English. The Brazilian LGPD policy now gives the Art. 19 deadlines for confirmation and access (immediately in simplified form, or a full statement within 15 days) instead of promising a "reasonable period".

= 1.34.1 =
* Fixed: A data request submitted through [faz_dsar_form] could not be acted on from the admin. The link in the notification email opened a record that showed a title and nothing else, so honouring the request meant retyping the address into Tools → Erase Personal Data by hand. The record now shows what was submitted and creates the matching WordPress privacy request — erasure for Art. 17, export for Art. 15 and Art. 20 — with WordPress's own confirmation email verifying the address first. Requests are also listed under the plugin menu, with requester, right invoked and status.
* Fixed: The data request form accepted an unbounded number of submissions. The 60-second lock per address only spaced them out, so one a minute with a fresh email each time went on indefinitely. There are now cumulative 24-hour ceilings, per address and site-wide, all filterable; the window closes 24 hours after the first submission rather than rolling forward while a flood lasts. The site-wide ceiling bounds the emails, not the right to submit: past it a request is still recorded and the per-request emails stop, so a flood cannot switch off anyone's right to make a request. It cannot hide one either: the admin gets an hourly summary instead, and the number of requests awaiting action is shown on the plugin's menu, which does not depend on email at all.
* Fixed: The per-connection limit on the data request form needed an address that identifies one visitor, and on a site behind a CDN or a reverse proxy it did not have one: every visitor arrived as the proxy's address, so five submissions closed the form for everybody else for 24 hours. The visitor's address is now resolved on its own behind Cloudflare; another CDN is declared with the faz_trusted_proxy_cidrs filter (plus faz_proxy_forwarded_header if it writes a header of its own), and a reverse proxy on the same host with faz_loopback_proxy_header, which is unset by default because a header the local proxy does not write arrives from the visitor untouched and guessing it would let a visitor choose their own limit. Where no per-visitor address can be found the per-connection limit is skipped instead of being applied to the whole site — the site-wide limits still bound a flood. A forwarded header is still ignored when it cannot be vouched for, so nobody can choose their own limit by sending one. The admin is told when the limit refuses a request, and when it is not in force, with the filter names to set.
* Added: faz_dsar_pre_submit, so a form-protection plugin (ALTCHA/OpenPorte, Turnstile, hCaptcha, Akismet) can gate the data request form. Those plugins protect the forms they know about and could not see this one; a filter returning a WP_Error now refuses the submission and shows its own message to the visitor.
* Fixed: Honouring an erasure from the new button deleted the stored request along with the personal data in it, so the record that showed a request had been received and answered went too. The request is now redacted instead: the name, the address and the message are removed, and what stays is the right that was invoked and the date — enough to demonstrate compliance, identifying nobody. No hash of the address is kept. One thing worth knowing: an erasure matches the email address rather than a single request, so every stored request from that address is redacted together, including one that is still waiting for an answer; the summary WordPress shows you now says how many of those there were.
* Added: Anti-bot protection on the data request form. The honeypot field is no longer at a fixed name a script can skip, and the form carries a signed timestamp so a submission that arrives in under three seconds, or from a page left open for a day, is refused. An optional proof-of-work check (off by default, no third-party service, no API key, no cookie, no puzzle to solve) can be switched on with one line. Every check lets the request through if it cannot run — with JavaScript off the form still works — because this form exercises a legal right and a protection that fails must not become a refusal. Put plainly: the proof of work and the dwell stamp only charge clients that run the form's JavaScript and never reject a client that omits them (fail-open by design), and the cumulative ceilings are the control that actually stops a spam chain.

* Fixed: The withdrawal fallback widget reappears when theme attributes or CSS transitions and animations hide the footer link after consent.
* Fixed: PHP GeoIP status requires a successful lookup, checks MMDB after extension failures, and explains how to repair a failing source.
* Fixed: The consent banner disappeared on sites with a full-page cache and came back when the cache was turned off. Hiding the banner from search-engine crawlers is on by default, which makes the page different for a crawler than for a person — and a cache does not distinguish the two, so the first crawler to visit a page left a copy with no banner that was then served to everyone, with tracking scripts unblocked. Link previews from WhatsApp, Telegram, Discord, X and Pinterest count as crawlers too, so simply sharing a page could do it. The banner is now hidden from a crawler only on a response that is also kept out of every cache, and if that is not possible the banner is shown instead. AMP pages are covered as well, and updating the plugin clears the caches, so an affected site is fixed as soon as it updates.
* Fixed: The generated configuration and main banner JavaScript load with ordered defer, removing their render-blocking requests. A small inline bootstrap protects dynamic scripts, images, frames, embeds, stylesheets and network calls until the full consent runtime is ready, and the deferred loading is only used when that protection is really in place on the page being served.
* Fixed: Resources that a script or a page builder inserts as HTML, rather than by setting an attribute, are now protected during that window too. The previous version of this change left them unprotected and, worse, left them unexamined afterwards, so a tracker inserted that way stayed live for the rest of the visit with no placeholder and no way for the visitor to decide about it.
* Fixed: A responsive image list (srcset) is now checked candidate by candidate instead of being judged on its first entry, so a list that starts with one of your own images can no longer smuggle a tracker in behind it.
* Fixed: Anything held back is now stored so it can be restored: before, a resource that was only meant to be delayed could end up switched off for the rest of the visit even after the visitor accepted everything.
* Fixed: Google Consent Mode defaults and the TCF command stub remain available before page scripts, and a failed configuration download keeps pending resources blocked. If the consent runtime cannot load at all, same-origin requests are released so the site keeps working, third-party resources stay blocked for the rest of the visit, and the browser console explains what happened and where to look.
* Fixed: A reopen link in the footer could be accepted as valid on the strength of text no visitor can click, so the plugin hid its own revisit widget and the site was left with no usable way to withdraw consent. The check removes script, style, title and textarea regions before looking for the link, but it ended those regions at the first tag whose name merely started the same way — `</titlex>` closed `<title>` — and treated the text after it as real markup. Those regions now end exactly where HTML says they do.
* Fixed: A tracker named inside an `image-set()` or `src()` value, after one of your own images, was seen by the server-side protection but not by the consent runtime that takes over from it. On a high-resolution display the request went out before the visitor had chosen anything.
* Fixed: A tracker address written with backslashes, or with a scheme and no slashes (`https:tracker.example/p.png`), inside an embedded document was read as a tracker by the server and as nothing by the runtime. Browsers resolve every one of those spellings to the same host, so the request went out.
* Fixed: A stylesheet address carrying credentials before the host (`url(https://user@tracker.example/f.woff2)`) was not recognised as belonging to a known provider, so the resource was not held back. Firefox does fetch such addresses.
* Fixed: An embedded document written directly in a post could be damaged rather than delayed. The pass that protects scripts wrote its own attribute inside the embedded markup and cut the document short at the first quotation mark, so the embed was destroyed instead of being restored after consent. The embed is now set aside untouched and comes back exactly as it was written.
* Fixed: With the new deferred loading, the IAB TCF framework could report its own interface as not displayed while the consent banner was on screen, so a vendor asking through the TCF API was told the notice had never been shown. The runtime already recorded that the banner had appeared; the framework now reads that record instead of relying only on an event it could arrive too late to hear.

= 1.34.0 =
* Fixed: The banner did not render at all with WPSpeed by JExtensions active, for two separate reasons. Its image optimiser rewrites the page through an HTML4 parser that truncates the banner template at the first closing tag, leaving a 0 px consent bar with no buttons; and because it combines and defers the scripts, initialisation ran before part of the file existed and died halfway. The template is now written so both parsers keep it intact, and initialisation runs in the same order whether the script is deferred, async, combined or delayed.
* Fixed: A reopen link verified in the footer could leave a visitor with no way to withdraw consent. Verification was a text match, so a control inside a template, hidden, inert or hidden by an inline style counted as usable. The markup is now parsed, `disabled` is judged as the HTML specification defines it — never on a link, inherited only from a `<fieldset disabled>` — and the plugin's own widget reveals itself in the browser whenever no usable control is present, including when the theme hides the footer, a breakpoint drops it, or a stylesheet finishes loading after the page.
* Fixed: Jurisdiction routing said it was active on sites where nothing can resolve a country. The switch governs two jobs — applying a jurisdiction's rules, which always works through the most protective fallback, and routing by country, which needs a geo source — and every screen described the second. The admin now reports four distinct states, and a saved MaxMind licence key no longer counts as proof that lookups work: that is checked with a real lookup.
* Fixed: AMP pages keep their own native reopen control instead of relying on a footer link verified for the regular frontend. The two are different mechanisms, and a footer probe cannot prove an AMP tap action exists.
* Added: A compact phone layout for the notice buttons, under Banner Control in Settings. Below 440px the shipped templates give each button a row of its own, which takes 44% of a typical phone screen; compact puts accept and reject on one row and brings that to 29%. The default is unchanged, so no existing site looks different after the update. Accept and reject stay exactly the same size in every language, every button keeps a 44px tap target, and long labels wrap instead of being cut off.
* Changed: Lighter pages. The service catalogue — about 43 KB with per-service consent on — moved from the inline configuration into the cached, content-hashed configuration file, so it is downloaded once instead of with every page. The DSAR form script, the Do-Not-Sell form script and the 8 KB Cookie Policy stylesheet now load only where their shortcode renders. Provider matching is cached per URL: on a reported homepage it was costing about 200 ms of main-thread time, now 2 ms in the browser, with identical results.
* Added: Filter `faz_load_shortcode_assets_everywhere` restores loading a shortcode's assets on every page, per asset, for page builders that inject the shortcode markup client-side.

= Older versions =
Older releases (1.33.0 and earlier) are listed in the full changelog on GitHub, linked at the top of this section.
