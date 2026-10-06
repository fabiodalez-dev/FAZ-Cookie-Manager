import { copyFileSync, existsSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '../fixtures/wp-fixture';
import { WP_PATH, wpEval } from '../utils/wp-env';

/**
 * A jurisdiction rule set asserting `ui.revisit_widget_required` requires a
 * standing way to reopen and withdraw consent — not FAZ's particular floating
 * widget. The plugin could only see its own widget, so it locked that switch on,
 * and an administrator who had deliberately placed
 * `[faz_cookie_settings type="link"]` in their footer could not turn the
 * floating one off. The requirement was already met; the screen had no way to
 * tell, so it refused.
 *
 * What this spec pins down is the DIRECTION OF FAILURE, which is the whole
 * design. The footer link is evidence the plugin does not own: it lives in a
 * theme or block template and can vanish with an edit nobody reports. So the
 * lock may only lift on a recent, successful check of the site's own pages, and
 * must come back the moment that evidence stops holding. The second test
 * removes the link and re-checks — without it, "the lock lifted" would be the
 * only thing proven, and a one-way gate is exactly the bug worth fearing here.
 *
 * Needs WP_PATH: the fixture mu-plugin stands in for the administrator's
 * template edit, and the assertions are about what a server-side fetch of the
 * real pages finds.
 */
const FIXTURE_SRC = fileURLToPath(new URL('../fixtures/faz-e2e-footer-withdrawal-link.php', import.meta.url));
const MU_DEST = WP_PATH ? join(WP_PATH, 'wp-content', 'mu-plugins', 'faz-e2e-footer-withdrawal-link.php') : '';

const SETTINGS_URL = '/wp-admin/admin.php?page=faz-cookie-manager-settings';
const BANNER_URL = '/wp-admin/admin.php?page=faz-cookie-manager-banner';

/** Read the live predicate, which is what both the overlay and the lock ask. */
const satisfied = () =>
  wpEval('echo \\FazCookie\\Includes\\Withdrawal_Path::satisfies_revisit_requirement() ? "yes" : "no";').trim();

/** The configured route, as stored. */
const setPath = (path: 'widget' | 'footer_link') =>
  wpEval(
    `$s = get_option( "faz_settings", array() );` +
      `$s["banner_control"]["withdrawal_path"] = "${path}";` +
      `update_option( "faz_settings", $s );` +
      `echo \\FazCookie\\Includes\\Withdrawal_Path::configured_path();`,
  ).trim();

const installLink = () => copyFileSync(FIXTURE_SRC, MU_DEST);
const removeLink = () => {
  if (MU_DEST && existsSync(MU_DEST)) {
    rmSync(MU_DEST);
  }
};

test.describe('Verified footer withdrawal path', () => {
  test.skip(!WP_PATH, 'WP_PATH is required to install the footer-link fixture mu-plugin');

  let revisitRequired = false;

  test.beforeAll(() => {
    // The lock only exists where a reachable rule set demands the control. On an
    // install with jurisdiction routing off there is nothing to unlock, and the
    // assertions below would pass for the wrong reason.
    revisitRequired =
      wpEval('echo \\FazCookie\\Frontend\\Includes\\Geo_Runtime::is_enabled() ? "yes" : "no";').trim() === 'yes';
  });

  test.afterAll(() => {
    removeLink();
    setPath('widget');
    wpEval('delete_option( "faz_withdrawal_link_probe" ); echo "clean";');
  });

  test('a link found on every page unlocks the revisit widget; removing it locks it again', async ({
    page,
    loginAsAdmin,
  }) => {
    test.skip(!revisitRequired, 'jurisdiction routing is off on this install, so no rule set locks the widget');

    await loginAsAdmin(page);

    // --- 1. Before anything: the widget is the only route, so it is locked ---
    setPath('widget');
    removeLink();
    wpEval('delete_option( "faz_withdrawal_link_probe" ); echo "reset";');
    expect(satisfied(), 'nothing verified yet → the requirement is not satisfied elsewhere').toBe('no');

    await page.goto(BANNER_URL, { waitUntil: 'domcontentloaded' });
    await page.click('button.faz-tab[data-tab="advanced"]');
    const toggle = page.locator('#faz-b-revisit-toggle input[type="checkbox"]');
    await expect(toggle, 'the revisit widget switch is locked while it is the only withdrawal route').toBeDisabled();

    // --- 2. The administrator adds the link, then runs the check -------------
    installLink();
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });
    const status = page.locator('#faz-withdrawal-status');
    await expect(status, 'the screen reports the state of the check').toBeVisible();

    // Click the real button: this exercises the admin JS, the REST route, the
    // nonce and the capability check together, not just the PHP underneath.
    await page.click('#faz-withdrawal-verify');
    await expect(status, 'the check reports finding the link on every page').toContainText(
      /consent-preferences link on every page|link alle preferenze di consenso su ogni pagina/i,
      { timeout: 45_000 },
    );
    await expect(
      page.locator('#faz-withdrawal-path'),
      'the verified flag the save-time warning reads is updated in place',
    ).toHaveAttribute('data-faz-verified', '1');

    // Choosing the route is a separate, deliberate act: a successful check does
    // not silently switch the site over to it.
    expect(satisfied(), 'a verified link on the widget route still does not satisfy the requirement').toBe('no');
    expect(setPath('footer_link'), 'the footer-link route is stored').toBe('footer_link');
    expect(satisfied(), 'verified link + footer-link route → satisfied').toBe('yes');

    await page.goto(BANNER_URL, { waitUntil: 'domcontentloaded' });
    await page.click('button.faz-tab[data-tab="advanced"]');
    await expect(toggle, 'the switch unlocks once a different route is proven to exist').toBeEnabled();
    await expect(
      toggle,
      'and it is no longer marked runtime-locked, so the stored value is the one that saves',
    ).not.toHaveAttribute('data-faz-runtime-locked', '1');

    // --- 3. The link is removed. The lock must come back --------------------
    // This is the half that matters: a gate that only ever opens would leave a
    // site with no withdrawal route at all and no indication of it.
    removeLink();
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });
    await page.click('#faz-withdrawal-verify');
    await expect(status, 'the re-check reports the link is gone').toContainText(
      /No consent-preferences link was found|non ho trovato alcun link alle preferenze/i,
      { timeout: 45_000 },
    );
    expect(satisfied(), 'the link is gone → the requirement is no longer satisfied').toBe('no');

    await page.goto(BANNER_URL, { waitUntil: 'domcontentloaded' });
    await page.click('button.faz-tab[data-tab="advanced"]');
    await expect(toggle, 'the switch locks again, so the visitor keeps a way out').toBeDisabled();
  });

  test('the runtime overlay stops forcing the widget on only while the route is verified', async ({ page }) => {
    test.skip(!revisitRequired, 'jurisdiction routing is off on this install, so the overlay applies no requirement');

    // The admin screen is one surface; what the visitor receives is the other.
    // Assert on the config actually shipped to the page, with the saved banner
    // switch OFF — the state an administrator reaches after unlocking it.
    const savedBefore = wpEval(
      '$c = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();' +
        '$b = $c->get_active_banner_for_country( "" );' +
        '$s = $b->get_settings();' +
        'echo empty( $s["config"]["revisitConsent"]["status"] ) ? "off" : "on";',
    ).trim();

    const setSavedWidget = (on: boolean) =>
      wpEval(
        '$c = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();' +
          '$b = $c->get_active_banner_for_country( "" );' +
          '$s = $b->get_settings();' +
          `$s["config"]["revisitConsent"]["status"] = ${on ? 'true' : 'false'};` +
          '$b->set_settings( $s ); $b->save(); delete_option( "faz_banner_template" ); echo "done";',
      ).trim();

    const shippedStatus = async () => {
      const response = await page.request.get('/');
      const body = await response.text();
      const match = body.match(/"revisitConsent":\{[^}]*"status":(true|false)/);
      return match ? match[1] : 'absent';
    };

    try {
      setSavedWidget(false);

      installLink();
      wpEval('\\FazCookie\\Includes\\Withdrawal_Path::verify(); echo "verified";');
      setPath('footer_link');
      expect(satisfied(), 'precondition: the alternative route is verified and selected').toBe('yes');
      expect(await shippedStatus(), 'with a verified route the overlay leaves the switch alone').toBe('false');

      // Only the route changes. Same banner, same page, same everything else.
      setPath('widget');
      expect(await shippedStatus(), 'with no alternative route the overlay forces the widget back on').toBe('true');
    } finally {
      setPath('widget');
      removeLink();
      setSavedWidget(savedBefore === 'on');
      wpEval('delete_option( "faz_withdrawal_link_probe" ); echo "clean";');
    }
  });
});
