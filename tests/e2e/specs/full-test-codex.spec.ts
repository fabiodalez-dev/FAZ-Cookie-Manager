import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import type { Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { getWpLoginPath } from '../utils/wp-auth';
import { clickFirstVisible } from '../utils/ui';

type ConsentCategory = 'analytics' | 'marketing' | 'functional';

type TargetConfig = {
  label: string;
  installSlugs: string[];
  probePattern: string;
  expectedCategory: ConsentCategory;
};

type Target = TargetConfig & { probeId: string };

type CommandResult = {
  ok: boolean;
  stdout: string;
  stderr: string;
  command: string;
};

type InstallResult = {
  label: string;
  resolvedSlug: string | null;
  status: 'active' | 'failed';
  details: string[];
};

// WP_PATH resolution: explicit env var only — we don't fall back to a
// developer-machine path. The constant may be the empty string at module
// load (so other specs sharing this file's process aren't crashed), but
// any wp-cli call inside the spec validates it and throws a clear error
// before issuing the call.
const WP_PATH = process.env.WP_PATH ?? '';

function requireWpPath(): string {
  if (!WP_PATH) {
    throw new Error(
      'WP_PATH env var is required for full-test-codex.spec.ts. ' +
      'Re-run with: WP_PATH=/path/to/wordpress npm run test:e2e'
    );
  }
  return WP_PATH;
}
const WP_LOGIN_PATH = getWpLoginPath();
const FULL_TEST_CODEX_REPORT = process.env.FULL_TEST_CODEX_REPORT
  ?? resolve(process.cwd(), 'tests/e2e/reports/full-test-codex-report.json');
const MIN_ACTIVE_TARGET_PLUGINS = 20;

const TARGET_CONFIGS: TargetConfig[] = [
  { label: 'Site Kit by Google', installSlugs: ['google-site-kit'], probePattern: 'google-site-kit', expectedCategory: 'analytics' },
  { label: 'MonsterInsights', installSlugs: ['google-analytics-for-wordpress'], probePattern: 'monsterinsights', expectedCategory: 'analytics' },
  { label: 'ExactMetrics', installSlugs: ['exactmetrics-google-analytics-dashboard-for-wp'], probePattern: 'exactmetrics', expectedCategory: 'analytics' },
  { label: 'Analytify', installSlugs: ['analytify-google-analytics-dashboard'], probePattern: 'analytify', expectedCategory: 'analytics' },
  { label: 'Analytics Insights', installSlugs: ['analytics-insights'], probePattern: 'analytics-insights', expectedCategory: 'analytics' },
  { label: 'GA Google Analytics', installSlugs: ['ga-google-analytics'], probePattern: 'ga-google-analytics', expectedCategory: 'analytics' },
  { label: 'HT Easy GA4', installSlugs: ['ht-easy-ga4'], probePattern: 'ht-easy-ga4', expectedCategory: 'analytics' },
  { label: 'Beehive Analytics', installSlugs: ['beehive-analytics'], probePattern: 'beehive-analytics', expectedCategory: 'analytics' },
  { label: 'WP Statistics', installSlugs: ['wp-statistics'], probePattern: 'wp_statistics_', expectedCategory: 'analytics' },
  { label: 'Independent Analytics', installSlugs: ['independent-analytics'], probePattern: 'independent-analytics', expectedCategory: 'analytics' },
  { label: 'Burst Statistics', installSlugs: ['burst-statistics'], probePattern: 'burst-statistics', expectedCategory: 'analytics' },
  { label: 'SlimStat Analytics', installSlugs: ['wp-slimstat'], probePattern: 'wp-slimstat', expectedCategory: 'analytics' },
  { label: 'WPAC Integration for Google Analytics', installSlugs: ['wpac-integration-for-google-analytics'], probePattern: 'wpac-integration-for-google-analytics', expectedCategory: 'analytics' },
  { label: 'PixelYourSite', installSlugs: ['pixelyoursite'], probePattern: 'pixelyoursite', expectedCategory: 'marketing' },
  { label: 'Meta pixel for WordPress', installSlugs: ['meta-pixel-for-wordpress', 'official-facebook-pixel'], probePattern: 'meta-pixel-for-wordpress', expectedCategory: 'marketing' },
  { label: 'Meta for WooCommerce', installSlugs: ['facebook-for-woocommerce'], probePattern: 'meta-for-woocommerce', expectedCategory: 'marketing' },
  { label: 'Pixel Cat – Conversion Pixel Manager', installSlugs: ['facebook-conversion-pixel', 'pixel-cat'], probePattern: 'fatcatapps-pixel', expectedCategory: 'marketing' },
  { label: 'Meta Pixel Event Tracker for WooCommerce', installSlugs: ['meta-pixel-event-tracker-for-woocommerce', 'woocommerce-facebook-pixel'], probePattern: 'meta-pixel-event-tracker-for-woocommerce', expectedCategory: 'marketing' },
  { label: 'Kliken: Ads + Pixel for Meta', installSlugs: ['kliken-ads-pixel-for-meta', 'kliken'], probePattern: 'kliken', expectedCategory: 'marketing' },
  { label: 'TikTok', installSlugs: ['tiktok'], probePattern: 'tiktok-events', expectedCategory: 'marketing' },
  { label: 'Add Tiktok Pixel for Tiktok Ads (+WooCommerce)', installSlugs: ['add-tiktok-pixel-for-tiktok-ads'], probePattern: 'add-tiktok-pixel-for-tiktok-ads', expectedCategory: 'marketing' },
  { label: 'Pinterest for WooCommerce', installSlugs: ['pinterest-for-woocommerce'], probePattern: 'pinterest-for-woocommerce', expectedCategory: 'marketing' },
  { label: 'Add Pinterest Conversion Tags for Pinterest Ads + Site Verification', installSlugs: ['add-pinterest-conversion-tags', 'add-pinterest-tags'], probePattern: 'add-pinterest-conversion-tags', expectedCategory: 'marketing' },
  { label: 'GTM4WP', installSlugs: ['duracelltomi-google-tag-manager'], probePattern: 'gtm4wp', expectedCategory: 'analytics' },
  { label: 'GTM Kit', installSlugs: ['gtm-kit'], probePattern: 'gtmkit', expectedCategory: 'analytics' },
  { label: 'Google Tag Manager Integration for WooCommerce', installSlugs: ['google-tag-manager-integration-for-woocommerce'], probePattern: 'google-tag-manager-integration-for-woocommerce', expectedCategory: 'analytics' },
  { label: 'Server Side Tracking via GTM for Google Analytics 4, Meta, Google Ads', installSlugs: ['server-side-tracking-via-gtm'], probePattern: 'server-side-tracking-via-gtm', expectedCategory: 'analytics' },
  { label: 'Conversion Pixel and Tracking Tag Manager', installSlugs: ['conversion-pixel-and-tracking-tag-manager'], probePattern: 'conversion-pixel-and-tracking-tag-manager', expectedCategory: 'analytics' },
  { label: 'Tracking and Consent Manager – WP Full Picture', installSlugs: ['full-picture-analytics-cookie-notice', 'wp-full-picture'], probePattern: 'wp-full-picture', expectedCategory: 'analytics' },
  { label: 'Pixel Manager for WooCommerce', installSlugs: ['pixel-manager-for-woocommerce', 'woocommerce-google-adwords-conversion-tracking-tag'], probePattern: 'pixel-manager-for-woocommerce', expectedCategory: 'marketing' },
  { label: 'Pixel Tag Manager for WooCommerce', installSlugs: ['pixel-tag-manager-for-woocommerce'], probePattern: 'pixel-tag-manager-for-woocommerce', expectedCategory: 'marketing' },
  { label: 'WooCommerce Google Ads Conversion Tracking Tag / Pixel Manager', installSlugs: ['woocommerce-google-adwords-conversion-tracking-tag'], probePattern: 'woocommerce-google-adwords-conversion-tracking-tag', expectedCategory: 'marketing' },
  { label: 'Tag Manager – Header, Body And Footer', installSlugs: ['tag-manager-header-body-and-footer'], probePattern: 'tag-manager-header-body-and-footer', expectedCategory: 'functional' },
  { label: 'WPCode', installSlugs: ['insert-headers-and-footers', 'wpcode-lite', 'wpcode'], probePattern: 'wpcode', expectedCategory: 'functional' },
  { label: 'Header Footer Code Manager', installSlugs: ['header-footer-code-manager'], probePattern: 'header-footer-code-manager', expectedCategory: 'functional' },
  { label: 'Insert Headers and Footers', installSlugs: ['insert-headers-and-footers'], probePattern: 'insert-headers-and-footers', expectedCategory: 'functional' },
  { label: 'Head, Footer and Post Injections', installSlugs: ['head-footer-code'], probePattern: 'head-footer-code', expectedCategory: 'functional' },
  { label: 'Insert Headers and Footers Code – HT Script', installSlugs: ['ht-script'], probePattern: 'ht-script', expectedCategory: 'functional' },
  { label: 'Simple Custom CSS and JS', installSlugs: ['custom-css-js'], probePattern: 'custom-css-js', expectedCategory: 'functional' },
  { label: 'Code Snippets', installSlugs: ['code-snippets'], probePattern: 'code-snippets', expectedCategory: 'functional' },
  { label: 'Woody Code Snippets', installSlugs: ['insert-php'], probePattern: 'woody', expectedCategory: 'functional' },
  { label: 'Ad Inserter', installSlugs: ['ad-inserter'], probePattern: 'ad-inserter', expectedCategory: 'functional' },
  { label: 'CM Header & Footer Script Loader', installSlugs: ['cm-header-footer-script-loader'], probePattern: 'cm-header-footer-script-loader', expectedCategory: 'functional' },
  { label: 'Easy Google Tag Manager', installSlugs: ['easy-google-tag-manager'], probePattern: 'easy-google-tag-manager', expectedCategory: 'analytics' },
  { label: 'Google Analytics WD', installSlugs: ['google-analytics-wd'], probePattern: 'google-analytics-wd', expectedCategory: 'analytics' },
  { label: 'GAinWP / Google Analytics Dashboard for WP', installSlugs: ['ga-in'], probePattern: 'gainwp', expectedCategory: 'analytics' },
  { label: 'Conversios – Google Analytics 4, Google Ads, Meta Pixel', installSlugs: ['conversios'], probePattern: 'conversios', expectedCategory: 'analytics' },
  { label: 'WooCommerce Conversion Tracking', installSlugs: ['woocommerce-conversion-tracking'], probePattern: 'woocommerce-conversion-tracking', expectedCategory: 'marketing' },
  { label: 'Facebook for WooCommerce legacy-type integrations / forks', installSlugs: ['facebook-for-woocommerce', 'official-facebook-pixel', 'facebook-for-wordpress'], probePattern: 'facebook-for-woocommerce', expectedCategory: 'marketing' },
  { label: 'All-in-one CAPI for Meta & Pinterest + GTM', installSlugs: ['all-in-one-capi-for-meta-pinterest-gtm', 'all-in-one-capi'], probePattern: 'all-in-one-capi-for-meta-pinterest-gtm', expectedCategory: 'marketing' },
];

const TARGETS: Target[] = TARGET_CONFIGS.map((target, index) => ({
  ...target,
  probeId: `full-test-codex-${String(index + 1).padStart(2, '0')}`,
}));

function runWp(args: string[]): CommandResult {
  const fullArgs = [...args, `--path=${requireWpPath()}`, '--skip-plugins', '--skip-themes'];
  try {
    const stdout = execFileSync('wp', fullArgs, {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    return {
      ok: true,
      stdout: stdout ?? '',
      stderr: '',
      command: `wp ${fullArgs.join(' ')}`,
    };
  } catch (error) {
    const err = error as { stdout?: string | Buffer; stderr?: string | Buffer };
    return {
      ok: false,
      stdout: String(err.stdout ?? ''),
      stderr: String(err.stderr ?? ''),
      command: `wp ${fullArgs.join(' ')}`,
    };
  }
}

function ensureActive(label: string, slugs: string[], cache: Map<string, boolean>): InstallResult {
  const details: string[] = [];

  for (const slug of slugs) {
    if (cache.get(slug) === true) {
      details.push(`${slug}: already marked active by previous target`);
      return { label, resolvedSlug: slug, status: 'active', details };
    }

    const active = runWp(['plugin', 'is-active', slug]);
    if (active.ok) {
      cache.set(slug, true);
      details.push(`${slug}: already active`);
      return { label, resolvedSlug: slug, status: 'active', details };
    }

    const installed = runWp(['plugin', 'is-installed', slug]);
    if (installed.ok) {
      const activate = runWp(['plugin', 'activate', slug]);
      details.push(`${slug}: installed -> activate => ${activate.ok ? 'ok' : 'failed'}`);
      if (activate.ok) {
        cache.set(slug, true);
        return { label, resolvedSlug: slug, status: 'active', details };
      }
      details.push(activate.stderr || activate.stdout || 'activation failed');
      continue;
    }

    const install = runWp(['plugin', 'install', slug, '--activate']);
    details.push(`${slug}: install+activate => ${install.ok ? 'ok' : 'failed'}`);
    if (install.ok) {
      cache.set(slug, true);
      return { label, resolvedSlug: slug, status: 'active', details };
    }
    details.push(install.stderr || install.stdout || 'install failed');
  }

  return { label, resolvedSlug: null, status: 'failed', details };
}

function buildProbeScript(target: Target): string {
  const js = [
    'window.__fullTestCodexHits=window.__fullTestCodexHits||{};',
    `window.__fullTestCodexHits["${target.probeId}"]=(window.__fullTestCodexHits["${target.probeId}"]||0)+1;`,
    `window.__fullTestCodexProbeMeta=window.__fullTestCodexProbeMeta||{};window.__fullTestCodexProbeMeta["${target.probeId}"]="${target.probePattern}";`,
    `//${target.probePattern}`,
  ].join('');
  return `<script data-full-test-codex="${target.probeId}">${js}</script>`;
}

async function setTextarea(page: Page, selector: string, value: string): Promise<void> {
  const locator = page.locator(selector);
  await expect(locator, `Textarea not found: ${selector}`).toHaveCount(1);

  await page.evaluate(
    ({ cssSelector, nextValue }) => {
      const el = document.querySelector<HTMLTextAreaElement>(cssSelector);
      if (!el) {
        throw new Error(`Missing textarea: ${cssSelector}`);
      }

      const asAny = el as unknown as { nextElementSibling?: { CodeMirror?: { setValue: (value: string) => void; save: () => void } } };
      const codeMirror = asAny.nextElementSibling?.CodeMirror;
      if (codeMirror) {
        codeMirror.setValue(nextValue);
        codeMirror.save();
      }

      el.value = nextValue;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    },
    { cssSelector: selector, nextValue: value },
  );
}

async function gotoResilient(page: Page, url: string): Promise<void> {
  let lastError: unknown;
  for (let attempt = 0; attempt < 2; attempt += 1) {
    try {
      await page.goto(url, { waitUntil: 'commit', timeout: 120_000 });
      await page.waitForLoadState('domcontentloaded', { timeout: 120_000 }).catch(() => {
        // Some plugin combinations keep requests open for a long time.
      });
      return;
    } catch (error) {
      lastError = error;
    }
  }
  throw lastError;
}

async function loginAdminResilient(page: Page, wpBaseURL: string, adminUser: string, adminPass: string): Promise<void> {
  await gotoResilient(page, `${wpBaseURL}${WP_LOGIN_PATH}`);
  await page.locator('#user_login').fill(adminUser);
  await page.locator('#user_pass').fill(adminPass);
  await page.locator('#wp-submit').click();
  await page.waitForLoadState('domcontentloaded', { timeout: 120_000 }).catch(() => {
    // Some plugin combinations keep the request open after auth succeeds.
  });

  if (page.url().includes('/wp-admin/')) {
    await expect(page.locator('#wpadminbar')).toBeVisible();
    return;
  }

  const cookies = await page.context().cookies(wpBaseURL);
  const hasLoggedCookie = cookies.some((cookie) => cookie.name.startsWith('wordpress_logged_in_'));
  if (hasLoggedCookie) {
    await gotoResilient(page, `${wpBaseURL}/wp-admin/`);
    await expect(page).toHaveURL(/\/wp-admin\//);
    await expect(page.locator('#wpadminbar')).toBeVisible();
    return;
  }

  const loginError = await page.locator('#login_error').textContent().catch(() => '');
  throw new Error(`Admin login failed after plugin activation. URL=${page.url()} error=${loginError ?? 'n/a'}`);
}

test.describe('full-test-codex', () => {
  test.describe.configure({ mode: 'serial' });

  test('installa plugin target, configura header/footer e valida blocco completo', async ({ page, browser, adminUser, adminPass, wpBaseURL }) => {
    test.setTimeout(45 * 60_000);

    const slugActivationCache = new Map<string, boolean>();
    const installResults: InstallResult[] = [];
    for (const target of TARGETS) {
      installResults.push(ensureActive(target.label, target.installSlugs, slugActivationCache));
    }

    // Ensure the injector plugin used for header/footer configuration is active.
    const injectorStatus = ensureActive('Head & Footer Code (injector used by full-test-codex)', ['head-footer-code'], slugActivationCache);
    installResults.push(injectorStatus);
    expect(injectorStatus.status).toBe('active');

    const activeTargetPlugins = installResults.filter((row) => row.status === 'active' && !row.label.startsWith('Head & Footer Code')).length;
    expect(
      activeTargetPlugins,
      `Expected at least ${MIN_ACTIVE_TARGET_PLUGINS} real third-party plugins to be active for compatibility coverage.\n${JSON.stringify(installResults, null, 2)}`,
    ).toBeGreaterThanOrEqual(MIN_ACTIVE_TARGET_PLUGINS);

    await loginAdminResilient(page, wpBaseURL, adminUser, adminPass);
    await gotoResilient(page, '/wp-admin/tools.php?page=head-footer-code');
    await expect(page).toHaveURL(/tools\.php\?page=head-footer-code/);

    const selectors = {
      head: '#auhfc_settings_sitewide_head',
      body: '#auhfc_settings_sitewide_body',
      footer: '#auhfc_settings_sitewide_footer',
    };

    const originalValues = await page.evaluate((sel) => {
      const head = document.querySelector<HTMLTextAreaElement>(sel.head)?.value ?? '';
      const body = document.querySelector<HTMLTextAreaElement>(sel.body)?.value ?? '';
      const footer = document.querySelector<HTMLTextAreaElement>(sel.footer)?.value ?? '';
      return { head, body, footer };
    }, selectors);

    const headScripts: string[] = [];
    const bodyScripts: string[] = [];
    const footerScripts: string[] = [];
    TARGETS.forEach((target, idx) => {
      const script = buildProbeScript(target);
      if (idx % 3 === 0) {
        headScripts.push(script);
      } else if (idx % 3 === 1) {
        bodyScripts.push(script);
      } else {
        footerScripts.push(script);
      }
    });

    const headPayload = `<!-- full-test-codex:start -->\n${headScripts.join('\n')}\n<!-- full-test-codex:end -->`;
    const bodyPayload = `<!-- full-test-codex:start -->\n${bodyScripts.join('\n')}\n<!-- full-test-codex:end -->`;
    const footerPayload = `<!-- full-test-codex:start -->\n${footerScripts.join('\n')}\n<!-- full-test-codex:end -->`;

    let report: Record<string, unknown> = {
      generatedAt: new Date().toISOString(),
      wpBaseURL,
      wpPath: WP_PATH,
      targetCount: TARGETS.length,
      installs: installResults,
      installSummary: {
        active: installResults.filter((row) => row.status === 'active').length,
        failed: installResults.filter((row) => row.status === 'failed').length,
        minActiveTargets: MIN_ACTIVE_TARGET_PLUGINS,
      },
      preConsentFailures: [],
      postConsentFailures: [],
      status: 'running',
    };
    try {
      await setTextarea(page, selectors.head, headPayload);
      await setTextarea(page, selectors.body, bodyPayload);
      await setTextarea(page, selectors.footer, footerPayload);

      const saveButton = page.locator('button[type="submit"], input[type="submit"]');
      await saveButton.first().click();
			await expect(page.locator('.notice-success, #message.updated').first()).toBeVisible({ timeout: 20_000 });

      await gotoResilient(page, '/wp-admin/tools.php?page=head-footer-code');
      const persisted = await page.evaluate((sel) => {
        const head = document.querySelector<HTMLTextAreaElement>(sel.head)?.value ?? '';
        const body = document.querySelector<HTMLTextAreaElement>(sel.body)?.value ?? '';
        const footer = document.querySelector<HTMLTextAreaElement>(sel.footer)?.value ?? '';
        return head.includes('full-test-codex:start')
          && body.includes('full-test-codex:start')
          && footer.includes('full-test-codex:start');
      }, selectors);
      expect(persisted, 'full-test-codex payload was not persisted in Head & Footer Code settings').toBeTruthy();

        const visitorContext = await browser.newContext({ baseURL: wpBaseURL });
        try {
          const visitorPage = await visitorContext.newPage();
          await visitorPage.goto('/', { waitUntil: 'commit', timeout: 120_000 });
          await visitorPage.waitForLoadState('domcontentloaded', { timeout: 120_000 }).catch(() => {
            // Some third-party plugins keep the document loading indefinitely.
            // We only need the DOM to be reachable for banner/script assertions.
          });
          const noticeVisible = await visitorPage.locator('[data-faz-tag="notice"]').isVisible().catch(() => false);
          if (!noticeVisible) {
            const reopened = await clickFirstVisible(visitorPage, [
              '[data-faz-tag="revisit-consent"]',
              '.faz-btn-revisit-wrapper',
              '.faz-btn-revisit',
            ]);
            if (reopened) {
              await expect(visitorPage.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: 20_000 }).catch(() => {});
            }
          }

        const preConsentState = await visitorPage.evaluate((targets) => {
          const hits = (window as typeof window & { __fullTestCodexHits?: Record<string, number> }).__fullTestCodexHits ?? {};
          return targets.map((target) => {
            const node = Array.from(document.querySelectorAll<HTMLScriptElement>('script'))
              .find((script) => {
                const body = script.textContent ?? '';
                return body.includes(target.probeId);
              });
            return {
              label: target.label,
              probeId: target.probeId,
              probePattern: target.probePattern,
              expectedCategory: target.expectedCategory,
              exists: Boolean(node),
              type: node?.getAttribute('type') ?? '',
              category: node?.getAttribute('data-faz-category') ?? '',
              src: node?.getAttribute('src') ?? '',
              hits: hits[target.probeId] ?? 0,
            };
          });
        }, TARGETS.map((target) => ({
          label: target.label,
          probeId: target.probeId,
          probePattern: target.probePattern,
          expectedCategory: target.expectedCategory,
        })));

        const preConsentFailures = preConsentState.filter((row) => {
          if (!row.exists) {
            return true;
          }
          if (row.type !== 'text/plain') {
            return true;
          }
          if (row.category !== row.expectedCategory) {
            return true;
          }
          return row.hits !== 0;
        });

        let accepted = await clickFirstVisible(visitorPage, [
          '[data-faz-tag="accept-button"] button',
          '[data-faz-tag="accept-button"]',
          '.faz-btn-accept',
        ]);
        if (!accepted) {
          await clickFirstVisible(visitorPage, [
            '[data-faz-tag="revisit-consent"]',
            '.faz-btn-revisit-wrapper',
            '.faz-btn-revisit',
          ]);
          accepted = await clickFirstVisible(visitorPage, [
            '[data-faz-tag="accept-button"] button',
            '[data-faz-tag="accept-button"]',
            '.faz-btn-accept',
          ]);
        }
        if (!accepted) {
          await visitorPage.evaluate(() => {
            const store = (window as typeof window & {
              _fazConfig?: { _categories?: Array<{ slug?: string }> };
            })._fazConfig;
            const categoryPairs = (store?._categories ?? [])
              .map((category) => category?.slug)
              .filter((slug): slug is string => Boolean(slug))
              .map((slug) => `${slug}:yes`);
            const consentValue = ['consent:yes', 'action:all', ...categoryPairs].join(',');
            document.cookie = `fazcookie-consent=${encodeURIComponent(consentValue)}; path=/; max-age=${180 * 24 * 60 * 60}`;
          });
        }

        await expect.poll(async () => {
          const cookies = await visitorContext.cookies(wpBaseURL);
          const consent = cookies.find((cookie) => cookie.name === 'fazcookie-consent');
          const raw = consent?.value ? decodeURIComponent(consent.value) : '';
          return raw.includes('consent:yes');
        }, { timeout: 30_000 }).toBeTruthy();

        await visitorPage.reload({ waitUntil: 'commit', timeout: 120_000 });
        await visitorPage.waitForLoadState('domcontentloaded', { timeout: 120_000 }).catch(() => {
          // Third-party plugins can keep pending requests open; not fatal for assertions.
        });

        await expect.poll(async () => {
          return visitorPage.evaluate((targets) => {
            const hits = (window as typeof window & { __fullTestCodexHits?: Record<string, number> }).__fullTestCodexHits ?? {};
            return targets.filter((target) => (hits[target.probeId] ?? 0) <= 0).map((target) => target.probeId);
          }, TARGETS.map((target) => ({
            probeId: target.probeId,
          })));
        }, { timeout: 60_000 }).toEqual([]);

        const postConsentState = await visitorPage.evaluate((targets) => {
          const hits = (window as typeof window & { __fullTestCodexHits?: Record<string, number> }).__fullTestCodexHits ?? {};
          return targets.map((target) => ({
            label: target.label,
            probeId: target.probeId,
            hits: hits[target.probeId] ?? 0,
          }));
        }, TARGETS.map((target) => ({
          label: target.label,
          probeId: target.probeId,
        })));

        const postConsentFailures = postConsentState.filter((row) => row.hits <= 0);

        report = {
          ...report,
          generatedAt: new Date().toISOString(),
          preConsentFailures,
          postConsentFailures,
          status: 'passed',
        };

        expect(preConsentFailures, `Blocking failures before consent:\n${JSON.stringify(preConsentFailures, null, 2)}`).toEqual([]);
        expect(postConsentFailures, `Unblock failures after consent:\n${JSON.stringify(postConsentFailures, null, 2)}`).toEqual([]);
      } finally {
        await visitorContext.close();
      }
    } catch (error) {
      report = {
        ...report,
        generatedAt: new Date().toISOString(),
        status: 'failed',
        error: error instanceof Error
          ? {
            name: error.name,
            message: error.message,
            stack: error.stack ?? '',
          }
          : String(error),
      };
      throw error;
    } finally {
      await gotoResilient(page, '/wp-admin/tools.php?page=head-footer-code');
      await setTextarea(page, selectors.head, originalValues.head);
      await setTextarea(page, selectors.body, originalValues.body);
      await setTextarea(page, selectors.footer, originalValues.footer);
      const saveButton = page.locator('button[type="submit"], input[type="submit"]');
      await saveButton.first().click();

      mkdirSync(dirname(FULL_TEST_CODEX_REPORT), { recursive: true });
      writeFileSync(FULL_TEST_CODEX_REPORT, `${JSON.stringify(report, null, 2)}\n`, 'utf8');
      test.info().attach('full-test-codex-report', {
        body: JSON.stringify(report, null, 2),
        contentType: 'application/json',
      });
    }
  });
});
