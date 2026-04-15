# Changelog

All notable changes to FAZ Cookie Manager are documented in this file.

## [1.11.1] — 2026-04-15

This release ships **four critical fixes** on top of the 1.11.0 publisher-revenue work, plus a new Czech translation. **Upgrade strongly recommended** for anyone running 1.11.0 in production — two of the fixes were reported by a live publisher (gooloo.de) and affect every visitor's consent persistence.

### Fixed

- **Consent persistence on revisit (every reload shows the banner)** — the `fazcookie-consent` cookie was written without URL-encoding, so on the next pageview `document.cookie` served a string whose `,` and `:` separators were lost in the naive splitter. The client-side parser then produced an empty map, no `rev` was extracted, and `isConsentCookieStale()` treated the cookie as stale every time — wiping it and re-showing the banner. Fixed by URL-encoding on write (`_fazSetCookie`) and decoding with a second-pass parser on read. Cross-domain consent forwarding and the forwarded-consent regex were adjusted to accept base64 (`+`, `/`, `=`) characters in the consentid. Reported by nkoffiziell (gooloo.de).
- **PMP `exempt_levels` setting not persisting (critical)** — admins entering `"2, 3"` in the PMP card and clicking Save saw the field reset to empty on the next pageload. `Settings::sanitize()` was coercing every non-array value to `[]` BEFORE `sanitize_option('exempt_levels')` had a chance to parse the CSV string. Fixed by dispatching excluded keys (including `exempt_levels`) to their per-key handler first. Without this fix the entire Paid Memberships Pro integration was silently non-functional. Reported by nkoffiziell.
- **Non-personalized ads fallback: region defaults now force `ad_user_data = denied` and `ad_personalization = denied`** — when NPA was active and the region config forced `ad_storage` to `granted`, the other two signals still inherited whatever the stored region value said, so the initial GCM `consent default` could emit a more permissive state than the post-"reject all" state. Aligned the region-default emission with `buildConsentState()` so NPA's promise ("no profiling upstream") holds even before the visitor interacts with the banner.
- **PMP auto-grant cookie used the wrong consent token** — the cookie wrote `consent:accepted`, but `script.js::_fazUnblock()` and the CCPA opt-out checkbox both gate on `consent === "yes"`. The result: PMP-exempt members had their scripts server-side-unblocked but client-side-re-blocked, silently defeating the exemption. Fixed by writing `consent:yes`. A regression assertion pinned the exact literal so a future rename can't reintroduce the bug.
- **GCM consent-update listener: `setAdditionalConsent(null)` no longer fires during a stale-revision window** — when the admin bumps `consent_revision`, `parseConsentCookie()` transiently returns `null`; the old code would still call `setAdditionalConsent(null)` and clobber the live GACM provider list with `"1~"` (empty). Now skipped alongside `updateConsentState()`.
- **Settings page race condition** — if `loadSettings()`'s GET resolved AFTER `invalidateConsents()` bumped `consent_revision`, the form silently reverted the counter, and a subsequent Save would persist the stale revision. Added a monotonic `settingsRequestId` guard so late responses are discarded.
- **Cross-domain consent forwarding: regex now accepts base64** — the old allowlist (`[a-zA-Z0-9._:\-]+`) rejected `+`, `/`, `=` characters that legitimately appear in base64 consentids and forwarded TCF strings. Forwarded consents from multi-domain setups were being silently dropped.
- **Cross-domain consent forwarding: recipient now clears stale vendor/TCF cookies before applying the forwarded state** — the receiver overwrote only `fazcookie-consent`, so a recipient domain that had previously stored `fazVendorConsent` or `euconsent-v2` from a more permissive choice would resurface that state after the reload, producing a contradictory combination ("deny marketing" in the main cookie but TCF vendors still flagged as consented). Now explicitly deletes those two cookies before writing the forwarded consent.
- **`wca.js` and `microsoft-consent.js` requested `.min.js` that does not exist** — those two scripts are not part of the `build:min` pipeline, but `enqueue_scripts()` reused the `$suffix` computed for `script.js`. On any install where `script.min.js` existed, WordPress Consent API and Microsoft UET/Clarity consent integration 404'd. Fixed by computing the suffix per-file (falls back to the source when no minified file exists).
- **PMP auto-grant cookie included internal/admin categories** — `get_category_slugs()` returned every category from the DB, including the `wordpress-internal` bucket (wp-settings-*, wordpress_logged_in_*, wp_test_cookie) and invisible categories. These cookies are admin/auth only and must never appear in a visitor's consent record. Now filters with the same logic used by `Frontend::get_cookie_groups()`.
- **Changelog wording on NPA fallback was misleading** — the 1.11.0 entry claimed NPA provides "no profiling, no identifiers". With `ad_storage = granted`, Google can still read/write advertising identifiers for frequency capping and fraud detection. Rewritten to describe what actually changes (no profiling signals upstream) without overstating the privacy posture.

