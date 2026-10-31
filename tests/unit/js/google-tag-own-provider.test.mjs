/** The plugin's own Google Ads loader is judged by its own provider (#371 review).
 *
 * Google_Tag::render() parks the Ads/Floodlight loader as
 * gtag/js?id=AW-… under marketing / google-ads. That URL also matches the
 * google-analytics catalogue pattern `googletagmanager.com/gtag/js`, and the
 * restore check ORed in that URL match, so a visitor who accepted Marketing and
 * rejected Analytics never got the Ads loader. The markup below is copied from
 * render()'s real output; the catalogue entries are the shipped ones.
 *
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
  if (condition) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

const BOOT = (id) => `window["dataLayer"]=window["dataLayer"]||[];function gtag(){window["dataLayer"].push(arguments);}gtag("js",new Date());gtag("config","${id}");`;
const RENDERED = ''
  + '<script id="ga-loader" type="text/plain" data-faz-category="analytics" data-faz-service="google-analytics" async src="https://www.googletagmanager.com/gtag/js?id=G-ABCD1234" data-faz-google-tag="google-analytics"></script>'
  + `<script id="ga-boot" type="text/plain" data-faz-category="analytics" data-faz-service="google-analytics" data-faz-google-tag="google-analytics">${BOOT('G-ABCD1234')}</script>`
  + '<script id="ads-loader" type="text/plain" data-faz-category="marketing" data-faz-service="google-ads" async src="https://www.googletagmanager.com/gtag/js?id=AW-123456789" data-faz-google-tag="google-ads"></script>'
  + `<script id="ads-boot" type="text/plain" data-faz-category="marketing" data-faz-service="google-ads" data-faz-google-tag="google-ads">${BOOT('AW-123456789')}</script>`;

function page(consent, { perService = false, markup = RENDERED } = {}) {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM(`<!DOCTYPE html><html><head>${markup}</head><body></body></html>`, {
    runScripts: 'outside-only', url: 'http://localhost/',
  });
  const { window: w } = dom;
  w._fazConfig = {
    _block: '1', _activeLaw: 'gdpr',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'analytics', isNecessary: false },
      { slug: 'marketing', isNecessary: false },
    ],
    _services: perService ? [
      { id: 'google-analytics', category: 'analytics', cookies: [] },
      { id: 'google-ads', category: 'marketing', cookies: [] },
    ] : [],
    _providersToBlock: [
      { re: 'googletagmanager.com/gtag/js', categories: ['analytics'], service: 'google-analytics' },
      { re: 'googleadservices.com/pagead', categories: ['marketing'], service: 'google-ads' },
    ],
    _userWhitelist: [], _perServiceConsent: perService, _perCookieConsent: false, i18n: {},
  };
  w.fazcookie = {};
  const realAdd = w.document.addEventListener.bind(w.document);
  w.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  w.eval(code);
  w.document.addEventListener = realAdd;
  for (const [k, v] of Object.entries(consent)) w.fazcookie._fazConsentStore.set(k, v);
  w._fazUnblockServerSide();
  return w;
}
const live = (w, id) => {
  const el = w.document.getElementById(id);
  return !!el && el.type !== 'text/plain' && el.type !== 'javascript/blocked';
};

console.log('own Google tag judged by its own provider (#371 review, jsdom)');
{
  const w = page({ consent: 'yes', marketing: 'yes', analytics: 'no' });
  check('Marketing yes, Analytics no: the Ads loader is released', live(w, 'ads-loader'));
  check('Marketing yes, Analytics no: the Ads bootstrap is released', live(w, 'ads-boot'));
  check('Marketing yes, Analytics no: the GA4 loader stays parked', !live(w, 'ga-loader') && !live(w, 'ga-boot'));
  w.close();
}
{
  const w = page({ consent: 'yes', marketing: 'no', analytics: 'yes' });
  check('Analytics yes, Marketing no: GA4 released, Ads parked', live(w, 'ga-loader') && !live(w, 'ads-loader') && !live(w, 'ads-boot'));
  w.close();
}
{
  const w = page({ consent: 'yes', marketing: 'yes', analytics: 'yes', 'svc.google-ads': 'no' }, { perService: true });
  check('per-service: an explicit google-ads denial keeps the Ads loader parked although Marketing is granted', !live(w, 'ads-loader'));
  check('per-service: …and GA4 still runs', live(w, 'ga-loader'));
  w.close();
}
{
  const w = page({ consent: 'yes', marketing: 'yes', analytics: 'yes', 'svc.google-analytics': 'no' }, { perService: true });
  check('per-service: a google-analytics denial no longer drags the Ads loader down', live(w, 'ads-loader'));
  check('per-service: …while GA4 stays parked', !live(w, 'ga-loader'));
  w.close();
}
{
  // A script that borrows the marker but is not render()'s markup keeps the URL match.
  const forged = '<script id="forged" type="text/plain" data-faz-category="marketing" data-faz-service="google-ads" async src="https://www.googletagmanager.com/gtag/js?id=G-ABCD1234" data-faz-google-tag="google-ads"></script>';
  const w = page({ consent: 'yes', marketing: 'yes', analytics: 'no' }, { markup: forged });
  check('a GA4 loader labelled as Ads is still held by the google-analytics match', !live(w, 'forged'));
  w.close();
}

console.log(`\ngoogle-tag-own-provider: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
