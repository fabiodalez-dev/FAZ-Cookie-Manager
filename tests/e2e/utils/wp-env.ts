import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// WP_PATH must be supplied explicitly by the caller. We deliberately do
// NOT fall back to a developer-machine path: a wrong fallback would let
// every WP-CLI call below run against the wrong WordPress install on
// CI / other contributors' machines, producing failures that look like
// real product bugs.
//
// Resolution is **lazy** — the constant exposes the env value (or an
// empty string), and the actual "WP_PATH must be set" enforcement
// lives in `assertWpPath()`, which runs only when a wp-cli call
// actually fires. This lets specs that import other helpers from this
// module (e.g. `SCAN_LAB_PAGE_SLUGS`) load on machines where WP_PATH
// is intentionally unset, without crashing the whole Playwright run.
export const WP_PATH = process.env.WP_PATH ?? '';

const UTILS_DIR = dirname(fileURLToPath(import.meta.url));
const WP_PLUGIN_DIR = join(WP_PATH, 'wp-content', 'plugins');
const FIXTURE_PLUGIN_DIR = join(UTILS_DIR, '..', 'fixtures', 'plugins');
const WP_CLI_ENV = {
  ...process.env,
  WP_CLI_PHP_ARGS: '-d error_reporting=E_ERROR -d display_errors=0',
};
const WP_CLI_TIMEOUT_ENV = Number(process.env.WP_CLI_TIMEOUT_MS);
// Default 30s. Briefly tried 60s to cushion `wp plugin activate` under
// suite-wide load (commit 4bb6cef) — that change made things WORSE because
// downstream tests waited up to 60s for a stuck activation instead of
// failing fast at 30s, so multi-minute stalls cascaded into 26+ minute
// test runs (e.g. frontend-consent.spec.ts:5 = 26.9m, DSAR-09 = 32.7m in
// the v10 suite). Reverted to 30s; fail-fast is the right default.
const WP_CLI_TIMEOUT_MS = Number.isFinite(WP_CLI_TIMEOUT_ENV) && WP_CLI_TIMEOUT_ENV > 0 ? WP_CLI_TIMEOUT_ENV : 30_000;

export const SCAN_LAB_PAGE_SLUGS = [
  'faz-lab-js-basic',
  'faz-lab-js-delayed',
  'faz-lab-js-dupe-a',
  'faz-lab-js-dupe-b',
  'faz-lab-headers',
  'faz-lab-script-src-ga',
  'faz-lab-script-data-src-ga',
  'faz-lab-script-litespeed-fb',
  'faz-lab-iframe-youtube',
  'faz-lab-script-src-facebook',
];
export const PROVIDER_MATRIX_PAGE_SLUG = 'faz-provider-matrix';

function assertWpPath(): void {
  if (!WP_PATH) {
    throw new Error(
      'WP_PATH env var is required for the E2E utils to call wp-cli. ' +
      'Re-run the suite with `WP_PATH=/path/to/wordpress npm run test:e2e` ' +
      '(or whatever script wraps Playwright on your environment).'
    );
  }
  if (!existsSync(WP_PATH)) {
    throw new Error(`WP_PATH does not exist: ${WP_PATH}`);
  }
}

// Transient-failure detection: wp-cli occasionally hits a SIGTERM kill due to
// MySQL pool exhaustion, an in-flight PHP-FPM worker recycle, or an OPcache
// stale-file reload mid-call. These are not real test failures and retry on
// a fresh process usually succeeds. Match on:
//   - the explicit timeout we emit when execFileSync's `timeout` kicks in
//   - SIGTERM/SIGKILL kill signals on child_process errors
//   - 'Database connection' / 'MySQL server has gone away' runtime fragments
//     the WP bootstrap can emit when the DB blinks
const TRANSIENT_WP_CLI_PATTERNS = [
  /ETIMEDOUT/i,
  /SIGTERM/i,
  /SIGKILL/i,
  /Database connection/i,
  /MySQL server has gone away/i,
  /Lost connection to MySQL/i,
];

