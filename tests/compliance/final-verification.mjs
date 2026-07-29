/**
 * FAZ Cookie Manager — Final Verification Suite (V01-V14)
 *
 * Tests admin pages, REST APIs, banner rendering, and system integrity.
 * Run: node final-verification.mjs
 *      node final-verification.mjs --trace    # record Playwright traces
 *      node final-verification.mjs --site=http://127.0.0.1:9998
 */
import { chromium } from 'playwright';
import { wrapContextWithTrace, printTraceInfo } from './test-helpers.mjs';

// Use the 127.0.0.1 literal, NOT localhost — the nginx test vhost binds
// 127.0.0.1:9998 and WP's canonical home is 127.0.0.1, so hitting
// `localhost` triggers a 301 redirect that drops the admin session cookie
// and the REST nonce (cross-host), breaking every authenticated check.
// Overridable via --site= or WP_BASE_URL.
const SITE = process.argv.find(a => a.startsWith('--site='))?.split('=')[1]
  || process.env.WP_BASE_URL
  || 'http://127.0.0.1:9998';

const results = [];
function log(name, pass, detail) {
  results.push({ name, pass, detail });
  console.log(`  ${pass ? '\x1b[32m PASS\x1b[0m' : '\x1b[31m FAIL\x1b[0m'}  ${name}${detail ? ' — ' + detail : ''}`);
}

console.log('\n============================================================');
console.log('  FAZ Cookie Manager — Final Verification (V01-V14)');
console.log('============================================================\n');

const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
await wrapContextWithTrace(ctx, 'final-verification');
const page = await ctx.newPage();

// Login
await page.goto(`${SITE}/wp-login.php`);
await page.fill('#user_login', 'admin');
await page.fill('#user_pass', 'admin');
await page.click('#wp-submit');
await page.waitForLoadState('domcontentloaded');

