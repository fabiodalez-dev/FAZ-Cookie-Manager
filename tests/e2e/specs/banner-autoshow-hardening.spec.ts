import { test, expect } from '../fixtures/wp-fixture';

/**
 * Auto-show hardening: a banner that never appears is a missing consent prompt
 * (a GDPR violation), strictly worse than one shown a beat late. Two safety nets:
 *
 *  - A fail-open watchdog re-arms the anti-FOUC reveal (the `.faz-ready` gate on
 *    <html>) if a partial init, a thrown decorator, or a CSS optimizer
 *    (LiteSpeed/WP Rocket/Autoptimize) ever strips it — so the banner can't stay
 *    invisible while its fixed container keeps eating clicks.
 *  - `fazcookie._diag()` gives support a one-call read-only snapshot of why the
 *    banner / service toggles may not be showing (incl. a build marker that
 *    exposes a stale cached bundle after an update).
 *
 * These run against a fresh context (no consent cookie) so the banner is in its
 * first-visit, must-be-visible state.
 */

test.describe('Banner auto-show hardening', () => {
  test('fail-open watchdog re-arms the .faz-ready reveal if it gets stripped', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // Within the watchdog's pre-fire window, simulate a CSS-optimizer that
    // hoisted/stripped the inline reveal: drop the .faz-ready gate and clear the
    // recorded action (a brand-new visitor). Nothing in the normal flow re-adds
    // .faz-ready after init, so only the watchdog can restore it.
    await page.evaluate(() => {
      document.documentElement.classList.remove('faz-ready');
      // Also hide the banner: this is what makes the test prove the watchdog
      // SHOWS the banner, not merely that it restores the .faz-ready gate. The
      // watchdog only calls _fazShowBanner() (which removes faz-hide) when the
      // container currently has it, so re-adding faz-hide here exercises that path.
      document.querySelector('.faz-consent-container')?.classList.add('faz-hide');
      try {
        const fz = (window as unknown as { fazcookie?: { _fazConsentStore?: Map<string, string> } }).fazcookie;
        fz?._fazConsentStore?.delete('action');
      } catch {
        /* noop */
      }
    });

    // The watchdog fires ~2.5s after init and must put the gate back.
    await expect
      .poll(() => page.evaluate(() => document.documentElement.classList.contains('faz-ready')), {
        timeout: 7000,
      })
      .toBe(true);

    // And it must actually reveal the banner (faz-hide removed), not just the gate.
    await expect
      .poll(() => page.evaluate(() => {
        const banner = document.querySelector('.faz-consent-container');
        return !!banner && !banner.classList.contains('faz-hide');
      }), {
        timeout: 7000,
      })
      .toBe(true);

    await ctx.close();
  });

  test('fazcookie._diag() returns a read-only support snapshot with a build marker', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 8000 });

    const diag = await page.evaluate(() => {
      const fz = (window as unknown as { fazcookie?: { _diag?: () => Record<string, unknown> } }).fazcookie;
      return fz && typeof fz._diag === 'function' ? fz._diag() : null;
    });

    expect(diag, '_diag is exposed on window.fazcookie').not.toBeNull();
    // Build marker — its presence is what lets support spot a stale cached bundle.
    expect(typeof diag!.build).toBe('string');
    expect((diag!.build as string).length).toBeGreaterThan(0);
    // Banner-visibility fields.
    expect(diag!.ready).toBe(true);
    expect(typeof diag!.bannerFound).toBe('boolean');
    expect('action' in diag!).toBe(true);
    expect(typeof diag!.hasConsentCookie).toBe('boolean');
    // Per-service fields are always present (null/0 when the feature is off).
    expect('perServiceConsent' in diag!).toBe(true);
    expect('catalogueCount' in diag!).toBe(true);

    // Read-only: calling it must not write a consent cookie.
    const before = await page.evaluate(() => document.cookie);
    await page.evaluate(() => (window as unknown as { fazcookie: { _diag: () => unknown } }).fazcookie._diag());
    const after = await page.evaluate(() => document.cookie);
    expect(after).toBe(before);

    await ctx.close();
  });
});
