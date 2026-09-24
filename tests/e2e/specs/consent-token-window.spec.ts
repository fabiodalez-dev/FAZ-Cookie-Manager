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
 * Two halves here, both against the real stack: the endpoint accepts a token
 * minted five days ago and writes the row (the fix), and System Status reports
 * refusals when they happen instead of leaving the gap silent. The unit suite
 * (test-consent-token-window-php.php) pins the window arithmetic, the filter
 * and the clamps.
 */
import { expect, test } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const REJECTION_OPTION = 'faz_consent_token_rejections';

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

  test('System Status reports refused records instead of staying silent', async ({ page, baseURL, loginAsAdmin }) => {
    await loginAsAdmin(page);
    wpEval(`delete_option( '${REJECTION_OPTION}' );`);
    await page.goto(`${baseURL}/wp-admin/admin.php?page=faz-cookie-manager-system-status`, { waitUntil: 'domcontentloaded' });
    await expect(
      page.getByText('Consent Records Refused'),
      'nothing is claimed when nothing was refused',
    ).toHaveCount(0);

    wpEval(
      `update_option( '${REJECTION_OPTION}', array( 'since' => time() - 3600, 'count' => 42, 'last' => time() ) );`,
    );
    await page.goto(`${baseURL}/wp-admin/admin.php?page=faz-cookie-manager-system-status`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Consent Records Refused')).toHaveCount(1);
    await expect(page.getByText('42 in the last 7 days', { exact: false })).toHaveCount(1);
    await expect(
      page.getByText('faz_consent_token_max_age', { exact: false }),
      'and it names the lever that fixes it',
    ).toHaveCount(1);

    // A tally whose window has passed is not reported as current, even though
    // no refusal has arrived since to roll it over.
    wpEval(
      `update_option( '${REJECTION_OPTION}', array( 'since' => time() - 8 * 86400, 'count' => 900, 'last' => time() - 8 * 86400 ) );`,
    );
    await page.goto(`${baseURL}/wp-admin/admin.php?page=faz-cookie-manager-system-status`, { waitUntil: 'domcontentloaded' });
    await expect(
      page.getByText('Consent Records Refused'),
      'an expired tally is not reported as the last 7 days',
    ).toHaveCount(0);
  });
});
