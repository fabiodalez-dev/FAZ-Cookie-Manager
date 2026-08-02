import { expect, test } from '../fixtures/wp-fixture';
import type { Page, Response, ConsoleMessage } from '@playwright/test';

/**
 * Guard against issue #198: an admin page calling REST paths that no route
 * matches.
 *
 * The Geo-routing module registers its routes under `faz/v1/geo/…`, but its
 * page script called `FAZ.get('status')`, and FAZ.get() only prepends
 * `faz/v1/`. Every tab rendered "No route was found matching the URL and
 * request method." Nothing failed at build time, no PHP error was logged, and
 * the admin page looked fine until you opened a tab — so it reached a released
 * version and a user had to report it.
 *
 * A static check on the JS would be fragile (paths are composed at runtime).
 * This instead asserts the observable symptom: load each admin screen as an
 * administrator and require that no faz/v1 request comes back 4xx/5xx and that
 * the routing error never appears in the console.
 *
 * Scope note: only the two modules that namespace their routes (`geo`,
 * `cookie-policy`) can exhibit this particular bug today, but the check is
 * written against every admin page so a future module gets the guard for free.
 */

/** Every registered FAZ admin screen, including the ones outside the nav. */
const ADMIN_PAGES = [
  'faz-cookie-manager',
  'faz-cookie-manager-banner',
  'faz-cookie-manager-cookies',
  'faz-cookie-manager-consent-logs',
  'faz-cookie-manager-gcm',
  'faz-cookie-manager-languages',
  'faz-cookie-manager-gvl',
  'faz-cookie-manager-geo-routing',
  'faz-cookie-manager-cookie-policy',
  'faz-cookie-manager-settings',
  'faz-cookie-manager-import-export',
  'faz-cookie-manager-system-status',
];

/** Tabs are rendered client-side, so a page's REST calls fire per tab. */
// Only pages that genuinely have tabs, with the selector each one actually
// uses. Getting this wrong is not a harmless over-reach: a selector that
// matches nothing makes the tab loop a no-op, and the guard then reports
// success for coverage it never achieved. That is precisely what happened
// here — geo-routing builds its tabs in JS with `.faz-geo-tab`, so the
// original `.faz-tab` guess never clicked anything on the one page issue #198
// was actually reported against. Verified against the source: `.faz-tab` +
// `.faz-tabs` appear only in admin/views/banner.php, `.faz-geo-tab` only in
// admin/assets/js/pages/geo-routing.js, and cookie-policy and settings have
// no tabs at all.
const TABBED_PAGES: Record<string, string> = {
  'faz-cookie-manager-banner': 'button.faz-tab',
  'faz-cookie-manager-geo-routing': '.faz-geo-tab',
};

const isFazRest = (url: string): boolean =>
  url.includes('/faz/v1/') || url.includes('rest_route=%2Ffaz%2Fv1') || url.includes('rest_route=/faz/v1');

/** Strip the origin so failures read as `faz/v1/status`, not a 200-char URL. */
const shortUrl = (url: string): string => {
  try {
    const u = new URL(url);
    return u.pathname + (u.search || '');
  } catch {
    return url;
  }
};

interface Collected {
  failures: string[];
  routingErrors: string[];
}

function collect(page: Page): Collected {
  const out: Collected = { failures: [], routingErrors: [] };

  page.on('response', (response: Response) => {
    const url = response.url();
    if (!isFazRest(url) || response.status() < 400) {
      return;
    }
    out.failures.push(`${response.status()} ${response.request().method()} ${shortUrl(url)}`);
  });

  page.on('console', (msg: ConsoleMessage) => {
    const text = msg.text();
    // The exact string WordPress returns for rest_no_route.
    if (text.includes('No route was found matching the URL')) {
      out.routingErrors.push(text);
    }
  });

  return out;
}

