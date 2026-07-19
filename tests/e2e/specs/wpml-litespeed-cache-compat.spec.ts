/**
 * WPML + LiteSpeed + Cache Compatibility Mode — reproduces the wp.org support
 * report where a WPML (IT/EN) site showed the cookie banner only in the default
 * language until Cache Compatibility Mode was turned off, and answers the
 * follow-up question ("can I keep a page cache and still get the right language,
 * does the plugin invalidate the cache?").
 *
 * Environment notes (why this is faithful without the commercial plugins):
 *   - Real WPML is commercial and cannot ship in the repo, so the fixture
 *     plugin faz-e2e-wpml-litespeed-lab EMULATES WPML's detection surface
 *     (ICL_LANGUAGE_CODE, SitePress, the wpml_current_language / wpml_setting
 *     filters). FAZ resolves language through exactly those hooks, so its
 *     behaviour is identical to a real WPML install. The "current" language is
 *     driven by ?wpmllang= (simulating a visit to WPML's /it/ or /en/ URL) and
 *     the negotiation mode by the faz_e2e_wpml_negotiation option
 *     (1 = directory, 2 = domain, 3 = URL parameter).
 *   - LiteSpeed's full-page cache only runs on a LiteSpeed web server (this dev
 *     box is nginx), so HIT/MISS page caching can't be exercised here. But the
 *     LiteSpeed Cache plugin defines \LiteSpeed\Purge, which activates FAZ's
 *     LiteSpeed adapter; the fixture counts LiteSpeed's litespeed_purged_all
 *     action so the tests prove FAZ invalidates the LiteSpeed cache on a save.
 *
 * The resolved language + WPML URL-safety are read from the X-Faz-Current-Language
 * and X-Faz-Wpml-Url-Safe response headers the fixture emits on every front-end
 * request.
 *
 * Auto-skips when the LiteSpeed Cache plugin is not installed.
 */
import { test, expect } from '../fixtures/wp-fixture';
import { type APIRequestContext } from '@playwright/test';
import { wp, wpEval, setOption, ensureFixturePlugin, isPluginActive } from '../utils/wp-env';
import { acquireSharedWordPressLock, releaseSharedWordPressLock } from '../utils/shared-wordpress-lock';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

let ready = false;
let savedSettings = '';
let lockHeld = false;
let litespeedWasActive = false;
let fixtureWasActive = false;

test.describe.configure({ mode: 'serial' });

function litespeedInstalled(): boolean {
  try {
    wp(['plugin', 'is-installed', 'litespeed-cache']);
    return true;
  } catch {
    return false;
  }
}

