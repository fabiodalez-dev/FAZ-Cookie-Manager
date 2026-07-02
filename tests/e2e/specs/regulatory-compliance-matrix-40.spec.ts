/**
 * Regulatory compliance matrix: 40 jurisdiction-level contract tests.
 *
 * These tests validate the plugin's declared compliance behaviour, not legal
 * conclusions. Each case checks the catalogue source, geo resolver, runtime
 * consent defaults, GPC/GPP obligations, UI withdrawal path, sensitive-data
 * treatment and Google Consent Mode mapping.
 *
 * Primary regulatory anchors used to shape the invariants:
 * - EDPB Guidelines 05/2020: valid consent and withdrawal as easy as granting.
 *   https://www.edpb.europa.eu/sites/default/files/files/file1/edpb_guidelines_202005_consent_en.pdf
 * - EDPB Cookie Banner Taskforce report: non-essential trackers and withdrawal.
 *   https://www.edpb.europa.eu/system/files/2023-01/edpb_20230118_report_cookie_banner_taskforce_en.pdf
 * - CPPA Regulations section 7025 and 2026 updates: opt-out preference signals.
 *   https://cppa.ca.gov/regulations/
 * - Colorado AG: GPC is the recognized Universal Opt-Out Mechanism.
 *   https://coag.gov/opt-out/
 *
 * Every ruleset also carries its own official regulator URL and statute
 * citation; this suite validates those per jurisdiction.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '../fixtures/wp-fixture';

type ConsentModel = 'opt-in' | 'opt-out' | 'hybrid' | 'opt-out-with-sensitive-opt-in';
type CategoryState = 'granted' | 'granted-locked' | 'denied' | 'denied-until-action';
type Profile = 'strict' | 'strict-sensitive' | 'fallback' | 'pipeda' | 'hybrid' | 'us-sensitive';

type Scenario = {
  id: string;
  label: string;
  country: string;
  region?: string;
  vpn?: boolean;
  model: ConsentModel;
  profile: Profile;
  citationToken: string;
  officialHost: string;
  gpcHonored?: boolean;
  gpcRequired?: boolean;
  doNotSell?: boolean;
  sensitive?: boolean;
};

type Ruleset = {
  id: string;
  version: string;
  display_name: string;
  law_references: string[];
  applies_to: { countries: string[]; regions?: string[] };
  model: ConsentModel;
  native_lang: string;
  official_resources_url: string[];
  signals: {
    tcf_v22: boolean;
    gpp: { enabled: boolean; section: string | null };
    gpc_honored: boolean;
    gpc_required: boolean;
    dnt_honored: boolean;
    cmv2: Record<string, CategoryState>;
  };
  ui: {
    equal_weight_buttons: boolean;
    donotsell_link_required: boolean;
    sensitive_separate_optin: boolean;
    revisit_widget_required: boolean;
    default_categories: Record<string, CategoryState>;
  };
  _meta: {
    behavior_review_date: string | null;
    next_review_due?: string | null;
    notes?: string | null;
  };
};

type RuntimeProbe = {
  resolved: string;
  model_to_law: 'gdpr' | 'ccpa';
  category_bool: Record<string, boolean | null>;
  default_consent: Record<string, { gdpr: boolean; ccpa: boolean }>;
  gcm_row: Record<string, string>;
  error?: string;
};

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = dirname(dirname(dirname(HERE)));
const RULESETS_DIR = join(ROOT, 'admin/modules/geo-routing/rulesets');
const PROBE = join(ROOT, 'tests/e2e/fixtures/compliance-runtime-probe.php');

const scenarios: Scenario[] = [
  { id: 'gdpr-strict', label: 'GDPR strict baseline (Austria)', country: 'AT', model: 'opt-in', profile: 'strict', citationToken: 'GDPR', officialHost: 'edpb.europa.eu', gpcHonored: true },
  { id: 'gdpr-italy', label: 'GDPR + Italian Garante cookie rules', country: 'IT', model: 'opt-in', profile: 'strict', citationToken: 'Garante Privacy', officialHost: 'garanteprivacy.it', gpcHonored: true },
  { id: 'gdpr-france', label: 'GDPR + CNIL cookie guidance', country: 'FR', model: 'opt-in', profile: 'strict', citationToken: 'CNIL', officialHost: 'cnil.fr', gpcHonored: true },
  { id: 'gdpr-germany', label: 'GDPR + German TTDSG', country: 'DE', model: 'opt-in', profile: 'strict', citationToken: 'TTDSG', officialHost: 'gesetze-im-internet.de', gpcHonored: true },
  { id: 'gdpr-spain', label: 'GDPR + Spanish AEPD guidance', country: 'ES', model: 'opt-in', profile: 'strict', citationToken: 'AEPD', officialHost: 'aepd.es', gpcHonored: true },
  { id: 'uk-gdpr-pecr', label: 'UK GDPR + PECR', country: 'GB', model: 'opt-in', profile: 'strict', citationToken: 'PECR', officialHost: 'ico.org.uk', gpcHonored: true },
  { id: 'lgpd-brazil', label: 'Brazil LGPD', country: 'BR', model: 'opt-in', profile: 'strict', citationToken: 'LGPD', officialHost: 'gov.br', gpcHonored: true },
  { id: 'pipeda-canada', label: 'Canada PIPEDA federal baseline', country: 'CA', model: 'hybrid', profile: 'hybrid', gpcHonored: true, citationToken: 'PIPEDA', officialHost: 'priv.gc.ca' },
  { id: 'law25-quebec', label: 'Quebec Law 25', country: 'CA', region: 'CA-QC', model: 'hybrid', profile: 'hybrid', citationToken: 'Law 25', officialHost: 'cai.gouv.qc.ca', gpcHonored: true, doNotSell: true },
  { id: 'pipl-china', label: 'China PIPL', country: 'CN', model: 'opt-in', profile: 'strict-sensitive', citationToken: 'PIPL', officialHost: 'cac.gov.cn', sensitive: true },
  { id: 'dpdpa-india', label: 'India DPDPA', country: 'IN', model: 'opt-in', profile: 'strict', citationToken: 'DPDPA', officialHost: 'meity.gov.in' },
  { id: 'appi-japan', label: 'Japan APPI', country: 'JP', model: 'hybrid', profile: 'strict-sensitive', citationToken: 'APPI', officialHost: 'ppc.go.jp', sensitive: true },
  { id: 'pipa-korea', label: 'South Korea PIPA', country: 'KR', model: 'opt-in', profile: 'strict-sensitive', citationToken: 'PIPA', officialHost: 'pipc.go.kr', sensitive: true },
  { id: 'pdpa-singapore', label: 'Singapore PDPA', country: 'SG', model: 'opt-in', profile: 'strict', citationToken: 'PDPA', officialHost: 'pdpc.gov.sg' },
  { id: 'pdpa-thailand', label: 'Thailand PDPA', country: 'TH', model: 'opt-in', profile: 'strict', citationToken: 'PDPA', officialHost: 'mdes.go.th' },
  { id: 'pdpa-malaysia', label: 'Malaysia PDPA', country: 'MY', model: 'opt-in', profile: 'strict', citationToken: 'PDPA', officialHost: 'pdp.gov.my' },
  { id: 'pdpd-vietnam', label: 'Vietnam PDPD', country: 'VN', model: 'opt-in', profile: 'strict-sensitive', citationToken: 'PDPD', officialHost: 'mic.gov.vn', sensitive: true },
  { id: 'popia-southafrica', label: 'South Africa POPIA', country: 'ZA', model: 'opt-in', profile: 'strict', citationToken: 'POPIA', officialHost: 'inforegulator.org.za' },
  { id: 'privacy-act-australia', label: 'Australia Privacy Act', country: 'AU', model: 'hybrid', profile: 'hybrid', citationToken: 'Privacy Act 1988', officialHost: 'oaic.gov.au' },
  { id: 'privacy-act-newzealand', label: 'New Zealand Privacy Act', country: 'NZ', model: 'opt-in', profile: 'strict', citationToken: 'Privacy Act 2020', officialHost: 'privacy.org.nz' },
  { id: 'fallback-gdpr-most-protective', label: 'Unknown/VPN most-protective fallback', country: 'XX', vpn: true, model: 'opt-in', profile: 'fallback', citationToken: 'most-protective', officialHost: 'edpb.europa.eu', gpcHonored: true, sensitive: true },
  { id: 'ccpa-california', label: 'California CCPA/CPRA', country: 'US', region: 'US-CA', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'CCPA', officialHost: 'cppa.ca.gov', gpcHonored: true, gpcRequired: true, doNotSell: true, sensitive: true },
  { id: 'cpa-colorado', label: 'Colorado Privacy Act', country: 'US', region: 'US-CO', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law CO', officialHost: 'coag.gov', gpcHonored: true, gpcRequired: true, doNotSell: true, sensitive: true },
  { id: 'ctdpa-connecticut', label: 'Connecticut Data Privacy Act', country: 'US', region: 'US-CT', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law CT', officialHost: 'ct.gov', gpcHonored: true, gpcRequired: true, doNotSell: true, sensitive: true },
  { id: 'vcdpa-virginia', label: 'Virginia Consumer Data Protection Act', country: 'US', region: 'US-VA', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law VA', officialHost: 'oag.state.va.us', doNotSell: true, sensitive: true },
  { id: 'ucpa-utah', label: 'Utah Consumer Privacy Act', country: 'US', region: 'US-UT', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law UT', officialHost: 'attorneygeneral.utah.gov', doNotSell: true, sensitive: true },
  { id: 'icdpa-iowa', label: 'Iowa Consumer Data Protection Act', country: 'US', region: 'US-IA', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law IA', officialHost: 'iowaattorneygeneral.gov', doNotSell: true, sensitive: true },
  { id: 'tipa-tennessee', label: 'Tennessee Information Protection Act', country: 'US', region: 'US-TN', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law TN', officialHost: 'tn.gov', doNotSell: true, sensitive: true },
  { id: 'mcdpa-montana', label: 'Montana Consumer Data Privacy Act', country: 'US', region: 'US-MT', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law MT', officialHost: 'dojmt.gov', gpcHonored: true, gpcRequired: true, doNotSell: true, sensitive: true },
  { id: 'tdpsa-texas', label: 'Texas Data Privacy and Security Act', country: 'US', region: 'US-TX', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law TX', officialHost: 'texasattorneygeneral.gov', gpcHonored: true, gpcRequired: true, doNotSell: true, sensitive: true },
  { id: 'ocpa-oregon', label: 'Oregon Consumer Privacy Act', country: 'US', region: 'US-OR', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law OR', officialHost: 'doj.state.or.us', gpcHonored: true, gpcRequired: true, doNotSell: true, sensitive: true },
  { id: 'fdbr-florida', label: 'Florida Digital Bill of Rights', country: 'US', region: 'US-FL', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law FL', officialHost: 'myfloridalegal.com', doNotSell: true, sensitive: true },
  { id: 'delaware-dpdpa', label: 'Delaware Personal Data Privacy Act', country: 'US', region: 'US-DE', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law DE', officialHost: 'attorneygeneral.delaware.gov', gpcHonored: true, gpcRequired: true, doNotSell: true, sensitive: true },
  { id: 'njdpl-newjersey', label: 'New Jersey Data Privacy Law', country: 'US', region: 'US-NJ', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law NJ', officialHost: 'nj.gov', gpcHonored: true, gpcRequired: true, doNotSell: true, sensitive: true },
  { id: 'nhpl-newhampshire', label: 'New Hampshire Privacy Law', country: 'US', region: 'US-NH', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law NH', officialHost: 'doj.nh.gov', gpcHonored: true, gpcRequired: true, doNotSell: true, sensitive: true },
  { id: 'kcdpa-kentucky', label: 'Kentucky Consumer Data Protection Act', country: 'US', region: 'US-KY', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law KY', officialHost: 'ag.ky.gov', doNotSell: true, sensitive: true },
  { id: 'modpa-maryland', label: 'Maryland Online Data Privacy Act', country: 'US', region: 'US-MD', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law MD', officialHost: 'marylandattorneygeneral.gov', gpcHonored: true, gpcRequired: true, doNotSell: true, sensitive: true },
  { id: 'mcdpa-minnesota', label: 'Minnesota Consumer Data Privacy Act', country: 'US', region: 'US-MN', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law MN', officialHost: 'ag.state.mn.us', gpcHonored: true, gpcRequired: true, doNotSell: true, sensitive: true },
  { id: 'ridtppa-rhodeisland', label: 'Rhode Island Data Transparency and Privacy Protection Act', country: 'US', region: 'US-RI', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law RI', officialHost: 'riag.ri.gov', doNotSell: true, sensitive: true },
  { id: 'icdpa-indiana', label: 'Indiana Consumer Data Protection Act', country: 'US', region: 'US-IN', model: 'opt-out-with-sensitive-opt-in', profile: 'us-sensitive', citationToken: 'State law IN', officialHost: 'in.gov', doNotSell: true, sensitive: true },
];

if (scenarios.length !== 40) {
  throw new Error(`Regulatory matrix must define exactly 40 tests; found ${scenarios.length}.`);
}

const strictCategories: Record<string, CategoryState> = {
  necessary: 'granted-locked',
  functional: 'denied',
  analytics: 'denied',
  marketing: 'denied',
  profiling: 'denied',
};

const hybridCategories: Record<string, CategoryState> = {
  necessary: 'granted-locked',
  functional: 'granted',
  analytics: 'denied-until-action',
  marketing: 'denied-until-action',
  profiling: 'denied-until-action',
};

const grantedCategories: Record<string, CategoryState> = {
  necessary: 'granted-locked',
  functional: 'granted',
  analytics: 'granted',
  marketing: 'granted',
  profiling: 'granted',
};

const usCategories: Record<string, CategoryState> = {
  necessary: 'granted-locked',
  functional: 'granted',
  analytics: 'granted',
  marketing: 'granted',
  profiling: 'denied-until-action',
};

function expectedCategories(profile: Profile): Record<string, CategoryState> {
  if (profile === 'pipeda') return grantedCategories;
  if (profile === 'hybrid') return hybridCategories;
  if (profile === 'us-sensitive') return usCategories;
  return strictCategories;
}

function stateToBool(state: CategoryState): boolean {
  return state === 'granted' || state === 'granted-locked';
}

function normalizeCmv2(state: CategoryState): 'granted' | 'denied' {
  return state === 'granted' || state === 'granted-locked' ? 'granted' : 'denied';
}

function loadRuleset(id: string): Ruleset {
  return JSON.parse(readFileSync(join(RULESETS_DIR, `${id}.json`), 'utf8')) as Ruleset;
}

function expectedGcmRow(ruleset: Ruleset): Record<string, string> {
  const cmv2 = ruleset.signals.cmv2;
  const functional = (
    normalizeCmv2(cmv2.functionality_storage) === 'denied'
    || normalizeCmv2(cmv2.personalization_storage) === 'denied'
  ) ? 'denied' : 'granted';

  return {
    marketing: normalizeCmv2(cmv2.ad_storage),
    ad_storage: normalizeCmv2(cmv2.ad_storage),
    analytics: normalizeCmv2(cmv2.analytics_storage),
    analytics_storage: normalizeCmv2(cmv2.analytics_storage),
    ad_user_data: normalizeCmv2(cmv2.ad_user_data),
    ad_personalization: normalizeCmv2(cmv2.ad_personalization),
    necessary: normalizeCmv2(cmv2.security_storage),
    security_storage: normalizeCmv2(cmv2.security_storage),
    functional,
    functionality_storage: functional,
    personalization_storage: functional,
    regions: 'All',
  };
}

let runtime: Record<string, RuntimeProbe> = {};

test.describe('Regulatory compliance matrix — 40 jurisdictions', () => {
  test.beforeAll(() => {
    const input = scenarios.map(({ id, country, region = '', vpn = false }) => ({
      id,
      country,
      region,
      vpn,
    }));
    const output = execFileSync('php', [PROBE], {
      cwd: ROOT,
      encoding: 'utf8',
      input: JSON.stringify(input),
      timeout: 30_000,
    });
    runtime = JSON.parse(output) as Record<string, RuntimeProbe>;
  });

  scenarios.forEach((scenario, index) => {
    test(`${String(index + 1).padStart(2, '0')} — ${scenario.label}`, async () => {
      const ruleset = loadRuleset(scenario.id);
      const probe = runtime[scenario.id];
      const categories = expectedCategories(scenario.profile);
      const expectedLaw = scenario.model === 'opt-out' ? 'ccpa' : 'gdpr';

      expect(probe, 'runtime probe produced a result').toBeDefined();
      expect(probe.error, 'runtime probe loaded the ruleset').toBeUndefined();

      // Geo resolution must select the exact jurisdictional contract.
      expect(probe.resolved, 'country/region resolver result').toBe(scenario.id);
      expect(ruleset.id, 'ruleset id matches its filename').toBe(scenario.id);
      expect(ruleset.applies_to.countries, 'country is declared in applies_to').toContain(scenario.country);
      if (scenario.region) {
        expect(ruleset.applies_to.regions, 'region is declared in applies_to').toContain(scenario.region);
      }

      // The catalogue must remain traceable to a named law and regulator.
      expect(ruleset.law_references.join(' '), 'statute/regulation citation').toContain(scenario.citationToken);
      expect(ruleset.official_resources_url.length, 'official resources are present').toBeGreaterThan(0);
      for (const rawUrl of ruleset.official_resources_url) {
        const url = new URL(rawUrl);
        expect(url.protocol, 'official resource uses TLS').toBe('https:');
      }
      expect(
        ruleset.official_resources_url.some((rawUrl) => new URL(rawUrl).hostname.includes(scenario.officialHost)),
        `official regulator host includes ${scenario.officialHost}`,
      ).toBe(true);

      // Compliance metadata must be reviewable and not already stale.
      expect(ruleset.version, 'ruleset version is semantic').toMatch(/^\d+\.\d+\.\d+$/);
      expect(ruleset._meta.behavior_review_date, 'behaviour review date is recorded').toMatch(/^\d{4}-\d{2}-\d{2}$/);
      expect(ruleset._meta.next_review_due, 'next review date is recorded').toMatch(/^\d{4}-\d{2}-\d{2}$/);
      expect(
        Date.parse(`${ruleset._meta.next_review_due}T23:59:59Z`),
        'legal behaviour review is not overdue',
      ).toBeGreaterThanOrEqual(Date.now());

      // Consent model and UI withdrawal controls.
      expect(ruleset.model).toBe(scenario.model);
      expect(probe.model_to_law, 'runtime binary enforcement mapping').toBe(expectedLaw);
      expect(ruleset.ui.equal_weight_buttons, 'accept/reject controls have equal weight').toBe(true);
      expect(ruleset.ui.revisit_widget_required, 'withdrawal/revisit remains available').toBe(true);
      expect(ruleset.ui.donotsell_link_required).toBe(Boolean(scenario.doNotSell));
      expect(ruleset.ui.sensitive_separate_optin).toBe(Boolean(scenario.sensitive));

      // GPC and GPP must agree with each jurisdiction's declared obligations.
      expect(ruleset.signals.gpc_honored).toBe(Boolean(scenario.gpcHonored));
      expect(ruleset.signals.gpc_required).toBe(Boolean(scenario.gpcRequired));
      if (ruleset.signals.gpc_required) {
        expect(ruleset.signals.gpc_honored, 'a required GPC signal cannot be ignored').toBe(true);
      }
      const isUsState = Boolean(scenario.region?.startsWith('US-'));
      expect(ruleset.signals.gpp.enabled, 'US state laws expose an IAB GPP section').toBe(isUsState);
      expect(ruleset.signals.gpp.section, 'GPP section matches the state code').toBe(
        isUsState ? scenario.region!.replace('-', '').toLowerCase() : null,
      );

      // Category defaults must encode prior consent, sensitive opt-in and
      // necessary-cookie exemptions without relying on frontend heuristics.
      expect(ruleset.ui.default_categories).toEqual(categories);
      for (const [slug, state] of Object.entries(categories)) {
        const expected = stateToBool(state);
        expect(probe.category_bool[slug], `${slug} runtime default`).toBe(expected);
        expect(probe.default_consent[slug], `${slug} wins for both binary laws`).toEqual({
          gdpr: slug === 'necessary' ? true : expected,
          ccpa: slug === 'necessary' ? true : expected,
        });
      }
      expect(categories.necessary, 'necessary storage is locked on').toBe('granted-locked');

      // Google Consent Mode must normalize "until action" to denied and use
      // the stricter value when functionality/personalization share a mirror.
      expect(probe.gcm_row).toMatchObject(expectedGcmRow(ruleset));
      expect(probe.gcm_row.security_storage, 'security storage remains available').toBe('granted');
    });
  });
});
