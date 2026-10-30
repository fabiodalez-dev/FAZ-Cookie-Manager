import type { Browser, Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

/**
 * Notice button order (#367) and the phone layout that used to reorder the
 * buttons with CSS (#368).
 *
 * What is guarded:
 *  1. The order chosen in Banner → Buttons is saved through the real admin
 *     save and reaches the visitor's markup, without anyone clearing the
 *     template cache by hand (the order is part of its signature).
 *  2. An order that separates Accept from Reject is refused on the way to the
 *     database, not just hidden from the select.
 *  3. On a phone, in the default comfortable layout, what is seen top to
 *     bottom is the markup order, which is also the Tab order (WCAG 2.4.3).
 *     The shipped templates used to sort Accept first and Reject last with
 *     CSS `order` while focus ran Customise → Reject → Accept.
 */

const PHONE = { width: 390, height: 844 };
const KINDS = ['customize', 'reject', 'accept'] as const;
type Kind = (typeof KINDS)[number];

type Snapshot = { id: number; settings: string };

const FLUSH =
  `\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();` +
  `delete_option('faz_banner_template');` +
  `global $wpdb;$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%faz_boosted_css%'");`;

function snapshotDefaultBanner(): Snapshot {
  const out = wpEval(
    `global $wpdb;$t=$wpdb->prefix.'faz_banners';` +
      `$row=$wpdb->get_row("SELECT banner_id, settings FROM $t WHERE banner_default=1 LIMIT 1");` +
      `echo "\\n".wp_json_encode(array('id'=>(int)$row->banner_id,'settings'=>base64_encode((string)$row->settings)));`,
  );
  return JSON.parse(out.trim().split('\n').pop() || '{}') as Snapshot;
}

function restoreBanner(snap: Snapshot | null): void {
  if (!snap || !snap.id) return;
  wpEval(
    `global $wpdb;$wpdb->update($wpdb->prefix.'faz_banners',array('settings'=>base64_decode('${snap.settings}')),array('banner_id'=>${snap.id}));` +
      FLUSH,
  );
}

/** Write the order straight into the stored row (bypasses the admin save). */
function writeOrder(order: string): void {
  wpEval(
    `global $wpdb;$t=$wpdb->prefix.'faz_banners';` +
      `$row=$wpdb->get_row("SELECT banner_id, settings FROM $t WHERE banner_default=1 LIMIT 1");` +
      `$s=json_decode($row->settings,true);$s['settings']['buttonOrder']='${order}';` +
      `$wpdb->update($t,array('settings'=>wp_json_encode($s)),array('banner_id'=>(int)$row->banner_id));` +
      FLUSH,
  );
}

function storedOrder(): string {
  return wpEval(
    `global $wpdb;$s=json_decode($wpdb->get_var("SELECT settings FROM {$wpdb->prefix}faz_banners WHERE banner_default=1 LIMIT 1"),true);` +
      `echo isset($s['settings']['buttonOrder'])?$s['settings']['buttonOrder']:'(missing)';`,
  ).trim().split('\n').pop() as string;
}

function setLayout(value: 'comfortable' | 'compact'): void {
  wpEval(
    `$s=get_option('faz_settings',array());if(!is_array($s)){$s=array();}` +
      `if(!isset($s['banner_control'])||!is_array($s['banner_control'])){$s['banner_control']=array();}` +
      `$s['banner_control']['mobile_layout']='${value}';update_option('faz_settings',$s);` +
      FLUSH,
  );
}

function readLayout(): string {
  return wpEval(
    `$s=get_option('faz_settings',array());echo isset($s['banner_control']['mobile_layout'])?$s['banner_control']['mobile_layout']:'';`,
  ).trim().split('\n').pop() as string;
}

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
}

async function chooseOrderInAdmin(page: Page, value: string, inject = false) {
  await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-banner', { waitUntil: 'domcontentloaded' });
  await page.click('button.faz-tab[data-tab="buttons"]');
  const select = page.locator('#faz-b-button-order');
  await expect(select).toBeVisible();
  if (inject) {
    // A value the select does not offer, as a crafted request would send it.
    await select.evaluate((el, v) => {
      const s = el as HTMLSelectElement;
      const o = document.createElement('option');
      o.value = v;
      o.textContent = v;
      s.appendChild(o);
      s.value = v;
      s.dispatchEvent(new Event('change', { bubbles: true }));
    }, value);
  } else {
    await select.selectOption(value);
  }
  await saveBanner(page);
}

/** Visitor view: kinds of the notice buttons in markup order and in on-screen order. */
async function visitorButtons(browser: Browser, viewport?: { width: number; height: number }) {
  const context = await browser.newContext(viewport ? { viewport } : {});
  const page = await context.newPage();
  try {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-consent-container .faz-notice-btn-wrapper .faz-btn', { state: 'visible', timeout: 20_000 });
    return await readNotice(page);
  } finally {
    await context.close();
  }
}

