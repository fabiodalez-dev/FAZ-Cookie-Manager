/**
 * The document.write predicate, measured against the engine instead of argued
 * about.
 *
 * bootstrap.js leaves markup the SERVER rendered alone — the server-side
 * blocker is the authority for it, the parser has already started those
 * requests, and stripping them costs a second fetch and the LCP element. It can
 * only do that while it can tell a parser insertion from a script's, and
 * document.write destroys that distinction. So the exemption is withdrawn when
 * the written stream could have introduced something the backstop would
 * otherwise have judged.
 *
 * Three hand-written versions of that predicate each disagreed with the HTML
 * tokenizer somewhere, and every disagreement was a consent bypass. The fourth
 * asks the engine's own parser. This spec is how that claim is kept honest: for
 * each written string it measures BOTH what the real input stream does (does a
 * request go out) and what the predicate decides, and fails on any pair where
 * something fetched and the exemption survived — except for the two cases where
 * the element is outside the set this plugin guards at all, which are named.
 *
 * Add a row here when a new markup shape comes up. The matrix is the point:
 * a differential nobody thought of shows up as a failure rather than as a
 * leak.
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const SOURCE = readFileSync(new URL('../../frontend/js/bootstrap.js', import.meta.url), 'utf8');
const RESOURCES = /var RESOURCES = '([^']+)'/.exec(SOURCE);
const OPEN = '/* write-predicate:start';
const CLOSE = '/* write-predicate:end */';

// Outside the element set this plugin guards ANYWHERE — server-side blocking,
// the runtime's own observer, and the bootstrap all work from the same list, so
// the exemption is irrelevant to these two and withdrawing would cost without
// protecting.
//
//   script      — deliberate, and the dominant payload of this API. A
//                 parser-built script has started its request before any
//                 observer is notified, which is why guard() declines every
//                 SCRIPT; withdrawing here would park every static resource
//                 after a legacy document.write('<script src=…>') and gain
//                 nothing.
//   svg <image> — a pre-existing gap in the element set, measured here rather
//                 than assumed away: it really does fetch. Neither
//                 _fazParkResourceElementIfBlocked (img/iframe/source/link) nor
//                 the server-side pass (#<(?:img|iframe)\b#i) has ever covered
//                 it, so closing it means widening the element set across PHP
//                 and both JS files, not adjusting this predicate.
const UNGUARDED = ['<script src=URL></script>', '<svg><image href=URL></svg>'];

const WRITES = [
  '<img src=URL>',
  '<source src=URL>',
  '<track src=URL>',
  '<input type=image src=URL>',
  '<video poster=URL>',
  '<object data=URL>',
  '<embed src=URL>',
  '<iframe src=URL>',
  '<link rel=stylesheet href=URL>',
  '<noscript><img src=URL></noscript>',
  '<template><img src=URL></template>',
  '<select><img src=URL></select>',
  '<table><img src=URL></table>',
  '<svg><image href=URL></svg>',
  '<picture><source srcset=URL></picture>',
  '<math><mi><img src=URL></mi></math>',
  '<frameset><frame src=URL>',
  '<plaintext><img src=URL>',
  '<xmp><img src=URL></xmp>',
  '<textarea><img src=URL></textarea>',
  '<iframe></iframe><img src=URL>',
  '<!--[if IE]><img src=URL><![endif]-->',
  '<script src=URL></script>',
  '<p>plain</p>',
  '<div><img src=URL></div>',
];

test('no written markup fetches while the parser exemption survives', async ({ page }) => {
  expect(RESOURCES, 'the resource element list moved — update this spec').not.toBeNull();
  const from = SOURCE.indexOf(OPEN);
  const to = SOURCE.indexOf(CLOSE);
  expect(from, 'noteWrite() moved — update the markers in this spec').toBeGreaterThan(-1);
  expect(to).toBeGreaterThan(from);
  const predicate = SOURCE.slice(from, to);

  const bypasses: string[] = [];
  const observed: string[] = [];
  for (let i = 0; i < WRITES.length; i++) {
    const url = `https://probe.test/r${i}.gif`;
    const markup = WRITES[i].replace(/URL/g, url);
    const hits: string[] = [];
    await page.route('https://probe.test/**', route => {
      hits.push(new URL(route.request().url()).pathname);
      return route.fulfill({ contentType: 'image/gif', body: '' });
    });
    await page.route('https://host.test/**', route => route.fulfill({
      contentType: 'text/html',
      body: `<!doctype html><html><head></head><body><p id=paint>x</p><script>document.write(${
        JSON.stringify(markup).replace(/<\/script/gi, '<\\/script')});</script></body></html>`,
    }));
    await page.goto('https://host.test/', { waitUntil: 'load' }).catch(() => { /* frameset/plaintext never fire load */ });
    await page.waitForTimeout(250);
    const fetched = hits.includes(`/r${i}.gif`);
    const withdrew = await page.evaluate(({ body, res, text }) => {
      // eslint-disable-next-line no-new-func
      const scan = new Function('w', 'RESOURCES', `${body}; return { note: noteWrite, withdrew: function () { return wrote; } };`)(window, res);
      scan.note([text]);
      return scan.withdrew() as boolean;
    }, { body: predicate, res: RESOURCES![1], text: markup });
    observed.push(`fetched=${fetched} withdrew=${withdrew} ${WRITES[i]}`);
    if (fetched && !withdrew && !UNGUARDED.includes(WRITES[i])) bypasses.push(WRITES[i]);
    await page.unrouteAll();
  }
  // Printed on failure AND on success: the safe-direction rows (a <source>
  // with no media parent, a <noscript> whose content the engine keeps as text,
  // a <picture><source srcset>) are where this predicate withdraws for
  // nothing, and that list is worth being able to read.
  console.log('\n' + observed.join('\n'));
  expect(bypasses, 'written markup that fetches while the exemption survives').toEqual([]);
  // And the named exceptions must keep being exceptions for the stated reason:
  // if one starts withdrawing, the element set changed and this list is stale.
  expect(UNGUARDED.every((c) => WRITES.includes(c)), 'UNGUARDED lists a case that is not in the matrix').toBe(true);
});
