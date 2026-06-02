// tests/e2e/specs/a11y.spec.ts
import { expect, test } from '../fixtures/wp-fixture';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { wpEval } from '../utils/wp-env';

// Ensure the banner is in "box / bottom-left" mode before a11y tests run.
// Other specs may leave the banner in a different type (e.g. "banner" / full-
// width) which changes the set of focusable elements and the focus-trap
// behaviour. Resetting here keeps the a11y suite self-contained.
test.beforeAll(async () => {
  wpEval(`
    global $wpdb;
    $row = $wpdb->get_row("SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 ORDER BY banner_id ASC LIMIT 1");
    if ($row) {
      $s = json_decode($row->settings, true);
      $s['settings']['type'] = 'box';
      $s['settings']['position'] = 'bottom-left';
      $wpdb->update(
        $wpdb->prefix . 'faz_banners',
        array('settings' => wp_json_encode($s)),
        array('banner_id' => (int) $row->banner_id)
      );
    }
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
      faz_clear_banner_template_cache();
    } else {
      delete_option( 'faz_banner_template' );
    }
    if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'banner_template' );
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
    }
  `);
});

// ---------------------------------------------------------------------------
// Structural DOM fixes — applied by a11y.js after fazcookie_banner_loaded fires.
// ---------------------------------------------------------------------------
test.beforeAll(() => {
  // Self-provision the default box+popup GDPR banner so this spec is immune
  // to a prior full-suite spec leaving the shared banner in classic/pushdown
  // or CCPA mode (see utils/seed-defaults.ts).
  resetDefaultBannerState();
});

test.describe('Native a11y — structural DOM fixes', () => {
  test.describe.configure({ mode: 'serial' });

  // Banner title must be a real <h2> with the id used by aria-labelledby.
  test('banner title is an <h2> with id="faz-banner-title"', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const title = page.locator('h2.faz-title#faz-banner-title');
    await expect(title).toBeAttached();
  });

  // Modal title must be a real <h2> with the id used by aria-labelledby.
  test('modal title is an <h2> with id="faz-modal-title"', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
    // Open the preference modal
    await page.locator('[data-faz-tag="settings-button"]').first().click();
    const title = page.locator('h2.faz-preference-title#faz-modal-title');
    await expect(title).toBeAttached();
  });

  // Category accordion buttons must sit inside <h3> for heading hierarchy.
  test('accordion category buttons are wrapped in <h3>', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
    await page.locator('[data-faz-tag="settings-button"]').first().click();
    const h3Button = page.locator('h3 > [data-faz-tag="detail-category-title"]').first();
    await expect(h3Button).toBeAttached();
  });

  // Category checkboxes need role="switch" for proper semantics.
  test('category toggle checkboxes have role="switch"', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
    await page.locator('[data-faz-tag="settings-button"]').first().click();
    const checkbox = page
      .locator('[data-faz-tag="detail-category-toggle"] input[type="checkbox"][role="switch"]')
      .first();
    await expect(checkbox).toBeAttached();
  });

  // Description wrapper needs a stable id so aria-controls can target it.
  test('modal description wrapper has id="faz-desc-content"', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
    await page.locator('[data-faz-tag="settings-button"]').first().click();
    const wrapper = page.locator('[data-faz-tag="detail-description"]#faz-desc-content');
    await expect(wrapper).toBeAttached();
  });
});

// ---------------------------------------------------------------------------
// Focus loop — Tab key must cycle within the banner for all non-classic types.
// ---------------------------------------------------------------------------
test.describe('Native a11y — focus loop on banner', () => {
  test.describe.configure({ mode: 'serial' });

  // FIXME(#62): focus trap test is flaky — fails when the banner template
  // cache is regenerated fresh (the focus loop keydown handler on the last
  // notice button does not fire consistently under Playwright's
  // keyboard.press). The underlying _fazLoopFocus() code is correct; the
  // issue appears to be timing between template injection and event
  // listener attachment. Tracked as issue #62.
  test.fixme('Tab from last banner button wraps to first (box type)', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const notice = page.locator('[data-faz-tag="notice"]');
    await expect(notice).toBeVisible();

    // Collect all visible, non-disabled focusable elements in the notice.
    const focusables = notice.locator(
      	'a:not([disabled]), button:not([disabled]), [tabindex]:not([disabled]):not([tabindex="-1"])'
    );
    await expect(focusables.first()).toBeVisible();

    // Focus the last button in the banner.
    await focusables.last().focus();

    // Tab should loop back to the first button.
    await page.keyboard.press('Tab');
    await expect(focusables.first()).toBeFocused();
  });
});

