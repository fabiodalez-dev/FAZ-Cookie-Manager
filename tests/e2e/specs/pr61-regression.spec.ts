import { expect, test, type Page } from '../fixtures/wp-fixture';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
	deactivatePluginsExcept,
	activatePlugins,
	deleteOption,
	ensureFixturePlugin,
	listActivePlugins,
	setOption,
	wp,
} from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';
const REPO_ROOT = dirname(dirname(dirname(dirname(fileURLToPath(import.meta.url)))));
const PRESET_DIR = join(REPO_ROOT, 'admin', 'modules', 'banners', 'includes', 'presets');

type PresetConfig = {
	name: string;
	config: {
		notice: {
			elements: {
				buttons: {
					elements: {
						accept: { styles: Record<string, string> };
						reject: { styles: Record<string, string> };
					};
				};
			};
		};
		preferenceCenter: {
			styles: Record<string, string>;
			elements: {
				title: { styles: Record<string, string> };
				description: { styles: Record<string, string> };
				categories: {
					elements: {
						toggle: {
							states: {
								active: { styles: Record<string, string> };
								inactive: { styles: Record<string, string> };
							};
						};
					};
				};
				buttons: {
					elements: {
						accept: { styles: Record<string, string> };
						reject: { styles: Record<string, string> };
						save: { styles: Record<string, string> };
					};
				};
			};
		};
		optoutPopup: {
			elements: {
				buttons: {
					elements: {
						confirm: { styles: Record<string, string> };
						cancel: { styles: Record<string, string> };
					};
				};
			};
		};
		categoryPreview: {
			elements: {
				title: { styles: Record<string, string> };
				buttons: {
					elements: {
						save: { styles: Record<string, string> };
					};
				};
			};
		};
	};
};

type BannerPayload = {
	id: number;
	name: string;
	status: boolean;
	default: boolean;
	properties: Record<string, unknown>;
	contents: Record<string, unknown>;
};

function loadDesignPresets(): PresetConfig[] {
	return readdirSync(PRESET_DIR)
		.filter((file) => file.endsWith('.json'))
		.sort()
		.map((file) => JSON.parse(readFileSync(join(PRESET_DIR, file), 'utf8')) as PresetConfig);
}

function cssColor(value: string): string {
	const normalized = value.trim().toLowerCase();
	if (normalized === 'transparent') {
		return 'rgba(0, 0, 0, 0)';
	}
	const hex = normalized.startsWith('#') ? normalized.slice(1) : normalized;
	const full = hex.length === 3
		? hex.split('').map((ch) => ch + ch).join('')
		: hex;
	const r = Number.parseInt(full.slice(0, 2), 16);
	const g = Number.parseInt(full.slice(2, 4), 16);
	const b = Number.parseInt(full.slice(4, 6), 16);
	return `rgb(${r}, ${g}, ${b})`;
}

async function getAdminNonce(page: Page): Promise<string> {
	return page.evaluate(() => (window as any).fazConfig?.api?.nonce ?? '');
}

async function goToBannerPage(page: Page) {
	await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, {
		waitUntil: 'domcontentloaded',
		timeout: 45_000,
	});
	await page.waitForFunction(() => {
		const el = document.getElementById('faz-b-type') as HTMLSelectElement | null;
		return !!el && el.value !== '';
	}, undefined, { timeout: 10_000 });
	await expect(page.locator('.faz-preset-card')).toHaveCount(5);
}

