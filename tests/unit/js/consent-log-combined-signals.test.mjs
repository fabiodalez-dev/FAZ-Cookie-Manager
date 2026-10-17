/** Site cookies, fresh TC proof, external GPP, token recovery and unload coexist. */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
const php=readFileSync(new URL('../../../frontend/class-frontend.php',import.meta.url),'utf8');
const block=php.slice(php.indexOf('$inline_js = "document.addEventListener(\'fazcookie_consent_update\''));
const code=block.slice(0,block.indexOf('"});";')+7).split('\n').map(l=>l.match(/^[\s]*(?:\$inline_js = )?"(.*)"[ .;]*$/)?.[1]||'').join('');
const w=new JSDOM('',{runScripts:'outside-only',url:'https://site.test/'}).window;
const names=Object.fromEntries(['fazcookie-consent','fazVendorConsent','fazVendorLI','euconsent-v2'].map(n=>[n,n+'-n1-b2']));
w._fazConfig={_cookieNames:names};
w._fazConsentLog={restUrl:'/consent',token:'stale',tokenUrl:'/refresh',cookieNames:names};
w._fazTcfConfig={cmpId:400,gvlVersion:100,selectedVendors:[1],vendors:{1:{name:'Vendor',purposes:[1,3],legIntPurposes:[2]}}};
const posts=[];let refreshes=0;let gppDelay=2;
w.__gpp=(command,cb)=>{assert.equal(command,'ping');w.setTimeout(()=>cb({gppString:'external-gpp'},true),gppDelay);};
w.fetch=async(url,opts)=>{
 if(url.startsWith('/refresh')){++refreshes;return {json:async()=>({success:true,data:{consent:'fresh'}})};}
 const body=JSON.parse(opts.body);posts.push(body);assert.equal(opts.keepalive,true);
 return body.token==='stale'?{status:403,json:async()=>({code:'invalid_token'})}:{status:200};
};
w.eval(code);w.eval(readFileSync(new URL('../../../frontend/js/tcf-cmp.js',import.meta.url),'utf8'));
function cookie(name,value){w.document.cookie=names[name]+'='+encodeURIComponent(value);}
function read(name){const prefix=names[name]+'=';const c=w.document.cookie.split(';').map(s=>s.trim()).find(s=>s.startsWith(prefix));return c?decodeURIComponent(c.slice(prefix.length)):'';}
function choose(action){
 const yes=action==='all';
 cookie('fazcookie-consent',`consentid:decision-id,action:yes,consent:${yes?'yes':'no'},necessary:yes,marketing:${yes?'yes':'no'}`);
 cookie('fazVendorConsent','1:'+(yes?'yes':'no'));
 cookie('fazVendorLI','p2:'+(yes?'yes':'no')+',1:'+(yes?'yes':'no'));
 w.document.dispatchEvent(new w.CustomEvent('fazcookie_consent_update',{detail:{action,accepted:yes?['necessary','marketing']:['necessary'],rejected:yes?[]:['marketing']}}));
}
choose('all');const acceptedTc=read('euconsent-v2');assert.ok(acceptedTc);
// GPP returns asynchronously; the row must retain the ID of this decision.
cookie('fazcookie-consent','consentid:a-later-cookie,action:yes,necessary:yes,marketing:yes');
await new Promise(r=>setTimeout(r,30));
assert.equal(posts.length,2);assert.equal(refreshes,1);
for(const row of posts){assert.equal(row.consent_id,'decision-id');assert.equal(row.tc_string,acceptedTc);assert.equal(row.gpp_string,'external-gpp');assert.equal(row.status,'accepted');assert.equal(row.categories.marketing,'yes');}
assert.equal(posts[0].can_refresh,1);assert.equal(posts[1].can_refresh,undefined);assert.equal(posts[1].token,'fresh');
gppDelay=30;choose('reject');assert.equal(read('euconsent-v2'),'');
w.dispatchEvent(new w.Event('pagehide'));
assert.equal(posts.length,3,'withdrawal flush starts synchronously before unload');
w.dispatchEvent(new w.Event('pagehide'));
await new Promise(r=>setTimeout(r,45));
assert.equal(posts.length,3,'a late CMP callback cannot duplicate the withdrawal');
const rejected=posts[2];
assert.equal(rejected.consent_id,'decision-id');assert.equal(rejected.status,'rejected');assert.equal(rejected.categories.marketing,'no');assert.equal(rejected.tc_string,'');assert.equal(rejected.gpp_string,'');
assert.equal(rejected.token,'fresh');assert.equal(refreshes,1);
w.close();
console.log('Combined signal assertions passed');