function isTransientWpCliError(error: unknown): boolean {
  if (!(error instanceof Error)) return false;
  const code = (error as NodeJS.ErrnoException).code ?? '';
  const signal = (error as { signal?: string }).signal ?? '';
  const haystack = `${error.message}\n${code}\n${signal}`;
  return TRANSIENT_WP_CLI_PATTERNS.some((re) => re.test(haystack));
}

function blockingSleep(ms: number): void {
  // execFileSync is sync, so retry backoff needs a sync sleep. Atomics.wait
  // on a SharedArrayBuffer yields the CPU without busy-waiting and works on
  // any Node 12+. Avoids spawning child_process('sleep') for sub-second
  // delays, which would add ~30-50ms fork overhead.
  const sab = new SharedArrayBuffer(4);
  Atomics.wait(new Int32Array(sab), 0, 0, ms);
}

/**
 * Format a wp-cli invocation for safe inclusion in error messages / CI logs.
 *
 * Callers like setOption(name, value) and wpEval(php) can pass API tokens,
 * encrypted blobs, attestation JSON, or arbitrary PHP code. If a wp-cli call
 * fails and we dump the raw args in the thrown error, those values land in
 * the CI artifact log. Redact the payload while keeping enough structural
 * information to identify which call failed.
 *
 * Sanitisation rules — narrow on purpose. A broad "redact everything"
 * sweep would lose the per-test debugging signal that test authors rely on
 * (which plugin slug, which post type, which option name failed). The
 * surfaces enumerated here are the ones that demonstrably carry secrets
 * via existing callers in this file.
 */
function formatWpCommand(args: string[]): string {
  const prefix = `wp --path=${WP_PATH}`;
  // `wp eval <php>` — the PHP body can contain encrypted keys, salt
  // values, or DB rows from privileged tables.
  if (args[0] === 'eval' && args.length >= 2) {
    return `${prefix} eval [REDACTED ${args[1].length} chars]`;
  }
  // `wp option update <name> <value>` — value can be a token / API key.
  // The option NAME stays in the message because that's the part a
  // developer needs to know to debug, and it's intentionally non-secret
  // (option names are listed in the schema, the value is what's secret).
  if (args[0] === 'option' && args[1] === 'update' && args.length >= 4) {
    return `${prefix} option update ${args[2]} [REDACTED]`;
  }
  // `wp user create <login> <email> --user_pass=<pw>` — the password
  // arg is sensitive. Strip --user_pass=… and any --*_pass=… look-alike
  // while preserving the positional args.
  if (args[0] === 'user' && args[1] === 'create') {
    const redacted = args.map((a) => a.replace(/^(--[a-z_-]*pass[a-z_]*)=.*$/i, '$1=[REDACTED]'));
    return `${prefix} ${redacted.join(' ')}`;
  }
  return `${prefix} ${args.join(' ')}`;
}

export type WpOptions = {
  // When false, fail fast on any error — no transient retry. The general
  // mutation channels (wpEval, setOption, deleteOption) opt out because
  // a mid-MySQL transient ("server has gone away" / "Lost connection")
  // could re-run the same INSERT/UPDATE against already-mutated state.
  // Direct wp() callers that issue mutating subcommands not wrapped
  // here (e.g. raw `wp(['post','create',...])`) should also pass
  // allowRetry:false when the operation isn't idempotent.
  allowRetry?: boolean;
};

