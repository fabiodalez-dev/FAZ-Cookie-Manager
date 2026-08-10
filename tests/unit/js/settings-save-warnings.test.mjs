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
        },
      },
    },
  },
  FAZ: { ready() {} },
};
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
assert.deepEqual(
  Array.from(collect(settings({
    banner_control: { cache_compatibility: true, ab_test: { status: true, variants: ['one', 'two'] } },
    geolocation: { geo_targeting: true },
    iab: { enabled: true, cmp_id: 300 },
  }))),
  ['LOCALIZED_AB_CACHE', 'LOCALIZED_CACHE_GEO', 'LOCALIZED_CACHE_IAB'],
  'all independent warnings are retained and ordered when configurations overlap',
);

const adminSource = readFileSync(new URL('../../../admin/class-admin.php', import.meta.url), 'utf8');
for (const key of ['cacheCompatWarnGeo', 'cacheCompatWarnIab']) {
  assert.match(adminSource, new RegExp(`'${key}'\\s*=>\\s*__\\(`), `${key} must be gettext-localized in the admin payload`);
}

console.log('settings save warnings: 7 passed');
