/**
 * FAZ Cookie Manager — shared browser-based cookie scan engine.
 *
 * The scan has to run in a browser, not on the server: a PHP crawl
 * (wp_remote_get + Set-Cookie headers) sees only what the response headers
 * declare, which on a typical site is one or two cookies. Everything that
 * matters for consent — Analytics, ad pixels, embedded players — writes its
 * cookies from JavaScript, and the only way to observe those is to load the
 * page somewhere that executes scripts. Hence hidden iframes.
 *
 * This engine used to live inside the Cookies page script, which is why the
 * setup wizard reached for the server-side endpoint instead and reported a
 * fraction of the real inventory. Extracted here so both surfaces run the same
 * scan and can never disagree about what a site sets.
 *
 * Talks to its caller through callbacks, never DOM nodes, so each page renders
 * progress in its own idiom. Requires a `#faz-scan-frame` container in the page
 * markup to host the iframes.
 */
(function () {
	'use strict';

	// i18n helper — looks up fazConfig.i18n.<key> with dot-notation, falls back
	// to the provided string. Mirrors the per-page helper so the engine carries
	// no dependency on whichever page happens to load it.
	function __(key, fallback) {
		var parts = key.split('.');
		var obj = (window.fazConfig && window.fazConfig.i18n) || {};
		for (var i = 0; i < parts.length; i++) {
			if (!obj || typeof obj !== 'object') { return fallback; }
			obj = obj[parts[i]];
		}
		return typeof obj === 'string' ? obj : fallback;
	}

	var IFRAME_LOAD_TIMEOUT = 15000; // Max wait for iframe load (ms). Increased for sites with cache/optimization plugins.
	var CONCURRENCY = 2;             // Parallel iframes (reduced for slow hosts).
	var EARLY_STOP_THRESHOLD = 7;    // Stop after N consecutive pages with no new findings.
	var SAFE_SCAN_THRESHOLD = 1000;  // Deep/full scans use safer timings and disable early stop.

	/**
	 * Normalize a URL: strip hash, preserve query, ensure trailing slash.
	 * Query params are kept by appending `u.search` to the normalized URL.
	 */
	function normalizeUrl(url) {
		try {
			var u = new URL(url, window.location.origin);
			return u.origin + u.pathname.replace(/\/?$/, '/') + u.search;
		} catch (_unused) {
			return url;
		}
	}

	/**
	 * Deduplicate and normalize an array of URLs.
	 */
	function deduplicateUrls(urls) {
		var seen = {};
		var result = [];
		for (var i = 0; i < urls.length; i++) {
			var n = normalizeUrl(urls[i]);
			if (!seen[n]) {
				seen[n] = true;
				result.push(n);
			}
		}
		return result;
	}

	function getApiErrorStatus(err) {
		if (!err) return 0;
		if (typeof err.status === 'number') return err.status;
		if (err.data && typeof err.data.status === 'number') return err.data.status;
		return 0;
	}

	function buildScanApiErrorDetail(err) {
		var status = getApiErrorStatus(err);
		var parts = [];
		if (status === 401) {
			parts.push('Session expired. Refresh the page and try again.');
		} else if (status === 403) {
			parts.push('Nonce/permissions error. Refresh the page and verify admin access.');
		} else if (status === 409) {
			parts.push('Another scan is already in progress.');
		} else if (status === 413) {
			parts.push('Request too large. Reduce scan depth and retry.');
		} else if (status === 429) {
			parts.push('Too many requests. Wait a moment and retry.');
		} else if (status >= 500) {
			parts.push('Server error. Check PHP/web server logs.');
		} else if (status === 0) {
			parts.push('Network/CORS/proxy issue while calling REST API.');
		}
		if (err && err.code) {
			parts.push('Code: ' + err.code + '.');
		}
		if (err && err.message) {
			parts.push(err.message);
		}
		return parts.length ? ' ' + parts.join(' ') : '';
	}

	function buildScanDiagnosticsHint(diagnostics, foundItems) {
		if (!diagnostics || foundItems > 0) return '';
		var hints = [];
		if (diagnostics.crossOrigin > 0) {
			hints.push('URL origin/protocol mismatch between admin and scanned pages');
		}
		if (diagnostics.iframeInaccessible > 0) {
			hints.push('iframe access blocked (X-Frame-Options/CSP or cross-origin redirect)');
		}
		if (diagnostics.iframeTimeout > 0 || diagnostics.settleTimeout > 0) {
			hints.push('pages too slow or blocked during iframe load');
		}
		if (diagnostics.missingContainer > 0) {
			hints.push('scanner iframe container missing in page markup');
		}
		if (diagnostics.invalidUrl > 0) {
			hints.push('invalid URLs discovered');
		}
		if (diagnostics.originRebased > 0) {
			hints.push('public URLs were retried through the WordPress admin origin');
		}
		return hints.length ? ' Possible blockers: ' + hints.join('; ') + '.' : '';
	}

	// A server-side enrichment pass is useful only after at least one page was
	// actually observable in the browser. If every iframe failed and produced no
	// browser evidence, importing the server fallback would recreate the old,
	// misleading "one or two cookies found" result that this engine replaced.
	function browserScanUnavailable(diagnostics, pagesScanned, cookies, scripts) {
		return !!diagnostics && pagesScanned > 0 && diagnostics.totalIssues >= pagesScanned
			&& cookies.length === 0 && scripts.length === 0;
	}

	/**
	 * Run a full browser-based scan.
	 *
	 * Discovers URLs, crawls them in hidden iframes — which is the whole point:
	 * an iframe executes the page's JavaScript, so cookies written by Analytics,
	 * ad pixels and embeds are actually observed. A server-side pass over the
	 * homepage then adds what markup alone reveals (deferred/data-src scripts),
	 * and the merged result is imported.
	 *
	 * Progress is reported through callbacks rather than DOM nodes, so the
	 * Cookies page and the setup wizard can present it however each prefers
	 * without the engine knowing either page exists.
	 *
	 * @param {Object} options       { maxPages } — 0 requests the server cap (full scan).
	 * @param {Object} hooks         { status(text), progress(pct), pages(text) } — all optional.
	 * @return {Promise<Object>}     Resolves { total, pagesScanned, cookies, scripts,
	 *                               diagnostics, metrics, importResult, fingerprint,
	 *                               earlyStopReason }; rejects with an Error carrying
	 *                               a display-ready .message.
	 */
	function runScan(options, hooks) {
		options = options || {};
		hooks = hooks || {};
		var emit = {
			status: function (t) { if (typeof hooks.status === 'function') { hooks.status(t); } },
			progress: function (p) { if (typeof hooks.progress === 'function') { hooks.progress(p); } },
			pages: function (t) { if (typeof hooks.pages === 'function') { hooks.pages(t); } },
		};

		return new Promise(function (resolve, reject) {
			var parsedMaxPages = parseInt(options.maxPages, 10);
			var requestPages = 20;
			var isFullScan = false;
			if (isFinite(parsedMaxPages) && parsedMaxPages > 0) {
				requestPages = parsedMaxPages;
			} else if (parsedMaxPages === 0) {
				// "Full scan": request the maximum server cap.
				requestPages = 2000;
				isFullScan = true;
			}
			var safeMode = isFullScan || requestPages >= SAFE_SCAN_THRESHOLD;
			var scanOptions = {
				enableEarlyStop: !safeMode,
				loadTimeoutMs: safeMode ? 10000 : IFRAME_LOAD_TIMEOUT,
				settleTimeoutMs: safeMode ? 2600 : 1700,
			};

			var scanMetrics = {
				discoverMs: 0, scanMs: 0, importMs: 0,
				pageTimes: [], urlsDiscovered: 0,
				cookiesFound: 0, scriptsFound: 0,
				earlyStopReason: null, pagesScanned: 0,
				incremental: false,
			};
			var discoverStart = Date.now();

			var storedFingerprint = '';
			try {
				storedFingerprint = localStorage.getItem('faz_scan_fingerprint') || '';
			} catch (e) {
				console.warn('[FAZ Scanner] Cannot read fingerprint from localStorage — incremental scanning disabled.', e.message);
			}
			var allowIncremental = !safeMode && !!storedFingerprint;

			var discoverPayload = {
				max_pages: requestPages,
				fingerprint: (!safeMode && allowIncremental) ? storedFingerprint : '',
			};

			function discoverWithRetry(attempt) {
				return FAZ.post('scans/discover', discoverPayload).catch(function (err) {
					if (attempt < 2 && err && err.code === 'fetch_error') {
						var delay = attempt === 0 ? 1000 : 3000;
						emit.status(__('cookies.serverBusyRetrying', 'Server busy, retrying in %ds...').replace('%d', delay / 1000));
						console.warn('[FAZ Scanner] Discover attempt ' + (attempt + 1) + ' failed, retrying...', err.message);
						return new Promise(function (r) { setTimeout(r, delay); })
							.then(function () { return discoverWithRetry(attempt + 1); });
					}
					throw err;
				});
			}

			discoverWithRetry(0).then(function (result) {
				scanMetrics.discoverMs = Date.now() - discoverStart;
				scanMetrics.incremental = !!(allowIncremental && result.incremental);
				var mainUrls = deduplicateUrls(result.urls || []);

				// WooCommerce priority URLs — prepended and exempt from early stop.
				var rawPriority = deduplicateUrls(result.priority_urls || []);
				var prioritySet = {};
				for (var p = 0; p < rawPriority.length; p++) {
					prioritySet[rawPriority[p]] = true;
				}
				var mainSeen = {};
				for (var m = 0; m < rawPriority.length; m++) {
					mainSeen[rawPriority[m]] = true;
				}
				var urls = rawPriority.slice();
				for (var u = 0; u < mainUrls.length; u++) {
					if (!mainSeen[mainUrls[u]]) {
						mainSeen[mainUrls[u]] = true;
						urls.push(mainUrls[u]);
					}
				}

				scanMetrics.urlsDiscovered = urls.length;
				scanOptions.priorityUrls = prioritySet;

				if (!urls.length) {
					reject(new Error(__('cookies.noPagesFound', 'No pages found to scan.')));
					return;
				}

				emit.status(__('cookies.scanningPages', 'Scanning 0/%d pages...').replace('%d', urls.length));
				emit.pages('0/' + urls.length + ' pages');
				emit.progress(0);

				var scanStart = Date.now();

				scanUrlsConcurrent(urls, function (collectedCookies, collectedScripts, diagnostics) {
					scanMetrics.scanMs = Date.now() - scanStart;
					scanMetrics.cookiesFound = collectedCookies.length;
					scanMetrics.scriptsFound = collectedScripts.length;

					if (browserScanUnavailable(
						diagnostics,
						scanMetrics.pagesScanned,
						collectedCookies,
						collectedScripts
					)) {
						var browserError = new Error(
							__('cookies.browserScanUnavailable', 'The browser scan could not inspect any page. Make sure the public site is reachable through the WordPress admin origin and that framing is not blocked.')
							+ buildScanDiagnosticsHint(diagnostics, 0)
						);
						browserError.stage = 'browser';
						reject(browserError);
						return;
					}

					// Server-side pass on the HOMEPAGE catches data-src / deferred
					// scripts an iframe never requests. Site root, not urls[0],
					// which may be a WooCommerce page after priority prepending.
					if (urls.length > 0) {
						emit.status(__('cookies.enrichingServer', 'Enriching with server scan...'));
						var homepageUrl = result.home_url || urls[0];
						FAZ.post('scans/server-scan', { url: homepageUrl }).then(function (serverResult) {
							var existingScripts = {};
							collectedScripts.forEach(function (s) { existingScripts[s] = true; });
							if (serverResult && Array.isArray(serverResult.scripts)) {
								serverResult.scripts.forEach(function (s) {
									if (!existingScripts[s]) {
										collectedScripts.push(s);
										existingScripts[s] = true;
									}
								});
							}
							var existingCookies = {};
							collectedCookies.forEach(function (c) { existingCookies[(c.name || '').toLowerCase()] = true; });
							if (serverResult && Array.isArray(serverResult.cookies)) {
								serverResult.cookies.forEach(function (c) {
									if (c.name && !existingCookies[c.name.toLowerCase()]) {
										collectedCookies.push(c);
										existingCookies[c.name.toLowerCase()] = true;
									}
								});
							}
							scanMetrics.cookiesFound = collectedCookies.length;
							scanMetrics.scriptsFound = collectedScripts.length;
							doImport();
						}).catch(function () {
							console.warn('[FAZ Scanner] Server scan failed, using iframe results only');
							doImport();
						});
						return;
					}
					doImport();

					function doImport() {
						emit.progress(100);
						emit.status(__('cookies.savingResults', 'Saving results...'));

						var importStart = Date.now();
						var metricsToSend = {
							discoverMs: scanMetrics.discoverMs,
							scanMs: scanMetrics.scanMs,
							urlsDiscovered: scanMetrics.urlsDiscovered,
							cookiesFound: scanMetrics.cookiesFound,
							scriptsFound: scanMetrics.scriptsFound,
							earlyStopReason: scanMetrics.earlyStopReason,
							pagesScanned: scanMetrics.pagesScanned,
							incremental: scanMetrics.incremental,
						};

						FAZ.post('scans/import', {
							cookies: collectedCookies,
							pages_scanned: scanMetrics.pagesScanned,
							scripts: collectedScripts,
							metrics: metricsToSend,
						}).then(function (res) {
							scanMetrics.importMs = Date.now() - importStart;
							// Persist the fingerprint only after a successful import.
							try {
								if (result.fingerprint) { localStorage.setItem('faz_scan_fingerprint', result.fingerprint); }
							} catch (e) {
								console.warn('[FAZ Scanner] Cannot persist fingerprint — next scan will be full.', e.message);
							}
							resolve({
								total: res.total_cookies || collectedCookies.length,
								pagesScanned: scanMetrics.pagesScanned,
								cookies: collectedCookies,
								scripts: collectedScripts,
								diagnostics: diagnostics,
								metrics: scanMetrics,
								importResult: res,
								fingerprint: result.fingerprint || '',
								earlyStopReason: scanMetrics.earlyStopReason,
								incremental: scanMetrics.incremental,
							});
						}).catch(function (err) {
							console.error('[FAZ Scanner] Import failed:', err);
							var e2 = new Error(__('cookies.scanSaveFailed', 'Scan finished but failed to save results.') + buildScanApiErrorDetail(err));
							e2.stage = 'import';
							reject(e2);
						});
					}
				}, emit, scanMetrics, scanOptions);
			}).catch(function (err) {
				console.error('[FAZ Scanner] Discover failed:', err);
				var e1 = new Error(__('cookies.discoverFailed', 'Failed to discover pages.') + buildScanApiErrorDetail(err));
				e1.stage = 'discover';
				reject(e1);
			});
		});
	}
	/**
	 * Scan URLs concurrently with a pool of iframes.
	 *
	 * @param {string[]}  urls        URLs to scan.
	 * @param {Function}  done        Callback(cookies, scripts) when all done.
	 * @param {Object}    emit        { status(text), progress(pct), pages(text) }.
	 * @param {object}    metrics     Metrics object to populate.
	 */
	function scanUrlsConcurrent(urls, done, emit, metrics, options) {
		options = options || {};
		var enableEarlyStop = options.enableEarlyStop !== false;
		var loadTimeoutMs = (typeof options.loadTimeoutMs === 'number' && options.loadTimeoutMs > 0) ? options.loadTimeoutMs : IFRAME_LOAD_TIMEOUT;
		var settleTimeoutMs = (typeof options.settleTimeoutMs === 'number' && options.settleTimeoutMs > 0) ? options.settleTimeoutMs : 1700;
		var priorityUrls = options.priorityUrls || {};  // URL hash map — exempt from early stop counter.
		var collectedCookies = [];
		var collectedScripts = [];
		var diagnostics = {
			invalidUrl: 0,
			crossOrigin: 0,
			originRebased: 0,
			missingContainer: 0,
			iframeInaccessible: 0,
			iframeTimeout: 0,
			settleTimeout: 0,
			totalIssues: 0,
		};
		var cookieSet = {};    // O(1) dedup for cookie names.
		var scriptSet = {};    // O(1) dedup for script URLs.
		var nextIndex = 0;     // Next URL to dispatch.
		var completed = 0;     // URLs finished.
		var active = 0;        // Currently scanning.
		var stopped = false;   // Early stop flag.
		var noNewCount = 0;    // Consecutive pages with no new findings.
		var total = urls.length;
		var totalPageTime = 0; // Running sum for ETA calculation.

		/**
		 * Add an item to an array if not already in the dedup set.
		 * Returns true if the item was new.
		 */
		function addUnique(set, arr, key, item) {
			if (set[key]) return false;
			set[key] = true;
			arr.push(item);
			return true;
		}

		function updateProgress() {
			var pct = Math.round((completed / total) * 100);
			emit.progress(pct);
			emit.pages(completed + '/' + total + ' pages');
			var eta = '';
			if (completed > 0) {
				var etaMs = Math.round(((total - completed) * totalPageTime / completed) / CONCURRENCY);
				if (etaMs > 1000) {
					eta = ' (~' + Math.ceil(etaMs / 1000) + 's left)';
				}
			}
			emit.status(completed + '/' + total + ' pages | ' +
				collectedCookies.length + ' cookies | ' + collectedScripts.length + ' scripts' + eta);
		}

		function dispatch() {
			while (active < CONCURRENCY && nextIndex < total && !stopped) {
				var idx = nextIndex;
				nextIndex++;
				active++;
				scanOne(idx);
			}
			if (active === 0 && (nextIndex >= total || stopped)) {
				metrics.pagesScanned = completed;
				// Clean up any orphaned iframes.
				try { document.getElementById('faz-scan-frame').textContent = ''; } catch (e) {}
				done(collectedCookies, collectedScripts, diagnostics);
			}
		}

		function scanOne(idx) {
			var cookiesBefore = parseBrowserCookies();
			var pageStart = Date.now();

			scanSingleUrl(urls[idx], function (pageResult) {
				active--;
				completed++;
				var elapsed = Date.now() - pageStart;
				metrics.pageTimes.push(elapsed);
				totalPageTime += elapsed;

				var foundNew = false;
				var issue = pageResult.issue || '';
				if (pageResult.originRebased) {
					diagnostics.originRebased++;
				}
				if (issue && Object.prototype.hasOwnProperty.call(diagnostics, issue)) {
					diagnostics[issue]++;
					diagnostics.totalIssues++;
				}

				// Add page-detected cookies.
				var pageCookies = pageResult.cookies || [];
				for (var i = 0; i < pageCookies.length; i++) {
					if (addUnique(cookieSet, collectedCookies, pageCookies[i].name, pageCookies[i])) {
						foundNew = true;
					}
				}

				// Diff cookies: find new ones set during this page load.
				var newCookies = diffCookies(cookiesBefore, parseBrowserCookies());
				for (var j = 0; j < newCookies.length; j++) {
					if (addUnique(cookieSet, collectedCookies, newCookies[j].name, newCookies[j])) {
						foundNew = true;
					}
				}

				// Collect scripts.
				var pageScripts = pageResult.scripts || [];
				for (var k = 0; k < pageScripts.length; k++) {
					if (addUnique(scriptSet, collectedScripts, pageScripts[k], pageScripts[k])) {
						foundNew = true;
					}
				}

				// Early stop check — priority URLs (e.g. WooCommerce pages) are exempt.
				var isPriority = !!priorityUrls[urls[idx]];
				if (isPriority) {
					// Priority URL: reset counter if it found something, but never increment.
					if (foundNew) noNewCount = 0;
				} else {
					noNewCount = foundNew ? 0 : noNewCount + 1;
				}
				if (enableEarlyStop && noNewCount >= EARLY_STOP_THRESHOLD && completed >= EARLY_STOP_THRESHOLD) {
					stopped = true;
					metrics.earlyStopReason = noNewCount + ' consecutive pages with no new findings';
				}

				updateProgress();
				dispatch();
			}, {
				loadTimeoutMs: loadTimeoutMs,
				settleTimeoutMs: settleTimeoutMs,
			});
		}

		dispatch();
	}

	/**
	 * Load a single URL in a hidden iframe with adaptive timeout.
	 *
	 * @param {string}   url  The URL to scan.
	 * @param {Function} done Callback({cookies, scripts}).
	 */
	function scanSingleUrl(url, done, options) {
		options = options || {};
		var loadTimeoutMs = (typeof options.loadTimeoutMs === 'number' && options.loadTimeoutMs > 0) ? options.loadTimeoutMs : IFRAME_LOAD_TIMEOUT;
		var settleTimeoutMs = (typeof options.settleTimeoutMs === 'number' && options.settleTimeoutMs > 0) ? options.settleTimeoutMs : 1700;

		function emptyResult(issue, originRebased) {
			return { cookies: [], scripts: [], issue: issue || '', originRebased: !!originRebased };
		}
		var hadAccessError = false;

		// Validate URL: only HTTP(S) pages can be observed or safely rebased.
		var parsedUrl;
		try {
			parsedUrl = new URL(url, window.location.origin);
		} catch (_unused) {
			done(emptyResult('invalidUrl'));
			return;
		}

		var container = document.getElementById('faz-scan-frame');
		var currentUrl;
		try {
			currentUrl = new URL(window.location.href);
		} catch (_unused2) {
			currentUrl = window.location;
		}

		var isHttpProtocol = (parsedUrl.protocol === 'http:' || parsedUrl.protocol === 'https:');
		if (!isHttpProtocol) {
			done(emptyResult('crossOrigin'));
			return;
		}

		// WordPress can legitimately expose wp-admin and the public home URL on
		// different hostnames (www/apex, reverse proxy, mapped admin domain). The
		// browser cannot read a cross-origin iframe, but the admin origin often
		// serves the same front-controller paths. Retry the discovered path through
		// the exact admin origin; canonical redirects remain safely detectable as
		// iframeInaccessible, while compatible aliases become fully observable.
		var originRebased = parsedUrl.origin !== currentUrl.origin;
		var observableUrl = parsedUrl;
		if (originRebased) {
			try {
				observableUrl = new URL(parsedUrl.pathname + parsedUrl.search + parsedUrl.hash, currentUrl.origin);
			} catch (_unused3) {
				done(emptyResult('crossOrigin'));
				return;
			}
		}
		if (!container) {
			done(emptyResult('missingContainer', originRebased));
			return;
		}

		var iframe = document.createElement('iframe');
		iframe.style.cssText = 'width:1px;height:1px;border:none;position:absolute;left:-9999px;';
		iframe.sandbox = 'allow-same-origin allow-scripts';
		iframe.src = 'about:blank';
		container.appendChild(iframe);

		var finished = false;
		var timer = null;
		var lastRead = null;

		function readIframe() {
			var result = { cookies: [], scripts: [], issue: '', originRebased: originRebased };
			try {
				var doc = iframe.contentDocument || iframe.contentWindow.document;

				var iframeCookieStr = '';
				try { iframeCookieStr = doc.cookie || ''; } catch (e) { hadAccessError = true; }
				if (iframeCookieStr) {
					result.cookies = parseCookieString(iframeCookieStr, parsedUrl.hostname);
				}

				try {
					// Collect script URLs from src, data-src, data-litespeed-src
					// (covers LiteSpeed/WP Rocket/Autoptimize delay loaders).
					var scriptEls = doc.querySelectorAll('script[src], script[data-src], script[data-litespeed-src]');
					scriptEls.forEach(function (s) {
						var src = s.getAttribute('src') || s.getAttribute('data-src') || s.getAttribute('data-litespeed-src') || '';
						if (src) {
							try { src = new URL(src, parsedUrl.href).href; } catch (_u) {}
							result.scripts.push(src);
						}
					});
				} catch (e) { hadAccessError = true; }

				try {
					var iframeEls = doc.querySelectorAll('iframe[src], iframe[data-src]');
					iframeEls.forEach(function (f) {
						var src = f.getAttribute('src') || f.getAttribute('data-src') || '';
						if (src) {
							try { src = new URL(src, parsedUrl.href).href; } catch (_u) {}
							result.scripts.push(src);
						}
					});
				} catch (e) { hadAccessError = true; }
			} catch (e) { hadAccessError = true; }
			if (hadAccessError && !result.cookies.length && !result.scripts.length) {
				result.issue = 'iframeInaccessible';
			}
			return result;
		}

		function finish(result) {
			if (finished) return;
			finished = true;
			if (timer) clearTimeout(timer);
			try { container.removeChild(iframe); } catch (e) {}
			var finalResult = result || readIframe();
			done(finalResult);
		}

		// Adaptive settle: read immediately, wait 700ms, recheck.
		// If stable, finish early. Otherwise wait 800ms more.
		iframe.addEventListener('load', function () {
			// Cancel the pre-load fallback timer — page loaded, settle phase starts.
			if (timer) { clearTimeout(timer); timer = null; }
			// Settle watchdog for slow pages/scripts.
			timer = setTimeout(function () {
				// Use last successful read instead of discarding results.
				if (lastRead) {
					lastRead.issue = lastRead.issue || 'settleTimeout';
					finish(lastRead);
				} else {
					finish(emptyResult('settleTimeout'));
				}
			}, settleTimeoutMs);

			var firstRead = readIframe();
			lastRead = firstRead;
			var firstCount = firstRead.cookies.length + firstRead.scripts.length;

			setTimeout(function () {
				if (finished) return;
				var secondRead = readIframe();
				lastRead = secondRead;
				var secondCount = secondRead.cookies.length + secondRead.scripts.length;

				if (secondCount === firstCount) {
					// Stable — finish early.
					finish(secondRead);
				} else {
					// Still changing — wait a bit more.
					setTimeout(function () {
						if (finished) return;
						lastRead = readIframe();
						finish(lastRead);
					}, 800);
				}
			}, 700);
		});

		// Timeout fallback in case load never fires (e.g. network error, 404).
		timer = setTimeout(function () { finish(emptyResult('iframeTimeout', originRebased)); }, loadTimeoutMs);

		// Navigate the iframe — append scan param to disable script blocking.
		var scanUrl = new URL(observableUrl.href);
		scanUrl.searchParams.set('faz_scanning', '1');
		iframe.src = scanUrl.href;
	}

	/**
	 * Parse a document.cookie string into an array of cookie objects.
	 */
	function parseCookieString(cookieStr, domain) {
		var result = [];
		if (!cookieStr) return result;
		var pairs = cookieStr.split(';');
		for (var i = 0; i < pairs.length; i++) {
			var pair = pairs[i].trim();
			if (!pair) continue;
			var eqPos = pair.indexOf('=');
			var name = eqPos > -1 ? pair.substring(0, eqPos).trim() : pair.trim();
			if (!name) continue;
			result.push({
				name: name,
				domain: domain,
				duration: 'session',
				description: '',
				category: 'uncategorized',
				source: 'browser',
			});
		}
		return result;
	}

	/**
	 * Parse the current browser's document.cookie into a name->value map.
	 */
	function parseBrowserCookies() {
		var map = {};
		var str = document.cookie || '';
		if (!str) return map;
		str.split(';').forEach(function (pair) {
			pair = pair.trim();
			var eq = pair.indexOf('=');
			if (eq > 0) {
				map[pair.substring(0, eq).trim()] = pair.substring(eq + 1).trim();
			}
		});
		return map;
	}

	/**
	 * Find cookies in `after` that weren't in `before`.
	 */
	function diffCookies(before, after, domain) {
		var result = [];
		domain = domain || location.hostname;
		for (var name in after) {
			if (!before.hasOwnProperty(name)) {
				result.push({
					name: name,
					domain: domain,
					duration: 'session',
					description: '',
					category: 'uncategorized',
					source: 'browser',
				});
			}
		}
		return result;
	}

	window.FAZ = window.FAZ || {};
	window.FAZ.scanEngine = {
		run: runScan,
		// Exposed so a caller can append the "possible blockers" hint to its own
		// completion message without re-deriving it from the diagnostics object.
		diagnosticsHint: buildScanDiagnosticsHint,
	};
})();
