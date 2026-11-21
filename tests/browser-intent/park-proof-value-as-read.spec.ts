/**
 * The server signs a parked value as the browser reads it back.
 *
 * The runtime sends the park-proofs route the values getAttribute() returns,
 * and the route signs those again, so Frontend::attr_value_as_read() has to
 * produce exactly that string from the raw markup or a real park is refused
 * and stays parked after consent. Two spellings used to differ: a numeric
 * reference that spells a named one (`&#38;quot;`, read as the text
 * `&quot;`, decoded twice to `"` on the server) and a literal CR or CRLF,
 * which the parser turns into LF before the attribute exists.
 *
 * Each raw value is served in a real document (not setContent, so the bytes
 * go through the parser's input stream) and read back with getAttribute();
 * PHP computes the server's reading of the same bytes.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const CASES = [
  'a&#38;quot;b',
  'x\r\ny',
  'x\ry',
  'background:url(&quot;https://t.example.test/a.png&quot;)',
  '&amp;amp;',
  '&ampx',
  '&amp=',
  'end&quot',
  '&#x26;lt;',
  '&notit;',
  '&#0;&#xD800;&#x110000;',
];

function serverReadings(values: string[]): string[] {
  const frontend = path.resolve('frontend/class-frontend.php');
  const source = 'define("ABSPATH", sys_get_temp_dir() . "/"); require $argv[1];'
    + ' $m = new ReflectionMethod("FazCookie\\\\Frontend\\\\Frontend", "attr_value_as_read"); $m->setAccessible(true);'
    + ' echo json_encode(array_map(function ($v) use ($m) { return $m->invoke(null, $v); }, json_decode($argv[2], true)));';
  return JSON.parse(execFileSync(process.env.PHP_BIN || 'php', ['-n', '-r', source, frontend, JSON.stringify(values)], { encoding: 'utf8' }));
}

test('the signed reading of a parked value is the one getAttribute() returns', async ({ page }) => {
  const body = CASES.map((raw, i) => `<div id="v${i}" data-faz-style="${raw}"></div>`).join('');
  await page.route('https://site.example.test/**', (route) => route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html><html><body>${body}</body></html>` }));
  await page.goto('https://site.example.test/');
  const browser = await page.evaluate((n) => Array.from({ length: n }, (_, i) => document.getElementById(`v${i}`)!.getAttribute('data-faz-style')), CASES.length);
  const server = serverReadings(CASES);
  CASES.forEach((raw, i) => {
    expect(server[i], `server reading of ${JSON.stringify(raw)}`).toBe(browser[i]);
  });
});
