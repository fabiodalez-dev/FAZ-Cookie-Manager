// tests/e2e/specs/a11y.spec.ts
import { expect, test } from '../fixtures/wp-fixture';
import { deleteOption, setOption } from '../utils/wp-env';

// ---------------------------------------------------------------------------
// PHP template fixes — verified by checking the rendered DOM directly.
// deleteOption clears the cached template so prepare_html() re-runs with our
// new A11y_Template::apply() code on the next page request.
// ---------------------------------------------------------------------------
test.describe('Native a11y — PHP template fixes', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(() => {
    // Synchronous WP-CLI call — completes before the first test runs.
    deleteOption('faz_banner_template');
  });

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

  test.beforeAll(() => {
    // Synchronous WP-CLI call — completes before the first test runs.
    setOption('faz_banner_type', 'box');
  });

  test.afterAll(() => {
    deleteOption('faz_banner_type');
  });

  // For box-type banners the original _fazLoopFocus() only attached the loop
  // for popup type. After the fix it must also apply to box type.
  test('Tab from last banner button wraps to first (box type)', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const notice = page.locator('[data-faz-tag="notice"]');
    await expect(notice).toBeVisible();

    // Collect all visible, non-disabled focusable elements in the notice.
    const buttons = notice.locator('button:not([disabled])');
    await expect(buttons.first()).toBeVisible();

    // Focus the last button in the banner.
    await buttons.last().focus();

    // Tab should loop back to the first button.
    await page.keyboard.press('Tab');
    await expect(buttons.first()).toBeFocused();
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

  // ESC closes the banner without requiring a mouse click.
  test('Escape key closes the banner when focus is inside it', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const banner = page.locator('.faz-consent-container');
    await expect(banner).toBeVisible();

    // Focus a button inside the notice element (child of .faz-consent-container).
    await page.locator('[data-faz-tag="notice"] button').first().focus();
    await page.keyboard.press('Escape');

    await expect(banner).toBeHidden({ timeout: 5_000 }); // tighter than the 10 s global — ESC should close immediately
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
    await page.locator('[data-faz-tag="settings-button"]').first().click();
    const modal = page.locator('.faz-modal');
    await expect(modal).toHaveClass(/faz-modal-open/);

    // Focus any button inside the modal so the ESC listener fires.
    await page.locator('.faz-modal button').first().focus();
    await page.keyboard.press('Escape');

    await expect(modal).not.toHaveClass(/faz-modal-open/, { timeout: 5_000 }); // tighter than the 10 s global — ESC should close immediately
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
    await expect(showMoreBtn).toHaveAttribute('aria-controls', 'faz-desc-content');
  });
});
