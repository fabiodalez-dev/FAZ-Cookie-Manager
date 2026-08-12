/**
 * JS unit test (jsdom) — the public per-service consent accessors.
 *
 * Asked for on wp.org ("Call script for already accepted cookies", @migaweb):
 * the reporter wants to start an OpenStreetMap embed only if the OSM cookie
 * inside the functional category was accepted, and assumed the new
 * fazcookie_consent_ready event would tell him. It does not — that event reports
 * CATEGORIES. With per-service consent enabled a visitor can grant "functional"
 * and deny OpenStreetMap inside it, and a check on the category alone would show
 * the map to somebody who asked not to see it.
 *
 * So getFazConsent() now reports a services map, and getFazCookieConsent(name)
 * answers by cookie name using the same resolution order the blocker and the
 * shredder apply: a per-cookie override beats the per-service decision, which
 * beats the category, and the most restrictive answer wins across services.
 *
 * Why jsdom rather than Playwright: the interesting cases are per-service
 * consent OFF (the server then ships NO service list at all, so the honest
 * answer is null), a cookie declared only in _serviceCatalogue and not in
 * _services, and a per-cookie override contradicting its service. Every page on
 * the dev WordPress stack has per-service on with 19+ scanner-detected services,
 * so those states cannot be built there without wiping the scan DB.
 *
 * Run: node tests/unit/js/per-service-public-api.test.mjs   (npm run test:unit:js)
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');

let passed = 0;
let failed = 0;
function check(label, cond) {
  if (cond) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
  }
}

const CATEGORIES = [
  { slug: 'necessary', isNecessary: true },
  { slug: 'functional', isNecessary: false, name: 'Functional' },
  { slug: 'marketing', isNecessary: false, name: 'Marketing' },
];

/**
 * @param {object} config Overrides merged into _fazConfig.
 * @param {object} consent Consent store entries, e.g. { functional: 'yes', 'svc.osm': 'no' }.
 */
function loadFrontend(config = {}, consent = {}) {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;

  window._fazConfig = {
    _perServiceConsent: false,
    _perCookieConsent: false,
    _categories: CATEGORIES,
    // getFazConsent() calls _fazGetLaw(), which reads this path unconditionally.
    // Without it the single try/catch swallows a TypeError and every field comes
    // back blank — which is how the first run of this suite "failed".
    _bannerConfig: { settings: { applicableLaw: 'gdpr' } },
    i18n: {},
    ...config,
  };

  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window.eval(code);
  window.document.addEventListener = realAdd;

  // Seed the in-memory consent store directly. Writing the cookie instead would
  // drag in the whole serialiser and the 4 KB cap for no benefit here.
  // `ref` is a const inside the script (not a global); the same object is
  // reachable as window.fazcookie, which is what the other jsdom suites use.
  Object.keys(consent).forEach((k) => window.fazcookie._fazConsentStore.set(k, consent[k]));

  return window;
}

console.log('per-service public API (getFazConsent().services / getFazCookieConsent, jsdom)');

// ---------------------------------------------------------------------------
// 1 — per-service consent OFF: the plugin has no service data, and says so.
// ---------------------------------------------------------------------------
{
  const w = loadFrontend({}, { functional: 'yes', marketing: 'no' });
  const consent = w.eval('getFazConsent()');

  check(
    'per-service off: categories still reported',
    consent.categories.functional === true && consent.categories.marketing === false,
  );
  check(
    'per-service off: services map is empty rather than invented',
    consent.services && Object.keys(consent.services).length === 0,
  );
  check(
    'per-service off: getFazCookieConsent returns null, not a guess',
    w.eval("getFazCookieConsent('_osm_session')") === null,
  );
}

// ---------------------------------------------------------------------------
// 2 — the reported case: category granted, that one service denied.
// ---------------------------------------------------------------------------
{
  const w = loadFrontend(
    {
      _perServiceConsent: '1',
      _services: [
        { id: 'openstreetmap', label: 'OpenStreetMap', category: 'functional', cookies: ['_osm_session'] },
        { id: 'youtube', label: 'YouTube', category: 'functional', cookies: ['YSC'] },
      ],
    },
    { functional: 'yes', 'svc.openstreetmap': 'no' },
  );
  const consent = w.eval('getFazConsent()');

  check(
    'denied service reads false even though its category is granted',
    consent.services.openstreetmap === false,
  );
  check(
    'a sibling service with no explicit decision inherits the granted category',
    consent.services.youtube === true,
  );
  check(
    'getFazCookieConsent denies the cookie of the denied service',
    w.eval("getFazCookieConsent('_osm_session')") === false,
  );
  check(
    'getFazCookieConsent allows the cookie of the inheriting service',
    w.eval("getFazCookieConsent('YSC')") === true,
  );
  check(
    'the category itself is untouched by the per-service denial',
    consent.categories.functional === true,
  );
}

