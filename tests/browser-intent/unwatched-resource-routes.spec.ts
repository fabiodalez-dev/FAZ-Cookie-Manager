/**
 * The resource routes every layer of this plugin used to be blind to: the SVG
 * namespace, the legacy `background` presentation attribute, and an iframe's
 * srcdoc.
 *
 * <image> is an <img> by another name, <use href="https://x/sprite.svg#icon">
 * fetches an external document, and an SVG <script href> EXECUTES. None of them
 * was covered anywhere: not by the server-side pass (its patterns name img and
 * iframe), not by the runtime's parking (its branches are keyed on img, iframe,
 * source and link), and not by the bootstrap, whose attribute map and DOM
 * backstop are both indexed on nodeName — which is uppercase for HTML elements
 * and the name as written for every other namespace, so `attrs[el.nodeName]`
 * was undefined for the whole tree.
 *
 * Two halves are measured here, because fixing one without the other is how a
 * blocked resource becomes a dead one:
 *   1. a script that inserts an SVG image during the download window cannot
 *      reach the network, through either attribute spelling;
 *   2. markup the SERVER parked comes back when consent allows it, and stays
 *      parked when it does not.
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const bootstrap = readFileSync(new URL('../../frontend/js/bootstrap.min.js', import.meta.url), 'utf8');
const runtime = readFileSync(new URL('../../frontend/js/script.min.js', import.meta.url), 'utf8');

// Both runs start with NO consent cookie, because that is the only state in
// which the server parks anything: with consent granted PHP serves the markup
// live. So the restore that matters is the one the ACCEPT flow performs, and
// that is what the `accept` run drives.
for (const consent of ['refused', 'accept']) {
  test(`every unwatched resource route is gated and restorable: consent=${consent}`, async ({ page }) => {
    const requests: string[] = [];
    const errors: string[] = [];
    let release!: () => void;
    const gate = new Promise<void>((resolve) => { release = resolve; });
    page.on('pageerror', (e) => errors.push(e.message));

    await page.route('https://tracker.example.test/**', async (route) => {
      requests.push(new URL(route.request().url()).pathname);
      await route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg"></svg>' });
    });

    await page.route('https://defer.example.test/**', async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path === '/config.js') {
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
        </head><body>
        <p id="paint">rendered while the consent runtime downloads</p>
        <!-- What the server-side pass emits for a blocked SVG reference: both
             spellings park into one attribute, so there is one restore branch. -->
        <svg><image id="server-parked" data-faz-category="analytics" data-faz-href="https://tracker.example.test/server.svg" width="1" height="1"/></svg>
        <table id="server-bg" data-faz-category="analytics" data-faz-background="https://tracker.example.test/server-bg.png"><tr><td>bg</td></tr></table>
        <iframe id="server-doc" data-faz-category="analytics" data-faz-srcdoc="&lt;img src=&quot;https://tracker.example.test/doc.png&quot;&gt;"></iframe>
        <svg id="holder"></svg>
        <script>
          var SVGNS='http://www.w3.org/2000/svg', XLINK='http://www.w3.org/1999/xlink';
          var holder=document.getElementById('holder');
          // Kept on window: an insertion that carries a resource is HELD, so
          // these elements are not in the document while the window is open and
          // getElementById would answer null.
          window.probes={};
          // The SVG2 spelling, through setAttribute.
          var a=document.createElementNS(SVGNS,'image');
          a.id='via-href';
          a.setAttribute('href','https://tracker.example.test/href.svg');
          window.probes['via-href']=a;
          holder.appendChild(a);
          // The legacy spelling, through the only API that can write it.
          var b=document.createElementNS(SVGNS,'image');
          b.id='via-xlink';
          b.setAttributeNS(XLINK,'xlink:href','https://tracker.example.test/xlink.svg');
          window.probes['via-xlink']=b;
          holder.appendChild(b);
          // An external sprite reference is a fetch too.
          var c=document.createElementNS(SVGNS,'use');
          c.id='via-use';
          c.setAttribute('href','https://tracker.example.test/sprite.svg#icon');
          window.probes['via-use']=c;
          holder.appendChild(c);
          // And the one that is not merely a fetch: an SVG script runs.
          var d=document.createElementNS(SVGNS,'script');
          d.id='via-script';
          d.setAttribute('href','https://tracker.example.test/svg-script.js');
          window.probes['via-script']=d;
          holder.appendChild(d);
          // The legacy attribute, on an element that honours it. Obsolete in the
          // spec, still fetched by every engine.
          var t=document.createElement('table');
          t.id='via-bg';
          t.setAttribute('background','https://tracker.example.test/bg.png');
          window.probes['via-bg']=t;
          document.body.appendChild(t);
          // A document in an attribute: the engine parses it into a context
          // nothing on this side can intercept once it exists.
          var f=document.createElement('iframe');
          f.id='via-srcdoc';
          f.srcdoc='<img src="https://tracker.example.test/srcdoc.png">';
          window.probes['via-srcdoc']=f;
          document.body.appendChild(f);
        </script>
        </body></html>`,
      });
    });

    await page.goto('https://defer.example.test/', { waitUntil: 'commit' });
    await expect(page.locator('#paint')).toBeVisible();

    // Nothing in the namespace reached the network while the window was open.
    // These are setter routes, so this is a synchronous guarantee rather than a
    // race: the assignment is intercepted before the engine is given a URL.
    expect(requests).toEqual([]);

    // And the server-parked reference is still parked, read natively so a
    // staged value cannot be mistaken for a live one.
    const live = async () => page.evaluate(() => {
      const native = (window as any).nativeGetAttr;
      const probes = (window as any).probes || {};
      const ids = ['server-parked', 'server-bg', 'server-doc', 'via-href', 'via-xlink', 'via-use', 'via-script', 'via-bg', 'via-srcdoc'];
      return ids.map((id) => {
        const el = document.getElementById(id) || probes[id];
        if (!el) return [id, 'missing', 'missing', null, 'missing', null];
        return [id, native.call(el, 'href'), native.call(el, 'xlink:href'),
          native.call(el, 'data-faz-href') || native.call(el, 'data-faz-background') || native.call(el, 'data-faz-srcdoc'),
          native.call(el, 'background') || native.call(el, 'srcdoc'), el.isConnected];
      });
    });
    for (const [id, href, xlink, , background] of await live()) {
      expect(href, `${id} has no live href during the window`).toBeNull();
      expect(xlink, `${id} has no live xlink:href during the window`).toBeNull();
      expect(background, `${id} has no live background during the window`).toBeNull();
    }

    release();
    await expect.poll(() => page.evaluate(() => typeof (window as any)._fazAcceptCookies === 'function'),
      { timeout: 15_000 }).toBe(true);

    if (consent === 'accept') {
      // The real restore trigger: the visitor accepts, and the runtime re-runs
      // its server-side unblock pass for the categories now allowed.
      const accepted = await page.evaluate(() => {
        try { (window as any)._fazAcceptCookies('all', true, 'test'); return 'ok'; }
        catch (e) { return String(e); }
      });
      expect(accepted, 'the accept flow ran').toBe('ok');
      // Delayed, not destroyed — and that includes the reference the SERVER
      // parked, which is the half a client-only fix would leave dead.
      await expect.poll(() => requests.includes('/server.svg'), { timeout: 10_000 }).toBe(true);
      await expect.poll(() => requests.includes('/href.svg'), { timeout: 10_000 }).toBe(true);
      await expect.poll(() => requests.includes('/xlink.svg'), { timeout: 10_000 }).toBe(true);
      await expect.poll(() => requests.includes('/svg-script.js'), { timeout: 10_000 }).toBe(true);
      await expect.poll(() => requests.includes('/server-bg.png'), { timeout: 10_000 }).toBe(true);
      await expect.poll(() => requests.includes('/bg.png'), { timeout: 10_000 }).toBe(true);
      await expect.poll(() => requests.includes('/doc.png'), { timeout: 10_000 }).toBe(true);
      await expect.poll(() => requests.includes('/srcdoc.png'), { timeout: 10_000 }).toBe(true);
    } else {
      // No decision was made, so the download window's refusal simply holds:
      // nothing fetched, and every URL still recoverable from its parking
      // attribute rather than thrown away.
      expect(requests).toEqual([]);
      const rows = await live();
      for (const [id, href, xlink, parked, background] of rows) {
        expect(href, `${id} is still not live`).toBeNull();
        expect(xlink, `${id} has no live xlink:href either`).toBeNull();
        expect(background, `${id} has no live background or srcdoc either`).toBeNull();
        expect(parked, `${id} keeps its URL where a restore pass can find it`).toContain('tracker.example.test');
      }
    }
    expect(errors).toEqual([]);
  });
}

/**
 * The premise the srcdoc gate's entity decode rests on.
 *
 * _fazMarkupUrls() has to read a srcdoc the way the engine will: the document
 * it builds decodes character references, so a search over the raw attribute
 * saw neither `https:&#x2F;&#x2F;` nor `https:\/\/`. The decode is done by
 * assigning the text to a DETACHED <textarea>, whose content is RCDATA — tags
 * stay text — and reading .value back.
 *
 * CodeQL flags that assignment as js/xss-through-dom (alert 83), and the
 * question it raises is a fair one: if `</textarea>` could close the RCDATA
 * context, the rest would be parsed as real elements, and a detached <img
 * onerror> both fetches and fires. That would make the gate execute the markup
 * it was asked only to inspect. It does not — measured here on all three
 * engines rather than deduced from the spec, because this is the assumption
 * that makes the whole route safe, and nothing else in the suite would notice
 * if an engine ever changed it.
 */
