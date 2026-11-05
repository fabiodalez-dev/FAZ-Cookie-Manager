/**
 * Preference centre Save colours, per-button hover colours, custom revisit image.
 *
 * Asked for on the wp.org forum ("More Control than Colours"). Driven the way an
 * administrator does it (Colours tab, save, template regeneration) and read from
 * the banner a visitor receives. Every field is empty by default, and an empty
 * field has to leave the banner as it was.
 */
import type { Browser, Page } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { resetBaseline } from '../utils/seed-defaults';
import { wpEval } from '../utils/wp-env';

const EDITOR = '/wp-admin/admin.php?page=faz-cookie-manager-banner';
const SAVE = '[data-faz-tag="detail-save-button"]';

const rgb = (hex: string) => {
  const n = parseInt(hex.slice(1), 16);
  return `rgb(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255})`;
};

async function openColours(page: Page) {
  await page.goto(EDITOR, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#faz-b-name')).not.toHaveValue('', { timeout: 30_000 });
  await page.click('button.faz-tab[data-tab="colours"]');
  await expect(page.locator('#faz-b-detail-save-bg-hex')).toBeVisible();
}

async function saveBanner(page: Page) {
  const response = page.waitForResponse(
    (r) => r.url().includes('banners') && !r.url().includes('preview')
      && (r.request().method() === 'PUT' || r.request().method() === 'POST'),
    { timeout: 30_000 },
  );
  await page.click('#faz-b-save');
  const saved = await response;
  if (saved.status() !== 200) throw new Error(`Banner save failed (${saved.status()}): ${await saved.text()}`);
}

async function setFields(page: Page, fields: Record<string, string>) {
  await openColours(page);
  for (const [id, value] of Object.entries(fields)) {
    await page.locator(`#${id}-hex`).fill(value);
    await page.locator(`#${id}-hex`).dispatchEvent('change');
  }
  await saveBanner(page);
}

async function visitor(browser: Browser) {
  const context = await browser.newContext();
  const front = await context.newPage();
  await front.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(front.locator('.faz-consent-container [data-faz-tag="accept-button"]').first()).toBeVisible();
  return { context, front };
}

async function openPreferences(front: Page) {
  await front.locator('[data-faz-tag="settings-button"]').first().click();
  await expect(front.locator(SAVE).first()).toBeVisible();
}

const colours = (front: Page, selector: string) => front.locator(selector).first().evaluate((el) => {
  const s = getComputedStyle(el);
  return { color: s.color, background: s.backgroundColor, border: s.borderTopColor, opacity: s.opacity, transition: s.transitionDuration };
});

/** Moves the pointer onto the button and reads it once the opacity has settled. */
async function hovered(front: Page, selector: string) {
  await front.locator(selector).first().hover();
  await front.waitForTimeout(400);
  return colours(front, selector);
}

// resetBaseline() does not touch the colours, so the row is kept and put back:
// otherwise a colour set here would still be on the banner of the next spec.
const restoreBanner = `
  global $wpdb;
  $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => get_option( 'faz_e2e_colours_snapshot' ) ), array( 'banner_default' => 1 ) );
  // The controller keeps the rows in a cache of its own: left stale, the next
  // reader saves the old colours straight back.
  \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
  delete_option( 'faz_banner_template' );
  if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
`;

test.beforeAll(() => {
  resetBaseline();
  wpEval(`
    global $wpdb;
    update_option( 'faz_e2e_colours_snapshot', $wpdb->get_var( "SELECT settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1" ), false );
  `);
});

test.beforeEach(() => {
  wpEval(restoreBanner);
});

test.afterAll(() => {
  wpEval(`${restoreBanner} delete_option( 'faz_e2e_colours_snapshot' );`);
});

test('with every field empty, Save follows Settings and hover is the 80% opacity', async ({ page, browser, loginAsAdmin }) => {
  await loginAsAdmin(page);
  await setFields(page, { 'faz-b-settings-bg': '#112233', 'faz-b-settings-text': '#ffffff', 'faz-b-settings-border': '#445566' });
  const { context, front } = await visitor(browser);
  try {
    const css = await front.locator('style').evaluateAll((all) => all.map((s) => s.textContent || '').join(''));
    expect(css).not.toContain('detail-save-button-custom');
    expect(css).not.toContain('-hover-background-color');
    expect((await hovered(front, '[data-faz-tag="accept-button"]')).opacity).toBe('0.8');
    await openPreferences(front);
    const save = await colours(front, SAVE);
    expect(save.background).toBe(rgb('#112233'));
    expect(save.color).toBe(rgb('#ffffff'));
    expect(save.border).toBe(rgb('#445566'));
  } finally {
    await context.close();
  }
});

test('Save takes its own colours, and Accept and Reject in the preference centre keep theirs', async ({ page, browser, loginAsAdmin }) => {
  await loginAsAdmin(page);
  await setFields(page, {
    'faz-b-accept-bg': '#0a5c36',
    'faz-b-settings-bg': '#112233',
    'faz-b-detail-save-bg': '#7a1f5c',
    'faz-b-detail-save-text': '#fefefe',
    'faz-b-detail-save-border': '#330022',
  });
  const { context, front } = await visitor(browser);
  try {
    expect((await colours(front, '[data-faz-tag="settings-button"]')).background).toBe(rgb('#112233'));
    await openPreferences(front);
    const save = await colours(front, SAVE);
    expect(save.background).toBe(rgb('#7a1f5c'));
    expect(save.color).toBe(rgb('#fefefe'));
    expect(save.border).toBe(rgb('#330022'));
    expect((await colours(front, '[data-faz-tag="detail-accept-button"]')).background).toBe(rgb('#0a5c36'));
  } finally {
    await context.close();
  }
});

test('a hover colour applies to its button only, at full opacity', async ({ page, browser, loginAsAdmin }) => {
  await loginAsAdmin(page);
  await setFields(page, { 'faz-b-accept-bg': '#0a5c36', 'faz-b-accept-hover-bg': '#222222', 'faz-b-accept-hover-text': '#eeeeee' });
  const { context, front } = await visitor(browser);
  try {
    // Jurisdiction routing gives Reject the style of Accept for equal prominence,
    // and hover has to follow it: a Reject that alone fades is not equal.
    const equalProminence = (await colours(front, '[data-faz-tag="reject-button"]')).background === rgb('#0a5c36');
    const accept = await hovered(front, '[data-faz-tag="accept-button"]');
    expect(accept.background).toBe(rgb('#222222'));
    expect(accept.color).toBe(rgb('#eeeeee'));
    expect(accept.opacity).toBe('1');
    const reject = await hovered(front, '[data-faz-tag="reject-button"]');
    if (equalProminence) {
      expect(reject.background).toBe(rgb('#222222'));
      expect(reject.opacity).toBe('1');
    } else {
      expect(reject.background).not.toBe(rgb('#222222'));
      expect(reject.opacity).toBe('0.8');
    }
    const settings = await hovered(front, '[data-faz-tag="settings-button"]');
    expect(settings.background).not.toBe(rgb('#222222'));
    expect(settings.opacity).toBe('0.8');
  } finally {
    await context.close();
  }
});

test('Save hovers like Settings unless it has colours of its own', async ({ page, browser, loginAsAdmin }) => {
  await loginAsAdmin(page);
  await setFields(page, { 'faz-b-settings-hover-bg': '#333333' });
  let session = await visitor(browser);
  try {
    await openPreferences(session.front);
    expect((await hovered(session.front, SAVE)).background).toBe(rgb('#333333'));
  } finally {
    await session.context.close();
  }

  await setFields(page, { 'faz-b-detail-save-bg': '#7a1f5c' });
  session = await visitor(browser);
  try {
    await openPreferences(session.front);
    const save = await hovered(session.front, SAVE);
    expect(save.background).toBe(rgb('#7a1f5c'));
    expect(save.opacity).toBe('0.8');
  } finally {
    await session.context.close();
  }

  await setFields(page, { 'faz-b-detail-save-hover-bg': '#444444' });
  session = await visitor(browser);
  try {
    await openPreferences(session.front);
    expect((await hovered(session.front, SAVE)).background).toBe(rgb('#444444'));
  } finally {
    await session.context.close();
  }
});

test('a value that is not a colour never reaches the stylesheet', async ({ page, browser, loginAsAdmin }) => {
  await loginAsAdmin(page);
  await setFields(page, { 'faz-b-accept-hover-bg': 'red;}body{display:none}', 'faz-b-detail-save-bg': 'red;}body{display:none}' });
  const { context, front } = await visitor(browser);
  try {
    const css = await front.locator('style').evaluateAll((all) => all.map((s) => s.textContent || '').join(''));
    expect(css).not.toContain('display:none}body');
    expect(css).not.toContain('body{display:none}');
    await expect(front.locator('body')).toBeVisible();
    expect((await hovered(front, '[data-faz-tag="accept-button"]')).opacity).toBe('0.8');
  } finally {
    await context.close();
  }
});

test.describe('revisit widget image', () => {
  const setImage = async (page: Page, url: string) => {
    await openColours(page);
    await page.evaluate((value) => {
      (document.getElementById('faz-b-revisit-image-url') as HTMLInputElement).value = value;
    }, url);
    await page.locator('#faz-b-title-color-hex').dispatchEvent('change');
    await saveBanner(page);
  };

  async function revisit(browser: Browser) {
    const session = await visitor(browser);
    await session.front.locator('[data-faz-tag="reject-button"]').first().click();
    await expect(session.front.locator('[data-faz-tag="revisit-consent"]')).toBeVisible();
    return session;
  }

  test('a custom image is shown, even with "revisit" in its URL, and the default icon comes back when removed', async ({ page, browser, loginAsAdmin }) => {
    await loginAsAdmin(page);
    // The URL deliberately contains "revisit": script.js swaps the default icon
    // by looking for that word in the src.
    const image = `${new URL(page.url()).origin}/wp-content/plugins/faz-cookie-manager/frontend/images/revisit.svg?custom=1`;
    await setImage(page, image);
    let session = await revisit(browser);
    try {
      const img = session.front.locator('[data-faz-tag="revisit-consent"] .faz-btn-revisit img');
      await expect(img).toHaveCount(1);
      await expect(img).toHaveAttribute('src', /custom=1/);
      await expect(img).toHaveAttribute('alt', '');
      await expect(session.front.locator('[data-faz-tag="revisit-consent"] svg')).toHaveCount(0);
      await expect(session.front.locator('[data-faz-tag="revisit-consent"] .faz-btn-revisit')).toHaveAttribute('aria-label', /.+/);
    } finally {
      await session.context.close();
    }

    await openColours(page);
    await expect(page.locator('#faz-b-revisit-image-url')).toHaveValue(image);
    await expect(page.locator('#faz-b-revisit-image-remove')).toBeVisible();

    await setImage(page, '');
    session = await revisit(browser);
    try {
      await expect(session.front.locator('[data-faz-tag="revisit-consent"] .faz-btn-revisit img')).toHaveCount(0);
      await expect(session.front.locator('[data-faz-tag="revisit-consent"] svg')).toHaveCount(1);
    } finally {
      await session.context.close();
    }
  });

  test('a non-http image URL is ignored', async ({ page, browser, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await setImage(page, 'javascript:alert(1)');
    const session = await revisit(browser);
    try {
      await expect(session.front.locator('[data-faz-tag="revisit-consent"] svg')).toHaveCount(1);
      const html = await session.front.content();
      expect(html).not.toContain('javascript:alert(1)');
    } finally {
      await session.context.close();
    }
  });
});
