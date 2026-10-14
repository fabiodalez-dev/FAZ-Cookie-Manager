/**
 * A Range edit on an inline <style> is judged BEFORE it lands.
 *
 * Blink reparses an inline sheet synchronously on the text mutation, and an
 * `@import` request leaves during that parse. A gate that let the native
 * edit run and neutralised the text afterwards therefore emitted the request
 * first: `@import url(https://tracker.example.te.st/range.css)` plus a
 * deleteContents() removing the dot completed the tracker host and fetched it
 * with consent refused. jsdom cannot see that order; this fixture can.
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const bootstrap = readFileSync(new URL('../../frontend/js/bootstrap.min.js', import.meta.url), 'utf8');
const runtime = readFileSync(new URL('../../frontend/js/script.min.js', import.meta.url), 'utf8');

for (const method of ['deleteContents', 'extractContents'] as const) {
  test(`a Range ${method} that completes a tracker host in an @import does not fetch it`, async ({ page, browserName }) => {
    test.skip(browserName !== 'chromium', 'the synchronous reparse under test is Blink\'s; chromium only');
    const tracker: string[] = [];
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.route('https://tracker.example.test/**', async (route) => {
      tracker.push(new URL(route.request().url()).pathname);
      await route.fulfill({ contentType: 'text/css', body: '' });
    });
    // The near-miss host the sheet names before the edit; harmless, answered.
    await page.route('https://tracker.example.te.st/**', (route) => route.fulfill({ contentType: 'text/css', body: '' }));

    await page.route('https://defer.example.test/**', async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path === '/config.js') {
        return route.fulfill({
          contentType: 'text/javascript',
          body: 'window._fazStaticConfig={_providersToBlock:[{re:"tracker.example.test",categories:["analytics"]}],_cookieCategoryMap:{},_serviceCatalogue:{}};',
        });
      }
      if (path === '/runtime.js') return route.fulfill({ contentType: 'text/javascript', body: runtime });
      const config = {
        _block: '1', _staticConfigRequired: true, _aggressiveCssUrlBlocking: true, _activeLaw: 'gdpr',
        _categories: [{ slug: 'necessary', isNecessary: true }, { slug: 'analytics', isNecessary: false }],
        _services: [], _userWhitelist: [], _perServiceConsent: false, _perCookieConsent: false, i18n: {},
      };
      return route.fulfill({
        contentType: 'text/html',
        body: `<!doctype html><html><head>
        <script>window._fazConfig=${JSON.stringify(config)};</script>
        <script defer src="/config.js"></script>
        <script>${bootstrap}</script>
        <script defer src="/runtime.js"></script>
        <style id="s">@import url(https://tracker.example.te.st/range.css);</style>
        </head><body><p id="paint">rendered</p></body></html>`,
      });
    });

    await page.goto('https://defer.example.test/', { waitUntil: 'commit' });
    await expect(page.locator('#paint')).toBeVisible();
    await expect.poll(() => page.evaluate(() => typeof (window as any)._fazAcceptCookies === 'function'), { timeout: 15_000 }).toBe(true);

    const result = await page.evaluate((m) => {
      const s = document.getElementById('s')!;
      const text = s.firstChild as Text;
      const at = text.data.indexOf('te.st') + 2;
      const range = document.createRange();
      range.setStart(text, at);
      range.setEnd(text, at + 1);
      const out = (range as any)[m]();
      return { text: s.textContent, parked: s.getAttribute('data-faz-css') !== null, returned: out ? String(out.textContent) : 'undefined' };
    }, method);
    expect(result.text, 'the live text names no tracker after the edit').not.toContain('tracker.example.test');
    expect(result.parked, 'the sheet is parked').toBe(true);
    expect(result.returned, 'the method returns what it should').toBe(method === 'extractContents' ? '.' : 'undefined');
    await page.waitForTimeout(500);
    expect(tracker, 'no request reached the tracker origin').toEqual([]);
    expect(errors).toEqual([]);
  });
}
