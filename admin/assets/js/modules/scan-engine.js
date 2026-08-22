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
	var SAFE_SCAN_THRESHOLD = 1000;  // Deep/full scans use longer observation timings.
	var MAX_RESOURCE_URLS = 10000;   // Bound imports without sacrificing normal-site coverage.
	// How often to slide the server-side capture window forward. The session is
	// an IDLE timeout (Controller::BROWSER_SCAN_TTL, 900s) renewed by every
	// scan-tagged page load; this is the fallback for fully page-cached sites,
	// where scanned pages are served off disk and never reach PHP. Comfortably
	// inside the window so one dropped heartbeat is not fatal.
	var HEARTBEAT_INTERVAL_MS = 300000; // 5 minutes.
	// Persisting a completed crawl may fail transiently after PHP has already
	// collected HttpOnly evidence. Reuse the exact same scan id and payload while
	// that server-side session is still alive; starting a fresh crawl would 409
	// against our own active-session lock and strand the administrator for 15m.
	var IMPORT_RETRY_DELAYS_MS = [1000, 3000];
	// How long after the interaction signal a page may still restore delayed
	// scripts. Cache/optimizer plugins commonly do so 1-3s in, which is why a
	// settled pair of reads inside this window is not yet evidence that the page
	// is done. See the settle schedule in scanSingleUrl().
	var DELAYED_SCRIPT_WINDOW_MS = 3000;

	// `result.scripts` means "URLs that can execute or beacon". Both PHP matchers
	// (Cookie_Database::lookup_scripts and infer_cookies_from_scripts) substring-
	// match those URLs against provider patterns, and the resulting declaration
	// becomes the AUTHORITATIVE catalogue entry — shown in the public preference
	// centre, used as the deletion policy for a real cookie of that name, and
	// enough to promote a provider into pre-consent blocking. So a passive
	// subresource must never reach that array: the pattern list contains image
	// CDNs (youtube -> ytimg.com, vimeo -> i.vimeocdn.com, linkedin ->
	// snap.licdn.com) and short tokens that occur in ordinary file paths.
	// Concretely, without this filter i.ytimg.com/vi/<id>/hqdefault.jpg fabricates
	// YSC + VISITOR_INFO1_LIVE + LOGIN_INFO — declaring exactly the cookies a
	// YouTube privacy facade exists to prevent — and any
	// uploads/googleads-guide.png fabricates _gcl_au + IDE + test_cookie.
	//
	// The test is deliberately in two parts. `img` STAYS in the allow-list so
	// extension-less tracking pixels (facebook.com/tr, google-analytics.com/collect,
	// bat.bing.com/action/0) are still imported — that is the coverage this
	// runtime pass exists for — and the extension check is what rejects the
	// thumbnails. Removing either half re-opens the fabrication.
	var RUNTIME_CODE_INITIATORS = {
		'': true,
		script: true,
		xmlhttprequest: true,
		fetch: true,
		beacon: true,
		ping: true,
		iframe: true,
		frame: true,
		embed: true,
		object: true,
		img: true,
		image: true,
		other: true
	};
	// css/link/font/video/audio/track are absent on purpose: they can never execute.
	var NON_CODE_ASSET_PATH = /\.(?:png|jpe?g|gif|webp|avif|bmp|ico|cur|svgz?|tiff?|heic|heif|css|less|sass|scss|woff2?|ttf|otf|eot|mp4|m4v|mov|avi|mkv|webm|ogv|mp3|m4a|wav|flac|aac|oga|ogg|opus|vtt|srt|pdf|zip|gz|tgz|bz2|xz|rar|7z|map)$/;

	/**
	 * Whether two consecutive iframe reads observed the same thing.
	 *
	 * Counts only — the arrays are already deduplicated per read, so a stable
	 * count over cookies, jar names and executable resources means nothing new
	 * appeared in the interval.
	 */
	function readsAreStable(first, second) {
		if (!first || !second) return false;
		return first.cookies.length === second.cookies.length
			&& first.scripts.length === second.scripts.length
			&& first.jarCookies.length === second.jarCookies.length;
	}

	function isRuntimeCodeResource(entry, base) {
		if (!entry || !entry.name) return false;
		var initiator = typeof entry.initiatorType === 'string' ? entry.initiatorType.toLowerCase() : '';
		if (!Object.prototype.hasOwnProperty.call(RUNTIME_CODE_INITIATORS, initiator)) return false;
		var pathname;
		try {
			pathname = new URL(entry.name, base || window.location.origin).pathname.toLowerCase();
		} catch (_unused) {
			return false;
		}
		return !NON_CODE_ASSET_PATH.test(pathname);
	}

	function createScanId() {
		var bytes = new Uint8Array(16);
		if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
			window.crypto.getRandomValues(bytes);
		} else {
			for (var i = 0; i < bytes.length; i++) { bytes[i] = Math.floor(Math.random() * 256); }
		}
		return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	}

	function canonicalizeResourceUrl(url, base) {
		try {
			var parsed = new URL(url, base || window.location.origin);
			if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return '';
			return parsed.origin + parsed.pathname;
		} catch (_unused) {
			return '';
		}
	}

	/**
	 * Normalize a URL: strip hash, preserve query, enforce trailing slash
	 * consistency.
	 *
	 * Mirrors the server rule in
	 * admin/modules/scanner/includes/class-controller.php::normalize_url() —
	 * a path whose last segment looks like a file (contains a dot, and does
	 * not START with one) is left bare, so permalink structures such as
	 * `/%postname%.html` keep the exact identity `discover` returned. A last
	 * segment starting with a dot is deliberately NOT a file, which keeps
	 * `/.well-known/` a directory. Any divergence here would make the same
	 * page get scanned under two capture keys.
	 *
	 * Query params are kept by appending `u.search` to the normalized URL.
	 */
	function normalizeUrl(url) {
		try {
			var u = new URL(url, window.location.origin);
			var path = u.pathname || '/';
			var lastSegment = path.slice(path.lastIndexOf('/') + 1);
			var isFilePath = path.charAt(path.length - 1) !== '/'
				&& lastSegment.indexOf('.') !== -1
				&& lastSegment.charAt(0) !== '.';
			var normalizedPath = isFilePath ? path : path.replace(/\/?$/, '/');
			return u.origin + normalizedPath + u.search;
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

	/**
	 * Whether the server said it kept this scan's evidence for a retry.
	 *
	 * Reads the same two places as getApiErrorStatus(): wp.apiFetch rejects with
	 * the decoded WP_Error body, so a value the server put in the WP_Error `data`
	 * array arrives as `err.data.faz_session_held`, and a flattened error object
	 * would carry it at the top level. Anything else — a network failure with no
	 * body, an older server that does not hold at all — is NOT a hold: the
	 * default is false on purpose, because offering a retry that then 409s is
	 * worse for the administrator than never offering one.
	 */
	function getApiErrorSessionHeld(err) {
		if (!err) return false;
		if (typeof err.faz_session_held === 'boolean') return err.faz_session_held;
		if (err.data && typeof err.data.faz_session_held === 'boolean') return err.data.faz_session_held;
		return false;
	}

	function buildScanApiErrorDetail(err) {
		var status = getApiErrorStatus(err);
		var parts = [];
		if (status === 401) {
			parts.push('Session expired. Refresh the page and try again.');
		} else if (status === 403) {
			parts.push('Nonce/permissions error. Refresh the page and verify admin access.');
		} else if (status === 409) {
			// Two very different 409s. The server names which one it is; only
			// guess when it did not, so an expired capture window is never
			// reported as "another tab is scanning".
			if (err && err.code === 'faz_browser_scan_session_expired') {
				parts.push('The scan capture session expired before the results could be saved.');
			} else if (!err || !err.code) {
				parts.push('Another scan is already in progress.');
			}
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
	 * The returned promise carries a `.cancel()` handle. Cancellation is
	 * cooperative: it stops the dispatcher from starting further pages, lets the
	 * in-flight iframes settle, and then imports what was collected with
	 * `metrics.stoppedReason = 'cancelled'` so every downstream coverage gate
	 * can see that the run is incomplete. It is NOT the early-stop heuristic
	 * this engine deliberately dropped — nothing here shortens a depth the
	 * administrator chose; only an explicit human action does.
	 *
	 * @param {Object} options       { maxPages, scanId } — maxPages 0 requests the
	 *                               server cap (full scan). `scanId` reuses an
	 *                               existing capture session instead of minting a
	 *                               new one, which is what lets a caller re-enter
	 *                               after a held import failure without 409-ing
	 *                               against the session that still holds the
	 *                               evidence.
	 * @param {Object} hooks         { status(text), progress(pct), pages(text) } — all optional.
	 * @return {Promise<Object>}     Resolves { total, pagesScanned, cookies, scripts,
	 *                               diagnostics, metrics, importResult, fingerprint,
	 *                               earlyStopReason, stoppedReason, scanId }; rejects
	 *                               with an Error carrying a display-ready .message,
	 *                               plus `.stage`, `.scanId`, `.sessionHeld` and —
	 *                               when the evidence is held — a `.retryImport()`
	 *                               that resubmits the SAME payload. Carries a
	 *                               `.cancel()` method and a `.scanId` property.
	 */
	function runScan(options, hooks) {
		options = options || {};
		hooks = hooks || {};
		var emit = {
			status: function (t) { if (typeof hooks.status === 'function') { hooks.status(t); } },
			progress: function (p) { if (typeof hooks.progress === 'function') { hooks.progress(p); } },
			pages: function (t) { if (typeof hooks.pages === 'function') { hooks.pages(t); } },
		};

		// Shared between runScan and the crawl loop so a cancel that lands during
		// discovery is still honoured once dispatching begins.
		var control = { cancelled: false, settled: false };
		// A caller-supplied id re-enters the capture session the server is already
		// holding for this scan. Minting a fresh one there would be read as a
		// different scan, and the held evidence would be discarded to make room.
		var scanId = (typeof options.scanId === 'string' && options.scanId) ? options.scanId : createScanId();

		var promise = new Promise(function (resolve, reject) {
			var heartbeatTimer = null;
			var unloadHandler = null;

			// Renew the server-side capture window while the crawl runs. Without
			// this the window is a fixed wall clock opened once at discovery, and
			// a crawl that outlives it loses 100% of its work to a 409 at import.
			function startHeartbeat() {
				if (heartbeatTimer) { return; }
				heartbeatTimer = setInterval(function () {
					FAZ.post('scans/heartbeat', { scan_id: scanId }).catch(function () {});
				}, HEARTBEAT_INTERVAL_MS);
			}

			function stopHeartbeat() {
				if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
			}

			// Closing the tab used to strand the administrator: no abort fired, the
			// active-scan transient survived, and every new scan 409'd until the
			// window expired. sendBeacon survives the unload that a fetch would not.
			function registerUnloadAbort() {
				unloadHandler = function () {
					if (control.settled) { return; }
					try {
						var cfg = (window.fazConfig && window.fazConfig.api) || {};
						if (!cfg.base || typeof navigator.sendBeacon !== 'function') { return; }
						var url = cfg.base + 'scans/abort?_wpnonce=' + encodeURIComponent(cfg.nonce || '');
						navigator.sendBeacon(url, new Blob([JSON.stringify({ scan_id: scanId })], { type: 'application/json' }));
					} catch (_beaconError) {}
				};
				window.addEventListener('pagehide', unloadHandler);
			}

			function releaseRunHooks() {
				control.settled = true;
				stopHeartbeat();
				if (unloadHandler) {
					try { window.removeEventListener('pagehide', unloadHandler); } catch (_removeError) {}
					unloadHandler = null;
				}
			}

			function rejectAndAbort(error) {
				// Best effort: avoid leaving this administrator locked out for the
				// capture TTL when an iframe or network phase fails.
				releaseRunHooks();
				// Wait for teardown before exposing the rejected run to the caller.
				// Otherwise an immediate click on Scan can race the abort and receive
				// a 409 from the session this run is still closing.
				FAZ.post('scans/abort', { scan_id: scanId })
					.catch(function () {})
					.then(function () { reject(error); });
			}
			/**
			 * Fail WITHOUT destroying the capture session.
			 *
			 * The sibling of rejectAndAbort, for the one stage where an abort is
			 * the expensive answer: the import. By then the crawl is finished and
			 * the observations are the only record of what this site set before
			 * consent — POSTing `scans/abort` deletes every one of them, so a
			 * transient persistence failure used to cost the administrator a run
			 * that can take many minutes. The server holds that evidence instead
			 * (see Controller::hold_browser_scan_session), and a held session does
			 * not lock the next scan out: starting one reclaims it.
			 *
			 * releaseRunHooks() still runs, so the heartbeat stops and the held
			 * session idles out on schedule rather than lingering for as long as
			 * this tab stays open.
			 */
			function rejectAndHold(error) {
				releaseRunHooks();
				reject(error);
			}
			function resolveRun(value) {
				releaseRunHooks();
				resolve(value);
			}
			startHeartbeat();
			registerUnloadAbort();
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
				// Scan exactly the depth selected by the administrator. The engine no
				// longer owns an implicit early-stop path.
				loadTimeoutMs: IFRAME_LOAD_TIMEOUT,
				settleTimeoutMs: safeMode ? 5200 : 3800,
				scanId: scanId,
				control: control,
			};

			var scanMetrics = {
				discoverMs: 0, scanMs: 0, importMs: 0,
				pageTimes: [], scannedUrls: [], urlsDiscovered: 0,
				cookiesFound: 0, scriptsFound: 0,
				earlyStopReason: null, pagesScanned: 0,
				incremental: false,
				// Set only by an explicit cancel(). Travels to the server so the
				// consecutive-miss tally never counts a run that stopped short,
				// and to the caller so its coverage gate can disable the
				// destructive stale action.
				stoppedReason: null,
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
				scan_id: scanId,
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

				// WooCommerce/recent-content priority URLs are scanned first.
				var rawPriority = deduplicateUrls(result.priority_urls || []);
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

				if (!urls.length) {
					rejectAndAbort(new Error(__('cookies.noPagesFound', 'No pages found to scan.')));
					return;
				}

				emit.status(__('cookies.scanningPages', 'Scanning 0/%d pages...').replace('%d', urls.length));
				emit.pages('0/' + urls.length + ' pages');
				emit.progress(0);

				var scanStart = Date.now();

				scanUrlsConcurrent(urls, function (collectedCookies, collectedScripts, diagnostics, jarOnlyCookies, cookieSet) {
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
						rejectAndAbort(browserError);
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
									var canonical = canonicalizeResourceUrl(s, homepageUrl);
									if (canonical && !existingScripts[canonical] && collectedScripts.length < MAX_RESOURCE_URLS) {
										collectedScripts.push(canonical);
										existingScripts[canonical] = true;
									} else if (canonical && !existingScripts[canonical] && diagnostics.resourceLimit === 0) {
										diagnostics.resourceLimit++;
										diagnostics.totalIssues++;
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
							diagnostics.serverScanFailed++;
							diagnostics.totalIssues++;
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
							stoppedReason: scanMetrics.stoppedReason,
							pagesScanned: scanMetrics.pagesScanned,
							incremental: scanMetrics.incremental,
							// The DEPTH the administrator asked for. Without these two
							// the server is structurally unable to tell a 20-page
							// sample from a full crawl — the fact never crossed the
							// wire — so every capped run reported itself as full-site
							// evidence and tallied a miss against every cookie it
							// never reached.
							//
							// `maxPages` is the raw choice (0 = whole site), the same
							// value the local coverage gate in pages/cookies.js tests;
							// `isFullScan` is its derived twin. Both travel so the
							// server can refuse a payload in which they disagree.
							maxPages: isFullScan ? 0 : requestPages,
							isFullScan: isFullScan,
							// The count of degraded-capture issues this run hit
							// (iframe timeouts, cross-origin refusals, a failed
							// server-scan, the resource cap). The LOCAL coverage
							// gate in pages/cookies.js has always required this to
							// be zero; the server's twin could not, because the
							// number never crossed the wire. A run with timed-out
							// iframes therefore read as full-site evidence server-
							// side, and every catalogue row it failed to observe
							// took a miss toward deletion.
							diagnosticsIssues: (diagnostics && typeof diagnostics.totalIssues === 'number')
								? diagnostics.totalIssues
								: null,
						};

						// Reconcile: a name held in the jar bucket that some page
						// later actually set is a genuine discovery, so it is only
						// reported as jar-only if no page ever wrote it.
						var jarOnlyRemaining = [];
						var jarList = jarOnlyCookies || [];
						var observedNames = cookieSet || {};
						for (var jr = 0; jr < jarList.length; jr++) {
							if (!Object.prototype.hasOwnProperty.call(observedNames, jarList[jr].name)) {
								jarOnlyRemaining.push(jarList[jr]);
							}
						}

						var importPayload = {
							scan_id: scanId,
							cookies: collectedCookies,
							jar_cookies: jarOnlyRemaining,
							pages_scanned: scanMetrics.pagesScanned,
							scanned_urls: scanMetrics.scannedUrls,
							scripts: collectedScripts,
							metrics: metricsToSend,
						};

						function importIsRetryable(err) {
							var status = getApiErrorStatus(err);
							return status === 0 || status === 408 || status === 429 || status >= 500;
						}

						function importWithRetry(attempt) {
							return FAZ.post('scans/import', importPayload).catch(function (err) {
								if (attempt >= IMPORT_RETRY_DELAYS_MS.length || !importIsRetryable(err)) {
									throw err;
								}
								var delay = IMPORT_RETRY_DELAYS_MS[attempt];
								emit.status(__('cookies.serverBusyRetrying', 'Server busy, retrying in %ds...').replace('%d', delay / 1000));
								console.warn('[FAZ Scanner] Import attempt ' + (attempt + 1) + ' failed, retrying the same scan...', err.message);
								return new Promise(function (retry) { setTimeout(retry, delay); })
									.then(function () { return importWithRetry(attempt + 1); });
							});
						}

						// Everything a successful import produces, whether it landed on
						// the first attempt or on a later resubmit of the same payload.
						function completeImport(res) {
							scanMetrics.importMs = Date.now() - importStart;
							if (res && res.capture_truncated) {
								diagnostics.captureTruncated++;
								diagnostics.totalIssues++;
							}
							// Persist the fingerprint only after a successful import.
							try {
								if (result.fingerprint) { localStorage.setItem('faz_scan_fingerprint', result.fingerprint); }
							} catch (e) {
								console.warn('[FAZ Scanner] Cannot persist fingerprint — next scan will be full.', e.message);
							}
							return {
								total: res.total_cookies || collectedCookies.length,
								pagesScanned: scanMetrics.pagesScanned,
								cookies: collectedCookies,
								jarCookies: jarOnlyRemaining,
								scripts: collectedScripts,
								diagnostics: diagnostics,
								metrics: scanMetrics,
								importResult: res,
								fingerprint: result.fingerprint || '',
								earlyStopReason: scanMetrics.earlyStopReason,
								stoppedReason: scanMetrics.stoppedReason,
								incremental: scanMetrics.incremental,
								scanId: scanId,
							};
						}

						// One shape for every import failure, so the first attempt and
						// a later manual retry are indistinguishable to the caller.
						function buildImportError(err) {
							var failure = new Error(__('cookies.scanSaveFailed', 'Scan finished but failed to save results.') + buildScanApiErrorDetail(err));
							failure.stage = 'import';
							failure.scanId = scanId;
							// Only what the SERVER said. See getApiErrorSessionHeld().
							failure.sessionHeld = getApiErrorSessionHeld(err);
							// A retry handle exists only while the evidence does. It
							// resubmits the payload this crawl already produced, so the
							// site is not walked a second time; the server answers a
							// resubmit that in fact succeeded with the response it
							// already returned, marked `duplicate`.
							failure.retryImport = failure.sessionHeld ? retryImport : null;
							return failure;
						}

						function retryImport() {
							importStart = Date.now();
							return importWithRetry(0).then(completeImport, function (err) {
								console.error('[FAZ Scanner] Import retry failed:', err);
								throw buildImportError(err);
							});
						}

						importWithRetry(0).then(function (res) {
							resolveRun(completeImport(res));
						}, function (err) {
							console.error('[FAZ Scanner] Import failed:', err);
							rejectAndHold(buildImportError(err));
						});
					}
				}, emit, scanMetrics, scanOptions);
			}).catch(function (err) {
				console.error('[FAZ Scanner] Discover failed:', err);
				var e1 = new Error(__('cookies.discoverFailed', 'Failed to discover pages.') + buildScanApiErrorDetail(err));
				e1.stage = 'discover';
				// The server's error code already travels in the MESSAGE above,
				// which is for humans. Carry it as data too, so a caller can
				// react to a specific refusal — the Cookies page uses
				// faz_browser_scan_in_progress to surface the session that
				// caused it, with its stop affordance — without parsing text.
				if (err && err.code) { e1.code = err.code; }
				rejectAndAbort(e1);
			});
		});

		/**
		 * Stop dispatching new pages and finish with whatever was collected.
		 *
		 * Cooperative on purpose: the iframes already in flight are allowed to
		 * settle, so a cancel never throws away a page that was nearly done, and
		 * the partial set is still imported (a partial import keeps the jar
		 * reconciliation intact). Idempotent, and a no-op once the run settled.
		 */
		// The id this run is capturing under, readable before it settles. A caller
		// that wants to re-enter the same session (a held import, a resumed page)
		// can pass it straight back as options.scanId.
		promise.scanId = scanId;

		promise.cancel = function () {
			if (control.settled || control.cancelled) { return false; }
			control.cancelled = true;
			if (typeof control.onCancel === 'function') { control.onCancel(); }
			return true;
		};
		return promise;
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
		var loadTimeoutMs = (typeof options.loadTimeoutMs === 'number' && options.loadTimeoutMs > 0) ? options.loadTimeoutMs : IFRAME_LOAD_TIMEOUT;
		var settleTimeoutMs = (typeof options.settleTimeoutMs === 'number' && options.settleTimeoutMs > 0) ? options.settleTimeoutMs : 3800;
		var scanId = typeof options.scanId === 'string' ? options.scanId : '';
		// Shared cancellation flag owned by runScan(). Absent when the crawl is
		// driven directly, in which case it can never be cancelled.
		var control = options.control || { cancelled: false };
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
			serverScanFailed: 0,
			resourceLimit: 0,
			captureTruncated: 0,
			totalIssues: 0,
		};
		var cookieSet = {};    // O(1) dedup for cookie names.
		var scriptSet = {};    // O(1) dedup for script URLs.
		// Names seen only as already-present in the scanning browser's jar. The
		// scan runs in the administrator's browser, so this bucket is where
		// wp-admin-only cookies land instead of the public declaration.
		var jarOnlyCookies = [];
		var jarSet = {};
		var nextIndex = 0;     // Next URL to dispatch.
		var completed = 0;     // URLs finished.
		var active = 0;        // Currently scanning.
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
			// No cookie count here, deliberately. The only number available to
			// the client mid-crawl is collectedCookies.length — cookies NEWLY set
			// where document.cookie can see them. Three whole classes of findings
			// are structurally invisible to it until import: HttpOnly cookies PHP
			// captured server-side (Controller::collect_browser_scan_session),
			// cookies inferred from the collected script URLs at save time, and
			// names already sitting in the administrator's jar (held apart in
			// jarOnlyCookies). On a well-behaved site with pre-consent blocking
			// that live number is 0 for the entire crawl while the finished
			// import is not — which reads as "the scanner found nothing" and was
			// reported as exactly that from production. The count is therefore
			// stated once, at completion, where it is true.
			emit.status(completed + '/' + total + ' pages | ' + collectedScripts.length + ' scripts' + eta);
		}

		var crawlFinished = false;

		function dispatch() {
			// A cancel stops the dispatcher only. Pages already loading are left
			// to settle: their cookies are real observations, and dropping them
			// would make the partial import poorer than it needs to be.
			while (!control.cancelled && active < CONCURRENCY && nextIndex < total) {
				var idx = nextIndex;
				nextIndex++;
				active++;
				scanOne(idx);
			}
			if (crawlFinished || active !== 0) {
				return;
			}
			if (!control.cancelled && nextIndex < total) {
				return;
			}
			crawlFinished = true;
			metrics.pagesScanned = completed;
			if (control.cancelled) {
				metrics.stoppedReason = 'cancelled';
			}
			// Clean up any orphaned iframes.
			try { document.getElementById('faz-scan-frame').textContent = ''; } catch (e) {}
			done(collectedCookies, collectedScripts, diagnostics, jarOnlyCookies, cookieSet);
		}

		// A cancel that lands while every worker is between pages (or before the
		// first dispatch) has nothing in flight to re-enter dispatch() for, so the
		// crawl would hang until the run was abandoned. Re-enter it explicitly.
		control.onCancel = function () { dispatch(); };

		function scanOne(idx) {
			var cookiesBefore = parseBrowserCookies();
			var pageStart = Date.now();

			scanSingleUrl(urls[idx], function (pageResult) {
				active--;
				completed++;
				metrics.scannedUrls.push(urls[idx]);
				var elapsed = Date.now() - pageStart;
				metrics.pageTimes.push(elapsed);
				totalPageTime += elapsed;

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
					addUnique(cookieSet, collectedCookies, pageCookies[i].name, pageCookies[i]);
				}

				// Cookies that were already in the jar when this page started
				// loading. They are held apart, not discarded: one of them may be
				// a genuine site cookie set by an earlier page in this same run
				// (or by the admin simply browsing the site), and dropping it
				// would lose a real declaration entry. A later page that actually
				// sets the name promotes it, and the reconciliation after the
				// crawl removes anything promoted from this list.
				var pageJarCookies = pageResult.jarCookies || [];
				for (var jc = 0; jc < pageJarCookies.length; jc++) {
					addUnique(jarSet, jarOnlyCookies, pageJarCookies[jc].name, pageJarCookies[jc]);
				}

				// Diff cookies: find new ones set during this page load.
				var newCookies = diffCookies(cookiesBefore, parseBrowserCookies());
				for (var j = 0; j < newCookies.length; j++) {
					addUnique(cookieSet, collectedCookies, newCookies[j].name, newCookies[j]);
				}

				// Collect scripts.
				var pageScripts = pageResult.scripts || [];
				for (var k = 0; k < pageScripts.length; k++) {
					var canonical = canonicalizeResourceUrl(pageScripts[k], urls[idx]);
					if (!canonical || scriptSet[canonical]) continue;
					if (collectedScripts.length >= MAX_RESOURCE_URLS) {
						if (diagnostics.resourceLimit === 0) {
							diagnostics.resourceLimit++;
							diagnostics.totalIssues++;
						}
						break;
					}
					addUnique(scriptSet, collectedScripts, canonical, canonical);
				}

				updateProgress();
				dispatch();
			}, {
				loadTimeoutMs: loadTimeoutMs,
				settleTimeoutMs: settleTimeoutMs,
				scanId: scanId,
				jarBaseline: cookiesBefore,
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
		var settleTimeoutMs = (typeof options.settleTimeoutMs === 'number' && options.settleTimeoutMs > 0) ? options.settleTimeoutMs : 3800;
		var scanId = typeof options.scanId === 'string' ? options.scanId : '';
		// The cookie jar as it stood immediately before this page loaded. Names
		// already in it cannot be attributed to this page — see readIframe().
		var jarBaseline = options.jarBaseline || null;

		function emptyResult(issue, originRebased) {
			return { cookies: [], jarCookies: [], scripts: [], issue: issue || '', originRebased: !!originRebased };
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
		iframe.style.cssText = 'width:1365px;height:900px;border:none;position:absolute;inset:0;';
		iframe.sandbox = 'allow-same-origin allow-scripts';
		iframe.src = 'about:blank';
		container.appendChild(iframe);

		var finished = false;
		var timer = null;
		var lastRead = null;

		function readIframe() {
			var result = { cookies: [], jarCookies: [], scripts: [], issue: '', originRebased: originRebased };
			try {
				var doc = iframe.contentDocument || iframe.contentWindow.document;

				var iframeCookieStr = '';
				try { iframeCookieStr = doc.cookie || ''; } catch (e) { hadAccessError = true; }
				if (iframeCookieStr) {
					// A same-origin iframe shares the top-level document's cookie jar,
					// so doc.cookie is not "what this page set" — it is EVERY cookie
					// the scanning browser holds for this domain. The scan runs in the
					// administrator's browser, so wp-admin-only cookies (Automattic
					// Tracks tk_ai/tk_qs, anything left by a plugin the admin uses)
					// were being imported into the PUBLIC cookie declaration as
					// first-class discoveries, for services a visitor never touches.
					//
					// Splitting against the jar as it stood immediately before this
					// page loaded separates the two: a name that was already there
					// cannot be attributed to this page.
					var all = parseCookieString(iframeCookieStr, parsedUrl.hostname);
					for (var ci = 0; ci < all.length; ci++) {
						if (jarBaseline && Object.prototype.hasOwnProperty.call(jarBaseline, all[ci].name)) {
							result.jarCookies.push(all[ci]);
						} else {
							result.cookies.push(all[ci]);
						}
					}
				}

				try {
					// Collect both live and optimizer-deferred sources. Several cache
					// plugins move src into a private data attribute until interaction.
					var scriptEls = doc.querySelectorAll('script[src], script[data-src], script[data-litespeed-src], script[data-rocket-src], script[data-wpr-src]');
					scriptEls.forEach(function (s) {
						var src = s.getAttribute('src') || s.getAttribute('data-src') || s.getAttribute('data-litespeed-src') || s.getAttribute('data-rocket-src') || s.getAttribute('data-wpr-src') || '';
						if (src) {
							try { src = new URL(src, parsedUrl.href).href; } catch (_u) {}
							result.scripts.push(src);
						}
					});
				} catch (e) { hadAccessError = true; }

				try {
					var iframeEls = doc.querySelectorAll('iframe[src], iframe[data-src], iframe[data-lazy-src]');
					iframeEls.forEach(function (f) {
						var src = f.getAttribute('src') || f.getAttribute('data-src') || f.getAttribute('data-lazy-src') || '';
						if (src) {
							try { src = new URL(src, parsedUrl.href).href; } catch (_u) {}
							result.scripts.push(src);
						}
					});
				} catch (e) { hadAccessError = true; }

				try {
					// Runtime entries cover dynamically injected scripts, pixels,
					// beacons and AJAX resources that no longer exist in the DOM.
					// Only the entries that can actually execute or beacon are
					// imported — see isRuntimeCodeResource() for why a bare
					// "push every subresource" fabricates cookie declarations.
					var resources = iframe.contentWindow.performance.getEntriesByType('resource') || [];
					resources.forEach(function (entry) {
						if (isRuntimeCodeResource(entry, parsedUrl.href)) { result.scripts.push(entry.name); }
					});
				} catch (_performanceError) {}
			} catch (e) { hadAccessError = true; }
			var canonicalScripts = [];
			var canonicalSeen = {};
			for (var resourceIndex = 0; resourceIndex < result.scripts.length; resourceIndex++) {
				var canonical = canonicalizeResourceUrl(result.scripts[resourceIndex], parsedUrl.href);
				if (!canonical || canonicalSeen[canonical]) continue;
				canonicalSeen[canonical] = true;
				if (canonicalScripts.length >= MAX_RESOURCE_URLS) {
					result.issue = 'resourceLimit';
					break;
				}
				canonicalScripts.push(canonical);
			}
			result.scripts = canonicalScripts;
			if (hadAccessError && !result.cookies.length && !result.scripts.length) {
				result.issue = 'iframeInaccessible';
			}
			return result;
		}

		function triggerDelayedScripts() {
			// Optimizers commonly wait for the first pointer, touch, key or scroll
			// event before restoring delayed script tags. Synthetic events are safe
			// here because the iframe is hidden and scanning is an explicit admin
			// action; deliberately avoid click/submit events that could mutate data.
			try {
				var doc = iframe.contentDocument || iframe.contentWindow.document;
				var targets = [iframe.contentWindow, doc, doc.documentElement, doc.body];
				var eventNames = ['pointermove', 'mousemove', 'touchstart', 'keydown', 'wheel', 'scroll'];
				eventNames.forEach(function (eventName) {
					targets.forEach(function (target) {
						if (!target || typeof target.dispatchEvent !== 'function') return;
						try {
							target.dispatchEvent(new iframe.contentWindow.Event(eventName, { bubbles: true, cancelable: true }));
						} catch (_dispatchError) {}
					});
				});
				try {
					var root = doc.scrollingElement || doc.documentElement;
					var bottom = root ? Math.max(1, root.scrollHeight - iframe.contentWindow.innerHeight) : 1;
					iframe.contentWindow.scrollTo(0, bottom);
				} catch (_scrollError) {}
			} catch (_interactionError) {}
		}

		function finish(result) {
			if (finished) return;
			finished = true;
			if (timer) clearTimeout(timer);
			try { container.removeChild(iframe); } catch (e) {}
			var finalResult = result || readIframe();
			done(finalResult);
		}

		// Observe multiple checkpoints after interaction. A stable count at 700ms
		// is not enough: delayed loaders commonly restore scripts after 1–3s.
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
			// Stays BEFORE the first checkpoint: the delayed-loader activation is
			// the coverage win this settle budget was widened for, and a fast path
			// that ran before it would simply observe the un-activated page.
			triggerDelayedScripts();

			var firstWait = settleTimeoutMs >= 5000 ? 2000 : 1500;
			var finalWait = settleTimeoutMs >= 5000 ? 2500 : 1800;
			// Where a page that produced nothing new is finalised. Not at the
			// first checkpoint: optimizers restore delayed scripts 1-3s after the
			// interaction signal, so a stable pair taken inside that window proves
			// only that the restore has not happened YET — finishing there loses
			// exactly the coverage this settle budget was widened to buy. Past the
			// window a stable pair is real evidence, and paying the rest of the
			// budget buys nothing but wall clock: a full scan's floor drops from
			// 4.5s to 3s per page, which is the third of the crawl this reclaims.
			var stableFinishAt = Math.min(firstWait + finalWait, DELAYED_SCRIPT_WINDOW_MS);
			setTimeout(function () {
				if (finished) return;
				var secondRead = readIframe();
				lastRead = secondRead;
				var remainingWait = readsAreStable(firstRead, secondRead)
					? Math.max(0, stableFinishAt - firstWait)
					: finalWait;
				if (0 === remainingWait) {
					finish(secondRead);
					return;
				}
				setTimeout(function () {
					if (finished) return;
					lastRead = readIframe();
					finish(lastRead);
				}, remainingWait);
			}, firstWait);
		});

		// Timeout fallback in case load never fires (e.g. network error, 404).
		timer = setTimeout(function () { finish(emptyResult('iframeTimeout', originRebased)); }, loadTimeoutMs);

		// Navigate the iframe — append scan param to disable script blocking.
		var scanUrl = new URL(observableUrl.href);
		scanUrl.searchParams.set('faz_scanning', '1');
		if (scanId) { scanUrl.searchParams.set('faz_scan_id', scanId); }
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
