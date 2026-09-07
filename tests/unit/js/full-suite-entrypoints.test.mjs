/** Execute the real npm entrypoints with fake test executables. This proves
 * ordering and failure propagation without recursively running the unit suite
 * or touching a WordPress installation. The scripts themselves are unchanged.
 */
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const root = fileURLToPath(new URL('../../../', import.meta.url));
const fixture = mkdtempSync(join(tmpdir(), 'faz-suite-entrypoints-'));
let passed = 0;
try {
  mkdirSync(join(fixture, 'scripts'), { recursive: true });
  mkdirSync(join(fixture, 'node_modules/.bin'), { recursive: true });
  writeFileSync(join(fixture, 'package.json'), readFileSync(join(root, 'package.json')));
  writeFileSync(join(fixture, 'gate.cjs'), `
const fs = require('node:fs');
const stage = process.argv[2];
fs.appendFileSync(process.env.FAZ_GATE_LOG, stage + '\\n');
process.exit(process.env.FAZ_GATE_FAIL === stage ? 9 : 0);
`);
  writeFileSync(join(fixture, 'scripts/run-unit-tests.sh'), '#!/bin/bash\nnode gate.cjs unit\n');
  writeFileSync(join(fixture, 'scripts/run-e2e-batches.sh'), readFileSync(join(root, 'scripts/run-e2e-batches.sh')));
  writeFileSync(join(fixture, 'node_modules/.bin/playwright'), `#!/usr/bin/env node
const fs = require('node:fs');
const stage = process.argv.join(' ').includes('browser-intent') ? 'browser' : 'wordpress';
fs.appendFileSync(process.env.FAZ_GATE_LOG, stage + '\\n');
process.exit(process.env.FAZ_GATE_FAIL === stage ? 9 : 0);
`, { mode: 0o755 });
  function run(command, args, fail, expected, extraEnv = {}) {
    const log = join(fixture, 'calls.log');
    rmSync(log, { force: true });
    let code = 0;
    try {
      execFileSync(command, args, {
        cwd: fixture, timeout: 30_000, stdio: 'pipe',
        env: { ...process.env, FAZ_GATE_LOG: log, FAZ_GATE_FAIL: fail,
          E2E_BATCH_OUT: join(fixture, 'batch-output'), WP_PATH: join(fixture, 'absent-wordpress'), ...extraEnv },
      });
    } catch (error) { code = error.status ?? -1; }
    assert.equal(code === 0, !fail, `${command} ${args.join(' ')}: failure must propagate`);
    const calls = existsSync(log) ? readFileSync(log, 'utf8').trim().split('\n') : [];
    assert.deepEqual(calls, expected, `${command} ${args.join(' ')}: stage order / fail-fast`);
    passed++;
  }
  run('npm', ['test'], '', ['unit', 'browser', 'wordpress']);
  run('npm', ['run', 'test:e2e'], '', ['unit', 'browser', 'wordpress']);
  run('npm', ['run', 'test:e2e:headed'], '', ['unit', 'browser', 'wordpress']);
  run('npm', ['run', 'test:e2e'], 'unit', ['unit']);
  run('npm', ['run', 'test:e2e'], 'browser', ['unit', 'browser']);
  run('npm', ['test'], 'wordpress', ['unit', 'browser', 'wordpress']);
  run('bash', ['scripts/run-e2e-batches.sh'], 'unit', ['unit']);
  run('bash', ['scripts/run-e2e-batches.sh'], 'browser', ['unit', 'browser']);
  mkdirSync(join(fixture, 'tests/unit/js'), { recursive: true });
  writeFileSync(join(fixture, 'scripts/real-unit-runner.sh'), readFileSync(join(root, 'scripts/run-unit-tests.sh')));
  writeFileSync(join(fixture, 'tests/unit/test-probe.php'), '<?php echo "passed";');
  writeFileSync(join(fixture, 'tests/unit/js/probe.test.mjs'), 'throw new Error("must not silently skip me");');
  run('bash', ['scripts/real-unit-runner.sh'], 'missing-node', [], {
    NODE_BIN: 'faz-node-runtime-intentionally-missing',
  });
  console.log(`full-suite-entrypoints: ${passed} passed`);
} finally { rmSync(fixture, { recursive: true, force: true }); }
