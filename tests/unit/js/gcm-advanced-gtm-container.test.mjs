/**
 * JS unit test (jsdom) — the Google Tag Manager container under Advanced
 * Consent Mode (#385).
 *
 * Advanced Consent Mode (#165) lets gtag.js / GA4 / Ads load before consent
 * behind the denied `consent default`, but keeps the GTM container (gtm.js)
 * blocked because it can run tags that ignore Consent Mode. Sites that set up
 * GA4 and Google Ads conversions INSIDE Tag Manager therefore lost every
 * cookieless ping (issue #385). The opt-in `advanced_mode_gtm` setting reaches
 * the browser as `_gcmAdvancedGtm`; this suite pins the client half:
 *
 *   - _fazIsGtmContainer() is host + exact path, the same test as the server's
 *     is_gtm_container_script(): look-alike hosts, the name in a query string,
 *     the noscript frame and the gtag.js library are not the container;
 *   - the container loads before consent only with Advanced mode AND the
 *     opt-in, and an explicit svc.google-tag-manager:no still blocks it;
 *   - with the opt-in off nothing changes: gtag.js is exempt, gtm.js is not;
 *   - the real container snippet, run in the page, has its gtm.js script left
 *     live or parked accordingly by the createElement interceptor.
 *
 * FAZ_SCRIPT_PATH allows verification against the previous implementation.
 *
 * Run: node tests/unit/js/gcm-advanced-gtm-container.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = process.env.FAZ_SCRIPT_PATH
  || resolve(HERE, '../../../frontend/js/script.js');

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

const GTM = 'https://www.googletagmanager.com/gtm.js?id=GTM-ABC123';
const GTAG = 'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234';

function page({ advanced = true, gtm = true, perService = false, consent = {} } = {}) {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><head><script id="anchor"></script></head><body></body></html>', {
    runScripts: 'outside-only', url: 'https://example.test/a-page/',
  });
  const { window: w } = dom;
  w._fazConfig = {
    _block: '1', _activeLaw: 'gdpr',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'analytics', isNecessary: false },
      { slug: 'marketing', isNecessary: false },
    ],
    _services: perService ? [
      { id: 'google-analytics', category: 'analytics', cookies: [] },
      { id: 'google-tag-manager', category: 'analytics', cookies: [] },
    ] : [],
    // The shipped catalogue entries for the two Google loaders.
    _providersToBlock: [
      { re: 'googletagmanager.com/gtag/js', categories: ['analytics'], service: 'google-analytics' },
      { re: 'googletagmanager.com/gtm.js', categories: ['analytics'], service: 'google-tag-manager' },
      { re: 'googletagmanager.com/ns.html', categories: ['analytics'], service: 'google-tag-manager' },
    ],
    _userWhitelist: [], _perServiceConsent: perService, _perCookieConsent: false, i18n: {},
    _gcmAdvanced: advanced,
  };
  // Emitted by the server only when true, exactly like here.
  if (gtm) w._fazConfig._gcmAdvancedGtm = true;
  w.fazcookie = {};
  const realAdd = w.document.addEventListener.bind(w.document);
  w.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  w.eval(code);
  w.document.addEventListener = realAdd;
  for (const [k, v] of Object.entries(consent)) w.fazcookie._fazConsentStore.set(k, v);
  return w;
}
const call = (w, expr) => {
  try {
    return w.eval(expr);
  } catch (e) {
    return `threw: ${e.message}`;
  }
};
const isGtm = (w, u) => call(w, `_fazIsGtmContainer(${JSON.stringify(u)})`);
const blocks = (w, u) => call(w, `_fazShouldBlockResource("", ${JSON.stringify(u)}, "")`);
const changes = (w, u) => call(w, `_fazShouldChangeType(document.createElement("div"), ${JSON.stringify(u)})`);

/**
 * Run the standard GTM container snippet (the shape Google_Tag::render() and
 * every pasted copy use) and report whether the gtm.js script it inserts is
 * left runnable.
 */
function snippetLoadsContainer(w) {
  w.eval('(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});'
    + 'var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";'
    + 'j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);'
    + '})(window,document,"script","dataLayer","GTM-ABC123");');
  const el = Array.prototype.find.call(w.document.scripts, (s) => (s.getAttribute('src') || s.getAttribute('data-faz-src') || s.src || '').indexOf('gtm.js') !== -1);
  if (!el) return 'no script inserted';
  const type = (el.getAttribute('type') || '').toLowerCase();
  return type !== 'text/plain' && type !== 'javascript/blocked' && !el.hasAttribute('data-faz-src');
}

