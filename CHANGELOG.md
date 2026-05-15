# Changelog

All notable changes to FAZ Cookie Manager are documented in this file.

## [1.13.18] — 2026-05-15

### Fixed

- **`wp_localize_script` and `wp_set_script_translations` payloads no longer trigger false-positive blocking** — inline `<script id="*-js-extra">` and `<script id="*-js-translations">` tags carry only data (config objects or i18n strings), never executable tracker code. The output-buffer / `wp_inline_script_tag` substring matcher would previously rewrite these tags to `type="text/plain"` whenever a config key or translated string incidentally contained a provider name (e.g. trx_addons emits `animate_to_mc4wp_form_submitted`, which substring-matched the `mc4wp` MailChimp pattern and crashed the page with `ReferenceError: TRX_ADDONS_STORAGE is not defined`). Both ID shapes are now exempt in `filter_inline_script_tag()` and the OB fallback `process_script_tag()`. `-js-before` and `-js-after` payloads (which DO accept executable code via `wp_add_inline_script()`) continue to route through the regular provider matcher.

### Tests

- 5 PHP-reflection regression tests in `tests/e2e/specs/v1-13-18-fixes.spec.ts` covering both call sites (filter + OB fallback), both ID suffixes (`-js-extra` and `-js-translations`), and the negative case (`-js-before` still blocked).

## [1.13.17] — 2026-05-15

### Fixed

- **`dataLayer is not defined`** when third-party trackers emit a bare `dataLayer.push()` before GTM bootstraps. Pre-initialised via `wp_add_inline_script('before')`. Closes wp.org thread *bug-report-datalayer-is-not-defined*.
- **Cookie category counts stay stale after scan + auto-categorise** — every cookie create/update/delete now invalidates the Category controller cache, the banner template, the IAB unmatched-vendors transient, and 10 page-cache adapters. Closes wp.org thread *bug-report-cookie-categories-not-populated*.
- **REST `bulk_update` silently dropping `opt_in_script` / `opt_out_script`** — now iterates schema editable fields through the same `sanitize_script_field` capability gate as single-cookie updates.
- **`_cookieScripts` no longer truncates at 500 cookies** — paged query, JSON-key-anchored LIKE, 10000-row ceiling.
- **`sanitize_meta_for_current_user`** intercepts every write path into `wp_faz_cookies.meta`. Closes a stored-XSS surface for multisite Site Administrators without `unfiltered_html`.
- **Own `wp_localize_script` payloads (`{handle}-js-extra`)** can no longer be classified as analytics by the output-buffer blocker. Closes #99 and #101 (reported independently by @Myblueroom).
- **WP Rocket "Load JavaScript deferred"** no longer wraps our `_fazConfig` bootstrap payload in a `DOMContentLoaded` callback (which would scope `var _fazConfig` to the callback and break `script.js` with `Cannot set properties of undefined`). New `rocket_defer_inline_exclusions` filter excludes `_fazConfig`, `_fazCfg`, `_fazGcm`, `_fazTcfConfig` from DeferJS wrapping. Closes #95 (thanks @dominikkucharski for the diagnosis and reference patch).
- **`<noscript>`-wrapped iframes** injected by page builders (Bricks/Elementor/Divi) no longer become 0x0 phantom placeholders.
- **Escape key no longer dismisses the consent banner** without a recorded decision (EDPB dark-pattern). Preference center close-on-Escape preserved.

### Added

- **`Necessary` selectable in Custom Blocking Rules dropdown.** Closes wp.org thread *feature-request-add-necessary-category-to-script-blocker*.
- **Banner-status toggle on the Cookie Banner admin page** mirroring Settings → Banner Control.
- **CCPA 1798.135(c) compliance** — `[faz_do_not_sell]` renders a Withdraw opt-out button + `dns_rescinded` log entry.
- **DSAR validation** announces errors via `role=alert`, `aria-invalid` per field, focus on first invalid. `.faz-dsar-btn` / `.faz-dnsmpi-btn` carry a contrasting focus indicator (WCAG 1.4.11). DNSMPI error notice switches to `role=alert` on failure.
- **`scripts/build-release.sh`** — scripted 3-way ZIP builder for wp.org / GitHub / ClassicPress Directory. Refs #20.

### Tests

- **Plugin lifecycle deep paths** — 6 new tests covering true fresh install, cross-version upgrade, `uninstall.php`, `run_pending_migrations` idempotence, Playground boot-order static analysis, and `svn-release.sh` smoke.
- **v1.13.17 regression suite** — 17 tests covering the 11 fixes above.

## [1.13.16] — 2026-05-05

### Fixed

- **Inline script false positive (Rank Math pattern)** — `match_script_to_provider()` and `check_per_service_blocking()` now distinguish URL-fragment patterns (containing `.` or `/`) from code-signature patterns. URL-fragment patterns are matched only against the `src` attribute, not against inline script content, preventing Rank Math config JSON (and similar) from triggering tracker-domain blocks.
- **`faz-skip` bypass matched as substring** — `stripos($class_attr, 'faz-skip')` would also match `faz-skipper` and similar classes. Fixed with whitespace-delimited token matching (`preg_split + in_array`).
- **CodeQL prototype pollution** — `setPath()` in the E2E test utility (`settings-options-matrix.spec.ts`) now guards against `__proto__`, `constructor`, and `prototype` path segments.
- **Brittle Clarity assertion** — `toContainEqual(['consent'])` upgraded to `toContainEqual(expect.arrayContaining(['consent']))` so extra call arguments do not break the assertion.
- **Playwright `locator.first()` called multiple times** — extracted to `const first` in `readUiValue()` to avoid redundant DOM queries.
- **Unpreflixed global variables in `uninstall.php`** — Plugin Check flagged `$force_remove_all`, `$offset`, `$batch`, `$site_ids`, `$site_id` as unprefixed. All renamed to `$faz_*`.
- **Mixed CRLF/LF line endings** in `admin/views/banner.php` — normalized to LF.

### Added

- **10 E2E tests** in `settings-options-matrix.spec.ts` and `settings-options-behavior.spec.ts` covering `faz-skip` bypass, per-service blocking, and settings matrix validation.

### CI

- **Plugin Check workflow** now supports manual runs via `workflow_dispatch` trigger.
- **Root-level PNG/JPG files excluded** from both ZIP variants — wildcard `*.png` / `*.jpg` patterns replace the previous per-filename exclusion, preventing dev screenshot PNGs from bloating the archive.

## [1.13.15] — 2026-05-04

### Fixed

