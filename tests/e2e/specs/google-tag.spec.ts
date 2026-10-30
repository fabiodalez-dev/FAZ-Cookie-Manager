import fs from 'node:fs';
import path from 'node:path';
import type { Browser, BrowserContext, Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { upsertPage, wpEval } from '../utils/wp-env';

/**
 * Built-in Google tag (#366).
 *
 * The plugin loads the Google tag IDs entered on the Consent Mode page and
 * gates them the way a pasted copy would be gated. Requests to
 * googletagmanager.com are answered by a stub, so nothing leaves the machine;
 * what is asserted is which URLs the browser asked for, and when:
 *
 *  - before any choice nothing is requested (Basic mode);
 *  - Analytics alone releases GA4 and the Tag Manager container (catalogue
 *    category Analytics) but not Google Ads;
 *  - Accept All releases all three, and a returning visitor gets them again;
 *  - Reject All releases nothing, before or after a reload;
 *  - Advanced Consent Mode loads gtag.js before consent behind the denied
 *    default, and still holds the Tag Manager container;
 *  - the admin keeps only valid IDs, says which ones it dropped, and warns
 *    when the home page already loads one of them from elsewhere.
 */

const IDS_TYPED = 'g-test1234, aw-12345678;GTM-ABC1234 bogus';
const IDS_SAVED = 'G-TEST1234,AW-12345678,GTM-ABC1234';
const MU_PLUGIN = 'faz-e2e-pasted-gtag.php';

type GcmSnapshot = string;

function snapshotGcm(): GcmSnapshot {
  return wpEval(`echo "\\n".base64_encode(maybe_serialize(get_option('faz_gcm_settings')));`).trim().split('\n').pop() as string;
}

function restoreGcm(snap: GcmSnapshot): void {
  wpEval(
    `$v=maybe_unserialize(base64_decode('${snap}'));if(false===$v||''===$v){delete_option('faz_gcm_settings');}else{update_option('faz_gcm_settings',$v);}` +
      `\\FazCookie\\Admin\\Modules\\Gcm\\Includes\\Gcm_Settings::flush_runtime_cache();`,
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

/** A visitor context whose Google requests are recorded and stubbed. */
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

const loaded = (requests: string[]) => ({
  ga4: requests.some((u) => u.includes('/gtag/js?id=G-TEST1234')),
  ads: requests.some((u) => u.includes('/gtag/js?id=AW-12345678')),
  gtm: requests.some((u) => u.includes('/gtm.js?id=GTM-ABC1234')),
});

async function clickFirstVisible(page: Page, selectors: string[]): Promise<void> {
  for (const sel of selectors) {
    const loc = page.locator(sel).first();
    if (await loc.isVisible().catch(() => false)) {
      await loc.click();
      return;
    }
  }
  throw new Error(`none visible: ${selectors.join(', ')}`);
}

async function setCategoryToggle(page: Page, slug: string, checked: boolean): Promise<void> {
  const sw = page.locator(`#fazSwitch${slug}`);
  const direct = page.locator(`#fazCategoryDirect${slug}`);
  const toggle = (await sw.count()) > 0 ? sw : direct;
  if ((await toggle.count()) === 0) return;
  await toggle.evaluate((el, v) => {
    const input = el as HTMLInputElement;
    input.checked = v;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, checked);
}

test.describe.serial('Built-in Google tag (#366)', () => {
  let gcm: GcmSnapshot = '';

  test.beforeAll(() => {
    resetDefaultBannerState();
    gcm = snapshotGcm();
  });

  test.afterAll(() => {
    restoreGcm(gcm);
    const wpPath = process.env.WP_PATH;
    if (wpPath) fs.rmSync(path.join(wpPath, 'wp-content', 'mu-plugins', MU_PLUGIN), { force: true });
  });

  test('admin keeps only valid IDs and says which were dropped', async ({ page, loginAsAdmin }) => {
    setGcm({ status: false, advanced_mode: false, google_tag_ids: '' });
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-gcm', { waitUntil: 'domcontentloaded' });
    const input = page.locator('#faz-gcm-google-tag-ids');
    await expect(input).toBeVisible();
    await input.fill(IDS_TYPED);
    const saved = page.waitForResponse((r) => r.url().includes('gcm') && r.request().method() === 'POST');
    await page.click('#faz-gcm-save');
    expect((await saved).status()).toBe(200);
    await expect(input).toHaveValue('G-TEST1234, AW-12345678, GTM-ABC1234');
    await expect(page.locator('#faz-gcm-google-tag-notice')).toContainText('BOGUS');
    const stored = wpEval(`echo "\\n".(new \\FazCookie\\Admin\\Modules\\Gcm\\Includes\\Gcm_Settings())->get('google_tag_ids');`).trim().split('\n').pop();
    expect(stored).toBe(IDS_SAVED);
  });

  test('Basic mode: nothing is requested before a choice, Reject All keeps it that way', async ({ browser }) => {
    setGcm({ status: true, advanced_mode: false, google_tag_ids: IDS_SAVED });
    const { context, page, requests } = await visitor(browser);
    try {
      await openHome(page);
      expect(loaded(requests)).toEqual({ ga4: false, ads: false, gtm: false });
      await expect(page.locator('script[data-faz-google-tag]')).toHaveCount(5);

      await clickFirstVisible(page, ['[data-faz-tag="reject-button"]', '.faz-btn-reject']);
      await page.waitForTimeout(800);
      expect(loaded(requests)).toEqual({ ga4: false, ads: false, gtm: false });
      await openHome(page);
      expect(loaded(requests), 'a visitor who refused gets nothing on the next page either').toEqual({ ga4: false, ads: false, gtm: false });
    } finally {
      await context.close();
    }
  });

  test('a page excluded from script blocking still holds the tag until consent', async ({ browser }) => {
    // The output buffer does not run on such a page, so a pasted tag would
    // run there before any choice. The built-in one is printed already
    // parked, so it does not depend on the buffer.
    const slug = 'faz-e2e-gtag-unbuffered';
    upsertPage(slug, 'Google tag without the buffer', 'Google tag without the buffer');
    const before = wpEval(`echo "\\n".base64_encode(maybe_serialize(get_option('faz_settings')));`).trim().split('\n').pop() as string;
    wpEval(
      `$s=get_option('faz_settings');if(!isset($s['script_blocking'])||!is_array($s['script_blocking'])){$s['script_blocking']=array();}` +
        `$s['script_blocking']['excluded_pages']=array('/${slug}/');update_option('faz_settings',$s);`,
    );
    const { context, page, requests } = await visitor(browser);
    try {
      await page.goto(`/${slug}/`, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('[data-faz-tag="notice"]', { state: 'visible', timeout: 20_000 });
      await page.waitForTimeout(800);
      expect(loaded(requests)).toEqual({ ga4: false, ads: false, gtm: false });
      await clickFirstVisible(page, ['[data-faz-tag="accept-button"]', '.faz-btn-accept']);
      await expect.poll(() => loaded(requests), { timeout: 10_000 }).toEqual({ ga4: true, ads: true, gtm: true });
    } finally {
      await context.close();
      wpEval(`update_option('faz_settings',maybe_unserialize(base64_decode('${before}')));`);
    }
  });

  test('Basic mode: Analytics alone releases GA4 and the container, not Google Ads', async ({ browser }) => {
    const { context, page, requests } = await visitor(browser);
    try {
      await openHome(page);
      await clickFirstVisible(page, ['[data-faz-tag="settings-button"]', '.faz-btn-customize']);
      await page.waitForTimeout(400);
      await setCategoryToggle(page, 'analytics', true);
      await setCategoryToggle(page, 'marketing', false);
      await setCategoryToggle(page, 'functional', false);
      await setCategoryToggle(page, 'performance', false);
      await clickFirstVisible(page, ['[data-faz-tag="detail-save-button"]', '.faz-btn-preferences']);
      await expect.poll(() => loaded(requests).ga4, { timeout: 10_000 }).toBe(true);
      await page.waitForTimeout(500);
      expect(loaded(requests)).toEqual({ ga4: true, ads: false, gtm: true });
      const configs = await page.evaluate(() =>
        ((window as unknown as { dataLayer?: unknown[] }).dataLayer || [])
          .filter((e) => Array.isArray(e) || (typeof e === 'object' && e !== null && 'length' in (e as object)))
          .map((e) => Array.from(e as ArrayLike<unknown>))
          .filter((a) => a[0] === 'config')
          .map((a) => a[1]),
      );
      expect(configs).toEqual(['G-TEST1234']);
    } finally {
      await context.close();
    }
  });

  test('Basic mode: Accept All releases every tag, and a returning visitor gets them again', async ({ browser }) => {
    const { context, page, requests } = await visitor(browser);
    try {
      await openHome(page);
      await clickFirstVisible(page, ['[data-faz-tag="accept-button"]', '.faz-btn-accept']);
      await expect.poll(() => loaded(requests), { timeout: 10_000 }).toEqual({ ga4: true, ads: true, gtm: true });
      requests.length = 0;
      await openHome(page);
      await expect.poll(() => loaded(requests), { timeout: 10_000 }).toEqual({ ga4: true, ads: true, gtm: true });
    } finally {
      await context.close();
    }
  });

  test('Advanced mode: gtag.js loads before consent behind the denied default; the container waits', async ({ browser }) => {
    setGcm({ status: true, advanced_mode: true, google_tag_ids: IDS_SAVED });
    const { context, page, requests } = await visitor(browser);
    try {
      await openHome(page);
      await expect.poll(() => loaded(requests), { timeout: 10_000 }).toEqual({ ga4: true, ads: true, gtm: false });
      const first = await page.evaluate(() => {
        const dl = (window as unknown as { dataLayer?: unknown[] }).dataLayer || [];
        const e = dl[0] as ArrayLike<unknown> | undefined;
        return e ? Array.from(e).slice(0, 2) : [];
      });
      expect(first[0]).toBe('consent');
      expect(first[1]).toBe('default');
      await clickFirstVisible(page, ['[data-faz-tag="accept-button"]', '.faz-btn-accept']);
      await expect.poll(() => loaded(requests).gtm, { timeout: 10_000 }).toBe(true);
    } finally {
      await context.close();
      setGcm({ advanced_mode: false });
    }
  });

  test('admin warns when the home page already loads one of the IDs', async ({ page, loginAsAdmin }) => {
    const wpPath = process.env.WP_PATH;
    test.skip(!wpPath, 'WP_PATH is required to install the pasted-snippet mu-plugin');
    const file = path.join(wpPath as string, 'wp-content', 'mu-plugins', MU_PLUGIN);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(
      file,
      "<?php\nadd_action('wp_head', function () { echo '<script async src=\"https://www.googletagmanager.com/gtag/js?id=G-TEST1234\"></script>'; }, 5);\n",
    );
    try {
      setGcm({ google_tag_ids: IDS_SAVED });
      await loginAsAdmin(page);
      await page.route(/googletagmanager\.com/, (r) => r.fulfill({ status: 200, body: '' }));
      await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-gcm', { waitUntil: 'domcontentloaded' });
      const notice = page.locator('#faz-gcm-google-tag-notice');
      await expect(notice).toBeVisible({ timeout: 15_000 });
      await expect(notice).toContainText('G-TEST1234');
      await expect(notice).not.toContainText('GTM-ABC1234');
    } finally {
      fs.rmSync(file, { force: true });
    }
    // Without the pasted copy the plugin's own tags are not counted.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    await expect(page.locator('#faz-gcm-google-tag-notice')).toBeHidden();
  });
});
