import { test, expect } from '@playwright/test';
import { getWpLoginPath } from '../utils/wp-auth';

const BASE = process.env.WP_BASE_URL || 'http://127.0.0.1:9998';
const USER = process.env.WP_ADMIN_USER || 'admin';
const PASS = process.env.WP_ADMIN_PASS || 'admin';
const WP_LOGIN_PATH = getWpLoginPath();

test.describe('Scan progress UI', () => {
	test.setTimeout(240_000);

	test.beforeEach(async ({ page }) => {
		await page.goto(`${BASE}${WP_LOGIN_PATH}`);
		await page.fill('#user_login', USER);
		await page.fill('#user_pass', PASS);
		await page.click('#wp-submit');
		await page.waitForURL(/wp-admin/);
	});

	test('shows total pages immediately after discover and updates progress', async ({ page }) => {
		await page.goto(`${BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`);
		await page.waitForLoadState('networkidle');

		// Clear stored fingerprint for a full scan.
		await page.evaluate(() => {
			try { localStorage.removeItem('faz_scan_fingerprint'); } catch (_) {}
		});

		// Set up response interception. Plain permalinks use encoded
		// `rest_route=`; pretty permalinks use `/wp-json/`. We used to also
		// reject non-200 responses here, but that buried real error statuses
		// (409/500/403 on nonce expiry) under a timeout instead of surfacing
		// them — the predicate now matches on URL shape only, so errors fail
		// fast with a useful response. `decodeURIComponent` throws on
		// malformed %-sequences; guard it so one bad URL in the stream
		// doesn't break the whole wait.
		const discoverPromise = page.waitForResponse((resp) => {
			if (resp.request().method() === 'OPTIONS') return false;
			let decoded = resp.url();
			try {
				decoded = decodeURIComponent(decoded);
			} catch (_e) {
				// Fallback to the raw URL if it contains a malformed escape.
			}
			return decoded.includes('rest_route=/faz/v1/scans/discover')
				|| decoded.includes('/wp-json/faz/v1/scans/discover');
		});

		// Progress semantics do not depend on re-crawling the standard profile;
		// the quick scan keeps this UI assertion bounded while still including
		// the scanner's priority URLs.
		await page.click('#faz-scan-btn');
		await page.click('.faz-dropdown-item[data-depth="10"]');

		// 1. "Discovering pages..." should appear immediately.
		const statusEl = page.locator('.faz-scan-status');
		await expect(statusEl).toBeVisible({ timeout: 5000 });
		const initialText = await statusEl.textContent();
		console.log('[Progress] Initial status:', initialText);
		expect(initialText).toContain('Discovering');

		// 2. Wait for discover API and check total.
		const resp = await discoverPromise;
		const data = await resp.json();
		console.log('[Progress] Discover response — total:', data.total, 'incremental:', data.incremental);
		expect(data.total).toBeGreaterThan(0);

		// 3. After discover, status should show "X/N pages" with N = total.
		await expect(statusEl).toContainText(/\d+\/\d+ pages/, { timeout: 10000 });
		const afterDiscoverText = await statusEl.textContent();
		console.log('[Progress] After discover:', afterDiscoverText);

		// Extract total from status text.
		const totalMatch = afterDiscoverText?.match(/(\d+)\/(\d+) pages/);
		expect(totalMatch).not.toBeNull();
		const displayedTotal = parseInt(totalMatch![2], 10);
		console.log('[Progress] Displayed total:', displayedTotal, '| API total:', data.total);
		expect(displayedTotal).toBe(data.total);

		// 4. Pages counter element should also show total.
		const pagesEl = page.locator('.faz-scan-pages');
		await expect(pagesEl).toContainText(`/${data.total} pages`);

		// 5. Wait for progress to advance (at least 1 page scanned).
		await expect(statusEl).toContainText(/[1-9]\d*\/\d+ pages/, { timeout: 60000 });
		const progressText = await statusEl.textContent();
		console.log('[Progress] Progress advancing:', progressText);

		// 6. Progress bar should have non-zero width.
		const barWidth = await page.locator('.faz-scan-bar').evaluate(
			(el: HTMLElement) => el.style.width
		);
		console.log('[Progress] Bar width:', barWidth);
		expect(barWidth).not.toBe('0%');

		// 7. Wait for scan to complete.
		await page.waitForFunction(
			() => !document.querySelector('.faz-scan-progress-wrap'),
			undefined,
			{ timeout: 180000 }
		);
		console.log('[Progress] Scan complete — progress UI removed.');

		// 8. Button should be back to normal.
		await expect(page.locator('#faz-scan-btn')).toContainText('Scan Site');
	});

	// Regression pin for the production report "14/18 pages | 0 cookies | 126
	// scripts" shown all the way through a crawl whose import then contained
	// the cookies. The only number the client can compute mid-crawl is the
	// count of cookies NEWLY set where document.cookie can see them; HttpOnly
	// cookies captured server-side, cookies inferred from script URLs at save
	// time, and names already in the administrator's jar all land only at
	// import. Presenting that partial number as "N cookies" told the
	// administrator the scanner found nothing while it was working, so the
	// engine defers the cookie count to the completion summary, where it is
	// the true post-import total.
	test('mid-crawl status never presents a partial cookie count; the true total arrives at completion', async ({ page }) => {
		await page.goto(`${BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`);
		await page.waitForLoadState('networkidle');

		await page.evaluate(() => {
			try { localStorage.removeItem('faz_scan_fingerprint'); } catch (_) {}
		});

		// Record every text the status element renders during the run. A
		// MutationObserver mounted before the scan starts sees each update,
		// including ones that land between Playwright polls.
		await page.evaluate(() => {
			(window as any).__fazStatusSamples = [];
			const record = () => {
				const el = document.querySelector('.faz-scan-status');
				if (!el) return;
				const text = el.textContent || '';
				const log = (window as any).__fazStatusSamples;
				if (text && log[log.length - 1] !== text) log.push(text);
			};
			new MutationObserver(record).observe(document.body, { subtree: true, childList: true, characterData: true });
		});

		await page.click('#faz-scan-btn');
		await page.click('.faz-dropdown-item[data-depth="10"]');

		await page.waitForFunction(
			() => !document.querySelector('.faz-scan-progress-wrap'),
			undefined,
			{ timeout: 180000 }
		);

		const samples: string[] = await page.evaluate(() => (window as any).__fazStatusSamples);
		console.log('[Progress] Sampled statuses:', JSON.stringify(samples, null, 1));

		// Guard against a vacuous pass: the sampler must have observed real
		// mid-crawl page-counter updates, not just the discovery message.
		const crawlSamples = samples.filter((t) => /\d+\/\d+ pages/.test(t));
		expect(crawlSamples.length).toBeGreaterThan(0);

		// The defect under test: any status of the form "N cookies" mid-crawl
		// is a partial, JS-only observation presented as the scan's answer.
		const partialCookieClaims = samples.filter((t) => /\d+ cookies/.test(t));
		expect(partialCookieClaims).toEqual([]);

		// Deferred, not dropped: the completion summary reports the true
		// post-import total (client observations + server-captured HttpOnly +
		// script-inferred cookies).
		await expect(page.locator('.faz-toast').last()).toContainText(/\d+ cookies found on \d+ pages/);
	});
});
