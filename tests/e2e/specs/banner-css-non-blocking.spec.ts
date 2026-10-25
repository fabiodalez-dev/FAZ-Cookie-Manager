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
 *  - script.js switches the link to media="all" and holds the .faz-ready
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
    const whileHeld = await page.evaluate(() => ({
      ready: document.documentElement.classList.contains('faz-ready'),
      media: document.querySelector<HTMLLinkElement>('link[data-faz-banner-css]')?.media,
    }));
    expect(whileHeld.media, 'script.js applies the sheet without waiting for it').toBe('all');
    expect(whileHeld.ready, 'banner stays hidden while its stylesheet is loading').toBe(false);

    release();
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), undefined, { timeout: 10000 });
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
    // Never answered: neither load nor error ever fires.
    await page.route(BANNER_CSS, () => new Promise(() => {}));
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), undefined, { timeout: 8000 });
    const shown = await page.evaluate(() => {
      const el = document.querySelector('.faz-consent-container');
      return !!el && !el.classList.contains('faz-hide');
    });
    expect(shown).toBe(true);
    await ctx.close();
  });
});
