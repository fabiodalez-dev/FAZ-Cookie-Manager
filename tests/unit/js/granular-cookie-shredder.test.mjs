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

function loadFrontend(overrides = {}) {
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
    ...overrides,
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

// Per-cookie allow is equally authoritative: it must be able to preserve one
// cookie even when both its service and parent category are denied.
{
  const window = loadFrontend();
  setCookie(window, '_ga');
  window.fazcookie._fazConsentStore.set('analytics', 'no');
  window.fazcookie._fazConsentStore.set('svc.google-analytics', 'no');
  window.fazcookie._fazConsentStore.set('ck.google-analytics._ga', 'yes');
  window.eval('_fazRemoveAllDeadCookies()');
  check('explicit per-cookie allow overrides service and category denials', hasCookie(window, '_ga'));
}

// ── the direction that fails compliance ────────────────────────────────────
// Every block above asserts a cookie SURVIVING. None asserted the opposite, so
// the suite could not see under-deletion — and under-deletion is the direction
// that breaks the law rather than the site. Measured: replacing the gate with
// one that skips whenever no explicit "no" exists leaves the whole unit run
// green while _ga survives with an empty store, i.e. before consent.
{
  const window = loadFrontend();
  setCookie(window, '_ga');
  setCookie(window, '_fbp');
  // Deliberately nothing set: no category value, no svc.*, no ck.*. This is a
  // first visit, and absent consent for a non-necessary category is a denial.
  window.eval('_fazRemoveAllDeadCookies()');
  check('an untouched consent store deletes an analytics cookie', !hasCookie(window, '_ga'));
  check('an untouched consent store deletes a marketing cookie', !hasCookie(window, '_fbp'));
}

// An explicit category denial with no granular tokens at all must still shred.
{
  const window = loadFrontend();
  setCookie(window, '_ga');
  window.fazcookie._fazConsentStore.set('analytics', 'no');
  window.eval('_fazRemoveAllDeadCookies()');
  check('an explicitly denied category deletes its cookie', !hasCookie(window, '_ga'));
}

// ── the whitelist branch ───────────────────────────────────────────────────
// Every block ran with an empty _whitelistedCookiePatterns, so the whitelist
// check in the shredder was dead code in all of them: deleting the line left
// the suite green. The new category gate makes that line LOOK redundant, and
// removing it would shred __stripe_mid / __stripe_sid on every load for a site
// with the Stripe gateway enabled and its category denied — breaking the fraud
// detection those cookies exist for.
{
  const window = loadFrontend({ _whitelistedCookiePatterns: ['_ga'] });
  setCookie(window, '_ga');
  setCookie(window, '_fbp');
  window.fazcookie._fazConsentStore.set('analytics', 'no');
  window.fazcookie._fazConsentStore.set('marketing', 'no');
  window.eval('_fazRemoveAllDeadCookies()');
  check('a whitelisted cookie survives a denied category', hasCookie(window, '_ga'));
  check('and the whitelist does not spare its neighbours', !hasCookie(window, '_fbp'));
}

// ── the non-granular path ──────────────────────────────────────────────────
// The gate sits ABOVE the granular check, so it changed behaviour for sites
// with per-service and per-cookie consent switched off too — the default
// configuration, and the one the PR's own notes did not mention.
{
  const window = loadFrontend({ _perServiceConsent: false, _perCookieConsent: false });
  setCookie(window, 'session_cookie');
  setCookie(window, '_ga');
  window.fazcookie._fazConsentStore.set('analytics', 'yes');
  window.fazcookie._fazConsentStore.set('marketing', 'no');
  window.eval('_fazRemoveAllDeadCookies()');
  check('non-granular: an accepted category keeps its cookie', hasCookie(window, '_ga'));
  check('non-granular: a necessary cookie is never touched', hasCookie(window, 'session_cookie'));
}
{
  const window = loadFrontend({ _perServiceConsent: false, _perCookieConsent: false });
  setCookie(window, '_fbp');
  window.fazcookie._fazConsentStore.set('marketing', 'no');
  window.eval('_fazRemoveAllDeadCookies()');
  check('non-granular: a denied category still loses its cookie', !hasCookie(window, '_fbp'));
}

// ── the call site the save path actually uses ──────────────────────────────
// _fazAcceptCookies shreds by iterating the categories, not by calling
// _fazRemoveAllDeadCookies. Its comment claims explicit denials are "shredded
// immediately on save"; every assertion above went through the other entry
// point, so that claim was asserted in prose and verified nowhere. Note the
// forEach shape: it passes an index as the second argument, which a signature
// change would silently mis-bind.
{
  const window = loadFrontend();
  setCookie(window, '_ga');
  setCookie(window, '_fbp');
  setCookie(window, 'session_cookie');
  window.fazcookie._fazConsentStore.set('analytics', 'yes');
  window.fazcookie._fazConsentStore.set('marketing', 'no');
  // _fazStore is module-scoped, so the same ARRAY is reached through the config
  // it was built from. The forEach shape is what matters here: it hands an index
  // as the second argument, which a signature change would silently mis-bind.
  window.eval('(window._fazConfig._categories || []).forEach(_fazRemoveDeadCookies)');
  check('save path: accepted category keeps its cookie', hasCookie(window, '_ga'));
  check('save path: denied category loses its cookie', !hasCookie(window, '_fbp'));
  check('save path: necessary cookie untouched', hasCookie(window, 'session_cookie'));
}

// ── the GPC ordering nobody pinned ─────────────────────────────────────────
// _fazApplyGpcOptOut writes the denial into the store and THEN shreds. Reverse
// those two statements and the shredder reads the pre-opt-out state, so a
// legally binding CPPA section 7025 opt-out leaves the cookies in place. The
// entry point needs request-time settings a jsdom fixture cannot supply, so the
// ordering is pinned against the source — a weaker check than executing it, but
// far stronger than the nothing that was there.
{
  const src = readFileSync(SCRIPT_PATH, 'utf8');
  const fn = src.slice(src.indexOf('function _fazApplyGpcOptOut'));
  const body = fn.slice(0, fn.indexOf('\n\tfunction ', 1) + 1 || 4000);
  const setAt = body.indexOf('_fazSetInStore(');
  const shredAt = body.indexOf('_fazRemoveDeadCookies(');
  check('GPC: the opt-out is recorded before anything is shredded', setAt !== -1 && shredAt !== -1 && setAt < shredAt);
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
