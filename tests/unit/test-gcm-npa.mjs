#!/usr/bin/env node
/**
 * Unit tests for the non-personalized-ads (`npa`) most-restrictive logic in
 * frontend/js/gcm.js (issue #8 / CodeRabbit: gtag('set',{npa}) is GLOBAL and
 * cannot be region-scoped, so the pre-consent default stage must emit ONE npa
 * based on the most-restrictive ad stance across all region rows — not let the
 * last-iterated row win for everyone).
 *
 * gcm.js is a self-contained IIFE that only reads window._fazGcm /
 * document.cookie and pushes to window[dataLayerName]; we run it in a node:vm
 * sandbox with mocked globals and inspect the resulting dataLayer. No WP, no
 * browser, no geo — this hits exactly the changed code, deterministically.
 *
 * Run: node tests/unit/test-gcm-npa.mjs
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const __dirname = dirname(fileURLToPath(import.meta.url));
const GCM_SRC = readFileSync(join(__dirname, '..', '..', 'frontend', 'js', 'gcm.js'), 'utf8');

let passed = 0;
let failed = 0;
function assert(cond, msg) {
	if (cond) { passed++; console.log(`  ✓ ${msg}`); }
	else { failed++; console.log(`  ✗ ${msg}`); }
}

/**
 * Run gcm.js against a given _fazGcm config + cookie, return the dataLayer.
 */
function runGcm({ gcm, cookie = '' }) {
	const dataLayer = [];
	const sandbox = {
		window: {
			_fazGcm: gcm,
			_fazConfig: {},
			fazSettings: { dataLayerName: 'dataLayer' },
			dataLayer,
			addEventListener() {},
		},
		document: { cookie, addEventListener() {} },
		console,
	};
	vm.createContext(sandbox);
	vm.runInContext(GCM_SRC, sandbox);
	return sandbox.window.dataLayer;
}

/** All `gtag('set', { npa })` entries in the dataLayer. */
function npaSets(dl) {
	return dl.filter((e) => e && e[0] === 'set' && e[1] && typeof e[1] === 'object' && 'npa' in e[1])
		.map((e) => e[1].npa);
}

/** All `gtag('consent', 'default', …)` payloads. */
function consentDefaults(dl) {
	return dl.filter((e) => e && e[0] === 'consent' && e[1] === 'default').map((e) => e[2]);
}

const GRANTED_ROW = (regions, marketing) => ({
	regions,
	necessary: 'granted',
	analytics: 'denied',
	functional: 'denied',
	marketing,
	ad_user_data: marketing === 'granted' ? 'granted' : 'denied',
	ad_personalization: marketing === 'granted' ? 'granted' : 'denied',
});

console.log('GCM npa most-restrictive — frontend/js/gcm.js\n');

// 1) Single "all" row, marketing denied, fallback ON → npa:1 (typical GDPR).
{
	const dl = runGcm({ gcm: {
		non_personalized_ads_fallback: true,
		default_settings: [GRANTED_ROW('all', 'denied')],
	}});
	const npa = npaSets(dl);
	console.log('Test 1 — single "all" row, marketing denied, fallback ON');
	assert(npa.length === 1 && npa[0] === 1, `exactly one npa set, value 1 (got ${JSON.stringify(npa)})`);
}

// 2) Single "all" row, marketing GRANTED, fallback ON → npa:0 (ads-on install).
{
	const dl = runGcm({ gcm: {
		non_personalized_ads_fallback: true,
		default_settings: [GRANTED_ROW('all', 'granted')],
	}});
	const npa = npaSets(dl);
	console.log('Test 2 — single "all" row, marketing granted, fallback ON');
	assert(npa.length === 1 && npa[0] === 0, `exactly one npa set, value 0 (got ${JSON.stringify(npa)})`);
}

// 3) Two rows EEA(denied) THEN US(granted), fallback ON → npa:1 (most-restrictive).
//    Regression: the OLD per-row code let the LAST row (US granted) win → npa:0.
{
	const dl = runGcm({ gcm: {
		non_personalized_ads_fallback: true,
		default_settings: [GRANTED_ROW('DE,FR', 'denied'), GRANTED_ROW('US', 'granted')],
	}});
	const npa = npaSets(dl);
	console.log('Test 3 — rows [EEA denied, US granted], fallback ON (US last)');
	assert(npa.length === 1 && npa[0] === 1, `single npa set = 1, NOT 0 from last row (got ${JSON.stringify(npa)})`);
	// GCM v2 region-scoped defaults must remain intact + region-targeted.
	const regioned = consentDefaults(dl).filter((p) => Array.isArray(p && p.region));
	assert(regioned.length === 2, `both region-scoped consent defaults still emitted (got ${regioned.length})`);
}

// 4) Reverse order US(granted) THEN EEA(denied), fallback ON → still npa:1.
//    Proves order-independence (not last-row-wins).
{
	const dl = runGcm({ gcm: {
		non_personalized_ads_fallback: true,
		default_settings: [GRANTED_ROW('US', 'granted'), GRANTED_ROW('DE,FR', 'denied')],
	}});
	const npa = npaSets(dl);
	console.log('Test 4 — rows [US granted, EEA denied], fallback ON (order reversed)');
	assert(npa.length === 1 && npa[0] === 1, `single npa set = 1, order-independent (got ${JSON.stringify(npa)})`);
}

// 5) Fallback OFF → no npa set entry at all (feature is opt-in, must not leak).
{
	const dl = runGcm({ gcm: {
		non_personalized_ads_fallback: false,
		default_settings: [GRANTED_ROW('all', 'denied')],
	}});
	const npa = npaSets(dl);
	console.log('Test 5 — fallback OFF → no npa signal emitted');
	assert(npa.length === 0, `zero npa set entries when fallback disabled (got ${JSON.stringify(npa)})`);
}

console.log(`\n${failed === 0 ? '✅' : '❌'} GCM npa: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
