/**
 * JS unit test (jsdom) — the consent cookie is stored on a host that the
 * configured cookie domain does not cover.
 *
 * wp.org support topic "Cookie Banner Not Working on GTranslate Translation
 * Proxy Domains": GTranslate's paid Translation Proxy serves the Czech origin
 * dilnastore.cz as dilnastore.com / .pl / .de. It rewrites every full URL in
 * the HTML but not the bare `_rootDomain` (".dilnastore.cz", subdomain sharing
 * on), so the runtime wrote `fazcookie-consent=…; domain=.dilnastore.cz` on
 * dilnastore.com. A browser silently rejects a cookie whose Domain does not
 * cover the current host (RFC 6265 §5.3 step 6): the consent was never stored,
 * the banner came back on every page and nothing was ever released.
 *
 * jsdom enforces the same rule through tough-cookie, so the jar is the oracle:
 * on the translated host the cookie must land host-only and read back; on a
 * real subdomain of the configured scope the Domain attribute must be kept.
 *
 * Run: node tests/unit/js/cookie-domain-proxy-host.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const CODE = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');

let passed = 0;
let failed = 0;
function ok(label, cond, detail) {
  if (cond) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}${detail !== undefined ? `\n       actual: ${JSON.stringify(detail)}` : ''}`);
  }
}

/**
 * Boot script.js on `url` with `rootDomain` as the localised cookie scope.
 * DOMContentLoaded is swallowed so the banner never initialises: only the
 * store and the cookie writers are under test.
 */
function boot(url, rootDomain) {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', {
    runScripts: 'outside-only',
    url,
  });
  const { window } = dom;
  window._fazConfig = {
    _rootDomain: rootDomain,
    _expiry: 180,
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'analytics', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    i18n: {},
  };
  const infos = [];
  window.console.info = (msg) => { infos.push(String(msg)); };
  window.console.warn = () => {};
  window.console.error = () => {};
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (t, cb, ...r) => (t === 'DOMContentLoaded' ? undefined : realAdd(t, cb, ...r));
  window.eval(CODE);
  window.document.addEventListener = realAdd;
  return { dom, window, infos };
}

function consentCookie(dom, url) {
  return dom.cookieJar.getCookiesSync(url).find((c) => c.key === 'fazcookie-consent') || null;
}

console.log('\n== translated domain outside the configured scope (dilnastore.com, scope .dilnastore.cz) ==');
{
  const { dom, window, infos } = boot('https://dilnastore.com/', '.dilnastore.cz');
  const ref = window.fazcookie;
  ref._fazSetInStore('consentid', 'abcdef0123456789');
  ref._fazSetInStore('consent', 'yes');
  ref._fazSetInStore('action', 'yes');
  ref._fazSetInStore('analytics', 'yes');

  const jar = consentCookie(dom, 'https://dilnastore.com/');
  ok('the consent cookie is stored on the translated host', !!jar, window.document.cookie);
  ok('it is a host-only cookie (no foreign Domain attribute)', !!jar && jar.hostOnly === true, jar && { domain: jar.domain, hostOnly: jar.hostOnly });
  ok('document.cookie round-trips the consent', window.document.cookie.indexOf('fazcookie-consent=') !== -1, window.document.cookie);
  const back = ref._fazGetCookie('fazcookie-consent') || '';
  ok('the runtime reads its own choice back', back.indexOf('action:yes') !== -1 && back.indexOf('analytics:yes') !== -1, back);
  ok('_diag() reports the configured scope', ref._diag().rootDomain === '.dilnastore.cz', ref._diag().rootDomain);
  ok('_diag() reports the effective (host-only) cookie domain', ref._diag().cookieDomain === '', ref._diag().cookieDomain);
  ok('the host-only fallback is explained once per page view, not per write', infos.length === 1 && /outside the configured cookie domain/.test(infos[0]), infos);
}

console.log('\n== subdomain of the configured scope (shop.dilnastore.cz) ==');
{
  const { dom, window, infos } = boot('https://shop.dilnastore.cz/', '.dilnastore.cz');
  const ref = window.fazcookie;
  ref._fazSetInStore('consentid', 'abcdef0123456789');
  ref._fazSetInStore('action', 'yes');

  const jar = consentCookie(dom, 'https://shop.dilnastore.cz/');
  ok('the consent cookie is stored', !!jar, window.document.cookie);
  ok('the Domain attribute is kept (subdomain sharing still works)', !!jar && jar.hostOnly === false && jar.domain === 'dilnastore.cz', jar && { domain: jar.domain, hostOnly: jar.hostOnly });
  ok('a sibling host of the scope sees the shared cookie', !!consentCookie(dom, 'https://www.dilnastore.cz/'));
  ok('_diag() reports the shared domain as effective', ref._diag().cookieDomain === '.dilnastore.cz', ref._diag().cookieDomain);
  ok('no host-only notice inside the scope', infos.length === 0, infos);
}

console.log('\n== the apex of the scope and the no-sharing default ==');
{
  const { dom, window } = boot('https://dilnastore.cz/', '.dilnastore.cz');
  window.fazcookie._fazSetInStore('action', 'yes');
  const jar = consentCookie(dom, 'https://dilnastore.cz/');
  ok('the apex keeps the Domain attribute', !!jar && jar.hostOnly === false, jar && { domain: jar.domain, hostOnly: jar.hostOnly });
}
{
  const { dom, window, infos } = boot('https://dilnastore.com/', '');
  window.fazcookie._fazSetInStore('action', 'yes');
  const jar = consentCookie(dom, 'https://dilnastore.com/');
  ok('with subdomain sharing off the cookie is host-only as before', !!jar && jar.hostOnly === true);
  ok('and nothing is reported, since no scope was configured', infos.length === 0, infos);
}

console.log('\n== _fazCookieDomainForHost() boundaries ==');
{
  const { window } = boot('https://example.test/', '');
  const fn = window._fazCookieDomainForHost;
  ok('is a top-level function (old-WebKit rule)', typeof fn === 'function');
  ok('apex matches', fn('.example.com', 'example.com') === '.example.com');
  ok('subdomain matches', fn('.example.com', 'a.b.example.com') === '.example.com');
  ok('a scope written without the leading dot matches too', fn('example.com', 'www.example.com') === 'example.com');
  ok('matching is case-insensitive and ignores a trailing dot', fn('.Example.COM', 'WWW.example.com.') === '.Example.COM');
  ok('a label-boundary lookalike does not match', fn('.example.com', 'badexample.com') === '');
  ok('another registrable domain does not match', fn('.example.cz', 'example.com') === '');
  ok('a sub-scope does not cover its parent', fn('.shop.example.com', 'example.com') === '');
  ok('an empty scope is host-only', fn('', 'example.com') === '' && fn('.', 'example.com') === '');
}

console.log(`\n  cookie-domain-proxy-host: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
