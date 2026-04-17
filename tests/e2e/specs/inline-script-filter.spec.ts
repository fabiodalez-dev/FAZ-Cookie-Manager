/**
 * E2E: WP 5.7+ wp_inline_script_tag filter
 *
 * Verifies that inline scripts matching a known provider are blocked via
 * the wp_inline_script_tag filter (when available) or by the output buffer
 * fallback, and unblocked after consent.
 *
 * The test injects a Google Analytics inline script via a mu-plugin helper
 * that uses wp_add_inline_script(), then asserts:
 *   1. Before consent: the script is rendered as type="text/plain" with
 *      data-faz-category="analytics" (blocked).
 *   2. After accept all: the script executes and sets a marker on window.
 */
import type { BrowserContext } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL || 'http://localhost:9998';
const INLINE_TEST_URL = `/?faz_inline_probe=${Date.now()}`;

// Create a mu-plugin that injects an analytics-pattern inline script
// via wp_add_inline_script(). This is the canonical WP 5.7+ path that
// our new filter should intercept.
const MU_PLUGIN_CODE = `<?php
/*
 * Plugin Name: FAZ E2E Inline Script Test
 * Description: Injects a google-analytics-pattern inline script for E2E testing.
 */
add_action('wp_enqueue_scripts', function() {
    // Register a dummy handle and attach an inline script that contains
    // a known provider pattern (googletagmanager.com). The FAZ inline
    // script filter should detect the pattern and block it.
    wp_register_script('faz-e2e-inline-test', false, array(), '1.0', false);
    wp_enqueue_script('faz-e2e-inline-test');
    wp_add_inline_script('faz-e2e-inline-test', '
        /* googletagmanager.com/gtag/js */
        window.__fazE2EInlineExecuted = true;
    ');
}, 20);
`;

const MU_PLUGIN_PATH_SEGMENT = 'faz-e2e-inline-script-test.php';

function installMuPlugin(): void {
	wpEval(`
		$mu_dir = WPMU_PLUGIN_DIR;
		if (!is_dir($mu_dir)) { wp_mkdir_p($mu_dir); }
		file_put_contents(
			$mu_dir . '/${MU_PLUGIN_PATH_SEGMENT}',
			base64_decode('${Buffer.from(MU_PLUGIN_CODE).toString('base64')}')
		);
	`);
}

function removeMuPlugin(): void {
	wpEval(`
		$path = WPMU_PLUGIN_DIR . '/${MU_PLUGIN_PATH_SEGMENT}';
		if (file_exists($path)) { unlink($path); }
	`);
}

async function seedAcceptAllConsent(context: BrowserContext): Promise<void> {
	await context.addCookies([
		{
			name: 'fazcookie-consent',
			sameSite: 'Lax',
			url: WP_BASE,
			value: [
				'consentid:faz-inline-e2e',
				'consent:yes',
				'action:yes',
				'necessary:yes',
				'functional:yes',
				'analytics:yes',
				'performance:yes',
				'uncategorized:yes',
				'marketing:yes',
				'rev:1',
			].join(','),
		},
	]);
}

test.describe.serial('WP 5.7+ inline script blocking', () => {
	test.beforeAll(async () => {
		removeMuPlugin();
		installMuPlugin();
	});

	test.afterAll(async () => {
		removeMuPlugin();
	});

	test('inline script matching a provider pattern is blocked before consent', async ({ browser }) => {
		const ctx = await browser.newContext({ baseURL: WP_BASE });
		try {
			const page = await ctx.newPage();
			await page.goto(INLINE_TEST_URL, { waitUntil: 'domcontentloaded' });

			// The inline script should be blocked (type="text/plain").
			const blockedScript = page.locator(
				'script[type="text/plain"][data-faz-category="analytics"]'
			);
			// At least one blocked script should match our injected pattern.
			const matchingScripts = await blockedScript.evaluateAll((scripts) =>
				scripts.filter((s) => s.textContent?.includes('__fazE2EInlineExecuted')).length
			);
			expect(matchingScripts, 'Injected inline script must be blocked with type="text/plain"').toBeGreaterThan(0);

			// The marker must NOT be set (script didn't execute).
			const executed = await page.evaluate(() => (window as any).__fazE2EInlineExecuted);
			expect(executed, 'Blocked inline script must not execute before consent').toBeFalsy();
		} finally {
			await ctx.close();
		}
	});

	test('blocked inline script executes after accept all', async ({ browser }) => {
		const ctx = await browser.newContext({ baseURL: WP_BASE });
		try {
			const page = await ctx.newPage();
			await page.goto(INLINE_TEST_URL, { waitUntil: 'domcontentloaded' });

			// Click Accept All.
			const acceptBtn = page.locator(
				'[data-faz-tag="accept-button"] button, [data-faz-tag="accept-button"], .faz-btn-accept'
			).first();
			await expect(acceptBtn).toBeVisible({ timeout: 5_000 });
			await acceptBtn.click();

			// After accept, the page may reload (if reloadBannerOnAccept is on)
			// or unblock in-place. Wait for the marker.
			await page.waitForFunction(
				() => (window as any).__fazE2EInlineExecuted === true,
				undefined,
				{ timeout: 10_000 }
			).catch(async () => {
				await page.waitForFunction(() => document.cookie.includes('fazcookie-consent'), undefined, {
					timeout: 5_000,
				});
				// If in-place unblock didn't fire the script, reload and check
				// (the server should render the script unblocked on next load
				// since consent cookie is now set).
				await page.goto(INLINE_TEST_URL, { waitUntil: 'domcontentloaded' });
				await page.waitForFunction(
					() => (window as any).__fazE2EInlineExecuted === true,
					undefined,
					{ timeout: 5_000 }
				);
			});

			const executed = await page.evaluate(() => (window as any).__fazE2EInlineExecuted);
			expect(executed, 'Inline script must execute after consent is granted').toBe(true);
		} finally {
			await ctx.close();
		}
	});

	test('returning visitor with stored consent executes inline script on first load', async ({ browser }) => {
		const ctx = await browser.newContext({ baseURL: WP_BASE });
		try {
			await seedAcceptAllConsent(ctx);

			const page = await ctx.newPage();
			await page.goto(INLINE_TEST_URL, { waitUntil: 'domcontentloaded' });
			await page.waitForFunction(
				() => (window as any).__fazE2EInlineExecuted === true,
				undefined,
				{ timeout: 5_000 }
			);

			const stillBlocked = await page.locator(
				'script[type="text/plain"][data-faz-category="analytics"]'
			).evaluateAll((scripts) =>
				scripts.filter((script) => script.textContent?.includes('__fazE2EInlineExecuted')).length
			);
			expect(stillBlocked, 'Stored consent must unblock the inline script on the first page load').toBe(0);

			const executed = await page.evaluate(() => (window as any).__fazE2EInlineExecuted);
			expect(executed, 'Returning visitors must execute the inline script after bootstrap').toBe(true);
		} finally {
			await ctx.close();
		}
	});
});
