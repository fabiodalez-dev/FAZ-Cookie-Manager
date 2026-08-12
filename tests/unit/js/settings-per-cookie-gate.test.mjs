/**
 * Per-cookie consent UI gate (jsdom unit test).
 *
 * Settings::sanitize() forces banner_control.per_cookie_consent off whenever
 * per_service_consent is off. The settings screen used to leave the child
 * toggle ungated, so an admin could tick it, get the generic "Settings saved
 * successfully." toast, and keep looking at an enabled switch whose value the
 * server had discarded.
 *
 * This loads the REAL admin settings script and drives its applyShowIf() over a
 * DOM built from the real markup in admin/views/settings.php, asserting the two
 * halves of the fix: the group is hidden while the parent is off, and its
 * checkbox is unticked in lockstep so nothing stale is ever submitted or
 * resurrected. It also pins the blast radius — an ordinary data-show-if group
 * must NOT have its checkboxes cleared.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { JSDOM } from 'jsdom';

const settingsPath = new URL('../../../admin/assets/js/pages/settings.js', import.meta.url);
let source = readFileSync(settingsPath, 'utf8');
source = source.replace(
  /\n\}\)\(\);\s*$/,
  '\n\twindow.__fazTestApplyShowIf = applyShowIf;\n})();\n',
);

const dom = new JSDOM('<!doctype html><body></body>');
const sandbox = {
  console,
  window: dom.window,
  document: dom.window.document,
  FAZ: { ready() {} },
};
vm.createContext(sandbox);
vm.runInContext(source, sandbox, { filename: settingsPath.pathname });

const applyShowIf = sandbox.window.__fazTestApplyShowIf;
assert.equal(typeof applyShowIf, 'function', 'real settings.js applyShowIf should be exposed to the VM');

let passed = 0;
const check = (label, fn) => { fn(); passed++; console.log(`  ✓ ${label}`); };

/** Build the parent/child pair exactly as admin/views/settings.php renders it. */
function buildForm({ serviceOn, cookieOn }) {
  const form = dom.window.document.createElement('form');
  form.innerHTML = `
    <div class="faz-form-group">
      <label class="faz-toggle">
        <input type="checkbox" data-path="banner_control.per_service_consent">
      </label>
    </div>
    <div class="faz-form-group" data-show-if="banner_control.per_service_consent" data-clear-when-hidden>
      <label class="faz-toggle">
        <input type="checkbox" data-path="banner_control.per_cookie_consent">
      </label>
    </div>`;
  const service = form.querySelector('[data-path="banner_control.per_service_consent"]');
  const cookie = form.querySelector('[data-path="banner_control.per_cookie_consent"]');
  service.checked = serviceOn;
  cookie.checked = cookieOn;
  dom.window.document.body.appendChild(form);
  return { form, service, cookie, group: form.querySelector('[data-show-if]') };
}

// 1. Load with per-service off: the group is hidden and the child is cleared,
//    which also self-heals a legacy row where the two disagreed on disk.
check('per-service off at load hides the per-cookie group', () => {
  const { form, group } = buildForm({ serviceOn: false, cookieOn: true });
  applyShowIf(form);
  assert.equal(group.style.display, 'none');
});
check('per-service off at load unticks a stale per-cookie value', () => {
  const { form, cookie } = buildForm({ serviceOn: false, cookieOn: true });
  applyShowIf(form);
  assert.equal(cookie.checked, false, 'a hidden checkbox still serializes — it must not stay ticked');
});

// 2. Switching per-service off in the session is the path that produced the
//    false "saved" toast: the child must go down with it, not merely vanish.
check('unticking per-service clears per-cookie', () => {
  const { form, service, cookie, group } = buildForm({ serviceOn: true, cookieOn: true });
  applyShowIf(form);
  assert.equal(cookie.checked, true, 'precondition: both on and visible');
  service.checked = false;
  service.dispatchEvent(new dom.window.Event('change'));
  assert.equal(group.style.display, 'none');
  assert.equal(cookie.checked, false);
});

// 3. Re-ticking the parent must not resurrect the value: the admin never chose
//    it again, and the server no longer holds it.
check('re-ticking per-service does not resurrect the cleared per-cookie value', () => {
  const { form, service, cookie, group } = buildForm({ serviceOn: true, cookieOn: true });
  applyShowIf(form);
  service.checked = false;
  service.dispatchEvent(new dom.window.Event('change'));
  service.checked = true;
  service.dispatchEvent(new dom.window.Event('change'));
  assert.equal(group.style.display, '', 'group is visible again');
  assert.equal(cookie.checked, false, 'stale "on" must not come back');
});