// V01 - Dashboard loads
await page.goto(`${SITE}/wp-admin/admin.php?page=faz-cookie-manager`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
log('V01 Dashboard page loads', page.url().includes('faz-cookie-manager'));

// V02 - Banner page
await page.goto(`${SITE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
log('V02 Banner settings page loads', page.url().includes('faz-cookie-manager-banner'));

// V03 - Cookies page
await page.goto(`${SITE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
log('V03 Cookies page loads', page.url().includes('faz-cookie-manager-cookies'));

// V04 - Settings page with GeoLite2 section
await page.goto(`${SITE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
const geoSection = await page.$('#faz-geodb-update');
log('V04 Settings page with GeoLite2 section', !!geoSection, geoSection ? 'GeoLite2 button found' : 'Missing');

// V05 - GCM page
await page.goto(`${SITE}/wp-admin/admin.php?page=faz-cookie-manager-gcm`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
log('V05 Google Consent Mode page loads', page.url().includes('faz-cookie-manager-gcm'));

// V06 - Settings REST API
await page.goto(`${SITE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
const settingsData = await page.evaluate(async () => {
  try {
    const nonce = window.fazConfig && window.fazConfig.api ? window.fazConfig.api.nonce : '';
    const r = await fetch('/?rest_route=/faz/v1/settings/', { headers: { 'X-WP-Nonce': nonce } });
    const text = await r.text();
    try { return JSON.parse(text); } catch(e) { return { _parseError: text.substring(0, 200) }; }
  } catch(e) { return { _fetchError: e.message }; }
});
const hasGeo = settingsData && settingsData.geolocation !== undefined;
log('V06 Settings REST API returns geolocation key', hasGeo,
    hasGeo ? 'geolocation key present' : JSON.stringify(settingsData).substring(0,150));

// V07 - GeoLite2 status API
const geoStatus = await page.evaluate(async () => {
  try {
    const nonce = window.fazConfig && window.fazConfig.api ? window.fazConfig.api.nonce : '';
    const r = await fetch('/?rest_route=/faz/v1/settings/geolite2/status', { headers: { 'X-WP-Nonce': nonce } });
    const text = await r.text();
    try { return JSON.parse(text); } catch(e) { return { _parseError: text.substring(0, 200) }; }
  } catch(e) { return { _fetchError: e.message }; }
});
log('V07 GeoLite2 status API responds', geoStatus && 'installed' in geoStatus,
    'installed=' + (geoStatus && geoStatus.installed !== undefined ? geoStatus.installed : 'error'));

// V08 - Cookies REST API
await page.goto(`${SITE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
const cookieResult = await page.evaluate(async () => {
  try {
    const nonce = window.fazConfig && window.fazConfig.api ? window.fazConfig.api.nonce : '';
    const r = await fetch('/?rest_route=/faz/v1/cookies/', { headers: { 'X-WP-Nonce': nonce } });
    const text = await r.text();
    let cookies;
    try { cookies = JSON.parse(text); } catch(e) { return 'parse error: ' + text.substring(0, 100); }
    if (!Array.isArray(cookies) || cookies.length === 0) return 'no cookies found';
    return 'cookies loaded: ' + cookies.length + ' items';
  } catch(e) { return 'error: ' + e.message; }
});
log('V08 Cookies REST API returns data', !cookieResult.includes('error'), cookieResult);

// V09 - Frontend banner renders (fresh context, no login cookies)
const frontCtx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
await wrapContextWithTrace(frontCtx, 'final-verification-frontend');
const frontPage = await frontCtx.newPage();
await frontPage.goto(`${SITE}/`, { waitUntil: 'domcontentloaded' });
await frontPage.waitForTimeout(2000);
const banner = await frontPage.$('[data-faz-tag="notice"]');
log('V09 Frontend banner renders on first visit', !!banner);

// V10 - No JS console errors on frontend
const consoleErrors = [];
frontPage.on('console', msg => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
await frontPage.goto(`${SITE}/`, { waitUntil: 'domcontentloaded' });
await frontPage.waitForTimeout(2000);
log('V10 No JavaScript errors on frontend', consoleErrors.length === 0,
    consoleErrors.length > 0 ? consoleErrors.join('; ').substring(0,200) : 'clean');
await frontCtx.close();

// V11 - Languages page
await page.goto(`${SITE}/wp-admin/admin.php?page=faz-cookie-manager-languages`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
log('V11 Languages page loads', page.url().includes('faz-cookie-manager-languages'));

// V12 - Consent Logs page
await page.goto(`${SITE}/wp-admin/admin.php?page=faz-cookie-manager-consent-logs`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
log('V12 Consent Logs page loads', page.url().includes('faz-cookie-manager-consent-logs'));

// V13 - GVL admin page loads
await page.goto(`${SITE}/wp-admin/admin.php?page=faz-cookie-manager-gvl`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
const gvlPage = page.url().includes('faz-cookie-manager-gvl');
const gvlHeading = await page.$('#faz-gvl');
log('V13 GVL admin page loads', gvlPage && !!gvlHeading);

// V14 - GVL update button exists
const gvlUpdateBtn = await page.$('#faz-gvl-download');
log('V14 GVL update button present', !!gvlUpdateBtn);

// Summary
console.log('\n============================================================');
console.log('  SUMMARY');
console.log('============================================================');
const passed = results.filter(r => r.pass).length;
const total = results.length;
console.log(`  TOTAL: ${passed === total ? '\x1b[32m' : '\x1b[31m'}${passed}/${total}\x1b[0m tests passed`);
if (passed < total) {
  console.log('\n  FAILURES:');
  results.filter(r => !r.pass).forEach(r =>
    console.log('    FAILED: ' + r.name + (r.detail ? ' — ' + r.detail : ''))
  );
}
printTraceInfo();
console.log('============================================================\n');

await ctx.close();
await browser.close();
process.exit(passed === total ? 0 : 1);
