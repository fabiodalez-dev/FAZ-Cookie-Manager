// The scan runs inside the administrator's browser, and a same-origin iframe
// shares the top-level cookie jar. `doc.cookie` therefore reports every cookie
// the ADMIN holds for the domain, not what the scanned page set — which is how
// wp-admin-only cookies (Automattic Tracks tk_ai/tk_qs) ended up in a site's
// PUBLIC cookie declaration for services no visitor ever touches.
//
// These tests pin the split: a name already in the jar before a page loaded is
// not attributable to that page.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '../../../admin/assets/js/modules/scan-engine.js'), 'utf8');

let checks = 0;
let failures = 0;
function ok(condition, label) {
  checks++;
  if (condition) { console.log(`  [PASS] ${label}`); return; }
  failures++;
  console.error(`  [FAIL] ${label}`);
}

ok(/var jarBaseline = options\.jarBaseline \|\| null;/.test(src),
  'the per-page scan receives the jar as it stood before the page loaded');
ok(/jarBaseline: cookiesBefore,/.test(src),
  'the crawl passes that baseline for every page it dispatches');
ok(/hasOwnProperty\.call\(jarBaseline, all\[ci\]\.name\)/.test(src),
  'each cookie read from the iframe is checked against the baseline');
ok(/result\.jarCookies\.push\(all\[ci\]\)/.test(src) && /result\.cookies\.push\(all\[ci\]\)/.test(src),
  'pre-existing names go to a separate bucket instead of the discovery list');
ok(/jarCookies: \[\]/.test(src),
  'an aborted or unreachable page still returns the bucket, never undefined');

// Reconciliation matters: a cookie can sit in the jar when page 2 loads
// precisely because page 1 set it. That is a real discovery, not contamination.
ok(/if \(!Object\.prototype\.hasOwnProperty\.call\(observedNames, jarList\[jr\]\.name\)\)/.test(src),
  'a jar name that some page actually set is promoted back to a discovery');
ok(/jar_cookies: jarOnlyRemaining,/.test(src),
  'only names no page ever set are reported as jar-only');

// The bucket and the observed-name set are LOCALS of scanUrlsConcurrent, while
// the reconciliation lives in the import callback. Reading them across that
// boundary threw a ReferenceError and broke every scan import — and the checks
// above did not notice, because they match source text rather than run it.
ok(/done\(collectedCookies, collectedScripts, diagnostics, jarOnlyCookies, cookieSet\)/.test(src),
  'the crawl hands the jar bucket and observed-name set to its callback');
ok(/function \(collectedCookies, collectedScripts, diagnostics, jarOnlyCookies, cookieSet\)/.test(src),
  'the import callback receives them as parameters instead of reaching out of scope');
ok(/var jarList = jarOnlyCookies \|\| \[\];/.test(src) && /var observedNames = cookieSet \|\| \{\};/.test(src),
  'a caller that omits them degrades to an empty bucket rather than throwing');

// The bucket must be reported, not silently dropped: swapping one invisible
// behaviour for another would leave a real cookie undeclared with no trace.
const api = readFileSync(join(here, '../../../admin/modules/scanner/api/class-api.php'), 'utf8');

/**
 * Body of one PHP method, comments stripped and braces counted outside string
 * literals. Scoping matters here: the import endpoint handles three cookie
 * arrays, and a claim about where the jar bucket goes is only meaningful
 * against the code that routes it.
 */
function phpMethodBody(source, name) {
  const start = source.search(new RegExp(`function\\s+${name}\\s*\\(`));
  if (start === -1) return null;
  let i = source.indexOf('{', start);
  if (i === -1) return null;
  const open = i;
  let depth = 0;
  for (; i < source.length; i += 1) {
    const c = source[i];
    if (c === "'" || c === '"') {
      const quote = c;
      i += 1;
      while (i < source.length && source[i] !== quote) {
        if (source[i] === '\\') i += 1;
        i += 1;
      }
      continue;
    }
    if (c === '/' && source[i + 1] === '/') {
      i = source.indexOf('\n', i);
      if (i === -1) return null;
      continue;
    }
    if (c === '/' && source[i + 1] === '*') {
      i = source.indexOf('*/', i);
      if (i === -1) return null;
      i += 1;
      continue;
    }
    if (c === '{') depth += 1;
    else if (c === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(open, i + 1);
    }
  }
  return null;
}

const importBody = phpMethodBody(api, 'import_cookies');
ok(importBody !== null, 'the import endpoint is still where the jar bucket is handled');

// Every line that touches the bucket, with comments dropped so prose about it
// cannot stand in for code. Anything outside these four shapes — an
// array_merge into the imported set, an extra append — is a leak. The fourth
// is the runtime-observation split: the capture window also sees the admin's
// own wp-admin traffic, and those sightings join the bucket rather than the
// declaration.
const jarLines = (importBody || '')
  .split('\n')
  .map((line) => line.trim().replace(/\s+/g, ' '))
  .filter((line) => line.includes('$jar_cookies') && !line.startsWith('//') && !line.startsWith('*'));
const allowedJarShapes = [
  /^\$jar_cookies\s*=\s*isset\( \$body\['jar_cookies'\] \)/,
  /^\$jar_cookies\[\] = \$entry;$/,
  /^\$jar_cookies\[\] = \$session_cookie;$/,
  /^foreach \( \$jar_cookies as \$jar_cookie \) \{$/,
];
ok(jarLines.length === 4 && jarLines.every((line) => allowedJarShapes.some((shape) => shape.test(line))),
  'the bucket is only filled and iterated — never merged into the imported set');

const jarLoopStart = (importBody || '').indexOf('foreach ( $jar_cookies as $jar_cookie ) {');
const jarLoop = jarLoopStart === -1
  ? ''
  : (importBody || '').slice(jarLoopStart, (importBody || '').indexOf('\n\t\t}', jarLoopStart));
ok(/\$jar_names\[\] = \$jar_name;/.test(jarLoop) && !/\$(raw_)?cookies\[\]/.test(jarLoop),
  'a jar name becomes a reported name and nothing else');
ok(/\$result\['jar_only_cookies'\]\s*= \$jar_names;/.test(importBody || ''),
  'the reported names reach the response');
ok(/\$result\['jar_only_count'\]\s+= count\( \$jar_names \);/.test(api),
  'the endpoint reports how many were held back');

// What actually reaches the cookie table is built from $raw_cookies alone. If
// the bucket ever joined that array upstream this would still pass, which is
// why the shape check above stands next to it.
ok(/foreach \( \$raw_cookies as \$c \) \{/.test(importBody || '')
  && /save_scan_result\( \$cookies,/.test(importBody || ''),
  'the persisted set is assembled from attributable cookies only');

if (failures) {
  console.error(`\n${failures} of ${checks} jar-provenance checks failed.`);
  process.exit(1);
}
console.log(`\n${checks} jar-provenance checks passed.`);
