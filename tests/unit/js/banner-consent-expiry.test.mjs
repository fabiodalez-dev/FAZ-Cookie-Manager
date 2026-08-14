/**
 * Admin banner consent-expiry constraints.
 *
 * Loads the real banner editor script but deliberately does not execute its
 * FAZ.ready callback; the law-change listener is registered before that
 * callback and can be exercised with a minimal DOM.
 */
import fs from 'node:fs';
import vm from 'node:vm';
import { JSDOM } from 'jsdom';

const dom = new JSDOM(`<!doctype html><html><body>
  <select id="faz-b-law">
    <option value="gdpr">GDPR</option>
    <option value="ccpa">CCPA</option>
    <option value="gdpr_ccpa">Both</option>
  </select>
  <input id="faz-b-expiry" type="number" value="90">
</body></html>`, { url: 'https://example.test/wp-admin/admin.php?page=faz-cookie-manager-banner' });

const { window } = dom;
window.FAZ = { ready() {} };
window.fazConfig = { i18n: {}, languages: { selected: ['en'], default: 'en' }, lawNoticeDescriptions: {} };

const context = vm.createContext({
  window,
  document: window.document,
  location: window.location,
  URL: window.URL,
  console,
  setTimeout,
  clearTimeout,
  isFinite
});
context.FAZ = window.FAZ;
context.fazConfig = window.fazConfig;

const source = fs.readFileSync(new URL('../../../admin/assets/js/pages/banner.js', import.meta.url), 'utf8');
vm.runInContext(source, context, { filename: 'admin/assets/js/pages/banner.js' });

let failures = 0;
let checks = 0;
function same(actual, expected, label) {
  checks += 1;
  if (actual !== expected) {
    failures += 1;
    console.error(`FAIL: ${label} (expected ${expected}, got ${actual})`);
  } else {
    console.log(`PASS: ${label}`);
  }
}

const law = window.document.getElementById('faz-b-law');
const expiry = window.document.getElementById('faz-b-expiry');
const changeLaw = (value) => {
  law.value = value;
  law.dispatchEvent(new window.Event('change', { bubbles: true }));
};

changeLaw('gdpr');
same(expiry.min, '180', 'GDPR sets a 180-day minimum');
same(expiry.max, '182', 'GDPR sets a 182-day maximum');
same(expiry.value, '180', 'GDPR raises an undersized value');

expiry.value = '365';
changeLaw('gdpr_ccpa');
same(expiry.min, '180', 'Both keeps the GDPR-family minimum');
same(expiry.max, '182', 'Both keeps the GDPR-family maximum');
same(expiry.value, '182', 'Both caps an oversized value');

expiry.value = '30';
changeLaw('ccpa');
same(expiry.min, '365', 'CCPA sets a 365-day minimum');
same(expiry.max, '3650', 'CCPA retains the configured admin maximum');
same(expiry.value, '365', 'CCPA raises a value shorter than 12 months');

expiry.value = '730';
changeLaw('ccpa');
same(expiry.value, '730', 'CCPA preserves a longer valid lifetime');
same(expiry.step, '1', 'Consent expiry only accepts whole days');

if (failures) {
  console.error(`\n${failures} of ${checks} banner expiry checks failed.`);
  process.exit(1);
}
console.log(`\n${checks} banner consent expiry checks passed.`);
