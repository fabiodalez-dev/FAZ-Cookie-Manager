/**
 * The routes a stylesheet can take into a page that no wrapper stands in front
 * of, exercised against a real parser because jsdom cannot have one.
 *
 * A <style> is not in the bootstrap's resource-element list, and deliberately
 * so: that list is elements which fetch by themselves. A stylesheet fetches
 * through the CSS engine instead, so three routes reached the network with
 * nothing in front of them — document.write, a shadow root (a separate tree no
 * observer on the document reaches) and setHTMLUnsafe (tagged for the backstop
 * but never gated).
 *
 * What is measured here is the part a client-side gate can actually win. An
 * @import in a sheet the parser has already finished is gone — the request left
 * before any observer was notified — but every other CSS request is LAZY:
 * background-image, @font-face src, cursor and mask are fetched when a matching
 * element is first rendered, a frame away rather than a microtask. So the probe
 * is a background-image on an element that renders, and the question is whether
 * it reaches the network before consent.
 *
 * The second half matters as much as the first: a sheet that is held must come
 * BACK. Parking a stylesheet and never restoring it turns a delayed page into a
 * broken one, so each case asserts the live text after the handoff too.
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const bootstrap = readFileSync(new URL('../../frontend/js/bootstrap.min.js', import.meta.url), 'utf8');
const runtime = readFileSync(new URL('../../frontend/js/script.min.js', import.meta.url), 'utf8');

for (const consent of ['no', 'yes']) {
  test(`a stylesheet cannot slip in through write, a shadow root or setHTMLUnsafe: analytics=${consent}`, async ({ page, context }) => {
    const requests: string[] = [];
    const errors: string[] = [];
    let release!: () => void;
    const gate = new Promise<void>((resolve) => { release = resolve; });
    if (consent === 'yes') {
      await context.addCookies([{ name: 'fazcookie-consent', value: 'consent:yes,action:all,necessary:yes,analytics:yes', domain: 'defer.example.test', path: '/' }]);
    }
    page.on('pageerror', (e) => errors.push(e.message));

    await page.route('https://tracker.example.test/**', async (route) => {
      requests.push(new URL(route.request().url()).pathname);
      await route.fulfill({ contentType: 'image/gif', body: '' });
    });

    await page.route('https://defer.example.test/**', async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path === '/config.js') {
        // Held open: this is the download window the bootstrap exists for.
        await gate;
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
        <script>window.nativeGetAttr=Element.prototype.getAttribute;window._fazConfig=${JSON.stringify(config)};</script>
        <script defer src="/config.js"></script>
        <script>${bootstrap}</script>
        <script defer src="/runtime.js"></script>
        <script>
          // Written INTO the parser's stream: there are no nodes to tag when
          // this call returns, which is why the exemption has to be withdrawn
          // for it rather than trusted.
          document.write('<style id="written">.probe-written{background-image:url(https://tracker.example.test/written-css.png)}</style>');
          window.hasSetHTMLUnsafe = typeof Element.prototype.setHTMLUnsafe === 'function';
        </script>
        </head><body>
        <div class="probe-written">written</div>
        <div id="shadow-host"></div>
        <div id="unsafe-host"></div>
        <p id="paint">rendered while the consent runtime downloads</p>
        <script>
          // A shadow root is a separate tree: an observer on the document never
          // reaches it, and the markup sinks' tag pass did not either.
          var root=document.getElementById('shadow-host').attachShadow({mode:'open'});
          root.innerHTML='<img id="shadow-img" src="https://tracker.example.test/shadow.gif">';
          if (window.hasSetHTMLUnsafe) {
            document.getElementById('unsafe-host').setHTMLUnsafe(
              '<style>.probe-unsafe{background-image:url(https://tracker.example.test/unsafe-css.png)}</style>'
              + '<div class="probe-unsafe">unsafe</div>');
          }
        </script>
        </body></html>`,
      });
    });

    await page.goto('https://defer.example.test/', { waitUntil: 'commit' });
    await expect(page.locator('#paint')).toBeVisible();

    // The window is still open — the runtime has not arrived and no consent
    // decision exists. The CSS probes may not have fetched, and unlike a
    // parser-built <img> that is a guarantee rather than a race: a
    // background-image is requested when a matching element is first rendered,
    // which is a frame away, so emptying the sheet in the observer's microtask
    // gets there first. The shadow <img> is the other kind — an observer cannot
    // win against an insertion that starts its own request — so it is judged on
    // the state it ENDS in, below, exactly as the markup routes are in
    // deferred-runtime.spec.ts.
    expect(requests).not.toContain('/written-css.png');
    expect(requests).not.toContain('/unsafe-css.png');

    // And the written sheet is parked where the server-side pass and the
    // runtime already keep a held stylesheet, read through the native accessor
    // so a staged value cannot be mistaken for a live one.
    const parked = await page.evaluate(() => {
      const native = (window as any).nativeGetAttr;
      const written = document.getElementById('written');
      const host = document.getElementById('shadow-host') as HTMLElement;
      const img = host.shadowRoot!.getElementById('shadow-img');
      return {
        writtenText: written ? written.textContent : null,
        writtenParked: written ? !!native.call(written, 'data-faz-css') : false,
        shadowLive: img ? native.call(img, 'src') : 'missing',
        shadowParked: img ? native.call(img, 'data-faz-src') : null,
        hadSetHTMLUnsafe: (window as any).hasSetHTMLUnsafe === true,
      };
    });
    expect(parked.writtenText).toBe('');
    expect(parked.writtenParked).toBe(true);
    // A shadow root's markup is judged like the document's: parked under the
    // attribute the runtime restores from, not left live and unexamined.
    expect(parked.shadowLive).toBeNull();
    expect(parked.shadowParked).toBe('https://tracker.example.test/shadow.gif');

    // Now the runtime arrives and the page view gets a real consent decision.
    release();
    // Delayed, not destroyed. The runtime's arrival is observed through this
    // effect rather than through a global of its own: the handoff is what hands
    // the sheet back, and from there the runtime's own CSS gate owns it — which
    // is why the request appears on consent and stays away without it.
    await expect.poll(() => page.evaluate(() => {
      const el = document.getElementById('written');
      return !!el && String(el.textContent).length > 0;
    }), { timeout: 15_000 }).toBe(true);

    if (consent === 'yes') {
      await expect.poll(() => requests.includes('/written-css.png'), { timeout: 10_000 }).toBe(true);
    } else {
      // Still blocked, now by the runtime's own neutralisation rather than by
      // an emptied element.
      expect(requests).not.toContain('/written-css.png');
      expect(requests).not.toContain('/unsafe-css.png');
    }
    expect(errors).toEqual([]);
  });
}
