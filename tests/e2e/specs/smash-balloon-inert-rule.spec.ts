/**
 * The blocker-template card must say when its rule cannot take effect.
 *
 * When Instagram Feed's own GDPR setting is Yes, this plugin stands down for that
 * feed — container and script alike. An admin who then adds the Smash Balloon
 * blocker template, watches the feed load anyway, and finds nothing explaining
 * why will reasonably conclude the plugin is broken. The card therefore carries
 * a badge, a plain-language note, and a link to the setting that actually
 * decides the behaviour.
 *
 * Two things are asserted that a screenshot could not: that the link points at
 * the page Instagram Feed really registers, and that the wording never claims to
 * override anything. Nothing is being overridden — with local copies served and
 * no request made, the third-party surface the rule exists to gate is simply
 * gone.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { WP_PATH, wpEval } from '../utils/wp-env';

const COOKIES_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager-cookies';
const CARD = '.faz-template-card-inert';
const BADGE = '.faz-template-card-inert-badge';

/** Instagram Feed's settings slug, as pinned by the PHP unit suite. */
const SB_SETTINGS_SLUG = 'sbi-settings';

function setGdpr(value: string): void {
  wpEval(
    `$s = get_option( 'sb_instagram_settings', array() );` +
    `$s = is_array( $s ) ? $s : array();` +
    `$s['gdpr'] = '${value}';` +
    `update_option( 'sb_instagram_settings', $s );`,
  );
}

function readGdpr(): string {
  return wpEval(
    "$s = get_option( 'sb_instagram_settings', array() );" +
    "echo ( is_array( $s ) && isset( $s['gdpr'] ) ) ? $s['gdpr'] : '';",
  ).trim();
}

function instagramFeedActive(): boolean {
  return wpEval("echo in_array( 'instagram-feed/instagram-feed.php', (array) get_option( 'active_plugins', array() ), true ) ? '1' : '';").trim() === '1';
}

test.describe('Smash Balloon: a rule that cannot take effect says so', () => {
  let restore = '';

  test.beforeAll(() => {
    test.skip(!WP_PATH, 'requires WP_PATH to read and set Instagram Feed’s own option');
    restore = readGdpr();
  });

  test.afterAll(() => {
    if (WP_PATH) {
      setGdpr(restore || 'auto');
    }
  });

  test('the card is marked inert only while Instagram Feed is limiting itself', async ({ page, loginAsAdmin }) => {
    test.skip(!WP_PATH, 'requires WP_PATH');
    // The accommodation requires a runtime signal that the plugin is loaded, so
    // with it inactive the correct outcome is "no badge" for a reason unrelated
    // to the wording under test. Skipping beats asserting the right thing for
    // the wrong reason.
    test.skip(!instagramFeedActive(), 'Instagram Feed is not active on this install');

    await loginAsAdmin(page);

    // 1. Not limiting itself → the rule applies → no badge.
    setGdpr('auto');
    await page.goto(COOKIES_PAGE, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.faz-template-card').first()).toBeVisible({ timeout: 30_000 });
    await expect(page.locator(CARD)).toHaveCount(0);

    // 2. Limiting itself → the rule cannot act → badge, note and link.
    setGdpr('yes');
    await page.goto(COOKIES_PAGE, { waitUntil: 'domcontentloaded' });
    const card = page.locator(CARD);
    await expect(card).toHaveCount(1, { timeout: 30_000 });
    await expect(card.locator(BADGE)).toBeVisible();

    const note = (await card.locator('.faz-template-card-inert-note').textContent()) ?? '';
    expect(note.length).toBeGreaterThan(20);

    // The wording constraint is the point of the feature, not decoration: an
    // "overridden" reading would tell the admin their setting lost an argument,
    // when in fact there is no longer anything for it to apply to.
    expect(note.toLowerCase()).not.toContain('overrid');
    expect(note.toLowerCase()).not.toContain('ignored');

    // 3. The link must reach the control that actually decides this.
    const link = card.locator('.faz-template-card-inert-link');
    await expect(link).toBeVisible();
    await link.click();
    await page.waitForURL(new RegExp(`page=${SB_SETTINGS_SLUG}`), { timeout: 30_000 });
    expect(page.url()).toContain(`page=${SB_SETTINGS_SLUG}`);
    // A link that 404s is worse than one that only names the setting.
    await expect(page.locator('body')).not.toContainText('Sorry, you are not allowed to access this page');

    // 4. Back to not-limiting: the badge must clear, so it tracks the setting
    //    rather than latching on once.
    setGdpr('no');
    await page.goto(COOKIES_PAGE, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.faz-template-card').first()).toBeVisible({ timeout: 30_000 });
    await expect(page.locator(CARD)).toHaveCount(0);
  });
});