test('the srcdoc entity decode builds no elements and fetches nothing', async ({ page }) => {
  const requests: string[] = [];
  page.on('request', (r) => { if (r.url().includes('probe.example.invalid')) requests.push(r.url()); });

  const result = await page.evaluate(() => {
    const out: { elements: string[][]; fired: boolean; decoded: string } = {
      elements: [], fired: false, decoded: '',
    };
    (window as unknown as { __fazProbeFired?: boolean }).__fazProbeFired = false;
    const ESCAPES = [
      '</textarea><img src="https://probe.example.invalid/a.png" onerror="window.__fazProbeFired=true">',
      '</TEXTAREA ><img src="https://probe.example.invalid/b.png" onerror="window.__fazProbeFired=true">',
      '</textarea/><img src="https://probe.example.invalid/c.png" onerror="window.__fazProbeFired=true">',
      '</textarea\t><img src="https://probe.example.invalid/d.png" onerror="window.__fazProbeFired=true">',
    ];
    for (const payload of ESCAPES) {
      const area = document.createElement('textarea');
      area.innerHTML = payload;
      out.elements.push(Array.from(area.children).map((n) => n.tagName));
    }
    // And the decode still decodes — otherwise this test would pass just as
    // well against a step that had stopped working altogether.
    const area = document.createElement('textarea');
    area.innerHTML = 'https:&#x2F;&#x2F;tracker.example.test/x.png';
    out.decoded = area.value;
    out.fired = (window as unknown as { __fazProbeFired?: boolean }).__fazProbeFired === true;
    return out;
  });

  for (const tags of result.elements) {
    expect(tags, 'the RCDATA context must hold: no element may be built from the text').toEqual([]);
  }
  expect(result.decoded, 'the decode must still resolve character references').toBe('https://tracker.example.test/x.png');
  await page.waitForTimeout(500);
  expect(await page.evaluate(() => (window as unknown as { __fazProbeFired?: boolean }).__fazProbeFired === true)).toBe(false);
  expect(requests, 'a detached <img> fetches, so any element built here would show up as a request').toEqual([]);
});
