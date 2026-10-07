/** Verified footer controls must remain usable on the actual visitor page. */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
const root = fileURLToPath(new URL('../../', import.meta.url));
const source = readFileSync(root + 'frontend/js/script.min.js', 'utf8');

async function boot(page: any, footer: string) {
  await page.route('https://withdrawal.example.test/**', (route: any) => route.fulfill({
    contentType: 'text/html', body: `<!doctype html><html><head><style>
      .faz-hide,.faz-revisit-hide,.faz-modal { display:none; }
      .faz-modal-open { display:block; }
      .theme-hidden { display:none; }
      @media (max-width:600px) { .mobile-hidden { display:none; } }
    </style></head><body>
      <div class="faz-consent-container faz-hide"><div data-faz-tag="notice">Cookie notice</div></div>
      <div class="faz-modal"><div data-faz-tag="detail" class="faz-preference-center"><button>Save preferences</button></div></div>
      <div data-faz-tag="revisit-consent" class="faz-btn-revisit-wrapper faz-revisit-hide"><button>Reopen preferences</button></div>
      <footer>${footer}</footer>
    </body></html>`
  }));
  await page.goto('https://withdrawal.example.test/');
  await page.evaluate(() => {
    const w = window as any;
    // This fixture drives the withdrawal helpers directly. Prevent the full
    // async init from racing them when the bundle is loaded after DOM ready.
    w.fazcookie = { _fazInitDone:true };
    w._fazConfig = {
      _activeLaw:'gdpr', _categories:[], _services:[], _providersToBlock:[],
      _cookieCategoryMap:{}, _whitelistedCookiePatterns:[], _userWhitelist:[],
      _bannerConfig:{settings:{applicableLaw:'gdpr',type:'box',preferenceCenterType:'popup'},
        behaviours:{}, config:{revisitConsent:{status:false,verifiedAlternative:true,position:'bottom-left'}}},
      i18n:{}
    };
  });
  await page.addScriptTag({content:source});
  await page.evaluate(() => {
    const w = window as any;
    w._fazRegisterShortcodeTriggers();
    w._fazRegisterListeners();
    w._fazWatchWithdrawalControl();
    w._fazRemoveBanner();
  });
}

for (const [name, footer] of Object.entries({
  missing:'<a href="/privacy">Privacy</a>',
  disabled:'<button disabled data-faz-open-preferences>Preferences</button>',
  hidden:'<div hidden><a data-faz-open-preferences>Preferences</a></div>',
  inert:'<div inert><button data-faz-open-preferences>Preferences</button></div>',
  css:'<div class="theme-hidden"><a data-faz-open-preferences>Preferences</a></div>',
  template:'<template><template>inner</template><button data-faz-open-preferences>Preferences</button></template>',
  empty:'<span data-faz-open-preferences></span>',
})) {
  test(`${name} footer retains a working consent reopen control`, async ({page}) => {
    await boot(page, footer);
    const fallback = page.locator('[data-faz-tag="revisit-consent"]');
    await expect(fallback).toBeVisible();
    await fallback.locator('button').click();
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
  });
}

test('usable footer suppresses the widget and reopens consent itself', async ({page}) => {
  await boot(page, '<a id="preferences" href="#faz-consent" data-faz-open-preferences>Preferences</a>');
  await expect(page.locator('[data-faz-tag="revisit-consent"]')).toBeHidden();
  await page.locator('#preferences').click();
  await expect(page.locator('[data-faz-tag="detail"]')).toBeVisible();
});

test('footer removal and restoration update the fallback', async ({page}) => {
  await boot(page, '<button id="preferences" data-faz-open-preferences>Preferences</button>');
  const fallback = page.locator('[data-faz-tag="revisit-consent"]');
  await expect(fallback).toBeHidden();
  await page.locator('#preferences').evaluate(el => el.remove());
  await expect(fallback).toBeVisible();
  await page.locator('footer').evaluate(el => { el.innerHTML='<button data-faz-open-preferences>Preferences</button>'; });
  await expect(fallback).toBeHidden();
});

