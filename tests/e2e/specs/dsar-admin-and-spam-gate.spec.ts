/**
 * E2E coverage for the two field reports on the data-request form:
 *
 *   DSAR-ADMIN-01  The stored request is actionable. The link in the notification
 *                  email used to open an editor showing a title and nothing else
 *                  (every DSAR meta key is underscore-prefixed, so WordPress hides
 *                  it from the Custom Fields panel), which left copying the address
 *                  into Tools → Erase Personal Data by hand as the only way to
 *                  honour a request.
 *   DSAR-ADMIN-02  One click turns the record into the matching core privacy
 *                  request, and the record remembers that it did.
 *   DSAR-ADMIN-03  The requests are reachable from the plugin's own menu, not only
 *                  from a link in an email that may never arrive.
 *   DSAR-SPAM-01   `faz_dsar_pre_submit` can refuse a submission and its message
 *                  reaches the visitor — the integration point a protection plugin
 *                  (ALTCHA / OpenPorte) needs, since it cannot see a form that
 *                  lives behind a plugin-specific wp_ajax action.
 */

import { copyFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { expect } from '@playwright/test';
import { test } from '../fixtures/wp-fixture';
import { upsertPage, wpEval, WP_PATH } from '../utils/wp-env';

// ── Constants ────────────────────────────────────────────────────────────────

const REQUESTER = 'e2e-dsar-subject@example.test';
const FIXTURE_SRC = fileURLToPath(new URL('../fixtures/faz-e2e-dsar-spam-gate.php', import.meta.url));
const MU_DEST = WP_PATH ? join(WP_PATH, 'wp-content', 'mu-plugins', 'faz-e2e-dsar-spam-gate.php') : '';
const FORM_SLUG = 'faz-e2e-dsar-gate';

/** Remove every stored request and core privacy request for our address. */
function purgeRequests(): void {
  wpEval(`
    $ids = get_posts( array(
      'post_type'   => 'faz_dsar',
      'post_status' => 'any',
      'numberposts' => -1,
      'fields'      => 'ids',
    ) );
    foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
    $reqs = get_posts( array(
      'post_type'   => 'user_request',
      'post_status' => 'any',
      'numberposts' => -1,
      'fields'      => 'ids',
      'title'       => '${REQUESTER}',
    ) );
    foreach ( $reqs as $id ) { wp_delete_post( $id, true ); }
    echo 'ok';
  `);
}

/** Create one stored request and return its post ID. */
function seedRequest(type = 'erasure'): number {
  const raw = wpEval(`
    $id = wp_insert_post( array(
      'post_type'   => 'faz_dsar',
      'post_status' => 'private',
      'post_title'  => '[E2E] Jane Doe',
      'meta_input'  => array(
        '_dsar_name'    => 'Jane Doe',
        '_dsar_email'   => '${REQUESTER}',
        '_dsar_type'    => '${type}',
        '_dsar_message' => 'Please remove everything you hold about me.',
        '_dsar_status'  => 'pending',
      ),
    ), true );
    echo is_wp_error( $id ) ? 0 : (int) $id;
  `).trim();
  const id = Number(raw);
  expect(id, 'seeded DSAR record').toBeGreaterThan(0);
  return id;
}

/** Drop every throttle the form keeps, so a run never inherits the last one's. */
function clearDsarThrottles(): void {
  wpEval(`
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_faz_dsar_rl_%'
      OR option_name LIKE '_transient_timeout_faz_dsar_rl_%'
      OR option_name LIKE 'faz_dsar_lock_%'
      OR option_name LIKE 'faz_dsar_rl_%'" );
    wp_cache_flush();
    echo 'ok';
  `);
}

// ── DSAR-ADMIN-01/02/03 ──────────────────────────────────────────────────────

test.describe('DSAR-ADMIN — a stored request can be handled from the admin', () => {
  test.describe.configure({ mode: 'serial' });

  let postId = 0;

  test.beforeAll(() => {
    purgeRequests();
    postId = seedRequest('erasure');
  });

  test.afterAll(() => {
    purgeRequests();
  });

  test('DSAR-ADMIN-01: the edit screen shows the submitted values and action buttons', async ({ page, wpBaseURL, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });

    const details = page.locator('#faz-dsar-details');
    await expect(details).toBeVisible();
    await expect(details).toContainText(REQUESTER);
    await expect(details).toContainText('Jane Doe');
    // The right invoked, spelled out — not the raw meta key.
    await expect(details).toContainText('Art. 17');
    await expect(details).toContainText('Please remove everything you hold about me.');

    // The regression in one assertion: there is something to click.
    const actions = page.locator('#faz-dsar-actions');
    await expect(actions).toBeVisible();
    await expect(actions.getByRole('link', { name: /Create erasure request/i })).toBeVisible();
    await expect(actions.getByRole('link', { name: /Mark as completed/i })).toBeVisible();
    // No nested form: a meta box renders inside the editor's own <form id="post">,
    // and an inner </form> closes THAT form, dropping everything after this box
    // out of the editor. Measured here, not assumed.
    await expect(actions.locator('form')).toHaveCount(0);
    await expect(page.locator('form#post')).toHaveCount(1);
    // The editor's own form must still contain the box and its Update button.
    await expect(page.locator('form#post #faz-dsar-actions')).toHaveCount(1);
    await expect(page.locator('form#post #publishing-action input[type="submit"]')).toHaveCount(1);
    // Crawlers are told to leave the action links alone.
    await expect(actions.locator('a.button[rel="nofollow"]').first()).toBeVisible();
  });

  test('DSAR-ADMIN-03: the requests are listed under the plugin menu', async ({ page, wpBaseURL, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager`, { waitUntil: 'domcontentloaded' });
    await expect(
      page.locator('#adminmenu a[href="edit.php?post_type=faz_dsar"]'),
    ).toHaveCount(1);

    // The pending count rides the menu item. Past the daily ceiling the form
    // stops emailing each request, so this is the one channel that tells the
    // admin a request is waiting no matter how much traffic arrives.
    await expect(
      page.locator('#adminmenu a[href="edit.php?post_type=faz_dsar"] .plugin-count'),
    ).toHaveCount(1);

    await page.goto(`${wpBaseURL}/wp-admin/edit.php?post_type=faz_dsar`, { waitUntil: 'domcontentloaded' });
    // The columns that identify a request, which the default list table lacked.
    await expect(page.locator('th#faz_dsar_email')).toBeVisible();
    await expect(page.locator('th#faz_dsar_status')).toBeVisible();
    await expect(page.locator('#the-list')).toContainText(REQUESTER);
  });

  test('DSAR-ADMIN-02: one click creates the core erasure request and the record remembers it', async ({ page, wpBaseURL, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });

    await page.locator('#faz-dsar-actions').getByRole('link', { name: /Create erasure request/i }).click();
    await page.waitForLoadState('domcontentloaded');

    await expect(page.locator('.notice-success')).toContainText(/Erasure request created/i);

    // Core now holds a privacy request of the right kind for this address.
    const core = wpEval(`
      $reqs = get_posts( array(
        'post_type'   => 'user_request',
        'post_status' => 'any',
        'numberposts' => -1,
        'title'       => '${REQUESTER}',
      ) );
      echo count( $reqs ) . '|' . ( $reqs ? $reqs[0]->post_name : '' );
    `).trim();
    expect(core, 'exactly one core request, of the erasure kind').toBe('1|remove_personal_data');

    // And the record carries the link, so the next visit does not create a second.
    const status = wpEval(`echo get_post_meta( ${postId}, '_dsar_status', true );`).trim();
    expect(status).toBe('forwarded');
    const linked = wpEval(`echo (int) get_post_meta( ${postId}, '_dsar_core_request_id', true );`).trim();
    expect(Number(linked)).toBeGreaterThan(0);

    await page.goto(`${wpBaseURL}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });
    const actions = page.locator('#faz-dsar-actions');
    await expect(actions.getByRole('link', { name: /Create erasure request/i })).toHaveCount(0);
    await expect(actions.getByRole('link', { name: /Erase Personal Data/i })).toBeVisible();
  });

  test('DSAR-ADMIN-02b: marking completed is recorded and reversible', async ({ page, wpBaseURL, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });

    await page.locator('#faz-dsar-actions').getByRole('link', { name: /Mark as completed/i }).click();
    await page.waitForLoadState('domcontentloaded');
    expect(wpEval(`echo get_post_meta( ${postId}, '_dsar_status', true );`).trim()).toBe('completed');

    await page.locator('#faz-dsar-actions').getByRole('link', { name: /Reopen request/i }).click();
    await page.waitForLoadState('domcontentloaded');
    expect(wpEval(`echo get_post_meta( ${postId}, '_dsar_status', true );`).trim()).toBe('pending');
  });

  test('DSAR-ADMIN-04: the action handler refuses a request without a valid nonce', async ({ page, wpBaseURL, loginAsAdmin }) => {
    await loginAsAdmin(page);
    const before = wpEval(`
      echo count( get_posts( array(
        'post_type'   => 'user_request',
        'post_status' => 'any',
        'numberposts' => -1,
        'title'       => '${REQUESTER}',
      ) ) );
    `).trim();

    // No _wpnonce: a logged-in administrator following a forged link must not
    // create a privacy request.
    const response = await page.goto(
      `${wpBaseURL}/wp-admin/admin-post.php?action=faz_dsar_action&post=${postId}&faz_action=export_personal_data`,
      { waitUntil: 'domcontentloaded' },
    );
    expect(response?.status()).toBeGreaterThanOrEqual(400);

    // POST without a nonce is refused on the same path.
    const posted = await page.request.post(`${wpBaseURL}/wp-admin/admin-post.php`, {
      form: {
        action: 'faz_dsar_action',
        post: String(postId),
        faz_action: 'export_personal_data',
      },
    });
    expect(posted.status(), 'a POST without a nonce is refused').toBeGreaterThanOrEqual(400);

    const after = wpEval(`
      echo count( get_posts( array(
        'post_type'   => 'user_request',
        'post_status' => 'any',
        'numberposts' => -1,
        'title'       => '${REQUESTER}',
      ) ) );
    `).trim();
    expect(after, 'no privacy request was created without a nonce').toBe(before);
  });
});

