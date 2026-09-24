/**
 * The "Copy status" snapshot is one `Label: value` line per table row.
 *
 * `textContent` hands back the template's own newlines and tabs — the browser
 * collapses whitespace when it PAINTS, not when it reads — so the moment a view
 * author wrote a `<td>` across several source lines, the snapshot people paste
 * into bug reports grew a ragged, tab-indented continuation line with no label
 * in front of it. The Consent Records Refused row added for issue #292 was the
 * first such cell on the page, which is why nothing had caught this before.
 *
 * `<br>` is the mirror image of the same mistake: it contributes no text at
 * all, so the Active Plugins list came out as one run-together string.
 *
 * Run: node tests/unit/js/system-status-copy-format.test.mjs
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../admin/assets/js/pages/system-status.js');

let passed = 0;
let failed = 0;
function ok(label, condition, detail) {
  if (condition) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
    if (detail !== undefined) {
      console.log(`       ${JSON.stringify(detail)}`);
    }
  }
}

// The cell shape the PHP view actually emits: the opening tag is followed by a
// newline and seven tabs, the help text sits in a nested element after a <br>,
// and the closing tag is preceded by more indentation. Reproduced verbatim
// rather than tidied, because tidy markup is exactly what would hide the bug.
const REFUSED_CELL = [
  '',
  '\t\t\t\t\t\t\t2.002 in the last 7 days — consent decisions that were not recorded. Most recent: 3 hours ago.',
  '\t\t\t\t\t\t\t<div class="faz-status-detail">Stale origin token: 1.998 &middot; No same-origin signal: 4</div>',
  '\t\t\t\t\t\t\t<div class="faz-help">',
  '\t\t\t\t\t\t\tA stale origin token means HTML was served from a cache older than the accepted token window.',
  '\t\t\t\t\t\t\t</div>',
  '\t\t\t\t\t\t',
].join('\n');

function copiedText() {
  const dom = new JSDOM(
    `<!DOCTYPE html><html><body>
      <button id="faz-copy-status">Copy</button>
      <div id="faz-system-status">
        <div class="faz-card">
          <div class="faz-card-header"><h3>
            Configuration
          </h3></div>
          <div class="faz-card-body">
            <table class="faz-status-table">
              <tr><td>Banner Enabled</td><td>Yes</td></tr>
              <tr><td>Plugin Version</td><td><code>1.32.1</code></td></tr>
              <tr><td>Consent Records Refused</td><td>${REFUSED_CELL}</td></tr>
              <tr><td>Auto Scan</td><td>Yes &mdash; weekly</td></tr>
            </table>
          </div>
        </div>
        <div class="faz-card">
          <div class="faz-card-header"><h3>Active Plugins</h3></div>
          <div class="faz-card-body">
            <div style="line-height:1.8;">
              Akismet 5.3<br>WooCommerce 9.1<br>FAZ Cookie Manager 1.32.1
            </div>
          </div>
        </div>
      </div>
    </body></html>`,
    { runScripts: 'outside-only', url: 'https://example.test/wp-admin/' }
  );
  const { window } = dom;

  let captured = null;
  window.navigator.clipboard = { writeText: (t) => { captured = t; return Promise.resolve(); } };
  window.FAZ = { notify: () => {} };

  window.eval(readFileSync(SCRIPT_PATH, 'utf8'));
  window.document.getElementById('faz-copy-status').dispatchEvent(new window.Event('click'));
  return captured;
}

const text = copiedText();
ok('the button produces a snapshot', typeof text === 'string' && text.length > 0);

const lines = text.split('\n');
const rowLines = lines.filter(
  (l) => l.includes(': ') && !l.startsWith('=') && !l.startsWith('-')
);

ok('no line carries a tab', !text.includes('\t'), lines.filter((l) => l.includes('\t')));
ok(
  'no line begins or ends with whitespace',
  lines.every((l) => l === l.trim()),
  lines.filter((l) => l !== l.trim())
);
ok('one line per table row', rowLines.length === 4, rowLines);
ok(
  'the refused row is a single line',
  rowLines.filter((l) => l.startsWith('Consent Records Refused: ')).length === 1
);

const refused = rowLines.find((l) => l.startsWith('Consent Records Refused: ')) || '';
ok('it keeps the count', refused.includes('2.002 in the last 7 days'));
ok('it keeps the per-cause breakdown', refused.includes('Stale origin token: 1.998'));
ok(
  'and it keeps the help sentence rather than dropping it',
  refused.includes('accepted token window')
);

ok(
  'a <code> value keeps its exact text',
  rowLines.some((l) => l === 'Plugin Version: 1.32.1'),
  rowLines
);
ok(
  'an entity-separated value keeps its spacing',
  rowLines.some((l) => l === 'Auto Scan: Yes — weekly'),
  rowLines
);
ok(
  'a multi-line heading is flattened',
  lines.some((l) => l === 'Configuration')
);

// The list branch: <br> has to become a line break, not nothing.
ok(
  'a <br>-separated list is not run together',
  !text.includes('Akismet 5.3WooCommerce'),
  lines.filter((l) => l.includes('Akismet'))
);
ok(
  'each list entry is its own line',
  ['Akismet 5.3', 'WooCommerce 9.1', 'FAZ Cookie Manager 1.32.1'].every((e) =>
    lines.includes(e)
  ),
  lines
);

console.log(`\nPassed: ${passed}; Failed: ${failed}`);
process.exit(failed > 0 ? 1 : 0);
