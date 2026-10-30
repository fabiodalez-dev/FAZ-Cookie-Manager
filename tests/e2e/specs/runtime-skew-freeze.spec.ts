/**
 * Runtime skew and the bootstrap's waiting window — the page must never freeze.
 *
 * Why this file exists: 1.34.1 moved the consent runtime to `defer` and put a
 * synchronous inline bootstrap (frontend/js/bootstrap.js, printed as
 * faz-cookie-manager-js-before) in charge of every DOM insertion until the
 * runtime calls `_fazBootstrap.start()` / `.finish()`. A site on LiteSpeed froze
 * with it ("Version 1.34.1 causes frontend pages to freeze"). The mechanism, read
 * in bootstrap.js at 42728bbc (the appendChild/insertBefore/replaceChild wrapper
 * and the append/prepend/before/after/replaceWith/replaceChildren loop under
 * "Delay dynamic insertions"): when the inserted node carries a resource
 * (`resources()` matches ANY <img>, whatever its origin) or the parent already
 * has a held insertion, the wrapper queues a closure and returns the node
 * WITHOUT taking it out of its old parent. The idiom
 *
 *     while (source.firstChild) target.appendChild(source.firstChild);
 *
 * then never terminates: `source.firstChild` is the same node on every turn,
 * every turn pushes one more closure, memory grows, DOMContentLoaded never
 * arrives and the page stays half drawn.
 *
 * Two ways to hit it, both pinned here:
 *   (a1) HTML new + runtime old. LiteSpeed "Remove Query Strings" strips
 *        `?ver=` from script.min.js, so the browser or the edge keeps serving
 *        the 1.34.0 runtime from the unchanged URL under 1.34.1 HTML. 1.34.0
 *        never calls `_fazBootstrap.start()`; it renders the banner in a
 *        microtask right after its own evaluation — before DOMContentLoaded,
 *        so while the bootstrap is still pending — with
 *        `while (doc.body.firstChild) fragment.appendChild(doc.body.firstChild)`
 *        over the banner template, which contains <img src=…revisit.svg>.
 *        Reproduced here by serving `git show v1.34.0:frontend/js/script.min.js`
 *        for the runtime URL under the HTML the site renders now.
 *   (a2) Any third-party script using the same idiom on image-bearing nodes
 *        while the document is still parsing (the bootstrap's waiting window).
 *        Printed by the fixture plugin faz-e2e-runtime-skew-lab, once with an
 *        iteration ceiling (a deterministic value: did the loop end?) and once
 *        raw (the real thing: does the page survive?).
 * And one server-side regression of the same release train:
 *   (e)  the buffer's media pass (class-frontend.php 4c, the
 *        `<(video|audio|source|embed|object|track|input)…>` preg_replace_callback)
 *        runs over the whole document, <script> bodies included. A JSON string
 *        literal holding `<video poster="https://i.ytimg.com/…">` gets
 *        double-quoted attributes inserted and the inline script stops parsing.
 *        Pass 5b already skips script/style/textarea/title/xmp and comments; 4c
 *        did not.
 *
 * FALSIFIABILITY — what turns each test red:
 *   RS-02, RS-03, RS-04 are red on 42728bbc (fix/1342-compliance-review HEAD) for
 *   the bootstrap reason above, and go green with fix/1342c-bootstrap-hold
 *   (detach a held node into a private fragment and rebuild the order on
 *   replay; dismantle the bootstrap when a runtime that never calls start()
 *   has run). RS-05 is red on 42728bbc for the 4c reason above and goes green
 *   with fix/1342c-buffer-passes (4c skips raw-text elements the way 5b does).
 *   Every test also carries a positive control so that "nothing happened" can
 *   never pass for "nothing broke": the old runtime really is the pre-handoff
 *   one and was really served (RS-02), the waiting window was really open when
 *   the mover ran (RS-03/04: the runtime had not started yet), and the media
 *   pass really ran on the response (RS-05: the control <video> is parked).
 *
 * ENVIRONMENT
 * -----------
 * Needs the tag v1.34.0 in the repository this file runs from (git show), or
 * FAZ_E2E_OLD_RUNTIME_FILE pointing at a copy of that script.min.js. Activates
 * the fixture plugin faz-e2e-runtime-skew-lab and creates one page; afterAll
 * removes the option, deletes the page if this file created it and deactivates
 * the fixture if it was not active before. Takes the shared WordPress lock.
 * Browser-matrix aware: run with FAZ_E2E_BROWSERS=chromium,firefox,webkit for the
 * real engines; the long-task and heap probes are Chromium-only and are skipped
 * (with the heartbeat still enforced) elsewhere.
 *
 *     npm run test:e2e:runtime-skew
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gunzipSync } from 'node:zlib';
import { request as playwrightRequest, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { wp, wpEval, setOption, deleteOption, ensureFixturePlugin, isPluginActive, upsertPage } from '../utils/wp-env';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { acquireSharedWordPressLock, releaseSharedWordPressLock } from '../utils/shared-wordpress-lock';
import {
  assertMainThreadHealthy,
  closeWithin,
  evaluateWithin,
  installMainThreadWatchdog,
  reachedDomContentLoaded,
} from '../utils/main-thread-watchdog';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const LAB_PLUGIN = 'faz-e2e-runtime-skew-lab';
const PAGE_SLUG = 'faz-e2e-runtime-skew';
const OLD_RUNTIME_TAG = 'v1.34.0';
/** sha256 of `git show v1.34.0:frontend/js/script.min.js`, so a moved tag cannot silently change the subject. */
const OLD_RUNTIME_SHA256 = '94317a20b09a68cd699f9172db54c74f620b475137bd1ecb8cc72cdc050fcb07';

