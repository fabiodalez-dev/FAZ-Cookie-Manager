/**
 * Environment preflight for the E2E suite.
 *
 * Every requirement below has, at some point, been the real reason for a red
 * run that read like a product regression: the Stripe gateway left off by an
 * earlier spec, WP_DEBUG missing so a debug-only helper returned an empty
 * string, the suite pointed at a stale `php -S` on
 * IPv6 instead of nginx, an edit tested without ever being rsynced to the site.
 * Each cost more to diagnose than it should have, because the failure surfaced
 * deep inside an assertion instead of at the door.
 *
 * So the suite states its own requirements, in two registers:
 *
 *   - HARD — what a test run cannot fix and must not paper over: missing
 *     configuration, the wrong web server, a deployment that does not match
 *     the working tree. These throw, carrying the command that fixes them.
 *   - REPAIRABLE — WordPress state that specs legitimately mutate and
 *     occasionally fail to restore. These are put back, and every repair is
 *     printed, so a drifting environment is visible rather than silent.
 *
 * Escape hatches, for deliberately testing a broken environment:
 *   FAZ_E2E_SKIP_PREFLIGHT=1  skip the whole thing
 *   FAZ_E2E_SKIP_DEPLOY_CHECK=1  skip only the source/deploy comparison
 */

import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ensureFixturePlugin, wpEval } from './wp-env';

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

/**
 * Fixtures every run presumes are already installed and active. The rest of
 * `tests/e2e/fixtures/plugins/` is activated on demand by the specs that own
 * it, so it does not belong here.
 */
const BASELINE_FIXTURES = [
  'faz-e2e-provider-matrix',
  'faz-e2e-scan-lab',
  'faz-e2e-audit-lab',
  'faz-e2e-woo-lab',
];

/**
 * The deploy exclusions, read from the deploy script rather than restated.
 *
 * This list used to live here as a second copy, and the copies disagreed:
 * `scripts/deploy-test.sh` excludes `.git*` (which also covers `.gitignore`
 * and `.githooks/`), this one excluded only `.git/`. A perfectly correct
 * deploy therefore showed up here as five files of drift, and the preflight
 * refused to start the whole suite over a disagreement between two lists that
 * were meant to be the same list.
 *
 * Asking the script keeps the drift check honest by construction: whatever the
 * deploy actually skips is exactly what this declines to compare. A failure to
 * read it is fatal on purpose — falling back to a hardcoded list would restore
 * the very divergence this removes, and it would do so silently.
 */
let excludesCache: string[] | null = null;
function deployExcludes(): string[] {
  if (excludesCache) {
    return excludesCache;
  }
  const script = join(REPO_ROOT, 'scripts', 'deploy-test.sh');
  const out = execFileSync('bash', [script, '--print-excludes'], {
    encoding: 'utf8',
    timeout: 15_000,
  });
  const list = out.split('\n').map((l) => l.trim()).filter(Boolean);
  if (!list.length) {
    throw new Error(`${script} --print-excludes returned nothing`);
  }
  excludesCache = list;
  return list;
}

const OK = '\x1b[32m✓\x1b[0m';
const FIX = '\x1b[33m⟳\x1b[0m';

function fail(title: string, detail: string, remedy: string): never {
  throw new Error(
    `\n\x1b[31mE2E preflight failed — ${title}\x1b[0m\n\n  ${detail}\n\n  Fix:\n    ${remedy}\n`,
  );
}

/**
 * Files that differ between the working tree and the deployed plugin.
 * `-c` compares checksums rather than size+mtime, so an edit that happens to
 * preserve both is still caught; `-n` makes it a dry run.
 */
function deploymentDrift(deployPath: string): string[] {
  const args = [
    '-rcni',
    '--delete',
    ...deployExcludes().map((p) => `--exclude=${p}`),
    `${REPO_ROOT}/`,
    deployPath.endsWith('/') ? deployPath : `${deployPath}/`,
  ];
  const out = execFileSync('rsync', args, { encoding: 'utf8', timeout: 60_000 });
  return out.split('\n').map((l) => l.trim()).filter(Boolean);
}

