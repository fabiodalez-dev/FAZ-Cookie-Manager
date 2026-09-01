/**
 * JS unit test (jsdom) — no part of the consent notice may become unreachable.
 *
 * The description truncation loop bails with `return` when it reaches the LAST
 * paragraph. Upstream CookieYes changed that to `continue` in 3.5.x, and the
 * change looks like an obvious bug fix: `return` aborts the whole function, so
 * an over-long description is left untruncated.
 *
 * It is the opposite of a fix, and the loop's own structure proves it. The
 * show-more button is appended only when adding the current paragraph would
 * cross the limit — and immediately after that, `strippedLen > contentLimit` is
 * necessarily true, so the loop breaks. Reaching the last index therefore
 * ALWAYS means no button was appended:
 *
 *   paragraphs [100,100,100,100] limit 150 -> breaks early, button appended
 *   paragraphs  [50,50,50,300]   limit 200 -> reaches last index, NO button
 *   paragraphs   [10,10,10,10]   limit 1000 -> reaches last index, NO button
 *
 * With `continue`, that second case renders a truncated fragment missing its
 * final paragraph and offers no way to expand it: text the visitor was supposed
 * to read before consenting simply disappears. Leaving the description
 * untruncated is the correct failure mode for a consent notice.
 *
 * This file exists so the "obvious" upstream fix is not adopted later by someone
 * reading the diff and not the loop.
 *
 * Run: node tests/unit/js/description-truncation.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');

let passed = 0;
let failed = 0;
function ok(label, cond) {
  if (cond) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
  }
}

/**
 * Render a description of N paragraphs through the real truncation helper and
 * report which paragraphs survived and whether a show-more control exists.
 */
function truncate(paragraphs) {
  const dom = new JSDOM(
    `<!DOCTYPE html><html><body>
       <div class="faz-consent-container">
         <div data-faz-tag="detail-description">${paragraphs.map((t) => `<p>${t}</p>`).join('')}</div>
       </div>
     </body></html>`,
    { runScripts: 'outside-only', url: 'http://localhost/' },
  );
  const { window } = dom;
  // The real requirements, read off _fazSetShowMoreLess rather than guessed: it
  // needs both shortcodes, the `detail-description` tag for a GDPR banner, and a
  // textContent longer than the hard-coded limit (300 above 375px wide). An
  // earlier draft supplied a `contentLimit` config key that does not exist, so
  // the function bailed at its first guard and every assertion passed against a
  // function that had not run.
  window._fazConfig = {
    _categories: [{ slug: 'necessary', isNecessary: true }],
    _services: [], _providersToBlock: [], _userWhitelist: [], i18n: {},
    _shortCodes: [
      { key: 'faz_show_desc', content: '<button data-faz-tag="show-desc-button">Show more</button>' },
      { key: 'faz_hide_desc', content: '<button data-faz-tag="hide-desc-button">Show less</button>' },
    ],
    _bannerConfig: {
      settings: { type: 'box', preferenceCenterType: 'popup', applicableLaw: 'gdpr' },
      config: {},
    },
  };
  Object.defineProperty(window.document, 'readyState', { get: () => 'loading', configurable: true });
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (t, cb, ...r) => (t === 'DOMContentLoaded' ? undefined : realAdd(t, cb, ...r));
  window.setTimeout = () => 0;
  window.requestAnimationFrame = (cb) => { cb(); return 0; };
  window.console.error = () => {};
  window.console.warn = () => {};

  window.eval(readFileSync(SCRIPT_PATH, 'utf8'));
  window.document.addEventListener = realAdd;
  window.fazcookie._fazSetShowMoreLess();

  const el = window.document.querySelector('[data-faz-tag="detail-description"]');
  return {
    text: el ? el.textContent : '',
    hasShowMore: !!window.document.querySelector('[data-faz-tag="show-desc-button"]'),
  };
}

function run() {
  // The load-bearing case: the limit is only crossed at the LAST paragraph, so
  // the loop reaches the final index with no button appended. Upstream's
  // `continue` drops that paragraph here.
  {
    const paras = ['A'.repeat(50), 'B'.repeat(50), 'C'.repeat(50), 'D'.repeat(300)];
    const r = truncate(paras);
    const lastPresent = r.text.indexOf('D'.repeat(50)) !== -1;
    ok(
      'the final paragraph is never dropped without a way to expand it',
      lastPresent || r.hasShowMore,
    );
  }

  // Nothing near the limit: the description is untouched and no control appears.
  {
    const r = truncate(['short one', 'short two', 'short three']);
    ok('a short description keeps every paragraph', r.text.indexOf('short three') !== -1);
    ok('and grows no show-more control it does not need', !r.hasShowMore);
  }

  // Genuine truncation, well before the end: this is the case the feature is
  // for, and it must keep working — a "fix" that simply never truncates would
  // satisfy the first case on its own.
  {
    const paras = ['E'.repeat(300), 'F'.repeat(300), 'G'.repeat(300), 'H'.repeat(300)];
    const r = truncate(paras);
    ok('a long description still gets a show-more control', r.hasShowMore);
  }

  console.log(`\n  description-truncation: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

run();
