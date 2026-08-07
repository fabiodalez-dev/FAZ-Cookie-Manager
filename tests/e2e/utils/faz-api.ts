import type { Page } from '@playwright/test';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const API_TIMEOUT_MS = 60_000;

export type FazApiResponse<T> = {
  status: number;
  data: T;
};

export async function getAdminNonce(page: Page): Promise<string> {
  return page.evaluate(() => (window as any).fazConfig?.api?.nonce ?? '');
}

export async function fazApiGet<T>(page: Page, nonce: string, route: string): Promise<FazApiResponse<T>> {
  const response = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/${route}`, {
    headers: { 'X-WP-Nonce': nonce },
    timeout: API_TIMEOUT_MS,
  });
  return {
    status: response.status(),
    data: await response.json(),
  };
}

export async function fazApiPost<T>(page: Page, nonce: string, route: string, data: Record<string, unknown>): Promise<FazApiResponse<T>> {
  const response = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/${route}`, {
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
    data,
    timeout: API_TIMEOUT_MS,
  });
  return {
    status: response.status(),
    data: await response.json(),
  };
}

export async function fazApiPut<T>(page: Page, nonce: string, route: string, data: Record<string, unknown>): Promise<FazApiResponse<T>> {
  // Mirror `fazApiDelete`: POST + X-HTTP-Method-Override. The
  // `?rest_route=` endpoint returns 405 on a native PUT under several
  // common HTTP stacks (php -S, some nginx configs, Apache with certain
  // mod_rewrite setups). WordPress's REST server honours the override
  // header transparently, so this path is the portable one.
  const response = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/${route}`, {
    headers: {
      'Content-Type': 'application/json',
      'X-HTTP-Method-Override': 'PUT',
      'X-WP-Nonce': nonce,
    },
    data,
    timeout: API_TIMEOUT_MS,
  });
  return {
    status: response.status(),
    data: await response.json(),
  };
}

export async function fazApiDelete(page: Page, nonce: string, route: string): Promise<{ status: number }> {
  const response = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/${route}`, {
    headers: {
      'X-HTTP-Method-Override': 'DELETE',
      'X-WP-Nonce': nonce,
    },
    timeout: API_TIMEOUT_MS,
  });
  return { status: response.status() };
}

export async function fazClientGet<T>(page: Page, route: string, params?: Record<string, unknown>): Promise<T> {
  return page.evaluate(
    async ({ params, route }) => (window as any).FAZ.get(route, params),
    { params: params ?? null, route },
  );
}

export async function fazClientPost<T>(page: Page, route: string, data: Record<string, unknown>): Promise<T> {
  return page.evaluate(
    async ({ data, route }) => (window as any).FAZ.post(route, data),
    { data, route },
  );
}

export async function fazClientPut<T>(page: Page, route: string, data: Record<string, unknown>): Promise<T> {
  return page.evaluate(
    async ({ data, route }) => (window as any).FAZ.put(route, data),
    { data, route },
  );
}

export function normalizeCookieList(data: any): any[] {
  if (Array.isArray(data)) {
    return data;
  }
  if (Array.isArray(data?.items)) {
    return data.items;
  }
  if (Array.isArray(data?.data)) {
    return data.data;
  }
  return [];
}

export async function listCookies(page: Page, nonce: string): Promise<any[]> {
  const response = await fazApiGet<any>(page, nonce, 'cookies');
  return normalizeCookieList(response.data);
}

/**
 * List cookies, waiting until every expected name is present.
 *
 * A scan reports "complete" in the UI before its rows are necessarily readable
 * through the REST list, so `await listCookies(); expect(names).toContain(x)`
 * asserts against whatever happened to be persisted at that instant. It holds on
 * an idle box and fails under full-suite load — the specs that used it passed
 * alone and went red in the full run, which is that race and not a defect in the
 * scanner.
 *
 * Retrying the REQUEST is not enough and was the existing mistake: the request
 * succeeds, it just answers with an incomplete list. Poll the CONDITION instead.
 * A genuinely missing cookie still fails, just after the deadline and with the
 * names it did find, which is the message you actually want at 3am.
 */
export async function listCookiesUntil(
  page: Page,
  nonce: string,
  expectedNames: string[],
  timeoutMs = 30_000
): Promise<any[]> {
  const deadline = Date.now() + timeoutMs;
  let cookies: any[] = [];
  let missing: string[] = [];
  for (;;) {
    try {
      cookies = await listCookies(page, nonce);
      const names = new Set(cookies.map((entry: any) => String(entry?.name ?? '')));
      missing = expectedNames.filter((name) => !names.has(name));
      if (missing.length === 0) {
        return cookies;
      }
    } catch {
      // Transport hiccup mid-scan — keep polling until the deadline.
    }
    if (Date.now() >= deadline) {
      throw new Error(
        `Cookies still missing after ${Math.round(timeoutMs / 1000)}s: ${missing.join(', ') || expectedNames.join(', ')}. ` +
          `Found ${cookies.length}: ${cookies.map((c: any) => c?.name).slice(0, 25).join(', ')}`
      );
    }
    await page.waitForTimeout(1000);
  }
}

export async function listCategories(page: Page, nonce: string): Promise<any[]> {
  const response = await fazApiGet<any>(page, nonce, 'cookies/categories');
  return Array.isArray(response.data) ? response.data : normalizeCookieList(response.data);
}

export async function findCategoryId(page: Page, nonce: string, slug: string): Promise<number> {
  const categories = await listCategories(page, nonce);
  const category = categories.find((item: any) => item.slug === slug);
  if (!category) {
    throw new Error(`Category "${slug}" not found.`);
  }
  return Number(category.id ?? category.category_id);
}

export async function deleteCookiesByPredicate(
  page: Page,
  nonce: string,
  predicate: (cookie: any) => boolean,
): Promise<void> {
  const cookies = await listCookies(page, nonce);
  for (const cookie of cookies) {
    if (!predicate(cookie)) {
      continue;
    }
    const id = Number(cookie.id ?? cookie.cookie_id);
    if (!id) {
      continue;
    }
    await fazApiDelete(page, nonce, `cookies/${id}`);
  }
}

export async function deleteCookiesByNames(page: Page, nonce: string, names: string[]): Promise<void> {
  const nameSet = new Set(names.map((item) => item.toLowerCase()));
  await deleteCookiesByPredicate(page, nonce, (cookie) => nameSet.has(String(cookie.name ?? '').toLowerCase()));
}

export async function deleteCookiesByPrefix(page: Page, nonce: string, prefix: string): Promise<void> {
  const lower = prefix.toLowerCase();
  await deleteCookiesByPredicate(page, nonce, (cookie) => String(cookie.name ?? '').toLowerCase().startsWith(lower));
}

export async function openCookiesPage(page: Page, loginAsAdmin: (page: Page) => Promise<void>): Promise<string> {
  await loginAsAdmin(page);
  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
  const nonce = await getAdminNonce(page);
  if (!nonce) {
    throw new Error('Unable to read FAZ REST nonce from cookies page.');
  }
  return nonce;
}

export async function openSettingsPage(page: Page, loginAsAdmin: (page: Page) => Promise<void>): Promise<string> {
  for (let attempt = 0; attempt < 2; attempt += 1) {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);
    if (nonce) {
      return nonce;
    }
    await page.context().clearCookies();
  }
  throw new Error('Unable to read FAZ REST nonce from settings page.');
}
