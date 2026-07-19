/**
 * Cookie Policy generator — gettext translation pipeline (PR #186).
 *
 * The policy sections are now routed through WordPress gettext: a generated
 * catalogue mirrors every English section as an _x() call, and at render time
 * Template_Translations::apply() replaces a section with its translation ONLY
 * when the active WP locale matches the requested policy language, the
 * translation is non-empty, differs from the English source, and preserves the
 * exact {{PLACEHOLDER}} multiset — otherwise it falls back section-by-section to
 * the reviewed bundled Markdown. Only Czech (cs_CZ) is translated so far.
 *
 * These tests exercise the REAL render path (Renderer::render) against the
 * deployed plugin via wp-cli, switching the active locale in-process with
 * switch_to_locale() (no persistent site-locale change), so they are
 * deterministic and need no browser. The only persisted mutation is
 * faz_cookie_policy_data, snapshotted and restored.
 *
 * Auto-skips if the Renderer class isn't present.
 */
import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';
import { acquireSharedWordPressLock, releaseSharedWordPressLock } from '../utils/shared-wordpress-lock';

const RENDERER = '\\FazCookie\\Admin\\Modules\\Cookie_Policy_Generator\\Includes\\Renderer';

let ready = false;
let savedData = '';
let lockHeld = false;

type RenderOpts = {
  jurisdiction?: string;
  lang: string;
  locale?: string | null;
  companyName?: string;
  disclaimerShow?: boolean;
};

type Facts = {
  len: number;
  article: boolean;
  version: string;
  rawPlaceholder: boolean;
  czech: boolean;
  company: boolean;
  disclaimer: boolean;
  disclaimerPhrase: boolean;
  md5: string;
};

/** Render the policy with controlled settings + active locale; return extracted facts. */
function renderFacts(opts: RenderOpts): Facts {
  const jurisdiction = opts.jurisdiction ?? 'gdpr-strict';
  const company = opts.companyName ?? 'ACME Test E2E';
  const show = opts.disclaimerShow === undefined ? 'true' : opts.disclaimerShow ? 'true' : 'false';
  const locale = opts.locale ?? '';
  const raw = wpEval(`
    update_option( 'faz_cookie_policy_data', array(
      'company'    => array( 'name' => ${JSON.stringify(company)}, 'email' => 'dpo@acme.test' ),
      'dpo'        => array( 'name' => 'DPO', 'email' => 'dpo@acme.test' ),
      'disclaimer' => array( 'show' => ${show} ),
    ) );
    ${locale ? `switch_to_locale( '${locale}' );` : ''}
    $html = ${RENDERER}::render( array( 'jurisdiction' => '${jurisdiction}', 'lang' => '${opts.lang}' ) );
    ${locale ? 'restore_previous_locale();' : ''}
    $html = (string) $html;
    preg_match( '/data-faz-policy-version="([^"]+)"/', $html, $v );
    echo wp_json_encode( array(
      'len'              => strlen( $html ),
      'article'          => strpos( $html, 'class="faz-cookie-policy"' ) !== false,
      'version'          => $v[1] ?? '',
      'rawPlaceholder'   => (bool) preg_match( '/\\{\\{[A-Z_]+\\}\\}/', $html ),
      'czech'            => ( stripos( $html, 'Zásady' ) !== false || stripos( $html, 'soubor' ) !== false || stripos( $html, 'Vaše' ) !== false ),
      'company'          => strpos( $html, ${JSON.stringify(company)} ) !== false,
      'disclaimer'       => strpos( $html, 'faz-cookie-policy-disclaimer' ) !== false,
      'disclaimerPhrase' => ( stripos( $html, 'legal advice' ) !== false || stripos( $html, 'poradenství' ) !== false ),
      'md5'              => md5( $html ),
    ) );
  `).trim();
  return JSON.parse(raw) as Facts;
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({}, testInfo) => {
  // The shared WordPress lock can wait for a long-running cache-compatibility
  // worker; keep the hook timeout above the lock's 40-minute acquisition window.
  testInfo.setTimeout(41 * 60_000);
  await acquireSharedWordPressLock();
  lockHeld = true;
  const exists = wpEval(`echo class_exists( '${RENDERER}' ) ? '1' : '0';`).trim();
  if (exists !== '1') {
    ready = false;
    return;
  }
  savedData = wpEval(`echo wp_json_encode( get_option( 'faz_cookie_policy_data', array() ) );`).trim();
  ready = true;
});

