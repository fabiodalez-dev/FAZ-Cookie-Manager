import { type Browser, type BrowserContext, type Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { clickFirstVisible } from '../utils/ui';
import { getWpLoginPath } from '../utils/wp-auth';

type SettingsTree = Record<string, any>;

let adminPage: Page;
let nonce = '';
let originalSettings: SettingsTree;
let baseURL = '';

async function loginAsAdminForBehaviorSpec(page: Page, wpBaseURL: string, adminUser: string, adminPass: string): Promise<string> {
  await page.goto(`${wpBaseURL}${getWpLoginPath()}`, { waitUntil: 'domcontentloaded' });

  if (page.url().includes('/wp-admin/')) {
    await expect(page.locator('#wpadminbar')).toBeVisible();
    return new URL(page.url()).origin;
  }

  const loginOrigin = new URL(page.url()).origin;
  const loginHost = new URL(page.url()).hostname;
  const formAction = await page.locator('#loginform').getAttribute('action').catch(() => null);
  const postOrigin = formAction ? new URL(formAction, loginOrigin).origin : loginOrigin;
  const postHost = new URL(postOrigin).hostname;

  await page.context().addCookies(Array.from(new Set([loginHost, postHost])).map((host) => ({
    name: 'wordpress_test_cookie',
    value: 'WP Cookie check',
    domain: host,
    path: '/',
  })));

  const redirect = page.locator('input[name="redirect_to"]');
  if (await redirect.count()) {
    await redirect.evaluate((node, value) => {
      (node as HTMLInputElement).value = value;
    }, `${postOrigin}/wp-admin/`);
  }

  for (let attempt = 0; attempt < 2; attempt += 1) {
    await expect(page.locator('#user_login')).toBeVisible({ timeout: 20_000 });
    await page.locator('#user_login').fill(adminUser);
    await page.locator('#user_pass').fill(adminPass);
    await Promise.all([
      page.locator('#wp-submit').click(),
      page.waitForLoadState('domcontentloaded', { timeout: 60_000 }).catch(() => undefined),
    ]);

    if (page.url().includes('/wp-admin/')) {
      break;
    }

    const cookies = await page.context().cookies(postOrigin);
    if (cookies.some((cookie) => cookie.name.startsWith('wordpress_logged_in_'))) {
      await page.goto(`${postOrigin}/wp-admin/`, { waitUntil: 'domcontentloaded' });
      break;
    }

    const loginError = await page.locator('#login_error').textContent().catch(() => '');
    if (loginError || attempt === 1) {
      throw new Error(`WordPress admin login failed. URL=${page.url()} error=${loginError ?? 'n/a'}`);
    }
  }

  await expect(page.locator('#wpadminbar')).toBeVisible();
  return new URL(page.url()).origin;
}

function mergeSettings(current: SettingsTree, patch: SettingsTree): SettingsTree {
  const result = { ...current };
  for (const [key, value] of Object.entries(patch)) {
    if (value && typeof value === 'object' && !Array.isArray(value) && current[key] && typeof current[key] === 'object' && !Array.isArray(current[key])) {
      result[key] = mergeSettings(current[key], value as SettingsTree);
    } else {
      result[key] = value;
    }
  }
  return result;
}

async function getSettings(): Promise<SettingsTree> {
  const response = await adminPage.request.get(`${baseURL}/?rest_route=/faz/v1/settings/`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(response.status()).toBe(200);
  return (await response.json()) as SettingsTree;
}

async function putSettings(patch: SettingsTree): Promise<SettingsTree> {
  const current = await getSettings();
  const payload = mergeSettings(current, patch);
  const response = await adminPage.request.post(`${baseURL}/?rest_route=/faz/v1/settings/`, {
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
    data: payload,
  });
  expect(response.status()).toBe(200);
  return (await response.json()) as SettingsTree;
}

async function restoreOriginalSettings(): Promise<void> {
  if (!originalSettings) {
    return;
  }
  const current = await getSettings();
  await putSettings({
    ...originalSettings,
    general: {
      ...originalSettings.general,
      consent_revision: Math.max(
        Number(originalSettings.general?.consent_revision ?? 1),
        Number(current.general?.consent_revision ?? 1),
      ),
    },
  });
}

async function newVisitorPage(browser: Browser, path = '/', init?: (context: BrowserContext) => Promise<void>): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ baseURL });
  if (init) {
    await init(context);
  }
  const page = await context.newPage();
  await page.goto(path, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
  await page.evaluate(() => {
    if ((window as any)._fazStore?._bannerConfig?.behaviours) {
      (window as any)._fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
    }
  });
  return { context, page };
}

async function acceptAll(page: Page): Promise<void> {
  const clicked = await clickFirstVisible(page, [
    '[data-faz-tag="accept-button"] button',
    '[data-faz-tag="accept-button"]',
    '.faz-btn-accept',
  ]);
  expect(clicked).toBe(true);
}

