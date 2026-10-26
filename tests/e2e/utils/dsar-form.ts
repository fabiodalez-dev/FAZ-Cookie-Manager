/**
 * Fields of a rendered [faz_dsar_form], ready to post to faz_dsar_submit.
 *
 * Since 1.34.1 the form carries an anti-bot stamp (faz_dsar_t / faz_dsar_s, the
 * proof-of-work fields and a honeypot whose name changes with the stamp), and
 * check_antibot() accepts a submission without one only during the grace
 * window after the stamp epoch (faz_dsar_stamped_since). A spec that posts a
 * hand-written body therefore passes on a fresh site and fails from the third
 * day on (#347). Posting what the rendered form actually contains, after the
 * minimum dwell time, is what a visitor's browser does and keeps working.
 */
import type { Page } from '@playwright/test';

/** Mirrors Dsar_Shortcode::MIN_DWELL_SECONDS, plus a margin for clock skew. */
const MIN_DWELL_MS = 3_500;

/**
 * Open the DSAR page, read every field of its form and wait until the stamp is
 * old enough to pass the dwell check.
 *
 * @param page    Page to load the form in (its cookies are the visitor's).
 * @param dsarUrl Permalink of a page that renders [faz_dsar_form].
 * @returns Field name → value, including nonce, stamp and the empty honeypot.
 */
export async function stampedDsarFields(page: Page, dsarUrl: string): Promise<Record<string, string>> {
  await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
  const form = page.locator('form.faz-dsar-form').first();
  await form.waitFor({ state: 'attached' });

  const fields = await form.evaluate((el) => {
    const out: Record<string, string> = {};
    new FormData(el as HTMLFormElement).forEach((value, key) => {
      if (typeof value === 'string') {
        out[key] = value;
      }
    });
    return out;
  });

  if (!fields.faz_dsar_t || !fields.faz_dsar_s) {
    throw new Error('the rendered DSAR form carries no anti-bot stamp');
  }
  const issuedMs = Number(fields.faz_dsar_t) * 1000;
  const waitMs = issuedMs + MIN_DWELL_MS - Date.now();
  if (waitMs > 0) {
    await page.waitForTimeout(waitMs);
  }
  return fields;
}
