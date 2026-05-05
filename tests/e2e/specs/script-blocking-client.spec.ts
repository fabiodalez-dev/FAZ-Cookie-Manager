/**
 * Client-side script blocking engine — comprehensive coverage.
 *
 * Tests the three JS intercept paths:
 *  - src setter (document.createElement override, src assignment)
 *  - setAttribute (data-fazcookie set after element creation)
 *  - MutationObserver (node appended to DOM)
 *
 * All tests run before any consent is given (cookies cleared).
 */

import { expect, test } from '../fixtures/wp-fixture';

test.describe.configure({ mode: 'serial' });

test.describe('Client-side script blocking engine', () => {
  test.beforeEach(async ({ page, wpBaseURL }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL + '/', { waitUntil: 'domcontentloaded' });
    // Ensure FAZ is loaded.
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
  });

  // ── 1. Analytics → blocked ────────────────────────────────────────────────
  test('data-fazcookie analytics → blocked before consent', async ({ page }) => {
    const state = await page.evaluate(() => {
      const s = document.createElement('script');
      s.setAttribute('data-fazcookie', 'fazcookie-analytics');
      s.textContent = 'window.__fazT1 = true;';
      document.head.appendChild(s);
      return {
        type: s.getAttribute('type'),
        executed: !!window.__fazT1,
      };
    });
    expect(state.type).toBe('javascript/blocked');
    expect(state.executed).toBe(false);
  });

  // ── 2. Marketing → blocked ────────────────────────────────────────────────
  test('data-fazcookie marketing → blocked before consent', async ({ page }) => {
    const state = await page.evaluate(() => {
      const s = document.createElement('script');
      s.setAttribute('data-fazcookie', 'fazcookie-marketing');
      s.textContent = 'window.__fazT2 = true;';
      document.head.appendChild(s);
      return {
        type: s.getAttribute('type'),
        executed: !!window.__fazT2,
      };
    });
    expect(state.type).toBe('javascript/blocked');
    expect(state.executed).toBe(false);
  });

  // ── 3. Necessary → never blocked ─────────────────────────────────────────
  test('data-fazcookie necessary → never blocked, always executes', async ({ page }) => {
    const state = await page.evaluate(() => {
      window.__fazT3 = false;
      const s = document.createElement('script');
      s.setAttribute('data-fazcookie', 'fazcookie-necessary');
      s.textContent = 'window.__fazT3 = true;';
      document.head.appendChild(s);
      return {
        type: s.getAttribute('type'),
        executed: window.__fazT3,
      };
    });
    expect(state.type).not.toBe('javascript/blocked');
    expect(state.executed).toBe(true);
  });

  // ── 4. faz-skip + analytics → not blocked, executes ─────────────────────
  test('faz-skip class + analytics → unconditional bypass', async ({ page }) => {
    const state = await page.evaluate(() => {
      window.__fazT4 = false;
      const s = document.createElement('script');
      s.className = 'faz-skip';
      s.setAttribute('data-fazcookie', 'fazcookie-analytics');
      s.textContent = 'window.__fazT4 = true;';
      document.head.appendChild(s);
      return {
        type: s.getAttribute('type'),
        executed: window.__fazT4,
      };
    });
    expect(state.type).not.toBe('javascript/blocked');
    expect(state.executed).toBe(true);
  });

  // ── 5. faz-skip + marketing → not blocked, executes ──────────────────────
  test('faz-skip class + marketing → unconditional bypass', async ({ page }) => {
    const state = await page.evaluate(() => {
      window.__fazT5 = false;
      const s = document.createElement('script');
      s.className = 'faz-skip';
      s.setAttribute('data-fazcookie', 'fazcookie-marketing');
      s.textContent = 'window.__fazT5 = true;';
      document.head.appendChild(s);
      return {
        type: s.getAttribute('type'),
        executed: window.__fazT5,
      };
    });
    expect(state.type).not.toBe('javascript/blocked');
    expect(state.executed).toBe(true);
  });

  // ── 6. faz-skip among multiple classes → not blocked ─────────────────────
  test('faz-skip among multiple classes → bypass still works', async ({ page }) => {
    const state = await page.evaluate(() => {
      window.__fazT6 = false;
      const s = document.createElement('script');
      s.className = 'my-config-script faz-skip extra-class';
      s.setAttribute('data-fazcookie', 'fazcookie-analytics');
      s.textContent = 'window.__fazT6 = true;';
      document.head.appendChild(s);
      return {
        type: s.getAttribute('type'),
        executed: window.__fazT6,
      };
    });
    expect(state.type).not.toBe('javascript/blocked');
    expect(state.executed).toBe(true);
  });

  // ── 7. faz-skip on external script (src URL matches tracker) → not blocked
  test('faz-skip on src-based tracker URL → not blocked', async ({ page }) => {
    const state = await page.evaluate(() => {
      const s = document.createElement('script');
      s.className = 'faz-skip';
      // src assignment triggers the src-setter intercept path.
      // Without faz-skip, googletagmanager.com matches a provider pattern.
      s.src = 'https://www.googletagmanager.com/gtag/js?id=G-FAZ-TEST';
      document.head.appendChild(s);
      return {
        type: s.getAttribute('type'),
        src: s.getAttribute('src'),
      };
    });
    expect(state.type).not.toBe('javascript/blocked');
    expect(state.src).toContain('googletagmanager.com');
  });

  // ── 8. setAttribute path: data-fazcookie set after creation → blocked ────
  test('data-fazcookie set via setAttribute after creation → blocked', async ({ page }) => {
    const state = await page.evaluate(() => {
      window.__fazT8 = false;
      const s = document.createElement('script');
      s.textContent = 'window.__fazT8 = true;';
      // Setting data-fazcookie via setAttribute triggers the attribute intercept.
      s.setAttribute('data-fazcookie', 'fazcookie-analytics');
      document.head.appendChild(s);
      return {
        type: s.getAttribute('type'),
        executed: !!window.__fazT8,
      };
    });
    expect(state.type).toBe('javascript/blocked');
    expect(state.executed).toBe(false);
  });

  // ── 9. faz-skip + setAttribute path → bypass wins ────────────────────────
  test('faz-skip class + setAttribute data-fazcookie → bypass wins', async ({ page }) => {
    const state = await page.evaluate(() => {
      window.__fazT9 = false;
      const s = document.createElement('script');
      s.className = 'faz-skip';
      s.textContent = 'window.__fazT9 = true;';
      s.setAttribute('data-fazcookie', 'fazcookie-marketing');
      document.head.appendChild(s);
      return {
        type: s.getAttribute('type'),
        executed: window.__fazT9,
      };
    });
    expect(state.type).not.toBe('javascript/blocked');
    expect(state.executed).toBe(true);
  });

  // ── 10. Unknown/custom category → safe default (not blocked) ─────────────
  test('unknown category in data-fazcookie → not blocked (safe default)', async ({ page }) => {
    const state = await page.evaluate(() => {
      window.__fazT10 = false;
      const s = document.createElement('script');
      s.setAttribute('data-fazcookie', 'fazcookie-nonexistent-custom-category');
      s.textContent = 'window.__fazT10 = true;';
      document.head.appendChild(s);
      return {
        type: s.getAttribute('type'),
        executed: window.__fazT10,
      };
    });
    // An unrecognised category should not trigger blocking —
    // FAZ only blocks categories it knows about.
    expect(state.type).not.toBe('javascript/blocked');
    expect(state.executed).toBe(true);
  });
});
