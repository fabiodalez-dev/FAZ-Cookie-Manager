/**
 * JS unit test (jsdom) — accessibility enhancements survive banner replacement.
 *
 * Browser-language detection paints the server-default banner, then replaces
 * the complete top-level container with localized template DOM. The public
 * fazcookie_banner_loaded event is intentionally one-shot (analytics also
 * consumes it), so a11y.js must notice the new DOM instance without requiring
 * a second event or applying its structural transforms twice to one instance.
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const A11Y_PATH = resolve(HERE, '../../../frontend/js/a11y.js');

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

function bannerMarkup(language) {
  return `
    <div class="faz-consent-container" role="region" data-language="${language}">
      <section data-faz-tag="notice">
        <p class="faz-title" role="heading" aria-level="1" data-faz-tag="title">${language} title</p>
        <button data-faz-tag="close-button">Close</button>
      </section>
      <div class="faz-modal">
        <div class="faz-preference-center">
          <span class="faz-preference-title" role="heading" aria-level="1" data-faz-tag="detail-title">${language} preferences</span>
          <div data-faz-tag="detail-description">
            <button data-faz-tag="show-desc-button">More</button>
          </div>
          <div class="faz-accordion">
            <div><button aria-label="Analytics" data-faz-tag="detail-category-title">Analytics</button></div>
            <label data-faz-tag="detail-category-toggle"><input type="checkbox"></label>
          </div>
        </div>
      </div>
    </div>`;
}

function assertEnhanced(window, language) {
  const { document } = window;
  check(`${language}: banner title is the labelled h2`, !!document.querySelector('h2.faz-title#faz-banner-title'));
  check(`${language}: modal title is the labelled h2`, !!document.querySelector('h2.faz-preference-title#faz-modal-title'));
  check(`${language}: category button has exactly one h3 wrapper`, document.querySelectorAll('h3.faz-accordion-heading > [data-faz-tag="detail-category-title"]').length === 1);
  check(`${language}: banner dialog points to its title`, document.querySelector('.faz-consent-container')?.getAttribute('aria-labelledby') === 'faz-banner-title');
  check(`${language}: preference center points to its title`, document.querySelector('.faz-preference-center')?.getAttribute('aria-labelledby') === 'faz-modal-title');
  check(`${language}: category checkbox has switch semantics and a state label`, !!document.querySelector('[data-faz-tag="detail-category-toggle"] input[role="switch"][aria-label]'));
  check(`${language}: description control points to the stable wrapper`, document.querySelector('[data-faz-tag="show-desc-button"]')?.getAttribute('aria-controls') === 'faz-desc-content');
}

const dom = new JSDOM(`<!DOCTYPE html><html><body>${bannerMarkup('default')}</body></html>`, {
  runScripts: 'outside-only',
  url: 'https://example.test/',
});
const { window } = dom;

// Exercise the late-listener fallback: the one-shot event happened before the
// a11y asset ran, but script.js recorded it synchronously before dispatch.
window._fazBannerLoaded = true;
window.eval(readFileSync(A11Y_PATH, 'utf8'));
assertEnhanced(window, 'default');

// Match _fazReRenderVisibleBanner(): insert a pristine localized container and
// remove the already-enhanced one without firing fazcookie_banner_loaded again.
const holder = window.document.createElement('div');
holder.innerHTML = bannerMarkup('localized');
window.document.querySelector('.faz-consent-container').replaceWith(holder.firstElementChild);

// MutationObserver callbacks run in a microtask after the synchronous swap.
await new Promise((resolvePromise) => window.setTimeout(resolvePromise, 0));
assertEnhanced(window, 'localized');

// An unrelated top-level mutation must not re-run init() and nest another h3.
window.document.body.appendChild(window.document.createElement('div'));
await new Promise((resolvePromise) => window.setTimeout(resolvePromise, 0));
check('same banner instance is not transformed twice', window.document.querySelectorAll('h3.faz-accordion-heading').length === 1);


for (const tag of ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span', 'script', '<img>', null]) {
  const testDom = new JSDOM(`<!doctype html><body>${bannerMarkup('custom')}</body>`, {runScripts:'outside-only', url:'https://example.test/'});
  const w = testDom.window;
  w.fazA11yConfig = {bannerTitleTag:tag, preferenceTitleTag:tag, categoryTitleTag:tag};
  w._fazBannerLoaded = true;
  const button = w.document.querySelector('[data-faz-tag="detail-category-title"]');
  let clicks = 0;
  button.addEventListener('click', () => clicks++);
  w.eval(readFileSync(A11Y_PATH, 'utf8'));
  const valid = ['h1','h2','h3','h4','h5','h6','p','div','span'].includes(tag);
  check(`${tag}: banner tag validated`, w.document.getElementById('faz-banner-title').localName === (valid ? tag : 'h2'));
  check(`${tag}: preference tag validated`, w.document.getElementById('faz-modal-title').localName === (valid ? tag : 'h2'));
  check(`${tag}: category wrapper validated`, button.parentElement.localName === (valid ? tag : 'h3'));
  check(`${tag}: heading role is not forced back onto plain text`, !w.document.getElementById('faz-banner-title').hasAttribute('role') && !w.document.getElementById('faz-modal-title').hasAttribute('aria-level'));
  button.click();
  check(`${tag}: original interactive control and listener preserved`, clicks === 1);
  check(`${tag}: dialog name still references the title`, w.document.querySelector('.faz-consent-container').getAttribute('aria-labelledby') === 'faz-banner-title');
  const replacement = w.document.createElement('div');
  replacement.innerHTML = bannerMarkup('translated');
  w.document.querySelector('.faz-consent-container').replaceWith(replacement.firstElementChild);
  await new Promise(resolve => w.setTimeout(resolve, 0));
  check(`${tag}: language replacement keeps custom tag`, w.document.getElementById('faz-banner-title').localName === (valid ? tag : 'h2'));
  check(`${tag}: language replacement does not nest wrappers`, w.document.querySelectorAll('.faz-accordion-heading').length === 1);
}
console.log(`\n  a11y-banner-replacement: ${passed} passed, ${failed} failed`);
if (failed > 0) process.exit(1);
