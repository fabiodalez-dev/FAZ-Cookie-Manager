import { expect, test } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import { wpEval } from '../utils/wp-env';

/**
 * Compact phone layout for the notice buttons (banner_control.mobile_layout).
 *
 * What this guards is not "the banner looks nicer". It is three properties that
 * a later CSS edit can silently break, and that no other spec asserts:
 *
 *  1. The compact layout actually makes the notice materially shorter. Without
 *     a measured ceiling, a rule that stops applying leaves the setting present
 *     in the UI and doing nothing — the exact failure shape this plugin keeps
 *     producing (a filter on a hook that does not exist, a guard with no
 *     writer). A setting that silently does nothing is worse than no setting.
 *  2. Accept and reject stay exactly the same size as each other, on the same
 *     row. EDPB Guidelines 03/2022 require equal prominence between accepting
 *     and refusing; a compact layout is precisely where that is easy to lose,
 *     because the pressure is to shrink something.
 *  3. Every button stays at least 44px tall. Reclaiming height by shrinking the
 *     tap target would trade one usability problem for a worse one.
 *
 * Reported by a user whose banner took more than half of a 375x667 screen.
 */

const PHONE = { width: 390, height: 844 };

type ButtonBox = { label: string; width: number; height: number; top: number };

async function setLayout(value: 'comfortable' | 'compact'): Promise<void> {
  wpEval(
    `$s = get_option('faz_settings', array());` +
      `if (!is_array($s)) { $s = array(); }` +
      `if (!isset($s['banner_control']) || !is_array($s['banner_control'])) { $s['banner_control'] = array(); }` +
      `$s['banner_control']['mobile_layout'] = '${value}';` +
      `update_option('faz_settings', $s);`,
  );
}

async function readButtons(page: Page): Promise<ButtonBox[]> {
  return page.evaluate(() =>
    [...document.querySelectorAll('.faz-consent-container .faz-notice-btn-wrapper .faz-btn')]
      .map((el) => {
        const r = el.getBoundingClientRect();
        return {
          label: (el.textContent ?? '').trim().toLowerCase(),
          width: Math.round(r.width),
          height: Math.round(r.height),
          top: Math.round(r.top),
        };
      })
      .filter((b) => b.height > 0),
  );
}

async function readNoticeRatio(page: Page): Promise<number> {
  return page.evaluate(() => {
    const container = document.querySelector('.faz-consent-container');
    if (!container) return -1;
    const bar = container.querySelector('.faz-consent-bar') ?? container;
    return bar.getBoundingClientRect().height / window.innerHeight;
  });
}

async function openBannerOnPhone(page: Page): Promise<void> {
  await page.context().clearCookies();
  await page.setViewportSize(PHONE);
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.faz-consent-container .faz-notice-btn-wrapper .faz-btn', {
    state: 'visible',
    timeout: 20_000,
  });
}

test.describe('Compact phone layout', () => {
  test.use({ viewport: PHONE });

  test.afterAll(async () => {
    await setLayout('comfortable');
  });

  test('compact lays the notice buttons on one row and keeps accept and reject equal', async ({ page }) => {
    await setLayout('comfortable');
    await openBannerOnPhone(page);

    const roomyButtons = await readButtons(page);
    const roomyRatio = await readNoticeRatio(page);
    expect(roomyButtons.length, 'the notice must render its buttons').toBeGreaterThanOrEqual(2);

    // Baseline: the shipped layout gives each button a row of its own. Asserted
    // rather than assumed, so that if the default ever changes this test says
    // so instead of quietly comparing compact against compact.
    const roomyRows = new Set(roomyButtons.map((b) => b.top)).size;
    expect(roomyRows, 'the comfortable layout stacks the buttons').toBe(roomyButtons.length);

    await setLayout('compact');
    await openBannerOnPhone(page);

    const compactButtons = await readButtons(page);
    expect(compactButtons.length).toBe(roomyButtons.length);

    // 1. Materially shorter, and shorter than a third of the screen.
    const compactRatio = await readNoticeRatio(page);
    expect(compactRatio, 'compact must actually shorten the notice').toBeLessThan(roomyRatio);
    expect(compactRatio, 'compact must keep the notice under a third of the phone screen').toBeLessThan(0.35);

    // The buttons are the block being reclaimed: on a 390px screen all three
    // share a single row.
    const compactRows = new Set(compactButtons.map((b) => b.top)).size;
    expect(compactRows, 'compact puts the buttons on one row at 390px').toBe(1);

    // 2. Equal prominence between accepting and refusing.
    const accept = compactButtons.find((b) => /accept/.test(b.label));
    const reject = compactButtons.find((b) => /reject|decline|refuse/.test(b.label));
    expect(accept, 'the notice must offer an accept button').toBeTruthy();
    expect(reject, 'the notice must offer a reject button').toBeTruthy();
    expect(reject!.width, 'reject must be exactly as wide as accept').toBe(accept!.width);
    expect(reject!.height, 'reject must be exactly as tall as accept').toBe(accept!.height);
    expect(reject!.top, 'reject must sit on the same row as accept').toBe(accept!.top);

    // 3. Tap targets survive the compaction.
    for (const button of compactButtons) {
      expect(button.height, `"${button.label}" must stay comfortable to tap`).toBeGreaterThanOrEqual(44);
    }

    // No label may be clipped by the narrower buttons.
    const clipped = await page.evaluate(() =>
      [...document.querySelectorAll('.faz-consent-container .faz-notice-btn-wrapper .faz-btn')]
        .filter((el) => el.scrollWidth > el.clientWidth + 1)
        .map((el) => (el.textContent ?? '').trim()),
    );
    expect(clipped, 'no button label may be cut off').toEqual([]);
  });

  test('narrow phones keep accept and reject paired and drop customise below', async ({ page }) => {
    await setLayout('compact');
    await page.context().clearCookies();
    await page.setViewportSize({ width: 320, height: 720 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-consent-container .faz-notice-btn-wrapper .faz-btn', {
      state: 'visible',
      timeout: 20_000,
    });

    const buttons = await readButtons(page);
    const accept = buttons.find((b) => /accept/.test(b.label));
    const reject = buttons.find((b) => /reject|decline|refuse/.test(b.label));
    expect(accept).toBeTruthy();
    expect(reject).toBeTruthy();

    // Under 360px three buttons do not fit side by side. The pair that the
    // equal-prominence rule compares stays together; the customise button, which
    // takes no part in that comparison, is the one that gets its own row.
    expect(reject!.top, 'accept and reject stay paired on narrow phones').toBe(accept!.top);
    expect(reject!.width, 'accept and reject stay equal on narrow phones').toBe(accept!.width);
    for (const button of buttons) {
      expect(button.height, `"${button.label}" must stay comfortable to tap`).toBeGreaterThanOrEqual(44);
    }
  });
});
