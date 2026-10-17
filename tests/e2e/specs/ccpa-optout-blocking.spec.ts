/**
 * E2E — CCPA/CPRA opt-out script blocking is law-aware (1.17.2).
 *
 * CCPA/CPRA is an OPT-OUT regime: personal data may be sold/shared (and the
 * corresponding scripts may run) UNTIL the visitor exercises the "Do Not Sell
 * or Share" opt-out. So a CCPA banner must not GATE sale-flagged scripts on a
 * first visit (it is a NOTICE): they run for a visitor with no opt-out. Once
 * the visitor opts out the consent cookie carries the category as ":no" and
 * they never run. An opt-IN (GDPR) banner, by contrast, blocks every
 * non-necessary category until explicit consent.
 *
 * Since 1.34.3 the CCPA first-visit HTML PARKS the sale-flagged scripts and
 * script.js releases them on boot. That HTML is the copy a page cache replays,
 * and the visitor it is replayed to may send Sec-GPC or carry the Do Not Sell
 * cookie, which the server never saw: shipped executable, the tracker ran from
 * the parser before the runtime could honour the opt-out. So the first two
 * tests read the wire (parked) and then the page after the runtime ran
 * (released without a signal, still parked with GPC).
 * `faz_ccpa_park_sell_share_by_default` false restores the executable HTML.
 *
 * A fixture plugin (faz-e2e-ccpa-blocking) prints a Google Analytics
 * <script> in the footer — a known provider in the "analytics" category — so we
 * can read the server-rendered HTML and check whether the tag was rewritten to
 * `type="text/plain" data-faz-category="analytics"` (blocked) or left intact.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { ensureFixturePlugin, wp, wpEval } from '../utils/wp-env';

const PROBE_RE = /<script[^>]*id=["']faz-e2e-ga-probe["'][^>]*>/i;
const CALIFORNIA_HEADERS = {
  'X-FAZ-E2E-Country': 'US',
  'X-FAZ-E2E-Region': 'CA',
};

function setActiveBannerLaw(law: 'gdpr' | 'ccpa'): void {
  wpEval(
    `global $wpdb; $t=$wpdb->prefix.'faz_banners';` +
    `$id=(int)$wpdb->get_var("SELECT banner_id FROM $t WHERE banner_default=1 LIMIT 1");` +
    `if(!$id){$id=(int)$wpdb->get_var("SELECT banner_id FROM $t WHERE status=1 LIMIT 1");}` +
    `if($id){$s=json_decode($wpdb->get_var($wpdb->prepare("SELECT settings FROM $t WHERE banner_id=%d",$id)),true);` +
    `if(!is_array($s))$s=array();if(!isset($s['settings'])||!is_array($s['settings']))$s['settings']=array();` +
    `$s['settings']['applicableLaw']='` + law + `';` +
    `$wpdb->update($t,array('settings'=>wp_json_encode($s)),array('banner_id'=>$id));}` +
    `\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();` +
    `delete_option('faz_banner_template');`,
  );
}

function serverConsentRevision(): number {
  const out = wpEval(
    `$s=get_option('faz_settings');$r=is_array($s)&&isset($s['general']['consent_revision'])?$s['general']['consent_revision']:1;echo max(1,(int)$r);`,
  );
  const n = parseInt(out.trim().split('\n').pop() || '1', 10);
  return Number.isFinite(n) && n > 0 ? n : 1;
}

test.describe('CCPA opt-out script blocking is law-aware (1.17.2)', () => {
  test.beforeAll(() => {
    ensureFixturePlugin('faz-e2e-ccpa-blocking');
  });

  test.afterAll(() => {
    setActiveBannerLaw('gdpr');
    // Deactivate the probe so its GA <script> does not leak into other specs.
    try {
      wp(['plugin', 'deactivate', 'faz-e2e-ccpa-blocking']);
    } catch {
      /* best-effort cleanup */
    }
  });

  test('CCPA first visit: sale-flagged script parked on the wire, released by the runtime', async ({ request, browser, wpBaseURL }) => {
    setActiveBannerLaw('ccpa');
    const res = await request.get(`${wpBaseURL}/?n=${Date.now()}`, {
      headers: { Cookie: 'nocache=1', ...CALIFORNIA_HEADERS },
    });
    const html = await res.text();
    const tag = (html.match(PROBE_RE) || [''])[0];
    expect(tag, 'GA probe tag must be present in the rendered HTML').not.toBe('');
    expect(tag, 'the cacheable first-visit HTML parks the sale-flagged script').toContain('text/plain');
    expect(tag).toContain('data-faz-category="analytics"');

    // No signal: the opt-out model still runs it, once script.js has started.
    const ctx = await browser.newContext({ extraHTTPHeaders: CALIFORNIA_HEADERS });
    await ctx.route(/google-analytics\.com/, (route) => route.fulfill({ status: 200, contentType: 'application/javascript', body: '' }));
    try {
      const page = await ctx.newPage();
      await page.goto(`${wpBaseURL}/?n=${Date.now()}`, { waitUntil: 'domcontentloaded' });
      await expect.poll(
        () => page.evaluate(() => {
          const el = document.getElementById('faz-e2e-ga-probe') as HTMLScriptElement | null;
          return el ? el.type : 'missing';
        }),
        { message: 'without GPC / Do Not Sell / opt-out the runtime releases the script', timeout: 10_000 },
      ).toBe('text/javascript');
    } finally {
      await ctx.close();
    }
  });

  test('CCPA + GPC: the same parked HTML, and the runtime never releases it', async ({ request, browser, wpBaseURL }) => {
    setActiveBannerLaw('ccpa');
    const res = await request.get(`${wpBaseURL}/?n=${Date.now()}`, {
      headers: { Cookie: 'nocache=1', 'Sec-GPC': '1', ...CALIFORNIA_HEADERS },
    });
    const tag = ((await res.text()).match(PROBE_RE) || [''])[0];
    expect(tag, 'GA probe tag must be present').not.toBe('');
    expect(tag, 'GPC: parked server-side').toContain('text/plain');

    const ctx = await browser.newContext({ extraHTTPHeaders: { 'Sec-GPC': '1', ...CALIFORNIA_HEADERS } });
    await ctx.addInitScript(() => {
      Object.defineProperty(navigator, 'globalPrivacyControl', { get: () => true, configurable: true });
    });
    await ctx.route(/google-analytics\.com/, (route) => route.fulfill({ status: 200, contentType: 'application/javascript', body: '' }));
    try {
      const page = await ctx.newPage();
      await page.goto(`${wpBaseURL}/?n=${Date.now()}`, { waitUntil: 'load' });
      await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), undefined, { timeout: 10_000 });
      // The restore pass of a recorded state runs on timers up to 2 s.
      await page.waitForTimeout(2_500);
      const type = await page.evaluate(() => {
        const el = document.getElementById('faz-e2e-ga-probe') as HTMLScriptElement | null;
        return el ? el.type : 'missing';
      });
      expect(type, 'with navigator.globalPrivacyControl the script stays parked').toBe('text/plain');
    } finally {
      await ctx.close();
    }
  });

  test('CCPA banner DOES block once the visitor has opted out', async ({ request, wpBaseURL }) => {
    setActiveBannerLaw('ccpa');
    const rev = serverConsentRevision();
    const optOutCookie = `fazcookie-consent=consent:no,action:yes,necessary:yes,analytics:no,marketing:no,functional:yes,consentid:e2eccpa1,rev:${rev}`;
    const res = await request.get(`${wpBaseURL}/?n=${Date.now()}`, {
      headers: { Cookie: optOutCookie, ...CALIFORNIA_HEADERS },
    });
    const tag = ((await res.text()).match(PROBE_RE) || [''])[0];
    expect(tag, 'GA probe tag must be present').not.toBe('');
    expect(tag, 'after a valid opt-out (analytics:no) the script must be blocked').toContain('text/plain');
  });

  test('GDPR banner blocks sale-flagged scripts until consent (control)', async ({ request, wpBaseURL }) => {
    setActiveBannerLaw('gdpr');
    const res = await request.get(`${wpBaseURL}/?n=${Date.now()}`, { headers: { Cookie: 'nocache=1' } });
    const tag = ((await res.text()).match(PROBE_RE) || [''])[0];
    expect(tag, 'GA probe tag must be present').not.toBe('');
    expect(tag, 'GDPR first visit must block the analytics script (opt-in model)').toContain('text/plain');
  });
});
