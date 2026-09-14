/**
 * The consent log table renders its rows whatever the administrative language.
 *
 * Issue #284: after updating to 1.30.0 the page showed "Failed to load consent
 * logs." on every install that had at least one log. Nothing had failed to
 * load — the statistics above the table, from the same REST namespace and the
 * same nonce, were correct. Rendering a row called toLocaleDateString() with
 * the WordPress user locale, which carries an underscore ('de_DE') and is not
 * a BCP 47 tag, so Intl threw. The throw happened inside the .then() of the
 * fetch and landed in the .catch() written for a failed request, so a working
 * response was reported as a failure and nothing reached the server log.
 *
 * The suite never saw it because a site with no consent logs takes the empty
 * branch, which formats no dates. These cases therefore insist on a log being
 * there, and run under the locale that broke it.
 */
import { expect, test } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const LOGS_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager-consent-logs';

/** One consent log, so the table cannot take its empty branch. */
function seedConsentLog(): void {
  wpEval(`
    global $wpdb;
    $table = $wpdb->prefix . 'faz_consent_logs';
    $wpdb->insert( $table, array(
      'consent_id'  => 'e2e-locale-' . wp_generate_password( 8, false ),
      'status'      => 'accepted',
      'categories'  => wp_json_encode( array( 'necessary' => 'yes', 'analytics' => 'no' ) ),
      'ip_hash'     => 'e2e-hash',
      'user_agent'  => 'e2e',
      'url'         => home_url( '/' ),
      'created_at'  => current_time( 'mysql' ),
    ) );
  `);
}

function setAdminLocale(locale: string): void {
  wpEval(`update_user_meta( 1, 'locale', '${locale}' );`);
}

test.describe('Consent log table rendering', () => {
  test.afterAll(() => {
    setAdminLocale('');
    wpEval(`
      global $wpdb;
      $wpdb->query( "DELETE FROM {$wpdb->prefix}faz_consent_logs WHERE consent_id LIKE 'e2e-locale-%'" );
    `);
  });

  // 'de_DE' is the reporter's locale; 'pt_PT_ao90' is the harder one, because
  // swapping the underscore for a hyphen is still not a tag Intl accepts.
  for (const locale of ['de_DE', 'pt_PT_ao90', 'en_US']) {
    test(`rows render under the ${locale} administrative locale`, async ({ page, wpBaseURL, loginAsAdmin }) => {
      seedConsentLog();
      setAdminLocale(locale);
      await loginAsAdmin(page);

      const pageErrors: string[] = [];
      page.on('pageerror', (error) => pageErrors.push(error.message));

      // The REST response has to arrive before anything is asserted: the table
      // ships with a server-rendered "Loading..." row, and a first `tr` exists
      // from the very first paint. Asserting on that placeholder is how an
      // earlier draft of this spec passed against the broken build.
      const responded = page.waitForResponse(
        (response) => response.url().includes('/faz/v1/consent_logs') && !response.url().includes('statistics'),
        { timeout: 20_000 },
      );
      await page.goto(`${wpBaseURL}${LOGS_PAGE}`, { waitUntil: 'domcontentloaded' });
      const restResponse = await responded;
      expect(restResponse.status(), 'the log request itself must succeed').toBe(200);

      const body = page.locator('#faz-logs-body');
      // A real row carries the consent id in a <code>; the placeholder and the
      // failure message do not. Waiting for it is language-independent.
      await expect(body.locator('tr td code.faz-code').first()).toBeVisible({ timeout: 15_000 });

      // The failure message is the whole point: it claims the request failed
      // when the request succeeded, so assert on it by name.
      await expect(body).not.toContainText('Failed to load consent logs.');
      await expect(body).not.toContainText('No consent logs found.');

      // A rendered row carries a formatted date, not the '--' placeholder: it
      // is the date formatting that used to throw.
      const firstCell = (await body.locator('tr').first().locator('td').first().innerText()).trim();
      expect(firstCell).not.toBe('--');
      expect(firstCell.length).toBeGreaterThan(4);

      expect(pageErrors, 'rendering must not raise').toEqual([]);
    });
  }
});
