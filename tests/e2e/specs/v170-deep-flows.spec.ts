import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import type { Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';
const DEPLOY_PATH = process.env.FAZ_PLUGIN_DEPLOY_PATH ?? '';
// WP_PATH resolution order:
//   1. WP_PATH env var (explicit, preferred)
//   2. Inferred from FAZ_PLUGIN_DEPLOY_PATH (the deploy target's grandparent
//      is the WordPress root: <wp-root>/wp-content/plugins/faz-cookie-manager/)
//   3. Empty — the WP-CLI fallback at canRunWpCli() will then return false
//      and every test guarded by `test.skip(!canRunWpCli(), …)` skips with
//      a message, instead of silently running against a hardcoded developer
//      path on CI.
const WP_PATH = process.env.WP_PATH
  ?? (DEPLOY_PATH ? dirname(dirname(dirname(DEPLOY_PATH))) : '');

let skipPluginsArgCache: string | null = null;

async function getAdminNonce(page: Page): Promise<string> {
  return page.evaluate(() => (window as any).fazConfig?.api?.nonce ?? '');
}

async function getSettings(page: Page, nonce: string) {
  const response = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/settings`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(response.status()).toBe(200);
  return response.json();
}

async function exportSettings(page: Page, nonce: string) {
  const response = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/settings/export`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(response.status()).toBe(200);
  return response.json();
}

async function importSettings(page: Page, nonce: string, data: Record<string, unknown>) {
  const response = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/settings/import`, {
    headers: {
      'X-WP-Nonce': nonce,
      'Content-Type': 'application/json',
    },
    data,
  });
  expect(response.status(), `Import failed with status ${response.status()}`).toBe(200);
  return response.json();
}

async function getCategories(page: Page, nonce: string) {
  const response = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/cookies/categories/`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(response.status()).toBe(200);
  return response.json();
}

async function getCookies(page: Page, nonce: string) {
  const response = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/cookies/`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(response.status()).toBe(200);
  return response.json();
}

function canRunWpCli(): boolean {
  try {
    execFileSync('wp', ['--info'], { stdio: 'ignore' });
    return existsSync(join(WP_PATH, 'wp-config.php'));
  } catch {
    return false;
  }
}

function getSkipPluginsArg(): string {
  if (null !== skipPluginsArgCache) {
    return skipPluginsArgCache;
  }

  const raw = execFileSync(
    'wp',
    ['option', 'get', 'active_plugins', '--format=json', `--path=${WP_PATH}`, '--skip-plugins', '--skip-themes'],
    { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
  );

  const parsed = JSON.parse(raw) as string[] | Record<string, string>;
  const plugins = Array.isArray(parsed) ? parsed : Object.values(parsed);
  const skipSlugs = [...new Set(
    plugins
      .map((entry) => String(entry).split('/')[0])
      .filter((slug) => slug && slug !== 'faz-cookie-manager'),
  )];

  skipPluginsArgCache = skipSlugs.join(',');
  return skipPluginsArgCache;
}

function runFazCli(args: string[]): string {
  const fullArgs = [...args, `--path=${WP_PATH}`];
  const skipPluginsArg = getSkipPluginsArg();

  if (skipPluginsArg) {
    fullArgs.push(`--skip-plugins=${skipPluginsArg}`);
  }

  fullArgs.push('--skip-themes');

  return execFileSync('wp', fullArgs, {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

test.describe.serial('v1.7.0 deep flows', () => {
  test('renders the cookie policy shortcode on a real published page', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const slug = `faz-cookie-policy-e2e-${Date.now()}`;
    const createResponse = await page.request.post(`${WP_BASE}/?rest_route=/wp/v2/pages`, {
      headers: {
        'X-WP-Nonce': nonce,
        'Content-Type': 'application/json',
      },
      data: {
        title: 'FAZ Cookie Policy E2E',
        slug,
        status: 'publish',
        content: '[faz_cookie_policy show_table="no" site_name="QA Cookie Site" contact="qa@example.com"]',
      },
    });

    expect([200, 201]).toContain(createResponse.status());
    const createdPage = await createResponse.json();

    try {
      await page.goto(createdPage.link, { waitUntil: 'domcontentloaded' });

      const policy = page.locator('.faz-cookie-policy');
      await expect(policy).toBeVisible();
      await expect(policy.getByRole('heading', { name: 'What Are Cookies' })).toBeVisible();
      await expect(policy).toContainText('QA Cookie Site');
      await expect(policy.locator('a[href="mailto:qa@example.com"]')).toHaveCount(1);
      await expect(policy.getByRole('button', { name: 'Manage Cookie Preferences' })).toBeVisible();
    } finally {
      await page.request.delete(`${WP_BASE}/?rest_route=/wp/v2/pages/${createdPage.id}&force=true`, {
        headers: { 'X-WP-Nonce': nonce },
      });
    }
  });

  test('clicking a blocker template adds the expected custom blocking rules', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });

    const templates = page.locator('#faz-blocker-templates > button');
    // Anchor on the card *name* element (`.faz-template-card-name`) so we
    // match only the canonical "Google Analytics" template — not other
    // Google-* templates whose auto-generated description happens to
    // contain the substring "google analytics" case-insensitively (e.g.
    // Site Kit by Google → "Blocks Site Kit by Google analytics tracking…").
    // Playwright's `hasText` is a case-insensitive substring match by
    // default; switching to a regex with a `$`-anchored, name-only locator
    // gives the exact match the test originally intended.
    const googleAnalyticsCard = templates.filter({
      has: page.locator('.faz-template-card-name', { hasText: /^Google Analytics$/ }),
    });

    await expect(googleAnalyticsCard).toHaveCount(1);
    await expect(googleAnalyticsCard).toBeVisible();

    const rules = page.locator('#faz-custom-rules-body tr');
    const initialCount = await rules.count();

    await googleAnalyticsCard.click();

    await expect(rules).toHaveCount(initialCount + 3);

    const firstNewRow = rules.nth(initialCount);
    await expect(firstNewRow.locator('[data-rule="pattern"]')).toHaveValue(/google-analytics\.com|googletagmanager\.com/);
    await expect(firstNewRow.locator('[data-rule="category"]')).toHaveValue('analytics');
  });

  test('REST import/export round-trip refreshes category and cookie data after cache prime', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const baseline = await exportSettings(page, nonce);
    const modified = JSON.parse(JSON.stringify(baseline));
    const suffix = Date.now();
    const categoryId = Math.max(0, ...modified.categories.map((cat: any) => Number(cat.category_id) || 0)) + 100;
    const cookieId = Math.max(0, ...modified.cookies.map((cookie: any) => Number(cookie.cookie_id) || 0)) + 100;
    const categorySlug = `qa-import-${suffix}`;
    const cookieSlug = `qa-import-cookie-${suffix}`;
    const cookieName = `qa_import_cookie_${suffix}`;

    modified.categories.push({
      category_id: categoryId,
      name: { en: `QA Import Category ${suffix}` },
      slug: categorySlug,
      description: { en: 'Imported category for cache invalidation coverage.' },
      prior_consent: 0,
      visibility: 1,
      priority: 99,
      sell_personal_data: 0,
      meta: null,
    });

    modified.cookies.push({
      cookie_id: cookieId,
      name: cookieName,
      slug: cookieSlug,
      description: { en: 'Imported cookie for cache invalidation coverage.' },
      duration: JSON.stringify({ en: '1 day' }),
      domain: 'qa-import.example',
      category: categoryId,
      type: '0',
      discovered: 0,
      url_pattern: 'qa-import.example/script.js',
      meta: { source: 'playwright-e2e' },
    });

    // Prime category/cookie caches before importing the modified payload.
    await getCategories(page, nonce);
    await getCookies(page, nonce);

    try {
      await importSettings(page, nonce, modified);

      const categoriesAfter = await getCategories(page, nonce);
      expect(
        categoriesAfter.some((category: any) => category.slug === categorySlug),
      ).toBeTruthy();

      const cookiesAfter = await getCookies(page, nonce);
      const importedCookie = cookiesAfter.find((cookie: any) => cookie.name === cookieName);

      expect(importedCookie).toBeTruthy();

      const description = typeof importedCookie.description === 'string'
        ? importedCookie.description
        : importedCookie.description?.en ?? JSON.stringify(importedCookie.description);

      expect(description).toContain('cache invalidation coverage');
    } finally {
      await importSettings(page, nonce, baseline);
    }
  });

  test('WP-CLI status, export, and import work end-to-end', async ({ page, loginAsAdmin }) => {
    test.skip(!canRunWpCli(), `WP-CLI or WordPress root unavailable at ${WP_PATH}`);

    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const statusRows = JSON.parse(runFazCli(['faz', 'status', '--format=json'])) as Array<{ Key: string; Value: string }>;
    expect(statusRows.some((row) => row.Key === 'Plugin Version')).toBeTruthy();
    expect(statusRows.some((row) => row.Key === 'Banner Enabled')).toBeTruthy();

    // `wp faz export` is scoped to wp_upload_dir() since 1.13.11 (wp.org
    // compliance: "plugins must not write outside wp_upload_dir()"). Use
    // a tmp dir INSIDE the WordPress uploads root so the absolute-path
    // argument validates. Falls back to the legacy os.tmpdir() if
    // WP_PATH is unavailable, which only matters for `faz import`
    // (import has no path restriction).
    const uploadsExportsRoot = join(WP_PATH, 'wp-content', 'uploads', 'faz-cookie-manager', 'exports');
    if (!existsSync(uploadsExportsRoot)) {
      execFileSync('mkdir', ['-p', uploadsExportsRoot], { stdio: 'ignore' });
    }
    const tmpDir = mkdtempSync(join(uploadsExportsRoot, 'cli-e2e-'));
    const baselineFile = join(tmpDir, 'baseline.json');
    const importFile = join(tmpDir, 'modified.json');

    try {
      runFazCli(['faz', 'export', baselineFile]);

      const exported = JSON.parse(readFileSync(baselineFile, 'utf8'));
      exported.settings.banner_control.gtm_datalayer = true;
      exported.settings.consent_forwarding.enabled = true;
      exported.settings.consent_forwarding.target_domains = ['https://cli-e2e.example'];
      writeFileSync(importFile, JSON.stringify(exported, null, 2));

      runFazCli(['faz', 'import', importFile, '--yes']);

      const settings = await getSettings(page, nonce);
      expect(settings.banner_control.gtm_datalayer).toBe(true);
      expect(settings.consent_forwarding.enabled).toBe(true);
      expect(settings.consent_forwarding.target_domains).toContain('https://cli-e2e.example');
    } finally {
      if (existsSync(baselineFile)) {
        runFazCli(['faz', 'import', baselineFile, '--yes']);
      }
      rmSync(tmpDir, { recursive: true, force: true });
    }
  });
});
