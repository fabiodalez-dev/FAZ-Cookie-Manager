/**
 * E2E — CCPA/CPRA opt-out script blocking is law-aware (1.17.2).
 *
 * CCPA/CPRA is an OPT-OUT regime: personal data may be sold/shared (and the
 * corresponding scripts may run) UNTIL the visitor exercises the "Do Not Sell
 * or Share" opt-out. So a CCPA banner must NOT block sale-flagged scripts
 * server-side on first visit (it is a NOTICE, not a gate); only once the
 * visitor opts out does the consent cookie carry the category as ":no" and the
 * server block it. An opt-IN (GDPR) banner, by contrast, blocks every
 * non-necessary category until explicit consent.
 *
 * A fixture plugin (faz-e2e-ccpa-blocking) prints a Google Analytics
 * <script> in the footer — a known provider in the "analytics" category — so we
 * can read the server-rendered HTML and check whether the tag was rewritten to
 * `type="text/plain" data-faz-category="analytics"` (blocked) or left intact.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { ensureFixturePlugin, wp, wpEval } from '../utils/wp-env';

const PROBE_RE = /<script[^>]*id=["']faz-e2e-ga-probe["'][^>]*>/i;

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

  test('CCPA banner does NOT block sale-flagged scripts on first visit', async ({ request, wpBaseURL }) => {
    setActiveBannerLaw('ccpa');
    const res = await request.get(`${wpBaseURL}/?n=${Date.now()}`, { headers: { Cookie: 'nocache=1' } });
    const html = await res.text();
    const tag = (html.match(PROBE_RE) || [''])[0];
    expect(tag, 'GA probe tag must be present in the rendered HTML').not.toBe('');
    expect(tag, 'CCPA first visit must NOT block the analytics script (opt-out model)').not.toContain('text/plain');
    expect(tag).not.toContain('data-faz-category');
  });

  test('CCPA banner DOES block once the visitor has opted out', async ({ request, wpBaseURL }) => {
    setActiveBannerLaw('ccpa');
    const rev = serverConsentRevision();
    const optOutCookie = `fazcookie-consent=consent:no,action:yes,necessary:yes,analytics:no,marketing:no,functional:yes,consentid:e2eccpa1,rev:${rev}`;
    const res = await request.get(`${wpBaseURL}/?n=${Date.now()}`, { headers: { Cookie: optOutCookie } });
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