// ---------------------------------------------------------------------------
// 3 — the mirror: category denied, that one service explicitly granted.
// ---------------------------------------------------------------------------
{
  const w = loadFrontend(
    {
      _perServiceConsent: '1',
      _services: [{ id: 'openstreetmap', label: 'OSM', category: 'functional', cookies: ['_osm_session'] }],
    },
    { functional: 'no', 'svc.openstreetmap': 'yes' },
  );

  check(
    'explicit svc:yes wins over a denied category',
    w.eval('getFazConsent()').services.openstreetmap === true,
  );
  check(
    'and the cookie follows the service, not the category',
    w.eval("getFazCookieConsent('_osm_session')") === true,
  );
}

// ---------------------------------------------------------------------------
// 4 — wildcard patterns, the shape most declared cookies actually use.
// ---------------------------------------------------------------------------
{
  const w = loadFrontend(
    {
      _perServiceConsent: '1',
      _services: [{ id: 'ga', label: 'Google Analytics', category: 'marketing', cookies: ['_ga_*'] }],
    },
    { marketing: 'yes' },
  );

  check('wildcard pattern matches a concrete cookie name', w.eval("getFazCookieConsent('_ga_ABC123')") === true);
  check('an unrelated cookie is still unknown', w.eval("getFazCookieConsent('_gid')") === null);
}

// ---------------------------------------------------------------------------
// 5 — a cookie declared only in _serviceCatalogue must still resolve.
//     _services is the scanner-detected set; the catalogue is the broader
//     enforceable one, and consulting only the first would answer "unknown"
//     for a service the plugin actively blocks (#134/#146).
// ---------------------------------------------------------------------------
{
  const w = loadFrontend(
    {
      _perServiceConsent: '1',
      _services: [],
      _serviceCatalogue: {
        dailymotion: { id: 'dailymotion', label: 'Dailymotion', category: 'marketing', cookies: ['dm_cookie'] },
      },
    },
    { marketing: 'no' },
  );

  check(
    'catalogue-only service appears in the services map',
    w.eval('getFazConsent()').services.dailymotion === false,
  );
  check(
    'catalogue-only cookie resolves instead of reading as unknown',
    w.eval("getFazCookieConsent('dm_cookie')") === false,
  );
}

// ---------------------------------------------------------------------------
// 6 — per-cookie override beats its own service.
// ---------------------------------------------------------------------------
{
  const w = loadFrontend(
    {
      _perServiceConsent: '1',
      _perCookieConsent: true,
      _services: [{ id: 'ga', label: 'GA', category: 'marketing', cookies: ['_ga', '_gid'] }],
    },
    { marketing: 'yes', 'svc.ga': 'yes', 'ck.ga._gid': 'no' },
  );

  check('the un-overridden cookie of a granted service stays allowed', w.eval("getFazCookieConsent('_ga')") === true);
  check('the overridden cookie is denied despite its service', w.eval("getFazCookieConsent('_gid')") === false);
  check(
    'the service itself still reads as granted — the override is per cookie',
    w.eval('getFazConsent()').services.ga === true,
  );
}

// ---------------------------------------------------------------------------
// 7 — most restrictive wins when two services declare the same cookie.
// ---------------------------------------------------------------------------
{
  const w = loadFrontend(
    {
      _perServiceConsent: '1',
      _services: [
        { id: 'allowed', label: 'A', category: 'marketing', cookies: ['shared_cookie'] },
        { id: 'denied', label: 'B', category: 'marketing', cookies: ['shared_cookie'] },
      ],
    },
    { marketing: 'yes', 'svc.allowed': 'yes', 'svc.denied': 'no' },
  );

  check(
    'a shared cookie is denied when ANY declaring service is denied',
    w.eval("getFazCookieConsent('shared_cookie')") === false,
  );
}

// ---------------------------------------------------------------------------
// 8 — defensive inputs never throw.
// ---------------------------------------------------------------------------
{
  const w = loadFrontend(
    {
      _perServiceConsent: '1',
      _services: [{ id: 'ga', label: 'GA', category: 'marketing', cookies: ['_ga'] }],
    },
    { marketing: 'yes' },
  );

  check('empty string returns null', w.eval("getFazCookieConsent('')") === null);
  check('undefined returns null', w.eval('getFazCookieConsent(undefined)') === null);
  check('a non-string returns null', w.eval('getFazCookieConsent(42)') === null);
}

console.log(
  failed === 0
    ? `\n  \x1b[32m${passed} passed, 0 failed\x1b[0m`
    : `\n  \x1b[31m${passed} passed, ${failed} failed\x1b[0m`,
);
process.exit(failed === 0 ? 0 : 1);
