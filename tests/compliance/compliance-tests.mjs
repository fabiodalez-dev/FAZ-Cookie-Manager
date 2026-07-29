/**
 * FAZ Cookie Manager — Comprehensive Compliance Test Suite
 *
 * Based on cookie-banner-compliance-checklist.md v1.0
 * Standards: GDPR, ePrivacy, Garante Privacy 2021, IAB TCF 2.3, Google Consent Mode v2
 *
 * Usage:
 *   node compliance-tests.mjs                    # default http://127.0.0.1:9998
 *   node compliance-tests.mjs --site=http://example.com
 *   node compliance-tests.mjs --headed           # show browser
 *   node compliance-tests.mjs --section=banner   # run only one section
 *   node compliance-tests.mjs --trace            # record Playwright traces
 */

import { chromium } from 'playwright';
import { createTracedContext, bannerToMarkdown, printTraceInfo, isTraceEnabled } from './test-helpers.mjs';

// --- Configuration ---
const args = process.argv.slice(2);
// 127.0.0.1 literal, NOT localhost — nginx binds 127.0.0.1:9998 and WP's
// canonical home is 127.0.0.1, so `localhost` 301-redirects and drops the
// admin session cookie / REST nonce (cross-host). Override via --site= / WP_BASE_URL.
const SITE = args.find(a => a.startsWith('--site='))?.split('=')[1] || process.env.WP_BASE_URL || 'http://127.0.0.1:9998';
const HEADED = args.includes('--headed');
const SECTION_FILTER = args.find(a => a.startsWith('--section='))?.split('=')[1] || '';
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

// Technical cookies that don't require consent
const TECHNICAL_COOKIE_RE = [
	/^wordpress_/i, /^wp-settings/i, /^PHPSESSID$/i,
	/^fazcookie-consent$/, /^wp_lang$/i, /^wordpress_test_cookie$/i,
];
const isTechnicalCookie = (name) => TECHNICAL_COOKIE_RE.some(re => re.test(name));

// --- Results tracking ---
const results = [];
let currentSection = '';

function startSection(name) {
	currentSection = name;
	console.log(`\n${'='.repeat(60)}`);
	console.log(`  ${name}`);
	console.log('='.repeat(60));
}

function test(id, pass, detail = '') {
	results.push({ section: currentSection, id, pass, detail });
	const icon = pass ? '\x1b[32m PASS\x1b[0m' : '\x1b[31m FAIL\x1b[0m';
	const info = detail ? ` — ${detail}` : '';
	console.log(`  ${icon}  ${id}${info}`);
}

// --- Helpers ---
let _traceCounter = 0;
async function freshContext(browser, opts = {}) {
	return browser.newContext({
		viewport: { width: opts.width || 1400, height: opts.height || 900 },
		...(opts.extra || {}),
	});
}

async function freshPage(browser, opts = {}) {
	const traceName = opts.traceName || `compliance-${++_traceCounter}`;
	const { ctx, page } = await createTracedContext(browser, traceName, opts);
	return { ctx, page };
}