test.describe('Settings option behavior interactions', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(async ({ page, wpBaseURL, adminUser, adminPass }) => {
    adminPage = page;
    baseURL = await loginAsAdminForBehaviorSpec(adminPage, wpBaseURL, adminUser, adminPass);
    await adminPage.goto(`${baseURL}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    await adminPage.waitForFunction(
      () => typeof (window as any).fazConfig?.api?.nonce === 'string' && (window as any).fazConfig.api.nonce.length > 0,
      undefined,
      { timeout: 15_000 },
    );
    nonce = await adminPage.evaluate(() => (window as any).fazConfig?.api?.nonce ?? '');
    expect(nonce.length).toBeGreaterThan(0);
    if (!originalSettings) {
      originalSettings = await getSettings();
    }
  });

  test.afterEach(async () => {
    await restoreOriginalSettings();
  });

  test('pageview_tracking gates both frontend config and public pageview route', async ({ browser }) => {
    await putSettings({ banner_control: { status: true }, pageview_tracking: false });

    const disabledRoute = await adminPage.request.post(`${baseURL}/wp-json/faz/v1/pageviews`, {
      headers: { 'Content-Type': 'application/json' },
      data: { token: 'invalid', event_type: 'pageview' },
    });
    expect(disabledRoute.status()).toBe(404);

    let visitor = await newVisitorPage(browser);
    expect(await visitor.page.evaluate(() => typeof (window as any)._fazPageviewConfig)).toBe('undefined');
    await visitor.context.close();

    await putSettings({ pageview_tracking: true });

    visitor = await newVisitorPage(browser);
    expect(await visitor.page.evaluate(() => typeof (window as any)._fazPageviewConfig)).toBe('object');
    await visitor.context.close();

    const enabledRoute = await adminPage.request.post(`${baseURL}/wp-json/faz/v1/pageviews`, {
      headers: { 'Content-Type': 'application/json' },
      data: { token: 'invalid', event_type: 'pageview' },
    });
    expect(enabledRoute.status()).not.toBe(404);
  });

  test('gtm_datalayer pushes per-category consent states after accept', async ({ browser }) => {
    await putSettings({ banner_control: { status: true, gtm_datalayer: true } });

    const { context, page } = await newVisitorPage(browser, '/', async (ctx) => {
      await ctx.addInitScript(() => {
        (window as any).dataLayer = [];
      });
    });

    await acceptAll(page);

    const event = await page.waitForFunction(() => {
      return (window as any).dataLayer.find((item: any) => item && item.event === 'faz_consent_update');
    });
    const payload = await event.jsonValue() as Record<string, string>;
    expect(payload.faz_analytics).toBe('granted');
    expect(payload.faz_marketing).toBe('granted');
    await context.close();
  });

  test('consent_forwarding creates bridge iframes only for configured target domains after consent', async ({ browser }) => {
    await putSettings({
      banner_control: { status: true },
      consent_forwarding: {
        enabled: true,
        target_domains: [`${baseURL}/sample-page/`],
      },
    });

    const { context, page } = await newVisitorPage(browser);
    const config = await page.evaluate(() => (window as any)._fazConfig?._consentForwarding);
    expect(config).toMatchObject({ enabled: true, targets: [`${baseURL}/sample-page/`] });

    await page.evaluate(() => {
      (window as any).__fazBridgeSeen = false;
      new MutationObserver(() => {
        if (document.querySelector('iframe.faz-consent-bridge')) {
          (window as any).__fazBridgeSeen = true;
        }
      }).observe(document.body, { childList: true, subtree: true });
    });

    await acceptAll(page);
    await page.waitForFunction(() => (window as any).__fazBridgeSeen === true);
    await context.close();
  });

  test('age_gate blocks accept-all behind verification and under-age falls back to reject', async ({ browser }) => {
    await putSettings({
      banner_control: { status: true },
      age_gate: { enabled: true, min_age: 14 },
    });

    const { context, page } = await newVisitorPage(browser);
    await acceptAll(page);

    await expect(page.locator('#faz-age-gate')).toBeVisible();
    await expect(page.locator('.faz-age-gate-message')).toContainText('14');
    // When the age gate intercepts an accept-all click, the script intentionally
    // avoids writing ANY `action:` token to the persistent fazcookie-consent
    // cookie (otherwise an abandoned modal would suppress the banner forever).
    // Instead it flags the pending state via sessionStorage. Verify both:
    //   - the persistent cookie has NO action key at all (strict regex, not
    //     just the absence of `:yes` / `:no` — a future regression that writes
    //     `action:age-gate` or `action:pending` would slip through the weaker
    //     two-step check),
    //   - the sessionStorage flag is set.
    const pendingCookie = (await context.cookies(baseURL)).find((cookie) => cookie.name === 'fazcookie-consent');
    const pendingValue = decodeURIComponent(pendingCookie?.value ?? '');
    expect(pendingValue, 'persistent cookie must not carry any `action:` token while the age gate is pending').not.toMatch(/(?:^|,)action:/);
    const agePending = await page.evaluate(() => sessionStorage.getItem('faz_age_gate_pending'));
    expect(agePending).toBe('1');

    await page.locator('.faz-age-gate-btn-no').click();
    await page.waitForFunction(() => document.cookie.includes('fazcookie-consent'));
    const consentCookie = (await context.cookies(baseURL)).find((cookie) => cookie.name === 'fazcookie-consent');
    const rejectedConsent = decodeURIComponent(consentCookie?.value ?? '');
    expect(rejectedConsent).toContain('action:yes');
    expect(rejectedConsent).toContain('consent:no');
    expect(rejectedConsent).toContain('analytics:no');
    expect(rejectedConsent).toContain('marketing:no');
    await context.close();
  });

  test('microsoft consent toggles enqueue UET defaults and update UET/Clarity on consent', async ({ browser }) => {
    await putSettings({
      banner_control: { status: true },
      microsoft: { uet_consent_mode: true, clarity_consent: true },
    });

    const { context, page } = await newVisitorPage(browser, '/', async (ctx) => {
      await ctx.addInitScript(() => {
        (window as any).__clarityCalls = [];
        (window as any).clarity = (...args: unknown[]) => (window as any).__clarityCalls.push(args);
      });
    });

    expect(await page.evaluate(() => (window as any)._fazMicrosoftUET)).toBe(true);
    expect(await page.evaluate(() => (window as any)._fazMicrosoftClarity)).toBe(true);
    expect(await page.evaluate(() => (window as any).uetq.slice(0, 3))).toEqual([
      'consent',
      'default',
      { ad_storage: 'denied', analytics_storage: 'denied' },
    ]);

    await acceptAll(page);
    await page.waitForFunction(() => (window as any).uetq.length >= 6);
    expect(await page.evaluate(() => (window as any).uetq.slice(-3))).toEqual([
      'consent',
      'update',
      { ad_storage: 'granted', analytics_storage: 'granted' },
    ]);
    expect(await page.evaluate(() => (window as any).__clarityCalls)).toContainEqual(expect.arrayContaining(['consent']));
    await context.close();
  });

  test('iab settings are projected into frontend TCF config when enabled', async ({ browser }) => {
    await putSettings({
      banner_control: { status: true },
      iab: {
        enabled: true,
        publisher_cc: 'DE',
        cmp_id: 123,
        purpose_one_treatment: true,
      },
    });

    const { context, page } = await newVisitorPage(browser);
    const config = await page.evaluate(() => ({
      iabEnabled: (window as any)._fazConfig?._iabEnabled,
      tcf: (window as any)._fazTcfConfig,
    }));

    expect(Boolean(config.iabEnabled)).toBe(true);
    expect(config.tcf.publisherCC).toBe('DE');
    expect(config.tcf.cmpId).toBe(123);
    expect(config.tcf.purposeOneTreatment).toBe(true);
    await context.close();
  });

  // 1.18.2 HOTFIX: per-service consent is force-disabled — _services is no longer
  // exposed to the frontend and no service rows render. Re-enable with the feature.
  test.skip('per_service_consent exposes services and renders service toggles in preferences', async ({ browser }) => {
    await putSettings({
      banner_control: { status: true, per_service_consent: true },
    });

    const { context, page } = await newVisitorPage(browser);
    const services = await page.evaluate(() => (window as any)._fazConfig?._services ?? []);
    expect(Array.isArray(services)).toBe(true);
    expect(services.length).toBeGreaterThan(0);
    expect(services.every((service: any) => Array.isArray(service.cookies))).toBe(true);

    const settingsClicked = await clickFirstVisible(page, [
      '[data-faz-tag="settings-button"] button',
      '[data-faz-tag="settings-button"]',
      '.faz-btn-customize',
    ]);
    expect(settingsClicked).toBe(true);
    await expect.poll(() => page.locator('.faz-service-row').count()).toBeGreaterThan(0);
    await context.close();
  });

  test('script_blocking whitelist_patterns bypass provider blocking before consent', async ({ browser }) => {
    await putSettings({
      banner_control: { status: true },
      script_blocking: {
        whitelist_patterns: ['connect.facebook.net/en_US/fbevents.js'],
      },
    });

    const { context, page } = await newVisitorPage(browser);
    const result = await page.evaluate(() => {
      const whitelisted = document.createElement('script');
      whitelisted.id = 'faz-whitelisted-provider-probe';
      whitelisted.src = 'https://connect.facebook.net/en_US/fbevents.js';
      document.head.appendChild(whitelisted);

      const blocked = document.createElement('script');
      blocked.id = 'faz-blocked-provider-probe';
      blocked.src = 'https://www.googletagmanager.com/gtag/js?id=G-TEST';
      document.head.appendChild(blocked);

      return {
        whitelistedType: whitelisted.getAttribute('type'),
        blockedType: blocked.getAttribute('type'),
        userWhitelist: (window as any)._fazConfig?._userWhitelist,
      };
    });

    expect(result.userWhitelist).toContain('connect.facebook.net/en_US/fbevents.js');
    expect(result.whitelistedType).not.toBe('javascript/blocked');
    expect(result.blockedType).toBe('javascript/blocked');
    await context.close();
  });
});
