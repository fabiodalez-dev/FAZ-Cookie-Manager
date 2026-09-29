/** Ordered restoration regression: real frontend, controlled network completion.
 * FAZ_SCRIPT_PATH allows verification against the previous implementation.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = process.env.FAZ_SCRIPT_PATH
  || resolve(HERE, '../../../frontend/js/script.js');

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

/** Load the real script.js with its DOMContentLoaded bootstrap neutralised. */
function loadFrontend() {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  window._fazConfig = {
    _block: '1',
    _activeLaw: 'gdpr',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'analytics', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    i18n: {},
  };
  window.fazcookie = {};
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  // window.eval is the harness loading the plugin's own source into the jsdom
  // realm. Script loading/execution is controlled by the test harness.
  window.eval(code);
  window.document.addEventListener = realAdd;
  window.fazcookie._fazConsentStore.set("analytics", "yes");
  window.fazcookie._fazConsentStore.set("consent", "yes");
  return window;
}


function fixture(w) {
    w.document.body.innerHTML = `
      <script id="generic" type="text/plain" data-faz-category="analytics" src="https://example.test/generic.js" defer data-deferred="1"></script>
      <script id="before" type="text/plain" data-faz-category="analytics">window.configReady=true;</script>
      <script id="woo" type="text/plain" data-faz-category="analytics" src="https://example.test/woocommerce.js"></script>`;
    return id => w.document.getElementById(id);
}
{
    const w = loadFrontend(), get = fixture(w);
    w._fazUnblockServerSide();
    check('first external begins loading', get('generic').type === 'text/javascript');
    check('inline stays inert while dependency downloads', get('before').type === 'text/plain');
    check('dependent external stays inert', get('woo').type === 'text/plain');
    check('restored classic is not implicitly async', get('generic').async === false);
    w._fazUnblockServerSide();
    check('repeated update cannot overtake dependency', get('woo').type === 'text/plain');
    get('generic').dispatchEvent(new w.Event('load'));
    check('inline restored after dependency', get('before').type === 'text/javascript');
    check('dependent released after dependency', get('woo').type === 'text/javascript');
    const restored = get('woo');
    restored.dispatchEvent(new w.Event('load'));
    w._fazUnblockServerSide();
    check('repeat update does not execute again', get('woo') === restored);
    w.close();
}
{
    const w = loadFrontend(), get = fixture(w);
    w._fazUnblockServerSide();
    w.fazcookie._fazConsentStore.set("analytics", "no");
    get('generic').dispatchEvent(new w.Event('load'));
    check('withdrawal keeps pending inline blocked', get('before').type === 'text/plain');
    check('withdrawal keeps pending external blocked', get('woo').type === 'text/plain');
    w.fazcookie._fazConsentStore.set("analytics", "yes");
    w._fazUnblockServerSide();
    check('subsequent consent can recover waiting scripts', get('woo').type === 'text/javascript');
    w.close();
}
{
    const w = loadFrontend(), get = fixture(w);
    w._fazUnblockServerSide();
    get('generic').dispatchEvent(new w.Event('error'));
    check('failed download does not deadlock later scripts', get('woo').type === 'text/javascript');
    w.close();
}
{
    const w = loadFrontend(), get = fixture(w);
    get('generic').setAttribute('async', '');
    w._fazUnblockServerSide();
    check('explicit async is preserved', get('generic').async === true);
    check('explicit async does not hold ordered scripts', get('woo').type === 'text/javascript');
    w.close();
}
{
    const w = loadFrontend(), get = fixture(w);
    get('generic').setAttribute('data-faz-original-type', 'module');
    get('generic').removeAttribute('src');
    get('generic').textContent = 'window.moduleReady=true;';
    w._fazUnblockServerSide();
    check('inline module holds following scripts', get('before').type === 'text/plain');
    get('generic').dispatchEvent(new w.Event('load'));
    check('module completion releases followers', get('woo').type === 'text/javascript');
    w.close();
}

{
    const w = loadFrontend(), get = fixture(w);
    get('before').removeAttribute('data-faz-category');
    get('before').setAttribute('data-faz-waitfor', 'analytics');
    w._fazUnblockServerSide();
    check('waitfor inline shares the dependency queue', get('before').type === 'text/plain');
    get('generic').dispatchEvent(new w.Event('load'));
    check('waitfor inline released once in DOM order', get('before').type === 'text/javascript' && !get('before').hasAttribute('data-faz-waitfor'));
    w.close();
}
{
    const w = loadFrontend(), get = fixture(w);
    get('generic').setAttribute('data-faz-original-type', 'application/ld+json');
    w._fazUnblockServerSide();
    check('external data block cannot deadlock the queue', get('woo').type === 'text/javascript');
    w.close();
}
{
    const w = loadFrontend(), get = fixture(w);
    // jsdom does not implement noModule; model the modern browser IDL here.
    Object.defineProperty(w.HTMLScriptElement.prototype, 'noModule', { get() { return this.hasAttribute('nomodule'); } });
    get('generic').setAttribute('nomodule', '');
    w._fazUnblockServerSide();
    check('ignored nomodule fallback cannot deadlock the queue', get('woo').type === 'text/javascript');
    w.close();
}
{
    const w = loadFrontend(), get = fixture(w);
    w._fazUnblockServerSide();
    get('before').remove();
    get('generic').dispatchEvent(new w.Event('load'));
    check('removed pending node is skipped', get('woo').type === 'text/javascript');
    w.close();
}
{
    const w = loadFrontend();
    w.document.body.innerHTML = '<div class="faz-placeholder" data-faz-category="analytics"><template class="faz-placeholder-content"><script id="embedded-library" type="text/plain" data-faz-category="analytics" src="https://example.test/library.js"></script><script id="embedded-inline" type="text/plain" data-faz-category="analytics">window.embeddedReady=true;</script></template></div>';
    w._fazUnblockServerSide();
    check('template library connects before waiting for load', w.document.getElementById('embedded-library').isConnected);
    check('template inline waits for library', w.document.getElementById('embedded-inline').type === 'text/plain');
    w.document.getElementById('embedded-library').dispatchEvent(new w.Event('load'));
    check('template inline resumes after load', w.document.getElementById('embedded-inline').type === 'text/javascript');
    w.close();
}

