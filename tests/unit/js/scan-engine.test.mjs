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

console.log('shared scan engine origin handling (6 checks)');

{
  const result = await runCrossOriginCase('https://public.example.test/');
  check('01 different public/admin hosts are retried through the admin origin', result.error?.stage === 'browser');
  check('02 diagnostics record both the retry and its later iframe-host blocker', result.error?.message.includes('retried through the WordPress admin origin')
    && result.error?.message.includes('container missing'));
  check('03 an unobservable scan never imports server-only fallback findings', JSON.stringify(result.calls) === JSON.stringify(['scans/discover']));
}

{
  const result = await runCrossOriginCase('https://www.admin.example.test/');
  check('04 www and apex paths also use the observable admin-origin retry', result.error?.stage === 'browser');
  check('05 the diagnostic identifies the admin-origin retry', result.error?.message.includes('retried through the WordPress admin origin'));
  check('06 the www mismatch also stops before enrichment and import', JSON.stringify(result.calls) === JSON.stringify(['scans/discover']));
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
