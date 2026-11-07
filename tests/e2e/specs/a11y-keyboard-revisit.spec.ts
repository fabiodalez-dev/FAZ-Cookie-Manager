// tests/e2e/specs/a11y-keyboard-revisit.spec.ts
//
// Keyboard-only round trip through the consent UI, for every banner layout.
//
// Reported on wordpress.org ("accessibility-97"): a keyboard-only visitor could
// not reach the floating cookie icon (the revisit widget) and, once a consent
// dialog opened, focus was not moved into it. What I measured on 1.37.0 before
// fixing it:
//   - After a decision taken from the banner, focus fell back to <body> while
//     the browser's sequential-focus starting point stayed on the hidden
//     button, AFTER the widget in DOM order: the next Tab went into the page and
//     the widget was only reachable by tabbing through the whole document.
//   - Activating the widget hid it (it is the focused element) and showed the
//     banner without moving focus, so focus was lost on <body>.
//   - The widget's only focus indicator was the UA ring on the 30px inner
//     button, blue on the blue disc: practically invisible.
//
// Each layout below runs the same journey with the keyboard only.
import { expect, test } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { wpEval } from '../utils/wp-env';

type Layout = {
  name: string;
  type: string;
  ptype: string;
  position: string;
  pushdown: boolean;
};

const LAYOUTS: Layout[] = [
  { name: 'box + popup', type: 'box', ptype: 'popup', position: 'bottom-left', pushdown: false },
  { name: 'box + sidebar', type: 'box', ptype: 'sidebar', position: 'bottom-left', pushdown: false },
  { name: 'banner + popup', type: 'banner', ptype: 'popup', position: 'bottom', pushdown: false },
  { name: 'banner + sidebar', type: 'banner', ptype: 'sidebar', position: 'bottom', pushdown: false },
  { name: 'banner + pushdown', type: 'banner', ptype: 'pushdown', position: 'bottom', pushdown: true },
  { name: 'classic (pushdown)', type: 'classic', ptype: 'pushdown', position: 'bottom', pushdown: true },
];

function configureLayout(layout: Layout): void {
  wpEval(`
    global $wpdb;
    $id = (int) $wpdb->get_var( "SELECT banner_id FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 ORDER BY banner_id ASC LIMIT 1" );
    $banner = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( $id );
    $s = $banner->get_settings();
    $s['settings']['type'] = '${layout.type}';
    $s['settings']['preferenceCenterType'] = '${layout.ptype}';
    $s['settings']['position'] = '${layout.position}';
    $s['config']['revisitConsent']['status'] = true;
    $s['config']['revisitConsent']['position'] = 'bottom-left';
    $s['behaviours']['reloadBannerOnAccept']['status'] = false;
    $banner->set_settings( $s );
    $banner->save();
    delete_option( 'faz_banner_template' );
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
  `);
}

/** Describe the focused element: which consent surface (if any) holds it. */
function focusInfo(page: Page) {
  return page.evaluate(() => {
    const a = document.activeElement as HTMLElement | null;
    if (!a || a === document.body || a === document.documentElement) {
      return { where: 'body', tag: '', visible: false };
    }
    const tagged = a.closest('[data-faz-tag]');
    let where = 'page';
    if (a.closest('[data-faz-tag="revisit-consent"]')) where = 'revisit';
    else if (a.closest('[data-faz-tag="detail"], [data-faz-tag="optout-popup"]')) where = 'preference';
    else if (a.closest('.faz-consent-container')) where = 'banner';
    return {
      where,
      tag: (a.getAttribute('data-faz-tag') || (tagged && tagged.getAttribute('data-faz-tag')) || ''),
      visible: a.getClientRects().length > 0,
    };
  });
}

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([disabled]):not([tabindex="-1"])';

/**
 * Focus the first or last control under `rootSelector` that is actually on
 * screen (a layout box and not visibility:hidden), and return its tag/name, or
 * '' when nothing could be focused.
 */
