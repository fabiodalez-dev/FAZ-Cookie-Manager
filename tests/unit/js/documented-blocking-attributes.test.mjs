/**
 * The attribute the readme tells people to use must be the one the runtime
 * looks for.
 *
 * readme.txt is the page a site owner reads on wordpress.org, and it said to
 * tag scripts with `data-faz-tag`. The runtime unblocks
 * `script[type="text/plain"][data-faz-category]`; `data-faz-tag` marks banner
 * ELEMENTS (accept-button, detail-title) and nothing converts one into the
 * other. So the documented recipe produced a script that was never run —
 * before consent or after it.
 *
 * The failure is nastier than it sounds: the first thing anyone checks is
 * "does it stay blocked before consent", and that passes, because a script
 * with a non-executable type is not run by any browser, plugin or no plugin.
 * It then never comes back, and the symptom reads as "blocking works but the
 * script does not restart on reload" — which is what was reported.
 *
 * These checks pin the two halves together so the docs cannot drift from the
 * code again.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const root = (p) => resolve(HERE, '../../../', p);
const SCRIPT = readFileSync(root('frontend/js/script.js'), 'utf8');
const README_TXT = readFileSync(root('readme.txt'), 'utf8');
const README_MD = readFileSync(root('README.md'), 'utf8');

let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

console.log('documented blocking attributes match the runtime');

// What the runtime actually restores.
check('the runtime unblocks scripts by type + data-faz-category',
  SCRIPT.includes('script[type="text/plain"][data-faz-category]'));

// data-faz-tag is a banner-element attribute. If user documentation ever
// offers it as the way to gate a script again, this fails.
const BAD = /data-faz-tag\s*=?\s*["'`]?(analytics|marketing|functional|category-name|your-category)/i;
check('readme.txt does not tell anyone to gate a script with data-faz-tag',
  !BAD.test(README_TXT));
check('README.md does not either', !BAD.test(README_MD));

// A weaker net for the same mistake in prose: the phrase "tag ... script with
// data-faz-tag" in any shape.
const PROSE = /(tag|mark)[^.\n]{0,40}script[^.\n]{0,40}data-faz-tag/i;
check('no prose in readme.txt recommends data-faz-tag for scripts', !PROSE.test(README_TXT));

// And the recipe that IS documented has to be the working one, both halves.
check('readme.txt documents the type that stops execution',
  README_TXT.includes('type="text/plain"'));
check('readme.txt documents the attribute the runtime reads',
  README_TXT.includes('data-faz-category'));

// The same pairing for the other gated resources, so the docs stay honest
// about those too.
for (const [what, needle] of [
  ['iframes and images', 'data-faz-src'],
  ['stylesheets', 'data-faz-href'],
]) {
  check(`the runtime restores ${what} via ${needle}`, SCRIPT.includes(needle));
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
