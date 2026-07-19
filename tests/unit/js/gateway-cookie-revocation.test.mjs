/**
 * JS unit test (jsdom) — a gateway whitelist may override the denied category
 * fallback, but it must never override an explicit per-service/per-cookie
 * revocation. This guards the payment-gateway compliance follow-up for PR #186.
 *
 * Loads the real frontend/js/script.js with automatic DOMContentLoaded bootstrap
 * disabled, then exercises the shipped _fazCleanupRevokedCookies implementation.
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

function loadFrontend({ whitelisted = true } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'https://shop.example.test/',
  });
  const { window } = dom;
  window._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'functional', isNecessary: false },
    ],
    _services: [
      {
        id: 'stripe',
        category: 'functional',
        cookies: ['__stripe_mid', '__stripe_sid'],
      },
    ],
    _providersToBlock: [],
    _cookieCategoryMap: {
      __stripe_mid: 'functional',
      __stripe_sid: 'functional',
    },
    _whitelistedCookiePatterns: whitelisted ? ['__stripe_mid', '__stripe_sid'] : [],
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

console.log('payment-gateway cookie revocation (PR #186, jsdom)');

// A gateway allowed on this request may keep its cookie when only the category
// fallback is denied; that is the purpose of the context-aware whitelist.
{
  const window = loadFrontend();
  setCookie(window, '__stripe_mid');
  window.fazcookie._fazConsentStore.set('functional', 'no');
  window.eval('_fazCleanupRevokedCookies()');
  check('gateway whitelist overrides only the denied category fallback', hasCookie(window, '__stripe_mid'));
}

// Explicit service revocation is more specific than the gateway whitelist.
{
  const window = loadFrontend();
  setCookie(window, '__stripe_mid');
  window.fazcookie._fazConsentStore.set('functional', 'no');
  window.fazcookie._fazConsentStore.set('svc.stripe', 'no');
  window.eval('_fazCleanupRevokedCookies()');
  check('explicit Stripe service denial deletes a whitelisted gateway cookie', !hasCookie(window, '__stripe_mid'));
}

// Per-cookie revocation is the most-specific decision and must also win.
{
  const window = loadFrontend();
  setCookie(window, '__stripe_mid');
  window.fazcookie._fazConsentStore.set('functional', 'yes');
  window.fazcookie._fazConsentStore.set('svc.stripe', 'yes');
  window.fazcookie._fazConsentStore.set('ck.stripe.__stripe_mid', 'no');
  window.eval('_fazCleanupRevokedCookies()');
  check('explicit Stripe cookie denial overrides service allow and gateway whitelist', !hasCookie(window, '__stripe_mid'));
}

// The same precedence applies to Web Storage cleanup.
{
  const window = loadFrontend();
  window.localStorage.setItem('__stripe_mid', 'present');
  window.fazcookie._fazConsentStore.set('svc.stripe', 'no');
  window.eval('_fazCleanupRevokedCookies()');
  check('explicit service denial removes a whitelisted matching localStorage key', window.localStorage.getItem('__stripe_mid') === null);
}

// An explicit allow still protects a non-whitelisted cookie from category fallback.
{
  const window = loadFrontend({ whitelisted: false });
  setCookie(window, '__stripe_sid');
  window.fazcookie._fazConsentStore.set('functional', 'no');
  window.fazcookie._fazConsentStore.set('svc.stripe', 'yes');
  window.eval('_fazCleanupRevokedCookies()');
  check('explicit service allow still overrides the denied category fallback', hasCookie(window, '__stripe_sid'));
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
