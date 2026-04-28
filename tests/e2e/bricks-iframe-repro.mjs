/**
 * Synthetic repro for issue #87 (Bricks Builder Video element):
 * an <iframe> dynamically inserted into a wrapper that uses
 * `aspect-ratio: 16/9` with no explicit width/height on the iframe
 * itself, so at MutationObserver time the iframe's offsetWidth
 * /Height are 0. We expect FAZ to still inject a `.faz-placeholder`.
 *
 * Pre-fix: the `_fazAddPlaceholder` early-return on zero metrics →
 *           NO `.faz-placeholder` ever inserted (the bug).
 * Post-fix: ancestor-measure → rAF retry → CSS-floor last resort →
 *           a `.faz-placeholder` is always present.
 *
 * Runs against the local nginx + WP test stack with FAZ deployed.
 */

import { chromium } from 'playwright';

const WP = process.env.WP_BASE_URL || 'http://127.0.0.1:9998';

const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
// Reject everything so script blocking is on.
await ctx.addCookies([{
  name: 'fazcookie-consent',
  value: 'consentid:repro-' + Date.now() + ',consent:no,action:yes,necessary:yes,functional:no,analytics:no,performance:no,marketing:no,uncategorized:no',
  domain: '127.0.0.1',
  path: '/',
  expires: Math.floor(Date.now() / 1000) + 86400,
}]);
const page = await ctx.newPage();

await page.goto(WP, { waitUntil: 'domcontentloaded', timeout: 30000 });
await page.waitForTimeout(1500);

console.log('--- Synthesising Bricks-shaped video wrapper at runtime ---');
const result = await page.evaluate(async () => {
  // Bricks Video element shape: outer wrapper with aspect-ratio + no
  // explicit width/height on the inner iframe. The browser hasn't run
  // layout when the MutationObserver fires.
  const wrapper = document.createElement('div');
  wrapper.className = 'bricks-video';
  wrapper.style.cssText = 'aspect-ratio:16/9;width:100%;max-width:600px;display:block;';

  const iframe = document.createElement('iframe');
  iframe.src = 'https://www.youtube.com/embed/dQw4w9WgXcQ';
  // Deliberately NO width/height attrs — this is exactly what Bricks does.
  iframe.style.cssText = 'border:0;width:100%;height:100%;';

  wrapper.appendChild(iframe);
  document.body.appendChild(wrapper);

  // Capture metrics at this moment — the same state the FAZ
  // MutationObserver sees.
  const metricsAtInsertion = {
    iframeOffsetWidth:  iframe.offsetWidth,
    iframeOffsetHeight: iframe.offsetHeight,
    parentOffsetWidth:  wrapper.offsetWidth,
    parentOffsetHeight: wrapper.offsetHeight,
  };

  // Wait long enough for: (a) MutationObserver to fire,
  //                      (b) up to 3 rAF retries inside _fazAddPlaceholder,
  //                      (c) layout to fully settle.
  await new Promise(r => setTimeout(r, 1500));

  const placeholdersAfter = document.querySelectorAll('.faz-placeholder, .video-placeholder-normal, .video-placeholder-youtube').length;
  const wrapperVisibleHeight = wrapper.getBoundingClientRect().height;

  return { metricsAtInsertion, placeholdersAfter, wrapperVisibleHeight };
});

console.log('  iframe metrics at insertion (Bricks-time):',
  `w=${result.metricsAtInsertion.iframeOffsetWidth} h=${result.metricsAtInsertion.iframeOffsetHeight}`);
console.log('  parent (.bricks-video) metrics:',
  `w=${result.metricsAtInsertion.parentOffsetWidth} h=${result.metricsAtInsertion.parentOffsetHeight}`);
console.log('  wrapper visible height after layout:', result.wrapperVisibleHeight);
console.log('  placeholder elements found:', result.placeholdersAfter);

await browser.close();

if (result.placeholdersAfter > 0) {
  console.log('\n✓ PASS — placeholder was injected despite iframe having 0 metrics at observer time.');
  process.exit(0);
} else {
  console.log('\n✗ FAIL — no placeholder injected, the bug is still present.');
  process.exit(1);
}
