import { type Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { getWpLoginPath } from '../utils/wp-auth';

type SettingsTree = Record<string, any>;

type SettingCase = {
  path: string;
  validValue: unknown | ((current: SettingsTree) => unknown);
  expectedValid?: unknown | ((current: SettingsTree, sent: unknown) => unknown);
  invalidValue: unknown | ((current: SettingsTree) => unknown);
  expectedInvalid: unknown | ((current: SettingsTree, sent: unknown) => unknown);
  ui?: 'single' | 'multi-checkbox';
};

let adminPage: Page;
let nonce = '';
let originalSettings: SettingsTree;
let baseURL = '';

const settingsUrl = (credsBaseURL = baseURL) => `${credsBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-settings`;

async function loginAsAdminForSettingsMatrix(page: Page, wpBaseURL: string, adminUser: string, adminPass: string): Promise<string> {
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
  const cookieHosts = Array.from(new Set([loginHost, postHost]));
  await page.context().addCookies(cookieHosts.map((host) => (
    {
      name: 'wordpress_test_cookie',
      value: 'WP Cookie check',
      domain: host,
      path: '/',
    }
  )));

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

  if (!page.url().includes('/wp-admin/')) {
    const loginError = await page.locator('#login_error').textContent().catch(() => '');
    throw new Error(`WordPress admin login failed. URL=${page.url()} error=${loginError ?? 'n/a'}`);
  }

  await expect(page.locator('#wpadminbar')).toBeVisible();
  return new URL(page.url()).origin;
}

function getPath(source: SettingsTree, path: string): any {
  return path.split('.').reduce((value, key) => (value == null ? undefined : value[key]), source);
}

function setPath(path: string, value: unknown): SettingsTree {
  const UNSAFE_KEYS = new Set(['__proto__', 'constructor', 'prototype']);
  const parts = path.split('.');
  const clone: SettingsTree = {};
  let cursor = clone;
  for (let index = 0; index < parts.length - 1; index += 1) {
    const key = parts[index];
    if (UNSAFE_KEYS.has(key)) throw new Error(`Unsafe path key: ${key}`);
    cursor[key] = {};
    cursor = cursor[key];
  }
  const last = parts[parts.length - 1];
  if (UNSAFE_KEYS.has(last)) throw new Error(`Unsafe path key: ${last}`);
  cursor[last] = value;
  return clone;
}

function resolveValue<T>(value: T | ((current: SettingsTree, sent?: unknown) => T), current: SettingsTree, sent?: unknown): T {
  return typeof value === 'function' ? (value as (current: SettingsTree, sent?: unknown) => T)(current, sent) : value;
}

async function refreshNonceFromAdmin(): Promise<void> {
  // Reload the settings page so PHP re-issues a nonce minted against the
  // current wp_salt. Long full-suite runs can rotate the salt or invalidate
  // the captured session between beforeEach and afterEach (other specs
  // exercise auth rotation), causing the cached top-level `nonce` to start
  // returning 401/403 against rest_route=/faz/v1/settings/.
  await adminPage.goto(settingsUrl(), { waitUntil: 'domcontentloaded' });
  const fresh = await adminPage.evaluate(() => (window as any).fazConfig?.api?.nonce ?? '');
  if (typeof fresh === 'string' && fresh.length > 0) {
    nonce = fresh;
  }
}

async function getSettings(): Promise<SettingsTree> {
  let response = await adminPage.request.get(`${baseURL}/?rest_route=/faz/v1/settings/`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  if (response.status() === 401 || response.status() === 403) {
    // Nonce went stale — refresh and retry exactly once.
    await refreshNonceFromAdmin();
    response = await adminPage.request.get(`${baseURL}/?rest_route=/faz/v1/settings/`, {
      headers: { 'X-WP-Nonce': nonce },
    });
  }
  expect(response.status()).toBe(200);
  return (await response.json()) as SettingsTree;
}

async function postSettings(payload: SettingsTree): Promise<SettingsTree> {
  let response = await adminPage.request.post(`${baseURL}/?rest_route=/faz/v1/settings/`, {
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
    data: payload,
  });
  if (response.status() === 401 || response.status() === 403) {
    await refreshNonceFromAdmin();
    response = await adminPage.request.post(`${baseURL}/?rest_route=/faz/v1/settings/`, {
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
      },
      data: payload,
    });
  }
  expect(response.status()).toBe(200);
  return (await response.json()) as SettingsTree;
}

async function restoreOriginalSettings(): Promise<void> {
  if (!originalSettings) {
    return;
  }
  const current = await getSettings();
  const originalRevision = Number(originalSettings.general?.consent_revision ?? 1);
  const currentRevision = Number(current.general?.consent_revision ?? originalRevision);
  await postSettings({
    ...originalSettings,
    general: {
      ...originalSettings.general,
      consent_revision: Math.max(originalRevision, currentRevision),
    },
  });
}

async function openSettingsAndWait(): Promise<void> {
  await adminPage.goto(settingsUrl(), { waitUntil: 'domcontentloaded' });
  await expect(adminPage.locator('#faz-settings')).toBeVisible();
}

async function readUiValue(setting: SettingCase): Promise<unknown> {
  if (setting.ui === 'multi-checkbox') {
    return adminPage.locator(`[data-path="${setting.path}"]:checked`).evaluateAll((nodes) =>
      nodes.map((node) => (node as HTMLInputElement).value),
    );
  }

  const locator = adminPage.locator(`[data-path="${setting.path}"]`);
  const first = locator.first();
  const tagName = await first.evaluate((node) => node.tagName.toLowerCase());
  if (tagName === 'input') {
    const type = await first.getAttribute('type');
    if (type === 'checkbox') {
      return first.isChecked();
    }
    return first.inputValue();
  }
  if (tagName === 'textarea' || tagName === 'select') {
    return first.inputValue();
  }
  return first.textContent();
}

function expectedUiValue(value: unknown, setting: SettingCase): unknown {
  if (setting.ui === 'multi-checkbox') {
    return value;
  }
  if (Array.isArray(value)) {
    return value.join('\n');
  }
  if (typeof value === 'number') {
    return String(value);
  }
  return value;
}

const booleanSetting = (path: string): SettingCase => ({
  path,
  validValue: true,
  expectedValid: true,
  invalidValue: 'false',
  expectedInvalid: false,
});

const trimmedListSetting = (path: string, validValue: string[]): SettingCase => ({
  path,
  validValue,
  expectedValid: validValue,
  invalidValue: 'not-an-array',
  expectedInvalid: [],
});

const settings: SettingCase[] = [
  booleanSetting('banner_control.status'),
  trimmedListSetting('banner_control.excluded_pages', ['/privacy/*', '42']),
  booleanSetting('banner_control.hide_from_bots'),
  booleanSetting('banner_control.gtm_datalayer'),
  booleanSetting('banner_control.alternative_asset_path'),
  booleanSetting('banner_control.per_service_consent'),
  booleanSetting('banner_control.subdomain_sharing'),
  booleanSetting('consent_forwarding.enabled'),
  {
    path: 'consent_forwarding.target_domains',
    validValue: ['https://shop.example.com', 'http://app.example.test/path'],
    expectedValid: ['https://shop.example.com', 'http://app.example.test/path'],
    invalidValue: ['javascript:alert(1)', '/relative/path', 'mailto:test@example.com'],
    expectedInvalid: [],
  },
  booleanSetting('pageview_tracking'),
  trimmedListSetting('script_blocking.excluded_pages', ['/checkout/*', '/cart/*']),
  trimmedListSetting('script_blocking.whitelist_patterns', ['googleapis.com/youtube/v3', 'recaptcha', 'my-inline-script-id']),
  booleanSetting('consent_logs.status'),
  {
    path: 'consent_logs.retention',
    validValue: 18,
    expectedValid: 18,
    invalidValue: 999,
    expectedInvalid: 120,
  },
  {
    path: 'scanner.max_pages',
    validValue: 37,
    expectedValid: 37,
    invalidValue: -5,
    expectedInvalid: 5,
  },
  booleanSetting('scanner.debug_mode'),
  booleanSetting('scanner.auto_scan'),
  {
    path: 'scanner.scan_frequency',
    validValue: 'daily',
    expectedValid: 'daily',
    invalidValue: 'fortnightly',
    expectedInvalid: 'weekly',
  },
  booleanSetting('microsoft.uet_consent_mode'),
  booleanSetting('microsoft.clarity_consent'),
  booleanSetting('age_gate.enabled'),
  {
    path: 'age_gate.min_age',
    validValue: 14,
    expectedValid: 14,
    invalidValue: 99,
    expectedInvalid: 18,
  },
  booleanSetting('iab.enabled'),
  {
    path: 'iab.publisher_cc',
    validValue: 'de',
    expectedValid: 'DE',
    invalidValue: 'Germany',
    expectedInvalid: '',
  },
  {
    path: 'iab.cmp_id',
    validValue: 123,
    expectedValid: 123,
    invalidValue: 99999,
    expectedInvalid: 4095,
  },
  booleanSetting('iab.purpose_one_treatment'),
  booleanSetting('geolocation.geo_targeting'),
  {
    path: 'geolocation.target_regions',
    validValue: ['eu', 'us', 'jp'],
    expectedValid: ['eu', 'us', 'jp'],
    invalidValue: 'not-an-array',
    expectedInvalid: [],
    ui: 'multi-checkbox',
  },
  {
    path: 'geolocation.default_behavior',
    validValue: 'no_banner',
    expectedValid: 'no_banner',
    invalidValue: 'hide_everything',
    expectedInvalid: 'show_banner',
  },
  {
    path: 'geolocation.maxmind_license_key',
    validValue: 'faz-test-license-key',
    expectedValid: 'faz-test-license-key',
    invalidValue: '<b>faz-test-license-key</b>',
    expectedInvalid: 'faz-test-license-key',
  },
  booleanSetting('general.remove_data_on_uninstall'),
  {
    path: 'general.consent_revision',
    validValue: (current) => Number(current.general?.consent_revision ?? 1) + 2,
    expectedValid: (_current, sent) => sent,
    invalidValue: (current) => Number(current.general?.consent_revision ?? 1) - 1,
    expectedInvalid: (current) => Number(current.general?.consent_revision ?? 1),
  },
];

test.describe('Settings options matrix', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(async ({ page, wpBaseURL, adminUser, adminPass }) => {
    adminPage = page;
    baseURL = await loginAsAdminForSettingsMatrix(adminPage, wpBaseURL, adminUser, adminPass);
    await adminPage.goto(settingsUrl(), { waitUntil: 'domcontentloaded' });
    nonce = await adminPage.evaluate(() => (window as any).fazConfig?.api?.nonce ?? '');
    expect(nonce.length).toBeGreaterThan(0);
    if (!originalSettings) {
      originalSettings = await getSettings();
    }
  });

  test.afterEach(async () => {
    await restoreOriginalSettings();
  });

  for (const setting of settings) {
    test(`${setting.path} renders in the settings UI and reflects persisted state`, async () => {
      await openSettingsAndWait();
      const current = await getSettings();
      const expected = expectedUiValue(getPath(current, setting.path), setting);
      const locator = adminPage.locator(`[data-path="${setting.path}"]`);

      await expect(locator.first()).toHaveCount(1);
      if (Array.isArray(expected)) {
        await expect.poll(() => readUiValue(setting)).toEqual(expected);
      } else {
        await expect.poll(() => readUiValue(setting)).toBe(expected);
      }
    });

    test(`${setting.path} persists a valid value through the settings API`, async () => {
      const current = await getSettings();
      const validValue = resolveValue(setting.validValue, current);
      const expected = resolveValue(setting.expectedValid ?? setting.validValue, current, validValue);

      const saved = await postSettings(setPath(setting.path, validValue));

      expect(getPath(saved, setting.path)).toEqual(expected);
      expect(getPath(await getSettings(), setting.path)).toEqual(expected);
    });

    test(`${setting.path} normalizes invalid or alternate input safely`, async () => {
      const current = await getSettings();
      const invalidValue = resolveValue(setting.invalidValue, current);
      const expected = resolveValue(setting.expectedInvalid, current, invalidValue);

      const saved = await postSettings(setPath(setting.path, invalidValue));

      expect(getPath(saved, setting.path)).toEqual(expected);
      expect(getPath(await getSettings(), setting.path)).toEqual(expected);
    });
  }
});