### Added

- **Czech (cs_CZ) translation** — 441 fully translated strings covering the frontend banner, cookie categories, admin UI, and `[faz_cookie_policy]` / `[faz_cookie_table]` shortcodes. Ships as `languages/faz-cookie-manager-cs_CZ.po` and `.mo`. Contributed by Vaclav.
- **readme.txt Upgrade Notice for 1.11.0** — highlights the consent-behavior changes (consent versioning, NPA fallback, PMP integration) at the WordPress.org upgrade prompt, so admins see the important items before upgrading.

### Refactored

- **`faz_get_cookie_domain()` is now the single source of truth for cookie scope** — `Frontend::get_cookie_domain()` is a thin wrapper that delegates to the global helper. The public-suffix-aware TLD list (30+ entries for `.co.uk`, `.com.au`, `.co.jp`, …) previously lived in two places; any future tweak would have had to land in both or client-side writes and server-side writes would have silently disagreed on scope. The `faz_cookie_domain` filter is still applied exactly once.

### Contributors

- **Vaclav** — Czech (cs_CZ) translation
- **nkoffiziell (gooloo.de)** — production bug reports that drove three of the fixes above

## [1.11.0] — 2026-04-14

### Added
- **Non-personalized ads fallback for Google Consent Mode** — new setting in `GCM → Advanced` that, when a visitor denies marketing consent, keeps `ad_storage = granted` while forcing `ad_user_data` and `ad_personalization` to `denied`. This is the configuration Google AdSense requires to serve *non-personalized* ads: no profiling and no user-data signals upstream, but note that with `ad_storage = granted` advertising cookies and persistent identifiers can still be read and written by Google to support frequency capping and fraud detection. Publishers still earn revenue on those pageviews. Disabled by default to preserve the previous behavior; admins enable it explicitly. See [Google AdSense docs](https://support.google.com/adsense/answer/13554116). Previously all three signals were tied to the same `marketing` flag, which left AdSense with `ad_storage = denied` and therefore unable to serve any ad.
- **Force re-consent (consent versioning)** — new `Settings → Force re-consent` card with an "Invalidate all consents" button. Clicking it bumps `faz_settings.general.consent_revision` on the server. The frontend stores the current revision in the `fazcookie-consent` cookie as `rev:N`; when the server revision is higher than the one stored in the cookie, the visitor is treated as having no consent and the banner reappears on their next pageview. Useful after changing AdSense/GTM settings or adding new tracking services — the user report was literally "I changed AdSense settings, now ads only load after a manual re-consent." Existing cookies from versions < 1.11.0 have no `rev` key and are NOT invalidated automatically on upgrade — they are only invalidated once the admin explicitly clicks the button.
- **Paid Memberships Pro integration (Pay-or-Accept / PUR model)** — new `Settings → Paid Memberships Pro integration` card (visible only when PMP is installed). Admin configures a comma-separated list of PMP level IDs; members of those levels bypass the cookie banner entirely and have consent auto-granted across all categories. The consent cookie is set server-side on `init` via a new `FazCookie\Includes\Integrations\Paid_Memberships_Pro` class. The integration is no-op when PMP is not active. Auto-granted cookies include the current `consent_revision`, so the force-reconsent button still invalidates them correctly. Third-party code can override the exemption via the `faz_pmp_user_exempted` filter.
- **Czech (cs_CZ) translation** — 441 fully translated strings covering the banner, cookie categories, admin UI and `[faz_cookie_policy]` / `[faz_cookie_table]` shortcodes. Contributed by Vaclav. Ships as `languages/faz-cookie-manager-cs_CZ.po` and `.mo`.

### Fixed
- **GCM race condition on revisit** — for returning visitors whose consent cookie already exists, `gcm.js` now emits `gtag("consent", "default", ...)` with the final granted states parsed from the cookie, instead of the previous sequence `default denied → update granted`. This removes the transient window in which ad tags (AdSense, GTM) could fire their first request while consent was still `denied` because the update hadn't arrived yet. Fixes the user report "ads don't load on revisit, only after a couple of refreshes or a manual re-accept."
- **Default `wait_for_update` incoherence** — the admin UI showed `value="500"` (5 hundred ms) as the default, but the PHP defaults had `2000`. New installations got 2000 ms (safer but slower), admins who saved the page once got 500 ms. Aligned both to 500 ms, which matches the default in the admin UI and is Google's recommended minimum.

### Internal
- New `includes/integrations/` directory for third-party plugin integrations (classes autoloaded as `FazCookie\Includes\Integrations\*`).
- `Settings::get_excludes()` now includes `exempt_levels` so PMP level IDs round-trip through save/load without being dropped.
- `Settings::sanitize_option( 'consent_revision' )` bounded to `[1, 999999]`; `'exempt_levels'` accepts both arrays and comma-separated strings from the UI.

## [1.10.2] — 2026-04-10

### Fixed
- **Preference center text colour on dark-theme host sites** (follow-up to [#57](https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/issues/57)). The 1.10.1 fix added a solid default background to `.faz-preference-center`, which resolved the transparent-modal bug on the classic template but exposed a pre-existing issue: several rules inside the preference center used `color: inherit`, which on sites with a dark theme (body text set to a light colour) inherited that light colour. The result was **unreadable "light on white" text** inside the now-white modal — technically a different bug than #57, but introduced to the user experience by the same fix.

  Root cause: the template CSS had three inheritance-chain rules that all walked up to the host `<body>`:

  - `.faz-preference-center, .faz-preference, .faz-preference-body-wrapper, .faz-accordion-wrapper { color: inherit }`
  - `.faz-preference-body-wrapper .faz-preference-content-wrapper p { color: inherit }`
  - `.faz-preference-center, .faz-preference, .faz-preference-header, .faz-footer-wrapper { background-color: var(--faz-detail-background-color, #ffffff) }` (no matching `color` lock — only backgrounds)

  Fix: every `color: inherit` on preference-center elements was replaced with `color: var(--faz-detail-color, #212121)`, and the combined background+colour rule now sets both properties at once. The default is dark regardless of host theme, and users can still override the colour from the banner editor because the CSS variable is fed from the stored banner config.

### Testing
- **New E2E regression** (`pr-regression.spec.ts` — "dark-theme host site: preference center text stays dark (follow-up to #57)"). Injects `html, body, .wp-site-blocks { background: #0f0f10 !important; color: #e6e6e6 !important }` on the frontend after page load, opens the preference center, and asserts the computed `color` of `.faz-preference-center`, `.faz-preference-header`, `.faz-preference-title`, description paragraphs and accordion buttons is NOT `rgb(230, 230, 230)` (the injected light theme colour). Canary for future regressions.
- **Existing #57 test hardened** — the classic+pushdown background test now tolerates both DOM shapes (`.faz-modal` wrapper on box/banner templates, direct `.faz-preference-center` on classic) so that what's asserted is the user-visible "modal has a visible background" condition, not the exact CSS class that carries it.

## [1.10.1] — 2026-04-10

### Fixed
- **Preference center transparent background on classic template** ([#57](https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/issues/57)) — When the banner type is *full-width + pushdown* (internally mapped to the `classic` template), clicking the *Customize* button opened a preference center with no visible background colour.

  Root cause: the DOM of the classic template is

  ```
  .faz-consent-container
    .faz-consent-bar
    .faz-preference-wrapper   ← no background-color, just position + animation
      .faz-preference-center  ← the visible modal content
  ```

  and the CSS rule for `.faz-preference-center, .faz-preference, .faz-preference-header, .faz-footer-wrapper` was `background-color: inherit`. Box/banner templates wrap the same `.faz-preference-center` inside a `.faz-modal` that carries `background: var(--faz-detail-background-color, #ffffff)`, so `inherit` resolved to white there. Classic has no `.faz-modal`, so `inherit` walked up the tree, found no colour, and ended up transparent.

  Fix: replace the `inherit` rule with `background-color: var(--faz-detail-background-color, #ffffff)` in both template versions (6.0.0 and 6.2.0). This gives the preference center its own default, independent of the parent chain, while still letting users override the colour via the banner editor — the CSS variable is set from the stored config.

### Testing
- **New E2E regression for issue #57** (`pr-regression.spec.ts` — "classic + pushdown: preference-center has a non-transparent default background"). Switches banner to `classic` + `pushdown`, opens the preference center on the frontend, verifies the DOM shape is classic (`.faz-preference-wrapper` present, `.faz-modal` absent) and asserts the computed `background-color` of `.faz-preference-center` is not in the set `{rgba(0, 0, 0, 0), transparent, ''}`. Restores the original banner settings in the `finally` block.

## [1.10.0] — 2026-04-10

### Added
- **German (de_DE) translation** — ships `languages/faz-cookie-manager-de_DE.po` and `.mo` covering `[faz_cookie_policy]`, `[faz_cookie_table]`, cookie category names and common banner labels. Fixes the gooloo.de user report where the Cookie Policy shortcode rendered in English on a German-only site because the plugin had no `de_DE.mo` for WordPress to load.
- **Admin JavaScript i18n infrastructure** — 128 localized keys exposed via `fazConfig.i18n.*`, organized in 8 namespaces (`cookies`, `banner`, `settings`, `gcm`, `consentLogs`, `languages`, `gvl`, `importExport`, `dashboard`). Every admin page JS now uses a shared `__(key, fallback)` helper so translators can localize admin messages without touching code.
- **WordPress.org submission assets** — new `.wordpress-org/` directory with:
  - 10 publish-ready screenshots (1280×960 @ 2x DPR): frontend banner, preference center, dashboard, banner editor, cookies, IAB TCF GVL, consent logs, GCM, languages, settings
  - `PUBLISHING-GUIDE.md` with the full submission checklist, SVN workflow (trunk/tags/assets), asset sizing spec, pre-submission validation and a Q&A block covering the standard wp.org reviewer questions
  - `README.md` orientation file for the `.wordpress-org/` folder
  - `scripts/capture-wporg-screenshots.mjs` — reproducible Playwright capture script that hides the admin bar, waits on REST hydration, and writes both numbered and ordered filenames
- **New FAQ entries in `readme.txt`** — telemetry, minified JS source and data removal on uninstall.
- **`.distignore` / release ZIP hardening** — excludes `.wordpress-org/`, `assets/`, `composer.json`, `composer.lock`, `tsconfig.json`. The distribution ZIP shrunk from 7.0 MB to 5.6 MB (of which ~2.7 MB is the intentional bundled Open Cookie Database).

### Fixed
- **Cookie definitions metadata normalization** — `Cookie_Definitions::get_meta()` now merges stored meta over a defaults array, so legacy installs upgrading from < 1.9 without the `source` field no longer send the UI down the wrong "downloaded vs. bundled" branch.
- **`META_KEY` autoload flag** — `update_option( self::META_KEY, …, false )` now matches the `OPTION_KEY` pattern, keeping metadata out of the autoload bucket.
- **`importFailed` i18n string** — now contains the `%s` placeholder that `admin/assets/js/pages/import-export.js` expects, so the underlying error message is actually surfaced instead of being silently swallowed by `String.replace('%s', …)`.
- **GVL admin page fully localized** — `admin/views/gvl.php` had 8 previously hardcoded English strings (heading, buttons, aria-labels, placeholder, "All purposes", "Select all on this page", "Save Selection") that are now wrapped with `esc_html_e` / `esc_attr_e`.
- **GVL REST API error message** — `'vendor_ids must be an array.'` is now translatable via `__()`.
- **`esc_html__` in JS i18n payload** — replaced 128 `esc_html__()` calls inside the `fazConfig.i18n` array with plain `__()`. HTML-escaped strings like `&quot;` were leaking into the UI because JS `.textContent` and `FAZ.notify()` do not interpret HTML entities.
- **Fully localized `gvl.js` and `settings.js` templates** — "Saved N vendor(s)", "GVL updated vX (N vendors)" and "DB file (size) — Last updated: date" lines (previously mixed English fragments with localized strings).

### Testing
- **New E2E regression for the gooloo.de scenario** (`pr-regression.spec.ts` — "gooloo.de regression: [faz_cookie_policy] on WPLANG=de_DE renders German strings"). Sets `WPLANG=de_DE` via the classic Settings → General form, creates a page with `[faz_cookie_policy]`, and asserts the five curated German phrases render while the English source strings do not leak. Acts as a canary for future regressions if anyone deletes `faz-cookie-manager-de_DE.mo` by mistake. The two pre-existing German tests only exercise the plugin's own language setting (`faz_settings.languages`) and never touch the WordPress gettext pipeline, so they would have passed even with the bug present.
- **E2E language-switch teardown hardening** — `pr-regression.spec.ts` teardown now uses the shared `completeAdminLogin` helper (exported from `wp-fixture.ts`) and `WP_ADMIN_USER` / `WP_ADMIN_PASS` env variables instead of hardcoded `admin`/`admin`. Prevents CI runs with custom credentials from contaminating subsequent tests when the WPLANG reset fails.

## [1.9.2] — 2026-04-09

### Fixed
- **Language settings controller** — The banner settings API `GET` handler was overwriting `languages.selected` from the database with the result of `faz_selected_languages()` on every read, which unconditionally re-injects the default language. This made it impossible to remove English from the selected languages list. The controller now reads `languages.selected` directly from `faz_settings` without modification.

## [1.9.1] — 2026-04-08

### Fixed
- **Default language** — `faz_default_language()` now falls back to the WordPress site language (`WPLANG`) instead of hardcoded `'en'`. Sites with `WPLANG = de_DE` will automatically use `de` as the default, allowing English to be removed from selected languages without it being re-added.
- **Theme link color bleed** — Added CSS reset (`color: inherit; background-color: transparent`) on `#faz-consent a, #faz-consent button` to prevent page builder themes (Divi, Elementor, Beaver Builder) from overriding banner button colors with their `a { color: ... }` rules.

## [1.9.0] — 2026-04-08

### Added
- **WCAG 2.2 accessibility** — new `a11y.js` module with `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, heading hierarchy (`<h2>`/`<h3>`), `role="switch"` on category toggles, dynamic checkbox aria-labels, Escape key on banner, and MutationObserver for `aria-controls` on show/hide buttons (contributed by Yard Digital Agency)
- **CSS custom properties** — all banner inline styles replaced with `--faz-*` CSS custom properties for CSP compatibility and easy theme customization via parent theme CSS (contributed by Yard Digital Agency)
- **Dutch language** — 573 fully translated strings for banner, categories, and admin (contributed by Yard Digital Agency)
- **Admin UI refresh** — modern design system with CSS custom properties, real-time iframe-based banner preview, design presets (Light Minimal, Dark Professional), TinyMCE integration
- **Live banner preview** — real-time iframe preview in admin banner editor showing actual site CSS with the banner overlaid
- **Focus management** — preference center saves and restores focus to the trigger element on close (WCAG 2.4.3)

### Fixed
- **Settings save** — replaced `array_merge` with `faz_merge_settings()` that correctly handles sequential arrays (fixes language duplicate accumulation on repeated saves)
- **Blocker templates** — clicking a template now auto-saves rules immediately (previously required manual "Save Rules" click)
- **`.faz-accordion-heading` CSS** — normalized across all 5 template types in both 6.0.0 and 6.2.0 (was only in 1 type, causing layout shifts)
- **`prepare_config()` null-safety** — all nested property access uses `??` fallback to prevent PHP warnings on banners with older schemas
- **`faz_audit_table()` return** — returns `''` instead of `null` when audit table is disabled
- **Category title listener scoping** — listeners scoped per slug instead of global querySelector
- **Age gate post-consent flow** — `btnYes` now runs full post-consent steps (banner removal, GCM signals, reload)
- **`toggleContainer` null guard** — prevents TypeError when DOM structure is unexpected

### Security
- **SSRF hardening** — `reject_unsafe_urls` set to `true` on scanner, sitemap sub-fetch disables redirects (`redirection => 0`), sitemap URL host validation
- **Path traversal** — `sanitize_file_name()` on admin view slug before `include()`
- **CSS variable sanitization** — `preg_replace` on `$tag` in CSS custom property names
- **ABSPATH guard** — added to `class-autoloader.php` for direct access prevention
- **Banner API** — `create_item`, `update_item`, `delete_item`, and `bulk` return `WP_Error` on DB failure instead of silent HTTP 200

### Performance
- **a11y.js in footer** — loaded with `in_footer: true` since it only runs after `fazcookie_banner_loaded`
- **Dead code removal** — removed unused `wp_localize_script('_fazStyles')` and `const _fazStyle`

### Contributors
- Wybe van den Bosch ([@WybeBosch](https://github.com/WybeBosch)) — CSS custom properties
- Yannic van Veen ([@Yannicvanveen](https://github.com/Yannicvanveen)) — Dutch translations
- Yvette Nikolov ([@YvetteNikolov](https://github.com/YvetteNikolov)) — WCAG 2.2 accessibility

## [1.8.0] — 2026-03-26

### Added
- **WooCommerce-aware scanner** — automatically discovers and prioritizes shop, product, cart, checkout, and my-account pages during scans. Catches payment SDKs (PayPal/Stripe), retargeting pixels, and reCAPTCHA that homepage-only scans miss.
- **Scanner Debug Mode** — comprehensive logging of every scan step and categorization decision. Toggle in Settings → Scanner, download logs from the Cookies page. Logs every Cookie_Database lookup, Known_Providers match, OCD fallback, and final category assignment.
- **OCD auto-download on activation** — the Open Cookie Database (7400+ definitions) is automatically scheduled for download when the plugin is activated, so the scanner has full cookie recognition from day one.
- **"Remove all data on uninstall" setting** — new toggle in Settings → Data Management (default: OFF). Prevents accidental data loss when users delete+reinstall to update.
- **Admin nav bar translation** — all navigation labels now use `__()` and are translatable via .po/.mo files. Italian translations added for Import/Export and System Status.
- **Server-side scan always merges** — runs after every iframe scan to catch `data-src`, `data-litespeed-src`, and deferred scripts invisible to iframes.
- **Priority URLs exempt from early stop** — WooCommerce pages are always scanned regardless of the early stop threshold.

### Fixed
- **Inferred cookies use site domain** — `lookup_scripts()` now uses the site domain instead of the script host domain (e.g., `cartaedilizia.it` instead of `googletagmanager.com`).
- **Auto-categorize serialized** — PUT requests sent one at a time to avoid 503 rate limiting on shared hosts.
- **Cache flush robustness** — `delete_cache()` cleans legacy `wp_cache` keys when `wp_cache_flush_group` is unavailable.
- **Logger try/finally** — all scanner entrypoints guarantee `finish()` is called even on exceptions.
- **Scanner bypass for cache plugins** — `?faz_scanning=1` sends no-cache headers and LiteSpeed bypass.
- **Categorization description enrichment** — OCD queried for description/duration even when Known Providers matches only the category.
- **`is_string()` guard** on `wp_kses_post()` in multilingual getters.

### Improved
- Iframe timeout increased from 6s to 15s for slow hosts
- Scanner concurrency reduced from 3 to 2 for better compatibility
- `get_posts` optimization flags for WooCommerce product query
- Auto-categorize scrape endpoint logs every lookup decision when debug mode is active

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

- Initial release — based on the GPL-licensed CookieYes v3.4.0 codebase, fully de-branded, cloud-free, and self-hosted
