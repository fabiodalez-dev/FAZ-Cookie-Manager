/**
 * #343 — "Allow anti-spam services before consent".
 *
 * reCAPTCHA, hCaptcha and Turnstile used to be hard-coded into the server
 * whitelist ahead of every provider rule, so a site owner who wanted reCAPTCHA
 * held until consent could not get there from the settings. The switch is on by
 * default (nothing changes for existing sites); off, the shipped CAPTCHA
 * exceptions are ignored and the catalogue holds reCAPTCHA under functional,
 * with no custom rule needed.
 *
 * The page under test carries a real reCAPTCHA <script src> in its content; the
 * assertions read the HTML the server sends, so no request to Google is needed
 * and every request to it is aborted.
 */
import type { Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { fazApiGet, fazApiPost, openSettingsPage } from '../utils/faz-api';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const SLUG = 'faz-e2e-antispam-343';
const RECAPTCHA_SRC = 'https://www.google.com/recaptcha/api.js?render=FAZE2EKEY';
// Settings::DEFAULT_ANTISPAM_EXCEPTIONS — what a fresh install stores in Script Blocking Exceptions.
const SHIPPED_EXCEPTIONS = ['www.google.com/recaptcha/api', 'www.gstatic.com/recaptcha/', 'challenges.cloudflare.com/', 'hcaptcha.com/'];

type Settings = Record<string, any>;

function ensurePage(): string {
  const content = `<p>reCAPTCHA probe</p><script src="${RECAPTCHA_SRC}" id="faz-e2e-recaptcha-js"></script>`;
  const b64 = Buffer.from(content, 'utf8').toString('base64');
  // kses would strip the <script> for a user without unfiltered_html, and WP-CLI
  // runs as user 0, so the filters are removed for this one insert.
  return wpEval(`
    kses_remove_filters();
    $existing = get_page_by_path( '${SLUG}', OBJECT, 'page' );
    $data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => '${SLUG}', 'post_title' => 'FAZ E2E anti-spam 343', 'post_content' => base64_decode( '${b64}' ) );
    if ( $existing ) { $data['ID'] = $existing->ID; $id = wp_update_post( $data ); } else { $id = wp_insert_post( $data ); }
    echo get_permalink( $id );
  `).trim();
}

async function getSettings(page: Page, nonce: string): Promise<Settings> {
  const res = await fazApiGet<Settings>(page, nonce, 'settings');
  expect(res.status).toBe(200);
  return res.data;
}

async function postSettings(page: Page, nonce: string, payload: Settings): Promise<void> {
  const res = await fazApiPost<Settings>(page, nonce, 'settings', payload);
  expect(res.status).toBe(200);
}

/** The reCAPTCHA <script> tag exactly as an anonymous visitor receives it. */
async function recaptchaTag(page: Page, url: string): Promise<string> {
  const ctx = await page.context().browser()!.newContext();
  try {
    const res = await ctx.request.get(`${url}${url.includes('?') ? '&' : '?'}faz_e2e_nocache=${Date.now()}`);
    expect(res.status()).toBe(200);
    const html = await res.text();
    const match = html.match(/<script\b[^>]*faz-e2e-recaptcha-js[^>]*>/i);
    expect(match, 'the reCAPTCHA probe script is on the page').not.toBeNull();
    return match![0];
  } finally {
    await ctx.close();
  }
}

test.describe('#343 — anti-spam services before consent', () => {
  test.describe.configure({ mode: 'serial' });

  let url = '';
  let original: Settings | null = null;
  let nonce = '';

  test.beforeAll(() => {
    url = ensurePage();
  });

  test.afterAll(() => {
    if (original) {
      const b64 = Buffer.from(JSON.stringify(original.script_blocking ?? {}), 'utf8').toString('base64');
      wpEval(`$s = get_option( 'faz_settings', array() ); $s['script_blocking'] = json_decode( base64_decode( '${b64}' ), true ); update_option( 'faz_settings', $s ); delete_option( 'faz_banner_template' );`);
    }
    wpEval(`$p = get_page_by_path( '${SLUG}', OBJECT, 'page' ); if ( $p ) { wp_delete_post( $p->ID, true ); }`);
  });

  test('the switch is on by default and shown on the Script Blocking screen', async ({ page, loginAsAdmin }) => {
    nonce = await openSettingsPage(page, loginAsAdmin);
    original = await getSettings(page, nonce);
    expect(original.script_blocking?.allow_antispam_before_consent ?? true).toBe(true);
    const toggle = page.locator('input[type="checkbox"][data-path="script_blocking.allow_antispam_before_consent"]');
    await expect(toggle).toHaveCount(1);
    await expect(toggle).toBeChecked();
  });

  test('switched off on a default install, reCAPTCHA is held under functional without any custom rule', async ({ page, loginAsAdmin }) => {
    await page.route(/(google\.com|gstatic\.com)\/recaptcha/, (route) => route.abort());
    nonce = await openSettingsPage(page, loginAsAdmin);
    const current = await getSettings(page, nonce);
    const rules = Array.isArray(current.script_blocking?.custom_rules) ? current.script_blocking.custom_rules : [];
    // The four CAPTCHA exceptions a fresh install stores stay in place: the
    // switch alone has to be enough, as it is on a default install.
    const stored = Array.isArray(current.script_blocking?.whitelist_patterns) ? current.script_blocking.whitelist_patterns : [];
    const whitelist = [...new Set([...stored.filter((p: string) => !/recaptcha|hcaptcha|turnstile|challenges\.cloudflare/i.test(p)), ...SHIPPED_EXCEPTIONS])];
    await postSettings(page, nonce, {
      ...current,
      script_blocking: {
        ...(current.script_blocking ?? {}),
        allow_antispam_before_consent: false,
        // No custom rule: the switch alone has to hold the catalogue's CAPTCHA
        // entries, which is the flow #343 asks for.
        custom_rules: rules.filter((r: any) => !/recaptcha|hcaptcha|turnstile/i.test(r?.pattern ?? '')),
        whitelist_patterns: whitelist,
      },
    });

    const saved = await getSettings(page, nonce);
    expect(saved.script_blocking.allow_antispam_before_consent).toBe(false);

    const tag = await recaptchaTag(page, url);
    expect(tag, 'reCAPTCHA must be parked before consent').toContain('type="text/plain"');
    expect(tag).toContain('data-faz-category="functional"');

    // The settings screen reflects the saved state.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('input[type="checkbox"][data-path="script_blocking.allow_antispam_before_consent"]')).not.toBeChecked();
  });

  test('switched back on, reCAPTCHA loads before consent again', async ({ page, loginAsAdmin }) => {
    nonce = await openSettingsPage(page, loginAsAdmin);
    const current = await getSettings(page, nonce);
    await postSettings(page, nonce, {
      ...current,
      script_blocking: { ...(current.script_blocking ?? {}), allow_antispam_before_consent: true },
    });

    const tag = await recaptchaTag(page, url);
    expect(tag, 'reCAPTCHA is allowed before consent again').not.toContain('text/plain');
    expect(tag).toContain(`src="${RECAPTCHA_SRC.replace(/&/g, '&amp;')}"`);
  });
});
