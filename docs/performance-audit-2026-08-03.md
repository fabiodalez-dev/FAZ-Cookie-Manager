# Performance Audit — 2026-08-03

Full-plugin performance review covering four dimensions: database queries,
options autoload, script/CSS loading, and per-request hook overhead.
Issues marked **FIXED** were addressed in the accompanying commit; the rest
are documented recommendations, ordered by expected impact.

## Summary

The plugin is unusually disciplined in several areas — no synchronous HTTP on
any frontend path, the giant payloads (IAB GVL, Open Cookie Database, scanner
debug log) already stored with `autoload=false`, a clean one-`file_exists`
autoloader, consolidated one-check migrations, and extensive per-request
memoization in `Frontend`. The problems found cluster around: one large
autoloaded option, several per-request N× costs that memoization removes, a
handful of unbounded/uncached queries, and the sheer volume of render-blocking
head payload the banner pipeline emits.

---

## Fixed in this commit

### Options autoload

1. **`faz_banner_template` (+ per-language variants) autoloaded** — each entry
   stores the full rendered banner HTML+CSS (~30–45 KB per banner × language,
   never pruned). A 3-banner × 5-language install autoloads ~450–650 KB into
   memory on every request — admin, AJAX, REST, cron included — while any one
   request needs at most one entry.
   *Fix:* `autoload=false` at all three write sites
   (`admin/modules/banners/includes/class-template.php`,
   `frontend/class-frontend.php` ×2) plus a one-time migration
   (`Activator::demote_bulky_autoloaded_options()`) that demotes existing rows
   directly, since `update_option()` cannot flip the flag of an unchanged value.
2. **Admin-only options autoloaded** — `faz_scan_history`, `faz_scan_details`,
   `faz_scan_counter`, `faz_scan_max_pages`, `faz_admin_notices`,
   `faz_first_time_activated_plugin`, and the two migration flags that missed
   the `false` their siblings already pass. All write sites now pass
   `autoload=false`; the migration demotes existing rows.

### Per-request hook overhead

3. **`Gcm_Settings::get()` unmemoized** — 5× per request (wp_head defaults +
   store data), each call re-reading the option and re-running the recursive
   sanitize walk. *Fix:* static per-request memo, invalidated on `update()`
   and by the advert→marketing migration.
4. **`Geolocation::get_database_path()` unmemoized** — up to 4 candidates ×
   (`file_exists` + `is_readable` + 128 KB tail read) per call, reached 2–3×
   per request. *Fix:* static memo (+ `reset_runtime_cache()` for tests and
   the DB-download path).
5. **`Geolocation::get_country()` / `detect_region()` re-resolved per call** —
   the banner pipeline asks for the visitor country several times per request.
   *Fix:* per-IP static memo in front of the transient layer.
6. **`Activator::schedule_cleanup()` on every request** — 3×
   `wp_next_scheduled()` (which deserializes the whole cron option) + a
   settings read on every frontend hit. *Fix:* gated to admin / cron / WP-CLI
   requests; activation and admin traffic self-heal a wiped cron option.
7. **Scanner controller instantiated on every request** —
   `register_cron_hook()` called `get_instance()` eagerly just to attach two
   cron callbacks. *Fix:* lazy closures; the controller is only built when a
   scan event actually fires.
8. **`extract_tag_attr()` rebuilt its regex per tag×attribute** in the output
   buffer (~200 pattern-string builds per page). *Fix:* static pattern cache.
9. **2.8 MB `open-cookie-database.json` decoded to render an admin notice** —
   `get_bundled_meta()`/`has_definitions()` decoded the whole bundled snapshot
   (~150–300 ms + tens of MB peak) on every FAZ admin screen without a
   downloaded dataset. *Fix:* bundled metadata (count/date) cached in a small
   option keyed by the file's mtime+size (`faz_cookie_definitions_bundled_meta`).

### Database

10. **Consent-log CSV export unbounded** — `SELECT *` of the plugin's
    highest-volume table materialized in memory in one call; OOM/timeout risk
    on long-lived installs. *Fix:* keyset-paginated batches of 1000 on the
    `log_id` PK, streamed into the CSV buffer.
11. **Retention `DELETE`s unbounded** — the first cleanup after configuring
    retention deleted everything in one InnoDB transaction. *Fix:* batched
    `DELETE … LIMIT 1000` loops (consent logs + pageviews).
12. **`wp_faz_pageviews` grew forever** — `cleanup_old_records()` existed but
    was never called. *Fix:* wired into the `faz_daily_cleanup` cron
    (default 6 months, `faz_pageviews_retention_months` filter, 0 disables).
13. **Missing indexes** — `faz_consent_logs.banner_slug` (A/B stats
    `GROUP BY` was a full scan) and a `(event_type, created_at)` composite on
    `faz_pageviews` (every analytics query filters on both). *Fix:* added via
    dbDelta with schema-version bumps (1.1→1.2, 1.0→1.1).
14. **`[faz_cookie_table]` bypassed the cache layer** — two uncached
    full-table scans (+ full longtext hydration) per shortcode render on
    public pages, via direct `get_item_from_db()` calls. *Fix:* switched to
    `get_items()` (object-cache/transient-backed, correctly invalidated on
    writes).
15. **Scanner discovery loop flushed caches per inserted cookie** — each
    insert paid a transient-prefix rotation, a `wp_options LIKE` scan, ~100
    `wp_cache_delete()`s, and an unindexed full-table vendor re-check via
    `faz_after_create_cookie`. *Fix:* `Base_Controller` bulk mode
    (`suspend_cache_invalidation()`); the loop now invalidates once and fires
    the action once at the end.
