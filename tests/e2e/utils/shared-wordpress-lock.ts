import { createHash, randomUUID } from 'node:crypto';
import { mkdirSync, readFileSync, renameSync, rmSync, statSync, utimesSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// These integration specs activate/deactivate real plugins and rewrite shared
// WordPress options. Playwright workers are separate processes, so an in-memory
// mutex cannot stop two files from changing the same installation at once.
const scope = createHash('sha1')
  .update(process.env.WP_PATH ?? process.env.WP_BASE_URL ?? 'faz-e2e-default')
  .digest('hex')
  .slice(0, 12);
const LOCK_DIR = join(tmpdir(), `faz-e2e-shared-wordpress-${scope}.lock`);
const OWNER_FILE = join(LOCK_DIR, 'owner.json');

// A live owner refreshes the directory mtime, but staleness is never decided by
// time alone: slow serial specs can legitimately exceed the old 15-minute TTL.
// The lock is recoverable only when the recorded process is no longer alive.
const STALE_MS = 2 * 60_000;
const HEARTBEAT_MS = 30_000;
const POLL_MS = 100;
const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));
type LockOwner = { token: string; pid: number; createdAt: number };

let heldToken: string | null = null;
let heartbeat: ReturnType<typeof setInterval> | null = null;

function readOwner(): LockOwner | null {
  try {
    const value = JSON.parse(readFileSync(OWNER_FILE, 'utf8')) as Partial<LockOwner>;
    if (typeof value.token !== 'string' || typeof value.pid !== 'number' || typeof value.createdAt !== 'number') {
      return null;
    }
    return value as LockOwner;
  } catch {
    return null;
  }
}

function processIsAlive(pid: number): boolean {
  if (!Number.isSafeInteger(pid) || pid <= 0) return false;
  try {
    process.kill(pid, 0);
    return true;
  } catch (error) {
    // EPERM means the process exists but cannot be signalled by this user.
    return (error as NodeJS.ErrnoException).code === 'EPERM';
  }
}

function startHeartbeat(token: string): void {
  heartbeat = setInterval(() => {
    const owner = readOwner();
    if (!owner || owner.token !== token) {
      if (heartbeat) clearInterval(heartbeat);
      heartbeat = null;
      heldToken = null;
      return;
    }
    try {
      const now = new Date();
      utimesSync(LOCK_DIR, now, now);
    } catch {
      // A lost/renamed lock is detected by the owner-token check on the next tick.
    }
  }, HEARTBEAT_MS);
  heartbeat.unref();
}

function recoverDeadLock(): boolean {
  try {
    if (Date.now() - statSync(LOCK_DIR).mtimeMs <= STALE_MS) return false;
    const owner = readOwner();
    if (owner && processIsAlive(owner.pid)) return false;

    // Rename is the atomic claim: only one contender can quarantine this exact
    // stale directory. A new owner can create LOCK_DIR immediately afterwards,
    // and cleanup below can never delete that replacement.
    const staleDir = `${LOCK_DIR}.stale-${randomUUID()}`;
    renameSync(LOCK_DIR, staleDir);
    rmSync(staleDir, { recursive: true, force: true });
    return true;
  } catch {
    return false;
  }
}

export async function acquireSharedWordPressLock(timeoutMs = 40 * 60_000): Promise<void> {
  if (heldToken) {
    throw new Error(`Shared WordPress E2E lock already held by this worker: ${LOCK_DIR}`);
  }
  const deadline = Date.now() + timeoutMs;
  for (;;) {
    try {
      mkdirSync(LOCK_DIR);
      const owner: LockOwner = { token: randomUUID(), pid: process.pid, createdAt: Date.now() };
      try {
        writeFileSync(OWNER_FILE, JSON.stringify(owner), { encoding: 'utf8', flag: 'wx' });
      } catch (error) {
        rmSync(LOCK_DIR, { recursive: true, force: true });
        throw error;
      }
      heldToken = owner.token;
      startHeartbeat(owner.token);
      return;
    } catch {
      if (recoverDeadLock()) continue;
      if (Date.now() > deadline) {
        throw new Error(`Timed out acquiring shared WordPress E2E lock: ${LOCK_DIR}`);
      }
      await sleep(POLL_MS);
    }
  }
}

export function releaseSharedWordPressLock(): void {
  if (heartbeat) clearInterval(heartbeat);
  heartbeat = null;

  const token = heldToken;
  heldToken = null;
  if (!token) return;

  const owner = readOwner();
  if (!owner || owner.token !== token) return;

  // The token check prevents an old holder from deleting a replacement lock.
  // Active owners are never reclaimed, so no valid contender can replace the
  // directory between this check and removal.
  rmSync(LOCK_DIR, { recursive: true, force: true });
}