/** WPML negotiation mode the fixture reports: 1 = directory, 2 = domain, 3 = parameter. */
function setNegotiation(mode: 1 | 2 | 3): void {
  setOption('faz_e2e_wpml_negotiation', String(mode));
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

function lsPurgeCount(): number {
  return Number(wpEval(`echo (int) get_option( 'faz_e2e_ls_purge_count', 0 );`).trim());
}

function resetLsPurgeCount(): void {
  setOption('faz_e2e_ls_purge_count', '0');
}

function fireHook(hook: string): void {
  // FAZ's cache adapters are intentionally instantiated on rest_api_init. A
  // bare WP-CLI do_action() would skip that production bootstrap and test only
  // an unregistered hook, yielding a false purge failure.
  wpEval(`do_action('rest_api_init'); do_action('${hook}');`);
}

/** Read FAZ's resolved language + WPML URL-safety off the fixture's headers. */
async function langProbe(
  request: APIRequestContext,
  wpmllang: string,
): Promise<{ lang: string; urlSafe: string }> {
  const resp = await request.get(`${WP_BASE}/?wpmllang=${wpmllang}`, {
    headers: { 'User-Agent': 'Mozilla/5.0 (FAZ-E2E WPML)' },
  });
  return {
    lang: (resp.headers()['x-faz-current-language'] ?? '').toLowerCase(),
    urlSafe: resp.headers()['x-faz-wpml-url-safe'] ?? '',
  };
}

test.beforeAll(async ({}, testInfo) => {
  testInfo.setTimeout(41 * 60_000);
  await acquireSharedWordPressLock();
  lockHeld = true;
  litespeedWasActive = isPluginActive('litespeed-cache');
  fixtureWasActive = isPluginActive('faz-e2e-wpml-litespeed-lab');

  if (!litespeedInstalled()) {
    ready = false;
    return;
  }
  // Snapshot faz_settings so afterAll can restore the shared env verbatim.
  savedSettings = wpEval(`echo wp_json_encode( get_option( 'faz_settings', array() ) );`).trim();

  // The lab's WPML emulation is opt-in (specs exercising a REAL multilingual
  // plugin activate the same fixture purely for its probe headers).
  setOption('faz_e2e_wpml_emulate', 'yes');
  if (!litespeedWasActive) {
    wp(['plugin', 'activate', 'litespeed-cache']);
  }
  ensureFixturePlugin('faz-e2e-wpml-litespeed-lab');

  // A bilingual EN+IT install, mirroring the reporter's setup.
  wpEval(`
    $s = get_option( 'faz_settings', array() );
    if ( ! is_array( $s ) ) { $s = array(); }
    $s['languages'] = array( 'default' => 'en', 'selected' => array( 'en', 'it' ) );
    update_option( 'faz_settings', $s );
  `);
  ready = true;
});

test.afterAll(() => {
  try {
    // Restore the shared env verbatim and undo only activations performed by
    // this spec. Pre-existing active plugins must remain active.
    if (savedSettings) {
      const b64 = Buffer.from(savedSettings, 'utf8').toString('base64');
      wpEval(`
        $s = json_decode( base64_decode( '${b64}' ), true );
        if ( is_array( $s ) ) { update_option( 'faz_settings', $s ); }
      `);
    }
    try {
      wpEval(`
        delete_option( 'faz_e2e_wpml_negotiation' );
        delete_option( 'faz_e2e_ls_purge_count' );
        delete_option( 'faz_e2e_wpml_emulate' );
      `);
    } catch {
      /* best-effort */
    }
    const deactivate: string[] = [];
    if (!fixtureWasActive && isPluginActive('faz-e2e-wpml-litespeed-lab')) {
      deactivate.push('faz-e2e-wpml-litespeed-lab');
    }
    if (!litespeedWasActive && isPluginActive('litespeed-cache')) {
      deactivate.push('litespeed-cache');
    }
    if (deactivate.length > 0) {
      try {
        wp(['plugin', 'deactivate', ...deactivate]);
      } catch {
        /* best-effort */
      }
    }
  } finally {
    if (lockHeld) {
      releaseSharedWordPressLock();
      lockHeld = false;
    }
  }
});

test.beforeEach(() => {
  test.skip(!ready, 'LiteSpeed Cache plugin is not installed on this environment');
});

test.describe('WPML + LiteSpeed + Cache Compatibility Mode (wp.org report)', () => {
  test('01 FAZ detects the WPML + LiteSpeed environment', () => {
    const multilingual = wpEval(`echo faz_i18n_is_multilingual() ? '1' : '0';`).trim();
    const litespeed = wpEval(`echo class_exists('\\\\LiteSpeed\\\\Purge') ? '1' : '0';`).trim();
    expect(multilingual).toBe('1');
    expect(litespeed).toBe('1');
  });

  test('02 [bug] cache-compat ON + WPML parameter mode → banner language collapses to the default (EN)', async ({
    request,
  }) => {
    // Reproduces the exact symptom the reporter saw: with Cache Compatibility
    // Mode on, an Italian visitor still gets the English banner — because a
    // ?lang= URL parameter is not a reliable cache key, so the language is
    // deliberately gated to the site default to keep the render cacheable.
    setCacheCompat(true);
    setNegotiation(3);
    const { lang, urlSafe } = await langProbe(request, 'it');
    expect(lang).toBe('en');
    expect(urlSafe).toBe('0');
  });

  test('03 [fix] cache-compat ON + WPML directory mode → the visitor\'s language resolves (IT)', async ({
    request,
  }) => {
    // The reporter's real case: WPML directories (/it/, /en/) encode the
    // language in the URL, so a URL-keyed cache is safe and the banner is
    // Italian for the Italian URL even with Cache Compatibility Mode on.
    setCacheCompat(true);
    setNegotiation(1);
    const { lang, urlSafe } = await langProbe(request, 'it');
    expect(lang).toBe('it');
    expect(urlSafe).toBe('1');
  });

  test('04 [fix] cache-compat ON + WPML domain mode → the visitor\'s language resolves (IT)', async ({
    request,
  }) => {
    setCacheCompat(true);
    setNegotiation(2);
    const { lang, urlSafe } = await langProbe(request, 'it');
    expect(lang).toBe('it');
    expect(urlSafe).toBe('1');
  });

  test('05 [fix] cache-compat ON + WPML directory mode → the English URL still serves English', async ({
    request,
  }) => {
    // No over-correction: the language follows the URL, not a global override.
    setCacheCompat(true);
    setNegotiation(1);
    const { lang } = await langProbe(request, 'en');
    expect(lang).toBe('en');
  });

  test('06 [workaround] cache-compat OFF → language resolves even in parameter mode', async ({ request }) => {
    // The reporter's own fix (disable Cache Compatibility Mode) keeps working
    // for every WPML mode, including the URL-parameter one.
    setCacheCompat(false);
    setNegotiation(3);
    const { lang } = await langProbe(request, 'it');
    expect(lang).toBe('it');
  });

  test('07 faz_wpml_language_in_url() tracks the WPML negotiation mode', async ({ request }) => {
    setCacheCompat(true);
    setNegotiation(1);
    expect((await langProbe(request, 'it')).urlSafe).toBe('1'); // directory
    setNegotiation(2);
    expect((await langProbe(request, 'it')).urlSafe).toBe('1'); // domain
    setNegotiation(3);
    expect((await langProbe(request, 'it')).urlSafe).toBe('0'); // parameter
  });

  test('08 [LiteSpeed] a banner save invalidates the LiteSpeed cache', () => {
    resetLsPurgeCount();
    fireHook('faz_after_update_banner');
    // FAZ's LiteSpeed adapter called \LiteSpeed\Purge::purge_all(), which fired
    // litespeed_purged_all exactly once.
    expect(lsPurgeCount()).toBe(1);
  });

  test('09 [LiteSpeed] cookie and settings saves also invalidate the LiteSpeed cache', () => {
    resetLsPurgeCount();
    fireHook('faz_after_create_cookie');
    fireHook('faz_after_update_settings');
    expect(lsPurgeCount()).toBe(2);
  });

  test('10 [coexistence] cache-compat OFF + WPML directory + LiteSpeed → right language AND purge on save', async ({
    request,
  }) => {
    // The setup the reporter wants: a page cache (LiteSpeed) with a working
    // multilingual banner. Each language URL renders its own language, and a
    // save invalidates the LiteSpeed cache so the change is never stale.
    setCacheCompat(false);
    setNegotiation(1);
    expect((await langProbe(request, 'it')).lang).toBe('it');
    expect((await langProbe(request, 'en')).lang).toBe('en');

    resetLsPurgeCount();
    fireHook('faz_after_update_banner');
    expect(lsPurgeCount()).toBe(1);
  });
});