const POSTER_EXPECTED = {
  __fazE2ePosterProbe1: '<video poster="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg"></video>',
  __fazE2ePosterProbe2: "<video poster='https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'></video>",
  __fazE2ePosterProbe3: { html: '<source src="https://i.ytimg.com/vi/dQw4w9WgXcQ/clip.mp4" type="video/mp4">' },
} as const;

let lockHeld = false;
let labWasActive = false;
let pageCreated = false;
let setupDone = false;
let pageUrl = '';

// Not serial on purpose: the tests are independent, and on a build where one
// is red the others must still run and report (setup is cheap to redo).
test.describe.configure({ timeout: 180_000 });

/* ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------ */

function readOldRuntime(): string {
  const override = process.env.FAZ_E2E_OLD_RUNTIME_FILE;
  if (override) {
    if (!existsSync(override)) throw new Error(`FAZ_E2E_OLD_RUNTIME_FILE=${override} does not exist`);
    return readFileSync(override, 'utf8');
  }
  try {
    return execFileSync('git', ['-C', REPO_ROOT, 'show', `${OLD_RUNTIME_TAG}:frontend/js/script.min.js`], {
      encoding: 'utf8',
      maxBuffer: 16 * 1024 * 1024,
    });
  } catch (error) {
    throw new Error(
      `cannot read ${OLD_RUNTIME_TAG}:frontend/js/script.min.js from ${REPO_ROOT} (${error instanceof Error ? error.message : String(error)}). ` +
        'Fetch the tags (git fetch --tags) or set FAZ_E2E_OLD_RUNTIME_FILE.',
    );
  }
}

async function sha256(text: string): Promise<string> {
  const { createHash } = await import('node:crypto');
  return createHash('sha256').update(text, 'utf8').digest('hex');
}

function decodeBody(body: Buffer): string {
  if (body.length > 2 && body[0] === 0x1f && body[1] === 0x8b) return gunzipSync(body).toString('utf8');
  return body.toString('utf8');
}

/** A cookie-less fetch: the anonymous, nothing-consented render. */
async function fetchHtml(url: string): Promise<string> {
  const ctx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
  try {
    const response = await ctx.get(url, { timeout: 60_000 });
    expect(response.status(), `${url} must render 200`).toBe(200);
    return decodeBody(await response.body());
  } finally {
    await ctx.dispose();
  }
}

