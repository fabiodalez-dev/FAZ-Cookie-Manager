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
 * Every hand-written version of that proof disagreed with the tokenizer
 * somewhere, and every disagreement was a consent bypass: `</script foo>` and
 * `</script/>` close script text, `--!>` and `<!-->` close comments, a comment
 * before a <script> wins over it, `<image>` becomes an <img>, and an end tag
 * split across two writes is still one end tag. So the predicate now asks the
 * engine's own parser, on an inert DOMParser document that fetches nothing,
 * and adds exactly one rule the parser cannot answer from a buffer: a tag left
 * unterminated at the end, which a string parse discards at EOF but the real
 * input stream completes.
 *
 * Every "withdraws" row below is a bypass that existed.
 *
 * The predicate is block-scoped inside the bootstrap IIFE, so it is extracted
 * by source markers and evaluated on its own. If the markers move, this test
 * fails loudly rather than silently testing nothing.
 *
 * Run: node tests/unit/js/bootstrap-write-stream.test.mjs
 */

import { JSDOM } from 'jsdom';
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

const OPEN = "    var wrote = false;\n    var written = '';";
const CLOSE = "    ['write', 'writeln', 'open'].forEach";
const from = SOURCE.indexOf(OPEN);
const to = SOURCE.indexOf(CLOSE);
if (from === -1 || to === -1 || to < from) {
  console.log('  \x1b[31mFAIL\x1b[0m could not locate noteWrite() in bootstrap.js — update the markers in this test');
  process.exit(1);
}
const body = SOURCE.slice(from, to);
eq('premise: the extracted slice is the predicate', /function noteWrite\(/.test(body), true);

const RESOURCES = /var RESOURCES = '([^']+)'/.exec(SOURCE);
eq('premise: the resource element list was found', !!RESOURCES, true);

// The predicate reads `w` (for DOMParser) and the file's RESOURCES list from
// its enclosing scope, so both are injected. jsdom supplies the parser, which
// is the point: the test exercises the real tokenizer, not a model of it.
const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { url: 'http://site.test/' });

// A fresh one per case: the flag and the accumulated stream are state.
function scanner() {
  // eslint-disable-next-line no-new-func
  return new Function('w', 'RESOURCES', `${body}; return { note: noteWrite, withdrew: function () { return wrote; } };`)(dom.window, RESOURCES[1]);
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
  [[['<script>a</scr'], ['ipt>']], false, 'an end tag split across two calls, with nothing after it'],
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
  // Corrected BY the parser: <frame> outside a frameset is dropped in body
  // insertion mode, so nothing is built and nothing fetches. A hand-written
  // predicate withdrew here for nothing.
  [[['<frame src=x>']], false, 'a <frame> in body context, which the parser drops'],
  // An SVG <image href> is outside the element set this file and the runtime's
  // observer guard at all, so the exemption is irrelevant to it: it was never
  // judged before this change either. Recorded as the limit it is.
  [[['<svg><image href=x></svg>']], false, 'an svg image (outside the guarded element set — known limit)'],
  // Markup with nothing to judge does not withdraw: the predicate asks the
  // backstop's own element list, so a written <p> changes nothing about what
  // the backstop would have done.
  [[['<p>plain</p>']], false, 'an element that fetches nothing'],
  [[['<!x><img src=t>']], true, 'a bogus comment followed by an image'],
  [[['<div id=x>'], ['<img src=t>']], true, 'plain markup first, a resource in the next call'],
  // Five ways to put a hand-rolled scanner into a false "inside a script"
  // state, after which it read everything as script text. All five are moot
  // against the engine's parser, which is the argument for asking it.
  [[['<!x<script><img src=t>']], true, 'a <script> inside a bogus <! comment'],
  [[['<?x<script><img src=t>']], true, 'a <script> inside a bogus <? comment'],
  [[['</ <script><img src=t>']], true, 'a <script> inside a bogus </ comment'],
  [[['<!--!><script>--><img src=t>']], true, 'a comment end that overlaps its own opener'],
  [[["</script x='>'<script>'><img src=t>"]], true, 'a quoted > inside an end tag'],
  [[['<script src="/a.js"></script>'], ['<im'], ['g src=x>']], true, 'a script first and a resource three calls later'],
  [[['<script>x</scr'], ['ipt><img src=t>']], true, 'an end tag split across two writes is still one end tag'],
  [[['<video src=x>']], true, 'a video'],
  [[['<object data=x>']], true, 'an object'],
  [[['<link rel=stylesheet href=x>']], true, 'a stylesheet link'],
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
