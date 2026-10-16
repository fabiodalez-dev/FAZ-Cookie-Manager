/**
 * Main-thread watchdog for browser tests that must prove a page did NOT freeze.
 *
 * The specs used to call `page.evaluate(() => new Promise(r => setTimeout(r, 0)))`
 * three times, 250 ms apart. That only proves the thread was free at three
 * instants after the page had already settled: a page that spent 40 s in a
 * synchronous loop before DOMContentLoaded and then recovered, or one whose
 * queue was growing without bound while it still answered, passed it.
 *
 * This watchdog measures the whole page view instead. An init script, installed
 * on the context BEFORE navigation so it runs ahead of every page script,
 * records:
 *   - a 50 ms heartbeat on the native setTimeout captured at document start —
 *     the largest gap between two beats is the longest time the thread was
 *     unavailable, whatever kept it busy;
 *   - every `longtask` PerformanceObserver entry (Chromium; recorded as
 *     unsupported elsewhere, where the heartbeat still answers the question);
 *   - `performance.memory.usedJSHeapSize` where the engine exposes it, sampled
 *     on every beat, so a queue that grows forever shows up as heap growth.
 * assertMainThreadHealthy() then requires that the page answers an evaluate
 * within a deadline, that the heartbeat is still ticking, and that no gap, long
 * task, heap size or element count crossed its ceiling.
 *
 * A page frozen in a synchronous loop never answers the evaluate at all, so the
 * deadline is what fails it; the ceilings catch the slower variants.
 */
import type { BrowserContext, Page } from '@playwright/test';
import { expect } from '../fixtures/wp-fixture';

export type WatchdogStats = {
  installed: boolean;
  beats: number;
  maxGapMs: number;
  sinceLastBeatMs: number;
  longTaskSupported: boolean;
  longTasks: number;
  maxLongTaskMs: number;
  heapMb: number | null;
  maxHeapMb: number | null;
  elements: number;
};

export type WatchdogLimits = {
  /** One evaluate must come back within this. */
  evaluateTimeoutMs?: number;
  /** Trivial evaluates taken, 250 ms apart. */
  samples?: number;
  /** Largest tolerated gap between two 50 ms heartbeats. */
  maxGapMs?: number;
  /** Longest tolerated single long task (Chromium only). */
  maxLongTaskMs?: number;
  /** Largest tolerated JS heap (Chromium only). */
  maxHeapMb?: number;
  /** Largest tolerated element count. */
  maxElements?: number;
};

const DEFAULT_LIMITS: Required<WatchdogLimits> = {
  evaluateTimeoutMs: 3_000,
  samples: 3,
  // Generous on purpose: the shared test site runs under load during the full
  // suite and a busy renderer can miss beats for a second or two. A freeze is
  // unbounded, so a ceiling of seconds still separates the two cleanly.
  maxGapMs: 5_000,
  maxLongTaskMs: 5_000,
  maxHeapMb: 512,
  maxElements: 50_000,
};

/** Runs in the page before any page script. Kept free of closures over test scope. */
function watchdogInitScript(): void {
  const w = window as unknown as Record<string, unknown> & { performance: Performance };
  if (w.__fazWatchdog) return;
  const nativeSetTimeout = window.setTimeout.bind(window);
  const now = (): number => w.performance.now();
  const state = {
    beats: 0,
    lastBeat: now(),
    maxGap: 0,
    longTaskSupported: false,
    longTasks: 0,
    maxLongTask: 0,
    maxHeap: 0,
  };
  w.__fazWatchdog = state;
  const heap = (): number => {
    const memory = (w.performance as unknown as { memory?: { usedJSHeapSize?: number } }).memory;
    return memory && typeof memory.usedJSHeapSize === 'number' ? memory.usedJSHeapSize : 0;
  };
  const beat = (): void => {
    const t = now();
    const gap = t - state.lastBeat;
    if (gap > state.maxGap) state.maxGap = gap;
    state.lastBeat = t;
    state.beats += 1;
    const h = heap();
    if (h > state.maxHeap) state.maxHeap = h;
    nativeSetTimeout(beat, 50);
  };
  nativeSetTimeout(beat, 50);
  try {
    const observer = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        state.longTasks += 1;
        if (entry.duration > state.maxLongTask) state.maxLongTask = entry.duration;
      }
    });
    observer.observe({ type: 'longtask', buffered: true } as PerformanceObserverInit);
    state.longTaskSupported = true;
  } catch {
    state.longTaskSupported = false;
  }
}

/** Install on the context before the first navigation; every page it opens is watched. */
export async function installMainThreadWatchdog(context: BrowserContext): Promise<void> {
  await context.addInitScript(watchdogInitScript);
}

