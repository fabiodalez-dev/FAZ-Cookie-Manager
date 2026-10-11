/**
 * JS unit test — the predicate that decides whether a document.write has made
 * the parser's insertions unattributable.
 *
 * The deferred bootstrap leaves markup the SERVER rendered alone: the
 * server-side blocker is the authority for it, the parser has already started
 * those requests, and stripping them costs a second fetch and the LCP element.
 * It can do that only while it can tell a parser insertion from a script's.
 * document.write destroys that distinction — its argument goes to the parser's
 * input stream, so there are no nodes to tag when the call returns — so the
 * exemption is withdrawn unless the written stream is PROVABLY nothing but
 * script elements, comments and text.
 *
 * Proving an absence is the only safe shape here: a list of resource tags has
 * to be complete to be sound, and it never is. But the proof has to agree with
 * the tokenizer, and that is what these cases pin. Every "expected: true" row
 * below is a case where a regex-based version of this predicate said "no
 * resource markup" while the parser built an <img> — a consent bypass, each one
 * either found in review or by the probe that replaced it.
 *
 * The predicate is block-scoped inside the bootstrap IIFE, so it is extracted
 * by source markers and evaluated on its own. If the markers move, this test
 * fails loudly rather than silently testing nothing.
 *
 * Run: node tests/unit/js/bootstrap-write-stream.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SOURCE = readFileSync(resolve(HERE, '../../../frontend/js/bootstrap.js'), 'utf8');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
    console.log(`       expected: ${JSON.stringify(expected)}`);
    console.log(`       actual:   ${JSON.stringify(actual)}`);
  }
}

const OPEN = '    var wrote = false;\n    var inScript = false;';
const CLOSE = "    ['write', 'writeln', 'open'].forEach";
const from = SOURCE.indexOf(OPEN);
const to = SOURCE.indexOf(CLOSE);
if (from === -1 || to === -1 || to < from) {
  console.log('  \x1b[31mFAIL\x1b[0m could not locate noteWrite() in bootstrap.js — update the markers in this test');
  process.exit(1);
}
const body = SOURCE.slice(from, to);
eq('premise: the extracted slice is the predicate', /function noteWrite\(/.test(body), true);

// A fresh scanner per case: the flag and the carried tail are state.
function scanner() {
  // eslint-disable-next-line no-new-func
  return new Function(`${body}; return { note: noteWrite, withdrew: function () { return wrote; } };`)();
}

// [calls, withdraws, label] — one call is one write(), its array the arguments.
const CASES = [
  [[['<script src="/x.js"></script>']], false, 'a script-only write keeps the exemption (guard() declines every SCRIPT)'],
  [[['<script>a</script>']], false, 'a closed script with nothing after it'],
  [[['<script>var a="<img src=x>";</script>']], false, 'markup inside a script body is text, not markup'],
  [[['<script src="a>b" x=">"><img src=t></script>']], false, 'a > inside a quoted attribute value does not end the tag'],
  [[['<!-- <img src=x> -->']], false, 'a commented-out image is not an image'],
  [[['<!-- unterminated <img src=x>']], false, 'a comment with no end runs past the written text'],
  [[['3 < 5 and 7 > 2']], false, 'a < that cannot start a tag is text'],
  [[['<!doctype html>']], false, 'a doctype is not an element'],
  [[['<scri'], ['pt src="/x.js"></script>']], false, 'a <script tag split across two calls'],
  // Each of these was a bypass.
  [[['<script>x</script foo><img src=t>']], true, '</script foo> ends the script for the tokenizer'],
  [[['<script></script x><img src=t>']], true, '</script x> ends it too'],
  [[['<script>x</script/><img src=t>']], true, 'and </script/>'],
  [[['<!--><img src=t>']], true, '<!--> is already a complete comment'],
  [[['<!---><img src=t>']], true, 'so is <!--->'],
  [[['<!-- x --!><img src=t>']], true, '--!> ends a comment as well as -->'],
  [[['<!-- <script> --><img src=t>']], true, 'a <script> inside a comment is not a script (one left-to-right pass)'],
  [[['<img src="https://t/x.gif" alt=""']], true, 'an unterminated tag is completed by the stream that follows it'],
  [[['<im'], ['g src=x>']], true, 'a tag split across two calls'],
  [[['<im', 'g src=x>']], true, 'a tag split across two arguments of one call'],
  [[['<image src=x>']], true, 'the <image> alias the parser turns into <img>'],
  [[['<frame src=x>']], true, '<frame>, which no tag list had'],
  [[['<svg><image href=x></svg>']], true, 'an svg image'],
  [[['<p>plain</p>']], true, 'any other element at all'],
  [[['<!x><img src=t>']], true, 'a bogus comment followed by an image'],
  [[['<script src="/a.js"></script>'], ['<im'], ['g src=x>']], true, 'a script first and a resource three calls later'],
];

console.log('\nthe written stream is judged the way the tokenizer reads it');
for (const [calls, withdraws, label] of CASES) {
  const scan = scanner();
  for (const args of calls) scan.note(args);
  eq(`${withdraws ? 'withdraws' : 'keeps the exemption'}: ${label}`, scan.withdrew(), withdraws);
}

console.log('');
console.log(`bootstrap-write-stream: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
