/**
 * JS unit test (jsdom) — wca.js must declare the WP Consent API regime from
 * the server's verdict, not from the banner's label.
 *
 * PHP's filter_wp_consent_type() answers `wp_get_consent_type` with the regime
 * actually being ENFORCED: it deliberately returns `optin` when the runtime
 * geo ruleset resolved an opt-in law for a visitor while the publisher only
 * has a CCPA banner (the fail-closed branch keeps that banner to drive
 * blocking and suppresses its UI), because the banner's law is the one thing
 * known to be wrong in exactly that state. The WP Consent API client prefers
 * `window.wp_consent_type` over the PHP-localised fallback, and wca.js used to
 * set it from `getFazConsent().activeLaw` — the banner label — so the client
 * overrode the server's correction and `wp_has_consent()` answered true for a
 * visitor the plugin itself had classified as opt-in.
 *
 * The server now ships its verdict in the store (`_fazConfig._wpConsentType`)
 * and wca.js reads it. The stricter of the two sources wins, so a wrong label
 * can never loosen the regime; with no verdict available the label mapping
 * stays as it was.
 *
 * The bridge is loaded as the real file with its dependencies faked, so what is
 * asserted is the shipped behaviour rather than a description of it.
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const WCA = resolve(HERE, '../../../frontend/js/wca.js');

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

/**
 * A jsdom window with the WP Consent API faked. `store` is what the server
 * localised as _fazConfig (undefined = no store at all).
 */
function loadWca(consent, store) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'https://example.test/',
  });
  const { window } = dom;
  window.calls = [];
  window.wp_set_consent = (cat, status) => window.calls.push(`${cat}:${status}`);
  window.getFazConsent = () => consent;
  window._fazGsk = false;
  if (store !== undefined) {
    window._fazConfig = store;
  }
  window.eval(`(function(){${readFileSync(WCA, 'utf8')}})()`);
  return window;
}

function fire(window, type, accepted) {
  window.document.dispatchEvent(new window.CustomEvent(type, { detail: { accepted } }));
}

const optInVisitorOnCcpaBanner = {
  activeLaw: 'ccpa',
  isUserActionCompleted: false,
  categories: { necessary: true, analytics: false, marketing: false },
};

console.log('wca.js — the WP Consent API regime comes from the server verdict (jsdom)');

// 1. The defect: runtime ruleset opt-in, CCPA-only install. The server says
//    optin; the banner label says ccpa. The client must not override the server.
{
  const w = loadWca(optInVisitorOnCcpaBanner, { _wpConsentType: 'optin' });
  fire(w, 'fazcookie_consent_ready', ['necessary']);
  check('a server verdict of optin wins over a ccpa banner label', w.wp_consent_type === 'optin');
  check('and the categories are still reported as denied', w.calls.includes('marketing:deny') && w.calls.includes('statistics:deny'));
}

// 2. A genuine opt-out install: the server agrees with the label.
{
  const w = loadWca(optInVisitorOnCcpaBanner, { _wpConsentType: 'optout' });
  fire(w, 'fazcookie_consent_ready', ['necessary']);
  check('a server verdict of optout on a ccpa banner stays optout', w.wp_consent_type === 'optout');
}

// 3. Stricter wins in the other direction too: a loose verdict can never
//    relax an opt-in label (defence in depth — the server never produces this
//    pair, but the rule must hold whichever source is wrong).
{
  const w = loadWca({ ...optInVisitorOnCcpaBanner, activeLaw: 'gdpr' }, { _wpConsentType: 'optout' });
  fire(w, 'fazcookie_consent_ready', ['necessary']);
  check('an optout verdict cannot loosen a gdpr label', w.wp_consent_type === 'optin');
}

// 4. No verdict shipped (older cached page, store filtered): the label
//    mapping is unchanged.
{
  const w = loadWca(optInVisitorOnCcpaBanner, {});
  fire(w, 'fazcookie_consent_ready', ['necessary']);
  check('without a verdict a ccpa label still maps to optout', w.wp_consent_type === 'optout');
}
{
  const w = loadWca({ ...optInVisitorOnCcpaBanner, activeLaw: 'gdpr' }, undefined);
  fire(w, 'fazcookie_consent_ready', ['necessary']);
  check('without a store at all a gdpr label still maps to optin', w.wp_consent_type === 'optin');
}
{
  const w = loadWca({ ...optInVisitorOnCcpaBanner, activeLaw: null }, {});
  fire(w, 'fazcookie_consent_ready', ['necessary']);
  check('an unknown law still falls back to optin', w.wp_consent_type === 'optin');
}

// 5. Only the two documented values are trusted; anything else is ignored.
{
  const w = loadWca(optInVisitorOnCcpaBanner, { _wpConsentType: 'maybe' });
  fire(w, 'fazcookie_consent_ready', ['necessary']);
  check('an unrecognised verdict is ignored, not trusted', w.wp_consent_type === 'optout');
}

// 6. The verdict is part of the duplicate-suppression signature: the same
//    state is still announced once.
{
  const w = loadWca(optInVisitorOnCcpaBanner, { _wpConsentType: 'optin' });
  w.typeEvents = 0;
  w.document.addEventListener('wp_consent_type_defined', () => { w.typeEvents += 1; });
  fire(w, 'fazcookie_consent_ready', ['necessary']);
  fire(w, 'fazcookie_consent_update', ['necessary']);
  check('an identical second event is still suppressed', w.typeEvents === 1);
}

// 7. Asserted on the source as well: the key name is the contract with
//    get_store_data().
{
  const src = readFileSync(WCA, 'utf8');
  check('wca.js reads _wpConsentType from the store', src.includes('_wpConsentType'));
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
