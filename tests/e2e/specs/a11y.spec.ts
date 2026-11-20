// tests/e2e/specs/a11y.spec.ts
import { expect, test } from '../fixtures/wp-fixture';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { wpEval } from '../utils/wp-env';

declare function _fazLoopFocus(): void;

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

  // #62. This was disabled as "flaky, the underlying code is correct". It was
  // neither: the trap was never armed at banner render. _fazLoopFocus() ran only
  // from the preference-centre path, so a visitor who never opened preferences
  // could Tab straight out of a role="dialog". Verified by hand before fixing —
  // attaching the same handler manually made Tab wrap immediately, which is what
  // ruled out the timing explanation.
  test('Tab from last banner button wraps to first (box type)', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const notice = page.locator('[data-faz-tag="notice"]');
    await expect(notice).toBeVisible();

    // Collect all visible, non-disabled focusable elements in the notice.
    const focusables = notice.locator(
      'a[href], button, [tabindex]'
    ).and(notice.locator(':visible:not(:disabled):not([tabindex="-1"])'));
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

    const accept = banner.locator('[data-faz-tag="accept-button"]');
    await accept.focus();
    await expect(accept).toBeFocused();
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
    const prefCenter = page.locator('[data-faz-tag="detail"]');
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

    const prefCenter = page.locator('[data-faz-tag="detail"]');
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

// Re-initializing a surface keeps one listener and reads the current
// controls when Tab is pressed (regression #124).
test.describe('Native a11y — focus loop listener cleanup (regression #124)', () => {
  test('multiple _fazLoopFocus() calls do not accumulate keydown listeners', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const notice = page.locator('[data-faz-tag="notice"]');
    await expect(notice).toBeVisible();
    await page.waitForFunction(() => typeof _fazLoopFocus === 'function');

    const added = await notice.evaluate((element) => {
      const surface = element.closest('.faz-consent-container');
      if (!surface) throw new Error('Missing consent surface');
      const originalAdd = surface.addEventListener;
      let keydownListeners = 0;
      surface.addEventListener = function (
        type: string,
        listener: EventListenerOrEventListenerObject | null,
        options?: boolean | AddEventListenerOptions,
      ) {
        if (type === 'keydown') keydownListeners++;
        originalAdd.call(this, type, listener, options);
      };

      try {
        for (let i = 0; i < 5; i++) _fazLoopFocus();
      } finally {
        surface.addEventListener = originalAdd;
      }

      const first = document.createElement('button');
      first.id = 'faz-test-first-tab-stop';
      first.textContent = 'First test control';
      const last = document.createElement('button');
      last.id = 'faz-test-last-tab-stop';
      last.textContent = 'Last test control';
      const skipped = document.createElement('button');
      skipped.tabIndex = -1;
      skipped.textContent = 'Programmatic focus only';
      surface.prepend(first);
      surface.append(last, skipped);
      return keydownListeners;
    });
    expect(added, 'reinitialization adds no listeners to the consent surface').toBe(0);

    const first = page.locator('#faz-test-first-tab-stop');
    const last = page.locator('#faz-test-last-tab-stop');
    await last.focus();
    await page.keyboard.press('Tab');
    await expect(first).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(last).toBeFocused();
  });
});

test.describe('Native a11y — upstream parity (CookieYes 3.5.x)', () => {
  test('banner headings are level 2 in the SERVED html, before a11y.js promotes them', async ({ page, context, wpBaseURL }) => {
    await context.clearCookies();
    const url = `${wpBaseURL}/?a11y-level=${Date.now()}`;
    const res = await page.request.get(url);
    const html = await res.text();

    // Asserted on the server response, not the live DOM, and the difference is
    // the point: a11y.js replaces these nodes with real <h2> elements and strips
    // role/aria-level as it goes (frontend/js/a11y.js — replaceTag(..., 'h2',
    // { remove: ['role','aria-level'] })), so by the time the DOM settles there
    // is nothing left to measure. A first draft of this case queried the runtime
    // DOM, found zero of BOTH levels, and would have passed at any aria-level.
    //
    // The markup still matters: it is what a visitor sees in the window before
    // a11y.js runs, and on any page where that file fails to load. A consent
    // banner is a region of someone else's document, so announcing its title at
    // level 1 puts a second top-level heading beside the page's real H1.
    const levelOne = (html.match(/aria-level="1"/g) || []).length;
    const levelTwo = (html.match(/aria-level="2"/g) || []).length;

    expect(levelOne, 'the served banner markup still carries a level-1 heading').toBe(0);
    expect(levelTwo, 'the served banner markup should carry level-2 headings').toBeGreaterThan(0);
  });

  test('the control that opens the preference center points at it with aria-controls', async ({ page, context, wpBaseURL }) => {
    await context.clearCookies();
    await page.goto(`${wpBaseURL}/?a11y-controls=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);

    // Read the relationship BEFORE any click. Checking it afterwards would also
    // pass if the attributes were only attached by the click handler — and the
    // whole point is that a screen-reader user is told what the button does
    // while deciding whether to press it.
    const beforeClick = await page.evaluate(() => {
      const btn = document.querySelector('[data-faz-tag="settings-button"]');
      const controls = btn ? btn.getAttribute('aria-controls') : null;
      const target = controls ? document.getElementById(controls) : null;
      return {
        haspopup: btn ? btn.getAttribute('aria-haspopup') : null,
        controls,
        // The id must name the panel this trigger actually opens, not merely
        // some element: on a "Both" banner the settings button opens `detail`
        // and Do-Not-Sell opens `optout-popup`.
        targetTag: target ? target.getAttribute('data-faz-tag') : null,
      };
    });

    expect(beforeClick.haspopup, 'the trigger should advertise a dialog before it is pressed').toBe('dialog');
    expect(beforeClick.controls, 'the trigger should name its panel before it is pressed').toBeTruthy();
    expect(
      beforeClick.targetTag,
      `aria-controls="${beforeClick.controls}" should resolve to the detail panel`,
    ).toBe('detail');

    // And the relationship must survive the panel actually opening.
    await page.locator('[data-faz-tag="settings-button"]').first().click({ timeout: 10_000 });
    await page.waitForTimeout(600);
    const afterClick = await page.evaluate(() => {
      const btn = document.querySelector('[data-faz-tag="settings-button"]');
      const controls = btn ? btn.getAttribute('aria-controls') : null;
      const target = controls ? document.getElementById(controls) : null;
      return { controls, isDialog: !!target && !!target.closest('[role="dialog"]') };
    });
    expect(afterClick.controls, 'aria-controls should not change on open').toBe(beforeClick.controls);
    expect(afterClick.isDialog, 'the named panel should live inside the dialog once opened').toBe(true);
  });

});
