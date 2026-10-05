/**
 * The banner must survive the two things page optimisers do to it.
 *
 * Reported on wordpress.org ("Doesn't work with WPSpeed by JExtensions"): with
 * WPSpeed active the banner did not show. Two independent causes:
 *
 *  1. WPSpeed's image optimiser (on by default) rewrites the whole page through
 *     PHP's DOMDocument::loadHTML(). Its HTML4 parser ends a <script> at any
 *     `</` + letter and drops the stray end tags, so the banner template inside
 *     <script type="text/template"> lost every closing tag and rendered 0 px tall.
 *     The server now writes `</` as `<\/` inside the template.
 *
 *  2. WPSpeed combines and defers the scripts. script.js then runs after the
 *     document is parsed, _fazDomReady() called the init synchronously in the
 *     middle of the file, and the init hit `const` declarations that did not
 *     exist yet ("Cannot access '_fazFocusLoopHandlers' before initialization").
 *     The late path now waits for the file to finish evaluating.
 *
 * Neither needs WPSpeed to reproduce: the fixture plugin performs the same
 * DOMDocument round trip, and a route adds `defer` to script.js.
 */
import { expect, test, type Page } from '../fixtures/wp-fixture';
import { ensureFixturePlugin, listActivePluginFiles, restoreActivePluginFiles } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

function captureRuntimeErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error' && !/Failed to load resource/.test(msg.text())) errors.push(msg.text());
  });
  page.on('pageerror', (error) => errors.push(String(error)));
  return errors;
}

async function expectWorkingBanner(page: Page, errors: string[]): Promise<void> {
  const bar = page.locator('#faz-consent .faz-consent-bar');
  await expect(bar).toBeVisible({ timeout: 10_000 });
  expect((await bar.boundingBox())?.height ?? 0).toBeGreaterThan(50);
  // The three notice buttons sit outside the title once the closing tags survive.
  await expect(page.locator('#faz-consent [data-faz-tag="accept-button"]')).toBeVisible();
  await expect(page.locator('#faz-consent [data-faz-tag="reject-button"]')).toBeVisible();
  await expect(page.locator('#faz-consent [data-faz-tag="settings-button"]')).toBeVisible();
  expect(await page.locator('#faz-consent .faz-title button').count()).toBe(0);

  await page.locator('#faz-consent [data-faz-tag="reject-button"]').click();
  await expect(bar).toBeHidden({ timeout: 5_000 });
  const consent = (await page.context().cookies()).find((c) => c.name === 'fazcookie-consent');
  expect(consent, 'Reject All stores a consent cookie').toBeTruthy();

  expect(errors.join('\n')).not.toMatch(/before initialization/);
  expect(errors.join('\n')).not.toMatch(/banner render step failed/);
}

test.describe.serial('Banner under page optimisers (WPSpeed report)', () => {
  test('a DOMDocument (HTML4) rewrite of the page keeps the banner template intact', async ({ page }) => {
    test.setTimeout(90_000);
    const originalActive = listActivePluginFiles();
    ensureFixturePlugin('faz-e2e-html4-rewriter');
    try {
      await page.context().clearCookies();
      const errors = captureRuntimeErrors(page);
      const response = await page.goto(`${WP_BASE}/?faz_html4_rewrite=1&t=${Date.now()}`, { waitUntil: 'load' });
      const html = (await response?.text()) ?? '';
      const template = /<script[^>]*id="?fazBannerTemplate"?[^>]*>([\s\S]*?)<\/script>/i.exec(html)?.[1] ?? '';
      expect(template.length, 'template present after the rewrite').toBeGreaterThan(1000);
      expect(template, 'closing tags survive, escaped').toContain('<\\/div>');
      await expectWorkingBanner(page, errors);
    } finally {
      restoreActivePluginFiles(originalActive);
    }
  });

  test('script.js loaded with defer (after parsing) still renders a working banner', async ({ page }) => {
    test.setTimeout(90_000);
    await page.context().clearCookies();
    let deferred = false;
    await page.route(/127\.0\.0\.1:9998\/\?faz_defer=/, async (route) => {
      const resp = await route.fetch();
      const body = (await resp.text()).replace(
        /<script([^>]*\bid=["']faz-cookie-manager-js["'][^>]*)>/i,
        (_m, attrs: string) => {
          deferred = true;
          return `<script${attrs} defer>`;
        },
      );
      await route.fulfill({ response: resp, body });
    });
    const errors = captureRuntimeErrors(page);
    await page.goto(`${WP_BASE}/?faz_defer=${Date.now()}`, { waitUntil: 'load' });
    expect(deferred, 'the route found the script.js tag').toBe(true);
    await expectWorkingBanner(page, errors);
  });
});