- **TinyMCE rich-text editors restored** for "Notice Description" and "Preference Description" fields in the banner admin page. A previous refactor replaced `wp_editor()` with plain `<textarea>` elements, breaking WYSIWYG editing for banner text content.
- **REST DELETE category was a silent no-op.** `delete_item()` returned early because `get_loaded()` was false — the REST controller called `set_id()` but never `read()` the row before deleting. Now calls `get_data_from_db()` first; confirmed with a 404 GET assertion in the E2E suite.
- **REST PUT category wiped unspecified fields.** `prepare_item_for_database()` started from a blank object, so a name-only PATCH would overwrite slug and description with empty values. Fixed by loading the existing row before applying request fields.
- **Dynamic video placeholder text stayed hidden (non-YouTube providers).** `_fazAddPlaceholder()` called `_fazSetPlaceHolder()` only in the YouTube branch; Vimeo, Dailymotion and other providers returned early without removing `faz-hidden` from the placeholder title. Fix: call `_fazSetPlaceHolder(addedNode)` in both branches.
- **Duplicate MutationObserver click listeners.** Each `_fazAddPlaceholder()` invocation ran `document.querySelectorAll(…)` globally, accumulating N listeners on placeholder #1 after N iframes were injected. Fixed by passing `addedNode` as `container` to scope the query to the newly added node.
- **Cookie domain broken on IP-addressed sites.** `faz_get_cookie_domain()` attempted to compute a domain suffix for IP addresses (e.g. `127.0.0.1` → `.0.1`), causing `setcookie()` to silently discard the cookie. Per RFC 6265 §4.1.2.3, the `Domain` attribute must not be an IP address; fix detects IPs via `filter_var(FILTER_VALIDATE_IP)` and returns `''` (host-only cookie).
- **`$wpdb->delete()` missing `$where_format`** in `Cookie_Controller::delete_item()`. Plugin Check flags this as an escaping issue; fixed by passing `array('%d')` as the third argument.
- **`Tested up to` version corrected** from `6.9` (unreleased) to `6.8`.

### Added

- **9 E2E regression tests** in `tests/e2e/specs/pr92-tinymce-and-placeholder.spec.ts` covering all five areas: TinyMCE render, REST DELETE, video placeholder, IP cookie domain, and REST PUT partial update.

## [1.13.14] — 2026-05-02

### Fixed

- **Fatal error on WordPress Playground.** `maybe_create_table()` was invoked synchronously from the `ConsentLogs\Includes\Controller` constructor at plugin-load time. In Playground's WASM environment the WordPress bootstrap order differs from a standard installation: `pluggable.php` (which defines `wp_salt()`) is not yet in scope when plugins are loaded, so every visit to the FAZ admin page crashed with *"Call to undefined function wp_salt()"*. The `\` namespace fix from 1.13.13 was insufficient — the root cause was the *timing*, not just the prefix. Fix: constructor now hooks `maybe_create_table()` to `plugins_loaded` (priority 20) via `add_action`; `function_exists('wp_salt')` guard added around the migration query as a belt-and-suspenders safety net.

## [1.13.13] — 2026-05-02

### Fixed