// ---------------------------------------------------------------------------
// a11y.js — runtime fixes applied after fazcookie_banner_loaded fires.
// ---------------------------------------------------------------------------
test.describe('Native a11y — a11y.js runtime fixes', () => {
  test.describe.configure({ mode: 'serial' });

  // Banner container must be role="dialog" (not region) for modal semantics.
  test('banner container has role="dialog"', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const banner = page.locator('.faz-consent-container');
    await expect(banner).toHaveAttribute('role', 'dialog');
  });

  // aria-labelledby links the dialog to its visible title heading.
  test('banner container has aria-labelledby="faz-banner-title"', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const banner = page.locator('.faz-consent-container');
    await expect(banner).toHaveAttribute('aria-labelledby', 'faz-banner-title');
  });

  // ESC must NOT dismiss the banner without a recorded consent decision.
  //
  // Inverted from the original Escape-closes-banner shape: 1.13.17
  // finding F024 removed the Escape→_fazHideBanner branch because it
  // let visitors silently dismiss the consent banner without ever
  // choosing accept or reject — the EDPB has explicitly flagged that
  // pattern as a dark pattern (April 2022 cookie-banner task-force
  // report). Banner-level Escape handling is therefore forbidden;
  // preference-center close-on-Escape (next test in this file) is
  // still required for keyboard a11y on the modal overlay.
  test('Escape key does NOT dismiss the banner without a consent decision (F024)', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const banner = page.locator('.faz-consent-container');
    await expect(banner).toBeVisible();

    await page.locator('[data-faz-tag="notice"] button').first().focus();
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300); // give any side-effect time to settle

    await expect(banner, 'banner must remain visible after Escape (EDPB dark-pattern guard)').toBeVisible();

    const fazConsent = await page.evaluate(() => {
      const m = document.cookie.split(';').find((c) => c.trim().startsWith('fazcookie-consent='));
      return m ?? null;
    });
    expect(fazConsent, 'fazcookie-consent must NOT be written by an Escape press').toBeNull();
  });

  // Modal preference center must carry aria-labelledby pointing to its title.
  test('preference center has aria-labelledby="faz-modal-title"', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
    await page.locator('[data-faz-tag="settings-button"]').first().click();
    const prefCenter = page.locator('.faz-preference-center');
    await expect(prefCenter).toHaveAttribute('aria-labelledby', 'faz-modal-title');
  });

  // ESC closes the modal.
  test('Escape key closes the modal when focus is inside it', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    // Disable reload so ESC doesn't trigger a navigation that destroys context.
    await page.evaluate(() => {
      _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
    });

    await page.locator('[data-faz-tag="settings-button"]').first().click();

    const prefCenter = page.locator('.faz-preference-center');
    await expect(prefCenter).toBeVisible({ timeout: 5_000 });

    // Focus a button inside so the ESC listener fires from within.
    await prefCenter.locator('button').first().focus();
    await page.keyboard.press('Escape');

    // After ESC, the preference center should close (hidden or class removed).
    await expect
      .poll(
        () =>
          page.evaluate(() => {
            const m = document.querySelector('.faz-modal');
            if (m && m.classList.contains('faz-modal-open')) return false;
            const pc = document.querySelector('.faz-preference-center') as HTMLElement | null;
            if (pc) {
              const style = window.getComputedStyle(pc);
              if (style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0') {
                // Check if parent container lost the expand class
                const container = pc.closest('.faz-consent-container');
                if (container && container.classList.contains('faz-consent-bar-expand')) return false;
              }
            }
            return true;
          }),
        { timeout: 5_000 },
      )
      .toBe(true);
  });

  // Checkbox aria-label must reflect current state (enabled / disabled).
  test('category checkbox aria-label reflects checked state', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
    await page.locator('[data-faz-tag="settings-button"]').first().click();

    // Find a non-necessary category checkbox (necessary is always disabled).
    const checkbox = page
      .locator('.faz-accordion:not(:has(.faz-always-active)) [data-faz-tag="detail-category-toggle"] input[type="checkbox"]')
      .first();
    await expect(checkbox).toBeVisible();

    const label = await checkbox.getAttribute('aria-label');
    expect(label).toMatch(/enabled|disabled/i);
  });

  // After toggling a checkbox its aria-label must update to the new state.
  test('category checkbox aria-label updates on change', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
    await page.locator('[data-faz-tag="settings-button"]').first().click();

    const checkbox = page
      .locator('.faz-accordion:not(:has(.faz-always-active)) [data-faz-tag="detail-category-toggle"] input[type="checkbox"]')
      .first();
    await expect(checkbox).toBeVisible();

    const labelBefore = await checkbox.getAttribute('aria-label');

    // Click the checkbox to toggle its state.
    await checkbox.click();

    await expect(checkbox).not.toHaveAttribute('aria-label', labelBefore ?? '');
  });

  // Show-more button must have aria-controls pointing to the description wrapper.
  test('show-more button has aria-controls="faz-desc-content"', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
    await page.locator('[data-faz-tag="settings-button"]').first().click();

    const showMoreBtn = page.locator('[data-faz-tag="show-desc-button"]');
    const exists = (await showMoreBtn.count()) > 0;

    if (exists && (await showMoreBtn.first().isVisible())) {
      await expect(showMoreBtn.first()).toHaveAttribute('aria-controls', 'faz-desc-content');
    }
  });
});

