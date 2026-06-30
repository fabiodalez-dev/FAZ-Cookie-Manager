/**
 * Repro + regression guard for the banner-template build-locale bug
 * (reported for sk_SK on wordpress.org, topic "Translation").
 *
 * Symptom: on a single-language install whose WordPress site locale is
 * non-English but whose FAZ banner language is the un-configured 'en'
 * default, the cached banner template is force-built in en_US. Strings
 * baked into that cache — "Always Active" and the cookie-audit-table
 * headers "Cookie"/"Duration"/"Description" — stayed English, while
 * runtime strings resolved outside the cache ("Show more"/"Show less")
 * DID translate to the site language. Confusing half-translated chrome.
 *
 * Root cause: Template::generate() switched to faz_wp_locale(
 * faz_current_language() ), and faz_current_language() returns the FAZ
 * default ('en'), ignoring get_locale(). Fix: Template::resolve_build_locale()
 * follows the WP site locale when the banner language is the un-configured
 * 'en' on a non-multilingual install. The audit-table headers were ALSO
 * never wrapped in __(); class-shortcodes.php::translate_header() now routes
 * them through translate_default_text().
 *
 * Uses de_DE as the proxy locale (its bundled .mo has all four strings).
 * Drives wp-cli to flip the WP locale + FAZ default language, busts the
 * template cache, renders the homepage, and asserts the chrome translates.
 * Restores en_US / FAZ 'en' on exit.
 *
 * Run: WP_BASE_URL=http://127.0.0.1:9998 WP_PATH=/Users/fabio/Sites/faz-test \
 *      node tests/e2e/locale-banner-translation.mjs
 */

import { execFileSync } from 'node:child_process';
import { existsSync, copyFileSync, rmSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';

const WP = process.env.WP_BASE_URL || 'http://127.0.0.1:9998';
const WP_PATH = process.env.WP_PATH;
if (!WP_PATH) {
  console.error('WP_PATH not set: export WP_PATH to the WordPress install root.');
  process.exit(1);
}
const PLUGIN_DIR = join(WP_PATH, 'wp-content', 'plugins', 'faz-cookie-manager');
const MO_SRC = join(PLUGIN_DIR, 'languages', 'faz-cookie-manager-de_DE.mo');
const MO_DST_DIR = join(WP_PATH, 'wp-content', 'languages', 'plugins');
const MO_DST = join(MO_DST_DIR, 'faz-cookie-manager-de_DE.mo');

function wp(args) {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}
function bust() { try { wp(['db', 'query', "DELETE FROM wp_options WHERE option_name LIKE 'faz_banner_template%'"]); } catch { /* ignore */ } }
function setFaz(selected, def) {
  wp(['eval', `$s=get_option('faz_settings',array()); $s['languages']['selected']=${selected}; $s['languages']['default']='${def}'; update_option('faz_settings',$s); faz_current_language(true);`]);
}
async function fetchHome() {
  const res = await fetch(WP + '/', { redirect: 'follow' });
  return res.text();
}
const count = (h, s) => (h.match(new RegExp('>' + s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '<', 'g')) || []).length;

let failures = 0;
function assert(name, cond) { console.log(`  ${cond ? 'PASS' : 'FAIL'} ${name}`); if (!cond) failures++; }

if (!existsSync(MO_SRC)) {
  console.log(`SKIP — bundled de_DE .mo not found at ${MO_SRC}`);
  process.exit(0);
}

try {
  mkdirSync(MO_DST_DIR, { recursive: true });
  copyFileSync(MO_SRC, MO_DST);

  console.log('## Scenario 1 — WP de_DE + FAZ default "en" (the reported bug)');
  // install --activate is the unified, non-deprecated path: it installs the
  // language pack first (fresh CI installs lack it) then activates it.
  wp(['language', 'core', 'install', 'de_DE', '--activate']);
  setFaz("['en']", 'en');
  bust();
  let html = await fetchHome();
  assert('"Always Active" translated to "Immer aktiv"', count(html, 'Immer aktiv') > 0 && count(html, 'Always Active') === 0);
  assert('header "Description" translated to "Beschreibung"', count(html, 'Beschreibung') > 0 && count(html, 'Description') === 0);

  console.log('## Scenario 2 — WP en_US + FAZ default "en" (no regression, all English)');
  wp(['language', 'core', 'install', 'en_US', '--activate']);
  setFaz("['en']", 'en');
  bust();
  html = await fetchHome();
  assert('"Always Active" stays English', count(html, 'Always Active') > 0 && count(html, 'Immer aktiv') === 0);

  console.log('## Scenario 3 — WP en_US + FAZ explicit "de" (deliberate case preserved)');
  setFaz("['en','de']", 'de');
  bust();
  html = await fetchHome();
  assert('explicit FAZ German still wins', count(html, 'Immer aktiv') > 0 && count(html, 'Always Active') === 0);
} finally {
  // Restore original state.
  try { wp(['language', 'core', 'install', 'en_US', '--activate']); } catch { /* ignore */ }
  try { setFaz("['en']", 'en'); } catch { /* ignore */ }
  try { rmSync(MO_DST, { force: true }); } catch { /* ignore */ }
  bust();
}

console.log(`\n=== ${failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'} ===`);
process.exit(failures === 0 ? 0 : 1);
