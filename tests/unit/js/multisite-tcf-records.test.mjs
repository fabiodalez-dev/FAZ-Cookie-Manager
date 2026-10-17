/** The complete vendor/LI record must stay with its site through A → B → A. */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM, CookieJar} from 'jsdom';
const runtime=readFileSync(new URL('../../../frontend/js/script.js',import.meta.url),'utf8');
const cmp=readFileSync(new URL('../../../frontend/js/tcf-cmp.js',import.meta.url),'utf8');
let passed=0;
const jar=new CookieJar();
const vendor={id:1,name:'Vendor',purposes:[1,3,4],legIntPurposes:[2,7],features:[]};
function visit(site) {
 const w=new JSDOM('',{runScripts:'outside-only',url:`https://${site}.network.test/`,cookieJar:jar}).window;
 const cookieNames=Object.fromEntries(['fazcookie-consent','fazVendorConsent','fazVendorLI','euconsent-v2','fazcookie-dnsmpi'].map(n=>[n,`${n}-n1-b${site==='a'?1:2}`]));
 w._fazConfig={_cookieNames:cookieNames,_siteScopeBound:true,_scopeFingerprint:'site-'+site,_bannerSlug:'main',_activeLaw:'gdpr',_rootDomain:'.network.test',_iabEnabled:true,_iabVendors:[vendor],_categories:[{slug:'necessary',isNecessary:true},{slug:'marketing',isNecessary:false}],_bannerConfig:{settings:{applicableLaw:'gdpr'}}};
 const add=w.document.addEventListener.bind(w.document);
 w.document.addEventListener=(type,...args)=>type==='DOMContentLoaded'?undefined:add(type,...args);
 w.eval(runtime);
 return w;
}
function decide(w,choice) {
 for(const [key,value] of Object.entries({'__scope.fp':w._fazConfig._scopeFingerprint,'__scope.banner':'main','__scope.law':'gdpr',action:'yes',consent:choice==='all'?'yes':'no',necessary:'yes',marketing:choice==='all'?'yes':'no'})) w.fazcookie._fazSetInStore(key,value);
 w.eval(`_fazSaveVendorConsent('${choice}')`);
}
function data(w) {
 w._fazTcfConfig={cmpId:400,gvlVersion:100,selectedVendors:[1],vendors:{1:vendor}};
 w.eval(cmp);
 let tc;w.__tcfapi('getTCData',2,(d,ok)=>{assert.ok(ok);tc=d;});return tc;
}
let a=visit('a');decide(a,'all');
assert.ok(a.document.cookie.includes('fazVendorLI-n1-b1='),'LI writes use the site cookie');++passed;
assert.equal(a.eval('_fazReadVendorConsent()[1]'),true);++passed;
a.close();
const b=visit('b');decide(b,'reject');b.close();
a=visit('a');
assert.equal(a.fazcookie._fazGetFromStore('action'),'yes','a valid vendor record must not force a new consent');++passed;
assert.equal(a.eval('_fazReadVendorConsent()[1]'),true,'the compact record is read using the site name');++passed;
assert.equal(a.eval('_fazReadVendorLI().purposes[2]'),true,'site B objections cannot overwrite A');++passed;
const tc=data(a);
assert.equal(tc.vendor.consents[1],true);++passed;
assert.equal(tc.purpose.legitimateInterests[2],true);++passed;
assert.equal(tc.vendor.legitimateInterests[1],true);++passed;
// Consent cleanup must preserve the objection cookie of another network site.
assert.equal(a.eval("_fazIsConsentCookieName('fazVendorLI-n1-b2')"),true);++passed;
a.close();
const b2=visit('b');const denied=data(b2);
assert.equal(denied.vendor.consents[1],false);++passed;
assert.equal(denied.purpose.legitimateInterests[2],false);++passed;
b2.close();
console.log(`${passed} passed, 0 failed`);