16. **`has_country_dependent_banners()` hydrated a full `Banner` per row per
    request** — N controller round-trips + N `sanitize_settings` cascades on
    every request on hosts without a persistent object cache. *Fix:* classify
    against the raw `prepare_item()` rows (same F010 pattern already used by
    `get_active_banner_for_country()`).

### Assets

17. **Four frontend scripts shipped unminified and outside the build
    pipeline** — `faz-dsar.js` (8.6 KB→3.6 KB), `faz-dnsmpi.js`
    (5.7 KB→2.3 KB), `wca.js` (2.1 KB→0.8 KB), `microsoft-consent.js`
    (1.8 KB→0.8 KB). The first two load on *every* frontend page by design.
    *Fix:* added to `build:min`, committed `.min` builds, enqueues prefer the
    minified files (with `SCRIPT_DEBUG` fallback).
18. **`faz-cookie-policy` style handle registered twice with different
    `src`** (generator's real file vs. shortcode's `src=false` inline handle)
    — whichever registers second is silently dropped. *Fix:* shortcode now
    uses its own `faz-cookie-policy-inline` handle.

---

## Recommendations (not addressed here)

Ordered by expected impact. Items 1–3 are the big remaining wins; each is
compliance-sensitive and deserves its own change with dedicated tests.

1. **Output buffer provider matching is O(tags × 1,041 patterns)**
   (`frontend/class-frontend.php`, `match_script_to_provider()` /
   `process_output_buffer()`). Seven full-document regex passes; every
   `<script>`/`<iframe>` is scanned linearly against ~1,041 provider patterns
   plus ~96 whitelist patterns — an estimated 20–100 ms of CPU per uncached
   page view for non-fully-consented visitors. Recommended: index patterns by
   host bucket (or an Aho-Corasick pass over the URL/inline text), and merge
   the two `<noscript>` passes. **Not changed here**: a false negative means
   an unblocked tracker, so this rewrite needs its own test-backed PR.
2. **~60–100 KB of JSON inlined into every page's `<head>`** — `_fazConfig`
   carries `_providersToBlock` (~55 KB, 1,021 static pattern objects) and
   `_cookieCategoryMap` (~7 KB) rebuilt and re-transferred on every page view,
   plus `_iabVendors`/`_fazTcfConfig` when TCF is on. The provider list is
   static site-wide: it belongs in a versioned static `.js` file (far-future
   cacheable), with only the site-specific config inlined.
3. **~30 KB of banner CSS inlined per page on a `src=false` handle**
   (`get_boosted_css()`), which can never be browser-cached. Writing the
   compiled CSS to an uploads file (regenerated on banner save, epoch-keyed
   filename) would make it a one-time download.
4. **`Mmdb_Reader` loads the whole `.mmdb` via `file_get_contents()`** —
   6–9 MB for GeoLite2-Country, 60–90 MB for City (memory-limit fatal risk).
   The MMDB format is designed for `fseek`/`fread` traversal; switch to
   stream reads. Related: per-IP geo transients write 2–4 `wp_options` rows
   per unique visitor on installs without a persistent object cache — with a
   stream-reading MMDB the transient layer could be dropped entirely when the
   lookup source is local.
5. **Main banner bundle (121 KB) loads render-blocking in `<head>`** with
   `data-no-defer`/`data-no-optimize` hints and 12 optimizer-exclusion
   filters. Correct for consent semantics, but consider an opt-in
   `faz_script_in_footer` / defer filter for sites that accept late banner
   paint, and document the LCP trade-off.
6. **Alt-asset mode inlines the whole 121 KB bundle into the HTML on every
   request** (`file_get_contents` + `wp_add_inline_script`). Consider copying
   the bundle to a randomized uploads path once instead of inlining per
   request.
7. **`Known_Providers::get_all()` re-parses 90 KB of JSON per request** —
   compile to a PHP array file at build time (opcache then holds it) or cache
   in a `FAZ_VERSION`-keyed transient.
8. **`Base_Controller::data_exist()`** runs `SHOW TABLES LIKE` +
   `SELECT COUNT(*)` before cold reads, and `Cache::get_transient()` treats an
   empty array as a miss (legitimately-empty result sets are never cached).
9. **Consent-log admin list**: leading-wildcard `LIKE '%…%'` search on an
   unindexed `url` column, unconditional `COUNT(*)` per screen load, and
   `SELECT *` pulling longtext columns the table never renders.
10. **`faz_cookies.discovered` has no index** — full scans in the GVL
    unmatched-vendor check and the detected-names transient rebuild (both
    currently bounded/cached, so low urgency).
11. **Release ZIP ships the unminified 347 KB `frontend/js/script.js`**
    (`.distignore` doesn't exclude unminified frontend sources); admin assets
    (banner.js 128 KB, faz-admin.css 52 KB) are never minified.
12. **AMP requests build the full store data at `wp_enqueue_scripts` prio 1
    and dequeue it all at prio 999** — gate `enqueue_scripts()` on the AMP
    check instead.

## Verification

`bash scripts/run-unit-tests.sh` — 61/61 suites pass. The two geolocation
suites were updated to call `Geolocation::reset_runtime_cache()` where they
mutate database files mid-process, reflecting the new per-request memoization
(a database file cannot change mid-request in production).