test.afterAll(() => {
  try {
    if (savedData) {
      const b64 = Buffer.from(savedData, 'utf8').toString('base64');
      wpEval(`
        $v = json_decode( base64_decode( '${b64}' ), true );
        update_option( 'faz_cookie_policy_data', is_array( $v ) ? $v : array() );
      `);
    }
  } finally {
    if (lockHeld) {
      releaseSharedWordPressLock();
      lockHeld = false;
    }
  }
});

test.beforeEach(() => {
  test.skip(!ready, 'Cookie Policy Renderer is not available on this environment');
});

test.describe('Cookie Policy gettext pipeline (#186)', () => {
  test('01 GDPR/en renders a well-formed policy (article, version hash, no raw placeholders)', () => {
    const f = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en' });
    expect(f.article).toBe(true);
    expect(f.version).toMatch(/^[a-f0-9.]{6,}$/);
    expect(f.len).toBeGreaterThan(5000);
    expect(f.rawPlaceholder).toBe(false);
  });

  test('02 [gettext] Czech (cs_CZ locale + lang=cs) is translated, differs from English', () => {
    const en = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en' });
    const cs = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'cs', locale: 'cs_CZ' });
    expect(cs.article).toBe(true);
    expect(cs.czech).toBe(true);
    expect(cs.md5).not.toBe(en.md5);
    expect(cs.rawPlaceholder).toBe(false);
  });

  test('03 [fallback] untranslated German (de_DE + lang=de) falls back to the bundled scaffold, not empty, not Czech', () => {
    const en = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en' });
    const cs = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'cs', locale: 'cs_CZ' });
    const de = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'de', locale: 'de_DE' });
    expect(de.article).toBe(true);
    expect(de.len).toBeGreaterThan(5000);
    expect(de.czech).toBe(false);
    expect(de.md5).not.toBe(en.md5);
    expect(de.md5).not.toBe(cs.md5);
  });

  test('04 company placeholder is substituted; no raw {{TOKEN}} survives', () => {
    const f = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en', companyName: 'PlaceholderProbe Ltd' });
    expect(f.company).toBe(true);
    expect(f.rawPlaceholder).toBe(false);
  });

  test('05 [version hash] stable across two identical renders', () => {
    const a = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en', companyName: 'Stable Co' });
    const b = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en', companyName: 'Stable Co' });
    expect(a.version).toBe(b.version);
  });

  test('06 [version hash] differs across jurisdictions (gdpr vs ccpa)', () => {
    const gdpr = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en', companyName: 'Same Co' });
    const ccpa = renderFacts({ jurisdiction: 'ccpa-california', lang: 'en', companyName: 'Same Co' });
    expect(gdpr.version).not.toBe(ccpa.version);
  });

  test('07 [version hash] reflects data drift (company change flips the hash)', () => {
    const a = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en', companyName: 'Drift One' });
    const b = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en', companyName: 'Drift Two' });
    expect(a.version).not.toBe(b.version);
  });

  test('08 all three jurisdictions render a distinct, well-formed policy', () => {
    const g = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en', companyName: 'Multi Co' });
    const c = renderFacts({ jurisdiction: 'ccpa-california', lang: 'en', companyName: 'Multi Co' });
    const l = renderFacts({ jurisdiction: 'lgpd-brazil', lang: 'en', companyName: 'Multi Co' });
    for (const f of [g, c, l]) {
      expect(f.article).toBe(true);
      expect(f.len).toBeGreaterThan(3000);
      expect(f.rawPlaceholder).toBe(false);
    }
    expect(new Set([g.version, c.version, l.version]).size).toBe(3);
  });

  test('09 the legal disclaimer is shown by default', () => {
    const f = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en' });
    expect(f.disclaimer).toBe(true);
    expect(f.disclaimerPhrase).toBe(true);
  });

  test('10 the disclaimer can be hidden from settings', () => {
    const shown = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en', disclaimerShow: true });
    const hidden = renderFacts({ jurisdiction: 'gdpr-strict', lang: 'en', disclaimerShow: false });
    expect(shown.disclaimer).toBe(true);
    expect(hidden.disclaimer).toBe(false);
  });
});
