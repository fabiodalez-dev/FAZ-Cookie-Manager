/**
 * PMP necessary-only state under strict geo scope validation.
 *
 * The membership alternative is generated server-side on every request and is
 * safe in every jurisdiction: only necessary remains enabled. It intentionally
 * has no consent ID or scope fingerprint. Strict fingerprint mode must retain
 * that exact state, while continuing to invalidate any consent-like forgery.
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../../frontend/js/script.js', import.meta.url), 'utf8');
let passed = 0;
let failed = 0;

function check(label, condition) {
  if (condition) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.error(`  \x1b[31mFAIL\x1b[0m ${label}`);
  }
}

function load(cookieValue) {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'https://example.test/',
  });
  const { window } = dom;
  window.document.cookie = `fazcookie-consent=${encodeURIComponent(cookieValue)}; path=/`;
  window._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'analytics', isNecessary: false },
    ],
    _bannerConfig: { settings: { applicableLaw: 'gdpr' } },
    _bannerSlug: 'eu-banner',
    _activeLaw: 'gdpr',
    _geoRouting: true,
    _strictScopeFp: true,
    _scopeFingerprint: '0123456789abcdef0123456789abcdef',
    _consentRevision: 7,
    i18n: {},
  };

  // Initialization is irrelevant to this unit; the consent store is hydrated
  // synchronously before the DOMContentLoaded callback is registered.
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...args) => (
    type === 'DOMContentLoaded' ? undefined : realAdd(type, ...args)
  );
  window.eval(source);
  window.document.addEventListener = realAdd;
  return window;
}

console.log('PMP privacy state / strict geo scope');

{
  const window = load('action:auto,consent:no,necessary:yes,analytics:no,rev:7,source:pmp');
  check('safe PMP automatic state survives missing strict fingerprint', window.fazcookie._fazGetFromStore('action') === 'auto');
  check('optional analytics remains denied', window.fazcookie._fazGetFromStore('analytics') === 'no');
}

{
  const window = load('action:yes,consent:yes,necessary:yes,analytics:yes,rev:7,source:pmp');
  check('source marker cannot exempt a consent-like forged state', window.fazcookie._fazGetFromStore('action') === '');
  check('forged optional grant is cleared by strict scope validation', window.fazcookie._fazGetFromStore('analytics') === '');
}

console.log(
  failed === 0
    ? `\n  \x1b[32m${passed} passed, 0 failed\x1b[0m`
    : `\n  \x1b[31m${passed} passed, ${failed} failed\x1b[0m`,
);
process.exit(failed === 0 ? 0 : 1);