console.log('\n_fazIsGtmContainer — host and exact path\n');
{
  const w = page();
  eq('the container loader', isGtm(w, GTM), true);
  eq('protocol-relative container URL', isGtm(w, '//www.googletagmanager.com/gtm.js?id=GTM-ABC123'), true);
  eq('upper-case spelling', isGtm(w, 'HTTPS://WWW.GOOGLETAGMANAGER.COM/GTM.JS?id=GTM-ABC123'), true);
  eq('the noscript frame is not the container', isGtm(w, 'https://www.googletagmanager.com/ns.html?id=GTM-ABC123'), false);
  eq('gtag.js is not the container', isGtm(w, GTAG), false);
  eq('a look-alike host', isGtm(w, 'https://googletagmanager.com.evil.example/gtm.js?id=GTM-X'), false);
  eq('a host that merely ends with the name', isGtm(w, 'https://notgoogletagmanager.com/gtm.js?id=GTM-X'), false);
  eq('the URL in a query string', isGtm(w, 'https://tracker.example/x.js?u=https://www.googletagmanager.com/gtm.js'), false);
  eq('another path on the host', isGtm(w, 'https://www.googletagmanager.com/gtm.js/extra'), false);
  eq('a first-party server-side container is not matched', isGtm(w, 'https://example.test/gtm.js?id=GTM-ABC123'), false);
  w.close();
}

console.log('\nAdvanced + container opt-in: gtm.js loads before consent\n');
{
  const w = page({ advanced: true, gtm: true });
  eq('resource gate releases gtm.js', blocks(w, GTM), false);
  eq('createElement gate releases gtm.js', changes(w, GTM), false);
  eq('gtag.js is still released', blocks(w, GTAG), false);
  eq('the noscript frame stays blocked', blocks(w, 'https://www.googletagmanager.com/ns.html?id=GTM-ABC123'), true);
  eq('a look-alike container host is not exempted', call(w, '_fazIsAdvancedConsentExempt("https://googletagmanager.com.evil.example/gtm.js")'), false);
  eq('the real container snippet inserts a live gtm.js', snippetLoadsContainer(w), true);
  w.close();
}

console.log('\nopt-in off, or Advanced off: the container waits as before\n');
{
  const w = page({ advanced: true, gtm: false });
  eq('opt-in off: resource gate blocks gtm.js', blocks(w, GTM), true);
  eq('opt-in off: createElement gate blocks gtm.js', changes(w, GTM), true);
  eq('opt-in off: gtag.js is still released', blocks(w, GTAG), false);
  eq('opt-in off: the container snippet is parked', snippetLoadsContainer(w), false);
  w.close();
}
{
  // The server never emits _gcmAdvancedGtm without _gcmAdvanced, but the
  // client must not read the flag on its own either.
  const w = page({ advanced: false, gtm: true });
  eq('Advanced off: resource gate blocks gtm.js', blocks(w, GTM), true);
  eq('Advanced off: createElement gate blocks gtm.js', changes(w, GTM), true);
  eq('Advanced off: gtag.js is blocked too', blocks(w, GTAG), true);
  w.close();
}

console.log('\nan explicit denial of the Tag Manager service still wins\n');
{
  const w = page({ advanced: true, gtm: true, perService: true, consent: { 'svc.google-tag-manager': 'no' } });
  eq('svc.google-tag-manager:no → resource gate blocks gtm.js', blocks(w, GTM), true);
  // The snippet's script carries no data-faz-service, so the createElement
  // gate has to resolve the service from the URL itself.
  eq('svc.google-tag-manager:no → createElement gate blocks gtm.js', changes(w, GTM), true);
  eq('svc.google-tag-manager:no → the container snippet is parked', snippetLoadsContainer(w), false);
  eq('…and gtag.js is unaffected', blocks(w, GTAG), false);
  w.close();
}
{
  const w = page({ advanced: true, gtm: true, perService: true, consent: { 'svc.google-analytics': 'no' } });
  eq('a denial of another service does not hold the container', changes(w, GTM), false);
  w.close();
}

console.log(`\ngcm-advanced-gtm-container: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
