/**
 * Repro + regression guard for Advanced Consent Mode (#165).
 *
 * Contract:
 *   Advanced ON  → the gtag-direct stack (gtag.js / GA4 / Ads) loads before
 *                  consent (NOT converted to type="text/plain"), a synchronous
 *                  denied `consent default` is printed inline in <head>, and the
 *                  GTM container (gtm.js) + non-Google trackers stay blocked.
 *   Advanced OFF → everything is hard-blocked as before (Basic mode), and no
 *                  inline consent default is emitted.
 *
 * Drives wp-cli to flip faz_gcm_settings, renders a fixture page carrying the
 * four script types with no consent cookie, asserts the rendered HTML, then
 * drives Chromium with a controlled gtag.js mock so the test can prove:
 *   - pre-consent config emits a cookieless G100 collect beacon with no _ga;
 *   - accept-all emits a granted G111 collect beacon and then sets _ga;
 *   - dynamically-injected gtag is allowed only in Advanced mode;
 *   - dynamically-injected GTM and Facebook stay blocked before consent.
 * Restores GCM-off and deletes the fixture page on exit.
 *
 * Run: WP_BASE_URL=http://127.0.0.1:9998 WP_PATH=/Users/fabio/Sites/faz-test \
 *      node tests/e2e/gcm-advanced-mode.mjs
 */

import { execFileSync } from 'node:child_process';
import { chromium } from '@playwright/test';

const WP = process.env.WP_BASE_URL || 'http://127.0.0.1:9998';
const WP_PATH = process.env.WP_PATH || '/Users/fabio/Sites/faz-test';
const SLUG = 'gcm-advanced-test';
const GTAG_ID = 'G-TESTADV1';
const DYN_GTAG_ID = 'G-DYNADV1';

function wp(args) {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}
function setGcm(status, advanced) {
  wp(['eval', `$s=get_option('faz_gcm_settings',array());$s['status']=${status?'true':'false'};$s['advanced_mode']=${advanced?'true':'false'};update_option('faz_gcm_settings',$s);do_action('faz_clear_cache');`]);
  wp(['db', 'query', "DELETE FROM wp_options WHERE option_name LIKE 'faz_banner_template%'"]);
}
async function fetchPage() {
  const res = await fetch(`${WP}/${SLUG}/`, { redirect: 'follow' });
  return res.text();
}

let failures = 0;
function assert(name, cond) { console.log(`  ${cond ? 'PASS' : 'FAIL'} ${name}`); if (!cond) failures++; }

const controlledGtagMock = `
(function(){
  window.dataLayer = window.dataLayer || [];
  var consent = {};
  function merge(next) {
    next = next || {};
    for (var key in next) {
      if (Object.prototype.hasOwnProperty.call(next, key)) consent[key] = next[key];
    }
  }
  function gcs() {
    return consent.ad_storage === 'granted' && consent.analytics_storage === 'granted' ? 'G111' : 'G100';
  }
  function collect(reason) {
    var img = new Image();
    img.src = 'https://www.google-analytics.com/g/collect?v=2&tid=${GTAG_ID}&reason=' + encodeURIComponent(reason) + '&gcs=' + gcs() + '&z=' + Math.random();
  }
  function handle(args) {
    if (!args || !args.length) return;
    if (args[0] === 'consent' && (args[1] === 'default' || args[1] === 'update')) {
      merge(args[2]);
      if (args[1] === 'update' && gcs() === 'G111') {
        document.cookie = '_ga=GA1.1.1234567890.1234567890;path=/;max-age=3600';
        collect('consent_update');
      }
      return;
    }
    if (args[0] === 'config') {
      collect('config');
    }
  }
  var queued = window.dataLayer.slice();
  var originalPush = window.dataLayer.push.bind(window.dataLayer);
  window.dataLayer.push = function(){
    for (var i = 0; i < arguments.length; i++) {
      handle(arguments[i]);
    }
    return originalPush.apply(window.dataLayer, arguments);
  };
  window.gtag = function(){
    window.dataLayer.push(Array.prototype.slice.call(arguments));
  };
  queued.forEach(handle);
})();`;

function requestSeen(requests, needle) {
  return requests.some((url) => url.includes(needle));
}

function collectSeen(requests, gcs) {
  return requests.some((url) => url.includes('google-analytics.com/g/collect') && url.includes(`gcs=${gcs}`));
}

