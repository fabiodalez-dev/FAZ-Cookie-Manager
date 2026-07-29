/**
 * FAZ Cookie Manager — Comprehensive Banner Settings Test
 * Tests ALL banner types, preference center types, colors, positions, and themes.
 * Every test is run for ALL 3 banner types (classic, full-width, box).
 * Verifies that backend settings are correctly reflected in the frontend.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'fs';

const BASE = 'http://127.0.0.1:9998';
const SCREENSHOT_DIR = '/Users/fabio/Documents/GitHub/Cookie Crawler/screenshots';

let passed = 0;
let failed = 0;
const failures = [];

function test(name, condition, detail = '') {
    if (condition) {
        passed++;
        console.log(`  \x1b[32mPASS\x1b[0m  ${name}${detail ? ' — ' + detail : ''}`);
    } else {
        failed++;
        failures.push(name);
        console.log(`  \x1b[31mFAIL\x1b[0m  ${name}${detail ? ' — ' + detail : ''}`);
    }
}

async function login(browser) {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(BASE + '/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**');
    return { ctx, page };
}

async function clearBannerCache() {
    const { execSync: run } = await import('child_process');
    try {
        run('php -r "\\"\\$pdo = new PDO(\'mysql:host=127.0.0.1;port=3306;dbname=faz_cookie_wp\', \'root\', \'Fa310reds?\'); \\$pdo->exec(\\\\\\\"DELETE FROM wp_options WHERE option_name = \'faz_banner_template\'\\\\\\\"); echo \'cleared\';\\"" 2>/dev/null || true', { shell: true, encoding: 'utf8' });
    } catch { /* ignore */ }
}

async function saveBanner(page, settings = {}) {
    await page.goto(BASE + '/wp-admin/admin.php?page=faz-cookie-manager-banner');
    await page.waitForSelector('#faz-banner', { timeout: 10000 });
    await page.waitForTimeout(2000);

    // Set banner type
    if (settings.type) {
        await page.selectOption('#faz-b-type', settings.type);
        await page.waitForTimeout(500);
    }
    // Set position
    if (settings.position) {
        await page.selectOption('#faz-b-position', settings.position);
        await page.waitForTimeout(500);
    }
    // Set preference center type
    if (settings.prefType) {
        await page.selectOption('#faz-b-pref-type', settings.prefType);
        await page.waitForTimeout(500);
    }
    // Set theme (it's a <select id="faz-b-theme">, NOT radio buttons)
    if (settings.theme) {
        await page.selectOption('#faz-b-theme', settings.theme);
        await page.waitForTimeout(1500); // Wait for applyThemePreset() to run
    }
    // Save
    await page.click('#faz-b-save');
    await page.waitForTimeout(2000);

    // Clear banner template cache via direct DB so template regenerates fresh
    await page.evaluate(async () => {
        try {
            await fetch('/wp-admin/admin-ajax.php?action=faz_clear_cache', { method: 'POST' });
        } catch {}
    });
    // Also clear via REST if possible
    try {
        const { execSync: run } = await import('child_process');
        run("php -r \"\\$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=faz_cookie_wp', 'root', 'Fa310reds?'); \\$pdo->exec(\\\"DELETE FROM wp_options WHERE option_name = 'faz_banner_template'\\\"); echo 'ok';\"", { encoding: 'utf8', stdio: 'pipe' });
    } catch {}
}

async function visitFrontend(browser, screenshotName) {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(BASE + '/');
    await page.waitForTimeout(3000);
    if (screenshotName) {
        await page.screenshot({ path: `${SCREENSHOT_DIR}/${screenshotName}.png`, fullPage: true });
    }
    return { ctx, page };
}

