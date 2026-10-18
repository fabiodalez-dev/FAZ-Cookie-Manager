/**
 * LiteSpeed Cache page optimisation — FAZ must come out of every LSCWP
 * optimiser pass exactly as it went in.
 *
 * Why this file exists: LiteSpeed's page optimiser is the plugin most often
 * found rewriting a consent banner in the field. A site on LSCWP froze with the
 * 1.34.1 deferred runtime, and a "delay JavaScript" pass that touches the
 * consent runtime shows the banner only after the first interaction — by which
 * point the trackers that interaction released have already run. The existing
 * LiteSpeed coverage (wpml-litespeed-cache-compat.spec.ts) proves purge-on-save;
 * nothing proved the optimiser leaves FAZ alone. These tests do, one LSCWP
 * option at a time and then all of them together.
 *
 * What every optimisation test checks, against a baseline render taken with the
 * optimiser bypassed:
 *   (a) every FAZ script handle (faz-cookie-manager-static-config-js, -js-extra,
 *       -js-before, -js, -gcm-js-after, -a11y-js, -a11y-js-extra, and any other
 *       faz-cookie-manager-* tag present in the baseline) is still there, with
 *       no `litespeed/javascript` type, no data-src, no data: URI conversion,
 *       not folded into a wp-content/litespeed bundle, src still FAZ's own file;
 *   (b) the inline bootstrap (-js-before, window._fazBootstrap) still precedes
 *       script.min.js, which still carries its own defer;
 *   (c) the banner template and the banner stylesheet survive;
 *   (d) every third-party script FAZ blocked server-side is still
 *       type="text/plain" with its data-faz-category, never turned into an
 *       executable litespeed/javascript, data-src or data: script;
 *   (e) the parked Meta pixel <img> and YouTube <iframe> keep data-faz-src and
 *       lazy-load never hands them a live src.
 * Each optimisation test also asserts a positive control — evidence that the
 * LSCWP pass under test actually ran on this request (a non-FAZ script deferred,
 * a wp-content/litespeed bundle referenced, the html_min footprint, a lazy-loaded
 * control image) — so a request on which the optimiser silently bypassed cannot
 * pass for a request on which it left FAZ alone.
 *
 * Then (f) real-browser runs under js_defer=1, js_defer=2 and Guest
 * Optimization: the banner appears with no user interaction, the main thread
 * keeps answering, and accepting sets the consent cookie and runs a script FAZ
 * had blocked; (g) the cache-veto contract: crawler render with hide_from_bots,
 * the DSAR form page and a country-dependent render all send
 * `X-LiteSpeed-Cache-Control: no-cache` AND leave LSCWP's own not-cacheable bit
 * set, against a control page that must stay cacheable; (h) no PHP warning,
 * notice or FAZ diagnostic was logged while all of this ran.
 *
 * Two contracts were added after the 2026-10-16 cache review showed the file
 * could not see the defects that actually reached sites:
 *   (i)  test 14 no longer stops at "the markup survived qs_rm": every FAZ asset
 *        that carries ?ver= must still carry the SAME ver= and
 *        `_litespeed_rm_qs=0` after "Remove Query Strings". Without that the
 *        runtime URL is identical across releases and a cache keeps serving the
 *        old runtime under new HTML (runtime-skew-freeze.spec.ts RS-02 shows
 *        what that does to the page);
 *   (j)  tests 26–29 run with Cache Compatibility Mode and Geo-Targeting at the
 *        plugin's OWN defaults (compatibility off, geo on) and no country
 *        source, and ask LiteSpeed itself — requests carry `X-LSCache: on` so
 *        LSCWP emits the X-LiteSpeed-Cache-Control a LiteSpeed server obeys —
 *        whether a page rendered for a visitor who accepted everything (alone,
 *        with Sec-GPC: 1, with fazcookie-dnsmpi=1) may be shared. It may only if
 *        it is the most-protective render. Tests 01–25 keep compatibility ON on
 *        purpose (see beforeAll), which is exactly why they could never see it.
 * The browser runs (17–19) now use the shared main-thread watchdog
 * (utils/main-thread-watchdog.ts: heartbeat gaps, long tasks, heap, element
 * count over the whole page view) instead of three setTimeout(0) samples.
 *
 * ENVIRONMENT
 * -----------
 * The test site is nginx + PHP-FPM, not a LiteSpeed server. LSCWP's optimiser
 * bypasses every request there because nothing marks it cacheable, so the lab
 * fixture tests/e2e/fixtures/plugins/faz-e2e-litespeed-optm drives it in one of
 * two ways: by firing LSCWP's own `litespeed_control_set_cacheable` action (what
 * a LiteSpeed server does; the configured js_defer level applies and a FAZ
 * `litespeed_control_set_nocache` still wins), or by defining
 * LITESPEED_GUEST_OPTM (Guest Optimization, which forces js_defer=2). Guest mode
 * proper (the `guest` + `guest_optm` LSCWP options) is exercised as well. The
 * fixture also records LSCWP's not-cacheable bit at shutdown for any request
 * that sends `X-Faz-E2E-Probe`, since the header LSCWP would derive from it is
 * only emitted on a LiteSpeed server.
 *
 * HOW TO RUN THIS FILE
 * --------------------
 *     npm run test:e2e:litespeed
 *
 * The file is serial: on a build where one test is red, every later test is
 * reported as skipped, not passed. To see each verdict on such a build, run the
 * tests one at a time (beforeAll still prepares the environment):
 *     npm run test:e2e:litespeed -- -g " 14 "
 * Set FAZ_E2E_REQUIRE_CACHE_PLUGINS=1 to turn the "plugin not installed" /
 * "workers > 1" skips into failures (release gate).
 *
 * It activates LiteSpeed Cache globally and rewrites its options, so it needs
 * the install to itself: it gates on `testInfo.config.workers === 1` and skips
 * otherwise, takes the shared WordPress lock, auto-skips when LSCWP is not
 * installed, and in afterAll restores every LSCWP option it changed, purges,
 * deactivates litespeed-cache (the rest of the suite must never see it
 * active), and removes every option the fixture reads.
 *
 * FALSIFIABILITY
 * --------------
 * The handle tests are only worth something if they can fail. To prove it,
 * re-run the file with the lab fixture's switch (option
 * faz_e2e_break_faz_exclusions=yes) held on for the whole run:
 *
 *     FAZ_E2E_BREAK_FAZ_EXCLUSIONS=1 npm run test:e2e:litespeed
 *
 * (Setting the option by hand is not enough: beforeAll resets it, so a normal
 * run can never inherit a broken state left behind by a falsifiability run.)
 * The fixture then removes every LiteSpeed exclusion callback FAZ registers and
 * strips data-no-defer / data-no-optimize / data-no-minify / data-cfasync /
 * data-ao-skip from FAZ's tags. The script and stylesheet tests (03–07, 09,
 * 10, 15, 16) and the Guest Optimization browser run (19) must then go red —
 * measured on 2026-10-16, one test at a time, all ten red for the stated
 * reason. The js_defer=2 browser run (18) stays green under the switch: on its
 * own js_defer=2 delays only two inline helpers (faz-cookie-manager-js-before
 * and the a11y -js-extra), not the runtime, so a visitor still gets a working
 * banner. Test 04 is the one that catches that rewrite. The html_min / media /
 * localize / qs_rm tests may stay green, since those passes never relied on
 * FAZ's hints.
 * Test 24 does exactly this inside the run, under js_defer=2, and asserts the
 * handle check reports violations; if it ever goes green with the switch on,
 * the check itself is broken. afterAll deletes the option either way.
 */
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
const DSAR_SLUG = 'faz-e2e-ls-optm-dsar';
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

