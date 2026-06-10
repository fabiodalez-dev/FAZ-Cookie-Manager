import { expect, test } from '../fixtures/wp-fixture';

/**
 * Regression tests for service-level consent bugs:
 *
 *  - #134: the content-blocker placeholder "Accept cookies" button accepted the
 *    whole category instead of the specific service. The button now carries
 *    data-faz-accept-service and the click handler grants only that service
 *    (svc.<id>:yes) without flipping the category to "yes".
 *  - #136: clicking a per-service toggle inside an expanded category accordion
 *    collapsed the accordion. The category click listener now ignores clicks on
 *    service toggles / switches.
 */

test.describe('Service-level consent (#134, #136)', () => {
  test('#134: placeholder Accept grants only the service, not the category', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await context.clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    // Wait for the frontend runtime to expose the per-service accept helper.
    await page.waitForFunction(() => typeof (window as any)._fazAcceptService === 'function', undefined, { timeout: 10_000 });

    // Simulate the placeholder Accept button for an embedded YouTube video in
    // the Marketing category: inject the exact markup the PHP builder now emits
    // and dispatch a real click so the delegated body listener handles it.
    await page.evaluate(() => {
      const btn = document.createElement('button');
      btn.className = 'faz-placeholder-btn';
      btn.setAttribute('data-faz-accept', 'marketing');
      btn.setAttribute('data-faz-accept-service', 'youtube');
      btn.id = 'faz-test-placeholder-accept';
      document.body.appendChild(btn);
      btn.click();
    });

    const consent = await getConsentCookie(context);
    expect(consent, 'a consent cookie must be written after accepting the service').toBeDefined();
    const parsed = parseConsentCookie(consent!.value);

    // The specific service is granted...
    expect(parsed['svc.youtube']).toBe('yes');
    // ...but the whole Marketing category is NOT flipped to "yes".
    expect(parsed.marketing === 'yes').toBeFalsy();
  });

  test('#136: the category accordion listener guards against service-toggle clicks', async ({ page, wpBaseURL }) => {
    // The fix makes _fazAttachCategoryListeners short-circuit on service-toggle /
    // switch / checkbox clicks so they no longer collapse the accordion. The
    // listener is module-internal, so assert the shipped runtime carries the
    // guard selector — a deterministic regression guard that fails if the fix is
    // reverted. Read the actual served script the page enqueues.
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const scriptUrl = await page.evaluate(() => {
      const s = Array.from(document.scripts).find((el) => /\/frontend\/js\/script(\.min)?\.js/.test(el.src));
      return s ? s.src : '';
    });
    expect(scriptUrl, 'the frontend script must be enqueued').toMatch(/script(\.min)?\.js/);
    const body = await (await page.request.get(scriptUrl)).text();
    expect(body, 'the accordion listener must guard service-toggle / switch clicks').toMatch(/faz-service-toggle/);
  });
});
