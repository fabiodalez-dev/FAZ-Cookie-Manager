/** Run the actual matrix driver against disposable files and fake external CLIs. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, cpSync, rmSync, existsSync, symlinkSync, readlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = resolve(fileURLToPath(new URL('../../../', import.meta.url)));
const driver = process.env.FAZ_MATRIX_DRIVER_PATH || join(root, 'scripts/cache-plugin-matrix.sh');

function runMatrix(extraEnv = {}, slug = 'autoptimize') {
    const dir = mkdtempSync(join(tmpdir(), 'faz matrix state '));
    const repo = join(dir, 'repo');
    const site = join(dir, 'site');
    const content = join(site, 'wp-content');
    const bin = join(dir, 'bin');
    for (const path of [join(repo, 'scripts'), join(content, 'w3tc-config'), bin]) mkdirSync(path, { recursive: true });
    cpSync(driver, join(repo, 'scripts/cache-plugin-matrix.sh'));
    const originals = {
        'wp-config.php': '<?php /* original WP_CACHE config */',
        '.htaccess': '# original rewrite rules',
        'wp-content/w3tc-config/master.php': 'original non-default W3TC settings',
        'wp-content/wpo-cache/config/test.php': 'original WP-Optimize settings',
        'wp-content/breeze-config/breeze-config.php': 'original Breeze settings',
        'wp-content/wp-cache-config.php': '<?php /* original supercache config */',
        'wp-content/advanced-cache.php': '<?php /* original W3TC drop-in */',
    };
    for (const [path, text] of Object.entries(originals)) {
        mkdirSync(resolve(join(site, path), '..'), { recursive: true });
        writeFileSync(join(site, path), text);
    }
    writeFileSync(join(dir, 'redis-dropin.php'), '<?php /* persistent cache */');
    symlinkSync(join(dir, 'redis-dropin.php'), join(content, 'object-cache.php'));
    const wp = `#!${process.execPath}
import { writeFileSync, appendFileSync, readFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
const site = process.env.WP_PATH;
const args = process.argv.slice(2).filter(a => !a.startsWith('--'));
appendFileSync(process.env.MATRIX_CALLS, JSON.stringify(args) + '\\n');
if (args[0] === 'config' && args[1] === 'path') console.log(join(site, 'wp-config.php'));
if (args[0] === 'config' && args[1] === 'get') console.log('true');
if (args[0] === 'eval-file') {
    if (args[2] === 'snapshot') writeFileSync(args[3], 'original option snapshot');
    else {
        if (process.env.MATRIX_RESTORE_FAIL) process.exit(1);
        if (readFileSync(args[3], 'utf8') !== 'original option snapshot') process.exit(1);
    }
}
if (args[0] === 'plugin' && args[1] === 'is-active') process.exit(process.env.MATRIX_STUCK_PLUGIN ? 0 : 1);
if (args[0] === 'plugin' && args[1] === 'activate') {
    writeFileSync(join(site, 'wp-config.php'), '<?php /* temporary config */');
    writeFileSync(join(site, '.htaccess'), '# temporary rules');
    writeFileSync(join(site, 'wp-content/wp-cache-config.php'), 'temporary supercache config');
    writeFileSync(join(site, 'wp-content/wp-cache-config.php.bak'), 'created by recipe');
    mkdirSync(join(site, 'wp-content/boost-cache'), { recursive: true });
    writeFileSync(join(site, 'wp-content/boost-cache/config.php'), 'created by recipe');
}
if (args[0] === 'eval' && args[1].includes('Breeze_ConfigCache::factory()->write')) {
    writeFileSync(join(site, 'wp-content/breeze-config/breeze-config.php'), 'temporary Breeze settings');
}
`;
    writeFileSync(join(bin, 'wp'), wp, { mode: 0o755 });
    writeFileSync(join(bin, 'package.json'), '{"type":"module"}');
    writeFileSync(join(bin, 'curl'), '#!/bin/sh\ncase " $* " in *" -w "*) printf "%s" "${MATRIX_HTTP_STATUS:-200}" ;; *) printf "Cache-Control: public\\r\\n" ;; esac\n', { mode: 0o755 });
    writeFileSync(join(bin, 'npx'), `#!/bin/sh
if [ -n "$MATRIX_LONG_OUTPUT" ]; then
    i=0
    while [ "$i" -lt 4000 ]; do
        printf '  - diagnostic %s: output must be consumed until the reporter and teardown finish\\n' "$i"
        i=$((i + 1))
    done
fi
if [ -n "$MATRIX_TEST_FAIL" ]; then echo "1 failed"; exit 1; fi
echo "8 passed"
printf done > "$MATRIX_NPX_DONE"
`, { mode: 0o755 });
    writeFileSync(join(bin, 'cp'), '#!/bin/sh\nfor arg do last="$arg"; done\nif [ -n "${MATRIX_STATE_RESTORE_FAIL:-}" ] && [ "$last" = "$WP_PATH/wp-content/w3tc-config" ]; then exit 1; fi\nexec /bin/cp "$@"\n', { mode: 0o755 });
    const calls = join(dir, 'calls');
    const result = spawnSync('bash', [join(repo, 'scripts/cache-plugin-matrix.sh'), slug], {
        encoding: 'utf8', timeout: 30_000,
        env: { ...process.env, ...extraEnv, PATH: `${bin}:${process.env.PATH}`, WP_PATH: site, MATRIX_CALLS: calls, MATRIX_NPX_DONE: join(dir, 'npx-completed') },
    });
    return { dir, site, result, originals, calls: readFileSync(calls, 'utf8').trim().split('\n').map(JSON.parse) };
}

