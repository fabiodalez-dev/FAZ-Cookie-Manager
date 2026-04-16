/**
 * Regression tests for the four user reports from nkoffiziell (German AdSense
 * publisher) that drove v1.11.0. One test per reported problem so a
 * regression on any of them surfaces as a focused, named failure instead of
 * a generic suite break.
 *
 * Reported issues (verbatim summary):
 *   1. "There should maybe be an option to force new consent."
 *      → Admin changes AdSense settings; existing visitors keep their old
 *        consent cookie and ads behave inconsistently until they manually
 *        re-consent. We need a way to invalidate *all* stored consents.
 *   2. "If users click 'Alle ablehnen' I don't see unpersonalized ads
 *        loading. Shouldn't it fallback to unpersonalized ads?"
 *      → When marketing is denied, AdSense should still be able to serve
 *        non-personalized ads (ad_storage=granted, ad_user_data=denied,
 *        ad_personalization=denied).
 *   3. "Upon revisiting the Website, ads don't load. It only happens either
 *        after a couple refreshes; or If you reaccept the Cookies."
 *      → Race condition on revisit: GCM emits `default denied` first, then
 *        `update granted`, but AdSense can fire before the update arrives.
 *        The fix is to emit `default` already-granted for returning visitors.
 *   4. "Paid Memberships Pro integration (Pay-or-Accept / PUR model)."
 *      → Members on selected PMP levels must bypass the banner and be
 *        auto-granted consent across all categories.
 */

import { expect, test, type Page } from '../fixtures/wp-fixture';
import {
	ensureFixturePlugin,
	setOption,
	deleteOption,
	wp,
	wpEval,
} from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';

async function getAdminNonce(page: Page): Promise<string> {
	return page.evaluate(() => (window as unknown as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '');
}

async function getSettings(page: Page, nonce: string) {
	const r = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/settings`, {
		headers: { 'X-WP-Nonce': nonce },
	});
	expect(r.status()).toBe(200);
	return r.json();
}

async function getGcmSettings(page: Page, nonce: string) {
	const r = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/gcm`, {
		headers: { 'X-WP-Nonce': nonce },
	});
	expect(r.status()).toBe(200);
	return r.json();
}

async function updateGcmSettings(page: Page, nonce: string, data: Record<string, unknown>) {
	const r = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/gcm`, {
		headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
		data,
	});
	expect(r.status(), `GCM update failed: ${r.status()}`).toBe(200);
	return r.json();
}

async function updateSettings(page: Page, nonce: string, data: Record<string, unknown>) {
	const r = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/settings`, {
		headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
		data,
	});
	expect(r.status(), `Settings update failed: ${r.status()}`).toBe(200);
	return r.json();
}

/**
 * Drive the banner to an accept-all / reject-all final state without
 * relying on a real click through the banner button.
 *
 * Rationale: the banner renders its accept/reject buttons in the initial
 * HTML but attaches the consent click handlers only after a full
 * template-mount cycle (_fazRegisterListeners, invoked AFTER the
 * document fragment with the re-built banner is inserted). In practice,
 * under the PHP built-in dev server + Playwright, this handler-attach
 * step is racy: the button is visible (visibility is CSS-only) before
 * the listener lands, so button.click() silently no-ops. The consent
 * cookie stays in its init-placeholder shape (`action:""`,
 * `marketing:"no"`) and every subsequent assertion keeps failing.
 *
 * We sidestep the race by calling `window._fazAcceptCookies('all' |
 * 'reject')` directly — that's the exact function the button's click
 * handler would eventually invoke, and it exercises the same consent
 * write path (cookie + dispatchEvent('fazcookie_consent_update') + GCM
 * update). This keeps the test honest about what it's asserting — the
 * *consent pipeline*, not the DOM listener — while decoupling it from a
 * banner-rendering timing bug that belongs to its own spec.
 */
