import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';
import { runSections } from '../../compliance/run-sections.mjs';

const repo = resolve(import.meta.dirname, '../../..');
const temp = mkdtempSync(join(tmpdir(), 'faz-release-evidence-'));
try {
  const report = join(temp, 'plugin-check.json');
  const pluginCheck = (raw) => {
    writeFileSync(report, raw);
    return spawnSync('python3', [join(repo, 'scripts/check-plugin-check.py'), report], { encoding: 'utf8' });
  };
  assert.equal(pluginCheck('[]').status, 0);
  assert.equal(pluginCheck('Success: Checks complete. No errors found.').status, 0);
  const finding = {file:'example.php',line:1,column:1,type:'WARNING',code:'example',message:'example finding'};
  assert.equal(pluginCheck(JSON.stringify([finding])).status, 0);
  assert.equal(pluginCheck(JSON.stringify([{...finding,type:'ERROR'}])).status, 1);
  for (const field of Object.keys(finding)) {
    const incomplete = {...finding};
    delete incomplete[field];
    assert.equal(pluginCheck(JSON.stringify([incomplete])).status, 2, `missing ${field}`);
  }
  for (const invalid of ['', '[', '{}', '[{"code":"untyped"}]', 'Success: unrelated operation']) {
    assert.equal(pluginCheck(invalid).status, 2, invalid);
  }

  const stateFile = join(temp, 'state.json');
  const packages = ['faz-cookie-manager-1.31.0.zip', 'faz-cookie-manager-1.31.0-full.zip', 'faz-cookie-manager-v1.31.0.zip'];
  packages.forEach(name => writeFileSync(join(temp, name), 'package contents'));
  const state = (action, step, commit = 'commit-a') => spawnSync('python3', [
    join(repo, 'scripts/release-state.py'), action, ...(step ? [step] : []),
    '--state', stateFile, '--commit', commit, '--project-root', temp, '--version', '1.31.0',
  ], { encoding: 'utf8' });
  writeFileSync(stateFile, '{"step":"built","at":"yesterday"}\n');
  assert.equal(state('validate').status, 0);
  assert.equal(state('check', 'built').status, 1, 'legacy state must rebuild');
  assert.equal(state('mark', 'built').status, 0);
  assert.equal(state('check', 'built').status, 0);
  assert.equal(state('check', 'built', 'commit-b').status, 1, 'changed commit must rebuild');
  writeFileSync(join(temp, packages[0]), 'changed package');
  assert.equal(state('check', 'built').status, 1, 'tampered package must rebuild');
  assert.equal(state('mark', 'built').status, 0);
  assert.equal(state('mark', 'svn_committed').status, 0);
  assert.equal(state('validate').status, 0);
  assert.equal(state('validate', null, 'commit-b').status, 2, 'published provenance cannot change');
  writeFileSync(join(temp, packages[1]), 'changed after SVN');
  assert.equal(state('validate').status, 2);
  writeFileSync(stateFile, readFileSync(stateFile, 'utf8') + '{broken');
  assert.equal(state('validate').status, 2, 'corrupt state must fail closed');

  // Exercise the real compliance entry point, replacing only browser IO.
  // This catches a runner that reports the exception but still exits green.
  const compliancePath = join(repo, 'tests/compliance/compliance-tests.mjs');
  let compliance = readFileSync(compliancePath, 'utf8')
    .replace("import { chromium } from 'playwright';", "const chromium = { launch: async () => ({ newContext: async () => { throw new Error('injected browser failure'); }, close: async () => {} }) };");
  for (const dependency of ['test-helpers.mjs', 'run-sections.mjs']) {
    compliance = compliance.replace(`'./${dependency}'`, JSON.stringify(pathToFileURL(join(repo, 'tests/compliance', dependency)).href));
  }
  const brokenRun = join(temp, 'fatal-compliance.mjs');
  writeFileSync(brokenRun, compliance);
  const fatal = spawnSync('node', [brokenRun], { encoding: 'utf8', timeout: 10000 });
  assert.equal(fatal.status, 1, fatal.stdout + fatal.stderr);
  assert.match(fatal.stdout + fatal.stderr, /injected browser failure/);
  assert.match(fatal.stdout + fatal.stderr, /Sections not run:.*popia/);

  const digest = (value) => createHash('sha256').update(value).digest('hex');
  const gateLog = join(temp, 'gate.log');
  writeFileSync(gateLog, 'verified');
  const evidenceFile = join(temp, 'evidence.json');
  const evidence = {
    commit: 'commit-a', version: '1.31.0',
    packages: Object.fromEntries(packages.map(name => [name, digest(readFileSync(join(temp, name)))])),
    gates: Object.fromEntries(['unit', 'e2e', 'multisite', 'compliance', 'verify', 'install', 'upgrade', 'plugin_check', 'restore'].map(name => [name, {status: 'passed', log: gateLog, log_sha256: digest('verified')}])),
  };
  const verifyEvidence = () => {
    writeFileSync(evidenceFile, JSON.stringify(evidence));
    return spawnSync('python3', [join(repo, 'scripts/verify-release-evidence.py'), evidenceFile, 'commit-a', '1.31.0', temp], {encoding: 'utf8'});
  };
  assert.equal(verifyEvidence().status, 0);
  evidence.gates.e2e.status = 'pending';
  assert.equal(verifyEvidence().status, 1);
  evidence.gates.e2e.status = 'passed';
  evidence.gates.e2e.skips = [{test: 'conditional', reason: ''}];
  assert.equal(verifyEvidence().status, 1);
  evidence.gates.e2e.skips = [{test: 'conditional', reason: 'post-publication check'}];
  assert.equal(verifyEvidence().status, 0);
  writeFileSync(gateLog, 'changed');
  assert.equal(verifyEvidence().status, 1);
  evidence.commit = 'commit-b';
  assert.equal(verifyEvidence().status, 1);

  const packageRepo = join(temp, 'candidate');
  const deployed = join(temp, 'deployed/faz-cookie-manager');
  mkdirSync(packageRepo, {recursive:true});
  mkdirSync(deployed, {recursive:true});
  writeFileSync(join(packageRepo, 'source.txt'), 'candidate');
  mkdirSync(join(packageRepo, 'scripts'));
  writeFileSync(join(packageRepo, 'faz-cookie-manager.php'), '<?php // candidate');
  writeFileSync(join(packageRepo, 'scripts/build-release.sh'), "#!/bin/sh\npython3 - \"$@\" <<'PYBUILD'\nimport subprocess,sys,zipfile\nfrom pathlib import Path\noutput=next(a.split('=',1)[1] for a in sys.argv[1:] if a.startswith('--output-dir='))\nwith zipfile.ZipFile(Path(output)/'candidate.zip','w') as archive:\n    archive.writestr('faz-cookie-manager/faz-cookie-manager.php',subprocess.check_output(['git','show','HEAD:faz-cookie-manager.php']))\nPYBUILD\n");
  for (const args of [['init', '-q'], ['add', '.'], ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-qm', 'candidate']]) {
    assert.equal(spawnSync('git', args, {cwd:packageRepo}).status, 0);
  }
  const head = spawnSync('git', ['rev-parse','HEAD'], {cwd:packageRepo,encoding:'utf8'}).stdout.trim();
  writeFileSync(join(deployed, 'faz-cookie-manager.php'), '<?php // candidate');
  const candidateZip = join(temp, 'candidate.zip');
  assert.equal(spawnSync('zip', ['-qr',candidateZip,'faz-cookie-manager'], {cwd:join(temp,'deployed')}).status, 0);
  const manifestFile = join(temp,'build.json');
  const manifest = {commit:head,version:'1.31.0',packages:{'candidate.zip':digest(readFileSync(candidateZip))}};
  const packageCheck = () => {
    writeFileSync(manifestFile,JSON.stringify(manifest));
    return spawnSync('python3',[join(repo,'scripts/check-package-deploy.py'),candidateZip,manifestFile,packageRepo,deployed],{encoding:'utf8'});
  };
  assert.deepEqual(JSON.parse(packageCheck().stdout),[]);
  writeFileSync(join(deployed,'faz-cookie-manager.php'),'changed');
  assert.deepEqual(JSON.parse(packageCheck().stdout),['changed faz-cookie-manager.php']);
  manifest.commit='another-commit';
  assert.equal(packageCheck().status,2);
  manifest.commit=head;
  writeFileSync(join(packageRepo,'untracked.php'),'<?php // absent from HEAD');
  assert.equal(packageCheck().status,2, 'untracked source invalidates provenance');
  rmSync(join(packageRepo,'untracked.php'));
  writeFileSync(join(packageRepo,'source.txt'),'uncommitted');
  assert.equal(packageCheck().status,2);
  writeFileSync(join(packageRepo,'source.txt'),'candidate');
  // Forge a matching ZIP, manifest hash and deployment without changing HEAD.
  assert.equal(spawnSync('zip', ['-q',candidateZip,'faz-cookie-manager/faz-cookie-manager.php'], {cwd:join(temp,'deployed')}).status, 0);
  manifest.packages['candidate.zip']=digest(readFileSync(candidateZip));
  assert.equal(packageCheck().status,2, 'self-consistent forged package must not prove HEAD provenance');


  // A batch run that executed nothing must not summarize as a pass, and a
  // report whose own counts are all zero must be rejected batch by batch.
  const batches = join(temp, 'batches');
  mkdirSync(batches, {recursive:true});
  writeFileSync(join(batches, 'commit.txt'), 'commit-a\n');
  const summarize = (count) => spawnSync('python3',
    [join(repo, 'scripts/summarize-e2e.py'), batches, String(count)], {encoding:'utf8'});
  assert.equal(summarize(0).status, 2, 'zero batches cannot be a green E2E gate');
  const batchReport = (stats) => JSON.stringify({stats, errors:[], suites:[]});
  writeFileSync(join(batches, 'batch-01.json'), batchReport({expected:7,unexpected:0,flaky:0,skipped:0}));
  assert.equal(summarize(1).status, 0);
  assert.equal(JSON.parse(readFileSync(join(batches,'evidence.json'),'utf8')).counts.expected, 7);
  writeFileSync(join(batches, 'batch-01.json'), batchReport({expected:0,unexpected:0,flaky:0,skipped:0}));
  assert.equal(summarize(1).status, 1, 'a batch that ran no test is a failed batch');
  assert.equal(summarize(2).status, 2, 'a missing batch report cannot be skipped over');
  // A skip the report counts but does not expose must not pass as "no skips":
  // the evidence contract is one documented entry per skipped test.
  writeFileSync(join(batches, 'batch-01.json'),
    batchReport({expected:5,unexpected:0,flaky:0,skipped:2}));
  assert.equal(summarize(1).status, 1, 'counted skips with no entry cannot be evidence');
  const skippedSpec = (title, reason) => ({specs:[{file:'a.spec.ts',title,tests:[
    {status:'skipped',annotations:[{type:'skip',description:reason}]}]}],suites:[]});
  writeFileSync(join(batches, 'batch-01.json'), JSON.stringify({
    stats:{expected:5,unexpected:0,flaky:0,skipped:2}, errors:[],
    suites:[skippedSpec('one','third-party plugin absent'), skippedSpec('two','feature disabled')],
  }));
  assert.equal(summarize(1).status, 0, 'every skip documented is a valid gate');
  assert.deepEqual(
    JSON.parse(readFileSync(join(batches,'evidence.json'),'utf8')).skips.map(s => s.reasons[0]),
    ['third-party plugin absent', 'feature disabled']);

  const called = [];
  const run = await runSections([
    ['first', async () => called.push('first')],
    ['fatal', async () => { throw new Error('injected timeout'); }],
    ['last', async () => called.push('last')],
  ]);
  assert.deepEqual(called, ['first']);
  assert.deepEqual(run.completed, ['first']);
  assert.equal(run.failed, 'fatal');
  assert.deepEqual(run.notRun, ['last']);
  assert.match(String(run.error), /injected timeout/);
  await assert.rejects(runSections([]), /No compliance sections/);
  assert.equal((await runSections([['ok', async () => {}]])).failed, null);
  console.log('Release evidence: parser, commit/package provenance and fatal-run regressions passed');
} finally {
  rmSync(temp, { recursive: true, force: true });
}