for (const [name, env, expectedStatus, slug] of [
    ['successful run', {}, 0],
    ['successful Breeze run', {}, 0, 'breeze'],
    ['successful WP-Optimize run', {}, 0, 'wp-optimize'],
    ['failing tests', { MATRIX_TEST_FAIL: '1' }, 1],
    ['plugin still active after cleanup', { MATRIX_STUCK_PLUGIN: '1' }, 1],
    ['early baseline failure', { MATRIX_HTTP_STATUS: '503' }, 3],
]) {
    test(`matrix restores original configuration after ${name}`, () => {
        const run = runMatrix(env, slug);
        try {
            assert.equal(run.result.status, expectedStatus, run.result.stdout + run.result.stderr);
            for (const [path, original] of Object.entries(run.originals)) {
                assert.equal(readFileSync(join(run.site, path), 'utf8'), original, path);
            }
            assert.equal(readlinkSync(join(run.site, 'wp-content/object-cache.php')), join(run.dir, 'redis-dropin.php'));
            assert.equal(existsSync(join(run.site, 'wp-content/wp-cache-config.php.bak')), false);
            assert.equal(existsSync(join(run.site, 'wp-content/boost-cache/config.php')), false);
            const restore = run.calls.findIndex(args => args[0] === 'eval-file' && args[2] === 'restore');
            const flush = run.calls.findLastIndex(args => args[0] === 'cache' && args[1] === 'flush');
            assert.ok(restore >= 0, 'original options restored');
            assert.ok(flush > restore, 'persistent object cache flushed after restoring options');
        } finally {
            rmSync(run.dir, { recursive: true, force: true });
        }
    });
}

test('matrix retains its snapshot and fails when restore fails', () => {
    const run = runMatrix({ MATRIX_RESTORE_FAIL: '1' });
    let snapshot;
    try {
        assert.equal(run.result.status, 1, run.result.stdout + run.result.stderr);
        snapshot = run.result.stderr.match(/snapshot retained at (.+)/)?.[1];
        assert.ok(snapshot, 'recovery path is reported');
        assert.equal(readFileSync(join(snapshot, 'options'), 'utf8'), 'original option snapshot');
        assert.equal(readFileSync(join(run.site, 'wp-content/w3tc-config/master.php'), 'utf8'), run.originals['wp-content/w3tc-config/master.php']);
        assert.equal(existsSync(join(run.site, 'wp-content/advanced-cache.php')), false, 'cache drop-in stays off with unrestored options');
        assert.equal(existsSync(join(run.site, 'wp-content/object-cache.php')), false, 'persistent backend stays off with unrestored options');
        const restore = run.calls.findIndex(args => args[0] === 'eval-file' && args[2] === 'restore');
        assert.equal(run.calls.slice(restore + 1).some(args => args[0] === 'cache' && args[1] === 'flush'), false);
    } finally {
        if (snapshot) rmSync(snapshot, { recursive: true, force: true });
        rmSync(run.dir, { recursive: true, force: true });
    }
});

test('a failed state-file restore does not prevent independent recovery', () => {
    const run = runMatrix({ MATRIX_STATE_RESTORE_FAIL: '1' });
    let snapshot;
    try {
        assert.equal(run.result.status, 1, run.result.stdout + run.result.stderr);
        snapshot = run.result.stderr.match(/snapshot retained at (.+)/)?.[1];
        assert.ok(snapshot, 'backup retained after partial restore');
        for (const [path, original] of Object.entries(run.originals)) {
            if (path.startsWith('wp-content/w3tc-config/')) continue;
            assert.equal(readFileSync(join(run.site, path), 'utf8'), original, path);
        }
        assert.equal(readlinkSync(join(run.site, 'wp-content/object-cache.php')), join(run.dir, 'redis-dropin.php'));
        assert.equal(readFileSync(join(snapshot, 'state-4/master.php'), 'utf8'), run.originals['wp-content/w3tc-config/master.php']);
        const restore = run.calls.findIndex(args => args[0] === 'eval-file' && args[2] === 'restore');
        assert.ok(restore >= 0, 'options restored despite a file failure');
        assert.ok(run.calls.slice(restore + 1).some(args => args[0] === 'cache' && args[1] === 'flush'), 'restored backend flushed');
    } finally {
        if (snapshot) rmSync(snapshot, { recursive: true, force: true });
        rmSync(run.dir, { recursive: true, force: true });
    }
});

test('the output preview drains long reporter output through completion', () => {
    const run = runMatrix({ MATRIX_LONG_OUTPUT: '1' });
    try {
        assert.equal(run.result.status, 0, run.result.stdout + run.result.stderr);
        assert.equal(readFileSync(join(run.dir, 'npx-completed'), 'utf8'), 'done');
        assert.match(run.result.stdout, /ok 8 passed/);
    } finally {
        rmSync(run.dir, { recursive: true, force: true });
    }
});
