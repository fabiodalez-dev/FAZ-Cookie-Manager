/**
 * Park proofs on a real WordPress: the output buffer signs what it parks, the
 * park-proofs REST route confirms exactly those values, and markup a page
 * fetches later (a second response, parked under its own key) is restored
 * when the visitor accepts.
 *
 * Before, a park was proven only by the key of the response that made it, so
 * "load more" / infinite-scroll content kept its style, background or object
 * data parked after consent until a reload. The unit tests cover each piece
 * (tests/unit/test-park-proof-php.php, test-park-proof-refresh-php.php,
 * tests/unit/js/park-proof-verify.test.mjs); this spec checks they agree end
 * to end: the server's signature and decoding, the route as WordPress
 * registers it, and the runtime's request.
 */
import { expect, test } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const SLUG = 'faz-e2e-park-proofs';
const THUMB = 'https://i.ytimg.com/vi/FAZE2EPARK/hqdefault.jpg';

function ensurePage(): string {
  const content = `<div id="faz-e2e-park" style="width:40px;height:40px;background-image:url(&quot;${THUMB}&quot;)">x</div>`;
  const b64 = Buffer.from(content, 'utf8').toString('base64');
  return wpEval(`
    kses_remove_filters();
    $existing = get_page_by_path( '${SLUG}', OBJECT, 'page' );
    $data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => '${SLUG}', 'post_title' => 'FAZ E2E park proofs', 'post_content' => base64_decode( '${b64}' ) );
    if ( $existing ) { $data['ID'] = $existing->ID; $id = wp_update_post( $data ); } else { $id = wp_insert_post( $data ); }
    echo get_permalink( $id );
  `).trim();
}

test.describe('park proofs', () => {
  let url = '';
  test.beforeAll(() => {
    url = ensurePage();
  });
  test.afterAll(() => {
    wpEval(`$p = get_page_by_path( '${SLUG}', OBJECT, 'page' ); if ( $p ) { wp_delete_post( $p->ID, true ); }`);
  });

  test('the server signs a parked style and the route confirms exactly that value', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await page.goto(`${url}${url.includes('?') ? '&' : '?'}faz_e2e_nocache=${Date.now()}`, { waitUntil: 'domcontentloaded' });
      const park = await page.evaluate(() => {
        const el = document.getElementById('faz-e2e-park')!;
        return {
          names: String(el.getAttribute('data-faz-park') || '').split(/\s+/).filter(Boolean),
          value: el.getAttribute('data-faz-style'),
          sig: el.getAttribute('data-faz-park-sig'),
          key: el.getAttribute('data-faz-park-key'),
          pageKey: (window as any)._fazConfig && (window as any)._fazConfig._srcdocKey,
          endpoint: (window as any)._fazConfig && (window as any)._fazConfig._parkProofEndpoint,
        };
      });
      expect(park.names).toEqual(['style']);
      expect(park.value).toContain('i.ytimg.com');
      expect(park.sig).toMatch(/^[0-9a-f]{64}$/);
      expect(park.key).toBe(park.pageKey);
      expect(park.endpoint).toContain('faz/v1/park-proofs');

      const verdict = await page.evaluate(async ({ endpoint, value, sig }) => {
        const res = await fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ items: [
            { names: ['style'], values: [value], sig },
            { names: ['style'], values: ['background:url(https://evil.example/x.png)'], sig },
            { names: ['background'], values: [value], sig },
          ] }),
        });
        return { status: res.status, body: await res.json() };
      }, { endpoint: park.endpoint, value: park.value, sig: park.sig });
      expect(verdict.status).toBe(200);
      expect(verdict.body).toEqual({ valid: [true, false, false] });
    } finally {
      await ctx.close();
    }
  });

  test('markup fetched from a later response is restored on accept', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await page.goto(`${url}${url.includes('?') ? '&' : '?'}faz_e2e_nocache=${Date.now()}`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-faz-tag="accept-button"]').first()).toBeVisible();

      // What a "load more" handler does: fetch the next response and insert
      // its article. That response parked the style under ITS own key.
      const premise = await page.evaluate(async (src) => {
        const html = await (await fetch(src + '&faz_e2e_more=1', { credentials: 'same-origin' })).text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const el = doc.getElementById('faz-e2e-park')!;
        el.id = 'faz-e2e-park-more';
        document.body.insertAdjacentHTML('beforeend', el.outerHTML);
        const inserted = document.getElementById('faz-e2e-park-more')!;
        return {
          insertedKey: inserted.getAttribute('data-faz-park-key'),
          pageKey: (window as any)._fazConfig._srcdocKey,
          parked: inserted.getAttribute('data-faz-style'),
        };
      }, `${url}${url.includes('?') ? '&' : '?'}faz_e2e_nocache=${Date.now()}`);
      expect(premise.parked).toContain('i.ytimg.com');
      expect(premise.insertedKey).not.toBe(premise.pageKey);

      await page.locator('[data-faz-tag="accept-button"]').first().click();

      await expect.poll(() => page.evaluate(() => document.getElementById('faz-e2e-park-more')!.getAttribute('style') || '')).toContain('i.ytimg.com');
      const after = await page.evaluate(() => {
        const more = document.getElementById('faz-e2e-park-more')!;
        const own = document.getElementById('faz-e2e-park')!;
        return {
          moreKey: more.getAttribute('data-faz-park-key'),
          moreParked: more.getAttribute('data-faz-style'),
          ownStyle: own.getAttribute('style') || '',
          pageKey: (window as any)._fazConfig._srcdocKey,
        };
      });
      expect(after.moreKey).toBe(after.pageKey);
      expect(after.moreParked).toBeNull();
      expect(after.ownStyle).toContain('i.ytimg.com');
    } finally {
      await ctx.close();
    }
  });
});