/** Click through every tab so tab-scoped REST calls actually fire. */
async function visitAllTabs(page: Page, selector: string): Promise<number> {
  const tabs = page.locator(selector);
  const count = await tabs.count();
  let visited = 0;
  for (let i = 0; i < count; i++) {
    const tab = tabs.nth(i);
    if (!(await tab.isVisible().catch(() => false))) {
      continue;
    }
    // Deliberately not caught. A visible tab that cannot be clicked means this
    // spec never exercises that tab's REST calls, and a guard that quietly
    // skips the thing it is guarding is worse than no guard — it reports
    // success for coverage it did not achieve.
    await tab.click();
    visited++;
    // Let the tab's XHRs start and settle; networkidle would hang on pages
    // that keep a long-poll open, so a short settle window is enough here.
    await page.waitForTimeout(400);
  }
  return visited;
}

test.describe('Admin pages call REST routes that exist (#198)', () => {
  for (const slug of ADMIN_PAGES) {
    test(`${slug}: no failed faz/v1 request`, async ({ page, wpBaseURL, loginAsAdmin }) => {
      await loginAsAdmin(page);

      const collected = collect(page);

      await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=${slug}`, {
        waitUntil: 'domcontentloaded',
      });
      await expect(page.locator('#wpadminbar')).toBeVisible();

      // Give the page's own boot requests time to complete.
      await page.waitForTimeout(1200);

      const tabSelector = TABBED_PAGES[slug];
      if (tabSelector) {
        const visited = await visitAllTabs(page, tabSelector);
        expect(
          visited,
          `${slug} exposes no visible tab for selector "${tabSelector}"; REST coverage was not exercised`,
        ).toBeGreaterThan(0);
      }

      expect(
        collected.failures,
        `${slug} issued FAZ REST requests that failed:\n  ${collected.failures.join('\n  ')}`,
      ).toEqual([]);

      expect(
        collected.routingErrors,
        `${slug} surfaced a rest_no_route error to the console:\n  ${collected.routingErrors.join('\n  ')}`,
      ).toEqual([]);
    });
  }

  test('geo-routing reaches its namespaced routes, not the bare ones', async ({
    page,
    wpBaseURL,
    loginAsAdmin,
  }) => {
    await loginAsAdmin(page);

    const seen: string[] = [];
    page.on('request', (request) => {
      const url = request.url();
      if (isFazRest(url)) {
        seen.push(shortUrl(url));
      }
    });

    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-geo-routing`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(1500);
    const visited = await visitAllTabs(page, TABBED_PAGES['faz-cookie-manager-geo-routing']);
    expect(visited, 'geo-routing exposes no visible tab; the route guard exercised nothing').toBeGreaterThan(0);

    // The six endpoints named in the issue must never be requested unscoped.
    const REGRESSION_PATHS = [
      'status',
      'rulesets',
      'overrides',
      'ipinfo-settings',
      'preview',
      'pipl-attestation',
    ];

    // Compare against the decoded URL as well as the raw one. Without pretty
    // permalinks WordPress addresses the API as `?rest_route=%2Ffaz%2Fv1%2F…`,
    // and a raw-substring check would miss the regression entirely on exactly
    // the sites most likely to hit it. The preflight enforces pretty permalinks
    // here, so this is latent today — but a guard that only works under one
    // permalink setting is not a guard.
    const unscoped = seen.filter((url) => {
      let decoded = url;
      try {
        decoded = decodeURIComponent(url);
      } catch {
        // Malformed escape sequence: fall back to the raw URL rather than
        // dropping the request from the check.
      }
      return REGRESSION_PATHS.some(
        (p) =>
          decoded.includes(`/faz/v1/${p}`) || decoded.includes(`rest_route=/faz/v1/${p}`),
      );
    });

    expect(
      unscoped,
      `geo-routing requested unscoped paths (the #198 regression):\n  ${unscoped.join('\n  ')}`,
    ).toEqual([]);

    // And it must actually have talked to the module, so the assertion above
    // is not passing merely because nothing was requested at all.
    const scoped = seen.filter((url) => url.includes('geo/') || url.includes('geo%2F'));
    expect(scoped.length, `geo-routing made no namespaced REST call; observed:\n  ${seen.join('\n  ')}`).toBeGreaterThan(0);
  });
});
