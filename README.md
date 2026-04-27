# FAZ Cookie Manager

**The only cookie consent plugin you need. 100% free, no required cloud account, no subscriptions.**

---

**Tired of cookie consent plugins that lock essential features behind paywalls, require cloud accounts, or send your visitors' data to third-party servers?**

FAZ Cookie Manager is a WordPress plugin that helps you implement cookie consent and privacy workflows for international regulations -- completely free, with no strings attached.

No account to create. No required cloud service to connect. No "premium" plan to unlock basic features like consent logging or geo-targeting. Core consent features run on your own server, and you own all your data.

## Why FAZ Cookie Manager?

Most cookie consent plugins follow the same pattern: a free version with crippled features, and a paid tier starting at $10-50/month that unlocks what you actually need. FAZ Cookie Manager breaks that model:

| Feature | Others (free) | Others (paid) | FAZ Cookie Manager |
|---|---|---|---|
| Cookie banner | Limited | Full | **Full** |
| Cookie scanner | No | Yes | **Yes** |
| Consent logging + CSV export | No | Yes | **Yes** |
| Google Consent Mode v2 | No | Yes | **Yes** |
| IAB TCF v2.3 + GVL | No | Yes | **Yes** |
| Geo-targeting | No | Yes | **Yes** |
| Multi-language (180+) | No | Yes | **Yes** |
| Cloud dependency | No | **Yes** | **No** |
| Price | Free | $10-50/mo | **Free forever** |

