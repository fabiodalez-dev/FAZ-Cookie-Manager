/** A queued vendor can query the CMP before a deferred geo lookup finishes. */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
const code=readFileSync(new URL('../../../frontend/js/tcf-cmp.js',import.meta.url),'utf8');
let passed=0;
for(const [name,scope,config,granted] of [
 ['matching scope',',__scope.banner:eu-1,__scope.law:gdpr,__scope.fp:eu',{},true],
 ['foreign scope without country source',',__scope.banner:us-1,__scope.law:ccpa,__scope.fp:us',{},false],
 ['pending geographic scope',',__scope.banner:eu-1,__scope.law:gdpr,__scope.fp:eu',{_geoBootstrap:true},false],
 ['resolved matching scope',',__scope.banner:eu-1,__scope.law:gdpr,__scope.fp:eu',{_geoBootstrap:true,_geoBootstrapResolved:'live'},true],
 ['resolved foreign scope',',__scope.banner:us-1,__scope.law:ccpa,__scope.fp:us',{_geoBootstrap:true,_geoBootstrapResolved:'live'},false],
 ['pre-scope legacy record','',{},true],
]) {
 const w=new JSDOM('',{runScripts:'outside-only',url:'https://site.test/'}).window;
 w._fazConfig={_geoRouting:false,_bannerSlug:'eu-1',_activeLaw:'gdpr',_scopeFingerprint:'eu',...config};
 w._fazTcfConfig={cmpId:400,gvlVersion:100,selectedVendors:[1],vendors:{1:{name:'Vendor',purposes:[1,3],legIntPurposes:[]}}};
 w.document.cookie='fazcookie-consent='+encodeURIComponent('action:yes,consent:yes,necessary:yes,marketing:yes'+scope);
 w.document.cookie='fazVendorConsent=1:yes';
 w.eval(code);
 w.__tcfapi('getTCData',2,(d,ok)=>{assert.ok(ok);assert.equal(d.vendor.consents[1]===true,granted,name+' vendor');assert.equal(d.purpose.consents[3]===true,granted,name+' purpose');passed+=3;});
 w.close();
}
console.log(`${passed} passed, 0 failed`);
