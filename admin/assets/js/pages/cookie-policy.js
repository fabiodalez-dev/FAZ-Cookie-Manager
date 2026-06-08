/**
 * Admin JS — Cookie Policy generator (Spec 002 FR-02 + US-05).
 *
 * DOM-safe construction (createElement + textContent + appendChild) for
 * all dynamic content. No innerHTML for user input or API responses.
 * Preview HTML is server-rendered through wp_kses_post() at the Renderer
 * boundary — we still treat it as trusted-but-sanitized and write via a
 * sandbox iframe so any future regression cannot escape the preview modal.
 */
(function () {
	'use strict';

	var root = document.getElementById('faz-cookie-policy-app');
	if (!root) { return; }

	var REST_URL   = root.dataset.fazRestUrl || '';
	var REST_NONCE = root.dataset.fazRestNonce || '';

	// ---------- i18n helper ----------
	// Strings live in fazConfig.i18n.cookiePolicy.* (see admin/class-admin.php).
	// Fallbacks ship the English default so the page degrades gracefully
	// when the locale array is incomplete.
	var FAZ_I18N = (window.fazConfig && window.fazConfig.i18n && window.fazConfig.i18n.cookiePolicy) || {};
	function t(key, fallback) {
		return (FAZ_I18N && FAZ_I18N[key]) || fallback;
	}

	// Per-request monotonic id used to discard stale preview responses
	// when the user clicks Preview multiple times before the previous
	// fetch resolved.
	var previewRequestId = 0;

	// Per-request monotonic id for the auto-detect button. Same pattern
	// as previewRequestId — guards against rapid clicks letting an
	// earlier /suggest-services response paint stale state over a newer
	// one. Mirrors the GVL admin page's autoDetectRequestId (PR #127).
	var autoDetectRequestId = 0;

	// Service IDs the admin manually UNTICKED during this session (since the
	// last hydration / save). Auto-detect consults this so a re-run does not
	// silently re-tick a detected service the admin deliberately removed
	// before saving (F009). A null-prototype map is used as a Set so a
	// service id that collides with an Object.prototype member name (e.g.
	// "constructor") can never read as a spurious truthy hit. Reset on
	// hydration (writeForm) and after a successful save — the saved state
	// becomes the new baseline.
	var userUntickedServices = Object.create(null);

	// Set of service IDs the scanner has observed on this site. Populated
	// in init() by GET /detected-services. Used by renderServicesList()
	// to draw a small "Detected" badge next to the matching checkboxes so
	// the admin understands why auto-detect would pre-tick them. Empty
	// object (never null) so the lookup is always safe.
	var detectedServiceIds = Object.create(null);

	function api(method, path, body) {
		var FAZ = window.FAZ;
		var verb = String(method || 'GET').toUpperCase();
		if (FAZ && typeof FAZ.api === 'function') {
			switch (verb) {
				case 'GET':  return FAZ.get('cookie-policy/' + path);
				case 'POST': return FAZ.post('cookie-policy/' + path, body || {});
			}
		}
		// Defensive fallback for the rare case faz-admin.js hasn't loaded
		// yet (e.g. async script race in the WP admin head). The raw
		// fetch path uses the fully-qualified REST_URL + nonce injected
		// by class-admin.php.
		return fetch(REST_URL + path, {
			method:      verb,
			credentials: 'same-origin',
			headers:     { 'X-WP-Nonce': REST_NONCE, 'Content-Type': 'application/json' },
			body:        body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			if (!r.ok) {
				return r.json().then(function (j) { throw new Error(j.message || 'HTTP ' + r.status); });
			}
			return r.json();
		});
	}

	// ---------- form ↔ settings serialization ----------

	// Dot-path setter: setDeep(obj, "company.name", "ACME").
	// Hardened against prototype pollution via three independent guards:
	//   1. Reject path segments matching the well-known dangerous keys
	//      (__proto__ / prototype / constructor) BEFORE any property access.
	//   2. Use Object.prototype.hasOwnProperty.call() to check whether the
	//      key exists as an own property — never look up the prototype chain.
	//   3. Create intermediate objects with Object.create(null) so they
	//      have NO prototype chain at all; mutating their "__proto__" is a
	//      regular property write that cannot escalate to the global one.
	// Each layer is sufficient on its own; together they satisfy CodeQL's
	// "Prototype-polluting function" rule which flags any dynamic assignment
	// even when guarded by a denylist.
	function isUnsafeKey(k) {
		return k === '__proto__' || k === 'prototype' || k === 'constructor';
	}
	function setDeep(obj, path, value) {
		var parts = path.split('.');
		var cur = obj;
		for (var i = 0; i < parts.length - 1; i++) {
			var key = parts[i];
			if (isUnsafeKey(key)) { return; }
			var existing = Object.prototype.hasOwnProperty.call(cur, key) ? cur[key] : undefined;
			if (typeof existing !== 'object' || existing === null) {
				cur[key] = Object.create(null);
			}
			cur = cur[key]; // nosemgrep
		}
		var last = parts[parts.length - 1];
		if (isUnsafeKey(last)) { return; }
		cur[last] = value;
	}

	function getDeep(obj, path, fallback) {
		var parts = path.split('.');
		var cur = obj;
		for (var i = 0; i < parts.length; i++) {
			if (cur === null || typeof cur !== 'object') { return fallback; }
			var key = parts[i];
			// Read-side defence: even though pure reads cannot mutate the
			// prototype chain, refusing to dereference these keys keeps the
			// function symmetric with setDeep() and silences static
			// analysers (Semgrep flags any computed-key chain walk on a
			// plain object as a prototype-pollution candidate).
			if (isUnsafeKey(key)) { return fallback; }
			// Only consider own properties — never walk into inherited /
			// prototype-chain values that an attacker-controlled `name`
			// could otherwise dereference.
			if ( ! Object.prototype.hasOwnProperty.call(cur, key) ) { return fallback; }
			cur = cur[key]; // nosemgrep
		}
		return (cur === undefined || cur === null) ? fallback : cur;
	}

	function readForm() {
		var form = document.getElementById('faz-cookie-policy-form');
		var out = {
			third_party_services: []
		};
		// Plain inputs / selects / textareas with name="X.Y"
		form.querySelectorAll('input[name],select[name],textarea[name]').forEach(function (el) {
			if (el.id === 'cp-services-list') { return; }
			var name = el.name;
			if (el.type === 'checkbox') {
				// Service checkboxes use name="third_party_services[]" effectively, value=svc id
				if (el.dataset.serviceId) {
					if (el.checked) { out.third_party_services.push(el.dataset.serviceId); }
					return;
				}
				// Generic boolean checkbox (e.g. disclaimer.show). Serialize as bool.
				setDeep(out, name, !!el.checked);
				return;
			}
			var v = el.value;
			if (el.type === 'number') { v = parseInt(v, 10); if (isNaN(v)) { v = 0; } }
			setDeep(out, name, v);
		});
		return out;
	}

	function writeForm(settings) {
		// Scalar fields.
		[
			'company.name', 'company.address', 'company.email', 'company.registry',
			'dpo.name', 'dpo.email',
			'jurisdiction', 'retention_months', 'privacy_policy_url', 'default_lang',
			'disclaimer.text'
		].forEach(function (path) {
			var el = document.querySelector('[name="' + path + '"]');
			if (el) {
				var v = getDeep(settings, path, '');
				el.value = v;
			}
		});
		// Boolean checkbox: disclaimer.show (default true to preserve pre-1.16.2 behaviour).
		var showCb = document.querySelector('[name="disclaimer.show"]');
		if (showCb) {
			var showVal = getDeep(settings, 'disclaimer.show', true);
			showCb.checked = !!showVal;
		}
		// Service checkboxes.
		var services = settings.third_party_services || [];
		document.querySelectorAll('#cp-services-list input[type=checkbox]').forEach(function (cb) {
			cb.checked = services.indexOf(cb.dataset.serviceId) !== -1;
		});
		// The hydrated state is the new baseline — clear any in-session
		// manual-untick tracking so Auto-detect re-suggests freely (F009).
		// Programmatic .checked above does not fire 'change', so the map is
		// not about to be repopulated by this write.
		userUntickedServices = Object.create(null);
	}

	// ---------- services list (renders the checkboxes) ----------

	function renderServicesList() {
		var container = document.getElementById('cp-services-list');
		if (!container) { return; }
		while (container.firstChild) { container.removeChild(container.firstChild); }
		// Group catalog renders with sub-headings inside a <details> collapsed
		// by default (see admin/views/cookie-policy.php). Brand names are
		// verbatim (registered trademarks) but each label is still routed
		// through t() so a translator can attach a clarifying gloss if a
		// service is less recognisable in their locale.
		var groups = [
			{ title: t('grpAnalytics', 'Analytics'), services: [
				{ id: 'ga4',         label: t('svcGa4', 'Google Analytics 4') },
				{ id: 'gtm',         label: t('svcGtm', 'Google Tag Manager') },
				{ id: 'matomo',      label: t('svcMatomo', 'Matomo Analytics') },
				{ id: 'plausible',   label: t('svcPlausible', 'Plausible Analytics') },
				{ id: 'mixpanel',    label: t('svcMixpanel', 'Mixpanel') },
				{ id: 'amplitude',   label: t('svcAmplitude', 'Amplitude') },
				{ id: 'heap',        label: t('svcHeap', 'Heap') },
				{ id: 'fathom',      label: t('svcFathom', 'Fathom Analytics') },
				{ id: 'statcounter', label: t('svcStatcounter', 'Statcounter') }
			] },
			{ title: t('grpHeatmaps', 'Heatmaps & session recording'), services: [
				{ id: 'hotjar',      label: t('svcHotjar', 'Hotjar') },
				{ id: 'clarity',     label: t('svcClarity', 'Microsoft Clarity') },
				{ id: 'mouseflow',   label: t('svcMouseflow', 'Mouseflow') },
				{ id: 'smartlook',   label: t('svcSmartlook', 'Smartlook') },
				{ id: 'luckyorange', label: t('svcLuckyorange', 'Lucky Orange') },
				{ id: 'fullstory',   label: t('svcFullstory', 'FullStory') },
				{ id: 'logrocket',   label: t('svcLogrocket', 'LogRocket') },
				{ id: 'crazyegg',    label: t('svcCrazyegg', 'Crazy Egg') }
			] },
			{ title: t('grpAdPixels', 'Advertising pixels'), services: [
				{ id: 'gads',      label: t('svcGads', 'Google Ads') },
				{ id: 'meta',      label: t('svcMeta', 'Meta (Facebook) Pixel') },
				{ id: 'tiktok',    label: t('svcTiktok', 'TikTok Pixel') },
				{ id: 'linkedin',  label: t('svcLinkedin', 'LinkedIn Insight Tag') },
				{ id: 'msuet',     label: t('svcMsuet', 'Microsoft UET') },
				{ id: 'twitter',   label: t('svcTwitter', 'Twitter (X) Pixel') },
				{ id: 'pinterest', label: t('svcPinterest', 'Pinterest Tag') },
				{ id: 'reddit',    label: t('svcReddit', 'Reddit Pixel') },
				{ id: 'snap',      label: t('svcSnap', 'Snapchat Pixel') },
				{ id: 'quora',     label: t('svcQuora', 'Quora Pixel') },
				{ id: 'outbrain',  label: t('svcOutbrain', 'Outbrain') },
				{ id: 'taboola',   label: t('svcTaboola', 'Taboola') },
				{ id: 'criteo',    label: t('svcCriteo', 'Criteo') }
			] },
			{ title: t('grpCdn', 'CDN, edge & performance'), services: [
				{ id: 'cf',         label: t('svcCf', 'Cloudflare') },
				{ id: 'fastly',     label: t('svcFastly', 'Fastly') },
				{ id: 'akamai',     label: t('svcAkamai', 'Akamai') },
				{ id: 'cloudfront', label: t('svcCloudfront', 'Amazon CloudFront') },
				{ id: 'bunnycdn',   label: t('svcBunnycdn', 'BunnyCDN') },
				{ id: 'jsdelivr',   label: t('svcJsdelivr', 'jsDelivr') }
			] },
			{ title: t('grpAntibot', 'Anti-bot & forms'), services: [
				{ id: 'recaptcha', label: t('svcRecaptcha', 'Google reCAPTCHA') },
				{ id: 'hcaptcha',  label: t('svcHcaptcha', 'hCaptcha') },
				{ id: 'turnstile', label: t('svcTurnstile', 'Cloudflare Turnstile') },
				{ id: 'akismet',   label: t('svcAkismet', 'Akismet') }
			] },
			{ title: t('grpEmbeds', 'Maps, embeds & media'), services: [
				{ id: 'gmaps',        label: t('svcGmaps', 'Google Maps') },
				{ id: 'mapbox',       label: t('svcMapbox', 'Mapbox') },
				{ id: 'osm',          label: t('svcOsm', 'OpenStreetMap') },
				{ id: 'youtube',      label: t('svcYoutube', 'YouTube (embed)') },
				{ id: 'vimeo',        label: t('svcVimeo', 'Vimeo (embed)') },
				{ id: 'twitterembed', label: t('svcTwitterembed', 'Twitter / X (embed)') },
				{ id: 'instagram',    label: t('svcInstagram', 'Instagram (embed)') },
				{ id: 'spotify',      label: t('svcSpotify', 'Spotify (embed)') },
				{ id: 'soundcloud',   label: t('svcSoundcloud', 'SoundCloud (embed)') },
				{ id: 'wistia',       label: t('svcWistia', 'Wistia') },
				{ id: 'brightcove',   label: t('svcBrightcove', 'Brightcove') },
				{ id: 'jwplayer',     label: t('svcJwplayer', 'JW Player') }
			] },
			{ title: t('grpChat', 'Chat & support'), services: [
				{ id: 'intercom',    label: t('svcIntercom', 'Intercom') },
				{ id: 'zendesk',     label: t('svcZendesk', 'Zendesk Chat') },
				{ id: 'crisp',       label: t('svcCrisp', 'Crisp') },
				{ id: 'livechat',    label: t('svcLivechat', 'LiveChat') },
				{ id: 'tawk',        label: t('svcTawk', 'Tawk.to') },
				{ id: 'drift',       label: t('svcDrift', 'Drift') },
				{ id: 'hubspotchat', label: t('svcHubspotchat', 'HubSpot Chat') },
				{ id: 'tidio',       label: t('svcTidio', 'Tidio') }
			] },
			{ title: t('grpEmail', 'Email & marketing automation'), services: [
				{ id: 'mailchimp',      label: t('svcMailchimp', 'Mailchimp') },
				{ id: 'activecampaign', label: t('svcActivecampaign', 'ActiveCampaign') },
				{ id: 'convertkit',     label: t('svcConvertkit', 'ConvertKit / Kit') },
				{ id: 'hubspot',        label: t('svcHubspot', 'HubSpot') },
				{ id: 'brevo',          label: t('svcBrevo', 'Brevo (Sendinblue)') },
				{ id: 'klaviyo',        label: t('svcKlaviyo', 'Klaviyo') },
				{ id: 'pardot',         label: t('svcPardot', 'Salesforce Pardot') },
				{ id: 'marketo',        label: t('svcMarketo', 'Adobe Marketo Engage') },
				{ id: 'adobe',          label: t('svcAdobe', 'Adobe Analytics') }
			] },
			{ title: t('grpPayments', 'Payments & commerce'), services: [
				{ id: 'stripe',  label: t('svcStripe', 'Stripe') },
				{ id: 'paypal',  label: t('svcPaypal', 'PayPal') },
				{ id: 'square',  label: t('svcSquare', 'Square') },
				{ id: 'shopify', label: t('svcShopify', 'Shopify') }
			] },
			{ title: t('grpSignin', 'Social sign-in & auth'), services: [
				{ id: 'google_signin',   label: t('svcGoogleSignin', 'Sign in with Google') },
				{ id: 'apple_signin',    label: t('svcAppleSignin', 'Sign in with Apple') },
				{ id: 'facebook_signin', label: t('svcFacebookSignin', 'Sign in with Facebook') },
				{ id: 'auth0',           label: t('svcAuth0', 'Auth0') },
				{ id: 'okta',            label: t('svcOkta', 'Okta') }
			] },
			{ title: t('grpMonitoring', 'Error & RUM monitoring'), services: [
				{ id: 'sentry',   label: t('svcSentry', 'Sentry') },
				{ id: 'newrelic', label: t('svcNewrelic', 'New Relic') },
				{ id: 'datadog',  label: t('svcDatadog', 'Datadog') },
				{ id: 'bugsnag',  label: t('svcBugsnag', 'Bugsnag') },
				{ id: 'raygun',   label: t('svcRaygun', 'Raygun') }
			] },
			{ title: t('grpAbtest', 'Personalisation & A/B testing'), services: [
				{ id: 'optimizely', label: t('svcOptimizely', 'Optimizely') },
				{ id: 'vwo',        label: t('svcVwo', 'VWO') },
				{ id: 'convert',    label: t('svcConvert', 'Convert.com') },
				{ id: 'abtasty',    label: t('svcAbtasty', 'AB Tasty') }
			] },
			{ title: t('grpPush', 'Push notifications'), services: [
				{ id: 'onesignal', label: t('svcOnesignal', 'OneSignal') },
				{ id: 'pushwoosh', label: t('svcPushwoosh', 'Pushwoosh') },
				{ id: 'fcm',       label: t('svcFcm', 'Firebase Cloud Messaging') }
			] }
		];
		groups.forEach(function (group) {
			var heading = document.createElement('h4');
			heading.style.cssText = 'margin:14px 0 6px 0;font-size:13px;font-weight:600;color:#444;border-bottom:1px dotted #ccd0d4;padding-bottom:3px;';
			heading.textContent = group.title;
			container.appendChild(heading);
			var wrap = document.createElement('div');
			wrap.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px 14px;';
			group.services.forEach(function (svc) {
				var label = document.createElement('label');
				label.style.cssText = 'display:inline-flex;align-items:center;gap:5px;font-size:13px;padding:2px 0;flex:0 0 auto;';
				var cb = document.createElement('input');
				cb.type = 'checkbox';
				// readForm() iterates input[name],select[name],textarea[name] —
				// without a name the checkbox is skipped and third_party_services
				// stays empty no matter how many boxes the user ticks. The PHP-
				// array suffix in the name is cosmetic (readForm uses the
				// dataset.serviceId branch, not the form value); value mirrors
				// dataset.serviceId for HTML-form correctness.
				cb.name = 'third_party_services[]';
				cb.value = svc.id;
				cb.dataset.serviceId = svc.id;
				label.appendChild(cb);
				label.appendChild(document.createTextNode(svc.label));
				// "Detected" badge — only when the cookie scanner has actually
				// observed a tracking domain associated with this service ID.
				// Uses a null-prototype lookup (detectedServiceIds is built via
				// Object.create(null)) so a service ID accidentally equal to
				// "constructor" or "toString" can't false-positive.
				if (Object.prototype.hasOwnProperty.call(detectedServiceIds, svc.id)) {
					var badge = document.createElement('span');
					// .faz-badge + .faz-badge-success supply the radius,
					// font-weight and the correct design-token colours (no
					// hardcoded hex). faz-svc-detected-badge is both the test
					// selector (cookie-policy-service-auto-detect.spec.ts) and
					// the source of the compact sizing override — including the
					// padding (1px 6px, which overrides .faz-badge's 2px 8px;
					// see faz-admin.css).
					badge.className = 'faz-badge faz-badge-success faz-svc-detected-badge';
					badge.title = t( 'svcDetectedTooltip', 'The cookie scanner observed a tracking domain for this service on your site.' );
					badge.setAttribute( 'aria-label', t( 'svcDetectedTooltip', 'The cookie scanner observed a tracking domain for this service on your site.' ) );
					badge.style.cssText = 'margin-left:4px;';
					badge.textContent = t( 'svcDetectedBadge', 'Detected' );
					label.appendChild(badge);
				}
				wrap.appendChild(label);
			});
			container.appendChild(wrap);
		});
	}

	// ---------- preview modal ----------

	function showPreview(html) {
		var modal = document.getElementById('cp-preview-modal');
		var content = document.getElementById('cp-preview-content');
		// Strongest possible iframe sandbox: empty value disables scripts,
		// forms, top-navigation, popups AND same-origin privileges. Even if
		// a future regression slips XSS past the server-side wp_kses_post
		// boundary, the iframe document cannot reach the admin page DOM,
		// cookies, or REST nonce — it's a fully isolated null-origin frame.
		// srcdoc (instead of doc.write) lets us set the content atomically
		// without needing a setTimeout dance.
		while (content.firstChild) { content.removeChild(content.firstChild); }
		var iframe = document.createElement('iframe');
		iframe.style.cssText = 'width:100%; height:70vh; border:1px solid #ccd0d4; background:#fff;';
		iframe.setAttribute('sandbox', ''); // null sandbox — no scripts, no same-origin, no nothing
		iframe.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><style>' +
			'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; padding:18px; line-height:1.55;}' +
			'.faz-cookie-policy-disclaimer{margin-top:24px;padding:12px 14px;background:#fff5d6;border-left:3px solid #d4a017;font-size:13px;}' +
			'</style></head><body>' + html + '</body></html>';
		content.appendChild(iframe);
		modal.hidden = false;
	}

	function hidePreview() {
		document.getElementById('cp-preview-modal').hidden = true;
	}

	// ---------- wire-up ----------

	function setStatus(msg, kind) {
		var el = document.getElementById('cp-save-status');
		if (!el) { return; }
		el.textContent = msg || '';
		el.style.color = kind === 'error' ? 'var(--faz-danger, #c03658)' : (kind === 'ok' ? 'var(--faz-success, #17785b)' : '');
		if (msg && kind === 'ok') {
			setTimeout(function () { el.textContent = ''; }, 3000);
		}
	}

	// Closure-level timer handle for the auto-detect status auto-clear.
	// Cleared at the start of every setAutoDetectStatus() call so a stale
	// timer scheduled by a previous 'ok' message can never blank a newer
	// scanning/error message that was painted afterwards.
	var autoDetectStatusTimer = null;
	function setAutoDetectStatus(msg, kind) {
		var el = document.getElementById('cp-services-auto-detect-status');
		if (!el) { return; }
		if (autoDetectStatusTimer) { clearTimeout(autoDetectStatusTimer); autoDetectStatusTimer = null; }
		el.textContent = msg || '';
		el.style.color = kind === 'error' ? 'var(--faz-danger, #c03658)' : (kind === 'warning' ? 'var(--faz-warning, #b86900)' : (kind === 'ok' ? 'var(--faz-success, #17785b)' : 'var(--faz-text-secondary, #555)'));
		// Mirror setStatus(): auto-clear the success message after 3s.
		// 'error' and scanning ('') states stay persistent (no timer).
		if (msg && kind === 'ok') {
			autoDetectStatusTimer = setTimeout(function () { el.textContent = ''; autoDetectStatusTimer = null; }, 3000);
		}
	}

	function autoDetectServices() {
		var btn = document.getElementById('cp-services-auto-detect');
		if (!btn || btn.disabled) { return; }
		// Race-guard: capture this invocation's id before issuing the
		// async fetch. If the user clicks again before we resolve, a
		// newer id is captured and our then-handler bails out so the
		// older /suggest-services response never paints over the newer
		// result. Mirrors the GVL admin page's pattern (PR #127).
		autoDetectRequestId += 1;
		var myReqId = autoDetectRequestId;
		// Spinner + disabled state via the shared FAZ.btnLoading helper
		// (parity with gvl.js). Read-only scan, so pass the scan-specific
		// label instead of letting it default to "Saving...". btnLoading
		// stashes the original button text in dataset.origText and restores
		// it on the false call.
		var FAZ = window.FAZ;
		if (FAZ && typeof FAZ.btnLoading === 'function') {
			FAZ.btnLoading(btn, true, t( 'svcAutoDetectScanning', 'Scanning cookie inventory…' ));
		} else {
			btn.disabled = true;
		}
		setAutoDetectStatus(t( 'svcAutoDetectScanning', 'Scanning cookie inventory…' ), '');

		api('GET', 'suggest-services')
			.then(function (resp) {
				if (myReqId !== autoDetectRequestId) { return; } // stale, drop
				if (FAZ && typeof FAZ.btnLoading === 'function') { FAZ.btnLoading(btn, false); } else { btn.disabled = false; }
				if (!resp || resp.scan_available !== true) {
					setAutoDetectStatus(t( 'svcAutoDetectNoScan', 'No scanner data yet. Run the cookie scanner first.' ), 'warning');
					return;
				}
				var newly  = (resp && Array.isArray(resp.newly_suggested))  ? resp.newly_suggested  : [];
				var already = (resp && Array.isArray(resp.already_selected)) ? resp.already_selected : [];
				// "already selected" for the status message must reflect the
				// CURRENT in-session selection, not the server's saved-state
				// `already_selected`: a service the admin unticked this session
				// (and that auto-detect deliberately leaves unticked, F009) must
				// not be counted as already-selected, or the count contradicts
				// what the admin sees on screen. Mirrors gvl.js alreadyInSession.
				var alreadyInSession = already.filter(function (sid) { return !userUntickedServices[sid]; });
				if (newly.length === 0 && already.length === 0) {
					setAutoDetectStatus(t( 'svcAutoDetectNoMatch', 'No matching services found among scanned cookies.' ), '');
					return;
				}
				// Nothing new to add but the detected services are already
				// selected: confirm that and OMIT the "Click Save to commit"
				// prompt — there is nothing to save. Mirrors gvl.js's
				// autoDetectAllAlready branch (added.length === 0).
				if (newly.length === 0 && already.length > 0) {
					var allAlreadyMsg = (alreadyInSession.length > 0)
						? t( 'svcAutoDetectAllAlready', 'All %d detected service(s) are already selected.' ).replace('%d', String(alreadyInSession.length))
						: t( 'svcAutoDetectNoneAdded', 'Detected services left unticked, as you set them.' );
					setAutoDetectStatus(allAlreadyMsg, 'ok');
					return;
				}
				// Pre-tick the newly_suggested checkboxes. Already-selected
				// boxes stay checked (they already are). Save commits.
				// EXCEPT services the admin manually unticked this session: a
				// detected service the admin deliberately removed (before
				// saving) must not be silently re-ticked by a later Auto-detect
				// run (F009). Auto-detect stays additive without reversing the
				// admin's unsaved intent.
				var list = document.getElementById('cp-services-list');
				var skipped = 0;
				if (list) {
					var boxes = list.querySelectorAll('input[type=checkbox][data-service-id]');
					for (var i = 0; i < boxes.length; i++) {
						var sid = boxes[i].dataset.serviceId;
						if (newly.indexOf(sid) === -1) { continue; }
						if (userUntickedServices[sid]) { skipped += 1; continue; }
						boxes[i].checked = true;
					}
				}
				// Report what was ACTUALLY pre-ticked, not the raw suggestion
				// count: services the admin unticked this session were skipped
				// above, so the count must subtract them or the status would
				// claim more boxes than it ticked (the desync CodeRabbit flagged
				// on F009).
				var addedCount = newly.length - skipped;
				if (addedCount === 0) {
					// Every newly-detected service was one the admin unticked
					// this session — nothing was pre-ticked, so omit the "Click
					// Save" prompt. Confirm without lying about a pending change.
					var noneMsg = (alreadyInSession.length > 0)
						? t( 'svcAutoDetectAllAlready', 'All %d detected service(s) are already selected.' ).replace('%d', String(alreadyInSession.length))
						: t( 'svcAutoDetectNoneAdded', 'Detected services left unticked, as you set them.' );
					setAutoDetectStatus(noneMsg, 'ok');
					return;
				}
				// Accept both positional (%1$d / %2$d — WP i18n best practice
				// for translators that need to reorder) AND plain %d / %d
				// in the English fallback string. Pure ordered .replace('%d', …)
				// chains break under reordering — same fragility CodeRabbit
				// flagged on the GVL admin page (F006, below_gate).
				var template = t( 'svcAutoDetectDone', 'Pre-ticked %1$d new service(s), %2$d were already selected. Click Save to commit.' );
				var formatted = template
					.replace(/%1\$d/g, String(addedCount))
					.replace(/%2\$d/g, String(alreadyInSession.length))
					.replace('%d', String(addedCount))
					.replace('%d', String(alreadyInSession.length));
				setAutoDetectStatus(formatted, 'ok');
			})
			.catch(function (err) {
				if (myReqId !== autoDetectRequestId) { return; }
				if (FAZ && typeof FAZ.btnLoading === 'function') { FAZ.btnLoading(btn, false); } else { btn.disabled = false; }
				// Keep the raw server error in the console for debugging, but
				// show the admin actionable copy only — don't surface verbatim
				// WP_Error / internal detail in the UI (matches the GVL sibling).
				if (window.console && console.error) { console.error('FAZ: service auto-detect failed', err); }
				setAutoDetectStatus(t( 'svcAutoDetectFailed', 'Auto-detect failed. Check the cookie scanner and try again.' ), 'error');
			});
	}

	function init() {
		// Initial render runs sync (badges absent because detected set is
		// empty); the /detected-services fetch below re-renders with the
		// "Detected" badges painted in. This keeps the page interactive
		// during the round-trip rather than blocking on a network call
		// the user may not even care about (some sites have no scanner
		// data at all). Both rendering passes preserve the checkbox
		// state because writeForm() runs after the second render.
		renderServicesList();

		// Bind + disable Auto-detect BEFORE the Promise.all fires. If
		// the user clicks the button during hydration, writeForm(settings)
		// would race and overwrite the just-applied auto-detect ticks
		// with the saved option's state, silently dropping the user's
		// action. Disabled until writeForm runs in the .then() resolver.
		// CodeRabbit PR #127 review (2026-05-27) flagged this race.
		var autoDetectBtn = document.getElementById('cp-services-auto-detect');
		if (autoDetectBtn) {
			autoDetectBtn.disabled = true;
			autoDetectBtn.addEventListener('click', autoDetectServices);
		}

		// Track the admin's manual tick/untick of service checkboxes so
		// Auto-detect can skip a detected service the admin deliberately
		// unticked this session (F009). Delegated on the container because
		// the checkboxes are (re)rendered dynamically by renderServicesList().
		// Programmatic `cb.checked = …` in writeForm() does NOT fire 'change',
		// so hydration never pollutes this map.
		var servicesListEl = document.getElementById('cp-services-list');
		if (servicesListEl) {
			servicesListEl.addEventListener('change', function (e) {
				var cb = e.target;
				if (!cb || cb.type !== 'checkbox' || !cb.dataset || !cb.dataset.serviceId) { return; }
				if (cb.checked) {
					delete userUntickedServices[cb.dataset.serviceId];
				} else {
					userUntickedServices[cb.dataset.serviceId] = true;
				}
			});
		}

		// Tracks whether the saved settings actually loaded. If the GET failed
		// the form holds only defaults, so we must NOT re-enable Auto-detect or
		// allow a submit — saving would overwrite the real config with blanks.
		var hydrationFailed = false;
		Promise.all([
			api('GET', 'settings').catch(function (err) { setStatus(t( 'loadFailed', 'Load failed' ) + ': ' + err.message, 'error'); hydrationFailed = true; return null; }),
			api('GET', 'detected-services').catch(function () { return { service_ids: [] }; })
		]).then(function (results) {
			try {
				var settings = results[0];
				if (settings === null) { hydrationFailed = true; }
				var detected = results[1] && Array.isArray(results[1].service_ids) ? results[1].service_ids : [];
				// Re-render with badges if the scanner found anything. Empty
				// detected list: skip the rerender (badges identical to first
				// pass — no point re-painting the DOM).
				if (detected.length > 0) {
					detectedServiceIds = Object.create(null);
					for (var i = 0; i < detected.length; i++) {
						detectedServiceIds[detected[i]] = true;
					}
					renderServicesList();
				}
				// writeForm runs LAST so checkbox state from settings overrides
				// any default in the freshly-rendered DOM. Preserves the
				// hydration-race guard: writeForm(settings) must complete
				// before the finally block re-enables the button.
				if (settings) { writeForm(settings); }
			} finally {
				// Hydration done — Auto-detect is safe to use now (writeForm
				// has already applied the saved selection, so subsequent
				// auto-detect ticks can't be overwritten by a late hydration).
				// In finally so a synchronous throw in renderServicesList()/
				// writeForm() can never leave the button permanently disabled.
				// Only when settings hydrated: a failed load keeps it disabled
				// so the admin can't auto-detect/save over the real config.
				if (autoDetectBtn && !hydrationFailed) {
					autoDetectBtn.disabled = false;
					// Clear the server-rendered "Loading saved selection…" hint.
					setAutoDetectStatus('', '');
				}
			}
		}).catch(function (err) {
			// Outer safety net: a synchronous throw in renderServicesList() or
			// writeForm() (inside the .then() try block) becomes a rejection
			// here. Without this, it surfaces as an unhandled rejection and the
			// "Load failed" status is lost. Re-enable Auto-detect (idempotent
			// with the finally block) and surface the failure. Mirrors gvl.js
			// loadSelectedVendors's .catch(). The inner api() .catch() handlers
			// return null (no throw), so this never double-fires on that path.
			// Keep the button disabled when settings never hydrated.
			if (autoDetectBtn && !hydrationFailed) { autoDetectBtn.disabled = false; }
			setStatus(t( 'loadFailed', 'Load failed' ) + ': ' + (err && err.message ? err.message : err), 'error');
		});

		document.getElementById('faz-cookie-policy-form').addEventListener('submit', function (e) {
			e.preventDefault();
			// Refuse to save over a config that never loaded — readForm() would
			// serialise default/blank fields and clobber the real saved data.
			if (hydrationFailed) {
				setStatus(t( 'loadFailed', 'Settings did not load — reload the page before saving.' ), 'error');
				return;
			}
			var payload = readForm();
			setStatus(t( 'saving', 'Saving…' ), '');
			api('POST', 'settings', payload)
				.then(function () {
					setStatus(t( 'saved', 'Saved.' ), 'ok');
					// Saved state is the new baseline — a service the admin
					// unticked is now persisted as unselected, so clear the
					// in-session tracking and let a later Auto-detect re-suggest
					// it freely (F009).
					userUntickedServices = Object.create(null);
				})
				.catch(function (err) { setStatus(t( 'saveFailed', 'Save failed' ) + ': ' + err.message, 'error'); });
		});

		document.getElementById('cp-preview-btn').addEventListener('click', function () {
			// Race-condition guard: if the user clicks Preview multiple times
			// quickly the responses may resolve out of order. We capture the
			// pre-increment id and only commit results whose id is still the
			// latest. Older responses are silently dropped.
			previewRequestId += 1;
			var myReqId = previewRequestId;
			var payload = readForm();
			api('POST', 'preview', { settings: payload, lang: payload.default_lang || '', jurisdiction: payload.jurisdiction || '' })
				.then(function (resp) {
					if (myReqId !== previewRequestId) { return; } // stale response, drop
					showPreview(resp.html || '');
				})
				.catch(function (err) {
					if (myReqId !== previewRequestId) { return; } // stale error too
					setStatus(t( 'previewFailed', 'Preview failed' ) + ': ' + err.message, 'error');
				});
		});

		var modal = document.getElementById('cp-preview-modal');
		modal.querySelector('.faz-cp-modal-close').addEventListener('click', hidePreview);
		modal.addEventListener('click', function (e) {
			if (e.target === modal) { hidePreview(); }
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !modal.hidden) { hidePreview(); }
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
