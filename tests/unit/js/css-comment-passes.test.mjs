/**
 * bootstrap.js reads the URLs a stylesheet names with three passes, and the
 * comment-stripped one may only ADD to the others.
 *
 * CSS_COMMENT cannot tell where the tokenizer is: `/*` inside an unquoted
 * url(…) is URL text, and inside an unterminated ("bad") string it starts
 * nothing. Stripping from there to a later `*\/` deleted an escape-spelled
 * tracker from the only pass that decoded escapes, so cssUrlsAll() missed it.
 * The runtime twin is covered by runtime-css-and-routes.test.mjs and the
 * server one by test-inline-style-blocking-php.php.
 *
 * The functions are sliced out of the shipped file (from the first CSS_ regex
 * to the end of cssUrlsAll) and evaluated on their own.
 *
 * Run: node tests/unit/js/css-comment-passes.test.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const code = readFileSync(new URL('../../../frontend/js/bootstrap.js', import.meta.url), 'utf8');
const start = code.indexOf('    var CSS_URL = ');
const fnAt = code.indexOf('    function cssUrlsAll(');
const end = code.indexOf('\n    }\n', fnAt) + 7;
assert.ok(start > 0 && fnAt > start && end > fnAt, 'the CSS readers are where this test expects them');
const cssUrlsAll = new Function(code.slice(start, end) + '\nreturn cssUrlsAll;')();

let passed = 0;
const names = (css, host) => cssUrlsAll(css).some((u) => String(u).indexOf(host) !== -1);
for (const [label, css] of [
  ['a comment between @import and its string', '@import/*c*/"https://tracker.test/c.css";'],
  ['/* inside an unquoted url() before an escaped tracker url()', '.a{background:u\\72l(https://cdn.example/*x)}.b{background:u\\72l(https://tracker.test/c.png)}/**/'],
  ['a bad string before an escaped @import', '.q{content:"/*\n}@\\69mport "https://tracker.test/c.css";/**/'],
]) {
  assert.equal(names(css, 'tracker.test'), true, label);
  ++passed;
  console.log('  PASS ' + label);
}
assert.equal(names('.q:before{content:"/*"}.r{color:red}', 'tracker.test'), false, 'control');
++passed;
console.log(`css-comment-passes: ${passed} passed, 0 failed`);
