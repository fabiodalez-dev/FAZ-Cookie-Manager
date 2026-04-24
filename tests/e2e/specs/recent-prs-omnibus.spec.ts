import { expect, test } from '../fixtures/wp-fixture';
import {
	deleteCookiesByPrefix,
	findCategoryId,
	fazApiDelete,
	fazApiGet,
	fazApiPost,
	listCookies,
	openCookiesPage,
	openSettingsPage,
} from '../utils/faz-api';
import {
	emulateNavigatorLanguages,
	getSelectedLanguages,
	readFazConfig,
	restoreLanguages,
	waitForBannerReady,
} from '../utils/multilingual';
import {
	disableLabFlags,
	enableWooLabScenario,
	ensureFixturePlugin,
	ensureProviderMatrixPage,
	ensureScanLabPages,
	ensureWooCommerceLabData,
	readProviderMatrixHits,
	readProviderMatrixUrl,
	resetProviderMatrixState,
	setLabToken,
	touchPosts,
	wpEval,
} from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';

const FIXTURE_CUSTOM_RULES = [
	{ category: 'performance', pattern: 'faz-lab-custom-provider.js' },
	{ category: 'functional', pattern: 'faz-lab-custom-functional.js' },
];

type SettingsData = Record<string, any>;

type BannerRecord = {
	id: number;
	name: string;
	status: string;
	default: boolean;
	properties: Record<string, any>;
	contents: Record<string, any>;
};

type DiscoverResponse = {
	urls: string[];
	priority_urls: string[];
	total: number;
	fingerprint: string;
	incremental: boolean;
};

type ConsentMap = Record<string, string>;

function decodeUrl(url: string): string {
	try {
		return decodeURIComponent(url);
	} catch {
		return url;
	}
}

function makeToken(prefix: string): string {
	const random = Math.random().toString(36).slice(2, 8);
	return `${prefix}-${Date.now().toString(36)}-${random}`.toLowerCase();
}

function parseConsentCookieValue(raw: string): ConsentMap {
	return raw.split(',').reduce<ConsentMap>((acc, pair) => {
		const trimmed = pair.trim();
		const idx = trimmed.indexOf(':');
		if (idx === -1) {
			return acc;
		}
		const key = trimmed.substring(0, idx).trim();
		if (!key) {
			return acc;
		}
		acc[key] = trimmed.substring(idx + 1).trim();
		return acc;
	}, {});
}

function mergeFixtureCustomRules(currentRules: any[] | undefined): any[] {
	const merged = Array.isArray(currentRules) ? [...currentRules] : [];
	for (const rule of FIXTURE_CUSTOM_RULES) {
		if (!merged.some((entry) => entry?.category === rule.category && entry?.pattern === rule.pattern)) {
			merged.push(rule);
		}
	}
	return merged;
}

function clearConsentLogState(): void {
	// Table name goes into the query via string interpolation because
	// `$wpdb->prepare()` has no placeholder for table identifiers. Validate
	// the computed name against a strict allowlist before the DELETE so any
	// future refactor that corrupts `$wpdb->prefix` fails loudly instead of
	// silently running an unexpected query.
	wpEval(`
		global $wpdb;
		$table = $wpdb->prefix . 'faz_consent_logs';
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || $table !== $wpdb->prefix . 'faz_consent_logs' ) {
			echo 'refused: unexpected table name ' . $table;
			return;
		}
		$wpdb->query( "DELETE FROM {$table}" );
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_faz_consent_%'
			OR option_name LIKE '_transient_timeout_faz_consent_%'"
		);
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		echo 'ok';
	`);
}

function readWooUrls(): { cart: string; checkout: string; myaccount: string; product: string; shop: string } {
	const raw = wpEval(`
		$urls = array();
		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $key ) {
			$id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( $key ) : 0;
			$urls[ $key ] = $id > 0 ? get_permalink( $id ) : '';
		}
		$product = get_page_by_path( 'faz-lab-woo-product', OBJECT, 'product' );
		$urls['product'] = $product ? get_permalink( $product ) : '';
		echo wp_json_encode( $urls );
	`);

	return JSON.parse(raw) as { cart: string; checkout: string; myaccount: string; product: string; shop: string };
}

async function getSettings(page: Parameters<typeof openSettingsPage>[0], nonce: string): Promise<SettingsData> {
	const response = await fazApiGet<SettingsData>(page, nonce, 'settings');
	expect(response.status).toBe(200);
	return response.data;
}

