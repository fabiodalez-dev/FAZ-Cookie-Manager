/**
 * The consent-log payload must name its page the way the placeholder inventory
 * does.
 *
 * The audit judges a GPC exception by asking whether the page it was clicked
 * on ever rendered that embed's placeholder, and the inventory stores pages
 * through faz_normalize_page_url(), which keeps WordPress routing parameters
 * (?p=123, ?lang=it, ?amp). The inline logger built the payload url from
 * origin + pathname only, so on a plain-permalink or query-language site every
 * page collapsed to the home page and every genuine exception read as
 * unverified. The page now bakes its own identity — the same function the
 * inventory used for this render — into _fazConsentLog.pageUrl, and the logger
 * falls back to origin + pathname + search when it is absent.
 *
 * The logger is a JavaScript string built inside PHP, so this test extracts the
 * shipped snippet from frontend/class-frontend.php and runs it in jsdom.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const PHP = readFileSync(resolve(HERE, '../../../frontend/class-frontend.php'), 'utf8');

let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

const line = PHP.split('\n').find((l) => l.trim().startsWith('"var safeUrl='));
if (!line) {
  console.log('  \x1b[31mFAIL\x1b[0m the safeUrl snippet was not found in class-frontend.php');
  process.exit(1);
}
const snippet = line.trim().replace(/^"/, '').replace(/"\s*\.\s*$/, '');

function safeUrl(href, pageUrl) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { runScripts: 'outside-only', url: href });
  dom.window._fazConsentLog = pageUrl === undefined ? {} : { pageUrl };
  return dom.window.eval(`(function(){${snippet}return safeUrl;})()`);
}

console.log('consent-log payload page url (jsdom, snippet extracted from class-frontend.php)');

check(
  'the baked server-side page identity is used when present',
  safeUrl('https://site.test/?p=123&utm_source=x#top', 'https://site.test/?p=123') === 'https://site.test/?p=123'
);
check(
  'without it the routing query survives in the fallback',
  safeUrl('https://site.test/?p=123') === 'https://site.test/?p=123'
);
check(
  'the fallback never carries the fragment',
  safeUrl('https://site.test/post/?lang=it#comments') === 'https://site.test/post/?lang=it'
);
check(
  'an empty baked value falls back rather than logging nothing',
  safeUrl('https://site.test/a/', '') === 'https://site.test/a/'
);

// The localize array carries the value, computed by the inventory's own
// function so the two sides cannot drift.
const localize = PHP.slice(PHP.indexOf("'_fazConsentLog',"), PHP.indexOf("'_fazConsentLog',") + 900);
check('_fazConsentLog carries pageUrl', /'pageUrl'\s*=>/.test(localize));
check('computed by Embed_Inventory::current_url()', localize.includes('Embed_Inventory::current_url()'));

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
