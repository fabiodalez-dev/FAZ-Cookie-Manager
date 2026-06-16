# FAZ Cookie Manager

**The only cookie consent plugin you need. 100% free, no required cloud account, no subscriptions.**

[![WordPress.org](https://img.shields.io/wordpress/plugin/v/faz-cookie-manager?label=wordpress.org&color=0073aa)](https://wordpress.org/plugins/faz-cookie-manager/)
[![Rating](https://img.shields.io/wordpress/plugin/rating/faz-cookie-manager?color=0073aa)](https://wordpress.org/plugins/faz-cookie-manager/reviews/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/faz-cookie-manager?color=0073aa)](https://wordpress.org/plugins/faz-cookie-manager/)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-blue)](LICENSE)
[![Try in Playground](https://img.shields.io/badge/Try%20in-Playground-3858e9)](https://playground.wordpress.net/?plugin=faz-cookie-manager)

Available on the [WordPress.org plugin directory](https://wordpress.org/plugins/faz-cookie-manager/) — install and update directly from your WordPress dashboard (**Plugins > Add New > search "FAZ Cookie Manager"**).

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
| Cookie Policy generator | Paid add-on | Yes | **Yes (NEW in 1.16.0)** |
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

### From the WordPress.org plugin directory (recommended)

1. In your WordPress dashboard go to **Plugins > Add New Plugin**
2. Search for **FAZ Cookie Manager**
3. Click **Install Now**, then **Activate**
4. Go to **FAZ Cookie** in the admin sidebar
5. Click **Scan Site** on the Cookies page to detect cookies
6. Customize banner design, text, and regulation type

Automatic updates are handled by WordPress — no manual steps needed.

### From GitHub (developers)

1. Download the latest release from [GitHub Releases](https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/releases)
2. Upload the `faz-cookie-manager` folder to `/wp-content/plugins/`
3. Activate in **WordPress admin > Plugins**

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

### Cookie Policy Generator (NEW in 1.16.0)

Generate a jurisdiction-aware Cookie Policy page directly from your admin — no copy-pasting templates from a privacy-lawyer blog, no paying $10/month for "policy creation" as a premium feature.

- **Jurisdiction-aware templates**: GDPR (EU/EEA/UK), CCPA/CPRA (California), LGPD (Brazil). Each shipped with its own template scaffold, legal references, and required sections for that framework.
- **Multilingual out of the box**: en, it, fr, de, es, pt-BR, bg. Override per render with `[faz_cookie_policy_complete lang="it"]` or let the visitor's browser language drive the choice. 21 scaffolds total (3 jurisdictions × 7 languages).
- **Auto-populated cookie inventory**: pulls live from `wp_faz_cookies`, so anything the scanner adds is reflected at the next render with its category, duration, and description.
- **Filled with your company data**: name, address, DPO email, third-party services, retention period. Configured once via the admin form, stored in `faz_cookie_policy_data`. Never seeded from `admin_email` or `blogname` (PII protection — operator must explicitly fill the form).
- **Non-removable disclaimer**: every generated policy ends with a footer making explicit that the templates are starting points, not legal advice. The disclaimer is hardcoded in the renderer, not in the template files, so admin section overrides cannot suppress it.
- **Versioning hash** for material-change detection: `data-faz-policy-version` attribute on the rendered article tracks drift across template + data changes. Display-only fields (`LAST_UPDATED_DATE`) are excluded so the hash doesn't drift on the calendar.
- **REST API** under `faz/v1/cookie-policy/*` (settings GET/POST, preview POST) — `manage_options` + nonce.
- **Live preview** from the admin form via a sandboxed iframe modal — iterate without persisting.
- **`faz_cookie_policy_data` filter** for site builders who want to inject custom placeholders before template substitution.
- **Backwards compatible**: the long-standing `[faz_cookie_policy]` shortcode (with `site_name` / `contact` / `show_table` attributes from 1.7.0) is unchanged. The standalone `[faz_cookie_table]` shortcode and matching `faz/cookie-table` Gutenberg block still work for embedding just the cookie inventory table on any page.

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
| `[faz_do_not_sell]` | CCPA "Do Not Sell My Personal Information" opt-out form |
| `[faz_dsar_form]` | GDPR Data Subject Access Request (DSAR) form |

**`[faz_cookie_table]` attributes:** `columns`, `category`, `heading`

#### `[faz_do_not_sell]` — CCPA Opt-Out

Renders a CCPA opt-out form for California residents. On submission:

- Logs the opt-out to the `wp_faz_consent_logs` table with `status = 'dnsmpi_optout'` and a hashed IP address
- Sets a `fazcookie-dnsmpi` cookie (365 days) so returning visitors see a confirmation instead of the form
- Sends a notification email to the site admin

If the visitor already has the opt-out cookie, the form is replaced with a confirmation message automatically.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `title` | `Do Not Sell My Personal Information` | Heading above the form |
| `button` | `Submit Opt-Out Request` | Submit button label |

```text
[faz_do_not_sell title="Opt Out" button="Submit Request"]
```

#### `[faz_dsar_form]` — GDPR DSAR Form

Renders a GDPR-compliant Data Subject Access Request form covering Articles 15–21. On submission:

- Stores the request as a private WordPress post (post type `faz_dsar`) so requests survive email delivery failures
- Sends a notification email to the admin with a direct link to the stored request (Reply-To set to the requester's address)
- Sends a confirmation email to the requester

Includes a honeypot field for bot protection and nonce verification.

Supported request types: Right of Access (Art. 15), Right to Erasure (Art. 17), Right to Data Portability (Art. 20), Right to Rectification (Art. 16), Right to Restrict Processing (Art. 18), Right to Object (Art. 21).

| Attribute | Default | Description |
|-----------|---------|-------------|
| `button` | `Send Request` | Submit button label |

```text
[faz_dsar_form button="Send Request"]
```

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

Only the most recent release is listed here. The complete history is in [CHANGELOG.md](CHANGELOG.md) (Keep-a-Changelog format) and on the [GitHub Releases page](https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/releases).

### 1.19.0 — 2026-06-16
- **Added**: per-service consent reintroduced and now actually enforced (server-side block + cookie shredder + client UI), opt-in by default and sourced from scanner-detected services; Czech (cs_CZ) cookie-policy templates; compliance hardening (Quebec/Law 25 routing, DNSMPI enforcement, DSAR, accessibility, scanner TLS, and new geo rulesets for MN/MD/NH/NJ/TX/Canada); CCPA opt-out success message with accessible countdown.
- **Fixed**: "Do Not Sell" always reaches a working opt-out (Classic-layout guard + runtime migration); banner template cache self-heals on update/toggle (no more stale markup served to new JS); blocked-embed placeholder keeps its branded styling; geo "source not configured" false-negative notice; cookie-policy controller labelled "data controller". New extension filters: `faz_per_service_services`, `faz_store_data`.

### 1.18.2 — 2026-06-13
- **Change**: the experimental opt-in features from 1.18.0 (per-service / per-cookie consent toggles and the `faz_geo_ruleset_runtime` runtime geo-routing) are temporarily disabled pending a correctness rework — an external review found they did not deliver the granular guarantees their UI implied when enabled. They are hard-off at their entry points; the default category-level consent flow (covered by the 113/113 compliance suite) is byte-for-byte unchanged. Catalogue-based multi-banner geo-routing (per-country banner selection) is unaffected.
- **Fix**: corrected an overstated per-cookie help text that claimed a denied cookie "is deleted whenever it appears" — that enforcement only ran client-side at save time and did not persist.

### 1.18.1 — 2026-06-13
- **Fix**: the Cookies admin *Scan Site* / *Auto-categorize* dropdown menus are no longer clipped by the card's `overflow: hidden` — the menu drops over the table below and shows every option.

### 1.18.0 — 2026-06-12
- **Feature**: geo-routing runtime (opt-in, `faz_geo_ruleset_runtime` filter) — the resolved per-jurisdiction ruleset drives the live banner (pre-consent default state, script blocking, Google Consent Mode v2 defaults and banner selection follow the visitor's jurisdiction: GDPR / CCPA-CPRA / Quebec Law 25 / POPIA / LGPD / …). Off by default; existing installs are unchanged until enabled.
- **Feature**: GeoLite2 edition choice (Country vs City) under Settings → GeoIP Database. Country (~10 MB) stays the default; City (~60 MB) adds province/state (ISO 3166-2) detection needed by sub-national rulesets such as Quebec's Law 25.
- **Feature**: granular per-cookie consent toggles (#135, opt-in, requires per-service consent) — a nested toggle for every cookie a service declares, so visitors can opt out of specific cookies within an accepted service. Stored as compact, override-only entries keyed by cookie name; a denied cookie is deleted whenever it appears.
- **Fix**: GeoLite2 database activation is validated and atomic — a corrupt or wrong-edition download is rejected instead of silently breaking lookups; the previous database is preserved on error and the edition preference is saved only after a successful download.
- **Translations**: all six bundled locales (it, fr, de, nl, hr, cs) completed and re-synced (1144 strings each).
- **Hardening**: per-cookie consent keys escape special characters in cookie names; runtime geo-routing custom saves honour per-category toggles and fail closed without a matching banner; the GeoLite2 edition setting is whitelisted on save.

### 1.17.2 — 2026-06-03
- **Feature**: new `[faz_cookie_settings]` shortcode — a *Manage consent preferences* button that re-opens the preference center on any page where the banner runtime is active (the equivalent of the common `[cookie_settings]` shortcode). It needs `script.js` + the preference-center template, so it stays inert on pages excluded from the banner. Optional `text` / `class` attributes; bound by a single delegated click handler in the banner script, so no inline JS. Styled to match the banner's primary button — it inherits the colours configured in Banner → Colours.
- **Feature**: Bulgarian (`bg`) added to the Cookie Policy generator as the 7th language — full gdpr-strict / ccpa-california / lgpd-brazil scaffolds, admin dropdown, display names, retention labels and disclaimer. `bg_BG` installs resolve to it automatically.
- **Fix**: `[faz_cookie_policy_complete lang="…"]` now strips curly / smart quotes the WordPress editor inserts (`lang=”it”`) before matching, so the language is honoured instead of silently falling back to English. `lang` / `jurisdiction` are sanitised to `[A-Za-z0-9_-]` (the underscore is kept so the locale form `lang="pt_BR"` still resolves to pt-BR).
- **Fix**: the generated "Last updated" date is localised to the policy's template language rather than the site locale (an Italian policy now shows "giugno", not "June"), with the right date format per language.
- **Fix**: LiteSpeed Cache compatibility — the anti-FOUC guard `<style>` and reveal markup carry `data-no-optimize` / `data-noptimize` so CSS Combine no longer hides the banner. Verified on live LiteSpeed Cache 7.8.
- **Feature**: per-element banner colour pickers (show-details link, category toggles) on Banner → Colours, applied to the modal as well as the inline banner.
- **Fix (cookie policy)**: the generated policy no longer prints its own `# Cookie Policy` H1 by default (the WordPress page already has a title — the second heading duplicated it); it still names itself in the intro prose, and `show_title="true"` restores the heading for a title-less embed. `<code>` inside the policy is also reset to a neutral, border-less, transparent token so a theme's global `code {}` styling doesn't bleed into the legal document.
- **Compliance (GPC)**: Global Privacy Control is now actually honoured. With the banner's *Respect GPC* toggle on and a browser asserting GPC, the plugin auto-applies a law-aware opt-out (reject non-necessary for GDPR-family laws; deny sale/sharing categories for CCPA), suppresses the banner, and records a `gpc` marker — the toggle used to be saved but never read. New CCPA banners ship with GPC on by default (CPPA Reg. §7025) and existing CCPA banners are migrated on upgrade.
- **Compliance (Google Consent Mode v2)**: the non-personalized-ads fallback no longer grants `ad_storage` after a reject — `ad_storage` / `ad_user_data` / `ad_personalization` stay denied and `npa = 1` is signalled, so Consent Mode v2 serves cookieless non-personalized ads (lawful in EEA/UK/CH, no geofencing).
- **Compliance (IAB TCF)**: the CMP no longer activates without a registered IAB Europe CMP ID (0/1 are reserved/invalid), so no TC string with an invalid CmpId is broadcast; PurposesLegitimateInterest encoding fixed (LI is default-established, cleared only by an explicit objection); the `__tcfapi` postMessage reply targets the caller's origin.
- **Compliance (pre-consent blocking)**: the default whitelist was narrowed to security/anti-abuse endpoints only (Google Fonts, Maps, the YouTube/Translation APIs, OAuth, jsDelivr, unpkg are no longer exempt by default); domain whitelist matching is host-anchored against look-alike spoofing; pre-consent pageview analytics are aggregate-only (the per-visitor `session_id`, which was never read back, was removed).
- **Compliance (EDPB 03/2022 dark patterns)**: equal-weight Accept/Reject buttons by default; the *uncategorized* bucket is no longer pre-ticked; scroll / navigation / idle no longer imply consent; the *GDPR Strict* preset drops green-accept / red-reject traffic-light colouring.
- **Compliance (Garante 2021)**: opt-in (GDPR-family) consent lifetime capped at 182 days regardless of the saved value; CCPA banners default to 365 days (CPRA's once-per-12-months re-prompt rule).
- **Compliance (CCPA/CPRA)**: `[faz_do_not_sell]` relabelled "Do Not Sell **or Share** My Personal Information"; consistent `sell_personal_data` default; plus a dedicated **`share_personal_data`** per-category flag (DB + REST + import/export + a "Sale / Sharing" toggle pair in the Cookies editor) so sharing for cross-context behavioural advertising is distinguishable from a sale — a category opt-out-able when sold OR shared.
- **Compliance (CCPA/CPRA — opt-out script loading)**: server-side script blocking is now law-aware. A CCPA banner is a NOTICE, not a gate — sale/sharing scripts run on first visit and are blocked only after the visitor opts out; GDPR/opt-in banners still block until consent. Verified end-to-end.
- **Compliance (Paid Memberships Pro)**: the pay-or-accept auto-grant is now a revocable default (a member's own preference-center choice survives subsequent page loads), is recorded in the consent log under a distinct `pmp_grant` status so the audit trail separates membership-basis grants from explicit consent, and cleans residual vendor/TCF state for ex-members via a `fazVendorSource=pmp` marker without risking a standard visitor's cookies.
- **Consent engine**: applicable-law routing is paradigm-based (opt-in vs opt-out) so LGPD and other opt-in regimes are handled as opt-in end-to-end and never misrouted.
- **Hardening**: fixed a wrong `function_exists()` guard that could leave the URL-path matcher undefined; a scalar `languages.selected` no longer fatals on PHP 8; bulk banner save + settings import are transaction-safe and an empty import set no longer wipes the categories/cookies tables; the policy generator no longer double-encodes entities; the unfiltered cookie query is bounded; the multisite network overview is cached; Microsoft UET/Clarity consent tolerates renamed categories.

### 1.17.1 — 2026-06-02
- **Fix**: empty cookie categories are no longer listed in the preference center or the revisit banner. A category with no cookies has nothing to consent to, yet the modal and revisit widget still showed every category — the empty-category removal only applied to the inline preview chip and was skipped in revisit mode. It now drops both the modal accordion item and the inline chip, in normal and revisit mode alike (Necessary is always shown). Consent recording is unaffected.

### 1.17.0 — 2026-05-31
- **Feature**: Auto-detect IAB TCF vendors and Cookie Policy third-party services from the cookie scan. New **Auto-detect from cookie scan** button on the GVL page and the Cookie Policy "Third-party services" tab pre-ticks the entries whose tracking domains the scanner actually observed (`SELECT DISTINCT domain FROM wp_faz_cookies WHERE discovered = 1`), matched against bundled `domain → vendor-id` / `domain → service-id` maps with a dot-prefix suffix guard. Read-only REST endpoints, `manage_options`-gated.
- **Feature**: Live WCAG colour-contrast checker on Banner → Colours. A non-blocking advisory flags any text/background pair below the AA 4.5:1 ratio, recomputed live as colours change. Never blocks saving.
- **Feature**: Redundant geo-routing cache-bypass warning. Detects a configuration that emits `Cache-Control: no-store` on every page for no benefit — geo-targeting on with `default_behavior = no_banner`, no target regions selected, and no banner with a target-countries list — and offers one-click "Disable Geo-routing" / dismiss (30-day transient). When target regions *are* selected the banner genuinely varies by country, so the no-store is justified and the notice stays hidden.
- **Accessibility (WCAG 2.2 AA)**: consent-saved `role="status"` `aria-live` announcement (SC 4.1.3); `prefers-reduced-motion` disables banner slide-in; decorative close-button image (`alt=""`) with the accessible name from the localized `aria-label`; server-rendered GVL auto-detect status region.
- **Security**: single-`SELECT` `scan_available` + id derivation (closes a TOCTOU window); `$wpdb->esc_like()` on `SHOW TABLES LIKE` probes; ReDoS-free literal-glob cookie-name matcher; `event.origin` allow-list on cross-domain consent forwarding; prototype-pollution-safe `deepGet`/`deepSet` dot-path helpers; CI-pinned `@wordpress/env` + Plugin Check.
- **Fix**: removed the redundant `({{COOKIE_POLICY_URL}})` parenthetical from the intro paragraph of all 18 Cookie Policy template scaffolds (the placeholder stays supported for `section_overrides`); CodeRabbit review threads on the feature resolved.
- **i18n**: regenerated `.pot` + bundled `.po`/`.mo` catalogs to the full 1109-string surface.
- **Tests**: new E2E specs for GVL / Cookie-Policy auto-detect (suffix-match guard, `discovered = 0` exclusion, allowlist pruning, `scan_available` semantics, admin round-trip) and `redundant-geo-routing-warning.spec.ts`.

### 1.16.2 — 2026-05-26
- **Fix**: Cookie Policy generator `[faz_cookie_policy_complete]` — round-two of Gooloo feedback on 1.16.x. `{{COOKIE_POLICY_URL}}` no longer leaks WP-preview query strings (`?preview_id=&preview_nonce=`); cookie inventory rendered as collapsible HTML5 accordion (`<details>/<summary>` + per-category `<table>`); footer disclaimer is admin-configurable (toggle + custom text) and wrapped in `<div>` instead of `<footer>`; empty placeholder lines (`**Register / USt-ID:**` etc.) suppressed when the corresponding field is blank.
- **Fix**: Translated GDPR scaffolds — dropped the non-standard `(DSB)` German DPO acronym + redundant `(DPO)` in Italian/Spanish/French/PT-BR; removed the `## Supervisory authority` block with the European Data Protection Board reference from all six GDPR templates (the existing GDPR Art. 77 "lodge a complaint with your national supervisory authority" line is sufficient).
- **Fix**: `Google Ads` and `Criteo` added to the third-party services allowlist (previously silently dropped on save). WordPress-internal cookies (`wp-settings-*`, `wordpress_logged_in_*`, the `wordpress-internal` admin category) excluded from the public policy — same filter already applied to the consent banner.
- **Tests**: new E2E spec `cookie-policy-1.16.2-regressions.spec.ts` — 11 tests, one per fix above plus a `getBoundingClientRect()` layout assertion on the accordion summary.

### 1.16.1 — 2026-05-25
- **Fix**: `[faz_cookie_policy_complete]` was rendering literal JSON like `{"en":"Functional"}` for category names and descriptions on multilingual installs. `Renderer::build_cookie_list_html()` now decodes the i18n JSON columns via a new private `decode_i18n_text()` helper (active language → `en` → first non-empty entry). Description fields flow through `wp_kses_post()` so the inline `<p>` they may contain survives. Audit of every other call site that reads `wp_faz_cookies` / `wp_faz_cookie_categories` confirmed no other leaks: controllers decode via `prepare_json()`, model getters via `normalize_multilingual_data()`, and WP-CLI / settings import/export decode explicitly. Reported by James on the wp.org support thread "Performance Impact???".

### 1.16.0 — 2026-05-20
- **Feature**: Cookie Policy Generator (spec 002). New admin tab "Cookie Policy" + new `[faz_cookie_policy_complete]` shortcode rendering a jurisdiction-aware, multi-language Cookie Policy from a template scaffold filled with the admin's company data (name, address, DPO, third-party services, retention). Covers GDPR (EU/EEA/UK), CCPA/CPRA (California) and LGPD (Brazil) in 6 languages (en, it, fr, de, es, pt-BR) — 18 scaffolds total.
- **Feature**: Auto-populated cookie list inside the rendered policy pulls live from `wp_faz_cookies` so additions via the scanner show up at the next render (5-min `wp_cache` TTL).
- **Feature**: New REST API `faz/v1/cookie-policy/*` (`/settings` GET/POST, `/preview` POST) — `manage_options` + nonce. Preview renders without persisting so the admin can iterate inside a sandboxed-iframe modal.
- **Feature**: Non-removable disclaimer at the bottom of every generated policy. Templates are starting points, not legal advice — `<footer class="faz-cookie-policy-disclaimer">` is hardcoded in the renderer, NOT in the template files, so section-overrides cannot suppress it.
- **Fix**: Frontend focus-trap listener accumulation (closes issue #124). `_fazAttachFocusLoop` now tracks attached keydown handlers per `(element, direction)` slot in a module-scope WeakMap and removes the previous listener before attaching a new one. Reopening the preference center repeatedly no longer stacks handlers.
- **Fix**: Plugin Check `wp_function_not_compatible_with_requires_wp` errors on `wp_cache_supports()` / `wp_cache_flush_group()` calls in `includes/class-base-controller.php::delete_cache()`. Removed the WP 6.1+ fast-path; manual `wp_cache_delete` loop replaces it. Plugin minimum stays at WP 5.0; the cache invalidation epoch bump above is what actually invalidates live reads.
- **Compatibility**: verified against **WordPress 7.0** (May 20, 2026 final release). No code changes required — plugin does not declare `add_theme_support('html5', …)`, does not use `the_author_meta`/`get_the_author_link` (title-attribute default change), does not bundle CodeMirror (Esprima → Espree swap), does not use the Interactivity API (`watch()` / server-side `state.url` changes), and all three Gutenberg blocks (`faz/cookie-table`, `faz/cookie-policy`, `faz/consent-button`) already declare `api_version: 3` (iframed-editor enforcement). PHP requirement already 7.4 (matches WP 7.0 floor). Plugin Check on WP 7.0: **0 errors**.
- **Compatibility**: the long-standing `[faz_cookie_policy]` shortcode (with `site_name` / `contact` / `show_table` attributes and the "How to Manage Cookies" section) is **unchanged and still supported**. The new generator is opt-in via the `[faz_cookie_policy_complete]` shortcode. The standalone `[faz_cookie_table]` shortcode and matching `faz/cookie-table` Gutenberg block also continue to work for embedding just the cookie inventory table.

### 1.15.0 — 2026-05-20
- **Feature**: Geo-routing v2 — 47 jurisdictional rule-set JSON files cover EU/UK + 19 US-state privacy laws + 18 international jurisdictions (LGPD/PIPL/APPI/PIPA/POPIA/PDPA/etc.) + most-protective fallback for unknown/VPN visitors. New `admin/modules/geo-routing/` module with REST API (`/faz/v1/geo/*`) and admin tab UI.
- **Feature**: VPN/proxy detection via ipinfo.io (opt-in, gated by admin DPF/SCC attestation). When VPN detected → most-protective ruleset forced. API key encrypted at rest via `wp_salt('auth')`.
- **Feature**: Field-by-field per-country admin override via dot-notation deltas (e.g. `signals.cmv2.ad_storage`). PIPL Art. 38-43 cross-border attestation UI (audit-trail only).
- **Migration**: `wp_faz_consent_logs` gains 7 NULL-default columns recording the geo/signal/TCF/GPP context at consent time. Online DDL on InnoDB 5.7.6+ / MariaDB 10.3+. Idempotent partial-failure recovery via `faz_geo_v2_migration_pending` option.
- **Compatibility**: 68 new unit tests, zero baseline regression. New filters: `faz_geo_rulesets_dir`, `faz_geo_admin_override_country`, `faz_geo_lookup_cache_ttl`. All new admin features are opt-in — installs upgrading from 1.14.x see no behavior change until they configure the geo-routing tab.

### 1.14.3 — 2026-05-19
- **Feature**: Multi-banner geo-routing (closes #103). New `target_countries` and `priority` schema columns on `wp_faz_banners` let admins serve different banners per visitor country — e.g. a Reject-mandatory GDPR banner to EU/EEA/UK and a CCPA-style banner with the close (X) button to US visitors. Routing is owned by `Controller::get_active_banner_for_country()` and reads from Cloudflare `CF-IPCountry` (opt-in) or the MaxMind / ip-api.com fallback chain.
- **Feature**: Per-banner close-button override (`settings.allowCloseButtonWithReject`), country-aware AMP `<amp-consent>` resolver, scope-change consent invalidation (`__scope.banner` / `__scope.law` keys), missing-banner admin notice with recovery CTA, country-dependent cache busting via `DONOTCACHEPAGE` / `Vary: CF-IPCountry` (with trust filter).
- **Filter**: new `faz_country_detection_consensus` (`$require_consensus`, `$votes`) — when `true` and ≥2 detection sources disagree, `detect_country()` returns empty (fail-open). Plugins that need the visitor IP should hook `faz_visitor_country`.
- **Fix (review hardening F101–F112, F301–F308, R4-S001–S004 + CodeRabbit#1/#2)**: transactional delete + update_item, InnoDB enforcement on faz_banners + faz_cookies + faz_cookie_categories with upgrade-path migration probe (with per-table partial-failure recovery), cache-poisoning races closed in promote_fallback_default and clear_default_on_others, multisite uninstall sweep gated on per-site opt-in and `FAZ_REMOVE_ALL_DATA`, banner_id pollution fix on REST POST, microsecond-precision cache epoch, empty-category preference-center render.

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
