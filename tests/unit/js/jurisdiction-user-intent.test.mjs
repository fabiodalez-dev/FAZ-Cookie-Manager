/** Real PHP payload -> real browser consent engine, for EVERY shipped ruleset.
 * Expectations follow explicit visitor choices, not the engine's own output.
 * Both banner laws exercise geo/banner mismatch. No network or WordPress stubs
 * are used for consent decisions; only automatic DOM bootstrap is suppressed.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
const root = fileURLToPath(new URL('../../../', import.meta.url));
const payloads = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', [root + 'tests/unit/helpers/consent-ruleset-payload.php'], { encoding: 'utf8' }));
const source = readFileSync(root + 'frontend/js/script.js', 'utf8');
let passed = 0, failed = 0;
function scenario(label, fn) {
  try { fn(); passed++; }
  catch (e) { failed++; console.error(`FAIL ${label}: ${e.message}`); }
}
function open(payload, law, gpc = false, cookie = '') {
  const dom = new JSDOM('<!doctype html><body></body>', { url: 'https://consent.example.test/', runScripts: 'outside-only' });
  const w = dom.window;
  Object.defineProperty(w.navigator, 'globalPrivacyControl', { configurable: true, value: gpc });
  w._fazConfig = {
    _runtimeGeo: true, _activeLaw: law, _bannerSlug: law, _expiry: 180,
    _categories: payload.categories, _services: [{ id: 'ads', category: 'marketing' }, { id: 'stats', category: 'analytics' }],
    _providersToBlock: [], _cookieCategoryMap: {}, _whitelistedCookiePatterns: [], _userWhitelist: [],
    _perServiceConsent: true, _perCookieConsent: false, _rootDomain: '',
    _bannerConfig: { settings: { applicableLaw: law }, behaviours: { respectGPC: false } }, i18n: {},
  };
  const add = w.document.addEventListener.bind(w.document);
  w.document.addEventListener = (type, ...args) => type === 'DOMContentLoaded' ? undefined : add(type, ...args);
  if (cookie) w.document.cookie = cookie;
  w.eval(source);
  w.document.addEventListener = add;
  if (!cookie) w.eval('_fazSeedInitialState()');
  return w;
}
function state(w, slug, expected) {
  assert.equal(w.fazcookie._fazConsentStore.get(slug), expected ? 'yes' : 'no', slug + ' stored choice');
  assert.equal(w.eval(`_fazIsCategoryToBeBlocked(${JSON.stringify(slug)})`), !expected, slug + ' enforcement');
}
assert.ok(payloads.length >= 47, 'Catalogue unexpectedly lost supported jurisdictions');
for (const p of payloads) {
  for (const law of ['gdpr', 'ccpa']) {
    const label = `${p.ruleset.id}/${law}`;
    const w = open(p, law);
    try {
      scenario(label + '/initial', () => {
        assert.equal(w.document.cookie, '', 'no identifier before action');
        assert.notEqual(w.fazcookie._fazConsentStore.get('action'), 'yes', 'no affirmative action on first visit');
        for (const c of p.categories) {
          const initial = ['granted', 'granted-locked'].includes(p.ruleset.ui.default_categories[c.slug]);
          state(w, c.slug, initial);
          if (p.ruleset.model === 'opt-in' && !c.isNecessary) assert.equal(initial, false, 'opt-in requires action');
        }
      });
      scenario(label + '/malformed-grants-fail-closed', () => {
        const malformed = open(p, law, false, 'fazcookie-consent=action:yes,consent:yes,necessary:yes,functional:true,analytics:garbage,marketing:0,profiling:1;path=/');
        try {
          for (const c of p.categories.filter(c => !c.isNecessary)) {
            assert.equal(malformed.eval(`_fazIsCategoryToBeBlocked(${JSON.stringify(c.slug)})`), true, c.slug);
          }
        } finally { malformed.close(); }
      });
      scenario(label + '/accept-all', () => {
        w.eval('_fazAcceptCookies("all", true)');
        for (const c of p.categories) state(w, c.slug, c.isNecessary || !c.requiresSeparateOptIn);
        assert.match(w.document.cookie, /fazcookie-consent=/, 'explicit action persisted');
      });
      scenario(label + '/targeted-optout-preserves-unrelated-granular-choices', () => {
        const granular = open(p, law);
        try {
          granular._fazConfig._perCookieConsent = true;
          granular._fazConfig._preferenceOriginTag = 'donotsell-button';
          granular._fazConfig._services.push({ id: 'prefs', category: 'analytics' });
          granular.document.body.innerHTML = '<input id="fazCCPAOptOut" type="checkbox" checked><div hidden><input class="faz-service-toggle" data-service="stats" data-category="analytics" type="checkbox" checked><input class="faz-cookie-toggle" data-service="prefs" data-cookie-name="personalization" type="checkbox" checked></div>';
          const store = granular.fazcookie._fazConsentStore;
          store.set('analytics', 'yes'); store.set('marketing', 'yes');
          store.set('svc.stats', 'no'); store.set('ck.stats.preference', 'yes');
          store.set('ck.prefs.personalization', 'no');
          store.set('svc.ads', 'yes'); store.set('ck.ads.tracker', 'yes');
          granular.eval('_fazAcceptCookies("custom", true)');
          state(granular, 'marketing', false); state(granular, 'analytics', true);
          assert.equal(store.get('svc.stats'), 'no', 'unrelated service denial survives');
          assert.equal(store.get('ck.stats.preference'), 'yes', 'unrelated cookie preference survives');
          assert.equal(store.get('ck.prefs.personalization'), 'no', 'unrelated cookie denial survives stale hidden controls');
          assert.equal(store.has('svc.ads'), false);
          assert.equal(store.has('ck.ads.tracker'), false);
        } finally { granular.close(); }
      });
      scenario(label + '/do-not-sell-popup', () => {
        w.document.body.innerHTML = '<input id="fazCCPAOptOut" type="checkbox" checked>';
        w._fazConfig._preferenceOriginTag = 'donotsell-button';
        w.fazcookie._fazConsentStore.set('marketing', 'yes');
        w.fazcookie._fazConsentStore.set('analytics', 'yes');
        w.eval('_fazAcceptCookies("custom", true)');
        state(w, 'marketing', false);
        state(w, 'analytics', true);
      });
      w._fazConfig._preferenceOriginTag = 'settings-button';
      // All 16 combinations of the four optional categories. Each starts from
      // the preceding choice, so this also covers grants becoming revocations.
      const optional = p.categories.filter(c => !c.isNecessary);
      for (let mask = 0; mask < (1 << optional.length); mask++) {
        scenario(`${label}/save-${mask}`, () => {
          w.document.body.innerHTML = optional.map((c, i) => `<input type="checkbox" id="fazSwitch${c.slug}" ${mask & (1 << i) ? 'checked' : ''}>`).join('');
          w.eval('_fazAcceptCookies("custom", true)');
          state(w, 'necessary', true);
          optional.forEach((c, i) => state(w, c.slug, !!(mask & (1 << i))));
          const returning = open(p, law, false, w.document.cookie);
          try {
            optional.forEach((c, i) => state(returning, c.slug, !!(mask & (1 << i))));
            state(returning, 'necessary', true);
          } finally { returning.close(); }
        });
      }
      // A manually selected banner must also respect explicit toggles when
      // runtime geolocation is disabled (including the CCPA detail panel).
      const manual = open(p, law);
      try {
        manual._fazConfig._runtimeGeo = false;
        manual._fazConfig._preferenceOriginTag = 'settings-button';
        for (let mask = 0; mask < (1 << optional.length); mask++) {
          scenario(`${label}/manual-banner-save-${mask}`, () => {
            manual.document.body.innerHTML = optional.map((c, i) => `<input type="checkbox" id="fazSwitch${c.slug}" ${mask & (1 << i) ? 'checked' : ''}>`).join('');
            manual.eval('_fazAcceptCookies("custom", true)');
            state(manual, 'necessary', true);
            optional.forEach((c, i) => state(manual, c.slug, !!(mask & (1 << i))));
          });
        }
      } finally { manual.close(); }
      scenario(label + '/explicit-reject-after-grant', () => {
        w.eval('_fazAcceptCookies("reject")');
        for (const c of p.categories) state(w, c.slug, c.isNecessary);
      });
      scenario(label + '/age-gate-never-blocks-rejection', () => {
        w._fazConfig._ageGate = { enabled: true, minAge: 16 };
        w.document.body.innerHTML = '';
        assert.equal(w.eval('_fazAcceptCookies("all")'), false);
        for (const c of p.categories) state(w, c.slug, c.isNecessary);
        assert.equal(w.eval('_fazAcceptCookies("reject")'), true);
        for (const c of p.categories) state(w, c.slug, c.isNecessary);
        w._fazConfig._ageGate = { enabled: false };
      });
      scenario(label + '/dnsmpi-cannot-be-overridden-by-accept-all', () => {
        w.document.cookie = 'fazcookie-dnsmpi=1;path=/';
        w.eval('_fazAcceptCookies("all", true)');
        state(w, 'marketing', false);
        state(w, 'analytics', true);
        assert.equal(w.fazcookie._fazConsentStore.get('dnsmpi'), '1');
        w.document.cookie = 'fazcookie-dnsmpi=;max-age=0;path=/';
      });
      scenario(label + '/gpc-overrides-prior-grant', () => {
        Object.defineProperty(w.navigator, 'globalPrivacyControl', { value: true, configurable: true });
        const store = w.fazcookie._fazConsentStore;
        store.set('marketing', 'yes'); store.set('analytics', 'yes'); store.set('svc.ads', 'yes'); store.set('svc.stats', 'yes');
        w.eval('_fazApplyGpcOptOut()');
        state(w, 'marketing', false); state(w, 'analytics', true);
        assert.equal(store.has('svc.ads'), false); assert.equal(store.get('svc.stats'), 'yes');
        w.eval('_fazAcceptCookies("all", true)');
        state(w, 'marketing', false);
        Object.defineProperty(w.navigator, 'globalPrivacyControl', { value: false, configurable: true });
        w.eval('_fazApplyGpcOptOut()');
        state(w, 'marketing', false);
      });
    } finally { w.close(); }
  }
}
console.log(`jurisdiction-user-intent: ${passed} passed, ${failed} failed (${payloads.length} rulesets, both banner laws)`);
process.exitCode = failed ? 1 : 0;
