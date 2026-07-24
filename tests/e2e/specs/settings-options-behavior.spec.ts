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

  test('age_gate gates only the accept path via an inline equal-weight checkbox', async ({ browser }) => {
    await putSettings({
      banner_control: { status: true },
      consent_logs: { status: true },
      age_gate: { enabled: true, min_age: 14 },
    });

    // ---- Surface 1: a fresh visitor sees an unchecked checkbox above the
    //      buttons, its label carries the configured age, and both Accept and
    //      Reject are visible & enabled (equal weight — EDPB 03/2022). ----
    const { context, page } = await newVisitorPage(browser);

    const checkbox = page.locator('.faz-age-confirm-cb').first();
    await expect(checkbox).toBeVisible();
    await expect(checkbox).not.toBeChecked(); // never pre-checked
    await expect(page.locator('.faz-age-confirm-label').first()).toContainText('14');

    // The confirmation row renders ABOVE the notice button group.
    const rowPrecedesButtons = await page.evaluate(() => {
      const group = document.querySelector('[data-faz-tag="notice-buttons"]');
      const prev = group && group.previousElementSibling;
      return !!(prev && prev.classList.contains('faz-age-confirm'));
    });
    expect(rowPrecedesButtons).toBe(true);

    const acceptBtn = page.locator('[data-faz-tag="accept-button"]').first();
    const rejectBtn = page.locator('[data-faz-tag="reject-button"]').first();
    await expect(acceptBtn).toBeVisible();
    await expect(rejectBtn).toBeVisible();
    await expect(acceptBtn).toBeEnabled(); // never disabled/greyed
    await expect(rejectBtn).toBeEnabled();

    // ---- Accept without affirming → NO action token written, inline error
    //      shown, banner stays. ----
    await acceptAll(page);
    await expect(page.locator('.faz-age-confirm-error').first()).toBeVisible();
    const noActionCookie = (await context.cookies(baseURL)).find((c) => c.name === 'fazcookie-consent');
    const noActionValue = decodeURIComponent(noActionCookie?.value ?? '');
    expect(noActionValue, 'no consent may be recorded until the visitor affirms').not.toMatch(/(?:^|,)action:/);
    await context.close();

    // ---- Surface 2: tick the checkbox then Accept → consent recorded with
    //      action:yes, and the consent-log row folds meta.age_affirmed=yes. ----
    const affirmVisit = await newVisitorPage(browser);
    let logBody: any = null;
    affirmVisit.page.on('response', async (resp) => {
      if (resp.url().includes('/faz/v1/consent') && resp.request().method() === 'POST') {
        try { logBody = resp.request().postDataJSON(); } catch { /* ignore */ }
      }
    });
    await affirmVisit.page.locator('.faz-age-confirm-cb').first().check();
    await acceptAll(affirmVisit.page);
    await affirmVisit.page.waitForFunction(() => /(?:^|;)\s*fazcookie-consent=.*action:yes/.test(decodeURIComponent(document.cookie)));
    const acceptedCookie = (await affirmVisit.context.cookies(baseURL)).find((c) => c.name === 'fazcookie-consent');
    const acceptedValue = decodeURIComponent(acceptedCookie?.value ?? '');
    expect(acceptedValue).toContain('action:yes');
    expect(acceptedValue).toContain('consent:yes');
    // The consent event carries ageAffirmed → the server logger folds the
    // reserved meta.age_affirmed:yes key into the categories map. The log POST
    // is fire-and-forget, so wait for the captured request body.
    await affirmVisit.page.waitForTimeout(800); // allow the fire-and-forget log POST to flush
    expect(logBody, 'a consent-log POST must fire on accept').not.toBeNull();
    expect(logBody.categories && logBody.categories['meta.age_affirmed']).toBe('yes');
    await affirmVisit.context.close();

    // ---- Surface 3: Reject is ungated — no affirmation needed, records a
    //      rejection with every non-necessary category denied. ----
    const rejectVisit = await newVisitorPage(browser);
    let rejectLogBody: any = null;
    rejectVisit.page.on('request', (request) => {
      if (request.url().includes('/faz/v1/consent') && request.method() === 'POST') {
        try { rejectLogBody = request.postDataJSON(); } catch { /* ignore malformed diagnostics */ }
      }
    });
    const clickedReject = await clickFirstVisible(rejectVisit.page, [
      '[data-faz-tag="reject-button"] button',
      '[data-faz-tag="reject-button"]',
      '.faz-btn-reject',
    ]);
    expect(clickedReject).toBe(true);
    await rejectVisit.page.waitForFunction(() => document.cookie.includes('fazcookie-consent'));
    const rejectCookie = (await rejectVisit.context.cookies(baseURL)).find((c) => c.name === 'fazcookie-consent');
    const rejectValue = decodeURIComponent(rejectCookie?.value ?? '');
    expect(rejectValue).toContain('action:yes');
    expect(rejectValue).toContain('consent:no');
    expect(rejectValue).toContain('analytics:no');
    expect(rejectValue).toContain('marketing:no');
    // A rejection never affirms age: validate the actual consent-log payload,
    // not the consent cookie (which never carries metadata keys).
    await expect.poll(() => rejectLogBody, { message: 'a consent-log POST must fire on reject' }).not.toBeNull();
    expect(rejectLogBody.categories?.['meta.age_affirmed']).toBeUndefined();
    await rejectVisit.context.close();
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
