import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const script = readFileSync(new URL('../../../admin/assets/js/pages/cookie-policy.js', import.meta.url), 'utf8');
let checks = 0;
for (const saved of [undefined, true, false]) {
  const dom = new JSDOM(`
    <div id="faz-cookie-policy-app" data-faz-rest-url="https://example.test/settings/">
      <form id="faz-cookie-policy-form">
        <input type="checkbox" name="obfuscate_emails" checked>
      </form>
      <button id="cp-preview-btn"></button>
      <span id="cp-save-status"></span>
      <div id="cp-preview-modal" hidden><button class="faz-cp-modal-close"></button></div>
    </div>
  `, { runScripts: 'outside-only' });
  const { window } = dom;
  const posts = [];
  window.fetch = async (url, options) => {
    if (options.method === 'POST') posts.push(JSON.parse(options.body));
    const settings = saved === undefined ? {} : { obfuscate_emails: saved };
    return { ok: true, json: async () => options.method === 'GET' && url.endsWith('/settings') ? settings : {} };
  };
  window.eval(script);
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  await new Promise(resolve => setTimeout(resolve, 0));
  const checkbox = window.document.querySelector('[name="obfuscate_emails"]');
  assert.equal(checkbox.checked, saved ?? true); checks++;
  for (const enabled of [false, true]) {
    checkbox.checked = enabled;
    window.document.getElementById('faz-cookie-policy-form').dispatchEvent(new window.Event('submit', { cancelable: true }));
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(posts.at(-1).obfuscate_emails, enabled); checks++;
    window.document.getElementById('cp-preview-btn').click();
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(posts.at(-1).settings.obfuscate_emails, enabled); checks++;
  }
  window.close();
}
console.log(`email-obfuscation-setting: ${checks} passed, 0 failed`);
