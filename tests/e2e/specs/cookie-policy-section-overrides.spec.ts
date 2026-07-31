/**
 * Cookie Policy section editor — end-to-end coverage.
 *
 * Exercises the real WordPress REST endpoint, admin UI serialisation and
 * Renderer pipeline, including an unbundled language (Slovak). The shipped
 * scaffold must be a textarea placeholder, never a copied value; authored text
 * remains isolated by language/jurisdiction and placeholders still resolve.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';
import { acquireSharedWordPressLock, releaseSharedWordPressLock } from '../utils/shared-wordpress-lock';

const ADMIN_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager-cookie-policy';
const RENDERER = '\\FazCookie\\Admin\\Modules\\Cookie_Policy_Generator\\Includes\\Renderer';
const MARKER = 'Slovenská politika súborov cookie E2E';

let savedData = '';
let englishBefore = '';
let popiaBefore = '';
let lockHeld = false;

function render(lang: string, jurisdiction = 'gdpr-strict'): string {
  return wpEval(
    `echo ${RENDERER}::render(array('lang'=>'${lang}','jurisdiction'=>'${jurisdiction}','show_title'=>'true'));`,
  );
}

async function openPolicyTextEditor(page: import('@playwright/test').Page): Promise<void> {
  await page.locator('#cp-override-lang').evaluate((select) => {
    const details = select.closest('details');
    if (details) details.open = true;
  });
  await expect(page.locator('#cp-override-lang')).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({}, testInfo) => {
  testInfo.setTimeout(41 * 60_000);
  await acquireSharedWordPressLock();
  lockHeld = true;
  savedData = wpEval(`
    $sentinel = new stdClass();
    $value = get_option('faz_cookie_policy_data', $sentinel);
    echo wp_json_encode(array(
      'exists' => $value !== $sentinel,
      'value'  => $value !== $sentinel ? $value : array(),
    ));
  `).trim();
  wpEval(`
    update_option('faz_cookie_policy_data', array(
      'jurisdiction'       => 'gdpr-strict',
      'default_lang'       => '',
      'company'            => array(
        'name'     => 'Slovak Override Company',
        'address'  => '1 Test Street',
        'email'    => 'privacy@example.test',
        'registry' => '',
      ),
      'dpo'                => array('name'=>'DPO', 'email'=>'dpo@example.test'),
      'privacy_policy_url' => 'https://example.test/privacy',
      'retention_months'   => 12,
      'section_overrides'  => array(),
    ));
  `);
  englishBefore = render('en');
  popiaBefore = render('en', 'popia-southafrica');
});

test.afterAll(() => {
  try {
    if (savedData) {
      const b64 = Buffer.from(savedData, 'utf8').toString('base64');
      wpEval(`
        $snapshot = json_decode(base64_decode('${b64}'), true);
        if (!empty($snapshot['exists'])) {
          update_option('faz_cookie_policy_data', $snapshot['value']);
        } else {
          delete_option('faz_cookie_policy_data');
        }
      `);
    }
  } finally {
    if (lockHeld) {
      releaseSharedWordPressLock();
      lockHeld = false;
    }
  }
});

test('Slovak is selectable and the REST scaffold exposes shipped text as placeholders', async ({ page, loginAsAdmin }) => {
  await loginAsAdmin(page);
  await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => {
    const field = document.querySelector<HTMLInputElement>('#cp-company-name');
    return field?.value === 'Slovak Override Company';
  });

  await expect(page.locator('#cp-override-lang option[value="sk"]')).toHaveCount(1);
  await expect(page.locator('#cp-default-lang option[value="sk"]')).toHaveCount(1);

  await openPolicyTextEditor(page);
  await page.locator('#cp-override-lang').selectOption('sk');
  const scaffoldResponse = page.waitForResponse((response) =>
    response.url().includes('/faz/v1/cookie-policy/scaffold') && response.request().method() === 'GET',
  );
  await page.locator('#cp-override-load').click();
  const response = await scaffoldResponse;
  expect(response.status()).toBe(200);
  const payload = await response.json() as {
    lang: string;
    template_lang: string;
    sections: Array<{ shipped: string; override: string }>;
  };
  expect(payload.lang).toBe('sk');
  expect(payload.template_lang).toBe('en');
  expect(payload.sections.length).toBeGreaterThanOrEqual(8);
  expect(payload.sections.every((section) => section.shipped.length > 0)).toBe(true);
  expect(payload.sections.every((section) => section.override === '')).toBe(true);

  const textareas = page.locator('#cp-override-sections textarea');
  await expect(textareas).toHaveCount(payload.sections.length);
  expect(await textareas.first().inputValue(), 'untouched textarea value stays empty').toBe('');
  expect((await textareas.first().getAttribute('placeholder'))?.length, 'shipped scaffold is the placeholder').toBeGreaterThan(100);
  await expect(page.locator('#cp-override-sections .faz-help')).toContainText(/No template ships/i);
});

test('authored Slovak text survives save/reload and placeholders resolve publicly', async ({ page, loginAsAdmin }) => {
  await loginAsAdmin(page);
  await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => {
    const field = document.querySelector<HTMLInputElement>('#cp-company-name');
    return field?.value === 'Slovak Override Company';
  });

  await openPolicyTextEditor(page);
  await page.locator('#cp-override-lang').selectOption('sk');
  await page.locator('#cp-override-load').click();
  const first = page.locator('#cp-override-sections textarea').first();
  await expect(first).toBeVisible();
  const shippedPlaceholder = await first.getAttribute('placeholder');
  await first.fill(`# ${MARKER}\nPrevádzkovateľ: {{COMPANY_NAME}}.`);
  await page.locator('form#faz-cookie-policy-form button[type=submit]').click();
  await expect(page.locator('#cp-save-status')).toContainText(/Saved/i);

  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => {
    const field = document.querySelector<HTMLInputElement>('#cp-company-name');
    return field?.value === 'Slovak Override Company';
  });
  await openPolicyTextEditor(page);
  await page.locator('#cp-override-lang').selectOption('sk');
  await page.locator('#cp-override-load').click();
  const reloaded = page.locator('#cp-override-sections textarea').first();
  await expect(reloaded).toHaveValue(new RegExp(MARKER));
  expect(await reloaded.getAttribute('placeholder')).toBe(shippedPlaceholder);

  const stored = JSON.parse(wpEval(`
    $value = get_option('faz_cookie_policy_data', array());
    echo wp_json_encode($value['section_overrides']['gdpr-strict']['sk']['0'] ?? null);
  `)) as { anchor: string; text: string } | null;
  expect(stored?.anchor).toBeTruthy();
  expect(stored?.text).toContain(MARKER);

  const slovak = render('sk');
  expect(slovak).toContain(MARKER);
  expect(slovak).toContain('Prevádzkovateľ: Slovak Override Company.');
  expect(slovak).not.toMatch(/\{\{[A-Z][A-Z0-9_]*\}\}/);
});

test('language/jurisdiction isolation is byte-stable and stale anchors fail closed', () => {
  expect(render('en')).toBe(englishBefore);
  expect(render('en', 'popia-southafrica')).toBe(popiaBefore);

  wpEval(`
    $value = get_option('faz_cookie_policy_data', array());
    $value['section_overrides']['gdpr-strict']['sk']['0']['anchor'] = '# obsolete heading';
    update_option('faz_cookie_policy_data', $value);
  `);
  const stale = render('sk');
  expect(stale).not.toContain(MARKER);
  expect(stale).toContain('Slovak Override Company');
});