async function fetchText(url: string): Promise<string> {
  const ctx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
  try {
    const response = await ctx.get(url, { timeout: 60_000 });
    expect(response.status(), `${url} must answer 200`).toBe(200);
    return decodeBody(await response.body());
  } finally {
    await ctx.dispose();
  }
}

function scriptTagById(html: string, id: string): { attrs: string; body: string } | null {
  const escaped = id.replace(/[.*+?^${}()|[\]\\-]/g, '\\$&');
  const re = new RegExp(`<script\\b([^>]*\\bid=["']${escaped}["'][^>]*)>([\\s\\S]*?)<\\/script\\b[^>]*>`, 'i');
  const match = re.exec(html);
  return match ? { attrs: match[1], body: match[2] } : null;
}

function attrValue(attrs: string, name: string): string | null {
  const match = new RegExp(`\\s${name}\\s*=\\s*(?:"([^"]*)"|'([^']*)'|([^\\s>]+))`, 'i').exec(` ${attrs}`);
  return match ? (match[1] ?? match[2] ?? match[3] ?? '') : null;
}

function isRuntimeUrl(url: URL): boolean {
  return /\/faz-cookie-manager\/frontend\/js\/script(?:\.min)?\.js$/.test(url.pathname);
}

type Visit = {
  context: BrowserContext;
  page: Page;
  pageErrors: string[];
  crashed: () => boolean;
};

async function openVisit(browser: Browser, url: string, setup?: (context: BrowserContext) => Promise<void>): Promise<Visit> {
  const context = await browser.newContext({ baseURL: WP_BASE });
  await installMainThreadWatchdog(context);
  if (setup) await setup(context);
  const page = await context.newPage();
  const pageErrors: string[] = [];
  let crashed = false;
  page.on('pageerror', (error) => pageErrors.push(`${error.name}: ${error.message}`));
  page.on('crash', () => {
    crashed = true;
  });
  // `commit`, not `domcontentloaded`: whether DOMContentLoaded arrives at all
  // is the question, and it is asked below with its own deadline.
  await page.goto(url, { waitUntil: 'commit', timeout: 60_000 });
  return { context, page, pageErrors, crashed: () => crashed };
}

async function expectDomContentLoaded(visit: Visit, label: string): Promise<void> {
  const reached = await reachedDomContentLoaded(visit.page, 20_000);
  expect(visit.crashed(), `${label}: the renderer crashed (out of memory in a runaway loop?)`).toBe(false);
  expect(reached, `${label}: DOMContentLoaded never arrived within 20 s — the page is frozen before the end of parsing`).toBe(true);
}

/* ---------------------------------------------------------------------------
 * Lifecycle
 * ------------------------------------------------------------------------ */

test.beforeAll(async ({}, testInfo) => {
  testInfo.setTimeout(10 * 60_000);
  await acquireSharedWordPressLock();
  lockHeld = true;
  labWasActive = isPluginActive(LAB_PLUGIN);
  setupDone = true;
  resetDefaultBannerState();
  ensureFixturePlugin(LAB_PLUGIN);
  pageCreated = wpEval(`echo get_page_by_path( '${PAGE_SLUG}', OBJECT, 'page' ) ? '0' : '1';`).trim() === '1';
  const id = upsertPage(PAGE_SLUG, 'FAZ Runtime Skew Lab', 'FAZ runtime-skew lab page.');
  pageUrl = wpEval(`echo get_permalink( ${id} );`).trim();
  setOption('faz_e2e_runtime_skew_page', PAGE_SLUG);
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
      cleanup('remove the lab option', () => deleteOption('faz_e2e_runtime_skew_page'));
      if (pageCreated) {
        cleanup('delete the lab page', () => {
          wpEval(`$p = get_page_by_path( '${PAGE_SLUG}', OBJECT, 'page' ); if ( $p ) { wp_delete_post( $p->ID, true ); }`);
        });
      }
      if (!labWasActive && isPluginActive(LAB_PLUGIN)) cleanup('deactivate the lab', () => wp(['plugin', 'deactivate', LAB_PLUGIN]));
    }
  } finally {
    if (lockHeld) {
      releaseSharedWordPressLock();
      lockHeld = false;
    }
  }
  if (errors.length > 0) throw new AggregateError(errors, 'runtime-skew E2E cleanup failed');
});

