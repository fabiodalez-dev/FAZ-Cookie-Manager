/**
 * Shared browser scan engine — origin-safety regressions.
 *
 * Run: node tests/unit/js/scan-engine.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT = readFileSync(resolve(HERE, '../../../admin/assets/js/modules/scan-engine.js'), 'utf8');

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

async function runCrossOriginCase(publicUrl) {
  // Omitting the iframe host makes the attempt finish synchronously. For an
  // HTTP(S) public URL on another host, reaching `missingContainer` proves the
  // engine first rebased the path onto the observable admin origin instead of
  // rejecting it as cross-origin.
  const dom = new JSDOM('', {
    runScripts: 'outside-only',
    url: 'https://admin.example.test/wp-admin/admin.php?page=faz-cookie-manager-setup',
  });
  const calls = [];
  dom.window.fazConfig = { i18n: {} };
  dom.window.FAZ = {
    post(endpoint) {
      calls.push(endpoint);
      if (endpoint === 'scans/discover') {
        return Promise.resolve({ urls: [publicUrl], priority_urls: [], home_url: publicUrl });
      }
      return Promise.resolve({});
    },
  };
  dom.window.eval(SCRIPT);

  let error;
  try {
    await dom.window.FAZ.scanEngine.run({ maxPages: 20 });
  } catch (caught) {
    error = caught;
  }
  return { calls, error };
}

/**
 * Drive a real crawl in jsdom.
 *
 * jsdom never fetches an iframe's src, so the harness plays the browser: it
 * finds the iframes the engine created and fires their `load` events itself.
 * Timers are captured rather than run, so the settle schedule can be inspected
 * and stepped exactly. Everything below therefore EXECUTES the engine — commit
 * 29c9916 records a source-text test that stayed green while the feature threw
 * a ReferenceError on every import, which is the failure mode being avoided.
 */
function bootCrawl({ urls, maxPages = 20, importOutcomes = [], scanId }) {
  const dom = new JSDOM('<!doctype html><div id="faz-scan-frame"></div>', {
    runScripts: 'outside-only',
    url: 'https://example.test/wp-admin/admin.php?page=faz-cookies',
  });
  const { window } = dom;
  const posts = [];
  const timers = [];
  const intervals = [];
  let importAttempt = 0;
  let nextId = 1;

  window.setTimeout = (callback, delay = 0) => {
    const timer = { id: nextId++, callback, delay, active: true };
    timers.push(timer);
    return timer.id;
  };
  window.clearTimeout = (id) => {
    const timer = timers.find((item) => item.id === id);
    if (timer) timer.active = false;
  };
  window.setInterval = (callback, delay = 0) => {
    const interval = { id: nextId++, callback, delay, active: true };
    intervals.push(interval);
    return interval.id;
  };
  window.clearInterval = (id) => {
    const interval = intervals.find((item) => item.id === id);
    if (interval) interval.active = false;
  };

  window.fazConfig = { i18n: {}, api: { base: 'https://example.test/wp-json/faz/v1/', nonce: 'n0nce' } };
  const resolvers = [];
  window.FAZ = {
    post(endpoint, payload) {
      posts.push({ endpoint, payload });
      if (endpoint === 'scans/discover') {
        return Promise.resolve({ urls, priority_urls: [], home_url: 'https://example.test/' });
      }
      if (endpoint === 'scans/import') {
		const outcome = importOutcomes[importAttempt++];
		if (outcome instanceof Error || (outcome && outcome.reject)) {
			return Promise.reject(outcome instanceof Error ? outcome : outcome.reject);
		}
		return Promise.resolve(outcome || { total_cookies: 0 });
      }
      return Promise.resolve({});
    },
  };
  window.eval(SCRIPT);

  const api = {
    window,
    posts,
    timers,
    intervals,
    resolvers,
    frames() { return Array.from(window.document.querySelectorAll('#faz-scan-frame iframe')); },
    // Fire `load` on every iframe currently in flight.
    loadAll() {
      api.frames().forEach((frame) => frame.dispatchEvent(new window.Event('load')));
    },
    runTimer(delay) {
      const timer = timers.find((item) => item.active && item.delay === delay);
      if (!timer) return false;
      timer.active = false;
      timer.callback();
      return true;
    },
    activeDelays() {
      return timers.filter((item) => item.active).map((item) => item.delay);
    },
    run: null,
  };
  const runOptions = { maxPages };
  if (scanId !== undefined) runOptions.scanId = scanId;
  api.run = window.FAZ.scanEngine.run(runOptions, {});
  return api;
}

