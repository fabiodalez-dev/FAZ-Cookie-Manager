import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
const source = readFileSync(new URL('../../../frontend/js/script.js', import.meta.url), 'utf8');
let passed = 0;
for (const fp of ['', 'foreign-site', 'this-site']) {
  const dom = new JSDOM('<div><div data-faz-tag="detail-powered-by"></div></div>', { runScripts: 'outside-only', url: 'https://site.test/' });
  const w = dom.window;
  w._fazConfig = { _siteScopeBound: true, _geoRouting: false, _scopeFingerprint: 'this-site', _categories: [{slug:'necessary', isNecessary:true}, {slug:'analytics', isNecessary:false}], _bannerConfig:{settings:{applicableLaw:'gdpr'}}, _bannerSlug:'main', _activeLaw:'gdpr', _i18n:{consent_id:'ID consenso'} };
  w.document.cookie = 'fazcookie-consent=' + encodeURIComponent(`action:yes,consent:yes,consentid:visitor123,analytics:yes,__scope.banner:main,__scope.law:gdpr,__scope.fp:${fp}`);
  const add = w.document.addEventListener.bind(w.document);
  w.document.addEventListener = (type, ...args) => type === 'DOMContentLoaded' ? undefined : add(type, ...args);
  w.eval(source);
  assert.equal(w.fazcookie._fazGetFromStore('action'), fp === 'this-site' ? 'yes' : ''); ++passed;
  w.eval('_fazSetPoweredBy()');
  const line = w.document.querySelector('[data-faz-tag="detail-consent-id"]');
  // Invalidated consent preserves its identifier for accountability.
  assert.ok(line.querySelector('input').readOnly); ++passed;
  w.fazcookie._fazSetInStore('consentid', 'newConsent123');
  assert.equal(line.hidden, false); ++passed;
  assert.equal(line.querySelector('input').value, 'newConsent123'); ++passed;
  const form = w.document.createElement('form'); form.className='faz-dsar-form';
  form.innerHTML='<input name="dsar_consent_id">'; w.document.body.append(form);
  w.eval(readFileSync(new URL('../../../frontend/js/faz-dsar.js', import.meta.url), 'utf8'));
  assert.equal(form.elements.dsar_consent_id.value, 'newConsent123'); ++passed;
  form.elements.dsar_consent_id.value='manuallySupplied';
  w.document.dispatchEvent(new w.Event('fazcookie_consent_update'));
  assert.equal(form.elements.dsar_consent_id.value, 'manuallySupplied'); ++passed;
  w.close();
}
{
 const w = new JSDOM('<div data-faz-tag="detail"><div class="faz-preference-body-wrapper"></div></div>', {runScripts:'outside-only',url:'https://site.test/'}).window;
 w._fazConfig={_categories:[],_bannerConfig:{settings:{}},_i18n:{}};
 const add=w.document.addEventListener.bind(w.document);
 w.document.addEventListener=(type,...args)=>type==='DOMContentLoaded'?undefined:add(type,...args);
 w.eval(source); w.eval('_fazSetPoweredBy()');
 const line=w.document.querySelector('[data-faz-tag="detail-consent-id"]');
 assert.ok(line, 'identifier exists even when powered-by branding is disabled'); ++passed;
 assert.equal(line.hidden,true,'identifier hidden before the first choice'); ++passed;
 w.fazcookie._fazSetInStore('consentid','first-choice');
 assert.equal(line.hidden,false); ++passed;
 w.close();
}
for (const file of ['gcm.js', 'tcf-cmp.js']) {
  const js = readFileSync(new URL('../../../frontend/js/' + file, import.meta.url), 'utf8');
  const start = js.indexOf('function isConsentCookieStale(');
  const tail = js.slice(start);
  const fn = tail.slice(0, tail.indexOf('\n' + (file === 'tcf-cmp.js' ? '\t}' : '}')) + (file === 'tcf-cmp.js' ? 3 : 2));
  const w = new JSDOM('', {runScripts:'outside-only'}).window;
  w._fazConfig={_siteScopeBound:true, _scopeFingerprint:'this-site'};
  w.eval(fn);
  assert.equal(w.isConsentCookieStale({'__scope.fp':'foreign-site'}), true); ++passed;
  assert.equal(w.isConsentCookieStale({'__scope.fp':'this-site'}), false); ++passed;
  assert.equal(w.isConsentCookieStale({}), true); ++passed;
  w._fazConfig._geoBootstrap=true;
  assert.equal(w.isConsentCookieStale({'__scope.fp':'this-site'}), true); ++passed;
  w._fazConfig._geoBootstrapResolved='live';
  assert.equal(w.isConsentCookieStale({'__scope.fp':'this-site'}), false); ++passed;
  w.close();
}
console.log(`${passed} passed, 0 failed`);