/* ---------------------------------------------------------------------------
 * Tests
 * ------------------------------------------------------------------------ */

test.describe('a page rendered by this build never freezes while the consent runtime is pending or stale', () => {
  test('RS-01 precondition: the page carries the deferred runtime, the inline bootstrap and an image-bearing banner template', async () => {
    const html = await fetchHtml(pageUrl);
    const before = scriptTagById(html, 'faz-cookie-manager-js-before');
    expect(before, 'faz-cookie-manager-js-before (inline bootstrap) must be on the page — without it nothing here is under test').not.toBeNull();
    expect(before?.body ?? '', 'the -js-before payload must define window._fazBootstrap').toContain('_fazBootstrap');
    const runtime = scriptTagById(html, 'faz-cookie-manager-js');
    expect(runtime, 'faz-cookie-manager-js (script.min.js) must be on the page').not.toBeNull();
    expect(/\sdefer(?:[\s=>]|$)/i.test(` ${runtime?.attrs ?? ''} `), 'script.min.js must be deferred (the waiting window exists only then)').toBe(true);
    const template = /<template\b[^>]*\bid=["']fazBannerTemplate["'][^>]*>([\s\S]*?)<\/template>/i.exec(html)?.[1] ?? '';
    expect(template, 'the banner template must contain an <img> (the old runtime moves it with while(firstChild))').toMatch(/<img\b/i);
    const runtimeSrc = attrValue(runtime?.attrs ?? '', 'src') ?? '';
    const deployed = await fetchText(new URL(runtimeSrc.replace(/&amp;/g, '&'), pageUrl).toString());
    expect(deployed, 'the deployed runtime must be the handoff-aware one (calls _fazBootstrap.start)').toContain('_fazBootstrap.start');
  });

  // FAILS on 42728bbc: 1.34.0's _fazRenderBanner() runs while the bootstrap is
  // pending and loops forever on the held template nodes (see header). Green
  // with fix/1342c-bootstrap-hold.
  test('RS-02 HTML from this build + the 1.34.0 runtime (stale ?ver-less cache): DOMContentLoaded arrives, the page stays responsive and the banner appears', async ({ browser }) => {
    const oldRuntime = readOldRuntime();
    expect(await sha256(oldRuntime), `${OLD_RUNTIME_TAG} script.min.js is not the file this test was written against`).toBe(OLD_RUNTIME_SHA256);
    expect(oldRuntime, 'positive control: the stale runtime must be the pre-handoff one').not.toContain('_fazBootstrap.start');
    let served = 0;
    const visit = await openVisit(browser, pageUrl, async (context) => {
      await context.route(isRuntimeUrl, async (route) => {
        served += 1;
        await route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: oldRuntime });
      });
    });
    try {
      await expectDomContentLoaded(visit, 'HTML new + runtime 1.34.0');
      expect(served, 'positive control: the 1.34.0 runtime must actually have been served for script.min.js').toBeGreaterThan(0);
      const stats = await assertMainThreadHealthy(visit.page, 'HTML new + runtime 1.34.0');
      test.info().annotations.push({ type: 'watchdog', description: JSON.stringify(stats) });
      await expect(visit.page.locator('[data-faz-tag="notice"]').first(), 'the 1.34.0 runtime must still draw the banner under the new HTML').toBeVisible({ timeout: 15_000 });
      await assertMainThreadHealthy(visit.page, 'HTML new + runtime 1.34.0 (banner drawn)');
    } finally {
      await closeWithin(visit.context);
    }
  });

  // FAILS on 42728bbc: target.appendChild(p-with-img) is queued without
  // detaching p, so the guarded loop hits its 1000-iteration ceiling and two
  // of the three nodes are never moved. Green with fix/1342c-bootstrap-hold.
  test('RS-03 third-party while(firstChild) appendChild on image-bearing nodes during the waiting window (guarded): the loop ends after 3 turns and the order survives', async ({ browser }) => {
    const visit = await openVisit(browser, `${pageUrl}${pageUrl.includes('?') ? '&' : '?'}faz_e2e_mover=guarded`);
    try {
      await expectDomContentLoaded(visit, 'guarded mover');
      await visit.page.waitForLoadState('load', { timeout: 30_000 }).catch(() => undefined);
      const read = await evaluateWithin(
        visit.page,
        () => {
          const w = window as unknown as {
            __fazE2eMover?: { iterations: number; tripped: boolean; finished: boolean; sourceLeft: number; bootstrap: boolean; runtimeStarted: boolean };
          };
          const target = document.getElementById('faz-e2e-mover-target');
          const source = document.getElementById('faz-e2e-mover-source');
          return {
            mover: w.__fazE2eMover ?? null,
            targetOrder: target ? Array.from(target.children).map((el) => (el as HTMLElement).dataset.i ?? '?') : null,
            targetImages: target ? target.querySelectorAll('img').length : -1,
            sourceLeft: source ? source.childNodes.length : -1,
          };
        },
        5_000,
      );
      expect(read.ok, 'guarded mover: the page did not answer').toBe(true);
      const value = read.ok ? read.value : null;
      expect(value?.mover, 'positive control: the fixture mover script must have run').not.toBeNull();
      expect(value?.mover?.bootstrap, 'positive control: the inline bootstrap must have been installed when the loop ran').toBe(true);
      expect(value?.mover?.runtimeStarted, 'positive control: the loop must have run inside the waiting window (runtime not started yet)').toBe(false);
      expect(value?.mover?.tripped, 'the while(firstChild) loop hit its 1000-iteration ceiling — an insertion was held without detaching the node').toBe(false);
      expect(value?.mover?.iterations, 'one turn per child: three image-bearing <p> nodes').toBe(3);
      expect(value?.mover?.sourceLeft, 'the source container must be empty when the loop returns').toBe(0);
      expect(value?.targetOrder, 'after the handoff every moved node must be in the target, in document order').toEqual(['0', '1', '2']);
      expect(value?.targetImages, 'the moved nodes keep their images').toBe(3);
      expect(value?.sourceLeft).toBe(0);
      await assertMainThreadHealthy(visit.page, 'guarded mover');
      await expect(visit.page.locator('[data-faz-tag="notice"]').first(), 'the banner must still appear').toBeVisible({ timeout: 15_000 });
    } finally {
      await closeWithin(visit.context);
    }
  });

  // FAILS on 42728bbc: same loop without a ceiling — the renderer spins (and
  // grows its queue) before DOMContentLoaded, so the DOMContentLoaded deadline
  // or the evaluate deadline fails. Green with fix/1342c-bootstrap-hold.
  test('RS-04 the same idiom unguarded (the real third-party script): the page reaches DOMContentLoaded, stays responsive and keeps the moved nodes', async ({ browser }) => {
    const visit = await openVisit(browser, `${pageUrl}${pageUrl.includes('?') ? '&' : '?'}faz_e2e_mover=raw`);
    try {
      await expectDomContentLoaded(visit, 'raw mover');
      const stats = await assertMainThreadHealthy(visit.page, 'raw mover');
      test.info().annotations.push({ type: 'watchdog', description: JSON.stringify(stats) });
      const window0 = await evaluateWithin(
        visit.page,
        () => {
          const mover = (window as unknown as { __fazE2eMover?: { bootstrap: boolean; runtimeStarted: boolean; finished: boolean } }).__fazE2eMover;
          return mover ? { bootstrap: mover.bootstrap, runtimeStarted: mover.runtimeStarted, finished: mover.finished } : null;
        },
        5_000,
      );
      expect(window0.ok, 'raw mover: the page did not answer').toBe(true);
      const mover = window0.ok ? window0.value : null;
      expect(mover?.finished, 'the unguarded while(firstChild) loop must have returned').toBe(true);
      expect(mover?.bootstrap, 'positive control: the inline bootstrap must have been installed when the loop ran').toBe(true);
      expect(mover?.runtimeStarted, 'positive control: the loop must have run inside the waiting window (runtime not started yet)').toBe(false);
      await expect
        .poll(
          async () => {
            const again = await evaluateWithin(
              visit.page,
              () => {
                const target = document.getElementById('faz-e2e-mover-target');
                return target ? Array.from(target.children).map((el) => (el as HTMLElement).dataset.i ?? '?').join(',') : '';
              },
              3_000,
            );
            return again.ok ? again.value : 'unanswered';
          },
          { timeout: 15_000, message: 'raw mover: the three nodes must end up in the target in order' },
        )
        .toBe('0,1,2');
      await expect(visit.page.locator('[data-faz-tag="notice"]').first(), 'the banner must still appear').toBeVisible({ timeout: 15_000 });
    } finally {
      await closeWithin(visit.context);
    }
  });

  // FAILS on 42728bbc: pass 4c rewrites the <video>/<source> tags inside the
  // JSON string literals (probe 2 certainly: `poster='…'` gains a
  // double-quoted data-faz-poster inside a double-quoted string), the scripts
  // throw SyntaxError and the globals stay undefined. Green with
  // fix/1342c-buffer-passes.
  test('RS-05 an inline data script whose JSON string contains <video poster>/<source src> markup stays executable and byte-identical', async ({ browser }) => {
    const html = await fetchHtml(pageUrl);
    // Positive control: the media pass ran on this response and parked the
    // real element, so a page on which the pass never ran cannot pass.
    const control = /<video\b[^>]*\bid=["']?faz-e2e-poster-control["']?[^>]*>/i.exec(html)?.[0] ?? '';
    expect(control, 'the control <video> must be on the page').not.toBe('');
    expect(control, 'positive control: the server media pass must have parked the control poster (data-faz-poster)').toMatch(/data-faz-poster\s*=/i);
    expect(control, 'positive control: the control <video> must not keep a live i.ytimg.com poster').not.toMatch(/\sposter\s*=\s*["']?https:\/\/i\.ytimg\.com/i);
    for (const n of [1, 2, 3]) {
      const tag = scriptTagById(html, `faz-e2e-poster-probe-${n}`);
      expect(tag, `probe ${n} must be on the page`).not.toBeNull();
      expect((attrValue(tag?.attrs ?? '', 'type') ?? '').toLowerCase(), `probe ${n} is a data-only assignment and must not be re-typed`).not.toBe('text/plain');
    }

    const visit = await openVisit(browser, pageUrl);
    try {
      await expectDomContentLoaded(visit, 'poster probes');
      await visit.page.waitForLoadState('load', { timeout: 30_000 }).catch(() => undefined);
      const read = await evaluateWithin(
        visit.page,
        () => {
          const w = window as unknown as Record<string, unknown>;
          return {
            p1: w.__fazE2ePosterProbe1 ?? null,
            p2: w.__fazE2ePosterProbe2 ?? null,
            p3: w.__fazE2ePosterProbe3 ?? null,
          };
        },
        5_000,
      );
      expect(read.ok, 'poster probes: the page did not answer').toBe(true);
      const syntaxErrors = visit.pageErrors.filter((message) => /SyntaxError/i.test(message));
      expect(syntaxErrors, 'no inline script may stop parsing because the server rewrote a string literal').toEqual([]);
      const value = read.ok ? read.value : { p1: null, p2: null, p3: null };
      expect(value.p1, 'probe 1 (escaped double quotes) must run and keep its value').toBe(POSTER_EXPECTED.__fazE2ePosterProbe1);
      expect(value.p2, 'probe 2 (single quotes inside a JSON string) must run and keep its value').toBe(POSTER_EXPECTED.__fazE2ePosterProbe2);
      expect(value.p3, 'probe 3 (<source src> in an object) must run and keep its value').toEqual(POSTER_EXPECTED.__fazE2ePosterProbe3);
    } finally {
      await closeWithin(visit.context);
    }
  });
});
