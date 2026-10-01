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

  /**
   * The fourth control in this wrapper, and the only one with no `order` of its
   * own: the Do-Not-Sell button. On a single row that is not cosmetic. At
   * order:0 it sorts ahead of Accept, and with the pair holding a 40% basis
   * each under 360px there is almost nothing left for it to grow into — it
   * collapses to a few pixels while the inherited `white-space:nowrap` pushes
   * its label across Accept.
   *
   * This is not a hand-built configuration. Geo_Runtime turns donotSell on for
   * a US visitor even when applicableLaw stays 'gdpr', and class-template.php
   * keeps the button precisely because its status is true — so after geo
   * routing lands this is the ordinary rendering for part of the audience, and
   * the one row where a US visitor's opt-out lives.
   */
  test('the Do-Not-Sell button takes a row of its own instead of collapsing beside the pair', async ({ page }) => {
    const saved = wpEval(
      `global $wpdb;$t=$wpdb->prefix.'faz_banners';` +
        `echo base64_encode((string)$wpdb->get_var("SELECT settings FROM $t WHERE banner_default=1 LIMIT 1"));`,
    ).trim().split('\n').pop() || '';

    try {
      // The supported combined mode: GDPR law, Do-Not-Sell on.
      wpEval(
        `global $wpdb;$t=$wpdb->prefix.'faz_banners';` +
          `$row=$wpdb->get_row("SELECT banner_id, settings FROM $t WHERE banner_default=1 LIMIT 1");` +
          `$s=json_decode($row->settings,true);` +
          `$s['settings']['applicableLaw']='gdpr';` +
          `$s['config']['notice']['elements']['buttons']['elements']['donotSell']['status']=true;` +
          `$s['config']['notice']['elements']['buttons']['elements']['donotSell']['tag']='donotsell-button';` +
          `$wpdb->update($t,array('settings'=>wp_json_encode($s)),array('banner_id'=>(int)$row->banner_id));` +
          `\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();` +
          `faz_clear_banner_template_cache();` +
          // The assembled stylesheet is cached separately from the template, in
          // a transient keyed on the plugin version, the layout and a hash of
          // the TEMPLATE css — the compact rules are appended after that hash is
          // taken. So editing them without bumping the pipeline revision leaves
          // a stale stylesheet that this test would then measure, reporting an
          // overflow or a collapse that the current code does not produce. Drop
          // it here so a red means the CSS is wrong, not that it is old.
          `global $wpdb;$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%faz_boosted_css%'");` +
          `echo 'dns-on';`,
      );

      await setLayout('compact');
      await page.context().clearCookies();
      // 320px is the worst case: the <=360px rules are in force here.
      await page.setViewportSize({ width: 320, height: 720 });
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('.faz-consent-container .faz-notice-btn-wrapper [data-faz-tag="donotsell-button"]', {
        state: 'visible',
        timeout: 20_000,
      });

      // Read EVERY flex child, not just `.faz-btn`: the shortcode emits the
      // control either as a button carrying that class or as a bare <a> with no
      // class at all, and the <a> variant is a flex item just the same.
      const row = await page.evaluate(() => {
        const wrapper = document.querySelector('.faz-consent-container .faz-notice-btn-wrapper');
        if (!wrapper) return null;
        // The CONTENT box, not the border box: the wrapper carries 24px of
        // padding each side, so a child at flex-basis:100% is 48px narrower
        // than the wrapper's own rect. Comparing against the rect would make a
        // correctly full-width row look short.
        const style = getComputedStyle(wrapper);
        const inner =
          wrapper.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        const children = [...wrapper.children]
          .map((el) => {
            const r = el.getBoundingClientRect();
            return {
              label: (el.textContent ?? '').trim().toLowerCase(),
              dns: el.matches('[data-faz-tag="donotsell-button"]'),
              width: Math.round(r.width),
              height: Math.round(r.height),
              top: Math.round(r.top),
              right: Math.round(r.right),
              left: Math.round(r.left),
              overflowing: el.scrollWidth > el.clientWidth + 1,
            };
          })
          .filter((c) => c.height > 0);
        return { inner: Math.round(inner), children };
      });

      expect(row, 'the notice must render its button wrapper').not.toBeNull();
      const dns = row!.children.find((c) => c.dns);
      const accept = row!.children.find((c) => /accept/.test(c.label));
      const reject = row!.children.find((c) => /reject|decline|refuse/.test(c.label));
      expect(dns, 'the combined mode must render the Do-Not-Sell control').toBeTruthy();
      expect(accept, 'the notice must still offer an accept button').toBeTruthy();
      expect(reject, 'the notice must still offer a reject button').toBeTruthy();

      // It must not be squeezed into the pair's row. This is the assertion that
      // fails without the full-width rule: the control lands on the first row
      // with a near-zero basis.
      expect(dns!.top, 'the Do-Not-Sell control must not share the row with accept').not.toBe(accept!.top);
      expect(dns!.top, 'the Do-Not-Sell control must not share the row with reject').not.toBe(reject!.top);

      // A full row of its own, and last — behind the two options the
      // equal-prominence rule actually compares.
      expect(dns!.width, 'the Do-Not-Sell control must span the row').toBeGreaterThanOrEqual(row!.inner - 2);
      expect(dns!.top, 'the Do-Not-Sell control must sit below the accept/reject pair').toBeGreaterThan(accept!.top);
      for (const sibling of row!.children) {
        if (sibling.dns) continue;
        expect(dns!.top, `the Do-Not-Sell control must come after "${sibling.label}"`).toBeGreaterThanOrEqual(sibling.top);
      }

      // The collapse showed up as an overflowing nowrap label overlapping
      // Accept, so assert both the absence of overflow and the absence of a
      // horizontal overlap with the pair.
      expect(dns!.overflowing, 'the Do-Not-Sell label must not overflow its box').toBe(false);
      for (const partner of [accept!, reject!]) {
        const overlaps = dns!.left < partner.right && partner.left < dns!.right && dns!.top === partner.top;
        expect(overlaps, `the Do-Not-Sell control must not overlap "${partner.label}"`).toBe(false);
      }
    } finally {
      // Put the banner back byte-for-byte, whatever happened above: a leaked
      // Do-Not-Sell button changes the button count every later spec counts on.
      if (saved) {
        wpEval(
          `global $wpdb;$t=$wpdb->prefix.'faz_banners';` +
            `$row=$wpdb->get_row("SELECT banner_id FROM $t WHERE banner_default=1 LIMIT 1");` +
            `$wpdb->update($t,array('settings'=>base64_decode('${saved}')),array('banner_id'=>(int)$row->banner_id));` +
            `\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();` +
            `faz_clear_banner_template_cache();` +
            `echo 'restored';`,
        );
      }
      await setLayout('comfortable');
    }
  });
});