> **A note on IAB TCF v2.3:** The plugin includes a fully functional IAB TCF v2.3 CMP implementation -- TC String encoding, GVL integration, vendor consent UI, and all required `__tcfapi()` commands work correctly. However, for the TC String to be recognized by the ad-tech supply chain, the CMP must be registered with IAB Europe (which requires an annual fee). CMP registration is on the roadmap. If you'd like to help make it happen, consider supporting the project:
>
> [![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-yellow?style=flat&logo=buy-me-a-coffee)](https://buymeacoffee.com/fabiodalez)

---

## Screenshots

### Cookie Consent Banner
Consent banner with Customize, Reject All, and Accept All buttons. Appears on first visit, fully responsive and keyboard accessible.

![Cookie consent banner](assets/screenshot-1.png)

### Dashboard
Analytics overview with pageviews chart, consent distribution (accept/reject rates), and quick links to all plugin sections.

![Dashboard](assets/screenshot-2.png)

### Cookie Banner Editor
Customize layout (box, bar, popup), position, theme (light/dark), and regulation type (GDPR/CCPA/both) with a live preview. Includes tabs for Content, Colours, Buttons, Preference Center, and Advanced settings.

![Cookie Banner editor](assets/screenshot-3.png)

### Cookies Management
View all detected cookies organized by category (Necessary, Functional, Analytics, Performance, Advertisement). Edit, delete, or add cookies manually. Integrated with the Open Cookie Database (2,242 definitions) for automatic categorization.

![Cookies management](assets/screenshot-4.png)

### Cookie Scanner
Built-in browser-based scanner with multiple scan depths: Quick (10 pages), Standard (100), Deep (1,000), or Full scan. Runs locally -- no external service, no API limits.

![Cookie scanner](assets/screenshot-5.png)

### Consent Logs
Complete audit trail of every visitor's consent decision. Shows consent ID, status, categories chosen, anonymized IP, and page URL. Search, filter, and export to CSV for GDPR accountability.

![Consent Logs](assets/screenshot-6.png)

### Google Consent Mode v2
Configure all 7 consent signal types with default and granted states. Includes Google Additional Consent Mode (GACM) for ad technology provider IDs.

![Google Consent Mode](assets/screenshot-7.png)

### Languages
Select from 180+ available languages. The banner text adapts automatically to the visitor's browser language.

![Languages](assets/screenshot-8.png)

### Settings
Global controls: enable/disable banner, exclude pages, consent log retention, scanner limits, Microsoft UET/Clarity consent APIs, and IAB TCF v2.3 toggle with CMP ID and Purpose One Treatment options.

![Settings](assets/screenshot-9.png)

---

## Compliance

| Standard | Status | Details |
|----------|--------|---------|
| GDPR (EU) | Assists | Opt-in model, granular consent, right to withdraw |
| ePrivacy Directive | Assists | Consent-based script blocking support |
| CCPA / CPRA (California) | Supported | "Do Not Sell" opt-out, GPC signal detection |
| Garante Privacy LG 2021 (Italy) | Assists | Equal-weight buttons, no scroll-as-consent, 6-month max expiry option |
| EDPB Guidelines | Assists | Scroll != consent, no pre-checked categories, equal button prominence |
| IAB TCF v2.3 | Supports | Full `__tcfapi()` CMP, GVL integration, vendor consent UI, DisclosedVendors segment |
| Google Consent Mode v2 | Supports | Default-denied signals, consent update on interaction |
| LGPD (Brazil) | Supported | Consent-based model |
| POPIA (South Africa) | Supported | Opt-in consent |
| WCAG 2.1 AA | Accessibility-focused | Keyboard navigation, focus indicators, ARIA labels |
| WP Consent API | Supports | Registered via `wp_consent_api_registered_` filter |

> **Legal Disclaimer:** Compliance status depends on correct plugin configuration for your specific use case and does not constitute a legal guarantee. This table is for informational purposes only and is not legal advice. Consult a qualified legal professional for your jurisdiction.

### Automated Compliance Tests

Playwright tests verify consent behavior and policy-oriented safeguards at runtime:

- TF01-TF18: Full functional test suite covering banner display, cookie blocking, consent flow, mobile, accessibility, revocation, logging, GCM signals, and cookie declarations
- P05: No ambiguous button labels (dark pattern check)
- G07: Non-technical toggles OFF by default
- I08: Technical cookies non-disableable
- T01-T03: IAB TCF `__tcfapi` CMP stub, TC String format, cross-frame messaging
- GCM01-GCM05: Google Consent Mode default-denied, granted on accept, revocation
- CD01-CD03: Cookie declarations, descriptions, categories
- VIS01-VIS09: Visual integrity checks across banner types and preference centers
- IAB01-IAB39: IAB Settings page, GVL admin page, vendor selection, TC String validation

**The test suite includes automated consent, privacy, accessibility, and integration checks across frontend, admin, scanner, GCM/TCF, visual integrity, and IAB flows.**

---

## Installation

1. Download the latest release from [GitHub Releases](https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/releases)
2. Upload the `faz-cookie-manager` folder to `/wp-content/plugins/`
3. Activate in WordPress admin > Plugins
4. Go to **FAZ Cookie** in the admin sidebar
5. Click **Scan Site** on the Cookies page to detect cookies
6. Customize banner design, text, and regulation type

### Requirements

- WordPress 5.0+
- PHP 7.4+
- MySQL/MariaDB
- Built-in Open Cookie Database snapshot included; `Update Definitions` refreshes it from GitHub.
- Core consent features run locally. Optional refresh/download features may contact GitHub, IAB Europe, MaxMind, or the AMP CDN depending on which features you enable and use.

---

## Features (detailed)

### Cookie Banner

- **Three banner types**: Classic (bar), Popup (modal), Box (widget)
- **Configurable position**: Top, bottom, or any corner
- **Three legislation modes**: GDPR (opt-in), CCPA (opt-out), Info-only
- **Preference center**: Granular per-category toggles with cookie audit tables
- **Full color customization**: Background, text, button colors via color pickers
- **Theme presets**: Light and dark themes
- **Brand logo**: Upload custom logo via WordPress Media Library
- **Live preview**: Real-time banner preview in admin as you edit
- **Responsive**: Adapts to mobile viewports, tested on 375px width
- **RTL support**: Arabic, Hebrew, Persian, Urdu, and other RTL languages
- **Consent expiry**: Capped at 180 days per Garante Privacy requirements
- **Revisit widget**: Floating button to reopen preferences after consent
- **Video placeholder**: Blocks YouTube/Vimeo embeds until consent
- **Page exclusions**: Skip banner on specific pages (supports wildcards)
- **Subdomain sharing**: Share consent across subdomains
- **Reload on accept**: Optional page reload after consent

### Buttons

- **Accept All** -- grants consent to all categories
- **Reject All** -- denies all non-necessary categories (equal visual weight as Accept)
- **Customize / Settings** -- opens preference center for granular control
- **Read More** -- links to privacy policy (configurable: button or link, nofollow, new tab)
- **Do Not Sell** -- CCPA opt-out button (only in CCPA mode)

### Cookie Management

- **Cookie list**: Full CRUD for cookies -- name, domain, duration, description, category, URL pattern
- **Cookie categories**: Necessary, Functional, Analytics, Performance, Advertisement, Uncategorized
- **Per-category prior consent**: Each category has a configurable `prior_consent` flag. Set to OFF for first-party analytics cookies that meet the Garante Privacy exemption (first-party only, aggregated data, anonymized IP, no cross-referencing)
- **Audit table**: Per-category cookie listing embedded in the preference center
- **Multilingual descriptions**: Cookie description and duration stored per-language

### Cookie Scanner

A fully local browser-based cookie crawler -- no external scanning service.

- Discovers pages via sitemap.xml parsing + homepage link extraction
- Scans pages in iframes to detect all cookies
- Configurable scan depth: Quick (10), Standard (100), Deep (1000), Full
- Deduplicates -- never overwrites existing cookie entries
- Scan history with results

### Open Cookie Database

Integrates the [Open Cookie Database](https://github.com/fabiodalez-dev/Open-Cookie-Database) (Apache-2.0) for automatic cookie identification.

- **Bundled snapshot included** — 2,200+ definitions ship with the plugin for immediate use
- **Manual update** via admin UI button
- **Exact + wildcard matching**: e.g., `_gat_` prefix matches `_gat_UA-12345`
- **Auto-categorize**: One-click bulk categorization

### Google Consent Mode v2

Full GCM v2 integration with all required consent signals:

- `ad_storage`, `analytics_storage`, `functionality_storage`, `personalization_storage`, `security_storage`
- `ad_user_data`, `ad_personalization` (v2 additions)
- **Default: all denied** -- updates to granted on consent
- **Wait for update** -- configurable delay (ms) for slow-loading CMPs
- **URL passthrough** -- pass ad click info even when consent denied
- **Ads data redaction** -- redact ad data when consent denied

### Google Additional Consent Mode (GACM)

- Enable/disable toggle
- Configure ATP (Authorized Technology Provider) IDs
- Generates Additional Consent string format: `1~id.id.id...`

### IAB TCF v2.3 CMP with Global Vendor List

Full `__tcfapi()` implementation aligned with the IAB Transparency & Consent Framework v2.3:

- **Commands**: `ping`, `getTCData`, `addEventListener`, `removeEventListener`, `getVendorList`
- **Global Vendor List (GVL)**: Server-side download and caching of the IAB GVL v3 (1,100+ vendors). Weekly auto-update via WP-Cron, manual update from admin UI
- **GVL Admin Page**: Browse, search, and filter all IAB-registered vendors. Select which vendors your site uses. Paginated table with purpose/feature details
- **Real Vendor Consent**: TC Strings encode actual vendor consent and legitimate interest bits based on user choices and vendor purpose declarations
- **Special Feature Opt-ins**: TCF v2.3 Special Features (precise geolocation, device scanning) mapped from user category consent
- **DisclosedVendors Segment**: Mandatory segment listing all vendors the CMP discloses to users
- **Vendor Legitimate Interest**: Honors user's Right to Object -- LI bits are only set when the user hasn't objected to the corresponding purposes
- **Vendor Consent UI**: Per-vendor toggles in the preference center, with vendor name, purposes, privacy policy link, and cookie retention info
- **TC String**: Full base64url encoding with core segment + DisclosedVendors segment, `euconsent-v2` cookie
- **Cross-frame messaging**: `__tcfapiLocator` iframe + `postMessage` bridge
- **Command queue**: Processes pre-load `__tcfapi.a` queue
- **CMP Stub**: Inline stub responds to `ping` before main script loads (`cmpStatus: 'stub'`)
- **Dynamic config**: ConsentLanguage, publisherCC, gdprApplies, CMP ID, Purpose One Treatment -- all configured from server-side settings
- **GVL file storage**: Cached at `wp-content/uploads/faz-cookie-manager/gvl/vendor-list.json` for frontend access

#### CMP ID and IAB Registration

FAZ Cookie Manager works in two modes:

| Mode | CMP ID | What works | What doesn't |
|------|--------|------------|--------------|
| **Self-hosted** (default) | `0` | Banner, cookie blocking, Google Consent Mode v2, consent logging, all admin features | Ad-tech vendors ignore the TC String (unrecognized CMP) |
| **IAB-registered** | Your ID | Everything above **plus** full TCF vendor chain -- SSPs, DSPs, and ad exchanges read and honor the TC String | Requires [IAB CMP registration](https://iabeurope.eu/cmp-list/) (annual fee) |

**When do you need a registered CMP ID?**

- If you run programmatic advertising (header bidding, ad exchanges) and need the buy-side to respect granular vendor consent via the TC String
- If your DPA or legal counsel requires a registered CMP for full TCF vendor-chain support

**When is self-hosted (CMP ID = 0) sufficient?**

- You only need GDPR/ePrivacy-oriented cookie consent tooling (banner + script blocking)
- You use Google Consent Mode v2 (GCM uses its own consent signal channel, independent of TCF)
- You don't participate in the IAB programmatic advertising supply chain

To set your CMP ID: **Settings > IAB TCF v2.3 > CMP ID**

### Microsoft Consent Integration

- **UET Consent Mode**: Sets `ad_storage`/`analytics_storage` defaults to denied, updates on consent
- **Clarity Consent API**: Calls `window.clarity('consent')` when analytics accepted

### Consent Logging

Stores proof of consent in a local database table for GDPR accountability:

- **Consent ID**: Unique per-visitor identifier
- **Status**: accepted, rejected, or partial
- **Categories**: JSON map of which categories were accepted/rejected
- **IP hash**: SHA256 hash (privacy-preserving, no raw IPs stored)
- **Pagination** and **search** in admin UI
- **CSV export** with date-stamped filename
- **Retention period**: Configurable (default: 12 months)

### Pageview Analytics

Built-in analytics dashboard -- no Google Analytics needed for basic metrics:

- **Events tracked**: pageview, banner_view, banner_accept, banner_reject, banner_settings
- **Dashboard charts**: Daily pageview trend, accept/reject rates

### Geolocation

Detects visitor country for geo-targeted banner display:

- **Detection chain**: Cloudflare > Apache mod_geoip > PHP GeoIP extension > local MaxMind GeoLite2 database
- **Geo-targeting modes**: ALL (everyone), EU (EU/EEA + UK), US only, Custom country list
- **Proxy-aware**: Reads `CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP` headers
- **Cached**: 1-hour WordPress transient per IP

### Multilingual Support

- **11 bundled languages**: English, German, French, Italian, Spanish, Polish, Portuguese (PT + BR), Hungarian, Finnish, Dutch
- **180+ selectable languages** in the admin configuration
- **Browser language detection**: resolved client-side from `navigator.languages` so full-page/CDN caches cannot serve the wrong language to visitors (see below)
- **Plugin integration**: Polylang, WPML, TranslatePress, Weglot auto-detected (URL-based, always cache-safe)
- **Per-language banner content**: Separate title, description, button text per language
- **RTL auto-detection**: Arabic, Hebrew, Persian, Kurdish, Urdu

#### Full-page cache and CDN compatibility

When **no URL-based multilingual plugin** (WPML/Polylang/TranslatePress/Weglot)
is installed and **two or more languages** are selected in the admin, the
banner HTML is rendered server-side in the **site default language** so it
stays safe to cache. A `Vary: Accept-Language` response header is emitted for
caches that honour it, and the banner is swapped client-side via
`GET /wp-json/faz/v1/banner/{lang}` when the visitor's browser prefers a
different selected language.

Recommended cache configuration:

- **Cloudflare (APO / Cache Everything / Cache Rules)**: `Vary` alone is not
  sufficient in these modes. Either keep banner pages off "Cache Everything",
  or add a Cache Rule that includes `Accept-Language` in the cache key for
  the affected pages.
- **LiteSpeed Cache**: enable **Cache by language** (Cache → Advanced) or
  exclude banner-bearing pages from HTML caching.
- **WP Rocket**: enable **Cache by language** under Advanced Rules.
- **nginx fastcgi_cache / page caches**: add `$http_accept_language` to the
  cache key.

Escape hatch — disable browser detection entirely (banner always served in
the site default language):

```php
add_filter( 'faz_disable_browser_language_detection', '__return_true' );
```

Disable only the `Vary` header (keep client-side detection active):

```php
add_filter( 'faz_send_vary_header', '__return_false' );
```

### Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[faz_cookie_table]` | Responsive cookie table grouped by category for policy pages |
| `[cookie_audit]` | Backward-compatible alias |

**Attributes:** `columns`, `category`, `heading`

---

## REST API

All endpoints under `faz/v1`. Admin endpoints require authentication (WordPress nonce).

### Settings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/settings` | Get all plugin settings |
| POST | `/settings` | Update settings (merge) |
| POST | `/settings/reinstall` | Recreate missing DB tables |
| POST | `/settings/apply_filter` | Apply WP Internal filter changes |
| POST | `/settings/geolite2/update` | Download/update GeoLite2 database |
| GET | `/settings/geolite2/status` | GeoLite2 database status |

### Google Consent Mode

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/gcm` | Get GCM settings |
| POST | `/gcm` | Update GCM settings |

### Cookies

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/cookies` | List cookies (filter by category) |
| POST | `/cookies` | Create a cookie |
| GET/PUT/DELETE | `/cookies/{id}` | Read/update/delete a cookie |
| POST | `/cookies/bulk-update` | Bulk update cookies |
| POST | `/cookies/bulk-delete` | Bulk delete cookies |
| POST | `/cookies/scrape` | Lookup names against Open Cookie Database |
| GET | `/cookies/definitions` | Get cookie definitions status |
| POST | `/cookies/definitions/update` | Download/refresh definitions from GitHub |

### Scanner

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/scans` | Scan history |
| POST | `/scans` | Start a new scan |
| GET | `/scans/{id}` | Scan details |
| GET | `/scans/info` | Scanner configuration |
| POST | `/scans/discover` | Discover site pages |
| POST | `/scans/import` | Import scan results |

### Consent Logs

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/consent_logs` | List logs (paginated, searchable) |
| GET | `/consent_logs/statistics` | Aggregate statistics |
| GET | `/consent_logs/export` | CSV export |
| GET | `/consent_logs/{consent_id}` | Single consent record |

### Pageviews

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/pageviews` | Record event (public) |
| GET | `/pageviews/chart` | Pageview chart data |
| GET | `/pageviews/banner-stats` | Banner interaction stats |
| GET | `/pageviews/daily` | Daily pageview breakdown |

### Banners

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/banners` | List banners |
| POST | `/banners` | Create a banner |
| GET/PUT/DELETE | `/banners/{id}` | Read/update/delete a banner |
| POST | `/banners/bulk` | Bulk operations |
| GET | `/banners/preview` | Banner preview HTML |
| GET | `/banners/presets` | Theme presets |
| GET | `/banners/configs` | Banner configuration |

### Global Vendor List (GVL)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/gvl` | GVL status (version, vendor count, purposes) |
| GET | `/gvl/vendors` | List vendors (paginated, searchable, filterable) |
| GET | `/gvl/vendors/{id}` | Single vendor details |
| POST | `/gvl/update` | Download/refresh GVL from IAB |
| GET | `/gvl/selected` | Get selected vendor IDs |
| POST | `/gvl/selected` | Save selected vendor IDs |

### Languages

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET/POST | `/languages` | Get/update language configuration |

---

## Database

Five custom tables (created on activation):

| Table | Purpose |
|-------|---------|
| `wp_faz_banners` | Banner configuration and per-language content |
| `wp_faz_cookies` | Cookie definitions (name, category, description, domain, pattern) |
| `wp_faz_cookie_categories` | Cookie categories (necessary, functional, analytics, etc.) |
| `wp_faz_consent_logs` | Visitor consent records with IP hash |
| `wp_faz_pageviews` | Pageview and banner interaction events |

## Frontend Events

JavaScript events fired on the `document` for third-party integration:

| Event | When | Detail |
|-------|------|--------|
| `fazcookie_consent_update` | User accepts/rejects/saves | `{ accepted: ['slug', ...], rejected: ['slug', ...] }` |
| `fazcookie_banner_loaded` | Banner is displayed | -- |

### Consent Cookie Format

Cookie name: `fazcookie-consent`

Value format: `consentid:{base64},consent:yes,action:yes,necessary:yes,functional:no,analytics:no,marketing:no,performance:no`

## WordPress Hooks

### Filters

| Filter | Description |
|--------|-------------|
| `faz_cookie_domain` | Override the consent cookie domain |
| `faz_allowed_html` | Customize allowed HTML tags in banner |
| `faz_current_language` | Override detected language |
| `faz_language_map` | Add language code normalization mappings |
| `faz_registered_admin_menus` | Register additional admin menu items |

### Actions

| Action | Description |
|--------|-------------|
| `faz_after_activate` | After plugin activation/upgrade |
| `faz_after_update_settings` | After settings are saved |
| `faz_after_update_cookie` | After cookies are bulk-updated |
| `faz_reinstall_tables` | Trigger table recreation |
| `faz_clear_cache` | Trigger cache flush |

---

## Changelog

### 1.13.7
- **Fix (#85)**: GVL update no longer triggers a fatal `Call to undefined function FazCookie\…\wp_tempnam()` on the REST endpoint. The function lives in `wp-admin/includes/file.php`, which is not auto-loaded outside the admin context, and the namespaced caller resolved the unqualified name in the local namespace. Now `\wp_tempnam()` (global) is called and the file is `require_once`'d on demand. Same fix shape applied to `download_url()` in the languages controller.
- **Fix (#87)**: Bricks Builder Video element no longer collapses to zero height when the YouTube iframe is blocked pending consent. `.faz-placeholder--video` keeps its `aspect-ratio: 16/9` and uses `min-width: min(280px, 100%)` so it stays usable on narrow viewports without overflowing.
- **Fix (Gooloo regression)**: comments on sites running wpDiscuz 7.6.x + LiteSpeed Cache + Divi were "completely disfigured" because the `Gravatar` entry in `Known_Providers` was categorised as `functional` and visitors who rejected "functional" had every `<img src="https://secure.gravatar.com/avatar/…">` replaced with the 200-px-tall `.faz-placeholder` div, blowing up the thread layout. Recategorised Gravatar to `necessary` (no cookies, no cross-site tracking, part of the WordPress core comment UX). As defence-in-depth, `wpdiscuz_nonce_*` and `comment_author_*` were added to the `is_wp_internal_cookie()` allowlist so a future Known_Providers entry cannot silently reintroduce the breakage.
- **wp.org compliance pass** (ahead of plugin directory submission): `$_COOKIE` sanitization visible at the access-line; `load_plugin_textdomain()` body documented no-op (auto-loaded since WP 4.6); 4× `__($variable, …)` calls in the cookie-table shortcode replaced with verbatim returns; 8 of 10 flagged inline `<script>`/`<style>` migrated to `wp_enqueue_*` / `wp_add_inline_*` (the 3 residuals carry a `phpcs:ignore` + technical justification: ClassicPress polyfill, `<script type="text/template">` inert HTML, AMP `<amp-consent>` runtime); `_faz_first_time_install` transient renamed to `faz_first_time_install` with migration in `Activator::check_for_upgrade()` and fallback read in `faz_first_time_install()`; `permission_callback => __return_true` on the three public REST routes gained explanatory comments documenting the HMAC-token + rate-limit model.

### 1.13.6
- **New**: blocker-template parity with `Known_Providers`. Every provider the runtime already auto-blocks (143 services — Google Analytics, Adobe, Plausible, Clarity, Mixpanel, Segment, Stripe, Mailchimp, Klaviyo, HubSpot, Pinterest, Snapchat, Reddit, Outbrain, Yandex, etc.) now appears in WP Admin → Cookies → "Add from template". The picker grows from 11 to 142. Privacy contract unchanged — this is a UI-discovery fix.
- **Internal**: the 131 new templates were generated from `includes/data/known-providers.json` so admin picker and runtime blocker stay in sync.

### 1.13.5
- **New**: Matomo (Piwik) blocker template — selectable as an analytics tool in Cookies → Blocker Templates. Covers self-hosted Matomo, Matomo Cloud, and Matomo Tag Manager, plus the full `_pk_*`, `MATOMO_SESSID`, and `mtm_consent*` cookie family. (Matomo was already auto-detected by the runtime script blocker; this just exposes the entry in the admin picker so it's discoverable.)

### 1.13.4
- **Fix**: `wp_localize_script` / translation payloads (`{handle}-js-extra`, `{handle}-js-translations`) for FAZ scripts now also carry the 5 cache opt-out attrs. Those inline tags don't travel through `script_loader_tag`; added a hook on `wp_inline_script_attributes` (WP 5.7+) so the cache-plugin opt-out applies to them too. Closes a guarantee gap that LiteSpeed Guest Mode was exposing.

### 1.13.3
- **Fix**: banner invisible on first paint when LiteSpeed Cache *Delay JS* had a hand-added entry mentioning `faz-cookie-manager` without the full `wp-content/plugins/` prefix — the 1.13.2 path-anchored scrubber was strict-anchored and skipped those entries; 1.13.3 also matches `faz-cookie-manager` as a complete token while still leaving third-party companion names like `my-integration-faz-cookie-manager-compat.js` untouched. Reported by gooloo.de.

### 1.13.2
- **Fix**: GDPR Strict preset "Customize" button (light-blue text on dark-blue background) — the `classic` template CSS had `color: #1863dc` hardcoded instead of reading the preset's `--faz-settings-button-color`, and `border-color` pointed at the text-colour variable instead of its own. Pattern now matches the other template variants.
- **Fix**: banner invisible on LiteSpeed Guest Mode installs — added the missing `litespeed_optm_gm_js_exc` filter so Guest Mode's separate JS exclude list also recognises our scripts; first-visit paint restored on Guest-Mode-enabled sites.
- **Fix**: alt-asset mode (`faz-fw` alias) children (`faz-fw-gcm`, `faz-fw-tcf-cmp`, `faz-fw-a11y`) now correctly tagged with the cache-plugin opt-out attributes; 1.13.1 missed them because the handle list was hardcoded.
- **Fix**: `litespeed_optm_js_delay_inc` scrubbing now path-anchored (`plugins/faz-cookie-manager/`) so third-party integration entries are never collaterally removed.
- **New**: `faz_auto_exclude_cache_plugins` filter for site admins who want to disable the automatic cache-plugin exclusion block (default `true`).

### 1.13.1
- **Auto-exclude FAZ scripts from cache plugins' Delay JS** — LiteSpeed Cache, WP Rocket, Autoptimize, SG Optimizer, Hummingbird, Cloudflare Rocket Loader and W3 Total Cache all default to deferring every JS file until first user interaction, which kept the consent banner dormant on page load and let trackers fire the moment the user scrolled. The plugin now adds `data-no-defer data-no-optimize data-no-minify data-cfasync="false" data-ao-skip` to every FAZ `<script>` and hooks the matching pattern-based exclude filters so admins no longer need to configure a thing.

### 1.13.0
- **Fix (#80)**: per-service consent cookie stays under the browser's 4 KB limit — `_fazSetInStore` omits `svc.<id>` entries whose value matches the category (the frontend loader already falls back to the category when absent). Previously a ~160-service install made every "Save My Preferences" click a no-op because the oversized write was silently dropped.
- **Fix (#78)**: scanner `discover_urls` places recently-modified pages in `priority_urls` so they're exempt from the client-side early-stop threshold — freshly-edited posts could previously be skipped on large sites.
- **Fix**: `shred_non_consented_cookies()` honours the frontend whitelist payload (was only respected on the first page load, then neutralized on every `send_headers`).
- **Fix**: whitelist pattern match is unidirectional with a minimum-length guard — entering `"js"` or `"com"` no longer whitelists nearly every provider.
- **Fix**: preference center focus-retry timers are cancelled on close (no more focus theft after rapid open/close).
- **Fix**: dynamic scripts preserve their original `type` (`module`, etc.) through the block/unblock round-trip via `data-faz-original-type`.
- **Internal**: provider-matrix fixture serialises hit increments under `flock`, `fazApiPut` uses `X-HTTP-Method-Override` for nginx/Apache/php -S portability, permalink-agnostic scanner discover predicate, deduped `get_priority_urls` WP_Query.

### 1.12.1
- **Fix**: LiteSpeed Cache cookies (`_lscache_vary`, `_litespeed_*`) added to internal whitelist

### 1.12.0
- **Security audit**: closed all findings from 20-agent code audit (H2-H5, M1-M28)
- **data: URI blocking**: decoded payload matched against provider patterns (PHP + JS)
- **Uppercase HTML tags**: `strpos` → `stripos` in output buffer guards
- **Consent logging**: throttle fix for empty consent_id, URL credential stripping, UA hashing
- **TCF/IAB v2.3**: `buildConsentArtifacts`, Purpose 1 treatment, euconsent-v2 cleanup
- **Accessibility**: extended focus trap, summary support, localized aria-labels
- **Performance**: `faz_settings` memoized, N+1 eliminated, `faz_current_language()` cached
- **Plugin Check**: 0 ERRORS — all escaping, WP_Filesystem, and ABSPATH issues resolved
- **Tests**: 35+ new E2E tests (category blocking, audit regressions, session fixes)
- **DB migration 3.4.1**: banner table indexes for existing installs

### 1.11.3
- **New: WP 5.7+ `wp_inline_script_tag` filter** — intercepts inline scripts added via `wp_add_inline_script()` before the output buffer. Backward compatible (WP < 5.7 uses the OB fallback).
- **New: returning visitor unblock retry** — `_fazUnblock()` retries at multiple delays (250ms, 1s, 2s) + load event so late-rendered blocked scripts are always restored.
- **Fix: WordPress Plugin Check errors** — resolved all `OutputNotEscaped`, `MissingTranslatorsComment`, and `NoExplicitVersion` findings for wp.org submission compliance.
- **Fix: inline script whitelist bypass** — `is_whitelisted()` now checks only tag attributes, not the inline body.
- **Refactor: `_fazBuildRestoredScript()` helper** — deduplicated script-cloning logic from `_fazUnblockServerSide()`.

### 1.11.2
- **Fix: preference center invisible on dark design presets** — all 5 presets now include full `preferenceCenter`, `categoryPreview` and `optoutPopup` color palettes (background, text, buttons, toggle states). Previously only the banner bar was styled.
- **Fix: TypeError crash on ChromeOS / PMP-exempt members** — `_fazRenderBanner()` null guard prevents crash when the banner template element is absent (PMP-exempt members, empty cache).
- **Fix: `applyDesignPreset()` deep-replaces preference center and optout popup config** — the old cherry-pick missed toggle states and left stale values across preset switches.
- **Fix: `const _fazGsk = true;` → `var`** for broader browser compatibility in the WP Consent API inline script.
- **Fix: removed `#000000` → transparent skip in template CSS** — High Contrast preset buttons now render as black instead of falling back to the default blue.
- **Internal: `normalizeBannerConfig()`, law-specific sanitization defaults, 6 new E2E regression tests.**

### 1.11.1
- **Fix: banner reappears on every page load (critical)** — the `fazcookie-consent` cookie was written without URL-encoding, so on the next pageview the naive `document.cookie` splitter lost the `,` and `:` separators, `rev` couldn't be extracted, and `isConsentCookieStale()` wiped the cookie every time. URL-encode on write, two-pass decode on read. Reported by a live publisher running 1.11.0 in production.
- **Fix: PMP `exempt_levels` setting didn't persist (critical)** — `Settings::sanitize()` was coercing the CSV input `"2, 3"` to `[]` before `sanitize_option()` could parse it. Excluded keys are now dispatched to their per-key handler first. Without this fix the entire Paid Memberships Pro integration was silently non-functional.
- **Fix: Non-personalized ads fallback also forces `ad_user_data` and `ad_personalization` to `denied` in the region-default code path** (not just in `buildConsentState()`). Prevents the first-page `gtag("consent", "default", …)` from being more permissive than the post-"reject all" state.
- **Fix: PMP auto-grant cookie wrote `consent:accepted` but `script.js::_fazUnblock()` gates on `consent:yes`** — exempt members had their scripts server-side-unblocked but client-side-re-blocked. Aligned the token and pinned the literal in a regression assertion.
- **Fix: `setAdditionalConsent(null)` no longer fires during the stale-revision window in `fazcookie_consent_update`** — would otherwise clobber the live GACM provider list with an empty `"1~"`.
- **Fix: Settings page race condition** — if `loadSettings()`'s GET resolved after `invalidateConsents()` bumped `consent_revision`, the form silently reverted the counter. Added a monotonic `settingsRequestId` guard.
- **Fix: Cross-domain consent forwarding regex accepts base64** — old allowlist rejected `+`, `/`, `=`; forwarded consents from multi-domain setups were silently dropped.
- **Fix: `wca.js` and `microsoft-consent.js` requested `.min.js` that does not exist** — those scripts are not in the `build:min` pipeline but reused `$suffix` from `script.js`. On installs with `script.min.js`, WP Consent API and Microsoft UET/Clarity consent integration 404'd. Suffix is now computed per-file.
- **Fix: PMP auto-grant cookie filtered internal/admin categories** — `wordpress-internal` (wp-settings-*, wordpress_logged_in_*) and invisible categories are no longer declared in a visitor's consent record.
- **Fix: changelog wording on NPA fallback** — clarified that with `ad_storage = granted` advertising identifiers can still be written for frequency capping / fraud detection; what NPA removes is profiling and ad-user-data signals, not all cookies.
- **New: Czech (cs_CZ) translation** — 441 fully translated strings (frontend banner, categories, admin UI, shortcodes) contributed by Vaclav. Ships `languages/faz-cookie-manager-cs_CZ.po` and `.mo`.
- **Refactor: `faz_get_cookie_domain()` is now the single source of truth** — `Frontend::get_cookie_domain()` delegates to it; the public-suffix-aware TLD list is no longer duplicated between client-facing and server-facing code paths.

### 1.11.0
- **New: Non-personalized ads fallback for Google Consent Mode** — new setting in `GCM → Advanced`. When a visitor denies marketing consent, `ad_storage` stays `granted` while `ad_user_data` and `ad_personalization` are forced to `denied`. This is the Google-sanctioned configuration for serving non-personalized ads to visitors who reject the banner, so publishers still earn ad revenue on those pageviews. Important: with `ad_storage = granted`, advertising cookies and persistent identifiers may still be written and read on the visitor's device — what changes is that those identifiers are not used for building user profiles or personalizing creative (`ad_user_data`/`ad_personalization` being denied). Disabled by default to preserve previous behaviour. See [Google AdSense docs](https://support.google.com/adsense/answer/13554116).
- **New: Force re-consent (consent versioning)** — new `Settings → Force re-consent` card with an "Invalidate all consents" button. Clicking it bumps `faz_settings.general.consent_revision`; returning visitors whose stored cookie carries a lower revision see the banner again on their next visit. Useful after changing AdSense/GTM settings or adding new tracking services. The cookie format is backward-compatible: cookies from < 1.11.0 have no `rev` key and are NOT invalidated automatically on upgrade — only once the admin explicitly clicks the button.
- **New: Paid Memberships Pro integration (Pay-or-Accept / PUR model)** — new optional integration. When PMP is installed, a `Settings → Paid Memberships Pro integration` card becomes visible. Admin enters a comma-separated list of PMP level IDs; members of those levels bypass the cookie banner entirely and have consent auto-granted across all categories via a server-side cookie set on `init`. Non-paying visitors follow the standard consent flow. No-op when PMP is not active. Third-party code can override the exemption via the `faz_pmp_user_exempted` filter.
- **Fix: GCM race condition on revisit** — `gcm.js` now emits `gtag("consent", "default", …)` with the cookie-derived granted states directly for returning visitors, instead of `default denied → update granted`. This removes the brief window in which AdSense/GTM would fire the first ad request while consent was still `denied`. Fixes the user report where ads only loaded "after a couple of refreshes or a manual re-accept".
- **Fix: `wait_for_update` default incoherence** — admin UI showed `500` as the default, PHP defaults had `2000`. Aligned both to 500 ms (Google's recommended minimum).

### 1.10.2
- **Fix: preference center text colour on dark-theme host sites** (follow-up to [#57](https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/issues/57)) — the 1.10.1 fix that added a solid default background to `.faz-preference-center` exposed a pre-existing problem: several rules inside the preference center used `color: inherit`, which on sites with a dark theme (body text set to a light colour) inherited that light colour. The result was unreadable "light on white" text inside the now-white modal. Locked the text colour to `var(--faz-detail-color, #212121)` on `.faz-preference-center`, `.faz-preference`, `.faz-preference-header`, `.faz-footer-wrapper`, `.faz-preference-body-wrapper`, `.faz-accordion-wrapper` and the description paragraphs. The default is dark regardless of host theme, and users can still override the colour from the banner editor because the CSS variable is fed from the stored banner config.
- **Test: new E2E regression for the dark-theme scenario** — injects a dark-theme stylesheet on the host site (`html, body, .wp-site-blocks { background: #0f0f10 !important; color: #e6e6e6 !important }`), opens the preference center, and asserts every text-bearing element (`.faz-preference-center`, `.faz-preference-header`, `.faz-preference-title`, description paragraphs, accordion buttons) has a dark computed `color` instead of the injected light one.

### 1.10.1
- **Fix: preference center transparent background on classic template** — When the banner type is "full-width + pushdown" (which internally maps to the `classic` template), clicking the *Customize* button opened a preference center with no background colour. Root cause: `.faz-preference-center` used `background-color: inherit`, and the classic template wraps it in `.faz-preference-wrapper` (not `.faz-modal`), so there was no ancestor providing a colour — the modal ended up fully transparent. Replaced the `inherit` rule with `background-color: var(--faz-detail-background-color, #ffffff)` so the default is always a solid background, regardless of which template variant is active. Reported as [issue #57](https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/issues/57).
- **Test: E2E regression for issue #57** — switches the banner to `classic` + `pushdown`, opens the preference center on the frontend, and asserts the computed `background-color` of `.faz-preference-center` is not `rgba(0, 0, 0, 0)` / `transparent`. Canary for future regressions.

### 1.10.0
- **German (de_DE) translation** — ships `languages/faz-cookie-manager-de_DE.po` and `.mo` covering `[faz_cookie_policy]`, `[faz_cookie_table]`, cookie category names and common banner labels. Fixes the gooloo.de user report where the Cookie Policy shortcode stayed in English on a German-only site because the plugin had no `de_DE.mo` to load.
- **Admin JavaScript i18n infrastructure** — 128 localized keys exposed via `fazConfig.i18n.*`, organized in 8 namespaces (cookies, banner, settings, GCM, consent logs, languages, GVL, import/export, dashboard). Every admin page JS now uses a shared `__(key, fallback)` helper.
- **WordPress.org submission assets** — new `.wordpress-org/` directory with 10 publish-ready screenshots (banner, preference center, dashboard, banner editor, cookies, IAB TCF GVL, consent logs, GCM, languages, settings), a full `PUBLISHING-GUIDE.md` covering the submission checklist, SVN workflow, asset sizing and reviewer Q&A, plus a reproducible Playwright capture script at `scripts/capture-wporg-screenshots.mjs`.
- **New FAQ entries** in `readme.txt`: telemetry, minified JS source and data removal on uninstall — the three questions wp.org reviewers always ask.
- **`.distignore` / release ZIP hardening** — excludes `.wordpress-org/`, `assets/`, `composer.json`, `composer.lock`, `tsconfig.json`. Distribution ZIP shrunk from 7.0 MB to 5.6 MB (of which ~2.7 MB is the intentional bundled Open Cookie Database).
- **Fix: cookie definitions metadata normalization** — `Cookie_Definitions::get_meta()` now merges stored meta over a defaults array, so legacy installs upgrading from < 1.9 without the `source` field no longer send the UI down the wrong "downloaded vs. bundled" branch.
- **Fix: `META_KEY` autoload flag** — `update_option( self::META_KEY, …, false )` matches the `OPTION_KEY` pattern, keeping metadata out of the autoload bucket.
- **Fix: `importFailed` i18n string** — now contains the `%s` placeholder expected by `import-export.js`, so the actual error detail is surfaced instead of being silently swallowed by `String.replace('%s', …)`.
- **Fix: GVL admin page fully localized** — 8 previously hardcoded English strings in `admin/views/gvl.php` (heading, buttons, aria labels, placeholder, "All purposes", "Select all on this page", "Save Selection") are now wrapped with `esc_html_e` / `esc_attr_e`.
- **Fix: GVL REST API error message** — `'vendor_ids must be an array.'` is now translatable via `__()`.
- **Fix: JS i18n payload** — replaced 128 `esc_html__()` calls inside the `fazConfig.i18n` array with plain `__()`. HTML-escaped strings like `&quot;` were leaking into the UI because JS `.textContent` and `FAZ.notify()` don't interpret HTML entities.
- **Fix: fully localized `gvl.js` and `settings.js` templates** — "Saved N vendor(s)", "GVL updated vX (N vendors)" and "DB file (size) — Last updated: date" lines (previously mixed English fragments with localized strings).
- **Test: new E2E regression for the gooloo.de scenario** — sets `WPLANG=de_DE`, creates a page with `[faz_cookie_policy]`, asserts German strings render (`Was sind Cookies`, `Notwendige Cookies`, `Cookies verwalten`) and the English fallbacks do **not**. Canary for future regressions if anyone deletes `de_DE.mo` by mistake.
- **Test: E2E teardown hardening** — `pr-regression.spec.ts` WPLANG restore uses the shared `completeAdminLogin` helper and `WP_ADMIN_USER`/`WP_ADMIN_PASS` env variables instead of hardcoded `admin`/`admin`.
- **7 rounds of CodeRabbit review addressed**.

### 1.9.2
- **Fix: language settings controller** — settings API no longer re-injects the default language into the selected list on every read, fully fixing the "English always comes back" bug for non-English sites

### 1.9.1
- **Fix: default language uses site locale** — `faz_default_language()` now falls back to WPLANG (e.g. `de_DE` → `de`) instead of hardcoded `'en'`, so German/French/etc.-only sites work correctly without English being forced back
- **Fix: theme link color bleed** — added CSS reset (`color:inherit`) on `#faz-consent a,button` to prevent Divi, Elementor, and other page builder themes from overriding banner button colors

### 1.9.0
- **WCAG 2.2 accessibility** — new `a11y.js` with `role="dialog"`, `aria-modal`, `aria-labelledby`, heading hierarchy (`<h2>`/`<h3>`), `role="switch"` on toggles, dynamic checkbox labels, and Escape key support (contributed by Yard Digital Agency)
- **CSS custom properties** — all banner inline styles replaced with `--faz-*` CSS vars for CSP compatibility and easy theme customization (contributed by Yard Digital Agency)
- **Dutch language** — 573 fully translated strings (contributed by Yard Digital Agency)
- **Admin UI refresh** — modern design system, real-time iframe-based banner preview, design presets
- **Settings save fix** — `array_merge` no longer accumulates duplicates on repeated saves
- **Blocker templates auto-save** — clicking a template now persists rules immediately
- **Security hardening** — SSRF protection on scanner redirects, path traversal sanitization, CSS var name sanitization, ABSPATH guard on autoloader
- **Error handling** — banner API returns `WP_Error` on DB failures (create, update, delete, bulk)
- **Focus management** — preference center restores focus to trigger element on close (WCAG 2.4.3)
- **Performance** — a11y.js loaded in footer (non render-blocking), `.faz-accordion-heading` CSS normalized across all template types
- **10 rounds of CodeRabbit review** — 68+ findings addressed
- **155+ E2E tests** across admin, frontend, scanner, a11y, and blocking flows

### 1.8.0
- **WooCommerce-aware scanner** — auto-discovers shop, product, cart, checkout, my-account pages for comprehensive cookie detection
- **Scanner Debug Mode** — logs every categorization decision, downloadable from admin
- **OCD auto-download** — 7400+ cookie definitions downloaded on activation
- **"Remove all data on uninstall"** — opt-in setting (default OFF) prevents accidental data loss
- **Admin nav bar translated** — all labels now translatable via .po/.mo
- **Inferred cookies use site domain** — no more `googletagmanager.com` as cookie domain
- **Auto-categorize serialized** — no more 503 rate limiting on shared hosts
- **Server-scan always merges** — catches LiteSpeed/WP Rocket deferred scripts

### 1.7.2
- **Per-service cookie shredding** — denied services now have their cookies deleted even when the parent category is consented
- **Scanner 3-tier lookup** — integrates Open Cookie Database (1400+ entries) as fallback, drastically reducing "uncategorized" cookies
- **Blocker templates create cookies** — applying a template now adds cookies to the DB, not just blocking rules
- **French translation** — complete `fr_FR` locale with 579 translated strings (thanks @pascalminator)
- **Cookie_Database expanded** — 40 → 64 entries including `_GRECAPTCHA`, GA Classic, YouTube, Stripe, and more
- **i18n fixes** — scanner uses default language, backend preserves all translation keys, shortcode category names use `localize_category_name()`
- **18 new E2E tests** — comprehensive regression coverage for PRs #39, #41, #44
- **Scanner LiteSpeed/cache compatibility** — reads `data-src` and `data-litespeed-src`, server-side scan always merges, description enrichment from OCD
- **Cache flush after scan** — fixes empty cookie table after scan on sites with object cache

### 1.7.1
- **Admin performance** — 50-68% faster backend navigation (cache fix, N+1 query, REST preloading)
- **User-configurable whitelist** for scripts/network requests with 11 default API patterns (fixes #40)
- **Google Maps TypeError fix** — type guards on all DOM-facing blocking functions (fixes #35)
- **ClassicPress compatibility** — Gutenberg guard, `wp_date` → `date_i18n`
- **Banner type persistence** — fixed incorrect classic↔banner mapping in admin JS

### 1.7.0
- **26 new features** — scheduled scanning, consent stats, cookie policy shortcode, geo-IP banner, visual placeholders, multisite, Gutenberg blocks (3), design presets (5), bot detection, GTM data layer, WP privacy tools, dashboard widget, cross-domain consent, cookie deletion, age protection, anti-ad-blocker, per-service consent, import/export, AMP consent, content blocker templates (10), WP-CLI commands, system status, TranslatePress/Weglot compat, unmatched vendor notification
- **Category editor** — edit category names/descriptions from admin (fixes #38)
- **Custom CSS** — banner custom CSS now saves and renders (fixes #37)
- **Per-service consent** — individual service toggles override category consent
- **Security** — import sanitization, CodeQL DOM XSS resolved, AMP guards, per-service cookie shredding, transactions with ROLLBACK
- **34 new E2E tests** for all features + deep-flow coverage

### 1.6.1
- **Security hardening** — GCM settings sanitisation (whitelist keys, validate values), pageview endpoint HMAC token, scanner SSRF prevention (block private IPs), filter data sanitisation, CSS injection fix
- **Bug fixes** — switch fallthrough, null guards for CCPA/preference/readmore handlers, deprecated `event.which` → `event.key`, double DOM query fix, `.map()` → `.forEach()` cleanup

### 1.6.0
- **WooCommerce compatibility** — auto-whitelists WooCommerce core + payment gateway scripts on checkout/cart pages
- **Complete admin i18n** — all 387 admin UI strings wrapped in WordPress translation functions
- **Italian translation** — complete `it_IT` (386 strings) with formal register and GDPR terminology
- **Contextual help text** — `.faz-help` descriptions on all settings pages (fixes #27)
- **Do Not Sell text colour picker** — dedicated colour control for CCPA opt-out link (fixes #34)
- **Pageview tracking opt-in** — new toggle in Settings (default: off for stricter privacy defaults)
- **Customize overlay fix** — removed nonce from public REST endpoints; stale nonces on cached pages caused 403 (fixes #35)
- **Consent log integrity** — HMAC origin token prevents external spoofing
- **Subdomain cookie sharing** — fixed for `.co.uk`, `.com.au`, `.co.jp` and 30+ multi-level TLDs
- **PCRE fail-secure** — strips scripts on regex error instead of serving unblocked

### 1.5.2
- **Security & mixed-content fixes** — auto-repair cached banner template on HTTPS, sanitise inline CSS values, harden URL parsing
- **Plugin lifecycle E2E tests** — upgrade and fresh-install paths with full category verification

### 1.5.1
- **Link color fix** — link colour picker now applies to all visible links including Cookie Policy/Read More link
- **Brand logo 404** — moved `cookie.png` to `frontend/images/` with DB migration for existing installs

### 1.5.0
- **Link text colour picker** — new colour control in Banner Colours tab
- **E2E test suite for banner settings** — 21 Playwright tests covering all banner tabs

### 1.4.1
- **ClassicPress polyfill fix** — WP 4.9 inline script compatibility

### 1.4.0
- **5-layer script blocking** — WP hooks, content filters, output buffer, client-side interceptors, cookie shredding
- **Known Providers database** — 147+ services with 500+ URL/script patterns
- **Video/social embed placeholders** — YouTube, Vimeo, Facebook, Instagram, Twitter/X consent placeholders
- **Custom blocking rules** — admin UI for user-defined patterns per category
- **Network interception** — XHR, fetch, sendBeacon requests to blocked providers silently dropped

### 1.3.0
- **Incremental cookie scans** — only re-scans modified pages
- **Scan progress UI** — real-time progress bar with ETA
- `advertisement` category renamed to `marketing` across the entire plugin

### 1.2.0 – 1.2.1
- Dual-guardrail consent throttle, proxy header trust filter
- CSV export fix, consent log "rejected" status fix
- Security: prototype pollution guard, DOM XSS prevention
- Playwright E2E test suite (11 tests), Composer/Packagist support

### 1.1.0
- **IAB TCF v2.3** with Global Vendor List, vendor consent UI, TC String encoding
- **GVL Admin Page** — browse, search, filter 1,100+ IAB vendors
- Google Consent Mode v2, Microsoft UET/Clarity consent, local consent logging, cookie scanner

### 1.0.0
- Initial release — based on the GPL-licensed CookieYes v3.4.0 codebase, fully de-branded, cloud-free, and self-hosted

## Translations

FAZ Cookie Manager is fully translatable. All admin and frontend strings use WordPress i18n functions (`__()`, `_e()`, `esc_html__()`) with the `faz-cookie-manager` text domain.

**How to translate:**

1. Use the included `.pot` file at `languages/faz-cookie-manager.pot` as a template
2. Create a `.po` file for your language (e.g., `faz-cookie-manager-it_IT.po`) using [Poedit](https://poedit.net/) or any gettext editor
3. Compile it to `.mo` and place both files in the `languages/` folder
4. WordPress will automatically load the translation matching your site language

The banner content (title, description, button labels) is configured separately in the admin UI under **Banner → Content** and supports per-language customisation via the **Languages** module.

## Author

**Fabio D'Alessandro** -- [fabiodalez.it](https://fabiodalez.it/)

## Support the Project

If FAZ Cookie Manager is useful to you, consider buying me a coffee. Your support helps fund IAB CMP registration and continued development.

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-yellow?style=for-the-badge&logo=buy-me-a-coffee)](https://buymeacoffee.com/fabiodalez)

## License

GPL-3.0-or-later. See [LICENSE](LICENSE) for full text.

Cookie definitions powered by [Open Cookie Database](https://github.com/jkwakman/Open-Cookie-Database) (Apache-2.0).
