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
 */
(function (w, d) {
    'use strict';
    if (w._fazBootstrap || !w._fazConfig || !w._fazConfig._block) return;
    var queue = [], undo = [], scripts = [], pending = true, handoffs = [], staged = new WeakMap();
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
    // Parking a stylesheet has to clear the live text WITHOUT going through the
    // gate that holds such a write — otherwise the clear is itself deferred and
    // nothing happens. holdCSS() defines its setter on the <style> interfaces,
    // never on Node.prototype, so this descriptor stays the real one, and
    // calling it directly bypasses the subclass property entirely.
    var nativeTextSet = (Object.getOwnPropertyDescriptor(Node.prototype, 'textContent') || {}).set;
    function attr(el, name) { try { return nativeGet.call(el, name); } catch (e) { return null; } }
    function setAttr(el, name, value) { try { nativeSet.call(el, name, value); } catch (e) { /* detached or read-only */ } }
    function dropAttr(el, name) { try { nativeRemove.call(el, name); } catch (e) { /* noop */ } }
    // One place that decides whether a task runs now or at the handoff, so no
    // call site has to know which of the two states the bootstrap is in.
    function defer(task) {
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
    // A list is same-origin only when EVERY candidate is: the browser picks one
    // by viewport and pixel ratio, so a single remote entry is a request.
    function localValue(key, value) {
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
    // way img and iframe do, and the runtime's own observer walks the DOM rather
    // than an element allowlist, so leaving them out gave the hold window a
    // narrower surface than the window it opened.
    var RESOURCES = 'script,iframe,img,source,link,video,audio,embed,object,track,input';
    function resources(node) {
        if (!node || !node.querySelectorAll) return [];
        var found = Array.prototype.slice.call(node.querySelectorAll(RESOURCES));
        if (node.matches && node.matches(RESOURCES)) found.unshift(node);
        return found.filter(function (el) {
            // An INPUT only fetches as type=image; every other input would be
            // held for nothing.
            if (el.nodeName === 'INPUT') return String(attr(el, 'type') || '').toLowerCase() === 'image';
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
            return el.nodeName !== 'SCRIPT' || nativeHas.call(el, 'src') || nativeHas.call(el, 'data-faz-category')
                || nativeHas.call(el, 'data-fazcookie') || nativeHas.call(el, 'data-faz-service');
        });
    }
    function prepare(node, policy) {
        resources(node).forEach(function (el) { policy.node(el); });
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
    // Restored by this file rather than handed over parked: the runtime adopts a
    // parked original only when the live text is the NEUTRALIZED form of it
    // (_fazPrimeStyleCssMarkers, script.js), and an emptied element does not
    // match, so handing it over would turn a delayed stylesheet into a dead one
    // — the exact trade parkTarget() below refuses to make. Handing back a live
    // <style> lets the runtime's own CSS gate judge it with real consent.
    function parkSheet(el) {
        if (!nativeTextSet || !isStyle(el)) return;
        var text = String(el.textContent == null ? '' : el.textContent);
        if (!text || !cssURL(text)) return;
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
            if (!allowedCss(policy, text)) return;
            // Through the PROPERTY, not the captured native setter. By the time
            // this runs the handoff has uninstalled this file's gate and
            // installed the runtime's (_fazGateStyleTextSetter), so an
            // assignment is judged by it with real consent, while a native
            // write would be invisible to it. Measured in a real browser: the
            // native write handed back a live third-party background-image and
            // the runtime never saw it — a leak traded for a leak. The clear
            // above still uses the native, because there the gate to avoid is
            // this file's own, which would defer the clear and do nothing.
            el.textContent = text;
            dropAttr(el, 'data-faz-css');
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
                    if (!allowedCss(policy, markup)) return;
                    // Through the LIVE property, like insertAdjacentHTML's own
                    // deferred call: by replay time the handoff has installed
                    // the runtime's gate for this sink, and going back to the
                    // native captured here would skip it — measured leaking a
                    // third-party background-image with consent refused.
                    if (typeof self[name] === 'function' && self[name] !== original) {
                        self[name].apply(self, args);
                        tag(self);
                        return;
                    }
                    apply();
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
    // backstop silenceable from the page: a script that runs before this file
    // assigns its own constructor whose parseFromString returns an empty
    // document, and then every question below comes back "nothing written,
    // nothing left open" and document.write regains the parser exemption for
    // free. Both halves are captured, because shadowing either one is enough —
    // `DOMParser.prototype.parseFromString = () => emptyDoc` needs no new
    // constructor at all. Same reason the attribute natives are captured at
    // the top of the file: a reference taken before page script runs cannot be
    // reached by whatever shadows the global afterwards.
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
    // Delay dynamic insertions, retaining the actual nodes and their listeners.
    ['appendChild', 'insertBefore', 'replaceChild'].forEach(function (name) {
        replace(Node.prototype, name, function (original) {
            return function () {
                var self = this, args = Array.prototype.slice.call(arguments);
                var styleOwner = isStyle(self) ? self : null;
                // replaceChild drops the old child, so the text it contributed
                // stops counting — the same bookkeeping replaceWith needs.
                var dropped = name === 'replaceChild' ? args[1] : null;
                var carries = resources(args[0]).length
                    || styleRisk(args[0])
                    || !!(styleOwner && cssRisk(containerText(styleOwner, dropped, name), [args[0]]));
                if (!carries && !waiting.has(self)) return original.apply(self, args);
                waiting.add(self);
                defer(function (policy) {
                    prepare(args[0], policy);
                    // The reference node may have moved or been removed while
                    // this insertion waited. Replaying against it throws, and
                    // the node would be dropped with only a console warning,
                    // so fall back to appending into the same parent.
                    if (styleOwner && !allowedStyle(policy, styleOwner, dropped, name, [args[0]])) return;
                    if (!allowedStyleNode(policy, args[0])) return;
                    var ref = args[1];
                    if (name !== 'appendChild' && ref && ref.parentNode !== self) {
                        if (name === 'replaceChild') Node.prototype.appendChild.call(self, args[0]);
                        else Node.prototype.insertBefore.call(self, args[0], null);
                        return;
                    }
                    self[name].apply(self, args);
                });
                return name === 'replaceChild' ? args[1] : args[0];
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
                    // One joined string, in call order, judged against the text
                    // the owner will actually hold. Per-argument tests missed a
                    // URL split across two arguments of one call.
                    var carries = args.some(function (n) { return resources(n).length || styleRisk(n); })
                        || !!(styleOwner && cssRisk(containerText(styleOwner, self, name), args));
                    if (!carries) return original.apply(self, args);
                    defer(function (policy) {
                        if (styleOwner && !allowedStyle(policy, styleOwner, self, name, args)) return;
                        // One call inserts every argument, so a refusal is
                        // all-or-nothing here as it already is above.
                        if (args.some(function (n) { return !allowedStyleNode(policy, n); })) return;
                        args.forEach(function (n) { prepare(n, policy); });
                        self[name].apply(self, args);
                    });
                };
            });
        });
    });
    // Removing the node that split a URL reveals it — the same reason
    // `deleteData` is intercepted, one level up. `url(https:/*x*/ /t.test/a.png)`
    // becomes a live request once the comment node goes.
    ['remove'].forEach(function (name) {
        [Element.prototype, w.CharacterData && CharacterData.prototype].forEach(function (proto) {
            if (!proto || typeof proto[name] !== 'function') return;
            replace(proto, name, function (original) {
                return function () {
                    var node = this, owner = node.parentNode;
                    if (!isStyle(owner)) return original.call(node);
                    if (!cssRisk(styleTextWithout(owner, node), [])) return original.call(node);
                    defer(function (policy) {
                        if (!allowedStyle(policy, owner, node, 'without', [])) return;
                        node[name].call(node);
                    });
                };
            });
        });
    });
    replace(Node.prototype, 'removeChild', function (original) {
        return function (child) {
            var self = this;
            if (!isStyle(self)) return original.call(self, child);
            if (!cssRisk(styleTextWithout(self, child), [])) return original.call(self, child);
            defer(function (policy) {
                if (!allowedStyle(policy, self, child, 'without', [])) return;
                self.removeChild(child);
            });
            return child;
        };
    });
    // Range.insertNode and Range.surroundContents put a node in the document
    // without touching Node or ParentNode, so they were the one insertion
    // route that reached the DOM unjudged — and the only reason the backstop
    // can treat an untagged insertion as the parser's is that no such route
    // exists. Held like insertAdjacentElement, which has the same shape.
    if (w.Range && Range.prototype) {
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
                        return rangeText(range);
                    }
                    var carries = resources(node).length
                        || styleRisk(node)
                        || cssURL(surrounded())
                        || !!(styleOwner && cssRisk(containerText(styleOwner, null, name), [node]));
                    if (!carries) return original.call(range, node);
                    defer(function (policy) {
                        var owner = rangeStyleOwner(range);
                        if (owner && !allowedStyle(policy, owner, null, name, [node])) return;
                        if (!allowedStyleNode(policy, node) || !allowedCss(policy, surrounded())) return;
                        prepare(node, policy);
                        original.call(range, node);
                    });
                };
            });
        });
    }
    replace(Element.prototype, 'insertAdjacentElement', function (original) {
        return function (position, node) {
            var el = this;
            if (!resources(node).length && !styleRisk(node) && !waiting.has(el)) return original.call(el, position, node);
            waiting.add(el);
            defer(function (policy) {
                if (!allowedStyleNode(policy, node)) return;
                prepare(node, policy);
                el.insertAdjacentElement(position, node);
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
        return /url|@import|image-set|src\s*\(|\\|\/\*/i.test(String(value || ''));
    }
    // During a normal handoff the runtime's permanent interceptors decide what
    // may load, so everything replays. During a safety release there are no
    // such interceptors and no provider rules, so only same-origin work runs.
    function allowed(policy, url, key) { return !policy || !policy.sameOriginOnly || localValue(key, url); }
    // CSS is not released by origin, because judging that means re-implementing
    // the browser's CSS parser: escapes, comments and image-set() can hide a
    // URL from any regex while the engine still fetches it, and the two
    // disagreeing is a bypass. During a safety release no style text that could
    // fetch anything is applied at all. The cost is a stylesheet that stays
    // parked for that page view, which only happens when the runtime is already
    // broken; the benefit is that no parser of ours stands between a visitor
    // and a third-party request.
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
    function allowedStyleNode(policy, node) {
        return !policy || !policy.sameOriginOnly || !styleRisk(node);
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
                defer(function (policy) { if (allowedStyle(policy, context || el, el, scope, [value])) el[prop] = value; });
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
            defer(function (policy) { if (allowedCss(policy, markup)) el.insertAdjacentHTML(position, markup); });
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
        var joined = (parts || []).map(function (part) {
            if (part == null) return '';
            return typeof part === 'string' ? part : String(part.textContent == null ? '' : part.textContent);
        }).join('');
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
    // surroundContents is held on the same rule even though its new parent's
    // own children are discarded by the spec and the wrapped contents stay in
    // the owner's text: broad is the safe direction here, it costs a replay,
    // and a rule per method is how this surface was wrong four times.
    // The text a range currently spans. Range.prototype.toString is what the
    // engine answers with, so no walk of our own can disagree with it — but
    // `String(range)` called it through the page's own property, and a page that
    // assigns `Range.prototype.toString = () => ''` then answers "no CSS here"
    // for the one route whose resulting text only the range knows. Captured at
    // install like the attribute natives, and failing CLOSED: with no native to
    // ask, or a throw, the answer is a string that cssURL matches, so the
    // insertion is held rather than let through on a missing measurement.
    var nativeRangeText = w.Range && Range.prototype && typeof Range.prototype.toString === 'function'
        ? Range.prototype.toString
        : null;
    var UNMEASURED = 'url(';
    function rangeText(range) {
        if (!nativeRangeText) return UNMEASURED;
        try { return String(nativeRangeText.call(range)); } catch (e) { return UNMEASURED; }
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
            text += child.textContent == null ? '' : child.textContent;
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
                    if (!allowedStyle(policy, owner, node, 'without', [landing])) return;
                    node[name].apply(node, args);
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
                if (!allowedStyle(policy, owner, null, 'insertAdjacentText', [text])) return;
                el.insertAdjacentText(position, text);
            });
        };
    });
    ['insertRule', 'replace', 'replaceSync'].forEach(function (name) {
        var proto = w.CSSStyleSheet && CSSStyleSheet.prototype;
        if (!proto || typeof proto[name] !== 'function') return;
        replace(proto, name, function (original) {
            return function () {
                var sheet = this, args = Array.prototype.slice.call(arguments);
                if (!cssURL(args[0])) return original.apply(sheet, args);
                if (name === 'replace') return new Promise(function (resolve, reject) {
                    defer(function (policy) {
                        // CSSStyleSheet.replace() resolves with the sheet, and
                        // callers chain on it: `sheet.replace(css).then(s =>
                        // s.insertRule(…))`. Resolving with undefined turned a
                        // refusal into a TypeError inside the caller.
                        if (!allowedCss(policy, args[0])) return resolve(sheet);
                        sheet.replace.apply(sheet, args).then(resolve, reject);
                    });
                });
                defer(function (policy) { if (allowedCss(policy, args[0])) sheet[name].apply(sheet, args); });
                // insertRule must answer synchronously with an index, and the
                // rule it would describe does not exist yet. Report the index the
                // caller asked for, clamped into the sheet as the spec requires,
                // which is also the index the rule lands on if it is replayed.
                // A caller that immediately deleteRule()s that index operates on
                // a sheet one rule short; there is no synchronous answer that
                // avoids it, and the alternative — fetching before consent to
                // keep an index honest — is not a trade worth making.
                if (name !== 'insertRule') return undefined;
                var at = Number(args[1]);
                if (!isFinite(at) || at < 0) at = 0;
                var length = (sheet.cssRules && sheet.cssRules.length) || 0;
                return at > length ? length : at;
            };
        });
    });
    // Document.prototype, for the reason the write wrappers give: an instance
    // wrapper is one property lookup away from being skipped.
    replace(w.Document && Document.prototype && typeof Document.prototype.createElement === 'function' ? Document.prototype : d, 'createElement', function (original) {
        return function () {
            var el = original.apply(this, arguments);
            if (el.nodeName === 'SCRIPT') scripts.push(el);
            return el;
        };
    });
    // Detached images can fetch before insertion. Hold URL assignments too;
    // replay uses the runtime's own property/setAttribute interceptors.
    // `srcdoc` is deliberately absent: the runtime does not gate it either, and
    // closing it here alone would hold an iframe for the download and then free
    // it the moment the runtime lands — the inverse asymmetry. It belongs in
    // both files or neither.
    var attrs = {
        SCRIPT: ['src'], IFRAME: ['src'], IMG: ['src', 'srcset'], SOURCE: ['src', 'srcset'],
        LINK: ['href', 'imageSrcset'], VIDEO: ['src', 'poster'], AUDIO: ['src'],
        EMBED: ['src'], OBJECT: ['data'], TRACK: ['src'], INPUT: ['src']
    };
    Object.keys(attrs).forEach(function (tag) {
        var constructors = {
            SCRIPT: 'HTMLScriptElement', IFRAME: 'HTMLIFrameElement', IMG: 'HTMLImageElement',
            SOURCE: 'HTMLSourceElement', LINK: 'HTMLLinkElement', VIDEO: 'HTMLVideoElement',
            AUDIO: 'HTMLAudioElement', EMBED: 'HTMLEmbedElement', OBJECT: 'HTMLObjectElement',
            TRACK: 'HTMLTrackElement', INPUT: 'HTMLInputElement'
        };
        var proto = w[constructors[tag]] && w[constructors[tag]].prototype;
        if (!proto) return;
        attrs[tag].forEach(function (prop) {
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
            var el = this, key = String(name).toLowerCase(), held = attrs[el.nodeName];
            if (held && held.some(function (p) { return p.toLowerCase() === key; })) {
                hold(el, key, value, function () { el.setAttribute(name, value); });
                return;
            }
            return original.call(el, name, value);
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
            var target = input && input.url ? input.url : input;
            return new Promise(function (resolve, reject) {
                defer(function (policy) {
                    // Resolve with an empty 200 rather than rejecting, matching
                    // what the runtime's own fetch gate returns. A rejection
                    // here would surface as an unhandled promise rejection in
                    // callers that never expected to be blocked.
                    if (!allowed(policy, target)) {
                        return resolve(typeof w.Response === 'function'
                            ? new w.Response('', { status: 200, statusText: 'Blocked by consent' })
                            : { ok: true, status: 200, statusText: 'Blocked by consent' });
                    }
                    w.fetch(input, init).then(resolve, reject);
                });
            });
        };
    });
    if (w.navigator.sendBeacon) replace(w.navigator, 'sendBeacon', function () {
        return function (url, data) {
            defer(function (policy) {
                if (allowed(policy, url)) w.navigator.sendBeacon(url, data);
            });
            return true;
        };
    });
    var xhrState = new WeakMap();
    replace(XMLHttpRequest.prototype, 'open', function (original) {
        return function (method, url, async) {
            xhrState.set(this, { url: url, sync: async === false, cancelled: false });
            return original.apply(this, arguments);
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
        if (key === 'data' && nodeName === 'OBJECT') return { value: 'data-faz-data', category: 'data-faz-category' };
        if (key === 'poster') return { value: 'data-faz-poster', category: 'data-faz-category' };
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
    function parkCategory(policy, value) {
        if (policy && typeof policy.category === 'function') {
            try {
                var named = policy.category(value);
                if (named) return String(named);
            } catch (e) { /* fall through to the default */ }
        }
        return PARK_CATEGORY;
    }
    function parkAttribute(el, key, value, policy) {
        if (!el || el.nodeType !== 1) return;
        var target = parkTarget(el.nodeName, key);
        setAttr(el, target.value, value);
        // A list restores by re-reading every candidate, so it carries no
        // category of its own; writing one there would collide with a src/href
        // park on the same element.
        if (target.category && !attr(el, target.category)) setAttr(el, target.category, parkCategory(policy, value));
        // The runtime hides a parked iframe rather than leaving an empty frame
        // in the layout; match it so the two paths look the same to a visitor.
        if (el.nodeName === 'IFRAME' && el.classList) el.classList.add('faz-hidden');
    }
    function park(el, policy) {
        (attrs[el.nodeName] || []).forEach(function (prop) {
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
            var src = attr(el, 'src') || attr(el, 'data-faz-src')
                || (values.src && values.src.value) || '';
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
            if (!nativeHas.call(el, 'data-faz-original-type') && attr(el, 'type')) setAttr(el, 'data-faz-original-type', attr(el, 'type'));
            setAttr(el, 'type', 'javascript/blocked');
            setAttr(el, 'data-faz-blocked-by-us', '1');
            park(el, safety);
        },
        node: function (el) {
            if (el.nodeName === 'SCRIPT') return safety.script(el);
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
        if (!el || el.nodeType !== 1 || !attrs[el.nodeName]) return;
        // A parser-built <script src> has already started its request by the
        // time any observer is notified, so parking it afterwards buys nothing
        // and restoring a legitimate one correctly is guesswork. Scripts keep
        // the coverage they have: createElement, the src setter, setAttribute,
        // and the handoff list that policy.script judges with the real rules.
        if (el.nodeName === 'SCRIPT') return;
        if (el.nodeName === 'INPUT' && String(attr(el, 'type') || '').toLowerCase() !== 'image') return;
        var values = staged.get(el);
        attrs[el.nodeName].forEach(function (prop) {
            var key = prop.toLowerCase();
            // Already staged by a setter: that record owns the value and its own
            // queued task will decide. Parking it here would steal the URL from
            // under it and the replay would write an empty attribute.
            if (values && values[key]) return;
            var value = attr(el, key);
            if (!value || localValue(key, value)) return;
            var target = parkTarget(el.nodeName, key);
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
            defer(function (live) {
                if (live && live.url && !live.url(value)) {
                    dropAttr(el, target.value);
                    if (target.category) dropAttr(el, target.category);
                    if (el.nodeName === 'IFRAME' && el.classList) el.classList.remove('faz-hidden');
                    return setAttr(el, key, value);
                }
                // Refused. Now someone can name the category the restore
                // selectors need, so replace the placeholder with the real one.
                if (target.category && live) setAttr(el, target.category, parkCategory(live, value));
                if (live && live.node) live.node(el);
            });
        });
    }
    var observer = null;
    var watching = false;
    // One set of options, used for the document and for every shadow root, so
    // the two cannot drift into watching different things.
    var OBSERVE = {
        childList: true, subtree: true,
        attributes: true,
        attributeFilter: ['src', 'srcset', 'href', 'imagesrcset', 'data', 'poster']
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
                    if (!fromScript(node)) continue;
                    resources(node).forEach(guard);
                    sheets(node).forEach(parkSheet);
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
    function releaseSafely() {
        if (!pending || immediate) return;
        w.console.warn(
            '[FAZ Cookie Manager] The consent runtime did not load before DOMContentLoaded, '
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
        try { scripts.forEach(safety.script); } finally { replaying--; }
        scripts = [];
        tasks.forEach(function (task) { run(task, safety); });
    }
    // Deferred scripts always run before DOMContentLoaded, so still pending at
    // that point means the runtime is not coming.
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', releaseSafely);
    else Promise.resolve().then(releaseSafely);

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
            // A second call, or a call after the safety release already decided,
            // must not re-drain. Say so instead of returning in silence: a
            // handoff that does nothing is indistinguishable from a handoff that
            // worked, and that ambiguity cost a review cycle.
            if (!pending || immediate) {
                return w.console.warn('[FAZ Cookie Manager] Consent handoff ignored: the bootstrap queue was already drained'
                    + (immediate ? ' by the safety release.' : '.'));
            }
            handoffs.forEach(function (capture) { capture(); });
            handoffs = [];
            pending = false;
            scripts.forEach(policy.script);
            scripts = [];
            var tasks = queue;
            queue = [];
            tasks.forEach(function (task) { run(task, policy); });
        }
    };
})(window, document);
