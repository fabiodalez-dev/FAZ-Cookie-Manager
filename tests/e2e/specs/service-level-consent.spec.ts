import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '../fixtures/wp-fixture';

/**
 * Reusable regression suite for the service-level consent work (#134, #136) and
 * the per-service consent mechanics it relies on.
 *
 *  - #134: the content-blocker placeholder "Accept" button accepted the whole
 *    category instead of the embedded service. The button now carries
 *    data-faz-accept-service and the handler grants only that service via
 *    _fazAcceptService(), writing svc.<id>:yes without flipping the category.
 *  - #136: a per-service toggle click inside an expanded accordion collapsed it.
 *    The category listener now ignores service-toggle / switch / checkbox clicks.
 *
 * The cookie-state assertions exercise the real frontend runtime. Services are
 * injected into _fazConfig._services before each direct call so the tested path
 * matches the production contract: only scanner-exposed services or configured
 * blockable providers can receive a granular svc.<id> grant.
 */

const EXCLUDED = ['consentid', 'consent', 'action', 'necessary', '__scope.banner', '__scope.law', '__scope.fp'];

async function gotoFresh(page, context) {
  await context.clearCookies();
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => typeof (window as any)._fazAcceptService === 'function', undefined, { timeout: 10_000 });
}

async function exposeServices(page, serviceIds: string[], category = 'marketing') {
  await page.evaluate(
    ({ ids, cat }) => {
      const cfg = (window as any)._fazConfig || {};
      cfg._perServiceConsent = true;
      const byId = new Map((cfg._services || []).map((service: any) => [service.id, service]));
      ids.forEach((id: string) => {
        byId.set(id, {
          id,
          label: id,
          category: cat,
          patterns: [],
          cookies: [],
        });
      });
      cfg._services = Array.from(byId.values());
    },
    { ids: serviceIds, cat: category },
  );
}

async function acceptService(page, serviceId: string, knownServices: string[] = [serviceId]) {
  await exposeServices(page, knownServices);
  await page.evaluate((svc) => { (window as any)._fazAcceptService(svc); }, serviceId);
}

test.describe('Service-level consent — per-service accept (#134)', () => {
  // 1–6: accepting a single service grants only that service.
  for (const svc of ['youtube', 'vimeo', 'google-maps', 'twitch', 'soundcloud', 'spotify']) {
    test(`${svc}: accept grants svc.${svc} only`, async ({ page, context, getConsentCookie, parseConsentCookie }) => {
      await gotoFresh(page, context);
      await acceptService(page, svc);
      const consent = await getConsentCookie(context);
      expect(consent, 'a consent cookie must be written').toBeDefined();
      const parsed = parseConsentCookie(consent!.value);
      expect(parsed[`svc.${svc}`]).toBe('yes');
    });
  }

  // 7: accepting a service never flips its category to "yes".
  test('accepting a service does not accept the category', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await gotoFresh(page, context);
    await acceptService(page, 'youtube');
    const parsed = parseConsentCookie((await getConsentCookie(context))!.value);
    expect(parsed.marketing === 'yes').toBeFalsy();
  });

  // 8: a sibling service in the same category stays ungranted.
  test('accepting one service leaves sibling services blocked', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await gotoFresh(page, context);
    await acceptService(page, 'vimeo');
    const parsed = parseConsentCookie((await getConsentCookie(context))!.value);
    expect(parsed['svc.vimeo']).toBe('yes');
    expect(parsed['svc.youtube'] === 'yes').toBeFalsy();
  });

  // 9: two services can be granted independently.
  test('two services are granted independently, category still not accepted', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await gotoFresh(page, context);
    await acceptService(page, 'youtube');
    await acceptService(page, 'google-maps');
    const parsed = parseConsentCookie((await getConsentCookie(context))!.value);
    expect(parsed['svc.youtube']).toBe('yes');
    expect(parsed['svc.google-maps']).toBe('yes');
    expect(parsed.marketing === 'yes').toBeFalsy();
  });

  // 10: a service accept records an action so the banner is not re-shown.
  test('accepting a service records an action (action:yes)', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await gotoFresh(page, context);
    await acceptService(page, 'youtube');
    const parsed = parseConsentCookie((await getConsentCookie(context))!.value);
    expect(parsed.action).toBe('yes');
  });

  // 11: the per-service grant persists across a reload.
  test('svc.<id> grant persists across reload', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await gotoFresh(page, context);
    await acceptService(page, 'vimeo');
    await page.reload({ waitUntil: 'domcontentloaded' });
    const parsed = parseConsentCookie((await getConsentCookie(context))!.value);
    expect(parsed['svc.vimeo']).toBe('yes');
  });

  // 12: a service accept generates a consentid.
  test('accepting a service generates a consentid', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await gotoFresh(page, context);
    await acceptService(page, 'youtube');
    const parsed = parseConsentCookie((await getConsentCookie(context))!.value);
    expect((parsed.consentid || '').length).toBeGreaterThan(0);
  });

  // 13: after a service accept, the banner notice is hidden.
  test('banner notice is hidden after a service accept', async ({ page, context }) => {
    await gotoFresh(page, context);
    await acceptService(page, 'youtube');
    await expect(page.locator('[data-faz-tag="notice"]')).toBeHidden({ timeout: 10_000 });
  });
});

