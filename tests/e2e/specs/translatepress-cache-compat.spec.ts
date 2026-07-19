/**
 * TranslatePress + Cache Compatibility Mode — real-plugin coverage.
 *
 * Cache Compatibility Mode used to gate TranslatePress out of the language
 * resolution, collapsing a TRP site's banner to the default language. That was
 * wrong: TranslatePress ALWAYS encodes the language in the URL (non-default
 * languages in a subdirectory, /it/) and derives $TRP_LANGUAGE from that URL —
 * it has no cookie/parameter mode — so each language is URL-keyed and a
 * URL-keyed page cache stores one entry per language, exactly like Polylang.
 * `faz_trp_language_in_url()` encodes that and un-gates the branch.
 *
 * This spec runs against the REAL TranslatePress plugin (free on wp.org, fully
 * local — no API key needed), configured EN + IT. Weglot's equivalent branch
 * cannot be exercised end-to-end because the real plugin requires a paid
 * Weglot cloud API key to resolve anything; it is covered deterministically by
 * tests/unit/test-trp-weglot-cache-compat-php.php instead.
 *
 * The resolved language is read from the X-Faz-Current-Language header emitted
 * by the faz-e2e-wpml-litespeed-lab fixture (activated here purely as a probe —
 * its WPML emulation stays opt-in and OFF, so TranslatePress is the only
 * multilingual plugin in play).
 *
 * Auto-skips when TranslatePress is not installed.
 */
import { test, expect } from '../fixtures/wp-fixture';
import { type APIRequestContext } from '@playwright/test';
import { wp, wpEval, ensureFixturePlugin, isPluginActive } from '../utils/wp-env';
import { acquireSharedWordPressLock, releaseSharedWordPressLock } from '../utils/shared-wordpress-lock';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const UA = { 'User-Agent': 'Mozilla/5.0 (FAZ-E2E TRP)' };

let ready = false;
let savedFazSettings = '';
let savedTrpSettings = '';
let lockHeld = false;
let translatePressWasActive = false;
let fixtureWasActive = false;

test.describe.configure({ mode: 'serial' });

function trpInstalled(): boolean {
  try {
    wp(['plugin', 'is-installed', 'translatepress-multilingual']);
    return true;
  } catch {
    return false;
  }
}

function setCacheCompat(on: boolean): void {
  wpEval(`
    $s = get_option( 'faz_settings', array() );
    if ( ! is_array( $s ) ) { $s = array(); }
    if ( ! isset( $s['banner_control'] ) || ! is_array( $s['banner_control'] ) ) { $s['banner_control'] = array(); }
    $s['banner_control']['cache_compatibility'] = ${on ? 'true' : 'false'};
    update_option( 'faz_settings', $s );
  `);
}

async function probe(request: APIRequestContext, path: string): Promise<{ lang: string; trpUrlSafe: string }> {
  const resp = await request.get(`${WP_BASE}${path}`, { headers: UA });
  return {
    lang: (resp.headers()['x-faz-current-language'] ?? '').toLowerCase(),
    trpUrlSafe: resp.headers()['x-faz-trp-url-safe'] ?? '',
  };
}

