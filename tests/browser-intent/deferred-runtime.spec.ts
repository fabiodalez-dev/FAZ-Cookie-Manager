/** Exercise the actual browser parser with slow deferred config and runtime. */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const bootstrap = readFileSync(new URL('../../frontend/js/bootstrap.min.js', import.meta.url), 'utf8');
const gcm = readFileSync(new URL('../../frontend/js/gcm.min.js', import.meta.url), 'utf8');
const runtime = readFileSync(new URL('../../frontend/js/script.min.js', import.meta.url), 'utf8');

test('a parser attribute containing a newline cannot exempt a new iframe URL', async ({ page }) => {
  const requests: string[] = [];
  let release!: () => void;
  const gate = new Promise<void>(resolve => { release = resolve; });
  await page.route('https://defer.example.test/**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/pending.js') {
      await gate;
      return route.fulfill({ contentType: 'text/javascript', body: '' });
    }
    return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head>
      <script>window._fazConfig={_block:true};</script>
      <script>${bootstrap}</script><script defer src="/pending.js"></script>
      </head><body><main id="host"><img id="kept-image" src="/unchanged.jpg"><iframe id="probe"
        background="/empty&#10;src=https://tracker.example.test/collision"></iframe></main>
      <script>(async function () {
        var frame = document.getElementById('probe');
        frame.remove();
        // Drain the removal: the observer no longer sees detached edits.
        await new Promise(function (resolve) { setTimeout(resolve, 0); });
        frame.getAttributeNode('background').value = '/empty';
        var src = document.createAttribute('src');
        src.value = 'https://tracker.example.test/collision';
        frame.setAttributeNode(src);
        document.getElementById('host').appendChild(frame);
        var destination = document.createElement('div');
        document.body.appendChild(destination);
        var image = document.getElementById('kept-image');
        destination.appendChild(image);
        window.collisionProbe = { inserted: frame.isConnected, imageMoved: destination.contains(image) };
      })();</script></body></html>` });
  });
  await page.route('https://tracker.example.test/**', route => {
    requests.push(route.request().url());
    return route.fulfill({ contentType: 'text/html', body: '' });
  });
  try {
    await page.goto('https://defer.example.test/', { waitUntil: 'commit' });
    await expect.poll(() => page.evaluate(() => (window as any).collisionProbe)).toEqual({ inserted: false, imageMoved: true });
    expect(requests, 'no external request before the deferred runtime answers').toEqual([]);
    release();
    await page.waitForLoadState('domcontentloaded');
    expect(requests, 'the safety release also keeps the external iframe parked').toEqual([]);
  } finally {
    release();
  }
});

for (const scenario of [
  {
    name: 'a relative base reinserted after navigation',
    base: 'assets/', src: 'widget.html', finalBase: 'https://defer.example.test/denied/assets/',
    change: "history.pushState(null, '', '/denied/'); base.remove(); document.head.appendChild(base);",
  },
  {
    name: 'a query-relative src after a base filename change',
    base: '/allowed.html', src: '?pixel', finalBase: 'https://defer.example.test/denied.html',
    change: "base.setAttribute('href', '/denied.html');",
  },
]) {
  test(`${scenario.name} requires a new iframe verdict`, async ({ page }) => {
    const requests: string[] = [];
    let release!: () => void;
    const gate = new Promise<void>(resolve => { release = resolve; });
    await page.route('https://defer.example.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/pending.js') {
        await gate;
        return route.fulfill({ contentType: 'text/javascript', body: '' });
      }
      if (url.pathname.endsWith('/widget.html') || url.searchParams.has('pixel')) {
        requests.push(url.pathname);
        return route.fulfill({ contentType: 'text/html', body: '' });
      }
      return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head>
        <base href="${scenario.base}"><script>window._fazConfig={_block:true};</script>
        <script>${bootstrap}</script><script defer src="/pending.js"></script>
        </head><body><main id="host"><img id="kept-image" src="/unchanged.jpg"><iframe id="probe" src="${scenario.src}"></iframe></main>
        <script>(async function () {
          var frame = document.getElementById('probe');
          frame.remove();
          await new Promise(function (resolve) { setTimeout(resolve, 0); });
          var base = document.querySelector('base');
          ${scenario.change}
          document.getElementById('host').appendChild(frame);
          var destination = document.createElement('div');
          document.body.appendChild(destination);
          var image = document.getElementById('kept-image');
          destination.appendChild(image);
          window.baseProbe = { inserted: frame.isConnected, base: document.baseURI, imageMoved: destination.contains(image) };
        })();</script></body></html>` });
    });
    try {
      await page.goto('https://defer.example.test/allowed/', { waitUntil: 'commit' });
      await expect.poll(() => page.evaluate(() => (window as any).baseProbe)).toEqual({
        inserted: false, base: scenario.finalBase, imageMoved: true,
      });
      expect(requests.filter(path => path.startsWith('/denied')), 'no request to the changed destination before a verdict').toEqual([]);
    } finally {
      release();
    }
  });
}