// 4. The retained direction: with the parent on, the child is untouched and
//    submittable — the gate must not make per-cookie consent unreachable.
check('per-service on leaves per-cookie visible and settable', () => {
  const { form, cookie, group } = buildForm({ serviceOn: true, cookieOn: false });
  applyShowIf(form);
  assert.equal(group.style.display, '');
  cookie.checked = true;
  assert.equal(cookie.checked, true);
});

// 5. Blast radius: clearing is opt-in via data-clear-when-hidden. A plain
//    data-show-if group (e.g. geolocation.target_regions) keeps its state, or
//    toggling geo-targeting off would silently wipe a stored configuration the
//    server is perfectly happy to keep.
check('a plain data-show-if group is hidden but never cleared', () => {
  const form = dom.window.document.createElement('form');
  form.innerHTML = `
    <input type="checkbox" data-path="geolocation.geo_targeting">
    <div data-show-if="geolocation.geo_targeting">
      <input type="checkbox" data-path="geolocation.target_regions" value="EU">
    </div>`;
  const parent = form.querySelector('[data-path="geolocation.geo_targeting"]');
  const region = form.querySelector('[data-path="geolocation.target_regions"]');
  parent.checked = true;
  region.checked = true;
  dom.window.document.body.appendChild(form);
  applyShowIf(form);
  parent.checked = false;
  parent.dispatchEvent(new dom.window.Event('change'));
  assert.equal(form.querySelector('[data-show-if]').style.display, 'none');
  assert.equal(region.checked, true, 'unrelated hidden config must survive');
});

// 6. The markup the test models must be the markup that ships.
const view = readFileSync(new URL('../../../admin/views/settings.php', import.meta.url), 'utf8');
check('settings.php gates per-cookie consent on per-service consent', () => {
  assert.match(
    view,
    /data-show-if="banner_control\.per_service_consent"[^>]*data-clear-when-hidden/,
    'the per-cookie group must carry both attributes in the shipped view',
  );
});

// ── the controller OUTSIDE the re-rendered root ─────────────────────────────
// applyShowIf takes a root so a partial re-render can re-gate just that
// fragment. The controlling checkbox routinely lives OUTSIDE it — it is the
// parent toggle, in a different form group. Looking the controller up inside
// the root made `src` null, the guard returned, and the group kept whatever
// visibility the markup shipped with: no error, no warning, and a
// data-clear-when-hidden group left ticked while the server was about to
// discard the value. A silent failure in the one mechanism whose only job is
// agreeing with the sanitiser.
function buildSplitForm({ serviceOn, cookieOn }) {
  const form = dom.window.document.createElement('form');
  form.innerHTML = `
    <div class="faz-form-group">
      <label class="faz-toggle">
        <input type="checkbox" data-path="banner_control.per_service_consent">
      </label>
    </div>
    <div class="faz-rerender-panel">
      <div class="faz-form-group" data-show-if="banner_control.per_service_consent" data-clear-when-hidden>
        <label class="faz-toggle">
          <input type="checkbox" data-path="banner_control.per_cookie_consent">
        </label>
      </div>
    </div>`;
  const service = form.querySelector('[data-path="banner_control.per_service_consent"]');
  const cookie = form.querySelector('[data-path="banner_control.per_cookie_consent"]');
  service.checked = serviceOn;
  cookie.checked = cookieOn;
  dom.window.document.body.appendChild(form);
  return { form, service, cookie, panel: form.querySelector(".faz-rerender-panel"), group: form.querySelector('[data-show-if]') };
}

check('a controller outside the re-scoped root is still found', () => {
  const { panel, group } = buildSplitForm({ serviceOn: false, cookieOn: true });
  applyShowIf(panel); // the controller lives outside `panel`
  assert.equal(group.style.display, 'none', 'the group must be hidden even when the controller is outside the root');
});

check('and the child is still cleared when the controller is outside the root', () => {
  const { panel, cookie } = buildSplitForm({ serviceOn: false, cookieOn: true });
  applyShowIf(panel);
  assert.equal(cookie.checked, false, 'a hidden checkbox still serializes — re-scoping must not skip the clear');
});

// The listener must not stack: applyShowIf runs again on every re-scope, and a
// data-clear-when-hidden group sweeps the DOM on each change event.
check('re-scoping does not stack change listeners', () => {
  const { form, service, group } = buildForm({ serviceOn: true, cookieOn: true });
  applyShowIf(form);
  applyShowIf(form);
  applyShowIf(form);
  let sweeps = 0;
  const realQSA = group.querySelectorAll.bind(group);
  group.querySelectorAll = (sel) => { sweeps++; return realQSA(sel); };
  service.checked = false;
  service.dispatchEvent(new dom.window.Event('change'));
  assert.equal(sweeps, 1, `one change event must sweep once, not once per applyShowIf call (got ${sweeps})`);
});

console.log(`settings per-cookie gate: ${passed} passed, 0 failed`);