export function wp(args: string[], options: WpOptions = {}): string {
  assertWpPath();
  const command = formatWpCommand(args);
  const allowRetry = options.allowRetry !== false;
  const maxAttempts = allowRetry ? 2 : 1; // 1 retry on transient error (read paths); fail-fast on opted-out mutations.
  let lastError: unknown;

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    try {
      return execFileSync('wp', [`--path=${WP_PATH}`, ...args], {
        encoding: 'utf8',
        env: WP_CLI_ENV,
        killSignal: 'SIGTERM',
        stdio: ['ignore', 'pipe', 'pipe'],
        timeout: WP_CLI_TIMEOUT_MS,
      }).trim();
    } catch (error) {
      lastError = error;
      if (attempt < maxAttempts && isTransientWpCliError(error)) {
        // 500ms backoff lets a recycling PHP-FPM worker / blinking MySQL
        // settle before the retry — empirically enough on the dev stack
        // (nginx + PHP-FPM 8.4 + brew MySQL). Longer makes per-spec
        // overhead visible in serial runs; shorter risks the same
        // transient firing twice.
        blockingSleep(500);
        continue;
      }
      break;
    }
  }

  if (lastError instanceof Error) {
    lastError.message = `WP-CLI command failed or timed out after ${WP_CLI_TIMEOUT_MS}ms (${maxAttempts} attempt(s)): ${command}\n${lastError.message}`;
  }
  throw lastError;
}

export function wpEval(code: string): string {
  // PHP eval body can run arbitrary mutations. A transient "MySQL gone
  // away" mid-execution would re-run the body against already-mutated
  // state on retry, so we fail fast here. Read-only eval bodies that
  // really need transient resilience should call wp() directly.
  return wp(['eval', code], { allowRetry: false });
}

function rsyncDirectory(sourceDir: string, targetDir: string): void {
  execFileSync('mkdir', ['-p', targetDir], {
    stdio: ['ignore', 'ignore', 'ignore'],
  });
  execFileSync('rsync', ['-a', '--delete', `${sourceDir}/`, `${targetDir}/`], {
    stdio: ['ignore', 'ignore', 'pipe'],
  });
}

export function ensureFixturePlugin(slug: string): void {
  const sourceDir = join(FIXTURE_PLUGIN_DIR, slug);
  const targetDir = join(WP_PLUGIN_DIR, slug);
  if (!existsSync(sourceDir)) {
    throw new Error(`Fixture plugin not found: ${sourceDir}`);
  }
  rsyncDirectory(sourceDir, targetDir);
  wp(['plugin', 'activate', slug]);
}

export function listActivePlugins(): string[] {
  const raw = wp(['plugin', 'list', '--status=active', '--field=name']);
  return raw
    .split('\n')
    .map((value) => value.trim())
    .filter(Boolean);
}

export function listActivePluginFiles(): string[] {
  const raw = wpEval(`echo wp_json_encode( array_values( (array) get_option( 'active_plugins', array() ) ) );`);
  const parsed = JSON.parse(raw) as unknown;
  return Array.isArray(parsed) ? parsed.filter((value): value is string => typeof value === 'string') : [];
}

export function restoreActivePluginFiles(pluginFiles: string[]): void {
  const encoded = Buffer.from(JSON.stringify(pluginFiles), 'utf8').toString('base64');
  wpEval(`
    $plugins = json_decode( base64_decode( '${encoded}' ), true );
    if ( ! is_array( $plugins ) ) {
      $plugins = array();
    }
    $plugins = array_values( array_filter( $plugins, function ( $plugin ) {
      return is_string( $plugin ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin );
    } ) );
    update_option( 'active_plugins', $plugins );
    delete_site_transient( 'update_plugins' );
    wp_cache_delete( 'plugins', 'plugins' );
  `);
}

export function deactivatePluginsExcept(allowedSlugs: string[]): void {
  const allowed = new Set(allowedSlugs);
  const extraActive = listActivePlugins().filter((slug) => !allowed.has(slug));
  if (extraActive.length === 0) {
    return;
  }
  for (const slug of extraActive) {
    try {
      wp(['plugin', 'deactivate', slug]);
    } catch {
      // Test teardowns should be resilient when a fixture plugin was already
      // removed or auto-deactivated earlier in the flow.
    }
  }
}

