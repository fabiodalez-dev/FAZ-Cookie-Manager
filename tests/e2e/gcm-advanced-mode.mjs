/**
 * Repro + regression guard for Advanced Consent Mode (#165).
 *
 * Contract:
 *   Advanced ON  → the gtag-direct stack (gtag.js / GA4 / Ads) loads before
 *                  consent (NOT converted to type="text/plain"), a synchronous
 *                  denied `consent default` is printed inline in <head>, and the
 *                  GTM container (gtm.js) + non-Google trackers stay blocked.
 *   Advanced OFF → everything is hard-blocked as before (Basic mode), and no
 *                  inline consent default is emitted.
 *
 * Drives wp-cli to flip faz_gcm_settings, renders a fixture page carrying the
 * four script types with no consent cookie, and asserts the rendered HTML.
 * Restores GCM-off and deletes the fixture page on exit.
 *
 * Run: WP_BASE_URL=http://127.0.0.1:9998 WP_PATH=/Users/fabio/Sites/faz-test \
 *      node tests/e2e/gcm-advanced-mode.mjs
 */

import { execFileSync } from 'node:child_process';

const WP = process.env.WP_BASE_URL || 'http://127.0.0.1:9998';
const WP_PATH = process.env.WP_PATH || '/Users/fabio/Sites/faz-test';
const SLUG = 'gcm-advanced-test';

function wp(args) {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}
function setGcm(status, advanced) {
  wp(['eval', `$s=get_option('faz_gcm_settings',array());$s['status']=${status?'true':'false'};$s['advanced_mode']=${advanced?'true':'false'};update_option('faz_gcm_settings',$s);do_action('faz_clear_cache');`]);
  wp(['db', 'query', "DELETE FROM wp_options WHERE option_name LIKE 'faz_banner_template%'"]);
}
async function fetchPage() {
  const res = await fetch(`${WP}/${SLUG}/`, { redirect: 'follow' });
  return res.text();
}

let failures = 0;
function assert(name, cond) { console.log(`  ${cond ? 'PASS' : 'FAIL'} ${name}`); if (!cond) failures++; }

// Fixture: a page with the four script types, inserted with kses disabled so
// the raw <script> tags survive into the rendered output.
const setupPhp = `kses_remove_filters();
$c = <<<HTML
<p>GCM Advanced test (#165).</p>
<script src="https://www.googletagmanager.com/gtag/js?id=G-TESTADV1"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('config','G-TESTADV1');</script>
<script src="https://www.googletagmanager.com/gtm.js?id=GTM-TESTADV1"></script>
<script src="https://connect.facebook.net/en_US/fbevents.js"></script>
HTML;
$e = get_page_by_path('${SLUG}', OBJECT, 'page');
$a = array('post_title'=>'GCM Advanced Test','post_name'=>'${SLUG}','post_status'=>'publish','post_type'=>'page','post_content'=>$c);
if ($e) { $a['ID']=$e->ID; echo wp_update_post($a); } else { echo wp_insert_post($a); }`;

const gtagBlocked = (h) => /<script[^>]*gtag\/js[^>]*type=["']text\/plain["']/.test(h);
const gtmBlocked = (h) => /<script[^>]*gtm\.js[^>]*type=["']text\/plain["']/.test(h);
const fbBlocked = (h) => /<script[^>]*fbevents[^>]*type=["']text\/plain["']/.test(h);
const hasInlineDefault = (h) => /gtag\('consent','default',\{[^}]*"ad_storage":"denied"/.test(h);

try {
  wp(['eval', setupPhp]);

  console.log('## Advanced ON');
  setGcm(true, true);
  let html = await fetchPage();
  assert('synchronous denied consent default printed inline in <head>', hasInlineDefault(html));
  assert('gtag.js loads before consent (NOT type=text/plain)', !gtagBlocked(html));
  assert('GTM container (gtm.js) still blocked', gtmBlocked(html));
  assert('non-Google tracker (facebook) still blocked', fbBlocked(html));

  console.log('## Advanced OFF (Basic mode — no regression)');
  setGcm(true, false);
  html = await fetchPage();
  assert('gtag.js hard-blocked again', gtagBlocked(html));
  assert('no inline consent default emitted', !hasInlineDefault(html));
} finally {
  try { setGcm(false, false); } catch { /* ignore */ }
  try { wp(['post', 'delete', String(wp(['eval', `$p=get_page_by_path('${SLUG}',OBJECT,'page');echo $p?$p->ID:0;`])), '--force']); } catch { /* ignore */ }
}

console.log(`\n=== ${failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'} ===`);
process.exit(failures === 0 ? 0 : 1);
