import { test, expect } from '@playwright/test';
import { getWpLoginPath } from '../utils/wp-auth';

const BASE = process.env.WP_BASE_URL || 'http://localhost:9998';
const USER = process.env.WP_ADMIN_USER || 'admin';
const PASS = process.env.WP_ADMIN_PASS || 'admin';
const WP_LOGIN_PATH = getWpLoginPath();

test.describe('Scan progress UI', () => {
	test.setTimeout(180_000);

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

		// Open dropdown and click "Standard scan (100 pages)".
		await page.click('#faz-scan-btn');
		await page.click('.faz-dropdown-item[data-depth="100"]');

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
		expect(data.total).toBeGreaterThan(10);

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
			{ timeout: 120000 }
		);
		console.log('[Progress] Scan complete — progress UI removed.');

		// 8. Button should be back to normal.
		await expect(page.locator('#faz-scan-btn')).toContainText('Scan Site');
	});
});