/** Every LSCWP option this file touches; all are boolean or 0/1/2 integers. */
const LS_KEYS = [
  'optm-js_defer',
  'optm-js_min',
  'optm-js_comb',
  'optm-js_comb_ext_inl',
  'optm-css_min',
  'optm-css_comb',
  'optm-css_comb_ext_inl',
  'optm-ucss',
  'optm-html_min',
  'optm-qs_rm',
  'optm-ggfonts_async',
  'optm-emoji_rm',
  'optm-noscript_rm',
  'media-lazy',
  'media-iframe_lazy',
  'optm-localize',
  'guest',
  'guest_optm',
] as const;
type LsKey = (typeof LS_KEYS)[number];
type LsSettings = Partial<Record<LsKey, number>>;
/**
 * off            — optimiser bypassed (nothing marks the request cacheable).
 * cacheable      — the lab fires litespeed_control_set_cacheable; configured js_defer applies.
 * guest-constant — the lab defines LITESPEED_GUEST_OPTM; LSCWP forces js_defer=2.
 */
type LsMode = 'off' | 'cacheable' | 'guest-constant';

const AGGRESSIVE: LsSettings = {
  'optm-js_defer': 2,
  'optm-js_min': 1,
  'optm-js_comb': 1,
  'optm-js_comb_ext_inl': 1,
  'optm-css_min': 1,
  'optm-css_comb': 1,
  'optm-css_comb_ext_inl': 1,
  'optm-ucss': 1,
  'optm-html_min': 1,
  'optm-qs_rm': 1,
  'optm-ggfonts_async': 1,
  'optm-emoji_rm': 1,
  'optm-noscript_rm': 1,
  'media-lazy': 1,
  'media-iframe_lazy': 1,
  'optm-localize': 1,
};

let lsInstalled = false;
let exclusiveRun = false;
let configuredWorkers = 0;
let lockHeld = false;
let setupDone = false;
let lsWasActive = false;
let flyingPressWasActive = false;
let labWasActive = false;
let matrixWasActive = false;
let auditLabWasActive = false;
let dsarPageCreated = false;
let settingsSnapshot = '';
const lsSnapshot: Partial<Record<LsKey, string>> = {};
const lsCurrent: Partial<Record<LsKey, string>> = {};
let matrixUrl = '';
let dsarUrl = '';
let baselineHtml = '';
let baseline: Baseline | null = null;
/** LSCWP's own `cache` switch, needed only by the X-LSCache (ADC emulation) tests; restored in afterAll. */
let lsCacheSnapshot: string | null = null;
let lsCacheChanged = false;
/** The anonymous render under the DEFAULT settings (tests 26–29). */
let strictBase: Baseline | null = null;