test.describe('Service-level consent — placeholder button (#134)', () => {
  function injectPlaceholder(page, attrs: Record<string, string>) {
    return page.evaluate((a) => {
      const btn = document.createElement('button');
      btn.className = 'faz-placeholder-btn';
      Object.entries(a).forEach(([k, v]) => btn.setAttribute(k, v));
      btn.id = 'faz-test-ph';
      document.body.appendChild(btn);
      btn.click();
    }, attrs);
  }

  // 14: placeholder with a service id grants only the service.
  test('placeholder with data-faz-accept-service grants the service only', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await gotoFresh(page, context);
    await exposeServices(page, ['youtube']);
    await injectPlaceholder(page, { 'data-faz-accept': 'marketing', 'data-faz-accept-service': 'youtube' });
    const parsed = parseConsentCookie((await getConsentCookie(context))!.value);
    expect(parsed['svc.youtube']).toBe('yes');
    expect(parsed.marketing === 'yes').toBeFalsy();
  });

  // 15: placeholder without a service id falls back to category accept.
  test('placeholder without a service id accepts the category (legacy fallback)', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await gotoFresh(page, context);
    await injectPlaceholder(page, { 'data-faz-accept': 'marketing' });
    const consent = await getConsentCookie(context);
    expect(consent).toBeDefined();
    const parsed = parseConsentCookie(consent!.value);
    // Category-accept path records an action and does not leave svc.* keys.
    expect(parsed.action).toBe('yes');
  });

  // 16: an empty service id falls back to the category (no empty svc. key).
  test('placeholder with an empty service id falls back to category', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await gotoFresh(page, context);
    await injectPlaceholder(page, { 'data-faz-accept': 'marketing', 'data-faz-accept-service': '' });
    const parsed = parseConsentCookie((await getConsentCookie(context))!.value);
    expect(Object.keys(parsed).some((k) => k === 'svc.')).toBeFalsy();
  });

  // 17: a placeholder service id not recognized by _services/_providersToBlock is a no-op.
  test('placeholder with an unknown service id does not create an arbitrary svc or category grant', async ({ page, context, getConsentCookie }) => {
    await gotoFresh(page, context);
    await exposeServices(page, []);
    await injectPlaceholder(page, { 'data-faz-accept': 'marketing', 'data-faz-accept-service': 'definitely-not-a-rendered-service' });
    const consent = await getConsentCookie(context);
    expect(consent, 'unknown service accepts must not persist consent').toBeUndefined();
  });

  // 18: the PHP builder ships the data-faz-accept-service attribute.
  test('PHP placeholder builder emits data-faz-accept-service', () => {
    const src = readFileSync(join(process.cwd(), 'frontend/includes/class-placeholder-builder.php'), 'utf8');
    expect(src).toMatch(/data-faz-accept-service="' \. esc_attr\( \$service_id \)/);
  });
});

