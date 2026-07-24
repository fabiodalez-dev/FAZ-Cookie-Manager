/**
 * Guided setup wizard (feat/competitor-parity-candidates).
 *
 * Walks the law-select → Finish flow and asserts the compliant defaults it
 * writes: onboarding.completed flips to true, the chosen jurisdiction is stored,
 * and the default banner's applicableLaw / Do-Not-Sell fields reflect the choice.
 * A second group asserts the Dashboard "Complete setup" card appears only when
 * onboarding is incomplete, that Dismiss hides it, and that a completed install
 * never renders it (backward-compat).
 *
 * State is snapshotted and restored so the wizard's writes do not pollute other
 * specs that read the default banner or faz_settings.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const SETUP_URL = `${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-setup`;
const DASHBOARD_URL = `${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager`;

type WizardState = {
  onboarding: { completed?: boolean; dismissed?: boolean; law?: string };
  law: string;
  donotSell: boolean;
  optoutPopup: boolean;
  expiry: number;
  noticeAccept: boolean;
  noticeReject: boolean;
};

/** Snapshot faz_settings + GCM settings + the default banner settings JSON for later restore. */
function snapshot(): string {
  return wpEval(`
    $o = new \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings();
    $settings = get_option( 'faz_settings' );
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    echo wp_json_encode( array(
      'settings'  => $settings,
      'gcm'       => get_option( 'faz_gcm_settings' ),
      'banner_id' => $row ? (int) $row->banner_id : 0,
      'banner'    => $row ? $row->settings : '',
    ) );
  `).trim();
}

function restore(snap: string): void {
  const b64 = Buffer.from(snap, 'utf8').toString('base64');
  wpEval(`
    $snap = json_decode( base64_decode( '${b64}' ), true );
    if ( is_array( $snap['settings'] ) ) { update_option( 'faz_settings', $snap['settings'] ); }
    if ( isset( $snap['gcm'] ) && is_array( $snap['gcm'] ) ) {
      update_option( 'faz_gcm_settings', $snap['gcm'] );
    } else {
      // The option did not exist at snapshot time (get_option returned false)
      // — a wizard run may have created it; remove it so GCM state cannot
      // leak into later tests.
      delete_option( 'faz_gcm_settings' );
    }
    if ( ! empty( $snap['banner_id'] ) && is_string( $snap['banner'] ) && '' !== $snap['banner'] ) {
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => $snap['banner'] ), array( 'banner_id' => (int) $snap['banner_id'] ), array( '%s' ), array( '%d' ) );
    }
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
    echo 'restored';
  `);
}

/**
 * Click Next until the review step (8) is visible, from whatever step the
 * wizard is currently on. The optional steps (2-7) are pass-through: skipping
 * them must reproduce the previous 3-step behaviour exactly.
 */
async function advanceToReview(page: import('@playwright/test').Page): Promise<void> {
  const review = page.locator('.faz-wizard-step[data-step="8"]');
  for (let i = 0; i < 8; i++) {
    if (await review.isVisible()) { return; }
    await page.click('#faz-setup-next');
  }
  await expect(review).toBeVisible();
}

/** Force onboarding to an incomplete, not-dismissed state (a fresh install). */
function forceIncomplete(): void {
  wpEval(`
    $o = new \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings();
    $all = $o->get();
    $all['onboarding']['completed'] = false;
    $all['onboarding']['dismissed'] = false;
    $all['onboarding']['law'] = '';
    $o->update( $all );
    echo 'ok';
  `);
}

/** Force onboarding complete (an upgrading/finished install). */
function forceComplete(): void {
  wpEval(`
    $o = new \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings();
    $all = $o->get();
    $all['onboarding']['completed'] = true;
    $all['onboarding']['dismissed'] = false;
    $o->update( $all );
    echo 'ok';
  `);
}

/** Read the wizard-relevant state: onboarding flags + default banner law fields. */
function readState(): WizardState {
  const raw = wpEval(`
    $o = new \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings();
    $onb = $o->get( 'onboarding' );
    $ctrl = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
    $b = $ctrl->get_active_banner();
    $law = ''; $dns = false; $pop = false; $expiry = 0; $accept = false; $reject = false;
    if ( $b ) {
      $s = $b->get_settings();
      $law = isset( $s['settings']['applicableLaw'] ) ? $s['settings']['applicableLaw'] : '';
      $dns = ! empty( $s['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] );
      $pop = ! empty( $s['config']['optoutPopup']['status'] );
      $expiry = isset( $s['settings']['consentExpiry']['value'] ) ? (int) $s['settings']['consentExpiry']['value'] : 0;
      $accept = ! empty( $s['config']['notice']['elements']['buttons']['elements']['accept']['status'] );
      $reject = ! empty( $s['config']['notice']['elements']['buttons']['elements']['reject']['status'] );
    }
    echo wp_json_encode( array(
      'onboarding' => $onb,
      'law' => $law,
      'donotSell' => (bool) $dns,
      'optoutPopup' => (bool) $pop,
      'expiry' => $expiry,
      'noticeAccept' => (bool) $accept,
      'noticeReject' => (bool) $reject,
    ) );
  `).trim();
  return JSON.parse(raw) as WizardState;
}

