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
		document.addEventListener('fazcookie_consent_update', function (e) {
			var cats = (e.detail && e.detail.accepted) ? e.detail.accepted : [];
			window.uetq.push('consent', 'update', {
				ad_storage: hasAny(cats, AD_SLUGS) ? 'granted' : 'denied',
				analytics_storage: hasAny(cats, ANALYTICS_SLUGS) ? 'granted' : 'denied'
			});
		});
	}

	// Microsoft Clarity Consent API
	if (window._fazMicrosoftClarity) {
		document.addEventListener('fazcookie_consent_update', function (e) {
			var cats = (e.detail && e.detail.accepted) ? e.detail.accepted : [];
			if (typeof window.clarity === 'function' && hasAny(cats, ANALYTICS_SLUGS)) {
				window.clarity('consent');
			}
		});
	}
})();
