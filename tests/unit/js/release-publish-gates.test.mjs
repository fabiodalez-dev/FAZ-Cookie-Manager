import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, copyFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

// Exercise the real shell orchestration with isolated files and fake remote CLIs.
// No network, real repository, credentials, or public release is touched.
const repo = resolve(import.meta.dirname, '../../..');
const fixture = mkdtempSync(join(tmpdir(), 'faz-release-gates-'));
const executable = (path, body) => writeFileSync(path, `#!/usr/bin/env bash\nset -eu\n${body}\n`, { mode: 0o755 });
try {
  for (const scenario of ['cancel-first', 'cancel-second', 'missing-remote-tag']) {
    const root = join(fixture, scenario);
    const plugin = join(root, 'plugin');
    const bin = join(root, 'bin');
    for (const path of [bin, join(plugin, 'scripts'), join(root, 'svn/.svn'), join(root, 'svn/trunk'), join(root, 'archive/faz-cookie-manager')]) mkdirSync(path, { recursive: true });
    writeFileSync(join(plugin, 'readme.txt'), 'Stable tag: 1.30.0\n');
    writeFileSync(join(plugin, 'faz-cookie-manager.php'), "<?php\ndefine( 'FAZ_VERSION', '1.30.0' );\n");
    writeFileSync(join(plugin, 'CHANGELOG.md'), '## [1.30.0]\nRelease fixture.\n');
    writeFileSync(join(root, 'archive/faz-cookie-manager/readme.txt'), 'Stable tag: 1.30.0\n');
    const zip = spawnSync('zip', ['-qr', join(root, 'faz-cookie-manager-1.30.0.zip'), 'faz-cookie-manager'], { cwd: join(root, 'archive') });
    assert.equal(zip.status, 0);
    for (const suffix of ['-1.30.0-full.zip', '-v1.30.0.zip']) copyFileSync(join(root, 'faz-cookie-manager-1.30.0.zip'), join(root, `faz-cookie-manager${suffix}`));
    for (const script of ['publish-release.sh', 'svn-release.sh']) copyFileSync(join(repo, 'scripts', script), join(plugin, 'scripts', script));
    for (const script of ['bump-version.sh', 'build-release.sh']) executable(join(plugin, 'scripts', script), 'exit 0');
    if (scenario === 'missing-remote-tag') executable(join(plugin, 'scripts/svn-release.sh'), 'exit 0');
    executable(join(bin, 'git'), `
while [[ "\${1:-}" == "-C" ]]; do shift 2; done
case "$*" in
  'rev-parse --abbrev-ref HEAD') echo main ;;
  'rev-parse HEAD'|'rev-parse origin/main') echo deadbeef ;;
  'rev-parse v1.30.0') exit 1 ;;
  status*|fetch*) ;;
  *) echo "git $*" >> "$CALL_LOG" ;;
esac`);
    executable(join(bin, 'gh'), `
if [[ "$1" == api ]]; then echo success; else echo "gh $*" >> "$CALL_LOG"; fi`);
    executable(join(bin, 'svn'), `
echo "svn $*" >> "$CALL_LOG"
case "$1" in
  --version) echo 1.14.0 ;;
  ls) exit 1 ;;
  status) echo 'M       trunk/readme.txt' ;;
  ci) echo 'UNEXPECTED COMMIT' >> "$CALL_LOG"; exit 99 ;;
esac`);
    const log = join(root, 'calls.log');
    const result = spawnSync('bash', [join(plugin, 'scripts/publish-release.sh'), '--version=1.30.0'], {
      cwd: plugin,
      env: { ...process.env, PATH: `${bin}:${process.env.PATH}`, PLUGIN_SRC: plugin, PROJECT_ROOT: root, SVN_DIR: join(root, 'svn'), STAGE_DIR: join(root, 'stage'), CALL_LOG: log },
      input: scenario === 'cancel-second' ? 'y\nn\n' : 'n\n', encoding: 'utf8', timeout: 30000,
    });
    const output = result.stdout + result.stderr;
    assert.equal(result.error, undefined, output);
    assert.notEqual(result.status, 0, output);
    const expected = scenario === 'cancel-first' ? 'Aborted at Gate 1' : scenario === 'cancel-second' ? 'Aborted at Gate 2' : 'SVN tag 1.30.0 is absent';
    assert.ok(output.includes(expected), output);
    const state = readFileSync(join(plugin, '.release-state-1.30.0.json'), 'utf8');
    assert.ok(state.includes('"draft"'), state);
    assert.doesNotMatch(state, /svn_committed|tagged|published/);
    assert.doesNotMatch(readFileSync(log, 'utf8'), /svn ci|git tag|git push|--draft=false|UNEXPECTED/);
  }
  console.log('3 release publication gate regressions passed');
} finally {
  rmSync(fixture, { recursive: true, force: true });
}