async function getFrontendState(page) {
    return page.evaluate(() => {
        const container = document.querySelector('.faz-consent-container');
        const notice = container?.querySelector('[data-faz-tag="notice"]');
        const classes = container?.className || '';

        // Banner type detection
        let detectedType = 'unknown';
        if (classes.includes('faz-classic')) detectedType = 'classic';
        else if (classes.includes('faz-box')) detectedType = 'box';
        else if (classes.includes('faz-banner')) detectedType = 'banner';

        // Position detection (handle compound positions first)
        let position = 'unknown';
        if (classes.includes('bottom-left')) position = 'bottom-left';
        else if (classes.includes('bottom-right')) position = 'bottom-right';
        else if (classes.includes('-bottom')) position = 'bottom';
        else if (classes.includes('-top')) position = 'top';

        // Inline category preview toggles (classic type)
        const inlineToggles = document.querySelectorAll('.faz-category-direct-preview-section input[type="checkbox"]');

        const getStyles = (el) => {
            if (!el) return null;
            const cs = getComputedStyle(el);
            return { color: cs.color, backgroundColor: cs.backgroundColor, borderColor: cs.borderColor };
        };

        const noticeStyles = getStyles(notice);

        // Buttons
        const acceptBtn = container?.querySelector('.faz-btn-accept');
        const rejectBtn = container?.querySelector('.faz-btn-reject');
        const customiseBtn = container?.querySelector('[data-faz-tag="settings-button"]');
        const titleEl = container?.querySelector('[data-faz-tag="notice-title"]');
        const descEl = container?.querySelector('[data-faz-tag="notice-description"]');

        // Toggle colors (inline preview)
        const toggleColors = {};
        inlineToggles.forEach(t => {
            toggleColors[t.id] = { checked: t.checked, backgroundColor: t.style.backgroundColor || getComputedStyle(t).backgroundColor };
        });

        // Store config data
        const bc = window._fazConfig?._bannerConfig;

        return {
            containerClasses: classes,
            detectedType,
            position,
            hasInlineToggles: inlineToggles.length > 0,
            inlineToggleCount: inlineToggles.length,
            noticeStyles,
            titleStyles: getStyles(titleEl),
            descStyles: getStyles(descEl),
            acceptStyles: getStyles(acceptBtn),
            rejectStyles: getStyles(rejectBtn),
            customiseStyles: getStyles(customiseBtn),
            toggleColors,
            storeType: bc?.settings?.type,
            storePrefType: bc?.settings?.preferenceCenterType,
            storePosition: bc?.settings?.position,
            storeTheme: bc?.settings?.theme,
            categoryPreviewStatus: bc?.config?.categoryPreview?.status,
            categoryPreviewToggleActive: bc?.config?.categoryPreview?.toggle?.states?.active?.styles?.['background-color'],
            categoryPreviewToggleInactive: bc?.config?.categoryPreview?.toggle?.states?.inactive?.styles?.['background-color'],
            prefToggleActive: bc?.config?.preferenceCenter?.toggle?.states?.active?.styles?.['background-color'],
            prefToggleInactive: bc?.config?.preferenceCenter?.toggle?.states?.inactive?.styles?.['background-color'],
        };
    });
}

