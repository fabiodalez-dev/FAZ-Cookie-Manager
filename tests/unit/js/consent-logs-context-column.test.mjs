/**
 * The consent-log table shows the context the row was written under.
 *
 * Migration_V2 added seven columns — country, region, ruleset, GPC/DNT
 * received, TC string, GPP string — and the REST list already returned them,
 * but the admin table rendered six columns and never read one of them: the
 * TC string a TCF site needs to evidence, or the "GPC header was present" bit
 * a CPRA complaint turns on, were visible only in the raw JSON. These cases
 * pin a seventh "Context" cell that paints:
 *
 *   - one pill for the jurisdiction (region when known, else country) and one
 *     for the ruleset id;
 *   - a pill each for a GPC and a DNT signal that was received (and none when
 *     the flag is 0 or absent);
 *   - a pill each for a TC string and a GPP string, carrying the full string
 *     in the title so it can be copied out;
 *   - a plain '--' for a pre-migration row with none of the above;
 *   - the view's header has the matching seventh column and the empty-state
 *     colspan matches it.
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
const ADMIN_PHP = readFileSync(resolve(ROOT, 'admin/class-admin.php'), 'utf8');

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
  return [...window.document.querySelectorAll('#faz-logs-body tr')];
}

const base = { created_at: '2026-09-18 10:00:00', consent_id: 'c', status: 'accepted', categories: { analytics: 'yes' }, ip_hash: 'x', url: 'https://s/' };
const TC = 'CPyyyAAA.AAAAxxxxx_BB.fakeAA-9999.YY';
const GPP = 'DBABLA~CPyyyAAA~~~';

console.log('consent-log context column (jsdom, shipped consent-logs.js)');

const [full, countryOnly, legacy, empty, emptyRowCase] = await Promise.all([
  render([{ ...base, country_at_consent: 'US', region_at_consent: 'US-CA', ruleset_id_at_consent: 'ccpa-california', signal_gpc_received: 1, signal_dnt_received: 1, tc_string: TC, gpp_string: GPP }]),
  render([{ ...base, country_at_consent: 'DE', region_at_consent: null, ruleset_id_at_consent: 'gdpr-strict', signal_gpc_received: 0, signal_dnt_received: 0, tc_string: null, gpp_string: null }]),
  render([{ ...base }]),
  render([]),
  render([{ ...base, country_at_consent: null, ruleset_id_at_consent: null, signal_gpc_received: '0', signal_dnt_received: '0', tc_string: '', gpp_string: '' }]),
]);

const cells = (tr) => [...tr.querySelectorAll('td')];
const contextCell = (tr) => cells(tr)[cells(tr).length - 1];
const pills = (tr) => [...contextCell(tr).querySelectorAll('.faz-ctx-pill')];
const text = (p) => p.textContent.trim();

check('a row renders seven cells', cells(full[0]).length === 7);
const fullPills = pills(full[0]);
check('the jurisdiction pill shows the region when known', fullPills.some((p) => text(p) === 'US-CA'));
check('and not the country separately', !fullPills.some((p) => text(p) === 'US'));
check('the ruleset pill shows the ruleset id', fullPills.some((p) => text(p) === 'ccpa-california'));
check('a received GPC signal paints a GPC pill', fullPills.some((p) => text(p) === 'GPC'));
check('a received DNT signal paints a DNT pill', fullPills.some((p) => text(p) === 'DNT'));
const tcPill = fullPills.find((p) => text(p) === 'TCF');
check('a TC string paints a TCF pill', !!tcPill);
check('whose title carries the full TC string', !!tcPill && tcPill.title.includes(TC));
const gppPill = fullPills.find((p) => text(p) === 'GPP');
check('a GPP string paints a GPP pill', !!gppPill);
check('whose title carries the full GPP string', !!gppPill && gppPill.title.includes(GPP));
check('six pills in all, nothing painted twice', fullPills.length === 6);

const coPills = pills(countryOnly[0]);
check('without a region the jurisdiction pill falls back to the country', coPills.some((p) => text(p) === 'DE'));
check('a 0 GPC flag paints no GPC pill', !coPills.some((p) => text(p) === 'GPC'));
check('a 0 DNT flag paints no DNT pill', !coPills.some((p) => text(p) === 'DNT'));
check('null TC/GPP strings paint no TCF/GPP pills', !coPills.some((p) => text(p) === 'TCF' || text(p) === 'GPP'));
check('country and ruleset only: two pills', coPills.length === 2);

check('a pre-migration row (no v2 keys at all) still renders seven cells', cells(legacy[0]).length === 7);
check('and its context cell reads --', pills(legacy[0]).length === 0 && text(contextCell(legacy[0])) === '--');
check('a row whose v2 keys are null/0/empty strings reads -- too', pills(emptyRowCase[0]).length === 0 && text(contextCell(emptyRowCase[0])) === '--');

const emptyTd = empty[0] && empty[0].querySelector('td');
check('the empty-state row spans all seven columns', !!emptyTd && emptyTd.colSpan === 7);

// The date cell: created_at is site wall-clock time with no offset, and parsed
// bare the browser reads it as its own local time — right digits, wrong
// instant for an administrator elsewhere. With created_at_gmt (RFC 3339 UTC)
// on the row the cell shows that instant in the viewer's zone and keeps the
// UTC string in the title.
const [withGmt] = await render([{ ...base, created_at: '2026-06-19 12:00:00', created_at_gmt: '2026-06-19T10:00:00Z' }]);
const dateCell = cells(withGmt)[0];
const expected = new Date('2026-06-19T10:00:00Z');
const expectedText = expected.toLocaleDateString(undefined) + ' ' + expected.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
check('the date cell renders the UTC instant in the viewer\'s zone when created_at_gmt is present', text(dateCell) === expectedText);
check('and carries the UTC value in its title', dateCell.title === '2026-06-19T10:00:00Z');
const [withoutGmt] = await render([{ ...base, created_at: '2026-06-19 12:00:00' }]);
check('without created_at_gmt (older API) the cell still renders', text(cells(withoutGmt)[0]).length > 4);

// The server-rendered header must have the matching column, and its own
// "Loading..." placeholder must span it.
const ths = (VIEW.match(/<th[\s>]/g) || []).length;
check('the view declares seven header cells', ths === 7);
check('the view names the new column', /Context/.test(VIEW));
check('the view\'s loading row spans seven columns', /colspan="7"/.test(VIEW));

// Every tooltip the context cell asks fazI18n for must exist in the admin
// i18n map, or a localised administrator always reads the English fallback.
// The map is keyed `'consentLogs' => array( 'key' => __( ... ) )` in
// admin/class-admin.php; the page JS reads it as consentLogs.<key>.
const ctxKeys = [...PAGE_JS.matchAll(/fazI18n\('consentLogs\.(ctx[A-Za-z]+)'/g)].map((m) => m[1]);
check('the context cell asks fazI18n for its seven tooltips', new Set(ctxKeys).size === 7);
for (const key of new Set(ctxKeys)) {
  check(`consentLogs.${key} is declared in the admin i18n map`, new RegExp(`'${key}'\\s*=>\\s*__\\(`).test(ADMIN_PHP));
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
