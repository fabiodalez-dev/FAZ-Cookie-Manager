/**
 * FlyingPress page optimisation — FAZ must come out of every FlyingPress
 * optimiser pass, and out of every cached copy FlyingPress serves, exactly as
 * it went in.
 *
 * Why this file exists: a site on FlyingPress (version unknown) had FAZ's
 * consent runtime delayed until the first interaction, which is the one
 * failure a consent banner cannot afford — the interaction that finally shows
 * the banner is the same one that releases every delayed tracker. Measured on
 * FlyingPress 5.5 and 5.6.5 with "Delay JavaScript: user interaction", all
 * seven FAZ scripts stayed live while 17 others were delayed, so the exclusion
 * works today; these tests make sure it keeps working, on every copy of the
 * page FlyingPress produces:
 *   MISS    — the first anonymous response after a purge (unoptimised, but it
 *             goes through FlyingPress's output buffer);
 *   preload — the optimised render FlyingPress builds when its preloader asks
 *             (`x-flying-press-preload: 1`), emulated directly;
 *   HIT     — the cached .html.gz advanced-cache.php serves every later
 *             visitor (the copy that matters most);
 *   disk    — the copy `wp flying-press preload-cache` writes, read with gunzip
 *             straight from wp-content/cache/flying-press.
 *
 * What every optimisation test checks, against a baseline preload render taken
 * with every FlyingPress optimisation off:
 *   (a) every FAZ script handle (faz-cookie-manager-static-config-js, -js-extra,
 *       -js-before, -js, -gcm-js-after, -a11y-js, -a11y-js-extra, and any other
 *       faz-cookie-manager-* tag in the baseline) is still there: no
 *       data-loading-method, no data-src, no data:text/javascript conversion,
 *       src byte-for-byte unchanged (so not minified into the FlyingPress cache
 *       dir either);
 *   (b) the inline bootstrap (-js-before, window._fazBootstrap) still precedes
 *       script.min.js, which keeps its own defer;
 *   (c) the banner template and stylesheet survive;
 *   (d) every third-party script FAZ blocked server-side is still
 *       type="text/plain" with its data-faz-category, and no executable
 *       (self-hosted) copy of it exists;
 *   (e) the parked Meta pixel <img> and YouTube <iframe> keep data-faz-src;
 *       lazy-load and the YouTube placeholder never give them a live source.
 * Each optimisation test also asserts a positive control where FlyingPress's
 * behaviour is deterministic (a non-FAZ script delayed, a hashed asset URL, a
 * lazy-loaded control image), so a pass that silently did nothing cannot pass
 * for a pass that left FAZ alone. Where the effect depends on FlyingPress's
 * Cloud Optimizer (third-party delay, self-hosting, RUCSS) the evidence is
 * recorded as an annotation instead.
 *
 * Then (f) real-browser runs on the HIT copy under delay user-interaction and
 * defer: banner without any interaction, a main thread that keeps answering,
 * accept → consent cookie + the blocked script runs; (g) the cache-veto
 * contract: a crawler render with hide_from_bots, the DSAR form page and a
 * country-dependent render are never cached by FlyingPress (its
 * `flying_press_is_cacheable` verdict is false, repeated requests never HIT,
 * no .html.gz is written), against a control page that does reach a HIT;
 * (h) no PHP warning, notice or FAZ diagnostic was logged meanwhile.
 *
 * Fixtures: tests/e2e/fixtures/plugins/faz-e2e-litespeed-optm (the page-optimiser
 * lab: probes, cache-verdict recorder, preload narrowing, error capture,
 * falsifiability switch) and faz-e2e-fp-probe (FlyingPress's in-memory delay
 * excludes as X-Faz-Fp-* headers), plus faz-e2e-provider-matrix for the
 * same-origin blocked scripts whose execution the browser tests observe.
 *
 * CLOUD OPTIMIZER. The FlyingPress copy on the test site answers its Cloud
 * Optimizer with `{}` (a licence shim's pre_http_request at priority 10), so
 * external_domains, used_css and content_visibility_targets were always empty
 * and the tests of the passes that read them (08, 14, 15, 16) could not fail;
 * 11 could not either, because FlyingPress self-hosts only library CDNs. The
 * fixture faz-e2e-fp-cloud-mock now answers at priority 20 with a deterministic
 * set (fake CDN host cdn.faz-e2e.test + www.google.com; a used CSS with a marker
 * rule and, like the real service, every rule matching the request's
 * css_rucss_include_selectors; lazy-render targets outside <script> bodies),
 * extends flying_press_selfhost_external_domains, prints a non-FAZ control
 * script on the fake CDN, and can move FAZ's own assets onto that host. Each
 * of those tests now carries a hard positive control instead of an annotation.
 * Two field reports got their own tests: 25 (FAZ served from a CDN, delayed by
 * "Delay third-party scripts") and 26 (a reCAPTCHA URL in FAZ's inline config).
 * Red on 42728bbc, statically: 14 and 16 (RUCSS moves the FAZ banner sheet to
 * data-href and the used CSS has no FAZ selector), 25 and 26 (FAZ never feeds
 * js_delay_third_party_excludes). Green with fix/1342c-flyingpress.
 * Browser runs use the shared main-thread watchdog (utils/main-thread-watchdog.ts).
 *
 * HOW TO RUN THIS FILE
 * --------------------
 *     npm run test:e2e:flyingpress-optm
 *
 * The file is serial: on a build where one test is red, every later test is
 * reported as skipped, not passed. To see each verdict on such a build, run the
 * tests one at a time (beforeAll still prepares the environment):
 *     npm run test:e2e:flyingpress-optm -- -g " 14 "
 * Set FAZ_E2E_REQUIRE_CACHE_PLUGINS=1 to turn the "plugin not installed" /
 * "workers > 1" skips into failures (release gate).
 *
 * It activates FlyingPress globally and rewrites FLYING_PRESS_CONFIG, so it
 * needs the install to itself: it gates on `testInfo.config.workers === 1` and
 * skips otherwise, takes the shared WordPress lock, auto-skips when FlyingPress
 * is not installed, and in afterAll restores FLYING_PRESS_CONFIG exactly from
 * its snapshot, purges, empties the preload queue and deactivates flying-press.
 *
 * FALSIFIABILITY
 * --------------
 * The handle tests are only worth something if they can fail. To prove it,
 * re-run the file with the lab fixture's switch (option
 * faz_e2e_break_faz_exclusions=yes) held on for the whole run:
 *
 *     FAZ_E2E_BREAK_FAZ_EXCLUSIONS=1 npm run test:e2e:flyingpress-optm
 *
 * (Setting the option by hand is not enough: beforeAll resets it, so a normal
 * run can never inherit a broken state left behind by a falsifiability run.)
 * The fixture then removes FAZ's flying_press_exclude_* filters and its
 * runtime delay-exclusion injection (resetting FlyingPress's in-memory
 * js_delay_excludes to the stored value) and strips FAZ's optimiser hint
 * attributes. Tests 03–10 and 16 must then go red (02 stays green: the
 * MISS copy is never optimised) — measured on 2026-10-16, one test at a time,
 * all nine red because FAZ's static config is delayed through data-src or its
 * inline config is turned into a data:text/javascript defer. The browser run
 * (17) stays green under the switch: the visitor still gets a working banner
 * and the blocked script still runs after Accept, so it cannot tell; test 04,
 * on the same HIT copy, is the one that catches the rewrite. Test 23 does exactly this
 * inside the run under "delay until user interaction" and asserts the handle
 * check reports delayed FAZ scripts; if it ever goes green with the switch on,
 * the check itself is broken. afterAll deletes the option either way.
 */
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { gunzipSync } from 'node:zlib';
import { request as playwrightRequest, type Browser, type BrowserContext } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import {
  wp,
  wpEval,
  setOption,
  ensureFixturePlugin,
  isPluginActive,
  ensureProviderMatrixPage,
  readProviderMatrixUrl,
  resetProviderMatrixState,
  upsertPage,
  PROVIDER_MATRIX_PAGE_SLUG,
  WP_PATH,
} from '../utils/wp-env';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { acquireSharedWordPressLock, releaseSharedWordPressLock } from '../utils/shared-wordpress-lock';
import { clickFirstVisible } from '../utils/ui';
import { assertMainThreadHealthy, installMainThreadWatchdog } from '../utils/main-thread-watchdog';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
/** Falsifiability run: hold the lab's protection-stripping switch on for the whole file. */
const BREAK_RUN = process.env.FAZ_E2E_BREAK_FAZ_EXCLUSIONS === '1';
const BREAK_STATE = BREAK_RUN ? 'yes' : 'no';
/**
 * Release gate: turn the environment skips below into failures, so a run that
 * silently lacked the plugin (or ran with workers > 1) cannot be read as green.
 */
const REQUIRE_PLUGIN = process.env.FAZ_E2E_REQUIRE_CACHE_PLUGINS === '1';
const HUMAN_UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const CRAWLER_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

const LAB_PLUGIN = 'faz-e2e-litespeed-optm';
const MATRIX_PLUGIN = 'faz-e2e-provider-matrix';
const FP_PROBE_PLUGIN = 'faz-e2e-fp-probe';
/** Deterministic Cloud Optimizer answer + fake CDN + self-host stand-ins (see the plugin header). */
const CLOUD_MOCK_PLUGIN = 'faz-e2e-fp-cloud-mock';
const CLOUD_MOCK_OPTIONS = ['faz_e2e_fp_cdn_assets', 'faz_e2e_fp_selfhost_extra', 'faz_e2e_fp_cdn_control_page', 'faz_e2e_fp_mock_last'];
const FAKE_CDN_HOST = 'cdn.faz-e2e.test';
const LAB_OPTIONS = [
  'faz_e2e_ls_guest_optm',
  'faz_e2e_ls_mark_cacheable',
  'faz_e2e_break_faz_exclusions',
  'faz_e2e_optm_probe_page',
  'faz_e2e_force_country_dependent',
  'faz_e2e_capture_errors',
  'faz_e2e_fp_preload_only',
  'faz_e2e_no_country_source',
];
/** Dedicated pages, so a background preload of one never pollutes another's cache assertion. */
const PAGES = {
  dsar: { slug: 'faz-e2e-fp-optm-dsar', title: 'FAZ FlyingPress Optimisation DSAR', content: '[faz_dsar_form]' },
  crawler: { slug: 'faz-e2e-fp-optm-crawler', title: 'FAZ FlyingPress Optimisation Crawler', content: 'FAZ FlyingPress crawler-render page.' },
  country: { slug: 'faz-e2e-fp-optm-country', title: 'FAZ FlyingPress Optimisation Country', content: 'FAZ FlyingPress country-dependent page.' },
  control: { slug: 'faz-e2e-fp-optm-control', title: 'FAZ FlyingPress Optimisation Control', content: 'FAZ FlyingPress control page.' },
} as const;
type PageKey = keyof typeof PAGES;