- **Fatal error on fresh install in namespaced context.** `wp_salt()` was called without a leading `\` in `ConsentLogs\Includes\Controller`, causing PHP to resolve it as `FazCookie\Admin\Modules\Consentlogs\Includes\wp_salt()` — a function that does not exist. This crashed any fresh install (Playground, staging, first-time activations) at the point where `maybe_create_table()` runs the user-agent migration query. Three callsites fixed: migration `$wpdb->prepare()` call, `hash_ip()`, and `hash_user_agent()`.

### Added

- **WordPress Playground Live Preview** on the wp.org plugin page. Try the plugin in your browser without installing it, via the Preview button next to Download on wordpress.org/plugins/faz-cookie-manager.

## [1.13.12] — 2026-04-30

### Security / hardening

- **`consent_revision` cannot be lowered via DevTools manipulation.** `Settings::sanitize_one()` now reads the persisted revision from DB/cache and enforces `max($incoming, $persisted)` — a power user editing the readonly field via DevTools cannot submit a lower number and silently re-validate already-revoked consents. The Settings UI field now also carries `disabled` so it is excluded from form submission entirely on modern browsers.
- **`target_domains` URL validation.** Each entry in the cross-domain consent forwarding list is validated via `esc_url_raw()` + `wp_parse_url()` — only `http`/`https` scheme and non-empty host are accepted. `javascript:`, `data:`, and malformed strings are dropped at the REST sanitise layer.
- **`necessary` and `uncategorized` categories are protected from deletion.** `Category_Controller` now refuses DELETE requests for these two slugs at the controller layer, throwing a `RuntimeException` before the DB DELETE runs. Deleting either silently breaks scanner auto-categorisation and the necessary-toggle non-disableable invariant on the frontend.
- **Pageview tracking REST endpoint gated on the `pageview_tracking` setting.** If tracking is disabled the public POST endpoint is not registered at all — removing the attack surface for token harvest and per-IP throttle bypass attempts on installs that don't use dashboard analytics.
- **WP-CLI export path traversal hardening.** `wp faz export` now explicitly rejects paths containing null bytes and `..` segments (which `wp_normalize_path()` does not collapse). Already-existing parent directories are resolved via `realpath()` to catch symlinks pointing outside `wp_upload_dir()`.

### Fixed

- **`purge_page_caches()` wrapped in try/catch per plugin.** A single misbehaving cache plugin can no longer abort the upgrade flow midway and leave `faz_version` un-bumped. Each best-effort purge call is now isolated; failures are logged via `error_log` but do not propagate.
- **`faz_version` bumped LAST in `Activator::install()`.** Previously the flag was written before cache purges ran — a fatal in any step left it already bumped, so `check_version()` never re-entered `install()` and the failed migration was silently skipped forever. Now the flag is written only after all steps complete.
- **Excluded-pages and whitelist patterns now match on path only.** `fnmatch($pattern, $current_url)` was matching the full `REQUEST_URI` including query string and fragment, so `/privacy/*` never matched `/privacy/?utm=foo`. The request URI is now stripped to path-only before matching.
- **`faz_path_matches_pattern()` replaces bare `fnmatch()`.** The new helper adds (a) a portable fallback for Windows PHP builds where `fnmatch()` is absent (uses `preg_*`); (b) case-insensitive matching via `FNM_CASEFOLD` / `i` flag — admins typing `/Privacy/*` now match `/privacy/foo`.
- **WCA.js `performance` category mapped to `statistics` (was `functional`).** The WP Consent API mapping was incorrect — admins gating on `performance` expected analytics behaviour, not preferences. Also added `advertisement` → `marketing` back-compat for consent cookies stored before the 1.13.5 slug rename.
- **Croatian locale `hr` → `hr_HR`** in `class-i18n-helpers.php` locale table.
- **`alwaysActive` toggle now has a distinct blue colour** in gdpr.json and ccpa.json default configs. The "Always Active" badge for the Necessary category was visually indistinguishable from the inactive state.

### Added

- **"Share consent across subdomains" toggle** in Settings. When enabled, the consent cookie is scoped to the registrable domain (e.g. `.example.com`) so it is shared across `www`, `shop`, `app`, etc. Public-suffix-aware for multi-level TLDs. Recommended only when all subdomains are covered by the same privacy policy.
- **GitHub Actions: Plugin Check workflow** (`.github/workflows/plugin-check.yml`). Runs `wordpress/plugin-check-action@v1` on every push and PR to `main` using the wp.org-shape build — Plugin Check errors appear as annotations on the commit/PR, catching compliance drift in real time.

## [1.13.11] — 2026-04-29

### Removed (breaking for one feature)

- **Banner → Custom CSS field eliminated.** The textarea in the Banner editor that let admins paste arbitrary CSS into `meta.customCSS` is gone, the REST API preview no longer renders that field, and the public frontend no longer injects it into the document `<style>` block. Plugin Review Team flagged it as "arbitrary code insertion" — the wp.org compliance baseline does not permit plugins to ship a free-form CSS textarea even with sanitisation, since the same UX surface (with the same selectors) is provided by the WordPress core **Customizer → Additional CSS** screen. Existing `meta.customCSS` values stay in the `wp_faz_banners` table for downgrade safety but are inert in both the admin preview and on the live frontend. Migration path for users who relied on this: copy the saved CSS over to Customizer → Additional CSS and target `.faz-consent-container`, `.faz-modal`, `.faz-preference-wrapper`, etc. directly (no scoping changes needed — the banner injects those exact selectors).

### Security / hardening

- **`$_SERVER['HTTP_USER_AGENT']` access-line sanitised.** `includes/class-utils.php::faz_is_bot()` now does `sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )` at the assignment line; the `phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` annotation is gone. The UA value is then passed downstream to the public `apply_filters('faz_is_bot', $is_bot, $ua)` hook, so we want third-party `faz_is_bot` listeners to receive an already-cleaned string.
- **`FS_CHMOD_DIR` / `FS_CHMOD_FILE` global `define()`s removed.** `includes/class-filesystem.php` previously issued `define('FS_CHMOD_DIR', 0755)` and `define('FS_CHMOD_FILE', 0644)` (guarded with `! defined()`, but still global state). Plugin Review Team flagged this as "changes global behaviour". WordPress core falls back to those exact octal values internally when the constants are unset, so removing the `define()`s is behaviour-preserving on every host that doesn't override them, and now we no longer compete with site owners or other plugins that legitimately want to set different permissions.

### WP-CLI

- **`wp faz export <path>` is now scoped to `wp_upload_dir()`.** Default output directory is `wp_upload_dir()/faz-cookie-manager/exports/faz-settings-YYYY-MM-DD.json` (auto-created). Bare-filename arguments (e.g. `wp faz export my-backup.json`) are appended to that directory after `sanitize_file_name()`. Absolute-path arguments are accepted ONLY if they normalise inside `wp_upload_dir()`; otherwise the command rejects with `WP_CLI::error( 'Refusing to write outside wp_upload_dir() …' )`. Plugin Review Team flagged the prior unrestricted `file_put_contents( $args[0], $json )` as "saving data outside the plugin/uploads sandbox".

### Documentation / safety nets

- **`frontend/class-frontend.php::start_blocking_buffer()` `ob_start()` callback pattern documented.** A block-comment now explains why we don't pair the `ob_start( [ $this, 'process_output_buffer' ] )` with an explicit `ob_end_flush()` / `ob_get_clean()` in the same logical flow — that's the WordPress core `template_redirect → buffered final render` pattern, where PHP itself flushes the buffer at request shutdown and invokes our callback exactly once. Calling `ob_end_flush()` ourselves would either fire the callback prematurely or risk double-execution if a downstream cache plugin then opens/closes the buffer.
- A belt-and-braces `register_shutdown_function( [ $this, 'flush_output_buffer_on_shutdown' ] )` is now also registered. The shutdown handler verifies (via `ob_list_handlers()`) that our handler is still on top of the output-buffer stack before flushing — so we never close someone else's buffer if a third-party plugin pushed one above ours.

## [1.13.10] — 2026-04-29

### Fixed

- **Plugin Check ERROR `library_core_files` on `admin/assets/js/cp-api-fetch-polyfill.js`.** Plugin Check fingerprints the file as a structural re-implementation of `wp-includes/js/dist/api-fetch.js` (it is — by design — the polyfill recreates `createRootURLMiddleware`, `createNonceMiddleware`, `createPreloadingMiddleware`, `mediaUploadMiddleware`, `fetchAllMiddleware`, `apiFetch.use`, `apiFetch.setFetchHandler`). The polyfill is needed only on ClassicPress 1.x (forked from WP 4.9, whose `wp-api-fetch` lacks `createRootURLMiddleware` introduced in WP 5.x). On WordPress.org-distributed WordPress the native `wp-api-fetch` is loaded and the polyfill is never enqueued — pure dead weight. Resolved by **excluding the file from the wp.org-shape ZIP** (extends the dual-ZIP pattern already used for `admin/modules/scanner/run-scan.php`); the GitHub `-full` release ZIP keeps it for ClassicPress users. `class-admin.php::deregister_api_fetch()` now carries a `file_exists()` guard so the wp.org build is a graceful no-op when the polyfill file is absent — ClassicPress users on the wp.org ZIP get the native (WP 4.9-era) `wp-api-fetch` left in place; admin pages depending on `createRootURLMiddleware` degrade, the rest of the admin keeps working. ClassicPress users who need the full FAZ admin experience grab the GitHub `-full` ZIP.

### Build

- **`.distignore` realigned to `release.md::COMMON_EXCLUDES`.** Prior drift between `.distignore` and the actual `zip` build flow caused a number of dev artefacts (`.code-review-graph/`, `graphify-out/`, `.serena/`, `phpstan-bootstrap.php`, `report.md`, `CLAUDE.md`, `cookie-banner-compliance-checklist.md`, `biome.json`, `.gitattributes`, `.playwright-cli/`, `languages/*.po~`, `languages/messages.mo`) to potentially leak into wp.org submissions when the SVN deploy used the `wp dist-archive` flow (which reads `.distignore`) instead of the inline `zip` flow in `release.md`. Both flows now produce byte-equivalent ZIPs.
- **`release.md` updated** to document the second wp.org-only exclusion (the polyfill) alongside the existing `run-scan.php` exclusion. Sanity-check block extended to verify both files are absent from the wp.org variant and present in the GitHub `-full` variant.

## [1.13.9] — 2026-04-28

### Fixed

- **Plugin Check ERROR `WordPress.Security.EscapeOutput.OutputNotEscaped` on `admin/class-admin.php:462`.** The ClassicPress wp.apiFetch polyfill was emitted as `echo '<script>' . $polyfill . '</script>';` in `admin_head`. Even with a `phpcs:ignore` line the wp.org Plugin Check classifier still flags the line as ERROR (not warning) because the standalone Plugin Check scanner is stricter than vanilla PHPCS. Resolved by extracting the polyfill into a static file at `admin/assets/js/cp-api-fetch-polyfill.js` and registering it against the `wp-api-fetch` handle via `wp_register_script()` + `wp_enqueue_script()`. The REST URL and CSRF nonce the polyfill needs are passed in via `wp_localize_script('wp-api-fetch', 'fazApiFetchConfig', [...])`. Behaviour-equivalent: same polyfill, same load timing (head, before any consumer of `wp.apiFetch`), same ClassicPress-only gate. Side benefits: the file is now browser-cacheable and cache-plugin-aware. The legacy `print_api_fetch_polyfill()` method is kept as a documented no-op so any third-party callback list referencing it does not crash.

### Added

- **Automatic page-cache invalidation on every plugin upgrade.** Until 1.13.9, after a FAZ update site admins had to manually purge their page cache (LiteSpeed, WP Rocket, etc.) before visitors would receive the updated `_fazConfig` localize block in the rendered HTML — until then, cached pages kept embedding the previous version's data. Reported on gooloo.de (Bunny + LSCache) and on fabiodalez.it during the 1.13.7 → 1.13.8 deploy. `Activator::install()` now invokes `Activator::purge_page_caches()` right after `update_option('faz_version', FAZ_VERSION)`, so the purge is atomic with the version bump. Best-effort detection across:
  - LiteSpeed Cache (`do_action('litespeed_purge_all', ...)`)
  - WP Rocket (`rocket_clean_domain()`)
  - W3 Total Cache (`w3tc_flush_all()`)
  - WP Super Cache (`wp_cache_clear_cache()`)
  - Cache Enabler (`cache_enabler_clear_complete_cache` action)
  - SG Optimizer (`sg_cachepress_purge_cache()`)
  - Hummingbird (`wphb_clear_page_cache` action)
  - Breeze / Cloudways (`Breeze_PurgeCache::breeze_cache_flush()`)
  - Autoptimize (`autoptimizeCache::clearall()`)
  - WP-Optimize (`wpo_cache_flush()`)
  - Comet Cache (`Plugin::wipeCache()`)
  - Generic WP object cache (Memcached/Redis drop-ins via `wp_cache_flush()`)

  Each backend is detected via a stable public symbol (function/action/class) so the method silently no-ops when a given plugin is not active. **CDN edges (Cloudflare, Bunny, KeyCDN) are intentionally NOT touched** — those need API credentials the plugin does not own; admins running a CDN should still purge it manually after a FAZ upgrade. The hook chain runs only on `Activator::install()`, which fires when `check_version()` detects a `faz_version` option mismatch — i.e. exactly once per upgrade, never on every page load.

## [1.13.8] — 2026-04-28

### Fixed (issue #87 — Bricks Builder support, three follow-up cases)

- **Bricks Video element placeholder.** The iframe inside `<div class="brxe-video">` (which uses `aspect-ratio: 16/9` and `width: 100%` with no explicit width/height on the iframe itself) had `offsetWidth`/`Height` of 0 at MutationObserver time, and the original `_fazAddPlaceholder()` early-returned in that case — leaving the wrapper empty with no consent CTA.
  - `_fazMutationObserver` now descends into added subtrees with `querySelectorAll('script[src], iframe[src]')`, catching `<iframe>` nested inside page-builder wrappers (Bricks `.brxe-video`, Elementor `.elementor-video-wrapper`, Divi `.et_pb_video`).
  - `_fazAddPlaceholder()` always inserts the placeholder **synchronously** on the first call (before the calling site's `node.remove()` can detach the iframe). A 4-step sizing fallback chain — iframe metrics → ancestor walk (4 hops) → CSS floor (`min-height: 200px`, `aspect-ratio: 16/9`) → optional `requestAnimationFrame` remeasure — guarantees a visible CTA in every shape we have seen so far.
- **Bricks lightbox-link click interception.** Bricks Container with `tag=a` + Video Lightbox emits `<a class="bricks-lightbox" href="https://youtube.com/watch?v=…" data-pswp-video-url="…">`; the iframe is only injected into the PhotoSwipe modal AFTER the click. We now run a capture-phase document-level click handler that walks up to 6 ancestors looking for the lightbox-link signal (`data-pswp-video-url`, `data-elementor-lightbox-video`, Divi `.et_pb_lightbox_video`, plus generic `data-video-url`/`data-youtube`/`data-vimeo`). Either a strict Known_Providers pattern match OR a host-based fallback (youtube.com / youtu.be / vimeo.com / dailymotion / wistia / twitch — covering the WATCH-style URL the lightbox link actually carries, not just the EMBED form indexed by Known_Providers) is enough to gate the click. On block: `preventDefault()` + `stopImmediatePropagation()` BEFORE Bricks' own listener, the URL is copied onto `data-faz-src` for the existing unblock path, and the standard `.faz-placeholder` CTA is injected inline.
- **Banner suppression in WP admin / Bricks visual editor.** `faz_disable_banner()` in `includes/class-utils.php` now recognises the three Bricks 2.x editor signals (`$_GET['bricks']='run'`, `$_GET['bricks_preview']`, `$_GET['_bricksmode']`) plus the helper functions `bricks_is_builder()`, `bricks_is_builder_main()`, `bricks_is_builder_iframe()` when the theme is active. The banner pipeline (DOM render, `_fazConfig` localize, script blocker) fully shuts down on those routes, so the consent banner no longer paints over the Bricks editor canvas and blocks element clicks.

### Tests

- `tests/e2e/bricks-iframe-repro.mjs` — synthetic Bricks-shape iframe injection with `offsetWidth=0` at observer time. Asserts the placeholder is injected.
- `tests/e2e/bricks-real-post.mjs` — visits a WP post containing a real `<div class="brxe-video"><iframe src="youtube.com/embed/…"></iframe></div>` with **Bricks 2.3.4 active as the theme**. Asserts the placeholder is injected and the YouTube iframe is removed.
- `tests/e2e/bricks-lightbox-and-admin.mjs` — synthesises a `<a class="bricks-lightbox" data-pswp-video-url="…">` link, dispatches a click, asserts the page-builder listener never ran and a placeholder was injected. Plus a WP-CLI `wp eval` that calls `faz_disable_banner()` with `$_GET['bricks']='run'` and asserts it returns true.
- Full suite still 255 passed / 0 failed (29 minutes, Playwright 1.58.2).

## [1.13.7] — 2026-04-27

### Fixed

- **Issue #85 — GVL update fatal `Call to undefined function FazCookie\…\wp_tempnam()` on the REST endpoint.** `wp_tempnam()` lives in `wp-admin/includes/file.php`, which is *not* auto-loaded outside the admin context. The caller in `includes/class-gvl.php` is namespaced (`FazCookie\Includes`), so an unqualified `wp_tempnam()` resolved in the local namespace and fataled. Fix: lazy `require_once ABSPATH . 'wp-admin/includes/file.php'` + call the global name explicitly as `\wp_tempnam(...)`. Same fix shape applied to `download_url()` in `admin/modules/languages/includes/class-controller.php`.
- **Issue #87 — Bricks Builder Video element collapsed to zero height when its YouTube iframe was blocked pending consent.** The placeholder used `min-height: 0` on the video variant which fought the parent's `aspect-ratio` and the inner `<iframe>`'s `height: 100%`. Removed `min-height: 0` from `.faz-placeholder--video`; scoped the `min-width: min(280px, 100%)` floor only to the video variant — the responsive `min()` form prevents horizontal overflow on narrow viewports (CodeRabbit nitpick).
- **Gooloo regression — wpDiscuz comments "completely disfigured" on FAZ 1.13.6 + wpDiscuz 7.6.54 + LiteSpeed + Divi.** Real cause: the `Gravatar` entry in `includes/data/known-providers.json` mapped to category `functional`. Visitors who rejected "functional" had each `<img src="https://secure.gravatar.com/avatar/…">` (used by wpDiscuz, Disqus, JetPack Comments, and WordPress core) replaced with the 200-px-tall `.faz-placeholder` div, blowing up the comment thread. Gravatar avatars set no cookies, are loaded via a hash of the commenter's email (no cross-site tracking), and are part of the WordPress core comment UX — recategorise to `necessary` (Art. 5(3) ePrivacy *strictly necessary* for the user-requested service of posting a comment). Mirrored in `admin/modules/cookies/includes/blocker-templates/gravatar.json` for admin-UX coherence.
- **Defence in depth** for the bug above: added `wpdiscuz_nonce_*` and `comment_author_*` to the `is_wp_internal_cookie()` allowlist in `frontend/class-frontend.php`. The single `comment_author_` prefix already covers the three core variants (`comment_author_{HASH}`, `comment_author_email_{HASH}`, `comment_author_url_{HASH}`) since the matcher uses `0 === strpos(...)` — CodeRabbit nitpick: dropped the redundant `comment_author_email_` and `comment_author_url_` entries that were no-ops, with a comment documenting the implicit coverage.

### Changed (wp.org compliance pass)

Address the WordPress.org plugin directory AUTOPREREVIEW feedback ahead of submission. Each item is technically equivalent to the previous behaviour or strictly more compliant; no user-facing regressions.

- **`$_COOKIE` sanitization visible at access-line.** `faz-cookie-manager.php::faz_get_consent_cookie_value()` now wraps `wp_unslash()` with `sanitize_text_field()` on the same line as the `$_COOKIE` access. `rawurldecode()` runs on the already-sanitized payload, then `sanitize_text_field()` is re-applied as defence-in-depth.
- **`load_plugin_textdomain()` body removed.** WordPress 4.6+ auto-loads the textdomain. The method signature stays (a documented no-op) so the loader registration in `class-cli.php` still resolves.
- **4× `__( $variable, ... )` calls in cookie-table shortcode replaced with verbatim returns.** xgettext cannot extract from variables.
- **8 of 10 flagged inline `<script>` / `<style>` blocks migrated to `wp_enqueue_*` / `wp_add_inline_*`:**
  - `admin/views/system-status.php` style → appended to `admin/assets/css/faz-admin.css`.
  - `admin/views/system-status.php` script → new `admin/assets/js/pages/system-status.js`.
  - `admin/views/cookies.php` script → appended to existing `admin/assets/js/pages/cookies.js`. 6 strings moved into `fazConfig.i18n.cookies.*`.
  - `admin/views/languages.php` script → `wp_add_inline_script('faz-page-languages', ...)`.
  - `includes/class-cookie-table-shortcode.php` style → new `frontend/css/cookie-table-shortcode.css`.
- **3 residuals stay inline with `phpcs:ignore` + a written technical justification**: ClassicPress wp-api-fetch polyfill, `<script type="text/template">` inert HTML banner template, AMP `<amp-consent>` runtime, and the iframe-shell critical CSS in `banner-preview-frame.php`.
- **`_faz_first_time_install` site-transient renamed to `faz_first_time_install`** with migration in `Activator::check_for_upgrade()` and fallback read in `faz_first_time_install()`.
- **`permission_callback => __return_true` on three public REST routes** carries explanatory block-comments documenting HMAC origin tokens + per-IP rate limiting + strict `sanitize_callback`.
- **`readme.txt` "External Services"** gained a "Note on third-party domain strings" subsection clarifying that `js.stripe.com`, `connect.facebook.net`, `cdn.jsdelivr.net`, `unpkg.com` are *blocking-detection patterns* (config), not outbound HTTP calls.

## [1.13.6] — 2026-04-26

### Added
- **Blocker-template parity with the runtime detection layer.** The plugin's runtime script blocker (driven by `Known_Providers`) covers 143 third-party services out of the box — Google Analytics, Adobe Analytics, Plausible, Microsoft Clarity, Mixpanel, Segment, Stripe, Mailchimp, Klaviyo, HubSpot, Pinterest, Snapchat, Reddit, Quora, Outbrain, Taboola, Yandex Metrica, Baidu Analytics, OneTrust, and 124 more. Until 1.13.5 only 11 of those were exposed in the admin's "Add from template" picker, so an admin running, say, Plausible or Microsoft Clarity had no in-UI affordance to manage their rule even though the runtime was already blocking them silently. 1.13.6 closes the gap: every `Known_Providers` entry now ships a matching blocker-template JSON, auto-derived from the same source of truth (label, category, URL patterns, cookies). The picker grows from 11 to 142 (Matomo Tag Manager and Facebook are merged into the existing matomo / meta-pixel templates respectively to avoid duplicates).
- **Single source of truth.** The 131 new templates were generated programmatically from `includes/data/known-providers.json` so the admin picker can never drift from the runtime block list. If a future release adds a new provider to Known_Providers it should also drop a matching template under `admin/modules/cookies/includes/blocker-templates/`; the same generator script can be re-run.

### Notes
- The privacy contract is unchanged. Sites that didn't use any of these providers see no behaviour difference. Sites that did use them already had their cookies auto-categorised and their scripts auto-blocked — the new templates only make them visible in the admin so admins can review or override the rule. The `F21: blocker templates REST endpoint returns 10+ templates` regression continues to pass (now 142).

## [1.13.5] — 2026-04-26

### Added
- **Matomo (Piwik) blocker template.** A user on a fresh 1.13.4 install asked for Matomo to be selectable as an analytics tool in the blocker-templates picker. Matomo's tracker was already auto-detected by the script blocker (via `Known_Providers`) and its cookies were already auto-categorised as analytics (via `Known_Providers` + Open Cookie Database), so the privacy contract was never broken — but the template wasn't visible in the admin's "select an analytics tool to block" picker, which made the integration discovery-unfriendly. Added `admin/modules/cookies/includes/blocker-templates/matomo.json`. The new entry covers self-hosted Matomo and Matomo Cloud, including Matomo Tag Manager, the legacy Piwik names, the `matomo.php` / `piwik.php` tracking endpoint, and the full `_pk_*`, `MATOMO_SESSID`, `mtm_consent*` cookie family. The blocker templates list grows from 10 to 11 (`F21: blocker templates REST endpoint returns 10+ templates` continues to pass).

## [1.13.4] — 2026-04-26

### Fixed
- **`wp_localize_script` and translations payloads were left un-tagged on the page.** The 1.13.1/1.13.2/1.13.3 cache opt-out work hooked `script_loader_tag`, which only fires for enqueued `<script src>` blobs (and the before/after inline payloads concatenated with them). The localize payload (`{handle}-js-extra`, e.g. `faz-cookie-manager-a11y-js-extra`) and the translation payload (`{handle}-js-translations`) take a separate code path inside core: `WP_Scripts::print_extra_script()` → `wp_print_inline_script_tag()` → `wp_get_inline_script_tag()`, which applies its own filter — `wp_inline_script_attributes` — to the attributes array before serialising. Without a hook on that filter, those inline tags shipped without the 5 opt-out attrs and a delay-aware optimizer (LiteSpeed Guest Mode in particular) was re-typing them to `type="litespeed/javascript"`. Visible on `fabiodalez.it` for the `faz-cookie-manager-a11y-js-extra` payload during the 1.13.3 live smoke. The localized config is static data, not executable logic, so this never broke the banner — but it left a guarantee gap that the script-tag policy was supposed to close everywhere. Added the missing hook; the fix is additive (no impact on installs that aren't using LiteSpeed Guest Mode).

## [1.13.3] — 2026-04-26

### Fixed
- **Consent banner invisible on first paint when LiteSpeed Cache "Delay JS" had a hand-added entry referencing `faz-cookie-manager` without the full `wp-content/plugins/` prefix.** The 1.13.2 post-review fix (`litespeed_exclude_own_scripts_from_include`) made the scrubber strictly path-anchored to `plugins/faz-cookie-manager/` to stop it from collaterally dropping a third-party companion entry whose name happens to contain `faz-cookie-manager` as a substring. That fix was correct in spirit but too narrow in practice: site admins who had previously added a naked-handle entry like `faz-cookie-manager` or `faz-cookie-manager/frontend/js/script.min.js` (without the `wp-content/plugins/` prefix) to LiteSpeed's *Delay JS Include* list before installing the plugin were silently regressed — those entries were no longer scrubbed, LiteSpeed kept delaying the consent banner's `script.min.js`, and the banner never rendered until the visitor's first interaction. Reproduced and reported on `gooloo.de`. The 1.13.3 matcher uses two phases: keep the path-anchored substring check (so `plugins/faz-cookie-manager/` always wins), AND additionally drop entries where `faz-cookie-manager` appears as a complete token (preceded by start-of-string / `/` / `=` AND followed by end-of-string / `/` / `.`). Third-party companions like `my-integration-faz-cookie-manager-compat.js` still match `faz-cookie-manager` only as an internal substring, so they remain in the admin's list — the original CodeRabbit-flagged regression is NOT re-introduced.

## [1.13.2] — 2026-04-24

### Fixed
- **GDPR Strict preset "Customize" button unreadable (light-blue text on dark-blue background).** The `classic` banner template CSS (internally selected when the admin picks `type: banner` + `preferenceCenterType: pushdown`, which the preset does) hardcoded `color: #1863dc` for `.faz-btn-customize` instead of routing through the `--faz-settings-button-color` CSS custom property like every other template variant does. It also wired `border-color` to `--faz-settings-button-color` (the *text* colour variable) instead of to `--faz-settings-button-border-color`. Result: the preset's `#ffffff` text override was silently ignored and the button text stayed at the framework default `#1863dc`, which collides visually with the preset's dark-blue `#1e40af` background. Pattern now matches the other templates: `color: var(--faz-settings-button-color, #1863dc)`, `border-color: var(--faz-settings-button-border-color, #1863dc)`.
- **Consent banner invisible on LiteSpeed Guest Mode installs.** LiteSpeed Cache's Guest Mode keeps a separate "Guest Mode JS exclude" list (`optm-gm_js_exc`) that is consulted *before* the regular optimization exclude lists, so on sites with `guest = 1` + `guest_optm = 1` (the default for any admin who clicks "Activate Guest Mode") the 1.13.1 auto-exclude block had no effect: `script.min.js` was still rewritten to `type="litespeed/javascript"` and held back until the visitor interacted with the page. The banner stayed hidden on first paint, which breaks first-visit GDPR compliance. Added the missing `litespeed_optm_gm_js_exc` filter alongside the four filters 1.13.1 already covered. Reproduced on `fabiodalez.it`: first-visit banner paint now happens immediately, regardless of Guest Mode state.
- **Alt-asset mode handles now protected from cache-plugin optimisation.** The 1.13.1 `tag_own_scripts_nooptimize` filter used a hardcoded handle list that included `faz-fw` (the main script alias used when "Alternative asset path" is enabled) but not its siblings `faz-fw-gcm`, `faz-fw-tcf-cmp`, `faz-fw-a11y`. On installs running alt-asset mode, those children were silently re-eligible for LiteSpeed / WP Rocket / Autoptimize defer+delay+combine, which undoes the 1.13.1 fix for TCF and GCM specifically. Replaced the hardcoded list with a prefix-matching helper (`is_own_script_handle()`) that covers every handle starting with `$this->plugin_name` or `faz-fw` without needing a manual update when a new handle is added in a future release.
- **`litespeed_optm_js_delay_inc` scrubbing now path-anchored.** The include-list filter hook was removing any entry containing the literal substring `faz-cookie-manager`, which could collaterally drop a third-party integration entry (e.g. `my-plugin-faz-cookie-manager-compat.js`) the admin had intentionally added. Match now anchored to `plugins/faz-cookie-manager/`, which only matches the plugin's own script paths.

### Added
- **`faz_auto_exclude_cache_plugins` filter** as an opt-out hatch. Site admins who deliberately want FAZ scripts to go through their cache plugin's JS delay/defer/combine pipelines (e.g. for a performance A/B test) can now disable the whole auto-exclusion block with `add_filter( 'faz_auto_exclude_cache_plugins', '__return_false' );`. Default remains `true` — nothing changes for existing installs.

### Review
- Post-merge review of PR #83 by internal tooling (CodeRabbit was rate-limited at merge time) flagged the alt-asset and path-anchor issues. The WP Rocket regex pattern finding was investigated and determined to be a false positive — WP Rocket wraps exclude patterns with `#...#` delimiters, so the `/` characters in `/wp-content/plugins/faz-cookie-manager/(.*)` are safe.

## [1.13.1] — 2026-04-24

### Fixed
- **Auto-exclude the plugin's own scripts from cache/optimization plugins.** LiteSpeed Cache's "Delay JS" (and the equivalent feature in WP Rocket / Autoptimize / SG Optimizer / Hummingbird / W3 Total Cache) defaults to holding every JS file back until the first user interaction. For a consent banner this is a critical regression: the banner — and the `document.createElement` interceptor that blocks third-party trackers pre-consent — stays dormant on page load, and when the user finally scrolls/taps, the optimizer releases every deferred script at once, so ad and analytics scripts execute alongside the banner instead of being gated by it. On `fabiodalez.it` this presented as "banner appears only after clicking"; on `gooloo.de` it presented as "ads flow in only on the second tap". Both were the same LiteSpeed delay behaviour, not a plugin bug, but the plugin now opts itself out by default so admins don't have to configure anything.
  - `script_loader_tag` now adds `data-no-defer="1" data-no-optimize="1" data-no-minify="1" data-cfasync="false" data-ao-skip="1"` to every `<script>` emitted for a FAZ handle (main, gcm, tcf-cmp, a11y, wca, microsoft-consent, faz-fw). These are the opt-out attributes recognised by LiteSpeed Cache, WP Rocket, Autoptimize, SG Optimizer, Hummingbird, Cloudflare Rocket Loader and W3 Total Cache.
  - Belt-and-suspenders hooks on `litespeed_optm_js_defer_exc`, `litespeed_optm_js_delay_inc`, `litespeed_optimize_js_excludes`, `rocket_exclude_defer_js`, `rocket_delay_js_exclusions`, `rocket_minify_excluded_external_js`, `autoptimize_filter_js_exclude` add the `plugins/faz-cookie-manager/` path pattern to each plugin's exclude list and scrub our path from LiteSpeed's include-based Delay JS list, covering the case where a future cache-plugin release changes attribute support.

## [1.13.0] — 2026-04-24

### Fixed
- **Per-service consent cookie oversized (#80)** — on installs with the default shipped service catalog (~160 services) the `fazcookie-consent` cookie grew to ~5.4 KB URL-encoded, past the 4 KB per-cookie limit every major browser enforces. The browser silently discarded every save, so enabling `banner_control.per_service_consent` effectively made "Save My Preferences" a no-op — the next request always saw the pre-choice snapshot. `_fazSetInStore` now omits `svc.<id>` entries whose value matches the category consent; the frontend loader already falls back to the category when the entry is absent, so the contract is preserved and the cookie stays well under the limit (~1 KB in practice).
- **Scanner misses freshly-modified pages (#78)** — `discover_urls` now places recently-modified posts (home + posts modified in the last 7 days, the same set returned by `get_priority_urls()`) in the `priority_urls` bucket, which the client-side scanner exempts from early-stop. Previously they landed in the regular `urls` queue and could be skipped when the 20-consecutive-no-findings threshold fired before reaching their position. Also deduplicates the `get_priority_urls()` WP_Query across the incremental and full-scan branches.
- **Server-side cookie shredder now honours the frontend whitelist** — `shred_non_consented_cookies()` was deleting cookies belonging to whitelisted services on every subsequent `send_headers`, so the `_whitelistedCookiePatterns` frontend payload was only respected on the first page load. Extracted a single-source-of-truth helper (`compute_whitelisted_cookie_patterns`) shared by the frontend store and the server-side shredder.
- **Whitelist pattern match is unidirectional with a minimum-length guard** — `stripos($pattern, $allowed) || stripos($allowed, $pattern)` previously whitelisted nearly every provider if an admin entered `"js"` or `"com"`. Narrowed to `stripos($pattern, $allowed)` with a three-character minimum on the needle.
- **Preference center focus retries now stop on close** — the RAF + `setTimeout(50/150/350/750ms)` retry chain queued by `_fazFocusIntoElement` kept running after `_fazHidePreferenceCenter`, occasionally stealing focus back from the restored trigger element. A tracker on `_fazConfig._preferenceFocusRetries` is now cancelled by the hide path.
- **Dynamic scripts preserve their original `type`** — scripts created with `type="module"` (or any other executable type) were being forced back to `text/javascript` when unblocked. The setters now snapshot the original type into `data-faz-original-type` before writing `javascript/blocked` and restore from it on unblock, mirroring the server-side approach in `_fazBuildRestoredScript` / `_fazRestoreInlineScript`.

### Internal
- **Provider-matrix fixture serialises hit increments under flock** — parallel PHP-FPM workers were silently dropping increments on the classic `get_option()` → `update_option()` read-modify-write; now wrapped in `flock(LOCK_EX)` with a diagnostic `error_log` when the lock can't be acquired. `increment_hit()` returns the in-lock count to `collect_hit()` so the response `hits` field never reports a stale value.
- **REST PUT helper uses `X-HTTP-Method-Override`** — `fazApiPut` mirrors `fazApiDelete` (POST + override header) to stay portable across nginx/Apache/php -S where native PUT over `?rest_route=…` returns 405. Duplicated `updateBanner` helpers in spec files consolidated to delegate.
- **E2E scanner discover predicate is permalink-agnostic** — the `waitForResponse` filter now matches both `?rest_route=/faz/v1/scans/discover` and `/wp-json/faz/v1/scans/discover`, and guards `decodeURIComponent` against malformed percent-escapes.
- **Scanner API `get_priority_urls` is computed once** per `discover_urls` request instead of twice, removing a redundant WP_Query with `date_query` over every public post type.
- **Test stability**: `resetProviderMatrixState()` purges stale `_faz_custom_*` cookie rows (and their cache-group transient) so the matrix test's `functional=yes` iteration no longer races the shredder on auto-discovered `uncategorized` entries. PR #44 i18n suite wipes leftover `_faz_test_i18n` rows in `beforeAll`.
- Omnibus coverage spec pins each of the above fixes at the contract level so regressions surface immediately.

## [1.12.1] — 2026-04-22

### Fixed
- Added LiteSpeed Cache cookies (`_lscache_vary`, `lscache_vary`, `_litespeed_*`) to the internal cookie whitelist so they are not shown in the frontend banner or flagged as non-technical.

## [1.12.0] — 2026-04-22

### Security & Blocking
- **data: URI scripts** decoded and content-matched against provider patterns (PHP + JS)
- `strpos` → `stripos` in all OB guard checks — uppercase HTML tags now processed
- `extract_tag_attr` supports unquoted HTML5 attributes and rejects `data-src` confusion
- Provider boundary check validates character **after** match (prevents suffix false positives)
- `rawurldecode()` before `base64_decode()` for percent-encoded data: URI payloads (PHP + JS)
- Cookie consent value: `sanitize_text_field()` now runs after `rawurldecode()` (was stripping delimiters)
- MutationObserver: `_fazIsCategoryToBeBlocked()` replaces loose attribute presence check
- Relative URL resolution via `new URL(urlToParse, window.location.href)` in MutationObserver

### Consent Logging
- Empty `consent_id` no longer triggers global 300s throttle collision
- `sanitize_log_url()` deliberately omits `user:pass@host` credentials
- `db_version` only bumped when UA hash migration succeeds
- Consent log schema: `banner_slug` and `policy_revision` columns added
- Throttle: 10s/IP + 300s/consent_id dual guardrail

### TCF / IAB v2.3
- `buildConsentArtifacts()` computes derived consent data once per call
- Purpose 1 gated on `purposeOneTreatment`
- `euconsent-v2` cleared on consent withdrawal
- `cmpStatus` lifecycle: `loading` → `loaded`
- Switch case variables wrapped in block braces (Biome lint)

### Accessibility
- Focus trap extended to `input`, `select`, `textarea`, `summary`
- `_fazFocusIntoElement` includes `summary` in focusable selector
- Pushdown toggle moves focus on open/close
- `aria-label` localized on preference center

### Performance
- `faz_settings` memoized per-request
- `faz_current_language()` static cache
- N+1 cookie queries eliminated (batch load)
- `always_allowed_cache` property avoids redundant `apply_filters` in loop
- `a11y.min.js` generated

### Database & Migrations
- Banner table indexes (`slug`, `status`) via migration 3.4.1
- Category deletion uses DB transactions with ROLLBACK on failure
- `get_fallback_category_id` returns `null` on query error (not 0)

### WordPress Plugin Check
- All ERRORS resolved (escaping, WP_Filesystem, ABSPATH guards)
- `run-scan.php` excluded from wp.org ZIP (CLI bootstrap pattern incompatible with checker)

### Geo & Misc
- CF-IPCountry trust unified via `faz_trust_cf_ipcountry_header` filter (default: false)
- GB added to EU country list
- `wp_tempnam()` uses destination dir for atomic GVL file move
- Uninstall cleans both `faz-cookie-manager/` and `fazcookie/` upload directories

### Tests
- New `category-blocking.spec.ts` — 10 tests covering all category consent scenarios
- New `session-fixes-coverage.spec.ts` — 10 tests for session fix verification
- New `pr-2026-04-19-audit.spec.ts` — 15 tests for audit finding regressions
- New fixture plugin `faz-e2e-audit-lab` for performance and geo probes
- 208+ E2E tests passing on nginx + php-fpm

## [1.11.3] — 2026-04-17

### Added
- **WP 5.7+ `wp_inline_script_tag` filter** — inline scripts added via `wp_add_inline_script()` are now intercepted before the output buffer, so the browser never sees the original executable tag in the page source. Backward compatible: on WP < 5.7 the filter does not exist and the OB catches everything as before.
- **Returning visitor unblock retry** — `_fazUnblock()` now runs at multiple delays (250ms, 1s, 2s) plus on the `load` event for returning visitors with stored consent. Scripts blocked server-side by the PHP output buffer are restored even when the initial unblock pass fires before late-rendered or deferred DOM nodes are present.

### Fixed
- **WordPress Plugin Check compliance** — resolved all errors flagged by the official Plugin Check tool: `OutputNotEscaped` (5 instances: `$total`, `$site_name`, exception messages in MMDB reader), `MissingTranslatorsComment` (12 `__()` calls with placeholders), `NoExplicitVersion` (2 `wp_register_script`/`wp_register_style` calls). The `Placeholder_Builder::get_css()` false positive is suppressed with an inline phpcs:ignore.
- **Inline script whitelist bypass** — `filter_inline_script_tag()` was passing the full `$tag` (including inline body) to `is_whitelisted()`, so any tracking snippet mentioning a whitelist token (e.g. "jquery", "wp-includes") in its body would bypass blocking. Now only passes tag attributes + handle + id, matching the OB path.
- **`_fazBuildRestoredScript()` helper** — extracted duplicated script-cloning logic from `_fazUnblockServerSide()` into a shared function. Handles `src`, inline content, data-URI decoding, attribute copying, and original-type restoration in one place.

### Internal
- E2E: `inline-script-filter.spec.ts` — 3 tests covering blocked-before-consent, unblocked-after-accept, and returning-visitor scenarios. Mu-plugin scoped to `?faz_inline_probe` requests to prevent cross-spec contamination.

## [1.11.2] — 2026-04-16

### Fixed
- **Preference center invisible on dark presets** — all 5 design presets (CCPA Simple, Dark Professional, GDPR Strict, High Contrast, Light Minimal) now include full `preferenceCenter`, `categoryPreview` and `optoutPopup` color palettes. Previously only the banner bar (`notice`) was styled; the preference center modal inherited defaults that produced invisible text and buttons on dark themes. Follows the CookieYes upstream `theme.json` structure.
- **TypeError crash on ChromeOS / PMP-exempt members** — `_fazRenderBanner()` now guards against a missing `#fazBannerTemplate` element. PMP-exempt members have `script.js` loaded (for GCM consent signals) but `banner_html()` suppresses the template element — the unguarded `template.innerHTML` threw "Cannot read properties of null". Reported by nkoffiziell (gooloo.de, ChromeOS).
- **`applyDesignPreset()` now deep-replaces `preferenceCenter` and `optoutPopup`** — the previous cherry-pick approach missed `toggle.states` and left stale values from prior presets.
- **`const _fazGsk = true;` → `var`** — the WP Consent API inline script used ES6 `const` which runs before the main script loads; a syntax error there would prevent the entire chain from executing on edge-case browsers.
- **Removed `#000000` → transparent skip in template CSS** — `<input type="color">` stores `#000000` when no transparent option exists, but design presets like High Contrast intentionally use `#000000` for buttons. The skip was producing blue defaults instead of black.

### Internal
- `applyDesignPreset()` in `banner.js` syncs `categoryPreview`, `preferenceCenter` and `optoutPopup` from preset config. Uses `applyPresetSection()` with structural-key preservation (`status`, `tag`, `meta`).
- `normalizeBannerConfig()` ensures the preference center toggle config is consistent on banner load.
- `get_default_config_type()` in `class-banner.php` picks the correct law-specific defaults (GDPR vs CCPA) for sanitization.
- E2E: `pr61-regression.spec.ts` — 6 regression tests covering preset application, transparent button handling, PMP+GCM no-crash, missing template guard, and WP Consent API compatibility.
- E2E: `a11y.spec.ts` — `beforeAll` resets banner to box/bottom-left via `wpEval()` for test isolation; focus trap test marked `fixme(#62)`.

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
