/** Browser component integration, independent of the shared WordPress site.
 * The PHP runtime builds every jurisdiction's category payload. The production
 * minified frontend makes the decisions, restores blocked scripts and persists
 * cookies. Probe requests are intercepted locally: no third party receives data.
 * This complements (does not replace) the full WordPress integration suite.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
const root = fileURLToPath(new URL('../../', import.meta.url));
const payloads = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', [root + 'tests/unit/helpers/consent-ruleset-payload.php'], { encoding: 'utf8' }));
const source = readFileSync(root + 'frontend/js/script.min.js', 'utf8');
for (const payload of payloads) for (const law of ['gdpr', 'ccpa']) {
  test(`${payload.ruleset.id} / ${law}: choice, network blocking, withdrawal and reload`, async ({ page, context }) => {
    const requests: string[] = [];
    await page.route('https://intent.example.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname.startsWith('/probe/')) {
        requests.push(url.pathname);
        await route.fulfill({ contentType: 'text/javascript', body: 'window.probesExecuted=(window.probesExecuted||0)+1;' });
      } else await route.fulfill({ contentType: 'text/html', body: '<!doctype html><body><button id="accept">Accept</button><button id="reject">Reject</button><button id="save">Save</button></body>' });
    });
    const boot = async () => {
      await page.goto('https://intent.example.test/');
      await page.evaluate(({ categories, law }) => {
        const w = window as any;
        w._fazConfig = {
          _runtimeGeo: true, _activeLaw: law, _bannerSlug: law, _expiry: 180,
          _categories: categories, _services: [], _providersToBlock: [], _cookieCategoryMap: {},
          _whitelistedCookiePatterns: [], _userWhitelist: [], _perServiceConsent: false, _perCookieConsent: false,
          _rootDomain: '', _bannerConfig: { settings: { applicableLaw: law }, behaviours: {} }, i18n: {},
        };
        // The component fixture drives real consent handlers explicitly. Full
        // DOMContentLoaded/banner bootstrap belongs to the WordPress suite.
        const add = document.addEventListener.bind(document);
        w.restoreListener = () => { document.addEventListener = add; };
        document.addEventListener = ((type: string, ...args: any[]) => type === 'DOMContentLoaded' ? undefined : (add as any)(type, ...args)) as any;
      }, { categories: payload.categories, law });
      await page.addScriptTag({ content: source });
      await page.evaluate(() => {
        const w = window as any;
        w.restoreListener();
        if (w.fazcookie._fazConsentStore.get('action') !== 'yes') w._fazSeedInitialState();
        document.getElementById('accept')!.onclick = () => w._fazAcceptCookies('all');
        document.getElementById('reject')!.onclick = () => w._fazAcceptCookies('reject');
        document.getElementById('save')!.onclick = () => w._fazAcceptCookies('custom');
      });
    };
    const states = () => page.evaluate(() => Object.fromEntries((window as any).fazcookie._fazConsentStore));
    const probe = async (slug: string, name: string, allowed: boolean) => {
      await page.evaluate(({ slug, name }) => {
        const script = document.createElement('script');
        script.type = 'text/plain'; script.dataset.fazCategory = slug;
        script.src = `https://intent.example.test/probe/${name}.js`;
        script.id = name;
        document.body.appendChild(script);
        (window as any)._fazUnblockServerSide();
      }, { slug, name });
      if (allowed) await expect.poll(() => requests.includes(`/probe/${name}.js`)).toBe(true);
      else {
        // The live MutationObserver may remove a blocked node into its backup
        // queue. Either parked representation is valid; executing it is not.
        await page.waitForTimeout(100);
        expect(await page.evaluate(id => {
          const node = document.getElementById(id);
          return !node || ['text/plain', 'javascript/blocked'].includes(node.getAttribute('type') || '');
        }, name)).toBe(true);
        expect(requests).not.toContain(`/probe/${name}.js`);
      }
    };
    await boot();
    expect((await context.cookies()).filter(c => c.name === 'fazcookie-consent')).toHaveLength(0);
    await probe('analytics', 'initial', payload.ruleset.ui.default_categories.analytics === 'granted');
    await page.click('#accept');
    for (const c of payload.categories) expect((await states())[c.slug], c.slug).toBe(c.requiresSeparateOptIn ? 'no' : 'yes');
    await probe('analytics', 'accepted', true);
    await page.evaluate(() => {
      (window as any)._fazConfig._preferenceOriginTag = 'donotsell-button';
      const input = document.createElement('input'); input.type = 'checkbox';
      input.id = 'fazCCPAOptOut'; input.checked = true; document.body.appendChild(input);
    });
    await page.click('#save');
    expect((await states()).marketing).toBe('no');
    expect((await states()).analytics).toBe('yes');
    await probe('marketing', 'optout', false);
    await page.evaluate(() => document.getElementById('fazCCPAOptOut')!.remove());
    await page.evaluate(() => {
      const w = window as any;
      w._fazConfig._preferenceOriginTag = 'settings-button';
      for (const c of w._fazConfig._categories.filter((c: any) => !c.isNecessary)) {
        const input = document.createElement('input'); input.type = 'checkbox';
        input.id = `fazSwitch${c.slug}`; input.checked = c.slug === 'functional'; document.body.appendChild(input);
      }
    });
    await page.click('#save');
    expect((await states()).functional).toBe('yes');
    expect((await states()).analytics).toBe('no');
    await probe('analytics', 'withdrawn', false);
    await boot();
    expect((await states()).functional).toBe('yes');
    expect((await states()).analytics).toBe('no');
    await probe('analytics', 'returning', false);
    await page.click('#reject');
    for (const c of payload.categories) expect((await states())[c.slug]).toBe(c.isNecessary ? 'yes' : 'no');
    await boot();
    for (const c of payload.categories) expect((await states())[c.slug]).toBe(c.isNecessary ? 'yes' : 'no');
    await probe('marketing', 'rejected', false);
    await page.evaluate(() => Object.defineProperty(navigator, 'globalPrivacyControl', { value: true, configurable: true }));
    await page.click('#accept');
    expect((await states()).marketing).toBe('no');
    expect((await states()).analytics).toBe('yes');
    await probe('marketing', 'gpc', false);
    await context.addCookies([{ name: 'fazcookie-consent', value: 'action:yes,consent:yes,necessary:yes,analytics:garbage,marketing:0,functional:true,profiling:1', url: 'https://intent.example.test/' }]);
    await boot();
    await probe('analytics', 'malformed', false);
  });
}