export function activatePlugins(slugs: string[], options: { tolerateFailures?: boolean } = {}): void {
  const tolerateFailures = options.tolerateFailures ?? false;
  if (slugs.length === 0) {
    return;
  }
  // Activate one at a time so that:
  // 1. Each call finishes well within the 30-second WP-CLI timeout (a single
  //    plugin activates in ~1 s; batching 30+ plugins in one call can exceed it).
  // 2. Dependency order is respected — sort WooCommerce first so plugins that
  //    depend on it (e.g. Kliken, Meta for WooCommerce) activate after it.
  const sorted = [...slugs].sort((a, b) => {
    if (a === 'woocommerce') return -1;
    if (b === 'woocommerce') return 1;
    return 0;
  });
  for (const slug of sorted) {
    try {
      wp(['plugin', 'activate', slug]);
    } catch (error) {
      const message = error instanceof Error ? error.stack || error.message : String(error);
      console.error(`Failed to activate plugin "${slug}": ${message}`);
      if (!tolerateFailures) {
        throw error;
      }
    }
  }
}

export function setOption(optionName: string, value: string): void {
  // option update mutates wp_options; no transient retry — see wpEval rationale.
  wp(['option', 'update', optionName, value], { allowRetry: false });
}

export function deleteOption(optionName: string): void {
  try {
    // option delete mutates wp_options; no transient retry — see wpEval rationale.
    wp(['option', 'delete', optionName], { allowRetry: false });
  } catch {
    // Option may not exist yet.
  }
}

function findPostIdBySlug(slug: string, postType: string): number | null {
  const raw = wp(['post', 'list', `--post_type=${postType}`, '--fields=ID,post_name', '--format=json']);
  const posts = JSON.parse(raw) as Array<{ ID: number; post_name: string }>;
  const match = posts.find((post) => post.post_name === slug);
  return match ? Number(match.ID) : null;
}

export function upsertPage(slug: string, title: string, content = ''): number {
  const existingId = findPostIdBySlug(slug, 'page');
  const pageContent = content || `FAZ E2E page for ${slug}`;
  if (existingId) {
    wpEval(`wp_update_post(array('ID' => ${existingId}, 'post_content' => ${JSON.stringify(pageContent)}, 'post_title' => ${JSON.stringify(title)}));`);
    return existingId;
  }

  const id = wp([
    'post',
    'create',
    '--post_type=page',
    '--post_status=publish',
    `--post_title=${title}`,
    `--post_name=${slug}`,
    `--post_content=${pageContent}`,
    '--porcelain',
  ]);
  return Number(id);
}

export function ensureScanLabPages(): void {
  for (const slug of SCAN_LAB_PAGE_SLUGS) {
    upsertPage(slug, slug.replace(/^faz-lab-/, '').replace(/-/g, ' '));
  }
}

export function ensureProviderMatrixPage(): number {
  return upsertPage(PROVIDER_MATRIX_PAGE_SLUG, 'FAZ Provider Matrix', 'FAZ provider matrix page for scanner and blocker e2e coverage.');
}

export function readProviderMatrixUrl(): string {
  return wpEval(`
    $page = get_page_by_path( ${JSON.stringify(PROVIDER_MATRIX_PAGE_SLUG)}, OBJECT, 'page' );
    echo $page ? get_permalink( $page->ID ) : '';
  `);
}

export function touchPosts(postType: string, slugs: string[]): void {
  for (const slug of slugs) {
    const id = findPostIdBySlug(slug, postType);
    if (!id) {
      continue;
    }
    wpEval(`
      wp_update_post(
        array(
          'ID' => ${id},
          'post_modified' => current_time( 'mysql' ),
          'post_modified_gmt' => current_time( 'mysql', 1 ),
        )
      );
    `);
  }
}

export function setLabToken(token: string): void {
  setOption('faz_e2e_scan_lab_token', token);
  setOption('faz_e2e_woo_lab_token', token);
}

export function disableLabFlags(): void {
  setOption('faz_e2e_scan_lab_home_enabled', 'no');
  setOption('faz_e2e_woo_lab_enabled', 'no');
}

type ProviderMatrixResetOptions = {
  clearFixtureCustomRules?: boolean;
};

