import { test, expect } from '../fixtures/wp-fixture';
import { execFileSync } from 'node:child_process';
import { WP_PATH } from '../utils/wp-env';

/**
 * GitHub issue #396: HubSpot forms disappeared with FAZ active.
 *
 * The legacy embed is a library tag plus an inline call:
 *   <script src="//js.hsforms.net/forms/embed/v2.js"></script>
 *   <script>hbspt.forms.create({...});</script>
 * The library was held until consent but the call was not, because the
 * catalogue's code signature `hbspt.forms.create` has dots and the matcher
 * took every dotted pattern for a URL. The call ran at once, threw
 * "hbspt is not defined", and was gone when consent arrived: the form only
 * appeared after a reload.
 *
 * The library is served by page.route() so the test does not depend on
 * HubSpot's network. The stand-in renders a marker where the form would go.
 */

const LIBRARY = 'https://js.hsforms.net/forms/embed/v2.js';
const FAKE_LIBRARY = `window.hbspt = { forms: { create: function (o) {
  var d = document.createElement('div');
  d.className = 'faz-e2e-hubspot-form';
  d.textContent = 'form ' + o.formId;
  document.getElementById('faz-e2e-hubspot-slot').appendChild(d);
} } };`;

function wp(args: string[]): string {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();
}

let postId = '';
let pageUrl = '';

test.describe('HubSpot legacy embed (#396)', () => {
  test.beforeAll(() => {
    const content = [
      '<!-- wp:html -->',
      '<div id="faz-e2e-hubspot-slot"></div>',
      `<script charset="utf-8" type="text/javascript" src="${LIBRARY}"></script>`,
      '<script>',
      '  hbspt.forms.create({ region: "na1", portalId: "123", formId: "faz-e2e" });',
      '</script>',
      '<!-- /wp:html -->',
    ].join('\n');
    const raw = wp([
      'post', 'create', '--post_type=page', '--post_status=publish',
      '--post_title=FAZ E2E HubSpot embed', `--post_content=${content}`,
      `--user=${process.env.WP_ADMIN_USER || 'admin'}`, '--porcelain',
    ]);
    if (!/^\d+$/.test(raw)) throw new Error(`Expected a post ID, got ${JSON.stringify(raw)}`);
    postId = raw;
    pageUrl = wp(['post', 'get', postId, '--field=url']);
  });

  test.afterAll(() => {
    if (postId) wp(['post', 'delete', postId, '--force']);
  });

  test('the inline call waits with its library and renders the form after Accept, without a reload', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    let libraryRequests = 0;
    await page.route(LIBRARY, (route) => {
      libraryRequests++;
      return route.fulfill({ status: 200, contentType: 'text/javascript', body: FAKE_LIBRARY });
    });

    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

    // Both halves are held server-side, in the same category.
    const held = await page.evaluate(() => Array.from(document.querySelectorAll('script'))
      .filter((s) => /hsforms\.net|hbspt\.forms\.create/.test(s.src + s.textContent))
      .map((s) => ({ type: s.type, category: s.getAttribute('data-faz-category') })));
    expect(held).toEqual([
      { type: 'text/plain', category: 'marketing' },
      { type: 'text/plain', category: 'marketing' },
    ]);

    await expect(page.locator('[data-faz-tag="accept-button"]').first()).toBeVisible();
    expect(libraryRequests).toBe(0);
    await expect(page.locator('.faz-e2e-hubspot-form')).toHaveCount(0);

    await page.locator('[data-faz-tag="accept-button"]').first().click();

    await expect(page.locator('.faz-e2e-hubspot-form')).toHaveText('form faz-e2e');
    expect(libraryRequests).toBe(1);
    expect(errors.filter((m) => /hbspt/.test(m))).toEqual([]);
    await context.close();
  });
});
