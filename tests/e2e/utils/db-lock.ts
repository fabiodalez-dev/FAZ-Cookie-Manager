import { mkdirSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// Cross-worker mutex for the shared `wp_faz_cookies` table.
//
// The GVL vendor auto-detect and Cookie Policy service auto-detect
// specs both read and mutate *discovered* rows at table scope: the
// suggest endpoints `SELECT DISTINCT domain ... WHERE discovered = 1`
// across the whole table, and the cookie-policy "zero discovered
// cookies" tests wipe every discovered row (then restore) to assert
// the empty-inventory path deterministically. Each spec is `mode:
// 'serial'` internally, so within a file there is no overlap — but
// with Playwright `workers > 1` (CI runs 2) the two *files* can land
// on different workers and interleave, so one spec's global SELECT can
// observe the other spec's transient wipe window. That makes both
// suites flaky in CI even though each is correct in isolation.
//
// We serialise the two specs against each other (and nothing else)
// with an atomic `mkdir` lockfile: `mkdirSync` succeeds for exactly
// one caller and throws `EEXIST` for the rest, which is the portable
// filesystem primitive for a mutex shared across separate Node
// processes (Playwright workers are processes, so an in-memory lock
// would not see each other). Every other spec keeps parallelising
// freely. A spec holds the lock for its whole lifetime (acquire in
// `beforeAll`, release in `afterAll`); since each spec is internally
// serial, whole-spec exclusivity is the simplest guarantee that no
// read ever races the other spec's wipe.
const LOCK_DIR = join(tmpdir(), 'faz-e2e-faz_cookies.lock');

// Reclaim a lock a crashed/killed worker never released, so a single
// hard failure can't wedge every later run. Comfortably longer than a
// single spec's wall-clock; short enough that a genuinely abandoned
// lock clears within one retry budget.
const STALE_MS = 180_000;
const POLL_MS = 100;

const sleep = (ms: number): Promise<void> => new Promise((res) => setTimeout(res, ms));

/**
 * Block until this worker owns the cookies-table lock. Spin-polls an
 * atomic mkdir lockfile, stealing it only if the current holder looks
 * dead (older than STALE_MS). Throws if the lock can't be acquired
 * within `timeoutMs` — a loud failure beats a silent deadlock.
 */
export async function acquireCookiesTableLock(timeoutMs = 600_000): Promise<void> {
  const deadline = Date.now() + timeoutMs;
  for (;;) {
    try {
      mkdirSync(LOCK_DIR); // atomic create-or-fail: the mutex.
      return;
    } catch {
      try {
        if (Date.now() - statSync(LOCK_DIR).mtimeMs > STALE_MS) {
          // Holder is presumed dead — reclaim and retry immediately.
          rmSync(LOCK_DIR, { recursive: true, force: true });
          continue;
        }
      } catch {
        // Lock vanished between our mkdir and stat (holder released) —
        // retry the mkdir straight away.
        continue;
      }
      if (Date.now() > deadline) {
        throw new Error(
          `Timed out after ${timeoutMs}ms acquiring the wp_faz_cookies table lock (${LOCK_DIR})`,
        );
      }
      await sleep(POLL_MS);
    }
  }
}

/**
 * Release the cookies-table lock. Safe to call when not held (force
 * + recursive make it a no-op if the directory is already gone), so an
 * `afterAll` can call it unconditionally.
 */
export function releaseCookiesTableLock(): void {
  rmSync(LOCK_DIR, { recursive: true, force: true });
}
