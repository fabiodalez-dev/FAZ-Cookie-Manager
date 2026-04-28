/**
 * Real-Bricks repro for issue #87.
 *
 * Visits a WP post on the local stack that contains a *server-rendered*
 * <div class="brxe-video"><iframe src="youtube..."></iframe></div>
 * (the exact DOM shape Bricks Video element produces) and verifies the
 * FAZ blocker injects a `.faz-placeholder` after a "reject all" consent.
 *
 * This is the closest possible repro of the real user case (Bricks 2.3.4
 * theme active on faz-test) without driving the Bricks visual editor.
 */

import { chromium } from 'playwright';

const POST_ID = parseInt(process.env.POST_ID || '540', 10);
const WP = process.env.WP_BASE_URL || 'http://127.0.0.1:9998';
const POST_URL = `${WP}/?p=${POST_ID}`;

const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
await ctx.addCookies([{
  name: 'fazcookie-consent',
  value: 'consentid:bricks-' + Date.now() + ',consent:no,action:yes,necessary:yes,functional:no,analytics:no,performance:no,marketing:no,uncategorized:no',
  domain: '127.0.0.1',
  path: '/',
  expires: Math.floor(Date.now() / 1000) + 86400,
}]);
const page = await ctx.newPage();

const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 250)); });

console.log(`--- Visiting real Bricks-shape post: ${POST_URL}`);
const r = await page.goto(POST_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
console.log(`  HTTP: ${r.status()}`);

await page.waitForTimeout(3000);

const result = await page.evaluate(() => {
  const wrapper = document.querySelector('.brxe-video');
  const iframes = document.querySelectorAll('iframe');
  const placeholders = document.querySelectorAll('.faz-placeholder, .video-placeholder-normal, .video-placeholder-youtube');
  const blockedIframes = document.querySelectorAll('iframe[data-faz-src]');
  const visibleIframes = Array.from(iframes).filter((f) => {
    const r = f.getBoundingClientRect();
    return r.width > 50 && r.height > 50 && getComputedStyle(f).visibility !== 'hidden';
  });

  return {
    bricksWrapperPresent: !!wrapper,
    bricksWrapperRect: wrapper ? { w: wrapper.getBoundingClientRect().width, h: wrapper.getBoundingClientRect().height } : null,
    iframeCount: iframes.length,
    visibleIframeCount: visibleIframes.length,
    placeholderCount: placeholders.length,
    blockedIframeCount: blockedIframes.length,
    youtubeStillLoaded: Array.from(iframes).some((f) => (f.src || '').includes('youtube.com/embed')),
  };
});

console.log('  Bricks wrapper present:', result.bricksWrapperPresent);
console.log('  Wrapper rect:', JSON.stringify(result.bricksWrapperRect));
console.log('  iframes total:', result.iframeCount, '/ visibly rendered:', result.visibleIframeCount);
console.log('  placeholders injected:', result.placeholderCount);
console.log('  blocked iframes (data-faz-src):', result.blockedIframeCount);
console.log('  YouTube iframe still loading:', result.youtubeStillLoaded);

console.log('\n--- Console errors:', consoleErrors.length);
consoleErrors.slice(0, 5).forEach((e) => console.log(' ', e));

await page.screenshot({ path: '/tmp/bricks-real-post.png', fullPage: true });
await browser.close();

const ok = result.placeholderCount > 0 && !result.youtubeStillLoaded;
if (ok) {
  console.log('\n✓ PASS — placeholder injected, YouTube iframe NOT executed.');
  process.exit(0);
} else {
  console.log('\n✗ FAIL —',
    result.placeholderCount === 0 ? 'no placeholder' : '',
    result.youtubeStillLoaded ? '+ youtube iframe still loaded (unblocked)' : '');
  process.exit(1);
}
