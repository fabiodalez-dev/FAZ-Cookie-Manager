/**
 * Synchronous handoff for the deferred consent runtime. No network request is
 * needed for this small inline bootstrap. Dynamic scripts and requests wait
 * until the full runtime can apply the visitor's actual consent decisions.
 * There is deliberately no timeout that releases tracking when a bundle fails.
 */
(function (w, d) {
    'use strict';
    if (w._fazBootstrap || !w._fazConfig || !w._fazConfig._block) return;
    var queue = [], undo = [], scripts = [], pending = true, handoffs = [], staged = new WeakMap();
    // Parents that already have a held insertion. A later sibling with no
    // resource of its own must queue behind them, or it lands first and the
    // sibling order inverts: appendChild(liWithImg) then appendChild(liText)
    // used to render liText above liWithImg.
    var waiting = new WeakSet();
    function local(url) {
        var value = String(url == null ? '' : url);
        if (!value || value.charAt(0) === '#') return true;
        try { return new URL(value, d.baseURI).origin === w.location.origin; }
        catch (e) { return false; }
    }
    function marks(node) {
        if (!node || !node.nodeType) return [];
        return resources(node).map(function (el) {
            return el.getAttribute('src') || el.getAttribute('href') || el.getAttribute('srcset') || '';
        }).filter(function (value) { return value !== ''; });
    }
    function hold(el, name, value, replay) {
        if (!pending) return replay();
        var values = staged.get(el) || {}, key = name.toLowerCase();
        var record = { value: String(value) };
        values[key] = record;
        staged.set(el, values);
        queue.push(function (policy) {
            if (values[key] !== record) return;
            delete values[key];
            if (allowed(policy, record.value)) return replay();
            // Refused: keep the URL where the runtime's own parking attributes
            // live instead of dropping it, so a later runtime (or a reload)
            // can still restore the resource once consent allows it.
            if (policy && policy.park) policy.park(el, key, record.value);
        });
    }
    function replace(obj, name, fn) {
        var original = obj[name];
        var bridge = { current: original };
        var installed = fn(original, bridge);
        // Libraries may retain a reference to the temporary wrapper. After
        // handoff such references must reach the permanent consent gate.
        obj[name] = name === 'WebSocket' ? installed : function () {
            return (pending ? installed : bridge.current).apply(this, arguments);
        };
        handoffs.push(function () { bridge.current = obj[name]; });
        undo.push(function () { obj[name] = original; });
    }
    function resources(node) {
        if (!node || !node.querySelectorAll) return [];
        var found = Array.prototype.slice.call(node.querySelectorAll('script,iframe,img,source,link'));
        if (node.matches && node.matches('script,iframe,img,source,link')) found.unshift(node);
        return found.filter(function (el) {
            return el.nodeName !== 'SCRIPT' || el.hasAttribute('src') || el.hasAttribute('data-faz-category')
                || el.hasAttribute('data-fazcookie') || el.hasAttribute('data-faz-service');
        });
    }
    function prepare(node, policy) {
        resources(node).forEach(function (el) { policy.node(el); });
    }
    // Delay dynamic insertions, retaining the actual nodes and their listeners.
    ['appendChild', 'insertBefore', 'replaceChild'].forEach(function (name) {
        replace(Node.prototype, name, function (original) {
            return function () {
                var self = this, args = Array.prototype.slice.call(arguments);
                var carries = resources(args[0]).length || (self.nodeName === 'STYLE' && styleHold(self, args[0] && args[0].textContent));
                if (!carries && !waiting.has(self)) return original.apply(self, args);
                waiting.add(self);
                queue.push(function (policy) {
                    prepare(args[0], policy);
                    // The reference node may have moved or been removed while
                    // this insertion waited. Replaying against it throws, and
                    // the node would be dropped with only a console warning,
                    // so fall back to appending into the same parent.
                    if (self.nodeName === 'STYLE' && !allowedCss(policy, args[0] && args[0].textContent)) return;
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
    [Element.prototype, Document.prototype, DocumentFragment.prototype].forEach(function (proto) {
        ['append', 'prepend', 'before', 'after', 'replaceWith', 'replaceChildren'].forEach(function (name) {
            if (typeof proto[name] !== 'function') return;
            replace(proto, name, function (original) {
                return function () {
                    var self = this, args = Array.prototype.slice.call(arguments);
                    if (!args.some(function (n) { return resources(n).length || (self.nodeName === 'STYLE' && styleHold(self, typeof n === 'string' ? n : n && n.textContent)); })) return original.apply(self, args);
                    queue.push(function (policy) {
                        if (self.nodeName === 'STYLE' && !args.every(function (n) { return allowedCss(policy, typeof n === 'string' ? n : n && n.textContent); })) return;
                        args.forEach(function (n) { prepare(n, policy); });
                        self[name].apply(self, args);
                    });
                };
            });
        });
    });
    replace(Element.prototype, 'insertAdjacentElement', function (original) {
        return function (position, node) {
            var el = this;
            if (!resources(node).length && !waiting.has(el)) return original.call(el, position, node);
            waiting.add(el);
            queue.push(function (policy) { prepare(node, policy); el.insertAdjacentElement(position, node); });
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
    function allowed(policy, url) { return !policy || !policy.sameOriginOnly || local(url); }
    // CSS is not released by origin, because judging that means re-implementing
    // the browser's CSS parser: escapes, comments and image-set() can hide a
    // URL from any regex while the engine still fetches it, and the two
    // disagreeing is a bypass. During a safety release no style text that could
    // fetch anything is applied at all. The cost is a stylesheet that stays
    // parked for that page view, which only happens when the runtime is already
    // broken; the benefit is that no parser of ours stands between a visitor
    // and a third-party request.
    function allowedCss(policy, value) { return !policy || !policy.sameOriginOnly || !cssURL(value); }
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
                var context = el && el.nodeName === 'STYLE' ? null : (el && el.parentNode);
                if (!eligible(el, value) || !cssURL(String(((context && context.textContent) || '')) + String(value == null ? '' : value))) return desc.set.call(el, value);
                queue.push(function (policy) { if (allowedCss(policy, value)) el[prop] = value; });
            }
        });
        undo.push(function () {
            if (own) Object.defineProperty(proto, prop, own);
            else delete proto[prop];
        });
    }
    ['textContent', 'innerHTML'].forEach(function (prop) {
        holdCSS(w.HTMLStyleElement && HTMLStyleElement.prototype, prop, function () { return true; });
    });
    ['data', 'nodeValue'].forEach(function (prop) {
        holdCSS(w.CharacterData && CharacterData.prototype, prop, function (node) { return node.parentNode && node.parentNode.nodeName === 'STYLE'; });
    });
    holdCSS(Element.prototype, 'innerHTML', function (el, value) { return /<style\b/i.test(String(value)); });
    // The runtime gates these too when aggressive CSS-URL blocking is on, so
    // leaving them open here would reopen during the download exactly what it
    // closes afterwards. insertAdjacentElement was already held; the HTML and
    // character-data routes to the same <style> text were not.
    replace(Element.prototype, 'insertAdjacentHTML', function (original) {
        return function (position, html) {
            var el = this, markup = String(html == null ? '' : html);
            if (!/<style\b/i.test(markup) || !cssURL(markup)) return original.call(el, position, markup);
            queue.push(function (policy) { if (allowedCss(policy, markup)) el.insertAdjacentHTML(position, markup); });
        };
    });
    function inStyle(node) { return !!(node && node.parentNode && node.parentNode.nodeName === 'STYLE'); }
    // One rule for every route into a <style>: judge the element's current text
    // together with whatever is being added. A fragment can complete a URL the
    // element already half-holds (`url(https://t` + `/x.png)`), a deletion can
    // reveal one by removing what split it, and a URL can be spelled across the
    // boundary (`...:u` + `rl(https://…)`) so neither side alone shows a sign.
    // Concatenating catches all three without parsing anything.
    function styleHold(el, extra) {
        return cssURL(String((el && el.textContent) || '') + String(extra == null ? '' : extra));
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
                var result = dataResult(name, node.data, args);
                if (!styleHold(node.parentNode, result)) return original.apply(node, args);
                queue.push(function (policy) { if (allowedCss(policy, result) && allowedCss(policy, node.parentNode && node.parentNode.textContent)) node[name].apply(node, args); });
            };
        });
    });
    if (w.CharacterData && typeof CharacterData.prototype.replaceWith === 'function') {
        replace(CharacterData.prototype, 'replaceWith', function (original) {
            return function () {
                var node = this, args = Array.prototype.slice.call(arguments);
                if (!inStyle(node) || !args.some(function (a) { return styleHold(node.parentNode, typeof a === 'string' ? a : a && a.textContent); })) return original.apply(node, args);
                queue.push(function (policy) { if (args.every(function (a) { return allowedCss(policy, typeof a === 'string' ? a : a && a.textContent); })) node.replaceWith.apply(node, args); });
            };
        });
    }
    replace(Element.prototype, 'insertAdjacentText', function (original) {
        return function (position, text) {
            var el = this;
            if (el.nodeName !== 'STYLE' || !styleHold(el, text)) return original.call(el, position, text);
            queue.push(function (policy) { if (allowedCss(policy, text)) el.insertAdjacentText(position, text); });
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
                    queue.push(function (policy) {
                        if (!allowedCss(policy, args[0])) return resolve();
                        sheet.replace.apply(sheet, args).then(resolve, reject);
                    });
                });
                queue.push(function (policy) { if (allowedCss(policy, args[0])) sheet[name].apply(sheet, args); });
                return name === 'insertRule' ? (args[1] || 0) : undefined;
            };
        });
    });
    replace(d, 'createElement', function (original) {
        return function () {
            var el = original.apply(this, arguments);
            if (el.nodeName === 'SCRIPT') scripts.push(el);
            return el;
        };
    });
    // Detached images can fetch before insertion. Hold URL assignments too;
    // replay uses the runtime's own property/setAttribute interceptors.
    var attrs = { SCRIPT: ['src'], IFRAME: ['src'], IMG: ['src', 'srcset'], SOURCE: ['src', 'srcset'], LINK: ['href', 'imageSrcset'] };
    Object.keys(attrs).forEach(function (tag) {
        var constructors = { SCRIPT: 'HTMLScriptElement', IFRAME: 'HTMLIFrameElement', IMG: 'HTMLImageElement', SOURCE: 'HTMLSourceElement', LINK: 'HTMLLinkElement' };
        var proto = w[constructors[tag]] && w[constructors[tag]].prototype;
        if (!proto) return;
        attrs[tag].forEach(function (prop) {
            var desc = Object.getOwnPropertyDescriptor(proto, prop);
            if (!desc || !desc.set || !desc.configurable) return;
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
                    hold(el, prop, value, function () { el[prop] = value; });
                }
            });
            undo.push(function () { Object.defineProperty(proto, prop, desc); });
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
                queue.push(function (policy) {
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
            queue.push(function (policy) {
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
                // URL as remote and threw on a site's own `https://host/api`.
                if (local(state.url)) return original.call(xhr, body);
                throw new DOMException('Consent runtime is loading', 'InvalidStateError');
            }
            queue.push(function (policy) {
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
            if (!pending) return protocols === undefined ? new bridge.current(url) : new bridge.current(url, protocols);
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
            queue.push(function (policy) {
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
    function park(el) {
        (attrs[el.nodeName] || []).forEach(function (prop) {
            var value = el.getAttribute(prop);
            if (!value || local(value)) return;
            el.setAttribute(el.nodeName === 'LINK' && prop === 'href' ? 'data-faz-href' : 'data-faz-src', value);
            el.removeAttribute(prop);
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
            // The URL may already sit in data-faz-src: a staged src is parked by
            // its own queued task, which runs before this insertion replays.
            var src = el.getAttribute('src') || el.getAttribute('data-faz-src');
            if (!src || local(src)) return;
            if (!el.hasAttribute('data-faz-original-type') && el.getAttribute('type')) el.setAttribute('data-faz-original-type', el.getAttribute('type'));
            el.setAttribute('type', 'javascript/blocked');
            park(el);
        },
        node: function (el) {
            if (el.nodeName === 'SCRIPT') return safety.script(el);
            park(el);
        },
        park: function (el, attr, value) {
            el.setAttribute(el.nodeName === 'LINK' && attr === 'href' ? 'data-faz-href' : 'data-faz-src', value);
        },
        url: function (url) { return !local(url); }
    };
    function releaseSafely() {
        if (!pending) return;
        w.console.warn('[FAZ Cookie Manager] Consent runtime did not load; releasing same-origin requests only and keeping third-party resources parked.');
        // Restore the native APIs first, or every replayed assignment would be
        // caught by this bootstrap's own interceptors and held a second time.
        while (undo.length) undo.pop()();
        w._fazBootstrap.finish(safety);
    }
    // Deferred scripts always run before DOMContentLoaded, so still pending at
    // that point means the runtime is not coming.
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', releaseSafely);
    else Promise.resolve().then(releaseSafely);

    w._fazBootstrap = {
        start: function () {
            // No other page JS runs during the synchronous runtime evaluation.
            // Restore first so its permanent interceptors capture native APIs.
            while (undo.length) undo.pop()();
        },
        finish: function (policy) {
            if (!pending) return;
            handoffs.forEach(function (capture) { capture(); });
            handoffs = [];
            pending = false;
            scripts.forEach(policy.script);
            scripts = [];
            var tasks = queue;
            queue = [];
            tasks.forEach(function (task) {
                try { task(policy); } catch (error) { w.console.warn('[FAZ Cookie Manager] Deferred resource failed', error); }
            });
        }
    };
})(window, document);
