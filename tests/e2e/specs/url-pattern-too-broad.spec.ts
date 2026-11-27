import { expect, test } from '../fixtures/wp-fixture';
import { fazApiDelete, fazApiPost, fazApiPut, findCategoryId, openCookiesPage } from '../utils/faz-api';

/**
 * A cookie's "Script URL pattern" is matched as a substring of every script
 * and iframe URL on the page. A pattern such as "/" or "js" in a
 * non-necessary category would block almost every script on the site until
 * that category is accepted, so the REST API refuses it on create and on
 * update, and accepts a distinctive one.
 */
test.describe('Script URL pattern breadth', () => {
  test('a pattern too broad to name one script is refused on create and update', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const categoryId = await findCategoryId(page, nonce, 'functional');
    const prefix = `e2e_wide_${Date.now()}`;
    const created: number[] = [];

    const base = {
      category: categoryId,
      description: { en: 'URL pattern breadth check' },
      discovered: false,
      domain: '',
      duration: { en: '1 day' },
      type: 0,
    };

    try {
      for (const wide of ['/', 'js', '.js', 'www', 'js%20%20']) {
        const refused = await fazApiPost<any>(page, nonce, 'cookies', { ...base, name: `${prefix}_${wide.length}`, url_pattern: wide });
        expect(refused.status, `pattern "${wide}" must be refused`).toBe(400);
        expect(JSON.stringify(refused.data)).toContain('faz_url_pattern_too_broad');
        const id = Number(refused.data?.id ?? refused.data?.cookie_id ?? 0);
        if (id > 0) created.push(id);
      }

      const accepted = await fazApiPost<any>(page, nonce, 'cookies', { ...base, name: `${prefix}_ok`, url_pattern: 'widget.booking.example/loader.js' });
      expect([200, 201]).toContain(accepted.status);
      const okId = Number(accepted.data.id ?? accepted.data.cookie_id);
      expect(okId).toBeGreaterThan(0);
      created.push(okId);

      // A short named call is a code signature for inline scripts, not a
      // URL fragment: it names the tracker's entry point and is accepted.
      const call = await fazApiPost<any>(page, nonce, 'cookies', { ...base, name: `${prefix}_call`, url_pattern: 'fbq(' });
      expect([200, 201], 'a named call such as "fbq(" is accepted').toContain(call.status);
      const callId = Number(call.data.id ?? call.data.cookie_id);
      if (callId > 0) created.push(callId);

      const widened = await fazApiPut<any>(page, nonce, `cookies/${okId}`, { url_pattern: '/' });
      expect(widened.status).toBe(400);

      const emptied = await fazApiPut<any>(page, nonce, `cookies/${okId}`, { url_pattern: '' });
      expect([200, 201]).toContain(emptied.status);
    } finally {
      for (const id of created) {
        await fazApiDelete(page, nonce, `cookies/${id}`).catch(() => ({ status: 0 }));
      }
    }
  });
});
