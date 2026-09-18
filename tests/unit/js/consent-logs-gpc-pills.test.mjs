/**
 * How the consent-log table renders a GPC exception.
 *
 * The server writes the client's claim (meta.gpc_exception.<id>) and, beside
 * it, its own verdict (meta.gpc_exception_served.<id> or _carried.<id>). The
 * table painted one pill per key, so every audited exception showed twice —
 * once as a bare claim and once as a verdict — and a carried verdict looked the
 * same whether the first one had been verified or not. These cases pin:
 *
 *   - exactly one pill per service id: a verdict suppresses the bare claim;
 *   - a carried 'no' paints red, labelled "carried, unverified";
 *   - every GPC pill explains its state in a title;
 *   - the page carries a legend saying what "unverified" does and does not mean.
 *
 * Loads the shipped admin/assets/js/pages/consent-logs.js in jsdom with a stub
 * FAZ that hands it one page of rows.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../../..');
const PAGE_JS = readFileSync(resolve(ROOT, 'admin/assets/js/pages/consent-logs.js'), 'utf8');
const VIEW = readFileSync(resolve(ROOT, 'admin/views/consent-logs.php'), 'utf8');
const ADMIN = readFileSync(resolve(ROOT, 'admin/class-admin.php'), 'utf8');

let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

const MARKUP = `<!DOCTYPE html><html><body>
  <span id="faz-stat-total"></span><span id="faz-stat-accepted"></span>
  <span id="faz-stat-rejected"></span><span id="faz-stat-partial"></span>
  <select id="faz-log-status"><option value=""></option></select>
  <input id="faz-log-search" value=""><button id="faz-log-filter"></button>
  <button id="faz-log-export"></button>
  <table><tbody id="faz-logs-body"></tbody></table>
  <div id="faz-log-footer"><div id="faz-log-info"></div><div id="faz-log-pagination"></div></div>
</body></html>`;

async function render(rows) {
  const dom = new JSDOM(MARKUP, { runScripts: 'outside-only', url: 'https://admin.example.test/' });
  const { window } = dom;
  window.FAZ = {
    ready: (fn) => fn(),
    get: () => Promise.resolve([]),
    getWithHeaders: () => Promise.resolve({ data: rows, total: rows.length, pages: 1 }),
    notify: () => {},
  };
  window.eval(PAGE_JS);
  await new Promise((r) => setTimeout(r, 20));
  return [...window.document.querySelectorAll('#faz-logs-body tr')].map((tr) => [...tr.querySelectorAll('.faz-cat-pill')]);
}

const row = (categories) => ({ created_at: '2026-09-18 10:00:00', consent_id: 'c', status: 'partial', categories, ip_hash: 'x', url: 'https://s/' });
const gpcPills = (pills) => pills.filter((p) => p.textContent.indexOf('GPC exception') === 0);

console.log('consent-log GPC exception pills (jsdom, shipped consent-logs.js)');

const [served, servedNo, carriedYes, carriedNo, legacy, twoIds] = await render([
  row({ necessary: 'yes', 'meta.gpc_exception.maps': 'yes', 'meta.gpc_exception_served.maps': 'yes' }),
  row({ 'meta.gpc_exception.maps': 'yes', 'meta.gpc_exception_served.maps': 'no' }),
  row({ 'meta.gpc_exception.maps': 'yes', 'meta.gpc_exception_carried.maps': 'yes' }),
  row({ 'meta.gpc_exception.maps': 'yes', 'meta.gpc_exception_carried.maps': 'no' }),
  row({ 'meta.gpc_exception.maps': 'yes' }),
  row({ 'meta.gpc_exception.maps': 'yes', 'meta.gpc_exception_served.maps': 'yes', 'meta.gpc_exception.yt': 'yes' }),
]);

check('a served verdict renders one pill, not two', gpcPills(served).length === 1);
check('and it is the verdict', /verified/.test(gpcPills(served)[0]?.textContent || '') && !/unverified/.test(gpcPills(served)[0]?.textContent || ''));
check('an unverified serve renders one red pill', gpcPills(servedNo).length === 1 && gpcPills(servedNo)[0].classList.contains('faz-cat-no'));
check('a carried verdict renders one pill', gpcPills(carriedYes).length === 1);
check('a carried yes stays neutral', !gpcPills(carriedYes)[0].classList.contains('faz-cat-no') && !/unverified/.test(gpcPills(carriedYes)[0].textContent));
check('a carried no renders one pill', gpcPills(carriedNo).length === 1);
check('a carried no is red', gpcPills(carriedNo)[0]?.classList.contains('faz-cat-no'));
check('and says carried, unverified', /carried, unverified/.test(gpcPills(carriedNo)[0]?.textContent || ''));
check('a legacy row with only the claim keeps its one pill', gpcPills(legacy).length === 1 && !gpcPills(legacy)[0].classList.contains('faz-cat-no'));
check('ids are deduplicated independently', gpcPills(twoIds).length === 2);

const all = [served, servedNo, carriedYes, carriedNo, legacy, twoIds].flatMap(gpcPills);
check('every GPC pill explains its state in a title', all.length > 0 && all.every((p) => typeof p.title === 'string' && p.title.length > 20));
check('the four states carry four different explanations', new Set([
  gpcPills(served)[0]?.title, gpcPills(servedNo)[0]?.title, gpcPills(carriedYes)[0]?.title, gpcPills(carriedNo)[0]?.title, gpcPills(legacy)[0]?.title,
]).size === 5);

// Labels and explanations are translatable: every key the page looks up with a
// GPC prefix exists in the consentLogs i18n array.
const keys = [...PAGE_JS.matchAll(/fazI18n\('consentLogs\.(metaGpc[A-Za-z]+)'/g)].map((m) => m[1]);
const block = ADMIN.slice(ADMIN.indexOf("'consentLogs'"), ADMIN.indexOf("'consentLogs'") + 6000);
check('every GPC label and title key is in the consentLogs i18n array', keys.length >= 10 && keys.every((k) => block.includes(`'${k}'`)));

// The legend beside the filter.
check('the page carries a GPC legend', /id="faz-gpc-legend"/.test(VIEW));
check('the legend says unverified is not proof of forgery', /not.{0,80}forged/i.test(VIEW));

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
