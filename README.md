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

### 1.13.18 — 2026-05-15
- **Fix**: `wp_localize_script` and `wp_set_script_translations` payloads (inline `<script id="*-js-extra">` and `<script id="*-js-translations">`) are no longer false-positively blocked when their body contains a substring that matches a provider pattern. These ID shapes carry only data/i18n strings, never executable tracker code — the prior content-substring matcher would crash third-party plugins whose config keys happened to mention a provider (e.g. trx_addons emits `animate_to_mc4wp_form_submitted`, which matched MailChimp's `mc4wp` and broke the page with `ReferenceError: TRX_ADDONS_STORAGE is not defined`).

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
