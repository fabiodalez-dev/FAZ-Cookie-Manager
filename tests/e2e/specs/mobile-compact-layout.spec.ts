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

type ButtonKind = 'accept' | 'reject' | 'customize' | 'other';
type ButtonBox = { label: string; kind: ButtonKind; width: number; height: number; top: number };

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
        // Identified by class, not by label: the label is translated and can
        // be rewritten by the site owner, the class is the template contract.
        const kind: ButtonKind = el.classList.contains('faz-btn-accept')
          ? 'accept'
          : el.classList.contains('faz-btn-reject')
            ? 'reject'
            : el.classList.contains('faz-btn-customize')
              ? 'customize'
              : 'other';
        return {
          label: (el.textContent ?? '').trim().toLowerCase(),
          kind,
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

/**
 * Snapshot the default banner's settings and contents, apply a PHP mutation
 * to the decoded `$s` (settings) and `$c` (contents) arrays, and return the
 * snapshot for restoreBanner(). Every cache that could serve the previous
 * rendering is dropped: the banner object cache, the template cache, and the
 * assembled-stylesheet transients (keyed on the TEMPLATE css, so a layout or
 * label change alone does not invalidate them).
 */
type BannerSnapshot = { id: number; settings: string; contents: string };

const FLUSH_BANNER_CACHES =
  `\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();` +
  `delete_option('faz_banner_template');` +
  `if (function_exists('faz_clear_banner_template_cache')) { faz_clear_banner_template_cache(); }` +
  `global $wpdb;$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%faz_boosted_css%'");`;

function mutateBanner(php: string): BannerSnapshot {
  const out = wpEval(
    `global $wpdb;$t=$wpdb->prefix.'faz_banners';` +
      `$row=$wpdb->get_row("SELECT banner_id, settings, contents FROM $t WHERE banner_default=1 LIMIT 1");` +
      `$snap=array('id'=>(int)$row->banner_id,'settings'=>base64_encode((string)$row->settings),'contents'=>base64_encode((string)$row->contents));` +
      `$s=json_decode($row->settings,true);if(!is_array($s)){$s=array();}` +
      `$c=json_decode($row->contents,true);if(!is_array($c)){$c=array();}` +
      php +
      `$wpdb->update($t,array('settings'=>wp_json_encode($s),'contents'=>wp_json_encode($c)),array('banner_id'=>(int)$row->banner_id));` +
      FLUSH_BANNER_CACHES +
      `echo "\\n".wp_json_encode($snap);`,
  );
  const last = out.trim().split('\n').pop() || '{}';
  return JSON.parse(last) as BannerSnapshot;
}

function restoreBanner(snap: BannerSnapshot | null): void {
  if (!snap || !snap.id) return;
  wpEval(
    `global $wpdb;$t=$wpdb->prefix.'faz_banners';` +
      `$wpdb->update($t,array('settings'=>base64_decode('${snap.settings}'),'contents'=>base64_decode('${snap.contents}')),array('banner_id'=>${snap.id}));` +
      FLUSH_BANNER_CACHES +
      `echo 'restored';`,
  );
}

/** PHP that writes the three notice labels into every language of `$c`. */
function setLabelsPhp(labels: { accept: string; reject: string; settings: string }): string {
  const json = Buffer.from(JSON.stringify(labels), 'utf8').toString('base64');
  return (
    `$labels=json_decode(base64_decode('${json}'),true);` +
    `if(empty($c)){$c=array((function_exists('faz_default_language')?faz_default_language():'en')=>array());}` +
    `foreach(array_keys($c) as $lang){` +
    `foreach($labels as $k=>$v){$c[$lang]['notice']['elements']['buttons']['elements'][$k]=$v;}` +
    `}`
  );
}

/**
 * Geometry of the notice buttons at the current viewport: per-button box,
 * whether the label overflows it, the customise chevron (classic template)
 * and the label's own text box, plus DOM order and visual order.
 */
async function measureRow(page: Page) {
  return page.evaluate(() => {
    const wrapper = document.querySelector('.faz-consent-container .faz-notice-btn-wrapper');
    if (!wrapper) return null;
    const rtl = getComputedStyle(wrapper).direction === 'rtl';
    const buttons = [...wrapper.querySelectorAll('.faz-btn')]
      .filter((el) => el.getBoundingClientRect().height > 0)
      .map((el) => {
        const r = el.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(el);
        const rects = [...range.getClientRects()].filter((x) => x.width > 0);
        const text = rects.length
          ? {
              left: Math.min(...rects.map((x) => x.left)),
              right: Math.max(...rects.map((x) => x.right)),
              top: Math.min(...rects.map((x) => x.top)),
              bottom: Math.max(...rects.map((x) => x.bottom)),
            }
          : null;
        let chevron: { left: number; right: number; top: number; bottom: number } | null = null;
        const after = getComputedStyle(el, '::after');
        if (after.content && after.content !== 'none' && after.position === 'absolute') {
          const cs = getComputedStyle(el);
          const borderBox = after.boxSizing === 'border-box';
          const w = borderBox
            ? parseFloat(after.width)
            : parseFloat(after.borderLeftWidth) + parseFloat(after.borderRightWidth) + (parseFloat(after.width) || 0);
          const h = borderBox
            ? parseFloat(after.height)
            : parseFloat(after.borderTopWidth) + parseFloat(after.borderBottomWidth) + (parseFloat(after.height) || 0);
          const left =
            after.left !== 'auto' && after.right === 'auto'
              ? r.left + parseFloat(cs.borderLeftWidth) + parseFloat(after.left)
              : r.right - parseFloat(cs.borderRightWidth) - parseFloat(after.right) - w;
          const top = r.top + parseFloat(cs.borderTopWidth) + parseFloat(after.top);
          chevron = { left, right: left + w, top, bottom: top + h };
        }
        const kind = el.classList.contains('faz-btn-accept')
          ? 'accept'
          : el.classList.contains('faz-btn-reject')
            ? 'reject'
            : el.classList.contains('faz-btn-customize')
              ? 'customize'
              : 'other';
        return {
          kind,
          tag: el.getAttribute('data-faz-tag') || '',
          left: r.left,
          right: r.right,
          top: Math.round(r.top),
          width: r.width,
          height: r.height,
          // scrollWidth catches a nowrap label pushed past the padding box; the
          // text-box test catches one that overflows without scrolling.
          overflowing:
            el.scrollWidth > el.clientWidth + 1 ||
            (!!text && (text.left < r.left - 0.5 || text.right > r.right + 0.5)),
          text,
          chevron,
        };
      });
    const dom = buttons.map((b) => b.kind);
    const visual = [...buttons]
      .sort((a, b) => a.top - b.top || (rtl ? b.right - a.right : a.left - b.left))
      .map((b) => b.kind);
    return { rtl, viewport: window.innerWidth, buttons, dom, visual };
  });
}

const LONG_LABEL_WIDTHS = [361, 375, 390, 414, 440];

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

    // Baseline: the shipped layout stacks the buttons. Asserted rather than
    // assumed, so that if the default ever changes this test says so instead
    // of quietly comparing compact against compact.
    //
    // How far it stacks depends on the template. Box and full-width give each
    // button a row of its own; classic (also used for Full-width + Pushdown)
    // puts Accept on a full row at <=576px and lets Customise and Reject share
    // the next one. Both are "stacked" - what matters is that comfortable is
    // not already a single row - so classic is held to its own shape rather
    // than to one row per button.
    const isClassic = await page.evaluate(() => {
      const c = document.querySelector('.faz-consent-container');
      return !!c && (c.classList.contains('faz-classic-top') || c.classList.contains('faz-classic-bottom'));
    });
    const roomyRows = new Set(roomyButtons.map((b) => b.top)).size;
    if (isClassic) {
      expect(roomyRows, 'the comfortable classic layout stacks the buttons on at least two rows').toBeGreaterThanOrEqual(2);
    } else {
      expect(roomyRows, 'the comfortable layout stacks the buttons').toBe(roomyButtons.length);
    }

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
    const accept = compactButtons.find((b) => b.kind === 'accept');
    const reject = compactButtons.find((b) => b.kind === 'reject');
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

  test('narrow phones keep accept and reject paired and give customise its own row', async ({ page }) => {
    await setLayout('compact');
    await page.context().clearCookies();
    await page.setViewportSize({ width: 320, height: 720 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-consent-container .faz-notice-btn-wrapper .faz-btn', {
      state: 'visible',
      timeout: 20_000,
    });

    const buttons = await readButtons(page);
    const accept = buttons.find((b) => b.kind === 'accept');
    const reject = buttons.find((b) => b.kind === 'reject');
    expect(accept).toBeTruthy();
    expect(reject).toBeTruthy();

    // Under 360px three buttons do not fit side by side. The pair that the
    // equal-prominence rule compares stays together; the customise button, which
    // takes no part in that comparison, is the one that gets its own row. It
    // comes first in the markup, so that row sits above the pair.
    expect(reject!.top, 'accept and reject stay paired on narrow phones').toBe(accept!.top);
    expect(reject!.width, 'accept and reject stay equal on narrow phones').toBe(accept!.width);
    for (const button of buttons) {
      expect(button.height, `"${button.label}" must stay comfortable to tap`).toBeGreaterThanOrEqual(44);
    }
  });

  /**
   * The reviewed defect: three buttons on a 361-440px row get ~100px each, and
   * with `nowrap` a long translated accept label was clipped ("Az összes
   * elfoga") while the short reject label beside it was not. Two boxes of the
   * same size that no longer look the same is exactly the asymmetry equal
   * prominence forbids. Hungarian is the worst shipped case: the longest
   * accept label next to one of the shortest reject labels.
   */
  test('long labels wrap instead of clipping and accept/reject stay identical boxes (361-440px)', async ({ page }) => {
    let snap: BannerSnapshot | null = null;
    try {
      snap = mutateBanner(setLabelsPhp({ accept: 'Az összes elfogadása', reject: 'Elutasít', settings: 'Testreszabás' }));
      await setLayout('compact');
      await openBannerOnPhone(page);

      for (const width of LONG_LABEL_WIDTHS) {
        await page.setViewportSize({ width, height: 800 });
        const row = await measureRow(page);
        expect(row, 'the notice must render its buttons').not.toBeNull();
        const accept = row!.buttons.find((b) => b.kind === 'accept');
        const reject = row!.buttons.find((b) => b.kind === 'reject');
        expect(accept, `${width}px: accept rendered`).toBeTruthy();
        expect(reject, `${width}px: reject rendered`).toBeTruthy();

        for (const b of row!.buttons) {
          expect(b.overflowing, `${width}px: the ${b.kind} label must not be cut off`).toBe(false);
          expect(b.height, `${width}px: ${b.kind} must stay comfortable to tap`).toBeGreaterThanOrEqual(44);
          expect(b.left, `${width}px: ${b.kind} must be on screen`).toBeGreaterThanOrEqual(-0.5);
          expect(b.right, `${width}px: ${b.kind} must be on screen`).toBeLessThanOrEqual(row!.viewport + 0.5);
        }
        // Same box, to the sub-pixel: a two-line accept must not stand taller
        // than a one-line reject.
        expect(Math.abs(accept!.width - reject!.width), `${width}px: accept and reject equally wide`).toBeLessThan(0.5);
        expect(Math.abs(accept!.height - reject!.height), `${width}px: accept and reject equally tall`).toBeLessThan(0.5);
        expect(reject!.top, `${width}px: accept and reject on the same row`).toBe(accept!.top);
      }
    } finally {
      restoreBanner(snap);
      await setLayout('comfortable');
    }
  });

  /**
   * WCAG 2.4.3: Tab must move through the buttons in the order they are seen.
   * The markup is [customise][reject][accept]; an earlier revision reordered
   * them visually with `order` and focus ran right to left across one row.
   * Checked both from geometry and with real Tab presses, at the one-row width
   * and at the two-row width.
   */
  test('keyboard focus order matches the visual order', async ({ page }) => {
    await setLayout('compact');
    await openBannerOnPhone(page);

    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 800 });
      const row = await measureRow(page);
      expect(row, 'the notice must render its buttons').not.toBeNull();
      expect(row!.visual, `${width}px: visual order must equal DOM (focus) order`).toEqual(row!.dom);

      // Real keyboard: start on the first button as seen, Tab through the rest.
      const kindByTag = new Map(row!.buttons.map((b) => [b.tag, b.kind]));
      const visualTags = row!.visual.map((k) => row!.buttons.find((b) => b.kind === k)!.tag);
      expect(visualTags.length, 'the notice must offer at least two buttons').toBeGreaterThanOrEqual(2);
      await page.focus(`.faz-consent-container .faz-notice-btn-wrapper [data-faz-tag="${visualTags[0]}"]`);
      for (let i = 1; i < visualTags.length; i++) {
        await page.keyboard.press('Tab');
        const focused = await page.evaluate(() => document.activeElement?.getAttribute('data-faz-tag') || '');
        expect(
          kindByTag.get(focused),
          `${width}px: Tab #${i} must land on the next button as seen (${row!.visual[i]})`,
        ).toBe(row!.visual[i]);
      }
    }
  });

  /**
   * The classic template (also used for Full-width + Pushdown) draws the
   * customise chevron as an absolute ::after 12px from the end of the button,
   * inside the 28px end padding it sets on desktop. The compact padding removed
   * that room and the chevron sat on the label ("Personalizz▾"). Italian is a
   * long customise label among the shipped ones.
   */
  test('classic template: the customise chevron stays clear of its label', async ({ page }) => {
    let snap: BannerSnapshot | null = null;
    try {
      snap = mutateBanner(
        `if(!isset($s['settings'])||!is_array($s['settings'])){$s['settings']=array();}` +
          `$s['settings']['type']='classic';$s['settings']['preferenceCenterType']='pushdown';$s['settings']['position']='bottom';` +
          setLabelsPhp({ accept: 'Accettare tutto', reject: 'Rifiuta tutto', settings: 'Personalizza' }),
      );
      await setLayout('compact');
      await openBannerOnPhone(page);
      const isClassic = await page.evaluate(() => {
        const c = document.querySelector('.faz-consent-container');
        return !!c && (c.classList.contains('faz-classic-top') || c.classList.contains('faz-classic-bottom'));
      });
      expect(isClassic, 'the banner must render with the classic template').toBe(true);

      for (const width of [...LONG_LABEL_WIDTHS, 320]) {
        await page.setViewportSize({ width, height: 800 });
        const row = await measureRow(page);
        expect(row).not.toBeNull();
        const customize = row!.buttons.find((b) => b.kind === 'customize');
        const accept = row!.buttons.find((b) => b.kind === 'accept');
        const reject = row!.buttons.find((b) => b.kind === 'reject');
        expect(customize, `${width}px: customise rendered`).toBeTruthy();
        expect(customize!.chevron, `${width}px: the classic customise button draws its chevron`).not.toBeNull();
        expect(customize!.text, `${width}px: customise has a label`).not.toBeNull();
        const c = customize!.chevron!;
        const t = customize!.text!;
        const intersects = !(c.right <= t.left || c.left >= t.right || c.bottom <= t.top || c.top >= t.bottom);
        expect(intersects, `${width}px: the chevron must not overlap the label`).toBe(false);
        expect(c.right, `${width}px: the chevron stays inside its button`).toBeLessThanOrEqual(customize!.right);

        for (const b of row!.buttons) {
          expect(b.overflowing, `${width}px: the ${b.kind} label must not be cut off`).toBe(false);
        }
        expect(Math.abs(accept!.width - reject!.width), `${width}px: accept and reject equally wide`).toBeLessThan(0.5);
        expect(Math.abs(accept!.height - reject!.height), `${width}px: accept and reject equally tall`).toBeLessThan(0.5);
        expect(row!.visual, `${width}px: visual order must equal DOM (focus) order`).toEqual(row!.dom);
      }
    } finally {
      restoreBanner(snap);
      await setLayout('comfortable');
    }
  });

  /**
   * The fourth control in this wrapper, last in the markup: the Do-Not-Sell
   * button. On a single row that is not cosmetic. Left to share the first line
   * with the pair, which holds a 40% basis each under 360px, it has almost
   * nothing left to grow into — an earlier revision rendered it collapsed to a
   * few pixels with its label spilling across Accept.
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
              kind: el.classList.contains('faz-btn-accept')
                ? 'accept'
                : el.classList.contains('faz-btn-reject')
                  ? 'reject'
                  : 'other',
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
      const accept = row!.children.find((c) => c.kind === 'accept');
      const reject = row!.children.find((c) => c.kind === 'reject');
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

      // The collapse showed up as an overflowing label overlapping Accept, so
      // assert both the absence of overflow and the absence of a horizontal
      // overlap with the pair.
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
