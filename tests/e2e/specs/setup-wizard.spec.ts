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
};

/** Snapshot faz_settings + the default banner settings JSON for later restore. */
function snapshot(): string {
  return wpEval(`
    $o = new \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings();
    $settings = get_option( 'faz_settings' );
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    echo wp_json_encode( array(
      'settings'  => $settings,
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
    if ( ! empty( $snap['banner_id'] ) && is_string( $snap['banner'] ) && '' !== $snap['banner'] ) {
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => $snap['banner'] ), array( 'banner_id' => (int) $snap['banner_id'] ), array( '%s' ), array( '%d' ) );
    }
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
    echo 'restored';
  `);
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
    $law = ''; $dns = false; $pop = false;
    if ( $b ) {
      $s = $b->get_settings();
      $law = isset( $s['settings']['applicableLaw'] ) ? $s['settings']['applicableLaw'] : '';
      $dns = ! empty( $s['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] );
      $pop = ! empty( $s['config']['optoutPopup']['status'] );
    }
    echo wp_json_encode( array( 'onboarding' => $onb, 'law' => $law, 'donotSell' => (bool) $dns, 'optoutPopup' => (bool) $pop ) );
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

      // Advance: step 1 → 2 → 3.
      await page.click('#faz-setup-next');
      await expect(page.locator('.faz-wizard-step[data-step="2"]')).toBeVisible();
      await page.click('#faz-setup-next');
      await expect(page.locator('.faz-wizard-step[data-step="3"]')).toBeVisible();

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

      await page.click('#faz-setup-next');
      await page.click('#faz-setup-next');
      await expect(page.locator('.faz-wizard-step[data-step="3"]')).toBeVisible();
      await page.click('#faz-setup-finish');
      await page.waitForURL(/page=faz-cookie-manager$/, { timeout: 15_000 });

      const state = readState();
      expect(state.onboarding.completed).toBe(true);
      expect(state.onboarding.law).toBe('gdpr');
      expect(state.law).toBe('gdpr');
      expect(state.donotSell).toBe(false);
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
      await page.click('#faz-setup-next');
      await page.click('#faz-setup-next');
      await page.click('#faz-setup-finish');
      await page.waitForURL(/page=faz-cookie-manager$/, { timeout: 15_000 });
      expect(readState().onboarding.completed).toBe(true);
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
