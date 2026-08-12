/**
 * Cookie Policy version review — the link between a changed policy and consent.
 *
 * The unit suite proves the ledger arithmetic. What it cannot prove is the part
 * that matters to a visitor: that clicking "Material change" actually makes a
 * consented browser see the banner again, and that clicking "Minor change" does
 * not. Those two outcomes travel through the admin page, the recoverable REST
 * decision endpoint, the
 * stored consent revision and the frontend script — so they are asserted here,
 * end to end, with a real browser holding a real consent cookie.
 *
 * Serial by necessity: every test mutates the same site-wide policy settings and
 * the same consent revision.
 */

import { test, expect, completeAdminLogin } from '../fixtures/wp-fixture';
import type { Browser, BrowserContext, Page } from '@playwright/test';
import { getAdminNonce, fazApiGet, fazApiPost } from '../utils/faz-api';
import { clickFirstVisible } from '../utils/ui';
import { WP_PATH, wpEval } from '../utils/wp-env';

const ADMIN_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager-cookie-policy';
const NOTICE = '#faz-cp-version-notice';
const HASH_PAIR_RE = /[0-9a-f]{6}\.[0-9a-f]{6}\s*→\s*[0-9a-f]{6}\.[0-9a-f]{6}/;

const HASH_RE = /^[0-9a-f]{6}\.[0-9a-f]{6}$/;

// Version_Ledger::OPTION / ::DOCUMENT. Read through wp-cli rather than through
// the admin page, because the page is the thing under test: asking it whether
// seeding happened would only ever return its own opinion.
const LEDGER_OPTION = 'faz_legal_doc_acknowledged';
const LEDGER_DOCUMENT = 'cookie-policy';

/** The hash currently recorded as acknowledged, or '' when none is. */
function acknowledgedHash(): string {
  return wpEval(
    `$l = get_option( '${LEDGER_OPTION}', array() );` +
    `echo ( is_array( $l ) && isset( $l['${LEDGER_DOCUMENT}']['hash'] ) ) ? $l['${LEDGER_DOCUMENT}']['hash'] : '';`,
  ).trim();
}

/** Return the ledger to the never-acknowledged state a fresh install is in. */
function clearLedger(): void {
  wpEval(`delete_option( '${LEDGER_OPTION}' );`);
}

/**
 * The review token the policy currently hashes to, computed without going
 * through the code path under test.
 *
 * review_hash() is what the notice compares against, and it neither reads nor
 * writes the ledger — so it can say which value a correct seeding must land on,
 * where the ledger itself can only say which value it happened to store.
 */
function currentReviewHash(): string {
  return wpEval(
    "echo \\FazCookie\\Admin\\Modules\\Cookie_Policy_Generator\\Includes\\Version_Ledger::review_hash();",
  ).trim();
}

type PolicySettings = Record<string, unknown> & { company?: Record<string, unknown> };

let settingsSnapshot: PolicySettings | null = null;

test.describe.configure({ mode: 'serial' });

/** Open the admin page and wait for the generator script to have run. */
async function openAdmin(page: Page): Promise<void> {
  await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => (window as unknown as { fazCpBooted?: boolean }).fazCpBooted === true, null, {
    timeout: 30_000,
  });
}

/**
 * Save the policy settings with a changed company name, which moves the version
 * hash. A settings edit is used rather than a section override deliberately: an
 * override whose anchor does not match the shipped scaffold is inert by design
 * (Section_Overrides fails closed), so it would leave the hash untouched and
 * this suite would pass while asserting nothing.
 */
// Unique per run, so a bump always lands on a value the ledger has never seen.
const revisionRunId = Math.random().toString(36).slice(2, 8);
let bumpCounter = 0;

async function bumpPolicyVersion(page: Page, marker: string): Promise<void> {
  const nonce = await getAdminNonce(page);
  const current = await fazApiGet<PolicySettings>(page, nonce, 'cookie-policy/settings');
  expect(current.status).toBe(200);

  const company = current.data.company ?? {};
  // Strip EVERY trailing marker, not just the last one, and refuse an empty
  // result. On this test site the name had degenerated to
  // "[rev material] [rev material]": the single-marker regex left one behind,
  // beforeAll snapshotted the already-contaminated value, and afterAll restored
  // it — so each run ate a little more of the real name.
  const baseName = String(company.name ?? '').replace(/(?: \[rev [^\]]*\])+$/, '').trim() || 'Test Co';
  // The marker alone does not guarantee a CHANGE. Re-running with the same
  // marker used to write back the identical string, so the policy hash never
  // moved and "a material change re-prompts" waited for a notice that had no
  // reason to appear — a test that could only pass once per marker value.
  bumpCounter += 1;
  const next = {
    ...current.data,
    company: { ...company, name: `${baseName} [rev ${marker} ${bumpCounter}-${revisionRunId}]` },
  };
  const saved = await fazApiPost<{ saved: boolean }>(page, nonce, 'cookie-policy/settings', next);
  expect(saved.status).toBe(200);
}

