/**
 * FAZ Cookie Manager - Microsoft Consent Integration
 * Handles UET Consent Mode and Clarity Consent API.
 */
(function () {
	// Resolve consent for an "advertising" / "analytics" purpose from the list
	// of accepted category slugs. We accept the known aliases so a site that
	// uses the "performance" analytics-class slug, or still carries the legacy
	// "advertisement" marketing slug, keeps working — mirroring gcm.js. (A fully
	// renamed custom slug cannot be auto-mapped without purpose metadata in the
	// consent payload, which the cookie does not carry.)
	function hasAny(cats, slugs) {
		for (var i = 0; i < slugs.length; i++) {
			if (cats.indexOf(slugs[i]) >= 0) {
				return true;
			}
		}
		return false;
	}
	var AD_SLUGS = ['marketing', 'advertisement'];
	var ANALYTICS_SLUGS = ['analytics', 'performance'];

	// Microsoft UET Consent Mode
	if (window._fazMicrosoftUET) {
		window.uetq = window.uetq || [];
		window.uetq.push('consent', 'default', {
			ad_storage: 'denied',
			analytics_storage: 'denied'
		});
		// Both events, because the `default: denied` pushed just above is all
		// Microsoft ever saw on the pages AFTER the visitor accepted: this file
		// had no bootstrap path, and fazcookie_consent_update does not fire for
		// a visitor whose choice was already stored. A returning visitor who had
		// granted marketing was still reported to UET as denied.
		var lastUetPush = null;
		function pushUetConsent(e) {
			var cats = (e.detail && e.detail.accepted) ? e.detail.accepted : [];
			var state = {
				ad_storage: hasAny(cats, AD_SLUGS) ? 'granted' : 'denied',
				analytics_storage: hasAny(cats, ANALYTICS_SLUGS) ? 'granted' : 'denied'
			};
			// On a first visit both events arrive with the same values; one push
			// is the honest report of one state.
			var signature = state.ad_storage + '|' + state.analytics_storage;
			if (signature === lastUetPush) {
				return;
			}
			lastUetPush = signature;
			window.uetq.push('consent', 'update', state);
		}
		document.addEventListener('fazcookie_consent_update', pushUetConsent);
		document.addEventListener('fazcookie_consent_ready', pushUetConsent);
		// Catch up if the announcement already happened — see wca.js for why a
		// separately-loaded file cannot rely on being present for it.
		if (window._fazConsentReady) {
			pushUetConsent({ detail: window._fazConsentReady });
		}
	}

	// Microsoft Clarity Consent API
	if (window._fazMicrosoftClarity) {
		// Same gap as UET above: without the ready event, clarity('consent') was
		// never called for a returning visitor. clarity('consent') is itself
		// idempotent, so no dedupe guard is needed here.
		function grantClarityConsent(e) {
			var cats = (e.detail && e.detail.accepted) ? e.detail.accepted : [];
			if (typeof window.clarity === 'function' && hasAny(cats, ANALYTICS_SLUGS)) {
				window.clarity('consent');
			}
		}
		document.addEventListener('fazcookie_consent_update', grantClarityConsent);
		document.addEventListener('fazcookie_consent_ready', grantClarityConsent);
		if (window._fazConsentReady) {
			grantClarityConsent({ detail: window._fazConsentReady });
		}
	}
})();