const settle = async () => { for (let i = 0; i < 12; i += 1) await Promise.resolve(); };

console.log('shared scan engine origin handling (6 checks)');

{
  const result = await runCrossOriginCase('https://public.example.test/');
  check('01 different public/admin hosts are retried through the admin origin', result.error?.stage === 'browser');
  check('02 diagnostics record both the retry and its later iframe-host blocker', result.error?.message.includes('retried through the WordPress admin origin')
    && result.error?.message.includes('container missing'));
  check('03 an unobservable scan never imports server-only fallback findings', result.calls[0] === 'scans/discover'
    && !result.calls.includes('scans/server-scan') && !result.calls.includes('scans/import'));
}

{
  const result = await runCrossOriginCase('https://www.admin.example.test/');
  check('04 www and apex paths also use the observable admin-origin retry', result.error?.stage === 'browser');
  check('05 the diagnostic identifies the admin-origin retry', result.error?.message.includes('retried through the WordPress admin origin'));
  check('06 the www mismatch also stops before enrichment and import', result.calls[0] === 'scans/discover'
    && !result.calls.includes('scans/server-scan') && !result.calls.includes('scans/import'));
}

/* ── Cancellation ──────────────────────────────────────────────────────
 *
 * A full crawl is a long foreground operation in an admin tab and nothing could
 * interrupt it: `stopped`/`noNewCount` were removed from the dispatch loop and
 * no caller had any flag to set. Closing the tab was the only exit, and it left
 * the capture lock held.
 */
console.log('\nscan cancellation (6 checks)');

{
  const app = bootCrawl({ urls: ['/a/', '/b/', '/c/', '/d/', '/e/', '/f/'] });
  await settle();

  check('07 the crawl starts CONCURRENCY pages, not all of them', app.frames().length === 2);
  check('08 run() exposes a cancel handle', typeof app.run.cancel === 'function');

  app.run.cancel();
  app.loadAll();
  // Both in-flight pages settle: first checkpoint, then the stable remainder.
  app.runTimer(1500);
  app.runTimer(1500);
  app.runTimer(1500);
  app.runTimer(1500);
  await settle();

  const imported = app.posts.find((call) => call.endpoint === 'scans/import');
  check('09 a cancel stops the dispatcher — no page beyond the in-flight ones is opened',
    app.frames().length === 0 && imported && imported.payload.pages_scanned === 2);
  check('10 the in-flight pages still settle and are imported',
    !!imported && imported.payload.pages_scanned > 0);
  check('11 the import declares the run incomplete via stoppedReason',
    !!imported && imported.payload.metrics.stoppedReason === 'cancelled');

  const result = await app.run;
  check('12 the resolved result carries stoppedReason for the caller coverage gate',
    result.stoppedReason === 'cancelled' && result.pagesScanned === 2);
}

/* ── Capture-window heartbeat ──────────────────────────────────────────
 *
 * The server-side capture session is an idle timeout opened once at discovery.
 * The capture path renews it on every scan-tagged page load, but a fully
 * page-cached site serves those pages off disk without booting PHP — so on
 * exactly the large sites whose crawls run longest, nothing renews it and the
 * import 409s after the whole crawl is done.
 */
console.log('\ncapture-window heartbeat (4 checks)');

{
  const app = bootCrawl({ urls: ['/a/'] });
  await settle();

  const beat = app.intervals.find((item) => item.active);
  check('13 a renewal interval is armed for the duration of the run', !!beat);
  check('14 it fires well inside the 900s capture window', !!beat && beat.delay > 0 && beat.delay < 900000);

  const before = app.posts.length;
  if (beat) beat.callback();
  await settle();
  const sent = app.posts.slice(before).find((call) => call.endpoint === 'scans/heartbeat');
  const discover = app.posts.find((call) => call.endpoint === 'scans/discover');
  check('15 the beat renews THIS scan session, by its own scan id',
    !!sent && !!discover && sent.payload.scan_id === discover.payload.scan_id);

  app.loadAll();
  app.runTimer(1500);
  app.runTimer(1500);
  await settle();
  await app.run;
  check('16 the interval is cleared once the run settles', app.intervals.every((item) => !item.active));
}

