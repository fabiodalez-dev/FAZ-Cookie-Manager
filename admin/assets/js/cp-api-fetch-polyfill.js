/**
 * wp.apiFetch polyfill for ClassicPress 1.x admin pages.
 *
 * ClassicPress ships a WordPress 4.9 build of wp-api-fetch that lacks
 * `createRootURLMiddleware` (introduced in WP 5.x). Our admin JS depends
 * on the modern API surface, so on ClassicPress we deregister the native
 * wp-api-fetch and replace it with this drop-in polyfill that mirrors
 * the WP 5.x shape.
 *
 * The two server-derived values (REST URL + nonce) are read from the
 * `fazApiFetchConfig` global, populated by `wp_localize_script()` in
 * `admin/class-admin.php::deregister_api_fetch()`.
 *
 * Active only when this file is enqueued — `class-admin.php` enqueues it
 * exclusively on ClassicPress admin pages, so on WordPress this file is
 * never loaded and the native wp-api-fetch ships unchanged.
 */
(function (root, config) {
    'use strict';
    var rootURL = (config && config.restUrl) || '';
    var nonce   = (config && config.nonce) || '';

    var middlewares = [];
    function registerMiddleware(m) { middlewares.unshift(m); }

    function defaultFetchHandler(options) {
        var parse = options.parse !== false;
        return window.fetch(options.url, options).then(function (response) {
            if (!parse) { return response; }
            return response.text().then(function (text) {
                var data;
                try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
                if (!response.ok) {
                    var err = Object.assign(
                        new Error(data && data.message ? data.message : 'Unknown error'),
                        { code: 'unknown_error', data: { status: response.status } },
                        data || {}
                    );
                    return Promise.reject(err);
                }
                return {
                    __fazParsed: true,
                    data: data,
                    headers: { get: function (h) { return response.headers.get(h); } },
                };
            });
        });
    }

    var fetchHandler = defaultFetchHandler;

    function runMiddleware(idx, options) {
        if (idx >= middlewares.length) {
            var req = Object.assign({}, options);
            if (req.data && !req.body && !(req.data instanceof window.FormData)) {
                req.body = JSON.stringify(req.data);
            }
            return fetchHandler(req);
        }
        return middlewares[idx](options, function (next) { return runMiddleware(idx + 1, next); });
    }

    function apiFetch(options) { return runMiddleware(0, options); }

    function createRootURLMiddleware(rootURLArg) {
        return function (options, next) {
            var opts = Object.assign({}, options);
            if (opts.path !== undefined && opts.url === undefined) {
                opts.url = rootURLArg.replace(/\/+$/, '') + '/' + opts.path.replace(/^\/+/, '');
                delete opts.path;
            }
            return next(opts);
        };
    }

    function createNonceMiddleware(initialNonce) {
        var currentNonce = initialNonce;
        var middleware = function (options, next) {
            var opts = Object.assign({}, options);
            opts.headers = Object.assign({}, opts.headers);
            if (currentNonce && !opts.headers['X-WP-Nonce']) {
                opts.headers['X-WP-Nonce'] = currentNonce;
            }
            return next(opts).then(function (result) {
                if (result && result.headers && typeof result.headers.get === 'function') {
                    var fresh = result.headers.get('X-WP-Nonce');
                    if (fresh) { currentNonce = fresh; }
                }
                return (result && result.__fazParsed) ? result.data : result;
            });
        };
        middleware.nonce = currentNonce;
        return middleware;
    }

    function createPreloadingMiddleware(preloadedData) {
        var cache = Object.assign({}, preloadedData);
        return function (options, next) {
            var method = (options.method || 'GET').toUpperCase();
            if (method !== 'GET') { return next(options); }
            var key = options.path || (options.url || '');
            if (Object.prototype.hasOwnProperty.call(cache, key)) {
                var cached = cache[key];
                delete cache[key];
                if (options.parse === false) {
                    return Promise.resolve(
                        new window.Response(JSON.stringify(cached.body), {
                            status: 200,
                            headers: new window.Headers(cached.headers || {}),
                        })
                    );
                }
                return Promise.resolve(cached.body);
            }
            return next(options);
        };
    }

    var mediaUploadMiddleware = function (options, next) {
        var opts = Object.assign({}, options);
        if (opts.data instanceof window.FormData) {
            opts.body = opts.data;
            opts.headers = Object.assign({}, opts.headers);
            delete opts.headers['Content-Type'];
            delete opts.data;
        }
        return next(opts);
    };

    var fetchAllMiddleware = function (options, next) {
        if (options.parse !== false) { return next(options); }
        return next(options).then(function (response) {
            var total = parseInt(
                (response.headers && response.headers.get('X-WP-TotalPages')) || '1',
                10
            );
            if (isNaN(total) || total <= 1) { return response; }
            var pages = [response.json()];
            var base = (options.path || '').replace(/([?&])page=[^&]*/g, '').replace(/\?$/, '');
            for (var p = 2; p <= total; p++) {
                var sep = base.indexOf('?') > -1 ? '&' : '?';
                pages.push(apiFetch(Object.assign({}, options, {
                    path: base + sep + 'page=' + p,
                    parse: true,
                })));
            }
            return Promise.all(pages).then(function (results) {
                return [].concat.apply([], results);
            });
        });
    };

    registerMiddleware(function (options, next) {
        var opts = Object.assign({}, options);
        if (opts.data && !(opts.data instanceof window.FormData)) {
            opts.headers = Object.assign({ 'Content-Type': 'application/json' }, opts.headers || {});
        }
        return next(opts);
    });
    registerMiddleware(createNonceMiddleware(nonce));
    registerMiddleware(createRootURLMiddleware(rootURL));

    apiFetch.use                          = registerMiddleware;
    apiFetch.setFetchHandler              = function (h) { fetchHandler = h; };
    apiFetch.createRootURLMiddleware      = createRootURLMiddleware;
    apiFetch.createNonceMiddleware        = createNonceMiddleware;
    apiFetch.createPreloadingMiddleware   = createPreloadingMiddleware;
    apiFetch.fetchAllMiddleware           = fetchAllMiddleware;
    apiFetch.mediaUploadMiddleware        = mediaUploadMiddleware;

    root.wp           = root.wp || {};
    root.wp.apiFetch  = apiFetch;
}(window, window.fazApiFetchConfig || {}));
