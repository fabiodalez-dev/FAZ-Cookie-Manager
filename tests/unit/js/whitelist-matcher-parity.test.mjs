/**
 * JS unit test (jsdom) — the runtime's user whitelist matches a URL the way the
 * server's matches_whitelist_pattern() / matches_domain_pattern() do.
 *
 * One operator setting (Script Blocking Exceptions) is judged by two layers:
 * PHP on the server-rendered markup, _fazIsUserWhitelisted() on everything the
 * client gates (img/iframe src, link href, CSS url(), the MutationObserver, the
 * fetch/XHR/beacon/WebSocket interceptors). The JS side was a substring test on
 * the raw URL — query and fragment included, host unanchored — while the PHP
 * contract it claims to mirror cuts the value at `?`/`#` unless the pattern
 * names one, and host-anchors any pattern whose pre-slash part is a hostname.
 * So `googleapis.com/youtube/v3` exempted evilgoogleapis.com at runtime, and
 * whitelisting one's own domain released every GA/Meta beacon whose query
 * carried the page URL. The identical tag in server markup stayed parked.
 *
 * Shapes and expectations below are the PHP matcher's, taken from
 * frontend/class-frontend.php (matches_whitelist_pattern, matches_domain_pattern)
 * and tests/unit/test-unwatched-resource-routes-php.php (M3).
 *
 * Run: node tests/unit/js/whitelist-matcher-parity.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
    console.log(`       expected: ${JSON.stringify(expected)}`);
    console.log(`       actual:   ${JSON.stringify(actual)}`);
  }
}

function loadFrontend() {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { runScripts: 'outside-only', url: 'https://site.test/' });
  const { window } = dom;
  window._fazConfig = {
    _block: '1',
    _categories: [{ slug: 'necessary', isNecessary: true }, { slug: 'marketing', isNecessary: false }],
    _services: [],
    _providersToBlock: [
      { re: 'facebook.com', categories: ['marketing'], service: 'meta' },
      { re: 'google-analytics.com', categories: ['marketing'], service: 'ga' },
    ],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    i18n: {},
  };
  window.fazcookie = { _fazGetFromStore: () => undefined };
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  window.eval(readFileSync(SCRIPT_PATH, 'utf8'));
  window.document.addEventListener = realAdd;
  return window;
}

const w = loadFrontend();
const cfg = w._fazConfig;
const wl = (url, el) => w.eval('_fazIsUserWhitelisted')(url, el || null);

console.log('whitelist: a domain+path pattern is host-anchored (matches_domain_pattern)');
cfg._userWhitelist = ['googleapis.com/youtube/v3'];
eq('the pattern host itself matches', wl('https://googleapis.com/youtube/v3/x.js'), true);
eq('a sub-domain on a label boundary matches', wl('https://www.googleapis.com/youtube/v3/x.js'), true);
eq('and a query on the legitimate URL does not spoil it', wl('https://www.googleapis.com/youtube/v3/x.js?key=abc'), true);
eq('a look-alike host (evilgoogleapis.com) does not match', wl('https://evilgoogleapis.com/youtube/v3/t.js'), false);
eq('a suffix look-alike (googleapis.com.attacker.net) does not match', wl('https://googleapis.com.attacker.net/youtube/v3/t.js'), false);
eq('the pattern in another host\'s query does not match', wl('https://www.facebook.com/tr?id=1&ev=PageView&r=googleapis.com/youtube/v3'), false);
eq('the pattern in another host\'s path does not match', wl('https://cdn.tracker.test/googleapis.com/youtube/v3/t.js'), false);
eq('the path is prefix-anchored: a different path on the right host does not match', wl('https://www.googleapis.com/maps/api/js'), false);
eq('a protocol-relative URL is parsed and matches', wl('//www.googleapis.com/youtube/v3/x.js'), true);
eq('a backslash spelling is normalised first and matches', wl('https:\\\\www.googleapis.com/youtube/v3/x.js'), true);
eq('a relative URL can never satisfy a domain pattern', wl('/youtube/v3/x.js'), false);

console.log('\nwhitelist: a host-only pattern with a trailing slash (the shipped defaults)');
cfg._userWhitelist = ['hcaptcha.com/'];
eq('the host matches', wl('https://hcaptcha.com/1/api.js'), true);
eq('a sub-domain matches', wl('https://js.hcaptcha.com/1/api.js'), true);
eq('a look-alike host does not', wl('https://nothcaptcha.com/1/api.js'), false);
eq('the token in a tracker\'s query does not', wl('https://www.facebook.com/tr?r=hcaptcha.com/'), false);

console.log('\nwhitelist: a bare host pattern (no slash) is a URL fragment judged without query/fragment');
cfg._userWhitelist = ['site.test'];
eq('the site\'s own absolute URL matches', wl('https://site.test/wp-content/plugins/x/x.js'), true);
eq('a GA beacon carrying the page URL in dl= does not match (query cut)', wl('https://www.google-analytics.com/collect?v=1&dl=https%3A%2F%2Fsite.test%2F'), false);
eq('nor with the page URL decoded in the query', wl('https://www.google-analytics.com/collect?v=1&dl=https://site.test/'), false);
eq('nor in the fragment', wl('https://www.facebook.com/tr#site.test'), false);

console.log('\nwhitelist: a filename pattern stays a loose match on host+path, never on the query');
cfg._userWhitelist = ['jquery.min.js'];
eq('the file itself matches on any host', wl('https://cdn.test/js/jquery.min.js'), true);
eq('with a version query', wl('https://cdn.test/js/jquery.min.js?ver=3.7.1'), true);
eq('a pixel carrying the token in its query is the pixel (M3 on the server)', wl('https://cdn.tracker.test/pixel.png?x=jquery.min.js'), false);
cfg._userWhitelist = ['wp-includes/'];
eq('a bare path fragment matches in the path', wl('https://cdn.tracker.test/wp-includes/js/x.js'), true);
eq('and not in the fragment', wl('https://cdn.tracker.test/pixel.png#wp-includes/'), false);

console.log('\nwhitelist: a pattern that names a query itself is matched with the query');
// A filename pattern, so the loose branch applies: the value keeps its query
// because the pattern has one (strpbrk($pattern, '?#') on the server).
cfg._userWhitelist = ['pixel.png?x=1'];
eq('the full URL with that query matches', wl('https://example.test/pixel.png?x=1'), true);
eq('the same path with another query does not', wl('https://example.test/pixel.png?x=2'), false);
// A dotted-host pattern is host-anchored and its path is compared with the
// request path, which never carries a query — so the server says false here
// too, and so does this side. Pinned so the two cannot drift apart.
cfg._userWhitelist = ['example.test/pixel.png?x=1'];
eq('a host pattern with a query never matches (same as the server)', wl('https://example.test/pixel.png?x=1'), false);

console.log('\nwhitelist: ID and class tokens are untouched');
cfg._userWhitelist = ['my-tracker'];
const el = w.document.createElement('script');
el.id = 'my-tracker';
eq('a bare token matches the element id', wl('https://www.facebook.com/tr', el), true);
eq('and never a URL', wl('https://cdn.test/my-tracker.js'), false);
cfg._userWhitelist = ['js'];
eq('a two-character token matches nothing', wl('https://cdn.test/js/app.js'), false);

console.log('\nwhitelist: the decision reaches the gates');
cfg._userWhitelist = ['site.test'];
const sb = (url) => w.eval('_fazImgShouldBlock')(null, url);
eq('a Meta pixel whose query carries the whitelisted site is still blocked', sb('https://www.facebook.com/tr?id=1&dl=https%3A%2F%2Fsite.test%2F'), true);
cfg._userWhitelist = ['googleapis.com/youtube/v3'];
// Provider rules for both hosts, so the gate has something to block and only
// the whitelist can tell the two apart. Without them neither URL is a
// provider, the gate says false either way, and the check proves nothing.
// Two rules, because the provider matcher is label-anchored: 'googleapis.com'
// alone does not match evilgoogleapis.com.
const lookAlikeRules = [
  { re: 'evilgoogleapis.com', categories: ['marketing'], service: 'lookalike' },
  { re: 'googleapis.com', categories: ['marketing'], service: 'gapi' },
];
cfg._providersToBlock.push(...lookAlikeRules);
eq('premise: a provider rule blocks the look-alike host', w.eval('_fazShouldBlockProvider')('https://evilgoogleapis.com/youtube/v3/t.js'), true);
eq('premise: and the legitimate host, before the whitelist is applied', w.eval('_fazShouldBlockProvider')('https://www.googleapis.com/youtube/v3/x.js'), true);
eq('a provider look-alike host is not exempted by the pattern', sb('https://evilgoogleapis.com/youtube/v3/t.js'), true);
eq('control: the whitelisted host itself is exempted at the gate', sb('https://www.googleapis.com/youtube/v3/x.js'), false);
cfg._providersToBlock.splice(-lookAlikeRules.length, lookAlikeRules.length);
cfg._userWhitelist = [];

console.log(`\nwhitelist-matcher-parity: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