for (const consent of ['no', 'yes', 'missing']) {
  test(`deferred runtime protects early resources: analytics=${consent}`, async ({ page, context }) => {
    const requests: string[] = [], errors: string[] = [], cdn: string[] = [];
    let release!: () => void;
    const gate = new Promise<void>(resolve => { release = resolve; });
    if (consent === 'yes') await context.addCookies([{ name:'fazcookie-consent', value:'consent:yes,action:all,necessary:yes,analytics:yes', domain:'defer.example.test', path:'/' }]);
    page.on('pageerror', e => errors.push(e.message));
    await page.route('https://defer.example.test/**', async route => {
      const path = new URL(route.request().url()).pathname;
      if (path === '/config.js') {
        await gate;
        if (consent === 'missing') return route.fulfill({ status:404, body:'' });
        return route.fulfill({ contentType:'text/javascript', body:'window._fazStaticConfig={_providersToBlock:[{re:"tracker.example.test",categories:["analytics"]}],_cookieCategoryMap:{},_serviceCatalogue:{}};' });
      }
      if (path === '/runtime.js') return route.fulfill({ contentType:'text/javascript', body:runtime });
      if (path === '/essential.js') {
        requests.push('essential');
        return route.fulfill({contentType:'text/javascript',body:'window.essentialLoaded=true;'});
      }
      const config = {_block:'1',_staticConfigRequired:true,_aggressiveCssUrlBlocking:true,_activeLaw:'gdpr',_categories:[{slug:'necessary',isNecessary:true},{slug:'analytics',isNecessary:false}],_services:[],_userWhitelist:[],_perServiceConsent:false,_perCookieConsent:false,i18n:{}};
      return route.fulfill({ contentType:'text/html', body:`<!doctype html><html><head>
        <script>window.nativeGetAttr=Element.prototype.getAttribute;window._fazConfig=${JSON.stringify(config)};</script>
        <script defer src="/config.js"></script>
        <script>${bootstrap}</script>
        <script defer src="/runtime.js"></script>
        <script>window._fazGcm={default_settings:[],wait_for_update:500};${gcm}</script>
        <script>
          window.retainedFetch=window.fetch;
          window.fetchDone=false;
          var sheetStyle=document.createElement('style');document.head.appendChild(sheetStyle);sheetStyle.sheet.insertRule('.probe{background-image:url(https://tracker.example.test/css-pixel)}',0);
          var textStyle=document.createElement('style');textStyle.textContent='.probe{background-image:url(https://tracker.example.test/css-text)}';document.head.appendChild(textStyle);
          window.fetch('https://tracker.example.test/fetch').then(()=>window.fetchDone=true);
          var xhr=new XMLHttpRequest();xhr.open('GET','https://tracker.example.test/xhr');xhr.send();
          var aborted=new XMLHttpRequest();aborted.open('GET','/aborted');aborted.send();aborted.abort();
          var inlineConfig=document.createElement('script');inlineConfig.text='window.inlineEssential=true;';document.head.appendChild(inlineConfig);window.inlineCfgRead=window.inlineEssential===true;
          var tracker=document.createElement('script');tracker.src='https://tracker.example.test/script.js';window.earlyURL=tracker.src;window.earlyAttr=tracker.getAttribute('src');document.head.appendChild(tracker);
          var cancelled=new Image();cancelled.src='https://tracker.example.test/cancelled.gif';cancelled.removeAttribute('src');
          window.socket=new WebSocket('wss://tracker.example.test/socket');window.socketNative=window.socket instanceof WebSocket;
          var image=new Image();image.src='https://tracker.example.test/pixel.gif';
          var frame=document.createElement('iframe');frame.src='https://tracker.example.test/frame';document.head.append(frame);
          var essential=document.createElement('script');essential.src='/essential.js';essential.onload=function(){window.essentialOnload=true;};document.head.appendChild(essential);
          // A candidate LIST whose first entry is relative: resolving the whole
          // string as one URL reads it as same-origin and never looks at the
          // tracker entry, which is the bug the runtime already fixed.
          var ss=window.ssProbe=new Image();ss.id='ss';ss.srcset='/local.png 1x, https://tracker.example.test/srcset.gif 2x';document.head.appendChild(ss);
        </script>
        </head><body><div class="probe">CSS resource probe</div><p id="paint">Page rendered while consent downloads</p>
        <!-- Server-rendered cross-origin resources: the other half of what the
             DOM backstop sees, since the observer is armed in <head> and the
             parser keeps building the body after it. These passed the
             server-side blocker, which is the authority for server markup, and
             the parser had already started their requests before any observer
             was notified. Parking them buys nothing and costs the LCP element
             plus a second fetch of the same bytes. -->
        <img id="static-hero" src="https://cdn.example.test/hero.jpg" alt="">
        <iframe id="static-frame" src="https://cdn.example.test/embed"></iframe>
        <div id="markup-host"></div>
        <script>
          // Markup routes, driven from the body where the container is really
          // attached. These never pass through a setter this file can wrap: the
          // parser builds the nodes. Before the runtime was deferred, its own
          // MutationObserver covered them — armed in <head>, ahead of every page
          // script — and deferring armed it after the document had parsed, so
          // the whole download window lost the only coverage they ever had.
          var host=document.getElementById('markup-host');
          var mk=document.createElement('div');host.appendChild(mk);
          mk.innerHTML='<img id="ih" src="https://tracker.example.test/innerhtml.gif"><iframe id="ihf" src="https://tracker.example.test/innerhtml-frame"></iframe>';
          var iah=document.createElement('div');host.appendChild(iah);
          iah.insertAdjacentHTML('beforeend','<img id="iahimg" src="https://tracker.example.test/iah.gif">');
          var oh=document.createElement('div');host.appendChild(oh);
          oh.outerHTML='<div id="ohw"><img id="ohimg" src="https://tracker.example.test/outerhtml.gif"></div>';
          // setHTMLUnsafe is the fourth markup sink bootstrap.js wraps, and the
          // only one this file never measured. Its sibling setHTML is the
          // sanitizing form and is newer, so it is probed only where it exists.
          // A SHADOW ROOT gets its own probe because that is where this API is
          // mostly used, and a shadow tree is one the document observer never
          // sees — if anything parks that image it has to be the sink itself.
          var shHost=document.createElement('div');host.appendChild(shHost);
          window.hasSetHTMLUnsafe=typeof shHost.setHTMLUnsafe==='function';
          if(window.hasSetHTMLUnsafe){
            shHost.setHTMLUnsafe('<img id="shu" src="https://tracker.example.test/sethtml.gif">');
            var srHost=document.createElement('div');host.appendChild(srHost);
            window.probeShadow=srHost.attachShadow({mode:'open'});
            window.probeShadow.setHTMLUnsafe('<img id="shadowimg" src="https://tracker.example.test/shadow-sethtml.gif">');
          }
        </script>
        </body></html>` });
    });
    await page.route('https://cdn.example.test/**', async route => {
      cdn.push(new URL(route.request().url()).pathname);
      await route.fulfill({ contentType: route.request().resourceType()==='document'?'text/html':'image/gif', body:'' });
    });
    await page.routeWebSocket('wss://tracker.example.test/**', () => {requests.push('socket');});
    await page.route('https://tracker.example.test/**', async route => {
      requests.push(new URL(route.request().url()).pathname);
      await route.fulfill({ contentType: route.request().resourceType()==='script'?'text/javascript':'text/plain', body:'' });
    });
    await page.goto('https://defer.example.test/', {waitUntil:'commit'});
    await expect(page.locator('#paint')).toBeVisible();
    // The setter routes are a synchronous guarantee: the assignment is
    // intercepted before the engine ever sees a URL, so nothing may be
    // requested. The markup routes are not, and cannot be — the only gate
    // available to a parser-built node is an asynchronous observer, which is
    // exactly what script.js has always used and documents as best-effort
    // against the first fetch. So they are judged on the state they end in,
    // below, not on winning a race.
    const MARKUP = ['/innerhtml.gif', '/innerhtml-frame', '/iah.gif', '/outerhtml.gif', '/sethtml.gif', '/shadow-sethtml.gif'];
    // Treated as one set everywhere below, because which member of it reaches
    // the network is a property of the engine's scheduler, not of this plugin.
    expect(requests.filter((path) => !MARKUP.includes(path))).toEqual([]);
    // And the one route no observer can ever win: an <iframe> navigates
    // synchronously on insertion, while an <img> queues its load and the
    // observer notification gets there first. That is measured against the
    // previous release, not asserted away — before this runtime was deferred
    // the same differential leaked /innerhtml-frame too, because the protection
    // there was also an observer. Parity is the goal; a synchronous guarantee
    // for parser-built markup is not available to a client-side gate at all.
    // What IS guaranteed: every one of them is parked under the attribute the
    // runtime restores from, instead of being left live and unexamined. That
    // last state was the permanent half of the regression — nothing re-reads an
    // already-present node, so there was no placeholder, no per-service reveal
    // and no re-evaluation when consent changed.
    // The sink has to be there for its probe to mean anything: a browser
    // without setHTMLUnsafe would make the two new ids absent and the check
    // below vacuously true.
    expect(await page.evaluate(() => (window as any).hasSetHTMLUnsafe)).toBe(true);
    await expect.poll(() => page.evaluate(() => {
      const parked = (el: Element | null | undefined) =>
        !!el && !el.getAttribute('src') && !!el.getAttribute('data-faz-src') && !!el.getAttribute('data-faz-category');
      const ids = ['ih', 'ihf', 'iahimg', 'ohimg', 'shu'];
      // Inside a shadow root getElementById does not reach, and neither does
      // the document observer — this one is the sink's own responsibility.
      const shadow = (window as any).probeShadow as ShadowRoot | undefined;
      return ids.every((id) => parked(document.getElementById(id)))
        && parked(shadow && shadow.getElementById ? shadow.getElementById('shadowimg') : shadow?.querySelector('#shadowimg'));
    })).toBe(true);
    // And the server's own markup is left exactly as it was sent. Read through
    // the native accessor: the bootstrap's getAttribute answers with the staged
    // value, so the shimmed one cannot tell "never touched" from "staged".
    expect(await page.evaluate(() => {
      const native = (window as any).nativeGetAttr;
      return ['static-hero', 'static-frame'].map((id) => {
        const el = document.getElementById(id)!;
        return [native.call(el, 'src'), native.call(el, 'data-faz-src')];
      });
    })).toEqual([
      ['https://cdn.example.test/hero.jpg', null],
      ['https://cdn.example.test/embed', null],
    ]);
    // The list is held by the setter route, so during the window it is simply
    // not on the element at all — neither applied nor parked. Parking is what
    // the safety release does with it, asserted in the `missing` branch below.
    // Read through the retained reference, not by id — document.head already
    // carries a held insertion, so every later append to it queues behind that
    // one and this element is still detached — and through the NATIVE accessor,
    // because the bootstrap's getAttribute deliberately answers with the staged
    // value so libraries can read back what they just set.
    expect(await page.evaluate(() => (window as any).nativeGetAttr.call((window as any).ssProbe, 'srcset'))).toBe(null);
    expect(await page.evaluate(() => (window as any).inlineCfgRead)).toBe(true);
    expect(await page.evaluate(() => (window as any).earlyURL)).toBe('https://tracker.example.test/script.js');
    expect(await page.evaluate(() => (window as any).earlyAttr)).toBe('https://tracker.example.test/script.js');
    expect(await page.evaluate(() => (window as any).socketNative)).toBe(true);
    expect(await page.evaluate(() => (window as any).dataLayer.some((args:any) => args[0]==='consent' && args[1]==='default'))).toBe(true);
    expect(await page.evaluate(() => document.readyState)).toBe('interactive');
    release();
    await page.waitForLoadState('domcontentloaded');
    // Across the handoff too, on all three paths. A parked static resource
    // would come back here as a second fetch of a file the browser already
    // had — the measurable half of the defect — and, if the runtime never
    // arrived, as an element left blank for the whole page view.
    expect(await page.evaluate(() => {
      const native = (window as any).nativeGetAttr;
      return ['static-hero', 'static-frame'].map((id) => native.call(document.getElementById(id)!, 'src'));
    })).toEqual(['https://cdn.example.test/hero.jpg', 'https://cdn.example.test/embed']);
    expect(cdn.filter((path) => path === '/hero.jpg')).toEqual(['/hero.jpg']);
    expect(cdn.filter((path) => path === '/embed')).toEqual(['/embed']);
    if (consent === 'missing') {
      // The runtime cannot arrive: /config.js 404s and _staticConfigRequired
      // keeps _fazStore null, so nothing calls start()/finish(). Holding the
      // queue forever would take the site's own JavaScript down with the
      // trackers, so the bootstrap releases same-origin work at
      // DOMContentLoaded and keeps third-party resources parked.
      await expect.poll(() => page.evaluate(() => (window as any).essentialOnload)).toBe(true);
      // Same split as before the release: the setter routes may request nothing,
      // the parser-built nodes are allowed to have raced the observer, and
      // nothing else may appear. Which of them wins that race is engine
      // dependent — Chromium queues an image load and the observer notification
      // gets there first, WebKit starts it synchronously and it does not — so
      // the race is measured, not asserted away, and the end state is what this
      // gate pins.
      expect(requests.filter((path) => !MARKUP.includes(path))).toEqual(['essential']);
      // The cross-origin fetch resolves with the same empty 200 the runtime's
      // own gate returns — a rejection would surface as an unhandled rejection
      // in callers that never expected to be blocked — but no request is made.
      expect(await page.evaluate(() => (window as any).fetchDone)).toBe(true);
      // Third-party resources keep their URL in the parking attribute and lose
      // the live one, so nothing is requested and nothing is lost. They stay
      // parked for the rest of this page view: restoring them needs the
      // provider rules that never arrived, and a reload hands the decision
      // back to server-side blocking.
      //
      // A refused <script> is the one exception to the parking attribute: it
      // keeps its src and is parked by TYPE (text/plain + data-faz-category),
      // the form the runtime's script restore reads. A script whose type is
      // not JavaScript is neither fetched nor run, which the request list
      // above already proves on every engine.
      expect(await page.evaluate(() => Array.from(document.querySelectorAll('script,iframe,img'))
        .filter((el) => (el.getAttribute('src') || '').includes('tracker.example.test'))
        .filter((el) => !(el.tagName === 'SCRIPT' && el.getAttribute('type') === 'text/plain' && el.hasAttribute('data-faz-category')))
        .length)).toBe(0);
      expect(await page.evaluate(() => Array.from(document.querySelectorAll('script[type="text/plain"][data-faz-category]'))
        .map((el) => el.getAttribute('src'))
        .filter((v) => (v || '').includes('tracker.example.test')))).toEqual(['https://tracker.example.test/script.js']);
      expect(await page.evaluate(() => Array.from(document.querySelectorAll('script,iframe,img'))
        .map((el) => el.getAttribute('data-faz-src'))
        .filter((v) => (v || '').includes('tracker.example.test')).sort())).toEqual([
          'https://tracker.example.test/frame',
          'https://tracker.example.test/iah.gif',
          'https://tracker.example.test/innerhtml-frame',
          'https://tracker.example.test/innerhtml.gif',
          'https://tracker.example.test/outerhtml.gif',
          'https://tracker.example.test/sethtml.gif',
        ]);
      // The shadow-root probe is deliberately NOT in the list above: a shadow
      // tree is outside document.querySelectorAll, which is also why the
      // document observer never sees it. It is asserted through its root.
      expect(await page.evaluate(() => {
        const root = (window as any).probeShadow as ShadowRoot;
        const el = root.querySelector('img');
        return [el && el.getAttribute('src'), el && el.getAttribute('data-faz-src')];
      })).toEqual([null, 'https://tracker.example.test/shadow-sethtml.gif']);
      // The detached probes (new Image(), the removeAttribute'd one) are not in
      // the document and so are not in the list above; the held pixel is
      // asserted through its own reference.
      expect(await page.evaluate(() => (window as any).nativeGetAttr.call((window as any).ssProbe, 'data-faz-srcset')))
        .toBe('/local.png 1x, https://tracker.example.test/srcset.gif 2x');
      // The permanent half of the regression, and the part that mattered more
      // than the extra request: a markup-injected node used to stay live and
      // unexamined for the whole page view — no placeholder, no per-service
      // reveal, no re-evaluation when consent changed. None is left live now
      // (a script parked by type keeps an inert src, asserted above).
      expect(await page.evaluate(() => Array.from(document.querySelectorAll('img,iframe,script,video,audio,embed,object,input'))
        .filter((el) => !(el.tagName === 'SCRIPT' && el.getAttribute('type') === 'text/plain' && el.hasAttribute('data-faz-category')))
        .map((el) => el.getAttribute('src') || el.getAttribute('data') || '')
        .filter((v) => v.includes('tracker.example.test')))).toEqual([]);
      // Everything the bootstrap parks carries a category, because every
      // runtime restore selector requires one. Without it a resource that was
      // merely delayed is dead for the rest of the page view, even on full
      // consent — a leak traded for silent destruction.
      expect(await page.evaluate(() => Array.from(document.querySelectorAll('[data-faz-src]'))
        .every((el) => !!el.getAttribute('data-faz-category')))).toBe(true);
      // The release is not the end of enforcement: a cross-origin assignment
      // made after DOMContentLoaded is still parked, which is what the warning
      // printed on this path claims.
      expect(await page.evaluate(() => {
        const late = document.createElement('img');
        document.body.appendChild(late);
        late.src = 'https://tracker.example.test/after-release.gif';
        return [late.getAttribute('src'), late.getAttribute('data-faz-src')];
      })).toEqual([null, 'https://tracker.example.test/after-release.gif']);
      expect(requests).not.toContain('/after-release.gif');
      expect(await page.evaluate(() => document.documentElement.className.includes('faz-consent-runtime-missing'))).toBe(true);
      // The candidate list is parked in its own key, which the runtime restores
      // by re-reading every candidate — not in data-faz-src, where it would be
      // read back as one URL.
      expect(await page.evaluate(() => {
        const el = (window as any).ssProbe as HTMLImageElement;
        const native = (window as any).nativeGetAttr;
        return [native.call(el, 'srcset'), native.call(el, 'data-faz-srcset')];
      })).toEqual([null, '/local.png 1x, https://tracker.example.test/srcset.gif 2x']);
      expect(requests).not.toContain('/srcset.gif');
      expect(errors).toEqual([]);
      return;
    }
    await expect.poll(() => page.evaluate(() => (window as any).essentialOnload)).toBe(true);
    await expect.poll(() => page.evaluate(() => (window as any).fetchDone)).toBe(true);
    expect(await page.evaluate(async () => {
      const response=await (window as any).retainedFetch('https://tracker.example.test/retained');return response.status;
    })).toBe(200);
    expect(await page.evaluate(() => (window as any)._fazConfig._providersToBlock.length)).toBe(1);
    if (consent === 'no') {
      // Same split as above: the setter routes may request nothing, the
      // parser-built iframe is allowed to have raced the observer, and nothing
      // else may appear. Listing them rather than filtering loosely, so a new
      // leak on any other route still fails this gate.
      expect(requests.filter((path) => !MARKUP.includes(path))).toEqual(['essential']);
      expect(await page.evaluate(() => Array.from(document.querySelectorAll('img,iframe'))
        .map((el) => el.getAttribute('src') || '')
        .filter((v) => v.includes('tracker.example.test')))).toEqual([]);
    }
    else {
      await expect.poll(() => requests.includes('/script.js')) .toBe(true);
      expect(requests).toContain('/fetch');
      expect(requests).toContain('/xhr');
      await expect.poll(() => requests.includes('socket')).toBe(true);
    }
    expect(requests).not.toContain('/cancelled.gif');
    expect(errors).toEqual([]);
  });
}