// ── DSAR-SPAM-01 ─────────────────────────────────────────────────────────────

test.describe('DSAR-SPAM-01 — faz_dsar_pre_submit can refuse a submission', () => {
  test.describe.configure({ mode: 'serial' });

  let pageUrl = '';

  test.beforeAll(() => {
    if (!WP_PATH) {
      throw new Error('WP_PATH is required: the spam-gate fixture is installed as an mu-plugin.');
    }
    purgeRequests();
    clearDsarThrottles();
    upsertPage(FORM_SLUG, 'FAZ DSAR Gate', '[faz_dsar_form]');
    pageUrl = wpEval(`
      $p = get_page_by_path( '${FORM_SLUG}', OBJECT, 'page' );
      echo $p ? get_permalink( $p->ID ) : '';
    `).trim();
    if (!pageUrl) {
      throw new Error(`Could not resolve permalink for "${FORM_SLUG}". Enable pretty permalinks.`);
    }
    copyFileSync(FIXTURE_SRC, MU_DEST);
  });

  test.afterAll(() => {
    rmSync(MU_DEST, { force: true });
    purgeRequests();
    clearDsarThrottles();
  });

  test.beforeEach(async ({ page, wpBaseURL }) => {
    clearDsarThrottles();
    await page.context().clearCookies();
    const rev = parseInt(wpEval('echo faz_get_consent_revision();').trim(), 10) || 1;
    const domain = new URL(wpBaseURL).hostname;
    await page.context().addCookies([{
      name:     'fazcookie-consent',
      value:    `consentid%3Ae2e-gate%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes%2Cmarketing%3Ayes%2Crev%3A${rev}`,
      domain,
      path:     '/',
      sameSite: 'Lax',
    }]);
  });

  async function submit(page, message: string) {
    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dsar-form', { timeout: 10_000 });
    await page.fill('input[name="dsar_name"]', 'Jane Doe');
    await page.fill('input[name="dsar_email"]', REQUESTER);
    await page.selectOption('select[name="dsar_type"]', 'erasure');
    await page.fill('textarea[name="dsar_message"]', message);
    await page.locator('.faz-dsar-btn').click();
    await page.waitForSelector('.faz-dsar-notice', { state: 'visible', timeout: 10_000 });
  }

  test("a gated submission is refused with the gate's own message and stores nothing", async ({ page }) => {
    await submit(page, 'Buy viagra now');

    const notice = page.locator('.faz-dsar-notice');
    await expect(notice).toHaveClass(/error/);
    await expect(notice).toContainText('Blocked by the E2E spam gate.');

    const stored = wpEval(`
      echo count( get_posts( array(
        'post_type'   => 'faz_dsar',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
      ) ) );
    `).trim();
    expect(stored, 'a refused submission leaves no record and sends no email').toBe('0');
  });

  test('a submission the gate accepts still goes through', async ({ page }) => {
    await submit(page, 'Please erase my data.');

    const notice = page.locator('.faz-dsar-notice');
    await expect(notice).toHaveClass(/success/);

    const stored = wpEval(`
      echo count( get_posts( array(
        'post_type'   => 'faz_dsar',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
      ) ) );
    `).trim();
    expect(stored, 'the gate does not block legitimate requests').toBe('1');
  });
});