async function updateSettings(page: Parameters<typeof openSettingsPage>[0], nonce: string, payload: SettingsData): Promise<void> {
	const response = await fazApiPost<SettingsData>(page, nonce, 'settings', payload);
	expect(response.status).toBe(200);
}

async function openBannerPage(page: Parameters<typeof openSettingsPage>[0], loginAsAdmin: (page: Parameters<typeof openSettingsPage>[0]) => Promise<void>): Promise<string> {
	await loginAsAdmin(page);
	await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, {
		waitUntil: 'domcontentloaded',
	});
	await page.waitForFunction(
		() => {
			const select = document.getElementById('faz-b-type') as HTMLSelectElement | null;
			return Boolean(select?.value);
		},
		undefined,
		{ timeout: 10_000 },
	);
	const nonce = await page.evaluate(() => (window as unknown as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '');
	if (!nonce) {
		throw new Error('Unable to read FAZ REST nonce from banner page.');
	}
	return nonce;
}

async function getBanner(page: Parameters<typeof openSettingsPage>[0], nonce: string, id = 1): Promise<BannerRecord> {
	const response = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/banners/${id}`, {
		headers: { 'X-WP-Nonce': nonce },
	});
	expect(response.status()).toBe(200);
	return response.json() as Promise<BannerRecord>;
}

async function updateBanner(page: Parameters<typeof openSettingsPage>[0], nonce: string, id: number, payload: Record<string, unknown>) {
	const response = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/banners/${id}`, {
		headers: {
			'Content-Type': 'application/json',
			'X-HTTP-Method-Override': 'PUT',
			'X-WP-Nonce': nonce,
		},
		data: payload,
	});
	expect(response.status()).toBe(200);
	return response.json();
}

async function waitForCookie(page: Parameters<typeof openSettingsPage>[0], name: string, timeout = 15_000): Promise<void> {
	await page.waitForFunction(
		(cookieName) => document.cookie.split(';').some((chunk) => chunk.trim().startsWith(`${cookieName}=`)),
		name,
		{ timeout },
	);
}

async function driveConsent(page: Parameters<typeof openSettingsPage>[0], choice: 'all' | 'reject', expectedMarketing: 'yes' | 'no'): Promise<void> {
	await page.waitForFunction(
		() => typeof (window as unknown as { _fazAcceptCookies?: unknown })._fazAcceptCookies === 'function',
		undefined,
		{ timeout: 10_000 },
	);
	await page.evaluate((selectedChoice) => {
		(window as unknown as { _fazAcceptCookies: (value: string) => unknown })._fazAcceptCookies(selectedChoice);
	}, choice);
	await page.waitForFunction((expected) => {
		const raw = document.cookie.split(';').find((chunk) => chunk.trim().startsWith('fazcookie-consent='));
		if (!raw) {
			return false;
		}
		const encoded = raw.split('=').slice(1).join('=');
		let value = encoded;
		try {
			value = decodeURIComponent(encoded);
		} catch {
			value = encoded;
		}
		return /(?:^|,)action:yes(?:,|$)/.test(value)
			&& new RegExp(`(?:^|,)marketing:${expected}(?:,|$)`).test(value);
	}, expectedMarketing, { timeout: 5_000 });
}

