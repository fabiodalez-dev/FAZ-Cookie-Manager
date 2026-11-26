/**
 * JS unit test (jsdom) — the built-in Google tag's client-side guard.
 *
 * Under Cache Compatibility Mode and the jurisdiction shell the server cannot
 * read `svc.<id>:no` from the consent cookie, and the built-in Google tag is
 * printed at the top of <head>, before the runtime that enforces per-service
 * choices. Printed live (Advanced Consent Mode, #385 container opt-in) it ran
 * for a visitor who had switched that very service off. In that state
 * Google_Tag::render() now prints each live tag as a guard that reads the
 * consent cookie itself. This suite runs the markup PHP really generates:
 *
 *   - no explicit denial → the same scripts the server used to print live:
 *     loader with the exact src, bootstrap executed, container snippet run;
 *   - `svc.<id>:no` for THAT service → nothing runs, and the scripts are left
 *     parked in the shape the runtime restores (text/plain, data-faz-category,
 *     data-faz-service), so switching the service back on releases them;
 *   - another service's denial, a look-alike service id or a look-alike cookie
 *     name park nothing; an unreadable cookie jar parks everything;
 *   - live or parked, the created scripts are the ones the runtime's
 *     _fazOwnGoogleTagProvider() recognises as the plugin's own.
 *
 * Run: node tests/unit/js/google-tag-service-guard.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../../..');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
    console.log(`       expected: ${JSON.stringify(expected)}  actual: ${JSON.stringify(actual)}`);
  }
}

// The markup comes from the real class, with the WordPress functions it calls
// stubbed the way the PHP runners stub them.
const PHP_RENDER = `
define( 'ABSPATH', '/' );
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ) { return str_replace( '&', '&amp;', (string) $v ); }
require $argv[1] . '/includes/class-known-providers.php';
require $argv[1] . '/includes/class-google-tag.php';
$args = json_decode( $argv[2], true );
echo FazCookie\\Includes\\Google_Tag::render( array( 'G-ABCD1234', 'AW-123456789', 'GTM-ABC123' ), $args );
`;
function render(args) {
  return execFileSync('php', ['-r', PHP_RENDER, '--', ROOT, JSON.stringify(args)]).toString('utf8');
}

const LIVE = { gtag_unblocked: true, gtm_unblocked: true };
const COOKIE = 'fazcookie-consent-n1-b7';
const guarded = render({ ...LIVE, guard_cookie: COOKIE, guard_id: 'faz-cookie-manager-google-tag' });
const guardBodies = [...guarded.matchAll(/<script[^>]*data-faz-google-tag-guard="([a-z-]+)"[^>]*>([\s\S]*?)<\/script>/g)]
  .map((m) => ({ provider: m[1], body: m[2] }));

eq('PHP printed one guard per gtag group and one for the container', guardBodies.map((g) => g.provider).join(','), 'google-analytics,google-ads,google-tag-manager');
eq('and no live tag beside them', /<script(?![^>]*data-faz-google-tag-guard)[^>]*>/.test(guarded), false);

// The runtime's own recogniser, read from script.js so this suite follows it.
const runtime = readFileSync(resolve(ROOT, 'frontend/js/script.js'), 'utf8');
const LOADER_RE = /^https:\/\/www\.googletagmanager\.com\/gtag\/js\?id=([A-Z0-9-]+)(?:&l=[A-Za-z0-9_%]+)?$/;
const BOOT_RE = /^window\[("[A-Za-z0-9_$]+")\]=window\[\1\]\|\|\[\];function gtag\(\)\{window\[\1\]\.push\(arguments\);\}gtag\("js",new Date\(\)\);((?:gtag\("config","[A-Z0-9-]+"\);)+)$/;
eq('the loader shape tested here is the runtime\'s', runtime.includes(`var loader = ${LOADER_RE}.exec(src);`), true);
eq('the bootstrap shape tested here is the runtime\'s', runtime.includes(`var boot = ${BOOT_RE}.exec(body);`), true);

/**
 * Run the guards in a page whose cookie jar holds `cookies` (name → raw value).
 */
function page(cookies, { brokenJar = false, markup = guardBodies } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><head><script id="first"></script></head><body></body></html>', {
    runScripts: 'dangerously', url: 'https://example.test/a-page/',
  });
  const { window: w } = dom;
  const d = w.document;
  for (const [name, value] of Object.entries(cookies || {})) {
    d.cookie = `${name}=${value}; path=/`;
  }
  if (brokenJar) {
    Object.defineProperty(d, 'cookie', { get() { throw new Error('SecurityError'); } });
  }
  for (const g of markup) {
    const s = d.createElement('script');
    s.setAttribute('data-faz-google-tag-guard', g.provider);
    s.text = g.body;
    d.head.appendChild(s);
  }
  const own = (provider) => [...d.querySelectorAll(`script[data-faz-google-tag="${provider}"]`)];
  const state = (provider) => {
    const scripts = own(provider);
    if (!scripts.length) return 'missing';
    const parked = scripts.filter((s) => s.type === 'text/plain'
      && s.getAttribute('data-faz-service') === provider && !!s.getAttribute('data-faz-category'));
    if (parked.length === scripts.length) return 'parked';
    return scripts.some((s) => s.type === 'text/plain' || s.hasAttribute('data-faz-category') || s.hasAttribute('data-faz-service')) ? 'mixed' : 'live';
  };
  const configured = () => (Array.isArray(w.dataLayer) ? w.dataLayer : [])
    .filter((e) => e && e[0] === 'config').map((e) => e[1]).join(',');
  const containerRequested = () => !!d.querySelector('script[src^="https://www.googletagmanager.com/gtm.js?id=GTM-ABC123"]');
  const containerStarted = () => (Array.isArray(w.dataLayer) ? w.dataLayer : []).some((e) => e && e.event === 'gtm.js');
  return { w, d, own, state, configured, containerRequested, containerStarted };
}
const consent = (pairs) => encodeURIComponent(pairs.join(','));