async function driveConsent(page: Page, choice: 'all' | 'reject', expectedMarketing: 'yes' | 'no'): Promise<void> {
	// Wait for the consent pipeline to be available on window.
	await page.waitForFunction(() => typeof (window as unknown as { _fazAcceptCookies?: unknown })._fazAcceptCookies === 'function', undefined, { timeout: 10_000 });
	await page.evaluate((c) => {
		(window as unknown as { _fazAcceptCookies: (c: string) => unknown })._fazAcceptCookies(c);
	}, choice);
	await page.waitForFunction((expected) => {
		const raw = document.cookie.split(';').find((c) => c.trim().startsWith('fazcookie-consent='));
		if (!raw) return false;
		const encodedValue = raw.split('=').slice(1).join('=');
		let value = encodedValue;
		try {
			value = decodeURIComponent(encodedValue);
		} catch {
			value = encodedValue;
		}
		return (
			/(?:^|,)action:yes(?:,|$)/.test(value) &&
			new RegExp(`(?:^|,)marketing:${expected}(?:,|$)`).test(value)
		);
	}, expectedMarketing, { timeout: 5_000 });
}

async function acceptAllOnFrontend(page: Page): Promise<void> {
	await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });
	await driveConsent(page, 'all', 'yes');
}

async function rejectAllOnFrontend(page: Page): Promise<void> {
	await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });
	await driveConsent(page, 'reject', 'no');
}

function parseConsentCookieValue(raw: string): Record<string, string> {
	return raw.split(',').reduce<Record<string, string>>((acc, pair) => {
		const trimmed = pair.trim();
		const idx = trimmed.indexOf(':');
		if (idx === -1) return acc;
		const k = trimmed.substring(0, idx).trim();
		if (!k) return acc;
		acc[k] = trimmed.substring(idx + 1).trim();
		return acc;
	}, {});
}

