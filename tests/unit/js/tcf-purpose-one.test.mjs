/**
 * JS unit test (jsdom) — TCF Purpose 1 must be earned, not assumed.
 *
 * Purpose 1 ("Store and/or access information on a device") is a consent-basis
 * purpose under IAB TCF. The strictly-necessary category is consent-EXEMPT
 * precisely because it does not rely on it, so deriving Purpose 1 from
 * "necessary" mints a consent the visitor never gave.
 *
 * The CMP used to do exactly that: readConsent() returns `necessary: true`
 * unconditionally, and buildPurposeConsent() read `!!categoryConsent.necessary`
 * — so every TC string carried Purpose 1 granted, from the first pre-banner
 * pageview through an explicit Reject All. An IAB validator decodes the string
 * and sees a consent record where none exists.
 *
 * These cases drive buildPurposeConsent() through the cookie, which is the only
 * input the real function has, so they fail if the derivation regresses.
 *
 * Run: node tests/unit/js/tcf-purpose-one.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/tcf-cmp.js');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
    console.log(`       expected: ${JSON.stringify(expected)}`);
    console.log(`       actual:   ${JSON.stringify(actual)}`);
  }
}

/**
 * Boot the real tcf-cmp.js in jsdom with a given consent cookie, and return the
 * purposes map it derives. `cookie` is the raw fazcookie-consent value, or null
 * for a visitor who has not answered the banner yet.
 */
function purposesFor(cookie) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;

  if (cookie !== null) {
    window.document.cookie = `fazcookie-consent=${encodeURIComponent(cookie)}`;
  }

  window._fazTcfConfig = {
    cmpId: 400,
    cmpVersion: 1,
    gvlVersion: 100,
    vendors: [],
    consentRevision: 1,
    purposeOneTreatment: false,
  };
  window.console.error = () => {};
  window.console.warn = () => {};

  window.eval(readFileSync(SCRIPT_PATH, 'utf8'));

  // The internals are not exported; drive them the way the CMP itself does, via
  // the documented __tcfapi surface, and read the purpose bits back.
  let out = null;
  window.__tcfapi('getTCData', 2, (data, ok) => {
    if (ok && data && data.purpose) out = data.purpose.consents;
  });
  return out || {};
}

function run() {
  // 1. No cookie at all: the banner has not been answered. Nothing may be
  //    presented to a vendor as consented — this is the pre-banner pageview.
  {
    const p = purposesFor(null);
    eq('first visit, no cookie: Purpose 1 is NOT granted', p['1'] === true, false);
  }

  // 2. Reject All. script.js records action:yes with every non-necessary
  //    category denied. Purpose 1 must be off — this is the case an IAB
  //    validator checks, and the one the old code got wrong most visibly.
  {
    const p = purposesFor('consentid:abc,consent:yes,action:yes,necessary:yes,functional:no,analytics:no,performance:no,marketing:no');
    eq('after Reject All: Purpose 1 is NOT granted', p['1'] === true, false);
  }

  // 3. Accept All: Purpose 1 is genuinely earned, and the purposes that need it
  //    are on. Guards the other direction — a rule that always denied would
  //    satisfy cases 1 and 2 on its own.
  {
    const p = purposesFor('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,performance:yes,marketing:yes');
    eq('after Accept All: Purpose 1 IS granted', p['1'], true);
    eq('after Accept All: a marketing purpose is granted too', p['3'], true);
  }

  // 4. Partial: one non-necessary category granted is enough to need device
  //    storage, so Purpose 1 follows the granted purpose rather than the
  //    category count.
  {
    const p = purposesFor('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:no,performance:no,marketing:no');
    eq('partial consent (functional only): Purpose 1 IS granted', p['1'], true);
    eq('partial consent: a marketing purpose stays denied', p['3'] === true, false);
  }

  // 5. The exact regression shape: necessary granted and nothing else, with an
  //    action recorded. "Strictly necessary" is consent-exempt, so it can never
  //    be the thing that earns Purpose 1.
  {
    const p = purposesFor('consentid:abc,consent:yes,action:yes,necessary:yes');
    eq('necessary alone never earns Purpose 1', p['1'] === true, false);
  }

  console.log(`\n  tcf-purpose-one: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

run();
