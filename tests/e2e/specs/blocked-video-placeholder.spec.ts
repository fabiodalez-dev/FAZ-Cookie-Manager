import { test, expect } from '../fixtures/wp-fixture';
import { execFileSync } from 'node:child_process';

/**
 * Regression guard for the blocked-video placeholder.
 *
 * Two bugs this locks down:
 *  1. The placeholder CSS used to live in the `#faz-style-inline` <style> that
 *     `_fazRemoveStyles()` deletes once the banner is positioned, so the moment
 *     the frontend JS ran the styled card collapsed to a bare, unstyled box
 *     (transparent background, content height) — the "nice design flashes, ugly
 *     box stays" report. The CSS now lives in its own persistent <style>, so
 *     the card must STILL be styled AFTER the JS has run.
 *  2. The card is branded per service via the `--faz-svc-color` custom property
 *     (YouTube red, Vimeo blue, …) and shows the service name label.
 */

const WP_PATH = process.env.WP_PATH || '';

function wp(args: string[]): string {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();
}

test.describe('Blocked-video placeholder stays styled after the JS runs', () => {
  test.skip(!WP_PATH, 'requires WP_PATH to seed the test page via wp-cli');

  let url = '';
  let postId = '';

  test.beforeAll(() => {
    postId = wp([
      'post', 'create', '--post_type=page', '--post_status=publish',
      '--post_title=FAZ E2E YouTube placeholder',
      '--post_content=<iframe width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube"></iframe>',
      '--porcelain',
    ]).replace(/\D/g, '');
    url = wp(['post', 'get', postId, '--field=url']);
  });

  test.afterAll(() => {
    if (postId) wp(['post', 'delete', postId, '--force']);
  });

  test('card keeps its grey styling + brand accent once the banner reveals', async ({ browser }) => {
    // Fresh visitor → marketing is blocked pre-consent → the YouTube iframe is
    // replaced with the consent placeholder server-side.
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    // Let the frontend JS run _fazRemoveStyles() (the step that used to strip
    // the placeholder CSS). The reveal flips <html> to .faz-ready.
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 8000 });
    await page.waitForTimeout(300);

    const info = await page.evaluate(() => {
      const card = document.querySelector('.faz-placeholder--video');
      if (!card) return null;
      const cs = getComputedStyle(card);
      const btn = card.querySelector('.faz-placeholder-btn');
      const svc = card.querySelector('.faz-placeholder-svcname');
      return {
        bg: cs.backgroundColor,
        height: Math.round(card.getBoundingClientRect().height),
        btnBg: btn ? getComputedStyle(btn).backgroundColor : null,
        svcname: svc ? (svc.textContent || '').trim() : null,
      };
    });

    expect(info, 'a video placeholder must be present').not.toBeNull();
    // The persistence fix: background is the styled grey, NOT transparent
    // (transparent + content-height is exactly the stripped-CSS symptom).
    expect(info!.bg).not.toBe('rgba(0, 0, 0, 0)');
    expect(info!.bg).toBe('rgb(233, 234, 236)');
    // aspect-ratio styling survived too (16/9 on a ~560px card is ~315px,
    // far above the unstyled content height of ~160px).
    expect(info!.height).toBeGreaterThan(250);
    // Brand accent applied: YouTube renders the red CTA + the service label.
    expect(info!.btnBg).toBe('rgb(255, 0, 0)');
    expect(info!.svcname).toBe('YouTube');

    await ctx.close();
  });
});