/** Everything the suite needs, asserted before the first spec runs. */
export async function assertPrerequisites(baseURL: string): Promise<void> {
  if (process.env.FAZ_E2E_SKIP_PREFLIGHT === '1') {
    console.log('\n  E2E preflight skipped (FAZ_E2E_SKIP_PREFLIGHT=1)\n');
    return;
  }

  const ok: string[] = [];
  const repaired: string[] = [];

  // ── HARD: configuration ────────────────────────────────────────────────
  if (!process.env.WP_PATH) {
    fail(
      'WP_PATH is not set',
      'Every WP-CLI-backed helper needs the WordPress root; without it each spec fails on its own first wp() call instead of here.',
      'WP_PATH=/Users/<you>/Sites/faz-test npm run test:e2e',
    );
  }
  const deployPath = process.env.FAZ_PLUGIN_DEPLOY_PATH;
  if (!deployPath) {
    fail(
      'FAZ_PLUGIN_DEPLOY_PATH is not set',
      'Specs that read shipped data files (provider definitions, blocker templates) resolve them through it.',
      'FAZ_PLUGIN_DEPLOY_PATH=$WP_PATH/wp-content/plugins/faz-cookie-manager/ npm run test:e2e',
    );
  }
  ok.push(`${OK} WP_PATH and FAZ_PLUGIN_DEPLOY_PATH set`);

  // ── HARD: the documented web server ────────────────────────────────────
  // Several specs skip fixture-page assertions when the site is served by
  // PHP's built-in server, whose is_singular() handling is unreliable. That
  // branch reads FAZ_E2E_SERVER, so a wrong value silently removes coverage
  // rather than failing. Detect the server instead of trusting the variable,
  // and reconcile the two.
  let server = '';
  try {
    const res = await fetch(baseURL, { method: 'HEAD' });
    server = (res.headers.get('server') ?? '').toLowerCase();
  } catch (err) {
    fail(
      `WordPress is not reachable at ${baseURL}`,
      (err as Error).message,
      'brew services start nginx && brew services start php',
    );
  }
  //
  // Only validate here — do not assign. Playwright evaluates the config in
  // every worker process, so the config's default is what the specs actually
  // read; a mutation made in this (main-process) hook would not reliably
  // reach them.
  const actual = server.includes('development server') || server.startsWith('php') ? 'php-built-in' : 'nginx';
  const declared = (process.env.FAZ_E2E_SERVER ?? '').toLowerCase();
  if (declared && declared !== actual) {
    fail(
      'server mismatch',
      `FAZ_E2E_SERVER=${declared}, but ${baseURL} answers with "${server || 'no Server header'}" (${actual}). ` +
        'Specs branching on this would opt themselves out of — or into — the wrong coverage.',
      actual === 'php-built-in'
        ? 'Point WP_BASE_URL at the nginx vhost (http://127.0.0.1:9998) and kill any stray `php -S`; the documented stack is nginx + PHP-FPM.'
        : 'export FAZ_E2E_SERVER=nginx (or unset it — the Playwright config defaults to nginx).',
    );
  }
  ok.push(`${OK} server: ${server || 'unknown'} (FAZ_E2E_SERVER=${declared || actual})`);

  // ── HARD: the site runs the code under test ────────────────────────────
  // The commonest wasted debugging session in this project is an edit tested
  // before it was rsynced. rsync itself answers the question authoritatively,
  // so ask it rather than trusting a version constant that rarely changes.
  if (process.env.FAZ_E2E_SKIP_DEPLOY_CHECK !== '1') {
    let drift: string[];
    try {
      drift = deploymentDrift(deployPath);
    } catch (err) {
      fail(
        'could not compare the working tree with the deployed plugin',
        (err as Error).message,
        `check that ${deployPath} exists, or set FAZ_E2E_SKIP_DEPLOY_CHECK=1 to run anyway.`,
      );
    }
    if (drift.length) {
      const shown = drift.slice(0, 12).map((l) => `      ${l}`).join('\n');
      const more = drift.length > 12 ? `\n      … and ${drift.length - 12} more` : '';
      fail(
        `the deployed plugin is ${drift.length} file(s) behind the working tree`,
        `The suite would test code you are not editing.\n\n${shown}${more}`,
        `FAZ_DEPLOY_TARGET="${deployPath}" bash scripts/deploy-test.sh`,
      );
    }
    ok.push(`${OK} deployed plugin matches the working tree`);
  }

  // ── HARD: WordPress-side configuration ─────────────────────────────────
  // One probe for everything, so a fatal in the plugin surfaces once, here,
  // rather than as a WP-CLI stack trace from whichever spec ran first.
  let probe: string;
  try {
    probe = wpEval(`
      $settings = (array) get_option( 'faz_settings', array() );
      $gateways = isset( $settings['script_blocking']['payment_gateways'] )
        ? (array) $settings['script_blocking']['payment_gateways'] : array();
      echo wp_json_encode( array(
        'debug'      => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
        'permalinks' => (string) get_option( 'permalink_structure' ),
        'version'    => defined( 'FAZ_VERSION' ) ? FAZ_VERSION : '',
        'active'     => array_map( function ( $p ) { return strtok( $p, '/' ); },
                          array_values( (array) get_option( 'active_plugins', array() ) ) ),
        'stripe'     => ! empty( $gateways['stripe'] ),
      ) );
    `);
  } catch (err) {
    fail(
      'WordPress could not be inspected',
      `WP-CLI failed to bootstrap the site at ${process.env.WP_PATH}. A PHP fatal on plugin load, or an unreachable database, both land here.\n\n  ${(err as Error).message.split('\n').slice(0, 3).join('\n  ')}`,
      `wp --path=${process.env.WP_PATH} option get siteurl   # reproduce it directly`,
    );
  }

  let wp: {
    debug: boolean;
    permalinks: string;
    version: string;
    active: string[];
    stripe: boolean;
  };
  try {
    wp = JSON.parse(probe);
  } catch {
    fail(
      'the environment probe returned unparseable output',
      `Expected JSON, got:\n\n  ${probe.slice(0, 300)}\n\n  A PHP notice or warning printed before the JSON is the usual cause.`,
      'silence the notice, or run with FAZ_E2E_SKIP_PREFLIGHT=1 to bypass.',
    );
  }

  if (!wp.version) {
    fail(
      'FAZ Cookie Manager is not active',
      'FAZ_VERSION is undefined on the test site, so the plugin is deployed but deactivated (or fatals on load).',
      `wp --path=${process.env.WP_PATH} plugin activate faz-cookie-manager`,
    );
  }
  ok.push(`${OK} plugin active (v${wp.version})`);

  if (!wp.permalinks) {
    fail(
      'plain permalinks',
      'The fixture rewrite rules — /faz-e2e-provider-script/…/stripe.js and friends — 404 without them, and the provider-blocking specs lose their subject silently.',
      `wp --path=${process.env.WP_PATH} option update permalink_structure '/%postname%/' && wp --path=${process.env.WP_PATH} rewrite flush`,
    );
  }
  ok.push(`${OK} pretty permalinks (${wp.permalinks})`);

  if (!wp.debug) {
    fail(
      'WP_DEBUG is off',
      'The IP-hash regression exercises a WP_DEBUG-gated helper that returns an empty string otherwise — the failure looks like a hashing bug, not a configuration one.',
      `wp --path=${process.env.WP_PATH} config set WP_DEBUG true --raw   (WP_DEBUG_DISPLAY/LOG can stay off)`,
    );
  }
  ok.push(`${OK} WP_DEBUG on`);

  // ── REPAIRABLE: fixtures and option state ──────────────────────────────
  // ensureFixturePlugin rsyncs the fixture from the repo before activating,
  // so this also rules out a fixture that drifted from its source.
  for (const slug of BASELINE_FIXTURES) {
    if (wp.active.includes(slug)) {
      continue;
    }
    try {
      ensureFixturePlugin(slug);
    } catch (err) {
      fail(
        `fixture plugin ${slug} could not be installed`,
        `Specs that depend on it would run against a site with no subject to assert on.\n\n  ${(err as Error).message.split('\n')[0]}`,
        `check tests/e2e/fixtures/plugins/${slug}/ exists, then: wp --path=${process.env.WP_PATH} plugin activate ${slug}`,
      );
    }
    repaired.push(`fixture plugin ${slug}: inactive → active`);
  }
  if (!repaired.length) {
    ok.push(`${OK} baseline fixture plugins active (${BASELINE_FIXTURES.length})`);
  }

  if (!wp.stripe) {
    wpEval(`
      $s = (array) get_option( 'faz_settings', array() );
      $s['script_blocking']['payment_gateways']['stripe'] = true;
      update_option( 'faz_settings', $s );
    `);
    repaired.push('payment gateway "stripe": off → on');
  }
  console.log('\n  E2E preflight');
  ok.forEach((line) => console.log(`  ${line}`));
  repaired.forEach((line) => console.log(`  ${FIX} repaired ${line}`));
  if (repaired.length) {
    console.log(
      `  \x1b[33m${repaired.length} prerequisite(s) had drifted\x1b[0m — a previous run left the site dirty; ` +
        'the specs relying on them would have failed for the wrong reason.',
    );
  }
  console.log('');
}