async function getBanner(page: Page, nonce: string, id = 1): Promise<BannerPayload> {
	const response = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/banners/${id}`, {
		headers: { 'X-WP-Nonce': nonce },
	});
	expect(response.status()).toBe(200);
	return response.json();
}

async function updateBanner(page: Page, nonce: string, id: number, payload: BannerPayload) {
	const response = await page.request.put(`${WP_BASE}/?rest_route=/faz/v1/banners/${id}`, {
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		data: payload,
	});
	expect(response.status()).toBe(200);
}

async function saveBanner(page: Page) {
	const responsePromise = page.waitForResponse(
		(response) =>
			response.url().includes('banners')
			&& !response.url().includes('preview')
			&& (response.request().method() === 'PUT' || response.request().method() === 'POST'),
		{ timeout: 30_000 },
	);
	await page.click('#faz-b-save');
	const response = await responsePromise;
	expect(response.status()).toBe(200);
	await page.waitForSelector('.faz-toast-success', { state: 'visible', timeout: 10_000 }).catch(() => {});
}

async function getInputValue(page: Page, id: string): Promise<string> {
	return page.evaluate((elId) => {
		const el = document.getElementById(elId) as HTMLInputElement | null;
		return el?.value ?? '';
	}, id);
}

async function openVisitorPage(browser: any, baseURL: string) {
	const ctx = await browser.newContext({ baseURL });
	const page = await ctx.newPage();
	await page.goto('/', { waitUntil: 'domcontentloaded', timeout: 45_000 });
	return { ctx, page };
}

async function openPreferenceCenter(page: Page) {
	await page.waitForFunction(() => typeof (window as any)._fazSetPreferenceAction === 'function', undefined, { timeout: 10_000 });
	await page.evaluate(() => {
		(window as any)._fazSetPreferenceAction('settings-button');
	});
}

async function readPreferenceCenterPalette(page: Page) {
	return page.evaluate(() => {
		const read = (selector: string) => {
			const el = document.querySelector(selector) as HTMLElement | null;
			if (!el) {
				return null;
			}
			const style = getComputedStyle(el);
			return {
				backgroundColor: style.backgroundColor,
				color: style.color,
				borderColor: style.borderColor,
			};
		};

		return {
			modal: read('.faz-modal'),
			content: read('.faz-preference-center'),
			categoryTitle: read('.faz-accordion-btn'),
			accept: read('[data-faz-tag="detail-accept-button"]'),
			reject: read('[data-faz-tag="detail-reject-button"]'),
		};
	});
}

async function getSettings(page: Page, nonce: string) {
	const response = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/settings`, {
		headers: { 'X-WP-Nonce': nonce },
	});
	expect(response.status()).toBe(200);
	return response.json();
}

async function updateSettings(page: Page, nonce: string, data: Record<string, unknown>) {
	const response = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/settings`, {
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		data,
	});
	expect(response.status()).toBe(200);
	return response.json();
}

async function getGcmSettings(page: Page, nonce: string) {
	const response = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/gcm`, {
		headers: { 'X-WP-Nonce': nonce },
	});
	expect(response.status()).toBe(200);
	return response.json();
}