async function testPreferenceCenter(page) {
    await page.evaluate(() => {
        const btn = document.querySelector('[data-faz-tag="settings-button"]');
        if (btn) btn.click();
    });
    await page.waitForTimeout(2000);

    return page.evaluate(() => {
        const container = document.querySelector('.faz-consent-container');
        const modal = document.querySelector('.faz-modal');
        const isExpanded = container?.classList.contains('faz-consent-bar-expand');
        const isModalOpen = modal?.classList.contains('faz-modal-open');
        const hasSidebarLeft = modal?.classList.contains('faz-sidebar-left');
        const hasSidebarRight = modal?.classList.contains('faz-sidebar-right');
        const isSidebar = hasSidebarLeft || hasSidebarRight;
        const overlay = document.querySelector('.faz-overlay');
        const overlayVisible = overlay ? !overlay.classList.contains('faz-hide') : false;

        let prefMode = 'unknown';
        if (isExpanded && !isModalOpen) prefMode = 'pushdown';
        else if (isSidebar && isModalOpen) prefMode = 'sidebar';
        else if (isModalOpen && overlayVisible) prefMode = 'popup';

        const prefCenter = document.querySelector('.faz-preference-center');
        const isPrefVisible = prefCenter
            ? getComputedStyle(prefCenter).display !== 'none' && getComputedStyle(prefCenter).visibility !== 'hidden'
            : false;

        const prefEl = document.querySelector('[data-faz-tag="detail"]');
        const prefStyles = prefEl ? { color: getComputedStyle(prefEl).color, backgroundColor: getComputedStyle(prefEl).backgroundColor } : null;

        const accordionToggles = document.querySelectorAll('.faz-switch input[type="checkbox"]');
        const catTitles = document.querySelectorAll('[data-faz-tag="detail-category-title"]');
        const getStyles = (el) => el ? { color: getComputedStyle(el).color, backgroundColor: getComputedStyle(el).backgroundColor, borderColor: getComputedStyle(el).borderColor } : null;

        return {
            containerClasses: container?.className,
            modalClasses: modal?.className,
            prefMode, isExpanded, isModalOpen, isSidebar, hasSidebarLeft, hasSidebarRight, overlayVisible, isPrefVisible, prefStyles,
            accordionToggleCount: accordionToggles.length, categoryCount: catTitles.length,
            detailSaveBtnStyles: getStyles(document.querySelector('[data-faz-tag="detail-save-button"]')),
            detailAcceptBtnStyles: getStyles(document.querySelector('[data-faz-tag="detail-accept-button"]')),
            detailRejectBtnStyles: getStyles(document.querySelector('[data-faz-tag="detail-reject-button"]')),
        };
    });
}

function rgbToHex(rgb) {
    if (!rgb || rgb === 'transparent') return 'transparent';
    const match = rgb.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/);
    if (!match) return rgb;
    return '#' + [match[1], match[2], match[3]].map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
}

function colorsMatch(actual, expected) {
    if (!actual || !expected) return false;
    return rgbToHex(actual).toLowerCase() === expected.toLowerCase();
}

function isNotWhite(rgb) {
    return rgb && rgb !== 'rgb(255, 255, 255)' && rgb !== 'rgba(255, 255, 255, 1)' && rgb !== 'rgba(0, 0, 0, 0)';
}

