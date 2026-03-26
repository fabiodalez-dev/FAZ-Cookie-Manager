import type { ChildProcessWithoutNullStreams } from 'node:child_process';
import { spawn } from 'node:child_process';
import { get } from 'node:http';
import { dirname, join } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';

async function probe(url: string): Promise<boolean> {
  return new Promise((resolve) => {
    const request = get(url, (response) => {
      response.resume();
      resolve((response.statusCode ?? 0) >= 200 && (response.statusCode ?? 0) < 400);
    });

    request.on('error', () => resolve(false));
    request.setTimeout(1000, () => {
      request.destroy();
      resolve(false);
    });
  });
}

export async function startServerScanLab(port = 10080): Promise<ChildProcessWithoutNullStreams> {
  const utilsDir = dirname(fileURLToPath(import.meta.url));
  const root = join(utilsDir, '..', 'fixtures', 'server-scan-lab');
  const processRef = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root], {
    stdio: 'ignore',
  });

  const probeUrl = `http://127.0.0.1:${port}/?scenario=ping`;
  for (let attempt = 0; attempt < 40; attempt += 1) {
    if (await probe(probeUrl)) {
      return processRef;
    }
    await delay(250);
  }

  processRef.kill('SIGTERM');
  throw new Error(`Server-scan lab did not become ready on ${probeUrl}.`);
}

export async function stopServerScanLab(processRef: ChildProcessWithoutNullStreams | null | undefined): Promise<void> {
  if (!processRef || processRef.killed) {
    return;
  }
  processRef.kill('SIGTERM');
  await delay(250);
}
