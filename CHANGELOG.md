# Changelog

All notable changes to FAZ Cookie Manager are documented in this file.

## [1.7.2] — 2026-03-24

### Fixed
- **Per-service cookie shredding** — `svc.hotjar:no + analytics:yes` now correctly deletes Hotjar cookies both server-side (PHP) and client-side (JS). Previously, `shred_non_consented_cookies()` returned early when no categories were blocked, skipping per-service logic.
- **Scanner auto-categorize uses default language** — no longer hardcodes `en` for descriptions; uses `getCategoryEditorLang()` and preserves existing translations via `Object.assign`.
- **Backend preserves all language keys** — `set_description()` and `set_duration()` no longer strip languages not in `faz_selected_languages()`. Translations survive language deselection.
- **Scanner 3-tier cookie lookup** — now uses Cookie_Database → Known_Providers → Open Cookie Database (1400+ entries) as fallback. Previously only tiers 1-2 were used, leaving most cookies as "uncategorized".
- **Blocker templates create cookies in DB** — clicking a template now also creates the template's cookies in the cookies table (not just blocking rules).
- **Cookie shredding domain handling** — strips port from `HTTP_HOST`, uses `get_cookie_domain()` for shared domain coverage.
- **`location.reload()` race** — per-service cookie cleanup returns flag to caller; single reload after cross-domain forwarding completes.
- **`is_string()` guard** on `wp_filter_post_kses()` in multilingual setters.
- **`normalize_multilingual_data()`** — tries JSON decode before treating string as monolingual.
- **Category name `__()` placement** — moved inside `localize_category_name()` so custom names pass through unchanged, stock names get po/mo translation.
- **Banner E2E tests** — `openVisitorPage()` now sets explicit `Accept-Language` header to match plugin default language.
- **Scanner bypass for cache plugins** — `?faz_scanning=1` disables banner/blocking for admin users during scan; sends `no-cache` headers and LiteSpeed bypass.
- **Scanner reads `data-src` / `data-litespeed-src`** — catches scripts deferred by LiteSpeed, WP Rocket, and Autoptimize that were previously invisible.
- **Server-side scan always runs** — merges with iframe results (deduped) to catch scripts the iframe missed; extracts URLs from `src`, `data-src`, and `data-litespeed-src` attributes.
- **Scanner description enrichment** — when Known Providers matches a category but has no description, the scanner now also queries the Open Cookie Database for description and duration.
- **Cache flush after scan** — `delete_cache()` now also flushes `wp_cache` group entries, fixing the "empty table after scan" bug where newly imported cookies were invisible until page reload.

