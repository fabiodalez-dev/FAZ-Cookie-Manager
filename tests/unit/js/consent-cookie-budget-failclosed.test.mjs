/**
 * JS unit test (jsdom) — when the consent cookie overflows its byte budget, a
 * DENIAL may never come back as an allow.
 *
 * The cookie is capped at FAZ_COOKIE_VALUE_BUDGET encoded bytes. Overflow
 * handling had a fail-closed path for service denials (downgrade the parent
 * category to "no") but none at all for per-cookie ones: `ck.*` entries were
 * appended and popped purely by size, with no distinction between `:yes` and
 * `:no`. A dropped `ck.x.y:no` simply vanished, and after reload that cookie
 * inherited its service or category — which may be "yes".
 *
 * The warning printed on overflow even claimed "Denied services that could not
 * fit fail closed", which was true only at service level. A comment promising a
 * guarantee the code does not provide is what stops the next person looking.
 *
 * Run: node tests/unit/js/consent-cookie-budget-failclosed.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');

let passed = 0;
let failed = 0;
function ok(label, cond) {
  if (cond) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
  }
}

/**
 * Boot script.js, write far more per-cookie decisions than the budget can hold,
 * and return the consent cookie that actually got persisted.
 *
 * `deniedCookie` is the one refusal whose survival is the point of the suite; it
 * is written LAST so the naive size-only loop would be the one to drop it.
 */
function overflowedCookie({ services, allows, deniedService, deniedCookie }) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;

  window._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'analytics', isNecessary: false },
      { slug: 'marketing', isNecessary: false },
    ],
    _services: services,
    _providersToBlock: [],
    _userWhitelist: [],
    i18n: {},
  };
  Object.defineProperty(window.document, 'readyState', { get: () => 'loading', configurable: true });
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (t, cb, ...r) => (t === 'DOMContentLoaded' ? undefined : realAdd(t, cb, ...r));
  window.setTimeout = () => 0;
  window.console.warn = () => {};
  window.console.error = () => {};

  window.eval(readFileSync(SCRIPT_PATH, 'utf8'));
  const set = window.fazcookie._fazSetInStore;

  set('consent', 'yes');
  set('action', 'yes');
  set('necessary', 'yes');
  // The serializer drops any entry that merely repeats its category — that is
  // deliberate size-saving, not a bug — so every entry here has to DIVERGE or it
  // never reaches the cookie at all. Two earlier drafts of this fixture got this
  // wrong in opposite directions and produced a 139-byte cookie that never
  // entered the overflow branch, so the suite passed while testing nothing.
  //
  //   marketing:no  + ck.<marketing svc>:yes  -> allows diverge, fill the budget
  //   analytics:yes + ck.matomo._pk_id:no     -> the denial diverges, and is the
  //                                              one whose survival is asserted
  set('marketing', 'no');
  set('analytics', 'yes');
  // Bulk of long ALLOW entries, to consume the budget.
  allows.forEach((key) => set(key, 'yes'));
  // The refusal, written last and therefore first to be dropped by size alone.
  if (deniedService) set(deniedService, 'no');
  if (deniedCookie) set(deniedCookie, 'no');

  window.document.addEventListener = realAdd;
  const m = window.document.cookie.match(/fazcookie-consent=([^;]+)/);
  if (!m) return '';
  try { return decodeURIComponent(m[1]); } catch (e) { return m[1]; }
}

function run() {
  // Long ids so the budget (3500 encoded bytes) is genuinely exceeded.
  const pad = (n) => `svc${String(n).padStart(3, '0')}${'x'.repeat(40)}`;
  const services = [];
  const allows = [];
  for (let i = 0; i < 70; i += 1) {
    const id = pad(i);
    services.push({ id, category: 'marketing' });
    allows.push(`ck.${id}.cookie_name_that_is_quite_long_${i}`);
  }
    // A LONG id on purpose. With a short one the denial always squeezes into
  // whatever bytes the allows leave over, so the ordering it depends on could
  // not be observed — the suite passed even with the fix mutated out.
  const MATOMO = 'matomo' + 'z'.repeat(60);
  services.push({ id: MATOMO, category: 'analytics' });

  // 1. A per-cookie DENIAL must survive the overflow in some form: either
  //    verbatim, or escalated to a denial of its service, or — last resort —
  //    as a category-level opt-out. What it must NEVER do is disappear and
  //    leave the cookie inheriting a granted decision.
  {
    const cookie = overflowedCookie({
      services,
      allows,
      deniedCookie: `ck.${MATOMO}._pk_id_long_enough_to_matter`,
    });
    const verbatim = cookie.indexOf(`ck.${MATOMO}._pk_id_long_enough_to_matter:no`) !== -1;
    const escalated = cookie.indexOf(`svc.${MATOMO}:no`) !== -1;
    const categoryClosed = /(^|,)analytics:no(,|$)/.test(cookie);
    ok(
      'an overflowing per-cookie denial is not silently lost (verbatim, escalated, or category-closed)',
      verbatim || escalated || categoryClosed,
    );
    ok(
      'and the denial never resolves to a grant',
      !(cookie.indexOf(`ck.${MATOMO}._pk_id_long_enough_to_matter:yes`) !== -1 || cookie.indexOf(`svc.${MATOMO}:yes`) !== -1),
    );
    ok('the cookie still fits the budget', cookie.length <= 4200);
  }

  // 2. The direction that must not regress: a service denial keeps its existing
  //    fail-closed downgrade.
  {
    const cookie = overflowedCookie({
      services,
      allows,
      deniedService: `svc.${MATOMO}`,
    });
    ok(
      'an overflowing service denial still fails closed',
      cookie.indexOf(`svc.${MATOMO}:no`) !== -1 || /(^|,)analytics:no(,|$)/.test(cookie),
    );
  }

  // 3. Without overflow nothing is dropped at all — the budget logic must not
  //    touch an ordinary cookie. Guards against a fix that fails closed by
  //    always denying.
  {
    const cookie = overflowedCookie({
      services: [{ id: MATOMO, category: 'analytics' }],
      allows: [],
      deniedCookie: `ck.${MATOMO}._pk_id_long_enough_to_matter`,
    });
    ok('a small cookie keeps the per-cookie denial verbatim', cookie.indexOf(`ck.${MATOMO}._pk_id_long_enough_to_matter:no`) !== -1);
    ok('and does not escalate it to a service denial it did not need', cookie.indexOf(`svc.${MATOMO}:no`) === -1);
  }

  console.log(`\n  consent-cookie-budget-failclosed: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

run();