/* ── Settle schedule ───────────────────────────────────────────────────
 *
 * The per-page cost became an unconditional floor of firstWait + finalWait
 * rather than a best case. A page that produced nothing new pays the whole
 * budget for no observation — and at ~92 minutes of crawling, the capture
 * window closes and the entire run is discarded at import.
 */
console.log('\nsettle schedule (3 checks)');

{
  const app = bootCrawl({ urls: ['/a/'] });
  await settle();
  app.loadAll();

  // firstWait for the non-safe budget (settleTimeoutMs 3800).
  check('17 the first checkpoint is scheduled at firstWait', app.activeDelays().includes(1500));

  // Whatever the first checkpoint schedules next IS the remaining wait, so read
  // it by identity rather than by guessing which of the live delays it is (the
  // 3800 settle watchdog and the 15000 load fallback are also armed).
  const beforeCheckpoint = app.timers.length;
  app.runTimer(1500);
  const scheduled = app.timers.slice(beforeCheckpoint).filter((item) => item.active);

  // Nothing changed between the two reads, so the page is finalised at the
  // delayed-script window (3000ms post-load) instead of paying finalWait (1800)
  // on top of firstWait. Delete the fast path and the remaining wait becomes
  // 1800 — this goes red.
  check('18 a stable page finishes before the full budget elapses',
    scheduled.length === 1 && scheduled[0].delay < 1800);
  check('19 but not before the delayed-script window has passed',
    scheduled.length === 1 && 1500 + scheduled[0].delay >= 3000);
}

/* ── Scan depth on the wire ────────────────────────────────────────────
 *
 * The server's coverage gate decides whether a scan's silence about a cookie may
 * advance the consecutive-miss tally that eventually offers that cookie for
 * deletion. It could not check the DEPTH of the run, because the depth was never
 * in the payload: `metricsToSend` carried incremental/earlyStop/stopped and
 * nothing about how much of the site the administrator asked for. So a 20-page
 * sample of a 500-page site, finishing cleanly, arrived indistinguishable from a
 * full crawl.
 *
 * Driven rather than grepped: the payload is read off the actual import call the
 * engine makes. Remove either field from metricsToSend and these go red.
 */
console.log('\nscan depth reaches the server (4 checks)');

async function drainToImport(app) {
  app.loadAll();
  for (let step = 0; step < 40; step += 1) {
    await settle();
    const done = app.posts.find((call) => call.endpoint === 'scans/import');
    if (done) return done;
    // Smallest live delay first: firing the 15s load fallback ahead of the
    // 1.5s settle checkpoint would simulate a page that never loaded, which is
    // a different scenario from the one under test.
    const timer = app.timers
      .filter((item) => item.active)
      .sort((a, b) => a.delay - b.delay)[0];
    if (!timer) break;
    timer.active = false;
    timer.callback();
    app.loadAll();
  }
  await settle();
  return app.posts.find((call) => call.endpoint === 'scans/import');
}

{
  const app = bootCrawl({ urls: ['/a/'], maxPages: 20 });
  await settle();
  const imported = await drainToImport(app);
  check('20 a depth-capped run declares its cap to the server',
    !!imported && imported.payload.metrics.maxPages === 20 && imported.payload.metrics.isFullScan === false);
  // The pre-existing terms say nothing about depth on this run — which is
  // exactly why the server used to read it as full-site evidence.
  check('21 and it is otherwise indistinguishable from a full crawl, which is the whole point',
    !!imported && !imported.payload.metrics.incremental
      && !imported.payload.metrics.earlyStopReason && !imported.payload.metrics.stoppedReason);
  await app.run.catch(() => {});
}

{
  const app = bootCrawl({ urls: ['/a/'], maxPages: 0 });
  await settle();
  const imported = await drainToImport(app);
  check('22 a full scan declares maxPages 0, the same value the local coverage gate tests',
    !!imported && imported.payload.metrics.maxPages === 0 && imported.payload.metrics.isFullScan === true);
  await app.run.catch(() => {});
}

