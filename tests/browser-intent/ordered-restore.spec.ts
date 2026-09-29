/** Exercise interactions between restore paths using the distributed bundle. */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../../frontend/js/script.min.js', import.meta.url), 'utf8');

for (const scenario of ['dynamic', 'template', 'shared', 'withdrawal']) {
  test(`restore integration: ${scenario}`, async ({ page }) => {
    const errors: string[] = [];
    const requests: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    const lib = '<script id="library" type="text/plain" data-faz-category="analytics" src="https://restore.example.test/library.js"></script>';
    const inline = '<script id="inline" type="text/plain" data-faz-category="analytics">window.events.push("inline"); window.helper();</script>';
    let releaseLibrary!: () => void;
    const gate = new Promise<void>(resolve => { releaseLibrary = resolve; });
    await page.route('https://restore.example.test/**', async route => {
      const path = new URL(route.request().url()).pathname;
      if (path === '/library.js') {
        requests.push('library');
        await gate;
        await route.fulfill({contentType:'text/javascript', body:'window.events.push("library"); window.helper=function(){};'});
      } else if (path === '/dependent.js') {
        requests.push('dependent');
        await route.fulfill({contentType:'text/javascript', body:'window.events.push("dependent"); window.helper();'});
      } else {
        const markup = scenario === 'template'
          ? '<div class="faz-placeholder" data-faz-category="analytics"><template class="faz-placeholder-content">' + lib + '</template></div>' + inline
          : lib + (scenario === 'shared' ? '' : inline);
        await route.fulfill({contentType:'text/html', body:'<!doctype html><html><head></head><body>' + markup + '</body></html>'});
      }
    });
    await page.goto('https://restore.example.test/');
    await page.evaluate(() => {
      const w = window as any;
      w.events=[];
      w._fazConfig={_block:'1',_activeLaw:'gdpr',_categories:[{slug:'necessary',isNecessary:true},{slug:'analytics',isNecessary:false},{slug:'marketing',isNecessary:false}],_services:[],_providersToBlock:[{re:'restore.example.test',categories:['analytics']}],_userWhitelist:[],_perServiceConsent:false,_perCookieConsent:false,i18n:{}};
      const add=document.addEventListener.bind(document);
      document.addEventListener=((type:string,...args:any[])=>type==='DOMContentLoaded'?undefined:(add as any)(type,...args)) as any;
    });
    await page.addScriptTag({content:source});
    if (scenario === 'dynamic' || scenario === 'withdrawal') {
      // Exercise the real MutationObserver backup and its position anchor.
      await page.evaluate(() => {
        const script=document.createElement('script'); script.id='dependent';
        script.setAttribute('data-faz-category','analytics'); script.src='https://restore.example.test/dependent.js';
        document.getElementById('inline')!.before(script);
      });
      await expect.poll(() => page.evaluate(() => (window as any)._fazConfig._backupNodes.length)).toBe(1);
      expect(requests).toEqual([]);
    }
    await page.evaluate(scenario => {
      const w=window as any;
      if(scenario==='shared') w._fazConfig._providersToBlock=[{re:'restore.example.test',categories:['analytics','marketing']}];
      w.fazcookie._fazConsentStore.set('consent','yes');
      w.fazcookie._fazConsentStore.set('analytics','yes');
      w.fazcookie._fazConsentStore.set('marketing','no');
      w._fazUnblock(); w._fazUnblock();
    }, scenario);
    if (scenario === 'shared') {
      await expect(page.locator('#library')).toHaveAttribute('type','text/plain');
      expect(requests).toEqual([]);
      await page.evaluate(() => {
        const w=window as any;
        w.fazcookie._fazConsentStore.set('marketing','yes'); w._fazUnblock();
      });
    }
    await expect.poll(() => requests.includes('library')).toBe(true);
    expect(requests).toEqual(['library']);
    if (scenario === 'withdrawal') {
      await page.evaluate(() => (window as any).fazcookie._fazConsentStore.set('analytics','no'));
    }
    releaseLibrary();
    await expect.poll(() => page.evaluate(() => (window as any).events)).toEqual(
      scenario === 'dynamic' ? ['library','dependent','inline'] : scenario === 'template' ? ['library','inline'] : ['library']
    );
    if (scenario === 'withdrawal') {
      await expect(page.locator('#dependent')).toHaveAttribute('type','text/plain');
      expect(requests).toEqual(['library']);
      await page.evaluate(() => {
        const w=window as any; w.fazcookie._fazConsentStore.set('analytics','yes'); w._fazUnblock();
      });
      await expect.poll(() => page.evaluate(() => (window as any).events)).toEqual(['library','dependent','inline']);
    }
    expect(errors).toEqual([]);
  });
}
