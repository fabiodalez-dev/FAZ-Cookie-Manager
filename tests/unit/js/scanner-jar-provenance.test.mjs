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
ok(/if \(!Object\.prototype\.hasOwnProperty\.call\(cookieSet, jarOnlyCookies\[jr\]\.name\)\)/.test(src),
  'a jar name that some page actually set is promoted back to a discovery');
ok(/jar_cookies: jarOnlyRemaining,/.test(src),
  'only names no page ever set are reported as jar-only');

// The bucket must be reported, not silently dropped: swapping one invisible
// behaviour for another would leave a real cookie undeclared with no trace.
const api = readFileSync(join(here, '../../../admin/modules/scanner/api/class-api.php'), 'utf8');
ok(/\$jar_cookies = isset\( \$body\['jar_cookies'\] \)/.test(api),
  'the import endpoint accepts the jar bucket');
ok(/\$result\['jar_only_count'\]\s+= count\( \$jar_names \);/.test(api),
  'the endpoint reports how many were held back');
ok(!/save_cookies\(\s*\$jar_cookies/.test(api),
  'the jar bucket is never written into the cookie table');

if (failures) {
  console.error(`\n${failures} of ${checks} jar-provenance checks failed.`);
  process.exit(1);
}
console.log(`\n${checks} jar-provenance checks passed.`);