function focusRendered(page: Page, rootSelector: string, which: 'first' | 'last') {
  return page.evaluate(([sel, edge, focusable]) => {
    const root = document.querySelector(sel);
    if (!root) return '';
    const rendered = (Array.from(root.querySelectorAll(focusable)) as HTMLElement[]).filter((el) =>
      el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden');
    const target = edge === 'first' ? rendered[0] : rendered[rendered.length - 1];
    if (!target) return '';
    target.focus();
    return document.activeElement === target
      ? (target.getAttribute('data-faz-tag') || target.id || target.tagName.toLowerCase())
      : '';
  }, [rootSelector, which, FOCUSABLE] as const);
}

/** Wait briefly for focus to reach `where`; return where it ended up. */
async function settleFocus(page: Page, where: string, timeoutMs = 2_000): Promise<string> {
  const deadline = Date.now() + timeoutMs;
  let current = (await focusInfo(page)).where;
  while (current !== where && Date.now() < deadline) {
    await page.waitForTimeout(100);
    current = (await focusInfo(page)).where;
  }
  return current;
}

async function tabTo(page: Page, where: string, max = 8): Promise<boolean> {
  for (let i = 0; i < max; i++) {
    await page.keyboard.press('Tab');
    if ((await focusInfo(page)).where === where) return true;
  }
  return false;
}

test.describe('Keyboard access to the revisit widget and the preference centre (wp.org accessibility-97)', () => {
  test.beforeAll(() => {
    resetDefaultBannerState();
  });

  test.afterAll(() => {
    resetDefaultBannerState();
  });

  for (const layout of LAYOUTS) {
    test(`${layout.name}: keyboard-only decide → revisit → preferences → back`, async ({ browser, wpBaseURL }) => {
      configureLayout(layout);
      const context = await browser.newContext({ baseURL: wpBaseURL });
      const page = await context.newPage();
      try {
        // ── First visit: the decision taken with the keyboard ──────────────
        await page.goto('/', { waitUntil: 'domcontentloaded' });
        const accept = page.locator('[data-faz-tag="notice"] [data-faz-tag="accept-button"]').first();
        await expect(accept).toBeVisible({ timeout: 15_000 });
        await accept.focus();
        await page.keyboard.press('Enter');
        await expect(page.locator('.faz-consent-container')).toBeHidden();

        // The banner the visitor was operating is gone; focus must land on the
        // control that replaces it, not on <body> behind the hidden button.
        const revisitWrapper = page.locator('[data-faz-tag="revisit-consent"]');
        const revisitButton = revisitWrapper.locator('button');
        await expect(revisitButton).toBeVisible();
        await expect.soft(revisitButton, 'focus moves to the revisit widget after a decision').toBeFocused();

        // ── A later page view: reach the widget with Tab ───────────────────
        await page.goto('/', { waitUntil: 'domcontentloaded' });
        await expect(revisitButton).toBeVisible({ timeout: 15_000 });
        expect(await tabTo(page, 'revisit'), 'Tab reaches the revisit widget').toBe(true);
        await expect(revisitButton).toHaveAccessibleName(/.+/);
        // The closed consent UI holds no tab stops: the next Tab leaves the
        // widget for the page itself, not for invisible dialog controls (the
        // closed sidebar used to sit off-screen with every control focusable).
        await page.keyboard.press('Tab');
        const afterWidget = await focusInfo(page);
        expect.soft(afterWidget.where, `Tab after the widget reaches the page (got ${afterWidget.where}/${afterWidget.tag})`).toBe('page');
        await page.keyboard.press('Shift+Tab');
        await expect(revisitButton).toBeFocused();

        // A focus ring that stands out from the coloured disc: drawn on the
        // wrapper (outside the disc), not only the UA ring on the inner button.
        const ring = await revisitWrapper.evaluate((el) => {
          const s = getComputedStyle(el);
          return { style: s.outlineStyle, width: parseFloat(s.outlineWidth) };
        });
        expect.soft(ring.style, 'revisit widget shows a focus indicator').not.toBe('none');
        expect.soft(ring.width, 'revisit focus indicator is at least 2px').toBeGreaterThanOrEqual(2);

        // ── Enter opens the consent dialog and focus goes into it ─────────
        await page.keyboard.press('Enter');
        await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
        expect.soft(await settleFocus(page, 'banner'), 'focus moves into the reopened banner').toBe('banner');

        // Tab stays inside the banner dialog.
        for (let i = 0; i < 12; i++) {
          await page.keyboard.press('Tab');
          const info = await focusInfo(page);
          expect.soft(`${info.where}/${info.visible}`, `Tab #${i + 1} stays on a visible control in the banner`).toBe('banner/true');
          if (info.where !== 'banner' || !info.visible) break;
        }

        // ── Open the preference centre with the keyboard ──────────────────
        const settings = page.locator('[data-faz-tag="notice"] [data-faz-tag="settings-button"]').first();
        await settings.focus();
        await page.keyboard.press('Enter');
        await expect(page.locator('[data-faz-tag="detail"]').first()).toBeVisible();
        expect.soft(await settleFocus(page, 'preference'), 'focus moves into the preference centre').toBe('preference');

        // Tab and Shift+Tab stay inside the open preference centre (the
        // pushdown panel is part of the banner dialog, so either surface
        // counts there).
        const allowed = layout.pushdown ? ['preference', 'banner'] : ['preference'];
        for (const key of ['Tab', 'Shift+Tab']) {
          for (let i = 0; i < 40; i++) {
            await page.keyboard.press(key);
            const info = await focusInfo(page);
            const ok = allowed.includes(info.where) && info.visible;
            expect.soft(ok, `${key} #${i + 1} stays on a visible control in the preference centre (got ${info.where}/${info.tag}, visible=${info.visible})`).toBe(true);
            if (!ok) break;
          }
        }

        // ── Pushdown: the expanded panel is reachable and holds Tab ──────
        // The loop above accepts either surface, because the expanded panel is
        // part of the banner dialog; it cannot tell a panel that holds focus
        // from one Tab walks straight out of. These two checks can. The panel
        // sits in the banner AFTER the notice, so the notice must let Tab
        // through to it instead of wrapping to its own first button; and once
        // in the panel, Tab past its last displayed control must wrap inside
        // the panel. The edge handler is bound to the last control in SOURCE
        // order, which the pushdown layout does not display, so it never ran
        // and Tab left the panel for the page.
        if (layout.pushdown) {
          const panel = '.faz-consent-container [data-faz-tag="detail"]';
          const lastInNotice = await focusRendered(page, '[data-faz-tag="notice"]', 'last');
          expect(lastInNotice, 'the notice has a displayed control to start from').not.toBe('');
          await page.keyboard.press('Tab');
          const fromNotice = await focusInfo(page);
          expect.soft(fromNotice.where, `Tab from the notice's last control enters the expanded panel (got ${fromNotice.where}/${fromNotice.tag})`).toBe('preference');

          for (const [key, edge] of [['Tab', 'last'], ['Shift+Tab', 'first']] as const) {
            const start = await focusRendered(page, panel, edge);
            expect(start, `the panel has a displayed ${edge} control`).not.toBe('');
            for (let i = 0; i < 40; i++) {
              await page.keyboard.press(key);
              const info = await focusInfo(page);
              const ok = info.where === 'preference' && info.visible;
              expect.soft(ok, `${key} #${i + 1} from the panel's ${edge} control stays in the panel (got ${info.where}/${info.tag}, visible=${info.visible})`).toBe(true);
              if (!ok) break;
            }
          }
          // Leave focus inside the panel, where the Escape step below expects
          // a visitor to press it.
          await focusRendered(page, panel, 'first');
        }

        // ── Escape closes it and returns focus to the opener ──────────────
        await page.keyboard.press('Escape');
        await expect
          .poll(() => page.evaluate(() => {
            const m = document.querySelector('.faz-modal');
            const c = document.querySelector('.faz-consent-container');
            return !(m && m.classList.contains('faz-modal-open')) && !(c && c.classList.contains('faz-consent-bar-expand'));
          }), { message: 'Escape closes the preference centre' })
          .toBe(true);
        // Back to the opener. A returning visitor's popup/sidebar closes onto
        // a banner that stays hidden (their decision is already recorded), so
        // the Customise button is gone and the widget takes focus instead; the
        // pushdown panel collapses back into the banner, which stays open.
        if (await settings.isVisible()) {
          await expect.soft(settings, 'focus returns to the Customise button').toBeFocused();
        } else {
          await expect.soft(revisitButton, 'focus returns to the revisit widget when the Customise button is gone').toBeFocused();
          // Reopen the banner from the widget, with the keyboard.
          await page.keyboard.press('Enter');
          await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
          expect.soft(await settleFocus(page, 'banner'), 'focus moves into the reopened banner (second time)').toBe('banner');
        }

        // ── A second decision returns focus to the widget ─────────────────
        const accept2 = page.locator('[data-faz-tag="notice"] [data-faz-tag="accept-button"]').first();
        await accept2.focus();
        await page.keyboard.press('Enter');
        await expect(page.locator('.faz-consent-container')).toBeHidden();
        await expect.soft(revisitButton, 'focus returns to the revisit widget').toBeFocused();
      } finally {
        await context.close();
      }
    });
  }
});
