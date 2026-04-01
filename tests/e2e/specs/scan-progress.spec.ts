import { test, expect } from '@playwright/test';

const BASE = process.env.WP_BASE_URL || 'http://localhost:9998';
const USER = process.env.WP_ADMIN_USER || 'admin';
const PASS = process.env.WP_ADMIN_PASS || 'admin';

test.describe('Scan progress UI', () => {
	test.setTimeout(180_000);

	test.beforeEach(async ({ page }) => {
		await page.goto(`${BASE}/wp-login.php`);
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

		// Set up response interception — URL uses rest_route query param on dev server.
		const discoverPromise = page.waitForResponse(
			(resp) => resp.url().includes('scans%2Fdiscover') && resp.status() === 200
		);

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
