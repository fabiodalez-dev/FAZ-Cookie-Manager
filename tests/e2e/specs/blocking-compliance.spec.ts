import type { BrowserContext, Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { clickFirstVisible } from '../utils/ui';
import { fazApiGet, fazApiPost, openSettingsPage } from '../utils/faz-api';
import {
  deactivatePluginsExcept,
  enableProviderMatrixCustomScenario,
  ensureFixturePlugin,
  ensureProviderMatrixPage,
  ensureScanLabPages,
  readProviderMatrixHits,
  readProviderMatrixUrl,
  resetProviderMatrixState,
  wpEval,
} from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';
const IS_PHP_BUILT_IN_E2E = (process.env.FAZ_E2E_SERVER ?? 'php-built-in').toLowerCase() === 'php-built-in';

type SettingsPayload = Record<string, any>;
type CategoryConsentState = Record<string, boolean>;

// Stripe is excluded from this matrix because it is always-whitelisted as a
// payment gateway (get_always_allowed_gateway_patterns). Testing Stripe
// blocking here would always fail — and it should, because blocking payment
// scripts breaks checkout.
const OBSERVED_CATEGORY_PROVIDERS = [
  { slug: 'analytics', cookieName: '_ga', hitKey: 'ga-monsterinsights' },
  { slug: 'marketing', cookieName: '_fbp', hitKey: 'facebook-pixel' },
  { slug: 'performance', cookieName: '_faz_custom_provider', hitKey: 'custom-unknown' },
  { slug: 'functional', cookieName: '_faz_custom_functional', hitKey: 'custom-functional' },
] as const;

const CUSTOM_RULES = [
  { category: 'performance', pattern: 'faz-lab-custom-provider.js' },
  { category: 'functional', pattern: 'faz-lab-custom-functional.js' },
];

function buildConsentCombinations(categories: string[]): CategoryConsentState[] {
  const combinations: CategoryConsentState[] = [];
  const total = 1 << categories.length;

  for (let mask = 0; mask < total; mask += 1) {
    const state: CategoryConsentState = {};
    categories.forEach((slug, index) => {
      state[slug] = Boolean(mask & (1 << index));
    });
    combinations.push(state);
  }

  return combinations;
}

function formatConsentState(state: CategoryConsentState): string {
  return Object.entries(state)
    .map(([slug, allowed]) => `${slug}=${allowed ? 'yes' : 'no'}`)
    .join(', ');
}

function withCustomRules(scriptBlocking: SettingsPayload | undefined): SettingsPayload {
  const currentRules = Array.isArray(scriptBlocking?.custom_rules) ? scriptBlocking.custom_rules : [];
  const merged = [...currentRules];
  for (const rule of CUSTOM_RULES) {
    if (!merged.some((r: any) => r?.category === rule.category && r?.pattern === rule.pattern)) {
      merged.push(rule);
    }
  }

  return {
    ...(scriptBlocking ?? {}),
    custom_rules: merged,
  };
}

function directCollectUrl(path: string): string {
  return `${WP_BASE}/faz-e2e-provider-collect/${path}`;
}

async function getSettings(page: Page, nonce: string): Promise<SettingsPayload> {
  const response = await fazApiGet<SettingsPayload>(page, nonce, 'settings');
  expect(response.status).toBe(200);
  return response.data;
}

async function postSettings(page: Page, nonce: string, payload: SettingsPayload): Promise<void> {
  const response = await fazApiPost<SettingsPayload>(page, nonce, 'settings', payload);
  expect(response.status).toBe(200);
}

async function gotoFrontend(page: Page, url: string): Promise<void> {
  await page.goto(url, { waitUntil: 'commit' });
  await page.locator('body').waitFor({ state: 'visible' });
}

async function waitForCookie(page: Page, name: string, timeout = 20_000): Promise<void> {
  await page.waitForFunction(
    (cookieName) => document.cookie.split(';').some((chunk) => chunk.trim().startsWith(`${cookieName}=`)),
    name,
    { timeout },
  );
}

async function browserCookieNames(page: Page): Promise<string[]> {
  return page.evaluate(() =>
    document.cookie
      .split(';')
      .map((chunk) => chunk.trim())
      .filter(Boolean)
      .map((chunk) => chunk.split('=')[0]?.trim())
      .filter((name): name is string => Boolean(name)),
  );
}

async function blockedScriptCount(page: Page, category: string): Promise<number> {
  return page.locator(`script[type="text/plain"][data-faz-category="${category}"]`).count();
}

async function openPreferenceCenter(page: Page): Promise<void> {
  const opened = await clickFirstVisible(page, [
    '[data-faz-tag="settings-button"] button',
    '[data-faz-tag="settings-button"]',
    '.faz-btn-customize',
  ]);
  expect(opened).toBeTruthy();
  // The preference center may be inside .faz-modal (popup) or embedded (pushdown/classic).
  const modal = page.locator('.faz-modal.faz-modal-open [data-faz-tag="detail"]');
  const fallback = page.locator('[data-faz-tag="detail"]');
  const target = (await modal.count()) > 0 ? modal : fallback;
  await expect(target).toBeVisible({ timeout: 10_000 });
}

async function savePreferences(page: Page): Promise<void> {
  const saved = await clickFirstVisible(page, [
    '[data-faz-tag="detail-save-button"] button',
    '[data-faz-tag="detail-save-button"]',
    '.faz-btn-preferences',
  ]);
  expect(saved).toBeTruthy();
}

async function acceptAll(page: Page): Promise<void> {
  const accepted = await clickFirstVisible(page, [
    '[data-faz-tag="accept-button"] button',
    '[data-faz-tag="accept-button"]',
    '.faz-btn-accept',
  ]);
  expect(accepted).toBeTruthy();
}

async function setCategoryToggle(page: Page, slug: string, checked: boolean): Promise<void> {
  const switchToggle = page.locator(`#fazSwitch${slug}`);
  const directToggle = page.locator(`#fazCategoryDirect${slug}`);
  const toggle = (await switchToggle.count()) > 0 ? switchToggle : directToggle;

  await expect(toggle).toHaveCount(1);
  await toggle.scrollIntoViewIfNeeded();
  try {
    await toggle.setChecked(checked, { force: true });
  } catch {
    await toggle.evaluate((element, value) => {
      const input = element as HTMLInputElement;
      input.checked = value;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }, checked);
  }
}

async function setServiceToggle(page: Page, serviceId: string, checked: boolean): Promise<void> {
  const toggle = page.locator(`.faz-service-toggle[data-service="${serviceId}"]`);
  await expect(toggle).toHaveCount(1);
  await toggle.scrollIntoViewIfNeeded();
  try {
    await toggle.setChecked(checked, { force: true });
  } catch {
    await toggle.evaluate((element, value) => {
      const input = element as HTMLInputElement;
      input.checked = value;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }, checked);
  }
}

async function setConsentCookie(context: BrowserContext, url: string, values: Record<string, string>): Promise<void> {
  const value = Object.entries(values)
    .map(([key, entry]) => `${key}:${entry}`)
    .join(',');

  await context.addCookies([
    {
      name: 'fazcookie-consent',
      sameSite: 'Lax',
      url,
      value,
    },
  ]);
}

async function runDirectFetch(page: Page, url: string): Promise<void> {
  await page.evaluate(async (targetUrl) => {
    await fetch(targetUrl, { credentials: 'same-origin', method: 'POST' });
  }, url);
}

async function runDirectXhr(page: Page, url: string): Promise<void> {
  await page.evaluate(
    async (targetUrl) =>
      new Promise<void>((resolve) => {
        const xhr = new XMLHttpRequest();
        let settled = false;
        const done = () => {
          if (settled) {
            return;
          }
          settled = true;
          resolve();
        };

        xhr.addEventListener('load', done);
        xhr.addEventListener('loadend', done);
        xhr.addEventListener('error', done);
        xhr.addEventListener('abort', done);
        xhr.onreadystatechange = () => {
          if (xhr.readyState === 4) {
            done();
          }
        };

        xhr.open('POST', targetUrl, true);
        xhr.send('1');
        window.setTimeout(done, 1_500);
      }),
    url,
  );
}

async function runDirectBeacon(page: Page, url: string): Promise<boolean> {
  return page.evaluate((targetUrl) => navigator.sendBeacon(targetUrl, '1'), url);
}

function readPageUrl(slug: string): string {
  return wpEval(`
    $page = get_page_by_path( ${JSON.stringify(slug)}, OBJECT, 'page' );
    echo $page ? get_permalink( $page->ID ) : '';
  `);
}

test.describe('Blocking compliance coverage', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(360_000);

  let matrixUrl = '';
  let matrixPageId = 0;
  let matrixPagePattern = '';
  let iframeLabUrl = '';

  test.beforeAll(async () => {
    deactivatePluginsExcept([
      'faz-cookie-manager',
      'faz-e2e-provider-matrix',
      'faz-e2e-scan-lab',
    ]);
    ensureFixturePlugin('faz-e2e-provider-matrix');
    ensureFixturePlugin('faz-e2e-scan-lab');
    matrixPageId = ensureProviderMatrixPage();
    ensureScanLabPages();
    matrixUrl = readProviderMatrixUrl();
    iframeLabUrl = readPageUrl('faz-lab-iframe-youtube');

    if (!matrixUrl) {
      throw new Error('Provider matrix page URL could not be resolved.');
    }

    if (!iframeLabUrl) {
      throw new Error('Iframe lab page URL could not be resolved.');
    }

    matrixPagePattern = `${new URL(matrixUrl).pathname.replace(/\/$/, '')}*`;
  });

  test.beforeEach(async () => {
    resetProviderMatrixState();
  });

  test('covers every observable category-consent combination against real providers', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await getSettings(page, nonce);
    const revision = String(Math.max(1, Number(original.general?.consent_revision ?? 1)));

    try {
      await postSettings(page, nonce, {
        script_blocking: withCustomRules(original.script_blocking),
      });

      const combinations = buildConsentCombinations(OBSERVED_CATEGORY_PROVIDERS.map((entry) => entry.slug));

      for (const consentState of combinations) {
        await test.step(`consent matrix: ${formatConsentState(consentState)}`, async () => {
          resetProviderMatrixState();
          enableProviderMatrixCustomScenario();

          const visitor = await browser.newContext({ baseURL: WP_BASE });
          try {
            await setConsentCookie(visitor, matrixUrl, {
              action: 'custom',
              consent: 'yes',
              necessary: 'yes',
              rev: revision,
              uncategorized: 'no',
              analytics: consentState.analytics ? 'yes' : 'no',
              marketing: consentState.marketing ? 'yes' : 'no',
              functional: consentState.functional ? 'yes' : 'no',
              performance: consentState.performance ? 'yes' : 'no',
            });

            const visitorPage = await visitor.newPage();
            await gotoFrontend(visitorPage, matrixUrl);

            for (const provider of OBSERVED_CATEGORY_PROVIDERS) {
              if (consentState[provider.slug]) {
                await waitForCookie(visitorPage, provider.cookieName);
              }
            }

            await visitorPage.waitForTimeout(750);

            const cookieNames = await browserCookieNames(visitorPage);
            const hits = readProviderMatrixHits();

            for (const provider of OBSERVED_CATEGORY_PROVIDERS) {
              const isAllowed = consentState[provider.slug];
              const count = await blockedScriptCount(visitorPage, provider.slug);

              if (isAllowed) {
                expect(cookieNames, `${provider.slug} cookie should be present when consent is granted`).toContain(provider.cookieName);
                expect(count, `${provider.slug} scripts should not stay blocked when consent is granted`).toBe(0);
                expect(hits[provider.hitKey] ?? 0, `${provider.slug} provider should be allowed to execute`).toBeGreaterThanOrEqual(1);
              } else {
                expect(cookieNames, `${provider.slug} cookie must stay absent when consent is denied`).not.toContain(provider.cookieName);
                expect(hits[provider.hitKey] ?? 0, `${provider.slug} provider must not execute when consent is denied`).toBe(0);
              }
            }
          } finally {
            await visitor.close();
          }
        });
      }
    } finally {
      await postSettings(page, nonce, {
        script_blocking: original.script_blocking ?? {},
      });
      resetProviderMatrixState();
    }
  });

  test('saving custom preferences from the UI persists category consent and only unblocks granted categories', async ({
    page,
    getConsentCookie,
    loginAsAdmin,
    parseConsentCookie,
  }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await getSettings(page, nonce);

    try {
      await postSettings(page, nonce, {
        script_blocking: withCustomRules(original.script_blocking),
      });

      enableProviderMatrixCustomScenario();
      await gotoFrontend(page, matrixUrl);
      await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

      await openPreferenceCenter(page);

      const renderedCategories = await page.locator('input[id^="fazSwitch"], input[id^="fazCategoryDirect"]').evaluateAll((inputs) =>
        Array.from(
          new Set(
            inputs
              .map((input) => input.id.replace(/^fazSwitch/, '').replace(/^fazCategoryDirect/, ''))
              .filter(Boolean),
          ),
        ),
      );

      const desiredState: CategoryConsentState = {
        analytics: true,
        functional: true,
        marketing: false,
        performance: false,
        uncategorized: true,
      };

      for (const slug of renderedCategories) {
        if (!(slug in desiredState)) {
          continue;
        }
        await setCategoryToggle(page, slug, desiredState[slug]);
      }

      await savePreferences(page);
      await waitForCookie(page, '_ga');
      await waitForCookie(page, '_faz_custom_functional');
      await page.waitForTimeout(750);

      const consent = await getConsentCookie(page.context());
      expect(consent).toBeDefined();

      const parsed = parseConsentCookie(consent!.value);
      if (renderedCategories.includes('analytics')) {
        expect(parsed.analytics).toBe('yes');
      }
      if (renderedCategories.includes('functional')) {
        expect(parsed.functional).toBe('yes');
      }
      if (renderedCategories.includes('marketing')) {
        expect(parsed.marketing).toBe('no');
      }
      if (renderedCategories.includes('performance')) {
        expect(parsed.performance).toBe('no');
      }
      if (renderedCategories.includes('uncategorized')) {
        expect(parsed.uncategorized).toBe('yes');
      }

      const cookieNames = await browserCookieNames(page);
      expect(cookieNames).toContain('_ga');
      expect(cookieNames).toContain('_faz_custom_functional');
      expect(cookieNames).not.toContain('_fbp');
      expect(cookieNames).not.toContain('_faz_custom_provider');

      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(750);

      const cookieNamesAfterReload = await browserCookieNames(page);
      expect(cookieNamesAfterReload).toContain('_ga');
      expect(cookieNamesAfterReload).toContain('_faz_custom_functional');
      expect(cookieNamesAfterReload).not.toContain('_fbp');
      expect(cookieNamesAfterReload).not.toContain('_faz_custom_provider');
    } finally {
      await postSettings(page, nonce, {
        script_blocking: original.script_blocking ?? {},
      });
      resetProviderMatrixState();
    }
  });

  test('per-service consent from the UI can allow Google Analytics while keeping Clarity blocked', async ({
    page,
    getConsentCookie,
    loginAsAdmin,
    parseConsentCookie,
  }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await getSettings(page, nonce);

    try {
      await postSettings(page, nonce, {
        banner_control: {
          ...(original.banner_control ?? {}),
          per_service_consent: true,
        },
      });

      await gotoFrontend(page, matrixUrl);
      await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

      await openPreferenceCenter(page);
      const renderedCategories = await page.locator('input[id^="fazSwitch"], input[id^="fazCategoryDirect"]').evaluateAll((inputs) =>
        Array.from(
          new Set(
            inputs
              .map((input) => input.id.replace(/^fazSwitch/, '').replace(/^fazCategoryDirect/, ''))
              .filter(Boolean),
          ),
        ),
      );

      if (renderedCategories.includes('analytics')) {
        await setCategoryToggle(page, 'analytics', true);
      }
      if (renderedCategories.includes('marketing')) {
        await setCategoryToggle(page, 'marketing', false);
      }
      if (renderedCategories.includes('functional')) {
        await setCategoryToggle(page, 'functional', false);
      }

      await page.locator('#fazDetailCategoryanalytics .faz-accordion-header-wrapper').click();
      await page.locator('.faz-service-toggle[data-service="google-analytics"]').waitFor({ state: 'attached' });

      await setServiceToggle(page, 'google-analytics', true);
      await setServiceToggle(page, 'clarity', false);
      await savePreferences(page);

      await waitForCookie(page, '_ga');
      await page.waitForTimeout(750);

      const consent = await getConsentCookie(page.context());
      expect(consent).toBeDefined();

      const parsed = parseConsentCookie(consent!.value);
      expect(parsed.analytics).toBe('yes');
      // `svc.<id>` entries are stored only when they diverge from the
      // category consent — the frontend's per-service loader falls back
      // to the category when an explicit entry is absent (see
      // `_fazUpdateServiceToggleStates` / `_fazShouldBlockProvider`).
      // Clarity is explicitly denied inside an accepted category, so it
      // must be present as `no`; Google Analytics aligns with its
      // category (`yes`) and may therefore be either explicit `yes` or
      // omitted, both of which resolve to "allowed" on reload. Keeping
      // the cookie minimal this way is what lets installs with large
      // service catalogs stay under the browser's 4 KB cookie limit.
      expect(['yes', undefined]).toContain(parsed['svc.google-analytics']);
      expect(parsed['svc.clarity']).toBe('no');

      const cookieNames = await browserCookieNames(page);
      expect(cookieNames).toContain('_ga');
      expect(cookieNames).not.toContain('_clck');
      expect(cookieNames).not.toContain('_fbp');

      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(750);

      const cookieNamesAfterReload = await browserCookieNames(page);
      expect(cookieNamesAfterReload).toContain('_ga');
      expect(cookieNamesAfterReload).not.toContain('_clck');
    } finally {
      await postSettings(page, nonce, {
        banner_control: original.banner_control ?? {},
      });
      resetProviderMatrixState();
    }
  });

  test('script blocking excluded pages keep the banner visible but bypass scripts and network gating', async ({ page, loginAsAdmin }) => {
    test.skip(IS_PHP_BUILT_IN_E2E, 'Fixture page is_singular() is unreliable on the PHP built-in server.');
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await getSettings(page, nonce);

    try {
      await postSettings(page, nonce, {
        script_blocking: {
          ...withCustomRules(original.script_blocking),
          excluded_pages: [matrixPagePattern],
        },
      });

      enableProviderMatrixCustomScenario();
      await gotoFrontend(page, matrixUrl);
      await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

      // On excluded pages scripts run freely. Fixture cookies depend on
      // is_singular() resolving for the matrix page — give them extra time.
      await page.waitForTimeout(2_000);

      await page.waitForTimeout(750);

      for (const provider of OBSERVED_CATEGORY_PROVIDERS) {
        expect(await blockedScriptCount(page, provider.slug), `${provider.slug} must not be blocked on excluded pages`).toBe(0);
      }

      await runDirectFetch(page, directCollectUrl('googletagmanager.com/gtag/js'));
      await runDirectXhr(page, directCollectUrl('clarity.ms/tag/faz-matrix.js'));
      expect(await runDirectBeacon(page, directCollectUrl('connect.facebook.net/en_US/fbevents.js'))).toBe(true);
      await page.waitForTimeout(750);

      const hits = readProviderMatrixHits();
      expect(hits['googletagmanager.com/gtag/js'] ?? 0).toBeGreaterThanOrEqual(1);
      expect(hits['clarity.ms/tag/faz-matrix.js'] ?? 0).toBeGreaterThanOrEqual(1);
      expect(hits['connect.facebook.net/en_US/fbevents.js'] ?? 0).toBeGreaterThanOrEqual(1);
    } finally {
      await postSettings(page, nonce, {
        script_blocking: original.script_blocking ?? {},
      });
      resetProviderMatrixState();
    }
  });

  test('banner excluded pages hide the FAZ runtime entirely and therefore do not block provider execution', async ({
    page,
    loginAsAdmin,
  }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await getSettings(page, nonce);

    try {
      await postSettings(page, nonce, {
        banner_control: {
          ...(original.banner_control ?? {}),
          excluded_pages: [String(matrixPageId)],
        },
        script_blocking: withCustomRules(original.script_blocking),
      });

      enableProviderMatrixCustomScenario();
      await gotoFrontend(page, matrixUrl);

      await expect(page.locator('[data-faz-tag="notice"]')).toHaveCount(0);

      const hasFrontendRuntime = await page.evaluate(() => typeof (window as any)._fazConfig !== 'undefined');
      expect(hasFrontendRuntime).toBe(false);

      await waitForCookie(page, '_ga');
      await waitForCookie(page, '_fbp');
      await waitForCookie(page, '_faz_custom_functional');
      await waitForCookie(page, '_faz_custom_provider');
    } finally {
      await postSettings(page, nonce, {
        banner_control: original.banner_control ?? {},
        script_blocking: original.script_blocking ?? {},
      });
      resetProviderMatrixState();
    }
  });

  test('whitelist patterns also exempt fetch, XHR, and beacon interception before consent', async ({ page, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await getSettings(page, nonce);
    const originalWhitelist = Array.isArray(original.script_blocking?.whitelist_patterns)
      ? original.script_blocking.whitelist_patterns
      : [];

    try {
      await postSettings(page, nonce, {
        script_blocking: {
          ...(original.script_blocking ?? {}),
          whitelist_patterns: [
            ...originalWhitelist,
            'connect.facebook.net/en_US/fbevents.js',
            'clarity.ms/tag/faz-matrix.js',
          ],
        },
      });

      await gotoFrontend(page, matrixUrl);
      await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

      await runDirectFetch(page, directCollectUrl('googletagmanager.com/gtag/js'));
      await runDirectXhr(page, directCollectUrl('clarity.ms/tag/faz-matrix.js'));
      expect(await runDirectBeacon(page, directCollectUrl('connect.facebook.net/en_US/fbevents.js'))).toBe(true);
      await page.waitForTimeout(750);

      const hits = readProviderMatrixHits();
      expect(hits['googletagmanager.com/gtag/js'] ?? 0).toBe(0);
      expect(hits['clarity.ms/tag/faz-matrix.js'] ?? 0).toBeGreaterThanOrEqual(1);
      expect(hits['connect.facebook.net/en_US/fbevents.js'] ?? 0).toBeGreaterThanOrEqual(1);
    } finally {
      await postSettings(page, nonce, {
        script_blocking: original.script_blocking ?? {},
      });
      resetProviderMatrixState();
    }
  });

  test('marketing iframe embeds are neutralized before consent and restored after acceptance', async ({ page }) => {
    await gotoFrontend(page, iframeLabUrl);
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    const placeholder = page.locator('.faz-placeholder[data-faz-category="marketing"]').first();
    await expect(placeholder).toBeVisible();

    const hasStoredBlockedIframe = await page.evaluate(() => {
      const templates = Array.from(
        document.querySelectorAll('.faz-placeholder[data-faz-category="marketing"] template.faz-placeholder-content'),
      );
      return templates.some(
        (template) => template instanceof HTMLTemplateElement
          && Boolean(template.content.querySelector('iframe[data-faz-src*="youtube.com/embed"]')),
      );
    });
    expect(hasStoredBlockedIframe).toBe(true);

    await acceptAll(page);
    await page.goto(iframeLabUrl, { waitUntil: 'domcontentloaded' });

    expect(await page.locator('iframe[src*="youtube.com/embed"]').count()).toBeGreaterThan(0);
    await expect(page.locator('iframe[data-faz-src*="youtube.com/embed"]')).toHaveCount(0);
  });
});
