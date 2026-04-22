import type { APIRequestContext, BrowserContext, Page } from '@playwright/test';
import { fazApiGet, fazApiPost, getAdminNonce } from './faz-api';

export type LanguagesSnapshot = {
  default: string;
  selected: string[];
};

/**
 * Minimum shape Playwright needs from _fazConfig for these tests.
 * Using `any` for individual fields keeps the helper tolerant to
 * wp_localize_script's bool→string coercion ("1"/"").
 */
export type FazConfigShape = {
  _language: string;
  _defaultLanguage: string;
  _availableLanguages: string[];
  _browserDetect: unknown;
  _bannerEndpoint: string;
  _languageMap?: Record<string, string>;
  _shortCodes?: Array<{ key: string; content: string; tag: string }>;
  _categories?: Array<{ slug: string; name?: string; description?: string }>;
  _i18n?: Record<string, string>;
};

/**
 * Read the current languages.* block from faz_settings via the admin
 * settings REST endpoint. Requires an authenticated admin page context so
 * the X-WP-Nonce is available.
 */
export async function getSelectedLanguages(page: Page): Promise<LanguagesSnapshot> {
  const nonce = await getAdminNonce(page);
  const { data } = await fazApiGet<any>(page, nonce, 'settings/');
  const langs = (data && data.languages) || {};
  const defaultLang: string = typeof langs.default === 'string' && langs.default ? langs.default : 'en';
  const selected: string[] = Array.isArray(langs.selected) ? langs.selected.filter((l: unknown) => typeof l === 'string') : [];
  return { default: defaultLang, selected };
}

/**
 * Write a new languages.selected / languages.default pair and return the
 * snapshot that was in place before the write, so callers can restore it
 * inside `test.afterAll` for idempotency.
 */
export async function setSelectedLanguages(
  page: Page,
  selected: string[],
  defaultLang?: string,
): Promise<LanguagesSnapshot> {
  const before = await getSelectedLanguages(page);
  const nonce = await getAdminNonce(page);
  await fazApiPost(page, nonce, 'settings/', {
    languages: {
      default: defaultLang ?? before.default,
      selected,
    },
  });
  return before;
}

/**
 * Restore a previously captured snapshot.
 */
export async function restoreLanguages(page: Page, snapshot: LanguagesSnapshot): Promise<void> {
  const nonce = await getAdminNonce(page);
  await fazApiPost(page, nonce, 'settings/', {
    languages: {
      default: snapshot.default,
      selected: snapshot.selected,
    },
  });
}

/**
 * Override navigator.language / navigator.languages for every document
 * opened in the given context. Must be called before `page.goto` so the
 * override is in place when script.js reads the property.
 *
 * An empty `languages` array is a no-op: real browsers always expose
 * `navigator.language` as a non-empty string, so we refuse to install an
 * override that would make it `undefined` and break any consumer that
 * treats the value as a string.
 */
export async function emulateNavigatorLanguages(
  context: BrowserContext,
  languages: string[],
): Promise<void> {
  if (!Array.isArray(languages) || languages.length === 0) {
    return;
  }
  await context.addInitScript((langs: string[]) => {
    try {
      Object.defineProperty(navigator, 'languages', { get: () => langs, configurable: true });
      // Defensive: `langs[0] ?? ''` guarantees a string even if the array
      // is mutated or ends up empty somehow at read time. Mirrors the real
      // navigator.language contract, which is always a string.
      Object.defineProperty(navigator, 'language', { get: () => langs[0] ?? '', configurable: true });
    } catch (e) {
      // Some platforms lock these properties — tests must treat this as a
      // silent no-op and fall back to whatever the runtime exposes.
    }
  }, languages);
}

/**
 * Read the runtime _fazConfig snapshot from the page. Returns null when the
 * banner script is not loaded (e.g. the site disabled the banner).
 */
export async function readFazConfig(page: Page): Promise<FazConfigShape | null> {
  return page.evaluate<FazConfigShape | null>(() => {
    const cfg = (window as any)._fazConfig;
    if (!cfg) return null;
    return {
      _language: cfg._language,
      _defaultLanguage: cfg._defaultLanguage,
      _availableLanguages: cfg._availableLanguages,
      _browserDetect: cfg._browserDetect,
      _bannerEndpoint: cfg._bannerEndpoint,
      _languageMap: cfg._languageMap,
      _shortCodes: cfg._shortCodes,
      _categories: cfg._categories,
      _i18n: cfg._i18n,
    };
  });
}

/**
 * Fetch the banner REST payload for a specific language from an API context
 * (no browser). Works with both pretty permalinks and the plain rewrite
 * structure used by the PHP built-in server.
 */
export async function fetchBannerPayload(
  request: APIRequestContext,
  wpBaseURL: string,
  lang: string,
): Promise<{ status: number; body: any }> {
  const response = await request.get(`${wpBaseURL}/?rest_route=/faz/v1/banner/${encodeURIComponent(lang)}`, {
    headers: { Accept: 'application/json' },
  });
  const body = response.status() === 200 ? await response.json() : null;
  return { status: response.status(), body };
}

/**
 * Wait until script.js has settled on a language.
 *
 * When `expectedLanguage` is provided the poll waits for _fazStore._language
 * to match — this removes the race where _fazConfig already exists (because
 * the localized script loaded) but the async REST swap in _fazMaybeSwapLanguage
 * has not yet rewritten _language. When omitted the poll only waits for
 * _fazConfig to be populated; callers that don't need swap determinism can
 * still use the helper unchanged.
 */
export async function waitForBannerReady(
  page: Page,
  timeoutMs = 3000,
  expectedLanguage?: string,
): Promise<void> {
  await page.waitForFunction(
    (lang: string | null) => {
      const cfg = (window as any)._fazConfig;
      if (!cfg || typeof cfg._language !== 'string') return false;
      return !lang || cfg._language === lang;
    },
    expectedLanguage ?? null,
    { timeout: timeoutMs },
  );
}