### Added
- **French translation** (`fr_FR`) — 579 fully translated strings, contributed by @pascalminator (closes #43). Dynamic category names included as active .po entries.
- **Cookie_Database expanded** — 40 → 64 curated entries: `_GRECAPTCHA` (necessary), Google Analytics Classic, YouTube, Vimeo, Stripe, Bing UET, LinkedIn, Mixpanel, Twitter/X, Snapchat, Pinterest.
- **HubSpot category fixed** — reclassified from `analytics` to `marketing` across Cookie_Database and Known_Providers.
- **Blocker template cookies synced** — 7 templates updated to match Known_Providers cookie lists.
- **18 new E2E regression tests** — covering i18n save, whitelist, blocker templates, per-service shredding, scanner defLang, cookie table shortcode, category validation.

## [1.7.1] — 2026-03-21

### Performance
- **Admin backend 50-68% faster** — removed cache reset from page load, N+1 query fix on categories, deduplicated JS fetches, REST API preloading for all admin pages

### Added
- **User-configurable whitelist** for scripts and network requests — 11 default API patterns (YouTube, reCAPTCHA, Google Maps, Cloudflare Turnstile, etc.)
- Whitelist applies to all blocking paths: createElement, MutationObserver, fetch, XHR, sendBeacon

### Fixed
- **Google Maps / NitroPack TypeError** — `_fazShouldBlockProvider` and `_fazShouldChangeType` now validate input type before calling string methods (fixes #35)
- **Whitelist scope bug** — `_fazIsUserWhitelisted` was defined inside IIFE, unreachable from `_fazShouldChangeType` and MutationObserver (fixes #40)
- **Banner type persistence** — removed incorrect `banner+pushdown → classic` mapping in admin JS
- Migration version decoupled from `FAZ_VERSION` (dedicated constant, try/catch for safety)
- Timezone drift in dashboard widget cutoff (`date_i18n` replaces `wp_date`)
- `faz_migrations_version` added to uninstall cleanup
- Whitelist patterns sanitization: trim + filter empty strings to prevent universal match

### ClassicPress Compatibility
- Guard `register_block_type()` with `function_exists` check
- Replace `wp_date()` with `date_i18n()` (WP 2.1+ compatible)

## [1.7.0] — 2026-03-18

### Added
- **26 new features**: Scheduled scanning, consent statistics, cookie policy shortcode, geo-IP banner, visual placeholders, multisite, Gutenberg blocks (3), design presets (5), bot detection, GTM data layer, WP privacy tools, dashboard widget, cross-domain consent, 1st-party cookie deletion, age protection, anti-ad-blocker, per-service consent, import/export, AMP consent, content blocker templates (10), WP-CLI commands, system status page, TranslatePress/Weglot compatibility, unmatched IAB vendor notification
- **Category editor** — edit cookie category names and descriptions from admin (fixes #38)
- **Custom CSS** — banner custom CSS field now saves and renders on frontend (fixes #37)
- **30 E2E tests** for all new features + 4 deep-flow tests (import/export round-trip, WP-CLI, shortcode render, blocker templates)

### Fixed
- Per-service consent: `svc.id:no` now shreds cookies even when category is allowed
- AMP: guards in all 7 runtime entry points prevent classic JS runtime on AMP pages
- Import: transactions with ROLLBACK, cache invalidation, round-trip safe JSON encoding
- Consent stats: timezone-consistent queries, filter sync with dashboard
- Cross-domain consent: format + length validation, scheme check on iframe.src
- Rate limiter: validates event_type against allowlist
- Script unblocking: handles `data:` URIs + `data-fazcookie` attribute
- Banner text fallback: defaults from en.json only for absent keys, empty strings respected
- GVL: auto-detect vendors from Known Providers instead of selecting all 1400+

### Security
- Import handler: recursive sanitization of banner contents/settings (wp_kses_post)
- Placeholder HTML: wp_kses with iframe/script allowlist
- AMP consent: double-output prevention guards
- Blocks/shortcodes: CSP-friendly event delegation (no inline onclick)
- CodeQL: all 7 DOM XSS + 1 prototype pollution alerts resolved
- AJAX dismiss: nonce verification
- CF-IPCountry: behind trust filter
- Uninstall: per-table return-value checks with contextual logging

## [1.6.1] — 2026-03-17

### Security
- **GCM settings sanitisation** — whitelist allowed consent signal keys and validate `granted`/`denied` values; `regions` field now validated as ISO 3166 country codes
- **Pageview endpoint HMAC** — added origin token verification (same pattern as consent logger) to prevent external request spoofing
- **Scanner SSRF prevention** — `static_ip` setting now blocks private and reserved IP ranges (RFC 1918, loopback, link-local)
- **Filter data sanitisation** — recursive `wp_kses_post()` sanitisation before `apply_filters()` in the settings API
- **CSS injection fix** — replaced `insertAdjacentHTML` with `createElement` + `textContent` for dynamic style injection

### Fixed
- Switch fallthrough bug in frontend selector parser
- Duplicate guard removed in placeholder rendering
- Null guards added to prevent banner crash in CCPA opt-out, preference checkbox, and read-more shortcode handlers
- Deprecated `event.which` replaced with `event.key` for Tab key detection
- Double DOM query eliminated in RTL class application
- `.map()` replaced with `.forEach()` for side-effect-only iterations (7 instances)

## [1.6.0] — 2026-03-15

### Added
- **WooCommerce compatibility** — automatically whitelists WooCommerce core scripts and payment gateway scripts (PayPal, Stripe, Mollie, Square, Klarna, etc.) on checkout and cart pages; customisable via `faz_whitelisted_scripts` and `faz_woocommerce_pages` filters
- **Complete admin i18n** — all admin UI strings (banner, settings, languages, cookies, consent logs, GCM, dashboard pages) wrapped in WordPress i18n functions for full translation support
- **Italian translation** — complete `it_IT` translation (386 strings) with formal register and standard GDPR terminology
- **Contextual help text** — `.faz-help` descriptions added to all settings: Banner Control, Consent Logs, Scanner, Microsoft APIs, IAB TCF, Default Language, Pageview Tracking
- **Do Not Sell text colour picker** — dedicated colour control for the CCPA "Do Not Sell" link, visible when regulation is set to CCPA or Both (fixes #34)
- **Pageview tracking opt-in** — new toggle in Settings to enable/disable pre-consent pageview and banner interaction tracking (default: off for compliance)
- **E2E test for DNS colour** — Playwright test verifying Do Not Sell colour persistence and frontend reflection with exact RGB assertion

### Fixed
- **Customize overlay JS error** — removed nonce from public REST endpoints (pageviews, consent) that use `__return_true` permission; stale nonces in cached pages caused 403 errors (fixes #35)
- **Consent log spoofing** — added HMAC origin token (time-bucketed `wp_hash()`, 24h acceptance window) to the consent logging endpoint; requests without a valid token are rejected with 403
- **Subdomain cookie sharing on multi-level TLDs** — `get_cookie_domain()` now correctly handles `.co.uk`, `.com.au`, `.co.jp` and 30+ other public suffixes by taking 3 labels instead of 2
- **PCRE fail-secure fallback** — `preg_replace_callback()` null returns in content filter and oEmbed blocker now strip scripts/iframes entirely (was serving them unblocked); added `error_log()` diagnostics
- **Whitelist pattern hardening** — deduplicated patterns, added word-boundary awareness, sanitised `faz_whitelisted_scripts` filter output

### Security
- Pre-consent tracking gated behind explicit opt-in setting
- HMAC token verification on consent log endpoint prevents external spoofing
- Fail-secure PCRE fallback prevents consent bypass on regex errors
- Public suffix domain handling prevents cookie scope issues on ccTLDs

## [1.5.2] — 2026-03-12

### Fixed
- **Mixed-content banner URLs** — auto-repair cached banner template when site switches to HTTPS (reverse proxy, load balancer)
- **Banner inline style injection** — sanitise user-controlled CSS values with allowlist to prevent style injection
- **Frontend URL handling** — harden `script.js` URL parsing with strict protocol validation and `_fazIsAllowedScheme()` guard
- **Cookie scraper origin matching** — relax www/apex comparison and add async httponly fallback
- **Migration safety** — guard `$wpdb->update()` and `$wpdb->delete()` return values in category rename migration
- **Translation file fallback** — copy from bundled files instead of downloading from cloud, with error logging on failure
- **Plugin action link** — wrap `get_admin_url()` output with `esc_url()` for defense-in-depth

### Added
- **Plugin lifecycle E2E tests** — upgrade path (deactivate → reactivate) and fresh install (delete → reinstall) with full category and banner verification

## [1.5.1] — 2026-03-11

### Fixed
- **Link color not applying** — link colour picker now applies to all visible links including the Cookie Policy/Read More link (fixes #30)
- **Brand logo 404** — moved `cookie.png` to `frontend/images/` and added DB migration to fix stored URLs on existing installs
- **Removed unused asset** — deleted orphaned `poweredbtcky.svg`

## [1.5.0] — 2026-03-11

### Added
- **Link text colour picker** — new colour control in Banner → Colours tab for customising link colours in the consent notice (closes #26)
- **E2E test suite for banner settings** — 21 Playwright tests covering all banner tabs (content, colours, buttons, preference center, advanced)

### Fixed
- **TinyMCE re-render on tab switch** — limited to the activated tab's editor only
- **Output buffer null guard** — guard against null from `preg_replace_callback`
- **PCRE error logging** — log regex compilation errors instead of silent fallback
- **Accessibility** — added `aria-label` attributes to link colour picker inputs
- **Admin preview link selector** — aligned with frontend to include optout-popup links

## [1.4.1] — 2026-03-08

### Fixed
- **ClassicPress polyfill not loading** — WP 4.9 (ClassicPress base) does not output inline scripts for handles with no source URL; polyfill now prints directly in `admin_head` instead of relying on `wp_add_inline_script`

## [1.4.0] — 2026-03-08

### Added
- **ClassicPress compatibility layer** — wp.apiFetch polyfill with nonce middleware, fetchAll pagination, and media upload via FilePond fallback when wp.media is unavailable
- **5-layer script blocking** — WP hook filters (`script_loader_tag`, `style_loader_tag`), HTML content filters (`the_content`, `widget_text_content`), output buffer processing, client-side interceptors (createElement, XHR, fetch, sendBeacon), and cookie shredding
- **Known Providers database** — 147+ services with 500+ URL/script patterns for automatic categorization (Google Analytics, Meta Pixel, HubSpot, Hotjar, TikTok, LinkedIn, etc.)
- **Video embed placeholders** — YouTube/Vimeo iframes replaced with consent-required placeholder showing video thumbnail
- **Social embed blocking** — Facebook, Instagram, Twitter/X embeds blocked until consent
- **Iframe placeholder system** — Visual placeholder with consent button for blocked third-party iframes
- **Custom blocking rules** — Admin UI on Cookies page for user-defined script/iframe blocking patterns per category
- **Script dependency chains** — `data-faz-waitfor` attribute for scripts that depend on consent-blocked resources
- **Network request interception** — XHR, fetch, and sendBeacon requests to blocked providers silently dropped
- **Cookie shredding** — Automatic cleanup of cookies from revoked categories using Known Providers cookie map
- **Revocation page reload** — Forces page reload when a previously accepted category is revoked (executed JS cannot be unloaded)

### Changed
- Custom Rules UI moved from Settings to Cookies page
- WPForms and Ninja Forms CAPTCHA handles classified as `necessary` (was `functional`)
- jQuery whitelist narrowed to avoid false positives on third-party plugin handles

### Fixed
- TinyMCE content preserved across banner tab switches (issue #18) — serialize outgoing editor before panel hide, restore from stored data if empty
- Brand logo upload lock prevents concurrent uploads and duplicate attachments
- SRI/CSP-safe script clone attribute ordering — integrity/crossorigin/nonce set before src
- XHR instance reuse after blocked request — synthetic properties use `configurable: true` with cleanup on `open()`
- Non-executable script types (`application/ld+json`, `application/json`, `text/template`, `importmap`) never blocked
- GVL auto-select distinguishes "never set" from "user explicitly saved empty array"
- ReadMore link enabled in banner
- Close button functionality restored
- Uncategorized toggle behavior fixed

### Security
- URL scheme validation (`_fazIsAllowedScheme`) prevents `javascript:` / `data:` injection on restored iframe/image/stylesheet URLs
- Word-boundary-safe regex for `src`/`href` attribute renaming — prevents matching `data-src` / `data-href`
- Inline-safe URL handling for banner preview sinks
- Hardened admin URL handling and stale bar actions

## [1.3.0] — 2026-03-06

### Added
- **Incremental cookie scans** — only re-scans pages modified since the last run, using a content fingerprint (post count + latest modified date + taxonomy term slugs)
- **Page discovery from DB** — discovers scannable URLs from `wp_posts` and public taxonomy archives instead of sitemap-only
- **Settle watchdog** — preserves last valid iframe read on timeout instead of discarding
- **Scan metrics** — tracks pages scanned, cookies found, timing, and early-stop reasons
- **Scan progress UI** — real-time progress bar with page count, cookie count, and ETA

### Changed
- `advertisement` category renamed to `marketing` across all JSON files, DB slugs, display names, and GCM region settings
- Idempotent migration with completion flag — safe for repeated activations
- Handles edge case where both slugs exist (merges cookies, deletes old category)

### Fixed
- Boundary-aware provider hostname matching in script blocking
- CSS transient cache key includes `FAZ_VERSION` to prevent stale styles after upgrades
- TCF Special Features always return `false` per IAB spec (removed category-based derivation)
- Scanner iframe cookies use scanned page hostname instead of admin hostname
- Scanner fingerprint persisted only after successful import
- German category translation typo ("Werbekampagne nzu" → "Werbekampagnen zu")
- E2E TCF test updated to match v2.3 apiVersion

### Security
- Inline-safe URL handling for banner preview sinks
- Hardened admin URL handling and stale bar actions
- Avoid HTML style injection in banner helpers

## [1.2.1] — 2026-02-28

### Fixed
- CSV export no longer wraps data in JSON encoding — produces valid CSV files
- Consent log now correctly records "rejected" status when visitors click Reject All
- Consent logger skips page-load init events to prevent false "partial" entries for returning visitors

### Security
- Prototype pollution guard in deepSet utility function (CodeQL)
- DOM XSS prevention — logo URL validated to https only, privacy link href sanitized (CodeQL)
- CSV export type guard and anti-cache headers for privacy

### Added
- Composer/Packagist support — install via `composer require fabiodalez/faz-cookie-manager`

## [1.2.0] — 2026-02-24

### Security
- Proxy header trust filter (`faz_trust_proxy_headers`) — proxy headers only parsed when explicitly enabled
- Dual-guardrail consent throttle (per-IP + per-consent_id) to prevent flooding
- TTL normalization in rate limiter — prevents zero/negative TTL bypass

### Changed
- Necessary category toggle uses active blue color instead of gray
- "Always active" label positioned right-aligned next to toggle
- Removed orphan methods from deprecated languages API

### Added
- Playwright E2E test suite: 11 tests with proper fixtures and global setup

## [1.1.0] — 2026-02-15

### Added
- Google Consent Mode v2 integration
- IAB TCF v2.3 CMP stub
- Microsoft UET/Clarity consent API
- Local consent logging with CSV export
- Cookie scanner with Open Cookie Database integration
- GeoLite2 geolocation support
- 180+ language translations

## [1.0.0] — 2026-01-20

- Initial release — fork of CookieYes v3.4.0, fully de-branded, cloud-free, all premium features unlocked