export function resetProviderMatrixState(options: ProviderMatrixResetOptions = {}): void {
  deleteOption('faz_e2e_provider_matrix_hits');
  setOption('faz_e2e_provider_matrix_woo_enabled', 'no');
  setOption('faz_e2e_provider_matrix_custom_enabled', 'no');
  // Remove stale cookie-DB rows for fixture-emitted cookies. Prior scanner runs
  // discover these cookies and default them to `uncategorized` — which the
  // plugin then shreds on any page load where `uncategorized=no`, breaking the
  // matrix test's expectation that `functional=yes` keeps `_faz_custom_functional`.
  // The custom fixture scripts (category=functional|performance) are always
  // re-emitted on demand, so deleting the DB row is safe — it simply lets the
  // real-time script blocker decide what to do with the cookie based on the
  // current custom_rule mapping, not the stale auto-discovered category.
  //
  // We also invalidate the `cookies` controller cache (serialized into
  // `_transient_faz_cookies_transient_prefix`) so subsequent page loads don't
  // re-read the stale row from cache.
  wpEval(`
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
      "DELETE FROM {$wpdb->prefix}faz_cookies WHERE name IN ( %s, %s )",
      '_faz_custom_functional',
      '_faz_custom_provider'
    ) );
    $clear_fixture_custom_rules = ${options.clearFixtureCustomRules ? 'true' : 'false'};
    $settings = get_option( 'faz_settings', array() );
    if ( $clear_fixture_custom_rules && is_array( $settings ) && isset( $settings['script_blocking']['custom_rules'] ) && is_array( $settings['script_blocking']['custom_rules'] ) ) {
      $fixture_patterns = array( 'faz-lab-custom-provider.js', 'faz-lab-custom-functional.js' );
      $settings['script_blocking']['custom_rules'] = array_values( array_filter(
        $settings['script_blocking']['custom_rules'],
        static function ( $rule ) use ( $fixture_patterns ) {
          $pattern = is_array( $rule ) && isset( $rule['pattern'] ) ? (string) $rule['pattern'] : '';
          return ! in_array( $pattern, $fixture_patterns, true );
        }
      ) );
      update_option( 'faz_settings', $settings, false );
    }
    if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'cookies' );
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'categories' );
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
    }
  `);
}

export function enableProviderMatrixWooScenario(): void {
  setOption('faz_e2e_provider_matrix_woo_enabled', 'yes');
}

export function enableProviderMatrixCustomScenario(): void {
  setOption('faz_e2e_provider_matrix_custom_enabled', 'yes');
}

export function readProviderMatrixHits(): Record<string, number> {
  const raw = wpEval(`echo wp_json_encode( get_option( 'faz_e2e_provider_matrix_hits', array() ) );`);
  if (!raw) {
    return {};
  }

  const parsed = JSON.parse(raw) as Record<string, number> | Array<unknown>;
  return Array.isArray(parsed) ? {} : parsed;
}

export function enableScanLabHomepageScenario(): void {
  setOption('faz_e2e_scan_lab_home_enabled', 'yes');
}

export function enableWooLabScenario(): void {
  setOption('faz_e2e_woo_lab_enabled', 'yes');
}

export function ensureWooCommerce(): void {
  try {
    wp(['plugin', 'activate', 'woocommerce']);
  } catch {
    wp(['plugin', 'install', 'woocommerce', '--activate']);
  }
}

export function ensureWooCommerceLabData(): void {
  ensureWooCommerce();
  wpEval(`
    if ( ! class_exists( 'WooCommerce' ) ) {
      throw new Exception( 'WooCommerce not loaded.' );
    }

    $pages = array(
      'shop' => array( 'Shop', 'shop' ),
      'cart' => array( 'Cart', 'cart' ),
      'checkout' => array( 'Checkout', 'checkout' ),
      'myaccount' => array( 'My Account', 'my-account' ),
    );

    foreach ( $pages as $key => $data ) {
      $current = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( $key ) : 0;
      if ( $current > 0 ) {
        continue;
      }
      $existing = get_page_by_path( $data[1], OBJECT, 'page' );
      if ( $existing ) {
        $page_id = $existing->ID;
      } else {
        $page_id = wp_insert_post(
          array(
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_title'   => $data[0],
            'post_name'    => $data[1],
            'post_content' => 'FAZ Woo lab page ' . $data[1],
          )
        );
      }
      update_option( 'woocommerce_' . $key . '_page_id', $page_id );
    }

    $product = get_page_by_path( 'faz-lab-woo-product', OBJECT, 'product' );
    if ( ! $product ) {
      $product_id = wp_insert_post(
        array(
          'post_status'  => 'publish',
          'post_type'    => 'product',
          'post_title'   => 'FAZ Lab Woo Product',
          'post_name'    => 'faz-lab-woo-product',
          'post_content' => 'WooCommerce lab product.',
        )
      );
      update_post_meta( $product_id, '_regular_price', '19.99' );
      update_post_meta( $product_id, '_price', '19.99' );
      update_post_meta( $product_id, '_stock_status', 'instock' );
      update_post_meta( $product_id, '_manage_stock', 'no' );
    }

    flush_rewrite_rules();
  `);
}

