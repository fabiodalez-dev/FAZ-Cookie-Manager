import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');
const SBJS_SRC = 'https://shop.example/wp-content/plugins/woocommerce/assets/js/sourcebuster/sourcebuster.min.js';
const OTHER_SRC = 'https://www.googletagmanager.com/gtag/js?id=G-TEST';

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

function loadFrontend(staticConfig) {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'https://shop.example/landing/?utm_source=test',
  });
  const { window } = dom;
  window._fazConfig = {
    _block: '1',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'functional', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    i18n: {},
  };
  window.fazcookie = { _fazGetFromStore: () => undefined };
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window._fazStaticConfig = staticConfig;
  window.eval(code);
  window.document.addEventListener = realAdd;
  return window;
}


const parked = '<div class="faz-social-placeholder" data-faz-category="functional" data-faz-service="google-maps"></div><div id="map" class="brxe-map faz-hidden" data-faz-category="functional" data-faz-service="google-maps" data-faz-bricks-map-options="{}"></div>';
const w = loadFrontend();
w._fazConfig._providersToBlock = [{ re: 'maps.googleapis.com', categories: ['functional'], fullPath: false }];
w.document.body.innerHTML = parked;
let calls = 0;
w.google = { maps: {} };
w.bricksData = { googleMapInstances: {} };
w.bricksMap = () => { calls++; w.bricksData.googleMapInstances.map = {}; };
w.eval('_fazUnblockServerSide()');
eq('denied: placeholder remains', !!w.document.querySelector('.faz-social-placeholder'), true);
eq('denied: map options stay inert', w.document.getElementById('map').hasAttribute('data-bricks-map-options'), false);
eq('denied: builder does not run', calls, 0);
w.fazcookie._fazConsentStore.set('functional', 'yes');
w.fazcookie._fazConsentStore.set('consent', 'yes');
w.eval('_fazUnblockServerSide()');
eq('accepted: placeholder removed', !!w.document.querySelector('.faz-social-placeholder'), false);
eq('accepted: options restored', w.document.getElementById('map').getAttribute('data-bricks-map-options'), '{}');
eq('accepted: map visible', w.document.getElementById('map').classList.contains('faz-hidden'), false);
eq('accepted: already loaded builder is called', calls, 1);
w.eval('_fazUnblockServerSide()');
eq('repeat restoration does not initialise twice', calls, 1);
w.close();

const late = loadFrontend();
late._fazConfig._providersToBlock = [{ re: 'maps.googleapis.com', categories: ['functional'], fullPath: false }];
late.document.body.innerHTML = parked;
late.fazcookie._fazConsentStore.set('functional', 'yes');
late.fazcookie._fazConsentStore.set('consent', 'yes');
late.eval('_fazUnblockServerSide()');
late.google = { maps: {} };
let lateCalls = 0;
late.bricksMap = () => { lateCalls++; };
const script = late.document.createElement('script');
script.id = 'bricks-map-js';
late.document.body.appendChild(script);
script.dispatchEvent(new late.Event('load'));
eq('delayed builder loads after Google: map is initialised', lateCalls, 1);
late.close();

const merged = loadFrontend({ _cookieCategoryMap: { test: 'functional' }, _block: '0' });
eq('static configuration merged before runtime starts', merged._fazConfig._cookieCategoryMap.test, 'functional');
eq('dynamic configuration keeps precedence', merged._fazConfig._block, '1');
merged.close();
console.log(`${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
