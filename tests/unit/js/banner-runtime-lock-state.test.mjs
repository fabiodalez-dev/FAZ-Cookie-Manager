/**
 * Runtime-locked banner controls must show their effective state without
 * overwriting the administrator's stored baseline.
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_SOURCE = readFileSync(resolve(HERE, '../../../admin/assets/js/pages/banner.js'), 'utf8');
const VIEW_SOURCE = readFileSync(resolve(HERE, '../../../admin/views/banner.php'), 'utf8');
const HELPERS_MATCH = SCRIPT_SOURCE.match(
  /\tfunction isChecked\(id\) \{[\s\S]*?\n\tfunction getStatus\(obj\) \{/,
);

if (!HELPERS_MATCH) {
  throw new Error('Could not extract the real banner checkbox helpers.');
}

const HELPERS = HELPERS_MATCH[0].replace(/\n\tfunction getStatus\(obj\) \{$/, '');
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

function loadHelpers(runtimeLocked = false) {
  const attribute = runtimeLocked ? ' data-faz-runtime-locked="1" disabled' : '';
  const dom = new JSDOM(
    `<!doctype html><div id="control"><input type="checkbox"${attribute}></div>`,
    { runScripts: 'outside-only' },
  );
  dom.window.eval(`${HELPERS}\nwindow.__setChecked = setChecked; window.__isChecked = isChecked;`);
  return dom.window;
}

console.log('banner runtime-lock state');

{
  const window = loadHelpers(true);
  window.__setChecked('control', false);
  const checkbox = window.document.querySelector('input');
  check('a locked false baseline is displayed as effectively enabled', checkbox.checked === true);
  check('serialization preserves the locked false baseline', window.__isChecked('control') === false);
  check('the false baseline is recorded explicitly', checkbox.dataset.fazStoredChecked === '0');
}

{
  const window = loadHelpers(true);
  window.__setChecked('control', true);
  const checkbox = window.document.querySelector('input');
  check('a locked true baseline remains displayed as enabled', checkbox.checked === true);
  check('serialization preserves the locked true baseline', window.__isChecked('control') === true);
}

{
  const window = loadHelpers(false);
  window.__setChecked('control', false);
  check('an unlocked checkbox still hydrates and serializes false normally', window.__isChecked('control') === false);
  window.__setChecked('control', true);
  check('an unlocked checkbox still hydrates and serializes true normally', window.__isChecked('control') === true);
}

for (const id of ['faz-b-accept-toggle', 'faz-b-reject-toggle', 'faz-b-revisit-toggle']) {
  const escapedId = id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const controlPattern = new RegExp(
    `id="${escapedId}"[\\s\\S]{0,500}?data-faz-runtime-locked="1"`,
  );
  check(`${id} is marked as runtime-locked in the real view`, controlPattern.test(VIEW_SOURCE));
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
