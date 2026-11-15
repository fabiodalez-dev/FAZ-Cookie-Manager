/**
 * A sheet in foreign content whose tracker URL is spelled with character
 * references, next to one written plainly, measured on the network of a real
 * browser during the bootstrap's safety release (the runtime never arrived).
 *
 * The SVG parser decodes references in <style> text, so
 * "@import url(&#47;&#47;tracker…)" is "//tracker…" to the CSS engine. The
 * markup walk judges the decoded reading first and parks the whole sheet when
 * it is refused (security-audit-high-priority.test.mjs covers the same in
 * jsdom). Here the question is the one jsdom cannot answer: does any request
 * reach the tracker host? A page without the bootstrap is the control, so the
 * probe is shown to fetch when nothing stands in front of it.
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const bootstrap = readFileSync(new URL('../../frontend/js/bootstrap.min.js', import.meta.url), 'utf8');

const MIXED = '<svg><style>@import url(https://tracker.example.test/plain.css);@import url(&#47;&#47;tracker.example.test/encoded.css);</style></svg>';

async function trackerRequests(page, withBootstrap: boolean) {
  const requests: string[] = [];
  await page.route('https://tracker.example.test/**', async (route) => {
    requests.push(new URL(route.request().url()).pathname);
    await route.fulfill({ contentType: 'text/css', body: '' });
  });
  await page.route('https://site.example.test/**', async (route) => {
    const config = {
      _block: '1', _activeLaw: 'gdpr',
      _categories: [{ slug: 'necessary', isNecessary: true }, { slug: 'analytics', isNecessary: false }],
      _providersToBlock: [], _services: [], _userWhitelist: [], i18n: {},
    };
    return route.fulfill({
      contentType: 'text/html',
      body: `<!doctype html><html><head>
        <script>window._fazConfig=${JSON.stringify(config)};</script>
        ${withBootstrap ? `<script>${bootstrap}</script>` : ''}
        </head><body><div id="host"></div></body></html>`,
    });
  });
  await page.goto('https://site.example.test/', { waitUntil: 'load' });
  if (withBootstrap) {
    // No runtime script on the page: the bootstrap's safety release is on.
    await expect.poll(() => page.evaluate(() => document.documentElement.className)).toContain('faz-consent-runtime-missing');
  }
  await page.evaluate((html) => { document.getElementById('host')!.innerHTML = html; }, MIXED);
  // An @import starts as the sheet is inserted; give the engine time to send it.
  await page.waitForTimeout(500);
  const live = await page.evaluate(() => document.querySelector('#host style')?.textContent || '');
  return { requests, live };
}

test('control: without the bootstrap the reference-spelled @import reaches the tracker', async ({ page }) => {
  const { requests } = await trackerRequests(page, false);
  expect(requests).toContain('/encoded.css');
});

test('on the safety release neither @import of a mixed foreign sheet reaches the tracker', async ({ page }) => {
  const { requests, live } = await trackerRequests(page, true);
  expect(requests).toEqual([]);
  expect(live).not.toContain('tracker.example.test');
});
