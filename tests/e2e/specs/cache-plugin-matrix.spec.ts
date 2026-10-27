import { test, expect } from '../fixtures/wp-fixture';
import type { APIRequestContext, APIResponse, Browser, Page } from '@playwright/test';
import { request as playwrightRequest } from '@playwright/test';
import { getWpLoginPath } from '../utils/wp-auth';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { upsertPage, wp, wpEval } from '../utils/wp-env';

/**
 * Cache plugin matrix — one real cache / optimisation plugin at a time.
 *
 * This file is generic on purpose: it knows nothing about the plugin under
 * test beyond what scripts/cache-plugin-matrix.sh passes in the environment.
 * The driver installs, activates and configures the plugin, runs this file,
 * then deactivates it and cleans the drop-ins up. The file itself never
 * activates or deactivates anything.
 *
 *   FAZ_CACHE_PLUGIN            slug under test (the file skips without it)
 *   FAZ_CACHE_PAGECACHE         "1" when the plugin serves a full-page cache on
 *                               this host, "0" for optimisation-only plugins
 *                               (Autoptimize, Speed Optimizer outside SiteGround,
 *                               LiteSpeed Cache on nginx)
 *   FAZ_CACHE_NATIVE_PURGE_PHP  PHP that purges the plugin's own cache, used to
 *                               isolate the tests from the FAZ purge adapter
 *                               (only test 05 is about the adapter)
 *   FAZ_CACHE_DUMP_DIR          absolute directory for the HTML the tests judged
 *
 * What is checked, in order:
 *   01 the plugin actually caches the fixture page (a HIT within a few tries)
 *   02 the cached copy carries the banner template, the consent runtime, its
 *      config and the server-side blocked provider script (type="text/plain")
 *   03 a crawler visit (hide_from_bots on) never leaves a banner-less copy for
 *      the next human visitor, and the crawler response says no-store
 *   04 a visitor who accepts everything never leaves a consented copy for the
 *      next visitor: the raw page still blocks the provider and ships the banner
 *   05 a real banner save through the REST API purges the page cache: the
 *      very next anonymous response shows the new banner title
 *   06 the plugin's REST routes are never served from the page cache
 *   07 in a real browser with the plugin's optimisations on, the banner mounts
 *      without any interaction, _fazConfig is defined, the provider stays
 *      blocked until accept, and accept releases it
 *   08 a logged-in administrator is never served the anonymous cached copy
 *
 * HIT detection is generic: either a header the plugin sets on a hit
 * (Surge, Jetpack Boost, FlyingPress, Super Page Cache) or a generation stamp
 * in an HTML comment that comes back byte-identical on the next request (WP
 * Super Cache, W3TC, WP Fastest Cache, Cache Enabler, Hummingbird, Breeze,
 * WP-Optimize, Comet Cache, Cachify). The stamp is reported in the failure
 * message so a plugin whose marker changed is diagnosed, not guessed.
 */

const BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const SLUG = (process.env.FAZ_CACHE_PLUGIN ?? '').trim();
const PAGE_CACHE = (process.env.FAZ_CACHE_PAGECACHE ?? '1') !== '0';
const NATIVE_PURGE_PHP = process.env.FAZ_CACHE_NATIVE_PURGE_PHP ?? '';
const FIXTURE_SLUG = 'faz-cache-matrix';
const GTAG_SRC = 'https://www.googletagmanager.com/gtag/js?id=G-FAZMATRIX';
const FIXTURE_HTML =
  '<p>FAZ cache matrix fixture.</p>' +
  `<script src="${GTAG_SRC}"></script>` +
  '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","G-FAZMATRIX");</script>';

/** Keep the HTML a plugin produced next to the verdict (tests/e2e/reports/cache-matrix), so a red can be read without re-running. */
function dump(name: string, body: string): void {
  try {
    const dir = process.env.FAZ_CACHE_DUMP_DIR || join(test.info().config.rootDir, '..', 'reports', 'cache-matrix');
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, `${SLUG}-${name}.html`), body, 'utf8');
  } catch {
    // reporting only
  }
}

const HUMAN_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 faz-cache-matrix';
const BOT_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

