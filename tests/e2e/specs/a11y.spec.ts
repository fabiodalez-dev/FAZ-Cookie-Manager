// tests/e2e/specs/a11y.spec.ts
import { expect, test } from '../fixtures/wp-fixture';
import { deleteOption } from '../utils/wp-env';

// ---------------------------------------------------------------------------
// PHP template fixes — verified by checking the rendered DOM directly.
// deleteOption clears the cached template so prepare_html() re-runs with our
// new A11y_Template::apply() code on the next page request.
// ---------------------------------------------------------------------------
test.describe('Native a11y — PHP template fixes', () => {
  test.beforeAll(() => {
    // Force template rebuild so A11y_Template::apply() changes take effect.
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
    // Open the preference modal
    await page.locator('[data-faz-tag="settings-button"]').first().click();
    const title = page.locator('h2.faz-preference-title#faz-modal-title');
    await expect(title).toBeAttached();
  });

  // Category accordion buttons must sit inside <h3> for heading hierarchy.
  test('accordion category buttons are wrapped in <h3>', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.locator('[data-faz-tag="settings-button"]').first().click();
    const h3Button = page.locator('h3 > [data-faz-tag="detail-category-title"]').first();
    await expect(h3Button).toBeAttached();
  });

  // Category checkboxes need role="switch" for proper semantics.
  test('category toggle checkboxes have role="switch"', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.locator('[data-faz-tag="settings-button"]').first().click();
    const checkbox = page
      .locator('[data-faz-tag="detail-category-toggle"] input[type="checkbox"][role="switch"]')
      .first();
    await expect(checkbox).toBeAttached();
  });

  // Description wrapper needs a stable id so aria-controls can target it.
  test('modal description wrapper has id="faz-desc-content"', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.locator('[data-faz-tag="settings-button"]').first().click();
    const wrapper = page.locator('[data-faz-tag="detail-description"]#faz-desc-content');
    await expect(wrapper).toBeAttached();
  });
});