test.describe('User-reported regressions (v1.11.0 publisher report)', () => {

	/* ─────────────────────────────────────────────────────────────────
	 * Report 1 — "There should maybe be an option to force new consent"
	 * ───────────────────────────────────────────────────────────────── */
	test('R1: admin bumping consent_revision re-shows the banner to visitors who already accepted', async ({ page, context, loginAsAdmin }) => {
		// Arrange — admin captures the current revision.
		await loginAsAdmin(page);
		await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
		const nonce = await getAdminNonce(page);
		const before = await getSettings(page, nonce);
		const originalRevision = Number(before.general?.consent_revision ?? 1);

		// Visitor accepts consent as a fresh user. Cookie must carry rev:<originalRevision>.
		const visitor = await context.browser()?.newContext({ baseURL: WP_BASE });
		if (!visitor) throw new Error('Could not create visitor context');
		try {
			const visitorPage = await visitor.newPage();
			await acceptAllOnFrontend(visitorPage);
			const firstVisitCookie = (await visitor.cookies()).find((c) => c.name === 'fazcookie-consent');
			expect(firstVisitCookie, 'Visitor should have a consent cookie after accepting').toBeTruthy();
			const parsed = parseConsentCookieValue(decodeURIComponent(firstVisitCookie!.value));
			expect(parsed.rev, 'Cookie must carry a revision token').toBeDefined();
			expect(Number(parsed.rev)).toBe(originalRevision);

			// Act — admin clicks "Invalidate all consents" (REST equivalent).
			const invalidateResp = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/settings/invalidate-consents`, {
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				data: {},
			});
			expect(invalidateResp.status()).toBe(200);
			const invalidateBody = await invalidateResp.json();
			expect(invalidateBody.consent_revision).toBeGreaterThan(originalRevision);

			// Visitor revisits the site. Their old cookie rev < new server rev,
			// so the plugin must show the banner again.
			await visitorPage.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });
			const bannerVisible = await visitorPage.locator('#faz-consent, [data-faz-tag="notice"]').first().isVisible({ timeout: 5_000 }).catch(() => false);
			expect(bannerVisible, 'Banner must reappear after consent_revision bump').toBe(true);
		} finally {
			// Teardown — restore original revision so other tests aren't affected.
			await updateSettings(page, nonce, { general: { consent_revision: originalRevision } });
			await visitor.close();
		}
	});

	test('R1b: visitor who re-consents after invalidation keeps consent on the next reload when IAB/TCF is enabled', async ({ page, context, loginAsAdmin }) => {
		await loginAsAdmin(page);
		await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
		const nonce = await getAdminNonce(page);
		const before = await getSettings(page, nonce);
		const originalRevision = Number(before.general?.consent_revision ?? 1);
		const originalIab = before.iab ?? { enabled: false };

		const visitor = await context.browser()?.newContext({ baseURL: WP_BASE });
		if (!visitor) throw new Error('Could not create visitor context');
		try {
			await updateSettings(page, nonce, {
				iab: {
					...originalIab,
					enabled: true,
				},
			});

			const visitorPage = await visitor.newPage();
			await acceptAllOnFrontend(visitorPage);
			await visitorPage.waitForFunction(() => document.cookie.includes('euconsent-v2='), undefined, { timeout: 5_000 });

			const invalidateResp = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/settings/invalidate-consents`, {
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				data: {},
			});
			expect(invalidateResp.status()).toBe(200);
			const invalidateBody = await invalidateResp.json();
			expect(invalidateBody.consent_revision).toBeGreaterThan(originalRevision);

			await visitorPage.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });
			const bannerVisibleAfterInvalidate = await visitorPage.locator('#faz-consent, [data-faz-tag="notice"]').first().isVisible({ timeout: 5_000 }).catch(() => false);
			expect(bannerVisibleAfterInvalidate, 'Banner must reappear once after consent invalidation').toBe(true);

			await driveConsent(visitorPage, 'all', 'yes');
			await visitorPage.waitForFunction(() => document.cookie.includes('euconsent-v2='), undefined, { timeout: 5_000 });

			await visitorPage.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });
			const bannerVisibleAfterReaccept = await visitorPage.locator('#faz-consent, [data-faz-tag="notice"]').first().isVisible({ timeout: 5_000 }).catch(() => false);
			expect(
				bannerVisibleAfterReaccept,
				'After re-consenting at the new revision, the banner must stay hidden on the next reload',
			).toBe(false);

			const consentCookie = (await visitor.cookies()).find((c) => c.name === 'fazcookie-consent');
			expect(consentCookie, 'Visitor should still have a consent cookie after re-accepting').toBeTruthy();
			const parsed = parseConsentCookieValue(decodeURIComponent(consentCookie!.value));
			expect(parsed.action, 'Saved consent must remain marked as an explicit user action').toBe('yes');
			expect(Number(parsed.rev), 'Saved consent must keep the bumped revision').toBe(Number(invalidateBody.consent_revision));
		} finally {
			await updateSettings(page, nonce, {
				general: { consent_revision: originalRevision },
				iab: originalIab,
			});
			await visitor.close();
		}
	});

	test('Audit: consent logging keeps the original consentid when the consent cookie is percent-encoded', async ({ page, context, loginAsAdmin }) => {
		await loginAsAdmin(page);
		await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
		const nonce = await getAdminNonce(page);
		const before = await getSettings(page, nonce);
		const originalConsentLogs = before.consent_logs ?? { status: false };

		wpEval('global $wpdb; $table = $wpdb->prefix . "faz_consent_logs"; $wpdb->query( "DELETE FROM {$table}" ); echo "ok";');

		const visitor = await context.browser()?.newContext({ baseURL: WP_BASE });
		if (!visitor) throw new Error('Could not create visitor context');
		try {
			await updateSettings(page, nonce, {
				consent_logs: {
					...originalConsentLogs,
					status: true,
				},
			});

			const visitorPage = await visitor.newPage();
			await acceptAllOnFrontend(visitorPage);

			const consentCookie = (await visitor.cookies()).find((c) => c.name === 'fazcookie-consent');
			expect(consentCookie, 'Visitor should have a consent cookie after accepting').toBeTruthy();
			const parsedCookie = parseConsentCookieValue(decodeURIComponent(consentCookie!.value));
			expect(parsedCookie.consentid, 'Consent cookie should expose consentid').toBeTruthy();

			await expect.poll(() => {
				const raw = wpEval(
					'global $wpdb; ' +
					'$table = $wpdb->prefix . "faz_consent_logs"; ' +
					'$row = $wpdb->get_row( "SELECT consent_id FROM {$table} ORDER BY log_id DESC LIMIT 1", ARRAY_A ); ' +
					'echo wp_json_encode( $row ? $row : array() );'
				);
				const row = raw ? JSON.parse(raw) as { consent_id?: string } : {};
				return row.consent_id ?? '';
			}, {
				timeout: 10_000,
				message: 'Consent logger should persist the consentid from the browser cookie',
			}).toBe(parsedCookie.consentid);
		} finally {
			await updateSettings(page, nonce, {
				consent_logs: originalConsentLogs,
			});
			wpEval('global $wpdb; $table = $wpdb->prefix . "faz_consent_logs"; $wpdb->query( "DELETE FROM {$table}" ); echo "ok";');
			await visitor.close();
		}
	});

	/* ─────────────────────────────────────────────────────────────────
	 * Report 2 — "Shouldn't it fallback to unpersonalized ads?"
	 * ───────────────────────────────────────────────────────────────── */
	test('R2: non_personalized_ads_fallback keeps ad_storage granted while ad_user_data/ad_personalization stay denied', async ({ page, context, loginAsAdmin }) => {
		// Arrange — admin enables GCM + fallback.
		await loginAsAdmin(page);
		await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-gcm`, { waitUntil: 'domcontentloaded' });
		const nonce = await getAdminNonce(page);
		const before = await getGcmSettings(page, nonce);
		await updateGcmSettings(page, nonce, {
			status: true,
			non_personalized_ads_fallback: true,
		});

		const visitor = await context.browser()?.newContext({ baseURL: WP_BASE });
		if (!visitor) throw new Error('Could not create visitor context');
		try {
			const visitorPage = await visitor.newPage();
			// Act — visitor rejects all marketing (click "Reject All").
			await rejectAllOnFrontend(visitorPage);
			// Wait for GCM to settle (either the fallback-friendly default emitted
			// at page load, or a subsequent consent update from the banner click).
			// Both cases produce an `ad_storage: "granted"` entry when the
			// non-personalized ads fallback is enabled and marketing is denied.
			await visitorPage.waitForFunction(() => {
				const dlName = (window as unknown as { fazSettings?: { dataLayerName?: string } }).fazSettings?.dataLayerName || 'dataLayer';
				const dl = (window as unknown as Record<string, unknown[]>)[dlName] ?? [];
				return (dl as Array<Record<number, unknown>>).some((entry) => {
					if (!entry || typeof entry !== 'object') return false;
					if (entry[0] !== 'consent') return false;
					const payload = entry[2] as Record<string, string> | undefined;
					return !!payload && payload.ad_storage === 'granted';
				});
			}, undefined, { timeout: 5_000 });

			// After a reject, GCM must emit an `update` call with the
			// non-personalized combination. Walk dataLayer and merge to find
			// the final consent state.
			const consentState = await visitorPage.evaluate(() => {
				const dlName = (window as unknown as { fazSettings?: { dataLayerName?: string } }).fazSettings?.dataLayerName || 'dataLayer';
				const dl = (window as unknown as Record<string, unknown[]>)[dlName] ?? [];
				const merged: Record<string, string> = {};
				for (const entry of dl as Array<Record<number, unknown>>) {
					if (!entry || typeof entry !== 'object') continue;
					if (entry[0] !== 'consent') continue;
					const payload = entry[2] as Record<string, string> | undefined;
					if (!payload) continue;
					for (const key of Object.keys(payload)) {
						merged[key] = payload[key];
					}
				}
				return merged;
			});

			expect(consentState.ad_storage, 'ad_storage must stay granted to serve non-personalized ads').toBe('granted');
			expect(consentState.ad_user_data, 'ad_user_data must be denied when marketing consent is rejected').toBe('denied');
			expect(consentState.ad_personalization, 'ad_personalization must be denied when marketing consent is rejected').toBe('denied');
		} finally {
			await updateGcmSettings(page, nonce, {
				status: before.status ?? false,
				non_personalized_ads_fallback: before.non_personalized_ads_fallback ?? false,
			});
			await visitor.close();
		}
	});

	/* ─────────────────────────────────────────────────────────────────
	 * Report 3 — "Upon revisiting, ads don't load unless you reaccept"
	 * ───────────────────────────────────────────────────────────────── */
	test('R3: returning visitor with saved consent sees GCM default granted (no denied→granted race)', async ({ page, context, loginAsAdmin }) => {
		// Arrange — enable GCM.
		await loginAsAdmin(page);
		await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-gcm`, { waitUntil: 'domcontentloaded' });
		const nonce = await getAdminNonce(page);
		const before = await getGcmSettings(page, nonce);
		await updateGcmSettings(page, nonce, {
			status: true,
			non_personalized_ads_fallback: false,
		});

		const visitor = await context.browser()?.newContext({ baseURL: WP_BASE });
		if (!visitor) throw new Error('Could not create visitor context');
		try {
			const settings = await getSettings(page, nonce);
			const currentRevisionRaw = Number(settings.general?.consent_revision ?? 1);
			const currentRevision = Number.isFinite(currentRevisionRaw) && currentRevisionRaw > 0 ? currentRevisionRaw : 1;
			const visitorBaseURL = new URL(WP_BASE);

			// Pre-seed the consent cookie directly on the visitor context.
			// Rationale: the scenario under test is specifically "a visitor
			// who already has a saved consent on revisit" — we don't need
			// to re-exercise the accept-all flow here (that's R2's job).
			// Injecting the cookie server-side avoids a banner-mount race
			// condition (script.js rewrites the cookie during init before
			// handlers are attached on a fresh pageload under the built-in
			// dev server), keeping this test focused on the *GCM default
			// emission* bug.
			const preBaked = [
				'consentid:e2etestconsentid0000000000000000',
				'consent:yes',
				'action:yes',
				'necessary:yes',
				'functional:yes',
				'analytics:yes',
				'performance:yes',
				'uncategorized:yes',
				'marketing:yes',
				`rev:${currentRevision}`,
			].join(',');
			await visitor.addCookies([
				{
					name: 'fazcookie-consent',
					value: preBaked,
					domain: visitorBaseURL.hostname,
					path: '/',
					expires: Math.floor(Date.now() / 1000) + 60 * 60 * 24 * 180, // 180 days
					httpOnly: false,
					secure: visitorBaseURL.protocol === 'https:',
					sameSite: 'Lax',
				},
			]);

			const visitorPage = await visitor.newPage();
			// Act — a single goto with the cookie already in place IS the
			// "revisit" scenario. The bug report said ads wouldn't load
			// until after a few refreshes or a manual re-accept; the fix is
			// that GCM's *first* `consent default` call must already carry
			// granted states for returning visitors.
			await visitorPage.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });
			// Give gcm.js a tick to run its init.
			await visitorPage.waitForFunction(() => {
				const dl = (window as unknown as { dataLayer?: unknown[] }).dataLayer ?? [];
				return (dl as Array<Record<number, unknown>>).some((e) => e && typeof e === 'object' && e[0] === 'consent');
			}, undefined, { timeout: 5_000 });

			const firstConsentCall = await visitorPage.evaluate(() => {
				const dlName = (window as unknown as { fazSettings?: { dataLayerName?: string } }).fazSettings?.dataLayerName || 'dataLayer';
				const dl = (window as unknown as Record<string, unknown[]>)[dlName] ?? [];
				// Find the FIRST consent call (not the last one) — that's what
				// AdSense reads when it fires its first ad request.
				for (const entry of dl as Array<Record<number, unknown>>) {
					if (!entry || typeof entry !== 'object') continue;
					if (entry[0] !== 'consent') continue;
					return {
						mode: entry[1] as string,
						payload: entry[2] as Record<string, string>,
					};
				}
				return null;
			});

			expect(firstConsentCall, 'At least one gtag consent call must fire').toBeTruthy();
			expect(firstConsentCall!.mode, 'First consent call must be the default (not update) for returning visitors').toBe('default');
			expect(
				firstConsentCall!.payload?.ad_storage,
				'First consent default MUST be granted for returning visitors (otherwise AdSense races to denied)',
			).toBe('granted');
			expect(firstConsentCall!.payload?.analytics_storage).toBe('granted');
		} finally {
			await updateGcmSettings(page, nonce, {
				status: before.status ?? false,
				non_personalized_ads_fallback: before.non_personalized_ads_fallback ?? false,
			});
			await visitor.close();
		}
	});

	/* ─────────────────────────────────────────────────────────────────
	 * Report 4 — "Paid Memberships Pro integration (PUR model)"
	 * ───────────────────────────────────────────────────────────────── */
	test('R4: PMP-exempt member bypasses banner and is auto-granted consent', async ({ page, loginAsAdmin }) => {
		// Arrange — install the PMP mock fixture plugin and configure the
		// integration to exempt level 2.
		ensureFixturePlugin('faz-e2e-pmp-mock');
		setOption('faz_e2e_pmp_mock_levels', '2'); // current admin user "owns" level 2

		await loginAsAdmin(page);
		await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
		const nonce = await getAdminNonce(page);
		const before = await getSettings(page, nonce);

		await updateSettings(page, nonce, {
			integrations: {
				paid_memberships_pro: {
					enabled: true,
					exempt_levels: [2],
				},
			},
		});

		try {
			// Act — visit the frontend as the logged-in admin (who, per our
			// mock, has level 2 membership).
			await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });

			// Assert — banner must NOT be visible.
			const bannerHidden = await page.locator('#faz-consent, [data-faz-tag="notice"]').first().isHidden({ timeout: 3_000 }).catch(() => true);
			expect(bannerHidden, 'PMP-exempt member must not see the banner').toBe(true);

			// Assert — consent cookie must be auto-granted with source:pmp.
			const consentCookie = (await page.context().cookies()).find((c) => c.name === 'fazcookie-consent');
			expect(consentCookie, 'Exempt member must receive an auto-granted cookie server-side').toBeTruthy();
			const parsed = parseConsentCookieValue(decodeURIComponent(consentCookie!.value));
			expect(parsed.action, 'Cookie must record an implicit user action').toBe('yes');
			// The consent token MUST be "yes" (not "accepted" or any other
			// human-readable label): script.js `_fazUnblock()` and the CCPA
			// opt-out checkbox both gate on `consent === "yes"`, so a PMP
			// auto-grant that used a different string would be server-side
			// accepted but client-side script-blocked — the exact silent
			// failure mode this assertion exists to prevent.
			expect(parsed.consent, 'consent must be "yes" to match the token script.js expects').toBe('yes');
			expect(parsed.source, 'Cookie must be tagged as sourced from PMP').toBe('pmp');

			// Downgrade: clear the mock level, reload, verify the cookie
			// is revoked so former members don't keep a stale auto-grant.
			setOption('faz_e2e_pmp_mock_levels', '');
			await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });
			const consentAfter = (await page.context().cookies()).find((c) => c.name === 'fazcookie-consent');
			// The auto-granted cookie must be gone (or at least not marked PMP).
			if (consentAfter) {
				const parsedAfter = parseConsentCookieValue(decodeURIComponent(consentAfter.value));
				expect(parsedAfter.source, 'After losing the exempt level, source:pmp cookie must be revoked').not.toBe('pmp');
			}
		} finally {
			await updateSettings(page, nonce, { integrations: before.integrations ?? { paid_memberships_pro: { enabled: false, exempt_levels: [] } } });
			deleteOption('faz_e2e_pmp_mock_levels');
			try {
				wp(['plugin', 'deactivate', 'faz-e2e-pmp-mock']);
			} catch {
				// ignore: already deactivated.
			}
		}
	});
});
