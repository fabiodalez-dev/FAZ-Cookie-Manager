import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
const dom = new JSDOM(`<div id="faz-dashboard" data-pageview-tracking="0"></div>
${['pageviews', 'banner', 'accept', 'reject'].map(id => `<span id="faz-stat-${id}">0</span>`).join('')}
<div id="faz-chart-empty" class="faz-hidden"></div><div id="faz-consent-empty" class="faz-hidden"></div>`, { runScripts: 'outside-only' });
const w = dom.window;
let calls = [];
let total = 17;
w.FAZ = { ready() {}, get(path) { calls.push(path); return Promise.resolve(path.endsWith('/chart') ? { total_views: total, data: [] } : { banner_view: 3, banner_accept: 2, banner_reject: 1 }); } };
let code = readFileSync(new URL('../../../admin/assets/js/pages/dashboard.js', import.meta.url), 'utf8');
code = code.replace(/^\}\)\(\);/m, 'window.stats = loadStats; window.chart = loadChart; drawConsentDonut = function () {}; })();');
w.eval(code);
w.stats({}); w.chart({});
assert.equal(calls.length, 0);
for (const id of ['pageviews', 'banner', 'accept', 'reject']) assert.equal(w.document.getElementById('faz-stat-' + id).textContent, '--');
assert.equal(w.document.getElementById('faz-chart-empty').classList.contains('faz-hidden'), false);
w.document.getElementById('faz-dashboard').dataset.pageviewTracking = '1';
w.stats({}); w.chart({});
await new Promise(resolve => setTimeout(resolve, 0));
assert.equal(w.document.getElementById('faz-stat-pageviews').textContent, '17');
assert.equal(w.document.getElementById('faz-stat-banner').textContent, '3');
total = 0;
w.chart({});
await new Promise(resolve => setTimeout(resolve, 0));
assert.equal(w.document.getElementById('faz-stat-pageviews').textContent, '0');
w.FAZ.get = () => Promise.reject(new Error('offline'));
w.chart({});
await new Promise(resolve => setTimeout(resolve, 0));
assert.equal(w.document.getElementById('faz-stat-pageviews').textContent, '--');

// Out-of-order responses. Clicking 30D while 1D is still loading leaves two
// requests in flight over different queries with different costs; before the
// sequence token the slower one landed last and painted the range nobody
// asked for, under a filter bar that said something else.
const deferred = [];
w.FAZ.get = () => new Promise(resolve => deferred.push(resolve));
w.chart({ days: 1 });    // richiesta lenta, chiesta per prima
w.chart({ days: 30 });   // richiesta veloce, chiesta per seconda
assert.equal(deferred.length, 2, 'both requests are in flight');
deferred[1]({ total_views: 30, data: [] });   // la seconda risponde per prima
await new Promise(resolve => setTimeout(resolve, 0));
assert.equal(w.document.getElementById('faz-stat-pageviews').textContent, '30');
deferred[0]({ total_views: 1, data: [] });    // la prima arriva in ritardo
await new Promise(resolve => setTimeout(resolve, 0));
assert.equal(
  w.document.getElementById('faz-stat-pageviews').textContent,
  '30',
  'a stale response must not overwrite the range actually selected'
);

// The same applies to a stale FAILURE: an old request rejecting must not blank
// the panel that a newer, successful one has already filled.
const late = [];
w.FAZ.get = () => new Promise((resolve, reject) => late.push({ resolve, reject }));
w.chart({ days: 1 });
w.chart({ days: 30 });
late[1].resolve({ total_views: 42, data: [] });
await new Promise(resolve => setTimeout(resolve, 0));
late[0].reject(new Error('slow request gave up'));
await new Promise(resolve => setTimeout(resolve, 0));
assert.equal(
  w.document.getElementById('faz-stat-pageviews').textContent,
  '42',
  'a stale rejection must not blank a newer answer'
);

console.log('15 passed: disabled tracking, genuine zero, actual pageview total, request error, out-of-order responses');
dom.window.close();
