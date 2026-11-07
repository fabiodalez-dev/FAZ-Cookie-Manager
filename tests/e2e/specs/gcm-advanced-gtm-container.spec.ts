import fs from 'node:fs';
import path from 'node:path';
import type { Browser, BrowserContext, Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { wpEval } from '../utils/wp-env';

/**
 * Google Tag Manager container under Advanced Consent Mode (#385).
 *
 * Advanced Consent Mode loads gtag.js before consent behind a denied
 * `consent default` but holds the Tag Manager container, because it can run
 * tags that ignore Consent Mode. Sites that configure GA4 and Google Ads
 * conversions INSIDE Tag Manager lost every cookieless ping for visitors who
 * did not consent. The opt-in `advanced_mode_gtm` lets the container load
 * before consent too. Requests to googletagmanager.com are answered by a stub,
 * so nothing leaves the machine; what is asserted is which URLs the browser
 * asked for, and what the dataLayer held first:
 *
 *  - Advanced + opt-in: gtm.js is requested before any choice, both for the
 *    built-in GTM- container and for a snippet pasted by a theme, and the
 *    first dataLayer entry is the denied `consent default`, ahead of the
 *    container's `gtm.js` event;
 *  - opt-in off: the container is not requested before a choice (unchanged);
 *  - an explicit per-service rejection of Google Tag Manager keeps it blocked;
 *  - the admin toggle is shown only with Advanced Consent Mode on.
 */

const GTM_ID = 'GTM-ABC1234';
const PASTED_ID = 'GTM-PASTE12';
const MU_PLUGIN = 'faz-e2e-pasted-gtm.php';

function snapshotOption(name: string): string {
  return wpEval(`echo "\\n".base64_encode(maybe_serialize(get_option('${name}')));`).trim().split('\n').pop() as string;
}

function restoreOption(name: string, snap: string): void {
  wpEval(
    `$v=maybe_unserialize(base64_decode('${snap}'));if(false===$v||''===$v){delete_option('${name}');}else{update_option('${name}',$v);}` +
      `\\FazCookie\\Admin\\Modules\\Gcm\\Includes\\Gcm_Settings::flush_runtime_cache();delete_option('faz_banner_template');`,
  );
}

function setGcm(patch: Record<string, unknown>): void {
  const json = Buffer.from(JSON.stringify(patch), 'utf8').toString('base64');
  wpEval(
    `$g=new \\FazCookie\\Admin\\Modules\\Gcm\\Includes\\Gcm_Settings();$d=$g->get();` +
      `foreach(json_decode(base64_decode('${json}'),true) as $k=>$v){$d[$k]=$v;}` +
      `$g->update($d);\\FazCookie\\Admin\\Modules\\Gcm\\Includes\\Gcm_Settings::flush_runtime_cache();`,
  );
}

async function visitor(browser: Browser): Promise<{ context: BrowserContext; page: Page; requests: string[] }> {
  const context = await browser.newContext();
  const requests: string[] = [];
  await context.route(/googletagmanager\.com/, (route) => {
    requests.push(route.request().url());
    return route.fulfill({ status: 200, contentType: 'application/javascript', body: '/* stub */' });
  });
  const page = await context.newPage();
  return { context, page, requests };
}

async function openHome(page: Page): Promise<void> {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-faz-tag="notice"], .faz-consent-container', { state: 'attached', timeout: 20_000 });
  await page.waitForTimeout(800);
}

const requested = (requests: string[], id: string) => requests.some((u) => u.includes(`/gtm.js?id=${id}`));

/**
 * The pasted-snippet half needs WP_PATH: the mu-plugin that prints the snippet
 * is written only then. Without it a "not requested" assertion on PASTED_ID
 * would pass without the snippet ever being on the page, so it is not made,
 * and the test records why instead of reporting a silent green.
 */