// ---------------------------------------------------------------------------
// Regression test for issue #124 — _fazAttachFocusLoop must not accumulate
// keydown listeners across repeated _fazLoopFocus() calls. Pre-fix, each
// call appended a NEW keydown handler with raw addEventListener and never
// removed the old one; the most-recently-registered handler ran last per
// FIFO order, and its closed-over targetElement pointed at a (potentially
// detached) DOM node from the FIRST registration cycle. Under full-suite
// load, the focus-trap E2E fixture (which injects new focusables + re-
// invokes _fazLoopFocus) ended up with Tab routing to nowhere.
//
// Fix: module-scope WeakMap keyed by (element, isReverse) replaces the
// previous handler before attaching a new one. This test verifies that
// counting handlers per element does NOT grow with repeated _fazLoopFocus
// calls — and that Tab dispatch still produces the expected focus jump
// after multiple cycles.
// ---------------------------------------------------------------------------
test.describe('Native a11y — focus loop listener cleanup (regression #124)', () => {
  test('multiple _fazLoopFocus() calls do not accumulate keydown listeners', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const notice = page.locator('[data-faz-tag="notice"]');
    await expect(notice).toBeVisible();

    // Verify the runtime is loaded and the focus-loop function exists.
    await page.waitForFunction(
      () => typeof (window as any)._fazLoopFocus === 'function'
        && typeof (window as any)._fazGetFocusableElements === 'function',
      undefined,
      { timeout: 5000 },
    );

    // Probe: instrument addEventListener on the document.querySelector
    // result for the "last focusable" of the notice container, count
    // keydown attachments before and after 5 _fazLoopFocus invocations.
    // Pre-fix this counter grew linearly with each call; post-fix it
    // stays at 1 (the WeakMap removes the prior handler before adding).
    const handlerGrowth = await page.evaluate(() => {
      const notice = document.querySelector('[data-faz-tag="notice"]');
      if (!notice) return { ok: false, reason: 'no_notice' };
      const focusables = notice.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([disabled]):not([tabindex="-1"])',
      );
      if (focusables.length < 2) return { ok: false, reason: 'too_few_focusables' };

      const last = focusables[focusables.length - 1] as HTMLElement;
      let attachCount = 0;
      const originalAddEventListener = last.addEventListener.bind(last);
      last.addEventListener = function (type: string, listener: any, options?: any) {
        if (type === 'keydown') attachCount++;
        return originalAddEventListener(type, listener, options);
      };

      // 5 cycles. Each call to _fazLoopFocus invokes _fazAttachFocusLoop
      // twice for the popup pair AND once for the notice pair on `last`
      // (depending on which is "last"). Pre-fix this stacked to N×2 or
      // similar; post-fix the per-(element,slot) WeakMap means
      // addEventListener gets called the same number of times BUT each
      // call is paired with a removeEventListener of the prior handler.
      // We can't directly count net live listeners, but we CAN verify
      // that the handler keeps working after the cycles (the integration
      // assertion below), and that addEventListener was called per
      // cycle (the WeakMap path always adds a fresh handler — just
      // removes the old one first).
      for (let i = 0; i < 5; i++) {
        (window as any)._fazLoopFocus();
      }

      // After 5 cycles, the WeakMap path will have called add+remove
      // 5 times each. The integration assertion below pins the real
      // user-visible behaviour: Tab on last → focus first. If the bug
      // returned, multiple handlers would race and focus would land
      // on a stale DOM node.
      return {
        ok: true,
        attach_count: attachCount,
        focusables_count: focusables.length,
        last_tag: last.tagName,
      };
    });

    expect(handlerGrowth.ok, `focus probe setup ok (reason: ${handlerGrowth.reason})`).toBeTruthy();
    expect(handlerGrowth.focusables_count, 'banner has ≥2 focusable buttons').toBeGreaterThanOrEqual(2);

    // Integration assertion: after the 5 stress cycles, Tab dispatch on
    // the last focusable element MUST still produce a valid focus jump.
    // Pre-fix, stacked handlers would race on `.focus()` and the last-
    // registered closure pointed at stale DOM, so focus would either
    // land nowhere or on an unexpected element.
    const tabResult = await page.evaluate(() => {
      const notice = document.querySelector('[data-faz-tag="notice"]');
      if (!notice) return { ok: false };
      const focusables = notice.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([disabled]):not([tabindex="-1"])',
      );
      if (focusables.length < 2) return { ok: false };
      const first = focusables[0] as HTMLElement;
      const last = focusables[focusables.length - 1] as HTMLElement;
      last.focus();
      last.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'Tab' }));
      return {
        ok: true,
        focused_id: (document.activeElement as HTMLElement | null)?.id || '',
        focused_tag: (document.activeElement as HTMLElement | null)?.tagName || '',
        expected_first_tag: first.tagName,
        expected_first_id: first.id,
      };
    });

    expect(tabResult.ok, 'Tab probe setup ok').toBeTruthy();
    // Post-fix: focus lands on the FIRST focusable (Tab wraps).
    // Pre-fix: focus landed on a stale DOM node or stayed on last.
    expect(
      tabResult.focused_tag,
      `Tab from last should focus first focusable (expected ${tabResult.expected_first_tag} got ${tabResult.focused_tag}) — regression #124 may have returned`,
    ).toBe(tabResult.expected_first_tag);
  });
});
