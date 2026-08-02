/**
 * JS unit test (jsdom) — the granular cookie shredder must fall back to the
 * category when no svc.* / ck.* override exists. Explicit granular decisions
 * remain more specific than the category.
 *
 * Loads the real frontend/js/script.js with automatic DOMContentLoaded bootstrap
 * disabled, then exercises the shipped _fazRemoveAllDeadCookies implementation.
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');

let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
  }
}

function loadFrontend() {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'https://example.test/',
  });
  const { window } = dom;
  window._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true, cookies: [{ cookieID: 'session_cookie', domain: '' }] },
      { slug: 'analytics', isNecessary: false, cookies: [{ cookieID: '_ga', domain: '' }] },
      { slug: 'marketing', isNecessary: false, cookies: [{ cookieID: '_fbp', domain: '' }] },
    ],
    _services: [
      { id: 'google-analytics', category: 'analytics', cookies: ['_ga'] },
      { id: 'facebook-pixel', category: 'marketing', cookies: ['_fbp'] },
    ],
    _providersToBlock: [],
    _cookieCategoryMap: { session_cookie: 'necessary', _ga: 'analytics', _fbp: 'marketing' },
    _whitelistedCookiePatterns: [],
    _perServiceConsent: true,
    _perCookieConsent: true,
    _rootDomain: '',
    i18n: {},
  };

  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window.eval(readFileSync(SCRIPT_PATH, 'utf8'));
  window.document.addEventListener = realAdd;
  return window;
}

function setCookie(window, name) {
  window.document.cookie = `${name}=present;path=/`;
}

function hasCookie(window, name) {
  return window.document.cookie.split(';').some((part) => part.trim().startsWith(`${name}=`));
}

console.log('granular cookie shredder category fallback (jsdom)');

// The first cleanup pass runs before first-visit defaults are written into the
// consent store. Necessary cookies must still survive that pass.
{
  const window = loadFrontend();
  setCookie(window, 'session_cookie');
  window.eval('_fazRemoveAllDeadCookies()');
  check('necessary category survives before the consent store is initialized', hasCookie(window, 'session_cookie'));
}

// This is the blocking-compliance regression: granular controls are enabled,
// Analytics is accepted, and the compact consent cookie has no redundant
// svc.google-analytics:yes token because the service follows its category.
{
  const window = loadFrontend();
  setCookie(window, '_ga');
  setCookie(window, '_fbp');
  window.fazcookie._fazConsentStore.set('analytics', 'yes');
  window.fazcookie._fazConsentStore.set('marketing', 'no');
  window.eval('_fazRemoveAllDeadCookies()');
  check('accepted category preserves its cookie without a redundant svc.* allow', hasCookie(window, '_ga'));
  check('denied category still removes its cookie without a svc.* override', !hasCookie(window, '_fbp'));
}

// Explicit service denial remains authoritative inside an accepted category.
{
  const window = loadFrontend();
  setCookie(window, '_ga');
  window.fazcookie._fazConsentStore.set('analytics', 'yes');
  window.fazcookie._fazConsentStore.set('svc.google-analytics', 'no');
  window.eval('_fazRemoveAllDeadCookies()');
  check('explicit service denial overrides accepted category', !hasCookie(window, '_ga'));
}

// Explicit service allow remains authoritative inside a denied category.
{
  const window = loadFrontend();
  setCookie(window, '_ga');
  window.fazcookie._fazConsentStore.set('analytics', 'no');
  window.fazcookie._fazConsentStore.set('svc.google-analytics', 'yes');
  window.eval('_fazRemoveAllDeadCookies()');
  check('explicit service allow overrides denied category', hasCookie(window, '_ga'));
}

// Per-cookie denial is the most-specific decision.
{
  const window = loadFrontend();
  setCookie(window, '_ga');
  window.fazcookie._fazConsentStore.set('analytics', 'yes');
  window.fazcookie._fazConsentStore.set('svc.google-analytics', 'yes');
  window.fazcookie._fazConsentStore.set('ck.google-analytics._ga', 'no');
  window.eval('_fazRemoveAllDeadCookies()');
  check('explicit per-cookie denial overrides service and category allows', !hasCookie(window, '_ga'));
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