// document.write is the one markup sink the bootstrap cannot tag: its argument
// goes to the parser's input stream, so there are no nodes to mark when the
// call returns, and from that point an insertion by the parser and one the page
// wrote are indistinguishable. The exemption that keeps server markup untouched
// must therefore be withdrawn rather than trusted — an unwrapped sink may cost
// a request, never a consent decision. Needs a real parser: jsdom has already
// closed its own, where write() reopens the document instead.
const WRITES = [
  {
    label: 'a plain document.write of an image',
    code: `document.write('<img id="written" src="https://tracker.example.test/written.gif" alt="">');`,
    withdraws: true,
  },
  {
    label: 'a write split across calls, through Document.prototype',
    // Both halves of what a per-call string test cannot do. write()
    // concatenates successive calls into one input stream, so `<im` +
    // `g src=…` builds an image no per-call regex sees; and going through the
    // prototype skips any wrapper installed on the document instance. Either
    // alone was enough to keep the exemption and leave the element unexamined.
    code: `Document.prototype.write.call(document, '<im');`
      + `Document.prototype.write.call(document, 'g id="written" src="https://tracker.example.test/split.gif" alt="">');`,
    withdraws: true,
  },
  {
    label: 'the <image> parser alias',
    // The parser turns <image src> into an <img>. A tag-name list does not.
    code: `document.write('<image id="written" src="https://tracker.example.test/alias.gif" alt="">');`,
    withdraws: true,
  },
  {
    label: 'a script closed by </script foo>',
    // The tokenizer ends script text at `</script` followed by whitespace, `/`
    // or `>`. A check that demanded the `>` read this as more script text and
    // let the image through.
    code: `document.write('<script>window.ran=1;<\\/script foo>'`
      + ` + '<img id="written" src="https://tracker.example.test/endtag.gif" alt="">');`,
    withdraws: true,
  },
  {
    label: 'a script-only write (the legacy tag)',
    // The other direction, and the one that costs rather than leaks:
    // withdrawing here would park every static resource after the write and
    // gain nothing, because guard() declines every SCRIPT — a parser-built
    // script has started its request before any observer is notified.
    code: `document.write('<script src="/essential.js"><\/script>');`,
    withdraws: false,
  },
];
for (const write of WRITES) {
test(`the parser exemption and ${write.label}`, async ({ page }) => {
  const cdn: string[] = [];
  let release!: () => void;
  const gate = new Promise<void>(resolve => { release = resolve; });
  await page.route('https://defer.example.test/**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/config.js') { await gate; return route.fulfill({ contentType:'text/javascript', body:'window._fazStaticConfig={_providersToBlock:[{re:"tracker.example.test",categories:["analytics"]}],_cookieCategoryMap:{},_serviceCatalogue:{}};' }); }
    if (path === '/runtime.js') return route.fulfill({ contentType:'text/javascript', body:runtime });
    if (path === '/essential.js') return route.fulfill({ contentType:'text/javascript', body:'window.essentialLoaded=true;' });
    const config = {_block:'1',_staticConfigRequired:true,_activeLaw:'gdpr',_categories:[{slug:'necessary',isNecessary:true},{slug:'analytics',isNecessary:false}],_services:[],_userWhitelist:[],_perServiceConsent:false,_perCookieConsent:false,i18n:{}};
    return route.fulfill({ contentType:'text/html', body:`<!doctype html><html><head>
      <script>window.nativeGetAttr=Element.prototype.getAttribute;window._fazConfig=${JSON.stringify(config)};</script>
      <script defer src="/config.js"></script>
      <script>${bootstrap}</script>
      <script defer src="/runtime.js"></script>
      </head><body><p id="paint">rendered</p>
      <img id="before-write" src="https://cdn.example.test/before.jpg" alt="">
      <script>${write.code}</script>
      <img id="after-write" src="https://cdn.example.test/after.jpg" alt="">
      </body></html>` });
  });
  await page.route('https://cdn.example.test/**', route => {
    cdn.push(new URL(route.request().url()).pathname);
    return route.fulfill({ contentType:'image/gif', body:'' });
  });
  await page.route('https://tracker.example.test/**', route => route.fulfill({ contentType:'image/gif', body:'' }));
  await page.goto('https://defer.example.test/', {waitUntil:'commit'});
  await expect(page.locator('#paint')).toBeVisible();
  const liveSrc = (id: string) => page.evaluate((target) => {
    const el = document.getElementById(target);
    return el ? (window as any).nativeGetAttr.call(el, 'src') : 'MISSING';
  }, id);
  if (write.withdraws) {
    await expect.poll(() => page.evaluate(() => {
      const native = (window as any).nativeGetAttr;
      return ['written', 'after-write'].every((id) => {
        const el = document.getElementById(id);
        return !!el && !native.call(el, 'src') && !!native.call(el, 'data-faz-src');
      });
    }), { message: 'the written element and everything after it are judged again' }).toBe(true);
  } else {
    // Nothing is withdrawn, so the static image after the write is left alone
    // and fetched once — the regression this exemption exists to prevent.
    expect(await liveSrc('after-write')).toBe('https://cdn.example.test/after.jpg');
    expect(await page.evaluate(() => (window as any).nativeGetAttr.call(document.getElementById('after-write')!, 'data-faz-src'))).toBe(null);
  }
  // The element the parser had already placed BEFORE the write keeps the
  // exemption either way: withdrawing cannot act backwards, and that resource
  // was already fetched.
  expect(await liveSrc('before-write')).toBe('https://cdn.example.test/before.jpg');
  release();
  await page.waitForLoadState('domcontentloaded');
  // And the runtime hands back what a withdrawal cost: cdn.example.test is in
  // no blocked category, so the price is a second fetch at worst, never a
  // resource lost for the page view.
  await expect.poll(() => liveSrc('after-write')).toBe('https://cdn.example.test/after.jpg');
  expect(cdn.filter((path) => path === '/before.jpg')).toEqual(['/before.jpg']);
  if (!write.withdraws) expect(cdn.filter((path) => path === '/after.jpg')).toEqual(['/after.jpg']);
});
}