export function readWooUrls(): { cart: string; checkout: string; myaccount: string; product: string; shop: string } {
  const raw = wpEval(`
    $urls = array();
    foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $key ) {
      $id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( $key ) : 0;
      $urls[ $key ] = $id > 0 ? get_permalink( $id ) : '';
    }
    $product = get_page_by_path( 'faz-lab-woo-product', OBJECT, 'product' );
    $urls['product'] = $product ? get_permalink( $product ) : '';
    echo wp_json_encode( $urls );
  `);

  return JSON.parse(raw) as { cart: string; checkout: string; myaccount: string; product: string; shop: string };
}

export function resetScanState(): void {
  wpEval(`
    delete_option( 'faz_scan_history' );
    delete_option( 'faz_scan_details' );
    delete_option( 'faz_scan_counter' );
    delete_option( 'faz_scanner_debug_log' );
  `);
}

/**
 * Flush every cache that the FAZ cookie pipeline relies on. Used by specs
 * that insert/delete cookies via raw `$wpdb->insert/delete` (bypassing the
 * `faz_after_create_cookie` / `faz_after_delete_cookie` action hooks), so
 * the next page load on the frontend sees the fresh DB state instead of
 * a stale cached _categories[].cookies or _cookieScripts payload.
 *
 * Also re-emits `faz_after_create_cookie` so any plugin/listener that
 * registers after this helper still picks the change up — this avoids
 * specs going stale if a new controller-level cache is added later.
 */
export function clearAllFazCookieCaches(): void {
  wpEval(`
    delete_transient( 'faz_cookie_scripts_map' );
    \\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Cookie_Controller::get_instance()->delete_cache();
    \\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Category_Controller::get_instance()->delete_cache();
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
      faz_clear_banner_template_cache();
    }
    do_action( 'faz_after_create_cookie' );
  `);
}

/**
 * Synthetic "user has already consented to everything" cookie. Used by
 * specs whose subject is an interaction that lives AFTER the banner has
 * been dismissed (DSAR forms, click-to-consent flows, …) where leaving
 * the consent banner up would intercept the click and break the test.
 *
 * Reads the current consent revision so the cookie isn't immediately
 * invalidated by `faz_maybe_invalidate_stale_consent_cookie`.
 *
 * @param page    Playwright Page (used for context().addCookies)
 * @param baseURL The site origin (e.g., http://127.0.0.1:9998)
 * @param consentId Optional opaque identifier; defaults to a random token.
 */
export async function seedConsentedCookie(
  page: import('@playwright/test').Page,
  baseURL: string,
  consentId = 'e2e-seed',
): Promise<void> {
  const rev = parseInt(wpEval('echo faz_get_consent_revision();').trim(), 10) || 1;
  const domain = new URL(baseURL).hostname;
  await page.context().addCookies([{
    name:     'fazcookie-consent',
    value:    `consentid%3A${encodeURIComponent(consentId)}%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes%2Cfunctional%3Ayes%2Canalytics%3Ayes%2Cperformance%3Ayes%2Cuncategorized%3Ayes%2Cmarketing%3Ayes%2Crev%3A${rev}`,
    domain,
    path:     '/',
    sameSite: 'Lax',
  }]);
}