async function updateGcmSettings(page: Page, nonce: string, data: Record<string, unknown>) {
	const response = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/gcm`, {
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		data,
	});
	expect(response.status()).toBe(200);
	return response.json();
}

async function driveConsent(page: Page, choice: 'all' | 'reject') {
	await page.waitForFunction(() => typeof (window as any)._fazAcceptCookies === 'function', undefined, { timeout: 10_000 });
	await page.evaluate((selectedChoice) => {
		(window as any)._fazAcceptCookies(selectedChoice);
	}, choice);
}

function captureRuntimeErrors(page: Page) {
	const consoleErrors: string[] = [];
	const pageErrors: string[] = [];
	page.on('console', (msg) => {
		if (msg.type() === 'error') {
			consoleErrors.push(msg.text());
		}
	});
	page.on('pageerror', (error) => {
		pageErrors.push(String(error));
	});
	return { consoleErrors, pageErrors };
}

function restorePlugins(originalActive: string[]) {
	deactivatePluginsExcept(originalActive);
	activatePlugins(originalActive);
}

test.describe.serial('PR #61 regressions', () => {
	test('design presets style the preference center and keep transparent category-preview save buttons', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
		test.setTimeout(240_000);
		const presets = loadDesignPresets();

		await loginAsAdmin(page);
		await goToBannerPage(page);
		const nonce = await getAdminNonce(page);
		const originalBanner = await getBanner(page, nonce);

		try {
			for (const preset of presets) {
				await page.locator('.faz-preset-card', { hasText: preset.name }).click();
				const expectedCatPreviewSaveBg = preset.config.categoryPreview.elements.buttons.elements.save.styles['background-color'];
				await expect.poll(() => getInputValue(page, 'faz-b-catprev-save-bg-hex')).toBe(expectedCatPreviewSaveBg);
				await saveBanner(page);
				const savedBanner = await getBanner(page, nonce);
				const savedConfig = (savedBanner.properties as Record<string, any>).config ?? {};
				expect(savedConfig.preferenceCenter?.elements?.categories?.elements?.toggle?.states?.active?.styles?.['background-color']).toBe(
					preset.config.preferenceCenter.elements.categories.elements.toggle.states.active.styles['background-color'],
				);
				expect(savedConfig.preferenceCenter?.elements?.closeButton).toBeTruthy();
				expect(savedConfig.preferenceCenter?.elements?.poweredBy).toBeTruthy();
				expect(savedConfig.optoutPopup?.elements?.gpcOption).toBeTruthy();
				expect(savedConfig.optoutPopup?.elements?.closeButton).toBeTruthy();
				expect(savedConfig.optoutPopup?.elements?.poweredBy).toBeTruthy();
				expect(savedConfig.optoutPopup?.elements?.buttons?.elements?.confirm?.styles?.['background-color']).toBe(
					preset.config.optoutPopup.elements.buttons.elements.confirm.styles['background-color'],
				);
				expect(savedConfig.optoutPopup?.elements?.buttons?.elements?.cancel?.styles?.['border-color']).toBe(
					preset.config.optoutPopup.elements.buttons.elements.cancel.styles['border-color'],
				);

				const visitor = await openVisitorPage(browser, wpBaseURL);
				try {
					await openPreferenceCenter(visitor.page);
					const palette = await readPreferenceCenterPalette(visitor.page);

					expect(palette.modal?.backgroundColor ?? palette.content?.backgroundColor).toBe(cssColor(preset.config.preferenceCenter.styles['background-color']));
					expect(palette.content?.color).toBe(cssColor(preset.config.preferenceCenter.styles.color));
					expect(palette.categoryTitle?.color).toBe(cssColor(preset.config.categoryPreview.elements.title.styles.color));
					expect(palette.accept?.backgroundColor).toBe(cssColor(preset.config.notice.elements.buttons.elements.accept.styles['background-color']));
					expect(palette.accept?.color).toBe(cssColor(preset.config.notice.elements.buttons.elements.accept.styles.color));
					expect(palette.reject?.backgroundColor).toBe(cssColor(preset.config.notice.elements.buttons.elements.reject.styles['background-color']));
					expect(palette.reject?.color).toBe(cssColor(preset.config.notice.elements.buttons.elements.reject.styles.color));
				} finally {
					await visitor.ctx.close();
				}

				await goToBannerPage(page);
				await expect.poll(() => getInputValue(page, 'faz-b-catprev-save-bg-hex')).toBe(expectedCatPreviewSaveBg);
			}
		} finally {
			await updateBanner(page, nonce, originalBanner.id, {
				id: originalBanner.id,
				name: originalBanner.name,
				status: originalBanner.status,
				default: originalBanner.default,
				properties: originalBanner.properties,
				contents: originalBanner.contents,
			} as BannerPayload);
		}
	});

	test('PMP-exempt members do not log template errors and still emit granted GCM consent', async ({ page, loginAsAdmin }) => {
		test.setTimeout(120_000);
		const originalActive = listActivePlugins();
		ensureFixturePlugin('faz-e2e-pmp-mock');

		// Declare outside the try so the finally block can access them
		// for rollback even when the test fails mid-flight.
		let settingsNonce = '';
		let gcmNonce = '';
		let beforeSettings: any = null;
		let beforeGcm: any = null;

		try {
			try {
				wp(['plugin', 'activate', 'google-site-kit']);
			} catch {
				test.skip(true, 'google-site-kit is not installed in the WordPress sandbox');
			}

			setOption('faz_e2e_pmp_mock_levels', '2');

			await loginAsAdmin(page);
			await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
			settingsNonce = await getAdminNonce(page);
			beforeSettings = await getSettings(page, settingsNonce);

			await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-gcm`, { waitUntil: 'domcontentloaded' });
			gcmNonce = await getAdminNonce(page);
			beforeGcm = await getGcmSettings(page, gcmNonce);

			await updateSettings(page, settingsNonce, {
				integrations: {
					paid_memberships_pro: {
						enabled: true,
						exempt_levels: [2],
					},
				},
			});
			await updateGcmSettings(page, gcmNonce, {
				status: true,
				non_personalized_ads_fallback: false,
			});

			const { consoleErrors, pageErrors } = captureRuntimeErrors(page);
			await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });

			expect(await page.locator('#fazBannerTemplate').count()).toBe(0);
			const bannerHidden = await page.locator('#faz-consent, [data-faz-tag="notice"]').first().isHidden({ timeout: 3_000 }).catch(() => true);
			expect(bannerHidden).toBe(true);

			await page.waitForFunction(() => {
				const dl = (window as any).dataLayer || [];
				return Array.isArray(dl) && dl.some((entry: any) => entry && entry[0] === 'consent');
			}, undefined, { timeout: 10_000 });

			const firstConsentCall = await page.evaluate(() => {
				const dl = (window as any).dataLayer || [];
				for (const entry of dl as any[]) {
					if (entry && entry[0] === 'consent') {
						return {
							mode: entry[1],
							payload: entry[2],
						};
					}
				}
				return null;
			});

			const consentCookie = (await page.context().cookies()).find((cookie) => cookie.name === 'fazcookie-consent');
			expect(consentCookie).toBeTruthy();
			expect(firstConsentCall).toBeTruthy();
			expect(firstConsentCall!.mode).toBe('default');
			expect(firstConsentCall!.payload?.ad_storage).toBe('granted');
			expect(firstConsentCall!.payload?.analytics_storage).toBe('granted');

			const allErrors = [...consoleErrors, ...pageErrors].join('\n');
			expect(allErrors).not.toContain('Cannot read properties of null');
			expect(allErrors).not.toContain("reading 'innerHTML'");

		} finally {
			// Restore settings even on test failure so the serial suite
			// does not start subsequent tests from a dirty state.
			if (beforeSettings && settingsNonce) {
				await updateSettings(page, settingsNonce, {
					integrations: beforeSettings.integrations ?? { paid_memberships_pro: { enabled: false, exempt_levels: [] } },
				}).catch(() => { /* best-effort */ });
			}
			if (beforeGcm && gcmNonce) {
				await updateGcmSettings(page, gcmNonce, {
					status: beforeGcm.status ?? false,
					non_personalized_ads_fallback: beforeGcm.non_personalized_ads_fallback ?? false,
				}).catch(() => { /* best-effort */ });
			}
			deleteOption('faz_e2e_pmp_mock_levels');
			restorePlugins(originalActive);
		}
	});

	test('frontend survives a missing banner template element without throwing the old TypeError', async ({ page }) => {
		test.setTimeout(90_000);
		const originalActive = listActivePlugins();
		ensureFixturePlugin('faz-e2e-template-stripper');

		try {
			const { consoleErrors, pageErrors } = captureRuntimeErrors(page);
			await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });
			await page.waitForFunction(() => typeof (window as any)._fazRenderBanner === 'function', undefined, { timeout: 10_000 });

			expect(await page.locator('#fazBannerTemplate').count()).toBe(0);

			const allErrors = [...consoleErrors, ...pageErrors].join('\n');
			expect(allErrors).not.toContain('Cannot read properties of null');
			expect(allErrors).not.toContain("reading 'innerHTML'");
		} finally {
			restorePlugins(originalActive);
		}
	});

	test('WP Consent API integration still updates consent after the GSK bootstrap script', async ({ page }) => {
		test.setTimeout(120_000);
		const originalActive = listActivePlugins();
		ensureFixturePlugin('faz-e2e-wp-consent-api-mock');

		try {
			try {
				wp(['plugin', 'activate', 'google-site-kit']);
			} catch {
				test.skip(true, 'google-site-kit is not installed in the WordPress sandbox');
			}

			const { consoleErrors, pageErrors } = captureRuntimeErrors(page);
			await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });

			const html = await page.content();
			expect(html).toContain('var _fazGsk = true;');

			await page.waitForFunction(() => Array.from(document.scripts).some((script) => script.src.includes('/frontend/js/wca.js')), undefined, { timeout: 10_000 });
			await driveConsent(page, 'all');
			await page.waitForFunction(() => Array.isArray((window as any)._fazWpConsentCalls) && (window as any)._fazWpConsentCalls.length > 0, undefined, { timeout: 10_000 });

			const consentBridge = await page.evaluate(() => ({
				gsk: (window as any)._fazGsk === true,
				consentType: (window as any).wp_consent_type || '',
				events: (window as any)._fazWpConsentTypeEvents || [],
				calls: (window as any)._fazWpConsentCalls || [],
			}));

			expect(consentBridge.gsk).toBe(true);
			expect(consentBridge.consentType).toBe('optin');
			expect(consentBridge.events).toContain('optin');
			expect(consentBridge.calls).toEqual(
				expect.arrayContaining([
					expect.objectContaining({ key: 'marketing', status: 'allow' }),
					expect.objectContaining({ key: 'preferences', status: 'allow' }),
				]),
			);

			const allErrors = [...consoleErrors, ...pageErrors].join('\n');
			expect(allErrors).not.toContain('SyntaxError');
		} finally {
			restorePlugins(originalActive);
		}
	});
});