{
    const w = loadFrontend();
    w._fazConfig._perServiceConsent = true;
    w._fazConfig._services = [{ id: 'youtube', category: 'analytics', patterns: ['youtube.com'] }];
    w.fazcookie._fazConsentStore.set('analytics', 'no');
    w.fazcookie._fazConsentStore.set('svc.youtube', 'yes');
    w.document.body.innerHTML = '<div class="faz-placeholder" data-faz-category="analytics" data-faz-service="youtube"><template class="faz-placeholder-content"><script id="service-script" type="text/plain" data-faz-category="analytics">window.serviceReady=true;</script></template></div>';
    w._fazUnblockServerSide();
    check('template carries its explicit service grant to child scripts', w.document.getElementById('service-script')?.type === 'text/javascript');
    w.close();
}

{
    const w = loadFrontend();
    w._fazConfig._categories.push({slug:'marketing', isNecessary:false});
    w._fazConfig._providersToBlock = [{re:'shared.test/library.js', categories:['analytics','marketing']}];
    w.fazcookie._fazConsentStore.set('marketing','no');
    w.document.body.innerHTML='<script id="shared" type="text/plain" data-faz-category="analytics" src="https://shared.test/library.js"></script>';
    w._fazUnblockServerSide();
    check('granting one category cannot release a shared denied provider', w.document.getElementById('shared').type === 'text/plain');
    w.fazcookie._fazConsentStore.set('marketing','yes');
    w._fazUnblockServerSide();
    check('all category grants release the shared provider', w.document.getElementById('shared').type === 'text/javascript');
    w.close();
}
{
    const w = loadFrontend();
    w.document.body.innerHTML='<div class="faz-placeholder" data-faz-category="analytics"><template class="faz-placeholder-content"><script id="tpl-lib" type="text/plain" data-faz-category="analytics" src="https://example.test/lib.js"></script></template></div><script id="outside-inline" type="text/plain" data-faz-category="analytics">window.ready=true;</script>';
    w._fazUnblockServerSide();
    check('template library holds dependent inline outside its template', w.document.getElementById('outside-inline').type === 'text/plain');
    w.document.getElementById('tpl-lib').dispatchEvent(new w.Event('load'));
    check('outside inline resumes after template library', w.document.getElementById('outside-inline').type === 'text/javascript');
    w.close();
}
{
    const w = loadFrontend(), get = fixture(w);
    const anchor = w.document.createComment('dynamic-position');
    get('before').before(anchor);
    const node = w.document.createElement('script');
    node.id='dynamic'; node.type='javascript/blocked'; node.src='https://example.test/dynamic.js';
    node.setAttribute('data-faz-category','analytics');
    w._fazConfig._backupNodes.push({position:'body',node,anchor});
    w._fazUnblock();
    check('dynamic backup waits behind preceding server library', get('dynamic').type === 'text/plain');
    get('generic').dispatchEvent(new w.Event('load'));
    check('anchored dynamic script gets its original turn', get('dynamic').type === 'text/javascript' && get('before').type === 'text/plain');
    w.fazcookie._fazConsentStore.set('analytics','no');
    get('dynamic').dispatchEvent(new w.Event('load'));
    check('withdrawal leaves later scripts inert across the mixed queue', get('woo').type === 'text/plain');
    w.close();
}

for (const mode of ['service', 'url-whitelist', 'id-whitelist']) {
    const w = loadFrontend();
    w._fazConfig._categories.push({slug:'marketing', isNecessary:false});
    w._fazConfig._providersToBlock = [{re:'shared.test/library.js', categories:['analytics','marketing']}];
    w.fazcookie._fazConsentStore.set('marketing','no');
    if (mode === 'service') {
        w._fazConfig._perServiceConsent=true;
        w._fazConfig._services=[{id:'example', category:'marketing', patterns:['shared.test/library.js']}];
        w.fazcookie._fazConsentStore.set('svc.example','yes');
    } else w._fazConfig._userWhitelist=[mode === 'url-whitelist' ? 'shared.test/library.js' : 'shared-library'];
    w.document.body.innerHTML='<script id="shared-library" type="text/plain" data-faz-category="analytics" data-faz-service="example" src="https://shared.test/library.js"></script>';
    w._fazUnblockServerSide();
    check(`${mode}: explicit exception survives overlapping category denial`, w.document.getElementById('shared-library').type === 'text/javascript');
    if (mode === 'service') check('restored script retains its service identity', w.document.getElementById('shared-library').getAttribute('data-faz-service') === 'example');
    w.close();
}
console.log(`${passed} passed, ${failed} failed`);
process.exitCode = failed ? 1 : 0;