{
  // No depth given at all: the engine's own default is 20 pages, so it must not
  // report the run as uncapped just because the caller said nothing.
  const app = bootCrawl({ urls: ['/a/'], maxPages: undefined });
  await settle();
  const imported = await drainToImport(app);
  check('23 an unspecified depth reports the default cap, not a full scan',
    !!imported && imported.payload.metrics.maxPages === 20 && imported.payload.metrics.isFullScan === false);
  await app.run.catch(() => {});
}

/* ── Retryable import ──────────────────────────────────────────────────
 *
 * Persistence happens after the expensive crawl and after PHP captured
 * HttpOnly observations. A transient 500 must retry that same import instead
 * of rejecting to the UI, whose next click would create a new scan id and 409
 * against the still-active old session.
 */
console.log('\nretryable import keeps one scan session (9 checks)');

function apiFailure(status, code = 'faz_scan_import_failed') {
  const error = new Error(`HTTP ${status}`);
  error.status = status;
  error.code = code;
  return error;
}

/**
 * The shape wp.apiFetch actually rejects with: the decoded WP_Error body, whose
 * `data` array is where import_cookies() puts both the status and the hold flag.
 * Nothing is set at the top level, so this also proves the client reads the flag
 * from the same place it already reads the status.
 */
function heldApiFailure(held) {
  const error = new Error('HTTP 500');
  error.code = 'faz_scan_import_failed';
  error.data = { status: 500 };
  if (held !== undefined) error.data.faz_session_held = held;
  return error;
}

{
  const app = bootCrawl({
    urls: ['/a/'],
    importOutcomes: [{ reject: apiFailure(500) }, { total_cookies: 2 }],
  });
  await settle();
  await drainToImport(app);
  await settle();

  check('24 a transient persistence failure schedules the first bounded retry', app.activeDelays().includes(1000));
  app.runTimer(1000);
  await settle();
  const result = await app.run;
  const imports = app.posts.filter((call) => call.endpoint === 'scans/import');
  const aborts = app.posts.filter((call) => call.endpoint === 'scans/abort');
  check('25 the retry succeeds without a second crawl', imports.length === 2 && result.total === 2);
  check('26 every import attempt reuses the exact scan id',
    imports.length === 2 && imports[0].payload.scan_id === imports[1].payload.scan_id);
  check('27 every import attempt reuses the collected payload',
    imports.length === 2
      && JSON.stringify(imports[0].payload.cookies) === JSON.stringify(imports[1].payload.cookies)
      && JSON.stringify(imports[0].payload.scanned_urls) === JSON.stringify(imports[1].payload.scanned_urls));
  check('28 a recovered import never aborts the capture session', aborts.length === 0);
}

{
  const failure = () => ({ reject: apiFailure(500) });
  const app = bootCrawl({ urls: ['/a/'], importOutcomes: [failure(), failure(), failure()] });
  await settle();
  await drainToImport(app);
  await settle();
  app.runTimer(1000);
  await settle();
  app.runTimer(3000);
  await settle();

  let rejected;
  try {
    await app.run;
  } catch (error) {
    rejected = error;
  }
  const imports = app.posts.filter((call) => call.endpoint === 'scans/import');
  const abort = app.posts.find((call) => call.endpoint === 'scans/abort');
  check('29 retries stop after the configured two delays', imports.length === 3);
  // BEHAVIOUR CHANGE (was: "exhausted retries close the same session"). Aborting
  // deletes every observation the crawl produced, and those observations are the
  // only record of what the site set before consent — a save failure used to
  // cost the administrator a run that can take many minutes. The server now
  // holds the evidence for a retry (Controller::hold_browser_scan_session), and
  // a held session does not lock the next scan out: starting one reclaims it.
  check('30 an exhausted import failure holds the capture session instead of aborting it',
    rejected?.stage === 'import' && !abort);
  check('31 exhausted retries release the heartbeat', app.intervals.every((item) => !item.active));
  // The server said nothing about a hold here (no faz_session_held anywhere in
  // the error), and silence must never be read as a promise: offering a retry
  // against evidence that is gone sends the administrator into a 409.
  check('32 a failure that does not say the session was held reports sessionHeld false',
    rejected?.sessionHeld === false && typeof rejected?.retryImport !== 'function');
}

