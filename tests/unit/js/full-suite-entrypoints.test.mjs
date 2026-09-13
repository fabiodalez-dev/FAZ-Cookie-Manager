/** Execute the real npm entrypoints with fake test executables. This proves
 * ordering and failure propagation without recursively running the unit suite
 * or touching a WordPress installation. The scripts themselves are unchanged.
 */
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const root = fileURLToPath(new URL('../../../', import.meta.url));
const fixture = mkdtempSync(join(tmpdir(), 'faz-suite-entrypoints-'));
const packageFixture = mkdtempSync(join(tmpdir(), 'faz-package-source-'));
let passed = 0;
try {
  mkdirSync(join(fixture, 'scripts'), { recursive: true });
  mkdirSync(join(fixture, 'node_modules/.bin'), { recursive: true });
  writeFileSync(join(fixture, 'package.json'), readFileSync(join(root, 'package.json')));
  writeFileSync(join(fixture, 'gate.cjs'), `
const fs = require('node:fs');
const stage = process.argv[2];
if (stage === 'unit' && process.env.FAZ_EXPECT_PACKAGED_SOURCE) {
  const source = process.env.FAZ_PLUGIN_SOURCE_PATH;
  if (!source || fs.readFileSync(source + '/faz-cookie-manager.php', 'utf8') !== '<?php // release-only' || fs.existsSync(source + '/gate.cjs')) process.exit(3);
  fs.appendFileSync(process.env.FAZ_GATE_LOG, 'package-source' + '\\n');
}
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
  // The fixture's own site copy is not part of the candidate: the runner reads
  // the tree with --untracked-files=all and would refuse to start.
  writeFileSync(join(fixture, '.gitignore'), 'calls.log\nbatch-output/\nabsent-wordpress/\n');
  writeFileSync(join(fixture, 'scripts/check-package-deploy.py'), readFileSync(join(root, 'scripts/check-package-deploy.py')));
  writeFileSync(join(fixture, 'faz-cookie-manager.php'), '<?php // release-only');
  writeFileSync(join(fixture, 'scripts/build-release.sh'), "#!/bin/sh\npython3 - \"$@\" <<'PYBUILD'\nimport subprocess,sys,zipfile\nfrom pathlib import Path\noutput=next(a.split('=',1)[1] for a in sys.argv[1:] if a.startswith('--output-dir='))\nwith zipfile.ZipFile(Path(output)/'release.zip','w') as archive:\n    archive.writestr('faz-cookie-manager/faz-cookie-manager.php',subprocess.check_output(['git','show','HEAD:faz-cookie-manager.php']))\nPYBUILD\n");
  // The batch runner now binds evidence to a clean, committed candidate.
  execFileSync('git', ['init', '-q'], { cwd: fixture });
  execFileSync('git', ['add', '.'], { cwd: fixture });
  execFileSync('git', ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-qm', 'fixture'], { cwd: fixture });
  function run(command, args, fail, expected, extraEnv = {}) {
    const log = join(fixture, 'calls.log');
    rmSync(log, { force: true });
    let code = 0;
    try {
      execFileSync(command, args, {
        cwd: fixture, timeout: 30_000, stdio: 'pipe',
        env: { ...process.env, FAZ_E2E_PACKAGE: '', FAZ_E2E_BUILD_MANIFEST: '', FAZ_PLUGIN_SOURCE_PATH: '', FAZ_GATE_LOG: log, FAZ_GATE_FAIL: fail,
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
  mkdirSync(join(packageFixture, 'faz-cookie-manager'));
  writeFileSync(join(packageFixture, 'faz-cookie-manager/faz-cookie-manager.php'), '<?php // release-only');
  // In package mode the runner now refuses to start unless the site under test
  // actually runs the package, so the fixture site has to carry it. WordPress
  // itself stays absent: the run stops at the first gate, well before any spec.
  const installed = join(fixture, 'absent-wordpress/wp-content/plugins/faz-cookie-manager');
  mkdirSync(installed, { recursive: true });
  writeFileSync(join(installed, 'faz-cookie-manager.php'), '<?php // release-only');
  const packageZip = join(packageFixture, 'release.zip');
  execFileSync('zip', ['-qr', packageZip, 'faz-cookie-manager'], {cwd:packageFixture});
  const manifest = join(packageFixture, 'build.json');
  writeFileSync(manifest, JSON.stringify({
    commit:execFileSync('git', ['rev-parse','HEAD'], {cwd:fixture,encoding:'utf8'}).trim(),
    version:'1.31.0',
    packages:{'release.zip':createHash('sha256').update(readFileSync(packageZip)).digest('hex')},
  }));
  run('bash', ['scripts/run-e2e-batches.sh'], 'unit', ['package-source','unit'], {
    FAZ_E2E_PACKAGE:packageZip, FAZ_E2E_BUILD_MANIFEST:manifest, FAZ_EXPECT_PACKAGED_SOURCE:'1',
  });
  writeFileSync(join(fixture, 'untracked.php'), '<?php // not committed');
  run('bash', ['scripts/run-e2e-batches.sh'], 'dirty-candidate', []);
  rmSync(join(fixture, 'untracked.php'));
  mkdirSync(join(fixture, 'tests/unit/js'), { recursive: true });
  writeFileSync(join(fixture, 'scripts/real-unit-runner.sh'), readFileSync(join(root, 'scripts/run-unit-tests.sh')));
  writeFileSync(join(fixture, 'tests/unit/test-probe.php'), '<?php echo "passed";');
  writeFileSync(join(fixture, 'tests/unit/js/probe.test.mjs'), 'throw new Error("must not silently skip me");');
  run('bash', ['scripts/real-unit-runner.sh'], 'missing-node', [], {
    NODE_BIN: 'faz-node-runtime-intentionally-missing',
  });
  console.log(`full-suite-entrypoints: ${passed} passed`);
} finally {
  rmSync(fixture, { recursive: true, force: true });
  rmSync(packageFixture, { recursive: true, force: true });
}