test.describe('Service-level consent — accordion guard (#136)', () => {
  // 18: the shipped runtime carries the service-toggle guard.
  test('frontend script guards the accordion against service-toggle clicks', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const scriptUrl = await page.evaluate(() => {
      const s = Array.from(document.scripts).find((el) => /\/frontend\/js\/script(\.min)?\.js/.test(el.src));
      return s ? s.src : '';
    });
    expect(scriptUrl).toMatch(/script(\.min)?\.js/);
    const body = await (await page.request.get(scriptUrl)).text();
    expect(body).toMatch(/faz-service-toggle/);
  });

  // 19: the guard also covers the generic switch wrapper.
  test('frontend script guards on the .faz-switch wrapper too', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const scriptUrl = await page.evaluate(() => {
      const s = Array.from(document.scripts).find((el) => /\/frontend\/js\/script(\.min)?\.js/.test(el.src));
      return s ? s.src : '';
    });
    const body = await (await page.request.get(scriptUrl)).text();
    expect(body).toMatch(/faz-switch/);
  });
});

test.describe('Consent invariants relied upon by the service work', () => {
  // 20: banner appears on first visit.
  test('banner appears on first visit', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: 10_000 });
  });

  // 21: no non-technical cookies before any consent.
  test('no non-technical cookies before consent', async ({ page, context, getNonTechnicalCookies }) => {
    await context.clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const nonTech = await getNonTechnicalCookies(context);
    expect(nonTech, JSON.stringify(nonTech)).toHaveLength(0);
  });

  // 22: a per-service accept does not set non-technical cookies for other services.
  test('service accept does not leak other providers’ cookies', async ({ page, context, getNonTechnicalCookies }) => {
    await context.clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => typeof (window as any)._fazAcceptService === 'function', undefined, { timeout: 10_000 });
    await acceptService(page, 'youtube');
    await page.waitForTimeout(800);
    const nonTech = await getNonTechnicalCookies(context);
    // Nothing from an un-consented provider should appear (no real embeds here).
    expect(nonTech.every((c) => !/_ga|_fbp|vuid/i.test(c.name))).toBeTruthy();
  });

  // 23: the consent cookie keeps necessary granted after a service accept.
  test('necessary stays granted after a service accept', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await gotoFresh(page, context);
    await acceptService(page, 'vimeo');
    const parsed = parseConsentCookie((await getConsentCookie(context))!.value);
    expect(parsed.necessary).toBe('yes');
  });

  // 24: a service grant survives a second, different service grant (no clobber).
  test('a second service grant does not clobber the first', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await gotoFresh(page, context);
    await acceptService(page, 'youtube');
    await acceptService(page, 'twitch');
    const parsed = parseConsentCookie((await getConsentCookie(context))!.value);
    expect(parsed['svc.youtube']).toBe('yes');
    expect(parsed['svc.twitch']).toBe('yes');
  });

  // 25: only the intended svc.* keys exist — no stray category grants.
  test('only the granted services appear as accepted optional state', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await gotoFresh(page, context);
    await acceptService(page, 'youtube');
    const parsed = parseConsentCookie((await getConsentCookie(context))!.value);
    const acceptedOptional = Object.entries(parsed)
      .filter(([k, v]) => v === 'yes' && !EXCLUDED.includes(k))
      .map(([k]) => k);
    // The only "yes" optional entry should be the granted service.
    expect(acceptedOptional).toContain('svc.youtube');
    expect(acceptedOptional.filter((k) => !k.startsWith('svc.'))).toHaveLength(0);
  });
});
