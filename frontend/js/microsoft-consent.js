/**
 * FAZ Cookie Manager - Microsoft Consent Integration
 * Handles UET Consent Mode and Clarity Consent API.
 */
(function () {
	// Resolve consent for an "advertising" / "analytics" purpose from the
	// accepted and rejected category slugs. Known aliases are accepted so a site
	// using the "performance" analytics-class slug, or still carrying the legacy
	// "advertisement" marketing slug, keeps working — mirroring gcm.js. (A fully
	// renamed custom slug cannot be auto-mapped without purpose metadata in the
	// consent payload, which the cookie does not carry.)
	//
	// Most-restrictive merge: a purpose is granted only when EVERY category the
	// site actually offers for it was accepted. `hasAny` granted the purpose as
	// soon as one matched, so a visitor who denied Analytics but allowed
	// Performance — both ship in the default category set — was reported to UET
	// and Clarity as analytics-granted. Merging two categories onto one signal
	// has to err toward the refusal, not the grant.
	//
	// `rejected` is what makes the distinction possible: a slug in neither list
	// is not offered on this site and must not drag the purpose down, while a
	// slug in `rejected` is a real denial.
	function allGranted(cats, rejected, slugs) {
		var sawOne = false;
		for (var i = 0; i < slugs.length; i++) {
			if (rejected.indexOf(slugs[i]) >= 0) {
				return false;
			}
			if (cats.indexOf(slugs[i]) >= 0) {
				sawOne = true;
			}
		}
		return sawOne;
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
			var rej  = (e.detail && e.detail.rejected) ? e.detail.rejected : [];
			var state = {
				ad_storage: allGranted(cats, rej, AD_SLUGS) ? 'granted' : 'denied',
				analytics_storage: allGranted(cats, rej, ANALYTICS_SLUGS) ? 'granted' : 'denied'
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
		// never called for a returning visitor. clarity('consent', true/false) is
		// itself idempotent, so no dedupe guard is needed here.
		function pushClarityConsent(e) {
			var cats = (e.detail && e.detail.accepted) ? e.detail.accepted : [];
			var rej  = (e.detail && e.detail.rejected) ? e.detail.rejected : [];
			if (typeof window.clarity !== 'function') {
				return;
			}
			// The explicit boolean matters: the grant-only call meant a visitor
			// who revoked analytics in the preference center kept Clarity
			// consented for the rest of the page load. clarity('consent', false)
			// tells Clarity to drop to cookieless mode immediately.
			window.clarity('consent', allGranted(cats, rej, ANALYTICS_SLUGS));
		}
		document.addEventListener('fazcookie_consent_update', pushClarityConsent);
		document.addEventListener('fazcookie_consent_ready', pushClarityConsent);
		if (window._fazConsentReady) {
			pushClarityConsent({ detail: window._fazConsentReady });
		}
	}
})();
