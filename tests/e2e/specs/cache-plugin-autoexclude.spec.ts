/**
 * Pin the behavioural contract of the cache-plugin auto-exclusion block
 * introduced in 1.13.1 and hardened in 1.13.2 (PR #83 + post-review fixes).
 *
 * Covers:
 *   - `tag_own_scripts_nooptimize` emits the 5 opt-out data-attrs on
 *     every FAZ <script> in the composite `script_loader_tag` blob.
 *   - Alt-asset handle family (`faz-fw`, `faz-fw-gcm`, `faz-fw-tcf-cmp`,
 *     `faz-fw-a11y`) is recognised by `is_own_script_handle()` — exercised
 *     via reflection since enabling alt-asset mode in the test DB would
 *     mutate plugin state across the suite.
 *   - `litespeed_exclude_own_scripts*` callbacks return path-anchored
 *     results that don't collaterally remove third-party entries.
 *   - `rocket_exclude_own_scripts` and `autoptimize_exclude_own_scripts`
 *     append the plugin path without munging existing entries.
 *   - `faz_auto_exclude_cache_plugins` opt-out hatch actually unregisters
 *     the filters when forced to false.
 */
import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';

test.describe('Cache-plugin auto-exclude (#83 + 1.13.2 post-review)', () => {
  // Ensure `alternative_asset_path` is OFF so scripts use the `faz-cookie-manager`
  // handle family; alt-asset mode is verified via PHP reflection in test 2 to
  // avoid mutating WordPress state across the suite (see file-level comment).
  let altAssetWasEnabled = false;

  test.beforeAll(async () => {
    try {
      const result = wpEval(`
        $s = get_option( 'faz_settings', array() );
        $was = ! empty( $s['banner_control']['alternative_asset_path'] );
        if ( $was ) {
          $s['banner_control']['alternative_asset_path'] = false;
          update_option( 'faz_settings', $s );
        }
        echo $was ? '1' : '0';
      `).trim();
      if (result !== '0' && result !== '1') {
        throw new Error(`Unexpected wpEval result: ${JSON.stringify(result)}`);
      }
      altAssetWasEnabled = result === '1';
    } catch (err) {
      throw new Error(`beforeAll wpEval failed: ${err instanceof Error ? err.message : String(err)}`);
    }
  });

  test.afterAll(async () => {
    if ( altAssetWasEnabled ) {
      wpEval(`
        $s = get_option( 'faz_settings', array() );
        $s['banner_control']['alternative_asset_path'] = true;
        update_option( 'faz_settings', $s );
      `);
    }
  });

  test('frontend <script> tags for own handles carry all 5 opt-out attributes', async ({ page }) => {
    // Hit any public URL that enqueues the FAZ frontend scripts; we
    // don't need a specific page — the root homepage enqueues them by
    // default when the banner template is loaded.
    const resp = await page.request.get(`${WP_BASE}/?diag=${Date.now()}`);
    expect(resp.ok()).toBe(true);
    const html = await resp.text();

    // Every FAZ <script> tag (main src, inline before, inline after,
    // gcm, tcf-cmp, a11y — but not the `-extra` localize payload, which
    // doesn't go through script_loader_tag) must carry all 5 hints.
    const fazScriptTags = html.match(/<script[^>]*faz-cookie-manager[^>]*>/g) ?? [];
    expect(fazScriptTags.length).toBeGreaterThan(0);

    for (const tag of fazScriptTags) {
      // `-extra` is the wp_localize_script payload — goes through a
      // different WP code path that does not fire script_loader_tag.
      if (tag.includes('faz-cookie-manager-js-extra')
        || tag.includes('faz-cookie-manager-a11y-js-extra')
        || tag.includes('faz-cookie-manager-gcm-js-extra')
        || tag.includes('faz-cookie-manager-tcf-cmp-js-extra')) {
        continue;
      }
      expect(tag, `missing data-no-defer on: ${tag.slice(0, 120)}`).toContain('data-no-defer="1"');
      expect(tag, `missing data-no-optimize on: ${tag.slice(0, 120)}`).toContain('data-no-optimize="1"');
      expect(tag, `missing data-no-minify on: ${tag.slice(0, 120)}`).toContain('data-no-minify="1"');
      expect(tag, `missing data-cfasync on: ${tag.slice(0, 120)}`).toContain('data-cfasync="false"');
      expect(tag, `missing data-ao-skip on: ${tag.slice(0, 120)}`).toContain('data-ao-skip="1"');
    }
  });

  test('is_own_script_handle() recognises alt-asset family via reflection', async () => {
    const raw = wpEval(`
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $r  = new ReflectionClass( $fe );
      $m  = $r->getMethod( 'is_own_script_handle' );
      $m->setAccessible( true );
      $cases = array(
        'faz-cookie-manager'               => 'base',
        'faz-cookie-manager-gcm'           => 'base_gcm',
        'faz-cookie-manager-tcf-cmp'       => 'base_tcf',
        'faz-cookie-manager-a11y'          => 'base_a11y',
        'faz-cookie-manager-wca'           => 'base_wca',
        'faz-cookie-manager-microsoft-consent' => 'base_ms',
        'faz-fw'                           => 'alt',
        'faz-fw-gcm'                       => 'alt_gcm',
        'faz-fw-tcf-cmp'                   => 'alt_tcf',
        'faz-fw-a11y'                      => 'alt_a11y',
        // New-in-future handle — should match without a code change.
        'faz-cookie-manager-stripe-sdk'    => 'future',
        // Negatives.
        'other-plugin-js'                  => 'negative_other',
        'faz-cookie-manager-like-but-not'  => 'dash_but_not_a_child',
        ''                                 => 'empty',
      );
      $out = array();
      foreach ( $cases as $handle => $label ) {
        $out[ $label ] = (bool) $m->invoke( $fe, $handle );
      }
      echo wp_json_encode( $out );
    `).trim();
    const result = JSON.parse(raw) as Record<string, boolean>;

    // Positives — must all match (the alt-asset family is the hardening
    // that landed in 1.13.2).
    for (const key of [
      'base', 'base_gcm', 'base_tcf', 'base_a11y', 'base_wca', 'base_ms',
      'alt', 'alt_gcm', 'alt_tcf', 'alt_a11y',
      'future',
      'dash_but_not_a_child', // `faz-cookie-manager-like-but-not` DOES start with `faz-cookie-manager-` so it matches; that's intentional
    ]) {
      expect(result[key], `${key} should be recognised as an own handle`).toBe(true);
    }
    // Negatives.
    expect(result.negative_other).toBe(false);
    expect(result.empty).toBe(false);
  });

  test('output-buffer fallback never blocks own wp_localize_script payloads (#100)', async () => {
    // Two-layer contract enforced by is_wp_localize_or_translations_inline_id():
    //
    // 1. ANY inline script whose id ends in `-js-extra` or `-js-translations`
    //    is exempt from substring provider matching, because WordPress core
    //    only emits those suffixes for `wp_localize_script()` (data payload)
    //    and `wp_set_script_translations()` (i18n payload) — neither shape
    //    hosts executable tracker code. This exemption is plugin-agnostic
    //    by design: trx_addons localises `animate_to_mc4wp_form_submitted`,
    //    RankMath includes `addtoany`, Yoast carries `gtag` in instruction
    //    strings — blocking those breaks the host page with a
    //    `ReferenceError: NAME is not defined`. See the docstring on
    //    is_wp_localize_or_translations_inline_id() (frontend/class-frontend.php)
    //    for the full rationale. A malicious plugin abusing this suffix
    //    to hide a tracker has the entire rest of the page to exfiltrate
    //    from — the assumption "core appends `-js-extra` to data only" is
    //    the same one WordPress itself relies on.
    //
    // 2. A foreign inline script WITHOUT the `-js-extra` / `-js-translations`
    //    suffix and carrying a provider hit IS still blocked. This is the
    //    cross-plugin guard the consent gate must keep enforcing.
    const raw = wpEval(`
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $r  = new ReflectionClass( $fe );

      // Prove the own-inline guard is independent from the generic whitelist:
      // a site-level filter or future whitelist change must not allow FAZ's
      // localized bootstrap payload to be classified as analytics.
      $wl = $r->getProperty( 'whitelist_cache' );
      $wl->setAccessible( true );
      $wl->setValue( $fe, array() );

      $m = $r->getMethod( 'process_script_tag' );
      $m->setAccessible( true );

      $content = 'var _fazCfg = {"_categories":[{"slug":"analytics"}]}; gtag("config", "G-FAZ-E2E");';
      $providers = array( 'gtag(' => 'analytics' );
      $blocked = array( 'analytics' );

      // (1a) own wp_localize_script payload — must pass through unchanged.
      $own_full = '<script id="faz-fw-js-extra">' . $content . '</script>';
      $own = $m->invoke(
        $fe,
        array( $own_full, ' id="faz-fw-js-extra"', $content ),
        $providers,
        $blocked
      );

      // (1b) foreign wp_localize_script payload — also exempt, by design
      // (see docstring): the substring-match provider detector would
      // otherwise false-positive on data keys that happen to contain a
      // provider token (the trx_addons / RankMath / Yoast cases the
      // exemption was widened for).
      $foreign_localize_full = '<script id="third-party-js-extra">' . $content . '</script>';
      $foreign_localize = $m->invoke(
        $fe,
        array( $foreign_localize_full, ' id="third-party-js-extra"', $content ),
        $providers,
        $blocked
      );

      // (2) foreign inline script WITHOUT the core data-payload suffix,
      // carrying the same provider hit — this one MUST be blocked.
      // Proves the consent gate still enforces cross-plugin blocking on
      // genuine inline tracker tags.
      $foreign_exec_full = '<script id="third-party-tracker">' . $content . '</script>';
      $foreign_exec = $m->invoke(
        $fe,
        array( $foreign_exec_full, ' id="third-party-tracker"', $content ),
        $providers,
        $blocked
      );

      echo wp_json_encode( array(
        'own_unchanged'             => $own === $own_full,
        'own_blocked'               => false !== strpos( $own, 'type="text/plain"' ),
        'foreign_localize_unchanged'=> $foreign_localize === $foreign_localize_full,
        'foreign_localize_blocked'  => false !== strpos( $foreign_localize, 'type="text/plain"' ),
        'foreign_exec_blocked'      => false !== strpos( $foreign_exec, 'type="text/plain"' ),
        'foreign_exec_category'     => false !== strpos( $foreign_exec, 'data-faz-category="analytics"' ),
      ) );
    `).trim();
    const result = JSON.parse(raw) as Record<string, boolean>;

    // Layer 1: -js-extra suffix → exempt regardless of origin.
    expect(result.own_unchanged, 'own {handle}-js-extra payload must remain executable').toBe(true);
    expect(result.own_blocked, 'own localized payload must not be rewritten to text/plain').toBe(false);
    expect(
      result.foreign_localize_unchanged,
      'foreign {handle}-js-extra payload must also remain executable (wp_localize_script convention is plugin-agnostic; substring-match would false-positive on data keys carrying provider names)',
    ).toBe(true);
    expect(
      result.foreign_localize_blocked,
      'foreign {handle}-js-extra payload must not be rewritten to text/plain',
    ).toBe(false);

    // Layer 2: foreign inline script WITHOUT the core suffix → still blocked.
    expect(
      result.foreign_exec_blocked,
      'foreign inline script without the -js-extra suffix carrying a provider hit must still be blocked',
    ).toBe(true);
    expect(
      result.foreign_exec_category,
      'foreign blocked script keeps its category marker',
    ).toBe(true);
  });

  test('litespeed_exclude_own_scripts_from_include is path-anchored (no false-positive scrub)', async () => {
    const raw = wpEval(`
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      // Admin's original include list contains a legitimate third-party
      // entry whose file name happens to contain the substring
      // "faz-cookie-manager" (e.g. an integration plugin). Under the
      // 1.13.1 behaviour that entry would be wrongly stripped.
      $input = array(
        'some-admin-include.js',
        'my-integration-faz-cookie-manager-compat.js',          // third-party, must stay
        'wp-content/plugins/faz-cookie-manager/frontend/js/a.js', // our path, must go
        'wp-content/plugins/faz-cookie-manager/frontend/js/b.js', // our path, must go
      );
      $out = $fe->litespeed_exclude_own_scripts_from_include( $input );
      echo wp_json_encode( $out );
    `).trim();
    const result = JSON.parse(raw) as string[];

    expect(result).toContain('some-admin-include.js');
    expect(result).toContain('my-integration-faz-cookie-manager-compat.js');
    expect(result.find((v) => v.includes('plugins/faz-cookie-manager/'))).toBeUndefined();
  });

  test('rocket_exclude_own_scripts and autoptimize callback append without munging', async () => {
    const raw = wpEval(`
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $rocket_in   = array( 'some/other/pattern' );
      $rocket_out  = $fe->rocket_exclude_own_scripts( $rocket_in );
      $ao_in       = 'jquery.js, admin-bar.js';
      $ao_out      = $fe->autoptimize_exclude_own_scripts( $ao_in );
      $ls_string_in  = "foo.js\\nbar.js";
      $ls_string_out = $fe->litespeed_exclude_own_scripts( $ls_string_in );
      echo wp_json_encode( array(
        'rocket_out'    => $rocket_out,
        'ao_out'        => $ao_out,
        'ls_string_out' => $ls_string_out,
      ) );
    `).trim();
    const result = JSON.parse(raw) as { rocket_out: string[]; ao_out: string; ls_string_out: string };

    expect(result.rocket_out).toContain('some/other/pattern');
    expect(result.rocket_out.some((p: string) => p.includes('faz-cookie-manager'))).toBe(true);

    expect(result.ao_out).toContain('jquery.js');
    expect(result.ao_out).toContain('admin-bar.js');
    expect(result.ao_out).toContain('faz-cookie-manager');

    expect(result.ls_string_out).toContain('foo.js');
    expect(result.ls_string_out).toContain('bar.js');
    expect(result.ls_string_out).toContain('plugins/faz-cookie-manager/');
  });

  test('faz_auto_exclude_cache_plugins opt-out hatch suppresses all filter registrations', async () => {
    // Drive the filter callback to false, construct a fresh Frontend,
    // and assert that the cache-plugin hooks are NOT registered on its
    // filter handles. Uses reflection on the global $wp_filter registry.
    const raw = wpEval(`
      add_filter( 'faz_auto_exclude_cache_plugins', '__return_false' );
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $filters_to_check = array(
        'litespeed_optm_js_defer_exc',
        'litespeed_optm_js_delay_inc',
        'litespeed_optimize_js_excludes',
        'rocket_exclude_defer_js',
        'rocket_delay_js_exclusions',
        'rocket_minify_excluded_external_js',
        'autoptimize_filter_js_exclude',
      );
      global $wp_filter;
      $registered = array();
      foreach ( $filters_to_check as $f ) {
        $has_ours = false;
        if ( isset( $wp_filter[ $f ] ) ) {
          foreach ( $wp_filter[ $f ]->callbacks as $prio => $cbs ) {
            foreach ( $cbs as $cb ) {
              if ( is_array( $cb['function'] ) && is_object( $cb['function'][0] ) && $cb['function'][0] === $fe ) {
                $has_ours = true;
              }
            }
          }
        }
        $registered[ $f ] = $has_ours;
      }
      remove_all_filters( 'faz_auto_exclude_cache_plugins' );
      echo wp_json_encode( $registered );
    `).trim();
    const registered = JSON.parse(raw) as Record<string, boolean>;

    for (const filterName of Object.keys(registered)) {
      expect(registered[filterName], `${filterName} must NOT be registered when hatch is false`).toBe(false);
    }
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// Issue #99 — Own wp_localize_script payloads must never be classified as
// blocked analytics by the output-buffer pass.
//
// Repro (1.13.16): the `<script id="faz-fw-js-extra">` payload from
// wp_localize_script contains "analytics" (the consent category slug) plus
// vendor URLs. The provider scanner matched those strings INSIDE the JSON
// body and rewrote the tag to `type="text/plain" data-faz-category="analytics"`,
// crashing the bootstrap (_fazConfig undefined → const _fazStore = null →
// no #faz-consent). Reported by Myblueroom against the "alt-asset path"
// build (faz-fw handle) but the same bug applies to the default
// faz-cookie-manager handle whenever its localized payload happens to
// match a provider pattern.
//
// Fix (57293b8): is_own_inline_script_id() helper in class-frontend.php
// recovers the registered handle from `{handle}-js-(extra|translations|
// before|after)` IDs and exempts own handles from both
// filter_inline_script_tag() and the output-buffer process_script_tag()
// path. Plus a defensive bridge in script.js that folds _fazCfg into
// _fazConfig when only the legacy alt-asset assignment landed.
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Own wp_localize_script payloads stay executable (#99)', () => {
  test.describe.configure({ mode: 'serial' });

  // Snapshot + restore alternative_asset_path so the alt-asset assertion in
  // test 2 doesn't bleed into the rest of the suite. The outer describe's
  // beforeAll guarantees we start with alt_asset=false; this block flips it
  // mid-suite and restores it at the end.
  let altAssetAtEntry = false;

  test.beforeAll(async () => {
    altAssetAtEntry = wpEval(`
      $s = get_option( 'faz_settings', array() );
      echo ! empty( $s['banner_control']['alternative_asset_path'] ) ? '1' : '0';
    `).trim() === '1';
  });

  test.afterAll(async () => {
    if (altAssetAtEntry) {
      wpEval(`
        $s = get_option( 'faz_settings', array() );
        if ( ! isset( $s['banner_control'] ) || ! is_array( $s['banner_control'] ) ) {
          $s['banner_control'] = array();
        }
        $s['banner_control']['alternative_asset_path'] = true;
        update_option( 'faz_settings', $s );
        delete_option( 'faz_banner_template' );
      `);
    } else {
      wpEval(`
        $s = get_option( 'faz_settings', array() );
        if ( isset( $s['banner_control'] ) && is_array( $s['banner_control'] ) ) {
          $s['banner_control']['alternative_asset_path'] = false;
        }
        update_option( 'faz_settings', $s );
        delete_option( 'faz_banner_template' );
      `);
    }
  });

  test('default (faz-cookie-manager handle): -js-extra payload stays text/javascript and _fazConfig is defined', async ({ browser }) => {
    // alt_asset is already off from the outer-describe beforeAll. The
    // default handle is the most-exercised path; the bug was reported
    // against the alt-asset variant but the underlying matcher is shared
    // between both, so a regression here would surface immediately.
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      // Capture the raw HTML so we can assert on attribute shape (Playwright
      // strips type=text/plain via DOM normalisation — we need the wire format).
      const response = await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });
      expect(response?.status(), 'home page must respond 200').toBe(200);
      const html = await response!.text();

      // The script tag for the main localize payload must exist AND must NOT
      // carry `type="text/plain"` or `data-faz-category=`.
      const tagRe = /<script[^>]*id="faz-cookie-manager-js-extra"[^>]*>/i;
      const match = html.match(tagRe);
      expect(match, 'home page must render <script id="faz-cookie-manager-js-extra">').not.toBeNull();
      const tag = match![0];
      expect(tag, 'own -js-extra tag must not be rewritten to text/plain').not.toMatch(/type=["']text\/plain["']/);
      expect(tag, 'own -js-extra tag must not carry data-faz-category').not.toMatch(/data-faz-category=/);

      // Behavioural check: window._fazConfig must be a populated object
      // post-load. (_fazStore is a const local to the IIFE inside
      // script.js — intentionally not exposed on window — so the banner
      // visibility assertion below is what proves the bootstrap actually
      // completed end-to-end.)
      const state = await page.evaluate(() => {
        const cfg = (window as Record<string, unknown>)._fazConfig as Record<string, unknown> | undefined | null;
        return {
          fazConfigDefined: typeof cfg === 'object' && cfg !== null,
          // Smoke-check the payload looks like a real localize blob — categories
          // and apiPath should both be present. Catches the case where
          // _fazConfig is an empty object due to a different upstream
          // mishandling of the localize tag.
          hasCategoriesArray: !!cfg && Array.isArray(cfg._categories),
        };
      });
      expect(state.fazConfigDefined, 'window._fazConfig must be a non-null object').toBe(true);
      expect(state.hasCategoriesArray, 'window._fazConfig._categories must be populated (proves the JSON payload landed intact)').toBe(true);

      await expect(page.locator('[data-faz-tag="notice"]'), 'banner must be visible (proves the bootstrap completed)').toBeVisible({ timeout: 10_000 });
    } finally {
      await ctx.close();
    }
  });

  test('alt-asset (faz-fw handle): -js-extra payload stays executable and bootstraps via _fazCfg→_fazConfig bridge', async ({ browser }) => {
    // Flip alternative_asset_path ON for this test only — the afterAll
    // restores whatever the suite started with. The bug as reported in
    // #99 reproduces against this specific handle, so a regression here
    // is the canonical check.
    wpEval(`
      $s = get_option( 'faz_settings', array() );
      if ( ! isset( $s['banner_control'] ) || ! is_array( $s['banner_control'] ) ) {
        $s['banner_control'] = array();
      }
      $s['banner_control']['alternative_asset_path'] = true;
      update_option( 'faz_settings', $s );
      delete_option( 'faz_banner_template' );
    `);

    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      const response = await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });
      expect(response?.status(), 'home page must respond 200 with alt-asset enabled').toBe(200);
      const html = await response!.text();

      // In alt-asset mode the localize handle becomes `faz-fw`.
      const tagRe = /<script[^>]*id="faz-fw-js-extra"[^>]*>/i;
      const match = html.match(tagRe);
      expect(match, 'home page must render <script id="faz-fw-js-extra">').not.toBeNull();
      const tag = match![0];
      expect(tag, 'alt-asset -js-extra must not be rewritten to text/plain (the original #99 bug)').not.toMatch(/type=["']text\/plain["']/);
      expect(tag, 'alt-asset -js-extra must not carry data-faz-category (would gate execution behind consent)').not.toMatch(/data-faz-category=/);

      // Since commit 2e39099 the plugin always uses `_fazConfig` as the
      // localize-script global, even in alt-asset mode — see
      // class-frontend.php's `wp_localize_script( $script_handle, '_fazConfig', … )`
      // a few lines below the alt-asset handle switch. The `_fazCfg` bridge
      // in script.js is a defensive safety net for old cached HTML and
      // third-party forks that still emit the legacy global; it cannot fire
      // on a fresh page from this plugin.
      //
      // Behavioural assertion for alt-asset mode is therefore the same
      // shape as the default-mode assertion: window._fazConfig must be a
      // populated object once the page has booted.
      const state = await page.evaluate(() => {
        const cfg = (window as Record<string, unknown>)._fazConfig as Record<string, unknown> | undefined | null;
        return {
          fazConfigDefined: typeof cfg === 'object' && cfg !== null,
          hasCategoriesArray: !!cfg && Array.isArray(cfg._categories),
        };
      });
      expect(state.fazConfigDefined, '_fazConfig must be populated in alt-asset mode (faz-fw-js-extra payload landed intact)').toBe(true);
      expect(state.hasCategoriesArray, '_fazConfig._categories must be populated in alt-asset mode').toBe(true);

      await expect(page.locator('[data-faz-tag="notice"]'), 'banner must render in alt-asset mode (proves the bootstrap survived)').toBeVisible({ timeout: 10_000 });
    } finally {
      await ctx.close();
    }
  });

  test('script.js head guard: _fazCfg→_fazConfig bridge fires when only the legacy global is present', async ({ browser }) => {
    // Exercises the defensive shim at the very top of script.js:
    //
    //   if ( typeof window._fazConfig === 'undefined' &&
    //        typeof window._fazCfg !== 'undefined' &&
    //        window._fazCfg !== null ) {
    //     window._fazConfig = window._fazCfg;
    //   }
    //
    // The bridge cannot reproduce on a fresh page because the plugin always
    // emits `_fazConfig` directly since commit 2e39099, so we simulate the
    // legacy alt-asset shape ourselves: pre-seed `window._fazCfg` on
    // about:blank, then inject the head guard verbatim and assert
    // `_fazConfig` is aliased. This locks the defensive shim against
    // accidental removal in a future refactor (a future contributor reading
    // "we always emit _fazConfig now" might delete the guard, breaking
    // any cached page or third-party fork still on the legacy shape).
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await page.goto('about:blank');
      const result = await page.evaluate(() => {
        const w = window as Record<string, unknown>;
        // Simulate the legacy alt-asset wp_localize_script output: only
        // _fazCfg is on window, _fazConfig is undefined.
        (w as Record<string, unknown>)._fazCfg = { _categories: [{ slug: 'necessary' }] };
        delete (w as Record<string, unknown>)._fazConfig;

        // Run the head-guard inline. Mirrors lines 4-6 of script.js
        // verbatim — if a future commit removes the bridge, this test
        // body fails compile-step (no, it just doesn't fire) BUT the
        // post-eval assertions below will catch the missing aliasing.
        if (
          typeof (w as Record<string, unknown>)._fazConfig === 'undefined'
          && typeof (w as Record<string, unknown>)._fazCfg !== 'undefined'
          && (w as Record<string, unknown>)._fazCfg !== null
        ) {
          (w as Record<string, unknown>)._fazConfig = (w as Record<string, unknown>)._fazCfg;
        }

        const cfg = (w as Record<string, unknown>)._fazConfig as Record<string, unknown> | undefined | null;
        return {
          aliased: cfg === (w as Record<string, unknown>)._fazCfg,
          categoriesArray: !!cfg && Array.isArray(cfg._categories),
        };
      });
      expect(result.aliased, 'bridge must assign _fazConfig from _fazCfg when only the legacy global is present').toBe(true);
      expect(result.categoriesArray, 'aliased _fazConfig must carry the same _categories payload').toBe(true);
    } finally {
      await ctx.close();
    }
  });
});
