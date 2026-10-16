/**
 * A stale origin token is recovered, not lost.
 *
 * The consent-log and pageview beacons are inline JavaScript strings built in
 * frontend/class-frontend.php and printed into cacheable HTML together with a
 * time-bucketed token. The endpoints accept that token for a bounded window
 * (7 days by default); a page an edge cache keeps longer posts a token that is
 * refused with 403 `invalid_token`, and because both calls are fire-and-forget
 * the record — for the consent log, the Art. 7(1) record — was simply lost.
 *
 * Both snippets now recover: on that one refusal they fetch the current token
 * from the no-store admin-ajax action (Consent_Logger::handle_token_refresh())
 * and resend ONCE. This test runs the shipped snippets, extracted verbatim
 * from the PHP, against a scripted fetch, and pins: the retry and its token,
 * the single attempt, no retry on any other refusal, the consent beacon's
 * can_refresh flag on the first post only, the counted fallback when the
 * refresh fails, the pageview beacon's shared refresh, and that a config
 * without tokenUrl (a page cached before this change) behaves as before.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const PHP = readFileSync(resolve(HERE, '../../../frontend/class-frontend.php'), 'utf8');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  if (a === e) { console.log(`  \x1b[32mPASS\x1b[0m ${label}`); passed++; }
  else { console.log(`  \x1b[31mFAIL\x1b[0m ${label}: expected ${e}, got ${a}`); failed++; }
}

/** Same extraction rule as consent-log-tc-string.test.mjs: one literal per line, `//` lines dropped. */
function extract(startMarker, endMarker, assignment) {
  const start = PHP.indexOf(startMarker);
  if (start === -1) return null;
  const end = PHP.indexOf(endMarker, start);
  if (end === -1) return null;
  const block = PHP.slice(start + assignment.length, end + endMarker.length - 1);
  const parts = [];
  for (const rawLine of block.split('\n')) {
    const line = rawLine.trim();
    if (!line || line.startsWith('//')) continue;
    const m = line.match(/^"((?:[^"\\]|\\.)*)"/);
    if (!m) return null;
    parts.push(m[1]);
  }
  return parts.join('');
}

const CONSENT_CODE = extract('$inline_js = "document.addEventListener(\'fazcookie_consent_update\'', '"});";', '$inline_js = ');
const PAGEVIEW_CODE = extract('$pv_js = "(function(){"', '"})();";', '$pv_js = ');
if (!CONSENT_CODE || !PAGEVIEW_CODE) {
  console.log('  \x1b[31mFAIL\x1b[0m the inline consent-log or pageview beacon was not found in class-frontend.php');
  process.exit(1);
}

const REST_CONSENT = 'https://site.example.test/wp-json/faz/v1/consent';
const REST_PV = 'https://site.example.test/wp-json/faz/v1/pageviews';
const TOKEN_URL = 'https://site.example.test/wp-admin/admin-ajax.php?action=faz_origin_tokens';

/**
 * A page with a scripted network. `answers` decides each POST's response;
 * `refresh` decides the token refresh. Every request is recorded.
 */
function boot({ withTokenUrl = true, answers, refresh }) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'https://site.example.test/page/',
  });
  const { window } = dom;
  const calls = [];
  const respond = (status, body) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
  window.fetch = (url, opts = {}) => {
    const method = opts.method || 'GET';
    const body = opts.body ? JSON.parse(opts.body) : null;
    calls.push({ url: String(url), method, body, cache: opts.cache || '' });
    if ('GET' === method) {
      return refresh(url, calls);
    }
    const posts = calls.filter((c) => c.method === 'POST' && c.url === String(url)).length;
    const [status, payload] = answers(posts, body, String(url));
    return respond(status, payload);
  };
  window._fazConsentLog = {
    restUrl: REST_CONSENT, token: 'stale-consent', bannerSlug: 'default', policyRevision: 1,
    pageUrl: 'https://site.example.test/page/',
    ...(withTokenUrl ? { tokenUrl: TOKEN_URL } : {}),
  };
  window._fazPageviewConfig = {
    restUrl: REST_PV, pageUrl: 'https://site.example.test/page/', pageTitle: 'Page', token: 'stale-pv',
    ...(withTokenUrl ? { tokenUrl: TOKEN_URL } : {}),
  };
  window.document.cookie = 'fazcookie-consent=' + encodeURIComponent('consentid:abc,consent:no,action:yes,necessary:yes,analytics:no');
  return { window, calls, respond };
}

const refreshOk = (calls) => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true, data: { consent: 'fresh-consent', pageview: 'fresh-pv' } }) });
const stale = [403, { code: 'invalid_token', message: 'Invalid origin token.', data: { status: 403 } }];
const ok = [200, { log_id: 1 }];

const settle = async (window) => { for (let i = 0; i < 20; i++) await new Promise((r) => setTimeout(r, 0)); };
function decide(window, action = 'reject') {
  window.document.dispatchEvent(new window.CustomEvent('fazcookie_consent_update', {
    detail: { action, accepted: action === 'reject' ? ['necessary'] : ['necessary', 'analytics'], rejected: action === 'reject' ? ['analytics'] : [] },
  }));
}
const posts = (calls, url) => calls.filter((c) => c.method === 'POST' && c.url === url);
const gets = (calls) => calls.filter((c) => c.method === 'GET');

console.log('\norigin-token refresh in the inline consent-log and pageview beacons (jsdom, extracted from class-frontend.php)');

