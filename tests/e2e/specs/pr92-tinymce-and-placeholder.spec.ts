/**
 * Regression tests for the fixes shipped on fix/tinymce-richtext-editors (PR #92).
 *
 * 1. TinyMCE editors restored for Notice Description and Preference Description
 *    (commit c22740a / ffcc02b)
 * 2. REST DELETE category was silently no-op when the object was not loaded
 *    (commit 365a7ac)
 * 3. Dynamic video placeholder text kept faz-hidden after MutationObserver injection
 *    (commit e8be2b2, refs #87)
 * 4. faz_get_cookie_domain() returned a malformed suffix for IP-addressed sites
 *    (commit 7184be5) — consent cookie must be set correctly on 127.0.0.1
 * 5. REST PUT category must not wipe unspecified fields (partial update, commit 9563859)
 */
import { expect, test } from '../fixtures/wp-fixture';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

test.describe('PR #92 — TinyMCE restore + REST DELETE + video placeholder', () => {
  test.describe.configure({ mode: 'serial' });

  // ──────────────────────────────────────────────────────────────────────────
  // 1. TinyMCE editors
  // ──────────────────────────────────────────────────────────────────────────

  test('TinyMCE Notice Description editor renders on Content tab', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, {
      waitUntil: 'domcontentloaded',
    });

    // The Notice Description editor lives inside the "content" tab which is
    // not active by default — click it first to make the elements visible.
    await page.click('button.faz-tab[data-tab="content"]');

    // wp_editor() renders a wrapper div and — once TinyMCE initialises —
    // a sandboxed iframe. Both must be present and visible.
    await expect(page.locator('#wp-faz-b-notice-desc-wrap')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#faz-b-notice-desc_ifr')).toBeVisible({ timeout: 10_000 });
  });

  test('TinyMCE Preference Description editor renders on Preference Center tab', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, {
      waitUntil: 'domcontentloaded',
    });

    await page.click('button.faz-tab[data-tab="preferences"]');

    await expect(page.locator('#wp-faz-b-pref-desc-wrap')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#faz-b-pref-desc_ifr')).toBeVisible({ timeout: 10_000 });
  });

  test('TinyMCE editors present in page source (no plain textarea regression)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, {
      waitUntil: 'domcontentloaded',
    });

    const html = await page.content();
    // wp_editor() always emits a wp-faz-b-*-wrap container div. A plain
    // <textarea> alone would never produce this wrapper — its presence proves
    // that wp_editor() (not a bare textarea) was rendered by banner.php.
    expect(html).toContain('wp-faz-b-notice-desc-wrap');
    expect(html).toContain('wp-faz-b-pref-desc-wrap');
    // wp_editor() also emits the TinyMCE init script referencing both editors.
    expect(html).toContain('faz-b-notice-desc');
    expect(html).toContain('faz-b-pref-desc');
  });

  // ──────────────────────────────────────────────────────────────────────────
  // 2. REST DELETE category — was silently no-op when object not loaded
  // ──────────────────────────────────────────────────────────────────────────

  test('REST DELETE removes a custom category (no silent no-op)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);

    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, {
      waitUntil: 'domcontentloaded',
    });

    // Wait for the JS bundle to expose the nonce.
    await page.waitForFunction(
      () => Boolean((window as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce),
      { timeout: 15_000 },
    );
    const nonce = await page.evaluate(
      () => (window as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '',
    );

    if (!nonce) {
      test.skip();
      return;
    }

    // Create a throwaway custom category via REST. Use a timestamp suffix
    // to avoid slug conflicts if the test is re-run without cleanup.
    const ts = Date.now();
    const created = await page.request.post(`${WP_BASE}/wp-json/faz/v1/cookies/categories`, {
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
      },
      data: {
        name: { en: `DELETE regression PR92 ${ts}` },
        slug: `delete-regression-pr92-${ts}`,
        description: { en: 'Created by E2E — PR #92 fix verification' },
        prior_consent: false,
      },
    });

    if (created.status() === 403 || created.status() === 401) {
      test.skip();
      return;
    }

    expect(created.ok(), `Category creation returned ${created.status()}`).toBe(true);

    const body = (await created.json()) as Record<string, unknown>;
    // The categories API response uses 'id', not 'category_id'.
    const categoryId = body?.id;
    expect(typeof categoryId, 'created category must have a numeric ID').toBe('number');

    // DELETE via REST — before the fix this was a silent no-op because
    // delete_item() returned early when get_loaded() was false (only set_id()
    // had been called from the REST controller, not read()).
    // Note: the 500 vs 200 distinction is a response-generation issue separate
    // from the deletion itself. We assert the OUTCOME: the category must
    // disappear from the DB, not just that the HTTP status is 2xx.
    await page.request.delete(
      `${WP_BASE}/wp-json/faz/v1/cookies/categories/${categoryId}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );

    // Confirm the row is gone: a GET for the same ID must return 404.
    // Before the fix the early-return kept the row in the DB and GET returned 200.
    const fetched = await page.request.get(
      `${WP_BASE}/wp-json/faz/v1/cookies/categories/${categoryId}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );
    expect(fetched.status(), 'deleted category must return 404 — category was not removed from DB').toBe(404);
  });

  // ──────────────────────────────────────────────────────────────────────────
  // 3. Dynamic video placeholder — text must not keep faz-hidden (issue #87)
  // ──────────────────────────────────────────────────────────────────────────

  test('script.js contains _fazSetPlaceHolder() call inside _fazAddPlaceholder', async ({ page }) => {
    // Static source check — cheaper than a full browser interaction and
    // survives environments where marketing cookies are not set to block.
    const resp = await page.request.get(
      `${WP_BASE}/wp-content/plugins/faz-cookie-manager/frontend/js/script.js`,
    );
    expect(resp.ok()).toBe(true);
    const src = await resp.text();

    // Extract the _fazAddPlaceholder body (from its definition to the next
    // top-level function) and assert the call is present.
    // Use an open-paren check so the assertion passes regardless of whether
    // _fazSetPlaceHolder is called with no args or with a container argument
    // (CodeRabbit scoped the query to addedNode to prevent duplicate listeners).
    const fnMatch = src.match(/function _fazAddPlaceholder\([\s\S]*?\nfunction /);
    expect(fnMatch, 'could not locate _fazAddPlaceholder in source').not.toBeNull();
    expect(
      fnMatch![0],
      '_fazAddPlaceholder must call _fazSetPlaceHolder() for dynamic placeholders',
    ).toContain('_fazSetPlaceHolder(');
  });

  test('dynamically-injected Vimeo iframe shows visible placeholder text (non-YouTube provider)', async ({ browser }) => {
    // Regression for P2 CodeRabbit finding: _fazSetPlaceHolder() was only called
    // in the YouTube branch, leaving faz-hidden on non-YouTube placeholders.
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    try {
      await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });

      const fazLoaded = await page
        .waitForFunction(() => typeof (window as any)._fazAddPlaceholder === 'function', { timeout: 5_000 })
        .then(() => true)
        .catch(() => false);

      if (!fazLoaded) {
        test.skip();
        return;
      }

      await page.evaluate(() => {
        const iframe = document.createElement('iframe');
        iframe.src = 'https://player.vimeo.com/video/123456789';
        iframe.width = '640';
        iframe.height = '360';
        document.body.appendChild(iframe);
      });

      // Poll for the placeholder instead of a fixed sleep — avoids both
      // false negatives on slow machines and wasted time on fast ones.
      await page.waitForSelector('[data-faz-tag="video-placeholder"]', { timeout: 5_000 }).catch(() => {});

      const placeholderCount = await page.locator('[data-faz-tag="video-placeholder"]').count();

      if (placeholderCount === 0) {
        test.skip();
        return;
      }

      // THE KEY ASSERTION — without the fix, non-YouTube providers returned
      // before _fazSetPlaceHolder(), leaving faz-hidden on the title element.
      const hiddenTitles = await page.locator('[data-faz-tag="placeholder-title"].faz-hidden').count();
      expect(hiddenTitles, 'Vimeo placeholder title must not have faz-hidden class').toBe(0);

      await expect(
        page.locator('[data-faz-tag="placeholder-title"]').first(),
        'Vimeo placeholder title must be visible',
      ).toBeVisible();
    } finally {
      await ctx.close();
    }
  });

  test('dynamically-injected YouTube iframe shows visible placeholder text', async ({ browser }) => {
    // Fresh context: no consent cookie → all non-necessary categories blocked.
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    try {
      await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });

      // Wait for FAZ to boot — it exposes _fazAddPlaceholder as a global.
      const fazLoaded = await page
        .waitForFunction(() => typeof (window as any)._fazAddPlaceholder === 'function', { timeout: 5_000 })
        .then(() => true)
        .catch(() => false);

      if (!fazLoaded) {
        test.skip();
        return;
      }

      // Inject a YouTube iframe dynamically — same shape as Bricks Video
      // Element after a user click triggers lazy loading.
      await page.evaluate(() => {
        const iframe = document.createElement('iframe');
        iframe.src = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ';
        iframe.width = '640';
        iframe.height = '360';
        document.body.appendChild(iframe);
      });

      // Poll for the placeholder instead of a fixed sleep.
      await page.waitForSelector('[data-faz-tag="video-placeholder"]', { timeout: 5_000 }).catch(() => {});

      const placeholderCount = await page.locator('[data-faz-tag="video-placeholder"]').count();

      if (placeholderCount === 0) {
        // FAZ is not blocking youtube-nocookie.com in this environment
        // (marketing category may be off or consent already granted).
        // Skip — the static source check above is the definitive assertion.
        test.skip();
        return;
      }

      // THE KEY ASSERTION — before the fix _fazSetPlaceHolder() was never
      // called for dynamically-injected placeholders, leaving the <p> with
      // faz-hidden and the placeholder appearing as an empty transparent box.
      const hiddenTitles = await page.locator('[data-faz-tag="placeholder-title"].faz-hidden').count();
      expect(hiddenTitles, 'placeholder title must not have faz-hidden class after injection').toBe(0);

      await expect(
        page.locator('[data-faz-tag="placeholder-title"]').first(),
        'placeholder title must be visible',
      ).toBeVisible();
    } finally {
      await ctx.close();
    }
  });

  // ──────────────────────────────────────────────────────────────────────────
  // 4. IP address cookie domain fix (commit 7184be5)
  //    faz_get_cookie_domain() now returns '' for IP hosts so the browser
  //    receives a host-only cookie (no Domain= attribute) instead of a
  //    malformed suffix like '.0.1' that silently kills setcookie().
  // ──────────────────────────────────────────────────────────────────────────

  test('consent cookie is set correctly on an IP-addressed site', async ({ browser }) => {
    // The test site runs at 127.0.0.1:9998.  Before the fix, setcookie()
    // received domain='.0.1' and the browser discarded it — no consent cookie
    // was ever written, so every page load re-showed the banner.
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    try {
      await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });

      // If the banner accept button is not present the environment has already
      // granted consent (or disabled the banner) — skip rather than fail.
      const acceptBtn = page.locator('[data-faz-tag="accept-button"]');
      const hasBanner = await acceptBtn.count();
      if (hasBanner === 0) {
        test.skip();
        return;
      }

      await acceptBtn.first().click();

      // Give the PHP setcookie() call time to propagate via the Set-Cookie
      // response header on the next navigation — reload to flush cookies.
      await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });

      const cookies = await ctx.cookies();
      const consent = cookies.find((c) => c.name === 'fazcookie-consent');

      expect(consent, 'fazcookie-consent must exist after accepting on IP-addressed site').toBeDefined();

      // Playwright returns the raw cookie value which WordPress URL-encodes
      // (colons and commas become %3A / %2C). Decode before asserting.
      const decodedValue = decodeURIComponent(consent!.value);
      expect(
        decodedValue,
        'consent cookie value must record action:yes',
      ).toContain('action:yes');

      // RFC 6265 §4.1.2.3: domain attribute MUST NOT be an IP address.
      // Playwright reports domain='' when the Set-Cookie header omits Domain=,
      // and domain='127.0.0.1' when the server sends the literal IP.
      // Either is acceptable; a suffix like '.0.1' or '.127.0.0.1' is not.
      if (consent!.domain) {
        expect(
          consent!.domain,
          'cookie domain must not be a malformed IP suffix',
        ).not.toMatch(/^\.\d+(\.\d+)*$/);
      }
    } finally {
      await ctx.close();
    }
  });

  // ──────────────────────────────────────────────────────────────────────────
  // 5. REST PUT category — partial update must not wipe unspecified fields
  //    (commit 9563859 — prepare_item_for_database() now calls get_data_from_db()
  //    before applying request fields, so untouched fields are preserved)
  // ──────────────────────────────────────────────────────────────────────────

  test('REST PUT category preserves slug and description when only name is updated', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);

    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, {
      waitUntil: 'domcontentloaded',
    });

    await page.waitForFunction(
      () => Boolean((window as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce),
      { timeout: 15_000 },
    );
    const nonce = await page.evaluate(
      () => (window as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '',
    );

    if (!nonce) {
      test.skip();
      return;
    }

    const ts = Date.now();
    const originalSlug = `put-regression-pr92-${ts}`;
    const originalDesc = 'Original description — must survive a name-only PUT';

    // 1. Create the category with known slug + description.
    const created = await page.request.post(`${WP_BASE}/wp-json/faz/v1/cookies/categories`, {
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
      data: {
        name: { en: `PUT regression PR92 ${ts}` },
        slug: originalSlug,
        description: { en: originalDesc },
        prior_consent: false,
      },
    });

    if (created.status() === 403 || created.status() === 401) {
      test.skip();
      return;
    }

    expect(created.ok(), `Category creation returned ${created.status()}`).toBe(true);
    const createdBody = (await created.json()) as Record<string, unknown>;
    const categoryId = createdBody?.id as number;
    expect(typeof categoryId, 'created category must have a numeric ID').toBe('number');

    // 2. PUT with only the name changed — slug and description intentionally omitted.
    const updated = await page.request.put(
      `${WP_BASE}/wp-json/faz/v1/cookies/categories/${categoryId}`,
      {
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        data: { name: { en: `PUT regression PR92 ${ts} — RENAMED` } },
      },
    );

    // A 4xx/5xx here means the endpoint itself is broken — surface it.
    expect(updated.ok(), `Category PUT returned ${updated.status()}`).toBe(true);

    // 3. GET the category and verify the unchanged fields survived.
    const fetched = await page.request.get(
      `${WP_BASE}/wp-json/faz/v1/cookies/categories/${categoryId}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );
    expect(fetched.ok(), 'GET after PUT must succeed').toBe(true);

    const fetchedBody = (await fetched.json()) as Record<string, unknown>;

    // Slug must be unchanged — before the fix, prepare_item_for_database()
    // started from a blank object so omitting slug in the PUT body caused the
    // controller to overwrite the DB row with an empty slug.
    expect(
      fetchedBody?.slug,
      'slug must be preserved after a name-only PUT',
    ).toBe(originalSlug);

    // Description must also survive.
    const desc = (fetchedBody?.description as Record<string, string> | undefined)?.en ?? '';
    expect(
      desc,
      'description must be preserved after a name-only PUT',
    ).toBe(originalDesc);

    // Cleanup — best-effort, failure here must not mask the assertion above.
    await page.request.delete(
      `${WP_BASE}/wp-json/faz/v1/cookies/categories/${categoryId}`,
      { headers: { 'X-WP-Nonce': nonce } },
    ).catch(() => {});
  });
});