for (const attribute of ['aria-hidden', 'data-state']) {
  test(`${attribute} theme styles update the withdrawal fallback`, async ({page}) => {
    await boot(page, '<div id="footer-controls"><button data-faz-open-preferences>Preferences</button></div>');
    const hiddenValue = attribute === 'aria-hidden' ? 'true' : 'hidden';
    await page.addStyleTag({content:`#footer-controls[${attribute}="${hiddenValue}"]{display:none}`});
    const fallback = page.locator('[data-faz-tag="revisit-consent"]');
    const controls = page.locator('#footer-controls');
    await expect(fallback).toBeHidden();
    await controls.evaluate((el, {attr, value}) => el.setAttribute(attr, value), {attr:attribute, value:hiddenValue});
    await expect(fallback).toBeVisible();
    await controls.evaluate((el, attr) => el.removeAttribute(attr), attribute);
    await expect(fallback).toBeHidden();
  });
}

for (const motion of ['transition', 'animation']) {
  test(`a completed CSS ${motion} updates the withdrawal fallback`, async ({page}) => {
    await boot(page, '<div id="footer-controls"><button data-faz-open-preferences>Preferences</button></div>');
    await page.addStyleTag({content: motion === 'transition'
      ? '#footer-controls{transition:opacity 300ms linear} #footer-controls.fading{opacity:0}'
      : '@keyframes hide-footer{from{opacity:1}to{opacity:0}} #footer-controls.fading{animation:hide-footer 300ms linear forwards}'});
    const fallback = page.locator('[data-faz-tag="revisit-consent"]');
    const controls = page.locator('#footer-controls');
    await expect(fallback).toBeHidden();
    await controls.evaluate(el => el.classList.add('fading'));
    await expect(controls).toHaveCSS('opacity', '0');
    await expect(fallback).toBeVisible();
    // The fallback must still reopen consent after the theme hides the link.
    await fallback.locator('button').click();
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
  });
}

/**
 * The case a DOM observer and a resize listener both miss.
 *
 * Cache plugins defer CSS: a `media="print"` sheet swapped on load, critical
 * CSS followed by the full sheet, a `<link>` injected into `<head>`. The sheet
 * applies AFTER the first usability check — which runs from _fazRemoveBanner()
 * at init — and applying it mutates nothing and resizes nothing. Without a
 * `load` listener the widget stayed hidden and the visitor had no withdrawal
 * route at all, which is the one outcome this fallback exists to prevent.
 *
 * The response is delayed on purpose. The <link> insertion is itself a
 * mutation, so without the delay the observer's animation frame could happen
 * to run after the sheet had already applied, and the test would pass without
 * the listener it is meant to cover.
 */
test('a stylesheet that loads after init brings the fallback back', async ({page}) => {
  await boot(page, '<div id="footer-controls"><button data-faz-open-preferences>Preferences</button></div>');
  const fallback = page.locator('[data-faz-tag="revisit-consent"]');
  await expect(fallback).toBeHidden();
  await page.route('https://withdrawal.example.test/late.css', async (route: any) => {
    await new Promise((resolve) => setTimeout(resolve, 400));
    await route.fulfill({contentType:'text/css', body:'#footer-controls{display:none}'});
  });
  await page.evaluate(() => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/late.css';
    document.head.appendChild(link);
  });
  await expect(fallback).toBeVisible();
});

/** A <style> injected into <head> — invisible to a body-rooted observer. */
test('a late style element in the head brings the fallback back', async ({page}) => {
  await boot(page, '<div id="footer-controls"><button data-faz-open-preferences>Preferences</button></div>');
  const fallback = page.locator('[data-faz-tag="revisit-consent"]');
  await expect(fallback).toBeHidden();
  await page.evaluate(() => {
    const style = document.createElement('style');
    style.textContent = '#footer-controls{display:none}';
    document.head.appendChild(style);
  });
  await expect(fallback).toBeVisible();
});

test('theme visibility and viewport changes retain a usable route', async ({page}) => {
  await page.setViewportSize({width:1000,height:800});
  await boot(page, '<div id="footer-controls" class="mobile-hidden"><button data-faz-open-preferences>Preferences</button></div>');
  const fallback = page.locator('[data-faz-tag="revisit-consent"]');
  await expect(fallback).toBeHidden();
  await page.locator('#footer-controls').evaluate(el => el.classList.add('theme-hidden'));
  await expect(fallback).toBeVisible();
  await page.locator('#footer-controls').evaluate(el => el.classList.remove('theme-hidden'));
  await expect(fallback).toBeHidden();
  await page.setViewportSize({width:400,height:800});
  await expect(fallback).toBeVisible();
  await page.setViewportSize({width:1000,height:800});
  await expect(fallback).toBeHidden();
});