/* ── Held import: retry the SAVE, not the scan ─────────────────────────
 *
 * The expensive half of a scan is the crawl. When only persistence fails, the
 * server keeps the session, its observations and the marker cookie alive, and
 * says so in `data.faz_session_held`. The engine must surface that fact and a
 * way to act on it that does not walk the site again.
 */
console.log('\nheld import offers a save-only retry (7 checks)');

{
  const app = bootCrawl({
    urls: ['/a/'],
    importOutcomes: [
      { reject: heldApiFailure(true) },
      { reject: heldApiFailure(true) },
      { reject: heldApiFailure(true) },
      { total_cookies: 4, duplicate: true },
    ],
  });
  await settle();
  await drainToImport(app);
  await settle();
  app.runTimer(1000);
  await settle();
  app.runTimer(3000);
  await settle();

  let rejected;
  try {
    await app.run;
  } catch (error) {
    rejected = error;
  }

  const failedImports = app.posts.filter((call) => call.endpoint === 'scans/import');
  check('33 the hold flag is read out of the WP_Error data, where the server put it',
    rejected?.sessionHeld === true);
  check('34 the rejection names the scan whose evidence is being held',
    !!rejected?.scanId && rejected.scanId === failedImports[0].payload.scan_id);
  check('35 nothing aborts, so the held observations survive the failure',
    !app.posts.some((call) => call.endpoint === 'scans/abort'));
  check('36 a retry handle is offered only because the server said the evidence is there',
    typeof rejected?.retryImport === 'function');

  const framesBefore = app.frames().length;
  const retried = await rejected.retryImport();
  const allImports = app.posts.filter((call) => call.endpoint === 'scans/import');
  const discovers = app.posts.filter((call) => call.endpoint === 'scans/discover');
  check('37 the retry resubmits the same payload under the same scan id — no second crawl',
    allImports.length === failedImports.length + 1
      && discovers.length === 1
      && framesBefore === 0
      && app.frames().length === 0
      && allImports[allImports.length - 1].payload.scan_id === failedImports[0].payload.scan_id
      && JSON.stringify(allImports[allImports.length - 1].payload.cookies)
        === JSON.stringify(failedImports[0].payload.cookies));
  check('38 a successful retry resolves the same result shape the first import would have',
    retried && retried.total === 4 && retried.pagesScanned === 1
      && Array.isArray(retried.cookies) && retried.scanId === rejected.scanId);
  // `duplicate` is the server saying "an earlier submission already saved this";
  // it must reach the caller so the page can say so instead of announcing a
  // fresh import that never happened.
  check('39 the duplicate marker reaches the caller untouched',
    retried?.importResult?.duplicate === true);
}

/* ── Caller-supplied scan id ───────────────────────────────────────────
 *
 * Re-entering a held session requires the SAME id: a fresh one is a different
 * scan to the server, and start_browser_scan_session() reclaims — i.e. deletes —
 * the held evidence to make room for it.
 */
console.log('\ncaller-supplied scan id (4 checks)');

{
  const supplied = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
  const app = bootCrawl({ urls: ['/a/'], scanId: supplied });
  await settle();
  const discover = app.posts.find((call) => call.endpoint === 'scans/discover');
  check('40 a supplied scan id is used instead of minting a new one',
    !!discover && discover.payload.scan_id === supplied);
  check('41 and it is readable off the run handle before it settles',
    app.run.scanId === supplied);
  const imported = await drainToImport(app);
  check('42 the whole run stays on the supplied id, through to import',
    !!imported && imported.payload.scan_id === supplied);
  await app.run.catch(() => {});
}

{
  const first = bootCrawl({ urls: ['/a/'] });
  const second = bootCrawl({ urls: ['/a/'] });
  await settle();
  check('43 an absent scanId still mints a fresh 32-hex id per run',
    /^[a-f0-9]{32}$/.test(first.run.scanId)
      && /^[a-f0-9]{32}$/.test(second.run.scanId)
      && first.run.scanId !== second.run.scanId);
  await drainToImport(first);
  await drainToImport(second);
  await first.run.catch(() => {});
  await second.run.catch(() => {});
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