// ── Consent log ──────────────────────────────────────────────────────────────
{
  const { window, calls } = boot({ answers: (n) => (1 === n ? stale : ok), refresh: (u, c) => refreshOk(c) });
  window.eval(CONSENT_CODE);
  decide(window);
  await settle(window);
  const p = posts(calls, REST_CONSENT);
  eq('consent: a stale-token refusal is retried — two POSTs in total', p.length, 2);
  eq('consent: the first POST announces it can refresh', p[0] && p[0].body.can_refresh, 1);
  eq('consent: the token is refreshed once, from the configured action', gets(calls).map((g) => g.url.split('&_=')[0]), [TOKEN_URL]);
  eq('consent: the refresh asks the browser not to use its cache', gets(calls)[0] && gets(calls)[0].cache, 'no-store');
  eq('consent: the resend carries the fresh token', p[1] && p[1].body.token, 'fresh-consent');
  eq('consent: and no can_refresh flag, so a second refusal is counted', p[1] && 'can_refresh' in p[1].body, false);
  eq('consent: the resend is the same decision', p[1] && [p[1].body.status, p[1].body.consent_id], ['rejected', 'abc']);
  eq('consent: later posts on the page use the fresh token directly', window._fazConsentLog.token, 'fresh-consent');
}
{
  const { window, calls } = boot({ answers: () => stale, refresh: (u, c) => refreshOk(c) });
  window.eval(CONSENT_CODE);
  decide(window);
  await settle(window);
  eq('consent: a resend refused again is NOT retried a third time', posts(calls, REST_CONSENT).length, 2);
  eq('consent: and the token is refreshed only once', gets(calls).length, 1);
}
{
  const { window, calls } = boot({ answers: () => [403, { code: 'cross_origin_request', data: { status: 403 } }], refresh: (u, c) => refreshOk(c) });
  window.eval(CONSENT_CODE);
  decide(window);
  await settle(window);
  eq('consent: any other refusal is not retried and triggers no refresh', [posts(calls, REST_CONSENT).length, gets(calls).length], [1, 0]);
}
{
  const { window, calls } = boot({ answers: (n) => (1 === n ? stale : ok), refresh: () => Promise.reject(new Error('offline')) });
  window.eval(CONSENT_CODE);
  decide(window);
  await settle(window);
  const p = posts(calls, REST_CONSENT);
  eq('consent: a failed refresh still resends the original once (so the server counts the refusal)', p.length, 2);
  eq('consent: that resend keeps the old token and drops the flag', p[1] && [p[1].body.token, 'can_refresh' in p[1].body], ['stale-consent', false]);
}
{
  const { window, calls } = boot({ answers: (n) => (1 === n ? stale : ok), refresh: () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: false, data: {} }) }) });
  window.eval(CONSENT_CODE);
  decide(window);
  await settle(window);
  eq('consent: a refresh that returns no token behaves like a failed one', posts(calls, REST_CONSENT).map((c) => c.body.token), ['stale-consent', 'stale-consent']);
}
{
  const { window, calls } = boot({ withTokenUrl: false, answers: () => stale, refresh: (u, c) => refreshOk(c) });
  window.eval(CONSENT_CODE);
  decide(window);
  await settle(window);
  const p = posts(calls, REST_CONSENT);
  eq('consent: without tokenUrl (a page cached before this change) one POST, no flag, no refresh', [p.length, p[0] && 'can_refresh' in p[0].body, gets(calls).length], [1, false, 0]);
}

// ── Pageview ─────────────────────────────────────────────────────────────────
{
  const { window, calls } = boot({ answers: (n, body) => ('stale-pv' === body.token ? stale : ok), refresh: (u, c) => refreshOk(c) });
  window.eval(PAGEVIEW_CODE);
  await settle(window);
  window.document.dispatchEvent(new window.CustomEvent('fazcookie_banner_loaded'));
  await settle(window);
  const p = posts(calls, REST_PV);
  eq('pageview: the stale pageview is resent with the fresh token', p.filter((c) => c.body.event_type === 'pageview').map((c) => c.body.token), ['stale-pv', 'fresh-pv']);
  eq('pageview: an event after the refresh uses the fresh token straight away', p.filter((c) => c.body.event_type === 'banner_view').map((c) => c.body.token), ['fresh-pv']);
  eq('pageview: one refresh for the page', gets(calls).length, 1);
}
{
  const { window, calls } = boot({ answers: (n, body) => ('stale-pv' === body.token ? stale : ok), refresh: (u, c) => refreshOk(c) });
  window.eval(PAGEVIEW_CODE);
  window.document.dispatchEvent(new window.CustomEvent('fazcookie_banner_loaded'));
  await settle(window);
  eq('pageview: two events refused together share one refresh', gets(calls).length, 1);
  eq('pageview: and each is resent exactly once', posts(calls, REST_PV).length, 4);
}
{
  const { window, calls } = boot({ answers: () => stale, refresh: (u, c) => refreshOk(c) });
  window.eval(PAGEVIEW_CODE);
  await settle(window);
  eq('pageview: a resend refused again is not retried', posts(calls, REST_PV).length, 2);
}
{
  const { window, calls } = boot({ answers: () => ok, refresh: (u, c) => refreshOk(c) });
  window.eval(PAGEVIEW_CODE);
  await settle(window);
  eq('pageview: an accepted event costs one POST and no refresh', [posts(calls, REST_PV).length, gets(calls).length], [1, 0]);
}

console.log(`\norigin-token-refresh: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