/** The FAZ handles the field reports were about; others found in the baseline are checked too. */
const NAMED_FAZ_HANDLES = [
  'faz-cookie-manager-static-config-js',
  'faz-cookie-manager-js-extra',
  'faz-cookie-manager-js-before',
  'faz-cookie-manager-js',
  'faz-cookie-manager-gcm-js-after',
  'faz-cookie-manager-a11y-js',
  'faz-cookie-manager-a11y-js-extra',
];
/** Present on every normal render; a baseline without them is a broken environment. */
const REQUIRED_FAZ_HANDLES = ['faz-cookie-manager-js-extra', 'faz-cookie-manager-js-before', 'faz-cookie-manager-js'];
/** Inline payloads and the global each one must still define. */
const INLINE_MARKERS: Record<string, string> = {
  'faz-cookie-manager-js-before': '_fazBootstrap',
  'faz-cookie-manager-js-extra': '_fazConfig',
  'faz-cookie-manager-a11y-js-extra': 'fazA11yConfig',
};

type FpConfig = Record<string, unknown>;
/**
 * Every FlyingPress optimisation off. Each scenario starts here and turns on
 * only what it tests; keys not listed keep the administrator's stored value.
 */
const FP_NEUTRAL: FpConfig = {
  js_delay: false,
  js_delay_method: 'user-interaction',
  js_delay_excludes: [],
  js_delay_third_party: false,
  js_delay_third_party_excludes: [],
  js_delay_selected: false,
  js_delay_selected_includes: [],
  css_js_minify: false,
  css_rucss: false,
  css_rucss_include_selectors: [],
  css_js_self_host_third_party: false,
  lazy_load: false,
  lazy_load_exclusions: [],
  properly_size_images: false,
  youtube_placeholder: false,
  self_host_gravatars: false,
  fonts_preload: false,
  fonts_optimize_google: false,
  fonts_display_swap: false,
  lazy_render: false,
  lazy_render_excludes: [],
  cache_link_prefetch: false,
  cache_mobile: false,
  cache_logged_in: false,
  cdn: false,
};
const DELAY_UI: FpConfig = { js_delay: true, js_delay_method: 'user-interaction' };
const AGGRESSIVE: FpConfig = {
  js_delay: true,
  js_delay_method: 'user-interaction',
  js_delay_third_party: true,
  css_js_minify: true,
  css_rucss: true,
  css_js_self_host_third_party: true,
  lazy_load: true,
  properly_size_images: true,
  youtube_placeholder: true,
  self_host_gravatars: true,
  fonts_preload: true,
  fonts_optimize_google: true,
  fonts_display_swap: true,
  lazy_render: true,
  cache_link_prefetch: true,
};

let fpInstalled = false;
let exclusiveRun = false;
let configuredWorkers = 0;
let lockHeld = false;
let setupDone = false;
let fpWasActive = false;
let lsWasActive = false;
let labWasActive = false;
let matrixWasActive = false;
let probeWasActive = false;
let cloudMockWasActive = false;
let auditLabWasActive = false;
let settingsSnapshot = '';
let fpConfigSnapshot = '';
const createdPages: string[] = [];
const pageUrls: Record<PageKey, string> = { dsar: '', crawler: '', country: '', control: '' };
let matrixUrl = '';
let baselineHtml = '';
let baseline: Baseline | null = null;

test.describe.configure({ mode: 'serial', timeout: 300_000 });

/* ---------------------------------------------------------------------------
 * Markup analysis
 * ------------------------------------------------------------------------ */

type Attrs = Record<string, string>;
type ScriptTag = { index: number; attrs: Attrs; content: string };
type HandleBaseline = { id: string; src: string | null; defer: boolean; async: boolean };
type BlockedBaseline = { key: string; category: string; src: string };
type Baseline = {
  handles: HandleBaseline[];
  blocked: BlockedBaseline[];
  cssKind: 'link' | 'style' | 'none';
  cssHref: string;
  verCount: number;
  /** Live sources to blocked embeds already present before any optimiser ran (the matrix's scan-only dormant tags). */
  liveParkedSources: string[];
  thumbnailCount: number;
};