/** Evaluate with a hard deadline: a frozen renderer never settles a plain evaluate. */
export async function evaluateWithin<T>(page: Page, fn: () => T | Promise<T>, ms: number): Promise<{ ok: true; value: T } | { ok: false }> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  const timeout = new Promise<{ ok: false }>((resolve) => {
    timer = setTimeout(() => resolve({ ok: false }), ms);
  });
  const evaluation = page.evaluate(fn).then((value) => ({ ok: true as const, value }));
  evaluation.catch(() => undefined);
  try {
    return await Promise.race([evaluation, timeout]);
  } finally {
    if (timer) clearTimeout(timer);
  }
}

/** Whether DOMContentLoaded arrived within `ms`. Protocol-driven, so it works on a frozen page too. */
export async function reachedDomContentLoaded(page: Page, ms: number): Promise<boolean> {
  try {
    await page.waitForLoadState('domcontentloaded', { timeout: ms });
    return true;
  } catch {
    return false;
  }
}

export async function readWatchdog(page: Page, ms = 3_000): Promise<WatchdogStats | null> {
  const answer = await evaluateWithin(
    page,
    () => {
      const w = window as unknown as Record<string, unknown> & { performance: Performance };
      const s = w.__fazWatchdog as
        | { beats: number; lastBeat: number; maxGap: number; longTaskSupported: boolean; longTasks: number; maxLongTask: number; maxHeap: number }
        | undefined;
      const memory = (w.performance as unknown as { memory?: { usedJSHeapSize?: number } }).memory;
      const heap = memory && typeof memory.usedJSHeapSize === 'number' ? memory.usedJSHeapSize : null;
      return {
        installed: !!s,
        beats: s ? s.beats : 0,
        maxGapMs: s ? Math.round(s.maxGap) : 0,
        sinceLastBeatMs: s ? Math.round(w.performance.now() - s.lastBeat) : 0,
        longTaskSupported: s ? s.longTaskSupported : false,
        longTasks: s ? s.longTasks : 0,
        maxLongTaskMs: s ? Math.round(s.maxLongTask) : 0,
        heapMb: heap === null ? null : Math.round(heap / 1048576),
        maxHeapMb: s && s.maxHeap > 0 ? Math.round(s.maxHeap / 1048576) : null,
        elements: document.getElementsByTagName('*').length,
      };
    },
    ms,
  );
  return answer.ok ? answer.value : null;
}

/**
 * Fail unless the page stayed responsive for the whole page view so far.
 * Returns the stats so a caller can annotate them.
 */
export async function assertMainThreadHealthy(page: Page, label: string, limits: WatchdogLimits = {}): Promise<WatchdogStats> {
  const l = { ...DEFAULT_LIMITS, ...limits };
  for (let i = 0; i < l.samples; i += 1) {
    const answer = await evaluateWithin(page, () => new Promise<number>((resolve) => setTimeout(() => resolve(1), 0)), l.evaluateTimeoutMs);
    expect(answer.ok, `${label}: the main thread did not answer a trivial evaluate within ${l.evaluateTimeoutMs} ms (frozen page)`).toBe(true);
    await page.waitForTimeout(250);
  }
  const stats = await readWatchdog(page, l.evaluateTimeoutMs);
  expect(stats, `${label}: the watchdog could not be read (frozen page)`).not.toBeNull();
  const s = stats as WatchdogStats;
  expect(s.installed, `${label}: the watchdog init script did not run — install it on the context before navigating`).toBe(true);
  expect(s.beats, `${label}: the heartbeat never fired`).toBeGreaterThan(0);
  expect(s.sinceLastBeatMs, `${label}: the heartbeat stopped ${s.sinceLastBeatMs} ms ago`).toBeLessThan(l.maxGapMs);
  expect(s.maxGapMs, `${label}: the main thread was unavailable for ${s.maxGapMs} ms at a stretch`).toBeLessThan(l.maxGapMs);
  if (s.longTaskSupported) {
    expect(s.maxLongTaskMs, `${label}: a single long task ran for ${s.maxLongTaskMs} ms`).toBeLessThan(l.maxLongTaskMs);
  }
  const heap = Math.max(s.heapMb ?? 0, s.maxHeapMb ?? 0);
  if (s.heapMb !== null || s.maxHeapMb !== null) {
    expect(heap, `${label}: the JS heap reached ${heap} MB (unbounded queue growth?)`).toBeLessThan(l.maxHeapMb);
  }
  expect(s.elements, `${label}: the document holds ${s.elements} elements`).toBeLessThan(l.maxElements);
  return s;
}

/** Close a context without letting a hung renderer hang the test's teardown. */
export async function closeWithin(context: BrowserContext, ms = 15_000): Promise<void> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  await Promise.race([
    context.close().catch(() => undefined),
    new Promise<void>((resolve) => {
      timer = setTimeout(resolve, ms);
    }),
  ]);
  if (timer) clearTimeout(timer);
}
