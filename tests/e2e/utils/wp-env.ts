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

export function wp(args: string[]): string {
  assertWpPath();
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], {
    encoding: 'utf8',
    env: WP_CLI_ENV,
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

export function wpEval(code: string): string {
  return wp(['eval', code]);
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

export function activatePlugins(slugs: string[]): void {
  if (slugs.length === 0) {
    return;
  }
  wp(['plugin', 'activate', ...slugs]);
}

export function setOption(optionName: string, value: string): void {
  wp(['option', 'update', optionName, value]);
}

export function deleteOption(optionName: string): void {
  try {
    wp(['option', 'delete', optionName]);
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