async function runBrowserContract(advanced) {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  const requests = [];
  page.on('request', (req) => requests.push(req.url()));

  await page.route('https://www.googletagmanager.com/gtag/js**', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/javascript',
      body: controlledGtagMock,
    });
  });
  await page.route('https://www.google-analytics.com/g/collect**', (route) => {
    route.fulfill({ status: 204, body: '' });
  });
  await page.route('https://www.googletagmanager.com/gtm.js**', (route) => {
    route.fulfill({ status: 204, body: '' });
  });
  await page.route('https://connect.facebook.net/**', (route) => {
    route.fulfill({ status: 204, body: '' });
  });

  await page.goto(`${WP}/${SLUG}/`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(500);

  assert(
    advanced ? 'browser requested gtag.js before consent' : 'browser did not request gtag.js in Basic mode',
    requestSeen(requests, `googletagmanager.com/gtag/js?id=${GTAG_ID}`) === advanced
  );
  assert('browser did not request the GTM container before consent', !requestSeen(requests, 'googletagmanager.com/gtm.js?id=GTM-TESTADV1'));
  assert('browser did not request the Facebook tracker before consent', !requestSeen(requests, 'connect.facebook.net/en_US/fbevents.js'));

  if (advanced) {
    assert('controlled gtag emitted pre-consent collect with gcs=G100', collectSeen(requests, 'G100'));
    assert('pre-consent collect did not create _ga', !(await context.cookies()).some((cookie) => cookie.name === '_ga'));
  } else {
    assert('Basic mode emitted no pre-consent collect beacon', !collectSeen(requests, 'G100') && !collectSeen(requests, 'G111'));
  }

  await page.evaluate((dynGtagId) => {
    [
      ['dyn-gtag', 'https://www.googletagmanager.com/gtag/js?id=' + dynGtagId],
      ['dyn-gtm', 'https://www.googletagmanager.com/gtm.js?id=GTM-DYNADV1'],
      ['dyn-fb', 'https://connect.facebook.net/en_US/fbevents.js?dyn=1'],
    ].forEach(([id, src]) => {
      var script = document.createElement('script');
      script.id = id;
      script.src = src;
      document.head.appendChild(script);
    });
  }, DYN_GTAG_ID);
  await page.waitForTimeout(500);
  assert(
    advanced ? 'dynamic gtag.js is allowed in Advanced mode' : 'dynamic gtag.js is blocked in Basic mode',
    requestSeen(requests, `googletagmanager.com/gtag/js?id=${DYN_GTAG_ID}`) === advanced
  );
  assert('dynamic GTM container remains blocked before consent', !requestSeen(requests, 'googletagmanager.com/gtm.js?id=GTM-DYNADV1'));
  assert('dynamic Facebook tracker remains blocked before consent', !requestSeen(requests, 'connect.facebook.net/en_US/fbevents.js?dyn=1'));

  if (advanced) {
    await page.evaluate(() => {
      var button = document.querySelector('[data-faz-tag="accept-button"]');
      if (!button) throw new Error('accept button not found');
      button.click();
    });
    await page.waitForTimeout(1000);
    assert('accept-all emitted granted collect with gcs=G111', collectSeen(requests, 'G111'));
    assert('post-consent granted collect created _ga', (await context.cookies()).some((cookie) => cookie.name === '_ga'));
  }

  await browser.close();
}

// Fixture: a page with the four script types, inserted with kses disabled so
// the raw <script> tags survive into the rendered output.
const setupPhp = `kses_remove_filters();
$c = <<<HTML
<p>GCM Advanced test (#165).</p>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','${GTAG_ID}');</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=${GTAG_ID}"></script>
<script src="https://www.googletagmanager.com/gtm.js?id=GTM-TESTADV1"></script>
<script src="https://connect.facebook.net/en_US/fbevents.js"></script>
HTML;
$e = get_page_by_path('${SLUG}', OBJECT, 'page');
$a = array('post_title'=>'GCM Advanced Test','post_name'=>'${SLUG}','post_status'=>'publish','post_type'=>'page','post_content'=>$c);
if ($e) { $a['ID']=$e->ID; echo wp_update_post($a); } else { echo wp_insert_post($a); }`;

const gtagBlocked = (h) => /<script[^>]*gtag\/js[^>]*type=["']text\/plain["']/.test(h);
function scriptTags(html) {
  return html.match(/<script\b[\s\S]*?<\/script>/gi) || [];
}

const inlineGtagBlocked = (h) => scriptTags(h).some((tag) => {
  const opening = tag.slice(0, tag.indexOf('>') + 1);
  return !/\ssrc\s*=/i.test(opening)
    && /\stype=["']text\/plain["']/i.test(opening)
    && tag.includes(`gtag('config','${GTAG_ID}')`);
});
const gtmBlocked = (h) => /<script[^>]*gtm\.js[^>]*type=["']text\/plain["']/.test(h);
const fbBlocked = (h) => /<script[^>]*fbevents[^>]*type=["']text\/plain["']/.test(h);
const hasInlineDefault = (h) => /gtag\('consent','default',\{[^}]*"ad_storage":"denied"/.test(h);
const inlineDefaultBeforeGtag = (h) => {
  const defaultIndex = h.indexOf("gtag('consent','default'");
  const configIndex = h.indexOf(`gtag('config','${GTAG_ID}')`);
  const scriptIndex = h.indexOf(`googletagmanager.com/gtag/js?id=${GTAG_ID}`);
  return defaultIndex > -1 && configIndex > -1 && scriptIndex > -1
    && defaultIndex < configIndex && defaultIndex < scriptIndex;
};

try {
  wp(['eval', setupPhp]);

  console.log('## Advanced ON');
  setGcm(true, true);
  let html = await fetchPage();
  assert('synchronous denied consent default printed inline in <head>', hasInlineDefault(html));
  assert('inline consent default appears before the gtag snippet', inlineDefaultBeforeGtag(html));
  assert('inline gtag bootstrap/config executes before consent', !inlineGtagBlocked(html));
  assert('gtag.js loads before consent (NOT type=text/plain)', !gtagBlocked(html));
  assert('GTM container (gtm.js) still blocked', gtmBlocked(html));
  assert('non-Google tracker (facebook) still blocked', fbBlocked(html));
  await runBrowserContract(true);

  console.log('## Advanced OFF (Basic mode — no regression)');
  setGcm(true, false);
  html = await fetchPage();
  assert('inline gtag bootstrap/config hard-blocked again', inlineGtagBlocked(html));
  assert('gtag.js hard-blocked again', gtagBlocked(html));
  assert('no inline consent default emitted', !hasInlineDefault(html));
  await runBrowserContract(false);
} finally {
  try { setGcm(false, false); } catch { /* ignore */ }
  try { wp(['post', 'delete', String(wp(['eval', `$p=get_page_by_path('${SLUG}',OBJECT,'page');echo $p?$p->ID:0;`])), '--force']); } catch { /* ignore */ }
}

console.log(`\n=== ${failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'} ===`);
process.exit(failures === 0 ? 0 : 1);
