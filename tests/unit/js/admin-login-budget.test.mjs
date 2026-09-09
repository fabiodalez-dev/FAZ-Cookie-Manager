import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

// Execute the actual helper with a virtual clock and Playwright-shaped transport.
// Delays consume the timeout the helper passes, so missing or per-operation
// deadlines produce an observable overrun rather than a source-string match.
const source = readFileSync(new URL('../../e2e/fixtures/wp-fixture.ts', import.meta.url), 'utf8');
// Use the runner's own TypeScript transform, including on the Node 20 CI job.
const require = createRequire(import.meta.url);
const { transformHook } = require(join(dirname(require.resolve('playwright/package.json')), 'lib/transform/transform.js'));
const helper = transformHook(source.slice(source.indexOf('const LOGIN_TOTAL_BUDGET_MS'), source.indexOf('export const test =')).replace('export async function', 'async function'), 'admin-login-budget.ts').code;
let passed = 0;
async function run(scenario) {
  let now = 0, attempts = 0, cleared = 0, currentURL = '', closed = false;
  const calls = [];
  const context = {
    cookies: async () => [],
    clearCookies: async (options) => {
      assert.ok(options.name.test('wordpress_logged_in_hash'));
      assert.ok(!options.name.test('fazcookie-consent'));
      cleared++;
    },
  };
  const step = async (name, options) => {
    assert.ok(options?.timeout > 0, `${name} must have a finite, nonzero timeout`);
    calls.push({ name, start: now, timeout: options.timeout, attempt: attempts });
    const delay = scenario(name, attempts);
    now += Math.min(delay, options.timeout);
    if (delay >= options.timeout) throw new Error(`${name} timeout`);
  };
  const page = {
    goto: async (_url, options) => {
      attempts++;
      currentURL = 'https://wp.test/wp-login.php';
      await step('goto', options);
    },
    waitForLoadState: async (_state, options) => step('load', options),
    url: () => currentURL,
    isClosed: () => closed,
    context: () => context,
    waitForNavigation: async (options) => {
      await step('navigation', options);
      currentURL = 'https://wp.test/wp-admin/';
    },
    locator: (selector) => ({
      selector,
      evaluate: async (_fn, _arg, options) => step(`evaluate:${selector}`, options),
      textContent: async (options) => { await step(`text:${selector}`, options); return ''; },
    }),
  };
  const expect = (locator) => ({
    toBeVisible: (options) => step(`visible:${locator.selector}`, options),
    toHaveCount: (_count, options) => step(`count:${locator.selector}`, options),
    toHaveURL: (_url, options) => step('url', options),
  });
  const sandbox = { Date: { now: () => now }, Math, Error, getWpLoginPath: () => '/wp-login.php', expect };
  vm.createContext(sandbox);
  vm.runInContext(helper + '\nglobalThis.login = completeAdminLogin;', sandbox);
  let error;
  try { await sandbox.login(page, 'https://wp.test', 'admin', 'secret'); } catch (e) { error = e; }
  return { now, attempts, cleared, calls, error };
}

let result = await run(() => 0);
assert.equal(result.error, undefined);
assert.equal(result.attempts, 1);
assert.equal(result.cleared, 0);
passed++;

result = await run((name) => name === 'goto' ? 100_000 : 0);
assert.match(result.error?.message, /admin login failed after 5 attempt\(s\) within 60000ms/);
assert.equal(result.now, 60_000);
assert.equal(result.cleared, 4, 'do not clear cookies after terminal failure');
passed++;

result = await run((name, attempt) => attempt === 1 ? ({ 'visible:#user_login': 9000, 'evaluate:#user_pass': 100_000 }[name] ?? 0) : 0);
assert.equal(result.error, undefined);
assert.equal(result.attempts, 2);
assert.equal(result.now, 12_000, 'field lookup shares the attempt deadline');
assert.equal(result.calls.find((c) => c.name === 'evaluate:#user_pass').timeout, 3000);
passed++;

result = await run((name, attempt) => attempt === 1 ? ({ 'visible:#user_login': 9000, 'count:#loginform': 100_000 }[name] ?? 0) : 0);
assert.equal(result.error, undefined);
assert.equal(result.attempts, 2);
assert.equal(result.now, 12_000, 'post-login assertion cannot escape the attempt budget');
passed++;

console.log(`${passed} admin login budget scenarios passed`);
