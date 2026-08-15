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

// Same wording and the same two placeholders the view ships in data-template.
// Without this element in the fixture the whole notice branch was dead code
// under test: the clamp could stop announcing itself and nothing would notice.
const HINT_TEMPLATE = 'Consent lifetime changed from %1$d to %2$d days to stay within the selected regulation.';

const dom = new JSDOM(`<!doctype html><html><body>
  <select id="faz-b-law">
    <option value="gdpr">GDPR</option>
    <option value="ccpa">CCPA</option>
    <option value="gdpr_ccpa">Both</option>
  </select>
  <input id="faz-b-expiry" type="number" value="90">
  <div id="faz-b-expiry-hint" style="display:none" role="status" data-template="${HINT_TEMPLATE}"></div>
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
const hint = window.document.getElementById('faz-b-expiry-hint');
const changeLaw = (value) => {
  law.value = value;
  law.dispatchEvent(new window.Event('change', { bubbles: true }));
};

// Six months is a cap, not a floor. A shorter re-prompt is more protective, so
// the editor must offer it and must not raise it. These assertions previously
// demanded a 180-day minimum and so encoded the defect.
changeLaw('gdpr');
same(expiry.min, '1', 'GDPR imposes no minimum — a shorter lifetime is stricter, not invalid');
same(expiry.max, '182', 'GDPR sets a 182-day maximum');
// The fixture ships 90 days. Under the old floor this became 180; it must now
// survive untouched, because that is a publisher choice and not an error.
same(expiry.value, '90', 'GDPR leaves an already-short value alone');
same(hint.style.display, 'none', 'nothing moved, so no notice is shown');

expiry.value = '30';
changeLaw('gdpr');
same(expiry.value, '30', 'A deliberate 30-day re-prompt survives a law re-selection');

expiry.value = '365';
changeLaw('gdpr_ccpa');
same(expiry.min, '1', 'Both inherits the GDPR-family cap-only rule');
same(expiry.max, '182', 'Both keeps the GDPR-family maximum');
same(expiry.value, '182', 'Both caps an oversized value');
// A rewrite under the cursor has to say so, and has to say which number it
// replaced — that is the whole reason the field is allowed to move at all.
same(hint.style.display, '', 'the clamp notice becomes visible');
same(
  hint.textContent.trim(),
  'Consent lifetime changed from 365 to 182 days to stay within the selected regulation.',
  'the notice names the entered lifetime and the one it was clamped to'
);

expiry.value = '30';
changeLaw('ccpa');
same(expiry.min, '365', 'CCPA sets a 365-day minimum');
same(expiry.max, '3650', 'CCPA retains the configured admin maximum');
same(expiry.value, '365', 'CCPA raises a value shorter than 12 months');
same(
  hint.textContent.trim(),
  'Consent lifetime changed from 30 to 365 days to stay within the selected regulation.',
  'a second clamp replaces the numbers instead of appending to them'
);
same(hint.textContent.indexOf('%1$d'), -1, 'no placeholder survives into the rendered notice');

expiry.value = '730';
changeLaw('ccpa');
same(expiry.value, '730', 'CCPA preserves a longer valid lifetime');
same(expiry.step, '1', 'Consent expiry only accepts whole days');
// Left standing, the previous notice would keep reporting 30 → 365 about a
// field that now reads 730: a stale explanation is worse than none.
same(hint.style.display, 'none', 'a clamp that did not happen clears the previous notice');
same(hint.textContent, '', 'and clears its text with it');

if (failures) {
  console.error(`\n${failures} of ${checks} banner expiry checks failed.`);
  process.exit(1);
}
console.log(`\n${checks} banner consent expiry checks passed.`);
