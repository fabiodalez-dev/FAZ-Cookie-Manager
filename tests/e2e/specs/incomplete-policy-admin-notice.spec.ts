/**
 * #342: a cookie policy published before the 1.34.2 controller gate keeps
 * rendering for visitors when the controller name or contact is missing.
 * That stays (hiding it would remove the cookie inventory and the withdrawal
 * instructions), but the administrator is now told in wp-admin, not only when
 * they happen to open the page:
 *
 *  - Dashboard / Pages / FAZ screens list each published page whose policy
 *    misses the required details, saying whether visitors still see it;
 *  - the notice disappears once the details are filled in;
 *  - it is not added to unrelated admin screens.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { execFileSync } from 'node:child_process';

const WP_PATH = process.env.WP_PATH || '';
const OPTION = 'faz_cookie_policy_data';
const NOTICE = '.faz-incomplete-policy-notice';

function wp(args: string[]): string {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();
}

function setCompany(name: string, email: string): void {
  const php = `$s = get_option( '${OPTION}', array() ); $s = is_array( $s ) ? $s : array();`
    + ` $s['company'] = array_merge( (array) ( $s['company'] ?? array() ), array( 'name' => ${JSON.stringify(name)}, 'email' => ${JSON.stringify(email)} ) );`
    + ` update_option( '${OPTION}', $s );`;
  wp(['eval', php]);
}

test.describe('Published cookie policy without controller details is flagged in wp-admin (#342)', () => {
  test.skip(!WP_PATH, 'requires WP_PATH to seed the policy settings and pages via wp-cli');
  test.describe.configure({ mode: 'serial' });

  let saved = '';
  const created: string[] = [];

  test.beforeAll(() => {
    // Absent on a fresh site: then it is deleted again afterwards.
    try {
      saved = execFileSync('wp', [`--path=${WP_PATH}`, 'option', 'get', OPTION, '--format=json'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
    } catch {
      saved = '';
    }
    setCompany('', '');
    created.push(wp(['post', 'create', '--post_type=page', '--post_status=publish', '--post_title=E2E 342 GDPR policy',
      '--post_content=[faz_cookie_policy_complete jurisdiction="gdpr-strict"]', '--porcelain']));
    created.push(wp(['post', 'create', '--post_type=page', '--post_status=publish', '--post_title=E2E 342 POPIA policy',
      // Curled quotes, as the editor writes them: the notice must read the
      // regime the page renders, not fall back to the saved one.
      '--post_content=[faz_cookie_policy_complete jurisdiction=”popia-southafrica”]', '--porcelain']));
    created.push(wp(['post', 'create', '--post_type=page', '--post_status=publish', '--post_title=E2E 342 shortcode example',
      '--post_content=[[faz_cookie_policy_complete jurisdiction="popia-southafrica"]]', '--porcelain']));
    created.push(wp(['post', 'create', '--post_type=page', '--post_status=publish', '--post_title=E2E 342 mixed policy and example',
      '--post_content=[[faz_cookie_policy_complete jurisdiction="popia-southafrica"]] [faz_cookie_policy_complete jurisdiction="gdpr-strict"]', '--porcelain']));
  });

  test.afterAll(() => {
    for (const id of created) {
      try { wp(['post', 'delete', id, '--force']); } catch { /* already gone */ }
    }
    if (saved) {
      wp(['option', 'update', OPTION, saved, '--format=json']);
    } else {
      try { execFileSync('wp', [`--path=${WP_PATH}`, 'option', 'delete', OPTION], { stdio: 'ignore' }); } catch { /* absent */ }
    }
  });

  test('the Dashboard lists each incomplete published policy and what visitors see', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto('/wp-admin/index.php', { waitUntil: 'domcontentloaded' });
    const notice = page.locator(NOTICE);
    await expect(notice).toBeVisible();
    const gdpr = notice.locator('li', { hasText: 'E2E 342 GDPR policy' });
    const popia = notice.locator('li', { hasText: 'E2E 342 POPIA policy' });
    // Each page names what is missing for ITS regime and what visitors see.
    await expect(gdpr).toContainText('Missing: Legal entity name; Privacy contact email.');
    await expect(gdpr).toContainText('Visitors still see this policy');
    await expect(popia).toContainText('Information Officer name');
    await expect(popia).toContainText('Separate Privacy Policy URL');
    await expect(popia).toContainText('Visitors see no policy until these are filled in.');
    // Escaped shortcodes are text, including when followed by a real policy.
    await expect(notice.locator('li', { hasText: 'E2E 342 shortcode example' })).toHaveCount(0);
    const mixed = notice.locator('li', { hasText: 'E2E 342 mixed policy and example' });
    await expect(mixed).toContainText('Visitors still see this policy');
    await expect(mixed).not.toContainText('Information Officer');
    await expect(notice.locator('a.button')).toHaveAttribute('href', /page=faz-cookie-manager-cookie-policy/);

    // Also on the page list, and not on an unrelated screen.
    await page.goto('/wp-admin/edit.php?post_type=page', { waitUntil: 'domcontentloaded' });
    await expect(page.locator(NOTICE)).toBeVisible();
    await page.goto('/wp-admin/options-general.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator(NOTICE)).toHaveCount(0);
  });

  test('the live page tells the administrator it is already visible, and visitors still get it', async ({ browser, page, loginAsAdmin }) => {
    const gdprUrl = wp(['post', 'list', '--post_type=page', '--title=E2E 342 GDPR policy', '--field=url']);
    await loginAsAdmin(page);
    await page.goto(gdprUrl, { waitUntil: 'domcontentloaded' });
    const inline = page.locator('.faz-cookie-policy-empty');
    await expect(inline).toContainText('visitors see this policy');
    await expect(inline).not.toContainText('before publishing');

    // What an anonymous visitor gets: the GDPR policy itself (no admin
    // notice), and nothing for the POPIA page the renderer refuses.
    const visitor = await browser.newContext();
    await visitor.clearCookies();
    const anon = await visitor.newPage();
    await anon.goto(gdprUrl, { waitUntil: 'domcontentloaded' });
    await expect(anon.locator('article.faz-cookie-policy')).toBeVisible();
    await expect(anon.locator('.faz-cookie-policy-empty')).toHaveCount(0);
    await anon.goto(wp(['post', 'list', '--post_type=page', '--title=E2E 342 POPIA policy', '--field=url']), { waitUntil: 'domcontentloaded' });
    await expect(anon.locator('article.faz-cookie-policy')).toHaveCount(0);
    await anon.goto(wp(['post', 'list', '--post_type=page', '--title=E2E 342 shortcode example', '--field=url']), { waitUntil: 'domcontentloaded' });
    await expect(anon.locator('article.faz-cookie-policy')).toHaveCount(0);
    await expect(anon.getByText('[faz_cookie_policy_complete jurisdiction="popia-southafrica"]', { exact: true })).toBeVisible();
    await visitor.close();
  });

  test('the notice goes away once the controller details are filled in', async ({ page, loginAsAdmin }) => {
    setCompany('E2E Controller Ltd', 'privacy@example.com');
    // Twenty newer, now-complete policy pages: the lookup must still reach
    // the older incomplete POPIA page behind them (it used to stop at 20).
    // (A future post_date would make them scheduled, not published, so the
    // POPIA page is moved two days back instead.)
    const popiaId = wp(['post', 'list', '--post_type=page', '--title=E2E 342 POPIA policy', '--field=ID']);
    wp(['post', 'update', popiaId, `--post_date=${new Date(Date.now() - 2 * 86400_000).toISOString().slice(0, 19).replace('T', ' ')}`]);
    const ids = wp(['eval', `$ids = array(); for ( $i = 0; $i < 20; $i++ ) { $ids[] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E 342 filler ' . $i, 'post_content' => '[faz_cookie_policy_complete jurisdiction="gdpr-strict"]' ) ); } echo implode( ' ', $ids );`]);
    created.push(...ids.split(' ').filter(Boolean));
    await loginAsAdmin(page);
    await page.goto('/wp-admin/index.php', { waitUntil: 'domcontentloaded' });
    const notice = page.locator(NOTICE);
    // GDPR only needs name + email; POPIA also needs address, DPO and privacy
    // URL, and the notice now lists only those.
    await expect(notice.locator('li', { hasText: 'E2E 342 GDPR policy' })).toHaveCount(0);
    await expect(notice.locator('li', { hasText: 'E2E 342 shortcode example' })).toHaveCount(0);
    await expect(notice.locator('li', { hasText: 'E2E 342 mixed policy and example' })).toHaveCount(0);
    const popia = notice.locator('li', { hasText: 'E2E 342 POPIA policy' });
    await expect(popia).toHaveCount(1);
    await expect(popia).toContainText('Postal address');
    await expect(popia).not.toContainText('Legal entity name');
    await expect(popia).not.toContainText('Privacy contact email');
  });
});
