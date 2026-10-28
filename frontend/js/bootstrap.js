/**
 * Synchronous handoff for the deferred consent runtime. No network request is
 * needed for this small inline bootstrap. Dynamic scripts and requests wait
 * until the full runtime can apply the visitor's actual consent decisions.
 *
 * There is deliberately no timeout that releases tracking when a bundle fails,
 * but there IS a release path, and it is not a guarantee of silence: if the
 * runtime has not arrived by DOMContentLoaded, releaseSafely() drains the queue
 * itself against a policy whose only rule is `sameOriginOnly`. Same-origin work
 * runs — a site's own cart fragments, AJAX forms and lazy loaders must not be
 * taken down with the trackers — and everything cross-origin stays parked, for
 * the rest of the page view, because the interceptors are never uninstalled on
 * that path. Nothing third-party is released without a consent decision; some
 * first-party work is.
 *
 * Known limits, shared with the runtime. Each one is reproducible, and each is
 * out of scope for this file rather than a gap it has not got to:
 *   - a same-origin URL that REDIRECTS to a third party is released, because
 *     nothing on the client can see where a URL lands before requesting it
 *     (see local() below); server-side blocking is where that is answered;
 *   - an SVG `href` written through the animated attribute object,
 *     `image.href.baseVal = url`, reaches the engine without a setter or
 *     setAttribute on the element: SVGAnimatedString.baseVal is not
 *     interceptable without wrapping the `href` GETTER to hand out a proxy,
 *     which neither file does. Libraries write SVG URLs through
 *     setAttribute/setAttributeNS, which both files gate;
 *   - an inline third-party snippet — a <script> with no src and none of the
 *     plugin's own blocking attributes — runs: nothing here can read a body
 *     and tell a tracker from a page builder's configuration, and the runtime
 *     keys its script gate on the same attributes (see resources() below);
 *   - the `contentWindow` of a same-origin frame is another realm with its
 *     own prototypes, and nothing here is installed in it: a script that
 *     fetches through `frame.contentWindow.fetch` or builds elements with the
 *     frame's document is judged by whatever that frame's own page loaded;
 *   - page code that runs SYNCHRONOUSLY inside a replay on the safety release
 *     — a load handler fired by an insertion, a MutationObserver callback
 *     delivered in the same task — runs with the `replaying` flag set and
 *     passes every wrapper here unjudged for that extent; the flag exists so a
 *     replay is not caught by its own interceptor, and it cannot tell a
 *     replay from code the replay happened to trigger;
 *   - `el.style.backgroundImage = 'url(…)'`, `el.style.setProperty(…)` and
 *     `el.style.cssText` write CSS through CSSStyleDeclaration, which neither
 *     file gates; the server-side pass rewrites inline `style` attributes it
 *     renders, and that is the coverage there is;
 *   - srcdoc is judged by the absolute and protocol-relative URLs the markup
 *     names: a relative URL inside the sub-document resolves against the
 *     parent's origin and is released, as the redirect limit above says, and
 *     nothing inside that document is intercepted once it is parsed;
 *   - a held insertion takes its node out of where it was at once, but the
 *     node only ARRIVES at the handoff: until then its parentNode is a private
 *     fragment and the destination does not list it (see stash()). A removal
 *     out of a <style> whose remainder could fetch happens at once as well,
 *     with that one sheet switched off until the remainder is judged (see
 *     suspendSheet()). Text changes INSIDE a sheet — the data setters,
 *     appendData and its siblings, textContent on a <style> — are still held
 *     as a whole. A loop that waits on one of them to take effect stops
 *     growing the queue at the fuse (LIMIT), but on the safety release a
 *     refused one does not happen at all, so such a loop would not end there.
 *     No such idiom is known to be in use; the insertion and removal idioms,
 *     which are, end.
 */