/** A fresh browser context that has accepted the banner on the front end. */
async function acceptBannerInFreshContext(
  page: Page,
  baseURL: string,
): Promise<{ context: BrowserContext; page: Page }> {
  const context = await page.context().browser()!.newContext({ baseURL });
  await context.clearCookies();
  const visitor = await context.newPage();
  await visitor.goto('/', { waitUntil: 'domcontentloaded' });

  const notice = visitor.locator('[data-faz-tag="notice"]');
  await expect(notice).toBeVisible({ timeout: 15_000 });
  const accepted = await clickFirstVisible(visitor, [
    '[data-faz-tag="accept-button"] button',
    '[data-faz-tag="accept-button"]',
    '.faz-btn-accept',
  ]);
  expect(accepted).toBeTruthy();
  await expect(notice).toBeHidden({ timeout: 15_000 });

  // Prove the acceptance persists before anything else touches the revision —
  // otherwise a later "the banner is back" assertion could just be a visitor
  // whose consent never stuck in the first place.
  await visitor.reload({ waitUntil: 'domcontentloaded' });
  await expect(notice).toBeHidden({ timeout: 15_000 });

  return { context, page: visitor };
}

/** Run a callback on a throwaway logged-in admin page (for beforeAll/afterAll). */
async function withAdminPage(
  browser: Browser,
  creds: { baseURL: string; adminUser: string; adminPass: string },
  fn: (page: Page) => Promise<void>,
): Promise<void> {
  const context = await browser.newContext({ baseURL: creds.baseURL });
  const page = await context.newPage();
  try {
    await completeAdminLogin(page, creds.baseURL, creds.adminUser, creds.adminPass);
    await fn(page);
  } finally {
    await context.close();
  }
}

// beforeAll/afterAll can only see worker-scoped fixtures, hence `wpCreds`
// rather than the per-test wpBaseURL/adminUser/adminPass trio.
// Acknowledging a MATERIAL change bumps faz_settings.general.consent_revision,
// and that counter invalidates every stored consent cookie on the site — it is
// how the plugin re-prompts visitors after a policy change. This spec therefore
// mutates global state that has nothing to do with cookie policy, and has to
// put it back: leaving it bumped makes the NEXT spec's pre-seeded consent stale,
// which surfaces far away as, say, per-service consent "failing" to keep _ga
// after a reload. That only started biting once the bump was fixed to actually
// change something — before, the notice never appeared, the material button was
// never clicked, and the counter never moved.
let consentRevisionSnapshot = 1;

function readConsentRevision(): number {
  const raw = wpEval(
    "$s = get_option( 'faz_settings', array() ); echo (int) ( $s['general']['consent_revision'] ?? 1 );",
  );
  return Number.parseInt(raw.trim(), 10) || 1;
}

function writeConsentRevision(value: number): void {
  wpEval(
    "$s = get_option( 'faz_settings', array() ); " +
    "if ( ! isset( $s['general'] ) || ! is_array( $s['general'] ) ) { $s['general'] = array(); } " +
    `$s['general']['consent_revision'] = ${value}; ` +
    "update_option( 'faz_settings', $s ); echo 'ok';",
  );
}

test.beforeAll(async ({ browser, wpCreds }) => {
  consentRevisionSnapshot = readConsentRevision();
  await withAdminPage(browser, wpCreds, async (page) => {
    const nonce = await getAdminNonce(page);
    const current = await fazApiGet<PolicySettings>(page, nonce, 'cookie-policy/settings');
    settingsSnapshot = current.status === 200 ? current.data : null;
  });
});

test.afterAll(async ({ browser, wpCreds }) => {
  writeConsentRevision(consentRevisionSnapshot);
  await withAdminPage(browser, wpCreds, async (page) => {
    const nonce = await getAdminNonce(page);
    if (settingsSnapshot) {
      await fazApiPost(page, nonce, 'cookie-policy/settings', settingsSnapshot);
    }
    // Restoring the settings moves the hash back to a value nobody has
    // acknowledged, so acknowledge once more — otherwise the next suite that
    // opens this page inherits a notice this one created.
    await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    // count() first: "no notice" is an expected outcome here (restoring the
    // settings can land back on an already-acknowledged hash), and calling
    // getAttribute() on an absent locator would burn Playwright's full 30s
    // default timeout in teardown before the catch swallowed it.
    const noticeEl = page.locator(NOTICE);
    const hash = (await noticeEl.count()) > 0
      ? await noticeEl.getAttribute('data-current-hash', { timeout: 5_000 }).catch(() => null)
      : null;
    if (hash) {
      await fazApiPost(page, nonce, 'cookie-policy/acknowledge-version', { hash });
    }
  });
});