test.describe.serial('Recent PR omnibus regressions', () => {
	let providerMatrixUrl = '';

	test.beforeAll(async () => {
		ensureFixturePlugin('faz-e2e-provider-matrix');
		ensureFixturePlugin('faz-e2e-scan-lab');
		ensureProviderMatrixPage();
		ensureScanLabPages();
		ensureWooCommerceLabData();
		providerMatrixUrl = readProviderMatrixUrl();
		if (!providerMatrixUrl) {
			throw new Error('Provider matrix URL could not be resolved.');
		}
	});

	test.afterAll(() => {
		disableLabFlags();
		resetProviderMatrixState({ clearFixtureCustomRules: true });
		clearConsentLogState();
		wpEval(`
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}faz_cookies WHERE name LIKE %s", '_faz_lab_%' ) );
			if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
				\\FazCookie\\Includes\\Cache::invalidate_cache_group( 'cookies' );
				\\FazCookie\\Includes\\Cache::invalidate_cache_group( 'category' );
			}
		`);
	});

	test('omnibus: multilingual render, inherited defaults, region-strip swap, and no-op swap stay coherent', async ({
		page,
		browser,
		request,
		loginAsAdmin,
		wpBaseURL,
	}) => {
		const nonce = await openSettingsPage(page, loginAsAdmin);
		const originalLanguages = await getSelectedLanguages(page);

		try {
			await updateSettings(page, nonce, {
				languages: { default: 'de', selected: ['de'] },
			});
			const germanResponse = await request.get(`${wpBaseURL}/?rest_route=/faz/v1/banner/de`, {
				headers: { 'Accept-Language': 'de-DE,de;q=0.9' },
			});
			expect(germanResponse.status()).toBe(200);
			const germanPayload = await germanResponse.json();
			const germanHtml = String(germanPayload?.html ?? '');
			expect(/privat|akzeptieren|ablehnen|einstellungen/i.test(germanHtml)).toBe(true);
			expect(germanHtml.toLowerCase()).not.toContain('accept all');
			expect(germanHtml.toLowerCase()).not.toContain('reject all');

			await updateSettings(page, nonce, {
				languages: { default: 'en', selected: ['en', 'de'] },
			});
			await request.get(wpBaseURL, {
				headers: { 'Accept-Language': 'en-US,en;q=0.9' },
			});

			const swapContext = await browser.newContext({ baseURL: wpBaseURL });
			await emulateNavigatorLanguages(swapContext, ['de-AT', 'de', 'en']);
			const swapPage = await swapContext.newPage();
			await swapPage.goto('/', { waitUntil: 'domcontentloaded' });
			await waitForBannerReady(swapPage, 20_000, 'de');
			await swapPage.waitForFunction(
				() => (window as unknown as { _fazConfig?: { _swapResolved?: boolean } })._fazConfig?._swapResolved === true,
				undefined,
				{ timeout: 5_000 },
			);
			const swappedConfig = await readFazConfig(swapPage);
			expect(swappedConfig?._language).toBe('de');
			await swapContext.close();

			const noOpContext = await browser.newContext({ baseURL: wpBaseURL });
			await emulateNavigatorLanguages(noOpContext, ['en-US', 'en']);
			const noOpPage = await noOpContext.newPage();
			const bannerFetches: string[] = [];
			noOpPage.on('request', (req) => {
				if (req.url().includes('/faz/v1/banner/')) {
					bannerFetches.push(req.url());
				}
			});
			await noOpPage.goto('/', { waitUntil: 'domcontentloaded' });
			await waitForBannerReady(noOpPage, 20_000, 'en');
			await noOpPage.waitForFunction(
				() => (window as unknown as { _fazConfig?: { _swapResolved?: boolean } })._fazConfig?._swapResolved === true,
				undefined,
				{ timeout: 5_000 },
			);
			expect(bannerFetches).toHaveLength(0);
			await noOpContext.close();
		} finally {
			await restoreLanguages(page, originalLanguages);
		}
	});

	test('omnibus: query-string REST overrides keep banner PUT and cookie DELETE portable', async ({ page, loginAsAdmin }) => {
		const bannerNonce = await openBannerPage(page, loginAsAdmin);
		const originalBanner = await getBanner(page, bannerNonce);
		const modifiedBanner = JSON.parse(JSON.stringify(originalBanner)) as BannerRecord;
		modifiedBanner.properties.settings.type =
			modifiedBanner.properties.settings.type === 'box' ? 'banner' : 'box';

		let tempCookieId: number | null = null;
		let cookiesNonce = '';

		try {
			await updateBanner(page, bannerNonce, originalBanner.id, {
				name: modifiedBanner.name,
				status: modifiedBanner.status,
				default: modifiedBanner.default,
				properties: modifiedBanner.properties,
				contents: modifiedBanner.contents,
			});

			const bannerAfterUpdate = await getBanner(page, bannerNonce, originalBanner.id);
			expect(bannerAfterUpdate.properties.settings.type).toBe(modifiedBanner.properties.settings.type);

			cookiesNonce = await openCookiesPage(page, loginAsAdmin);
			const uncategorizedId = await findCategoryId(page, cookiesNonce, 'uncategorized');
			const tempPrefix = `faz-omni-${Date.now()}`;
			const createResponse = await fazApiPost<any>(page, cookiesNonce, 'cookies', {
				category: uncategorizedId,
				description: { en: 'Omnibus delete regression cookie' },
				discovered: false,
				domain: '.example.com',
				duration: { en: '1 year' },
				name: `${tempPrefix}_cookie`,
				slug: `${tempPrefix}_cookie`,
				type: 0,
				url_pattern: `${tempPrefix}.example.com`,
			});
			expect([200, 201]).toContain(createResponse.status);
			tempCookieId = Number(createResponse.data.id ?? createResponse.data.cookie_id);
			expect(tempCookieId).toBeTruthy();

			const deleteResponse = await fazApiDelete(page, cookiesNonce, `cookies/${tempCookieId}`);
			expect([200, 204]).toContain(deleteResponse.status);

			const cookiesAfterDelete = await listCookies(page, cookiesNonce);
			expect(
				cookiesAfterDelete.some((entry: any) => Number(entry.id ?? entry.cookie_id) === tempCookieId),
			).toBe(false);
		} finally {
			const restoreBannerNonce = await openBannerPage(page, loginAsAdmin);
			await updateBanner(page, restoreBannerNonce, originalBanner.id, {
				name: originalBanner.name,
				status: originalBanner.status,
				default: originalBanner.default,
				properties: originalBanner.properties,
				contents: originalBanner.contents,
			});
			if (tempCookieId && cookiesNonce) {
				await fazApiDelete(page, cookiesNonce, `cookies/${tempCookieId}`).catch(() => ({ status: 0 }));
			}
		}
	});

	test('omnibus: whitelist and provider-matrix resets preserve intended exemptions while data-src stays unblockable', async ({
		page,
		browser,
		loginAsAdmin,
		wpBaseURL,
	}) => {
		resetProviderMatrixState({ clearFixtureCustomRules: true });
		const nonce = await openSettingsPage(page, loginAsAdmin);
		const originalSettings = await getSettings(page, nonce);
		const originalScriptBlocking = originalSettings.script_blocking ?? {};

		try {
			await updateSettings(page, nonce, {
				script_blocking: {
					...originalScriptBlocking,
					custom_rules: mergeFixtureCustomRules(originalScriptBlocking.custom_rules),
					whitelist_patterns: ['connect.facebook.net'],
				},
			});

			resetProviderMatrixState();

			const afterDefaultReset = await getSettings(page, nonce);
			const patternsAfterDefaultReset = (afterDefaultReset.script_blocking?.custom_rules ?? []).map((rule: any) => rule?.pattern);
			expect(patternsAfterDefaultReset).toEqual(expect.arrayContaining(FIXTURE_CUSTOM_RULES.map((rule) => rule.pattern)));

			const visitor = await browser.newContext({ baseURL: wpBaseURL });
			try {
				const visitorPage = await visitor.newPage();
				await visitorPage.goto(providerMatrixUrl, { waitUntil: 'domcontentloaded' });
				await visitorPage.locator('body').waitFor({ state: 'visible' });
				await waitForCookie(visitorPage, '_fbp');
				const hits = readProviderMatrixHits();
				expect(hits['facebook-pixel'] ?? 0).toBeGreaterThanOrEqual(1);

				const dynamicScriptType = await visitorPage.evaluate(() => {
					(window as any)._fazConfig._userWhitelist = ['cdn.example.com/safe-library.js'];
					const script = document.createElement('script');
					script.id = 'faz-omni-data-src-probe';
					script.setAttribute('data-src', 'https://analytics.example.com/track.js');
					script.setAttribute('data-fazcookie', 'fazcookie-analytics');
					script.src = 'https://cdn.example.com/safe-library.js';
					document.head.appendChild(script);
					return document.getElementById('faz-omni-data-src-probe')?.getAttribute('type') ?? null;
				});

				expect(dynamicScriptType).not.toBe('text/plain');
				expect(dynamicScriptType).not.toBe('javascript/blocked');
			} finally {
				await visitor.close();
			}

			resetProviderMatrixState({ clearFixtureCustomRules: true });
			const afterExplicitClear = await getSettings(page, nonce);
			const patternsAfterExplicitClear = (afterExplicitClear.script_blocking?.custom_rules ?? []).map((rule: any) => rule?.pattern);
			for (const fixtureRule of FIXTURE_CUSTOM_RULES) {
				expect(patternsAfterExplicitClear).not.toContain(fixtureRule.pattern);
			}
		} finally {
			await updateSettings(page, nonce, {
				script_blocking: originalScriptBlocking,
			});
			resetProviderMatrixState({ clearFixtureCustomRules: true });
		}
	});

	test('omnibus: scanner discover exposes priority URLs and the UI scan flow survives either REST URL shape', async ({
		page,
		loginAsAdmin,
	}) => {
		test.setTimeout(240_000);
		const nonce = await openCookiesPage(page, loginAsAdmin);
		const token = makeToken('omni-scan');
		setLabToken(token);
		enableWooLabScenario();
		touchPosts('page', ['faz-lab-js-basic']);

		try {
			const discoverResponse = await fazApiPost<DiscoverResponse>(page, nonce, 'scans/discover', {
				max_pages: 100,
				fingerprint: '',
			});
			expect(discoverResponse.status).toBe(200);
			expect(discoverResponse.data.total).toBeGreaterThan(0);

			const combinedUrls = [...discoverResponse.data.urls, ...discoverResponse.data.priority_urls];
			const wooUrls = readWooUrls();
			for (const expectedUrl of [wooUrls.cart, wooUrls.checkout, wooUrls.myaccount, wooUrls.product, wooUrls.shop]) {
				expect(combinedUrls).toContain(expectedUrl);
			}

			await deleteCookiesByPrefix(page, nonce, '_faz_lab_');

			await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
			await page.evaluate(() => {
				try {
					localStorage.removeItem('faz_scan_fingerprint');
				} catch {
					// Ignore localStorage restrictions in hardened browsers.
				}
			});

			const uiDiscoverPromise = page.waitForResponse((response) => {
				if (response.status() !== 200) {
					return false;
				}
				const decoded = decodeUrl(response.url());
				return decoded.includes('rest_route=/faz/v1/scans/discover')
					|| decoded.includes('/wp-json/faz/v1/scans/discover')
					|| response.url().includes('scans%2Fdiscover');
			});

			await page.locator('#faz-scan-btn').click();
			await page.locator('#faz-scan-dropdown .faz-dropdown-item[data-depth="100"]').click();

			const uiDiscoverResponse = await uiDiscoverPromise;
			const uiDiscoverData = (await uiDiscoverResponse.json()) as DiscoverResponse;
			expect(uiDiscoverData.total).toBeGreaterThan(0);

			await page.waitForFunction(() => !document.querySelector('.faz-scan-progress-wrap'), undefined, {
				timeout: 180_000,
			});
			await expect(page.locator('.faz-toast').last()).toContainText('Scan complete', { timeout: 20_000 });

			const cookies = await listCookies(page, nonce);
			expect(cookies.some((entry: any) => String(entry.name ?? '') === `_faz_lab_js_basic_${token}`)).toBe(true);
		} finally {
			disableLabFlags();
			await deleteCookiesByPrefix(page, nonce, '_faz_lab_').catch(() => {});
		}
	});

	test('omnibus: consent logging keeps the original consentid when the browser cookie is percent-encoded', async ({
		page,
		browser,
		loginAsAdmin,
		wpBaseURL,
	}) => {
		const nonce = await openSettingsPage(page, loginAsAdmin);
		const originalSettings = await getSettings(page, nonce);
		const originalConsentLogs = originalSettings.consent_logs ?? { status: false };

		clearConsentLogState();

		const visitor = await browser.newContext({ baseURL: wpBaseURL });
		try {
			await updateSettings(page, nonce, {
				consent_logs: {
					...originalConsentLogs,
					status: true,
				},
			});

			const visitorPage = await visitor.newPage();
			await visitorPage.goto('/', { waitUntil: 'domcontentloaded' });
			await driveConsent(visitorPage, 'all', 'yes');

			const consentCookie = (await visitor.cookies()).find((cookie) => cookie.name === 'fazcookie-consent');
			expect(consentCookie).toBeTruthy();

			const parsedCookie = parseConsentCookieValue(decodeURIComponent(consentCookie!.value));
			expect(parsedCookie.consentid).toBeTruthy();

			await expect
				.poll(() => {
					const raw = wpEval(
						'global $wpdb; ' +
						'$table = $wpdb->prefix . "faz_consent_logs"; ' +
						'$row = $wpdb->get_row( "SELECT consent_id FROM {$table} ORDER BY log_id DESC LIMIT 1", ARRAY_A ); ' +
						'echo wp_json_encode( $row ? $row : array() );',
					);
					const row = raw ? (JSON.parse(raw) as { consent_id?: string }) : {};
					return row.consent_id ?? '';
				}, {
					timeout: 10_000,
					message: 'Consent logger should persist the consentid from the browser cookie',
				})
				.toBe(parsedCookie.consentid);
		} finally {
			await updateSettings(page, nonce, {
				consent_logs: originalConsentLogs,
			});
			clearConsentLogState();
			await visitor.close();
		}
	});
});
