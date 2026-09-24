/**
 * E2E — a consent refused for a stale origin token is recorded and shown.
 *
 * The token that proves a consent POST came from a page this site rendered
 * used to last 12 to 24 hours, so a page held by a full-page cache stopped
 * being able to record consent about a day after it was stored. Nothing on the
 * site looked wrong — the banner worked, the POST is fire-and-forget — and the
 * only casualty was the accountability record. Reported with production
 * figures in issue #292.
 *
 * Three parts here, all against the real stack: the endpoint accepts a token
 * minted five days ago and writes the row (the fix), the refusal accounting is
 * bounded so an anonymous caller cannot buy a database write per request, and
 * System Status reports refusals — by cause, and at zero too, because a row
 * that appears only on bad news makes its own absence unreadable. The unit
 * suite (test-consent-token-window-php.php) pins the window arithmetic, the
 * bucket shape, the filter and the clamps.
 */
import type { Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const REJECTION_OPTION = 'faz_consent_token_rejections';

/** The last line of a `wp eval` run — WP-CLI may print notices before it. */
function lastLine(out: string): string {
  return out.trim().split('\n').pop() || '';
}

/** A token as a page cached `daysAgo` days ago would carry. */
function tokenAgedDays(daysAgo: number): string {
  const buckets = Math.round((daysAgo * 24) / 12);
  return lastLine(wpEval(
    `echo wp_hash( 'faz_consent_' . (string) ( (int) floor( time() / 43200 ) - ${buckets} ) );`,
  )).trim();
}

/**
 * Seed the tally directly, in the shape the writer persists.
 *
 * `days` is a map of UTC day index to per-cause counts. Written as an option
 * rather than driven by request volume, because the write is rate-limited per
 * client — a loop of POSTs from one address is counted once, which is the
 * point of the throttle and would make a volume-driven fixture lie.
 */
function seedTally(days: Record<string, Record<string, number>>, lastOffset = 0): void {
  const entries = Object.entries(days)
    .map(([day, causes]) => {
      const inner = Object.entries(causes)
        .map(([cause, n]) => `'${cause}' => ${n}`)
        .join(', ');
      return `( (int) floor( time() / 86400 ) ${day} ) => array( ${inner} )`;
    })
    .join(', ');
  wpEval(
    `update_option( '${REJECTION_OPTION}', array( 'days' => array( ${entries} ), 'last' => time() ${lastOffset >= 0 ? '-' : '+'} ${Math.abs(lastOffset)} ), false );`,
  );
}

/**
 * The reported figure, matched at its own left edge.
 *
 * Substring matching cannot assert a count: "900 in the last 7 days" contains
 * "0 in the last 7 days", so the case that proves an expired tally reads as
 * zero passed against a bug reporting 900 — and "42" would have been satisfied
 * by a residual "1042". Nothing that is a digit, a thousands separator or a
 * decimal point may precede the number. Returns the page text so a caller can
 * make further assertions on the same read.
 */
async function expectTally(page: Page, pattern: string, why: string): Promise<string> {
  const body = (await page.locator('#faz-system-status').innerText()).replace(/\u00a0/g, ' ');
  expect(body, why).toMatch(new RegExp(`(?:^|[^\\d.,])${pattern} in the last 7 days`));
  return body;
}

/**
 * Drop the per-IP and per-cause throttle transients.
 *
 * Every case here posts from one address, which is exactly what the throttles
 * exist to limit; without this a later case would be answered from a window
 * opened by an earlier one and would assert nothing.
 */
function clearThrottles(): void {
  wpEval(
    'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \'_transient%faz_consent_%\' OR option_name LIKE \'_transient%faz_throttle%\'" );',
  );
}

test.describe('consent-log origin token outlives the page cache (#292)', () => {
  test.afterAll(() => {
    wpEval(`delete_option( '${REJECTION_OPTION}' );`);
    wpEval(
      'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}faz_consent_logs WHERE consent_id LIKE \'e2e-token-%\'" );',
    );
  });

  test('a five-day-old token still records consent, an expired one does not', async ({ request, baseURL }) => {
    const post = async (token: string, consentId: string) =>
      request.post(`${baseURL}/wp-json/faz/v1/consent`, {
        headers: { Origin: baseURL as string, 'Sec-Fetch-Site': 'same-origin' },
        data: {
          token,
          consent_id: consentId,
          status: 'accepted',
          categories: { necessary: 'yes' },
          url: `${baseURL}/`,
        },
      });

    const cachedId = `e2e-token-cached-${Date.now()}`;
    clearThrottles();
    const cached = await post(tokenAgedDays(5), cachedId);
    expect(cached.status(), 'a page cached five days ago can still record consent').toBe(200);
    const rows = lastLine(wpEval(
      `global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}faz_consent_logs WHERE consent_id = %s", '${cachedId}' ) );`,
    )).replace(/\D/g, '');
    expect(rows, 'and the row is actually written').toBe('1');

    // The window still ends: this is a cache allowance, not an open door.
    clearThrottles();
    const expired = await post(tokenAgedDays(9), `e2e-token-expired-${Date.now()}`);
    expect(expired.status(), 'a token older than the window is still refused').toBe(403);
    expect((await expired.json()).code).toBe('invalid_token');
  });

  test('the refusal accounting is bounded, so a bogus token cannot buy a DB write per request', async ({ request, baseURL }) => {
    // The endpoint's own per-IP throttle runs AFTER the token check, so without
    // a gate of its own this accounting handed any anonymous caller one
    // guaranteed option write per request — on the one route that has to stay
    // reachable without authentication. The 403 itself must not change.
    wpEval(`delete_option( '${REJECTION_OPTION}' );`);
    clearThrottles();

    const bogus = 'not-a-token-at-all';
    const statuses: number[] = [];
    for (let i = 0; i < 6; i += 1) {
      const res = await request.post(`${baseURL}/wp-json/faz/v1/consent`, {
        headers: { Origin: baseURL as string, 'Sec-Fetch-Site': 'same-origin' },
        data: { token: bogus, consent_id: `e2e-token-burst-${i}`, status: 'accepted', url: `${baseURL}/` },
      });
      statuses.push(res.status());
    }
    expect(statuses.every((s) => s === 403), 'every bogus token is still refused').toBe(true);

    const counted = Number(
      lastLine(wpEval(
        `$t = \\FazCookie\\Frontend\\Modules\\Consent_Logger\\Consent_Logger::rejection_tally(); echo (int) $t['count'];`,
      )).replace(/\D/g, '') || '0',
    );
    expect(counted, 'but a burst from one client is counted once, not six times').toBeLessThan(6);
    expect(counted, 'and it is counted at least once — the signal is not silenced').toBeGreaterThan(0);
  });

  test('a cross-origin refusal is counted under its own cause', async ({ request, baseURL }) => {
    // Reachable by a real browser whenever the page origin differs from the
    // WordPress Address — a scheme or port mismatch, or a sandboxed iframe.
    // WordPress echoes back any Origin in its CORS headers, so the preflight
    // succeeds and the POST reaches PHP: this plugin refuses it, not the browser.
    wpEval(`delete_option( '${REJECTION_OPTION}' );`);
    clearThrottles();

    const res = await request.post(`${baseURL}/wp-json/faz/v1/consent`, {
      headers: { Origin: 'https://not-this-site.example', 'Sec-Fetch-Site': 'cross-site' },
      data: { token: 'irrelevant', consent_id: `e2e-token-xorigin-${Date.now()}`, status: 'accepted' },
    });
    expect(res.status()).toBe(403);
    expect((await res.json()).code).toBe('cross_origin_request');

    const cause = lastLine(wpEval(
      `$t = \\FazCookie\\Frontend\\Modules\\Consent_Logger\\Consent_Logger::rejection_tally(); echo (int) ( $t['causes']['cross_origin'] ?? 0 );`,
    )).replace(/\D/g, '');
    expect(cause, 'the refusal is counted, and under cross_origin rather than as a stale token').toBe('1');
  });

  test('a request carrying no token at all is refused here, and counted', async ({ request, baseURL }) => {
    // The route used to declare `token` as a required argument, so WordPress
    // answered 400 rest_missing_callback_param from has_valid_params() — before
    // the callback, and so before anything could count the loss. The gate was
    // keyed on `null === $param`, which meant `token=""` reached the handler and
    // an absent `token` did not: the one shape System Status names in this
    // cause's copy, "no origin token at all", was the one shape it never saw.
    wpEval(`delete_option( '${REJECTION_OPTION}' );`);
    clearThrottles();

    const res = await request.post(`${baseURL}/wp-json/faz/v1/consent`, {
      headers: { Origin: baseURL as string, 'Sec-Fetch-Site': 'same-origin' },
      data: { consent_id: `e2e-token-absent-${Date.now()}`, status: 'accepted', url: `${baseURL}/` },
    });
    expect(res.status(), 'refused by the handler, not by the REST argument validator').toBe(403);
    expect(
      (await res.json()).code,
      'and with this plugin\'s own code, so the cause is knowable',
    ).toBe('missing_token');

    const cause = lastLine(wpEval(
      `$t = \\FazCookie\\Frontend\\Modules\\Consent_Logger\\Consent_Logger::rejection_tally(); echo (int) ( $t['causes']['missing_token'] ?? 0 );`,
    )).replace(/\D/g, '');
    expect(cause, 'counted under missing_token rather than lost outside this class').toBe('1');
  });

  test('System Status reports refused records by cause, and says so at zero too', async ({ page, baseURL, loginAsAdmin }) => {
    await loginAsAdmin(page);
    const statusUrl = `${baseURL}/wp-admin/admin.php?page=faz-cookie-manager-system-status`;

    // Zero state. The row used to be hidden below one refusal, so a site losing
    // every record through a cause nothing counted rendered a page identical to
    // a healthy one: absence of signal read as good news.
    wpEval(`delete_option( '${REJECTION_OPTION}' );`);
    await page.goto(statusUrl, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Consent Records Refused')).toHaveCount(1);
    await expectTally(page, '0', 'the row states zero rather than vanishing');

    // A four-digit tally. printf's %d applied to the grouped string a locale
    // formatter returns stopped at the thousands separator, so 2002 rendered as
    // "2" — and the suite stayed green because every fixture was under 1000.
    seedTally({ '- 1': { stale_token: 2002 } });
    await page.goto(statusUrl, { waitUntil: 'domcontentloaded' });
    const body = await expectTally(page, '2[.,\\s]002', 'the full count is printed, not its first group');
    expect(body, 'and it is not truncated to the leading group').not.toMatch(/\b2 in the last 7 days/);
    expect(body, 'the cause is named').toContain('Stale origin token');
    expect(body, 'and the lever that fixes it').toContain('faz_consent_token_max_age');

    // Two causes, reported separately: folding automated traffic into the same
    // figure as records a cache lost would make an alarming number out of noise.
    seedTally({ '- 0': { stale_token: 5, cross_origin: 3, write_failed: 1 } });
    await page.goto(statusUrl, { waitUntil: 'domcontentloaded' });
    const split = await page.locator('#faz-system-status').innerText();
    expect(split).toContain('Stale origin token');
    expect(split).toContain('No same-origin signal');
    expect(split).toContain('Database write failed');

    // Ten days of continuous refusals report the trailing window, never a count
    // that restarted when the oldest day aged out. The anchored shape this
    // replaced collapsed from thousands to one at the boundary.
    const tenDays: Record<string, Record<string, number>> = {};
    for (let i = 0; i < 10; i += 1) {
      tenDays[`- ${i}`] = { stale_token: 300 };
    }
    seedTally(tenDays);
    await page.goto(statusUrl, { waitUntil: 'domcontentloaded' });
    const rolling = await expectTally(page, '2[.,\\s]100', 'seven days of the ten are reported');
    expect(rolling, 'and not the whole ten').not.toMatch(/3[.,\s]000 in the last 7 days/);

    // A tally whose buckets have all aged out reads as zero, without needing a
    // new refusal to roll it over.
    seedTally({ '- 9': { stale_token: 900 } }, 9 * 86400);
    await page.goto(statusUrl, { waitUntil: 'domcontentloaded' });
    const stale = await expectTally(page, '0', 'an expired tally reads as zero');
    expect(stale, 'and the aged-out bucket is not reported as the last 7 days').not.toMatch(
      /900 in the last 7 days/,
    );

    // A row left by the first cut of this feature must still be reported rather
    // than silently dropped on upgrade.
    wpEval(
      `update_option( '${REJECTION_OPTION}', array( 'since' => time() - 3600, 'count' => 42, 'last' => time() ), false );`,
    );
    await page.goto(statusUrl, { waitUntil: 'domcontentloaded' });
    await expectTally(page, '42', 'a legacy flat tally survives the upgrade');
  });
});
