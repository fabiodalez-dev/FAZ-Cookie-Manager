/**
 * JS unit test (jsdom) — the runtime gates <video>/<audio> src and poster,
 * <embed src>, <object data>, <track src> and <input type=image src> exactly as
 * it gates <img>/<iframe> src.
 *
 * These six element types fetch the way img and iframe do — `<object data>`
 * and `<embed src>` to youtube.com/embed render the full player, a nested
 * browsing context that sets YSC/VISITOR_INFO1_LIVE; a `poster` or a Vimeo
 * direct-file `src` transmits the visitor's IP and referrer to a provider the
 * catalogue classifies as marketing. The deferred bootstrap held them during
 * the download window on the premise ("replay uses the runtime's own
 * property/setAttribute interceptors") that the runtime would judge the
 * replay. It did not: no setter, setAttribute, observer or sweep covered
 * them, so a held assignment was merely delayed, a post-handoff assignment
 * was never judged, and server-rendered markup lived for the page view with
 * no placeholder and no re-evaluation on consent. The restore pass already
 * knew the park keys (data-faz-src, data-faz-poster, data-faz-data); this
 * pins the gate half.
 *
 * Runtime half (frontend/js/script.js), loaded the way img-iframe-src-gate
 * loads it: the property setters, setAttribute, the parsed-markup park
 * helper, the MutationObserver, the startup sweep, and the restore.
 * Both files: a `video.src` / `setAttribute('poster')` / `object.data` held
 * by the bootstrap is parked at the handoff rather than applied.
 *
 * Run: node tests/unit/js/media-embed-object-gate.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');
const BOOTSTRAP_PATH = resolve(HERE, '../../../frontend/js/bootstrap.js');
const runtimeCode = readFileSync(SCRIPT_PATH, 'utf8');
const bootstrapCode = readFileSync(BOOTSTRAP_PATH, 'utf8');

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

const SITE = 'http://site.test/';
const YT_EMBED = 'https://www.youtube.com/embed/NL2UmY9oKow';
const YT_POSTER = 'https://i.ytimg.com/vi/NL2UmY9oKow/hqdefault.jpg';
const VIMEO_MP4 = 'https://player.vimeo.com/external/123456.sd.mp4?s=abc';
const VIMEO_POSTER = 'https://i.vimeocdn.com/video/123456_640.jpg';
const OWN_MP4 = '/wp-content/uploads/clip.mp4';

function runtimeConfig() {
  return {
    _block: '1',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'marketing', isNecessary: false },
    ],
    _services: [{ id: 'youtube', patterns: ['youtube.com', 'ytimg.com'] }, { id: 'vimeo', patterns: ['vimeo.com', 'vimeocdn.com'] }],
    _providersToBlock: [
      { re: 'youtube.com/embed', categories: ['marketing'], service: 'youtube' },
      { re: 'ytimg.com', categories: ['marketing'], service: 'youtube' },
      { re: 'player.vimeo.com', categories: ['marketing'], service: 'vimeo' },
      { re: 'i.vimeocdn.com', categories: ['marketing'], service: 'vimeo' },
    ],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    i18n: {},
  };
}

function loadRuntime(window) {
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  window.eval(runtimeCode);
  window.document.addEventListener = realAdd;
}
function runtimeOnly({ body = '' } = {}) {
  const dom = new JSDOM(`<!DOCTYPE html><html><head></head><body>${body}</body></html>`, { runScripts: 'outside-only', url: SITE });
  const { window } = dom;
  window._fazConfig = runtimeConfig();
  window.fazcookie = { _fazGetFromStore: () => undefined };
  // Captured before the runtime patches the prototype: the only way to write
  // an attribute the way the parser does, with no gate in front of it.
  const nativeSetAttribute = window.Element.prototype.setAttribute;
  loadRuntime(window);
  return { window, document: window.document, nativeSetAttribute };
}
const tick = () => new Promise((r) => setTimeout(r, 0));

// ---------------------------------------------------------------------------
console.log('runtime: the property setters');
{
  const { window, document } = runtimeOnly();
  const video = document.createElement('video');
  video.src = VIMEO_MP4;
  eq('video.src = vimeo direct file is parked', video.getAttribute('data-faz-src'), VIMEO_MP4);
  eq('with no live src', video.getAttribute('src'), null);
  eq('under the provider\'s category', video.getAttribute('data-faz-category'), 'marketing');
  const poster = document.createElement('video');
  poster.poster = YT_POSTER;
  eq('video.poster = ytimg frame is parked', poster.getAttribute('data-faz-poster'), YT_POSTER);
  eq('with no live poster', poster.getAttribute('poster'), null);
  const audio = document.createElement('audio');
  audio.src = VIMEO_MP4;
  eq('audio.src is parked', audio.getAttribute('data-faz-src'), VIMEO_MP4);
  eq('with no live src', audio.getAttribute('src'), null);
  const embed = document.createElement('embed');
  embed.src = YT_EMBED;
  eq('embed.src = youtube embed is parked', embed.getAttribute('data-faz-src'), YT_EMBED);
  eq('with no live src', embed.getAttribute('src'), null);
  const object = document.createElement('object');
  object.data = YT_EMBED;
  eq('object.data = youtube embed is parked', object.getAttribute('data-faz-data'), YT_EMBED);
  eq('with no live data', object.getAttribute('data'), null);
  const track = document.createElement('track');
  track.src = VIMEO_MP4;
  eq('track.src is parked', track.getAttribute('data-faz-src'), VIMEO_MP4);
  eq('with no live src', track.getAttribute('src'), null);
  const input = document.createElement('input');
  input.type = 'image';
  input.src = YT_POSTER;
  eq('input[type=image].src is parked', input.getAttribute('data-faz-src'), YT_POSTER);
  eq('with no live src', input.getAttribute('src'), null);
  // <source> inside a <video>/<audio>: its `src` lives on HTMLSourceElement,
  // not on HTMLMediaElement, so the media gate never saw it.
  const source = document.createElement('source');
  source.src = VIMEO_MP4;
  eq('source.src = vimeo direct file is parked', source.getAttribute('data-faz-src'), VIMEO_MP4);
  eq('with no live src', source.getAttribute('src'), null);
  const holder = document.createElement('video');
  const inPlace = document.createElement('source');
  holder.appendChild(inPlace);
  document.body.appendChild(holder);
  inPlace.src = VIMEO_MP4;
  eq('a connected <source>.src assignment is parked too', inPlace.getAttribute('src'), null);
  const ownSource = document.createElement('source');
  ownSource.src = OWN_MP4;
  eq('a same-origin source src stays live', ownSource.getAttribute('src'), OWN_MP4);
  // Controls: same-origin and non-provider cross-origin stay live.
  const own = document.createElement('video');
  own.src = OWN_MP4;
  eq('a same-origin video src stays live', own.getAttribute('src'), OWN_MP4);
  eq('and is not parked', own.getAttribute('data-faz-src'), null);
  const cdn = document.createElement('video');
  cdn.poster = 'https://cdn.the-site.com/hero.jpg';
  eq('a non-provider poster stays live', cdn.getAttribute('poster'), 'https://cdn.the-site.com/hero.jpg');
  const img = document.createElement('img');
  img.src = YT_POSTER;
  eq('premise: the identical URL on <img> is parked (the gate img had all along)', img.getAttribute('data-faz-src'), YT_POSTER);
}

console.log('\nruntime: setAttribute');
{
  const { window, document } = runtimeOnly();
  const video = document.createElement('video');
  video.setAttribute('src', VIMEO_MP4);
  eq('video.setAttribute("src") is parked', video.getAttribute('data-faz-src'), VIMEO_MP4);
  eq('with no live src', video.getAttribute('src'), null);
  video.setAttribute('poster', VIMEO_POSTER);
  eq('video.setAttribute("poster") is parked', video.getAttribute('data-faz-poster'), VIMEO_POSTER);
  eq('with no live poster', video.getAttribute('poster'), null);
  const audio = document.createElement('audio');
  audio.setAttribute('src', VIMEO_MP4);
  eq('audio.setAttribute("src") is parked', audio.getAttribute('src'), null);
  const embed = document.createElement('embed');
  embed.setAttribute('src', YT_EMBED);
  eq('embed.setAttribute("src") is parked', embed.getAttribute('src'), null);
  const object = document.createElement('object');
  object.setAttribute('data', YT_EMBED);
  eq('object.setAttribute("data") is parked', object.getAttribute('data-faz-data'), YT_EMBED);
  eq('with no live data', object.getAttribute('data'), null);
  const track = document.createElement('track');
  track.setAttribute('src', VIMEO_MP4);
  eq('track.setAttribute("src") is parked', track.getAttribute('src'), null);
  const input = document.createElement('input');
  input.setAttribute('type', 'image');
  input.setAttribute('src', YT_POSTER);
  eq('input.setAttribute("src") is parked', input.getAttribute('src'), null);
  const own = document.createElement('object');
  own.setAttribute('data', '/own.pdf');
  eq('a same-origin object data stays live', own.getAttribute('data'), '/own.pdf');
}

console.log('\nruntime: parsed markup (the observer\'s park helper)');
{
  const { window, document, nativeSetAttribute } = runtimeOnly();
  const park = window.eval('_fazParkResourceElementIfBlocked');
  function parsed(tag, attr, url, extra) {
    const el = document.createElement(tag);
    (extra || []).forEach(([k, v]) => nativeSetAttribute.call(el, k, v));
    nativeSetAttribute.call(el, attr, url);
    park(el);
    return el;
  }
  eq('a parsed <video src> is parked', parsed('video', 'src', VIMEO_MP4).getAttribute('src'), null);
  const vp = parsed('video', 'poster', YT_POSTER);
  eq('a parsed <video poster> is parked', vp.getAttribute('poster'), null);
  eq('into data-faz-poster', vp.getAttribute('data-faz-poster'), YT_POSTER);
  eq('a parsed <audio src> is parked', parsed('audio', 'src', VIMEO_MP4).getAttribute('src'), null);
  eq('a parsed <embed src> is parked', parsed('embed', 'src', YT_EMBED).getAttribute('src'), null);
  const od = parsed('object', 'data', YT_EMBED);
  eq('a parsed <object data> is parked', od.getAttribute('data'), null);
  eq('into data-faz-data', od.getAttribute('data-faz-data'), YT_EMBED);
  eq('a parsed <track src> is parked', parsed('track', 'src', VIMEO_MP4).getAttribute('src'), null);
  eq('a parsed <input type=image src> is parked', parsed('input', 'src', YT_POSTER, [['type', 'image']]).getAttribute('src'), null);
  eq('a parsed <input type=text src> is left alone (it fetches nothing)', parsed('input', 'src', YT_POSTER, [['type', 'text']]).getAttribute('src'), YT_POSTER);
  eq('a same-origin <video src> is left alone', parsed('video', 'src', OWN_MP4).getAttribute('src'), OWN_MP4);
}

console.log('\nruntime: markup written after the handoff (MutationObserver)');
{
  const { window, document } = runtimeOnly();
  document.body.innerHTML = '<div id="lazy">'
    + '<video id="v" src="' + VIMEO_MP4 + '" poster="' + YT_POSTER + '"></video>'
    + '<audio id="a" src="' + VIMEO_MP4 + '"></audio>'
    + '<embed id="e" src="' + YT_EMBED + '">'
    + '<object id="o" data="' + YT_EMBED + '"></object>'
    + '<track id="t" src="' + VIMEO_MP4 + '">'
    + '<input id="i" type="image" src="' + YT_POSTER + '">'
    + '<video id="own" src="' + OWN_MP4 + '"></video>'
    + '</div>';
  await tick();
  eq('innerHTML <video src> is parked', document.getElementById('v').getAttribute('src'), null);
  eq('innerHTML <video poster> is parked', document.getElementById('v').getAttribute('poster'), null);
  eq('innerHTML <audio src> is parked', document.getElementById('a').getAttribute('src'), null);
  eq('innerHTML <embed src> is parked', document.getElementById('e').getAttribute('src'), null);
  eq('innerHTML <object data> is parked', document.getElementById('o').getAttribute('data'), null);
  eq('innerHTML <track src> is parked', document.getElementById('t').getAttribute('src'), null);
  eq('innerHTML <input type=image src> is parked', document.getElementById('i').getAttribute('src'), null);
  eq('the same-origin video keeps its src', document.getElementById('own').getAttribute('src'), OWN_MP4);
}

console.log('\nruntime: server-rendered markup (the startup sweep)');
{
  const { document } = runtimeOnly({
    body: '<video id="v" src="' + VIMEO_MP4 + '" poster="' + VIMEO_POSTER + '"></video>'
      + '<audio id="a" src="' + VIMEO_MP4 + '"></audio>'
      + '<embed id="e" src="' + YT_EMBED + '">'
      + '<object id="o" data="' + YT_EMBED + '"></object>'
      + '<track id="t" src="' + VIMEO_MP4 + '">'
      + '<input id="i" type="image" src="' + YT_POSTER + '">'
      + '<video id="own" src="' + OWN_MP4 + '" poster="/own.jpg"></video>',
  });
  eq('a server-rendered <video src> is parked by the sweep', document.getElementById('v').getAttribute('src'), null);
  eq('and its poster', document.getElementById('v').getAttribute('poster'), null);
  eq('a server-rendered <audio src> is parked', document.getElementById('a').getAttribute('src'), null);
  eq('a server-rendered <embed src> is parked', document.getElementById('e').getAttribute('src'), null);
  eq('a server-rendered <object data> is parked', document.getElementById('o').getAttribute('data'), null);
  eq('a server-rendered <track src> is parked', document.getElementById('t').getAttribute('src'), null);
  eq('a server-rendered <input type=image src> is parked', document.getElementById('i').getAttribute('src'), null);
  eq('the site\'s own video keeps its src', document.getElementById('own').getAttribute('src'), OWN_MP4);
  eq('and its poster', document.getElementById('own').getAttribute('poster'), '/own.jpg');
}

console.log('\nruntime: what the gate parks, the restore pass hands back on consent');
{
  const { window, document } = runtimeOnly();
  const video = document.createElement('video');
  video.src = VIMEO_MP4;
  video.poster = VIMEO_POSTER;
  const object = document.createElement('object');
  object.data = YT_EMBED;
  const embed = document.createElement('embed');
  embed.src = YT_EMBED;
  document.body.append(video, object, embed);
  eq('premise: parked', video.getAttribute('src') === null && object.getAttribute('data') === null && embed.getAttribute('src') === null, true);
  window.fazcookie._fazGetFromStore = (k) => (k === 'marketing' ? 'yes' : undefined);
  window.eval('_fazUnblockServerSide()');
  eq('video src restored after consent', video.getAttribute('src'), VIMEO_MP4);
  eq('video poster restored after consent', video.getAttribute('poster'), VIMEO_POSTER);
  eq('object data restored after consent', object.getAttribute('data'), YT_EMBED);
  eq('embed src restored after consent', embed.getAttribute('src'), YT_EMBED);
  eq('park keys cleared', video.getAttribute('data-faz-src') === null && video.getAttribute('data-faz-poster') === null && object.getAttribute('data-faz-data') === null, true);
}

console.log('\nboth files: an assignment held by the bootstrap is judged at the handoff, not replayed');
{
  // The download window: the bootstrap holds video.src / setAttribute('poster')
  // / object.data, and at the handoff replays each as `el[prop] = value` or
  // `el.setAttribute(name, value)` on whatever setter is then installed. With
  // no runtime gate on these prototypes that was the native setter, and the
  // hold had only delayed the request.
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { runScripts: 'outside-only', url: SITE });
  const { window } = dom;
  const { document } = window;
  Object.defineProperty(document, 'readyState', { value: 'loading', configurable: true });
  window._fazConfig = runtimeConfig();
  window.fazcookie = { _fazGetFromStore: () => undefined };
  const nativeGetAttribute = window.Element.prototype.getAttribute;
  window.eval(bootstrapCode);
  const video = document.createElement('video');
  video.src = VIMEO_MP4;
  video.setAttribute('poster', YT_POSTER);
  const object = document.createElement('object');
  object.data = YT_EMBED;
  const embed = document.createElement('embed');
  embed.setAttribute('src', YT_EMBED);
  const own = document.createElement('video');
  own.src = OWN_MP4;
  const source = document.createElement('source');
  source.src = VIMEO_MP4;
  video.appendChild(source);
  document.body.appendChild(video);
  document.body.appendChild(object);
  document.body.appendChild(embed);
  document.body.appendChild(own);
  eq('premise: the src is held, not applied', nativeGetAttribute.call(video, 'src'), null);
  loadRuntime(window); // start() + finish(): the handoff replays every hold
  eq('held video.src is parked at the handoff', nativeGetAttribute.call(video, 'data-faz-src'), VIMEO_MP4);
  eq('and not applied', nativeGetAttribute.call(video, 'src'), null);
  eq('held setAttribute("poster") is parked at the handoff', nativeGetAttribute.call(video, 'data-faz-poster'), YT_POSTER);
  eq('and not applied', nativeGetAttribute.call(video, 'poster'), null);
  eq('held object.data is parked at the handoff', nativeGetAttribute.call(object, 'data-faz-data'), YT_EMBED);
  eq('and not applied', nativeGetAttribute.call(object, 'data'), null);
  eq('held embed.setAttribute("src") is parked at the handoff', nativeGetAttribute.call(embed, 'data-faz-src'), YT_EMBED);
  eq('and not applied', nativeGetAttribute.call(embed, 'src'), null);
  eq('the site\'s own held video.src is applied', nativeGetAttribute.call(own, 'src'), OWN_MP4);
  eq('held source.src is parked at the handoff', nativeGetAttribute.call(source, 'data-faz-src'), VIMEO_MP4);
  eq('and not applied', nativeGetAttribute.call(source, 'src'), null);
  source.src = VIMEO_MP4;
  eq('a source.src assignment after the handoff is judged by the runtime', nativeGetAttribute.call(source, 'src'), null);
}

console.log(`\nmedia-embed-object-gate: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