test.describe.configure({ mode: 'serial', timeout: 240_000 });

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
  // &amp; last: decoding it first would turn a literal "&amp;quot;" into a quote.
  return value
    .replace(/&quot;/g, '"')
    .replace(/&#0?39;/g, "'")
    .replace(/&#0?38;/g, '&')
    .replace(/&amp;/g, '&');
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
  const re = /<script\b([^>]*)>([\s\S]*?)<\/script\b[^>]*>/gi;
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
      } else if (pathOf(src) !== pathOf(expected.src)) {
        problems.push(`${expected.id}: src path changed ${short(pathOf(expected.src))} -> ${short(pathOf(src))}`);
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
      if (/\/litespeed\/css\//.test(href) || /\/cache\/flying-press\//.test(href)) problems.push(`banner stylesheet now points at an optimiser copy ${short(href)}`);
      if (href && pathOf(href) !== pathOf(base.cssHref)) problems.push(`banner stylesheet path changed -> ${short(pathOf(href))}`);
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
  // Repeat until stable, so a removal cannot splice a new <script> together.
  let out = html;
  let prev;
  do {
    prev = out;
    out = out.replace(/<script\b[^>]*>[\s\S]*?<\/script\b[^>]*>/gi, '');
  } while (out !== prev);
  return out;
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
      found.push(`${match[1].toLowerCase()}=${value}`);
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
 * Query-string removal (c) and the consent cache oracle (b)
 * ------------------------------------------------------------------------ */

type VersionedAsset = { id: string; url: string; ver: string };

function queryOf(url: string): URLSearchParams {
  try {
    return new URL(decodeEntities(url), WP_BASE).searchParams;
  } catch {
    return new URLSearchParams();
  }
}

/** Every FAZ <script src> / <link href> whose URL carries ?ver= (the content-hashed files carry none). */
function fazVersionedAssets(html: string): VersionedAsset[] {
  const out: VersionedAsset[] = [];
  for (const [id, tag] of fazScriptsById(html)) {
    const src = tag.attrs.src ?? '';
    const ver = src ? queryOf(src).get('ver') : null;
    if (ver) out.push({ id, url: src, ver });
  }
  for (const tag of voidTags(html, 'link')) {
    const id = tag.attrs.id ?? '';
    const href = tag.attrs.href ?? '';
    const ver = href ? queryOf(href).get('ver') : null;
    if (isFazId(id) && ver) out.push({ id, url: href, ver });
  }
  return out;
}

/** Non-FAZ asset URLs still carrying ?ver= — the qs_rm positive control. */
function nonFazVersionedCount(html: string): number {
  let count = 0;
  for (const tag of [...scriptTags(html), ...voidTags(html, 'link')]) {
    if (isFazId(tag.attrs.id)) continue;
    const url = tag.attrs.src ?? tag.attrs.href ?? '';
    if (url && queryOf(url).get('ver')) count += 1;
  }
  return count;
}

/**
 * (c) After LSCWP "Remove Query Strings", each FAZ asset must still name its
 * version: `ver=` unchanged and `_litespeed_rm_qs=0` (the one marker
 * Optimize::remove_query_strings() honours, optimize.cls.php). Without it the
 * runtime URL is the same for every release, so a browser, the LiteSpeed ADC
 * or a CDN keeps serving the previous runtime under new HTML — the skew
 * runtime-skew-freeze.spec.ts RS-02 shows freezing the page.
 */
function qsRmViolations(html: string, expected: VersionedAsset[]): string[] {
  const problems: string[] = [];
  const scripts = fazScriptsById(html);
  const links = new Map(voidTags(html, 'link').filter((tag) => isFazId(tag.attrs.id)).map((tag) => [tag.attrs.id, tag] as const));
  for (const asset of expected) {
    const tag = scripts.get(asset.id) ?? links.get(asset.id);
    const url = tag ? (tag.attrs.src ?? tag.attrs.href ?? '') : '';
    if (!url) {
      problems.push(`${asset.id}: missing from the qs_rm page`);
      continue;
    }
    const query = queryOf(url);
    if (query.get('ver') !== asset.ver) problems.push(`${asset.id}: ver=${asset.ver} became ${query.get('ver') ?? '(stripped)'} — ${short(url)}`);
    if (query.get('_litespeed_rm_qs') !== '0') problems.push(`${asset.id}: no _litespeed_rm_qs=0, so LiteSpeed strips its version — ${short(url)}`);
  }
  return problems;
}

type CacheVerdict = { liteSpeed: string[]; cacheControl: string[]; vetoed: boolean; publicCacheable: boolean };

function headerValues(page: Fetched, name: string): string[] {
  return page.headerList
    .filter((header) => header.name.toLowerCase() === name)
    .flatMap((header) => header.value.split('\n'))
    .map((value) => value.trim())
    .filter(Boolean);
}

/**
 * What a LiteSpeed server would do with this response. LSCWP behaves as if
 * behind a LiteSpeed ADC when the request carries `X-LSCache` (litespeed-cache.php:
 * LITESPEED_SERVER_TYPE = ADC → LITESPEED_ALLOWED → LITESPEED_ON) and then emits
 * its own X-LiteSpeed-Cache-Control (Core::send_headers / Control::output), which
 * is the header the server obeys. "Vetoed" means every such value says no-cache
 * or private; "public" means the shared cache keeps it for the next visitor.
 */
function cacheVerdict(page: Fetched): CacheVerdict {
  const liteSpeed = headerValues(page, 'x-litespeed-cache-control');
  const cacheControl = headerValues(page, 'cache-control');
  const vetoed = liteSpeed.length > 0 && liteSpeed.every((value) => /no-cache|private/i.test(value) && !/\bpublic\b/i.test(value));
  const publicCacheable = liteSpeed.some((value) => /\bpublic\b/i.test(value)) && !liteSpeed.some((value) => /no-cache/i.test(value));
  return { liteSpeed, cacheControl, vetoed, publicCacheable };
}

/**
 * (b) A response the shared cache may keep must be safe for EVERY later
 * visitor, and the only render that is, is the most-protective one: every
 * script the anonymous render blocked is still text/plain. A parser-inserted
 * executable tracker runs before any client code can stop it, so a cached
 * "accepted" page is a tracker for the next visitor that nothing can undo.
 * Either answer is accepted: the response is vetoed for LiteSpeed, or it is the
 * most-protective render (the invariant-render fix).
 */
function consentCacheViolations(page: Fetched, strict: Baseline): string[] {
  const verdict = cacheVerdict(page);
  if (verdict.vetoed) return [];
  const leaks = blockedScriptViolations(page.html, strict);
  const header = verdict.liteSpeed.join(' | ') || '(no X-LiteSpeed-Cache-Control)';
  return leaks.map((leak) => `cacheable for LiteSpeed [${header}] while ${leak}`);
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

/**
 * Current value of an LSCWP option as `wp litespeed-option set` accepts it.
 *
 * `get` prints a zero integer as the two characters '' (its "empty non-bool"
 * branch), so that is normalised to 0 — otherwise the restore in afterAll
 * would write the literal string back.
 */
function lsGet(key: LsKey): string {
  const lines = wp(['litespeed-option', 'get', key]).split('\n').map((line) => line.trim()).filter(Boolean);
  const value = lines[lines.length - 1] ?? '0';
  return value === "''" || value === '' ? '0' : value;
}

function lsSet(key: LsKey, value: string): void {
  wp(['litespeed-option', 'set', key, value], { allowRetry: false });
  lsCurrent[key] = value;
}

function lsPurge(): void {
  // `wp litespeed-purge all` goes through a loopback REST call that answers
  // 400 on a non-LiteSpeed server (nginx here), so fire LSCWP's own purge
  // hook from inside WordPress instead. It purges the same stores.
  wp(['eval', 'do_action( "litespeed_purge_all" ); echo "purged";']);
}

/** LSCWP options outside LS_KEYS (read and restored on their own, never zeroed by applyLs). */
function lsRawGet(key: string): string {
  const lines = wp(['litespeed-option', 'get', key]).split('\n').map((line) => line.trim()).filter(Boolean);
  const value = lines[lines.length - 1] ?? '0';
  return value === "''" || value === '' ? '0' : value;
}

function lsRawSet(key: string, value: string): void {
  wp(['litespeed-option', 'set', key, value], { allowRetry: false });
}

/** Every LS_KEYS option to 0 except the ones named, then the lab's drive mode. */
function applyLs(settings: LsSettings, mode: LsMode): void {
  for (const key of LS_KEYS) {
    const desired = String(settings[key] ?? 0);
    if (lsCurrent[key] !== desired) {
      lsSet(key, desired);
    }
  }
  setOption('faz_e2e_ls_mark_cacheable', mode === 'cacheable' ? 'yes' : 'no');
  setOption('faz_e2e_ls_guest_optm', mode === 'guest-constant' ? 'yes' : 'no');
  lsPurge();
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

/**
 * The cache-relevant settings back to the plugin's OWN defaults (read from
 * Settings::get_defaults(), not hardcoded here): Cache Compatibility Mode off,
 * Geo-Targeting on, the geo bootstrap shell off, hide_from_bots on — and TCF
 * off, which would make every page country-dependent and veto it for an
 * unrelated reason.
 */
function applyDefaultCacheSettings(): void {
  patchFazSettings(`
    $d = \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings::get_instance()->get_defaults();
    $s['banner_control']['cache_compatibility'] = $d['banner_control']['cache_compatibility'];
    $s['banner_control']['hide_from_bots']      = $d['banner_control']['hide_from_bots'];
    $s['geolocation']['geo_targeting']          = $d['geolocation']['geo_targeting'];
    $s['geolocation']['cache_geo_bootstrap']    = $d['geolocation']['cache_geo_bootstrap'];
    if ( ! isset( $s['iab'] ) || ! is_array( $s['iab'] ) ) { $s['iab'] = array(); }
    $s['iab']['enabled'] = isset( $d['iab']['enabled'] ) ? $d['iab']['enabled'] : false;
  `);
}

function readCacheSettings(): Record<string, unknown> {
  const raw = wpEval(`
    $s = get_option( 'faz_settings', array() );
    echo wp_json_encode( array(
      'cache_compatibility' => isset( $s['banner_control']['cache_compatibility'] ) ? $s['banner_control']['cache_compatibility'] : null,
      'geo_targeting'       => isset( $s['geolocation']['geo_targeting'] ) ? $s['geolocation']['geo_targeting'] : null,
      'default_cache_compatibility' => \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings::get_instance()->get_defaults()['banner_control']['cache_compatibility'],
      'default_geo_targeting'       => \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings::get_instance()->get_defaults()['geolocation']['geo_targeting'],
    ) );
  `).trim();
  return JSON.parse(raw || '{}') as Record<string, unknown>;
}

/** Synthetic "accepted everything" cookie, same shape as wp-env's seedConsentedCookie(). */
function consentCookieValue(): string {
  const rev = parseInt(wpEval('echo faz_get_consent_revision();').trim(), 10) || 1;
  return (
    'consentid%3Ae2e-ls-cache%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes%2Cfunctional%3Ayes' +
    `%2Canalytics%3Ayes%2Cperformance%3Ayes%2Cuncategorized%3Ayes%2Cmarketing%3Ayes%2Crev%3A${rev}`
  );
}

/** Whether FAZ's own validator accepts that cookie — otherwise a "baseline" render would prove nothing. */
function serverAcceptsConsent(cookieValue: string): boolean {
  const b64 = Buffer.from(decodeURIComponent(cookieValue), 'utf8').toString('base64');
  return (
    wpEval(`
      $c = base64_decode( '${b64}' );
      echo ( function_exists( 'faz_get_valid_consent_cookie' ) && '' !== faz_get_valid_consent_cookie( $c ) && ! apply_filters( 'faz_strict_scope_fingerprint', false ) ) ? '1' : '0';
    `).trim() === '1'
  );
}

function decodeBody(body: Buffer): string {
  if (body.length > 2 && body[0] === 0x1f && body[1] === 0x8b) {
    return gunzipSync(body).toString('utf8');
  }
  return body.toString('utf8');
}

type Fetched = { status: number; headers: Record<string, string>; headerList: { name: string; value: string }[]; html: string };

/** One request from a cookie-less client, so LSCWP always sees a first-time guest. */
async function fetchPage(url: string, init: { userAgent?: string; headers?: Record<string, string> } = {}): Promise<Fetched> {
  const ctx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
  try {
    const resp = await ctx.get(url, {
      headers: { 'User-Agent': init.userAgent ?? HUMAN_UA, ...(init.headers ?? {}) },
      timeout: 60_000,
    });
    return { status: resp.status(), headers: resp.headers(), headerList: resp.headersArray(), html: decodeBody(await resp.body()) };
  } finally {
    await ctx.dispose();
  }
}

async function lsRender(settings: LsSettings, mode: LsMode, url = matrixUrl): Promise<string> {
  applyLs(settings, mode);
  const page = await fetchPage(url);
  expect(page.status, `${url} must render 200 under LiteSpeed (${mode})`).toBe(200);
  return page.html;
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

async function probedRequest(url: string, token: string, userAgent = HUMAN_UA, extraHeaders: Record<string, string> = {}): Promise<Fetched> {
  const response = await fetchPage(url, { userAgent, headers: { ...extraHeaders, 'X-Faz-E2E-Probe': token } });
  await expect
    .poll(() => readProbe(token).ls ?? '', { timeout: 10_000, message: `the lab never recorded LiteSpeed's verdict for ${token}` })
    .not.toBe('');
  return response;
}

function newToken(label: string): string {
  return `ls-${label}-${Date.now().toString(36)}`;
}

function readCapturedLog(): string {
  const raw = wpEval(`
    $f = WP_CONTENT_DIR . '/faz-e2e-optm-php.log';
    echo is_readable( $f ) ? base64_encode( (string) file_get_contents( $f ) ) : '';
  `).trim();
  return raw ? Buffer.from(raw, 'base64').toString('utf8') : '';
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

function restoreProbeKey(base: Baseline): string {
  const preferred = ['facebook-pixel', 'ga-monsterinsights', 'hotjar', 'linkedin', 'tiktok', 'hubspot', 'clarity', 'microsoft-ads'];
  const blockedKeys = new Set(base.blocked.map((entry) => entry.key));
  const pick = preferred.find((key) => blockedKeys.has(key));
  if (!pick) {
    throw new Error(`no same-origin matrix provider was blocked in the baseline (blocked: ${[...blockedKeys].join(', ') || 'none'})`);
  }
  return pick;
}

async function visitorFlow(browser: Browser, url: string, label: string, base: Baseline): Promise<void> {
  const provider = restoreProbeKey(base);
  const context: BrowserContext = await browser.newContext({ baseURL: WP_BASE });
  // Installed before the first navigation: the watchdog measures the whole
  // page view (heartbeat gaps, long tasks, heap), not three instants after it.
  await installMainThreadWatchdog(context);
  const thirdPartyBeforeConsent: string[] = [];
  let consented = false;
  try {
    await context.route(THIRD_PARTY, async (route) => {
      if (!consented) thirdPartyBeforeConsent.push(route.request().url());
      await route.abort();
    });
    const page = await context.newPage();
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });

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

  lsInstalled = pluginInstalled('litespeed-cache');
  if (!lsInstalled) {
    return;
  }

  lsWasActive = isPluginActive('litespeed-cache');
  flyingPressWasActive = isPluginActive('flying-press');
  labWasActive = isPluginActive(LAB_PLUGIN);
  matrixWasActive = isPluginActive(MATRIX_PLUGIN);
  auditLabWasActive = isPluginActive('faz-e2e-audit-lab');
  settingsSnapshot = wpEval(`echo base64_encode( serialize( get_option( 'faz_settings', array() ) ) );`).trim();

  // Two page optimisers on one request would make every verdict below
  // ambiguous. The audit-lab fixture pins the jurisdiction runtime on, which
  // makes every page country-dependent and would turn the control into a veto.
  if (flyingPressWasActive) wp(['plugin', 'deactivate', 'flying-press']);
  if (auditLabWasActive) wp(['plugin', 'deactivate', 'faz-e2e-audit-lab']);
  if (!lsWasActive) wp(['plugin', 'activate', 'litespeed-cache']);
  setupDone = true;

  for (const key of LS_KEYS) {
    const value = lsGet(key);
    lsSnapshot[key] = value;
    lsCurrent[key] = value;
  }

  resetDefaultBannerState();
  // Forced for 01–25 only: they test the OPTIMISER, which LSCWP bypasses on any
  // request FAZ vetoes, and on faz-test the dev fake-CF source makes every page
  // country-dependent (vetoed) unless compatibility is on. Not the default —
  // the default-settings contract is tested separately in 26–29.
  patchFazSettings(`
    $s['geolocation']['geo_targeting'] = false;
    $s['banner_control']['cache_compatibility'] = true;
  `);

  ensureFixturePlugin(MATRIX_PLUGIN);
  ensureFixturePlugin(LAB_PLUGIN);
  ensureProviderMatrixPage();
  resetProviderMatrixState();
  matrixUrl = readProviderMatrixUrl().trim();
  setOption('faz_e2e_optm_probe_page', PROVIDER_MATRIX_PAGE_SLUG);
  setOption('faz_e2e_capture_errors', 'yes');
  setOption('faz_e2e_break_faz_exclusions', BREAK_STATE);
  setOption('faz_e2e_no_country_source', 'no');
  wpEval(`@unlink( WP_CONTENT_DIR . '/faz-e2e-optm-php.log' );`);

  dsarPageCreated = wpEval(`echo get_page_by_path( '${DSAR_SLUG}', OBJECT, 'page' ) ? '0' : '1';`).trim() === '1';
  const dsarId = upsertPage(DSAR_SLUG, 'FAZ LiteSpeed Optimisation DSAR', '[faz_dsar_form]');
  dsarUrl = wpEval(`echo get_permalink( ${dsarId} );`).trim();

  baselineHtml = await lsRender({}, 'off');
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
      if (isPluginActive('litespeed-cache')) {
        for (const key of LS_KEYS) {
          const original = lsSnapshot[key];
          if (original !== undefined && lsCurrent[key] !== original) {
            cleanup(`restore LiteSpeed option ${key}`, () => lsSet(key, original));
          }
        }
        if (lsCacheChanged && lsCacheSnapshot !== null) {
          const original = lsCacheSnapshot;
          cleanup('restore LiteSpeed option cache', () => lsRawSet('cache', original));
        }
        cleanup('purge LiteSpeed', lsPurge);
      }
      cleanup('remove lab options and probe records', () => {
        wpEval(`
          global $wpdb;
          foreach ( (array) json_decode( '${JSON.stringify(LAB_OPTIONS)}', true ) as $name ) { delete_option( $name ); }
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
      if (dsarPageCreated) {
        cleanup('delete the DSAR page', () => {
          wpEval(`$p = get_page_by_path( '${DSAR_SLUG}', OBJECT, 'page' ); if ( $p ) { wp_delete_post( $p->ID, true ); }`);
        });
      }
      if (!labWasActive && isPluginActive(LAB_PLUGIN)) cleanup('deactivate the lab fixture', () => wp(['plugin', 'deactivate', LAB_PLUGIN]));
      if (!matrixWasActive && isPluginActive(MATRIX_PLUGIN)) cleanup('deactivate the provider matrix', () => wp(['plugin', 'deactivate', MATRIX_PLUGIN]));
      // Unconditional on purpose: an active LiteSpeed Cache left behind makes
      // every later spec print "Purged…" from wp eval and go falsely red.
      if (isPluginActive('litespeed-cache')) cleanup('deactivate LiteSpeed Cache', () => wp(['plugin', 'deactivate', 'litespeed-cache']));
      if (auditLabWasActive && !isPluginActive('faz-e2e-audit-lab')) cleanup('reactivate audit-lab', () => wp(['plugin', 'activate', 'faz-e2e-audit-lab']));
      if (flyingPressWasActive && !isPluginActive('flying-press')) cleanup('reactivate FlyingPress', () => wp(['plugin', 'activate', 'flying-press']));
    }
  } finally {
    if (lockHeld) {
      releaseSharedWordPressLock();
      lockHeld = false;
    }
  }
  if (errors.length > 0) {
    throw new AggregateError(errors, 'LiteSpeed optimisation E2E cleanup failed');
  }
});

test.beforeEach(() => {
  if (REQUIRE_PLUGIN) {
    expect(exclusiveRun, `FAZ_E2E_REQUIRE_CACHE_PLUGINS=1: this file must run with --workers=1 (got ${String(configuredWorkers)})`).toBe(true);
    expect(lsInstalled, 'FAZ_E2E_REQUIRE_CACHE_PLUGINS=1: LiteSpeed Cache (litespeed-cache) must be installed on this environment').toBe(true);
  }
  test.skip(
    !exclusiveRun,
    `this file was run with workers=${String(configuredWorkers)} — it activates LiteSpeed Cache and rewrites its options, ` +
      'so it needs the install to itself. Run `npm run test:e2e:litespeed` (adds --workers=1).',
  );
  test.skip(!lsInstalled, 'LiteSpeed Cache (litespeed-cache) is not installed on this environment');
});

/* ---------------------------------------------------------------------------
 * Tests
 * ------------------------------------------------------------------------ */

test.describe('LiteSpeed Cache page optimisation leaves FAZ intact', () => {
  test('01 baseline (optimiser bypassed): FAZ handles, bootstrap order, banner assets, blocked probes and parked embeds are all present', async () => {
    const base = requireBaseline();
    const ids = base.handles.map((handle) => handle.id);
    for (const id of REQUIRED_FAZ_HANDLES) {
      expect(ids, `baseline must contain ${id}`).toContain(id);
    }
    test.info().annotations.push({
      type: 'faz-handles',
      description: `named handles present: ${NAMED_FAZ_HANDLES.filter((id) => ids.includes(id)).join(', ')}; all: ${ids.join(', ')}`,
    });
    expect(base.cssKind, 'the banner stylesheet must be in the baseline (link or inline style)').not.toBe('none');
    expect(bootstrapOrderViolations(baselineHtml)).toEqual([]);
    expect(bannerAssetViolations(baselineHtml, base)).toEqual([]);
    const blockedKeys = base.blocked.map((entry) => entry.key);
    expect(blockedKeys, 'the Google tag probe must be blocked server-side before consent').toContain('gtag');
    expect(() => restoreProbeKey(base)).not.toThrow();
    expect(parkedEmbedViolations(baselineHtml, base)).toEqual([]);
    // Bypass proof: the baseline must carry no LiteSpeed rewriting at all.
    expect(baselineHtml).not.toMatch(/type=["']?litespeed\/javascript|\/litespeed\/js\/|data-lazyloaded/);
  });

  test('02 js_defer=0 on a cacheable request: FAZ handles keep their type, src, defer and order', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'optm-js_defer': 0 }, 'cacheable');
    expect(fazHandleViolations(html, base)).toEqual([]);
    expect(bootstrapOrderViolations(html)).toEqual([]);
    expect(blockedScriptViolations(html, base)).toEqual([]);
  });

  test('03 js_defer=1: inline FAZ scripts are not turned into data: URI defers and script.min.js keeps its own defer', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'optm-js_defer': 1 }, 'cacheable');
    expect(html, 'positive control: js_defer=1 must defer at least one non-FAZ script').toMatch(
      /src=["']?data:text\/javascript;base64|data-deferred=["']?1/,
    );
    expect(fazHandleViolations(html, base)).toEqual([]);
    expect(bootstrapOrderViolations(html)).toEqual([]);
    expect(blockedScriptViolations(html, base)).toEqual([]);
  });

  test('04 js_defer=2: no FAZ handle becomes type="litespeed/javascript" or data-src, and blocked scripts stay text/plain', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'optm-js_defer': 2 }, 'cacheable');
    expect(html, 'positive control: js_defer=2 must delay at least one non-FAZ script').toMatch(/type=["']?litespeed\/javascript/);
    expect(fazHandleViolations(html, base)).toEqual([]);
    expect(bootstrapOrderViolations(html)).toEqual([]);
    expect(blockedScriptViolations(html, base)).toEqual([]);
  });

  test('05 js_min: FAZ external scripts keep their own src instead of a wp-content/litespeed/js copy', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'optm-js_min': 1 }, 'cacheable');
    expect(html, 'positive control: js_min must serve at least one minified litespeed/js file').toMatch(/\/litespeed\/js\//);
    expect(fazHandleViolations(html, base)).toEqual([]);
    expect(bootstrapOrderViolations(html)).toEqual([]);
  });

  test('06 js_comb: FAZ scripts are not merged into the combined litespeed/js bundle', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'optm-js_comb': 1 }, 'cacheable');
    expect(html, 'positive control: js_comb must emit a combined litespeed/js bundle').toMatch(/\/litespeed\/js\//);
    expect(fazHandleViolations(html, base)).toEqual([]);
    expect(bootstrapOrderViolations(html)).toEqual([]);
    expect(blockedScriptViolations(html, base)).toEqual([]);
  });

  test('07 js_comb + js_comb_ext_inl: FAZ inline config and bootstrap are not folded into the bundle', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'optm-js_comb': 1, 'optm-js_comb_ext_inl': 1 }, 'cacheable');
    expect(html, 'positive control: the combined litespeed/js bundle must be emitted').toMatch(/\/litespeed\/js\//);
    expect(fazHandleViolations(html, base)).toEqual([]);
    expect(bootstrapOrderViolations(html)).toEqual([]);
    expect(blockedScriptViolations(html, base)).toEqual([]);
  });

  test('08 html_min: the banner template, stylesheet, bootstrap order and blocked scripts survive HTML minification', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'optm-html_min': 1 }, 'cacheable');
    expect(html, 'positive control: html_min must have run (LiteSpeed footprint)').toContain('Page optimized by LiteSpeed Cache');
    expect(fazHandleViolations(html, base)).toEqual([]);
    expect(bootstrapOrderViolations(html)).toEqual([]);
    expect(bannerAssetViolations(html, base)).toEqual([]);
    expect(blockedScriptViolations(html, base)).toEqual([]);
    expect(parkedEmbedViolations(html, base)).toEqual([]);
  });

  test('09 css_min + css_comb: the banner stylesheet is not combined and the banner template is intact', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'optm-css_min': 1, 'optm-css_comb': 1 }, 'cacheable');
    expect(html, 'positive control: css_comb must emit a litespeed/css file').toMatch(/\/litespeed\/css\//);
    expect(bannerAssetViolations(html, base)).toEqual([]);
    expect(fazHandleViolations(html, base)).toEqual([]);
  });

  test('10 ucss + css_comb + css_comb_ext_inl: the banner stylesheet is neither removed nor deferred', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'optm-ucss': 1, 'optm-css_comb': 1, 'optm-css_comb_ext_inl': 1 }, 'cacheable');
    expect(html, 'positive control: css_comb must emit a litespeed/css file').toMatch(/\/litespeed\/css\//);
    expect(bannerAssetViolations(html, base)).toEqual([]);
    expect(fazHandleViolations(html, base)).toEqual([]);
  });

  test('11 media-lazy: the parked Meta pixel keeps data-faz-src and lazy-load gives it no live src', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'media-lazy': 1 }, 'cacheable');
    const control = controlTag(html, 'img');
    expect(control, 'the control image must be on the page').toBeDefined();
    expect(
      'data-lazyloaded' in (control?.attrs ?? {}) || 'data-src' in (control?.attrs ?? {}),
      'positive control: media-lazy must lazy-load the same-origin control image',
    ).toBe(true);
    expect(parkedEmbedViolations(html, base)).toEqual([]);
  });

  test('12 media-iframe_lazy: the parked YouTube embed keeps data-faz-src and lazy-load gives it no live src', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'media-iframe_lazy': 1 }, 'cacheable');
    const control = controlTag(html, 'iframe');
    expect(control, 'the control iframe must be on the page').toBeDefined();
    expect(
      'data-lazyloaded' in (control?.attrs ?? {}) || 'data-src' in (control?.attrs ?? {}),
      'positive control: media-iframe_lazy must lazy-load the same-origin control iframe',
    ).toBe(true);
    expect(parkedEmbedViolations(html, base)).toEqual([]);
  });

  test('13 optm-localize: the blocked Google tag stays text/plain with its category and gets no executable local copy', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'optm-localize': 1 }, 'cacheable');
    test.info().annotations.push({
      type: 'localize-evidence',
      description: /\/litespeed\/localres\//.test(html) ? 'LiteSpeed localized at least one resource' : 'no localres URL in the page (domain list may not include the probe host)',
    });
    expect(blockedScriptViolations(html, base)).toEqual([]);
    expect(fazHandleViolations(html, base)).toEqual([]);
  });

  // FAILS on 42728bbc: no FAZ URL carries _litespeed_rm_qs=0
  // (`grep -rn _litespeed_rm_qs` finds nothing outside tests), so LSCWP's
  // remove_query_strings() strips ?ver= from script.min.js and every other
  // versioned FAZ asset. Green once the qs_rm fix (B2 in the 2026-10-16 cache
  // review, part of the fix/1342c-* series) appends _litespeed_rm_qs=0.
  test('14 optm-qs_rm: FAZ assets keep their path, order and defer, and keep a version-bearing URL (ver= intact + _litespeed_rm_qs=0)', async () => {
    const base = requireBaseline();
    const html = await lsRender({ 'optm-qs_rm': 1 }, 'cacheable');
    expect(
      nonFazVersionedCount(html),
      'positive control: qs_rm must strip ?ver= from at least one non-FAZ asset on this request',
    ).toBeLessThan(nonFazVersionedCount(baselineHtml));
    expect(fazHandleViolations(html, base)).toEqual([]);
    expect(bootstrapOrderViolations(html)).toEqual([]);
    expect(bannerAssetViolations(html, base)).toEqual([]);
    const versioned = fazVersionedAssets(baselineHtml);
    test.info().annotations.push({ type: 'faz-versioned-assets', description: versioned.map((asset) => `${asset.id} ver=${asset.ver}`).join(', ') });
    expect(versioned.map((asset) => asset.id), 'the baseline must carry script.min.js with ?ver=').toContain('faz-cookie-manager-js');
    expect(qsRmViolations(html, versioned)).toEqual([]);
  });

  test('15 Guest Mode (guest + guest_optm options): every FAZ handle stays live under Guest Optimization', async () => {
    const base = requireBaseline();
    const html = await lsRender({ guest: 1, guest_optm: 1 }, 'off');
    expect(html, 'positive control: Guest Optimization must delay at least one non-FAZ script').toMatch(/type=["']?litespeed\/javascript/);
    expect(fazHandleViolations(html, base)).toEqual([]);
    expect(bootstrapOrderViolations(html)).toEqual([]);
    expect(blockedScriptViolations(html, base)).toEqual([]);
    expect(bannerAssetViolations(html, base)).toEqual([]);
  });

  test('16 aggressive preset (LITESPEED_GUEST_OPTM + every JS/CSS/HTML/media option): the whole FAZ contract holds', async () => {
    const base = requireBaseline();
    const html = await lsRender(AGGRESSIVE, 'guest-constant');
    expect(html, 'positive control: the preset must delay at least one non-FAZ script').toMatch(/type=["']?litespeed\/javascript/);
    expect(fullContractViolations(html, base)).toEqual([]);
  });

  test('17 browser, js_defer=1: banner without interaction, responsive main thread, accept sets the cookie and runs the blocked script', async ({ browser }) => {
    applyLs({ 'optm-js_defer': 1 }, 'cacheable');
    resetProviderMatrixState();
    await visitorFlow(browser, matrixUrl, 'LiteSpeed js_defer=1', requireBaseline());
  });

  test('18 browser, js_defer=2: banner without interaction, responsive main thread, accept sets the cookie and runs the blocked script', async ({ browser }) => {
    applyLs({ 'optm-js_defer': 2 }, 'cacheable');
    resetProviderMatrixState();
    await visitorFlow(browser, matrixUrl, 'LiteSpeed js_defer=2', requireBaseline());
  });

  test('19 browser, Guest Optimization preset: banner without interaction, responsive main thread, accept sets the cookie and runs the blocked script', async ({ browser }) => {
    applyLs(AGGRESSIVE, 'guest-constant');
    resetProviderMatrixState();
    await visitorFlow(browser, matrixUrl, 'LiteSpeed Guest Optimization preset', requireBaseline());
  });

  test('20 control: an ordinary visitor render stays cacheable for LiteSpeed (proves tests 21–23 are not vacuous)', async () => {
    applyLs({}, 'cacheable');
    const token = newToken('control');
    const page = await probedRequest(matrixUrl, token);
    expect(page.status).toBe(200);
    expect(page.headers['x-litespeed-cache-control'] ?? '', 'FAZ must not send a LiteSpeed no-cache directive on an ordinary page').not.toMatch(/no-cache/i);
    expect(readProbe(token).ls, 'LiteSpeed must still consider an ordinary page cacheable').toBe('cacheable');
  });

  test('21 cache veto: a crawler render with hide_from_bots sends X-LiteSpeed-Cache-Control: no-cache and marks LiteSpeed not-cacheable', async () => {
    applyLs({}, 'cacheable');
    // The crawler variant only exists without Cache Compatibility Mode.
    patchFazSettings(`
      $s['banner_control']['cache_compatibility'] = false;
      $s['banner_control']['hide_from_bots'] = true;
    `);
    try {
      const token = newToken('crawler');
      const page = await probedRequest(matrixUrl, token, CRAWLER_UA);
      expect(page.status).toBe(200);
      expect(page.html, 'precondition: the crawler render must omit the banner template (bot-variant render active)').not.toContain('id="fazBannerTemplate"');
      expect(page.headers['x-litespeed-cache-control'] ?? '').toMatch(/no-cache/i);
      expect(readProbe(token).ls, 'LiteSpeed must hold the request not-cacheable').toBe('no-cache');
    } finally {
      patchFazSettings(`$s['banner_control']['cache_compatibility'] = true;`);
    }
  });

  test('22 cache veto: the DSAR form page sends the LiteSpeed no-cache directive and marks LiteSpeed not-cacheable', async () => {
    applyLs({}, 'cacheable');
    const token = newToken('dsar');
    const page = await probedRequest(dsarUrl, token);
    expect(page.status).toBe(200);
    expect(page.html, 'precondition: the DSAR form must render').toContain('faz-dsar-form');
    expect(page.headers['x-litespeed-cache-control'] ?? '').toMatch(/no-cache/i);
    const probe = readProbe(token);
    expect(probe.ls, 'LiteSpeed must hold the DSAR page not-cacheable').toBe('no-cache');
    expect(probe.donotcachepage).toBe('yes');
  });

  test('23 cache veto: a country-dependent render sends the LiteSpeed no-cache directive and marks LiteSpeed not-cacheable', async () => {
    applyLs({}, 'cacheable');
    setOption('faz_e2e_force_country_dependent', 'yes');
    try {
      const token = newToken('country');
      const page = await probedRequest(matrixUrl, token);
      expect(page.status).toBe(200);
      expect(page.headers['x-litespeed-cache-control'] ?? '').toMatch(/no-cache/i);
      expect(page.headers['cache-control'] ?? '').toMatch(/no-store/i);
      expect(readProbe(token).ls, 'LiteSpeed must hold the country-dependent page not-cacheable').toBe('no-cache');
    } finally {
      setOption('faz_e2e_force_country_dependent', 'no');
    }
  });

  test('24 falsifiability: with FAZ protections stripped by the lab fixture, the handle check reports violations under js_defer=2', async () => {
    const base = requireBaseline();
    setOption('faz_e2e_break_faz_exclusions', 'yes');
    try {
      const html = await lsRender({ 'optm-js_defer': 2 }, 'cacheable');
      const violations = fazHandleViolations(html, base);
      test.info().annotations.push({ type: 'violations-with-protections-stripped', description: violations.join(' | ') });
      expect(violations.length, 'stripping FAZ protections must make LiteSpeed touch FAZ — otherwise tests 02–19 cannot fail').toBeGreaterThan(0);
    } finally {
      setOption('faz_e2e_break_faz_exclusions', BREAK_STATE);
    }
  });

  test('25 no PHP warning, notice or FAZ diagnostic was logged during the LiteSpeed renders', async () => {
    applyLs({}, 'cacheable');
    const token = newToken('canary');
    await probedRequest(matrixUrl, token);
    const log = readCapturedLog();
    expect(log, 'the lab must have captured its canary line (otherwise the log redirect did not take)').toContain(`FAZ-E2E-OPTM-CANARY ${token}`);
    const lines = log.split('\n').filter((line) => /plugins\/faz-cookie-manager\/|FAZ Cookie Manager:/.test(line));
    const deprecations = lines.filter((line) => /PHP Deprecated/.test(line));
    if (deprecations.length > 0) {
      test.info().annotations.push({ type: 'faz-deprecations', description: deprecations.slice(0, 5).join(' | ') });
    }
    // The per-IP consent throttle's diagnostic is expected here: the browser
    // runs accept from the same 127.0.0.1 a few seconds apart, and the 10 s
    // window refuses the second record by design. Everything else FAZ logs,
    // and every PHP warning or notice, still fails the test.
    const throttle = /FAZ Cookie Manager: refused a consent record because the request was rate-limited/;
    const offending = lines.filter((line) => /PHP (?:Warning|Notice|Fatal error|Parse error)|FAZ Cookie Manager:/.test(line) && !throttle.test(line));
    expect(offending).toEqual([]);
  });
});

/* ---------------------------------------------------------------------------
 * (b) Consent-dependent HTML under the DEFAULT settings
 *
 * Every test above runs with Cache Compatibility Mode forced ON (beforeAll),
 * because they are about the optimiser rewriting FAZ markup and need a page
 * LiteSpeed is allowed to optimise; with the dev fake-CF source on faz-test and
 * compatibility off, FAZ's country veto would bypass the optimiser and every
 * positive control would fail. That choice also hid the one cache defect that
 * matters most: with compatibility at its default (OFF) and geo-targeting at its
 * default (ON) on an install with no country source, get_blocked_categories()
 * reads the visitor's consent cookie / Sec-GPC / fazcookie-dnsmpi, and
 * send_geo_cache_headers() emits no veto because the output is not
 * country-dependent. A visitor who accepted everything then MISSes into a page
 * whose trackers are executable, LiteSpeed keeps it public, and every later
 * visitor — including one who never consented — gets that copy.
 *
 * This block runs with the plugin's own defaults for those switches, with the
 * lab's "no country source" switch on (the shared-hosting case), and asks
 * LiteSpeed itself: requests carry `X-LSCache: on`, which makes LSCWP behave as
 * behind a LiteSpeed ADC and emit the X-LiteSpeed-Cache-Control header a real
 * server would obey.
 *
 * FAILS on 42728bbc: 27, 28 and 29 (public + executable trackers, see the
 * oracle consentCacheViolations()). 26 is the control and passes there. Green
 * with fix/1342c-cache-veto (veto when the render is not the most-protective
 * one) or with an invariant default render; either satisfies the oracle.
 * ------------------------------------------------------------------------ */

const LSCACHE_REQUEST: Record<string, string> = { 'X-LSCache': 'on' };

/**
 * The anonymous render under the default settings, taken once. Lazily, so any
 * of 27–29 can be run on its own with -g (the file is serial: on a build where
 * an earlier test is red, the later ones are skipped and must be run singly).
 */
async function anonymousDefaultRender(): Promise<Fetched> {
  applyLs({}, 'cacheable');
  const page = await probedRequest(matrixUrl, newToken('dflt-anon'), HUMAN_UA, LSCACHE_REQUEST);
  expect(page.status).toBe(200);
  return page;
}

async function requireStrictBase(): Promise<Baseline> {
  if (!strictBase) {
    strictBase = buildBaseline((await anonymousDefaultRender()).html);
  }
  return strictBase;
}

function annotateVerdict(label: string, page: Fetched): void {
  const verdict = cacheVerdict(page);
  test.info().annotations.push({
    type: `cache-verdict-${label}`,
    description: `X-LiteSpeed-Cache-Control=[${verdict.liteSpeed.join(' | ')}] Cache-Control=[${verdict.cacheControl.join(' | ')}]`,
  });
}

test.describe('Consent-dependent HTML stays out of the shared LiteSpeed cache under the DEFAULT settings', () => {
  test.beforeAll(() => {
    if (!exclusiveRun || !lsInstalled || !setupDone) {
      return;
    }
    lsCacheSnapshot = lsRawGet('cache');
    if (lsCacheSnapshot !== '1') {
      lsRawSet('cache', '1');
      lsCacheChanged = true;
    }
    applyDefaultCacheSettings();
    setOption('faz_e2e_no_country_source', 'yes');
  });

  test('26 default settings, control: the anonymous render is the most-protective one and LiteSpeed may cache it', async () => {
    const settings = readCacheSettings();
    test.info().annotations.push({ type: 'cache-settings', description: JSON.stringify(settings) });
    expect(settings.cache_compatibility, 'Cache Compatibility Mode must be at its default for this block').toBe(settings.default_cache_compatibility);
    expect(settings.geo_targeting, 'Geo-Targeting must be at its default for this block').toBe(settings.default_geo_targeting);
    const page = await anonymousDefaultRender();
    annotateVerdict('anonymous', page);
    const verdict = cacheVerdict(page);
    expect(
      verdict.liteSpeed.length,
      'positive control: with X-LSCache: on, LSCWP must behave as behind a LiteSpeed ADC and emit X-LiteSpeed-Cache-Control',
    ).toBeGreaterThan(0);
    expect(
      verdict.publicCacheable,
      'an anonymous render under the default settings must stay publicly cacheable — otherwise 27–29 prove nothing (and the whole site would be uncacheable)',
    ).toBe(true);
    strictBase = buildBaseline(page.html);
    const strictKeys = strictBase.blocked.map((entry) => entry.key);
    for (const entry of requireBaseline().blocked) {
      expect(strictKeys, `the anonymous default render must block "${entry.key}" exactly as the Cache Compatibility render does`).toContain(entry.key);
    }
  });

  test('27 default settings: a visitor who accepted everything gets a response that is vetoed for LiteSpeed or is the most-protective render', async () => {
    const strict = await requireStrictBase();
    applyLs({}, 'cacheable');
    const cookie = consentCookieValue();
    expect(serverAcceptsConsent(cookie), "precondition: FAZ's own validator must accept the synthetic accept-all cookie").toBe(true);
    const page = await probedRequest(matrixUrl, newToken('dflt-consent'), HUMAN_UA, {
      ...LSCACHE_REQUEST,
      Cookie: `fazcookie-consent=${cookie}`,
    });
    expect(page.status).toBe(200);
    annotateVerdict('accept-all', page);
    expect(consentCacheViolations(page, strict)).toEqual([]);
  });

  test('28 default settings: accepted everything + Sec-GPC: 1 — vetoed for LiteSpeed or the most-protective render', async () => {
    const strict = await requireStrictBase();
    applyLs({}, 'cacheable');
    const cookie = consentCookieValue();
    expect(serverAcceptsConsent(cookie), "precondition: FAZ's own validator must accept the synthetic accept-all cookie").toBe(true);
    const page = await probedRequest(matrixUrl, newToken('dflt-gpc'), HUMAN_UA, {
      ...LSCACHE_REQUEST,
      'Sec-GPC': '1',
      Cookie: `fazcookie-consent=${cookie}`,
    });
    expect(page.status).toBe(200);
    annotateVerdict('accept-all+gpc', page);
    expect(consentCacheViolations(page, strict)).toEqual([]);
  });

  test('29 default settings: accepted everything + fazcookie-dnsmpi=1 — vetoed for LiteSpeed or the most-protective render', async () => {
    const strict = await requireStrictBase();
    applyLs({}, 'cacheable');
    const cookie = consentCookieValue();
    expect(serverAcceptsConsent(cookie), "precondition: FAZ's own validator must accept the synthetic accept-all cookie").toBe(true);
    const page = await probedRequest(matrixUrl, newToken('dflt-dnsmpi'), HUMAN_UA, {
      ...LSCACHE_REQUEST,
      Cookie: `fazcookie-consent=${cookie}; fazcookie-dnsmpi=1`,
    });
    expect(page.status).toBe(200);
    annotateVerdict('accept-all+dnsmpi', page);
    expect(consentCacheViolations(page, strict)).toEqual([]);
  });
});