test.describe('Guided setup wizard', () => {
  test('law-select → Finish writes compliant CCPA defaults and completes onboarding', async ({ page, loginAsAdmin }) => {
    const snap = snapshot();
    try {
      forceIncomplete();
      await loginAsAdmin(page);
      await page.goto(SETUP_URL, { waitUntil: 'domcontentloaded' });

      // Step 1 — choose CCPA.
      const ccpa = page.locator('input[name="faz-setup-law"][value="ccpa"]');
      await expect(ccpa).toBeVisible();
      await ccpa.check();

      // Advance through every optional step to the review.
      await advanceToReview(page);

      // Review lists the chosen law.
      await expect(page.locator('#faz-setup-review')).toContainText('CCPA');

      // Finish redirects to the Dashboard.
      await page.click('#faz-setup-finish');
      await page.waitForURL(/page=faz-cookie-manager$/, { timeout: 15_000 });

      const state = readState();
      expect(state.onboarding.completed).toBe(true);
      expect(state.onboarding.law).toBe('ccpa');
      expect(state.law).toBe('ccpa');
      expect(state.donotSell).toBe(true);
      expect(state.optoutPopup).toBe(true);
      expect(state.expiry).toBe(365);
      expect(state.noticeAccept).toBe(false);
      expect(state.noticeReject).toBe(false);
    } finally {
      restore(snap);
    }
  });

  test('GDPR choice keeps the opt-in model (no Do-Not-Sell entry point)', async ({ page, loginAsAdmin }) => {
    const snap = snapshot();
    try {
      forceIncomplete();
      await loginAsAdmin(page);
      await page.goto(SETUP_URL, { waitUntil: 'domcontentloaded' });

      // GDPR is the pre-selected compliant default.
      await expect(page.locator('input[name="faz-setup-law"][value="gdpr"]')).toBeChecked();

      await advanceToReview(page);
      await page.click('#faz-setup-finish');
      await page.waitForURL(/page=faz-cookie-manager$/, { timeout: 15_000 });

      const state = readState();
      expect(state.onboarding.completed).toBe(true);
      expect(state.onboarding.law).toBe('gdpr');
      expect(state.law).toBe('gdpr');
      expect(state.donotSell).toBe(false);
      expect(state.optoutPopup).toBe(false);
      expect(state.expiry).toBe(180);
      expect(state.noticeAccept).toBe(true);
      expect(state.noticeReject).toBe(true);
    } finally {
      restore(snap);
    }
  });

  test('Both keeps GDPR opt-in controls and adds the US Do-Not-Sell path', async ({ page, loginAsAdmin }) => {
    const snap = snapshot();
    try {
      forceIncomplete();
      await loginAsAdmin(page);
      await page.goto(SETUP_URL, { waitUntil: 'domcontentloaded' });

      await page.locator('input[name="faz-setup-law"][value="both"]').check();
      await advanceToReview(page);
      await page.click('#faz-setup-finish');
      await page.waitForURL(/page=faz-cookie-manager$/, { timeout: 15_000 });

      const state = readState();
      expect(state.onboarding.completed).toBe(true);
      expect(state.onboarding.law).toBe('both');
      expect(state.law).toBe('gdpr');
      expect(state.donotSell).toBe(true);
      expect(state.optoutPopup).toBe(true);
      expect(state.expiry).toBe(180);
      expect(state.noticeAccept).toBe(true);
      expect(state.noticeReject).toBe(true);
    } finally {
      restore(snap);
    }
  });

  test('quick scan is optional and non-blocking (POST /scans returns scanning or 409)', async ({ page, loginAsAdmin }) => {
    const snap = snapshot();
    try {
      forceIncomplete();
      await loginAsAdmin(page);
      await page.goto(SETUP_URL, { waitUntil: 'domcontentloaded' });

      // Wait for the admin nonce so the direct REST probe authenticates.
      await page.waitForFunction(
        () => typeof (window as any).fazConfig?.api?.nonce === 'string' && (window as any).fazConfig.api.nonce.length > 0,
        undefined,
        { timeout: 15_000 },
      );

      const scanStatus = await page.evaluate(async () => {
        const nonce = (window as any).fazConfig?.api?.nonce ?? '';
        const res = await fetch('/?rest_route=/faz/v1/scans', {
          method: 'POST',
          headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
          body: JSON.stringify({ max_pages: 20 }),
        });
        return res.status;
      });
      // Either accepted (2xx) or already-in-progress (409) — both are non-fatal
      // and the wizard lets the admin Finish regardless.
      expect([200, 201, 409]).toContain(scanStatus);

      // Finish must be reachable regardless of scan outcome.
      await advanceToReview(page);
      await page.click('#faz-setup-finish');
      await page.waitForURL(/page=faz-cookie-manager$/, { timeout: 15_000 });
      expect(readState().onboarding.completed).toBe(true);
    } finally {
      restore(snap);
    }
  });

  test('TCF step blocks Next when enabled without a CMP ID', async ({ page, loginAsAdmin }) => {
    const snap = snapshot();
    try {
      forceIncomplete();
      await loginAsAdmin(page);
      await page.goto(SETUP_URL, { waitUntil: 'domcontentloaded' });

      // Advance to the TCF step (5).
      for (let step = 2; step <= 5; step++) {
        await page.click('#faz-setup-next');
        await expect(page.locator(`.faz-wizard-step[data-step="${step}"]`)).toBeVisible();
      }

      // Enable TCF with no CMP ID → Next must stay on step 5 with the inline error.
      await page.locator('#faz-setup-tcf').check();
      await page.click('#faz-setup-next');
      await expect(page.locator('.faz-wizard-step[data-step="5"]')).toBeVisible();
      await expect(page.locator('#faz-setup-tcf-error')).toBeVisible();

      // A valid CMP ID unblocks.
      await page.fill('#faz-setup-tcf-cmpid', '300');
      await page.click('#faz-setup-next');
      await expect(page.locator('.faz-wizard-step[data-step="6"]')).toBeVisible();
    } finally {
      restore(snap);
    }
  });

  test('optional selections persist: language, per-service toggles, GCM, payment gateway', async ({ page, loginAsAdmin }) => {
    const snap = snapshot();
    try {
      forceIncomplete();
      // Deterministic payment-gateway fixture: an already-enabled gateway is
      // ALWAYS listed by the recommendations endpoint (source 'enabled',
      // pre-checked), independent of what the scanner found. The test then
      // UNTICKS it, proving the explicit { key: bool } payload genuinely
      // disables a previously always-allowed gateway on Finish.
      wpEval(`
        $s = get_option( 'faz_settings' );
        $s['script_blocking']['payment_gateways']['stripe'] = true;
        update_option( 'faz_settings', $s );
        echo 'seeded';
      `);
      await loginAsAdmin(page);
      await page.goto(SETUP_URL, { waitUntil: 'domcontentloaded' });

      // Step 2 — Italian banner language.
      await page.click('#faz-setup-next');
      await page.selectOption('#faz-setup-lang', 'it');
      // Step 3 — per-service consent on.
      await page.click('#faz-setup-next');
      await page.locator('#faz-setup-bc-per_service_consent').check();
      // Step 4 — GCM on.
      await page.click('#faz-setup-next');
      await page.locator('#faz-setup-gcm').check();
      // Steps 5-6 — pass through; step 7 — untick the pre-checked Stripe row.
      await page.click('#faz-setup-next'); // → 5 (TCF)
      await page.click('#faz-setup-next'); // → 6 (geo)
      await page.click('#faz-setup-next'); // → 7 (scan + payments)
      await expect(page.locator('.faz-wizard-step[data-step="7"]')).toBeVisible();
      const stripe = page.locator('#faz-setup-payments-list input[data-gateway="stripe"]');
      await expect(stripe).toBeChecked({ timeout: 10_000 }); // pre-checked from stored state
      await stripe.uncheck();
      await advanceToReview(page);
      await expect(page.locator('#faz-setup-review')).toContainText('Italian');
      // The deselection of a previously-enabled gateway is disclosed.
      await expect(page.locator('#faz-setup-review')).toContainText('Stripe');

      await page.click('#faz-setup-finish');
      await page.waitForURL(/page=faz-cookie-manager$/, { timeout: 15_000 });

      const raw = wpEval(`
        $s = get_option( 'faz_settings' );
        $g = get_option( 'faz_gcm_settings' );
        echo wp_json_encode( array(
          'lang'        => isset( $s['languages']['default'] ) ? $s['languages']['default'] : '',
          'selected'    => isset( $s['languages']['selected'] ) ? $s['languages']['selected'] : array(),
          'per_service' => ! empty( $s['banner_control']['per_service_consent'] ),
          'gcm'         => ! empty( $g['status'] ),
          'stripe'      => ! empty( $s['script_blocking']['payment_gateways']['stripe'] ),
        ) );
      `).trim();
      const persisted = JSON.parse(raw);
      expect(persisted.lang).toBe('it');
      expect(persisted.selected).toContain('it');
      expect(persisted.per_service).toBe(true);
      expect(persisted.gcm).toBe(true);
      // Unticked in the wizard → genuinely disabled, not merge-kept true.
      expect(persisted.stripe).toBe(false);
    } finally {
      restore(snap);
    }
  });

  test('recommendations endpoint returns the detection payload', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(SETUP_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(
      () => typeof (window as any).fazConfig?.api?.nonce === 'string' && (window as any).fazConfig.api.nonce.length > 0,
      undefined,
      { timeout: 15_000 },
    );

    const payload = await page.evaluate(async () => {
      const nonce = (window as any).fazConfig.api.nonce;
      const res = await fetch('/?rest_route=/faz/v1/settings/onboarding/recommendations', {
        headers: { 'X-WP-Nonce': nonce },
      });
      return { status: res.status, body: await res.json() };
    });

    expect(payload.status).toBe(200);
    expect(typeof payload.body.site_language).toBe('string');
    expect(typeof payload.body.cache_plugin).toBe('string');
    expect(typeof payload.body.google_tags).toBe('boolean');
    expect(typeof payload.body.woocommerce).toBe('boolean');
    expect(Array.isArray(payload.body.gateways)).toBe(true);
    // Every detected gateway entry is well-formed.
    for (const gateway of payload.body.gateways) {
      expect(typeof gateway.key).toBe('string');
      expect(typeof gateway.label).toBe('string');
      expect(['plugin', 'scan']).toContain(gateway.source);
    }
  });

  test('invalid jurisdiction is rejected without completing onboarding', async ({ page, loginAsAdmin }) => {
    const snap = snapshot();
    try {
      forceIncomplete();
      await loginAsAdmin(page);
      await page.goto(SETUP_URL, { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(
        () => typeof (window as any).fazConfig?.api?.nonce === 'string' && (window as any).fazConfig.api.nonce.length > 0,
      );

      const response = await page.evaluate(async () => {
        const nonce = (window as any).fazConfig.api.nonce;
        const res = await fetch('/?rest_route=/faz/v1/settings/onboarding', {
          method: 'POST',
          headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
          body: JSON.stringify({ law: 'not-a-law' }),
        });
        return { status: res.status, body: await res.json() };
      });

      expect(response.status).toBe(400);
      // The enum is enforced at BOTH layers: the REST arg validation
      // (rest_invalid_param) rejects first; the handler's own whitelist
      // (faz_invalid_onboarding_law) is the defence-in-depth behind it.
      expect(['rest_invalid_param', 'faz_invalid_onboarding_law']).toContain(response.body.code);
      const state = readState();
      expect(state.onboarding.completed).toBe(false);
      expect(state.onboarding.law).toBe('');
    } finally {
      restore(snap);
    }
  });
});

test.describe('Dashboard "Complete setup" card', () => {
  test('appears when incomplete, Dismiss hides it and persists dismissed=true', async ({ page, loginAsAdmin }) => {
    const snap = snapshot();
    try {
      forceIncomplete();
      await loginAsAdmin(page);
      await page.goto(DASHBOARD_URL, { waitUntil: 'domcontentloaded' });

      const card = page.locator('#faz-setup-card');
      // dashboard.js reveals it after reading settings.
      await expect(card).toBeVisible({ timeout: 15_000 });

      await page.click('#faz-setup-card-dismiss');
      await expect(card).toBeHidden({ timeout: 10_000 });

      expect(readState().onboarding.dismissed).toBe(true);

      // Reload — the card stays hidden.
      await page.goto(DASHBOARD_URL, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1500); // give dashboard.js its settings fetch
      await expect(page.locator('#faz-setup-card')).toBeHidden();
    } finally {
      restore(snap);
    }
  });

  test('never renders for a completed install (backward-compat)', async ({ page, loginAsAdmin }) => {
    const snap = snapshot();
    try {
      forceComplete();
      await loginAsAdmin(page);
      await page.goto(DASHBOARD_URL, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1500); // allow dashboard.js to run its settings fetch
      await expect(page.locator('#faz-setup-card')).toBeHidden();
    } finally {
      restore(snap);
    }
  });
});
