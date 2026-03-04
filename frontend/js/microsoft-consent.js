/**
 * FAZ Cookie Manager - Microsoft Consent Integration
 * Handles UET Consent Mode and Clarity Consent API.
 */
(function () {
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
				ad_storage: cats.indexOf('marketing') >= 0 ? 'granted' : 'denied',
				analytics_storage: cats.indexOf('analytics') >= 0 ? 'granted' : 'denied'
			});
		});
	}

	// Microsoft Clarity Consent API
	if (window._fazMicrosoftClarity) {
		document.addEventListener('fazcookie_consent_update', function (e) {
			var cats = (e.detail && e.detail.accepted) ? e.detail.accepted : [];
			if (typeof window.clarity === 'function' && cats.indexOf('analytics') >= 0) {
				window.clarity('consent');
			}
		});
	}
})();