/** Header a plugin sets only when it served the response from its cache. */
const HIT_HEADERS: Array<{ name: string; hit: RegExp }> = [
  { name: 'x-cache', hit: /^hit/i }, // Surge
  { name: 'x-jetpack-boost-cache', hit: /^hit/i },
  { name: 'x-flying-press-cache', hit: /^hit/i },
  { name: 'x-wp-spc-disk-cache', hit: /^hit/i }, // Super Page Cache 5.x
  { name: 'x-wp-cf-super-cache-disk', hit: /hit|cache/i },
  { name: 'x-litespeed-cache', hit: /^hit/i },
  { name: 'x-cache-enabler', hit: /^hit/i },
];

/** Generation stamps: identical on two consecutive responses means the second was served from cache. */
const STAMPS: RegExp[] = [
  /Cached page generated by WP-Super-Cache on [^>]*/,
  /Served from: [^\n<]*by W3 Total Cache/,
  /WP Fastest Cache file was created in [^>]*/,
  /Cache Enabler by KeyCDN @ [^>]*/,
  /Hummingbird cache file was created in [^>]*/,
  /Cache served by breeze CACHE[^>]*/,
  /Cached by WP-Optimize[^>]*/,
  /Comet Cache file (?:path|built)[^>]*/,
  /Cachify \|[^>]*/,
  /Cached by FlyingPress[^>]*/i,
  /Cached by Jetpack Boost[^>]*/i,
];

type Fetched = { html: string; headers: Record<string, string>; status: number; stamp: string; headerHit: boolean };

async function fetchAnon(url: string, ua = HUMAN_UA, cookies: string[] = []): Promise<Fetched> {
  const ctx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
  try {
    // A browser's Accept header: Cache Enabler refuses to store a response
    // whose request did not accept text/html, and the Playwright request
    // context sends */* by default.
    const res: APIResponse = await ctx.get(url, {
      headers: {
        'User-Agent': ua,
        Accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ...(cookies.length ? { Cookie: cookies.join('; ') } : {}),
      },
      maxRedirects: 0,
    });
    const html = await res.text();
    const headers = res.headers();
    let stamp = '';
    for (const re of STAMPS) {
      const m = html.match(re);
      if (m) {
        stamp = m[0];
        break;
      }
    }
    const headerHit = HIT_HEADERS.some((h) => headers[h.name] !== undefined && h.hit.test(headers[h.name]));
    return { html, headers, status: res.status(), stamp, headerHit };
  } finally {
    await ctx.dispose();
  }
}

/** Fetch until the plugin serves the page from its cache. Returns the HIT response. */
async function warmUntilHit(url: string, tries = 20): Promise<Fetched> {
  let previous = '';
  let last: Fetched | null = null;
  for (let i = 0; i < tries; i += 1) {
    const got = await fetchAnon(url);
    last = got;
    if (got.headerHit) return got;
    if (got.stamp && previous && got.stamp === previous) return got;
    previous = got.stamp;
    await new Promise((r) => setTimeout(r, 400));
  }
  const seenHeaders = HIT_HEADERS.map((h) => `${h.name}=${last?.headers[h.name] ?? '-'}`).join(' ');
  throw new Error(
    `${SLUG}: no cache HIT for ${url} after ${tries} requests. last stamp="${last?.stamp || '(none)'}" ` +
      `cache-control="${last?.headers['cache-control'] ?? '-'}" ${seenHeaders}`
  );
}

