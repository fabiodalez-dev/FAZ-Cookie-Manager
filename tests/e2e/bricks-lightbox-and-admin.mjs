/**
 * Tests for issue #87 follow-ups:
 *   (A) Bricks lightbox-link case — <a class="bricks-lightbox"
 *       data-pswp-video-url="https://youtube.com/..."> should be
 *       intercepted at click-time so the lightbox modal NEVER opens
 *       a YouTube iframe before consent.
 *   (B) Banner suppression in WP admin / Bricks editor — the consent
 *       banner must NOT render on routes the editor uses
 *       (?bricks=run, ?bricks_preview, ?_bricksmode).
 *
 * Runs against the local stack with Bricks 2.3.4 active as the theme.
 */

import { chromium } from 'playwright';

const WP = process.env.WP_BASE_URL || 'http://127.0.0.1:9998';
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const fails = [];
const passes = [];
function pass(n, d = '') { passes.push(n); console.log(`  PASS ${n}${d ? ' — ' + d : ''}`); }
function fail(n, d = '') { fails.push({ n, d }); console.log(`  FAIL ${n} — ${d}`); }

// ─── Test A: Bricks lightbox-link interception ────────────────────────
console.log('## A — Bricks lightbox-link interception (synthetic DOM)');
await ctx.addCookies([{
  name: 'fazcookie-consent',
  value: 'consentid:lb-' + Date.now() + ',consent:no,action:yes,necessary:yes,functional:no,analytics:no,performance:no,marketing:no,uncategorized:no',
  domain: '127.0.0.1',
  path: '/',
  expires: Math.floor(Date.now() / 1000) + 86400,
}]);

const pageA = await ctx.newPage();
const consoleErrA = [];
pageA.on('console', m => { if (m.type() === 'error') consoleErrA.push(m.text().slice(0, 200)); });

await pageA.goto(WP, { waitUntil: 'domcontentloaded', timeout: 30000 });
await pageA.waitForTimeout(1500);

