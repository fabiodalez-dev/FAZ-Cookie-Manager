// tests/e2e/specs/a11y-escape-close-button.spec.ts
//
// Escape never records a consent decision, also when the banner shows its X.
//
// a11y.js used to answer Escape inside the banner by clicking the close
// button, and the X runs _fazActionClose(), which rejects and writes the
// consent cookie. The F024 tests in a11y.spec.ts could not see it: by default
// class-template.php removes the X whenever Reject is shown, so the click
// found nothing. Here the X is kept (settings.allowCloseButtonWithReject, as
// geo-routed CCPA banners do), which is the configuration where an Escape
// press silently rejected — and, with the pushdown panel open, an Escape
// meant only to collapse it rejected too. Both tests fail on that code.
import { expect, test } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

function configureBanner(type: 'box' | 'banner', preferenceCenterType: 'popup' | 'pushdown', allowClose: boolean): void {
  wpEval(`
    $controller = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
    $banner = $controller->get_active_banner();
    if ( $banner ) {
      $s = $banner->get_settings();
      if ( ! is_array( $s ) ) { $s = array(); }
      if ( ! isset( $s['settings'] ) || ! is_array( $s['settings'] ) ) { $s['settings'] = array(); }
      $s['settings']['type'] = '${type}';
      $s['settings']['position'] = '${type === 'box' ? 'bottom-left' : 'bottom'}';
      $s['settings']['preferenceCenterType'] = '${preferenceCenterType}';
      $s['settings']['allowCloseButtonWithReject'] = ${allowClose ? 'true' : 'false'};
      $els =& $s['config']['notice']['elements'];
      if ( ! isset( $els['closeButton'] ) || ! is_array( $els['closeButton'] ) ) { $els['closeButton'] = array(); }
      $els['closeButton']['status'] = true;
      $els['buttons']['elements']['reject']['status'] = true;
      $banner->set_settings( $s );
      $banner->save();
    }
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
      faz_clear_banner_template_cache();
    } else {
      delete_option( 'faz_banner_template' );
    }
    $controller->delete_cache();
  `);
}

async function consentCookie(page: import('@playwright/test').Page): Promise<string | null> {
  return page.evaluate(() => {
    const m = document.cookie.split(';').find((c) => c.trim().startsWith('fazcookie-consent='));
    return m ?? null;
  });
}

test.describe.configure({ mode: 'serial' });

test.describe('Escape with the close button shown (F024, a11y)', () => {
  test.afterAll(() => {
    // The canonical default other specs expect.
    configureBanner('box', 'popup', false);
  });

  test('ESC-X-01: Escape on a banner control neither hides the banner nor writes consent', async ({ page, context }) => {
    configureBanner('box', 'popup', true);
    await context.clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const banner = page.locator('.faz-consent-container');
    await expect(banner).toBeVisible();
    // Premise: the X is really there, or this test proves nothing.
    await expect(banner.locator('[data-faz-tag="close-button"]')).toHaveCount(1);

    const accept = banner.locator('[data-faz-tag="accept-button"]');
    await accept.focus();
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    await expect(banner, 'Escape must not dismiss the banner').toBeVisible();
    expect(await consentCookie(page), 'Escape must not record a rejection').toBeNull();
  });

  test('ESC-X-02: Escape that collapses the pushdown panel does not reject', async ({ page, context }) => {
    configureBanner('banner', 'pushdown', true);
    await context.clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const banner = page.locator('.faz-consent-container');
    await expect(banner).toBeVisible();
    await expect(banner.locator('[data-faz-tag="close-button"]')).toHaveCount(1);

    const settings = banner.locator('[data-faz-tag="settings-button"]').first();
    await settings.click();
    const panel = page.locator('.faz-preference-wrapper');
    await expect(panel).toBeVisible();
    await expect(banner, 'premise: the pushdown panel is open').toHaveClass(/faz-consent-bar-expand/);

    await panel.locator('button, [tabindex="0"]').first().focus();
    await page.keyboard.press('Escape');

    await expect(banner, 'Escape collapses the pushdown panel').not.toHaveClass(/faz-consent-bar-expand/);
    await page.waitForTimeout(300);
    await expect(banner, 'the banner stays after Escape').toBeVisible();
    expect(await consentCookie(page), 'collapsing the panel with Escape must not record a rejection').toBeNull();
  });
});
