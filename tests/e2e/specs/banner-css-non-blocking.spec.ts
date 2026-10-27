import { test, expect } from '../fixtures/wp-fixture';

/**
 * The compiled banner stylesheet must not block the page's first paint
 * (PageSpeed "Render-blocking requests", wp.org support topic
 * "render-blocking-requests-2"), and making it non-blocking must never show
 * an unstyled banner or leave page content unstyled.
 *
 *  - The link is printed as media="print" with the data-faz-banner-css marker;
 *    the rules that style server-rendered page content (.faz-hidden, the
 *    placeholder floor, the [faz_cookie_settings] button) are printed inline.
 *  - script.js switches loaded CSS to media="all" and holds the .faz-ready
 *    reveal until the sheet has loaded, so a slow stylesheet delays the banner
 *    instead of flashing it unstyled.
 *  - A stylesheet that never arrives cannot keep the consent prompt hidden.
 *
 * Fresh contexts (no consent cookie) keep the banner in its first-visit state.
 */

const BANNER_CSS = /\/faz-cookie-manager\/assets\/banner-[0-9a-f]+\.css/;

test.describe('Banner stylesheet is not render-blocking', () => {
  test('banner CSS is a media="print" link and page-content rules are inline', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
    const html = (await response?.text()) || '';

    const link = html.match(/<link\b[^>]*data-faz-banner-css[^>]*>/i)?.[0] || '';
    expect(link, 'the banner stylesheet carries the data-faz-banner-css marker').not.toBe('');
    expect(link).toMatch(BANNER_CSS);
    expect(link, 'served as media="print" so it never blocks rendering').toMatch(/media=['"]print['"]/);
    expect(link, 'no inline handler: strict CSPs must keep working').not.toMatch(/\bonload=/i);

    const inline = html.match(/<style\b[^>]*id=['"]faz-cookie-manager-css-page-inline-css['"][^>]*>([\s\S]*?)<\/style>/i)?.[1] || '';
    expect(inline, '.faz-hidden must apply before script.js runs').toContain('.faz-hidden{display:none!important');
    expect(inline, 'placeholder floor must apply before script.js runs').toContain('.video-placeholder-normal');
    expect(inline, 'the [faz_cookie_settings] button is page content').toContain('.faz-cookie-settings-btn{');

    // After init the sheet is applied for real.
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), undefined, { timeout: 10000 });
    const media = await page.evaluate(() => document.querySelector<HTMLLinkElement>('link[data-faz-banner-css]')?.media);
    expect(media).toBe('all');
    await ctx.close();
  });

  test('a slow banner stylesheet delays the reveal instead of showing an unstyled banner', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    let release: () => void = () => {};
    const held = new Promise<void>((resolve) => { release = resolve; });
    await page.route(BANNER_CSS, async (route) => {
      await held;
      await route.continue();
    });
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // The runtime has rendered the banner, but the sheet is still in flight:
    // the anti-FOUC gate must stay closed.
    await page.waitForSelector('.faz-consent-container', { state: 'attached', timeout: 10000 });
    await page.waitForTimeout(800);
    // Top of the first piece of page content: the first <body> child the
    // banner runtime did not insert and that has a box (skip links and
    // live regions are positioned off-screen), marked once so both reads
    // measure the same element.
    await page.evaluate(() => {
      const first = Array.from(document.body.children).find((el) =>
        !/\bfaz-/.test(String(el.className))
        && !['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'LINK'].includes(el.tagName)
        && !el.classList.contains('screen-reader-text')
        && el.getBoundingClientRect().height > 0);
      first?.setAttribute('data-e2e-content-probe', '1');
    });
    const contentTop = () => page.evaluate(() => {
      const el = document.querySelector('[data-e2e-content-probe]');
      return el ? Math.round(el.getBoundingClientRect().top + window.scrollY) : null;
    });
    const whileHeld = await page.evaluate(() => ({
      ready: document.documentElement.classList.contains('faz-ready'),
      media: document.querySelector<HTMLLinkElement>('link[data-faz-banner-css]')?.media,
    }));
    expect(whileHeld.media, 'pending CSS stays non-blocking until it loads').toBe('print');
    expect(whileHeld.ready, 'banner stays hidden while its stylesheet is loading').toBe(false);
    const topWhileHeld = await contentTop();
    expect(topWhileHeld, 'found a page-content element to measure').not.toBeNull();
    // The invisible banner boxes must not swallow taps meant for the page.
    // Checked on the computed style rather than by hit-testing: whether they
    // paint above the content depends on how the theme positions it (on the
    // block theme here its positioned wrappers paint on top anyway).
    const catchingClicks = await page.evaluate(() =>
      ['.faz-overlay', '.faz-btn-revisit-wrapper', '.faz-consent-container', '.faz-modal']
        .filter((sel) => {
          const el = document.querySelector(sel);
          return !!el && getComputedStyle(el).pointerEvents !== 'none';
        }));
    expect(catchingClicks, 'the hidden banner boxes do not intercept clicks on the page').toEqual([]);

    release();
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), undefined, { timeout: 10000 });
    // The hidden banner must not have pushed the page down while it waited:
    // otherwise the content jumps back when the sheet lands (layout shift).
    const topAfter = await contentTop();
    expect(topAfter, 'the measured element is still in the page').not.toBeNull();
    expect(topWhileHeld, 'page content did not move when the banner stylesheet arrived').toBe(topAfter);
    // Revealed WITH its styles: the template positions the container as fixed.
    const position = await page.evaluate(() => {
      const el = document.querySelector('.faz-consent-container');
      return el ? getComputedStyle(el).position : '';
    });
    expect(position).toBe('fixed');
    await ctx.close();
  });

  test('a banner stylesheet that never arrives cannot keep the consent prompt hidden', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    // Never answered: neither load nor error ever fires. Poll the timer-driven
    // fallback independently of rendering frames, which WebKit can defer while
    // the document still has a pending stylesheet.
    await page.route(BANNER_CSS, () => new Promise(() => {}));
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), undefined, { timeout: 8000, polling: 50 });
    const shown = await page.evaluate(() => {
      const el = document.querySelector('.faz-consent-container');
      return !!el && !el.classList.contains('faz-hide');
    });
    expect(shown).toBe(true);
    // State classes must keep working even when the external CSS never loads.
    // Native DOM click avoids depending on RAF while WebKit has pending CSS.
    await page.locator('.faz-btn-accept').evaluate((button) => (button as HTMLButtonElement).click());
    await page.waitForFunction(() => document.querySelector('.faz-consent-container')?.classList.contains('faz-hide'), undefined, { polling: 50 });
    expect(await page.locator('.faz-consent-container').evaluate((el) => getComputedStyle(el).display)).toBe('none');
    await ctx.close();
  });

  test('a stylesheet arriving after the fallback still applies its styles', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    let release: () => void = () => {};
    const held = new Promise<void>((resolve) => { release = resolve; });
    await page.route(BANNER_CSS, async (route) => { await held; await route.continue(); });
    try {
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), undefined, { timeout: 8000, polling: 50 });
      expect(await page.evaluate(() => document.querySelector<HTMLLinkElement>('link[data-faz-banner-css]')?.media)).toBe('print');
      release();
      await page.waitForFunction(() => document.querySelector<HTMLLinkElement>('link[data-faz-banner-css]')?.media === 'all');
      expect(await page.locator('.faz-consent-container').evaluate((el) => getComputedStyle(el).position)).toBe('fixed');
    } finally {
      release();
      await ctx.close();
    }
  });

});
