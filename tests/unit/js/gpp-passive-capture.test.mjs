import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
const php = readFileSync(new URL('../../../frontend/class-frontend.php', import.meta.url), 'utf8');
const block = php.slice(php.indexOf('$inline_js = "document.addEventListener(\'fazcookie_consent_update\''));
const lines = block.slice(0, block.indexOf('"});";') + 7).split('\n');
const code = lines.map(l => l.match(/^[\s]*(?:\$inline_js = )?"(.*)"[ .;]*$/)?.[1] || '').join('');
let passed=0;
for (const scenario of ['missing','sync','async','throws','never','unload','unload-late']) {
 const w = new JSDOM('', {runScripts:'outside-only', url:'https://site.test/'}).window;
 const sent=[];
 w._fazConsentLog={restUrl:'/wp-json/faz/v1/consent',token:'proof'};
 w.document.cookie='fazcookie-consent=consentid:visitor123,svc.maps:no';
 w.fetch=async (url,opts)=>{sent.push({payload:JSON.parse(opts.body),opts});};
 if(scenario!=='missing') w.__gpp=(command,cb)=>{
   assert.equal(command,'ping');
   if(scenario==='throws') throw new Error('unavailable');
   if(scenario==='never' || scenario==='unload') return;
   if(scenario==='async'||scenario==='unload-late') { w.setTimeout(()=>cb({gppString:'external-gpp'},true),5); return; }
   cb({gppString:'external-gpp'},true);
 };
 w.eval(code);
 w.document.dispatchEvent(new w.CustomEvent('fazcookie_consent_update',{detail:{action:'all',accepted:['analytics']}}));
 // The original decision/identifier must survive an asynchronous CMP response.
 w.document.cookie='fazcookie-consent=;max-age=0';
 if (scenario==='unload'||scenario==='unload-late') {
   w.dispatchEvent(new w.Event('pagehide'));
   assert.equal(sent.length,1, 'unload flush starts the POST synchronously'); ++passed;
   w.dispatchEvent(new w.Event('pagehide'));
 }
 await new Promise(resolve=>setTimeout(resolve,scenario==='never'?180:20));
 assert.equal(sent.length,1); ++passed;
 assert.equal(sent[0].payload.gpp_string,['sync','async'].includes(scenario)?'external-gpp':''); ++passed;
 assert.equal(sent[0].payload.consent_id,'visitor123'); ++passed;
 assert.equal(sent[0].opts.keepalive,true); ++passed;
 w.close();
}
console.log(`${passed} passed, 0 failed`);