test.beforeAll(async ({}, testInfo) => {
  testInfo.setTimeout(41 * 60_000);
  await acquireSharedWordPressLock();
  lockHeld = true;
  translatePressWasActive = isPluginActive('translatepress-multilingual');
  fixtureWasActive = isPluginActive('faz-e2e-wpml-litespeed-lab');

  if (!trpInstalled()) {
    ready = false;
    return;
  }
  savedFazSettings = wpEval(`echo wp_json_encode( get_option( 'faz_settings', array() ) );`).trim();
  savedTrpSettings = wpEval(`echo wp_json_encode( get_option( 'trp_settings', array() ) );`).trim();

  if (!translatePressWasActive) {
    wp(['plugin', 'activate', 'translatepress-multilingual']);
  }
  // Probe only — the lab's WPML emulation is opt-in and deliberately left off
  // so TranslatePress is the sole multilingual plugin under test.
  ensureFixturePlugin('faz-e2e-wpml-litespeed-lab');

  // TranslatePress: English default (served at /), Italian at /it/.
  wpEval(`
    $t = get_option( 'trp_settings', array() );
    if ( ! is_array( $t ) ) { $t = array(); }
    $t['default-language']       = 'en_US';
    $t['translation-languages']  = array( 'en_US', 'it_IT' );
    $t['publish-languages']      = array( 'en_US', 'it_IT' );
    $t['url-slugs']              = array( 'en_US' => 'en', 'it_IT' => 'it' );
    $t['add-subdirectory-to-default-language'] = 'no';
    update_option( 'trp_settings', $t );

    $s = get_option( 'faz_settings', array() );
    if ( ! is_array( $s ) ) { $s = array(); }
    $s['languages'] = array( 'default' => 'en', 'selected' => array( 'en', 'it' ) );
    update_option( 'faz_settings', $s );
  `);
  wp(['rewrite', 'flush']);
  ready = true;
});

test.afterAll(() => {
  try {
    const restore = (json: string, option: string) => {
      if (!json) return;
      const b64 = Buffer.from(json, 'utf8').toString('base64');
      wpEval(`
        $v = json_decode( base64_decode( '${b64}' ), true );
        if ( is_array( $v ) ) { update_option( '${option}', $v ); }
      `);
    };
    restore(savedFazSettings, 'faz_settings');
    restore(savedTrpSettings, 'trp_settings');

    const deactivate: string[] = [];
    if (!fixtureWasActive && isPluginActive('faz-e2e-wpml-litespeed-lab')) {
      deactivate.push('faz-e2e-wpml-litespeed-lab');
    }
    if (!translatePressWasActive && isPluginActive('translatepress-multilingual')) {
      deactivate.push('translatepress-multilingual');
    }
    if (deactivate.length > 0) {
      try {
        wp(['plugin', 'deactivate', ...deactivate]);
      } catch {
        /* best-effort */
      }
    }
    try {
      wp(['rewrite', 'flush']);
    } catch {
      /* best-effort */
    }
  } finally {
    if (lockHeld) {
      releaseSharedWordPressLock();
      lockHeld = false;
    }
  }
});

test.beforeEach(() => {
  test.skip(!ready, 'TranslatePress is not installed on this environment');
});

test.describe('TranslatePress + Cache Compatibility Mode', () => {
  test('01 FAZ recognises TranslatePress as a URL-keyed multilingual plugin', async ({ request }) => {
    setCacheCompat(true);
    const { trpUrlSafe } = await probe(request, '/');
    expect(trpUrlSafe).toBe('1');
    expect(wpEval(`echo faz_i18n_is_multilingual() ? '1' : '0';`).trim()).toBe('1');
  });

  test('02 [fix] cache-compat ON → the Italian URL resolves Italian', async ({ request }) => {
    // Before the fix this returned the site default (English) — the bug.
    setCacheCompat(true);
    const { lang } = await probe(request, '/it/');
    expect(lang).toBe('it');
  });

  test('03 [fix] cache-compat ON → the default URL still serves the default language', async ({ request }) => {
    // The language follows the URL, so the cache entry for / stays English.
    setCacheCompat(true);
    const { lang } = await probe(request, '/');
    expect(lang).toBe('en');
  });

  test('04 cache-compat OFF → the Italian URL resolves Italian (unchanged)', async ({ request }) => {
    setCacheCompat(false);
    const { lang } = await probe(request, '/it/');
    expect(lang).toBe('it');
  });

  test('05 cache-compat OFF → the default URL serves the default language (unchanged)', async ({ request }) => {
    setCacheCompat(false);
    const { lang } = await probe(request, '/');
    expect(lang).toBe('en');
  });
});