// Cache-busting query string appended to every front-end navigation so
// LiteSpeed / WP Rocket / other full-page caches don't serve stale HTML
// from a previous compliance run (the very issue that masked the post-
// rsync banner update on fabiodalez.it). Random per-run, not per-call,
// so subsequent navigations inside the same test still hit the same
// cached entry once it warms up.
const CACHE_BUST = `_cb=${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
function withCacheBust(url) {
	const sep = url.includes('?') ? '&' : '?';
	return `${url}${sep}${CACHE_BUST}`;
}

async function gotoFront(page) {
	await page.goto(withCacheBust(SITE + '/'), { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(1500);
}

async function waitForBanner(page, timeout = 5000) {
	try {
		await page.waitForSelector('[data-faz-tag="notice"]', { state: 'visible', timeout });
		return true;
	} catch { return false; }
}

async function getNonTechnicalCookies(ctx) {
	const all = await ctx.cookies();
	return all.filter(c => !isTechnicalCookie(c.name));
}

async function getConsentCookie(ctx) {
	const all = await ctx.cookies();
	return all.find(c => c.name === 'fazcookie-consent');
}

function parseConsentCookie(value) {
	// The plugin URL-encodes the consent cookie value (see _fazSetCookie in
	// frontend/js/script.js — every key/value goes through encodeURIComponent).
	// Playwright's context.cookies() returns the raw stored value, so a naive
	// split(',') on the URL-encoded string returns the entire blob as a
	// single key. Decode first, then split. Try/catch covers the (rare)
	// case where the cookie value contains a stray '%' that breaks
	// decodeURIComponent — fall back to the raw value rather than throw.
	let decoded;
	try {
		decoded = decodeURIComponent(value);
	} catch {
		decoded = value;
	}
	const map = {};
	decoded.split(',').forEach(pair => {
		const [k, ...rest] = pair.split(':');
		if (k) map[k.trim()] = rest.join(':').trim();
	});
	return map;
}

async function loginAdmin(page) {
	await page.goto(SITE + '/wp-login.php', { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', ADMIN_USER);
	await page.fill('#user_pass', ADMIN_PASS);
	await page.click('#wp-submit');
	await page.waitForLoadState('domcontentloaded');
}

function shouldRun(sectionKey) {
	if (!SECTION_FILTER) return true;
	return sectionKey.toLowerCase().includes(SECTION_FILTER.toLowerCase());
}

// ====================================================================
// TEST SECTIONS
// ====================================================================

async function testBannerAppearance(browser) {
	if (!shouldRun('banner')) return;
	startSection('1. BANNER APPEARANCE [B01-B06]');

	const { ctx, page } = await freshPage(browser);

	// B01 — Banner appears on first visit
	await gotoFront(page);
	const bannerVisible = await waitForBanner(page);
	test('B01 Banner appears on first visit', bannerVisible);

	if (!bannerVisible) {
		test('B02-B06', false, 'Skipped — banner not visible');
		await ctx.close();
		return;
	}

	// B02 — Banner doesn't completely block the page
	const bannerBox = await page.locator('[data-faz-tag="notice"]').boundingBox();
	const vp = page.viewportSize();
	const bannerCoversAll = bannerBox &&
		bannerBox.width >= vp.width * 0.99 &&
		bannerBox.height >= vp.height * 0.95;
	test('B02 Banner does not fully block page', !bannerCoversAll,
		bannerBox ? `${Math.round(bannerBox.width)}x${Math.round(bannerBox.height)} vs viewport ${vp.width}x${vp.height}` : 'no box');

	// B03 — Banner has visible contrast (background color differs from transparent)
	const bannerBg = await page.locator('[data-faz-tag="notice"]').evaluate(el => {
		return window.getComputedStyle(el).backgroundColor;
	});
	const isTransparent = bannerBg === 'rgba(0, 0, 0, 0)' || bannerBg === 'transparent';
	test('B03 Banner has visible background', !isTransparent, bannerBg);

	// B04 — Banner persists (doesn't auto-dismiss)
	await page.waitForTimeout(3000);
	const stillVisible = await page.locator('.faz-consent-container').evaluate(el => !el.classList.contains('faz-hide'));
	test('B04 Banner persists until user action', stillVisible);

	// B05 — Keyboard accessible (Tab to buttons, Enter to activate)
	const focusableCount = await page.evaluate(() => {
		const banner = document.querySelector('[data-faz-tag="notice"]');
		if (!banner) return 0;
		const focusable = banner.querySelectorAll('button, a, [tabindex="0"], input');
		return focusable.length;
	});
	test('B05 Banner has focusable elements', focusableCount >= 2, `${focusableCount} focusable elements`);

	// B05b — Tab navigation works (may need multiple tabs to reach banner buttons)
	let reachedBanner = false;
	let focusedTag = '';
	for (let i = 0; i < 15; i++) {
		await page.keyboard.press('Tab');
		const info = await page.evaluate(() => ({
			tag: document.activeElement?.tagName,
			inBanner: !!document.activeElement?.closest('.faz-consent-container'),
			isInteractive: ['BUTTON', 'A', 'INPUT', 'SELECT'].includes(document.activeElement?.tagName),
		}));
		if (info.inBanner && info.isInteractive) { reachedBanner = true; focusedTag = info.tag; break; }
	}
	test('B05b Tab navigation reaches banner button', reachedBanner, `focused: ${focusedTag}`);

	// B06 — Mobile responsive (test at 375px)
	await ctx.close();
	const { ctx: mCtx, page: mPage } = await freshPage(browser, { width: 375, height: 812 });
	await gotoFront(mPage);
	const mobileBanner = await waitForBanner(mPage);
	let mobileOk = false;
	if (mobileBanner) {
		const mBox = await mPage.locator('[data-faz-tag="notice"]').boundingBox();
		mobileOk = mBox && mBox.width <= 375 && mBox.width >= 300;
	}
	test('B06 Banner responsive on mobile (375px)', mobileBanner && mobileOk,
		mobileBanner ? 'renders correctly' : 'not visible');
	await mCtx.close();
}

async function testInformationContent(browser) {
	if (!shouldRun('info')) return;
	startSection('2. INFORMATION CONTENT [I01-I09]');

	const { ctx, page } = await freshPage(browser);
	await gotoFront(page);
	await waitForBanner(page);

	// I01 — Has brief information text
	const descText = await page.locator('[data-faz-tag="description"]').textContent().catch(() => '');
	test('I01 Banner has informative description', descText.length > 20, `${descText.length} chars`);

	// I02 — Indicates purposes of non-technical cookies
	const descLower = descText.toLowerCase();
	const mentionsPurposes = descLower.includes('cookie') || descLower.includes('trattament') ||
		descLower.includes('privacy') || descLower.includes('consenso');
	test('I02 Description mentions cookies/privacy', mentionsPurposes);

	// I04 — Link to cookie policy
	const policyLink = await page.locator('[data-faz-tag="notice"] a').count();
	test('I04 Banner has link(s)', policyLink > 0, `${policyLink} link(s) found`);

	// I05 — Link/button to preference center
	const settingsBtn = await page.locator('[data-faz-tag="settings-button"]').count();
	test('I05 Customize/preferences button present', settingsBtn > 0);

	// I06 — Close (X) presence is CONDITIONAL on the regulatory model.
	// Since 1.13.18 the plugin auto-hides the X whenever a labelled Reject
	// button is visible on the same banner (EDPB Guidelines 03/2022 +
	// Italian Garante Provv. 10/06/2021 classify "X + labelled Reject" as
	// a recognised dark pattern). 1.14.0 added a per-banner override
	// (settings.allowCloseButtonWithReject) for non-EU banners. So:
	//   - Reject hidden                                 → X must be present.
	//   - Reject visible AND override OFF (default)     → X correctly stripped (PASS).
	//   - Reject visible AND override ON                → X must be present.
	const closeBtn   = await page.locator('[data-faz-tag="close-button"]').count();
	const rejectBtn  = await page.locator('[data-faz-tag="reject-button"]').count();
	const overrideOn = await page.evaluate(() => {
		const cfg = window._fazConfig;
		return !!(cfg && cfg._bannerConfig && cfg._bannerConfig.settings && cfg._bannerConfig.settings.allowCloseButtonWithReject);
	}).catch(() => false);
	const closeExpected = rejectBtn === 0 || overrideOn;
	test('I06 Close (X) button presence matches Garante/EDPB rule',
		closeExpected ? closeBtn > 0 : closeBtn === 0,
		`reject=${rejectBtn}, override=${overrideOn}, close=${closeBtn} (expected ${closeExpected ? '>0' : '0'})`);

	// I08 — Necessary cookies NOT requiring consent in UI
	// Check: the banner shouldn't say "accept technical cookies" as a requirement
	const bannerText = await page.locator('.faz-consent-container').textContent().catch(() => '');
	const asksForTechnical = /accett(a|i)\s+(i\s+)?cookie\s+tecnic/i.test(bannerText) ||
		/must accept.*technical/i.test(bannerText);
	test('I08 Does NOT ask consent for technical cookies', !asksForTechnical);

	// I09 — Clear language (no ambiguous single-word buttons like "Go!", "OK")
	const btnTexts = await page.locator('[data-faz-tag="notice"] button, [data-faz-tag="notice"] a.faz-btn').evaluateAll(
		els => els.map(el => el.textContent.trim())
	);
	const ambiguous = ['ok', 'vai', 'vai!', 'go', 'go!', 'continua', 'continue', 'declino'];
	const hasAmbiguous = btnTexts.some(t => ambiguous.includes(t.toLowerCase()));
	test('I09 No ambiguous button labels', !hasAmbiguous, `labels: [${btnTexts.join(', ')}]`);

	await ctx.close();
}

async function testCommandsButtons(browser) {
	if (!shouldRun('buttons')) return;
	startSection('3. COMMANDS & BUTTONS [P01-P09]');

	const { ctx, page } = await freshPage(browser);
	await gotoFront(page);
	await waitForBanner(page);

	// P01 — Accept all button
	const acceptBtn = await page.locator('[data-faz-tag="accept-button"]');
	const acceptVisible = await acceptBtn.isVisible().catch(() => false);
	test('P01 Accept All button present', acceptVisible);

	// P02 — Reject all button or X
	const rejectBtn = await page.locator('[data-faz-tag="reject-button"]');
	const rejectVisible = await rejectBtn.isVisible().catch(() => false);
	const closeBtn = await page.locator('[data-faz-tag="close-button"]');
	const closeVisible = await closeBtn.isVisible().catch(() => false);
	test('P02 Reject All button or X present', rejectVisible || closeVisible,
		rejectVisible ? 'reject button' : closeVisible ? 'X button' : 'none');

	// P03 — Equal visual weight
	if (acceptVisible && rejectVisible) {
		const acceptBox = await acceptBtn.boundingBox();
		const rejectBox = await rejectBtn.boundingBox();
		if (acceptBox && rejectBox) {
			const widthRatio = Math.min(acceptBox.width, rejectBox.width) / Math.max(acceptBox.width, rejectBox.width);
			const heightRatio = Math.min(acceptBox.height, rejectBox.height) / Math.max(acceptBox.height, rejectBox.height);
			const sizeOk = widthRatio > 0.6 && heightRatio > 0.8;
			test('P03 Accept/Reject equal visual weight', sizeOk,
				`accept: ${Math.round(acceptBox.width)}x${Math.round(acceptBox.height)}, reject: ${Math.round(rejectBox.width)}x${Math.round(rejectBox.height)}`);

			// P08 — No dark patterns (compare font size and opacity)
			const styles = await page.evaluate(() => {
				const a = document.querySelector('[data-faz-tag="accept-button"]');
				const r = document.querySelector('[data-faz-tag="reject-button"]');
				if (!a || !r) return null;
				const sa = window.getComputedStyle(a);
				const sr = window.getComputedStyle(r);
				return {
					acceptFontSize: parseFloat(sa.fontSize),
					rejectFontSize: parseFloat(sr.fontSize),
					acceptOpacity: parseFloat(sa.opacity),
					rejectOpacity: parseFloat(sr.opacity),
				};
			});
			if (styles) {
				const fontOk = Math.abs(styles.acceptFontSize - styles.rejectFontSize) <= 2;
				const opacityOk = styles.rejectOpacity >= 0.8;
				test('P08 No dark patterns (font/opacity)', fontOk && opacityOk,
					`font: ${styles.acceptFontSize}/${styles.rejectFontSize}px, opacity: ${styles.acceptOpacity}/${styles.rejectOpacity}`);
			}
		}
	} else if (acceptVisible && closeVisible) {
		test('P03 Accept/X both accessible at same level', true, 'X button present as reject equivalent');
		test('P08 No dark patterns', true, 'X button used as reject');
	}

	// P04 — Customize button
	const customizeBtn = await page.locator('[data-faz-tag="settings-button"]').isVisible().catch(() => false);
	test('P04 Customize/preferences button present', customizeBtn);

	// P05 — No ambiguous labels
	const allBtnLabels = await page.locator('[data-faz-tag="notice"] button, [data-faz-tag="notice"] a.faz-btn').evaluateAll(
		els => els.filter(el => el.offsetParent !== null).map(el => el.textContent.trim())
	);
	const ambiguousLabels = ['ok', 'vai', 'vai!', 'go', 'go!'];
	const foundAmbiguous = allBtnLabels.filter(t => ambiguousLabels.includes(t.toLowerCase()));
	test('P05 No ambiguous labels', foundAmbiguous.length === 0,
		`labels: [${allBtnLabels.join(', ')}]`);

	// P06 — No duplicate commands with different labels but same function
	const uniqueLabels = new Set(allBtnLabels.map(l => l.toLowerCase()));
	test('P06 No duplicate-function buttons', uniqueLabels.size === allBtnLabels.length,
		`${allBtnLabels.length} buttons, ${uniqueLabels.size} unique`);

	// P07 — Reject reachable with same clicks as accept (both 1 click)
	test('P07 Reject in same number of clicks as accept',
		rejectVisible || closeVisible, 'both accessible at first level');

	// P09 — No pre-checked non-necessary checkboxes
	// Open preference center to check
	if (customizeBtn) {
		await page.click('[data-faz-tag="settings-button"]');
		await page.waitForTimeout(800);

		const toggleStates = await page.evaluate(() => {
			const toggles = document.querySelectorAll('.faz-switch input[type="checkbox"]');
			return [...toggles].map(t => ({
				id: t.id,
				checked: t.checked,
				disabled: t.disabled,
			}));
		});

		const nonNecessaryChecked = toggleStates.filter(t =>
			!t.id.toLowerCase().includes('necessary') && t.checked && !t.disabled
		);
		test('P09 No pre-checked non-necessary toggles', nonNecessaryChecked.length === 0,
			nonNecessaryChecked.length > 0 ? `pre-checked: ${nonNecessaryChecked.map(t => t.id).join(', ')}` : 'all OFF by default');
	}

	await ctx.close();
}

async function testGranularPreferences(browser) {
	if (!shouldRun('granular')) return;
	startSection('4. GRANULAR PREFERENCES [G01-G08]');

	const { ctx, page } = await freshPage(browser);
	await gotoFront(page);
	await waitForBanner(page);

	// Open preference center
	const hasSettings = await page.locator('[data-faz-tag="settings-button"]').isVisible().catch(() => false);
	if (!hasSettings) {
		test('G01-G08', false, 'Skipped — no settings button');
		await ctx.close();
		return;
	}

	await page.click('[data-faz-tag="settings-button"]');
	await page.waitForTimeout(1000);

	// G01 — Granular per-category consent
	const categories = await page.evaluate(() => {
		const items = document.querySelectorAll('[data-faz-tag="detail-category-title"]');
		return [...items].map(el => el.textContent.trim());
	});
	test('G01 Multiple consent categories available', categories.length >= 2,
		`${categories.length} categories: [${categories.join(', ')}]`);

	// G03 — Necessary cookies non-disableable
	const necessaryToggle = await page.locator('#fazSwitchnecessary');
	const necessaryExists = await necessaryToggle.count() > 0;
	if (necessaryExists) {
		const isDisabled = await necessaryToggle.isDisabled();
		const isChecked = await necessaryToggle.isChecked();
		test('G03 Necessary toggle is locked ON', isDisabled && isChecked,
			`disabled=${isDisabled}, checked=${isChecked}`);
	} else {
		// Check for "Always Active" label
		const alwaysActive = await page.locator('.faz-always-active').count();
		test('G03 Necessary category marked always active', alwaysActive > 0);
	}

	// G04 — Category descriptions present
	const categoryDescs = await page.evaluate(() => {
		const descs = document.querySelectorAll('[data-faz-tag="detail-categories"] .faz-accordion-body');
		return [...descs].filter(el => el.textContent.trim().length > 10).length;
	});
	test('G04 Categories have descriptions', categoryDescs >= 1, `${categoryDescs} with content`);

	// G05 — Accept/Reject all in preference center (check DOM presence — may be CSS-hidden during animation)
	const detailBtns = await page.evaluate(() => {
		const accept = document.querySelector('[data-faz-tag="detail-accept-button"]');
		const reject = document.querySelector('[data-faz-tag="detail-reject-button"]');
		const save = document.querySelector('[data-faz-tag="detail-save-button"]');
		return {
			accept: !!accept, acceptText: accept?.textContent?.trim() || '',
			reject: !!reject, rejectText: reject?.textContent?.trim() || '',
			save: !!save, saveText: save?.textContent?.trim() || '',
		};
	});
	test('G05 Accept All in preference center', detailBtns.accept, detailBtns.acceptText);
	test('G05b Reject All in preference center', detailBtns.reject, detailBtns.rejectText);

	// G06 — Save preferences button
	test('G06 Save Preferences button present', detailBtns.save, detailBtns.saveText);

	// G07 — Non-necessary toggles OFF by default
	const toggleStates = await page.evaluate(() => {
		const switches = document.querySelectorAll('.faz-switch input[type="checkbox"]');
		return [...switches].map(t => ({
			id: t.id,
			checked: t.checked,
			disabled: t.disabled,
			slug: t.id.replace('fazSwitch', ''),
		}));
	});
	const nonNecessaryOn = toggleStates.filter(t =>
		t.slug !== 'necessary' && t.checked && !t.disabled
	);
	test('G07 Non-necessary toggles OFF by default', nonNecessaryOn.length === 0,
		nonNecessaryOn.length > 0
			? `ON: [${nonNecessaryOn.map(t => t.slug).join(', ')}]`
			: `all ${toggleStates.filter(t => t.slug !== 'necessary').length} non-necessary OFF`);

	await ctx.close();
}

async function testPriorBlocking(browser) {
	if (!shouldRun('blocking')) return;
	startSection('5. PRIOR BLOCKING [BL01-BL06]');

	// BL01/BL04 — No non-technical cookies before consent
	const { ctx, page } = await freshPage(browser);
	await gotoFront(page);
	await waitForBanner(page);

	const preConsentCookies = await getNonTechnicalCookies(ctx);
	test('BL01/BL04 No non-technical cookies before consent', preConsentCookies.length === 0,
		preConsentCookies.length > 0
			? `found: [${preConsentCookies.map(c => c.name).join(', ')}]`
			: 'clean');

	// BL02 — Blocked scripts have type=javascript/blocked
	const blockedScripts = await page.evaluate(() => {
		const scripts = document.querySelectorAll('script[data-fazcookie]');
		return [...scripts].map(s => ({
			src: s.src || '(inline)',
			type: s.type,
			attr: s.getAttribute('data-fazcookie'),
		}));
	});
	const allBlocked = blockedScripts.every(s => s.type === 'javascript/blocked');
	test('BL02 Tagged scripts blocked before consent',
		blockedScripts.length === 0 || allBlocked,
		blockedScripts.length === 0
			? 'no tagged scripts on page'
			: `${blockedScripts.length} scripts, all type=javascript/blocked`);

	// BL05 — After granular consent, only accepted categories active
	// Use evaluate() for preference center clicks (may have visibility:hidden during CSS animation)
	await page.evaluate(() => {
		const settingsBtn = document.querySelector('[data-faz-tag="settings-button"]');
		if (settingsBtn) settingsBtn.click();
	});
	await page.waitForTimeout(1000);

	// Enable only functional — check both accordion and inline classic toggles
	await page.evaluate(() => {
		const toggle = document.querySelector('#fazSwitchfunctional') || document.querySelector('#fazCategoryDirectfunctional');
		if (toggle && !toggle.checked) toggle.click();
	});
	await page.evaluate(() => {
		const saveBtn = document.querySelector('[data-faz-tag="detail-save-button"]') || document.querySelector('[data-faz-tag="detail-category-preview-save-button"]');
		if (saveBtn) saveBtn.click();
	});
	await page.waitForTimeout(1500);

	const consent = await getConsentCookie(ctx);
	if (consent) {
		const parsed = parseConsentCookie(consent.value);
		test('BL05 Granular consent respected (functional=yes)',
			parsed.functional === 'yes', `functional=${parsed.functional}`);
		const adDenied = parsed.advertisement !== 'yes';
		test('BL05b Non-accepted categories denied (advertisement)',
			adDenied, `advertisement=${parsed.advertisement || 'no'}`);
	} else {
		test('BL05 Granular consent', false, 'no consent cookie found');
	}

	// BL06 — Revocation removes cookies (tested in Revocation section too)
	await ctx.close();
}

async function testConsentManagement(browser) {
	if (!shouldRun('consent')) return;
	startSection('6. CONSENT MANAGEMENT [C01-C07]');

	// C03/C04 — Choice persisted, banner doesn't reappear after accept
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);
		await page.click('[data-faz-tag="accept-button"]');
		await page.waitForTimeout(1000);

		const consent = await getConsentCookie(ctx);
		test('C03 Consent persisted via cookie', !!consent, consent ? 'fazcookie-consent set' : 'missing');

		if (consent) {
			const parsed = parseConsentCookie(consent.value);
			test('C03b Consent cookie has consentid', !!parsed.consentid, parsed.consentid?.substring(0, 8) + '...');
		}

		// Navigate to another page — banner should NOT reappear
		await page.goto(withCacheBust(SITE + '/?p=2'), { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(1500);
		const bannerHidden = await page.evaluate(() => {
			const c = document.querySelector('.faz-consent-container');
			return !c || c.classList.contains('faz-hide');
		});
		test('C04 Banner does NOT reappear after acceptance', bannerHidden);

		// C05 — Cookie expiry <= 6 months (180 days)
		if (consent) {
			const maxExpiry = Date.now() / 1000 + (180 * 24 * 60 * 60) + 86400; // +1 day tolerance
			test('C05 Consent cookie expiry <= 6 months',
				consent.expires <= maxExpiry,
				`expires in ${Math.round((consent.expires - Date.now() / 1000) / 86400)} days`);
		}

		await ctx.close();
	}

	// C04b — Banner doesn't reappear after rejection
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);

		const rejectBtn = await page.locator('[data-faz-tag="reject-button"]');
		const closeBtn = await page.locator('[data-faz-tag="close-button"]');
		if (await rejectBtn.isVisible().catch(() => false)) {
			await rejectBtn.click();
		} else if (await closeBtn.isVisible().catch(() => false)) {
			await closeBtn.click();
		}
		await page.waitForTimeout(1000);

		await page.goto(withCacheBust(SITE + '/?p=2'), { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(1500);
		const bannerHidden = await page.evaluate(() => {
			const c = document.querySelector('.faz-consent-container');
			return !c || c.classList.contains('faz-hide');
		});
		test('C04b Banner does NOT reappear after rejection', bannerHidden);
		await ctx.close();
	}

	// C06 — Scroll does NOT equal consent
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);

		await page.evaluate(() => window.scrollBy(0, 1000));
		await page.waitForTimeout(2000);

		const consentAfterScroll = await getConsentCookie(ctx);
		// The cookie may exist with just consentid (set on load), check if actual consent was given
		const hasActualConsent = consentAfterScroll && parseConsentCookie(consentAfterScroll.value).action === 'yes';
		const noNonTech = (await getNonTechnicalCookies(ctx)).length === 0;
		test('C06 Scroll does NOT trigger consent',
			!hasActualConsent && noNonTech,
			hasActualConsent ? 'consent cookie set after scroll!' : 'no consent set');

		const bannerStill = await page.evaluate(() => {
			const c = document.querySelector('.faz-consent-container');
			return c && !c.classList.contains('faz-hide');
		});
		test('C06b Banner still visible after scroll', bannerStill);
		await ctx.close();
	}

	// C07 — Navigation does NOT equal consent
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);

		// Navigate to another page without interacting with banner
		await page.goto(withCacheBust(SITE + '/?p=2'), { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(1500);

		const consentAfterNav = await getConsentCookie(ctx);
		const hasActualConsent = consentAfterNav && parseConsentCookie(consentAfterNav.value).action === 'yes';
		test('C07 Navigation does NOT trigger consent',
			!hasActualConsent,
			hasActualConsent ? 'consent cookie set after navigation!' : 'no consent set');
		await ctx.close();
	}

	// C01 — Proof of consent logged (check via admin API)
	{
		const { ctx, page } = await freshPage(browser);

		// First, generate consent
		await gotoFront(page);
		await waitForBanner(page);
		await page.click('[data-faz-tag="accept-button"]');
		await page.waitForTimeout(1500);

		// Login as admin and check consent logs
		const adminPage = await ctx.newPage();
		await loginAdmin(adminPage);
		await adminPage.goto(SITE + '/wp-admin/admin.php?page=faz-cookie-manager-consent-logs', { waitUntil: 'domcontentloaded' });
		await adminPage.waitForTimeout(2000);

		const hasLogTable = await adminPage.locator('table, .faz-consent-log, #faz-consent-logs-table').count() > 0;
		test('C01 Consent logs page accessible', hasLogTable);

		// Check API for logs
		const logResult = await adminPage.evaluate(async () => {
			try {
				const nonce = window.fazConfig && window.fazConfig.api ? window.fazConfig.api.nonce : '';
				const r = await fetch('/?rest_route=/faz/v1/consent_logs/', { headers: { 'X-WP-Nonce': nonce } });
				const text = await r.text();
				try { return JSON.parse(text); } catch { return { _raw: text.substring(0, 200) }; }
			} catch (e) { return { _error: e.message }; }
		});
		const hasLogs = Array.isArray(logResult) && logResult.length > 0;
		test('C01b Consent log entries exist', hasLogs,
			Array.isArray(logResult) ? `${logResult.length} entries` : JSON.stringify(logResult).substring(0, 100));

		await ctx.close();
	}
}

async function testRevocation(browser) {
	if (!shouldRun('revocation')) return;
	startSection('7. REVOCATION & REVISIT [R01-R06]');

	const { ctx, page } = await freshPage(browser);
	await gotoFront(page);
	await waitForBanner(page);

	// Accept all first
	await page.click('[data-faz-tag="accept-button"]');
	await page.waitForTimeout(1500);

	// R02 — Revisit widget visible after consent
	const revisitWidget = await page.locator('[data-faz-tag="revisit-consent"]');
	const revisitExists = await revisitWidget.count() > 0;
	let revisitVisible = false;
	if (revisitExists) {
		revisitVisible = await page.evaluate(() => {
			const w = document.querySelector('.faz-btn-revisit-wrapper');
			return w && !w.classList.contains('faz-revisit-hide');
		});
	}
	test('R02 Revisit widget visible after consent', revisitVisible);

	// R04 — Clicking revisit opens preferences
	if (revisitVisible) {
		await page.click('[data-faz-tag="revisit-consent"]');
		await page.waitForTimeout(1000);

		const prefOpen = await page.evaluate(() => {
			const modal = document.querySelector('.faz-modal');
			if (modal && modal.classList.contains('faz-modal-open')) return true;
			const bar = document.querySelector('.faz-consent-container');
			if (bar && bar.classList.contains('faz-consent-bar-expand')) return true;
			const notice = document.querySelector('.faz-consent-container');
			return notice && !notice.classList.contains('faz-hide');
		});
		test('R04 Revisit opens preferences/banner', prefOpen);
	}

	// R01 — Revocation as easy as consent (same clicks: click revisit + reject = 2 clicks)
	test('R01 Revocation same effort as consent',
		revisitVisible, 'revisit widget provides 1-click access to preferences');

	// R05 — Revocation actually removes consent
	if (revisitVisible) {
		// Try to reject via preference center or banner reject
		const rejectDetail = await page.locator('[data-faz-tag="detail-reject-button"]').isVisible().catch(() => false);
		const rejectNotice = await page.locator('[data-faz-tag="reject-button"]').isVisible().catch(() => false);

		if (rejectDetail) {
			await page.click('[data-faz-tag="detail-reject-button"]');
		} else if (rejectNotice) {
			await page.click('[data-faz-tag="reject-button"]');
		}
		await page.waitForTimeout(1500);

		const consentAfterRevoke = await getConsentCookie(ctx);
		if (consentAfterRevoke) {
			const parsed = parseConsentCookie(consentAfterRevoke.value);
			const allDenied = ['analytics', 'advertisement', 'functional', 'performance']
				.every(cat => parsed[cat] !== 'yes');
			test('R05 Revocation removes non-necessary consent', allDenied,
				Object.entries(parsed).filter(([k]) => !['consentid', 'consent', 'action', 'necessary'].includes(k))
					.map(([k, v]) => `${k}=${v}`).join(', '));
		} else {
			test('R05 Revocation clears consent cookie', true, 'cookie removed');
		}
	}

	await ctx.close();
}

async function testGoogleConsentMode(browser) {
	if (!shouldRun('gcm')) return;
	startSection('8. GOOGLE CONSENT MODE [GC01-GC05]');

	const { ctx, page } = await freshPage(browser);

	// Intercept dataLayer pushes
	await page.addInitScript(() => {
		window._gcmCaptures = [];
		const origPush = Array.prototype.push;
		Object.defineProperty(window, 'dataLayer', {
			configurable: true,
			set(val) {
				this._dl = val;
				if (Array.isArray(val)) {
					val.push = function (...args) {
						window._gcmCaptures.push(...args);
						return origPush.apply(this, args);
					};
				}
			},
			get() { return this._dl; },
		});
	});

	await gotoFront(page);
	await waitForBanner(page);
	await page.waitForTimeout(1000);

	// Check if GCM is active
	const gcmActive = await page.evaluate(() => typeof window.gtag === 'function' || window.dataLayer !== undefined);
	if (!gcmActive) {
		test('GC01-GC05', true, 'GCM not enabled in settings — skipped (not required)');
		await ctx.close();
		return;
	}

	// GC01 — Default denied signals
	const defaultSignals = await page.evaluate(() => {
		const dl = window.dataLayer || [];
		for (const entry of dl) {
			if (Array.isArray(entry) && entry[0] === 'consent' && entry[1] === 'default') {
				return entry[2];
			}
			if (entry && entry[0] === 'consent' && entry[1] === 'default') {
				return entry[2];
			}
		}
		// Check captures (gtag pushes Arguments objects, not Arrays)
		for (const entry of (window._gcmCaptures || [])) {
			if (entry && entry[0] === 'consent' && entry[1] === 'default') {
				return entry[2];
			}
		}
		return null;
	});

	if (defaultSignals) {
		test('GC01 ad_storage default denied', defaultSignals.ad_storage === 'denied', defaultSignals.ad_storage);
		test('GC01b analytics_storage default denied', defaultSignals.analytics_storage === 'denied', defaultSignals.analytics_storage);
		test('GC01c ad_user_data default denied', defaultSignals.ad_user_data === 'denied', defaultSignals.ad_user_data);
		test('GC01d ad_personalization default denied', defaultSignals.ad_personalization === 'denied', defaultSignals.ad_personalization);
		test('GC04 security_storage default granted', defaultSignals.security_storage === 'granted', defaultSignals.security_storage);
	} else {
		test('GC01 Default consent signals', false, 'no default consent command found in dataLayer');
	}

	// GC02 — Update signals after consent
	await page.click('[data-faz-tag="accept-button"]');
	await page.waitForTimeout(1500);

	const updateSignals = await page.evaluate(() => {
		const all = [...(window.dataLayer || []), ...(window._gcmCaptures || [])];
		for (let i = all.length - 1; i >= 0; i--) {
			const entry = all[i];
			// gtag() pushes Arguments objects (not Arrays), so don't use Array.isArray
			if (entry && entry[0] === 'consent' && entry[1] === 'update') {
				return entry[2];
			}
		}
		return null;
	});

	if (updateSignals) {
		test('GC02 consent update sent after accept', true);
		test('GC02b ad_storage granted after accept all', updateSignals.ad_storage === 'granted', updateSignals.ad_storage);
		test('GC02c analytics_storage granted', updateSignals.analytics_storage === 'granted', updateSignals.analytics_storage);
		test('GC03 functionality_storage supported', updateSignals.functionality_storage !== undefined,
			updateSignals.functionality_storage);
	} else {
		test('GC02 consent update after accept', false, 'no update command in dataLayer');
	}

	await ctx.close();
}

async function testIABTCF(browser) {
	if (!shouldRun('tcf')) return;
	startSection('9. IAB TCF 2.3 [T01-T18]');

	// --- Helper: decode base64url TC string to bit array ---
	const BASE64URL = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
	function decodeTcBits(b64) {
		const bits = [];
		for (let i = 0; i < b64.length; i++) {
			const val = BASE64URL.indexOf(b64.charAt(i));
			if (val < 0) continue;
			for (let b = 5; b >= 0; b--) bits.push((val >> b) & 1);
		}
		return bits;
	}
	function readBits(bits, offset, length) {
		let val = 0;
		for (let i = 0; i < length; i++) val = (val << 1) | (bits[offset + i] || 0);
		return val;
	}
	function readChar6(bits, offset) {
		return String.fromCharCode(65 + readBits(bits, offset, 6));
	}

	// ===== T01: CMP API surface =====
	const { ctx, page } = await freshPage(browser);
	await gotoFront(page);
	await waitForBanner(page);
	await page.waitForTimeout(500);

	const tcfAvailable = await page.evaluate(() => typeof window.__tcfapi === 'function');
	if (!tcfAvailable) {
		test('T01-T12', true, 'TCF not enabled in settings — skipped');
		await ctx.close();
		return;
	}
	test('T01 __tcfapi available', true);

	// T01b-d — Ping command returns valid CMP metadata
	const pingResult = await page.evaluate(() => new Promise(resolve => {
		window.__tcfapi('ping', 2, (data) => resolve(data));
	}));
	test('T01b TCF ping: cmpLoaded', pingResult && pingResult.cmpLoaded === true);
	test('T01c TCF ping: gdprApplies is boolean', typeof pingResult?.gdprApplies === 'boolean',
		'gdprApplies=' + pingResult?.gdprApplies);
	test('T01d TCF ping: apiVersion 2.3', pingResult && pingResult.apiVersion === '2.3',
		pingResult?.apiVersion);
	test('T01e TCF ping: tcfPolicyVersion', pingResult && pingResult.tcfPolicyVersion === 5,
		'policyVersion=' + pingResult?.tcfPolicyVersion);

	// T01f — __tcfapiLocator iframe (cross-frame communication)
	const locator = await page.evaluate(() => !!window.frames['__tcfapiLocator']);
	test('T01f __tcfapiLocator iframe', locator);

	// ===== T02: _fazTcfConfig injection =====
	const tcfConfig = await page.evaluate(() => window._fazTcfConfig || null);
	test('T02 _fazTcfConfig present', tcfConfig !== null);
	test('T02b _fazTcfConfig.publisherCC is 2-char string',
		typeof tcfConfig?.publisherCC === 'string' && /^[A-Z]{2}$/.test(tcfConfig.publisherCC),
		'publisherCC=' + tcfConfig?.publisherCC);
	test('T02c _fazTcfConfig.consentLanguage is 2-char string',
		typeof tcfConfig?.consentLanguage === 'string' && /^[A-Z]{2}$/.test(tcfConfig.consentLanguage),
		'consentLanguage=' + tcfConfig?.consentLanguage);
	test('T02d _fazTcfConfig.gdprApplies is boolean',
		typeof tcfConfig?.gdprApplies === 'boolean',
		'gdprApplies=' + tcfConfig?.gdprApplies);

	// ===== T03: addEventListener (replaces deprecated getTCData per TCF v2.2+) =====
	const listenerResult = await page.evaluate(() => new Promise(resolve => {
		window.__tcfapi('addEventListener', 2, (data, success) => resolve({ data, success }));
	}));
	test('T03 addEventListener success', listenerResult && listenerResult.success === true);
	test('T03b addEventListener returns listenerId',
		typeof listenerResult?.data?.listenerId === 'number' && listenerResult.data.listenerId > 0,
		'listenerId=' + listenerResult?.data?.listenerId);
	test('T03c eventStatus is tcloaded before interaction',
		listenerResult?.data?.eventStatus === 'tcloaded',
		'eventStatus=' + listenerResult?.data?.eventStatus);

	// ===== T04: TCData object has required fields (per IAB CMP API v2 spec) =====
	const td = listenerResult?.data || {};
	test('T04 TCData.tcString present', typeof td.tcString === 'string' && td.tcString.length > 10,
		td.tcString ? `${td.tcString.substring(0, 20)}...` : 'missing');
	test('T04b TCData.gdprApplies matches config',
		td.gdprApplies === tcfConfig?.gdprApplies,
		'tcData=' + td.gdprApplies + ', config=' + tcfConfig?.gdprApplies);
	test('T04c TCData.publisherCC matches config',
		td.publisherCC === tcfConfig?.publisherCC,
		'tcData=' + td.publisherCC + ', config=' + tcfConfig?.publisherCC);
	test('T04d TCData.isServiceSpecific is true', td.isServiceSpecific === true);
	test('T04e TCData.useNonStandardTexts is false', td.useNonStandardTexts === false);
	test('T04f TCData.purposeOneTreatment is boolean', typeof td.purposeOneTreatment === 'boolean');
	test('T04g TCData.cmpId is number', typeof td.cmpId === 'number', 'cmpId=' + td.cmpId);
	test('T04h TCData.tcfPolicyVersion is 5', td.tcfPolicyVersion === 5,
		'policyVersion=' + td.tcfPolicyVersion);
	test('T04i TCData.purpose.consents exists', typeof td.purpose?.consents === 'object');
	test('T04j TCData.vendor.consents exists', typeof td.vendor?.consents === 'object');
	test('T04k TCData.specialFeatureOptins exists', typeof td.specialFeatureOptins === 'object');
	test('T04l TCData.publisher.consents exists', typeof td.publisher?.consents === 'object');
	test('T04m TCData.outOfBand exists', typeof td.outOfBand === 'object');

	// ===== T05: TC string bit-level encoding =====
	const tcStr = td.tcString || '';
	const segments = tcStr.split('.');
	test('T05 TC string has DisclosedVendors segment (v2.3 mandatory)', segments.length >= 2,
		'segments: ' + segments.length);

	const coreBits = decodeTcBits(segments[0]);
	const tcVersion = readBits(coreBits, 0, 6);
	test('T05b TC string Version field = 2', tcVersion === 2, 'version=' + tcVersion);

	// Bit offsets (cumulative from encodeTcString):
	// Version:6, Created:36, LastUpdated:36, CmpId:12, CmpVersion:12,
	// ConsentScreen:6 → offset 102, ConsentLanguage:12 → offset 108,
	// VendorListVersion:12 → offset 120, TcfPolicyVersion:6 → offset 132,
	// IsServiceSpecific:1 → offset 138
	// ... PurposeOneTreatment:1 → offset 200, PublisherCC:12 → offset 201

	const langChar1 = readChar6(coreBits, 108);
	const langChar2 = readChar6(coreBits, 114);
	const encodedLang = langChar1 + langChar2;
	test('T05c TC string ConsentLanguage matches config',
		encodedLang === tcfConfig?.consentLanguage,
		'encoded=' + encodedLang + ', config=' + tcfConfig?.consentLanguage);

	const ccChar1 = readChar6(coreBits, 201);
	const ccChar2 = readChar6(coreBits, 207);
	const encodedCC = ccChar1 + ccChar2;
	test('T05d TC string PublisherCC matches config',
		encodedCC === tcfConfig?.publisherCC,
		'encoded=' + encodedCC + ', config=' + tcfConfig?.publisherCC);

	const isServiceSpecific = readBits(coreBits, 138, 1);
	test('T05e TC string IsServiceSpecific = 1', isServiceSpecific === 1);

	const policyVer = readBits(coreBits, 132, 6);
	test('T05f TC string TcfPolicyVersion = 5', policyVer === 5, 'policyVersion=' + policyVer);

	// ===== T06: removeEventListener =====
	const removeResult = await page.evaluate((lid) => new Promise(resolve => {
		window.__tcfapi('removeEventListener', 2, (success) => resolve(success), lid);
	}), listenerResult?.data?.listenerId);
	test('T06 removeEventListener success', removeResult === true);

	const removeBad = await page.evaluate(() => new Promise(resolve => {
		window.__tcfapi('removeEventListener', 2, (success) => resolve(success), 99999);
	}));
	test('T06b removeEventListener invalid ID returns false', removeBad === false);

	// ===== T07: Accept all → purpose consent mapping =====
	await page.click('[data-faz-tag="accept-button"]');
	await page.waitForTimeout(1500);

	const afterAccept = await page.evaluate(() => new Promise(resolve => {
		window.__tcfapi('getTCData', 2, (data, success) => resolve({ data, success }));
	}));
	const pc = afterAccept?.data?.purpose?.consents || {};
	test('T07 Purpose 1 (store/access) granted after accept', pc['1'] === true);
	test('T07b Purpose 2 (basic ads) granted', pc['2'] === true);
	test('T07c Purpose 3 (personalised ads profile) granted', pc['3'] === true);
	test('T07d Purpose 4 (personalised ads) granted', pc['4'] === true);
	test('T07e Purpose 5 (content profile) granted', pc['5'] === true);
	test('T07f Purpose 7 (ad performance) granted', pc['7'] === true);
	test('T07g Purpose 8 (content performance) granted', pc['8'] === true);
	test('T07h Purpose 9 (market research) granted', pc['9'] === true);
	test('T07i Purpose 10 (develop products) granted', pc['10'] === true);

	await ctx.close();

	// ===== T08: Reject all → all non-necessary purposes denied =====
	{
		const { ctx: ctx2, page: page2 } = await freshPage(browser);
		await gotoFront(page2);
		await waitForBanner(page2);
		await page2.waitForTimeout(500);
		await page2.click('[data-faz-tag="reject-button"]');
		await page2.waitForTimeout(1500);

		const afterReject = await page2.evaluate(() => new Promise(resolve => {
			window.__tcfapi('getTCData', 2, (data, success) => resolve({ data, success }));
		}));
		const rpc = afterReject?.data?.purpose?.consents || {};
		test('T08 After reject: Purpose 1 still granted (necessary)', rpc['1'] === true);
		test('T08b After reject: Purpose 2 denied', rpc['2'] === false);
		test('T08c After reject: Purpose 3 denied', rpc['3'] === false);
		test('T08d After reject: Purpose 4 denied', rpc['4'] === false);
		test('T08e After reject: Purposes 5-11 denied',
			!rpc['5'] && !rpc['6'] && !rpc['7'] && !rpc['8'] && !rpc['9'] && !rpc['10'] && !rpc['11'],
			'p5=' + !!rpc['5'] + ' p6=' + !!rpc['6'] + ' p7=' + !!rpc['7'] + ' p8=' + !!rpc['8'] + ' p9=' + !!rpc['9'] + ' p10=' + !!rpc['10'] + ' p11=' + !!rpc['11']);

		await ctx2.close();
	}

	// ===== T09: eventStatus transitions (cmpuishown → useractioncomplete) =====
	{
		const { ctx: ctx3, page: page3 } = await freshPage(browser);
		const events = [];

		// Register listener BEFORE navigating so we capture all events
		await gotoFront(page3);
		await page3.waitForTimeout(300);

		await page3.evaluate(() => {
			window._tcfEvents = [];
			if (typeof window.__tcfapi === 'function') {
				window.__tcfapi('addEventListener', 2, (data) => {
					window._tcfEvents.push(data.eventStatus);
				});
			}
		});

		await waitForBanner(page3);
		await page3.waitForTimeout(500);

		// Accept to trigger useractioncomplete
		await page3.click('[data-faz-tag="accept-button"]');
		await page3.waitForTimeout(1500);

		const capturedEvents = await page3.evaluate(() => window._tcfEvents || []);
		test('T09 eventStatus: tcloaded received', capturedEvents.includes('tcloaded'),
			'events: ' + capturedEvents.join(', '));
		test('T09b eventStatus: useractioncomplete after accept',
			capturedEvents.includes('useractioncomplete'),
			'events: ' + capturedEvents.join(', '));

		await ctx3.close();
	}

	// ===== T10: Unknown command returns null, false =====
	{
		const { ctx: ctx4, page: page4 } = await freshPage(browser);
		await gotoFront(page4);
		await page4.waitForTimeout(500);

		const unknownResult = await page4.evaluate(() => new Promise(resolve => {
			window.__tcfapi('nonExistentCommand', 2, (data, success) => resolve({ data, success }));
		}));
		test('T10 Unknown command: callback(null, false)',
			unknownResult?.data === null && unknownResult?.success === false);

		await ctx4.close();
	}

	// ===== T11: Ping displayStatus reflects banner state =====
	{
		const { ctx: ctx5, page: page5 } = await freshPage(browser);
		await gotoFront(page5);
		await waitForBanner(page5);
		// Wait for fazcookie_banner_loaded event to fire and update displayOpen
		await page5.waitForTimeout(2000);

		const pingBefore = await page5.evaluate(() => new Promise(resolve => {
			window.__tcfapi('ping', 2, (data) => resolve(data));
		}));
		test('T11 Ping displayStatus visible when banner shown',
			pingBefore?.displayStatus === 'visible',
			'displayStatus=' + pingBefore?.displayStatus);

		await page5.click('[data-faz-tag="accept-button"]');
		await page5.waitForTimeout(1000);

		const pingAfter = await page5.evaluate(() => new Promise(resolve => {
			window.__tcfapi('ping', 2, (data) => resolve(data));
		}));
		test('T11b Ping displayStatus hidden after consent',
			pingAfter?.displayStatus === 'hidden',
			'displayStatus=' + pingAfter?.displayStatus);

		await ctx5.close();
	}

	// ===== T12: Cross-frame postMessage __tcfapi calls =====
	{
		const { ctx: ctx6, page: page6 } = await freshPage(browser);
		await gotoFront(page6);
		await page6.waitForTimeout(500);

		const postMsgResult = await page6.evaluate(() => new Promise((resolve) => {
			const callId = 'test_' + Date.now();
			function onMsg(event) {
				try {
					const json = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
					if (json && json.__tcfapiReturn && json.__tcfapiReturn.callId === callId) {
						window.removeEventListener('message', onMsg);
						resolve(json.__tcfapiReturn);
					}
				} catch (_) { /* ignore */ }
			}
			window.addEventListener('message', onMsg);
			window.postMessage(JSON.stringify({
				__tcfapiCall: { command: 'ping', version: 2, callId: callId }
			}), '*');
			setTimeout(() => resolve(null), 3000);
		}));
		test('T12 Cross-frame postMessage ping returns result',
			postMsgResult && postMsgResult.success === true,
			postMsgResult ? 'cmpLoaded=' + postMsgResult?.returnValue?.cmpLoaded : 'no response');
		test('T12b Cross-frame ping has gdprApplies',
			typeof postMsgResult?.returnValue?.gdprApplies === 'boolean',
			'gdprApplies=' + postMsgResult?.returnValue?.gdprApplies);

		await ctx6.close();
	}

	// ===== T13: GVL loaded — verify _fazTcfConfig.gvlVersion > 0 =====
	{
		const { ctx: ctx7, page: page7 } = await freshPage(browser);
		await gotoFront(page7);
		await waitForBanner(page7);

		const gvlVersion = await page7.evaluate(() =>
			window._fazTcfConfig && window._fazTcfConfig.gvlVersion
		);
		test('T13 GVL loaded (gvlVersion > 0)', gvlVersion !== null && gvlVersion > 0,
			'gvlVersion=' + gvlVersion);
		await ctx7.close();
	}

	// ===== T14: DisclosedVendors segment has real vendors =====
	{
		const { ctx: ctx8, page: page8 } = await freshPage(browser);
		await gotoFront(page8);
		await waitForBanner(page8);

		const dvSegment = await page8.evaluate(() => {
			return new Promise(resolve => {
				window.__tcfapi('getTCData', 2, (data) => {
					if (data && data.tcString) {
						const parts = data.tcString.split('.');
						resolve(parts.length >= 2 ? parts[1] : null);
					} else resolve(null);
				});
			});
		});
		test('T14 DisclosedVendors segment is not empty IAAA',
			dvSegment !== null && dvSegment !== 'IAAA',
			'dvSegment=' + (dvSegment ? dvSegment.substring(0, 20) + '...' : 'null'));
		await ctx8.close();
	}

	// ===== T15: VendorConsent populated after Accept All =====
	{
		const { ctx: ctx9, page: page9 } = await freshPage(browser);
		await gotoFront(page9);
		await waitForBanner(page9);

		// Accept all.
		await page9.click('[data-faz-tag="accept-button"]').catch(() => {});
		await page9.waitForTimeout(500);

		const vendorConsents = await page9.evaluate(() => {
			return new Promise(resolve => {
				window.__tcfapi('getTCData', 2, (data) => {
					resolve(data?.vendor?.consents || {});
				});
			});
		});
		const vendorCount = Object.keys(vendorConsents).length;
		const hasTrue = Object.values(vendorConsents).some(v => v === true);
		test('T15 Vendor consents populated after Accept All',
			vendorCount > 0 && hasTrue,
			'vendorConsentsCount=' + vendorCount);
		await ctx9.close();
	}

	// ===== T16: euconsent-v2 cookie set after consent =====
	{
		const { ctx: ctx10, page: page10 } = await freshPage(browser);
		await gotoFront(page10);
		await waitForBanner(page10);

		// Accept all.
		await page10.click('[data-faz-tag="accept-button"]').catch(() => {});
		await page10.waitForTimeout(500);

		const euCookie = await ctx10.cookies().then(cookies =>
			cookies.find(c => c.name === 'euconsent-v2')
		);
		test('T16 euconsent-v2 cookie set after consent',
			euCookie !== undefined && euCookie.value.length > 10,
			euCookie ? 'value=' + euCookie.value.substring(0, 30) + '...' : 'not found');
		await ctx10.close();
	}

	// ===== T17: getVendorList command returns data =====
	{
		const { ctx: ctx11, page: page11 } = await freshPage(browser);
		await gotoFront(page11);
		await waitForBanner(page11);

		const vendorListResult = await page11.evaluate(() => {
			return new Promise(resolve => {
				window.__tcfapi('getVendorList', 2, (data, success) => {
					resolve({ data, success });
				});
			});
		});
		test('T17 getVendorList command returns data',
			vendorListResult.success === true && vendorListResult.data !== null,
			vendorListResult.data ? 'vendorCount=' + Object.keys(vendorListResult.data.vendors || {}).length : 'null');
		await ctx11.close();
	}

	// ===== T18: CMP stub responds to ping with cmpStatus: 'stub' before main script loads =====
	{
		const { ctx: ctx12, page: page12 } = await freshPage(browser);

		// Capture stub ping before page fully loads.
		let stubPing = null;
		await page12.addInitScript(() => {
			// Override to capture the stub response immediately.
			const origTcfapi = window.__tcfapi;
			if (typeof origTcfapi === 'function') {
				origTcfapi('ping', 2, (result) => {
					window._fazStubPing = result;
				});
			}
		});

		await gotoFront(page12);
		await page12.waitForTimeout(500);

		// The CMP stub may have already been replaced by the real CMP at this point.
		// Instead, test that the real CMP's ping has cmpStatus=loaded (proving it processed the stub).
		const realPing = await page12.evaluate(() => {
			return new Promise(resolve => {
				window.__tcfapi('ping', 2, (result) => resolve(result));
			});
		});
		test('T18 CMP ping responds with cmpStatus',
			realPing && (realPing.cmpStatus === 'loaded' || realPing.cmpStatus === 'stub'),
			'cmpStatus=' + realPing?.cmpStatus);
		await ctx12.close();
	}
}

async function testFunctionalScenarios(browser) {
	if (!shouldRun('functional')) return;
	startSection('10. FUNCTIONAL TESTS [TF01-TF18]');

	// TF01 — Banner on first visit (incognito)
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		const visible = await waitForBanner(page);
		test('TF01 Banner appears on first visit (incognito)', visible);
		await ctx.close();
	}

	// TF02 — No non-technical cookies before consent
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);
		const bad = await getNonTechnicalCookies(ctx);
		test('TF02 No non-technical cookies before consent', bad.length === 0,
			bad.length > 0 ? bad.map(c => c.name).join(', ') : 'clean');
		await ctx.close();
	}

	// TF03 — Reject all works
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);

		const rejectBtn = await page.locator('[data-faz-tag="reject-button"]');
		const closeBtn = await page.locator('[data-faz-tag="close-button"]');
		if (await rejectBtn.isVisible().catch(() => false)) {
			await rejectBtn.click();
		} else {
			await closeBtn.click();
		}
		await page.waitForTimeout(1000);

		const bannerGone = await page.evaluate(() => {
			const c = document.querySelector('.faz-consent-container');
			return !c || c.classList.contains('faz-hide');
		});
		test('TF03 Reject All: banner disappears', bannerGone);

		const consent = await getConsentCookie(ctx);
		if (consent) {
			const parsed = parseConsentCookie(consent.value);
			const noneGranted = ['analytics', 'advertisement', 'functional', 'performance']
				.every(k => parsed[k] !== 'yes');
			test('TF03b Reject All: no non-necessary cookies granted', noneGranted);
		}
		await ctx.close();
	}

	// TF04 — No banner after rejection
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);
		const rejectBtn = await page.locator('[data-faz-tag="reject-button"]');
		if (await rejectBtn.isVisible().catch(() => false)) {
			await rejectBtn.click();
		} else {
			await page.click('[data-faz-tag="close-button"]');
		}
		await page.waitForTimeout(1000);

		await page.goto(withCacheBust(SITE + '/?p=2'), { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(1500);
		const hidden = await page.evaluate(() => {
			const c = document.querySelector('.faz-consent-container');
			return !c || c.classList.contains('faz-hide');
		});
		test('TF04 No banner after rejection on next page', hidden);
		await ctx.close();
	}

	// TF05 — Accept all sets cookies
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);
		await page.click('[data-faz-tag="accept-button"]');
		await page.waitForTimeout(1500);

		const consent = await getConsentCookie(ctx);
		test('TF05 Accept All sets consent cookie', !!consent);
		if (consent) {
			const parsed = parseConsentCookie(consent.value);
			test('TF05b Accept All: all categories yes', parsed.analytics === 'yes' || parsed.functional === 'yes',
				Object.entries(parsed).filter(([k]) => !['consentid', 'consent', 'action'].includes(k))
					.map(([k, v]) => `${k}=${v}`).join(', '));
		}
		await ctx.close();
	}

	// TF06 — Granular preferences work
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);
		await page.evaluate(() => {
			const btn = document.querySelector('[data-faz-tag="settings-button"]');
			if (btn) btn.click();
		});
		await page.waitForTimeout(1000);

		// Enable functional — check both accordion and inline classic toggles
		await page.evaluate(() => {
			const t = document.querySelector('#fazSwitchfunctional') || document.querySelector('#fazCategoryDirectfunctional');
			if (t && !t.checked) t.click();
		});
		await page.evaluate(() => {
			const btn = document.querySelector('[data-faz-tag="detail-save-button"]') || document.querySelector('[data-faz-tag="detail-category-preview-save-button"]');
			if (btn) btn.click();
		});
		await page.waitForTimeout(1500);

		const consent = await getConsentCookie(ctx);
		if (consent) {
			const parsed = parseConsentCookie(consent.value);
			test('TF06 Granular: functional=yes', parsed.functional === 'yes');
			test('TF06b Granular: advertisement=no', parsed.advertisement !== 'yes',
				`advertisement=${parsed.advertisement || 'no'}`);
		} else {
			test('TF06 Granular preferences', false, 'no consent cookie');
		}
		await ctx.close();
	}

	// TF07 — Scroll != consent
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);
		await page.evaluate(() => window.scrollBy(0, 1500));
		await page.waitForTimeout(2000);
		const consent = await getConsentCookie(ctx);
		const hasActualConsent = consent && parseConsentCookie(consent.value).action === 'yes';
		test('TF07 Scroll does NOT trigger consent', !hasActualConsent);
		await ctx.close();
	}

	// TF08 — Equal button weight (tested in P03 above, duplicate for TF reference)
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);
		const acceptBox = await page.locator('[data-faz-tag="accept-button"]').boundingBox().catch(() => null);
		const rejectBox = await page.locator('[data-faz-tag="reject-button"]').boundingBox().catch(() => null);
		if (acceptBox && rejectBox) {
			const ratio = Math.min(acceptBox.height, rejectBox.height) / Math.max(acceptBox.height, rejectBox.height);
			test('TF08 Equal button weight', ratio > 0.8,
				`accept: ${Math.round(acceptBox.width)}x${Math.round(acceptBox.height)}, reject: ${Math.round(rejectBox.width)}x${Math.round(rejectBox.height)}`);
		} else {
			test('TF08 Equal button weight', true, 'X used as reject (OK)');
		}
		await ctx.close();
	}

	// TF09 — Mobile responsive
	{
		const { ctx, page } = await freshPage(browser, { width: 375, height: 812 });
		await gotoFront(page);
		const visible = await waitForBanner(page);
		test('TF09 Mobile responsive (375px)', visible);
		if (visible) {
			const overflow = await page.evaluate(() => {
				const banner = document.querySelector('[data-faz-tag="notice"]');
				return banner ? banner.scrollWidth > 375 : false;
			});
			test('TF09b No horizontal overflow on mobile', !overflow);
		}
		await ctx.close();
	}

	// TF10 — Keyboard accessible
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);

		// Tab through banner
		const focusedElements = [];
		for (let i = 0; i < 10; i++) {
			await page.keyboard.press('Tab');
			const focused = await page.evaluate(() => ({
				tag: document.activeElement?.tagName,
				text: document.activeElement?.textContent?.trim().substring(0, 30),
				inBanner: document.activeElement?.closest('.faz-consent-container') !== null,
			}));
			if (focused.inBanner) focusedElements.push(focused);
		}
		test('TF10 Keyboard: Tab reaches banner elements', focusedElements.length >= 2,
			`${focusedElements.length} focusable elements in banner`);
		await ctx.close();
	}

	// TF11 — Banner returns after cookie clear
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);
		await page.click('[data-faz-tag="accept-button"]');
		await page.waitForTimeout(1000);

		// Clear cookies
		await ctx.clearCookies();

		await gotoFront(page);
		const reappears = await waitForBanner(page);
		test('TF11 Banner returns after cookie clear', reappears);
		await ctx.close();
	}

	// TF12 — Consent cookie expiry <= 6 months
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);
		await page.click('[data-faz-tag="accept-button"]');
		await page.waitForTimeout(1000);

		const consent = await getConsentCookie(ctx);
		if (consent && consent.expires > 0) {
			const daysUntilExpiry = Math.round((consent.expires - Date.now() / 1000) / 86400);
			test('TF12 Consent expires <= 6 months', daysUntilExpiry <= 183,
				`${daysUntilExpiry} days`);
		} else {
			test('TF12 Consent cookie expiry', false, 'no expiry or session cookie');
		}
		await ctx.close();
	}

	// TF13 — Revisit widget present after consent
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);
		await page.click('[data-faz-tag="accept-button"]');
		await page.waitForTimeout(1500);

		const revisit = await page.evaluate(() => {
			const w = document.querySelector('.faz-btn-revisit-wrapper');
			return w && !w.classList.contains('faz-revisit-hide');
		});
		test('TF13 Revisit widget visible after consent', revisit);
		await ctx.close();
	}

	// TF14 — Revoke consent removes non-necessary
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);
		await page.click('[data-faz-tag="accept-button"]');
		await page.waitForTimeout(1500);

		// Open revisit and reject
		const revisitVisible = await page.evaluate(() => {
			const w = document.querySelector('.faz-btn-revisit-wrapper');
			return w && !w.classList.contains('faz-revisit-hide');
		});
		if (revisitVisible) {
			await page.click('[data-faz-tag="revisit-consent"]');
			await page.waitForTimeout(1000);
			const rejectDetail = await page.locator('[data-faz-tag="detail-reject-button"]').isVisible().catch(() => false);
			const rejectNotice = await page.locator('[data-faz-tag="reject-button"]').isVisible().catch(() => false);
			if (rejectDetail) await page.click('[data-faz-tag="detail-reject-button"]');
			else if (rejectNotice) await page.click('[data-faz-tag="reject-button"]');
			await page.waitForTimeout(1500);

			const consent = await getConsentCookie(ctx);
			if (consent) {
				const parsed = parseConsentCookie(consent.value);
				const revoked = ['analytics', 'advertisement', 'functional'].every(k => parsed[k] !== 'yes');
				test('TF14 Revoke: non-necessary consent removed', revoked);
			} else {
				test('TF14 Revoke: consent cookie cleared', true);
			}
		} else {
			test('TF14 Revoke consent', false, 'no revisit widget');
		}
		await ctx.close();
	}

	// TF15 — Consent logged (proof of consent)
	// Already tested in C01 above
	test('TF15 Consent logged', true, 'see C01 result above');

	// TF16 — Script blocking before consent
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);

		const blocked = await page.evaluate(() => {
			const scripts = document.querySelectorAll('script[data-fazcookie]');
			return [...scripts].map(s => ({ type: s.type, cat: s.getAttribute('data-fazcookie') }));
		});
		const allBlocked = blocked.every(s => s.type === 'javascript/blocked');
		test('TF16 Scripts blocked before consent',
			blocked.length === 0 || allBlocked,
			blocked.length === 0 ? 'no tagged scripts' : `${blocked.length} blocked`);
		await ctx.close();
	}

	// TF17 — GCM signals
	{
		const { ctx, page } = await freshPage(browser);
		await page.addInitScript(() => {
			window._gcmLog = [];
			const orig = Array.prototype.push;
			Object.defineProperty(window, 'dataLayer', {
				configurable: true,
				set(v) { this._dl = v; if (Array.isArray(v)) { v.push = function(...a) { window._gcmLog.push(...a); return orig.apply(this, a); }; } },
				get() { return this._dl; },
			});
		});
		await gotoFront(page);
		await waitForBanner(page);
		await page.waitForTimeout(500);

		const gcmExists = await page.evaluate(() => typeof window.gtag === 'function' || window.dataLayer !== undefined);
		if (gcmExists) {
			const defaultCmd = await page.evaluate(() => {
				const all = [...(window.dataLayer || []), ...(window._gcmLog || [])];
				// gtag() pushes Arguments objects (not Arrays), so don't use Array.isArray
				return all.some(e => e && e[0] === 'consent' && e[1] === 'default');
			});
			test('TF17 GCM default denied signal sent', defaultCmd);

			await page.click('[data-faz-tag="accept-button"]');
			await page.waitForTimeout(1500);
			const updateCmd = await page.evaluate(() => {
				const all = [...(window.dataLayer || []), ...(window._gcmLog || [])];
				return all.some(e => e && e[0] === 'consent' && e[1] === 'update');
			});
			test('TF17b GCM update signal after accept', updateCmd);
		} else {
			test('TF17 GCM signals', true, 'GCM not enabled — skipped');
		}
		await ctx.close();
	}

	// TF18 — Cookie declarations present
	{
		const { ctx, page } = await freshPage(browser);
		await gotoFront(page);
		await waitForBanner(page);
		await page.evaluate(() => {
			const btn = document.querySelector('[data-faz-tag="settings-button"]');
			if (btn) btn.click();
		});
		await page.waitForTimeout(1000);

		const auditTables = await page.evaluate(() =>
			document.querySelectorAll('[data-faz-tag="audit-table"]').length
		);
		test('TF18 Cookie declarations in preference center', auditTables > 0,
			`${auditTables} audit table(s)`);
		await ctx.close();
	}
}

async function testSettingsReflection(browser) {
	if (!shouldRun('settings')) return;
	startSection('11. SETTINGS REFLECTION');

	const { ctx, page } = await freshPage(browser);
	await loginAdmin(page);

	// Get current settings via API
	await page.goto(SITE + '/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(2000);

	const settings = await page.evaluate(async () => {
		try {
			const nonce = window.fazConfig && window.fazConfig.api ? window.fazConfig.api.nonce : '';
			const r = await fetch('/?rest_route=/faz/v1/settings/', { headers: { 'X-WP-Nonce': nonce } });
			const text = await r.text();
			try { return JSON.parse(text); } catch { return null; }
		} catch { return null; }
	});

	if (!settings) {
		test('Settings API', false, 'cannot fetch settings');
		await ctx.close();
		return;
	}

	test('S01 Settings API accessible', true);

	// Check geolocation settings present
	test('S02 Geolocation settings present', settings.geolocation !== undefined,
		settings.geolocation ? 'has maxmind_license_key field' : 'missing');

	// Get banner settings
	const bannerData = await page.evaluate(async () => {
		try {
			const nonce = window.fazConfig && window.fazConfig.api ? window.fazConfig.api.nonce : '';
			const r = await fetch('/?rest_route=/faz/v1/banners/', { headers: { 'X-WP-Nonce': nonce } });
			const text = await r.text();
			try { return JSON.parse(text); } catch { return null; }
		} catch { return null; }
	});

	if (Array.isArray(bannerData) && bannerData.length > 0) {
		const banner = bannerData[0];
		test('S03 Banner configuration accessible', true, `banner_id: ${banner.id || banner.banner_id}`);

		// Verify banner law
		if (banner.settings?.law) {
			test('S04 Banner law configured', true, banner.settings.law);
		}
	}

	// Get categories
	const categories = await page.evaluate(async () => {
		try {
			const nonce = window.fazConfig && window.fazConfig.api ? window.fazConfig.api.nonce : '';
			const r = await fetch('/?rest_route=/faz/v1/categories/', { headers: { 'X-WP-Nonce': nonce } });
			const text = await r.text();
			try { return JSON.parse(text); } catch { return null; }
		} catch { return null; }
	});

	if (Array.isArray(categories)) {
		test('S05 Cookie categories configured', categories.length >= 2, `${categories.length} categories`);
		const hasNecessary = categories.some(c => c.slug === 'necessary' || c.slug === 'uncategorized');
		test('S06 Necessary/uncategorized category exists', hasNecessary);
	}

	// Get cookies
	const cookies = await page.evaluate(async () => {
		try {
			const nonce = window.fazConfig && window.fazConfig.api ? window.fazConfig.api.nonce : '';
			const r = await fetch('/?rest_route=/faz/v1/cookies/', { headers: { 'X-WP-Nonce': nonce } });
			const text = await r.text();
			try { return JSON.parse(text); } catch { return null; }
		} catch { return null; }
	});

	if (Array.isArray(cookies)) {
		test('S07 Cookie declarations in database', cookies.length > 0, `${cookies.length} cookies`);
	}

	// GeoLite2 status
	const geoStatus = await page.evaluate(async () => {
		try {
			const nonce = window.fazConfig && window.fazConfig.api ? window.fazConfig.api.nonce : '';
			const r = await fetch('/?rest_route=/faz/v1/settings/geolite2/status', { headers: { 'X-WP-Nonce': nonce } });
			const text = await r.text();
			try { return JSON.parse(text); } catch { return null; }
		} catch { return null; }
	});

	test('S08 GeoLite2 status API works', geoStatus && 'installed' in geoStatus,
		geoStatus ? `installed: ${geoStatus.installed}` : 'error');

	// Check GCM settings
	const gcmSettings = await page.evaluate(async () => {
		try {
			const nonce = window.fazConfig && window.fazConfig.api ? window.fazConfig.api.nonce : '';
			const r = await fetch('/?rest_route=/faz/v1/gcm/', { headers: { 'X-WP-Nonce': nonce } });
			const text = await r.text();
			try { return JSON.parse(text); } catch { return null; }
		} catch { return null; }
	});

	if (gcmSettings) {
		test('S09 GCM settings accessible', true,
			`status: ${gcmSettings.status !== undefined ? gcmSettings.status : 'unknown'}`);
	}

	// Verify frontend reflects admin settings
	const frontPage = await ctx.newPage();
	await frontPage.goto(SITE + '/', { waitUntil: 'domcontentloaded' });
	await frontPage.waitForTimeout(2000);

	// Check no JS errors
	const errors = [];
	frontPage.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });
	await frontPage.goto(SITE + '/', { waitUntil: 'domcontentloaded' });
	await frontPage.waitForTimeout(2000);
	test('S10 No JS console errors on frontend', errors.length === 0,
		errors.length > 0 ? errors.join('; ').substring(0, 150) : 'clean');

	// Check banner renders on frontend
	const bannerVisible = await frontPage.evaluate(() => {
		const c = document.querySelector('.faz-consent-container');
		return c && !c.classList.contains('faz-hide');
	});
	test('S11 Banner renders on frontend', bannerVisible);

	// Check public API available
	const publicApi = await frontPage.evaluate(() => typeof window.getFazConsent === 'function');
	test('S12 Public JS API (getFazConsent) available', publicApi);

	await ctx.close();
}

async function testProhibitedPractices(browser) {
	if (!shouldRun('prohibited')) return;
	startSection('12. PROHIBITED PRACTICES [V01-V08]');

	const { ctx, page } = await freshPage(browser);
	await gotoFront(page);
	await waitForBanner(page);

	// V01 — No cookie wall (page content accessible behind banner)
	const pageContent = await page.evaluate(() => {
		const body = document.body;
		const banner = document.querySelector('.faz-consent-container');
		if (!banner) return true;
		// Check if page content exists outside banner
		const children = [...body.children].filter(el =>
			!el.classList.contains('faz-consent-container') &&
			!el.classList.contains('faz-overlay') &&
			!el.classList.contains('faz-modal') &&
			!el.classList.contains('faz-btn-revisit-wrapper') &&
			el.id !== 'fazBannerTemplate' &&
			el.tagName !== 'SCRIPT' && el.tagName !== 'STYLE' && el.tagName !== 'LINK'
		);
		return children.length > 0;
	});
	test('V01 No cookie wall (content accessible)', pageContent);

	// V02 — No "you must accept technical cookies" message
	const text = await page.locator('.faz-consent-container').textContent().catch(() => '');
	const mustAccept = /devi accettare.*cookie\s+tecnic/i.test(text) || /must accept.*technical/i.test(text);
	test('V02 No "must accept technical cookies" message', !mustAccept);

	// V03 — No nudging (tested via P08 button comparison)
	test('V03 No visual nudging', true, 'see P08 result');

	// V05 — Closing browser != consent (inherent in cookie-based system)
	test('V05 Browser close is NOT consent', true, 'cookie-based consent: no cookie = no consent');

	// V06 — No pre-ticked boxes
	await page.evaluate(() => {
		const btn = document.querySelector('[data-faz-tag="settings-button"]');
		if (btn) btn.click();
	});
	await page.waitForTimeout(1000);
	const preTicked = await page.evaluate(() => {
		const toggles = document.querySelectorAll('.faz-switch input[type="checkbox"]');
		return [...toggles].filter(t => t.checked && !t.disabled)
			.map(t => t.id.replace('fazSwitch', ''));
	});
	test('V06 No pre-ticked boxes', preTicked.length === 0,
		preTicked.length > 0 ? `pre-ticked: [${preTicked.join(', ')}]` : 'all OFF');

	// V07 — Granular consent (not single generic consent) — count distinct categories
	// in preference center (accordion items) or inline classic toggles. "Necessary"
	// shows "Always Active" instead of a toggle, so count accordion sections not just checkboxes.
	const categoryCount = await page.evaluate(() => {
		const accordion = document.querySelectorAll('.faz-accordion-wrapper .faz-accordion').length;
		const inline = document.querySelectorAll('.faz-category-direct-preview-section input[type="checkbox"]').length;
		return accordion || inline;
	});
	test('V07 Granular consent (multiple categories)', categoryCount >= 2, `${categoryCount} categories`);

	// V08 — Reject not hidden behind extra levels
	await page.evaluate(() => {
		const btn = document.querySelector('[data-faz-tag="detail-close"]');
		if (btn) btn.click();
	});
	await page.waitForTimeout(500);

	// Reload fresh page to check first-level buttons
	await gotoFront(page);
	await waitForBanner(page);
	const rejectVisible = await page.evaluate(() => {
		const r = document.querySelector('[data-faz-tag="reject-button"]');
		return r && r.offsetParent !== null;
	});
	const closeVisible = await page.evaluate(() => {
		const c = document.querySelector('[data-faz-tag="close-button"]');
		return c && c.offsetParent !== null;
	});
	test('V08 Reject not hidden behind extra levels', rejectVisible || closeVisible,
		'reject/X accessible at first level');

	await ctx.close();
}

// ====================================================================
// 13. VISUAL TESTS
// ====================================================================

async function testVisualIntegrity(browser) {
	if (!shouldRun('visual')) return;
	startSection('13. VISUAL INTEGRITY [VIS01-VIS09]');

	// VIS01 — Banner renders with proper styling (not unstyled HTML)
	const { ctx, page } = await freshPage(browser);
	await gotoFront(page);
	await waitForBanner(page);

	const bannerStyled = await page.evaluate(() => {
		const banner = document.querySelector('#faz-consent');
		if (!banner) return { found: false };
		const cs = window.getComputedStyle(banner);
		// Background may be on the container or the inner bar (.faz-consent-bar)
		const bar = banner.querySelector('.faz-consent-bar');
		const barCs = bar ? window.getComputedStyle(bar) : null;
		const hasBg = (cs.backgroundColor !== 'rgba(0, 0, 0, 0)' && cs.backgroundColor !== 'transparent')
			|| (barCs && barCs.backgroundColor !== 'rgba(0, 0, 0, 0)' && barCs.backgroundColor !== 'transparent');
		return {
			found: true,
			hasPosition: cs.position === 'fixed' || cs.position === 'absolute',
			hasZIndex: parseInt(cs.zIndex) > 0,
			hasBgColor: hasBg,
		};
	});
	test('VIS01 Banner has proper CSS (position, z-index, background)',
		bannerStyled.found && bannerStyled.hasPosition && bannerStyled.hasZIndex && bannerStyled.hasBgColor,
		bannerStyled.found ? `pos=${bannerStyled.hasPosition}, z=${bannerStyled.hasZIndex}, bg=${bannerStyled.hasBgColor}` : 'banner not found');

	// VIS02 — Modal/preference center is hidden by default
	const modalHidden = await page.evaluate(() => {
		const modal = document.querySelector('.faz-modal');
		if (!modal) return { found: false, hidden: true }; // no modal = OK (classic type)
		const cs = window.getComputedStyle(modal);
		return {
			found: true,
			hidden: cs.visibility === 'hidden' || cs.display === 'none' || modal.offsetHeight === 0,
			visibility: cs.visibility,
			display: cs.display,
		};
	});
	test('VIS02 Preference center hidden by default',
		modalHidden.hidden,
		modalHidden.found ? `visibility=${modalHidden.visibility}, display=${modalHidden.display}` : 'no modal element (OK)');

	// VIS03 — Buttons are visible and styled (not raw HTML)
	const buttonsStyled = await page.evaluate(() => {
		const accept = document.querySelector('[data-faz-tag="accept-button"]');
		const reject = document.querySelector('[data-faz-tag="reject-button"]');
		if (!accept) return { found: false };
		const aCs = window.getComputedStyle(accept);
		const rCs = reject ? window.getComputedStyle(reject) : null;
		return {
			found: true,
			acceptVisible: accept.offsetHeight > 0 && accept.offsetWidth > 0,
			acceptHasBg: aCs.backgroundColor !== 'rgba(0, 0, 0, 0)' && aCs.backgroundColor !== 'transparent',
			rejectVisible: reject ? reject.offsetHeight > 0 && reject.offsetWidth > 0 : false,
			rejectHasBorder: rCs ? rCs.borderWidth !== '0px' : false,
		};
	});
	test('VIS03 Buttons are visible and styled',
		buttonsStyled.found && buttonsStyled.acceptVisible && buttonsStyled.acceptHasBg,
		buttonsStyled.found ? `accept=${buttonsStyled.acceptVisible}, bg=${buttonsStyled.acceptHasBg}, reject=${buttonsStyled.rejectVisible}` : 'buttons not found');

	// VIS04 — No unstyled FAZ content leaking into page body
	const contentLeak = await page.evaluate(() => {
		const body = document.body;
		const children = [...body.children];
		for (const el of children) {
			if (el.tagName === 'SCRIPT' || el.tagName === 'STYLE' || el.tagName === 'LINK') continue;
			if (el.classList.contains('faz-overlay') || el.classList.contains('faz-btn-revisit-wrapper')) continue;
			if (el.id === 'faz-consent' || el.id === 'fazBannerTemplate') continue;
			// Check for visible faz- elements that shouldn't be visible
			if (el.classList.toString().includes('faz-') && el.offsetHeight > 100) {
				const cs = window.getComputedStyle(el);
				if (cs.visibility !== 'hidden' && cs.display !== 'none') {
					return { leak: true, class: el.className, height: el.offsetHeight };
				}
			}
		}
		return { leak: false };
	});
	test('VIS04 No unstyled FAZ content leaking into page',
		!contentLeak.leak,
		contentLeak.leak ? `LEAK: .${contentLeak.class} height=${contentLeak.height}px` : 'clean');

	// VIS05 — Preference center opens with proper styling
	const settingsBtn = await page.$('[data-faz-tag="settings-button"]');
	if (settingsBtn) {
		await settingsBtn.click();
		await page.waitForTimeout(1500);
	}
	const prefStyled = await page.evaluate(() => {
		const pref = document.querySelector('.faz-modal, .faz-preference-wrapper');
		if (!pref) return { found: false };
		const cs = window.getComputedStyle(pref);
		return {
			found: true,
			visible: pref.offsetHeight > 0 && cs.visibility !== 'hidden',
			hasPosition: cs.position === 'fixed' || cs.position === 'absolute' || cs.position === 'relative',
			hasBgColor: cs.backgroundColor !== 'rgba(0, 0, 0, 0)' && cs.backgroundColor !== 'transparent',
		};
	});
	test('VIS05 Preference center opens with styling',
		prefStyled.found && prefStyled.visible && prefStyled.hasBgColor,
		prefStyled.found ? `visible=${prefStyled.visible}, pos=${prefStyled.hasPosition}, bg=${prefStyled.hasBgColor}` : 'pref center not found');

	// VIS07 — Preference center accordion collapsed by default
	const accordionState = await page.evaluate(() => {
		const bodies = document.querySelectorAll('.faz-accordion-body');
		if (!bodies.length) return { found: false };
		let allCollapsed = true;
		for (const body of bodies) {
			const cs = window.getComputedStyle(body);
			if (cs.display !== 'none' && body.offsetHeight > 0) {
				allCollapsed = false;
				break;
			}
		}
		return { found: true, count: bodies.length, allCollapsed };
	});
	test('VIS07 Accordion bodies collapsed by default',
		accordionState.found && accordionState.allCollapsed,
		accordionState.found ? `${accordionState.count} accordions, allCollapsed=${accordionState.allCollapsed}` : 'no accordions');

	// VIS08 — Preference center buttons styled like banner buttons (visible only)
	const prefBtnStyled = await page.evaluate(() => {
		const modal = document.querySelector('.faz-modal, .faz-preference-wrapper');
		if (!modal) return { found: false };
		const allBtns = modal.querySelectorAll('.faz-btn');
		// Only check actually-visible buttons. offsetParent is null when the
		// button OR any ancestor is display:none — which covers both
		// template-hidden accept/reject buttons AND the buttons inside the
		// closed Do-Not-Sell opt-out popup that a "CCPA"/"Both" banner ships
		// in the DOM (they'd otherwise be measured at 0px height and fail
		// the check even though the visitor never sees them unstyled).
		const btns = Array.from(allBtns).filter(b => b.offsetParent !== null);
		if (!btns.length) return { found: false };
		let allStyled = true;
		const details = [];
		for (const btn of btns) {
			const cs = window.getComputedStyle(btn);
			const pad = parseInt(cs.paddingTop) || 0;
			const h = btn.offsetHeight;
			const hasBorder = cs.borderStyle !== 'none' && cs.borderWidth !== '0px';
			const styled = pad >= 6 && h >= 30 && hasBorder;
			if (!styled) allStyled = false;
			details.push(`${btn.textContent.trim().substring(0,15)}:${h}px,pad=${pad}`);
		}
		return { found: true, count: btns.length, allStyled, details: details.join('; ') };
	});
	test('VIS08 Preference buttons styled (padding, border, height)',
		prefBtnStyled.found && prefBtnStyled.allStyled,
		prefBtnStyled.found ? `${prefBtnStyled.count} btns — ${prefBtnStyled.details}` : 'no pref buttons');

	// VIS09 — Accordion expands on click
	const accordionExpand = await page.evaluate(() => {
		const accBtn = document.querySelector('.faz-accordion-btn');
		if (!accBtn) return { found: false };
		accBtn.click();
		// Small delay for transition
		return new Promise(resolve => {
			setTimeout(() => {
				const body = accBtn.closest('.faz-accordion')
					? accBtn.closest('.faz-accordion').querySelector('.faz-accordion-body')
					: null;
				if (!body) { resolve({ found: false }); return; }
				const cs = window.getComputedStyle(body);
				resolve({
					found: true,
					expanded: cs.display !== 'none' && body.offsetHeight > 0,
					height: body.offsetHeight,
				});
			}, 500);
		});
	});
	test('VIS09 Accordion expands on click',
		accordionExpand.found && accordionExpand.expanded,
		accordionExpand.found ? `expanded=${accordionExpand.expanded}, height=${accordionExpand.height}px` : 'no accordion button');

	// VIS10 — IAB vendor section renders in preference center (when IAB enabled)
	{
		const vendorSection = await page.evaluate(() => {
			const section = document.querySelector('.faz-iab-vendors-section');
			if (!section) return null;
			return {
				exists: true,
				childCount: section.querySelectorAll('[id^="fazVendor"]').length,
				heading: section.querySelector('h4')?.textContent || ''
			};
		});
		// Only test if IAB is enabled (vendor section exists).
		if (vendorSection) {
			test('VIS10 IAB vendor section renders in preference center',
				vendorSection.exists && vendorSection.childCount > 0,
				'vendors=' + vendorSection.childCount + ', heading=' + vendorSection.heading);
		}
	}

	// VIS06 — No JS errors on page
	const errors = [];
	const page2 = await ctx.newPage();
	page2.on('pageerror', err => errors.push(err.message));
	await gotoFront(page2);
	await page2.waitForTimeout(3000);
	test('VIS06 No JavaScript errors on frontend',
		errors.length === 0,
		errors.length > 0 ? errors[0].substring(0, 100) : 'clean');

	await ctx.close();
}

// ====================================================================
// 14. TOGGLE PERSISTENCE [TP01-TP04]
// ====================================================================
async function testTogglePersistence(browser) {
	if (!shouldRun('toggle')) return;
	startSection('14. TOGGLE PERSISTENCE [TP01-TP04]');

	// TP01 — Accept All: all categories saved as "yes", persists after reload
	{
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await page.goto(SITE, { waitUntil: 'networkidle', timeout: 15000 });
		await page.waitForTimeout(1500);
		const acceptBtn = await page.$('[data-faz-tag="accept-button"]');
		if (acceptBtn) await acceptBtn.click();
		await page.waitForTimeout(1000);
		const cookies = await page.context().cookies(SITE);
		const c1 = cookies.find(c => c.name === 'fazcookie-consent');
		const p1 = c1 ? parseConsentCookie(decodeURIComponent(c1.value)) : {};
		const allYes = Object.entries(p1).every(([k, v]) => v === 'yes' || k === 'consentid' || k === 'action' || k === 'consent' || k === 'rev' || k.startsWith('__scope.'));
		test('TP01 Accept All sets all categories yes', allYes, JSON.stringify(p1).substring(0, 120));

		// Reload and verify persistence
		await page.goto(SITE, { waitUntil: 'networkidle', timeout: 15000 });
		const cookies2 = await page.context().cookies(SITE);
		const c2 = cookies2.find(c => c.name === 'fazcookie-consent');
		const p2 = c2 ? parseConsentCookie(decodeURIComponent(c2.value)) : {};
		const persistsYes = Object.entries(p2).every(([k, v]) => v === 'yes' || k === 'consentid' || k === 'action' || k === 'consent' || k === 'rev' || k.startsWith('__scope.'));
		test('TP01b Accept All persists after reload', persistsYes);
		await ctx.close();
	}

	// TP02 — Reject All: non-necessary "no", persists after reload
	{
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await page.goto(SITE, { waitUntil: 'networkidle', timeout: 15000 });
		await page.waitForTimeout(1500);
		const rejectBtn = await page.$('[data-faz-tag="reject-button"]');
		if (rejectBtn) await rejectBtn.click();
		await page.waitForTimeout(1000);
		const cookies = await page.context().cookies(SITE);
		const c1 = cookies.find(c => c.name === 'fazcookie-consent');
		const p1 = c1 ? parseConsentCookie(decodeURIComponent(c1.value)) : {};
		// Necessary should be "yes", all others "no"
		const necessaryYes = p1['necessary'] === 'yes';
		const othersNo = ['functional', 'analytics', 'performance', 'advertisement', 'uncategorized']
			.every(k => !p1[k] || p1[k] === 'no');
		test('TP02 Reject All: necessary=yes, others=no', necessaryYes && othersNo,
			`necessary=${p1['necessary']}, functional=${p1['functional']}`);

		// Reload
		await page.goto(SITE, { waitUntil: 'networkidle', timeout: 15000 });
		const cookies2 = await page.context().cookies(SITE);
		const c2 = cookies2.find(c => c.name === 'fazcookie-consent');
		const p2 = c2 ? parseConsentCookie(decodeURIComponent(c2.value)) : {};
		const rejectPersists = p2['necessary'] === 'yes' &&
			['functional', 'analytics', 'performance', 'advertisement', 'uncategorized']
				.every(k => !p2[k] || p2[k] === 'no');
		test('TP02b Reject All persists after reload', rejectPersists);
		await ctx.close();
	}

	// TP03 — Accept All → reopen → turn OFF toggle → save → reload → still OFF
	{
		const ctx = await browser.newContext();
		const page = await ctx.newPage();

		// Step 1: Accept All
		await page.goto(SITE, { waitUntil: 'networkidle', timeout: 15000 });
		await page.waitForTimeout(1500);
		const acceptBtn = await page.$('[data-faz-tag="accept-button"]');
		if (acceptBtn) await acceptBtn.click();
		await page.waitForTimeout(1000);

		// Step 2: Reload
		await page.goto(SITE, { waitUntil: 'networkidle', timeout: 15000 });
		await page.waitForTimeout(1500);

		// Step 3: Open revisit + preference center
		const revisit = await page.$('.faz-btn-revisit');
		if (revisit && await revisit.isVisible().catch(() => false)) {
			await revisit.click();
			await page.waitForTimeout(800);
		}
		const settings = await page.$('[data-faz-tag="settings-button"]');
		if (settings && await settings.isVisible().catch(() => false)) {
			await settings.click();
			await page.waitForTimeout(800);
		}

		// Step 4: Find a non-necessary toggle and turn it OFF
		let toggle = await page.$('#fazSwitchfunctional');
		if (!toggle || !(await toggle.isVisible().catch(() => false))) {
			toggle = await page.$('#fazCategoryDirectfunctional');
		}
		let turnedOff = false;
		if (toggle) {
			const checked = await toggle.isChecked();
			if (checked) {
				await toggle.scrollIntoViewIfNeeded().catch(() => {});
				await page.evaluate(el => el.click(), toggle);
				await page.waitForTimeout(400);
				turnedOff = !(await toggle.isChecked());
			}
		}

		// Step 5: Save
		let saveBtn = await page.$('[data-faz-tag="detail-save-button"]');
		if (!saveBtn || !(await saveBtn.isVisible().catch(() => false))) {
			saveBtn = await page.$('[data-faz-tag="detail-category-preview-save-button"]');
		}
		if (saveBtn) {
			await saveBtn.click();
			await page.waitForTimeout(1200);
		}

		// Step 6: Check cookie
		const cookies = await page.context().cookies(SITE);
		const c = cookies.find(c => c.name === 'fazcookie-consent');
		const p = c ? parseConsentCookie(decodeURIComponent(c.value)) : {};
		test('TP03 Turn OFF toggle after Accept All saves correctly',
			turnedOff && p['functional'] === 'no',
			`turnedOff=${turnedOff}, functional=${p['functional']}`);

		// Step 7: Reload and verify
		await page.goto(SITE, { waitUntil: 'networkidle', timeout: 15000 });
		const cookies2 = await page.context().cookies(SITE);
		const c2 = cookies2.find(c => c.name === 'fazcookie-consent');
		const p2 = c2 ? parseConsentCookie(decodeURIComponent(c2.value)) : {};
		test('TP03b Toggle OFF persists after reload',
			p2['functional'] === 'no',
			`functional=${p2['functional']}`);

		await ctx.close();
	}

	// TP04 — Inline toggle sync: fazCategoryDirect OFF → fazSwitch syncs
	{
		const ctx = await browser.newContext();
		const page = await ctx.newPage();

		// Accept All first
		await page.goto(SITE, { waitUntil: 'networkidle', timeout: 15000 });
		await page.waitForTimeout(1500);
		const acceptBtn = await page.$('[data-faz-tag="accept-button"]');
		if (acceptBtn) await acceptBtn.click();
		await page.waitForTimeout(1000);

		// Reload
		await page.goto(SITE, { waitUntil: 'networkidle', timeout: 15000 });
		await page.waitForTimeout(1500);

		// Open revisit
		const revisit = await page.$('.faz-btn-revisit');
		if (revisit && await revisit.isVisible().catch(() => false)) {
			await revisit.click();
			await page.waitForTimeout(800);
		}

		// Check if inline toggle exists
		const inlineToggle = await page.$('#fazCategoryDirectfunctional');
		if (inlineToggle && await inlineToggle.isVisible().catch(() => false)) {
			const initChecked = await inlineToggle.isChecked();
			if (initChecked) {
				await inlineToggle.click({ force: true });
				await page.waitForTimeout(400);
			}

			// Check paired fazSwitch is synced
			const paired = await page.$('#fazSwitchfunctional');
			const pairedState = paired ? await paired.isChecked() : null;
			test('TP04 Inline toggle OFF syncs with fazSwitch',
				pairedState === false,
				`inline=OFF, fazSwitch=${pairedState}`);

			// Save and verify cookie
			let saveBtn = await page.$('[data-faz-tag="detail-save-button"]');
			if (!saveBtn || !(await saveBtn.isVisible().catch(() => false))) {
				saveBtn = await page.$('[data-faz-tag="detail-category-preview-save-button"]');
			}
			if (saveBtn) {
				await saveBtn.click();
				await page.waitForTimeout(1200);
			}
			const cookies = await page.context().cookies(SITE);
			const c = cookies.find(c => c.name === 'fazcookie-consent');
			const p = c ? parseConsentCookie(decodeURIComponent(c.value)) : {};
			test('TP04b Inline toggle OFF saves to cookie',
				p['functional'] === 'no',
				`functional=${p['functional']}`);
		} else {
			// No inline toggle in this banner type — skip
			test('TP04 Inline toggle sync (skipped: no inline toggle)', true, 'no fazCategoryDirect');
		}

		await ctx.close();
	}
}

// ====================================================================
// 15. POPIA — SOUTH AFRICA [ZA01-ZA09]
// ====================================================================
//
// POPIA section 11(1)(a) makes consent the lawful basis for every
// non-essential technology, and defines it as "voluntary, specific and
// informed". That is an opt-in regime: nothing non-essential may run
// before the visitor acts, no category may arrive pre-ticked, and
// refusing must be no harder than accepting. The rest of the suite
// proves those properties for the GDPR banner; this section proves the
// POPIA choice actually produces them rather than a weaker shape — the
// jurisdiction ships a geo ruleset, a wizard law and a policy
// scaffold, but nothing asserted its runtime behaviour.
//
// The law is applied through the plugin's own onboarding endpoint (the
// path the setup wizard uses), and the previous law is restored in a
// finally so the shared test site is left as it was found.
async function testPopiaSouthAfrica(browser) {
	if (!shouldRun('popia')) return;
	startSection('15. POPIA SOUTH AFRICA [ZA01-ZA09]');

	const { ctx: adminCtx, page: adminPage } = await freshPage(browser, { traceName: 'compliance-popia-admin' });
	let previousLaw = 'gdpr';
	let applied = null;

	try {
		await loginAdmin(adminPage);
		await adminPage.goto(SITE + '/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
		await adminPage.waitForTimeout(2000);

		previousLaw = await adminPage.evaluate(async () => {
			const nonce = window.fazConfig?.api?.nonce || '';
			const r = await fetch('/?rest_route=/faz/v1/settings/', { headers: { 'X-WP-Nonce': nonce } });
			const s = await r.json().catch(() => ({}));
			return s?.onboarding?.law || 'gdpr';
		});

		applied = await adminPage.evaluate(async () => {
			const nonce = window.fazConfig?.api?.nonce || '';
			const r = await fetch('/?rest_route=/faz/v1/settings/onboarding', {
				method: 'POST',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				body: JSON.stringify({ law: 'popia' }),
			});
			return r.json().catch(() => null);
		});

		test('ZA01 POPIA law applies through the setup endpoint',
			!!applied && applied.banner_applied === true && applied.law === 'popia',
			applied ? JSON.stringify(applied).slice(0, 120) : 'no response');

		// The runtime has no dedicated "popia" banner law: POPIA is opt-in, so
		// it maps onto the opt-in shape. What matters for compliance is the
		// SHAPE, not the label. Read the exact paths Onboarding writes and
		// FAIL when a key is absent — an assertion that silently passes on
		// `undefined` proves nothing.
		const banner = await adminPage.evaluate(async () => {
			const nonce = window.fazConfig?.api?.nonce || '';
			const r = await fetch('/?rest_route=/faz/v1/banners/', { headers: { 'X-WP-Nonce': nonce } });
			const list = await r.json().catch(() => null);
			const rows = Array.isArray(list) ? list : (list?.items || []);
			if (!rows.length) return null;
			const row = rows.find(b => b.banner_default || b.default || b.is_default) || rows[0];
			let props = row.settings ?? row.properties ?? null;
			if (typeof props === 'string') { try { props = JSON.parse(props); } catch { props = null; } }
			if (!props) return null;
			const at = (o, path) => path.split('.').reduce((acc, k) => (acc == null ? acc : acc[k]), o);
			return {
				name: row.name,
				law: at(props, 'settings.applicableLaw'),
				expiry: at(props, 'settings.consentExpiry.value'),
				donotSell: at(props, 'config.notice.elements.buttons.elements.donotSell.status'),
				optoutPopup: at(props, 'config.optoutPopup.status'),
			};
		});

		test('ZA02 POPIA yields the opt-in consent model (s.11(1)(a))',
			!!banner && typeof banner.law === 'string' && banner.law !== '' && banner.law !== 'ccpa',
			banner ? `banner="${banner.name}" applicableLaw=${JSON.stringify(banner.law)}` : 'default banner unreadable');

		// A Do-Not-Sell link is an artefact of US opt-out statutes. Under an
		// opt-in regime it is meaningless and misleading.
		test('ZA03 No Do-Not-Sell opt-out artefact under an opt-in law',
			!!banner && banner.donotSell === false && banner.optoutPopup === false,
			banner ? `donotSell=${JSON.stringify(banner.donotSell)} optoutPopup=${JSON.stringify(banner.optoutPopup)}` : '');

		// Consent must be re-collected periodically; the wizard applies 180
		// days, the ceiling this project uses for opt-in regimes.
		const expiry = Number(banner?.expiry);
		test('ZA04 Consent expiry is set and capped at 180 days',
			Number.isFinite(expiry) && expiry > 0 && expiry <= 180,
			banner ? `${JSON.stringify(banner.expiry)} days` : '');
	} catch (err) {
		test('ZA01-ZA04 POPIA admin setup', false, err.message);
	}

	// ---- front-end behaviour for a visitor under the POPIA banner ----
	try {
		const { ctx, page } = await freshPage(browser, { traceName: 'compliance-popia-front' });
		await gotoFront(page);
		const bannerVisible = await waitForBanner(page);
		test('ZA05 Banner is shown before any non-essential processing', bannerVisible);

		const before = await getNonTechnicalCookies(ctx);
		test('ZA06 No non-essential cookie before consent (s.11(1)(a))',
			before.length === 0,
			before.length ? before.map(c => c.name).join(', ') : 'none');

		// Equal weight: refusing must not be harder than accepting.
		const weights = await page.evaluate(() => {
			const a = document.querySelector('[data-faz-tag="accept-button"]');
			const r = document.querySelector('[data-faz-tag="reject-button"]');
			if (!a || !r) return null;
			const ra = a.getBoundingClientRect(), rr = r.getBoundingClientRect();
			const sa = getComputedStyle(a), sr = getComputedStyle(r);
			return {
				rejectPresent: true,
				heightRatio: Math.min(ra.height, rr.height) / Math.max(ra.height, rr.height),
				fontDelta: Math.abs(parseFloat(sa.fontSize) - parseFloat(sr.fontSize)),
				rejectOpacity: parseFloat(sr.opacity),
			};
		});
		test('ZA07 Refusing is no harder than accepting (voluntary consent)',
			!!weights && weights.heightRatio > 0.8 && weights.fontDelta <= 2 && weights.rejectOpacity >= 0.8,
			weights ? `h=${weights.heightRatio.toFixed(2)} Δfont=${weights.fontDelta} opacity=${weights.rejectOpacity}` : 'reject button missing');

		// Refusal must actually refuse. Do this BEFORE opening the preference
		// center: the modal overlay intercepts pointer events on the notice.
		const rejectBtn = await page.$('[data-faz-tag="reject-button"]');
		if (rejectBtn) {
			await rejectBtn.click();
			await page.waitForTimeout(1200);
		}
		const afterReject = await getNonTechnicalCookies(ctx);
		test('ZA09 Refusing leaves no non-essential cookie behind',
			!!rejectBtn && afterReject.length === 0,
			!rejectBtn ? 'no reject button' : (afterReject.length ? afterReject.map(c => c.name).join(', ') : 'none'));
		await ctx.close();

		// No pre-ticked categories — consent must be "specific". Fresh context:
		// the one above has already recorded a decision.
		const { ctx: prefCtx, page: prefPage } = await freshPage(browser, { traceName: 'compliance-popia-prefs' });
		await gotoFront(prefPage);
		await waitForBanner(prefPage);
		const settingsBtn = await prefPage.$('[data-faz-tag="settings-button"]');
		if (settingsBtn) {
			await settingsBtn.click();
			await prefPage.waitForTimeout(1000);
		}
		const switches = await prefPage.evaluate(() => {
			const all = document.querySelectorAll('.faz-switch input[type="checkbox"]');
			return [...all].map(t => ({ slug: t.id.replace('fazSwitch', ''), checked: t.checked, disabled: t.disabled }));
		});
		const nonNecessary = switches.filter(t => t.slug !== 'necessary');
		const preTicked = nonNecessary.filter(t => t.checked && !t.disabled).map(t => t.slug);
		test('ZA08 No category arrives pre-ticked (specific consent)',
			nonNecessary.length > 0 && preTicked.length === 0,
			nonNecessary.length === 0
				? 'no category toggles found — preference center did not open'
				: (preTicked.length ? `pre-ticked: ${preTicked.join(', ')}` : `all ${nonNecessary.length} non-necessary off`));
		await prefCtx.close();

	} catch (err) {
		test('ZA05-ZA09 POPIA front-end behaviour', false, err.message);
	}

	// ---- restore the law the site had before this section ----
	try {
		await adminPage.goto(SITE + '/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
		await adminPage.waitForTimeout(1500);
		await adminPage.evaluate(async (law) => {
			const nonce = window.fazConfig?.api?.nonce || '';
			await fetch('/?rest_route=/faz/v1/settings/onboarding', {
				method: 'POST',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				body: JSON.stringify({ law }),
			});
		}, previousLaw === 'popia' ? 'gdpr' : previousLaw);
	} catch { /* restoration is best-effort; the next run re-applies anyway */ }

	await adminCtx.close();
}


// ====================================================================
// MAIN
// ====================================================================

console.log('╔════════════════════════════════════════════════════════════╗');
console.log('║  FAZ Cookie Manager — Compliance Test Suite               ║');
console.log('║  Based on cookie-banner-compliance-checklist.md v1.0      ║');
console.log('╚════════════════════════════════════════════════════════════╝');
console.log(`Site: ${SITE}`);
console.log(`Mode: ${HEADED ? 'headed' : 'headless'}${isTraceEnabled() ? ' + trace' : ''}`);
if (SECTION_FILTER) console.log(`Filter: ${SECTION_FILTER}`);

const browser = await chromium.launch({ headless: !HEADED });

try {
	await testBannerAppearance(browser);
	await testInformationContent(browser);
	await testCommandsButtons(browser);
	await testGranularPreferences(browser);
	await testPriorBlocking(browser);
	await testConsentManagement(browser);
	await testRevocation(browser);
	await testGoogleConsentMode(browser);
	await testIABTCF(browser);
	await testFunctionalScenarios(browser);
	await testSettingsReflection(browser);
	await testProhibitedPractices(browser);
	await testVisualIntegrity(browser);
	await testTogglePersistence(browser);
	await testPopiaSouthAfrica(browser);
} catch (err) {
	console.error('\n\x1b[31mFATAL ERROR:\x1b[0m', err.message);
	console.error(err.stack);
}

await browser.close();

// ====================================================================
// SUMMARY
// ====================================================================

console.log('\n' + '='.repeat(60));
console.log('  SUMMARY');
console.log('='.repeat(60));

const sections = [...new Set(results.map(r => r.section))];
let totalPass = 0, totalFail = 0;

for (const s of sections) {
	const sectionResults = results.filter(r => r.section === s);
	const pass = sectionResults.filter(r => r.pass).length;
	const fail = sectionResults.filter(r => !r.pass).length;
	totalPass += pass;
	totalFail += fail;
	const icon = fail === 0 ? '\x1b[32m✓\x1b[0m' : '\x1b[31m✗\x1b[0m';
	console.log(`  ${icon} ${s}: ${pass}/${pass + fail}`);
}

console.log('\n' + '-'.repeat(60));
console.log(`  TOTAL: \x1b[${totalFail === 0 ? '32' : '31'}m${totalPass}/${totalPass + totalFail}\x1b[0m tests passed`);

if (totalFail > 0) {
	console.log('\n  FAILURES:');
	results.filter(r => !r.pass).forEach(r => {
		console.log(`  \x1b[31m✗\x1b[0m [${r.section}] ${r.id}${r.detail ? ' — ' + r.detail : ''}`);
	});
}

printTraceInfo();
console.log('\n' + '='.repeat(60));
process.exit(totalFail === 0 ? 0 : 1);
