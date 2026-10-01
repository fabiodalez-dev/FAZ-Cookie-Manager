/**
 * Settings save warnings (jsdom-free VM unit test).
 *
 * Loads the real admin settings script and exposes its pure warning collector
 * inside the test VM. This protects the compatibility/compliance messaging
 * without writing settings to a WordPress instance.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const settingsPath = new URL('../../../admin/assets/js/pages/settings.js', import.meta.url);
let source = readFileSync(settingsPath, 'utf8');
source = source.replace(
  /\n\}\)\(\);\s*$/,
  '\n\twindow.__fazTestCollectSaveWarnings = collectSaveWarnings;\n})();\n',
);

const sandbox = {
  console,
  window: {
    fazConfig: {
      i18n: {
        settings: {
          abTestWarnVariants: 'LOCALIZED_AB_VARIANTS',
          abTestWarnCache: 'LOCALIZED_AB_CACHE',
          cacheCompatWarnGeo: 'LOCALIZED_CACHE_GEO',
          cacheCompatWarnIab: 'LOCALIZED_CACHE_IAB',
          withdrawalUnverified: 'LOCALIZED_WITHDRAWAL_UNVERIFIED',
        },
      },
    },
  },
  // The withdrawal-path warning reads the verification state off the rendered
  // select, because at save time the client has no other way to know whether
  // the check passed. sandboxState.verified is what that attribute would say;
  // null stands for "the element is not on the page at all".
  document: { getElementById: (id) => (
    'faz-withdrawal-path' === id && null !== sandboxState.verified
      ? { getAttribute: () => sandboxState.verified }
      : null
  ) },
  FAZ: { ready() {} },
};
const sandboxState = { verified: null };
vm.createContext(sandbox);
vm.runInContext(source, sandbox, { filename: settingsPath.pathname });

const collect = sandbox.window.__fazTestCollectSaveWarnings;
assert.equal(typeof collect, 'function', 'real settings.js warning collector should be exposed to the VM');

const settings = (overrides = {}) => ({
  banner_control: {
    cache_compatibility: false,
    ab_test: { status: false, variants: [] },
    ...(overrides.banner_control || {}),
  },
  geolocation: { geo_targeting: false, ...(overrides.geolocation || {}) },
  iab: { enabled: false, cmp_id: 0, ...(overrides.iab || {}) },
});

assert.deepEqual(Array.from(collect(settings())), [], 'ordinary settings produce no warning');
assert.deepEqual(
  Array.from(collect(settings({ banner_control: { cache_compatibility: true }, geolocation: { geo_targeting: true } }))),
  ['LOCALIZED_CACHE_GEO'],
  'cache + geo emits the localized geo warning',
);
assert.deepEqual(
  Array.from(collect(settings({ banner_control: { cache_compatibility: true }, iab: { enabled: true, cmp_id: 0 } }))),
  [],
  'IAB checkbox without a valid CMP ID is inactive and must not claim a conservative TCF default is running',
);
assert.deepEqual(
  Array.from(collect(settings({ banner_control: { cache_compatibility: true }, iab: { enabled: true, cmp_id: '2' } }))),
  ['LOCALIZED_CACHE_IAB'],
  'effective IAB TCF emits the localized compatibility warning',
);
assert.deepEqual(
  Array.from(collect(settings({ banner_control: { ab_test: { status: true, variants: ['one'] } } }))),
  ['LOCALIZED_AB_VARIANTS'],
  'an undersized A/B test emits its localized warning',
);
// Cache Compatibility Mode has three states, and with routing on it is the
// PAUSED one: the runtime stands it down, so the server-side A/B split keeps
// running. The two warnings this case used to emit contradicted each other —
// "A/B is disabled because cache mode is on" alongside "cache mode is itself
// inactive because of geo" — and the first of them was the false one. Only the
// geo warning survives.
assert.deepEqual(
  Array.from(collect(settings({
    banner_control: { cache_compatibility: true, ab_test: { status: true, variants: ['one', 'two'] } },
    geolocation: { geo_targeting: true },
    iab: { enabled: true, cmp_id: 300 },
  }))),
  ['LOCALIZED_CACHE_GEO'],
  'routing pauses cache mode, so neither the A/B nor the IAB cache warning applies',
);
// ...and with routing OFF the mode really is active, so the A/B warning is true
// and must still be shown. Without this case the fix above could be "remove the
// warning" rather than "fire it when it is accurate".
assert.deepEqual(
  Array.from(collect(settings({
    banner_control: { cache_compatibility: true, ab_test: { status: true, variants: ['one', 'two'] } },
  }))),
  ['LOCALIZED_AB_CACHE'],
  'cache mode active (no routing) still warns that the A/B split is paused',
);

// --- Verified footer withdrawal path ---------------------------------------
// Selecting the footer link without a passing check saves fine and changes
// nothing: the server keeps forcing the revisit widget on. Silence would look
// like the setting worked.
sandboxState.verified = '0';
assert.deepEqual(
  Array.from(collect(settings({ banner_control: { withdrawal_path: 'footer_link' } }))),
  ['LOCALIZED_WITHDRAWAL_UNVERIFIED'],
  'an unverified footer withdrawal path warns that the widget stays forced on',
);
sandboxState.verified = null;
assert.deepEqual(
  Array.from(collect(settings({ banner_control: { withdrawal_path: 'footer_link' } }))),
  ['LOCALIZED_WITHDRAWAL_UNVERIFIED'],
  'a missing verification attribute counts as unverified, not as verified',
);
sandboxState.verified = '1';
assert.deepEqual(
  Array.from(collect(settings({ banner_control: { withdrawal_path: 'footer_link' } }))),
  [],
  'a verified footer withdrawal path produces no warning',
);
sandboxState.verified = '0';
assert.deepEqual(
  Array.from(collect(settings({ banner_control: { withdrawal_path: 'widget' } }))),
  [],
  'the widget path never warns — it needs no verification',
);

const adminSource = readFileSync(new URL('../../../admin/class-admin.php', import.meta.url), 'utf8');
for (const key of ['cacheCompatWarnGeo', 'cacheCompatWarnIab']) {
  assert.match(adminSource, new RegExp(`'${key}'\\s*=>\\s*__\\(`), `${key} must be gettext-localized in the admin payload`);
}

console.log('settings save warnings: 13 passed');