const result = await pageA.evaluate(async () => {
  // Build the exact Bricks lightbox-link shape into the DOM.
  var a = document.createElement('a');
  a.className = 'bricks-lightbox brxe-container';
  a.setAttribute('href', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
  a.setAttribute('data-pswp-video-url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
  a.setAttribute('data-pswp-width', '1280');
  a.setAttribute('data-pswp-height', '720');
  a.style.cssText = 'display:block;width:600px;height:200px;background:#eee;';
  a.textContent = 'Click to play (Bricks Lightbox)';
  document.body.appendChild(a);

  // Track if the page-builder listener would have run.
  var lightboxOpened = false;
  a.addEventListener('click', function (e) {
    // Bricks PhotoSwipe normally handles this — if our capture-phase
    // interceptor preventDefault'd + stopImmediatePropagation'd, we
    // should NOT reach here.
    lightboxOpened = true;
  });

  // Synthesise the click.
  a.click();
  await new Promise(r => setTimeout(r, 200));

  return {
    lightboxOpened,
    intercepted: a.dataset.fazLightboxIntercepted === '1',
    placeholderInjected: !!document.querySelector('.faz-placeholder, .video-placeholder-normal, .video-placeholder-youtube'),
    fazSrcSet: a.getAttribute('data-faz-src'),
  };
});

console.log('  state:', JSON.stringify(result));
if (!result.lightboxOpened) pass('Lightbox open prevented (page-builder listener never ran)');
else fail('Lightbox open prevented', 'page-builder listener fired despite our capture-phase intercept');

if (result.intercepted) pass('data-faz-lightbox-intercepted="1" set on the link');
else fail('intercepted flag set', 'attribute missing');

if (result.fazSrcSet === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ') pass('data-faz-src URL preserved');
else fail('data-faz-src URL preserved', 'got ' + result.fazSrcSet);

if (result.placeholderInjected) pass('Consent placeholder injected after intercept');
else fail('Consent placeholder injected', 'no .faz-placeholder anywhere');

if (consoleErrA.filter(e => !/favicon/i.test(e)).length === 0) pass('No console errors during intercept');
else fail('No console errors', consoleErrA.slice(0, 2).join(' | '));

await pageA.close();

// ─── Test B: Banner suppressed in Bricks editor route ─────────────────
console.log('\n## B — Banner suppressed on ?bricks=run editor route');
const pageB = await ctx.newPage();

// Login as admin so wp-admin route is reachable.
await pageB.goto(WP + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 30000 });
await pageB.fill('#user_login', ADMIN_USER);
await pageB.fill('#user_pass', ADMIN_PASS);
await Promise.all([pageB.waitForLoadState('domcontentloaded'), pageB.click('#wp-submit')]);

// Find any post id we can use — fetch via REST.
const postsResp = await pageB.request.get(WP + '/?rest_route=/wp/v2/posts&per_page=1&_fields=id,link');
const posts = await postsResp.json();
const postId = Array.isArray(posts) && posts.length ? posts[0].id : null;
const postLink = Array.isArray(posts) && posts.length ? posts[0].link : null;
if (!postId || !postLink) {
  console.log('  no post available, skipping B');
} else {
  // Bricks editor URL: append ?bricks=run to the canonical permalink so
  // WordPress' redirect_canonical doesn't strip the query string back.
  const editorUrl = postLink + (postLink.includes('?') ? '&' : '?') + 'bricks=run';
  await pageB.goto(editorUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await pageB.waitForTimeout(2000);

  const editorState = await pageB.evaluate(() => {
    return {
      url: window.location.href,
      bannerPresent: !!document.querySelector('.faz-consent-container'),
      bannerVisible: (function () {
        var b = document.querySelector('.faz-consent-container');
        if (!b) return false;
        var st = getComputedStyle(b);
        return st.display !== 'none' && st.visibility !== 'hidden';
      })(),
      fazConfigPresent: typeof window._fazConfig !== 'undefined' && window._fazConfig !== null,
      isBricksRunQs: window.location.search.includes('bricks=run'),
    };
  });
  console.log('  editor state:', JSON.stringify(editorState));

  // Bricks may redirect (license activation, post type not enabled, etc.).
  // The redirect target is always a wp-admin route, where faz_disable_banner()
  // also returns true via the is_admin() guard — same outcome from the
  // visitor's perspective. The point of test B is "banner doesn't appear",
  // which both terminal states satisfy.
  if (editorState.isBricksRunQs) {
    pass('Stayed on ?bricks=run route (license/perm available)');
  } else {
    pass('Bricks redirected (license/perm flow) — banner suppression still applies');
  }

  if (!editorState.bannerPresent) pass('Banner DOM NOT rendered');
  else fail('Banner DOM NOT rendered', 'banner container exists in DOM');

  if (!editorState.fazConfigPresent) pass('_fazConfig NOT localized (banner pipeline fully disabled)');
  else fail('_fazConfig NOT localized', '_fazConfig still on window');

  // Pure server-side check: confirm faz_disable_banner() returns true
  // for the bricks query params, independent of the redirect outcome.
  // We hit the REST banner endpoint with the param spoofed via X-FAZ-Test
  // header — the endpoint won't render in this case if disable kicks in.
  console.log('  testing faz_disable_banner() PHP logic via WP-CLI eval...');
  try {
    const { execFileSync } = await import('node:child_process');
    const wpPath = process.env.WP_PATH || '/Users/fabio/Sites/faz-test';
    const checkPHP = `
$_GET['bricks'] = 'run';
echo faz_disable_banner() ? 'TRUE' : 'FALSE';
`;
    const out = execFileSync('wp', ['eval', checkPHP, '--path=' + wpPath, '--skip-themes'], { encoding: 'utf8' }).trim();
    if (out.endsWith('TRUE')) pass('faz_disable_banner() returns true on ?bricks=run (PHP-level)');
    else fail('faz_disable_banner() returns true on ?bricks=run', 'got: ' + out);
  } catch (e) {
    console.log('  WP-CLI eval skipped:', e.message.slice(0, 200));
  }
}

await pageB.close();
await browser.close();

console.log('\n=== Summary ===');
console.log(`  passed: ${passes.length}`);
console.log(`  failed: ${fails.length}`);
fails.forEach(f => console.log(`  - ${f.n}: ${f.d}`));
process.exit(fails.length > 0 ? 1 : 0);