async function readNotice(page: Page) {
  return page.evaluate((kinds) => {
    const kindOf = (el: Element) => kinds.find((k) => el.classList.contains(`faz-btn-${k}`)) || 'other';
    const els = [...document.querySelectorAll('.faz-consent-container .faz-notice-btn-wrapper .faz-btn')].filter(
      (el) => (el as HTMLElement).offsetParent !== null && kinds.some((k) => el.classList.contains(`faz-btn-${k}`)),
    );
    const dom = els.map(kindOf);
    const screen = els
      .map((el) => ({ k: kindOf(el), r: el.getBoundingClientRect() }))
      .sort((a, b) => a.r.top - b.r.top || a.r.left - b.r.left)
      .map((x) => x.k);
    return { dom, screen };
  }, KINDS as unknown as string[]);
}

/** Kinds of the notice buttons in the order Tab reaches them. */
async function tabOrder(page: Page): Promise<string[]> {
  const seen: string[] = [];
  for (let i = 0; i < 25 && seen.length < 3; i++) {
    await page.keyboard.press('Tab');
    const kind = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || !el.closest('.faz-notice-btn-wrapper')) return '';
      for (const k of ['customize', 'reject', 'accept']) if (el.classList.contains(`faz-btn-${k}`)) return k;
      return '';
    });
    if (kind && !seen.includes(kind)) seen.push(kind);
  }
  return seen;
}

test.describe.serial('Notice button order (#367, #368)', () => {
  let snap: Snapshot | null = null;
  let layout = '';

  test.beforeAll(() => {
    snap = snapshotDefaultBanner();
    layout = readLayout();
    setLayout('comfortable');
  });

  test.afterAll(() => {
    restoreBanner(snap);
    setLayout(layout === 'compact' ? 'compact' : 'comfortable');
  });

  test('the order chosen in the admin reaches the visitor markup', async ({ page, browser, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await chooseOrderInAdmin(page, 'accept-reject-settings');
    expect(storedOrder()).toBe('accept-reject-settings');

    // No cache flush between save and visit: the order is part of the
    // template signature, so a stale cached template would show here.
    const desktop = await visitorButtons(browser);
    expect(desktop.dom).toEqual(['accept', 'reject', 'customize']);
    expect(desktop.screen).toEqual(desktop.dom);

    await chooseOrderInAdmin(page, 'settings-reject-accept');
    expect(storedOrder()).toBe('settings-reject-accept');
    expect((await visitorButtons(browser)).dom).toEqual(['customize', 'reject', 'accept']);
  });

  test('an order that separates Accept from Reject is refused on save', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await chooseOrderInAdmin(page, 'accept-reject-settings');
    expect(storedOrder()).toBe('accept-reject-settings');
    await chooseOrderInAdmin(page, 'accept-settings-reject', true);
    expect(storedOrder()).toBe('settings-reject-accept');
  });

  for (const order of ['settings-reject-accept', 'accept-reject-settings', 'reject-accept-settings']) {
    test(`phone, comfortable layout: screen order and Tab order follow the markup (${order})`, async ({ browser }) => {
      writeOrder(order);
      const want = order.split('-').map((k) => (k === 'settings' ? 'customize' : k));

      const context = await browser.newContext({ viewport: PHONE });
      const page = await context.newPage();
      try {
        await page.goto('/', { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('.faz-consent-container .faz-notice-btn-wrapper .faz-btn', { state: 'visible', timeout: 20_000 });
        const notice = await readNotice(page);
        expect(notice.dom).toEqual(want);
        expect(notice.screen, 'what a phone shows top to bottom is the markup order').toEqual(want);
        expect(await tabOrder(page), 'keyboard focus meets the buttons in the order they are seen').toEqual(want);

        // Accept and Reject stay next to each other on screen.
        const pos = notice.screen.indexOf('accept') - notice.screen.indexOf('reject');
        expect(Math.abs(pos)).toBe(1);

        // Preference centre: on screen in markup order too.
        await page.locator('.faz-consent-container .faz-btn-customize').first().click();
        await page.waitForSelector('.faz-prefrence-btn-wrapper .faz-btn', { state: 'visible', timeout: 10_000 });
        const pref = await page.evaluate(() => {
          const wrap = [...document.querySelectorAll('.faz-prefrence-btn-wrapper')].find(
            (w) => (w as HTMLElement).offsetParent !== null,
          );
          const els = wrap ? [...wrap.querySelectorAll('.faz-btn')].filter((el) => (el as HTMLElement).offsetParent !== null) : [];
          const dom = els.map((el) => el.className);
          const screen = [...els]
            .sort((a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top)
            .map((el) => el.className);
          return { dom, screen };
        });
        expect(pref.dom.length).toBeGreaterThan(1);
        expect(pref.screen, 'preference-centre buttons follow the markup on a phone').toEqual(pref.dom);
      } finally {
        await context.close();
      }
    });
  }

  test('phone, compact layout: the chosen order is kept and Accept/Reject stay paired', async ({ browser }) => {
    setLayout('compact');
    try {
      writeOrder('accept-reject-settings');
      const notice = await visitorButtons(browser, PHONE);
      expect(notice.dom).toEqual(['accept', 'reject', 'customize']);
      expect(notice.screen).toEqual(notice.dom);
    } finally {
      setLayout('comfortable');
    }
  });
});
