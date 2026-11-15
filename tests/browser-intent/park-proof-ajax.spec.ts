/**
 * Markup a page fetches after load (infinite scroll, "load more") was parked
 * by its own response, under its own park key. Before, the runtime of the
 * page refused that key and the parked style stayed inert after consent
 * until a reload. Now a signed park is sent to POST /faz/v1/park-proofs and,
 * once the route confirms it, restored: measured here in a real browser, with
 * the minified runtime and the browser's own fetch, by whether the tracker
 * image behind the style is requested.
 *
 * The route is a stub that confirms one known signature. An unsigned
 * (author-written) proof on the same page is the control: it is never sent
 * and never restored.
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const runtime = readFileSync(new URL('../../frontend/js/script.min.js', import.meta.url), 'utf8');
const GOOD = 'a'.repeat(64);

test('a signed park from a later response is restored on consent; an unsigned one is not', async ({ page, context }) => {
  const requests: string[] = [];
  const asked: unknown[] = [];
  await context.addCookies([{ name: 'fazcookie-consent', value: 'consent:yes,action:yes,necessary:yes,marketing:yes', domain: 'site.example.test', path: '/' }]);

  await page.route('https://tracker.example.test/**', async (route) => {
    requests.push(new URL(route.request().url()).pathname);
    await route.fulfill({ contentType: 'image/gif', body: '' });
  });
  await page.route('https://site.example.test/**', async (route) => {
    const req = route.request();
    const path = new URL(req.url()).pathname;
    if (path === '/wp-json/faz/v1/park-proofs') {
      const items = (req.postDataJSON() || {}).items || [];
      asked.push(...items);
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ valid: items.map((it: { sig: string }) => it.sig === GOOD) }) });
    }
    const config = {
      _block: '1', _srcdocKey: 'page-key', _parkProofEndpoint: 'https://site.example.test/wp-json/faz/v1/park-proofs', _activeLaw: 'gdpr',
      _categories: [{ slug: 'necessary', isNecessary: true }, { slug: 'marketing', isNecessary: false }],
      _services: [], _providersToBlock: [{ re: 'tracker.example.test', categories: ['marketing'], service: 'tracker' }],
      _userWhitelist: [], _perServiceConsent: false, _perCookieConsent: false, i18n: {},
    };
    return route.fulfill({
      contentType: 'text/html',
      body: `<!doctype html><html><head>
        <script>window._fazConfig=${JSON.stringify(config)};</script>
        <script>${runtime}</script>
        </head><body><div id="feed"></div></body></html>`,
    });
  });

  await page.goto('https://site.example.test/', { waitUntil: 'load' });
  // What a "load more" handler inserts: two articles the next page's response
  // parked under ITS key, one signed by the server, one written by an author.
  await page.evaluate((sig) => {
    const park = (id: string, file: string, s: string) =>
      `<div id="${id}" style="width:40px;height:40px" data-faz-category="marketing" data-faz-park="style" data-faz-park-key="next-page-key"${s}`
      + ` data-faz-style="width:40px;height:40px;background-image:url(https://tracker.example.test/${file})"></div>`;
    document.getElementById('feed')!.insertAdjacentHTML('beforeend', park('signed', 'signed.png', ` data-faz-park-sig="${sig}"`) + park('author', 'author.png', ''));
  }, GOOD);
  expect(requests).toEqual([]);

  // The restore pass that runs on consent.
  await page.evaluate(() => (window as unknown as { _fazUnblockServerSide: () => void })._fazUnblockServerSide());

  await expect.poll(() => requests).toContain('/signed.png');
  expect(asked).toHaveLength(1);
  expect(await page.evaluate(() => document.getElementById('signed')!.getAttribute('data-faz-park-key'))).toBe('page-key');
  // The author-written proof: never sent, never restored, no request.
  await page.waitForTimeout(300);
  expect(requests).not.toContain('/author.png');
  expect(await page.evaluate(() => document.getElementById('author')!.getAttribute('data-faz-style'))).toContain('author.png');
});