test('first load seeds the ledger silently — the feature itself never prompts', async ({ page, loginAsAdmin }) => {
  test.skip(!WP_PATH, 'requires WP_PATH to put the ledger back to its unacknowledged state');

  // The precondition IS the subject here. Seeding only happens when nothing has
  // been acknowledged yet (Version_Ledger::evaluate returns 'seeded' on an empty
  // ledger and adopts the current hash), so a test that inherits whatever ledger
  // state the site happens to carry is not exercising seeding at all — it is
  // asserting "no notice pending", which is a different claim from the one in
  // its own name. It passed for that weaker reason, and failed outright on any
  // site holding a genuine unacknowledged change, including a run left half
  // finished. Establish the state rather than hope for it.
  clearLedger();
  expect(acknowledgedHash()).toBe('');

  // Captured before the page runs, so the expected value cannot be derived from
  // whatever the page decided to store.
  const expected = currentReviewHash();
  expect(expected).toMatch(HASH_RE);

  await loginAsAdmin(page);
  await openAdmin(page);
  await expect(page.locator(NOTICE)).toHaveCount(0);

  // Silence on its own would also be the outcome if the feature had done
  // nothing at all, so assert the other half of "seeds silently": the ledger
  // really did adopt the CURRENT hash on that first load. Checking only the
  // <6hex>.<6hex> shape would pass on any well-formed value, including a stale
  // one — the shape is not the claim, the value is.
  const seeded = acknowledgedHash();
  expect(seeded).toBe(expected);

  // Second load, still nothing: seeding is a one-off, not a per-load reset —
  // and it must not quietly re-seed to some other value either.
  await openAdmin(page);
  await expect(page.locator(NOTICE)).toHaveCount(0);
  expect(acknowledgedHash()).toBe(seeded);
});

test('a material change re-prompts a visitor who had already consented', async ({ page, loginAsAdmin, wpBaseURL }) => {
  await loginAsAdmin(page);
  await openAdmin(page);
  await bumpPolicyVersion(page, 'material');

  await openAdmin(page);
  const notice = page.locator(NOTICE);
  await expect(notice).toBeVisible();
  await expect(notice).toContainText(HASH_PAIR_RE);

  const visitor = await acceptBannerInFreshContext(page, wpBaseURL);
  try {
    page.on('dialog', (dialog) => dialog.accept());
    await page.locator('#faz-cp-version-material').click();
    await expect(notice).toBeHidden({ timeout: 20_000 });

    await visitor.page.reload({ waitUntil: 'domcontentloaded' });
    await expect(visitor.page.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: 20_000 });

    // Acknowledged: the decision is recorded, so the notice does not come back.
    await openAdmin(page);
    await expect(page.locator(NOTICE)).toHaveCount(0);
  } finally {
    await visitor.context.close();
  }
});

test('a minor change acknowledges without disturbing existing consents', async ({ page, loginAsAdmin, wpBaseURL }) => {
  await loginAsAdmin(page);
  await openAdmin(page);

  // A visitor who consents AFTER the material bump above holds the current
  // revision — the right baseline for proving the minor path leaves it alone.
  const visitor = await acceptBannerInFreshContext(page, wpBaseURL);
  try {
    await bumpPolicyVersion(page, 'minor');
    await openAdmin(page);
    const notice = page.locator(NOTICE);
    await expect(notice).toBeVisible();

    await page.locator('#faz-cp-version-minor').click();
    await expect(notice).toBeHidden({ timeout: 20_000 });

    await visitor.page.reload({ waitUntil: 'domcontentloaded' });
    await expect(visitor.page.locator('[data-faz-tag="notice"]')).toBeHidden({ timeout: 20_000 });

    await openAdmin(page);
    await expect(page.locator(NOTICE)).toHaveCount(0);
  } finally {
    await visitor.context.close();
  }
});

test('"Decide later" hides the notice for this load only', async ({ page, loginAsAdmin }) => {
  await loginAsAdmin(page);
  await openAdmin(page);
  await bumpPolicyVersion(page, 'dismiss');

  await openAdmin(page);
  const notice = page.locator(NOTICE);
  await expect(notice).toBeVisible();
  await page.locator('#faz-cp-version-dismiss').click();
  await expect(notice).toBeHidden();

  // Nothing was persisted, so an undecided change keeps asking.
  await openAdmin(page);
  await expect(page.locator(NOTICE)).toBeVisible();

  // Leave the ledger settled for the next spec.
  await page.locator('#faz-cp-version-minor').click();
  await expect(notice).toBeHidden({ timeout: 20_000 });
});