// ============================================================
// MAIN TEST
// ============================================================
(async () => {
    mkdirSync(SCREENSHOT_DIR, { recursive: true });

    console.log('╔════════════════════════════════════════════════════════════╗');
    console.log('║  FAZ Cookie Manager — Banner Settings Test Suite          ║');
    console.log('║  Testing ALL settings for ALL 3 banner types             ║');
    console.log('╚════════════════════════════════════════════════════════════╝');
    console.log(`Site: ${BASE}\n`);

    const browser = await chromium.launch({ headless: true });
    const { ctx: adminCtx, page: adminPage } = await login(browser);

    // ══════════════════════════════════════════════════════════
    // SECTION 1: BANNER TYPE RENDERING
    // ══════════════════════════════════════════════════════════
    console.log('════════════════════════════════════════════════════════════');
    console.log('  SECTION 1: BANNER TYPE RENDERING');
    console.log('════════════════════════════════════════════════════════════');

    // --- 1A: CLASSIC ---
    console.log('\n  ── 1A: Classic Banner ──');
    await saveBanner(adminPage, { type: 'classic', position: 'bottom', theme: 'light' });
    {
        const { ctx, page } = await visitFrontend(browser, '01a-classic');
        const s = await getFrontendState(page);
        test('1A.01 Classic: faz-classic class', s.detectedType === 'classic', s.containerClasses);
        test('1A.02 Classic: position bottom', s.position === 'bottom');
        test('1A.03 Classic: has inline category toggles', s.hasInlineToggles);
        test('1A.04 Classic: categoryPreview.status = true', s.categoryPreviewStatus === true);
        test('1A.05 Classic: store type = "banner"', s.storeType === 'banner');
        test('1A.06 Classic: store prefType = "pushdown"', s.storePrefType === 'pushdown');
        test('1A.07 Classic: accept btn exists', !!s.acceptStyles?.backgroundColor);
        test('1A.08 Classic: reject btn exists', !!s.rejectStyles?.backgroundColor);
        test('1A.09 Classic: customise btn exists', !!s.customiseStyles);
        const pref = await testPreferenceCenter(page);
        await page.screenshot({ path: `${SCREENSHOT_DIR}/01a-classic-pref.png`, fullPage: true });
        test('1A.10 Classic pref: pushdown mode', pref.prefMode === 'pushdown', `mode=${pref.prefMode}`);
        test('1A.11 Classic pref: overlay NOT visible', !pref.overlayVisible);
        test('1A.12 Classic pref: has categories', pref.categoryCount > 0 || pref.accordionToggleCount > 0);
        await ctx.close();
    }

    // --- 1B: FULL-WIDTH ---
    console.log('\n  ── 1B: Full-width Banner ──');
    await saveBanner(adminPage, { type: 'banner', position: 'bottom', prefType: 'popup', theme: 'light' });
    {
        const { ctx, page } = await visitFrontend(browser, '01b-fullwidth');
        const s = await getFrontendState(page);
        test('1B.01 Full-width: faz-banner class', s.detectedType === 'banner', s.containerClasses);
        test('1B.02 Full-width: position bottom', s.position === 'bottom');
        test('1B.03 Full-width: NO inline toggles', !s.hasInlineToggles);
        test('1B.04 Full-width: store type = "banner"', s.storeType === 'banner');
        test('1B.05 Full-width: store prefType = "popup"', s.storePrefType === 'popup');
        test('1B.06 Full-width: accept btn exists', !!s.acceptStyles?.backgroundColor);
        const pref = await testPreferenceCenter(page);
        await page.screenshot({ path: `${SCREENSHOT_DIR}/01b-fullwidth-pref.png`, fullPage: true });
        test('1B.07 Full-width popup: overlay visible', pref.overlayVisible);
        test('1B.08 Full-width popup: modal open', pref.isModalOpen);
        test('1B.09 Full-width popup: NOT expanded', !pref.isExpanded);
        test('1B.10 Full-width popup: has categories', pref.categoryCount > 0);
        await ctx.close();
    }

    // --- 1C: BOX ---
    console.log('\n  ── 1C: Box Banner ──');
    await saveBanner(adminPage, { type: 'box', position: 'bottom-left', prefType: 'popup', theme: 'light' });
    {
        const { ctx, page } = await visitFrontend(browser, '01c-box');
        const s = await getFrontendState(page);
        test('1C.01 Box: faz-box class', s.detectedType === 'box', s.containerClasses);
        test('1C.02 Box: position bottom-left', s.position === 'bottom-left', s.containerClasses);
        test('1C.03 Box: NO inline toggles', !s.hasInlineToggles);
        test('1C.04 Box: store type = "box"', s.storeType === 'box');
        test('1C.05 Box: accept btn exists', !!s.acceptStyles?.backgroundColor);
        const pref = await testPreferenceCenter(page);
        await page.screenshot({ path: `${SCREENSHOT_DIR}/01c-box-pref.png`, fullPage: true });
        test('1C.06 Box popup: overlay visible', pref.overlayVisible);
        test('1C.07 Box popup: modal open', pref.isModalOpen);
        await ctx.close();
    }

    // ══════════════════════════════════════════════════════════
    // SECTION 2: POSITIONS
    // ══════════════════════════════════════════════════════════
    console.log('\n════════════════════════════════════════════════════════════');
    console.log('  SECTION 2: POSITIONS');
    console.log('════════════════════════════════════════════════════════════');

    for (const pos of ['top', 'bottom']) {
        await saveBanner(adminPage, { type: 'classic', position: pos, theme: 'light' });
        const { ctx, page } = await visitFrontend(browser, `02-classic-${pos}`);
        const s = await getFrontendState(page);
        test(`2.x Classic ${pos}: position matches`, s.position === pos, s.containerClasses);
        await ctx.close();
    }
    for (const pos of ['top', 'bottom']) {
        await saveBanner(adminPage, { type: 'banner', position: pos, prefType: 'popup', theme: 'light' });
        const { ctx, page } = await visitFrontend(browser, `02-fullwidth-${pos}`);
        const s = await getFrontendState(page);
        test(`2.x Full-width ${pos}: position matches`, s.position === pos, s.containerClasses);
        await ctx.close();
    }
    for (const pos of ['bottom-left', 'bottom-right']) {
        await saveBanner(adminPage, { type: 'box', position: pos, prefType: 'popup', theme: 'light' });
        const { ctx, page } = await visitFrontend(browser, `02-box-${pos}`);
        const s = await getFrontendState(page);
        test(`2.x Box ${pos}: position matches`, s.position === pos, s.containerClasses);
        await ctx.close();
    }

    // ══════════════════════════════════════════════════════════
    // SECTION 3: PREFERENCE CENTER TYPES
    // ══════════════════════════════════════════════════════════
    console.log('\n════════════════════════════════════════════════════════════');
    console.log('  SECTION 3: PREFERENCE CENTER TYPES');
    console.log('════════════════════════════════════════════════════════════');

    // Classic always pushdown
    console.log('\n  ── Classic (always pushdown) ──');
    await saveBanner(adminPage, { type: 'classic', position: 'bottom', theme: 'light' });
    { const { ctx, page } = await visitFrontend(browser, '03-classic-pushdown');
      const pref = await testPreferenceCenter(page);
      test('3.00 Classic: pushdown mode', pref.prefMode === 'pushdown', `mode=${pref.prefMode}`);
      await ctx.close(); }

    // Full-width: popup and sidebar (pushdown becomes classic)
    for (const pt of ['popup', 'sidebar']) {
        console.log(`\n  ── Full-width+${pt} ──`);
        await saveBanner(adminPage, { type: 'banner', position: 'bottom', prefType: pt, theme: 'light' });
        const { ctx, page } = await visitFrontend(browser, `03-fullwidth-${pt}`);
        const s = await getFrontendState(page);
        test(`3.x Full-width+${pt}: storePrefType`, s.storePrefType === pt, `got=${s.storePrefType}`);
        const pref = await testPreferenceCenter(page);
        await page.screenshot({ path: `${SCREENSHOT_DIR}/03-fullwidth-${pt}-pref.png`, fullPage: true });
        if (pt === 'popup') {
            test(`3.x Full-width+popup: popup mode`, pref.prefMode === 'popup', `mode=${pref.prefMode}`);
            test(`3.x Full-width+popup: overlay`, pref.overlayVisible);
        } else {
            test(`3.x Full-width+sidebar: sidebar mode`, pref.prefMode === 'sidebar', `mode=${pref.prefMode}, modal=${pref.modalClasses}`);
            test(`3.x Full-width+sidebar: directional class`, pref.hasSidebarLeft || pref.hasSidebarRight, `L=${pref.hasSidebarLeft} R=${pref.hasSidebarRight}`);
            test(`3.x Full-width+sidebar: modal open`, pref.isModalOpen);
        }
        test(`3.x Full-width+${pt}: has categories`, pref.categoryCount > 0 || pref.accordionToggleCount > 0);
        await ctx.close();
    }

    // Box: popup, pushdown, sidebar
    for (const pt of ['popup', 'pushdown', 'sidebar']) {
        console.log(`\n  ── Box+${pt} ──`);
        await saveBanner(adminPage, { type: 'box', position: 'bottom-left', prefType: pt, theme: 'light' });
        const { ctx, page } = await visitFrontend(browser, `03-box-${pt}`);
        const s = await getFrontendState(page);
        const pref = await testPreferenceCenter(page);
        await page.screenshot({ path: `${SCREENSHOT_DIR}/03-box-${pt}-pref.png`, fullPage: true });
        if (pt === 'popup') {
            test(`3.x Box+popup: popup mode`, pref.prefMode === 'popup', `mode=${pref.prefMode}`);
            test(`3.x Box+popup: overlay`, pref.overlayVisible);
        } else if (pt === 'pushdown') {
            // Box can't visually "push down" — falls back to popup behavior
            test(`3.x Box+pushdown: mode (popup fallback)`, pref.prefMode === 'popup', `mode=${pref.prefMode}`);
        } else {
            test(`3.x Box+sidebar: sidebar mode`, pref.prefMode === 'sidebar', `mode=${pref.prefMode}, modal=${pref.modalClasses}`);
            test(`3.x Box+sidebar: directional class`, pref.hasSidebarLeft || pref.hasSidebarRight);
            test(`3.x Box+sidebar: modal open`, pref.isModalOpen);
        }
        test(`3.x Box+${pt}: has categories`, pref.categoryCount > 0 || pref.accordionToggleCount > 0);
        await ctx.close();
    }

    // ══════════════════════════════════════════════════════════
    // SECTION 4: DARK THEME — All 3 types
    // ══════════════════════════════════════════════════════════
    console.log('\n════════════════════════════════════════════════════════════');
    console.log('  SECTION 4: DARK THEME');
    console.log('════════════════════════════════════════════════════════════');

    const darkTests = [
        { type: 'classic', position: 'bottom', label: 'Classic' },
        { type: 'banner', position: 'bottom', prefType: 'popup', label: 'Full-width' },
        { type: 'box', position: 'bottom-left', prefType: 'popup', label: 'Box' },
    ];
    for (const dt of darkTests) {
        console.log(`\n  ── ${dt.label} Dark ──`);
        await saveBanner(adminPage, { ...dt, theme: 'dark' });
        const { ctx, page } = await visitFrontend(browser, `04-${dt.type}-dark`);
        const s = await getFrontendState(page);
        test(`4.x ${dt.label} dark: notice bg not white`, isNotWhite(s.noticeStyles?.backgroundColor), `bg=${s.noticeStyles?.backgroundColor}`);
        test(`4.x ${dt.label} dark: title text light`, s.titleStyles?.color !== 'rgb(33, 33, 33)', `color=${s.titleStyles?.color}`);
        test(`4.x ${dt.label} dark: accept btn exists`, !!s.acceptStyles?.backgroundColor);
        const pref = await testPreferenceCenter(page);
        await page.screenshot({ path: `${SCREENSHOT_DIR}/04-${dt.type}-dark-pref.png`, fullPage: true });
        test(`4.x ${dt.label} dark: pref bg exists`, !!pref.prefStyles?.backgroundColor);
        await ctx.close();
    }

    // ══════════════════════════════════════════════════════════
    // SECTION 5: THEME SWITCH (DARK → LIGHT) — All 3 types
    // ══════════════════════════════════════════════════════════
    console.log('\n════════════════════════════════════════════════════════════');
    console.log('  SECTION 5: THEME SWITCH (DARK → LIGHT)');
    console.log('════════════════════════════════════════════════════════════');

    for (const dt of darkTests) {
        console.log(`\n  ── ${dt.label} Dark→Light ──`);
        await saveBanner(adminPage, { ...dt, theme: 'dark' });
        let darkState;
        { const { ctx, page } = await visitFrontend(browser); darkState = await getFrontendState(page); await ctx.close(); }

        await saveBanner(adminPage, { ...dt, theme: 'light' });
        { const { ctx, page } = await visitFrontend(browser, `05-${dt.type}-dark-to-light`);
          const ls = await getFrontendState(page);
          test(`5.x ${dt.label}: notice bg changed`, ls.noticeStyles?.backgroundColor !== darkState.noticeStyles?.backgroundColor,
              `dark=${darkState.noticeStyles?.backgroundColor}, light=${ls.noticeStyles?.backgroundColor}`);
          test(`5.x ${dt.label}: light notice bg is white`,
              ls.noticeStyles?.backgroundColor === 'rgb(255, 255, 255)' || ls.noticeStyles?.backgroundColor === 'rgba(255, 255, 255, 1)',
              `bg=${ls.noticeStyles?.backgroundColor}`);
          await ctx.close(); }
    }

    // ══════════════════════════════════════════════════════════
    // SECTION 6: COLOR CONSISTENCY
    // ══════════════════════════════════════════════════════════
    console.log('\n════════════════════════════════════════════════════════════');
    console.log('  SECTION 6: COLOR CONSISTENCY');
    console.log('════════════════════════════════════════════════════════════');

    console.log('\n  ── Classic colors ──');
    await saveBanner(adminPage, { type: 'classic', position: 'bottom', theme: 'light' });
    {
        const { ctx, page } = await visitFrontend(browser, '06-classic-colors');
        const s = await getFrontendState(page);
        const cfg = await page.evaluate(() => {
            const bc = window._fazConfig?._bannerConfig;
            return bc ? {
                noticeBg: bc.config?.notice?.styles?.['background-color'],
                prevToggleActive: bc.config?.categoryPreview?.toggle?.states?.active?.styles?.['background-color'],
                prevToggleInactive: bc.config?.categoryPreview?.toggle?.states?.inactive?.styles?.['background-color'],
                prefToggleActive: bc.config?.preferenceCenter?.toggle?.states?.active?.styles?.['background-color'],
            } : null;
        });
        if (cfg) {
            const nec = s.toggleColors['fazCategoryDirectnecessary'];
            if (nec && cfg.prevToggleActive) {
                test('6.01 Inline toggle (active): matches preview config', colorsMatch(nec.backgroundColor, cfg.prevToggleActive),
                    `actual=${nec.backgroundColor}, cfg=${cfg.prevToggleActive}`);
            }
            const func = s.toggleColors['fazCategoryDirectfunctional'];
            if (func && cfg.prevToggleInactive) {
                test('6.02 Inline toggle (inactive): matches preview config', colorsMatch(func.backgroundColor, cfg.prevToggleInactive),
                    `actual=${func.backgroundColor}, cfg=${cfg.prevToggleInactive}`);
            }
            if (cfg.noticeBg) {
                test('6.03 Notice bg matches config', colorsMatch(s.noticeStyles?.backgroundColor, cfg.noticeBg),
                    `actual=${s.noticeStyles?.backgroundColor}, cfg=${cfg.noticeBg}`);
            }
        }
        test('6.04 Accept btn', !!s.acceptStyles?.backgroundColor);
        test('6.05 Reject btn', !!s.rejectStyles?.backgroundColor);
        test('6.06 Customise btn', !!s.customiseStyles);
        await ctx.close();
    }

    for (const bt of [{ type: 'banner', position: 'bottom', prefType: 'popup', label: 'Full-width' }, { type: 'box', position: 'bottom-left', prefType: 'popup', label: 'Box' }]) {
        console.log(`\n  ── ${bt.label} colors ──`);
        await saveBanner(adminPage, { ...bt, theme: 'light' });
        const { ctx, page } = await visitFrontend(browser, `06-${bt.type}-colors`);
        const s = await getFrontendState(page);
        const cfg = await page.evaluate(() => {
            const bc = window._fazConfig?._bannerConfig;
            return bc ? { noticeBg: bc.config?.notice?.styles?.['background-color'] } : null;
        });
        if (cfg?.noticeBg) {
            test(`6.x ${bt.label}: notice bg matches config`, colorsMatch(s.noticeStyles?.backgroundColor, cfg.noticeBg),
                `actual=${s.noticeStyles?.backgroundColor}, cfg=${cfg.noticeBg}`);
        }
        test(`6.x ${bt.label}: accept btn`, !!s.acceptStyles?.backgroundColor);
        test(`6.x ${bt.label}: reject btn`, !!s.rejectStyles?.backgroundColor);
        await ctx.close();
    }

    // ══════════════════════════════════════════════════════════
    // SECTION 7: BANNER TYPES ARE DISTINCT
    // ══════════════════════════════════════════════════════════
    console.log('\n════════════════════════════════════════════════════════════');
    console.log('  SECTION 7: BANNER TYPES ARE DISTINCT');
    console.log('════════════════════════════════════════════════════════════');

    const states = {};
    await saveBanner(adminPage, { type: 'classic', position: 'bottom', theme: 'light' });
    { const { ctx, page } = await visitFrontend(browser); states.classic = await getFrontendState(page); await ctx.close(); }
    await saveBanner(adminPage, { type: 'banner', position: 'bottom', prefType: 'popup', theme: 'light' });
    { const { ctx, page } = await visitFrontend(browser); states.banner = await getFrontendState(page); await ctx.close(); }
    await saveBanner(adminPage, { type: 'box', position: 'bottom-left', prefType: 'popup', theme: 'light' });
    { const { ctx, page } = await visitFrontend(browser); states.box = await getFrontendState(page); await ctx.close(); }

    test('7.01 Classic ≠ Full-width', states.classic.detectedType !== states.banner.detectedType, `${states.classic.detectedType} vs ${states.banner.detectedType}`);
    test('7.02 Classic ≠ Box', states.classic.detectedType !== states.box.detectedType);
    test('7.03 Full-width ≠ Box', states.banner.detectedType !== states.box.detectedType);
    test('7.04 Classic has inline toggles', states.classic.hasInlineToggles);
    test('7.05 Full-width NO inline toggles', !states.banner.hasInlineToggles);
    test('7.06 Box NO inline toggles', !states.box.hasInlineToggles);

    // ══════════════════════════════════════════════════════════
    // SECTION 8: ADMIN FORM REFLECTS SAVED STATE
    // ══════════════════════════════════════════════════════════
    console.log('\n════════════════════════════════════════════════════════════');
    console.log('  SECTION 8: ADMIN FORM REFLECTS SAVED STATE');
    console.log('════════════════════════════════════════════════════════════');

    const adminTests = [
        { type: 'classic', position: 'bottom', theme: 'light', label: 'Classic light' },
        { type: 'banner', position: 'bottom', prefType: 'popup', theme: 'light', label: 'Full-width popup' },
        { type: 'box', position: 'bottom-right', prefType: 'popup', theme: 'dark', label: 'Box dark' },
        { type: 'box', position: 'bottom-left', prefType: 'sidebar', theme: 'light', label: 'Box sidebar' },
    ];

    for (const at of adminTests) {
        console.log(`\n  ── ${at.label} ──`);
        await saveBanner(adminPage, at);
        await adminPage.goto(BASE + '/wp-admin/admin.php?page=faz-cookie-manager-banner');
        await adminPage.waitForSelector('#faz-banner', { timeout: 10000 });
        await adminPage.waitForTimeout(3000);
        const vals = await adminPage.evaluate(() => ({
            type: document.getElementById('faz-b-type')?.value,
            position: document.getElementById('faz-b-position')?.value,
            prefType: document.getElementById('faz-b-pref-type')?.value,
            theme: document.getElementById('faz-b-theme')?.value,
        }));
        test(`8.x ${at.label}: type="${at.type}"`, vals.type === at.type, `got: ${vals.type}`);
        test(`8.x ${at.label}: position="${at.position}"`, vals.position === at.position, `got: ${vals.position}`);
        if (at.prefType) test(`8.x ${at.label}: prefType="${at.prefType}"`, vals.prefType === at.prefType, `got: ${vals.prefType}`);
        test(`8.x ${at.label}: theme="${at.theme}"`, vals.theme === at.theme, `got: ${vals.theme}`);
    }

    // Restore
    await saveBanner(adminPage, { type: 'classic', position: 'bottom', theme: 'light' });
    await adminCtx.close();
    await browser.close();

    // ══════════════════════════════════════════════════════════
    // SUMMARY
    // ══════════════════════════════════════════════════════════
    console.log('\n════════════════════════════════════════════════════════════');
    console.log('  SUMMARY');
    console.log('════════════════════════════════════════════════════════════');
    console.log(`  TOTAL: ${passed + failed} tests`);
    console.log(`  \x1b[32mPASSED: ${passed}\x1b[0m`);
    if (failed > 0) {
        console.log(`  \x1b[31mFAILED: ${failed}\x1b[0m`);
        console.log('\n  Failures:');
        for (const f of failures) console.log(`  \x1b[31m✗\x1b[0m ${f}`);
    } else {
        console.log('\n  \x1b[32m✓ ALL TESTS PASSED!\x1b[0m');
    }
    console.log('════════════════════════════════════════════════════════════');
    console.log(`\nScreenshots saved to: ${SCREENSHOT_DIR}/`);
    process.exit(failed > 0 ? 1 : 0);
})();