console.log('\nno explicit denial → the tags run as the server used to print them');
{
  const p = page({});
  eq('first visit: GA4 live', p.state('google-analytics'), 'live');
  eq('first visit: Ads live', p.state('google-ads'), 'live');
  eq('first visit: container live', p.state('google-tag-manager'), 'live');
  eq('both gtag bootstraps ran', p.configured(), 'G-ABCD1234,AW-123456789');
  eq('the container snippet ran', p.containerStarted(), true);
  eq('and asked for gtm.js', p.containerRequested(), true);
  const [gaLoader, gaBoot] = p.own('google-analytics');
  eq('GA4 loader src is the exact URL', gaLoader.getAttribute('src'), 'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234');
  eq('GA4 loader is async', gaLoader.async, true);
  eq('the runtime recognises the loader as its own', LOADER_RE.test(gaLoader.getAttribute('src')), true);
  eq('the runtime recognises the bootstrap as its own', BOOT_RE.test(gaBoot.textContent.trim()), true);
  const [adsLoader, adsBoot] = p.own('google-ads');
  eq('Ads loader keeps its own ID', LOADER_RE.exec(adsLoader.getAttribute('src'))[1], 'AW-123456789');
  eq('Ads bootstrap is recognised too', BOOT_RE.test(adsBoot.textContent.trim()), true);
  p.w.close();
}
{
  const p = page({ [COOKIE]: consent(['consentid:abc', 'consent:yes', 'action:yes', 'necessary:yes', 'analytics:yes', 'marketing:no', 'svc.google-analytics:yes']) });
  eq('an explicit yes: GA4 live', p.state('google-analytics'), 'live');
  eq('category rejected but service not denied: Ads still live behind the denied default (Advanced mode)', p.state('google-ads'), 'live');
  p.w.close();
}

console.log('\nsvc.<id>:no → that tag never runs, and waits parked');
{
  const p = page({ [COOKIE]: consent(['consent:yes', 'analytics:yes', 'marketing:yes', 'svc.google-tag-manager:no']) });
  eq('container parked', p.state('google-tag-manager'), 'parked');
  eq('the container snippet did not run', p.containerStarted(), false);
  eq('gtm.js was never requested', p.containerRequested(), false);
  eq('parked under its category', p.own('google-tag-manager')[0].getAttribute('data-faz-category'), 'analytics');
  eq('the snippet is kept for the runtime to restore', /googletagmanager\.com\/gtm\.js\?id=/.test(p.own('google-tag-manager')[0].textContent), true);
  eq('GA4 unaffected', p.state('google-analytics'), 'live');
  eq('Ads unaffected', p.state('google-ads'), 'live');
  p.w.close();
}
{
  const p = page({ [COOKIE]: consent(['consent:yes', 'analytics:yes', 'svc.google-analytics:no']) });
  eq('GA4 parked although Analytics is accepted', p.state('google-analytics'), 'parked');
  eq('its bootstrap did not run', p.configured(), 'AW-123456789');
  const [loader, boot] = p.own('google-analytics');
  eq('the parked loader keeps its src for the restore', loader.getAttribute('src'), 'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234');
  eq('the parked bootstrap is still the plugin\'s own', BOOT_RE.test(boot.textContent.trim()), true);
  eq('Ads unaffected by a GA4 denial', p.state('google-ads'), 'live');
  eq('container unaffected', p.state('google-tag-manager'), 'live');
  p.w.close();
}
{
  const p = page({ [COOKIE]: consent(['svc.google-ads:no']) });
  eq('Ads parked under Marketing', p.own('google-ads').map((s) => s.getAttribute('data-faz-category')).join(','), 'marketing,marketing');
  eq('Ads bootstrap did not run', p.configured(), 'G-ABCD1234');
  p.w.close();
}
{
  // A raw, unencoded value, as older cookies and some proxies leave it.
  const p = page({ [COOKIE]: 'consent:yes,svc.google-tag-manager:no,analytics:yes' });
  eq('an unencoded cookie value is read too', p.state('google-tag-manager'), 'parked');
  p.w.close();
}

console.log('\nwhat does not count as a denial');
{
  const p = page({ [COOKIE]: consent(['svc.google-analytics-4:no', 'svc.google-tag-manager-server:no', 'xsvc.google-ads:no']) });
  eq('a longer service id is not google-analytics', p.state('google-analytics'), 'live');
  eq('nor google-tag-manager', p.state('google-tag-manager'), 'live');
  eq('nor is a key that merely ends with the service', p.state('google-ads'), 'live');
  p.w.close();
}
{
  const p = page({ 'fazcookie-consent': consent(['svc.google-tag-manager:no']), [`x${COOKIE}`]: consent(['svc.google-analytics:no']) });
  eq('another site\'s cookie (unbound name) is not this site\'s choice', p.state('google-tag-manager'), 'live');
  eq('nor is a cookie whose name merely ends with it', p.state('google-analytics'), 'live');
  p.w.close();
}

console.log('\nfail closed');
{
  const p = page({}, { brokenJar: true });
  eq('unreadable cookie jar: GA4 parked', p.state('google-analytics'), 'parked');
  eq('unreadable cookie jar: Ads parked', p.state('google-ads'), 'parked');
  eq('unreadable cookie jar: container parked', p.state('google-tag-manager'), 'parked');
  eq('nothing ran', p.configured() + '|' + p.containerStarted(), '|false');
  p.w.close();
}

console.log(`\ngoogle-tag-service-guard: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