function expectPastedNotRequested(requests: string[], message: string): void {
  if (!process.env.WP_PATH) {
    test.info().annotations.push({ type: 'skip-part', description: `${message}: not checked, WP_PATH is unset so no snippet is pasted` });
    return;
  }
  expect(requested(requests, PASTED_ID), message).toBe(false);
}

/** The first dataLayer entries, flattened to something comparable. */
async function dataLayerHead(page: Page): Promise<Array<{ kind: string; detail: unknown }>> {
  return page.evaluate(() => {
    const dl = ((window as unknown as { dataLayer?: unknown[] }).dataLayer || []).slice(0, 8);
    return dl.map((e) => {
      if (e && typeof e === 'object' && 'length' in (e as object)) {
        const a = Array.from(e as ArrayLike<unknown>);
        return { kind: `${String(a[0])}:${String(a[1])}`, detail: a[2] ?? null };
      }
      const o = (e || {}) as Record<string, unknown>;
      return { kind: `event:${String(o.event ?? '')}`, detail: null };
    });
  });
}

test.describe.serial('GTM container under Advanced Consent Mode (#385)', () => {
  let gcm = '';
  let settings = '';

  test.beforeAll(() => {
    resetDefaultBannerState();
    gcm = snapshotOption('faz_gcm_settings');
    settings = snapshotOption('faz_settings');
    const wpPath = process.env.WP_PATH;
    if (wpPath) {
      // A container pasted by a theme: the standard snippet, printed late in
      // <head> so the plugin's consent default is already in front of it.
      const file = path.join(wpPath, 'wp-content', 'mu-plugins', MU_PLUGIN);
      fs.mkdirSync(path.dirname(file), { recursive: true });
      fs.writeFileSync(
        file,
        "<?php\nadd_action('wp_head', function () { echo '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\"gtm.start\":new Date().getTime(),event:\"gtm.js\"});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!=\"dataLayer\"?\"&l=\"+l:\"\";j.async=true;j.src=\"https://www.googletagmanager.com/gtm.js?id=\"+i+dl;f.parentNode.insertBefore(j,f);})(window,document,\"script\",\"dataLayer\",\"" + PASTED_ID + "\");</script>'; }, 99);\n",
      );
    }
  });

  test.afterAll(() => {
    restoreOption('faz_gcm_settings', gcm);
    restoreOption('faz_settings', settings);
    const wpPath = process.env.WP_PATH;
    if (wpPath) fs.rmSync(path.join(wpPath, 'wp-content', 'mu-plugins', MU_PLUGIN), { force: true });
  });

  test('admin: the container toggle is shown only with Advanced Consent Mode on', async ({ page, loginAsAdmin }) => {
    setGcm({ status: true, advanced_mode: false, advanced_mode_gtm: false, google_tag_ids: '' });
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-gcm', { waitUntil: 'domcontentloaded' });
    const group = page.locator('#faz-gcm-advanced-gtm-group');
    const advanced = page.locator('[data-path="advanced_mode"]');
    await expect(advanced).toBeAttached();
    await expect(group).toBeHidden();
    await advanced.evaluate((el) => {
      (el as HTMLInputElement).checked = true;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await expect(group).toBeVisible();
    await expect(page.locator('#faz-gcm-advanced-gtm-warning')).toContainText('Consent Settings');
    await page.locator('#faz-gcm-advanced-gtm').evaluate((el) => {
      (el as HTMLInputElement).checked = true;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    const saved = page.waitForResponse((r) => r.url().includes('gcm') && r.request().method() === 'POST');
    await page.click('#faz-gcm-save');
    expect((await saved).status()).toBe(200);
    const stored = wpEval(
      `$g=new \\FazCookie\\Admin\\Modules\\Gcm\\Includes\\Gcm_Settings();echo "\\n".($g->is_advanced_gtm_mode()?'yes':'no');`,
    ).trim().split('\n').pop();
    expect(stored).toBe('yes');
  });

  test('Advanced + opt-in: gtm.js is requested before consent, behind the denied default', async ({ browser }) => {
    setGcm({ status: true, advanced_mode: true, advanced_mode_gtm: true, google_tag_ids: GTM_ID });
    const { context, page, requests } = await visitor(browser);
    try {
      await openHome(page);
      await expect.poll(() => requested(requests, GTM_ID), { timeout: 10_000 }).toBe(true);
      if (process.env.WP_PATH) {
        await expect.poll(() => requested(requests, PASTED_ID), { timeout: 10_000 }).toBe(true);
      }
      const head = await dataLayerHead(page);
      expect(head[0]?.kind, 'the first dataLayer entry is the consent default').toBe('consent:default');
      const def = head[0]?.detail as Record<string, string>;
      for (const signal of ['ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage']) {
        expect(def[signal], `${signal} denied by default`).toBe('denied');
      }
      const gtmEvent = head.findIndex((e) => e.kind === 'event:gtm.js');
      expect(gtmEvent, 'the container pushed its gtm.js event').toBeGreaterThan(0);
      // No choice was made: the banner is still there and nothing was granted.
      const updates = head.filter((e) => e.kind === 'consent:update');
      for (const u of updates) {
        expect((u.detail as Record<string, string>).ad_storage).not.toBe('granted');
      }
    } finally {
      await context.close();
    }
  });

  test('opt-in off: the container still waits for consent', async ({ browser }) => {
    setGcm({ status: true, advanced_mode: true, advanced_mode_gtm: false, google_tag_ids: GTM_ID });
    const { context, page, requests } = await visitor(browser);
    try {
      await openHome(page);
      await page.waitForTimeout(1500);
      expect(requested(requests, GTM_ID), 'built-in container not requested').toBe(false);
      expectPastedNotRequested(requests, 'pasted container not requested');
    } finally {
      await context.close();
    }
  });

  test('Advanced off: the opt-in alone loads nothing', async ({ browser }) => {
    setGcm({ status: true, advanced_mode: false, advanced_mode_gtm: true, google_tag_ids: GTM_ID });
    const { context, page, requests } = await visitor(browser);
    try {
      await openHome(page);
      await page.waitForTimeout(1500);
      expect(requested(requests, GTM_ID)).toBe(false);
      expectPastedNotRequested(requests, 'pasted container not requested without Advanced mode');
    } finally {
      await context.close();
    }
  });

  test('an explicit rejection of the Google Tag Manager service keeps the container blocked', async ({ browser }) => {
    setGcm({ status: true, advanced_mode: true, advanced_mode_gtm: true, google_tag_ids: GTM_ID });
    wpEval(`$s=get_option('faz_settings');if(!is_array($s)){$s=array();}$s['banner_control']=isset($s['banner_control'])&&is_array($s['banner_control'])?$s['banner_control']:array();$s['banner_control']['per_service_consent']=true;update_option('faz_settings',$s);delete_option('faz_banner_template');`);
    const { context, page, requests } = await visitor(browser);
    try {
      const rev = parseInt(wpEval('echo faz_get_consent_revision();').trim(), 10) || 1;
      const host = new URL(process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998').hostname;
      await context.addCookies([{
        name: 'fazcookie-consent',
        value: encodeURIComponent(`consentid:e2e-385,consent:yes,action:yes,necessary:yes,functional:yes,analytics:no,performance:no,uncategorized:no,marketing:no,svc.google-tag-manager:no,rev:${rev}`),
        domain: host, path: '/', sameSite: 'Lax',
      }]);
      await openHome(page);
      await page.waitForTimeout(1500);
      expect(requested(requests, GTM_ID), 'built-in container held by the service denial').toBe(false);
      expectPastedNotRequested(requests, 'pasted container held by the service denial');
    } finally {
      await context.close();
      restoreOption('faz_settings', settings);
    }
  });
});