function decodeEntities(value: string): string {
  return value
    .replace(/&amp;/g, '&')
    .replace(/&#0?38;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#0?39;/g, "'");
}

/** Attribute map of one tag's attribute source; tolerant of html_min's unquoted values. */
function parseAttrs(source: string): Attrs {
  const attrs: Attrs = {};
  const re = /([^\s=/>"']+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g;
  let match: RegExpExecArray | null;
  while ((match = re.exec(source)) !== null) {
    const name = match[1].toLowerCase();
    if (!(name in attrs)) {
      attrs[name] = decodeEntities(match[2] ?? match[3] ?? match[4] ?? '');
    }
  }
  return attrs;
}

function scriptTags(html: string): ScriptTag[] {
  const tags: ScriptTag[] = [];
  const re = /<script\b([^>]*)>([\s\S]*?)<\/script\s*>/gi;
  let match: RegExpExecArray | null;
  while ((match = re.exec(html)) !== null) {
    tags.push({ index: match.index, attrs: parseAttrs(match[1]), content: match[2] });
  }
  return tags;
}

function voidTags(html: string, name: 'link' | 'img' | 'iframe' | 'style'): ScriptTag[] {
  const tags: ScriptTag[] = [];
  const re = name === 'style' ? /<style\b([^>]*)>([\s\S]*?)<\/style\s*>/gi : new RegExp(`<${name}\\b([^>]*)>`, 'gi');
  let match: RegExpExecArray | null;
  while ((match = re.exec(html)) !== null) {
    tags.push({ index: match.index, attrs: parseAttrs(match[1]), content: match[2] ?? '' });
  }
  return tags;
}

function isFazId(id: string | undefined): boolean {
  return !!id && (id.startsWith('faz-cookie-manager') || id.startsWith('faz-fw'));
}

function fazScriptsById(html: string): Map<string, ScriptTag> {
  const out = new Map<string, ScriptTag>();
  for (const tag of scriptTags(html)) {
    const id = tag.attrs.id;
    if (isFazId(id) && !out.has(id)) {
      out.set(id, tag);
    }
  }
  return out;
}

function pathOf(src: string): string {
  try {
    return new URL(src, WP_BASE).pathname;
  } catch {
    return src.split(/[?#]/)[0];
  }
}

function short(value: string, max = 90): string {
  return value.length > max ? `${value.slice(0, max)}…` : value;
}

function probeKey(tag: ScriptTag): string {
  return tag.attrs['data-faz-matrix-provider'] || tag.attrs['data-faz-e2e-probe'] || '';
}

function buildBaseline(html: string): Baseline {
  const handles: HandleBaseline[] = [];
  for (const [id, tag] of fazScriptsById(html)) {
    const type = (tag.attrs.type ?? '').toLowerCase();
    if (type === 'text/template' || type === 'text/plain') {
      continue;
    }
    handles.push({
      id,
      src: tag.attrs.src ? tag.attrs.src : null,
      defer: 'defer' in tag.attrs,
      async: 'async' in tag.attrs,
    });
  }
  const blocked: BlockedBaseline[] = [];
  for (const tag of scriptTags(html)) {
    const key = probeKey(tag);
    if (key && (tag.attrs.type ?? '').toLowerCase() === 'text/plain' && tag.attrs['data-faz-category']) {
      blocked.push({ key, category: tag.attrs['data-faz-category'], src: tag.attrs.src ?? tag.attrs['data-faz-src'] ?? '' });
    }
  }
  const link = voidTags(html, 'link').find((tag) => tag.attrs.id === 'faz-cookie-manager-css-css');
  const style = voidTags(html, 'style').find((tag) => tag.attrs.id === 'faz-cookie-manager-css-inline-css');
  return {
    handles,
    blocked,
    cssKind: link ? 'link' : style ? 'style' : 'none',
    cssHref: link?.attrs.href ?? '',
    verCount: (html.match(/\b(?:src|href)=["']?[^"'\s>]*[?&](?:amp;)?ver=/gi) ?? []).length,
    liveParkedSources: liveParkedSources(html),
    thumbnailCount: thumbnailCount(html),
  };
}

const EXECUTABLE_TYPES = new Set(['', 'text/javascript', 'application/javascript', 'module']);

/** (a) every baseline FAZ handle is still a live, unmodified script. */
function fazHandleViolations(html: string, base: Baseline): string[] {
  const current = fazScriptsById(html);
  const problems: string[] = [];
  for (const expected of base.handles) {
    const tag = current.get(expected.id);
    if (!tag) {
      problems.push(`${expected.id}: missing from the optimised page (removed or combined into a bundle)`);
      continue;
    }
    const type = (tag.attrs.type ?? '').toLowerCase();
    if (!EXECUTABLE_TYPES.has(type)) {
      problems.push(`${expected.id}: type="${type}" (rewritten away from an executable script)`);
    }
    for (const attr of ['data-src', 'data-loading-method', 'data-lazyloaded', 'data-litespeed-src', 'data-optimized']) {
      if (attr in tag.attrs) {
        problems.push(`${expected.id}: carries ${attr}="${short(tag.attrs[attr], 60)}"`);
      }
    }
    const src = tag.attrs.src ?? '';
    if (expected.src) {
      if (!src) {
        problems.push(`${expected.id}: lost its src`);
      } else if (src.startsWith('data:')) {
        problems.push(`${expected.id}: src became a data: URI`);
      } else if (/\/litespeed\/(?:js|css)\//.test(src) || /\/cache\/flying-press\//.test(src)) {
        problems.push(`${expected.id}: src now points at an optimiser copy ${short(src)}`);
      } else if (src !== expected.src) {
        // Byte-for-byte: a FlyingPress ?ver=<hash> rewrite means minify did not
        // honour FAZ's exclusion, even if the path is unchanged.
        problems.push(`${expected.id}: src changed ${short(expected.src)} -> ${short(src)}`);
      }
      if (expected.defer && !('defer' in tag.attrs)) {
        problems.push(`${expected.id}: lost its defer`);
      }
      if (!expected.defer && 'defer' in tag.attrs) {
        problems.push(`${expected.id}: gained a defer it did not have`);
      }
      if (!expected.async && 'async' in tag.attrs) {
        problems.push(`${expected.id}: gained async`);
      }
    } else {
      if (src) {
        problems.push(`${expected.id}: inline script converted to src=${short(src)}`);
      }
      if ('defer' in tag.attrs || 'async' in tag.attrs) {
        problems.push(`${expected.id}: inline script gained defer/async`);
      }
      if (tag.content.trim() === '') {
        problems.push(`${expected.id}: inline body emptied`);
      }
      const marker = INLINE_MARKERS[expected.id];
      if (marker && !tag.content.includes(marker)) {
        problems.push(`${expected.id}: inline body no longer defines ${marker}`);
      }
    }
  }
  return problems;
}

/** (b) bootstrap → runtime order, and the runtime's own defer. */
function bootstrapOrderViolations(html: string): string[] {
  const tags = fazScriptsById(html);
  const extra = tags.get('faz-cookie-manager-js-extra');
  const before = tags.get('faz-cookie-manager-js-before');
  const runtime = tags.get('faz-cookie-manager-js');
  const staticConfig = tags.get('faz-cookie-manager-static-config-js');
  const problems: string[] = [];
  if (!before) problems.push('faz-cookie-manager-js-before (inline bootstrap) is missing');
  if (!runtime) problems.push('faz-cookie-manager-js (script.min.js) is missing');
  if (!before || !runtime) return problems;
  if (!before.content.includes('_fazBootstrap')) problems.push('the -js-before payload no longer defines window._fazBootstrap');
  if (before.index > runtime.index) problems.push('the inline bootstrap now comes AFTER script.min.js');
  if (extra && extra.index > before.index) problems.push('the localized _fazConfig (-js-extra) now comes after the bootstrap');
  if (staticConfig && staticConfig.index > runtime.index) problems.push('the static config script now comes after script.min.js');
  if (!/script(?:\.min)?\.js/.test(runtime.attrs.src ?? '')) problems.push(`script.min.js src is now ${short(runtime.attrs.src ?? '(none)')}`);
  if (!('defer' in runtime.attrs)) problems.push('script.min.js lost its defer');
  if ('async' in runtime.attrs) problems.push('script.min.js gained async');
  return problems;
}

/** (c) banner template and stylesheet. */
function bannerAssetViolations(html: string, base: Baseline): string[] {
  const problems: string[] = [];
  const template = scriptTags(html).find((tag) => tag.attrs.id === 'fazBannerTemplate');
  if (!template) {
    problems.push('script#fazBannerTemplate is missing');
  } else {
    if ((template.attrs.type ?? '').toLowerCase() !== 'text/template') problems.push(`fazBannerTemplate type is "${template.attrs.type ?? ''}"`);
    if (!template.content.includes('data-faz-tag')) problems.push('fazBannerTemplate lost its data-faz-tag markup');
  }
  if (base.cssKind === 'link') {
    const link = voidTags(html, 'link').find((tag) => tag.attrs.id === 'faz-cookie-manager-css-css');
    if (!link) {
      problems.push('link#faz-cookie-manager-css-css (banner stylesheet) is missing — combined or removed');
    } else {
      const href = link.attrs.href ?? '';
      if ((link.attrs.rel ?? '').toLowerCase() !== 'stylesheet') problems.push(`banner stylesheet rel="${link.attrs.rel ?? ''}" (deferred/preloaded instead of applied)`);
      if (!href) problems.push('banner stylesheet lost its href');
      // FAZ registers no FlyingPress CSS exclusion, and a minified copy of the
      // banner stylesheet in FlyingPress's cache dir is still the banner
      // stylesheet. What must not happen is losing it or deferring it.
      const fpCopy = /\/cache\/flying-press\//.test(href);
      if (href && !fpCopy && pathOf(href) !== pathOf(base.cssHref)) problems.push(`banner stylesheet path changed -> ${short(pathOf(href))}`);
      for (const attr of ['data-href', 'data-lazy-href', 'media']) {
        if (attr in link.attrs && !(attr === 'media' && /^(?:all|screen)?$/i.test(link.attrs.media))) {
          problems.push(`banner stylesheet carries ${attr}="${short(link.attrs[attr], 40)}"`);
        }
      }
    }
  } else if (base.cssKind === 'style') {
    const style = voidTags(html, 'style').find((tag) => tag.attrs.id === 'faz-cookie-manager-css-inline-css');
    if (!style || style.content.trim() === '') problems.push('inline banner stylesheet is missing or emptied');
  }
  return problems;
}

/** (d) server-blocked third-party scripts stay inert. */
function blockedScriptViolations(html: string, base: Baseline): string[] {
  const problems: string[] = [];
  const all = scriptTags(html);
  const byKey = new Map<string, ScriptTag>();
  for (const tag of all) {
    const key = probeKey(tag);
    if (key && !byKey.has(key)) byKey.set(key, tag);
  }
  for (const expected of base.blocked) {
    const tag = byKey.get(expected.key);
    if (!tag) {
      problems.push(`blocked "${expected.key}": tag is gone (removed or combined)`);
      continue;
    }
    const type = (tag.attrs.type ?? '').toLowerCase();
    if (type !== 'text/plain') problems.push(`blocked "${expected.key}": type is now "${type}" — executable`);
    if (tag.attrs['data-faz-category'] !== expected.category) {
      problems.push(`blocked "${expected.key}": data-faz-category "${expected.category}" -> "${tag.attrs['data-faz-category'] ?? '(none)'}"`);
    }
    for (const attr of ['data-src', 'data-loading-method', 'data-lazyloaded', 'data-litespeed-src']) {
      if (attr in tag.attrs) problems.push(`blocked "${expected.key}": carries ${attr}`);
    }
    if ((tag.attrs.src ?? '').startsWith('data:')) problems.push(`blocked "${expected.key}": src became a data: URI`);
    if (expected.src) {
      const blockedPath = pathOf(expected.src);
      for (const other of all) {
        if (other === tag) continue;
        const otherType = (other.attrs.type ?? '').toLowerCase();
        if (otherType === 'text/plain' || otherType === 'text/template') continue;
        const candidates = [other.attrs.src, other.attrs['data-src'], other.attrs['data-origin-src']].filter(Boolean) as string[];
        if (candidates.some((value) => pathOf(value) === blockedPath)) {
          problems.push(`blocked "${expected.key}": an executable copy of ${short(blockedPath)} exists`);
        }
      }
    }
  }
  return problems;
}

const PARKED_TARGET = /(?:youtube(?:-nocookie)?\.com\/embed|facebook\.com\/tr)/i;

function withoutScripts(html: string): string {
  return html.replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '');
}

/** Every attribute that would make the browser load a blocked embed, as `attr=value`. */
function liveParkedSources(html: string): string[] {
  const found: string[] = [];
  const live = /\s(src|data-src|data-lazy-src|data-litespeed-src|data-origin-src|srcdoc)\s*=\s*["']?([^"'\s>]*)/gi;
  let match: RegExpExecArray | null;
  const body = withoutScripts(html);
  while ((match = live.exec(body)) !== null) {
    const value = decodeEntities(match[2]);
    if (PARKED_TARGET.test(value)) {
      // data-src / data-lazy-src / data-litespeed-src / data-origin-src are all
      // dormant: nothing loads from them until a script copies them into src.
      // A lazy loader renaming one into another (FlyingPress turns a third-party
      // data-src into data-lazy-src) is the same dormant URL, not a new live
      // one; whether a script later makes it live is a runtime question, and
      // the browser scroll check in tests 12–13 answers it.
      const attr = match[1].toLowerCase();
      const kind = attr === 'src' || attr === 'srcdoc' ? attr : 'dormant';
      found.push(`${kind}=${value}`);
    }
  }
  return found;
}

function thumbnailCount(html: string): number {
  return (withoutScripts(html).match(/i\.ytimg\.com|flying-press-youtube/gi) ?? []).length;
}

/**
 * (e) parked embeds keep data-faz-src and gain no live source.
 *
 * Baseline-relative on purpose: the provider-matrix fixture prints scan-only
 * dormant tags (`<iframe data-src="…youtube.com/embed/…">`) that exist before
 * any optimiser runs. What must never happen is an optimiser ADDING a live
 * source to a blocked embed.
 */
function parkedEmbedViolations(html: string, base: Baseline): string[] {
  const problems: string[] = [];
  const remaining = [...base.liveParkedSources];
  for (const entry of liveParkedSources(html)) {
    const at = remaining.indexOf(entry);
    if (at >= 0) {
      remaining.splice(at, 1);
    } else {
      problems.push(`a live ${short(entry)} now points at a blocked provider`);
    }
  }
  if (thumbnailCount(html) > base.thumbnailCount) {
    problems.push('a YouTube thumbnail / placeholder was injected for the blocked embed');
  }
  const body = withoutScripts(html);
  if (!/data-faz-src\s*=\s*["']?[^"'\s>]*youtube(?:-nocookie)?\.com\/embed/i.test(body)) {
    problems.push('the parked YouTube iframe lost its data-faz-src');
  }
  if (!/data-faz-src\s*=\s*["']?[^"'\s>]*facebook\.com\/tr/i.test(body)) {
    problems.push('the parked Meta pixel lost its data-faz-src');
  }
  return problems;
}

function fullContractViolations(html: string, base: Baseline): string[] {
  return [
    ...fazHandleViolations(html, base),
    ...bootstrapOrderViolations(html),
    ...bannerAssetViolations(html, base),
    ...blockedScriptViolations(html, base),
    ...parkedEmbedViolations(html, base),
  ];
}

function controlTag(html: string, kind: 'img' | 'iframe'): ScriptTag | undefined {
  return voidTags(html, kind).find((tag) => tag.attrs['data-faz-e2e-probe'] === `control-${kind}`);
}


/* ---------------------------------------------------------------------------
 * Environment helpers
 * ------------------------------------------------------------------------ */

function pluginInstalled(slug: string): boolean {
  try {
    wp(['plugin', 'is-installed', slug]);
    return true;
  } catch {
    return false;
  }
}

/** class_exists() probe — true only once FlyingPress is actually loaded. */
function fpLoaded(): boolean {
  try {
    return wpEval(`echo ( class_exists( '\\\\FlyingPress\\\\Config' ) && class_exists( '\\\\FlyingPress\\\\Purge' ) ) ? '1' : '0';`).trim() === '1';
  } catch {
    return false;
  }
}

function fpPurge(): void {
  wpEval(`if ( class_exists( '\\\\FlyingPress\\\\Purge' ) ) { \\FlyingPress\\Purge::purge_everything(); }`);
}

/** FP_NEUTRAL + patch over the stored config, then a full purge so the next render is fresh. */
function applyFp(patch: FpConfig): void {
  const b64 = Buffer.from(JSON.stringify({ ...FP_NEUTRAL, ...patch }), 'utf8').toString('base64');
  wpEval(`
    $current = get_option( 'FLYING_PRESS_CONFIG', array() );
    $patch   = json_decode( base64_decode( '${b64}' ), true );
    update_option( 'FLYING_PRESS_CONFIG', array_merge( is_array( $current ) ? $current : array(), is_array( $patch ) ? $patch : array() ) );
    if ( class_exists( '\\\\FlyingPress\\\\Purge' ) ) { \\FlyingPress\\Purge::purge_everything(); }
  `);
}

function storedDelayExcludes(): unknown {
  const raw = wpEval(`
    $opt = get_option( 'FLYING_PRESS_CONFIG' );
    echo wp_json_encode( ( is_array( $opt ) && isset( $opt['js_delay_excludes'] ) ) ? $opt['js_delay_excludes'] : null );
  `).trim();
  return JSON.parse(raw || 'null') as unknown;
}

function clearFpQueue(): void {
  wpEval(`
    global $wpdb;
    $t = $wpdb->prefix . 'flying_press_queue';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
      $wpdb->query( "DELETE FROM {$t}" );
    }
  `);
}

function patchFazSettings(php: string): void {
  wpEval(`
    $s = get_option( 'faz_settings', array() );
    if ( ! is_array( $s ) ) { $s = array(); }
    if ( ! isset( $s['geolocation'] ) || ! is_array( $s['geolocation'] ) ) { $s['geolocation'] = array(); }
    if ( ! isset( $s['banner_control'] ) || ! is_array( $s['banner_control'] ) ) { $s['banner_control'] = array(); }
    ${php}
    update_option( 'faz_settings', $s );
    if ( class_exists( '\\\\FazCookie\\\\Includes\\\\Cache' ) ) { \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' ); }
  `);
}

function decodeBody(body: Buffer): string {
  if (body.length > 2 && body[0] === 0x1f && body[1] === 0x8b) {
    return gunzipSync(body).toString('utf8');
  }
  return body.toString('utf8');
}

type Fetched = { status: number; headers: Record<string, string>; html: string };

/** One request from a cookie-less client, so FlyingPress always sees an anonymous visitor. */
async function fetchPage(url: string, init: { userAgent?: string; headers?: Record<string, string> } = {}): Promise<Fetched> {
  const ctx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
  try {
    const resp = await ctx.get(url, {
      headers: { 'User-Agent': init.userAgent ?? HUMAN_UA, ...(init.headers ?? {}) },
      timeout: 90_000,
    });
    return { status: resp.status(), headers: resp.headers(), html: decodeBody(await resp.body()) };
  } finally {
    await ctx.dispose();
  }
}

function cacheState(page: Fetched): string {
  return (page.headers['x-flying-press-cache'] ?? '').toUpperCase();
}

/** The optimised render, exactly as FlyingPress's preloader requests it. */
async function fetchPreload(url: string): Promise<Fetched> {
  const page = await fetchPage(url, { headers: { 'x-flying-press-preload': '1' } });
  expect(page.status, `FlyingPress optimisation of ${url} must succeed (a 503 means its Cloud Optimizer call failed)`).toBe(200);
  return page;
}

/**
 * Request until advanced-cache.php serves a HIT. FlyingPress writes the file
 * on shutdown, after the response has left, so it is never immediate; the
 * error names the last state seen because MISS (impatient), BYPASS (vetoed)
 * and a missing header (FlyingPress not serving) are three different bugs.
 */
async function primeHit(url: string): Promise<Fetched> {
  let last: Fetched | null = null;
  for (let i = 0; i < 40; i += 1) {
    last = await fetchPage(url);
    if (cacheState(last) === 'HIT') {
      return last;
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`FlyingPress never served a HIT for ${url} within 20s; last x-flying-press-cache was "${last ? cacheState(last) || '(absent)' : '(no response)'}"`);
}

type Copies = { miss: Fetched; preload: Fetched; hit: Fetched };

/** MISS → preload render → HIT for one URL, starting from a purged cache. */
async function fpCopies(url: string): Promise<Copies> {
  const miss = await fetchPage(url);
  expect(miss.status, `${url} must render 200`).toBe(200);
  expect(cacheState(miss), 'the first anonymous request after a purge must be a FlyingPress MISS').toBe('MISS');
  const preload = await fetchPreload(url);
  const hit = await primeHit(url);
  return { miss, preload, hit };
}

/** Locate wp-content/cache/flying-press/<host>/<slug>/index.html.gz, whatever the host directory is called. */
function findCacheFile(slug: string): string | null {
  const root = join(WP_PATH, 'wp-content', 'cache', 'flying-press');
  if (!existsSync(root)) {
    return null;
  }
  const stack = [root];
  while (stack.length > 0) {
    const dir = stack.pop() as string;
    let entries: string[] = [];
    try {
      entries = readdirSync(dir);
    } catch {
      continue;
    }
    for (const entry of entries) {
      const full = join(dir, entry);
      let isDir = false;
      try {
        isDir = statSync(full).isDirectory();
      } catch {
        continue;
      }
      if (isDir) {
        if (entry === slug && existsSync(join(full, 'index.html.gz'))) {
          return join(full, 'index.html.gz');
        }
        stack.push(full);
      }
    }
  }
  return null;
}

function readCacheFile(file: string): string {
  return gunzipSync(readFileSync(file)).toString('utf8');
}

/** Sample for `ms` and fail if a cache file for `slug` ever appears. */
async function expectNeverCached(slug: string, ms: number, label: string): Promise<void> {
  const deadline = Date.now() + ms;
  while (Date.now() < deadline) {
    const file = findCacheFile(slug);
    expect(file, `${label}: FlyingPress wrote a cached copy (${file ?? ''})`).toBeNull();
    await new Promise((resolve) => setTimeout(resolve, 1_000));
  }
}

function requireBaseline(): Baseline {
  if (!baseline) {
    throw new Error('baseline render was not captured in beforeAll');
  }
  return baseline;
}

function readProbe(token: string): Record<string, string> {
  const raw = wpEval(`echo wp_json_encode( get_option( 'faz_e2e_optm_probe_${token}', array() ) );`).trim();
  const parsed = JSON.parse(raw || '{}') as unknown;
  return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as Record<string, string>) : {};
}

/** A request whose FlyingPress cacheability verdict the lab records (shutdown always writes `donotcachepage`). */
async function probedRequest(url: string, token: string, init: { userAgent?: string; preload?: boolean } = {}): Promise<Fetched> {
  const headers: Record<string, string> = { 'X-Faz-E2E-Probe': token };
  if (init.preload) headers['x-flying-press-preload'] = '1';
  const response = await fetchPage(url, { userAgent: init.userAgent, headers });
  if (cacheState(response) === 'HIT') {
    // Served by advanced-cache.php: WordPress never ran, so there is no verdict to wait for.
    return response;
  }
  await expect
    .poll(() => readProbe(token).donotcachepage ?? '', { timeout: 10_000, message: `the lab never recorded a verdict for ${token}` })
    .not.toBe('');
  return response;
}

function newToken(label: string): string {
  return `fp-${label}-${Date.now().toString(36)}`;
}

function readCapturedLog(): string {
  const raw = wpEval(`
    $f = WP_CONTENT_DIR . '/faz-e2e-optm-php.log';
    echo is_readable( $f ) ? base64_encode( (string) file_get_contents( $f ) ) : '';
  `).trim();
  return raw ? Buffer.from(raw, 'base64').toString('utf8') : '';
}

function normaliseCss(text: string): string {
  return text.replace(/\s+/g, ' ').replace(/\s*([{},>+~])\s*/g, '$1').trim();
}

/** Every selector of the FAZ banner stylesheet that names FAZ (contains "faz"), as written. */
async function fazBannerSelectors(href: string): Promise<string[]> {
  const url = new URL(decodeEntities(href), WP_BASE).toString();
  const css = (await fetchPage(url)).html.replace(/\/\*[\s\S]*?\*\//g, '');
  const out = new Set<string>();
  const re = /([^{}]+)\{([^{}]*)\}/g;
  let match: RegExpExecArray | null;
  while ((match = re.exec(css)) !== null) {
    const selector = match[1].trim();
    if (selector && !selector.startsWith('@') && /faz/i.test(selector)) out.add(selector);
  }
  return [...out];
}

function annotate(type: string, description: string): void {
  test.info().annotations.push({ type, description });
}

/* ---------------------------------------------------------------------------
 * Browser flow (f)
 * ------------------------------------------------------------------------ */

// Matched against the request HOST only. The provider-matrix fixture serves its
// same-origin copies at paths like /faz-e2e-provider-script/connect.facebook.net/…,
// and a regex over the whole URL aborted those too, so the restored script the
// test waits for could never load.
const THIRD_PARTY_HOST = /(?:^|\.)(?:googletagmanager\.com|google-analytics\.com|facebook\.com|facebook\.net|youtube\.com|youtube-nocookie\.com|ytimg\.com)$/i;
const THIRD_PARTY = (url: URL): boolean => THIRD_PARTY_HOST.test(url.hostname);

/**
 * The fake CDN host only exists in the PHP fixture's world. In a browser it is
 * answered here: the control library gets a stand-in, every other path (FAZ's
 * own assets, when faz_e2e_fp_cdn_assets moves them there) is proxied to the
 * real site, the way a pull CDN would serve it.
 */
async function routeFakeCdn(context: BrowserContext): Promise<void> {
  await context.route(
    (url) => url.hostname === FAKE_CDN_HOST,
    async (route) => {
      const url = new URL(route.request().url());
      if (url.pathname.startsWith('/faz-e2e/')) {
        await route.fulfill({ status: 200, contentType: 'application/javascript', body: 'window.__fazE2eCdnControl = true;' });
        return;
      }
      const origin = new URL(WP_BASE);
      url.protocol = origin.protocol;
      url.host = origin.host;
      await route.fulfill({ response: await route.fetch({ url: url.toString() }) });
    },
  );
}

/** A visitor context: watchdog installed before the first navigation, fake CDN answered. */
async function visitorContext(browser: Browser): Promise<BrowserContext> {
  const context = await browser.newContext({ baseURL: WP_BASE });
  await installMainThreadWatchdog(context);
  await routeFakeCdn(context);
  return context;
}

function restoreProbeKey(base: Baseline): string {
  const preferred = ['facebook-pixel', 'ga-monsterinsights', 'hotjar', 'linkedin', 'tiktok', 'hubspot', 'clarity', 'microsoft-ads'];
  const blockedKeys = new Set(base.blocked.map((entry) => entry.key));
  const pick = preferred.find((key) => blockedKeys.has(key));
  if (!pick) {
    throw new Error(`no same-origin matrix provider was blocked in the baseline (blocked: ${[...blockedKeys].join(', ') || 'none'})`);
  }
  return pick;
}

/**
 * A visitor who scrolls the whole cached page and never answers the banner must
 * cause no request to a blocked provider. Lazy loaders make dormant embeds live
 * as they enter the viewport (FlyingPress copies data-lazy-src into src), so
 * this is the check that matters for them: the HTML only shows a dormant URL,
 * and FAZ's runtime is what has to park it when the loader assigns it.
 */
async function scrollWithoutConsent(browser: Browser, url: string, label: string): Promise<void> {
  const context: BrowserContext = await visitorContext(browser);
  const blocked: string[] = [];
  try {
    await context.route(THIRD_PARTY, async (route) => {
      blocked.push(route.request().url());
      await route.abort();
    });
    const page = await context.newPage();
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    expect((response?.headers()['x-flying-press-cache'] ?? '').toUpperCase(), `${label}: the visitor must be served the cached copy`).toBe('HIT');
    await expect(page.locator('[data-faz-tag="notice"]').first(), `${label}: banner must be on screen`).toBeVisible({ timeout: 20_000 });
    // Real wheel input as well as programmatic scrolling: wheel is one of the
    // interaction events that also releases FlyingPress's delayed scripts.
    await page.mouse.move(200, 200);
    for (let i = 0; i < 12; i += 1) {
      await page.mouse.wheel(0, 800);
      await page.waitForTimeout(150);
    }
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await page.waitForTimeout(3_000);
    const liveEmbeds = await page.evaluate(() =>
      Array.from(document.querySelectorAll('iframe'))
        .map((el) => el.getAttribute('src') || '')
        .filter((src) => /youtube(?:-nocookie)?\.com\/embed|facebook\.com\/tr/i.test(src)),
    );
    expect(liveEmbeds, `${label}: no blocked embed may carry a live src after scrolling without consent`).toEqual([]);
    expect(blocked, `${label}: no request may reach a blocked provider before consent`).toEqual([]);
    const consent = (await context.cookies(WP_BASE)).find((cookie) => cookie.name === 'fazcookie-consent');
    expect(consent, `${label}: scrolling is not consent`).toBeUndefined();
  } finally {
    await context.close();
  }
}

async function visitorFlow(browser: Browser, url: string, label: string, base: Baseline, expectHit = false): Promise<void> {
  const provider = restoreProbeKey(base);
  const context: BrowserContext = await visitorContext(browser);
  const thirdPartyBeforeConsent: string[] = [];
  let consented = false;
  try {
    await context.route(THIRD_PARTY, async (route) => {
      if (!consented) thirdPartyBeforeConsent.push(route.request().url());
      await route.abort();
    });
    const page = await context.newPage();
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    if (expectHit) {
      expect((response?.headers()['x-flying-press-cache'] ?? '').toUpperCase(), `${label}: the visitor must be served the cached copy`).toBe('HIT');
    }

    // No mouse, key, scroll or touch happens before these assertions: a
    // runtime held until interaction can only fail them.
    await expect(page.locator('[data-faz-tag="notice"]').first(), `${label}: banner must appear without any user interaction`).toBeVisible({
      timeout: 20_000,
    });
    await page.waitForFunction(() => typeof (window as unknown as { fazcookie?: unknown }).fazcookie === 'object', undefined, { timeout: 20_000 });
    await assertMainThreadHealthy(page, `${label} (before consent)`);

    const consentBefore = (await context.cookies(WP_BASE)).find((cookie) => cookie.name === 'fazcookie-consent');
    expect(consentBefore, `${label}: no consent cookie before a choice`).toBeUndefined();
    const ranEarly = await page.evaluate(
      (key) => !!((window as unknown as { __fazProviderMatrixLoaded?: Record<string, boolean> }).__fazProviderMatrixLoaded ?? {})[key],
      provider,
    );
    expect(ranEarly, `${label}: blocked provider "${provider}" executed before consent`).toBe(false);
    expect(thirdPartyBeforeConsent, `${label}: third-party requests fired before consent`).toEqual([]);

    consented = true;
    const clicked = await clickFirstVisible(page, ['[data-faz-tag="accept-button"] button', '[data-faz-tag="accept-button"]', '.faz-btn-accept'], 5_000);
    expect(clicked, `${label}: an accept button must be clickable`).toBe(true);

    await expect
      .poll(async () => (await context.cookies(WP_BASE)).some((cookie) => cookie.name === 'fazcookie-consent'), {
        timeout: 15_000,
        message: `${label}: accepting must set the fazcookie-consent cookie`,
      })
      .toBe(true);
    await expect
      .poll(
        () =>
          page.evaluate(
            (key) => !!((window as unknown as { __fazProviderMatrixLoaded?: Record<string, boolean> }).__fazProviderMatrixLoaded ?? {})[key],
            provider,
          ),
        { timeout: 20_000, message: `${label}: the blocked "${provider}" script must run once consent is given` },
      )
      .toBe(true);
    await assertMainThreadHealthy(page, `${label} (after consent)`);
  } finally {
    await context.close();
  }
}


/* ---------------------------------------------------------------------------
 * Lifecycle
 * ------------------------------------------------------------------------ */

test.beforeAll(async ({}, testInfo) => {
  testInfo.setTimeout(41 * 60_000);
  configuredWorkers = testInfo.config.workers;
  exclusiveRun = configuredWorkers === 1;
  if (!exclusiveRun) {
    return;
  }
  await acquireSharedWordPressLock();
  lockHeld = true;

  fpInstalled = pluginInstalled('flying-press');
  if (!fpInstalled) {
    return;
  }

  fpWasActive = isPluginActive('flying-press');
  lsWasActive = isPluginActive('litespeed-cache');
  labWasActive = isPluginActive(LAB_PLUGIN);
  matrixWasActive = isPluginActive(MATRIX_PLUGIN);
  probeWasActive = isPluginActive(FP_PROBE_PLUGIN);
  cloudMockWasActive = isPluginActive(CLOUD_MOCK_PLUGIN);
  auditLabWasActive = isPluginActive('faz-e2e-audit-lab');
  settingsSnapshot = wpEval(`echo base64_encode( serialize( get_option( 'faz_settings', array() ) ) );`).trim();
  // Taken before activation: loading FlyingPress can rewrite its own option
  // (migration, licence block), and the restore must give back what the suite
  // had, not what activation produced.
  fpConfigSnapshot = wpEval(`echo base64_encode( serialize( get_option( 'FLYING_PRESS_CONFIG', null ) ) );`).trim();

  // Two page optimisers on one request would make every verdict ambiguous;
  // audit-lab pins the jurisdiction runtime on, which makes every page
  // country-dependent and turns the control page into a veto.
  if (lsWasActive) wp(['plugin', 'deactivate', 'litespeed-cache']);
  if (auditLabWasActive) wp(['plugin', 'deactivate', 'faz-e2e-audit-lab']);
  if (!fpWasActive) wp(['plugin', 'activate', 'flying-press']);
  setupDone = true;
  if (!fpLoaded()) {
    throw new Error('flying-press is installed and was activated, but \\FlyingPress\\Config / \\FlyingPress\\Purge are not loaded');
  }

  resetDefaultBannerState();
  // Geo-Targeting OFF + Cache Compatibility Mode ON is the state in which an
  // ordinary page is visitor-invariant and FlyingPress may cache it.
  patchFazSettings(`
    $s['geolocation']['geo_targeting'] = false;
    $s['banner_control']['cache_compatibility'] = true;
  `);

  ensureFixturePlugin(MATRIX_PLUGIN);
  ensureFixturePlugin(LAB_PLUGIN);
  ensureFixturePlugin(FP_PROBE_PLUGIN);
  ensureFixturePlugin(CLOUD_MOCK_PLUGIN);
  ensureProviderMatrixPage();
  resetProviderMatrixState();
  matrixUrl = readProviderMatrixUrl().trim();
  for (const key of Object.keys(PAGES) as PageKey[]) {
    const { slug, title, content } = PAGES[key];
    if (wpEval(`echo get_page_by_path( '${slug}', OBJECT, 'page' ) ? '0' : '1';`).trim() === '1') {
      createdPages.push(slug);
    }
    const id = upsertPage(slug, title, content);
    pageUrls[key] = wpEval(`echo get_permalink( ${id} );`).trim();
  }

  setOption('faz_e2e_optm_probe_page', PROVIDER_MATRIX_PAGE_SLUG);
  setOption('faz_e2e_capture_errors', 'yes');
  setOption('faz_e2e_break_faz_exclusions', BREAK_STATE);
  setOption('faz_e2e_no_country_source', 'no');
  setOption('faz_e2e_force_country_dependent', 'no');
  setOption('faz_e2e_fp_cdn_assets', 'no');
  setOption('faz_e2e_fp_selfhost_extra', 'yes');
  setOption('faz_e2e_fp_cdn_control_page', PROVIDER_MATRIX_PAGE_SLUG);
  wpEval(`delete_option( 'faz_e2e_fp_mock_last' );`);
  // Every page this file uses, and nothing else: `wp flying-press
  // preload-cache` and the preload a MISS schedules then warm only these.
  setOption('faz_e2e_fp_preload_only', JSON.stringify([matrixUrl, ...Object.values(pageUrls)]));
  wpEval(`@unlink( WP_CONTENT_DIR . '/faz-e2e-optm-php.log' );`);
  clearFpQueue();

  applyFp({});
  baselineHtml = (await fetchPreload(matrixUrl)).html;
  baseline = buildBaseline(baselineHtml);
});

test.afterAll(() => {
  const errors: Error[] = [];
  const cleanup = (label: string, action: () => void): void => {
    try {
      action();
    } catch (error) {
      errors.push(new Error(`${label}: ${error instanceof Error ? error.message : String(error)}`));
    }
  };
  try {
    if (setupDone) {
      if (fpLoaded()) {
        cleanup('purge FlyingPress', fpPurge);
        cleanup('empty the FlyingPress preload queue', clearFpQueue);
      }
      cleanup('remove lab options and probe records', () => {
        wpEval(`
          global $wpdb;
          foreach ( (array) json_decode( '${JSON.stringify([...LAB_OPTIONS, ...CLOUD_MOCK_OPTIONS])}', true ) as $name ) { delete_option( $name ); }
          $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'faz\\\\_e2e\\\\_optm\\\\_probe\\\\_%'" );
          @unlink( WP_CONTENT_DIR . '/faz-e2e-optm-php.log' );
        `);
      });
      if (settingsSnapshot) {
        cleanup('restore FAZ settings', () => {
          wpEval(`
            $s = unserialize( base64_decode( '${settingsSnapshot}' ) );
            update_option( 'faz_settings', is_array( $s ) ? $s : array() );
            if ( class_exists( '\\\\FazCookie\\\\Includes\\\\Cache' ) ) { \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' ); }
          `);
        });
      }
      cleanup('reset provider matrix state', () => resetProviderMatrixState());
      for (const slug of createdPages) {
        cleanup(`delete page ${slug}`, () => {
          wpEval(`$p = get_page_by_path( '${slug}', OBJECT, 'page' ); if ( $p ) { wp_delete_post( $p->ID, true ); }`);
        });
      }
      if (!labWasActive && isPluginActive(LAB_PLUGIN)) cleanup('deactivate the lab fixture', () => wp(['plugin', 'deactivate', LAB_PLUGIN]));
      if (!matrixWasActive && isPluginActive(MATRIX_PLUGIN)) cleanup('deactivate the provider matrix', () => wp(['plugin', 'deactivate', MATRIX_PLUGIN]));
      if (!probeWasActive && isPluginActive(FP_PROBE_PLUGIN)) cleanup('deactivate the FlyingPress probe', () => wp(['plugin', 'deactivate', FP_PROBE_PLUGIN]));
      if (!cloudMockWasActive && isPluginActive(CLOUD_MOCK_PLUGIN)) cleanup('deactivate the Cloud Optimizer mock', () => wp(['plugin', 'deactivate', CLOUD_MOCK_PLUGIN]));
      // Unconditional: FlyingPress's page cache serves stale HTML to whatever
      // spec runs next. Deactivated BEFORE the config restore, because every
      // load of the plugin may rewrite its own option.
      if (isPluginActive('flying-press')) cleanup('deactivate FlyingPress', () => wp(['plugin', 'deactivate', 'flying-press']));
      if (fpConfigSnapshot) {
        cleanup('restore FLYING_PRESS_CONFIG exactly', () => {
          wpEval(`
            $v = unserialize( base64_decode( '${fpConfigSnapshot}' ) );
            if ( null === $v ) { delete_option( 'FLYING_PRESS_CONFIG' ); } else { update_option( 'FLYING_PRESS_CONFIG', $v ); }
          `);
        });
      }
      if (auditLabWasActive && !isPluginActive('faz-e2e-audit-lab')) cleanup('reactivate audit-lab', () => wp(['plugin', 'activate', 'faz-e2e-audit-lab']));
      if (lsWasActive && !isPluginActive('litespeed-cache')) cleanup('reactivate LiteSpeed Cache', () => wp(['plugin', 'activate', 'litespeed-cache']));
    }
  } finally {
    if (lockHeld) {
      releaseSharedWordPressLock();
      lockHeld = false;
    }
  }
  if (errors.length > 0) {
    throw new AggregateError(errors, 'FlyingPress optimisation E2E cleanup failed');
  }
});

test.beforeEach(() => {
  if (REQUIRE_PLUGIN) {
    expect(exclusiveRun, `FAZ_E2E_REQUIRE_CACHE_PLUGINS=1: this file must run with --workers=1 (got ${String(configuredWorkers)})`).toBe(true);
    expect(fpInstalled, 'FAZ_E2E_REQUIRE_CACHE_PLUGINS=1: FlyingPress (flying-press) must be installed on this environment').toBe(true);
  }
  test.skip(
    !exclusiveRun,
    `this file was run with workers=${String(configuredWorkers)} — it activates FlyingPress and rewrites FLYING_PRESS_CONFIG, ` +
      'so it needs the install to itself. Run `npm run test:e2e:flyingpress-optm` (adds --workers=1).',
  );
  test.skip(!fpInstalled, 'FlyingPress (flying-press) is not installed on this environment');
});

/* ---------------------------------------------------------------------------
 * Tests
 * ------------------------------------------------------------------------ */

const DELAYED_UI = /data-loading-method=["']?user-interaction/;

test.describe('FlyingPress page optimisation leaves FAZ intact', () => {
  test('01 baseline (every optimisation off): FAZ inventory, bootstrap order, blocked probes and parked embeds are present, and the page reaches a HIT', async () => {
    const base = requireBaseline();
    const ids = base.handles.map((handle) => handle.id);
    for (const id of REQUIRED_FAZ_HANDLES) {
      expect(ids, `baseline must contain ${id}`).toContain(id);
    }
    annotate('faz-handles', `named handles present: ${NAMED_FAZ_HANDLES.filter((id) => ids.includes(id)).join(', ')}; all: ${ids.join(', ')}`);
    expect(base.cssKind, 'the banner stylesheet must be in the baseline (link or inline style)').not.toBe('none');
    expect(bootstrapOrderViolations(baselineHtml)).toEqual([]);
    expect(bannerAssetViolations(baselineHtml, base)).toEqual([]);
    expect(base.blocked.map((entry) => entry.key), 'the Google tag probe must be blocked server-side before consent').toContain('gtag');
    expect(() => restoreProbeKey(base)).not.toThrow();
    expect(parkedEmbedViolations(baselineHtml, base)).toEqual([]);
    expect(baselineHtml, 'with js_delay off no script may carry data-loading-method').not.toMatch(/data-loading-method=/);
    const hit = await primeHit(matrixUrl);
    expect(fullContractViolations(hit.html, base)).toEqual([]);
  });

  test('02 delay user-interaction, MISS copy: FAZ handles are untouched on the uncached response', async () => {
    const base = requireBaseline();
    applyFp(DELAY_UI);
    const { miss } = await fpCopies(matrixUrl);
    expect(fazHandleViolations(miss.html, base)).toEqual([]);
    expect(bootstrapOrderViolations(miss.html)).toEqual([]);
    expect(blockedScriptViolations(miss.html, base)).toEqual([]);
  });

  test('03 delay user-interaction, preload copy: no FAZ handle gets data-loading-method or data-src, and the bootstrap still precedes script.min.js', async () => {
    const base = requireBaseline();
    applyFp(DELAY_UI);
    const { preload } = await fpCopies(matrixUrl);
    expect(preload.html, 'positive control: at least one non-FAZ script must be delayed until interaction').toMatch(DELAYED_UI);
    expect(fazHandleViolations(preload.html, base)).toEqual([]);
    expect(bootstrapOrderViolations(preload.html)).toEqual([]);
    expect(blockedScriptViolations(preload.html, base)).toEqual([]);
  });

  test('04 delay user-interaction, HIT copy: the cached page visitors get keeps every FAZ handle live and every blocked script blocked', async () => {
    const base = requireBaseline();
    applyFp(DELAY_UI);
    const { hit } = await fpCopies(matrixUrl);
    expect(hit.html, 'positive control: the cached copy must carry FlyingPress delays').toMatch(DELAYED_UI);
    expect(fullContractViolations(hit.html, base)).toEqual([]);
  });

  test('05 delay user-interaction, `wp flying-press preload-cache` copy on disk: FAZ handles live, bootstrap order, blocked scripts blocked', async () => {
    const base = requireBaseline();
    applyFp(DELAY_UI);
    clearFpQueue();
    expect(findCacheFile(PROVIDER_MATRIX_PAGE_SLUG), 'the purge must have removed the cached matrix page').toBeNull();
    wp(['flying-press', 'preload-cache']);
    await expect
      .poll(() => findCacheFile(PROVIDER_MATRIX_PAGE_SLUG), {
        timeout: 120_000,
        intervals: [1_000],
        message: 'the FlyingPress preloader never wrote the matrix page to wp-content/cache/flying-press',
      })
      .not.toBeNull();
    const file = findCacheFile(PROVIDER_MATRIX_PAGE_SLUG) as string;
    // The preloader writes the file in one call, but give a concurrent writer
    // a moment so the gunzip never reads a half-written stream.
    await new Promise((resolve) => setTimeout(resolve, 1_000));
    const html = readCacheFile(file);
    expect(html, 'positive control: the preloaded copy must carry FlyingPress delays').toMatch(DELAYED_UI);
    expect(fullContractViolations(html, base)).toEqual([]);
  });

  test('06 delay method defer, HIT copy: FAZ inline scripts are not converted to data:text/javascript defers', async () => {
    const base = requireBaseline();
    applyFp({ js_delay: true, js_delay_method: 'defer' });
    const { preload, hit } = await fpCopies(matrixUrl);
    expect(hit.html, 'positive control: defer mode must convert at least one non-FAZ inline script').toMatch(/data:text\/javascript,/);
    expect(fazHandleViolations(hit.html, base)).toEqual([]);
    expect(bootstrapOrderViolations(hit.html)).toEqual([]);
    expect(fazHandleViolations(preload.html, base)).toEqual([]);
    expect(blockedScriptViolations(hit.html, base)).toEqual([]);
  });

  test('07 delay method background, HIT copy: no FAZ handle is scheduled as data-loading-method="idle"', async () => {
    const base = requireBaseline();
    applyFp({ js_delay: true, js_delay_method: 'background' });
    const { hit } = await fpCopies(matrixUrl);
    expect(hit.html, 'positive control: background mode must schedule at least one non-FAZ script as idle').toMatch(/data-loading-method=["']?idle/);
    expect(fazHandleViolations(hit.html, base)).toEqual([]);
    expect(bootstrapOrderViolations(hit.html)).toEqual([]);
    expect(blockedScriptViolations(hit.html, base)).toEqual([]);
  });

  // Could not fail before: the Cloud Optimizer answered `{}`, external_domains
  // was empty and nothing was ever delayed as third-party. The mock now names
  // the fake CDN and www.google.com, and the fake-CDN control script must be
  // delayed. Whether 42728bbc goes red here depends on whether the stored
  // whitelist puts www.google.com/recaptcha into the inline _fazConfig — test 26
  // forces that case.
  test('08 third-party delay (method defer), HIT copy: FAZ stays live and the blocked Google tag stays text/plain', async () => {
    const base = requireBaseline();
    applyFp({ js_delay: true, js_delay_method: 'defer', js_delay_third_party: true });
    const { hit } = await fpCopies(matrixUrl);
    annotate('third-party-delay-evidence', `${(hit.html.match(DELAYED_UI) ?? []).length} script(s) delayed until interaction on the HIT copy`);
    const control = scriptTags(hit.html).find((tag) => tag.attrs.id === 'faz-e2e-cdn-control-js');
    expect(control, 'the fake-CDN control script must be on the page').toBeDefined();
    expect(
      control?.attrs['data-loading-method'],
      'positive control: the third-party delay must hold the fake-CDN control script until interaction (external_domains from the Cloud Optimizer mock)',
    ).toBe('user-interaction');
    expect(fazHandleViolations(hit.html, base)).toEqual([]);
    expect(bootstrapOrderViolations(hit.html)).toEqual([]);
    expect(blockedScriptViolations(hit.html, base)).toEqual([]);
  });

  test('09 js_delay_excludes with unrelated keywords: FAZ is still excluded at runtime and the stored list stays exactly as saved', async () => {
    const base = requireBaseline();
    const adminList = ['jquery-migrate', 'faz-e2e-unrelated-keyword'];
    applyFp({ ...DELAY_UI, js_delay_excludes: adminList });
    const { preload, hit } = await fpCopies(matrixUrl);
    expect(hit.html, 'positive control: other scripts must still be delayed').toMatch(DELAYED_UI);
    expect(fazHandleViolations(preload.html, base)).toEqual([]);
    expect(fazHandleViolations(hit.html, base)).toEqual([]);
    expect(bootstrapOrderViolations(hit.html)).toEqual([]);

    // ?fazprobe dodges the page cache so the probe sees a processed request.
    const probe = await fetchPage(`${matrixUrl}?fazprobe=${Date.now()}`);
    const runtime = JSON.parse(probe.headers['x-faz-fp-runtime-excludes'] ?? 'null') as unknown;
    expect(Array.isArray(runtime), 'the fp-probe fixture must report FlyingPress runtime excludes').toBe(true);
    for (const keyword of [...adminList, 'faz-cookie-manager', 'faz-fw']) {
      expect(runtime as string[], `runtime delay excludes must contain "${keyword}"`).toContain(keyword);
    }
    expect(JSON.parse(probe.headers['x-faz-fp-stored-excludes'] ?? 'null')).toEqual(adminList);
    expect(storedDelayExcludes(), 'FAZ must never persist its keywords into FLYING_PRESS_CONFIG').toEqual(adminList);
  });

  test('10 css_js_minify, HIT copy: FAZ scripts keep their exact src and the banner stylesheet is still applied', async () => {
    const base = requireBaseline();
    applyFp({ css_js_minify: true });
    const { hit } = await fpCopies(matrixUrl);
    expect(hit.html, 'positive control: minify must rewrite at least one asset URL').toMatch(
      /\/cache\/flying-press\/[^"'\s>]+\.(?:js|css)|[?&](?:amp;)?ver=[0-9a-f]{12}(?![0-9a-f])/,
    );
    expect(fazHandleViolations(hit.html, base)).toEqual([]);
    expect(bootstrapOrderViolations(hit.html)).toEqual([]);
    expect(bannerAssetViolations(hit.html, base)).toEqual([]);
  });

  test('11 css_js_self_host_third_party, HIT copy: the blocked Google tag is not turned into an executable first-party copy', async () => {
    const base = requireBaseline();
    applyFp({ css_js_self_host_third_party: true });
    const { hit } = await fpCopies(matrixUrl);
    const selfHosted = scriptTags(hit.html).filter((tag) => 'data-origin-src' in tag.attrs);
    annotate(
      'self-host-evidence',
      selfHosted.length > 0
        ? `self-hosted: ${selfHosted.map((tag) => `${tag.attrs['data-origin-src']} (type=${tag.attrs.type ?? ''})`).join(', ')}`
        : 'FlyingPress self-hosted no script on this run (download may have failed)',
    );
    // Positive controls, both deterministic now: FlyingPress's own list holds
    // only library CDNs, so the mock extends flying_press_selfhost_external_domains
    // with the fake CDN and www.googletagmanager.com and serves both downloads.
    expect(
      selfHosted.some((tag) => tag.attrs.id === 'faz-e2e-cdn-control-js'),
      'positive control: the fake-CDN control library must have been self-hosted',
    ).toBe(true);
    const gtag = scriptTags(hit.html).find((tag) => tag.attrs['data-faz-e2e-probe'] === 'gtag');
    expect(gtag, 'the Google tag probe must still be on the page').toBeDefined();
    expect(gtag?.attrs['data-origin-src'] ?? '', 'the blocked Google tag must have gone through the self-host pass').toMatch(/googletagmanager\.com/i);
    expect((gtag?.attrs.type ?? '').toLowerCase(), 'a self-hosted Google tag must still be text/plain').toBe('text/plain');
    const gtagBaseline = base.blocked.find((entry) => entry.key === 'gtag');
    expect(gtag?.attrs['data-faz-category'], 'the self-hosted Google tag keeps its category').toBe(gtagBaseline?.category);
    for (const tag of selfHosted) {
      if (/googletagmanager\.com/i.test(tag.attrs['data-origin-src'])) {
        expect((tag.attrs.type ?? '').toLowerCase(), 'a self-hosted Google tag must still be text/plain').toBe('text/plain');
      }
    }
    expect(blockedScriptViolations(hit.html, base)).toEqual([]);
    expect(fazHandleViolations(hit.html, base)).toEqual([]);
  });

  test('12 lazy_load images, HIT copy: the parked Meta pixel keeps data-faz-src and is given no live src', async ({ browser }) => {
    const base = requireBaseline();
    applyFp({ lazy_load: true });
    const { hit } = await fpCopies(matrixUrl);
    const control = controlTag(hit.html, 'img');
    expect(control, 'the control image must be on the page').toBeDefined();
    expect('loading' in (control?.attrs ?? {}), 'positive control: lazy_load must set loading= on the same-origin control image').toBe(true);
    expect(parkedEmbedViolations(hit.html, base)).toEqual([]);
    await scrollWithoutConsent(browser, matrixUrl, 'FlyingPress lazy_load (HIT)');
  });

  test('13 lazy_load iframes + youtube_placeholder, HIT copy: the parked YouTube embed is neither made live nor swapped for a FlyingPress thumbnail', async ({ browser }) => {
    const base = requireBaseline();
    applyFp({ lazy_load: true, youtube_placeholder: true });
    const { hit } = await fpCopies(matrixUrl);
    const control = controlTag(hit.html, 'iframe');
    expect(control, 'the control iframe must be on the page').toBeDefined();
    expect('data-lazy-src' in (control?.attrs ?? {}), 'positive control: lazy_load must defer the same-origin control iframe').toBe(true);
    expect(parkedEmbedViolations(hit.html, base)).toEqual([]);
    await scrollWithoutConsent(browser, matrixUrl, 'FlyingPress lazy_load + youtube_placeholder (HIT)');
  });

  // FAILS on 42728bbc: with a non-empty used_css, CSS::load_used_css() moves
  // EVERY <link rel=stylesheet> to data-href (FlyingPress has no exclusion
  // there), the FAZ banner sheet included, and nothing FAZ asks the Cloud
  // Optimizer to keep (no css_rucss_include_selectors). The banner then
  // renders unstyled until the visitor's first interaction. Green with
  // fix/1342c-flyingpress (either keep the FAZ sheet live, or make the used CSS
  // carry every FAZ selector).
  test('14 remove unused CSS (css_rucss), HIT copy: the banner sheet stays live or every FAZ selector is in the used CSS, and the banner is styled with no interaction', async ({ browser }) => {
    const base = requireBaseline();
    applyFp({ css_rucss: true });
    const { hit } = await fpCopies(matrixUrl);
    const usedCss = voidTags(hit.html, 'style').find((tag) => tag.attrs.id === 'flying-press-css')?.content ?? '';
    expect(usedCss, 'positive control: FlyingPress must inject the Cloud Optimizer used CSS (style#flying-press-css)').toContain('.e2e-rucss-marker');
    const deferredOthers = voidTags(hit.html, 'link').filter((tag) => 'data-href' in tag.attrs && !isFazId(tag.attrs.id));
    expect(deferredOthers.length, 'positive control: RUCSS must defer at least one non-FAZ stylesheet to data-href').toBeGreaterThan(0);
    expect(fazHandleViolations(hit.html, base)).toEqual([]);
    const template = scriptTags(hit.html).find((tag) => tag.attrs.id === 'fazBannerTemplate');
    expect(template?.content ?? '', 'the banner template must survive').toContain('data-faz-tag');
    if (base.cssKind === 'link') {
      const fazLink = voidTags(hit.html, 'link').find((tag) => tag.attrs.id === 'faz-cookie-manager-css-css');
      const deferred = !fazLink || 'data-href' in fazLink.attrs || !fazLink.attrs.href;
      annotate('faz-banner-sheet', deferred ? 'deferred to data-href — judged on the used CSS' : 'kept live');
      if (deferred) {
        const selectors = await fazBannerSelectors(base.cssHref);
        expect(selectors.length, 'the FAZ banner stylesheet must contain FAZ selectors').toBeGreaterThan(0);
        const used = normaliseCss(usedCss);
        const missing = selectors.filter((selector) => !used.includes(`${normaliseCss(selector)}{`));
        expect(missing, `the FAZ banner sheet is deferred until interaction, so all ${selectors.length} FAZ selectors must be in the used CSS`).toEqual([]);
      }
    } else {
      expect(bannerAssetViolations(hit.html, base)).toEqual([]);
    }

    const context = await visitorContext(browser);
    try {
      const page = await context.newPage();
      const response = await page.goto(matrixUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
      expect((response?.headers()['x-flying-press-cache'] ?? '').toUpperCase(), 'the visitor must get the cached RUCSS copy').toBe('HIT');
      await expect(page.locator('[data-faz-tag="notice"]').first(), 'banner must be in the DOM').toBeAttached({ timeout: 20_000 });
      await expect
        .poll(
          () =>
            page.evaluate(() => {
              const el = document.querySelector('.faz-consent-container');
              return el ? getComputedStyle(el).position : 'absent';
            }),
          { timeout: 10_000, message: 'with no interaction the banner container must already be styled (position: fixed from the FAZ sheet)' },
        )
        .toBe('fixed');
      await assertMainThreadHealthy(page, 'FlyingPress RUCSS (HIT)');
    } finally {
      await context.close();
    }
  });

  // Could not fail before: lazy_render only acts on the Cloud Optimizer's
  // content_visibility_targets, which were empty. The mock targets every
  // div/section/article/footer outside <script> bodies, so the pass now runs
  // (positive control) and the banner template — whose markup FlyingPress also
  // stamps with data-uid — must come out byte-identical.
  test('15 lazy_render, HIT copy: the banner template and FAZ handles survive', async () => {
    const base = requireBaseline();
    applyFp({ lazy_render: true });
    const { hit } = await fpCopies(matrixUrl);
    expect(hit.html, 'positive control: lazy_render must apply content-visibility to at least one element').toMatch(/content-visibility:\s*auto/);
    const templateNow = scriptTags(hit.html).find((tag) => tag.attrs.id === 'fazBannerTemplate')?.content;
    const templateBase = scriptTags(baselineHtml).find((tag) => tag.attrs.id === 'fazBannerTemplate')?.content;
    expect(templateNow, 'the banner template must come out of lazy_render byte-identical').toBe(templateBase);
    expect(bannerAssetViolations(hit.html, base)).toEqual([]);
    expect(fazHandleViolations(hit.html, base)).toEqual([]);
    expect(bootstrapOrderViolations(hit.html)).toEqual([]);
  });

  test('16 aggressive preset (every optimisation on, delay user-interaction): the whole FAZ contract holds on the preload and HIT copies', async () => {
    const base = requireBaseline();
    applyFp(AGGRESSIVE);
    const { preload, hit } = await fpCopies(matrixUrl);
    expect(hit.html, 'positive control: the preset must delay at least one non-FAZ script').toMatch(DELAYED_UI);
    expect(fullContractViolations(preload.html, base)).toEqual([]);
    expect(fullContractViolations(hit.html, base)).toEqual([]);
  });

  test('17 browser, delay user-interaction, HIT copy: banner without interaction, responsive main thread, accept sets the cookie and runs the blocked script', async ({ browser }) => {
    resetProviderMatrixState();
    applyFp(DELAY_UI);
    await fpCopies(matrixUrl);
    await visitorFlow(browser, matrixUrl, 'FlyingPress delay user-interaction (HIT)', requireBaseline(), true);
  });

  test('18 browser, delay method defer, HIT copy: banner without interaction, responsive main thread, accept sets the cookie and runs the blocked script', async ({ browser }) => {
    resetProviderMatrixState();
    applyFp({ js_delay: true, js_delay_method: 'defer' });
    await fpCopies(matrixUrl);
    await visitorFlow(browser, matrixUrl, 'FlyingPress delay defer (HIT)', requireBaseline(), true);
  });

  test('19 control: an ordinary visitor render is cacheable for FlyingPress and reaches a HIT (proves tests 20–22 are not vacuous)', async () => {
    applyFp({});
    const token = newToken('control');
    const page = await probedRequest(pageUrls.control, token);
    expect(page.status).toBe(200);
    expect(readProbe(token).fp, 'FlyingPress must consider an ordinary page cacheable').toBe('cacheable');
    await fetchPreload(pageUrls.control);
    const hit = await primeHit(pageUrls.control);
    expect(cacheState(hit)).toBe('HIT');
    expect(findCacheFile(PAGES.control.slug)).not.toBeNull();
  });

  test('20 cache veto: a crawler render with hide_from_bots is never cached by FlyingPress', async () => {
    applyFp({});
    // The crawler variant only exists without Cache Compatibility Mode.
    patchFazSettings(`
      $s['banner_control']['cache_compatibility'] = false;
      $s['banner_control']['hide_from_bots'] = true;
    `);
    try {
      for (let i = 0; i < 3; i += 1) {
        const token = newToken(`crawler${i}`);
        const page = await probedRequest(pageUrls.crawler, token, { userAgent: CRAWLER_UA });
        expect(page.status).toBe(200);
        expect(cacheState(page), `crawler request ${i + 1} must never be served from the FlyingPress cache`).not.toBe('HIT');
        if (i === 0) {
          expect(page.html, 'precondition: the crawler render must omit the banner template (bot-variant render active)').not.toContain('id="fazBannerTemplate"');
        }
        expect(readProbe(token).fp, 'flying_press_is_cacheable must be false for a crawler-only render').toBe('vetoed');
      }
      await expectNeverCached(PAGES.crawler.slug, 8_000, 'crawler render');
    } finally {
      patchFazSettings(`$s['banner_control']['cache_compatibility'] = true;`);
    }
  });

  test('21 cache veto: the DSAR form page is never cached by FlyingPress', async () => {
    applyFp({});
    for (let i = 0; i < 3; i += 1) {
      const token = newToken(`dsar${i}`);
      const page = await probedRequest(pageUrls.dsar, token);
      expect(page.status).toBe(200);
      expect(cacheState(page), `DSAR request ${i + 1} must never be a FlyingPress HIT`).not.toBe('HIT');
      if (i === 0) {
        expect(page.html, 'precondition: the DSAR form must render').toContain('faz-dsar-form');
      }
      expect(readProbe(token).fp, 'flying_press_is_cacheable must be false on the DSAR page').toBe('vetoed');
    }
    // Even FlyingPress's own preloader must not get a cacheable copy.
    const preloadToken = newToken('dsar-preload');
    await probedRequest(pageUrls.dsar, preloadToken, { preload: true });
    expect(readProbe(preloadToken).fp).toBe('vetoed');
    await expectNeverCached(PAGES.dsar.slug, 8_000, 'DSAR page');
    expect(cacheState(await fetchPage(pageUrls.dsar))).not.toBe('HIT');
  });

  test('22 cache veto: a country-dependent render is never cached by FlyingPress', async () => {
    applyFp({});
    setOption('faz_e2e_force_country_dependent', 'yes');
    try {
      const token = newToken('country');
      const page = await probedRequest(pageUrls.country, token);
      expect(page.status).toBe(200);
      expect(readProbe(token).fp, 'flying_press_is_cacheable must be false for country-dependent output').toBe('vetoed');
      const preloadToken = newToken('country-preload');
      await probedRequest(pageUrls.country, preloadToken, { preload: true });
      expect(readProbe(preloadToken).fp).toBe('vetoed');
      await expectNeverCached(PAGES.country.slug, 8_000, 'country-dependent render');
      expect(cacheState(await fetchPage(pageUrls.country))).not.toBe('HIT');
    } finally {
      setOption('faz_e2e_force_country_dependent', 'no');
    }
  });

  test('23 falsifiability: with FAZ protections stripped by the lab fixture, the handle check reports delayed FAZ scripts', async () => {
    const base = requireBaseline();
    setOption('faz_e2e_break_faz_exclusions', 'yes');
    try {
      applyFp(DELAY_UI);
      const preload = await fetchPreload(matrixUrl);
      const violations = fazHandleViolations(preload.html, base);
      annotate('violations-with-protections-stripped', violations.join(' | '));
      expect(violations.length, 'stripping FAZ protections must make FlyingPress delay FAZ — otherwise tests 02–18 cannot fail').toBeGreaterThan(0);
    } finally {
      setOption('faz_e2e_break_faz_exclusions', BREAK_STATE);
      fpPurge();
    }
  });

  test('24 no PHP warning, notice or FAZ diagnostic was logged during the FlyingPress renders', async () => {
    applyFp({});
    const token = newToken('canary');
    await probedRequest(pageUrls.control, token);
    const log = readCapturedLog();
    expect(log, 'the lab must have captured its canary line (otherwise the log redirect did not take)').toContain(`FAZ-E2E-OPTM-CANARY ${token}`);
    const lines = log.split('\n').filter((line) => /plugins\/faz-cookie-manager\/|FAZ Cookie Manager:/.test(line));
    const deprecations = lines.filter((line) => /PHP Deprecated/.test(line));
    if (deprecations.length > 0) {
      annotate('faz-deprecations', deprecations.slice(0, 5).join(' | '));
    }
    // The per-IP consent throttle's diagnostic is expected here: the browser
    // runs accept from the same 127.0.0.1 a few seconds apart, and the 10 s
    // window refuses the second record by design. Everything else FAZ logs,
    // and every PHP warning or notice, still fails the test.
    const throttle = /FAZ Cookie Manager: refused a consent record because the request was rate-limited/;
    const offending = lines.filter((line) => /PHP (?:Warning|Notice|Fatal error|Parse error)|FAZ Cookie Manager:/.test(line) && !throttle.test(line));
    expect(offending).toEqual([]);
  });
  // FAILS on 42728bbc: FAZ feeds its keywords only into js_delay_excludes
  // (flying_press_apply_runtime_delay_exclusions / flying_press_exclude_own_scripts);
  // FlyingPress's third-party pass reads js_delay_third_party_excludes, which no
  // FAZ filter touches, and matches external_domains as a substring of the whole
  // tag. Every FAZ <script src> on the CDN host therefore becomes
  // data-loading-method="user-interaction": banner and runtime start on the
  // first interaction or after FlyingPress's 10 s fallback. Green with
  // fix/1342c-flyingpress (bridge extended to the third-party excludes, plus the
  // flying_press_optimization:after safety net).
  test('25 FAZ assets on a CDN host + third-party delay (method defer), HIT copy: no FAZ handle is delayed until interaction and the banner appears without one', async ({ browser }) => {
    const base = requireBaseline();
    setOption('faz_e2e_fp_cdn_assets', 'yes');
    try {
      applyFp({ js_delay: true, js_delay_method: 'defer', js_delay_third_party: true });
      const { miss, preload, hit } = await fpCopies(matrixUrl);
      const runtimeMiss = fazScriptsById(miss.html).get('faz-cookie-manager-js');
      expect(runtimeMiss?.attrs.src ?? '', 'precondition: script.min.js must be served from the fake CDN host').toContain(FAKE_CDN_HOST);
      const control = scriptTags(hit.html).find((tag) => tag.attrs.id === 'faz-e2e-cdn-control-js');
      expect(control?.attrs['data-loading-method'], 'positive control: a non-FAZ script on the same CDN host must be delayed as third-party').toBe('user-interaction');
      const delayed = [...fazScriptsById(hit.html)].filter(([, tag]) => 'data-loading-method' in tag.attrs).map(([id]) => id);
      expect(delayed, 'no FAZ handle may be delayed until interaction').toEqual([]);
      expect(fazHandleViolations(preload.html, base)).toEqual([]);
      expect(fazHandleViolations(hit.html, base)).toEqual([]);
      expect(bootstrapOrderViolations(hit.html)).toEqual([]);

      const context = await visitorContext(browser);
      try {
        const page = await context.newPage();
        const response = await page.goto(matrixUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
        expect((response?.headers()['x-flying-press-cache'] ?? '').toUpperCase(), 'the visitor must get the cached copy').toBe('HIT');
        // 6 s: well under FlyingPress's 10 s fallback for user-interaction
        // scripts, well over a deferred runtime on an idle page.
        await expect(page.locator('[data-faz-tag="notice"]').first(), 'banner must appear with no interaction').toBeVisible({ timeout: 6_000 });
        await assertMainThreadHealthy(page, 'FAZ on a CDN host (HIT)');
      } finally {
        await context.close();
      }
    } finally {
      setOption('faz_e2e_fp_cdn_assets', 'no');
      fpPurge();
    }
  });

  // FAILS on 42728bbc: the whitelist pattern lands in the inline _fazConfig
  // (_userWhitelist), the tag faz-cookie-manager-js-extra then contains
  // "www.google.com" (an external_domains keyword), and the third-party pass
  // delays -js-extra until interaction while script.min.js runs on time without
  // its configuration — no banner for that page view. Green with
  // fix/1342c-flyingpress.
  test('26 a reCAPTCHA URL inside FAZ\'s inline config + third-party delay (method defer), HIT copy: neither -js-extra nor script.min.js is delayed and the banner appears', async ({ browser }) => {
    const base = requireBaseline();
    const before = wpEval(`
      $s = get_option( 'faz_settings', array() );
      echo base64_encode( wp_json_encode( isset( $s['script_blocking']['whitelist_patterns'] ) ? $s['script_blocking']['whitelist_patterns'] : null ) );
    `).trim();
    patchFazSettings(`
      if ( ! isset( $s['script_blocking'] ) || ! is_array( $s['script_blocking'] ) ) { $s['script_blocking'] = array(); }
      $list = ( isset( $s['script_blocking']['whitelist_patterns'] ) && is_array( $s['script_blocking']['whitelist_patterns'] ) ) ? $s['script_blocking']['whitelist_patterns'] : array();
      if ( ! in_array( 'www.google.com/recaptcha/', $list, true ) ) { $list[] = 'www.google.com/recaptcha/'; }
      $s['script_blocking']['whitelist_patterns'] = $list;
    `);
    try {
      applyFp({ js_delay: true, js_delay_method: 'defer', js_delay_third_party: true });
      const { miss, hit } = await fpCopies(matrixUrl);
      const extraMiss = fazScriptsById(miss.html).get('faz-cookie-manager-js-extra');
      expect(extraMiss?.content ?? '', 'precondition: the inline _fazConfig must name www.google.com/recaptcha').toMatch(/www\.google\.com\\?\/recaptcha/);
      const control = scriptTags(hit.html).find((tag) => tag.attrs.id === 'faz-e2e-cdn-control-js');
      expect(control?.attrs['data-loading-method'], 'positive control: the third-party delay must have run on this copy').toBe('user-interaction');
      const tags = fazScriptsById(hit.html);
      expect(tags.get('faz-cookie-manager-js-extra')?.attrs['data-loading-method'], '-js-extra (the inline _fazConfig) must not be delayed until interaction').toBeUndefined();
      expect(tags.get('faz-cookie-manager-js')?.attrs['data-loading-method'], 'script.min.js must not be delayed until interaction').toBeUndefined();
      expect(fazHandleViolations(hit.html, base)).toEqual([]);
      expect(bootstrapOrderViolations(hit.html)).toEqual([]);

      const context = await visitorContext(browser);
      try {
        const page = await context.newPage();
        const response = await page.goto(matrixUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
        expect((response?.headers()['x-flying-press-cache'] ?? '').toUpperCase(), 'the visitor must get the cached copy').toBe('HIT');
        await expect(page.locator('[data-faz-tag="notice"]').first(), 'banner must appear with no interaction').toBeVisible({ timeout: 6_000 });
        await assertMainThreadHealthy(page, 'reCAPTCHA in _fazConfig (HIT)');
      } finally {
        await context.close();
      }
    } finally {
      patchFazSettings(`
        $prior = json_decode( base64_decode( '${before}' ), true );
        if ( null === $prior ) { unset( $s['script_blocking']['whitelist_patterns'] ); } else { $s['script_blocking']['whitelist_patterns'] = $prior; }
      `);
      fpPurge();
    }
  });
});