(function (w, d) {
    'use strict';
    // _fazRuntimeStarted: the runtime already ran, which only happens when an
    // optimiser deferred this inline script past it. Arming now would put
    // these interceptors on top of the runtime's gates, release at once as if
    // the runtime were missing, and stay that way for the page view.
    // A consent store on window.fazcookie is the same fact for a runtime that
    // predates this file: it never sets _fazRuntimeStarted, but it creates
    // that Map as its first act, so finding one means it has already run.
    if (w._fazBootstrap || w._fazRuntimeStarted || !w._fazConfig || !w._fazConfig._block) return;
    if (w.fazcookie && w.fazcookie._fazConsentStore) return;
    var queue = [], undo = [], scripts = [], pending = true, handoffs = [], staged = new WeakMap();
    // The fuse. Every held operation is a closure kept alive until the
    // handoff, and nothing about a page bounds how many it makes: a loop that
    // waits for a held insertion to take effect queued one per iteration,
    // synchronously, until the tab ran out of memory (LiteSpeed serving the
    // 1.34.0 runtime under 1.34.1 markup did exactly that inside
    // _fazRenderBanner). Past this many, the bootstrap stops queuing and
    // applies its safety policy instead (see defer()), and the lists below
    // that grow with page activity are capped at the same figure.
    var LIMIT = 5000;
    // Set by releaseSafely() only. From then on the interceptors stay installed
    // and every new decision is taken immediately against this policy instead
    // of being queued for a runtime that is not coming. Without it the natives
    // were restored at DOMContentLoaded and the page had no client-side
    // enforcement at all from that point on, while the warning said the
    // opposite.
    var immediate = null;
    // Replaying a held task means doing the very thing the wrappers intercept —
    // `el.src = value`, `w.fetch(…)`, `self.appendChild(node)`. On the normal
    // handoff that is safe because start() has already uninstalled everything.
    // The safety release deliberately leaves the wrappers in place, so without
    // this flag every replay would be caught by its own interceptor and either
    // held a second time or, once the queue drains immediately, re-enter
    // forever. While it is set the wrappers pass straight through to the
    // natives they captured.
    var replaying = 0;
    // The attribute accessors below are replaced further down, and the
    // replacements answer from `staged`. Park bookkeeping has to see and write
    // the real DOM, so capture the natives before anything shadows them — the
    // same reason script.js writes its own park attributes natively.
    var nativeGet = Element.prototype.getAttribute;
    var nativeSet = Element.prototype.setAttribute;
    var nativeHas = Element.prototype.hasAttribute;
    var nativeRemove = Element.prototype.removeAttribute;
    // The legacy SVG `xlink:href` is a NAMESPACED attribute, and the engine
    // resolves it by namespace and local name, not by its qualified name: a
    // library may write it as `setAttributeNS(XLINK, 'p:href', url)` with any
    // prefix it likes, and getAttribute('xlink:href') then answers null while
    // the image fetches. The three accessors below ask by namespace for that
    // one key, and fall back to the qualified spelling, which is what a plain
    // setAttribute('xlink:href') produces.
    var XLINK = 'http://www.w3.org/1999/xlink';
    var nativeGetNS = Element.prototype.getAttributeNS;
    var nativeHasNS = Element.prototype.hasAttributeNS;
    var nativeRemoveNS = Element.prototype.removeAttributeNS;
    // Parking a stylesheet has to clear the live text WITHOUT going through the
    // gate that holds such a write — otherwise the clear is itself deferred and
    // nothing happens. holdCSS() defines its setter on the <style> interfaces,
    // never on Node.prototype, so this descriptor stays the real one, and
    // calling it directly bypasses the subclass property entirely.
    var nativeTextSet = (Object.getOwnPropertyDescriptor(Node.prototype, 'textContent') || {}).set;
    // Captured before the insertion wrappers below shadow it: emptySheet()
    // puts one empty text node into a parked <style>, and that write must not
    // be judged by the very gate that is parking the element.
    var nativeAppend = Node.prototype.appendChild;
    // Captured for the same reason: a held insertion takes its node out of
    // where it is NOW (stash() below), and that move must not be judged — or
    // held — by the wrapper that is holding the insertion.
    var nativeRemoveChild = Node.prototype.removeChild;
    var nativeFragment = Document.prototype.createDocumentFragment;
    function attr(el, name) {
        try {
            if (name === 'xlink:href') {
                var namespaced = nativeGetNS.call(el, XLINK, 'href');
                return namespaced != null ? namespaced : nativeGet.call(el, name);
            }
            return nativeGet.call(el, name);
        } catch (e) { return null; }
    }
    function has(el, name) {
        try {
            if (name === 'xlink:href' && nativeHasNS.call(el, XLINK, 'href')) return true;
            return nativeHas.call(el, name);
        } catch (e) { return false; }
    }
    // What a network hold judges, and what it later hands to the native: the
    // bootstrap twin of _fazNetworkTarget() in script.js, read ONCE, when the
    // call is made, which is when the native itself reads its argument.
    //
    // The holds used to judge one reading and pass the input on for another:
    // fetch read `input.url` by duck typing while the native stringifies
    // anything that is not a genuine Request, and every hold re-read the input
    // at release. An object whose string has state (the site's own API on the
    // first reading, a tracker on the next) was judged on one URL and sent to
    // the other, and on the safety release this judgement is the only one.
    // So: a genuine Request (brand-checked through the native getter captured
    // here, before any page script runs) travels as itself, its URL is in an
    // internal slot that cannot change; a URL object becomes its href; anything
    // else its string, and that string is both judged and passed. `error` is
    // set when the input cannot be stringified: the caller fails the way the
    // native would rather than hand it a value it would read a second time.
    function brandGetter(ctor, name) {
        try {
            var desc = typeof ctor === 'function' && Object.getOwnPropertyDescriptor(ctor.prototype, name);
            return desc && typeof desc.get === 'function' ? desc.get : null;
        } catch (e) { return null; }
    }
    var requestUrl = brandGetter(w.Request, 'url');
    var urlHref = brandGetter(w.URL, 'href');
    function networkTarget(input) {
        if (input == null) return { url: '', pass: input, error: null };
        if (typeof input === 'string') return { url: input, pass: input, error: null };
        if (typeof input === 'object') {
            if (requestUrl) {
                try {
                    var fromRequest = requestUrl.call(input);
                    if (typeof fromRequest === 'string') return { url: fromRequest, pass: input, error: null };
                } catch (e) { /* not a Request */ }
            }
            if (urlHref) {
                try {
                    var href = urlHref.call(input);
                    if (typeof href === 'string') return { url: href, pass: href, error: null };
                } catch (e) { /* not a URL */ }
            }
        }
        try {
            var text = String(input);
            return { url: text, pass: text, error: null };
        } catch (e) {
            return { url: '', pass: input, error: e };
        }
    }
    function setAttr(el, name, value) { try { nativeSet.call(el, name, value); } catch (e) { /* detached or read-only */ } }
    function dropAttr(el, name) {
        try {
            if (name === 'xlink:href') nativeRemoveNS.call(el, XLINK, 'href');
            nativeRemove.call(el, name);
        } catch (e) { /* noop */ }
    }
    // One place that decides whether a task runs now or at the handoff, so no
    // call site has to know which of the two states the bootstrap is in.
    function defer(task) {
        // Past the fuse the queue is released, not grown: the safety policy
        // decides what is held so far and everything after it, which is what
        // would happen at DOMContentLoaded anyway, only sooner. A runtime that
        // arrives later still takes over (finish(), the `late` branch).
        if (!immediate && queue.length >= LIMIT) releaseSafely('overflow');
        if (!immediate) return queue.push(task);
        run(task, immediate);
    }
    // The bypass is opened ONLY for the safety release, which is the only path
    // that replays while the wrappers are still installed. On the normal handoff
    // start() has already uninstalled them and `bridge.current` routes a replay
    // into the runtime's own gate — which is the point, because the runtime is
    // what applies real consent. Setting the flag there too would have opened a
    // global pass-through window for every synchronously-reachable piece of page
    // JavaScript, on the path where it buys nothing.
    function run(task, policy) {
        var bypass = policy === safety;
        if (bypass) replaying++;
        try { task(policy); }
        catch (error) { w.console.warn('[FAZ Cookie Manager] Deferred resource failed', error); }
        finally { if (bypass) replaying--; }
    }
    // Parents that already have a held insertion. A later sibling with no
    // resource of its own must queue behind them, or it lands first and the
    // sibling order inverts: appendChild(liWithImg) then appendChild(liText)
    // used to render liText above liWithImg.
    var waiting = new WeakSet();
    // "Can this reach a third party?", not "is this literally my origin?".
    //   * data:, blob:, about: and javascript: make no network request at all —
    //     a blob is same-origin by construction and a data: URL carries its own
    //     bytes. Judging them by origin called them remote (`new URL('data:,')
    //     .origin` is the string "null") and a safety release then refused a
    //     site's own inline SVG.
    //   * ws:/wss: never equal an http(s) origin, so a same-origin socket was
    //     always refused while the fetch and XHR gates let the identical host
    //     through. Compare the host for those two schemes.
    //
    // Known limit, and it is a limit of the question rather than of this code: a
    // same-origin URL that REDIRECTS to a third party — an open redirect, a
    // `/out?url=` click tracker — is released, because nothing on the client can
    // see where a URL will land before requesting it. The runtime has the same
    // limit; its provider rules match the URL it is given, not the one it ends
    // on. Server-side blocking is where that case is actually answered.
    function local(url) {
        var value = String(url == null ? '' : url);
        if (!value || value.charAt(0) === '#') return true;
        var resolved;
        try { resolved = new URL(value, d.baseURI); }
        catch (e) { return false; }
        // Judged on the RESOLVED protocol, never on how the string starts.
        // Matching `/^(?:data|blob|about):/` on the raw text put this function
        // and the URL parser into disagreement, and the disagreement was
        // exploitable: `blob:https://tracker.example/uuid` begins with an
        // allowed scheme while its resolved origin is a third party, so the
        // string test called it same-origin and a safety release let it
        // through. `javascript:` was in that list too, which was simply wrong —
        // it runs code rather than fetching, and its resolved origin is "null",
        // so it now falls through to the origin comparison and stays held, the
        // behaviour this file had before the scheme list was added.
        //
        // data: and about: carry no network destination at all: a data URL is
        // its own bytes, about:blank is nothing. Judging them by origin called
        // them remote (`origin` is the string "null") and a safety release
        // refused a site's own inline SVG.
        if (resolved.protocol === 'data:' || resolved.protocol === 'about:') return true;
        // ws:/wss: never equal an http(s) origin, so a same-origin socket was
        // always refused while fetch to the identical host was allowed.
        if (resolved.protocol === 'ws:' || resolved.protocol === 'wss:') return resolved.host === w.location.host;
        // Everything else, blob: included, answers on its resolved origin — for
        // a blob that is the origin that created it, which is the question that
        // matters and the one the engine itself asks.
        return resolved.origin === w.location.origin;
    }
    // srcset and imagesrcset are comma-separated candidate LISTS, and resolving
    // the whole string as one URL answers about the FIRST candidate only:
    // commas and spaces percent-encode into the path, so
    // new URL('/a.png 1x, https://tracker.example/b.png 2x', base).origin is
    // the page's own origin and the tracker candidate is never looked at. The
    // runtime had this exact bug and fixed it by walking every candidate
    // (_fazSrcsetBlockedCategory, script.js, with the explanation still in
    // place where it was written); this is the same shape.
    function candidates(value) {
        return String(value == null ? '' : value).split(',').map(function (part) {
            return part.replace(/^\s+/, '').split(/\s+/)[0];
        }).filter(function (url) { return url !== ''; });
    }
    function isList(key) { return key === 'srcset' || key === 'imagesrcset'; }
    // Every absolute or protocol-relative URL a string names. Deliberately
    // broad and deliberately not a parser: the question is "could this markup
    // reach a third party", and a sub-document's own resources are beyond
    // anything this file can intercept once it is parsed.
    // The markup is read AFTER the encodings that hide a URL from a literal
    // search: HTML character references (`https:&#47;&#47;tracker`, numeric in
    // both bases and the named `&sol;` / `&colon;`), which the parser decodes
    // before the sub-document sees them; and tab, LF and CR, which the URL
    // parser strips from anywhere in a URL before it reads a scheme or a host
    // (`https:/\t/tracker` is tracker), as the server pass strips them. The
    // JSON escape `\/` is deliberately NOT undone here: see urlsIn().
    // Named references too: the sub-document decodes `&bsol;` to a backslash
    // and `&Tab;` / `&NewLine;` to the characters stripped below, and the list
    // the parser knows is long. A <textarea> parsed by the native DOMParser
    // (captured here, as the predicate below captures its own) decodes every
    // reference the way the parser does without building any element from
    // the markup; a `</textarea` in the text is kept as text. The numeric and
    // `&sol;` / `&colon;` replacements stay as the fallback where no parser is
    // available.
    var MarkupParser = typeof w.DOMParser === 'function' ? w.DOMParser : null;
    var markupParse = MarkupParser && MarkupParser.prototype && typeof MarkupParser.prototype.parseFromString === 'function'
        ? MarkupParser.prototype.parseFromString
        : null;
    var textareaValue = (function () {
        try {
            var desc = w.HTMLTextAreaElement && Object.getOwnPropertyDescriptor(w.HTMLTextAreaElement.prototype, 'value');
            return desc && typeof desc.get === 'function' ? desc.get : null;
        } catch (e) { return null; }
    }());
    function decodeReferences(text) {
        if (!markupParse || text.indexOf('&') === -1) return text;
        try {
            for (var pass = 0; pass < 2; pass++) {
                var doc = markupParse.call(new MarkupParser(), '<textarea>\n' + text.replace(/<\/(textarea)/gi, '&lt;/$1') + '</textarea>', 'text/html');
                var area = doc && doc.getElementsByTagName('textarea')[0];
                if (!area) return text;
                var decoded = String(textareaValue ? textareaValue.call(area) : area.value);
                if (decoded === text) break;
                text = decoded;
            }
        } catch (e) { /* the replacements below still run */ }
        return text;
    }
    function decodeMarkup(value, keepWhitespace) {
        var text = decodeReferences(String(value == null ? '' : value))
            .replace(/&#x([0-9a-f]+);?/gi, function (match, hex) {
                try { return String.fromCodePoint(parseInt(hex, 16)); } catch (e) { return match; }
            })
            .replace(/&#(\d+);?/g, function (match, dec) {
                try { return String.fromCodePoint(parseInt(dec, 10)); } catch (e) { return match; }
            })
            .replace(/&sol;/gi, '/')
            .replace(/&colon;/gi, ':');
        return keepWhitespace ? text : text.replace(/[\t\n\r]/g, '');
    }
    // Every spelling that names a host to the WHATWG parser, not just a
    // literal `//`: for a special scheme a backslash IS a slash, and any run
    // of slashes after `https:` is ignored — so `https:host`, `https:/host`,
    // `\\host`, `/\\host` and `\\/host` all fetch from that host (the
    // scheme-less ones resolve against the page's scheme, which local() asks
    // the parser about). The server judges all of them inside a srcdoc and
    // the runtime's _fazMarkupUrls reads the same shape; this file matched a
    // literal `//` only, found nothing, and an empty list is "same-origin" —
    // the layer disagreement the three files keep falling into, fixed in the
    // other two first (9ab2ef77) and here last.
    var MARKUP_URL = /(?:https?:[\\\/]*|[\\\/]{2})[^\s"'<>)\\\/][^\s"'<>)]*/gi;
    // Namespace names. They are spelled as URLs and are never fetched, and
    // every inline SVG names one: `<svg xmlns="http://www.w3.org/2000/svg">`
    // read as a cross-origin reference, so on the safety release a srcdoc
    // carrying nothing but a first-party icon stayed parked for the page view.
    // Matched by VALUE, not by stripping `xmlns="…"` from the text: an
    // attribute-shaped string outside a tag is text to the tokenizer, and a
    // regex that removed it there would swallow the real markup up to the
    // next quote — `xmlns='</p><img src="https://t/x.png">'` is an image. An
    // exact w3.org namespace name is not a destination anyone tracks with,
    // wherever in the markup it appears.
    var NAMESPACES = [
        'http://www.w3.org/2000/svg', 'http://www.w3.org/1999/xlink', 'http://www.w3.org/1999/xhtml',
        'http://www.w3.org/1998/Math/MathML', 'http://www.w3.org/XML/1998/namespace', 'http://www.w3.org/2000/xmlns/'
    ];
    function namespaceName(url) {
        return NAMESPACES.indexOf(String(url).replace(/\\\//g, '/')) !== -1;
    }
    function urlsIn(value) {
        var found = [];
        // Tab, LF and CR are stripped by the URL parser from inside a URL, so
        // the text is read with them removed; but stripping them from the
        // whole markup also JOINS tokens they kept apart, and
        // `src=http://own/a.png<LF>srcset=https://tracker/p` read as one
        // first-party URL. Both copies are read and the results united; every
        // pass can only add a candidate.
        [decodeMarkup(value), decodeMarkup(value, true)].forEach(function (text, i) {
            if (i === 1 && !/[\t\n\r]/.test(text)) return;
            found = found.concat(text.match(MARKUP_URL) || []);
            // The JSON escape `\/`, which a script embedding its markup as a
            // string leaves behind. Collapsing it to `/` BEFORE the match would
            // turn `\/host` — protocol-relative to the parser — into a
            // first-party path and lose the host, so the escaped form is read
            // as a separate pass, the same union _fazMarkupUrls makes.
            var unescaped = text.replace(/\\\//g, '/');
            if (unescaped !== text) found = found.concat(unescaped.match(MARKUP_URL) || []);
        });
        return found.filter(function (url) { return !namespaceName(url); });
    }
    // A list is same-origin only when EVERY candidate is: the browser picks one
    // by viewport and pixel ratio, so a single remote entry is a request.
    function localValue(key, value) {
        // srcdoc carries a document, so "is this same-origin" is asked of every
        // URL the document names. One remote reference is a request.
        if (key === 'srcdoc') return urlsIn(value).every(local);
        if (!isList(key)) return local(value);
        var list = candidates(value);
        return !list.length || list.every(local);
    }
    function hold(el, name, value, replay) {
        if (!pending || replaying) return replay();
        var values = staged.get(el) || {}, key = name.toLowerCase();
        var record = { value: String(value) };
        values[key] = record;
        staged.set(el, values);
        defer(function (policy) {
            if (values[key] !== record) return;
            delete values[key];
            if (allowed(policy, record.value, key)) return replay();
            // Refused: keep the URL where the runtime's own parking attributes
            // live instead of dropping it, so a later runtime (or a reload)
            // can still restore the resource once consent allows it.
            parkAttribute(el, key, record.value, policy);
        });
    }
    function replace(obj, name, fn) {
        var original = obj[name];
        var bridge = { current: original };
        var installed = fn(original, bridge);
        // Libraries may retain a reference to the temporary wrapper. After
        // handoff such references must reach the permanent consent gate.
        obj[name] = name === 'WebSocket' ? installed : function () {
            return (pending && !replaying ? installed : bridge.current).apply(this, arguments);
        };
        handoffs.push(function () { bridge.current = obj[name]; });
        undo.push(function () { obj[name] = original; });
    }
    // video, audio, embed, object, track and input[type=image] fetch exactly the
    // way img and iframe do. The runtime's own observer is an ALLOWLIST too —
    // _fazParkResourceElementIfBlocked branches on the tag name — and the two
    // lists are the SAME set, kept so on purpose: a tag held here and not gated
    // there is replayed onto the native setter at the handoff, so the hold
    // would only have delayed the request. That was the case for these six
    // until the runtime grew their gates; media-embed-object-gate.test.mjs
    // holds each of them through this file and asserts the runtime parks the
    // replay.
    // `image` and `use` are the SVG namespace's own fetching elements: <image>
    // is an <img> by another name, and <use href="https://x/sprite.svg#icon">
    // fetches an external document. A CSS type selector with no namespace
    // matches any namespace, so one list still covers both trees.
    // `[background]` is an attribute selector on purpose. The legacy
    // presentation attribute fetches an image from <body>, <table>, <tr>, <td>
    // and friends, and listing those TAGS would hold every table on the page
    // and make every written one cost the parser exemption. The attribute is
    // the thing that fetches, so the attribute is what the selector asks for.
    // `feImage` is spelled as the SVG namespace spells it: a type selector is
    // case-sensitive outside the HTML namespace, so `feimage` matches nothing.
    var RESOURCES = 'script,iframe,img,source,link,video,audio,embed,object,track,input,image,use,feImage,[background]';
    // Keyed on the LOCAL name, uppercased. nodeName is uppercase only for HTML
    // elements; for every other namespace it is the name as written, so
    // `attrs[el.nodeName]` was undefined for every SVG element — and that one
    // lookup is what both the attribute gate and the DOM backstop are built on.
    // Measured before this: setAttribute('href', tracker) on an <svg><image>
    // applied live, and the backstop parked neither it nor an SVG
    // <script href>, which EXECUTES rather than merely fetching.
    function tagKey(el) {
        if (!el || el.nodeType !== 1) return '';
        return String(el.localName || el.nodeName).toUpperCase();
    }
    // The URL attributes to hold on THIS element: the per-tag list, plus the
    // legacy `background`, which belongs to no tag in particular — it is
    // honoured on <body>, <table>, <thead>, <tbody>, <tfoot>, <tr>, <td> and
    // <th>, and enumerating them would be a list to forget something from.
    function heldAttrs(el) {
        var list = attrs[tagKey(el)] || [];
        if (list.indexOf('background') === -1 && (nativeHas.call(el, 'background') || (staged.get(el) || {}).background)) {
            return list.concat(['background']);
        }
        return list;
    }
    function resources(node) {
        if (!node || !node.querySelectorAll) return [];
        var found = Array.prototype.slice.call(node.querySelectorAll(RESOURCES));
        if (node.matches && node.matches(RESOURCES)) found.unshift(node);
        return found.filter(function (el) {
            // An INPUT only fetches as type=image; every other input would be
            // held for nothing.
            if (nativeHas.call(el, 'background')) return true;
            if (tagKey(el) === 'INPUT') return String(attr(el, 'type') || '').toLowerCase() === 'image';
            // An inline <script> carrying no src and none of the plugin's own
            // blocking attributes is deliberately NOT held, and the reason is
            // worth stating because the opposite looks safer than it is.
            // Nothing here can read an inline body and tell a tracker snippet
            // from a page builder's configuration — and the runtime cannot
            // either: its script gate keys on data-faz-category /
            // data-fazcookie / data-faz-service, which is exactly what this
            // filter admits. Holding the rest would close no gap the runtime
            // closes and would break the one thing an inline script guarantees,
            // that it has run by the time appendChild returns. Measured: it
            // breaks a same-origin inline configuration read on the next line,
            // which is an ordinary pattern. An inline third-party snippet is a
            // pre-existing gap in both files, to be closed in both or neither.
            return tagKey(el) !== 'SCRIPT' || nativeHas.call(el, 'src') || nativeHas.call(el, 'href')
                || has(el, 'xlink:href') || nativeHas.call(el, 'data-faz-category')
                || nativeHas.call(el, 'data-fazcookie') || nativeHas.call(el, 'data-faz-service');
        });
    }
    function prepare(node, policy) {
        resources(node).forEach(function (el) { policy.node(el); });
    }
    // The resource elements in `node` that an insertion still has to answer
    // for: every one except those the PARSER put in the document. The server-
    // side blocker already judged that markup, and moving it fetches nothing
    // new — the request left when the parser built it, and a URL changed since
    // went through the attribute gates. Holding such a move bought nothing and
    // broke the page: a layout script that regroups the server's own markup
    // (Divi's Salvattore pulls a product gallery out with
    // Range.extractContents() and appends it back in columns) found the
    // gallery empty on its very next line, serialized it through outerHTML,
    // and the held images replayed into the detached copy. WooCommerce product
    // images vanished on every Divi product page. `served` is filled by the
    // DOM backstop, which sees each parser insertion before the next parser-
    // run script, so the record is in place before any script can move it.
    // Scripts are never recorded — see observed().
    //
    // The exemption is for the URL the server judged, not for the element:
    // the record keeps the element's URL attributes as the parser left them,
    // and an element whose URLs differ now is judged like any other. The
    // setters and setAttribute stage a write without touching the attribute,
    // so this matters for the routes they do not cover — an Attr node's
    // value, setAttributeNode, a namespace prefix nobody wrapped. Detach a
    // parser <iframe>, rewrite its src through one of those and insert it
    // back: the iframe loads the new URL on insertion, and without this check
    // it would have loaded with no verdict. Record the RESOLVED destinations
    // too: a changed <base> can redirect an unchanged attribute. Its directory
    // alone is insufficient for a query-relative src like "?pixel", which
    // inherits the base's filename. Resolving each candidate also avoids
    // holding an image at p.jpg just because replaceState stripped utm_* from
    // the document URL: that image's destination did not change. A srcdoc is
    // markup, so retain its full inherited base rather than treating it as a
    // URL. A later runtime can judge the document it would actually create.
    // The base is THIS document's, never el.baseURI: the element resolves
    // against the document it is inserted into, and a script can adoptNode()
    // a parser <img> into a DOMParser document, point this page's <base> at
    // another host and append it back — el.baseURI would still answer the old
    // base and the record would match.
    function urls(el) {
        var names = heldAttrs(el).map(function (p) { return p.slice(p.indexOf(':') + 1).toLowerCase(); });
        var list = el.attributes || [], out = [], base = d.baseURI;
        function resolve(value) {
            try {
                var target = new URL(value, base);
                // A local SVG href points into this document, even after
                // history strips its query. Keep that identity stable without
                // exempting iframe #fragments or SVG links to another base.
                if (el.namespaceURI === 'http://www.w3.org/2000/svg' && local === 'href' && /^\s*#/.test(value)) {
                    var page = new URL(d.URL), resource = new URL(target.href);
                    page.hash = ''; resource.hash = '';
                    if (resource.href === page.href) return ['local-fragment', target.hash];
                }
                return target.href;
            }
            catch (e) { return [base, value]; } // stricter if the URL cannot be resolved
        }
        for (var i = 0; i < list.length; i++) {
            var a = list[i], local = String(a.localName || a.name).toLowerCase();
            if (names.indexOf(local) === -1) continue;
            var targets = local === 'srcdoc' ? [base]
                : (isList(local) ? candidates(a.value) : [a.value]).map(resolve);
            out.push([a.namespaceURI || '', a.name, a.value, targets]);
        }
        // These attributes can turn non-fetching parser markup into a
        // fetching resource without changing its URL. The server leaves a
        // canonical link alone; changing its rel to stylesheet while detached
        // must therefore require a new verdict before it can be reinserted.
        var mode = tagKey(el) === 'LINK' ? 'rel' : tagKey(el) === 'INPUT' ? 'type' : '';
        if (mode) out.push(['mode', mode, attr(el, mode)]);
        // Attribute values can contain newlines. Joining with that delimiter
        // made one background="/empty\nsrc=https://tracker/..." compare equal
        // to background="/empty" plus a new src attribute. A detached parser
        // element could then fetch the new URL without an insertion verdict.
        return JSON.stringify(out.sort());
    }
    function unjudged(node) {
        var found = resources(node);
        if (!served) return found;
        return found.filter(function (el) { return !served.has(el) || served.get(el) !== urls(el); });
    }
    // Every <style> in a subtree, the node itself included, in any namespace —
    // querySelectorAll('style') matches both, since a CSS type selector with no
    // namespace does.
    function sheets(node) {
        if (!node) return [];
        var found = node.querySelectorAll ? Array.prototype.slice.call(node.querySelectorAll('style')) : [];
        if (isStyle(node)) found.unshift(node);
        return found;
    }
    function base64(text) {
        // Same encoding the runtime decodes with (_fazBase64DecodeUtf8), so what
        // is parked here is readable by whatever looks at it next.
        try { return w.btoa(w.unescape(w.encodeURIComponent(text))); } catch (e) { return ''; }
    }
    // A <style> the DOM backstop caught: one a script put in the document
    // through a route with no wrapper of its own — document.write, or markup in
    // a tree the sinks could not tag. The element list above is about elements
    // that fetch BY THEMSELVES; a stylesheet fetches through the CSS engine, so
    // nothing here judged it.
    //
    // What this does and does not buy, stated plainly because the difference is
    // the whole justification. An @import in a sheet the parser has already
    // finished is gone: the request left before any observer was notified, and
    // nothing on this side can unsend it. Every other CSS request in that sheet
    // is LAZY — background-image, @font-face src, cursor, mask, list-style-image
    // are fetched when a matching element is first rendered, which is a frame
    // away, not a microtask — so emptying the sheet here stops them.
    //
    // Emptied here, not handed over in that state: the runtime adopts a parked
    // original only when the live text is the NEUTRALIZED form of it
    // (_fazPrimeStyleCssMarkers, script.js), and an emptied element does not
    // match, so leaving it like this would turn a delayed stylesheet into a
    // dead one — the exact trade parkTarget() below refuses to make. The task
    // below delivers it at the handoff through deliverCss(): judged by the
    // runtime's rules, it lands either live or in the runtime's own parked
    // form, which the runtime then manages as its own.
    //
    // `judge` is the question asked of the text, and it depends on who wrote
    // the sheet. One a script sink built — tagged, so the whole string was in
    // the script's hands — gets the broad cssURL(): a regex cannot tell what
    // an escape or a comment is hiding, and holding costs a replay. One the
    // PARSER built is different: it reaches here only once a document.write
    // has withdrawn the exemption for the server's markup, and from then on
    // every theme stylesheet after it was emptied for a comment or an
    // icon-font escape — a flash of unstyled content until the handoff, and
    // on the safety release a theme never restored. The server's own sheet
    // has no URL to hide; it is judged on the URLs it names, by origin.
    function parkSheet(el, judge) {
        if (!nativeTextSet || !isStyle(el)) return;
        // Delivered by a wrapper on the safety release, judged there.
        if (judged && judged.has(el)) return;
        var text = String(el.textContent == null ? '' : el.textContent);
        if (!text || !(judge || cssURL)(text)) return;
        // Already parked: by the server-side pass, by the runtime, or by this
        // function on an earlier record for the same element.
        if (nativeHas.call(el, 'data-faz-css')) return;
        var encoded = base64(text);
        nativeTextSet.call(el, '');
        // The attribute the server and the runtime already use for exactly this,
        // so the text is where anyone looking for it looks. It is bookkeeping
        // here, not the restore path: the task below holds the text itself.
        if (encoded) setAttr(el, 'data-faz-css', encoded);
        defer(function (policy) {
            // The page may have written the sheet while it waited. A live text
            // that is no longer the emptied form is the page's: it is left
            // alone, and the marker this function wrote goes with it, or the
            // restore pass would later put the old text back over the new. A
            // marker that is no longer this one belongs to whoever wrote it.
            var live = String(el.textContent == null ? '' : el.textContent);
            var mine = attr(el, 'data-faz-css') === (encoded || null);
            if (live !== '') {
                if (mine) dropAttr(el, 'data-faz-css');
                return;
            }
            if (!mine) return;
            deliverCss(policy, el, null, 'replaceChildren', [text], function () {
                // The marker goes BEFORE the write, not after it. The write
                // goes through the PROPERTY, and by now that is the runtime's
                // setter (_fazGateStyleTextSetter): when it neutralises the
                // text it rewrites data-faz-css itself and owns it from then
                // on — dropping it afterwards deleted the runtime's marker and
                // the restore pass never found the sheet again. The clear
                // above still uses the native, because there the gate to avoid
                // is this file's own, which would defer the clear and do
                // nothing.
                dropAttr(el, 'data-faz-css');
                el.textContent = text;
            });
        });
    }
    // Which insertions the DOM backstop further down has any business judging.
    //
    // A MutationObserver cannot tell a parser insertion from a script one, and
    // that difference decides everything. Markup a script injects during the
    // download window reached no gate above and is the whole reason the
    // backstop exists. Markup the SERVER rendered already passed the
    // server-side blocker, which is the authority for it: whatever had to be
    // blocked was rewritten before the HTML left PHP, and what remains is what
    // the server allowed. Parking that buys nothing — the parser had already
    // started the request before any observer was notified — and costs a
    // second fetch of the same file plus, on a CDN-hosted page, the LCP
    // element, which is the opposite of what this PR is for.
    //
    // So: while the document is parsing, judge only what a script sink
    // produced. Once parsing is over the parser is no longer a source of
    // insertions at all, and everything is script activity again.
    //
    // One thing this must not become is an allowlist of known sinks that fails
    // open on the one nobody wrapped. Every API that inserts a node is held
    // further down — appendChild, insertBefore, replaceChild, append, prepend,
    // before, after, replaceWith, replaceChildren, insertAdjacentElement,
    // insertAdjacentHTML — so a node that reaches the document through any of
    // them never depended on this backstop in the first place. What is left is
    // document.write, handled below by withdrawing the exemption rather than
    // by trusting it.
    var sunk = typeof w.WeakSet === 'function' ? new w.WeakSet() : null;
    // Resource elements the parser inserted, each with its URLs as inserted —
    // see unjudged().
    var served = sunk && typeof w.WeakMap === 'function' ? new w.WeakMap() : null;
    // Sheets a wrapper has already delivered, judged, on the safety release.
    // The backstop stays armed on that path and must not judge them again —
    // which is only sound because the delivery question (judgeCss) reads the
    // text the way the tokenizer does, escapes decoded and comments removed,
    // so an escaped `u\72l(` is caught there and not only by the backstop's
    // broad cssURL(), while a comment on its own is not a reason to park.
    var judged = sunk ? new w.WeakSet() : null;
    function tag(node) {
        if (!sunk || !node) return node;
        if (node.nodeType === 1) sunk.add(node);
        var kids = node.querySelectorAll ? node.querySelectorAll('*') : [];
        for (var i = 0; i < kids.length; i++) sunk.add(kids[i]);
        return node;
    }
    // Tag a method's or setter's output so the backstop still sees it. Stacks
    // on top of any wrapper already installed for the same name: `undo` drains
    // LIFO, so the layers come off in the order they went on.
    function tagSink(obj, name, run) {
        if (!obj || !sunk) return;
        var own = Object.getOwnPropertyDescriptor(obj, name);
        var owner = obj;
        while (owner && !Object.getOwnPropertyDescriptor(owner, name)) owner = Object.getPrototypeOf(owner);
        var desc = owner && Object.getOwnPropertyDescriptor(owner, name);
        if (!desc) return;
        var restore = function () {
            if (own) Object.defineProperty(obj, name, own);
            else delete obj[name];
        };
        if (desc.set) {
            if (!desc.configurable) return;
            Object.defineProperty(obj, name, {
                configurable: true, enumerable: desc.enumerable, get: desc.get,
                set: function (value) { run.call(this, desc.set, value); }
            });
            undo.push(restore);
            return;
        }
        if (typeof desc.value !== 'function') return;
        var original = desc.value;
        obj[name] = function () { return run.call(this, original, arguments); };
        undo.push(restore);
    }
    // innerHTML and setHTMLUnsafe replace the subtree: tag what is there after.
    [Element.prototype, w.ShadowRoot && ShadowRoot.prototype].forEach(function (proto) {
        tagSink(proto, 'innerHTML', function (set, value) {
            set.call(this, value);
            tag(this);
        });
    });
    // These two were tagged for the backstop but not gated, while their two
    // siblings — innerHTML and insertAdjacentHTML — are both. A <style> inside
    // the markup brings its own complete text, so the string IS the resulting
    // CSS, which is the same reason insertAdjacentHTML can judge it as a string.
    // ShadowRoot is in the list because a shadow root is where this API is
    // mostly used, and its tree is one the document observer never sees.
    ['setHTMLUnsafe', 'setHTML'].forEach(function (name) {
        [Element.prototype, w.ShadowRoot && ShadowRoot.prototype].forEach(function (proto) {
            tagSink(proto, name, function (original, args) {
                var self = this, markup = String(args[0] == null ? '' : args[0]);
                var apply = function () {
                    var out = original.apply(self, args);
                    tag(self);
                    return out;
                };
                if (!/<style\b/i.test(markup) || !cssURL(markup)) return apply();
                defer(function (policy) {
                    deliverMarkup(policy, markup, function (html) {
                        args[0] = html;
                        // Through the LIVE property, like insertAdjacentHTML's
                        // own deferred call: by replay time the handoff has
                        // installed the runtime's gate for this sink, and going
                        // back to the native captured here would skip it —
                        // measured leaking a third-party background-image with
                        // consent refused.
                        if (typeof self[name] === 'function' && self[name] !== original) {
                            self[name].apply(self, args);
                            tag(self);
                            return;
                        }
                        apply();
                    }, self);
                });
            });
        });
    });
    // outerHTML detaches the element, so the new nodes are the parent's
    // children between the old element's two neighbours. Bound the walk by
    // them instead of tagging every sibling: over-tagging would park the
    // server's markup next door, which is the defect this guard removes.
    tagSink(Element.prototype, 'outerHTML', function (set, value) {
        var parent = this.parentNode, prev = this.previousSibling, next = this.nextSibling;
        set.call(this, value);
        if (!parent) return;
        var node = prev ? prev.nextSibling : parent.firstChild;
        while (node && node !== next) { tag(node); node = node.nextSibling; }
    });
    tagSink(Element.prototype, 'insertAdjacentHTML', function (original, args) {
        var parent = args[0] && /^after/i.test(String(args[0])) ? this.parentNode : this;
        var out = original.apply(this, args);
        // Cheap and sufficient: the four positions all land inside `this` or
        // its parent, and tagging that subtree cannot reach the rest of the
        // document. A sibling tagged along with it is judged by origin only,
        // which is what it would have been judged by anyway.
        tag(parent || this);
        return out;
    });
    tagSink(w.Range && Range.prototype, 'createContextualFragment', function (original, args) {
        return tag(original.apply(this, args));
    });
    // document.write hands markup to the parser's input stream instead of
    // building nodes, so there is nothing to tag when the call returns — and
    // from that moment an insertion by the parser and one this page wrote are
    // indistinguishable. So stop distinguishing: the parser exemption is
    // withdrawn for the rest of the page view and every insertion is judged
    // again, which is where this file started. The cost lands only on a page
    // that writes markup while parsing — a pattern browsers themselves now
    // penalise — and there enforcement is worth more than the second fetch it
    // reintroduces; every page that does not write keeps its static markup
    // untouched. Failing this way round is the whole point: an unwrapped sink
    // must cost a request, never a consent decision.
    //
    // What is NOT asked is "does this markup contain a resource element". A
    // denylist of tag names has to be complete to be safe, and it never is:
    // <image> is a parser alias for <img>, and <frame>, <portal> and whatever
    // ships next are on nobody's list. Worse, write() concatenates its
    // arguments and successive calls into one input stream, so `<im` +
    // `g src=x>` builds an image that no per-call test ever sees whole. Both
    // of those fail open. This file already learned that lesson on the CSS
    // side, where the rule is written out: judge the text the engine will
    // parse, never the fragment handed in.
    //
    // So the question is inverted into one that fails closed. Keep the whole
    // written stream, and withdraw the exemption unless that stream is
    // PROVABLY nothing but script elements and whitespace. Proving an absence
    // needs no list: anything left after the script blocks and comments come
    // out — down to a single "<" — withdraws. An unterminated tag at the end
    // is left pending instead, because the tokenizer discards one at EOF and
    // the next write re-tests the whole buffer anyway.
    //
    // Script-only earns that one exception on its merits. guard() declines
    // every SCRIPT — a parser-built script has started its request before any
    // observer is notified — so withdrawing for the legacy
    // document.write('<script src=…>') tag would park every static resource
    // after it and gain exactly nothing. Markup written by that script, if it
    // writes any, comes back through this same wrapper.
    //
    // document.open() withdraws unconditionally: it discards the document and
    // replaces documentElement, so the static markup this exemption is about
    // no longer exists.
    /* write-predicate:start — sliced out and run against the engine's own
       tokenizer by tests/unit/js/bootstrap-write-stream.test.mjs and
       tests/browser-intent/write-stream-differential.spec.ts. Both fail loudly
       if these markers move; keep them around the predicate and nothing else,
       and note that the slice is evaluated with only `w` and `RESOURCES` in
       scope. */
    var wrote = false;
    // The parser this predicate asks is captured here, at install, and never
    // read from `w` again. Reading `w.DOMParser` at call time made the whole
    // backstop silenceable from the page AFTER this file had run: any script
    // that comes later assigns its own constructor, or just its own
    // parseFromString, and every question below is answered by it. The
    // dangerous stub is not one returning an empty document — that one would
    // lose the sentinel probe below and the predicate would withdraw by
    // accident — but one answering "nothing was written, and the stream is
    // closed", which is exactly what bootstrap-write-stream.test.mjs models:
    // with that answer document.write regains the parser exemption for free.
    // Both halves are captured, because shadowing either one is enough —
    // `DOMParser.prototype.parseFromString = () => closedDoc` needs no new
    // constructor at all. Same reason the attribute natives are captured at
    // the top of the file: a reference taken before page script runs cannot
    // be reached by whatever shadows the global afterwards.
    //
    // What this does NOT defend against, said plainly: a script that runs
    // BEFORE this file. That one shadows the global before the capture, and
    // the capture then takes the hostile constructor as the native. It is the
    // undefendable case for every native this file captures, and the reason
    // the bootstrap is attached inline in <head>, before page scripts run.
    var Parser = typeof w.DOMParser === 'function' ? w.DOMParser : null;
    var parseHTML = Parser && Parser.prototype && typeof Parser.prototype.parseFromString === 'function'
        ? Parser.prototype.parseFromString
        : null;
    function parse(text) { return parseHTML.call(new Parser(), String(text), 'text/html'); }
    // A sentinel element, to ask the parser one question it cannot be asked
    // directly: after this text, is the stream still inside something that
    // swallows markup? If the sentinel comes back as an element the state is
    // closed; if it was eaten as comment text, raw text, an attribute or
    // template content, it is not. No list of such constructs is needed, which
    // is the point — <!--, <template>, <textarea>, <title>, <style>, <xmp>,
    // <plaintext> and an unfinished tag all answer the same way.
    var PROBE = '<faz-probe-open></faz-probe-open>';
    // Is `prefix` the beginning of no guarded tag but <script>? The <script>
    // exception is the reason this predicate is not simply "any write
    // withdraws", so the prefix that claims it has to be unambiguous.
    function onlyScript(prefix) {
        var tags = RESOURCES.split(',');
        for (var t = 0; t < tags.length; t++) {
            if (tags[t] !== 'script' && tags[t].indexOf(prefix) === 0) return false;
        }
        return 'script'.indexOf(prefix) === 0;
    }
    function noteWrite(args) {
        if (wrote) return;
        // No parser, no distinction: withdraw and be done.
        if (!parseHTML) { wrote = true; return; }
        // Each call on its OWN, deliberately. Joining successive writes into
        // one buffer was wrong in both directions, because writes from
        // different scripts are not contiguous in the real stream — the
        // server's markup sits between them. A `write('<!--')` followed later
        // by `write('<iframe src=tracker>')` read as one string puts the iframe
        // inside a comment that the page's own `-->` had already closed, and
        // the iframe went unexamined and navigated. Judging each call by itself
        // cannot make that mistake, and the state a call LEAVES open is handled
        // below instead of being guessed at.
        var text = Array.prototype.join.call(args, '');
        var doc;
        try { doc = parse(text); }
        catch (e) { wrote = true; return; }
        // Asked with the backstop's OWN element list, so the two cannot drift:
        // the question is not "is this markup" but "could it have introduced
        // something the backstop would otherwise have judged". A written <p> or
        // <div> introduces nothing to judge, and the server's markup around it
        // stays the server's. The one thing outside that list in both places is
        // an SVG <image href>, which neither this file, nor the runtime's
        // observer, nor the server-side pass has ever guarded — a limit of the
        // element set, measured in write-stream-differential.spec.ts, not
        // something this predicate can close on its own.
        var found = doc.querySelectorAll(RESOURCES);
        for (var i = 0; i < found.length; i++) {
            // SCRIPT is the one exception, and it is the dominant payload of
            // this API. guard() declines every script — a parser-built one has
            // started its request before any observer is notified — so
            // withdrawing for the legacy document.write('<script src=…>') tag
            // would park every static resource after it and gain nothing.
            // Markup that script writes comes back through this wrapper.
            if (found[i].nodeName !== 'SCRIPT') { wrote = true; return; }
        }
        // Nothing to judge in the text itself. Two things are left, and both
        // are about where the text STOPS, because a string parse ends at EOF
        // and the real stream does not: the page's own bytes follow this text
        // at the insertion point.
        //
        // First, markup the text leaves half-written. Everything after the
        // last `>` that could still begin markup — a `<` before a letter, `/`,
        // `!` or `?`, or a `<` at the very end — is a tag the page's next
        // bytes will finish. `write('<img src=x alt=""')` is a real image, and
        // so is `write('<')` followed by `write('img src=x>')`: fifty-one
        // splits of a resource tag got past a version of this check that
        // treated a bare `<` as a prefix of `<script`. A `<` before anything
        // else is literal text to the tokenizer and is left alone, so
        // `write('5 < 10')` costs nothing.
        var trailer = text.slice(text.lastIndexOf('>') + 1);
        var open = /<(?=[a-z/!?])[^>]*$|<$/i.exec(trailer);
        if (open) {
            var head = open[0];
            // An end tag builds no element whatever it grows into, so `</scr`
            // — which is how write('<script>x</scr') ends — is safe.
            if (head.charAt(1) !== '/') {
                // Otherwise the tag name decides, and it has to be
                // unambiguous: `<s` can still become <source>, and `<` can
                // become anything. Derived from the guarded list itself, so it
                // cannot drift from what the backstop would have judged.
                var named = /^<([a-z0-9]*)(.?)/i.exec(head);
                var name = named[1].toLowerCase();
                var done = /[\s/]/.test(named[2]);
                if (done ? name !== 'script' : !onlyScript(name)) { wrote = true; return; }
            }
            // The tail is safe in itself, but the text BEFORE it may still
            // have left a swallowing state — `<textarea> <scr` ends in a
            // harmless script prefix while the stream is inside RCDATA. The
            // sentinel cannot be appended to a text that stops inside a tag
            // (it would be absorbed into it), so it is appended to the part
            // before that tag instead. Returning here outright was a fail-open
            // early exit: it skipped the check below entirely.
            text = text.slice(0, text.length - open[0].length);
        }
        // Second, a state that swallows what follows: a comment, raw text,
        // RCDATA, template content. The page's bytes close it, and from there
        // this file cannot reason about the write at all. Asked with a sentinel
        // element rather than a list of such constructs, because a list is what
        // has been wrong four times on this surface.
        var probe;
        try { probe = parse(text + PROBE); }
        catch (e) { wrote = true; return; }
        if (probe.querySelector('faz-probe-open')) return;
        // Swallowed — with one acceptable exception, asked of the parser the
        // same way. An open <script> element swallows the page's bytes as
        // script TEXT, which fetches nothing, and a later write is judged on
        // its own; write('<script src=x>') + write('</script>') is an ordinary
        // pair and must not cost the exemption.
        var scripts = probe.querySelectorAll('script');
        var tail = scripts.length ? scripts[scripts.length - 1] : null;
        if (tail && String(tail.textContent).indexOf('faz-probe-open') !== -1) return;
        wrote = true;
    }
    /* write-predicate:end */
    ['write', 'writeln', 'open'].forEach(function (name) {
        // On Document.prototype, not on the document. An instance wrapper is
        // skipped by Document.prototype.write.call(document, …), which is one
        // property lookup away and is exactly how a wrapper-aware snippet gets
        // around a gate like this one.
        var host = w.Document && Document.prototype && typeof Document.prototype[name] === 'function'
            ? Document.prototype : (typeof d[name] === 'function' ? d : null);
        if (!host) return;
        replace(host, name, function (original) {
            return function () {
                if (name === 'open') wrote = true;
                else {
                    noteWrite(arguments);
                    noteWrittenStyle(arguments);
                }
                return original.apply(this, arguments);
            };
        });
    });
    // A written <style> is not in RESOURCES, and deliberately so: that list is
    // elements that fetch by themselves. A stylesheet fetches through the CSS
    // engine, so a write carrying nothing but one kept the parser exemption,
    // and the backstop then read the style the parser built as the server's. It
    // is not — a script wrote it — and parkSheet() can only reach it once the
    // exemption is withdrawn.
    //
    // Only a sheet that COULD fetch costs the exemption. A legacy widget
    // writing `<style>.a{color:red}</style>` is ordinary markup, and
    // withdrawing for it would park the server's resources after it for
    // nothing. A sheet split across two writes needs no handling here: the
    // first call leaves the stream inside a <style>, which the sentinel probe
    // in the predicate already reports as a swallowing state.
    //
    // Outside the predicate slice because the slice is evaluated standalone by
    // the tests with only `w` and RESOURCES in scope, and this question needs
    // cssURL — one regex, in one place, shared with every other CSS gate here.
    function noteWrittenStyle(args) {
        if (wrote || !parseHTML) return;
        var doc;
        try { doc = parse(Array.prototype.join.call(args, '')); }
        catch (e) { wrote = true; return; }
        var found = doc.querySelectorAll('style');
        for (var i = 0; i < found.length; i++) {
            if (cssURL(found[i].textContent)) { wrote = true; return; }
        }
    }
    function fromScript(node) {
        // No WeakSet (and therefore no tags): keep the broader behaviour rather
        // than silently dropping the backstop. Costs a double fetch on an
        // engine that predates 2015; losing enforcement would cost consent.
        if (!sunk) return true;
        // Something wrote markup the parser swallowed: no node here can be
        // attributed any more, so judge them all.
        if (wrote) return true;  // see the write/open wrappers above
        // Past the parser: nothing here is the server's any more.
        if (parsed) return true;
        // And that is the whole question. Deliberately NOT "is the document
        // still parsing": a MutationObserver callback is a microtask, and the
        // task that finishes parsing sets readyState to 'interactive' BEFORE
        // the checkpoint that delivers its records — so records for the tail
        // of the document arrive with readyState already flipped. Firefox and
        // WebKit finish the remaining markup in one task where Chromium
        // yields, which is how a static image after a document.write came back
        // parked on two engines out of three. Reading a phase at delivery time
        // cannot answer a question about insertion time.
        //
        // What makes the remaining exemption safe is not a phase at all: every
        // API that inserts a node is wrapped and decides with the policy
        // itself — Node, ParentNode, insertAdjacentElement, Range — and every
        // HTML sink tags what it builds, so while the parser is running an
        // UNTAGGED insertion is the parser's or a replay of one a wrapper
        // already judged.
        return sunk.has(node);
    }
    // ---- Held insertions -------------------------------------------------
    //
    // A held insertion used to leave its node exactly where it was and return
    // as if it had moved. Every DOM idiom that moves nodes until a source is
    // empty then never ended:
    //
    //     while (src.firstChild) dst.appendChild(src.firstChild);
    //
    // src.firstChild stayed the same node, each call queued another task, and
    // the loop ran synchronously until the tab ran out of memory. The runtime's
    // own _fazRenderBanner() is written that way (DOMParser body → fragment),
    // and a stale 1.34.0 runtime runs it while this file is still pending — a
    // page cache serving script.min.js without its ?ver= is enough.
    //
    // So the move happens NOW and only the arrival waits. The node goes into a
    // private DocumentFragment ("holder") owned by the node's OWN document —
    // never this one: an <img> built by DOMParser is inert there, and adopting
    // it into this document, even into a detached fragment, starts its fetch.
    // What the page observes is what the call promised: the node left its old
    // parent, the source empties, the loop ends. At the replay the HOLDER is
    // inserted, so whatever the page put next to the node in the meantime goes
    // with it, and a node the page has since moved or removed elsewhere has
    // simply left the holder and is not dragged back.
    //
    // Deliberately not emulated: until the replay `node.parentNode` is the
    // holder, not the destination, and the destination does not list it. Both
    // were already untrue before this — the node then claimed its OLD parent —
    // and nothing short of the insertion itself could make them true.
    var records = new WeakMap();   // holder → the held insertion it belongs to
    var into = new WeakMap();      // container → held insertions still queued for it
    function isNode(n) { return !!n && typeof n === 'object' && typeof n.nodeType === 'number'; }
    function contains(node, container) {
        try { return !!(container && node.contains && node.contains(container)); } catch (e) { return false; }
    }
    function holderFor(node) {
        var doc = node.nodeType === 9 ? node : (node.ownerDocument || d);
        try { return nativeFragment.call(doc); } catch (e) { return nativeFragment.call(d); }
    }
    // A child the page has already given to `parent` through a held call: in
    // the DOM the page is reasoning about, it IS a child of `parent`, so it is
    // a valid reference node and a valid thing to remove.
    function pendingChild(parent, node) {
        var record = node && node.parentNode && records.get(node.parentNode);
        return !!record && record.target === parent;
    }
    // A fragment with insertions still queued INTO it: inserting it now would
    // move nothing, and the queued children would land in an orphan once they
    // replay. Its insertion is held behind them instead, and forward() points
    // them at the holder that takes the fragment's place.
    function waitingFragment(n) { return !!n && n.nodeType === 11 && into.has(n); }
    function forward(fragment, holder) {
        var list = into.get(fragment);
        if (!list) return;
        into.delete(fragment);
        waiting.delete(fragment);
        list.forEach(function (record) { record.target = holder; });
        into.set(holder, list);
    }
    // A holder is about to be inserted into `target`. Until now its nodes'
    // parentNode was the holder, so a page that reasoned from it — `a.parentNode
    // .insertBefore(b, a)`, `replaceChild`, `a.before(b)`, `a.replaceWith(b)` —
    // queued its own held call against the HOLDER. That call replays after this
    // one, when the holder is already empty and out of the document: the new
    // node landed in an orphan fragment and, for the replacing idioms, both
    // nodes were gone. Every insertion still queued into the holder follows its
    // nodes to the real destination instead, as forward() does for a fragment.
    function land(holder, target) {
        if (!holder || holder.nodeType !== 11 || !target) return;
        var list = into.get(holder);
        if (!list) return;
        into.delete(holder);
        waiting.delete(holder);
        list.forEach(function (record) { record.target = target; });
        var existing = into.get(target);
        into.set(target, existing ? existing.concat(list) : list);
    }
    function stash(node, record) {
        var holder = holderFor(node);
        records.set(holder, record);
        if (node.nodeType === 11) {
            // Inserting a fragment empties it; holding one has to as well.
            forward(node, holder);
            while (node.firstChild) moveInto(holder, node.firstChild);
        } else {
            moveInto(holder, node);
        }
        return holder;
    }
    function moveInto(holder, node) {
        var from = node.parentNode;
        // Leaving a <style> takes the node's text out of the sheet, and that
        // can REVEAL a URL it was splitting — the removal rule below.
        if (isStyle(from) && cssRisk(styleTextWithout(from, node), [])) {
            return removeFromSheet(from, node, function () { nativeAppend.call(holder, node); });
        }
        nativeAppend.call(holder, node);
    }
    function enqueue(record, task) {
        if (!immediate && record.target) {
            var list = into.get(record.target);
            if (!list) into.set(record.target, list = []);
            list.push(record);
        }
        defer(task);
    }
    // Delay dynamic insertions, retaining the actual nodes and their listeners.
    ['appendChild', 'insertBefore', 'replaceChild'].forEach(function (name) {
        replace(Node.prototype, name, function (original) {
            return function () {
                var self = this, args = Array.prototype.slice.call(arguments);
                var node = args[0], ref = name === 'appendChild' ? null : args[1];
                var styleOwner = isStyle(self) ? self : null;
                // replaceChild drops the old child, so the text it contributed
                // stops counting — the same bookkeeping replaceWith needs.
                var dropped = name === 'replaceChild' ? ref : null;
                var pendingRef = !!ref && pendingChild(self, ref);
                var carries = unjudged(node).length
                    || styleRisk(node)
                    || waitingFragment(node)
                    || pendingRef
                    || !!(styleOwner && cssRisk(containerText(styleOwner, dropped, name), [node]));
                if (!carries && !waiting.has(self)) return original.apply(self, args);
                // A child the page already gave `self` through a held call,
                // put in its own place: a no-op to the engine. Passed through,
                // the native call saw the node in its holder and threw
                // NotFoundError.
                if (node === ref && pendingRef) return node;
                // A call the engine refuses is refused here too, with the
                // engine's own error: holding it turned a synchronous
                // NotFoundError or HierarchyRequestError into a node quietly
                // dropped at the replay.
                if (!isNode(node) || node.nodeType === 9 || node === ref || contains(node, self)
                    || (name !== 'appendChild' && args.length < 2)
                    || (name === 'replaceChild' && !ref)
                    || (ref && ref.parentNode !== self && !pendingRef)) {
                    return original.apply(self, args);
                }
                waiting.add(self);
                var record = { target: self };
                var holder = stash(node, record);
                // replaceChild: the old child leaves now, as the call says it
                // does — a lazy loader's `while (el = q('.lazy')) el.replaceWith…`
                // spins on it otherwise — and the replacement lands where it
                // was. Two exceptions keep it until the replay: a child of a
                // <style>, whose text is part of the sheet and could reveal or
                // reorder a URL by leaving early; and one that is itself still
                // waiting to arrive, which is not in the document to leave.
                var anchor = ref, mode = name;
                if (name === 'replaceChild' && !styleOwner && !pendingRef) {
                    anchor = ref.nextSibling;
                    nativeRemoveChild.call(self, ref);
                    mode = 'insertBefore';
                }
                enqueue(record, function (policy) {
                    var target = record.target;
                    prepare(holder, policy);
                    // The reference node may have moved or been removed while
                    // this insertion waited. Replaying against it throws, and
                    // the node would be dropped with only a console warning,
                    // so fall back to appending into the same parent.
                    var moved = mode !== 'appendChild' && anchor && anchor.parentNode !== target;
                    var insert = function () {
                        parkSheets(holder, policy);
                        land(holder, target);
                        if (moved || mode === 'appendChild' || (mode === 'insertBefore' && !anchor)) return target.appendChild(holder);
                        if (mode === 'insertBefore') return target.insertBefore(holder, anchor);
                        target.replaceChild(holder, anchor);
                    };
                    // land() may have pointed this record at a <style> after it
                    // was queued against a holder, which is no sheet: the owner
                    // is read from the destination it replays into, so text
                    // reaching a sheet is always judged as CSS.
                    var owner = styleOwner || (isStyle(target) ? target : null);
                    if (!owner) return insert();
                    // The anchor is the second argument for both insertBefore
                    // and replaceChild; an appendChild has none, and so does a
                    // reference that moved, since the fallback appends.
                    deliverCss(policy, owner, moved ? null : anchor, moved ? 'appendChild' : mode, [holder], insert);
                });
                return name === 'replaceChild' ? ref : node;
            };
        });
    });
    // CharacterData.prototype is in the list because the text nodes INSIDE a
    // <style> are exactly where these methods matter: `styleEl.firstChild
    // .after('rl(https://…)}')` mutates the stylesheet, and it was unpatched —
    // only `replaceWith` had a block of its own, which this loop now subsumes.
    [Element.prototype, Document.prototype, DocumentFragment.prototype, w.CharacterData && CharacterData.prototype].forEach(function (proto) {
        if (!proto) return;
        ['append', 'prepend', 'before', 'after', 'replaceWith', 'replaceChildren'].forEach(function (name) {
            if (typeof proto[name] !== 'function') return;
            replace(proto, name, function (original) {
                return function () {
                    var self = this, args = Array.prototype.slice.call(arguments);
                    var styleOwner = cssOwner(self, name);
                    // before/after/replaceWith place the arguments next to
                    // `self`, in its parent; the other three fill `self`.
                    var sibling = name === 'before' || name === 'after' || name === 'replaceWith';
                    var container = sibling ? self.parentNode : self;
                    // One joined string, in call order, judged against the text
                    // the owner will actually hold. Per-argument tests missed a
                    // URL split across two arguments of one call.
                    var carries = args.some(function (n) { return unjudged(n).length || styleRisk(n) || waitingFragment(n); })
                        || !!(styleOwner && cssRisk(containerText(styleOwner, self, name), args));
                    // Queued behind an earlier held insertion into the same
                    // container, as appendChild always was: otherwise a plain
                    // append overtakes it and the sibling order inverts.
                    if (!carries && !(container && waiting.has(container))) return original.apply(self, args);
                    // A sibling call on a node with no parent does nothing at
                    // all, nodes included; a node placed inside itself is the
                    // engine's error to raise. Neither is held.
                    if (!container || args.some(function (n) { return isNode(n) && (n.nodeType === 9 || contains(n, container)); })) {
                        return original.apply(self, args);
                    }
                    waiting.add(container);
                    var record = { target: container };
                    // What the call removes, it removes now, for the reason
                    // replaceChild gives — except inside a <style>, where the
                    // text that goes is part of the sheet. The place it held is
                    // its first following sibling that is not itself being
                    // inserted (the specification's "viable next sibling"),
                    // read before the arguments are taken out.
                    var anchor = null, gone = false, listed = false;
                    if (name === 'replaceWith' && !styleOwner) {
                        anchor = self.nextSibling;
                        while (anchor && args.indexOf(anchor) !== -1) anchor = anchor.nextSibling;
                        gone = true;
                    } else if ((name === 'before' || name === 'after') && !styleOwner && args.indexOf(self) !== -1) {
                        // `s.before(x, s)`: `s` is one of the nodes being
                        // moved, so it is in a holder at the replay and cannot
                        // anchor the call. The engine places the nodes before
                        // the first sibling, from `s` (before) or after it
                        // (after), that is not itself being inserted; read now,
                        // like the replaceWith anchor above.
                        anchor = name === 'before' ? self : self.nextSibling;
                        while (anchor && args.indexOf(anchor) !== -1) anchor = anchor.nextSibling;
                        listed = true;
                    }
                    var parts = args.map(function (n) { return isNode(n) ? stash(n, record) : n; });
                    if (gone && self.parentNode === container) nativeRemoveChild.call(container, self);
                    // Emptying is safe in a <style> too: no text fetches nothing.
                    if (name === 'replaceChildren') {
                        while (self.firstChild) nativeRemoveChild.call(self, self.firstChild);
                    }
                    enqueue(record, function (policy) {
                        var target = record.target;
                        var insert = function () {
                            parts.forEach(function (n) { prepare(n, policy); parkSheets(n, policy); land(n, target); });
                            if (gone || listed) {
                                if (anchor && anchor.parentNode === target) return anchor.before.apply(anchor, parts);
                                return target.append.apply(target, parts);
                            }
                            // The node a sibling call was anchored on may have
                            // left while this waited; land in the parent then,
                            // as a moved reference node does above.
                            if (sibling) {
                                if (self.parentNode === target) return self[name].apply(self, parts);
                                return target.append.apply(target, parts);
                            }
                            // `target`, not `self`: a fragment that was itself
                            // inserted while this waited has been forwarded to
                            // the holder that took its place.
                            target[name].apply(target, parts);
                        };
                        // As above: a record land() pointed at a <style> is
                        // judged against the sheet it now replays into.
                        var owner = styleOwner || (isStyle(target) ? target : null);
                        if (!owner) return insert();
                        // The sheet is judged with the parts exactly where
                        // insert() will put them, branch for branch: a part
                        // judged at the end of the sheet while the engine
                        // receives it at the head can complete, or hide, a URL
                        // across the junction with the existing text.
                        var at = null, how = name;
                        if (gone || listed) {
                            if (anchor && anchor.parentNode === target) { at = anchor; how = 'insertBefore'; }
                            else how = 'append';
                        } else if (sibling) {
                            if (self.parentNode === target) at = self;
                            else how = 'append';
                        }
                        deliverCss(policy, owner, at, how, parts, insert);
                    });
                };
            });
        });
    });
    // Removing the node that split a URL reveals it — the same reason
    // `deleteData` is intercepted, one level up. `url(https:/*x*/ /t.test/a.png)`
    // becomes a live request once the comment node goes.
    //
    // The removal used to be HELD, and a held removal is the insertion bug in
    // reverse: `while (style.firstChild) style.removeChild(style.firstChild)`
    // removed nothing, queued a task per turn and never ended — on the safety
    // release too, where a refused removal simply did not happen. It happens
    // now. What is held instead is the SHEET: it is switched off before the
    // node goes (suspendSheet), so the engine never parses the text the
    // removal reveals, and switched back on by a queued task that judges the
    // text it then holds the way every other route into a <style> is judged —
    // parked, if the policy refuses a URL in it. The site's own rules in that
    // one sheet are off for the length of the hold; that is the price, paid
    // only by a script that removes part of a sheet whose remainder could
    // fetch.
    //
    // Not the neutralised text in place of the removal: rewriting the live
    // text turns the element's text nodes into one, which a page walking its
    // children — the loop above — sees as a different sheet, and the
    // judgement it needs is the runtime's, which does not exist yet.
    var HELD_TYPE = 'text/faz-held';
    var suspended = new WeakMap();
    function suspendSheet(owner) {
        if (suspended.has(owner)) return false;
        suspended.set(owner, { had: nativeHas.call(owner, 'type'), type: attr(owner, 'type') });
        // A type the engine does not know means no stylesheet at all — and it
        // is read on the next change of the element's children, which is the
        // removal that follows, never on the attribute write itself.
        setAttr(owner, 'type', HELD_TYPE);
        return true;
    }
    function resumeSheet(owner, policy) {
        var saved = suspended.get(owner);
        if (!saved) return;
        suspended.delete(owner);
        var text = styleText(owner);
        var verdict = policy ? judgeCss(policy, text) : null;
        // The safety release keeps its broad rule: no text that could fetch
        // anything is applied without the runtime's judgement.
        if (!verdict && policy && policy.sameOriginOnly && !allowedCss(policy, text)) verdict = { hit: '', whole: true };
        if (verdict) {
            // A sheet someone already parked keeps THEIR original in its
            // marker; only the live stand-in is emptied.
            if (nativeHas.call(owner, 'data-faz-css')) emptySheet(owner);
            else parkCss(owner, text, policy, verdict);
        }
        if (attr(owner, 'type') === HELD_TYPE) {
            if (saved.had) setAttr(owner, 'type', saved.type); else dropAttr(owner, 'type');
        }
        // The type is not re-read on its own: a change of the children is
        // what makes the engine build the sheet again, so make one that leaves
        // the children as they are.
        try {
            var touch = (owner.ownerDocument || d).createTextNode('');
            nativeAppend.call(owner, touch);
            nativeRemoveChild.call(owner, touch);
        } catch (e) { /* an owner that takes no children has no sheet to rebuild */ }
    }
    // Off, then the removal, then the task that turns it back on — in that
    // order, because on the safety release defer() runs the task at once.
    function removeFromSheet(owner, node, remove) {
        var first = suspendSheet(owner);
        remove();
        if (first) defer(function (policy) { resumeSheet(owner, policy); });
    }
    ['remove'].forEach(function (name) {
        [Element.prototype, w.CharacterData && CharacterData.prototype].forEach(function (proto) {
            if (!proto || typeof proto[name] !== 'function') return;
            replace(proto, name, function (original) {
                return function () {
                    var node = this, owner = node.parentNode;
                    if (!isStyle(owner)) return original.call(node);
                    if (!cssRisk(styleTextWithout(owner, node), [])) return original.call(node);
                    removeFromSheet(owner, node, function () { nativeRemoveChild.call(owner, node); });
                };
            });
        });
    });
    replace(Node.prototype, 'removeChild', function (original) {
        return function (child) {
            var self = this;
            // A child the page gave this parent through a held call is its
            // child as far as the page knows. Removing it cancels the arrival;
            // the engine would have answered NotFoundError.
            if (isNode(child) && child.parentNode !== self && pendingChild(self, child)) {
                nativeRemoveChild.call(child.parentNode, child);
                return child;
            }
            if (!isStyle(self) || !isNode(child) || child.parentNode !== self) return original.call(self, child);
            if (!cssRisk(styleTextWithout(self, child), [])) return original.call(self, child);
            removeFromSheet(self, child, function () { nativeRemoveChild.call(self, child); });
            return child;
        };
    });
    // Range.insertNode and Range.surroundContents put a node in the document
    // without touching Node or ParentNode, so they were the one insertion
    // route that reached the DOM unjudged — and the only reason the backstop
    // can treat an untagged insertion as the parser's is that no such route
    // exists. Held like insertAdjacentElement, which has the same shape.
    if (w.Range && Range.prototype) {
        // The two natives the parked form of a surroundContents is built from,
        // captured before the loop below wraps the first of them.
        var nativeRangeInsert = Range.prototype.insertNode;
        var nativeRangeDelete = Range.prototype.deleteContents;
        ['insertNode', 'surroundContents'].forEach(function (name) {
            if (typeof Range.prototype[name] !== 'function') return;
            replace(Range.prototype, name, function (original) {
                return function (node) {
                    var range = this;
                    // Resource ELEMENTS were judged here; CSS text was not, and
                    // `style` is deliberately not in RESOURCES, so this was the
                    // one insertion route that let a <style> grow a URL
                    // unexamined. A text node carrying
                    // `@import url(https://third-party/x.css)` dropped into a
                    // connected <style> through a Range fetched on the spot,
                    // while the identical appendChild — and every append,
                    // prepend, before, after, replaceWith, insertAdjacentText
                    // and appendData route — was held. Same owner rule as those:
                    // judge the text the engine will parse, not the fragment.
                    // Captured only for the hold decision. A range is MUTABLE:
                    // between the call and the replay the page can move the
                    // boundary into another <style>, and validating the owner
                    // captured here while inserting into the live one is the
                    // same validator/consumer split this file closed everywhere
                    // else. Reachable on the safety release with an innocuous
                    // half — `rl(https://t/x)}` contains no url(, @import or
                    // image-set, so it passes on its own — completing a URL the
                    // OTHER style already half-holds. Recomputed below.
                    var styleOwner = rangeStyleOwner(range);
                    // surroundContents discards the new parent's own children
                    // and moves the range's contents inside it, so when that
                    // parent IS a <style> the resulting stylesheet text is the
                    // range's text — page text that passed through no setter.
                    // Recomputed at replay, like every other owner text here,
                    // because the queue drains in order.
                    function surrounded() {
                        if (name !== 'surroundContents' || !isStyle(node)) return '';
                        var text = rangeText(range);
                        // Unmeasured: fail closed, with a string cssURL matches.
                        return text === null ? 'url(' : text;
                    }
                    // insertNode can land in the MIDDLE of the owner's text —
                    // a boundary inside a text node splits it there — and
                    // neither junction order cssRisk() tries sees a URL that
                    // only the splice completes (`…:u` + `rl(https://…)` + `}`).
                    // So the spliced text is asked too, exactly as the engine
                    // will read it.
                    // waitingFragment(): a fragment with a held insertion still
                    // queued INTO it, as in the Node and ParentNode wrappers.
                    // Inserted natively it lands empty, and the held child
                    // later replays into the orphaned fragment and is lost.
                    var carries = unjudged(node).length
                        || styleRisk(node)
                        || waitingFragment(node)
                        || cssURL(surrounded())
                        || !!(styleOwner && cssRisk(containerText(styleOwner, null, name), [node]))
                        || !!(styleOwner && name === 'insertNode' && cssURL(rangeResult(range, styleOwner, node)));
                    if (!carries) return original.call(range, node);
                    // What the engine refuses, with its own error; see the
                    // Node wrappers above.
                    if (!isNode(node) || node.nodeType === 9 || (name === 'surroundContents' && (node.nodeType === 10 || node.nodeType === 11))) {
                        return original.call(range, node);
                    }
                    // The node leaves its old place now, as at every other
                    // held insertion (stash() above). insertNode takes the
                    // holder at the replay, so a fragment's children arrive
                    // together; surroundContents needs the element itself, and
                    // one the page has taken back since is not wrapped.
                    var holder = stash(node, { target: null });
                    var arg = name === 'insertNode' ? holder : node;
                    defer(function (policy) {
                        if (name === 'surroundContents' && node.parentNode !== holder) return;
                        var owner = rangeStyleOwner(range);
                        var insert = function () {
                            prepare(arg, policy);
                            parkSheets(arg, policy);
                            original.call(range, arg);
                        };
                        if (owner) {
                            // insertNode splices the node's text in at the
                            // boundary. surroundContents moves the wrapped text
                            // INTO the new element, and for the CSS engine only
                            // direct child text nodes are stylesheet, so what
                            // the owner then holds is its text minus the wrapped
                            // part — judged on the whole of its text instead,
                            // which is the safe direction and costs a replay.
                            var result = name === 'insertNode'
                                ? rangeResult(range, owner, arg)
                                : resultText(owner, null, 'append', []);
                            return deliverCss(policy, owner, null, name, [arg], insert, result);
                        }
                        if (name !== 'surroundContents' || !isStyle(node)) return insert();
                        // A fresh <style> wrapping page text: the sheet it
                        // becomes is the range's text, which the wrap moves in
                        // after discarding the element's own children — so the
                        // element cannot be parked ahead of the call the way a
                        // subtree is. Build the wrap's end state by hand when
                        // the policy refuses: the contents go into the marker,
                        // the element takes their neutralised form and lands
                        // where they were.
                        var text = rangeText(range);
                        if (text === null) return;
                        var verdict = judgeCss(policy, text);
                        if (!verdict) {
                            if (!allowedCss(policy, text)) return;
                            prepare(node, policy);
                            return original.call(range, node);
                        }
                        nativeRangeDelete.call(range);
                        parkCss(node, text, policy, verdict);
                        prepare(node, policy);
                        nativeRangeInsert.call(range, node);
                    });
                };
            });
        });
        // Removing a span of a sheet's text reveals a URL the same way
        // deleteData and removeChild do — `url(https:/*x*/ /t/a.png)` is a
        // valid URL once the comment goes — and these two were the one removal
        // route with no gate. Judged on the owner's text WITHOUT the range, as
        // the other removals are, and handled the way they are: the removal
        // happens now, with the sheet switched off around it (removeFromSheet),
        // so extractContents answers with the real fragment instead of an
        // empty stand-in, and a range the page deletes again finds the text
        // gone.
        ['deleteContents', 'extractContents'].forEach(function (name) {
            if (typeof Range.prototype[name] !== 'function') return;
            replace(Range.prototype, name, function (original) {
                return function () {
                    var range = this, owner = rangeStyleOwner(range);
                    if (!owner) return original.call(range);
                    var result = rangeRemoved(range, owner);
                    if (result !== null && !cssURL(result)) return original.call(range);
                    var out;
                    removeFromSheet(owner, null, function () { out = original.call(range); });
                    return out;
                };
            });
        });
    }
    replace(Element.prototype, 'insertAdjacentElement', function (original) {
        return function (position, node) {
            var el = this, where = String(position).toLowerCase();
            var outside = where === 'beforebegin' || where === 'afterend';
            var container = outside ? el.parentNode : el;
            if (!unjudged(node).length && !styleRisk(node) && !(container && waiting.has(container))) return original.call(el, position, node);
            // An unknown position, a non-element, a parentless element asked
            // for a sibling, an element placed inside itself: the engine
            // throws or does nothing, and so does this.
            if (['beforebegin', 'afterbegin', 'beforeend', 'afterend'].indexOf(where) === -1 || !container
                || !isNode(node) || node.nodeType !== 1 || contains(node, container)) {
                return original.call(el, position, node);
            }
            waiting.add(container);
            var record = { target: container };
            var holder = stash(node, record);
            enqueue(record, function (policy) {
                var target = record.target;
                prepare(holder, policy);
                parkSheets(holder, policy);
                // The holder is a fragment, which insertAdjacentElement does
                // not take, so the four positions are spelled out against the
                // element as it stands now — falling back to the container the
                // call was made into once the element has left it.
                if (where === 'afterbegin') return el.insertBefore(holder, el.firstChild);
                if (where === 'beforeend') return el.appendChild(holder);
                if (el.parentNode !== target) return target.appendChild(holder);
                target.insertBefore(holder, where === 'beforebegin' ? el : el.nextSibling);
            });
            return node;
        };
    });
    // Deliberately broad. Deciding this wrong in one direction only delays a
    // stylesheet; wrong in the other it lets a request out before consent. So
    // it answers "could this text fetch anything", not "does it contain a URL
    // I can parse": url(), @import, image-set(), src(), and any CSS escape or
    // comment, which can spell a property the browser understands and a regex
    // does not. Holding more costs a replay; holding less costs a leak.
    function cssURL(value) {
        return /url\s*\(|@import|image-set|src\s*\(|\\|\/\*/i.test(String(value || ''));
    }
    // The URLs a regex can read out of CSS text: url() in its three spellings
    // and the string form of @import — the same two patterns the runtime
    // extracts (_fazCssUrls, script.js), kept identical on purpose, so a
    // decision taken here on them is the decision the runtime would take on
    // the same text.
    // A CSS string ends at an UNESCAPED quote. Reading the body as `[^"]*`
    // stopped at an escaped one, failed the closing paren, and let the bare
    // branch swallow the quotes with it — a token that resolves against the
    // page and lands on the site's own origin, so the third party in
    // `url("https://host/a\".png")` was never named. The two alternatives are
    // disjoint on their first character, so this cannot backtrack badly.
    var CSS_URL = /url\(\s*(?:"((?:[^"\\]|\\[\s\S])*)"|'((?:[^'\\]|\\[\s\S])*)'|([^\)\s]+))\s*\)/gi;
    var CSS_IMPORT = /(@import\s+)(["'])((?:(?!\2)[^\\]|\\[\s\S])+)\2/gi;
    // Four more forms the engine reads, and both files read now (the runtime
    // caught up in _fazCssUrlsRaw: its tight @import, _FAZ_CSS_URL_OPEN and
    // _FAZ_CSS_STRING_FN are these four): `@import"x.css"`, which needs no
    // whitespace to be an import; a url( left open at the end of the text,
    // which the tokenizer reads to EOF as the URL — the shape a sheet has when
    // a held fragment lands last; and the string arguments of image-set() and
    // src(), which name a file without any url() around it. A form read here
    // and not there is a sheet parked by this file and called clean by the
    // runtime, so one is not added without the other.
    var CSS_IMPORT_TIGHT = /@import(["'])((?:(?!\1)[^\\]|\\[\s\S])+)\1/gi;
    var CSS_URL_OPEN = /url\(\s*(?:"((?:[^"\\]|\\[\s\S])*)|'((?:[^'\\]|\\[\s\S])*)|([^\)\s]*))\s*$/i;
    var CSS_STRING_FN = /\b(?:image-set|src)\(((?:[^()]|\([^()]*\))*)\)/gi;
    var CSS_STRING = /"((?:[^"\\]|\\[\s\S])*)"|'((?:[^'\\]|\\[\s\S])*)'/g;
    // The bare branch of CSS_URL stops at the first whitespace, which is right
    // for the raw text — an unquoted url() token cannot hold one. On the
    // NORMALISED text it is wrong: an escape that decoded to a space put one
    // inside the token, and `url(https://tracker.test/a\ b.png)` then matched
    // neither pass, so no URL was read out of it at all and the sheet was
    // called clean. Here the whitespace is already part of the URL the engine
    // resolved, so the body runs to the closing paren instead.
    var CSS_URL_DECODED = /url\(\s*(?:"((?:[^"\\]|\\[\s\S])*)"|'((?:[^'\\]|\\[\s\S])*)'|([^\)]+?))\s*\)/gi;
    // A comment is a comment only outside a string. `content:"/*"` is two
    // characters of content, and stripping from there to the `*/` held in a
    // later string deleted every real token between them — including the url()
    // the sheet was judged on. The alternation consumes whole strings first,
    // and gives them back untouched, so only a comment outside one is removed.
    var CSS_COMMENT = /("(?:[^"\\\n]|\\[\s\S])*"|'(?:[^'\\\n]|\\[\s\S])*')|\/\*[\s\S]*?\*\//g;
    // `simple` restricts the answer to the two forms neutralise() can rewrite
    // in place; a URL found only by the other three cannot be neutralised one
    // token at a time, and the sheet carrying it is parked whole instead.
    // `decoded` says the text has been through normaliseCss(), which is the
    // one case the looser bare branch above applies to.
    function cssUrls(text, simple, decoded) {
        var urls = [], value = String(text == null ? '' : text);
        value.replace(decoded ? CSS_URL_DECODED : CSS_URL, function (match, dq, sq, bare) {
            urls.push(dq || sq || bare || '');
            return match;
        });
        value.replace(CSS_IMPORT, function (match, prefix, quote, url) {
            urls.push(url || '');
            return match;
        });
        if (simple) return urls;
        value.replace(CSS_IMPORT_TIGHT, function (match, quote, url) {
            urls.push(url || '');
            return match;
        });
        var open = CSS_URL_OPEN.exec(value);
        if (open && (open[1] || open[2] || open[3])) urls.push(open[1] || open[2] || open[3]);
        value.replace(CSS_STRING_FN, function (match, inner) {
            String(inner).replace(CSS_STRING, function (m, dq, sq) {
                urls.push(dq || sq || '');
                return m;
            });
            return match;
        });
        return urls;
    }
    // The text as the CSS tokenizer reads it, for the regexes above to be
    // asked a second time: comments removed and escapes decoded, both the hex
    // form (`\72` is "r", with one optional whitespace after it) and the
    // single-character form (`\.` is "."). `u\72l(`, `\75 rl(` and
    // `tracker\.example.test` all spell something a regex on the raw text does
    // not see and the engine fetches.
    function normaliseCss(text, keepComments) {
        var value = String(text == null ? '' : text);
        return (keepComments ? value : value.replace(CSS_COMMENT, '$1'))
            // One whitespace after the hex digits belongs to the escape, and
            // CRLF counts as ONE: the tokenizer's preprocessing folds it into
            // a single newline before the escape consumes it, so eating only
            // the `\r` left `u\nrl(` behind and a regex that read it as text.
            .replace(/\\([0-9a-f]{1,6})(?:\r\n|[ \t\n\r\f])?/gi, function (match, hex) {
                var code = parseInt(hex, 16);
                try { return code > 0 && code <= 0x10ffff ? String.fromCodePoint(code) : '�'; }
                catch (e) { return '�'; }
            })
            .replace(/\\([^\n\r\f])/g, '$1');
    }
    // Every URL the text can be read to name, on the raw text and on the
    // normalised one.
    // The comment-stripped pass can only ADD URLs, never stand in for the
    // others: CSS_COMMENT cannot tell where the tokenizer is, and removes text
    // the engine keeps — `/*` inside an unquoted url(…) is part of the URL,
    // and an unterminated string ends at the newline, so a `/*` inside it
    // starts nothing. Stripping there deleted a live @import or url() from the
    // copy being judged. The escape-decoded pass therefore also runs on the
    // text with its comments kept, and the three sets are united.
    function cssUrlsAll(text) {
        return cssUrls(text)
            .concat(cssUrls(normaliseCss(text, true), false, true))
            .concat(cssUrls(normaliseCss(text), false, true));
    }
    // The narrow question, for a sheet whose text is complete and whose author
    // is the parser: does it name a URL that can reach a third party? Judged on
    // the URLs that can be read out of it, by the same origin test every
    // resource element gets, with an unresolvable one counting as remote. A
    // comment or an escape on its own is not a reason — a theme's `/* header
    // */` and an icon font's `content:"\f101"` are what those look like in
    // practice, and cssURL() above held both.
    function cssFetches(text) {
        var urls = cssUrlsAll(text);
        for (var i = 0; i < urls.length; i++) {
            if (!local(urls[i])) return true;
        }
        return false;
    }
    // During a normal handoff the runtime's permanent interceptors decide what
    // may load, so everything replays — which is only true while the runtime
    // gates every key in `attrs` below. That has been wrong before: <source src>
    // was held here with no runtime gate on HTMLSourceElement until 1.34.2, so
    // its replay reached the native setter. media-embed-object-gate.test.mjs
    // replays held assignments through the handoff, <source src> included, and
    // a key added to `attrs` needs a case there. During a safety release there
    // are no such interceptors and no provider rules, so only same-origin work
    // runs.
    function allowed(policy, url, key) { return !policy || !policy.sameOriginOnly || localValue(key, url); }
    // CSS TEXT is not released by origin on the safety release, because judging
    // that means re-implementing the browser's CSS parser: escapes, comments
    // and image-set() can hide a URL from any regex while the engine still
    // fetches it, and the two disagreeing is a bypass. During a safety release
    // no style text that could fetch anything is applied at all. The cost is a
    // stylesheet that stays parked for that page view, which only happens when
    // the runtime is already broken; the benefit is that no parser of ours
    // stands between a visitor and a third-party request.
    //
    // A <style> ELEMENT arriving inside a subtree is the one exception, and
    // parkSheets() below says why: there the choice was never "apply or hold"
    // but "insert or drop the whole subtree", and dropping took a site's own
    // widget down with the sheet it carried.
    function allowedCss(policy, value) { return !policy || !policy.sameOriginOnly || !cssURL(value); }
    // A <style> ELEMENT that arrives with its CSS already in it. Every gate in
    // this file asks whether the CONTAINER is a <style>, and the text-entry
    // routes (textContent, innerHTML, data, insertAdjacentText/HTML, appendData)
    // cover the ways a style GROWS text — so what was left was bringing in a
    // style that already holds the URL, built where no setter runs. Two such
    // routes were measured fetching on insertion in jsdom:
    // `range.createContextualFragment('<style>@import url(…)</style>')` followed
    // by appendChild, and existing page text moved into a fresh <style> by
    // Range.surroundContents. Judged with cssURL, the same deliberately broad
    // question insertAdjacentHTML asks about markup carrying a <style>.
    // nodeName is uppercase for HTML elements and the qualified name AS WRITTEN
    // for every other namespace, so `=== 'STYLE'` answered "no" for an SVG
    // <style> — an element browsers apply to the whole document, @import
    // included. Measured: inside an inline <svg>, appendChild of
    // `@import url(https://third-party/x.css)` into its <style> landed and
    // fetched, and so did the textContent setter, because HTMLStyleElement is
    // not in that element's prototype chain either. localName is the name
    // without the namespace, which is the question every one of these sites is
    // actually asking — and the same question `querySelectorAll('style')` asks,
    // since a CSS type selector with no namespace matches any.
    function isStyle(node) {
        if (!node || node.nodeType !== 1) return false;
        return String(node.localName || node.nodeName).toLowerCase() === 'style';
    }
    function styleRisk(node) {
        if (!node || typeof node.nodeName !== 'string') return false;
        if (isStyle(node)) return cssURL(node.textContent);
        if (!node.querySelectorAll) return false;
        var styles = node.querySelectorAll('style');
        for (var i = 0; i < styles.length; i++) {
            if (cssURL(styles[i].textContent)) return true;
        }
        return false;
    }
    // Does the policy refuse this URL? `url` is the runtime's provider rules
    // at the handoff and the origin test on the safety release. A rule that
    // throws refuses: a missing answer must not read as permission.
    function refused(policy, url) {
        if (!policy || typeof policy.url !== 'function') return false;
        try { return !!policy.url(url); } catch (e) { return true; }
    }
    function refusedUrls(policy, urls) {
        return urls.filter(function (url) { return refused(policy, url); });
    }
    // The verdict on a sheet's text: null when the policy would let every URL
    // it can be read to name load, otherwise the first refused URL and whether
    // the sheet has to be parked WHOLE. Whole when a refused URL is one
    // neutralise() cannot rewrite in place — found only once the text is
    // normalised (an escape in the function name or in the host), or only in
    // a form the runtime's pair of patterns does not read (an open url(, an
    // image-set() string). Rewriting the URLs a regex can see while one it
    // cannot stays live would be a park in name only.
    function judgeCss(policy, text) {
        var raw = String(text == null ? '' : text);
        var simple = refusedUrls(policy, cssUrls(raw, true));
        var all = refusedUrls(policy, cssUrlsAll(raw));
        if (!all.length) return null;
        var whole = all.some(function (url) { return simple.indexOf(url) === -1; });
        return { hit: all[0], whole: whole };
    }
    // The form the runtime gives a blocked sheet (_fazNeutralizeCssUrls):
    // every refused url() and @import replaced by an inert data: URL and
    // everything else left as written, so a sheet that mixes the site's own
    // icons with a tracker's pixel keeps the icons. Byte-for-byte the
    // runtime's own output on purpose: its bookkeeping adopts a parked
    // original only when the live text IS that form
    // (_fazPrimeStyleCssMarkers), which is what makes a sheet parked here one
    // the runtime goes on managing as its own — a later append through its
    // gate re-syncs the marker from the original instead of from an empty
    // element and losing it. Only the two forms the runtime reads are
    // rewritten here; when judgeCss() finds a refused URL in any other form
    // the sheet is parked whole (emptied) instead, see there.
    var INERT_CSS_URL = 'data:application/octet-stream,';
    function neutralise(policy, text) {
        var inert = 'url("' + INERT_CSS_URL + '")';
        return String(text == null ? '' : text)
            .replace(CSS_URL, function (match, dq, sq, bare) {
                return refused(policy, dq || sq || bare || '') ? inert : match;
            })
            .replace(CSS_IMPORT, function (match, prefix, quote, url) {
                return refused(policy, url) ? prefix + inert : match;
            });
    }
    // Park a <style> whose text the policy refuses, in place: the live text
    // becomes the neutralised form, the original goes into data-faz-css, and
    // the category the restore selectors need is named. Delivered, not
    // dropped — the element is in the document, the site's own rules in it
    // still apply, and the runtime's restore pass (`style[data-faz-css]` in
    // _fazUnblockServerSide) hands the original back on the consent that
    // allows it. Dropping the operation instead left the page one stylesheet
    // short for the rest of the view, on that same consent.
    // The live text a refused sheet is left with. The in-place rewrite is
    // asked the same question AGAIN, on its own raw and normalised copies: a
    // URL that appears once in a form neutralise() rewrites and once in one
    // it does not (`url(T/x)` next to `u\72l(T/x)`, `@import url(X)` next to
    // `@import"X"`) is the same string in both lists, so a verdict decided
    // by string identity called the sheet rewritable and left the second
    // spelling live. Whatever still fetches after the rewrite parks the
    // sheet whole.
    function parkedForm(policy, text, verdict) {
        var out = verdict.whole ? '' : neutralise(policy, text);
        if (out && judgeCss(policy, out)) out = '';
        return out;
    }
    // A sheet parked whole keeps exactly ONE empty text node as its content,
    // not none: the runtime adopts a parked original from the marker only
    // when the element has exactly one text child (_fazPrimeStyleCssMarkers),
    // and with zero children the first append through its gate re-synced the
    // marker from nothing and dropped it, so the original was never restored.
    function emptySheet(el) {
        nativeTextSet.call(el, '');
        try { nativeAppend.call(el, d.createTextNode('')); } catch (e) { /* a document the element cannot take text in */ }
    }
    function parkCss(el, text, policy, verdict) {
        if (!nativeTextSet) return;
        var encoded = base64(text);
        var live = parkedForm(policy, text, verdict);
        if (live) nativeTextSet.call(el, live); else emptySheet(el);
        if (encoded) setAttr(el, 'data-faz-css', encoded);
        if (!attr(el, 'data-faz-category')) setAttr(el, 'data-faz-category', parkCategory(policy, verdict.hit));
    }
    // Every sheet a subtree carries, judged BEFORE the subtree is inserted:
    // while the element is detached nothing parses it, so a refused sheet
    // lands already parked and the engine never sees the URL. Inserting first
    // and parking after is not the same thing — an @import is requested while
    // the sheet is parsed, synchronously, on insertion. On the safety release
    // this is also where a subtree holding a first-party sheet stops being
    // dropped whole: each sheet is judged on its own and the rest of the
    // subtree lands as the page meant it to.
    function parkSheets(node, policy) {
        sheets(node).forEach(function (el) {
            var text = styleText(el);
            var verdict = text ? judgeCss(policy, text) : null;
            if (verdict) parkCss(el, text, policy, verdict);
            if (judged) judged.add(el);
        });
    }
    // The text a <style> is left holding once a change lands: the exact string
    // the engine parses. The hold side asks the broader cssRisk(), which tries
    // both junction orders because a hold must fail closed; delivery needs the
    // one real order, because the URL it extracts and the original it parks
    // are both read from this string. The anchor is the child the change is
    // placed against — the reference node of insertBefore, the node
    // before/after/replaceWith was called on, the text node a character
    // method edits, the child being removed. No anchor, or one no longer in
    // the owner, means the end of the text, which is where the replay puts an
    // insertion whose reference node moved.
    //
    // Only direct Text and CDATA children are stylesheet. A comment node, or
    // an element, between two text nodes contributes nothing to what the
    // engine parses — and counting its textContent did worse than over-hold:
    // `url(https://t/c.png` + <!-- x --> + `)}` read as `url(https://t/c.png
    // x)}`, which no URL pattern matches, while the engine reads the two text
    // nodes back to back and fetches.
    function isText(node) { return !!node && (node.nodeType === 3 || node.nodeType === 4); }
    function textOf(node) { return isText(node) ? String(node.data == null ? '' : node.data) : ''; }
    function styleText(owner) {
        var text = '';
        for (var child = owner && owner.firstChild; child; child = child.nextSibling) text += textOf(child);
        return text;
    }
    function textAround(owner, anchor) {
        var out = { before: '', self: '', after: '' }, seen = false;
        if (!owner) return out;
        for (var child = owner.firstChild; child; child = child.nextSibling) {
            var part = textOf(child);
            if (child === anchor) { seen = true; out.self = part; continue; }
            if (seen) out.after += part; else out.before += part;
        }
        return out;
    }
    // What an inserted part adds to the sheet: a string, a text node's data,
    // the text children of a fragment (they become the owner's children), and
    // nothing for an element or a comment.
    function joinParts(parts) {
        return (parts || []).map(function (part) {
            if (part == null) return '';
            if (typeof part === 'string') return part;
            if (part.nodeType === 11) return styleText(part);
            return textOf(part);
        }).join('');
    }
    function resultText(owner, anchor, name, parts) {
        var joined = joinParts(parts);
        if (name === 'replaceChildren') return joined;
        var at = textAround(owner, anchor);
        switch (name) {
            case 'prepend': case 'afterbegin': return joined + at.before + at.self + at.after;
            case 'insertBefore': case 'before': case 'beforebegin': return at.before + joined + at.self + at.after;
            case 'after': case 'afterend': return at.before + at.self + joined + at.after;
            case 'replaceChild': case 'replaceWith': case 'without': return at.before + joined + at.after;
            default: return at.before + at.self + at.after + joined;
        }
    }
    // The text a <style> holds once Range.insertNode lands `node` at the
    // range's start: before a child when the boundary is on the element's
    // child list, inside a text node — which the engine splits at the offset —
    // when it is in one. Read at replay, against the boundary as it stands
    // then, for the reason rangeStyleOwner() is recomputed there.
    // The text a <style> is left with once a range of it is deleted or
    // extracted: each text child keeps the part before the range's start and
    // the part after its end. The engine's own comparePoint() places a child
    // against the boundaries, so no model of the range is kept here; when it
    // cannot answer (a boundary in another tree, a detached node) the result
    // is null, which the hold reads as risk and the delivery as the whole
    // owner text.
    function rangeRemoved(range, owner) {
        var text = '';
        try {
            for (var child = owner.firstChild; child; child = child.nextSibling) {
                if (!isText(child)) continue;
                var data = textOf(child), from, to;
                if (range.startContainer === child) from = range.startOffset;
                else from = range.comparePoint(child, 0) < 0 ? data.length : 0;
                if (range.endContainer === child) to = range.endOffset;
                else to = range.comparePoint(child, data.length) > 0 ? 0 : data.length;
                text += from >= to ? data : data.slice(0, from) + data.slice(to);
            }
        } catch (e) { return null; }
        return text;
    }
    function rangeResult(range, owner, node) {
        var at = range.startContainer, offset = range.startOffset;
        if (at === owner) return resultText(owner, owner.childNodes[offset] || null, 'insertBefore', [node]);
        var around = textAround(owner, at);
        return around.before + around.self.slice(0, offset) + joinParts([node]) + around.self.slice(offset) + around.after;
    }
    // Deliver a held change to a <style>'s text. On the safety release the
    // broad rule stands — see allowedCss() — and a refusal leaves the owner as
    // it is. At the handoff the runtime's rules are in the policy, so the
    // resulting text is judged by them HERE, and a refused result lands parked
    // rather than dropped: the owner takes the neutralised form of what it was
    // about to hold and the original is marked for the restore pass. Judged on
    // this side rather than left to the live method because the runtime gates
    // the HTMLStyleElement interface and nothing else: a Range, the SVG
    // <style>, before/after on a text node inside a sheet all reach the engine
    // through methods it never wrapped, and a replay through those fetched
    // with consent refused. The runtime does not have to learn these routes —
    // the file that held them delivers them.
    function deliverCss(policy, owner, anchor, name, parts, apply, result) {
        if (!policy) return apply();
        if (policy.sameOriginOnly) {
            // The exact text, when the caller could compute one, is asked as
            // well: a splice into the middle of a sheet completes a URL that
            // neither junction order shows.
            if (!allowedStyle(policy, owner, anchor, name, parts) || (result != null && cssURL(result))) return;
            return apply();
        }
        var text = result == null ? resultText(owner, anchor, name, parts) : result;
        var verdict = judgeCss(policy, text);
        if (verdict) return parkCss(owner, text, policy, verdict);
        apply();
    }
    // The markup half of parkSheets(): a <style> inside a string a sink is
    // about to parse, rewritten the way the runtime rewrites the same string
    // (_fazPrepareStyleTagHtml, script.js) when the policy refuses a URL in it
    // — text neutralised, original in data-faz-css, a category. A tag already
    // carrying the marker is someone else's park and is left as it is. Only
    // the safety release comes through here: at the handoff the live sink IS
    // the runtime's own markup gate, and that gate decides.
    function escapeAttr(value) {
        return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }
    function parkMarkup(policy, markup) {
        return String(markup == null ? '' : markup).replace(/<style\b([^>]*)>([\s\S]*?)<\/style>/gi, function (full, attrs, css) {
            attrs = attrs || '';
            if (/\bdata-faz-css\s*=/i.test(attrs)) return full;
            var verdict = css ? judgeCss(policy, css) : null;
            if (!verdict) return full;
            var encoded = base64(css);
            if (!encoded) return full;
            var category = /\bdata-faz-category\s*=/i.test(attrs) ? '' : ' data-faz-category="' + escapeAttr(parkCategory(policy, verdict.hit)) + '"';
            return '<style' + attrs + ' data-faz-css="' + escapeAttr(encoded) + '"' + category + '>'
                + parkedForm(policy, css, verdict) + '</style>';
        });
    }
    // `host` is where the parsed nodes land: the sheets that are new in it
    // afterwards are the ones this delivery judged, and the backstop — still
    // watching on the safety release — is told so, or it would re-ask its own
    // broad question of a sheet just delivered on the narrow one and park a
    // theme for a comment.
    function deliverMarkup(policy, markup, apply, host) {
        if (!policy || !policy.sameOriginOnly) return apply(markup);
        var before = sheets(host);
        apply(parkMarkup(policy, markup));
        sheets(host).forEach(function (el) {
            if (before.indexOf(el) !== -1) return;
            // The parser builds no text node for an empty element, so a
            // sheet parked whole through markup gets its one empty text child
            // here, for the reason emptySheet() gives.
            if (nativeHas.call(el, 'data-faz-css') && !el.firstChild) emptySheet(el);
            if (judged) judged.add(el);
        });
    }
    // Preserve the runtime's CSS enforcement during its download too. Plain
    // layout/style changes keep their synchronous semantics.
    function holdCSS(proto, prop, eligible) {
        if (!proto) return;
        var owner = proto;
        while (owner && !Object.getOwnPropertyDescriptor(owner, prop)) owner = Object.getPrototypeOf(owner);
        var desc = owner && Object.getOwnPropertyDescriptor(owner, prop);
        if (!desc || !desc.set || !desc.configurable) return;
        var own = Object.getOwnPropertyDescriptor(proto, prop);
        Object.defineProperty(proto, prop, {
            configurable: true, enumerable: desc.enumerable, get: desc.get,
            set: function (value) {
                var el = this;
                if (replaying) return desc.set.call(el, value);
                // On a <style> the setter REPLACES all of the text, so nothing
                // existing survives to judge. On a text node inside one it
                // replaces that node's share only, and the siblings stay — so
                // the resulting text is the owner's text without this node,
                // plus the value. Adding the whole parent textContent counted
                // this node's old data twice.
                var context = isStyle(el) ? null : (el && el.parentNode);
                var scope = context ? 'without' : 'replaceChildren';
                if (!eligible(el, value) || !cssRisk(containerText(context || el, el, scope), [value])) return desc.set.call(el, value);
                // innerHTML on anything but a <style> is MARKUP carrying a
                // sheet, not the text of one: delivered through the markup
                // rule, where the sheets inside are judged one by one, and not
                // written back as the text of some container.
                // outerHTML is markup too, and its nodes land in the parent.
                var markup = (prop === 'innerHTML' && !isStyle(el)) || prop === 'outerHTML';
                var host = prop === 'outerHTML' ? (el.parentNode || el) : el;
                defer(function (policy) {
                    if (markup) return deliverMarkup(policy, value, function (html) { el[prop] = html; }, host);
                    deliverCss(policy, context || el, el, scope, [value], function () { el[prop] = value; });
                });
            }
        });
        undo.push(function () {
            if (own) Object.defineProperty(proto, prop, own);
            else delete proto[prop];
        });
    }
    ['textContent', 'innerHTML'].forEach(function (prop) {
        holdCSS(w.HTMLStyleElement && HTMLStyleElement.prototype, prop, function () { return true; });
        // The SVG <style> has its own interface, so the descriptor defined on
        // HTMLStyleElement.prototype never saw it and its text setters were
        // ungated. jsdom has no SVGStyleElement, so the guard here is also what
        // keeps this line harmless there.
        holdCSS(w.SVGStyleElement && SVGStyleElement.prototype, prop, function () { return true; });
    });
    ['data', 'nodeValue'].forEach(function (prop) {
        holdCSS(w.CharacterData && CharacterData.prototype, prop, function (node) { return isStyle(node.parentNode); });
    });
    holdCSS(Element.prototype, 'innerHTML', function (el, value) { return /<style\b/i.test(String(value)); });
    // A shadow root's innerHTML is a separate descriptor on a separate
    // interface, so the line above never covered `root.innerHTML = '<style>…'`.
    holdCSS(w.ShadowRoot && ShadowRoot.prototype, 'innerHTML', function (el, value) { return /<style\b/i.test(String(value)); });
    // outerHTML replaces the element with parsed markup, which can carry a
    // <style> exactly as innerHTML can; it was tagged for the backstop but
    // not held, so the sheet was live a frame before the backstop saw it.
    holdCSS(Element.prototype, 'outerHTML', function (el, value) { return /<style\b/i.test(String(value)); });
    // innerText on a <style> is one more spelling of its text. jsdom defines
    // no innerText, so holdCSS() finds no descriptor there and installs
    // nothing; the line is exercised by the real engines only.
    holdCSS(w.HTMLElement && HTMLElement.prototype, 'innerText', function (el) { return isStyle(el); });
    // `textNode.textContent = css` on a text node INSIDE a <style> is the
    // Node.prototype setter, which the two <style> interfaces above never
    // covered — only `data` and `nodeValue` were. Installed last, so the gates
    // above captured the real descriptor, not this one.
    holdCSS(Node.prototype, 'textContent', function (node) { return isText(node) && isStyle(node.parentNode); });
    // The runtime gates these too when aggressive CSS-URL blocking is on, so
    // leaving them open here would reopen during the download exactly what it
    // closes afterwards. insertAdjacentElement was already held; the HTML and
    // character-data routes to the same <style> text were not.
    replace(Element.prototype, 'insertAdjacentHTML', function (original) {
        return function (position, html) {
            var el = this, markup = String(html == null ? '' : html);
            // A <style> inside the markup brings its own complete text, so the
            // fragment IS the resulting text here. Markup carrying resource
            // elements is handled by the DOM backstop further down, which judges
            // what landed instead of what the string looked like.
            if (!/<style\b/i.test(markup) || !cssURL(markup)) return original.call(el, position, markup);
            defer(function (policy) {
                // beforebegin and afterend land beside the element, in its
                // parent; the other two land inside it.
                deliverMarkup(policy, markup, function (html) { el.insertAdjacentHTML(position, html); },
                    /^(beforebegin|afterend)$/i.test(String(position)) ? (el.parentNode || el) : el);
            });
        };
    });
    function inStyle(node) { return isStyle(node && node.parentNode); }
    // One rule for every route into a <style>, on BOTH sides of the gate: judge
    // the text the engine will parse, never the fragment handed in. A fragment
    // can complete a URL the element already half-holds (`url(https://t` +
    // `/x.png)`), a deletion can reveal one by removing what split it, and a URL
    // can be spelled across the boundary (`...:u` + `rl(https://…)`) so neither
    // side alone shows a sign. Concatenating catches all three without parsing
    // anything.
    //
    // This used to be the hold rule only. The release asked `cssURL(fragment)`
    // instead, so during a safety release every one of those three shapes was
    // applied and fetched — the asymmetry was structural, and it was invisible
    // to the suite because the normal handoff policy sets no `sameOriginOnly`
    // and the release check is a no-op there.
    //
    // Both junction orders are tested rather than modelled per method:
    // concatenating `extra` after the element text is right for append and
    // appendData, wrong for prepend, and wrong for before/after, so the junction
    // that mattered was simply never examined for half the routes. And all
    // arguments are joined in call order, because judging them one at a time let
    // `style.append('.q{background-image:u', 'rl(https://…)}')` through — that
    // one leaks during the ordinary hold window, with no failed runtime needed.
    function cssRisk(text, parts) {
        var joined = joinParts(parts);
        var current = String(text == null ? '' : text);
        return cssURL(joined) || cssURL(current + joined) || cssURL(joined + current);
    }
    // `before`, `after` and `replaceWith` insert SIBLINGS of the node, not
    // children, so the element whose text is about to change is the parent.
    // Testing the node itself held `styleTag.after(text)`, which never becomes
    // CSS, while letting `nodeInsideStyle.after('rl(https://…)}')` through,
    // which does. Both error directions were reachable.
    // The <style> a Range boundary writes into. insertNode lands the node at
    // the range's start, so the element whose text changes is the start
    // container when the boundary sits on a child list, and its parent when the
    // boundary is inside a text node — the same two cases cssOwner() separates
    // for before/after. commonAncestorContainer answers a different question
    // (it can be an ancestor far above the <style>, or the <style>'s parent for
    // a range that spans it) and would both miss and over-hold.
    //
    // surroundContents inside a <style> is held on the same rule, and the
    // reason is worth stating exactly, because the obvious account is wrong.
    // The wrap moves the range's text INTO the new element, and for the CSS
    // engine only the direct child text nodes of a <style> are stylesheet —
    // text inside a wrapper element is not. So the operation can only ever
    // take text OUT of the sheet; it cannot complete a URL. It is held anyway
    // because a hold is the safe direction and costs nothing but a replay,
    // while a rule per method is how this surface was wrong four times.
    // The text a range currently spans. Range.prototype.toString is what the
    // engine answers with, so no walk of our own can disagree with it — but
    // `String(range)` called it through the page's own property, and a page that
    // assigns `Range.prototype.toString = () => ''` then answers "no CSS here"
    // for the one route whose resulting text only the range knows. Captured at
    // install like the attribute natives, and failing CLOSED: with no native to
    // ask, or a throw, the answer is null, which the hold reads as a string
    // cssURL matches and the delivery reads as nothing to deliver, so the
    // insertion is neither let through nor replayed on a missing measurement.
    var nativeRangeText = w.Range && Range.prototype && typeof Range.prototype.toString === 'function'
        ? Range.prototype.toString
        : null;
    function rangeText(range) {
        if (!nativeRangeText) return null;
        try { return String(nativeRangeText.call(range)); } catch (e) { return null; }
    }
    function rangeStyleOwner(range) {
        var at = null;
        try { at = range && range.startContainer; } catch (e) { return null; }
        if (!at) return null;
        if (isStyle(at)) return at;
        return isStyle(at.parentNode) ? at.parentNode : null;
    }
    function cssOwner(node, name) {
        if (!node) return null;
        var owner = (name === 'before' || name === 'after' || name === 'replaceWith') ? node.parentNode : node;
        return isStyle(owner) ? owner : null;
    }
    // The text an owner is left with once `node` is gone. Removing the node that
    // split a URL reveals it, which is the same reason `deleteData` is
    // intercepted; `replaceWith` needs it too, since the node it replaces stops
    // contributing its text.
    function styleTextWithout(owner, node) {
        if (!owner) return '';
        var text = '';
        for (var child = owner.firstChild; child; child = child.nextSibling) {
            if (child === node) continue;
            text += textOf(child);
        }
        return text;
    }
    // `replaceChildren` discards what is there, so its container contributes
    // nothing; `replaceWith` drops the node being replaced.
    function containerText(owner, node, name) {
        if (name === 'replaceChildren') return '';
        if (name === 'replaceWith' || name === 'replaceChild' || name === 'without') return styleTextWithout(owner, node);
        return owner && owner.textContent;
    }
    // Recomputed from the live owner at replay time, not captured at call time:
    // the queue drains in order, so this sees the text that actually landed.
    function allowedStyle(policy, owner, node, name, parts) {
        if (!policy || !policy.sameOriginOnly) return true;
        return !cssRisk(containerText(owner, node, name), parts);
    }
    // The resulting text is what the engine parses, not the fragment handed in.
    // A node already holding `background-image:url(https://tracker` plus an
    // appendData('.test/x)') completes a cross-origin URL while neither half
    // contains one, so checking the argument alone was a bypass.
    function dataResult(name, current, args) {
        var text = String(current == null ? '' : current);
        if (name === 'appendData') return text + String(args[0] == null ? '' : args[0]);
        var offset = Number(args[0]) || 0;
        if (name === 'insertData') return text.slice(0, offset) + String(args[1] == null ? '' : args[1]) + text.slice(offset);
        var count = Number(args[1]) || 0;
        if (name === 'deleteData') return text.slice(0, offset) + text.slice(offset + count);
        return text.slice(0, offset) + String(args[2] == null ? '' : args[2]) + text.slice(offset + count);
    }
    ['appendData', 'insertData', 'replaceData', 'deleteData'].forEach(function (name) {
        var proto = w.CharacterData && CharacterData.prototype;
        if (!proto || typeof proto[name] !== 'function') return;
        replace(proto, name, function (original) {
            return function () {
                var node = this, args = Array.prototype.slice.call(arguments);
                if (!inStyle(node)) return original.apply(node, args);
                // deleteData is here because removing text can reveal a URL:
                // `url(https:/*x*/ /t.test/a.png)` becomes valid once the
                // comment goes, and the fragment it was handed is a length.
                var owner = node.parentNode;
                var result = dataResult(name, node.data, args);
                // The owner's text MINUS this node's current data, plus what the
                // data becomes: the exact string the engine will parse. Adding
                // the parent's whole textContent counted this node's old text
                // twice and asked a different question on each side of the gate.
                if (!cssRisk(styleTextWithout(owner, node), [result])) return original.apply(node, args);
                defer(function (policy) {
                    // Recomputed: earlier tasks in the queue may have changed
                    // this node's data since the call was made.
                    var landing = dataResult(name, node.data, args);
                    deliverCss(policy, owner, node, 'without', [landing], function () { node[name].apply(node, args); });
                });
            };
        });
    });
    replace(Element.prototype, 'insertAdjacentText', function (original) {
        return function (position, text) {
            var el = this;
            // afterbegin/beforeend land INSIDE the element; beforebegin/afterend
            // land beside it, where the owner is the parent. Testing the element
            // for all four held `styleTag.before(text)`, which never becomes
            // CSS, and missed the element-inside-a-style case.
            var inside = position === 'afterbegin' || position === 'beforeend';
            var owner = inside
                ? (isStyle(el) ? el : null)
                : (isStyle(el.parentNode) ? el.parentNode : null);
            if (!owner || !cssRisk(owner.textContent, [text])) return original.call(el, position, text);
            defer(function (policy) {
                // The position names the junction: afterbegin/beforeend are the
                // owner's two ends, beforebegin/afterend sit against `el`.
                deliverCss(policy, owner, inside ? null : el, String(position).toLowerCase(), [text], function () {
                    el.insertAdjacentText(position, text);
                });
            });
        };
    });
    // The CSSOM routes into a sheet. CSSStyleSheet.addRule is the legacy
    // spelling of insertRule and takes the rule in two halves; a grouping rule
    // (@media, @supports, @layer) has an insertRule of its own on another
    // interface, and a rule inserted there is as live as one at the top.
    [
        { proto: w.CSSStyleSheet && CSSStyleSheet.prototype, names: ['insertRule', 'replace', 'replaceSync', 'addRule'] },
        { proto: w.CSSGroupingRule && CSSGroupingRule.prototype, names: ['insertRule'] }
    ].forEach(function (host) { host.names.forEach(function (name) {
        var proto = host.proto;
        if (!proto || typeof proto[name] !== 'function') return;
        replace(proto, name, function (original) {
            return function () {
                var sheet = this, args = Array.prototype.slice.call(arguments);
                var css = name === 'addRule'
                    ? String(args[0] == null ? '' : args[0]) + '{' + String(args[1] == null ? '' : args[1]) + '}'
                    : args[0];
                if (!cssURL(css)) return original.apply(sheet, args);
                if (name === 'replace') return new Promise(function (resolve, reject) {
                    defer(function (policy) {
                        // CSSStyleSheet.replace() resolves with the sheet, and
                        // callers chain on it: `sheet.replace(css).then(s =>
                        // s.insertRule(…))`. Resolving with undefined turned a
                        // refusal into a TypeError inside the caller.
                        if (!allowedCss(policy, css)) return resolve(sheet);
                        sheet.replace.apply(sheet, args).then(resolve, reject);
                    });
                });
                defer(function (policy) { if (allowedCss(policy, css)) sheet[name].apply(sheet, args); });
                // insertRule must answer synchronously with an index, and the
                // rule it would describe does not exist yet. Report the index the
                // caller asked for, clamped into the sheet as the spec requires,
                // which is also the index the rule lands on if it is replayed.
                // A caller that immediately deleteRule()s that index operates on
                // a sheet one rule short; there is no synchronous answer that
                // avoids it, and the alternative — fetching before consent to
                // keep an index honest — is not a trade worth making. addRule
                // answers -1 by specification, whatever happened.
                if (name === 'addRule') return -1;
                if (name !== 'insertRule') return undefined;
                var at = Number(args[1]);
                if (!isFinite(at) || at < 0) at = 0;
                var length = (sheet.cssRules && sheet.cssRules.length) || 0;
                return at > length ? length : at;
            };
        });
    }); });
    // Document.prototype, for the reason the write wrappers give: an instance
    // wrapper is one property lookup away from being skipped.
    replace(w.Document && Document.prototype && typeof Document.prototype.createElement === 'function' ? Document.prototype : d, 'createElement', function (original) {
        return function () {
            var el = original.apply(this, arguments);
            // Kept for judgeScripts() at the handoff, and only while there is
            // one to come. On the safety release every script is judged where
            // it is used — its src assignment and its insertion both decide
            // immediately — and the list, which nothing drains on that path,
            // grew by one element per createElement('script') for the rest of
            // the page view. While pending it is bounded by the fuse.
            if (tagKey(el) === 'SCRIPT' && !immediate) {
                if (scripts.length >= LIMIT) releaseSafely('overflow');
                else scripts.push(el);
            }
            return el;
        };
    });
    // Detached images can fetch before insertion. Hold URL assignments too;
    // replay uses the runtime's own property/setAttribute interceptors, and
    // the runtime installs one for every key in this map — src on
    // img/iframe/source/media/embed/track/input, poster, object data, href,
    // srcset, srcdoc, background and the SVG spellings. A key held here
    // without a runtime gate replays onto the native setter (what happened to
    // <source src> before 1.34.2), so adding one means adding its gate in
    // script.js first and a handoff case in media-embed-object-gate.test.mjs.
    //
    // `srcdoc` is in the list now, and it used to be deliberately absent with
    // the note "it belongs in both files or neither" — so it went into both.
    // Its value is MARKUP, not a URL: the iframe parses it as a document of its
    // own, which no gate on this side can reach, so the unit of judgement is
    // the whole attribute and the question is whether the markup names anything
    // cross-origin.
    //
    // `background` is listed on the elements that reflect it so that the IDL
    // route — `document.body.background = url` — is held like every other
    // reflected URL. Only <body> reflects it as a property in any engine; the
    // table parts are listed for the attribute gate and the DOM backstop, which
    // key on this map too, and their missing descriptor is skipped below.
    var attrs = {
        SCRIPT: ['src', 'href', 'xlink:href'], IFRAME: ['src', 'srcdoc'], IMG: ['src', 'srcset'], SOURCE: ['src', 'srcset'],
        LINK: ['href', 'imageSrcset'], VIDEO: ['src', 'poster'], AUDIO: ['src'],
        EMBED: ['src'], OBJECT: ['data'], TRACK: ['src'], INPUT: ['src'],
        BODY: ['background'], TABLE: ['background'], TR: ['background'], TD: ['background'], TH: ['background'],
        // SVG. <script> is in the list above with href for the same reason:
        // in that namespace a script's URL is an href, and it runs. <feImage>
        // fetches through href exactly as <image> does; its key is the local
        // name uppercased, which is what tagKey() answers for it.
        IMAGE: ['href', 'xlink:href'], USE: ['href', 'xlink:href'], FEIMAGE: ['href', 'xlink:href']
    };
    var installedSetters = [];
    Object.keys(attrs).forEach(function (tag) {
        var constructors = {
            SCRIPT: 'HTMLScriptElement', IFRAME: 'HTMLIFrameElement', IMG: 'HTMLImageElement',
            SOURCE: 'HTMLSourceElement', LINK: 'HTMLLinkElement', VIDEO: 'HTMLVideoElement',
            AUDIO: 'HTMLAudioElement', EMBED: 'HTMLEmbedElement', OBJECT: 'HTMLObjectElement',
            TRACK: 'HTMLTrackElement', INPUT: 'HTMLInputElement', BODY: 'HTMLBodyElement',
            TABLE: 'HTMLTableElement', TR: 'HTMLTableRowElement', TD: 'HTMLTableCellElement', TH: 'HTMLTableCellElement'
        };
        var proto = w[constructors[tag]] && w[constructors[tag]].prototype;
        if (!proto) return;
        attrs[tag].forEach(function (prop) {
            // TD and TH share one interface: install each setter once per
            // prototype, or the second pass would wrap the first.
            if (installedSetters.some(function (s) { return s.proto === proto && s.prop === prop; })) return;
            installedSetters.push({ proto: proto, prop: prop });
            // `src` on <video>/<audio> is declared on HTMLMediaElement, not on
            // the element's own prototype, so an own-property lookup found
            // nothing and those two setters were silently left open. Walk up for
            // the descriptor, install on the tag's prototype.
            var holder = proto;
            while (holder && !Object.getOwnPropertyDescriptor(holder, prop)) holder = Object.getPrototypeOf(holder);
            var desc = holder && Object.getOwnPropertyDescriptor(holder, prop);
            if (!desc || !desc.set || !desc.configurable) return;
            var ownDesc = Object.getOwnPropertyDescriptor(proto, prop);
            Object.defineProperty(proto, prop, {
                configurable: true, enumerable: desc.enumerable,
                get: function () {
                    var values = staged.get(this), record = values && values[prop.toLowerCase()];
                    if (!record) return desc.get.call(this);
                    if (prop.toLowerCase().indexOf('srcset') !== -1) return record.value;
                    try { return record.value ? new URL(record.value, d.baseURI).href : ''; }
                    catch (e) { return record.value; }
                },
                set: function (value) {
                    var el = this;
                    if (replaying) return desc.set.call(el, value);
                    hold(el, prop, value, function () { el[prop] = value; });
                }
            });
            undo.push(function () {
                if (ownDesc) Object.defineProperty(proto, prop, ownDesc);
                else delete proto[prop];
            });
        });
    });
    replace(Element.prototype, 'setAttribute', function (original) {
        return function (name, value) {
            var el = this, key = String(name).toLowerCase(), held = attrs[tagKey(el)];
            if (key === 'background' || (held && held.some(function (p) { return p.toLowerCase() === key; }))) {
                hold(el, key, value, function () { el.setAttribute(name, value); });
                return;
            }
            return original.call(el, name, value);
        };
    });
    // setAttributeNS is the only way to write a namespaced attribute, and
    // `image.setAttributeNS(XLINK, 'xlink:href', url)` is how every SVG library
    // writes the legacy spelling. The engine resolves the attribute by
    // NAMESPACE and LOCAL NAME, so the gate keys on those and not on the
    // qualified name: `setAttributeNS(XLINK, 'p:href', url)` is the same
    // attribute under a prefix nobody wrapped, and comparing the spelling let
    // it through while getAttribute('xlink:href') answered null for it. The
    // staged record is keyed by the canonical spelling the park map knows,
    // and the replay re-issues the call with the arguments as given.
    replace(Element.prototype, 'setAttributeNS', function (original) {
        return function (ns, name, value) {
            var el = this, held = attrs[tagKey(el)];
            var qualified = String(name == null ? '' : name);
            var localName = qualified.slice(qualified.indexOf(':') + 1).toLowerCase();
            var key = null;
            if (held && localName === 'href' && (ns === XLINK || ns == null || ns === '')) {
                key = ns === XLINK ? 'xlink:href' : 'href';
            } else if (held && (ns == null || ns === '') && held.some(function (p) { return p.toLowerCase() === qualified.toLowerCase(); })) {
                key = qualified.toLowerCase();
            }
            if (key && held.some(function (p) { return p.toLowerCase() === key; })) {
                hold(el, key, value, function () { el.setAttributeNS(ns, name, value); });
                return;
            }
            return original.call(el, ns, name, value);
        };
    });
    replace(Element.prototype, 'getAttribute', function (original) {
        return function (name) {
            var values = staged.get(this), record = values && values[String(name).toLowerCase()];
            return record ? record.value : original.call(this, name);
        };
    });
    replace(Element.prototype, 'hasAttribute', function (original) {
        return function (name) {
            var values = staged.get(this);
            return !!(values && values[String(name).toLowerCase()]) || original.call(this, name);
        };
    });
    replace(Element.prototype, 'removeAttribute', function (original) {
        return function (name) {
            var values = staged.get(this);
            if (values) delete values[String(name).toLowerCase()];
            return original.call(this, name);
        };
    });
    if (w.fetch) replace(w, 'fetch', function () {
        return function (input, init) {
            var judged = networkTarget(input);
            if (judged.error) return Promise.reject(judged.error);
            return new Promise(function (resolve, reject) {
                defer(function (policy) {
                    // Resolve with an empty 200 rather than rejecting, matching
                    // what the runtime's own fetch gate returns. A rejection
                    // here would surface as an unhandled promise rejection in
                    // callers that never expected to be blocked.
                    if (!allowed(policy, judged.url)) {
                        return resolve(typeof w.Response === 'function'
                            ? new w.Response('', { status: 200, statusText: 'Blocked by consent' })
                            : { ok: true, status: 200, statusText: 'Blocked by consent' });
                    }
                    w.fetch(judged.pass, init).then(resolve, reject);
                });
            });
        };
    });
    if (w.navigator.sendBeacon) replace(w.navigator, 'sendBeacon', function () {
        return function (url, data) {
            var judged = networkTarget(url);
            if (judged.error) throw judged.error;
            defer(function (policy) {
                if (allowed(policy, judged.url)) w.navigator.sendBeacon(judged.pass, data);
            });
            return true;
        };
    });
    var xhrState = new WeakMap();
    replace(XMLHttpRequest.prototype, 'open', function (original) {
        return function (method, url, async) {
            // The native reads the URL here, so it is read here once: send()
            // judges this same string instead of stringifying the input again.
            var judged = networkTarget(url);
            if (judged.error) throw judged.error;
            xhrState.set(this, { url: judged.url, sync: async === false, cancelled: false });
            var args = Array.prototype.slice.call(arguments);
            if (args.length > 1) args[1] = judged.pass;
            return original.apply(this, args);
        };
    });
    replace(XMLHttpRequest.prototype, 'abort', function (original) {
        return function () {
            var state = xhrState.get(this);
            if (state) state.cancelled = true;
            return original.apply(this, arguments);
        };
    });
    replace(XMLHttpRequest.prototype, 'send', function (original) {
        return function (body) {
            var xhr = this, state = xhrState.get(xhr);
            // A synchronous request cannot be suspended while JS is executing.
            // Fail closed instead of silently turning it into an async request.
            if (state && state.sync) {
                // Same-origin requests cannot reach a third-party endpoint, so
                // they proceed. Matching on '//' instead treated every absolute
                // URL as remote and threw on a site's own `https://host/api`;
                // local() also admits data:, blob: and about:, which make no
                // network request and were refused for looking remote.
                if (local(state.url)) return original.call(xhr, body);
                // Nothing can suspend a synchronous XHR, so the choice is
                // between making the request before consent and refusing it.
                // Say which URL and why, or the caller reports an unexplained
                // InvalidStateError that did not happen before this release.
                throw new DOMException(
                    'FAZ Cookie Manager: refused a synchronous cross-origin XMLHttpRequest to '
                    + String(state.url) + ' because the consent runtime has not loaded yet. '
                    + 'A synchronous request cannot be deferred; use an asynchronous request, '
                    + 'which is held and replayed once consent is known.',
                    'InvalidStateError'
                );
            }
            defer(function (policy) {
                if (state && state.cancelled) return;
                if (!allowed(policy, state && state.url)) return;
                xhr._fazBlocked = !!(state && policy.url && policy.url(state.url));
                xhr.send(body);
            });
        };
    });
    // A pending socket exposes the native interface through a proxy and only
    // connects after the runtime's WebSocket gate has been installed.
    if (w.WebSocket) replace(w, 'WebSocket', function (Original, bridge) {
        function PendingSocket(url, protocols) {
            if (!new.target) throw new TypeError("WebSocket requires 'new'");
            if (!pending || replaying) return protocols === undefined ? new bridge.current(url) : new bridge.current(url, protocols);
            // Read once, as the constructor reads it (see networkTarget()): the
            // facade, the release judgement and the socket all use this string.
            var judged = networkTarget(url);
            if (judged.error) throw judged.error;
            url = judged.pass;
            var target = new EventTarget(), socket = null, closed = false, facade;
            facade = new Proxy(target, {
                getPrototypeOf: function () { return Original.prototype; },
                get: function (obj, key) {
                    if (key === 'readyState') return socket ? socket.readyState : (closed ? 3 : 0);
                    if (key === 'url') return socket ? socket.url : String(url);
                    if (key === 'bufferedAmount') return socket ? socket.bufferedAmount : 0;
                    if (key === 'protocol' || key === 'extensions') return socket ? socket[key] : '';
                    if (key === 'send') return function (data) {
                        if (!socket) throw new DOMException('WebSocket is connecting', 'InvalidStateError');
                        socket.send(data);
                    };
                    if (key === 'close') return function () {
                        closed = true;
                        if (socket) socket.close.apply(socket, arguments);
                    };
                    if (key === 'binaryType') return socket ? socket.binaryType : (obj.binaryType || 'blob');
                    var value = obj[key];
                    return typeof value === 'function' ? value.bind(obj) : value;
                },
                set: function (obj, key, value) {
                    obj[key] = value;
                    if (socket && key === 'binaryType') socket.binaryType = value;
                    return true;
                }
            });
            defer(function (policy) {
                if (closed || !allowed(policy, url)) return;
                socket = protocols === undefined ? new w.WebSocket(url) : new w.WebSocket(url, protocols);
                if (target.binaryType) socket.binaryType = target.binaryType;
                ['open', 'message', 'error', 'close'].forEach(function (type) {
                    socket.addEventListener(type, function (event) {
                        var copy = type === 'message' ? new MessageEvent(type, { data: event.data, origin: event.origin })
                            : type === 'close' ? new CloseEvent(type, { code: event.code, reason: event.reason, wasClean: event.wasClean }) : new Event(type);
                        target.dispatchEvent(copy);
                        if (typeof target['on' + type] === 'function') target['on' + type].call(facade, copy);
                    });
                });
            });
            return facade;
        }
        PendingSocket.prototype = Original.prototype;
        Object.setPrototypeOf(PendingSocket, Original);
        return PendingSocket;
    });
    // Move a cross-origin URL out of the live attribute and into the parking
    // attribute the runtime already understands, so the element exists for
    // layout while the request stays unmade.
    //
    // Which attribute that is, exactly, is the whole point. The runtime restores
    // on `img[data-faz-src][data-faz-category]`, `source[data-faz-src]
    // [data-faz-category]` and `link[data-faz-href][data-faz-category]`, and it
    // keeps candidate lists in `data-faz-srcset` / `data-faz-imagesrcset`, which
    // its restore pass re-evaluates candidate by candidate. Writing every
    // attribute into one `data-faz-src` key and no category at all meant two
    // separate silent losses: an IMG carrying both src and srcset had its src
    // overwritten by the srcset on the second iteration, and nothing the
    // bootstrap parked was selectable by any restore pass — so a resource that
    // was merely delayed became dead for the rest of the page view, even on
    // full consent. A fix that parks MORE elements has to settle this first, or
    // it trades a leak for destruction.
    function parkTarget(nodeName, key) {
        if (key === 'srcset') return { value: 'data-faz-srcset', category: 'data-faz-srcset-category' };
        if (key === 'imagesrcset') return { value: 'data-faz-imagesrcset', category: '' };
        if (key === 'href' && nodeName === 'LINK') return { value: 'data-faz-href', category: 'data-faz-category' };
        // SVG <image>/<use>/<script>. Both spellings park into ONE attribute and
        // are restored as the SVG2 `href`, which every engine in the support
        // matrix honours — keeping two parked spellings would mean two restore
        // branches that can disagree, which is how a parked resource becomes a
        // dead one.
        if (key === 'href' || key === 'xlink:href') return { value: 'data-faz-href', category: 'data-faz-category' };
        if (key === 'data' && nodeName === 'OBJECT') return { value: 'data-faz-data', category: 'data-faz-category' };
        if (key === 'poster') return { value: 'data-faz-poster', category: 'data-faz-category' };
        if (key === 'background') return { value: 'data-faz-background', category: 'data-faz-category' };
        if (key === 'srcdoc') return { value: 'data-faz-srcdoc', category: 'data-faz-category' };
        return { value: 'data-faz-src', category: 'data-faz-category' };
    }
    // The bootstrap has no provider rules, so it cannot name the category a URL
    // really belongs to — only the runtime can, and it exposes that through the
    // handoff policy. Ask when there is someone to ask; fall back to the
    // runtime's own default for an unrecognised third party otherwise. The
    // category is never the sole gate on restore: script.js re-checks the URL
    // against the live provider rules next to it, so a conservative guess
    // delays nothing it should not.
    var PARK_CATEGORY = 'marketing';
    // What the safety release parked, by origin alone and under that
    // placeholder category. A runtime that arrives afterwards (finish(), the
    // `late` branch) has the rules this file lacks, and these are the
    // decisions it has to see again: an allowed CDN image is handed back, a
    // refused one gets the category its consent actually turns on. Bounded
    // like the queue; past the cap a park simply keeps its placeholder,
    // which delays a restore and releases nothing.
    var safetyParks = [];
    function noteSafetyPark(el, key, value, script) {
        if (safetyParks.length < LIMIT) safetyParks.push({ el: el, key: key, value: String(value), script: !!script });
    }
    function parkCategory(policy, value) {
        if (policy && typeof policy.category === 'function') {
            try {
                var named = policy.category(value);
                if (named) return String(named);
            } catch (e) { /* fall through to the default */ }
        }
        return PARK_CATEGORY;
    }
    // An HTML <script src> is parked in the form the server writes and the
    // runtime restores from: type="text/plain", a data-faz-category, and the
    // URL LIVE in src. _fazUnblockServerSide() selects
    // `script[type="text/plain"][data-faz-category]` and _fazBuildRestoredScript
    // reads `src`; a script whose type is not a JavaScript MIME type is never
    // fetched, so the live attribute makes no request. The type is made inert
    // FIRST, then the URL is written — the other order would fetch.
    //
    // The previous form — src moved into data-faz-src under
    // type="javascript/blocked" — was selectable by no restore pass at all: the
    // runtime's data-faz-src pass lists img/source/media, its javascript/blocked
    // branch requires data-fazcookie, its builder reads src only, and its
    // observer never backs up a script without a src. A script merely refused
    // at the handoff (policy.script threw) therefore stayed dead after the
    // visitor consented, against the contract judgeScripts() states. The SVG
    // spellings keep their data-faz-href park, which
    // script[data-faz-href][data-faz-category] restores.
    function parkScriptSrc(el, src, policy) {
        if (policy === safety) noteSafetyPark(el, 'src', src, true);
        var type = attr(el, 'type');
        if (type && type !== 'text/plain' && type !== 'javascript/blocked' && !nativeHas.call(el, 'data-faz-original-type')) {
            setAttr(el, 'data-faz-original-type', type);
        }
        setAttr(el, 'type', 'text/plain');
        if (!attr(el, 'data-faz-category')) setAttr(el, 'data-faz-category', parkCategory(policy, src));
        dropAttr(el, 'data-faz-src');
        if (attr(el, 'src') !== src) setAttr(el, 'src', src);
    }
    function parkAttribute(el, key, value, policy) {
        if (!el || el.nodeType !== 1) return;
        if (key === 'src' && tagKey(el) === 'SCRIPT') return parkScriptSrc(el, String(value), policy);
        if (policy === safety) noteSafetyPark(el, key, value, false);
        var target = parkTarget(tagKey(el), key);
        setAttr(el, target.value, value);
        if (key === 'srcdoc') setAttr(el, 'data-faz-srcdoc-key', (window._fazConfig || {})._srcdocKey || '');
        // A list restores by re-reading every candidate, so it carries no
        // category of its own; writing one there would collide with a src/href
        // park on the same element.
        if (target.category && !attr(el, target.category)) setAttr(el, target.category, parkCategory(policy, value));
        // The runtime hides a parked iframe rather than leaving an empty frame
        // in the layout; match it so the two paths look the same to a visitor.
        // Except a frame that keeps a live srcdoc: the engine ignores src while
        // srcdoc is present, so parking src leaves it showing that document —
        // the "lite embed" facade — and there is nothing empty to hide. The
        // server and the runtime (_fazHasLiveSrcdoc) leave it visible. When the
        // key being parked IS srcdoc, it is about to leave, so the frame hides.
        if (el.nodeName === 'IFRAME' && el.classList && (key === 'srcdoc' || !has(el, 'srcdoc'))) el.classList.add('faz-hidden');
    }
    function park(el, policy) {
        heldAttrs(el).forEach(function (prop) {
            var key = prop.toLowerCase();
            var value = attr(el, key);
            if (!value || localValue(key, value)) return;
            parkAttribute(el, key, value, policy);
            dropAttr(el, key);
        });
    }
    // The runtime never arrived: blocked by a filter list (the plugin path
    // contains "cookie"), a 404, or an exception while it evaluated. Holding
    // the queue forever would take the whole site's first-party JavaScript
    // down with the trackers — same-origin fetch/XHR, cart fragments, AJAX
    // forms, lazy loaders — so release what cannot reach a third party and
    // keep parking what can. Third-party tracking is cross-origin by
    // definition, and server-side blocking is untouched either way.
    var safety = {
        sameOriginOnly: true,
        script: function (el) {
            // Three places the URL can be at this moment, and finish() visits
            // this list BEFORE it drains the queue, so for a script whose src
            // was assigned through the staged setter neither the live attribute
            // nor the parked one exists yet. Read the staged value too, or the
            // guard below returns early and the element is never stamped — the
            // one case this function exists for.
            var values = staged.get(el) || {};
            // In the SVG namespace a script's URL is an href, in either
            // spelling, and resources() admits the element for it — so the
            // same three places are read for href too, or the guard below
            // returns on "no URL" and the script EXECUTES on the safety
            // release. Measured: `<svg><script href="https://tracker/x.js">`
            // inside a cloned <template> was requested.
            var htmlSrc = attr(el, 'src') || attr(el, 'data-faz-src')
                || (values.src && values.src.value) || '';
            var src = htmlSrc
                || attr(el, 'href') || attr(el, 'xlink:href') || attr(el, 'data-faz-href')
                || (values.href && values.href.value)
                || (values['xlink:href'] && values['xlink:href'].value) || '';
            // An inline script the SITE marked as consent-gated must not run
            // without a consent decision, and there is none on this path. It has
            // no URL, so the origin test below cannot speak for it and used to
            // return here — the script then replayed and executed, which is a
            // consent bypass on the one route where the publisher had said
            // explicitly that the code is gated. Reproduced in Chromium: an
            // inline script carrying data-faz-category="marketing" executed on
            // the safety release. Stamp it and leave it inert; the server-side
            // restore path re-enables it on a later page view once consent
            // allows it.
            if (!src && (nativeHas.call(el, 'data-faz-category') || nativeHas.call(el, 'data-fazcookie') || nativeHas.call(el, 'data-faz-service'))) {
                if (!nativeHas.call(el, 'data-faz-original-type') && attr(el, 'type')) setAttr(el, 'data-faz-original-type', attr(el, 'type'));
                setAttr(el, 'type', 'javascript/blocked');
                setAttr(el, 'data-faz-blocked-by-us', '1');
                return;
            }
            if (!src || local(src)) return;
            // An HTML src: the restorable form (parkScriptSrc). The staged
            // record is retired, or its own task would park the same URL again.
            if (htmlSrc) {
                if (values.src) delete values.src;
                return parkScriptSrc(el, htmlSrc, safety);
            }
            if (!nativeHas.call(el, 'data-faz-original-type') && attr(el, 'type')) setAttr(el, 'data-faz-original-type', attr(el, 'type'));
            setAttr(el, 'type', 'javascript/blocked');
            setAttr(el, 'data-faz-blocked-by-us', '1');
            park(el, safety);
        },
        node: function (el) {
            if (tagKey(el) === 'SCRIPT') return safety.script(el);
            park(el, safety);
        },
        park: function (el, key, value) { parkAttribute(el, key, value, safety); },
        url: function (url) { return !local(url); }
    };
    // The DOM backstop. Every route above gates one API, and that list cannot be
    // completed: markup reaches the parser through innerHTML, outerHTML,
    // insertAdjacentHTML, document.write, Range.createContextualFragment,
    // setHTMLUnsafe and whatever sink is added next, and none of them is a
    // setter this file can wrap. Before the runtime was deferred it did not have
    // to be: script.js installs a MutationObserver on documentElement and that
    // observer — armed in <head>, before any page script ran — was what covered
    // the markup routes. Deferring the runtime arms it after the document has
    // parsed, and a MutationObserver has no retroactive view, so the whole
    // download window lost the only coverage those routes ever had.
    //
    // So this asks the same question the runtime asks, in the same way: judge
    // the DOM, not the API. One observer closes every markup route at once,
    // including the ones nobody has thought of yet.
    //
    // It is asynchronous, and so is the runtime's. Neither is a guarantee
    // against the very first fetch — script.js says so where it defines
    // _fazParkResourceElementIfBlocked — and the goal here is parity with what
    // the page had before, not a promise the platform cannot keep. The
    // synchronous setter gates above remain the hard guarantee for assignments.
    function guard(el) {
        if (!el || el.nodeType !== 1 || !heldAttrs(el).length) return;
        // A parser-built <script src> has already started its request by the
        // time any observer is notified, so parking it afterwards buys nothing
        // and restoring a legitimate one correctly is guesswork. Scripts keep
        // the coverage they have: createElement, the src setter, setAttribute,
        // and the handoff list that policy.script judges with the real rules.
        if (tagKey(el) === 'SCRIPT') return;
        if (tagKey(el) === 'INPUT' && String(attr(el, 'type') || '').toLowerCase() !== 'image') return;
        var values = staged.get(el);
        heldAttrs(el).forEach(function (prop) {
            var key = prop.toLowerCase();
            // Already staged by a setter: that record owns the value and its own
            // queued task will decide. Parking it here would steal the URL from
            // under it and the replay would write an empty attribute.
            if (values && values[key]) return;
            var value = attr(el, key);
            if (!value || localValue(key, value)) return;
            var target = parkTarget(tagKey(el), key);
            // Park on origin alone, immediately, with the default category — so
            // the element is never left both unrequested and unrestorable, even
            // if no handoff ever comes.
            parkAttribute(el, key, value, null);
            dropAttr(el, key);
            // Then put the decision back where the rules are. Origin is all this
            // file can judge; the runtime knows providers, whitelists and the
            // visitor's consent, and on a page that already carries consent the
            // right answer is to hand the attribute straight back — its restore
            // pass has already run by the time the handoff reaches here, so
            // leaving the element parked would strand it for the page view.
            //
            // What is asked depends on what the attribute holds. A URL goes to
            // live.url. A candidate LIST goes candidate by candidate: asked as
            // one string, `/a.png 1x, https://t/b.png 2x` is not a URL to any
            // parser, came back "not blocked", and the list was handed back
            // live with its remote candidate unjudged. A srcdoc is MARKUP and
            // goes to live.markup, which the runtime answers with the category
            // it would park the document under; the same string through
            // live.url read as a relative path and came back "not blocked" too.
            // On the safety release, where this decision is immediate, the
            // re-applied attribute produced a new record, a new park and a new
            // re-application — an endless microtask loop that froze the page,
            // measured in jsdom with a plain `https://` pixel inside an
            // innerHTML-sunk srcdoc. With no runtime there is nothing that can
            // read markup, so a parked srcdoc stays parked; the restore pass
            // of a later page view hands it back on the consent that allows it.
            defer(function (live) {
                var verdict = parkVerdict(el, key, value, live);
                if (!verdict.refused) {
                    unpark(el, key, target);
                    return setAttr(el, key, value);
                }
                // Refused. Now someone can name the category the restore
                // selectors need, so replace the placeholder with the real one.
                if (target.category && live) setAttr(el, target.category, verdict.named || parkCategory(live, value));
                if (live === safety) noteSafetyPark(el, key, value, false);
                if (live && live.node) live.node(el);
            });
        });
    }
    // The question guard() hands to whoever has the rules, and the one a late
    // runtime is asked again about each safety park (reviewSafetyParks).
    function parkVerdict(el, key, value, live) {
        if (key === 'srcdoc') {
            if (live && typeof live.markup === 'function') {
                var named = String(live.markup(el, value) || '');
                return { refused: named !== '', named: named };
            }
            return { refused: true, named: '' };
        }
        var urls = isList(key) ? candidates(value) : [value];
        return { refused: !(live && live.url) || urls.some(function (url) { return !!live.url(url); }), named: '' };
    }
    function unpark(el, key, target) {
        dropAttr(el, target.value);
        if (target.category) dropAttr(el, target.category);
        if (el.nodeName === 'IFRAME' && el.classList) el.classList.remove('faz-hidden');
    }
    // Each park the safety release made, judged again by the runtime that has
    // just taken over. A park the page or the runtime has since changed is
    // theirs and is left alone; so is a category somebody other than this
    // file wrote. An allowed one is handed back through the LIVE setAttribute,
    // which by now is the runtime's own gate, so it is judged a second time
    // by the rules that apply from here on rather than written natively.
    // Scripts are not re-inserted here — restoring one is the runtime's own
    // pass (_fazUnblockServerSide), which reads the category; this corrects
    // the category it will read.
    function reviewSafetyParks(policy) {
        var list = safetyParks;
        safetyParks = [];
        list.forEach(function (park) {
            try {
                var el = park.el;
                if (park.script) {
                    if (attr(el, 'type') === 'text/plain' && attr(el, 'src') === park.value && attr(el, 'data-faz-category') === PARK_CATEGORY) {
                        setAttr(el, 'data-faz-category', parkCategory(policy, park.value));
                    }
                    return;
                }
                var target = parkTarget(tagKey(el), park.key);
                if (attr(el, target.value) !== park.value) return;
                var verdict = parkVerdict(el, park.key, park.value, policy);
                if (!verdict.refused) {
                    unpark(el, park.key, target);
                    return el.setAttribute(park.key, park.value);
                }
                if (target.category && attr(el, target.category) === PARK_CATEGORY) {
                    setAttr(el, target.category, verdict.named || parkCategory(policy, park.value));
                }
                if (policy.node) policy.node(el);
            } catch (error) {
                w.console.warn('[FAZ Cookie Manager] Re-evaluating a resource parked by the safety release failed; it stays parked', error);
            }
        });
    }
    var observer = null;
    var watching = false;
    // One set of options, used for the document and for every shadow root, so
    // the two cannot drift into watching different things.
    var OBSERVE = {
        childList: true, subtree: true,
        attributes: true,
        attributeFilter: ['src', 'srcset', 'href', 'imagesrcset', 'data', 'poster', 'background', 'srcdoc']
    };
    function watch(target) {
        if (!observer || !target) return;
        try { observer.observe(target, OBSERVE); watching = true; }
        catch (e) { /* a detached root, or an engine that refuses it */ }
    }
    // Set once the parser can no longer be inserting, which is NOT the same as
    // reading d.readyState inside the callback: the task that finishes parsing
    // flips readyState before the checkpoint that delivers its records, so the
    // tail of the document arrives with the phase already changed, and which
    // records land on which side of it differs by engine. This flag flips in a
    // listener that first drains the queue itself, so the boundary is drawn by
    // this file instead of by the scheduler.
    var parsed = false;
    if (typeof w.MutationObserver === 'function') {
        observer = new w.MutationObserver(function (records) { observed(records); });
        function observed(records) {
            for (var i = 0; i < records.length; i++) {
                var record = records[i];
                // Unconditional, including while parsing: the parser sets a
                // resource's attributes before it inserts the element, so it
                // never produces an attribute record. One of these is a script
                // rewriting a URL — exactly what must not slip through.
                if (record.type === 'attributes') { guard(record.target); continue; }
                for (var j = 0; j < record.addedNodes.length; j++) {
                    var node = record.addedNodes[j];
                    if (!node || node.nodeType !== 1) continue;
                    // Server-rendered markup is the server-side blocker's to
                    // judge, and it already did. See fromScript() above.
                    if (!fromScript(node)) {
                        // Never a <script>: a parked one (type text/plain) is
                        // not "already started", so a page that flips its type
                        // back and moves it would run it with no verdict. A
                        // moved image, frame or media element fetches nothing
                        // its current attributes did not already fetch.
                        if (served) resources(node).forEach(function (el) { if (tagKey(el) !== 'SCRIPT' && !served.has(el)) served.set(el, urls(el)); });
                        continue;
                    }
                    resources(node).forEach(guard);
                    // Tagged means a script sink built it; untagged here means
                    // the parser did (or a wrapper above already judged it).
                    sheets(node).forEach(function (el) {
                        parkSheet(el, sunk && !sunk.has(el) ? cssFetches : cssURL);
                    });
                }
            }
        }
        try {
            // The DOCUMENT, not documentElement. document.open() replaces
            // documentElement, and an observer left watching the old one is
            // attached to a detached node: no records, no error, no
            // enforcement for the rest of the page view. Observing the
            // document survives that.
            watch(d);
            if (!watching) observer = null;
        } catch (e) { observer = null; }
        // A shadow root is a SEPARATE tree: an observer on the document never
        // reaches it. Insertions through Node and ParentNode are wrapped, so
        // what was unguarded is the markup routes — `root.innerHTML =
        // '<img src=tracker>'` had nothing at all in front of it, and no tag
        // pass either. Watch each root as it is created; the declarative form
        // (<template shadowrootmode>) is the parser's and stays the
        // server-side blocker's to judge, like the rest of its markup.
        if (observer && typeof Element.prototype.attachShadow === 'function') {
            replace(Element.prototype, 'attachShadow', function (original) {
                return function (init) {
                    var root = original.call(this, init);
                    watch(root);
                    return root;
                };
            });
        }
        // Only the normal handoff tears it down — the runtime's own observer
        // takes over there. On the safety path it stays, because nothing else
        // will ever watch this document.
        if (observer) handoffs.push(function () { observer.disconnect(); });
        // Capture phase and registered here, so it runs before any other
        // DOMContentLoaded listener: drain whatever the parser left queued and
        // judge it under the exemption, THEN withdraw the exemption for good.
        // After this point an untagged insertion cannot be the parser's, and
        // anything that is neither tagged nor wrapped is judged rather than
        // trusted.
        if (observer && d.readyState === 'loading') {
            d.addEventListener('DOMContentLoaded', function () {
                try { observed(observer.takeRecords()); } catch (e) { /* nothing left to drain */ }
                parsed = true;
            }, true);
        } else {
            parsed = true;
        }
    }
    // `reason` is 'overflow' when the fuse in defer() tripped; anything else —
    // the DOMContentLoaded event itself, or nothing — is the runtime being
    // missing, which is what the second message says.
    function releaseSafely(reason) {
        if (!pending || immediate) return;
        w.console.warn(reason === 'overflow'
            ? '[FAZ Cookie Manager] More than ' + LIMIT + ' operations were held while waiting for the consent '
                + 'runtime, so the bootstrap stopped queuing them and applied its safety policy instead of '
                + 'letting the queue grow without bound: third-party scripts, iframes, images and embeds stay '
                + "blocked and same-origin requests are released. The consent runtime still takes over if it "
                + 'loads. A script on this page is inserting or requesting at a rate no consent window should see.'
            : '[FAZ Cookie Manager] The consent runtime did not load before DOMContentLoaded, '
                + 'so the consent banner will not appear on this page view. Third-party scripts, '
                + 'iframes, images and embeds stay blocked; same-origin requests are released so the '
                + "site's own JavaScript keeps working. Check the Network tab for a failed or blocked "
                + 'request to frontend/js/script.min.js — the usual causes are an ad-blocker or filter-list '
                + 'rule matching the "cookie" segment in the plugin path, a 404 after a partial update, a '
                + 'minify/combine plugin that mangled the bundle, or a stale CDN copy.'
        );
        // What a visitor sees on this page view, stated plainly because the
        // answer is not "nothing": every embed the SERVER blocked keeps its
        // placeholder and its consent call-to-action, because that markup is in
        // the HTML and the banner stylesheet is a separate, non-deferred file.
        // What goes silently blank is a third-party resource some script tried
        // to insert dynamically after the page loaded — there is no banner to
        // reach and nothing to click. A bootstrap of this size cannot author a
        // consent UI, so it does the one useful thing it can: it marks the
        // document, so a theme or a site stylesheet can say something, and so
        // support can see the state without devtools open.
        try { d.documentElement.className += ' faz-consent-runtime-missing'; } catch (e) { /* noop */ }
        if (w._fazBootstrap) w._fazBootstrap.released = true;
        // The natives are deliberately NOT restored. Restoring them left the page
        // with no client-side enforcement from this point on — a cross-origin
        // script, image, fetch, XHR or beacon created after DOMContentLoaded was
        // neither held nor blocked — while this very warning said the opposite.
        // Keeping the interceptors in place and switching the queue to decide
        // immediately makes the sentence true for the rest of the page view.
        var tasks = queue;
        queue = [];
        immediate = safety;
        replaying++;
        try { judgeScripts(safety); } finally { replaying--; }
        // The queued tasks run BEFORE the map of insertions queued into each
        // container is dropped, the order finish() uses. land() reads that map
        // to repoint a later call made through a node still in its holder
        // (`a.replaceWith(b)`, `a.parentNode.replaceChild(b, a)`, `a.before(b)`)
        // at the node's real destination. Dropped first, it found nothing, and
        // the second node replayed into the emptied, orphaned holder: lost,
        // neither parked nor refused. The fuse in defer() takes this path too,
        // so the loss depended on where the fuse happened to fall.
        tasks.forEach(function (task) { run(task, safety); });
        // Every queued insertion has landed: nothing is waiting INTO a
        // container any more, and nothing will queue again on this path.
        into = new WeakMap();
    }
    // Every script the page created, judged one by one and each on its own,
    // the way run() treats a task. A policy that threw on one element used to
    // abort the loop AND the drain that followed it, with `pending` already
    // false: every held fetch and XHR on the page suspended for good, over one
    // script. And the element itself, left unjudged, had its staged src
    // replayed as allowed — a throw read as permission. Decided the other way
    // now, explicitly: a decision nobody made is a refusal. The script is
    // stamped inert and its URL, live or staged, is parked where the runtime
    // restores from, so the one error a consent runtime can have never runs
    // a script.
    function judgeScripts(policy) {
        var list = scripts;
        scripts = [];
        list.forEach(function (el) {
            try { policy.script(el); }
            catch (error) {
                w.console.warn('[FAZ Cookie Manager] Deferred script decision failed; the script stays blocked', error);
                refuseScript(el);
            }
        });
    }
    function refuseScript(el) {
        // The staged record is retired with its URL parked, or its own task
        // would still replay the assignment as the policy allows it. An HTML
        // src goes into the restorable form (parkScriptSrc): the staged value
        // is the later assignment and wins over a live attribute.
        var values = staged.get(el);
        var src = (values && values.src && values.src.value) || attr(el, 'src') || '';
        if (values && values.src) delete values.src;
        if (src) {
            parkScriptSrc(el, src, null);
            return;
        }
        if (!nativeHas.call(el, 'data-faz-original-type') && attr(el, 'type')) setAttr(el, 'data-faz-original-type', attr(el, 'type'));
        setAttr(el, 'type', 'javascript/blocked');
        setAttr(el, 'data-faz-blocked-by-us', '1');
        // An SVG script fetches through href; same refusal, same parking.
        ['href', 'xlink:href'].forEach(function (key) {
            if (values && values[key]) {
                parkAttribute(el, key, values[key].value, null);
                delete values[key];
            }
            var h = attr(el, key);
            if (h) {
                parkAttribute(el, key, h, null);
                dropAttr(el, key);
            }
        });
    }
    // Deferred scripts always run before DOMContentLoaded, so still pending at
    // that point means the runtime is not coming.
    //
    // Once parsing is over that cannot be read off the event any more. This
    // file is then itself running as a DEFERRED script — an optimiser rewrote
    // the inline bootstrap (LiteSpeed "Load JS Deferred" turns it into a
    // data: URI with `defer`) — and the runtime, deferred after it in the same
    // ordered list, has not run yet. A microtask released before it every
    // time, so the runtime always arrived "late" and every first-party request
    // of the window went out under the origin-only policy instead of the real
    // one. When the runtime's tag is in the document and has not run, its own
    // load or error event is the signal, with a ceiling for a tag that never
    // fires one (a delay-until-interaction loader that runs a copy instead).
    var RUNTIME_WAIT_MS = 5000;
    function runtimeTag() {
        var tag = d.getElementById('faz-cookie-manager-js');
        if (tag && tag.nodeName === 'SCRIPT') return tag;
        var all = d.getElementsByTagName('script');
        for (var i = 0; i < all.length; i++) {
            var src = attr(all[i], 'src') || attr(all[i], 'data-src') || '';
            if (/\/frontend\/js\/script(?:\.min)?\.js(?:[?#]|$)/.test(src)) return all[i];
        }
        return null;
    }
    function releaseAfterRuntime() {
        // Every runtime leaves window.fazcookie behind once it has run — a
        // store-less one included, which is exactly the one that never hands
        // over — so its presence means there is nothing left to wait for.
        var tag = w.fazcookie ? null : runtimeTag();
        if (!tag) return void Promise.resolve().then(releaseSafely);
        var done = false, timer = null;
        var settle = function () {
            if (done) return;
            done = true;
            tag.removeEventListener('load', settle);
            tag.removeEventListener('error', settle);
            if (timer) w.clearTimeout(timer);
            // A microtask later, not now: the handoff of a stale runtime
            // (adoptStale) is itself a microtask queued while it evaluated.
            Promise.resolve().then(releaseSafely);
        };
        tag.addEventListener('load', settle);
        tag.addEventListener('error', settle);
        timer = w.setTimeout(settle, RUNTIME_WAIT_MS);
    }
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', releaseSafely);
    else releaseAfterRuntime();

    // A runtime from before this file — 1.34.0 and older — never calls
    // start() or finish(). A page cache serving that bundle under current
    // markup is not hypothetical: LiteSpeed's "Remove Query Strings" strips
    // the ?ver= that is the only cache buster script.min.js has. Such a
    // runtime installed its gates ON TOP of this file's wrappers, its init ran
    // while this file was still pending, and _fazRenderBanner() — written as
    // `while (body.firstChild) fragment.appendChild(body.firstChild)` — never
    // ended. The held-insertion fix above makes that loop finish; this makes
    // the old runtime work: it is recognised the moment it creates its
    // consent store, which every version does as its first act once it has a
    // configuration (`fazcookie._fazConsentStore = new Map()`), and the
    // bootstrap steps aside exactly as start() would have it step aside —
    // natives back BEFORE the runtime installs its gates, then the held queue
    // drained, a microtask later, once the runtime has finished evaluating and
    // its gates are in place. What that runtime does from there is what it
    // did before the bootstrap existed.
    //
    // The store is the signal, not window.fazcookie itself: a runtime without
    // a configuration creates the object and stops, and that one must leave
    // the bootstrap in charge (the static-config failure path in script.js).
    var adopting = false;
    function adoptStale() {
        if (!pending || adopting) return;
        adopting = true;
        w.console.warn('[FAZ Cookie Manager] A consent runtime older than this page is running: it predates the '
            + 'bootstrap handoff, so a page cache or optimiser is serving a stale frontend/js/script.min.js (a '
            + '"remove query strings" option drops the ?ver= cache buster). The bootstrap hands enforcement to that '
            + 'runtime. Purge the page and JS caches so the current runtime is served.');
        w._fazBootstrap.start();
        Promise.resolve().then(function () { if (pending) w._fazBootstrap.finish(stalePolicy()); });
    }
    // The handoff policy, built from what an old runtime exposes. Its
    // functions are top-level declarations in a classic script, so they are
    // globals once it has evaluated; each is used only if it is there, and a
    // missing matcher leaves `url` out — which makes the backstop keep what
    // it parked (restored by that runtime's own pass, on consent) rather than
    // hand it back unjudged.
    function stalePolicy() {
        var fn = function (name) { return typeof w[name] === 'function' ? w[name] : null; };
        var parkNode = fn('_fazParkResourceElementIfBlocked');
        var blocks = fn('_fazShouldBlockProvider');
        var listed = fn('_fazIsUserWhitelisted');
        var category = fn('_fazImgCategory');
        var policy = {
            stale: true,
            script: function () {},
            node: function (el) {
                if (!parkNode || tagKey(el) === 'SCRIPT') return;
                try { parkNode(el); } catch (e) { /* that runtime's own observer still sees the insertion */ }
            }
        };
        if (blocks) policy.url = function (url) { return !(listed && listed(String(url))) && !!blocks(String(url)); };
        if (category) policy.category = function (url) { return category(String(url)); };
        return policy;
    }
    (function () {
        var own = Object.getOwnPropertyDescriptor(w, 'fazcookie');
        // Someone else's accessor, or a `var fazcookie` (not configurable):
        // not ours to wrap. The stale case then goes unrecognised and falls
        // back to the safety release, which the fixes above keep bounded.
        if (own && (!own.configurable || own.get || own.set)) return;
        var value = own ? own.value : undefined;
        var armed = [];
        function arm(obj) {
            if (!obj || (typeof obj !== 'object' && typeof obj !== 'function')) return;
            if (Object.prototype.hasOwnProperty.call(obj, '_fazConsentStore')) return;
            try {
                Object.defineProperty(obj, '_fazConsentStore', {
                    configurable: true, enumerable: false,
                    get: function () { return undefined; },
                    set: function (store) {
                        Object.defineProperty(obj, '_fazConsentStore', { configurable: true, enumerable: true, writable: true, value: store });
                        adoptStale();
                    }
                });
                armed.push(obj);
            } catch (e) { /* a frozen object: nothing will be stored on it either */ }
        }
        Object.defineProperty(w, 'fazcookie', {
            configurable: true, enumerable: own ? own.enumerable : true,
            get: function () { return value; },
            set: function (next) { value = next; arm(next); }
        });
        arm(value);
        undo.push(function () {
            armed.forEach(function (obj) {
                var trap = Object.getOwnPropertyDescriptor(obj, '_fazConsentStore');
                if (trap && trap.get) delete obj._fazConsentStore;
            });
            if (own || value !== undefined) Object.defineProperty(w, 'fazcookie', { configurable: true, enumerable: true, writable: true, value: value });
            else delete w.fazcookie;
        });
    })();

    w._fazBootstrap = {
        start: function () {
            // No other page JS runs during the synchronous runtime evaluation.
            // Restore first so its permanent interceptors capture native APIs
            // rather than this file's wrappers — which is also why the restore
            // cannot be moved into finish(): script.js installs its own gates
            // between these two calls.
            //
            // That leaves a window: if script.js throws after this line and
            // before finish(), the natives are bare and nothing re-wraps them.
            // It is not a regression — before this release the same throw left
            // the page with no gates at all, because there was no bootstrap to
            // uninstall — and it cannot be closed from here: re-wrapping would
            // have to guess which of the runtime's own interceptors had already
            // been installed. finish() says out loud when it is reached in an
            // unexpected state, which is the part that was silent.
            while (undo.length) undo.pop()();
        },
        finish: function (policy) {
            // A second call must not re-drain. Say so instead of returning in
            // silence: a handoff that does nothing is indistinguishable from a
            // handoff that worked, and that ambiguity cost a review cycle.
            if (!pending) {
                return w.console.warn('[FAZ Cookie Manager] Consent handoff ignored: the bootstrap queue was already drained.');
            }
            // A runtime that arrives AFTER the safety release — an optimiser
            // re-ran this inline script after parsing, or delayed script.js
            // past DOMContentLoaded — used to be refused here with the queue
            // "already drained". The queue was indeed empty, but refusing left
            // two layers in charge: start() had already restored the natives
            // and the runtime had installed its gates, while this file's
            // observer stayed armed with the origin-only safety policy and
            // every wrapper a library had captured during the window still
            // routed to `installed` because `pending` never flipped. Measured:
            // a first-party CDN image inserted through innerHTML after the
            // late handoff was parked under data-faz-category="marketing" with
            // the runtime's rules never consulted, a captured fetch resolved
            // "Blocked by consent" for an allowed API, and <html> kept
            // faz-consent-runtime-missing with the banner on screen. The
            // runtime is what applies consent, so it takes over: the same
            // handoff as on time, with the released state undone. There is
            // nothing to drain, but the scripts created since the release are
            // judged by the real rules, and any straggling decision is made
            // against the runtime's policy rather than by origin.
            var late = immediate === safety;
            if (late) {
                w.console.warn('[FAZ Cookie Manager] The consent runtime loaded after the safety release and takes over '
                    + 'enforcement from here; the warning above about a missing runtime no longer applies to this page view.');
                try {
                    d.documentElement.className = d.documentElement.className
                        .replace(/(^|\s)faz-consent-runtime-missing(?=\s|$)/g, '$1').replace(/\s+/g, ' ').replace(/^\s|\s$/g, '');
                } catch (e) { /* noop */ }
                if (w._fazBootstrap) w._fazBootstrap.released = false;
            }
            handoffs.forEach(function (capture) { capture(); });
            handoffs = [];
            pending = false;
            judgeScripts(policy);
            var tasks = queue;
            queue = [];
            tasks.forEach(function (task) { run(task, policy); });
            into = new WeakMap();
            if (late) {
                immediate = policy;
                // What the safety release parked under its placeholder is
                // decided again by the rules that exist now. A policy with
                // no URL matcher (a stale runtime that exposes none) cannot
                // say anything new, and the parks stay as they are.
                if (typeof policy.url === 'function') reviewSafetyParks(policy);
            }
            safetyParks = [];
        }
    };
})(window, document);
