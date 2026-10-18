import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM, CookieJar } from 'jsdom';
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
  for (const name of ['readScopedCookieValue', 'isPmpNecessaryOnly', 'isSiteBindingBroken', 'isConsentCookieOutOfScope']) {
    const dependency = js.slice(js.indexOf('function ' + name + '('));
    const closing = file === 'tcf-cmp.js' ? '\n\t}' : '\n}';
    w.eval(dependency.slice(0, dependency.indexOf(closing) + closing.length));
  }
  w.eval(fn);
  assert.equal(w.isConsentCookieStale({'__scope.fp':'foreign-site'}), true); ++passed;
  assert.equal(w.isConsentCookieStale({'__scope.fp':'this-site'}), false); ++passed;
  assert.equal(w.isConsentCookieStale({}), true); ++passed;
  for (const grant of ['analytics','svc.maps','ck.maps.x','custom']) {
    assert.equal(w.isConsentCookieStale({source:'pmp',action:'auto',consent:'no',[grant]:'yes'}),true); ++passed;
  }
  w._fazConfig._geoBootstrap=true;
  assert.equal(w.isConsentCookieStale({'__scope.fp':'this-site'}), true); ++passed;
  w._fazConfig._geoBootstrapResolved='live';
  assert.equal(w.isConsentCookieStale({'__scope.fp':'this-site'}), false); ++passed;
  w.close();
}

for (const topology of ['subdirectory','subdomain']) {
 const jar=new CookieJar();
 function visit(site) {
  const url=topology==='subdirectory'?`https://network.test/${site}/`:`https://${site}.network.test/`;
  const w=new JSDOM('',{runScripts:'outside-only',url,cookieJar:jar}).window;
  const cookieNames=Object.fromEntries(['fazcookie-consent','fazVendorConsent','euconsent-v2','fazcookie-dnsmpi'].map(name=>[name,`${name}-n1-b${site==='a'?1:2}`]));
  w._fazConfig={_cookieNames:cookieNames,_siteScopeBound:true,_geoRouting:false,_scopeFingerprint:'site-'+site,_bannerSlug:'main',_activeLaw:'gdpr',_rootDomain:'.network.test',_categories:[{slug:'necessary',isNecessary:true},{slug:'analytics',isNecessary:false}],_bannerConfig:{settings:{applicableLaw:'gdpr'}}};
  const add=w.document.addEventListener.bind(w.document);
  w.document.addEventListener=(type,...args)=>type==='DOMContentLoaded'?undefined:add(type,...args);
  w.eval(source); return w;
 }
 let a=visit('a');
 for(const [key,value] of Object.entries({'__scope.fp':'site-a','__scope.banner':'main','__scope.law':'gdpr',consentid:'visitorA',analytics:'yes',action:'yes',consent:'yes'})) a.fazcookie._fazSetInStore(key,value);
 a.close();
 const b=visit('b');
 assert.equal(b.fazcookie._fazGetFromStore('action'),'','a different site cannot inherit acceptance'); ++passed;
 for(const [key,value] of Object.entries({'__scope.fp':'site-b','__scope.banner':'main','__scope.law':'gdpr',consentid:'visitorB',analytics:'no',action:'yes',consent:'no'})) b.fazcookie._fazSetInStore(key,value);
 b.close();
 a=visit('a');
 assert.equal(a.fazcookie._fazGetFromStore('action'),'yes',topology+' A → B → A keeps the original choice'); ++passed;
 assert.equal(a.fazcookie._fazGetFromStore('analytics'),'yes'); ++passed;
 assert.equal(a.getFazConsent().consentID,'visitorA'); ++passed;
 a._fazGcm={default_settings:[{analytics:'denied',marketing:'denied',functional:'denied',necessary:'granted',regions:'all'}]};
 a.eval(readFileSync(new URL('../../../frontend/js/gcm.js',import.meta.url),'utf8'));
 const update=a.dataLayer.find(args=>args[0]==='consent' && args[1]==='update');
 assert.equal(update[2].analytics_storage,'granted','GCM reads this site’s namespaced consent'); ++passed;
 a._fazTcfConfig={};
 a.eval(readFileSync(new URL('../../../frontend/js/tcf-cmp.js',import.meta.url),'utf8'));
 a.__tcfapi('getTCData',2,(data,success)=>{assert.ok(success);assert.equal(data.purpose.consents['8'],true,'TCF reads this site’s namespaced consent');++passed;});
 assert.ok(a.document.cookie.includes('euconsent-v2-n1-b1='),'TCF writes its per-site cookie'); ++passed;
 a.close();
 const b2=visit('b');
 assert.equal(b2.fazcookie._fazGetFromStore('analytics'),'no'); ++passed;
 assert.equal(b2.getFazConsent().consentID,'visitorB'); ++passed;
 b2.close();
}

{
 const w=new JSDOM('',{runScripts:'outside-only',url:'https://site.test/'}).window;
 w._fazConfig={_geoRouting:false,_siteScopeBound:false,_scopeFingerprint:'new-banner',_bannerSlug:'eu-1',_activeLaw:'gdpr',_categories:[{slug:'necessary',isNecessary:true},{slug:'marketing'}],_bannerConfig:{settings:{applicableLaw:'gdpr'}}};
 w.document.cookie='fazcookie-consent='+encodeURIComponent('action:yes,consent:yes,marketing:yes,__scope.banner:us-1,__scope.law:ccpa,__scope.fp:old-banner');
 const add=w.document.addEventListener.bind(w.document);
 w.document.addEventListener=(type,...args)=>type==='DOMContentLoaded'?undefined:add(type,...args);
 w.eval(source);
 assert.equal(w.fazcookie._fazGetFromStore('action'),'','scope validation does not depend on cache variance'); ++passed;
 assert.notEqual(w.fazcookie._fazGetFromStore('marketing'),'yes'); ++passed;
 w.close();
}
// The prefill reads a page global. A runtime that is missing, stale or
// replaced by another script can hand back null or throw; that used to abort
// attachToForm() before the submit handler was registered, so the request form
// fell back to a plain browser submit with none of its checks.
for (const [label, impl] of [['returns null', () => null], ['throws', () => { throw new Error('boom'); }], ['returns a non-string id', () => ({ consentID: 42 })]]) {
  const w = new JSDOM('<form class="faz-dsar-form"><input name="dsar_consent_id"></form>', { runScripts: 'outside-only', url: 'https://site.test/' }).window;
  w.getFazConsent = impl;
  const errors = [];
  w.addEventListener('error', (e) => errors.push(e.message));
  let thrown = '';
  try { w.eval(readFileSync(new URL('../../../frontend/js/faz-dsar.js', import.meta.url), 'utf8')); } catch (e) { thrown = e.message; }
  const form = w.document.querySelector('form');
  assert.equal(thrown, '', 'faz-dsar.js loads when getFazConsent() ' + label); ++passed;
  assert.equal(form._fazDsarAttached, true, 'the form is still attached when getFazConsent() ' + label); ++passed;
  assert.equal(form.elements.dsar_consent_id.value, '', 'and no identifier is invented when getFazConsent() ' + label); ++passed;
  let threwOnEvent = '';
  try { w.document.dispatchEvent(new w.Event('fazcookie_consent_update')); } catch (e) { threwOnEvent = e.message; }
  assert.deepEqual(errors, [], 'the consent-update listener does not throw when getFazConsent() ' + label); ++passed;
  w.close();
}
console.log(`${passed} passed, 0 failed`);
