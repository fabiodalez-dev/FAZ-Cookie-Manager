import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

test.use({ javaScriptEnabled: false });

test('random PHP email encoding remains readable and clickable without JavaScript', async ({ page }) => {
  const helper = path.resolve('includes/class-email-obfuscation.php');
  const source = String.raw`
    define('ABSPATH', '.');
    function esc_html($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8', false); }
    require $argv[1];
    $emails = array("privacy+test&'box@example.test", 'dpo@example.test');
    $html = '';
    foreach ($emails as $email) {
      $html .= '<p><a href="mailto:' . esc_html($email) . '">' . esc_html($email) . '</a></p>';
    }
    echo \FazCookie\Includes\Email_Obfuscation::apply($html, $emails);
  `;
  const render = () => execFileSync(process.env.PHP_BIN || 'php', ['-n', '-r', source, helper], { encoding: 'utf8' });
  const html = render();
  expect(html).not.toContain('@');
  expect(render()).not.toBe(html);
  await page.setContent(html);
  const emails = ["privacy+test&'box@example.test", 'dpo@example.test'];
  for (let i = 0; i < emails.length; i++) {
    const link = page.locator('a').nth(i);
    await expect(link).toHaveText(emails[i]);
    await expect(link).toHaveAttribute('href', 'mailto:' + emails[i]);
  }
  expect(await page.locator('a').count()).toBe(2);
});
