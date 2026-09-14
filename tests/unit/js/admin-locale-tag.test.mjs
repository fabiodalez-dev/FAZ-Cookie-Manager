/**
 * FAZ.locale() turns the WordPress user locale into a tag the Intl APIs
 * accept — and never throws, whatever it is handed.
 *
 * Issue #284: the consent log page showed "Failed to load consent logs." on
 * every install that had at least one log. Nothing had failed to load. The
 * rows were already in the browser, and rendering them called
 * toLocaleDateString('de_DE') — WordPress writes locales with an underscore,
 * which is not BCP 47, so Intl answers with a RangeError. The throw happened
 * inside the .then() of the fetch, so it landed in the .catch() written for
 * a failed request and was reported as one. Nothing reached the server log,
 * which is why the reporter found nothing there.
 *
 * These cases pin both halves: a valid tag comes back for the locales
 * WordPress really ships, and an unusable one degrades instead of throwing.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const ADMIN_JS = readFileSync(resolve(HERE, '../../../admin/assets/js/faz-admin.js'), 'utf8');

let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

// Load the shipped file the way an admin page does, with only the globals it
// needs at definition time. wp.apiFetch is never called here.
function localeFor(configLocale, documentLang = '') {
  const dom = new JSDOM(`<!DOCTYPE html><html lang="${documentLang}"><body></body></html>`, {
    runScripts: 'outside-only', url: 'https://admin.example.test/',
  });
  const { window } = dom;
  window.wp = { apiFetch: () => Promise.resolve({}) };
  if (configLocale !== undefined) window.fazConfig = { locale: configLocale };
  window.eval(ADMIN_JS);
  return window.FAZ.locale();
}

// Formatting through the returned tag must not throw either: the tag is only
// useful if Intl accepts it, and that is the assertion the page depends on.
function formats(tag) {
  try { new Date('2026-09-14T19:20:00Z').toLocaleDateString(tag); return true; }
  catch (_unused) { return false; }
}

console.log('FAZ.locale() — WordPress locales reach Intl as valid tags (jsdom)');

// The reported case, and the shape every WordPress locale has.
check('de_DE becomes de-DE', localeFor('de_DE') === 'de-DE');
check('en_US becomes en-US', localeFor('en_US') === 'en-US');
check('it_IT becomes it-IT', localeFor('it_IT') === 'it-IT');

// WordPress variants. 'de_DE_formal' survives as a variant subtag; 'pt_PT_ao90'
// does not — 'ao90' is not a well-formed one — so it has to lose the variant
// rather than the region: a Portuguese admin keeps a Portuguese date.
check('de_DE_formal keeps its variant', localeFor('de_DE_formal') === 'de-DE-formal');
check('pt_PT_ao90 degrades to pt-PT, not to pt', localeFor('pt_PT_ao90') === 'pt-PT');

// Language-only locales WordPress ships for languages without a region.
check('ca stays ca', localeFor('ca') === 'ca');
check('roh stays roh', localeFor('roh') === 'roh');

// Nothing usable: undefined tells Intl to use the runtime default, which is
// what these pages did before the locale was ever passed.
check('an empty locale yields undefined', localeFor('') === undefined);
check('a missing fazConfig yields undefined', localeFor(undefined) === undefined);
check('a non-string locale yields undefined', localeFor(42) === undefined);
check('an unusable tag yields undefined rather than throwing', localeFor('!!!') === undefined);

// The document language is the documented fallback when the config has none.
check('falls back to <html lang> when the config carries no locale',
  localeFor('', 'fr-FR') === 'fr-FR');

// Every tag handed back must actually format a date — the property the
// consent log page needs and the one that was broken.
for (const wpLocale of ['de_DE', 'en_US', 'it_IT', 'pt_PT_ao90', 'de_DE_formal', 'ca', '', '!!!']) {
  check(`a date formats with the tag returned for ${JSON.stringify(wpLocale)}`,
    formats(localeFor(wpLocale)));
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
