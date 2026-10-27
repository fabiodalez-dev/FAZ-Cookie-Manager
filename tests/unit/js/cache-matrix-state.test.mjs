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
    if (extraEnv.MATRIX_EXISTING_FIXTURE) writeFileSync(join(site, 'fixture-page'), 'original fixture content');
    const wp = `#!${process.execPath}
import { writeFileSync, appendFileSync, readFileSync, mkdirSync, existsSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
const site = process.env.WP_PATH;
const rawArgs = process.argv.slice(2);
const args = rawArgs.filter(a => !a.startsWith('--'));
const activeFile = join(site, 'active-plugin');
appendFileSync(process.env.MATRIX_CALLS, JSON.stringify(args) + '\\n');
if (args[0] === 'plugin' && args[1] === 'list') {
    if (process.env.MATRIX_STATE_CHECK_FAIL) process.exit(1);
    console.log('name,status');
    if (existsSync(activeFile)) console.log(readFileSync(activeFile, 'utf8') + ',' + (process.env.MATRIX_NETWORK_ACTIVE ? 'active-network' : 'active'));
}
if (args[0] === 'plugin' && args[1] === 'deactivate') {
    if (process.env.MATRIX_DEACTIVATE_FAIL || (process.env.MATRIX_PRIMARY_DEACTIVATE_FAIL && !rawArgs.includes('--skip-plugins'))) process.exit(1);
    if (!process.env.MATRIX_STUCK_PLUGIN && existsSync(activeFile)) unlinkSync(activeFile);
}
if (args[0] === 'eval' && args[1].includes('wp_insert_post')) {
    if (process.env.MATRIX_CREATE_FIXTURE_FAIL) process.exit(1);
    if (!existsSync(join(site, 'fixture-page'))) {
        writeFileSync(join(site, 'fixture-page'), 'created by matrix');
        console.log('123');
    }
}
if (args[0] === 'post' && args[1] === 'get') console.log(process.env.MATRIX_FIXTURE_SLUG_MISMATCH ? 'some-other-page' : 'faz-cache-matrix');
if (args[0] === 'post' && args[1] === 'delete') {
    if (args[2] !== '123' || process.env.MATRIX_DELETE_FIXTURE_FAIL) process.exit(1);
    unlinkSync(join(site, 'fixture-page'));
}
if (args[0] === 'config' && args[1] === 'path') console.log(join(site, 'wp-config.php'));
if (args[0] === 'config' && args[1] === 'get') console.log('true');
if (args[0] === 'eval-file') {
    if (args[2] === 'snapshot') writeFileSync(args[3], 'original option snapshot');
    else {
        if (process.env.MATRIX_RESTORE_FAIL) process.exit(1);
        if (readFileSync(args[3], 'utf8') !== 'original option snapshot') process.exit(1);
    }
}
if (args[0] === 'plugin' && args[1] === 'activate') {
    writeFileSync(activeFile, args[2]);
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
    const result = spawnSync('bash', [join(repo, 'scripts/cache-plugin-matrix.sh'), ...[slug].flat()], {
        encoding: 'utf8', timeout: 30_000,
        env: { ...process.env, ...extraEnv, PATH: `${bin}:${process.env.PATH}`, WP_PATH: site, MATRIX_CALLS: calls, MATRIX_NPX_DONE: join(dir, 'npx-completed') },
    });
    return { dir, site, result, originals, calls: readFileSync(calls, 'utf8').trim().split('\n').map(JSON.parse) };
}

for (const [name, env, expectedStatus, slug] of [
    ['successful run', {}, 0],
    ['successful Breeze run', {}, 0, 'breeze'],
    ['successful WP-Optimize run', {}, 0, 'wp-optimize'],
    ['fallback deactivation before the next plugin', { MATRIX_PRIMARY_DEACTIVATE_FAIL: '1' }, 0, ['autoptimize', 'breeze']],
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
            assert.equal(existsSync(join(run.site, 'fixture-page')), false, 'owned fixture removed even after an early failure');
            const restore = run.calls.findIndex(args => args[0] === 'eval-file' && args[2] === 'restore');
            const flush = run.calls.findLastIndex(args => args[0] === 'cache' && args[1] === 'flush');
            assert.ok(restore >= 0, 'original options restored');
            assert.ok(flush > restore, 'persistent object cache flushed after restoring options');
        } finally {
            rmSync(run.dir, { recursive: true, force: true });
        }
    });
}

for (const [name, env, message] of [
    ['both deactivation attempts fail', { MATRIX_DEACTIVATE_FAIL: '1' }, /autoptimize is still active after cleanup/],
    ['the plugin remains network-active', { MATRIX_DEACTIVATE_FAIL: '1', MATRIX_NETWORK_ACTIVE: '1' }, /autoptimize is still active after cleanup/],
    ['the inactive-state query fails', { MATRIX_STATE_CHECK_FAIL: '1' }, /could not verify deactivation of autoptimize/],
]) {
    test(`matrix stops and restores the site when ${name}`, () => {
        const run = runMatrix(env, ['autoptimize', 'breeze']);
        try {
            assert.equal(run.result.status, 1, run.result.stdout + run.result.stderr);
            assert.match(run.result.stderr, message);
            assert.equal(run.calls.some(args => args[0] === 'plugin' && args[1] === 'activate' && args[2] === 'breeze'), false, 'next plugin never activated');
            assert.ok(run.calls.some(args => args[0] === 'eval-file' && args[2] === 'restore'), 'EXIT trap still restores options');
            for (const [path, original] of Object.entries(run.originals)) {
                assert.equal(readFileSync(join(run.site, path), 'utf8'), original, path);
            }
            assert.equal(readlinkSync(join(run.site, 'wp-content/object-cache.php')), join(run.dir, 'redis-dropin.php'));
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


test('matrix preserves a fixture page that existed before the run', () => {
    const run = runMatrix({ MATRIX_EXISTING_FIXTURE: '1' });
    try {
        assert.equal(run.result.status, 0, run.result.stdout + run.result.stderr);
        assert.equal(readFileSync(join(run.site, 'fixture-page'), 'utf8'), 'original fixture content');
        assert.equal(run.calls.some(args => args[0] === 'post' && args[1] === 'delete'), false);
    } finally {
        rmSync(run.dir, { recursive: true, force: true });
    }
});

test('a fixture id that is not the matrix page is never deleted', () => {
    const run = runMatrix({ MATRIX_FIXTURE_SLUG_MISMATCH: '1' });
    try {
        assert.equal(run.result.status, 1, run.result.stdout + run.result.stderr);
        assert.match(run.result.stderr, /is not the matrix fixture page; left in place/);
        assert.equal(run.calls.some(args => args[0] === 'post' && args[1] === 'delete'), false, 'no delete issued');
        assert.ok(run.calls.some(args => args[0] === 'eval-file' && args[2] === 'restore'), 'configuration still restored');
    } finally {
        rmSync(run.dir, { recursive: true, force: true });
    }
});

test('failed fixture deletion fails the run but still restores the configuration', () => {
    const run = runMatrix({ MATRIX_DELETE_FIXTURE_FAIL: '1' });
    let snapshot;
    try {
        assert.equal(run.result.status, 1, run.result.stdout + run.result.stderr);
        assert.match(run.result.stderr, /fixture page deletion failed: 123/);
        assert.equal(existsSync(join(run.site, 'fixture-page')), true);
        for (const [path, original] of Object.entries(run.originals)) {
            assert.equal(readFileSync(join(run.site, path), 'utf8'), original, path);
        }
        assert.equal(readlinkSync(join(run.site, 'wp-content/object-cache.php')), join(run.dir, 'redis-dropin.php'));
        snapshot = run.result.stderr.match(/snapshot retained at (.+)/)?.[1];
        assert.ok(snapshot);
    } finally {
        if (snapshot) rmSync(snapshot, { recursive: true, force: true });
        rmSync(run.dir, { recursive: true, force: true });
    }
});

test('failed fixture creation stops before activating a cache plugin', () => {
    const run = runMatrix({ MATRIX_CREATE_FIXTURE_FAIL: '1' });
    try {
        assert.equal(run.result.status, 3, run.result.stdout + run.result.stderr);
        assert.equal(existsSync(join(run.site, 'fixture-page')), false);
        assert.equal(run.calls.some(args => args[0] === 'plugin' && args[1] === 'activate'), false);
        assert.ok(run.calls.some(args => args[0] === 'eval-file' && args[2] === 'restore'));
    } finally {
        rmSync(run.dir, { recursive: true, force: true });
    }
});
