/** Exercise the actual browser parser with slow deferred config and runtime. */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const bootstrap = readFileSync(new URL('../../frontend/js/bootstrap.min.js', import.meta.url), 'utf8');
const gcm = readFileSync(new URL('../../frontend/js/gcm.min.js', import.meta.url), 'utf8');
const runtime = readFileSync(new URL('../../frontend/js/script.min.js', import.meta.url), 'utf8');

for (const consent of ['no', 'yes', 'missing']) {
  test(`deferred runtime protects early resources: analytics=${consent}`, async ({ page, context }) => {
    const requests: string[] = [], errors: string[] = [];
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
        <script>window._fazConfig=${JSON.stringify(config)};</script>
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
        </script>
        </head><body><div class="probe">CSS resource probe</div><p id="paint">Page rendered while consent downloads</p></body></html>` });
    });
    await page.routeWebSocket('wss://tracker.example.test/**', () => {requests.push('socket');});
    await page.route('https://tracker.example.test/**', async route => {
      requests.push(new URL(route.request().url()).pathname);
      await route.fulfill({ contentType: route.request().resourceType()==='script'?'text/javascript':'text/plain', body:'' });
    });
    await page.goto('https://defer.example.test/', {waitUntil:'commit'});
    await expect(page.locator('#paint')).toBeVisible();
    expect(requests).toEqual([]);
    expect(await page.evaluate(() => (window as any).inlineCfgRead)).toBe(true);
    expect(await page.evaluate(() => (window as any).earlyURL)).toBe('https://tracker.example.test/script.js');
    expect(await page.evaluate(() => (window as any).earlyAttr)).toBe('https://tracker.example.test/script.js');
    expect(await page.evaluate(() => (window as any).socketNative)).toBe(true);
    expect(await page.evaluate(() => (window as any).dataLayer.some((args:any) => args[0]==='consent' && args[1]==='default'))).toBe(true);
    expect(await page.evaluate(() => document.readyState)).toBe('interactive');
    release();
    await page.waitForLoadState('domcontentloaded');
    if (consent === 'missing') {
      // The runtime cannot arrive: /config.js 404s and _staticConfigRequired
      // keeps _fazStore null, so nothing calls start()/finish(). Holding the
      // queue forever would take the site's own JavaScript down with the
      // trackers, so the bootstrap releases same-origin work at
      // DOMContentLoaded and keeps third-party resources parked.
      await expect.poll(() => page.evaluate(() => (window as any).essentialOnload)).toBe(true);
      expect(requests).toEqual(['essential']);
      // The cross-origin fetch resolves with the same empty 200 the runtime's
      // own gate returns — a rejection would surface as an unhandled rejection
      // in callers that never expected to be blocked — but no request is made.
      expect(await page.evaluate(() => (window as any).fetchDone)).toBe(true);
      // Third-party resources keep their URL in the parking attribute and lose
      // the live one, so nothing is requested and nothing is lost. They stay
      // parked for the rest of this page view: restoring them needs the
      // provider rules that never arrived, and a reload hands the decision
      // back to server-side blocking.
      expect(await page.evaluate(() => Array.from(document.querySelectorAll('script,iframe,img'))
        .filter((el) => (el.getAttribute('src') || '').includes('tracker.example.test')).length)).toBe(0);
      expect(await page.evaluate(() => Array.from(document.querySelectorAll('script,iframe'))
        .map((el) => el.getAttribute('data-faz-src'))
        .filter((v) => (v || '').includes('tracker.example.test')).sort())).toEqual([
          'https://tracker.example.test/frame',
          'https://tracker.example.test/script.js',
        ]);
      expect(errors).toEqual([]);
      return;
    }
    await expect.poll(() => page.evaluate(() => (window as any).essentialOnload)).toBe(true);
    await expect.poll(() => page.evaluate(() => (window as any).fetchDone)).toBe(true);
    expect(await page.evaluate(async () => {
      const response=await (window as any).retainedFetch('https://tracker.example.test/retained');return response.status;
    })).toBe(200);
    expect(await page.evaluate(() => (window as any)._fazConfig._providersToBlock.length)).toBe(1);
    if (consent === 'no') expect(requests).toEqual(['essential']);
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
