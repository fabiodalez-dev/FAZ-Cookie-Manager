/**
 * A document without a window parses with the scripting flag off, so its
 * <noscript> content may be markup instead of RAWTEXT, and browsers disagree:
 * Chromium and WebKit read markup, Firefox reads RAWTEXT in a
 * createHTMLDocument document. jsdom always reads RAWTEXT, so only real parsers
 * can show the difference.
 *
 * The runtime's markup gate walks an HTML string with the tokenizer's rules to
 * find the <style> elements it parks. Walking <noscript> as RAWTEXT where the
 * parser reads it as markup made the walk end the element at a "</noscript"
 * inside an attribute value and rewrite the "<style>" text after it: the quotes
 * and ">" it inserted closed the attribute early, and an <img onerror> that was
 * attribute text came out as a live element (mutation XSS). The walk now stops
 * at <noscript> in such a document, so in every browser the gated parse must
 * produce exactly the tree the native parser does.
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const runtime = readFileSync(new URL('../../frontend/js/script.min.js', import.meta.url), 'utf8');

const PAYLOAD = '<noscript><p title="</noscript><style>@import url(//tracker.example.test/x)</style><img src=x onerror=window.__pwned=1>"></p></noscript>';

test('the markup gate stops at noscript in a document without a window', async ({ page }) => {
  await page.route('https://inert.example.test/**', async (route) => {
    const config = {
      _block: '1', _aggressiveCssUrlBlocking: true, _activeLaw: 'gdpr',
      _categories: [{ slug: 'necessary', isNecessary: true }, { slug: 'analytics', isNecessary: false }],
      _services: [], _userWhitelist: [], _perServiceConsent: false, _perCookieConsent: false, i18n: {},
      _providersToBlock: [{ re: 'tracker.example.test', categories: ['analytics'] }],
    };
    return route.fulfill({
      contentType: 'text/html',
      body: `<!doctype html><html><head>
      <script>
        window.nativeInner = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML').set;
        window._fazConfig = ${JSON.stringify(config)};
      </script>
      <script>${runtime}</script>
      </head><body><p id="ready">ready</p></body></html>`,
    });
  });
  await page.goto('https://inert.example.test/', { waitUntil: 'load' });

  const result = await page.evaluate((payload) => {
    const out: Record<string, unknown> = {};
    const docs: Record<string, () => Element> = {
      createHTMLDocument: () => document.implementation.createHTMLDocument('').createElement('div'),
      DOMParser: () => new DOMParser().parseFromString('<div></div>', 'text/html').body.firstElementChild as Element,
      template: () => {
        const t = document.createElement('template');
        t.innerHTML = '<div></div>';
        return t.content.firstElementChild as Element;
      },
    };
    for (const [name, make] of Object.entries(docs)) {
      const nativeEl = make();
      (window as any).nativeInner.call(nativeEl, payload);
      const gated = make();
      gated.innerHTML = payload;
      out[name] = {
        nativeHasImg: !!nativeEl.querySelector('img'),
        gatedHasImg: !!gated.querySelector('img'),
        same: nativeEl.innerHTML === gated.innerHTML,
      };
    }
    return out;
  }, PAYLOAD);

  for (const name of ['createHTMLDocument', 'DOMParser', 'template']) {
    const r = result[name] as { nativeHasImg: boolean; gatedHasImg: boolean; same: boolean };
    // Whatever this browser's parser does, the gate must not change it: no
    // live <img> the native parse does not also produce.
    expect(r.gatedHasImg, `${name}: gated parse has a live <img> only where the native one does`).toBe(r.nativeHasImg);
    expect(r.same, `${name}: gated parse equals the native one`).toBe(true);
  }
});
