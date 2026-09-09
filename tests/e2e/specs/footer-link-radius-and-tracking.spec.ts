import type { Page } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { upsertPage, wpEval } from '../utils/wp-env';

const slug = 'faz-e2e-footer-consent-link';

/** Save the banner and wait for the real REST round-trip, not a toast. */
async function saveBanner(page: Page) {
  const responsePromise = page.waitForResponse(
    (r) =>
      r.url().includes('banners') &&
      !r.url().includes('preview') &&
      (r.request().method() === 'PUT' || r.request().method() === 'POST'),
    { timeout: 30_000 },
  );
  await page.click('#faz-b-save');
  const response = await responsePromise;
  if (response.status() !== 200) {
    throw new Error(`Banner save failed (${response.status()}): ${await response.text()}`);
  }
  await page.waitForSelector('.faz-toast-success', { state: 'visible', timeout: 10_000 }).catch(() => {});
}


test('footer shortcode opens preferences using keyboard navigation', async ({ page }) => {
  upsertPage(slug, 'Footer consent link', '[faz_cookie_settings type="link" text="Cookie preferences"]');
  const url = wpEval(`$p = get_page_by_path('${slug}'); echo get_permalink($p->ID);`).trim();
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    const accept = page.locator('[data-faz-tag="accept-button"]:visible').first();
    await expect(accept).toBeVisible();
    await accept.click();
    const link = page.locator('a.faz-cookie-settings-link');
    await expect(link).toHaveText('Cookie preferences');
    await expect(link).not.toHaveClass(/faz-cookie-settings-btn/);
    await link.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-faz-tag="detail"]')).toBeVisible();
    await expect(link).toHaveAttribute('aria-haspopup', 'dialog');
  } finally {
    wpEval(`$p = get_page_by_path('${slug}'); if ($p) wp_delete_post($p->ID, true);`);
  }
});

test('disabled pageview tracking displays unavailable metrics', async ({ page, loginAsAdmin }) => {
  const saved = wpEval('$s = get_option("faz_settings", array()); echo wp_json_encode(array("exists" => array_key_exists("pageview_tracking", $s), "value" => $s["pageview_tracking"] ?? null));');
  try {
    wpEval('$s = get_option("faz_settings", array()); $s["pageview_tracking"] = false; update_option("faz_settings", $s);');
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#faz-dashboard')).toHaveAttribute('data-pageview-tracking', '0');
    for (const stat of ['pageviews', 'banner', 'accept', 'reject']) {
      await expect(page.locator('#faz-stat-' + stat)).toHaveText('--');
    }
    await expect(page.locator('#faz-chart-empty')).toBeVisible();
    await expect(page.locator('#faz-chart-empty')).toContainText('Pageview tracking is disabled');
    await page.locator('.faz-chart-filter-btn[data-days="30"]').click();
    await expect(page.locator('#faz-stat-pageviews')).toHaveText('--');
  } finally {
    const encoded = Buffer.from(saved).toString('base64');
    wpEval(`$b = json_decode(base64_decode('${encoded}'), true); $s = get_option('faz_settings', array()); if ($b['exists']) { $s['pageview_tracking'] = $b['value']; } else { unset($s['pageview_tracking']); } update_option('faz_settings', $s);`);
  }
});

test('button corner radius set in the admin reaches the rendered banner (#191)', async ({ page, loginAsAdmin }) => {
  // Drive the whole chain the way an administrator does — form field, save,
  // template regeneration — rather than writing the option directly: the
  // banner HTML is cached in faz_banner_template, so a DB-level change would
  // prove nothing about what a visitor actually receives.
  await loginAsAdmin(page);
  await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-banner', { waitUntil: 'domcontentloaded' });
  await page.click('button.faz-tab[data-tab="colours"]');
  const field = page.locator('#faz-b-border-radius');
  await expect(field).toBeVisible();
  const previous = await field.inputValue();
  try {
    await field.fill('14px');
    await saveBanner(page);

    const visitor = await page.context().browser()!.newContext();
    try {
      const front = await visitor.newPage();
      await front.goto('/', { waitUntil: 'domcontentloaded' });
      const accept = front.locator('[data-faz-tag="accept-button"]:visible').first();
      await expect(accept).toBeVisible();
      await expect
        .poll(() => accept.evaluate((el) => getComputedStyle(el).borderRadius))
        .toBe('14px');
    } finally {
      await visitor.close();
    }
  } finally {
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-banner', { waitUntil: 'domcontentloaded' });
    await page.click('button.faz-tab[data-tab="colours"]');
    await page.locator('#faz-b-border-radius').fill(previous);
    await saveBanner(page);
  }
});
