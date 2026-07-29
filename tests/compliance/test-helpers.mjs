/**
 * Shared test helpers: Playwright tracing + Turndown HTML→Markdown
 *
 * Usage:
 *   import { createTracedContext, pageToMarkdown, bannerToMarkdown, printTraceInfo } from './test-helpers.mjs';
 *
 *   // Option A: auto-traced context (ctx.close() saves trace automatically)
 *   const { ctx, page } = await createTracedContext(browser, 'my-test');
 *   // ... run tests ...
 *   await ctx.close(); // automatically saves trace to traces/my-test.zip
 *
 *   // Option B: wrap existing context
 *   const ctx = await browser.newContext();
 *   wrapContextWithTrace(ctx, 'my-test');
 *   // ... ctx.close() now saves trace ...
 *
 * Enable tracing: pass --trace flag to your test script.
 */

import { existsSync, mkdirSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const TRACES_DIR = join(__dirname, 'traces');

// CLI flags
const args = process.argv.slice(2);
const TRACE_ENABLED = args.includes('--trace');

// Lazy-load Turndown
let _turndown = null;
async function getTurndown() {
    if (_turndown !== null) return _turndown;
    try {
        const TurndownService = (await import('turndown')).default;
        _turndown = new TurndownService({
            headingStyle: 'atx',
            codeBlockStyle: 'fenced',
            bulletListMarker: '-',
        });
        _turndown.remove(['script', 'style', 'noscript', 'svg']);
    } catch (e) {
        console.warn('  [warn] turndown not installed — markdown helpers disabled');
        _turndown = false; // mark as attempted
    }
    return _turndown;
}

// ── Trace helpers ──

/**
 * Wrap a context's close() to save trace on close.
 * Does nothing if --trace is not passed.
 */
export async function wrapContextWithTrace(ctx, name) {
    if (!TRACE_ENABLED) return;
    if (!existsSync(TRACES_DIR)) mkdirSync(TRACES_DIR, { recursive: true });
    await ctx.tracing.start({ screenshots: true, snapshots: true, sources: false });

    const origClose = ctx.close.bind(ctx);
    ctx.close = async () => {
        const tracePath = join(TRACES_DIR, `${name}.zip`);
        await ctx.tracing.stop({ path: tracePath });
        console.log(`  [trace] ${tracePath}`);
        await origClose();
    };
}

/**
 * Create a browser context with auto-trace on close().
 * Existing code can keep calling ctx.close() — trace is saved automatically.
 */
export async function createTracedContext(browser, name, opts = {}) {
    const ctx = await browser.newContext({
        viewport: { width: opts.width || 1400, height: opts.height || 900 },
        ...(opts.extra || {}),
    });
    await wrapContextWithTrace(ctx, name);
    const page = await ctx.newPage();
    return { ctx, page };
}

// ── Turndown HTML→Markdown helpers ──

/** Convert full page HTML to Markdown. */
export async function pageToMarkdown(page) {
    const td = await getTurndown();
    if (!td) return '(turndown not available)';
    const html = await page.content();
    return td.turndown(html);
}

/** Convert a specific element's innerHTML to Markdown. */
export async function elementToMarkdown(page, selector) {
    const td = await getTurndown();
    if (!td) return '(turndown not available)';
    const count = await page.locator(selector).count();
    if (count === 0) return `(element not found: ${selector})`;
    const html = await page.locator(selector).first().innerHTML();
    return td.turndown(html);
}

/** Get the consent banner HTML as Markdown. */
export async function bannerToMarkdown(page) {
    const td = await getTurndown();
    if (!td) return '(turndown not available)';
    const count = await page.locator('.faz-consent-container').count();
    if (count === 0) return '(no banner found)';
    const html = await page.locator('.faz-consent-container').first().innerHTML();
    return td.turndown(html);
}

/** Get visible page content as Markdown (strips hidden elements). */
export async function visibleToMarkdown(page) {
    const td = await getTurndown();
    if (!td) return '(turndown not available)';
    const html = await page.evaluate(() => {
        const body = document.body.cloneNode(true);
        body.querySelectorAll('[style*="display: none"], [style*="display:none"], [hidden], .hidden, .faz-hide').forEach(el => el.remove());
        return body.innerHTML;
    });
    return td.turndown(html);
}

// ── Utilities ──

export function isTraceEnabled() { return TRACE_ENABLED; }

/** Print trace info at end of test run. */
export function printTraceInfo() {
    if (!TRACE_ENABLED) {
        console.log('\n  [tip] Re-run with --trace to record Playwright traces');
        return;
    }
    console.log(`\n  [trace] Files saved in: ${TRACES_DIR}/`);
    console.log('  [trace] View with: npx playwright show-trace traces/<name>.zip');
}