// HTML minifiers (W3TC, Breeze, Cache Enabler) drop attribute quotes, so every
// marker below is quote-agnostic. The banner template is the one marker that
// survives every optimiser (a minifier may fold the runtime and the bootstrap
// into its own bundle) and is absent from a crawler render, which is what the
// cache-poisoning tests need to tell apart. Whether the runtime still runs is
// test 07's job, in a real browser.
function hasBanner(html: string): boolean {
  return /id=["']?fazBannerTemplate["'\s>]/.test(html);
}

function providerBlocked(html: string): boolean {
  const m = html.match(/<script[^>]*gtag\/js\?id=G-FAZMATRIX[^>]*>/);
  return !!m && /type=["']?text\/plain["'\s>]/.test(m[0]) && /data-faz-category=/.test(m[0]);
}

function fazPurge(): void {
  wpEval(`do_action('rest_api_init'); do_action('faz_clear_cache');`);
}

function nativePurge(): void {
  if (NATIVE_PURGE_PHP.trim()) {
    wpEval(NATIVE_PURGE_PHP);
  }
}

function purgeAll(): void {
  nativePurge();
  fazPurge();
}

/** Active banner id, read once (read-only). */
function activeBannerId(): number {
  return Number(wpEval(`$b = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner(); echo $b ? (int) $b->get_id() : 0;`).trim());
}

/**
 * Real REST save of the active banner's notice title, over HTTP as the
 * administrator — the request the admin screen makes. Not WP-CLI: a purge
 * adapter that derives the site host from the request (Jetpack Boost) cannot
 * find its cache from a CLI process, and a CLI save would report that as a
 * product failure.
 */
async function saveBannerTitle(title: string): Promise<void> {
  const ctx = await playwrightRequest.newContext({ baseURL: BASE, ignoreHTTPSErrors: true });
  try {
    const loginPath = getWpLoginPath();
    await ctx.get(loginPath);
    const login = await ctx.post(loginPath, {
      form: {
        log: process.env.WP_ADMIN_USER ?? 'admin',
        pwd: process.env.WP_ADMIN_PASS ?? 'admin',
        'wp-submit': 'Log In',
        redirect_to: `${BASE}/wp-admin/`,
        testcookie: '1',
      },
    });
    expect(login.url()).toContain('/wp-admin');
    const nonce = (await (await ctx.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    expect(nonce.length, 'REST nonce').toBeGreaterThan(5);
    const id = activeBannerId();
    expect(id).toBeGreaterThan(0);
    const current = await ctx.get(`/wp-json/faz/v1/banners/${id}`, { headers: { 'X-WP-Nonce': nonce } });
    expect(current.status()).toBe(200);
    const banner = (await current.json()) as { name?: string; properties?: unknown; contents?: Record<string, any> };
    const contents = banner.contents ?? {};
    for (const lang of Object.keys(contents)) {
      contents[lang] = contents[lang] ?? {};
      contents[lang].notice = contents[lang].notice ?? {};
      contents[lang].notice.elements = contents[lang].notice.elements ?? {};
      contents[lang].notice.elements.title = title;
    }
    const saved = await ctx.post(`/wp-json/faz/v1/banners/${id}`, {
      headers: { 'X-WP-Nonce': nonce, 'X-HTTP-Method-Override': 'PUT', 'Content-Type': 'application/json' },
      data: { name: banner.name || 'matrix', status: true, default: true, properties: banner.properties ?? {}, contents },
    });
    expect(saved.status(), `banner PUT status ${saved.status()}`).toBe(200);
  } finally {
    await ctx.dispose();
  }
}

function readActiveTitle(): string {
  return wpEval(`
    $b = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
    $c = $b ? $b->get_contents() : array();
    $first = is_array( $c ) ? reset( $c ) : array();
    echo isset( $first['notice']['elements']['title'] ) ? $first['notice']['elements']['title'] : '';
  `).trim();
}

async function adminCookieHeader(): Promise<string[]> {
  const ctx = await playwrightRequest.newContext({ baseURL: BASE, ignoreHTTPSErrors: true });
  try {
    const loginPath = getWpLoginPath();
    await ctx.get(loginPath);
    const res = await ctx.post(loginPath, {
      form: {
        log: process.env.WP_ADMIN_USER ?? 'admin',
        pwd: process.env.WP_ADMIN_PASS ?? 'admin',
        'wp-submit': 'Log In',
        redirect_to: `${BASE}/wp-admin/`,
        testcookie: '1',
      },
    });
    expect(res.url()).toContain('/wp-admin');
    const state = await ctx.storageState();
    return state.cookies.map((c) => `${c.name}=${c.value}`);
  } finally {
    await ctx.dispose();
  }
}

/** Open the fixture in a fresh browser context, with the provider URL stubbed so its execution is observable offline. */
async function openFixture(browser: Browser, url: string): Promise<{ page: Page; providerRequests: () => number; close: () => Promise<void> }> {
  const ctx = await browser.newContext({ userAgent: HUMAN_UA, baseURL: BASE });
  let providerRequests = 0;
  await ctx.route('**/gtag/js**', async (route) => {
    providerRequests += 1;
    await route.fulfill({ status: 200, contentType: 'application/javascript', body: 'window.__fazMatrixProviderRan = true;' });
  });
  const page = await ctx.newPage();
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  return {
    page,
    providerRequests: () => providerRequests,
    close: async () => {
      await ctx.close();
    },
  };
}

test.describe(`Cache plugin matrix — ${SLUG || '(no plugin)'}`, () => {
  test.describe.configure({ timeout: 120_000 });
  test.skip(!SLUG, 'FAZ_CACHE_PLUGIN is not set — run through scripts/cache-plugin-matrix.sh');

  let fixtureUrl = '';
  let fixtureId = 0;
  let fixtureBefore: { id: number; title: string; content: string } | null = null;
  let originalTitle = '';
  let titleChanged = false;

  test.beforeAll(() => {
    // A page that already exists (the driver creates one before its baseline
    // probe) gets its title and content back in afterAll; one this file
    // creates is deleted there.
    const existing = wpEval(`
      $p = get_page_by_path( '${FIXTURE_SLUG}', OBJECT, 'page' );
      echo $p ? wp_json_encode( array( 'id' => (int) $p->ID, 'title' => $p->post_title, 'content' => $p->post_content ) ) : '';
    `).trim();
    fixtureBefore = existing ? (JSON.parse(existing) as { id: number; title: string; content: string }) : null;
    const id = upsertPage(FIXTURE_SLUG, 'FAZ Cache Matrix', FIXTURE_HTML);
    fixtureId = id;
    fixtureUrl = wpEval(`echo get_permalink( ${id} );`).trim();
    originalTitle = readActiveTitle();
    expect(originalTitle.length, 'active banner must have a notice title').toBeGreaterThan(0);
    purgeAll();
  });

  test.afterAll(async () => {
    if (titleChanged) {
      await saveBannerTitle(originalTitle);
    }
    if (fixtureBefore) {
      const encoded = Buffer.from(JSON.stringify(fixtureBefore), 'utf8').toString('base64');
      wpEval(`
        $p = json_decode( base64_decode( '${encoded}' ), true );
        wp_update_post( array( 'ID' => (int) $p['id'], 'post_title' => $p['title'], 'post_content' => $p['content'] ) );
      `);
    } else if (fixtureId > 0) {
      wp(['post', 'delete', String(fixtureId), '--force'], { allowRetry: false });
    }
    purgeAll();
  });

  test('01 the plugin serves the fixture page from its page cache', async () => {
    test.skip(!PAGE_CACHE, `${SLUG} has no page cache on this host`);
    const hit = await warmUntilHit(fixtureUrl);
    expect(hit.status).toBe(200);
  });

  test('02 the cached copy carries the banner, the runtime, its config and the blocked provider', async () => {
    test.skip(!PAGE_CACHE, `${SLUG} has no page cache on this host`);
    const hit = await warmUntilHit(fixtureUrl);
    dump('cached-page', hit.html);
    expect(hasBanner(hit.html), 'banner template in the cached copy').toBe(true);
    // Reported, not asserted: an optimiser that folds the runtime into its own
    // bundle is judged by test 07 (does the banner still mount, does blocking
    // hold), and by the dumped HTML next to this verdict.
    const runtimeIntact = /id=["']?faz-cookie-manager-js-before["'\s>]/.test(hit.html) && /id=["']?faz-cookie-manager-js["'\s>]/.test(hit.html);
    test.info().annotations.push({ type: 'runtime-tags', description: runtimeIntact ? 'FAZ script tags untouched' : 'FAZ script tags rewritten or merged by the optimiser' });
    expect(providerBlocked(hit.html), 'server-side blocked provider stays text/plain in the cached copy').toBe(true);
    expect(hit.html, 'fixture content present').toContain('FAZ cache matrix fixture');
  });

  test('03 a crawler visit never leaves a banner-less copy for the next human', async () => {
    test.skip(!PAGE_CACHE, `${SLUG} has no page cache on this host`);
    purgeAll();
    // Crawler first: with hide_from_bots on this render has no banner. It
    // must not be stored.
    const bot = await fetchAnon(fixtureUrl, BOT_UA);
    dump('crawler-page', bot.html);
    expect(bot.status).toBe(200);
    if (!bot.headerHit) {
      // A crawler served a stored human copy (banner included) is fine; a
      // crawler RENDER must leave as no-store.
      expect(bot.headers['cache-control'] ?? '', 'crawler render is sent no-store').toMatch(/no-store/);
    }
    const crawlerSawBanner = hasBanner(bot.html);

    const human1 = await fetchAnon(fixtureUrl);
    const human2 = await fetchAnon(fixtureUrl);
    dump('human-after-crawler', human1.html);
    expect(hasBanner(human1.html), `first human after the crawler gets the banner (crawler copy had banner: ${crawlerSawBanner})`).toBe(true);
    expect(hasBanner(human2.html), 'second human after the crawler gets the banner').toBe(true);
    expect(providerBlocked(human2.html)).toBe(true);

    // And the other order: a cached human copy must not be replaced by a crawler render.
    const hit = await warmUntilHit(fixtureUrl);
    expect(hasBanner(hit.html)).toBe(true);
    await fetchAnon(fixtureUrl, BOT_UA);
    const human3 = await fetchAnon(fixtureUrl);
    expect(hasBanner(human3.html), 'crawler visit on a warm cache does not evict the banner copy').toBe(true);
  });

  test('04 a consenting visitor never leaves a consented copy for the next visitor', async ({ browser }) => {
    test.skip(!PAGE_CACHE, `${SLUG} has no page cache on this host`);
    purgeAll();
    const visitor = await openFixture(browser, fixtureUrl);
    try {
      const accept = visitor.page.locator('[data-faz-tag="accept-button"]').first();
      await expect(accept).toBeVisible({ timeout: 20_000 });
      expect(visitor.providerRequests(), 'provider not requested before consent').toBe(0);
      await accept.click();
      await expect
        .poll(async () => (await visitor.page.context().cookies()).some((c) => c.name === 'fazcookie-consent'), { timeout: 10_000 })
        .toBe(true);
      await expect.poll(() => visitor.providerRequests(), { timeout: 10_000 }).toBeGreaterThan(0);
      // Load the page again as the consenting visitor: this is the render a
      // cache keyed only on the URL would store.
      purgeAll();
      await visitor.page.reload({ waitUntil: 'domcontentloaded' });
      await visitor.page.waitForTimeout(1_000);
    } finally {
      await visitor.close();
    }

    const next1 = await fetchAnon(fixtureUrl);
    const next2 = await fetchAnon(fixtureUrl);
    for (const [label, got] of [
      ['first', next1],
      ['second', next2],
    ] as const) {
      expect(hasBanner(got.html), `${label} anonymous response after a consenting visitor still ships the banner`).toBe(true);
      expect(providerBlocked(got.html), `${label} anonymous response after a consenting visitor still blocks the provider`).toBe(true);
      expect(got.html, `${label} anonymous response does not carry the consenting visitor's state`).not.toMatch(/fazcookie-consent=/);
    }

    const fresh = await openFixture(browser, fixtureUrl);
    try {
      await expect(fresh.page.locator('[data-faz-tag="accept-button"]').first()).toBeVisible({ timeout: 20_000 });
      expect(fresh.providerRequests(), 'fresh visitor after a consenting one: provider still not requested').toBe(0);
    } finally {
      await fresh.close();
    }
  });

  test('05 a banner save through the REST API purges the page cache', async () => {
    test.skip(!PAGE_CACHE, `${SLUG} has no page cache on this host`);
    purgeAll();
    const before = await warmUntilHit(fixtureUrl);
    expect(before.html).toContain(originalTitle);

    const marker = `FAZ matrix ${SLUG} ${Date.now()}`;
    // Set before the save: a PUT that fails after the server stored the
    // banner must still be undone in afterAll.
    titleChanged = true;
    await saveBannerTitle(marker);

    const after = await fetchAnon(fixtureUrl);
    expect(after.html, 'the very next anonymous response shows the new title (the save purged the cache)').toContain(marker);
    expect(after.html).not.toMatch(new RegExp(`data-faz-tag=["']?title["']?>${originalTitle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}<`));

    // The purge was not an accident of the cache having expired: the page
    // caches again with the new title.
    const again = await warmUntilHit(fixtureUrl);
    expect(again.html).toContain(marker);
  });

  test('06 the public banner REST route is never served from the page cache', async () => {
    // /faz/v1/banner/<lang> is the one anonymous GET route (the others need a
    // nonce or are POST). It may legitimately be `public, max-age=300` when its
    // payload is visitor-invariant, so the contract here is narrower than
    // no-store: a page cache must never hand back an HTML footer, a minified
    // or rewritten body, or a stale page-cache stamp in place of the JSON.
    const url = `${BASE}/wp-json/faz/v1/banner/en`;
    const first = await fetchAnon(url);
    const second = await fetchAnon(url);
    for (const got of [first, second]) {
      expect(got.status).toBe(200);
      expect(got.headers['content-type'] ?? '', 'JSON content type').toMatch(/application\/json/);
      if (got.headers['x-faz-jurisdiction'] === 'live') {
        expect(got.headers['cache-control'] ?? '', 'country-dependent REST response is private').toMatch(/\bprivate\b/i);
        expect(got.headers['cache-control'] ?? '', 'country-dependent REST response is no-store').toMatch(/\bno-store\b/i);
        expect(got.headerHit, 'country-dependent REST response is not served from the page cache').toBe(false);
      }
      expect(got.stamp, 'no page-cache stamp injected into the JSON response').toBe('');
      expect(() => JSON.parse(got.html), 'REST body is valid JSON (no minifier or footer comment appended)').not.toThrow();
    }
  });

  test('07 browser: banner mounts without interaction, provider blocked until accept, released on accept', async ({ browser }) => {
    purgeAll();
    dump('optimised-page', (await fetchAnon(fixtureUrl)).html);
    const visitor = await openFixture(browser, fixtureUrl);
    try {
      const page = visitor.page;
      const errors: string[] = [];
      page.on('pageerror', (e) => errors.push(String(e)));
      page.on('console', (m) => {
        if (m.type() === 'error') errors.push(m.text());
      });
      const accept = page.locator('[data-faz-tag="accept-button"]').first();
      const reject = page.locator('[data-faz-tag="reject-button"]').first();
      // No mouse, no keyboard, no scroll before this assertion: a delay-JS
      // pass that caught the consent runtime would hold the banner until the
      // first interaction.
      await expect(accept, 'accept button visible with no user interaction').toBeVisible({ timeout: 15_000 });
      await expect(reject, 'reject button visible with no user interaction').toBeVisible({ timeout: 5_000 });
      const acceptBox = await accept.boundingBox();
      expect(acceptBox && acceptBox.height > 10 && acceptBox.width > 10, 'accept button has a real size (template not truncated by an HTML minifier)').toBe(true);
      const configDefined = await page.evaluate(() => typeof (window as any)._fazConfig === 'object' && (window as any)._fazConfig !== null);
      expect(configDefined, '_fazConfig defined').toBe(true);
      expect(visitor.providerRequests(), 'provider not requested before consent').toBe(0);
      const typeBefore = await page.evaluate(() => {
        const s = Array.from(document.querySelectorAll('script')).find((el) => (el.getAttribute('src') ?? '').includes('G-FAZMATRIX'));
        return s ? s.getAttribute('type') : '(missing)';
      });
      expect(typeBefore, 'provider script still text/plain before consent').toBe('text/plain');

      await accept.click();
      await expect.poll(() => visitor.providerRequests(), { timeout: 10_000 }).toBeGreaterThan(0);
      const ran = await page.evaluate(() => (window as any).__fazMatrixProviderRan === true);
      expect(ran, 'provider executed after accept').toBe(true);
      const fazErrors = errors.filter((e) => /faz/i.test(e) && !/favicon/i.test(e));
      expect(fazErrors, 'no FAZ runtime errors in the console').toEqual([]);
    } finally {
      await visitor.close();
    }
  });

  test('08 a logged-in administrator is never served the anonymous cached copy', async () => {
    test.skip(!PAGE_CACHE, `${SLUG} has no page cache on this host`);
    await warmUntilHit(fixtureUrl);
    const cookies = await adminCookieHeader();
    const admin = await fetchAnon(fixtureUrl, HUMAN_UA, cookies);
    expect(admin.status).toBe(200);
    expect(admin.html, 'admin bar rendered').toMatch(/id=["']?wpadminbar["'\s>]/);
    expect(admin.headerHit, 'not a cache hit for the administrator').toBe(false);
  });
});
